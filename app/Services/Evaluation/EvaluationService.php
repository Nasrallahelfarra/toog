<?php

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use App\Notifications\PlatformNotification;
use App\Services\Ai\Ai;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs one evaluation end to end: score → model explanation and cultural
 * check → re-score with any cultural flags → modifications or alternatives
 * → save → notify.
 */
class EvaluationService
{
    private const CRITERIA_ENUM = ['success_similarity', 'trend_fit', 'saudi_fit', 'failure_risk', 'saturation', 'price_fit'];

    public function __construct(
        private readonly EvaluationEngine $engine,
        private readonly ExplanationWriter $writer,
        private readonly Ai $ai,
        private readonly ProductReview $reviewer,
    ) {}

    public function run(Evaluation $evaluation, ?StoreContext $ctx = null, bool $notify = true): Evaluation
    {
        $evaluation->update(['status' => 'processing', 'error' => null]);

        try {
            $ctx ??= new StoreContext($evaluation->store);
            $candidate = Candidate::fromEvaluation($evaluation);
            $result = $this->engine->score($candidate, $ctx);
            $context = $this->reviewer->context($evaluation, $ctx, $result);
            $images = $this->image($evaluation);

            $ai = $this->ai->json(
                operation: 'evaluation',
                store: $ctx->store,
                system: $this->systemPrompt().$this->reviewer->instructions(),
                prompt: $this->prompt($evaluation, $result)."\nImage available: ".($images ? 'yes' : 'no')."\nReviewer evidence pack:\n".json_encode($context, JSON_UNESCAPED_UNICODE),
                schema: $this->schema(),
                fallback: fn () => $this->writer->write($result),
                images: $images,
                userId: $evaluation->user_id,
            );

            $flags = $this->validFlags($ai->data['cultural_flags'] ?? []);
            $review = $this->reviewer->normalize($ai->data['specialist_review'] ?? null, $ai->fromModel, (bool) $images, $context);
            // Generated designs are measured from the resulting pixels, never
            // from the attributes the image model was asked to produce.
            if ($evaluation->source === 'design') {
                if (isset($review['visual_attributes'])) {
                    $evaluation->image_observations = ['path' => $evaluation->image_path, 'attributes' => $review['visual_attributes']];
                }
                $observations = $evaluation->image_observations;
                $evaluation->fill(($observations['path'] ?? null) === $evaluation->image_path && isset($observations['attributes'])
                    ? $observations['attributes'] : ['colors' => [], 'pattern' => null, 'style' => null]);
                $candidate = Candidate::fromEvaluation($evaluation);
            } elseif (isset($review['visual_attributes'])) {
                // Complete missing visual fields without replacing the merchant's
                // explicitly supplied product specifications.
                $observed = $review['visual_attributes'];
                foreach (['colors', 'pattern', 'style'] as $attribute) {
                    if (blank($evaluation->{$attribute})) {
                        $evaluation->{$attribute} = $observed[$attribute];
                    }
                }
                $candidate = Candidate::fromEvaluation($evaluation);
            }
            $rescored = (bool) $flags || $evaluation->source === 'design' || $evaluation->isDirty(['colors', 'pattern', 'style']);
            if ($rescored) {
                $result = $this->engine->score($candidate, $ctx, $flags);
            }
            $ruleRecommendation = $result['recommendation'];
            if ($review['status'] === 'available') {
                $result['recommendation'] = match ($review['verdict']) {
                    'unsuitable' => Evaluation::REJECT,
                    'improve', 'insufficient' => $ruleRecommendation === Evaluation::REJECT ? Evaluation::REJECT : Evaluation::MODIFY,
                    default => $ruleRecommendation,
                };
            }
            $explanation = $rescored || $ruleRecommendation !== $result['recommendation'] ? $this->writer->write($result) : $ai->data;

            $mods = $result['recommendation'] === Evaluation::ACCEPT
                ? ['modifications' => [], 'expected_score' => $result['score']]
                : $this->engine->modifications($candidate, $ctx, $result, $flags);

            $alternatives = $result['recommendation'] === Evaluation::REJECT
                ? $this->engine->alternatives($candidate, $ctx)
                : [];

            // The model may only phrase factors; labels and ordering of
            // criteria stay ours.
            $factors = collect($ai->data['top_factors'] ?? [])
                ->filter(fn ($f) => in_array($f['criterion'] ?? null, self::CRITERIA_ENUM, true))
                ->map(fn ($f) => [
                    ...$f,
                    'label_ar' => ExplanationWriter::label($f['criterion'], 'ar'),
                    'label_en' => ExplanationWriter::label($f['criterion'], 'en'),
                ])
                ->values()
                ->all() ?: $this->writer->factors($result);

            $evaluation->update([
                'status' => 'completed',
                'specialist_review' => $review,
                'score' => $result['score'],
                'breakdown' => [
                    'criteria' => $result['criteria'],
                    'evidence' => $result['evidence'],
                    'advice' => $ai->data['advice'] ?? [],
                    'ai_written' => $ai->fromModel,
                    'rule_recommendation' => $ruleRecommendation,
                    'thresholds' => ['accept' => $ctx->acceptThreshold, 'reject' => $ctx->rejectThreshold],
                ],
                'recommendation' => $result['recommendation'],
                'explanation_ar' => $explanation['explanation_ar'] ?? null,
                'explanation_en' => $explanation['explanation_en'] ?? null,
                'top_factors' => array_slice($factors, 0, 5),
                'modifications' => $mods['modifications'],
                'expected_score' => $mods['expected_score'],
                'cultural_flags' => $flags,
                'alternatives' => $alternatives,
                'suggested_launch' => $result['launch']['date'] ?? null,
                'is_novel' => $result['is_novel'],
                'ai_cost' => (float) $evaluation->ai_cost + $ai->cost,
            ]);
        } catch (Throwable $e) {
            report($e);
            $evaluation->update(['status' => 'failed', 'error' => $e->getMessage()]);

            return $evaluation;
        }

        if ($notify && $evaluation->user_id) {
            $this->notify($evaluation);
        }

        return $evaluation;
    }

    private function notify(Evaluation $evaluation): void
    {
        $user = User::query()->find($evaluation->user_id);

        $decision = [
            'accept' => ['قبول', 'Accept', 'success'],
            'reject' => ['رفض', 'Reject', 'danger'],
            'modify' => ['تعديل', 'Modify', 'warning'],
        ][$evaluation->recommendation] ?? ['—', '—', 'info'];

        $user?->notify(new PlatformNotification(
            kind: 'evaluation',
            titleAr: "اكتمل تقييم «{$evaluation->name}»",
            titleEn: "Evaluation of “{$evaluation->name}” is ready",
            bodyAr: "الدرجة {$evaluation->score}/100 — التوصية: {$decision[0]}",
            bodyEn: "Score {$evaluation->score}/100 — recommendation: {$decision[1]}",
            url: "/store/evaluations/{$evaluation->id}",
            storeId: $evaluation->store_id,
            level: $decision[2],
        ));
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are طوق, a product strategy advisor for e-commerce stores in Saudi Arabia.
        You receive a candidate product, the score the platform already computed from the
        available store data, and the evidence behind it. These numbers are a baseline:
        never invent or recompute them. The platform will recalculate the final score
        after your visual observations. Avoid quoting a final score or decision in prose.

        Write for a busy store owner. explanation_ar is in clear Modern Standard Arabic,
        explanation_en in plain English; 3-5 sentences each, citing the evidence (similar
        products by name, matched trends, price band, season).

        top_factors: the 3-5 criteria that moved the score most, using the given criterion keys.

        cultural_flags: inspect the image and text for anything inappropriate for Saudi
        culture or Islamic values (symbols, imagery, wording, revealing designs). Return an
        empty list when nothing is wrong; do not flag ordinary designs.

        advice: up to 3 short, concrete, practical tips beyond the listed modifications.
        TXT;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function prompt(Evaluation $e, array $result): string
    {
        $candidate = [
            'name' => $e->name,
            'category' => $e->category,
            'price_sar' => $e->price,
            'colors' => array_map(fn ($c) => ExplanationWriter::term('colors', $c), $e->colors ?? []),
            'pattern' => ExplanationWriter::term('patterns', $e->pattern),
            'material' => ExplanationWriter::term('materials', $e->material),
            'style' => ExplanationWriter::term('styles', $e->style),
            'season' => ExplanationWriter::term('seasons', $e->season),
            'description' => $e->description,
        ];

        return "Candidate:\n".json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nScore: {$result['score']}/100, recommendation: {$result['recommendation']}"
            ."\n\nCriteria (0-100, higher is better; weight in %):\n".json_encode($result['criteria'], JSON_PRETTY_PRINT)
            ."\n\nEvidence:\n".json_encode($result['evidence'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $bilingual = fn (array $extra = []) => [
            'type' => 'object',
            'properties' => [...$extra, 'ar' => ['type' => 'string'], 'en' => ['type' => 'string']],
            'required' => [...array_keys($extra), 'ar', 'en'],
        ];

        return [
            'type' => 'object',
            'properties' => [
                'explanation_ar' => ['type' => 'string'],
                'explanation_en' => ['type' => 'string'],
                'top_factors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'criterion' => ['type' => 'string', 'enum' => self::CRITERIA_ENUM],
                            'impact' => ['type' => 'string', 'enum' => ['positive', 'negative']],
                            'detail_ar' => ['type' => 'string'],
                            'detail_en' => ['type' => 'string'],
                        ],
                        'required' => ['criterion', 'impact', 'detail_ar', 'detail_en'],
                    ],
                ],
                'cultural_flags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                            'issue_ar' => ['type' => 'string'],
                            'issue_en' => ['type' => 'string'],
                        ],
                        'required' => ['severity', 'issue_ar', 'issue_en'],
                    ],
                ],
                'advice' => ['type' => 'array', 'items' => $bilingual()],
                'specialist_review' => $this->reviewer->schema(),
            ],
            'required' => ['explanation_ar', 'explanation_en', 'top_factors', 'cultural_flags', 'advice', 'specialist_review'],
        ];
    }

    /**
     * @return list<array{mime: string, data: string}>
     */
    private function image(Evaluation $e): array
    {
        $disk = Storage::disk('public');

        if (! $e->image_path || ! $disk->exists($e->image_path) || $disk->size($e->image_path) > 8_388_608) {
            return [];
        }

        if (! in_array($disk->mimeType($e->image_path), ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return [];
        }

        return [[
            'mime' => $disk->mimeType($e->image_path) ?: 'image/jpeg',
            'data' => base64_encode($disk->get($e->image_path)),
        ]];
    }

    /**
     * @param  mixed  $flags
     * @return list<array{severity: string, issue_ar: string, issue_en: string}>
     */
    private function validFlags($flags): array
    {
        return collect(is_array($flags) ? $flags : [])
            ->filter(fn ($f) => is_array($f) && in_array($f['severity'] ?? null, ['low', 'medium', 'high'], true))
            ->map(fn ($f) => ['severity' => $f['severity'], 'issue_ar' => (string) ($f['issue_ar'] ?? ''), 'issue_en' => (string) ($f['issue_en'] ?? '')])
            ->values()
            ->all();
    }
}
