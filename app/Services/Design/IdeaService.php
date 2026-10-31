<?php

namespace App\Services\Design;

use App\Models\SeasonalEvent;
use App\Models\Store;
use App\Models\Trend;
use App\Services\Ai\Ai;
use App\Services\Evaluation\Candidate;
use App\Services\Evaluation\EvaluationEngine;
use App\Services\Evaluation\StoreContext;
use App\Support\Vocabulary;
use Illuminate\Support\Collection;

/**
 * Proactive new-product ideas (FR-4.4): the store's winning attributes
 * crossed with rising market trends and the next season. Gemini proposes
 * and names them; every idea is then scored by the evaluation engine so
 * only ideas the data supports rise to the top.
 */
class IdeaService
{
    public function __construct(
        private readonly Ai $ai,
        private readonly EvaluationEngine $engine,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function suggest(Store $store): array
    {
        $ctx = new StoreContext($store);
        $brief = $this->brief($ctx);

        $result = $this->ai->json(
            operation: 'idea',
            store: $store,
            system: 'You are Mojo AI, a product strategist for Saudi e-commerce stores. Propose new, production-ready product ideas '
                .'that combine the store\'s proven best-seller attributes with rising market trends and the next Saudi season. '
                .'Use only the attribute keys provided in the vocabulary. Titles and descriptions in Arabic (Modern Standard) and English. '
                .'The "prompt" field is an English image-generation brief for the product.',
            prompt: json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            schema: $this->schema(),
            fallback: fn () => ['ideas' => $this->fallback($ctx, $brief)],
        );

        return collect($result->data['ideas'] ?? [])
            ->map(fn ($idea) => $this->clean(is_array($idea) ? $idea : [], $ctx))
            ->filter(fn ($idea) => $idea['title_ar'] || $idea['title_en'])
            ->map(function ($idea) use ($ctx, $result) {
                $score = $this->engine->score(new Candidate(
                    $idea['category'], $idea['price'], $idea['colors'], $idea['pattern'], $idea['material'], $idea['style'], $idea['season'],
                ), $ctx);

                return [...$idea, 'score' => $score['score'], 'recommendation' => $score['recommendation'], 'from_model' => $result->fromModel];
            })
            ->sortByDesc('score')
            ->values()
            ->take(6)
            ->all();
    }

    /**
     * What the model (or the fallback) works from.
     *
     * @return array<string, mixed>
     */
    private function brief(StoreContext $ctx): array
    {
        $categories = $ctx->winners->groupBy(fn ($w) => $w['candidate']->category)
            ->map(fn (Collection $items, $category) => [
                'category' => $category,
                'best_sellers' => $items->count(),
                'top_colors' => $this->top($items->pluck('candidate.colors')->flatten(), 3),
                'top_patterns' => $this->top($items->pluck('candidate.pattern'), 2),
                'top_materials' => $this->top($items->pluck('candidate.material'), 2),
                'top_styles' => $this->top($items->pluck('candidate.style'), 2),
                'median_price' => $items->pluck('candidate.price')->filter()->median(),
            ])
            ->sortByDesc('best_sellers')
            ->values()
            ->take(3)
            ->all();

        $next = $ctx->upcomingEvents->first(fn (SeasonalEvent $e) => $e->season_key && $e->starts_on->isFuture());

        return [
            'categories' => $categories,
            'rising_trends' => $ctx->trends->where('status', 'rising')->sortByDesc('momentum')->take(8)
                ->map(fn (Trend $t) => ['type' => $t->type, 'value' => $t->value, 'name_en' => $t->name_en, 'momentum' => $t->momentum, 'saturation' => $t->saturation, 'in_catalog' => $t->in_catalog])
                ->values()->all(),
            'next_season' => $next ? ['key' => $next->season_key, 'name_en' => $next->name_en, 'starts_on' => $next->starts_on->toDateString()] : null,
            'vocabulary' => [
                'colors' => array_keys(Vocabulary::colors()),
                'patterns' => array_keys(Vocabulary::patterns()),
                'materials' => array_keys(Vocabulary::materials()),
                'styles' => array_keys(Vocabulary::styles()),
                'seasons' => array_keys(Vocabulary::seasons()),
            ],
        ];
    }

    /**
     * Deterministic ideas: best-seller DNA × each rising trend.
     *
     * @param  array<string, mixed>  $brief
     * @return list<array<string, mixed>>
     */
    private function fallback(StoreContext $ctx, array $brief): array
    {
        $season = $brief['next_season']['key'] ?? 'all_year';
        $ideas = [];

        foreach (array_slice($brief['categories'], 0, 2) as $ci => $cat) {
            $base = [
                'category' => $cat['category'],
                'price' => $cat['median_price'],
                'colors' => array_slice($cat['top_colors'], 0, 2),
                'pattern' => $cat['top_patterns'][0] ?? 'solid',
                'material' => $cat['top_materials'][0] ?? null,
                'style' => $cat['top_styles'][0] ?? 'modern',
                'season' => $season,
            ];

            $trends = collect($brief['rising_trends'])->whereIn('type', ['color', 'pattern', 'style'])->slice($ci, 3);

            foreach ($trends as $trend) {
                $idea = $base;
                $field = $trend['type'] === 'color' ? 'colors' : $trend['type'];
                $idea[$field] = $field === 'colors' ? array_values(array_unique([$trend['value'], $base['colors'][0] ?? 'beige'])) : $trend['value'];

                $patternAr = Vocabulary::label('patterns', $idea['pattern'], 'ar');
                $patternEn = Vocabulary::label('patterns', $idea['pattern'], 'en');
                $colorAr = Vocabulary::label('colors', $idea['colors'][0] ?? null, 'ar');
                $colorEn = Vocabulary::label('colors', $idea['colors'][0] ?? null, 'en');
                $trendAr = Vocabulary::label($field === 'colors' ? 'colors' : $field.'s', $trend['value'], 'ar');

                $ideas[] = [
                    ...$idea,
                    'title_ar' => "{$cat['category']} {$patternAr} بلون {$colorAr}",
                    'title_en' => "{$patternEn} {$cat['category']} in {$colorEn}",
                    'description_ar' => "يجمع سمات منتجاتك الرابحة مع توجه «{$trendAr}» الصاعد (زخم {$trend['momentum']}/100) استعدادًا للموسم القادم.",
                    'description_en' => "Combines your best-seller attributes with the rising “{$trend['name_en']}” trend (momentum {$trend['momentum']}/100) ahead of the next season.",
                    'prompt' => "{$patternEn} {$cat['category']} in {$colorEn}, ".Vocabulary::label('styles', $idea['style'], 'en').' style',
                ];
            }
        }

        return $ideas;
    }

    /**
     * Keep only vocabulary keys from model output.
     *
     * @param  array<string, mixed>  $idea
     * @return array<string, mixed>
     */
    private function clean(array $idea, StoreContext $ctx): array
    {
        $in = fn ($value, array $list) => is_string($value) && array_key_exists($value, $list) ? $value : null;

        return [
            'title_ar' => (string) ($idea['title_ar'] ?? ''),
            'title_en' => (string) ($idea['title_en'] ?? ''),
            'description_ar' => (string) ($idea['description_ar'] ?? ''),
            'description_en' => (string) ($idea['description_en'] ?? ''),
            'category' => is_string($idea['category'] ?? null) ? $idea['category'] : $ctx->winners->first()['candidate']->category ?? null,
            'price' => is_numeric($idea['price'] ?? null) ? (float) $idea['price'] : null,
            'colors' => array_values(array_filter((array) ($idea['colors'] ?? []), fn ($c) => is_string($c) && array_key_exists($c, Vocabulary::colors()))),
            'pattern' => $in($idea['pattern'] ?? null, Vocabulary::patterns()),
            'material' => $in($idea['material'] ?? null, Vocabulary::materials()),
            'style' => $in($idea['style'] ?? null, Vocabulary::styles()),
            'season' => $in($idea['season'] ?? null, Vocabulary::seasons()),
            'prompt' => (string) ($idea['prompt'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ideas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title_ar' => ['type' => 'string'],
                            'title_en' => ['type' => 'string'],
                            'description_ar' => ['type' => 'string'],
                            'description_en' => ['type' => 'string'],
                            'category' => ['type' => 'string'],
                            'price' => ['type' => 'number'],
                            'colors' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Vocabulary::colors())]],
                            'pattern' => ['type' => 'string', 'enum' => array_keys(Vocabulary::patterns())],
                            'material' => ['type' => 'string', 'enum' => array_keys(Vocabulary::materials())],
                            'style' => ['type' => 'string', 'enum' => array_keys(Vocabulary::styles())],
                            'season' => ['type' => 'string', 'enum' => array_keys(Vocabulary::seasons())],
                            'prompt' => ['type' => 'string'],
                        ],
                        'required' => ['title_ar', 'title_en', 'description_ar', 'description_en', 'category', 'colors', 'pattern', 'style', 'season', 'prompt'],
                    ],
                ],
            ],
            'required' => ['ideas'],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return list<string>
     */
    private function top(Collection $values, int $n): array
    {
        return $values->filter()->countBy()->sortDesc()->keys()->take($n)->values()->all();
    }
}
