<?php

namespace App\Services\Evaluation;

use App\Support\Vocabulary;

/**
 * Plain-language explanation built from the engine's numbers. Used when
 * the model is unavailable (demo mode, budget reached, API error) and as
 * the factual brief the model rewrites in better prose.
 */
class ExplanationWriter
{
    private const LABELS = [
        'success_similarity' => ['ar' => 'التشابه مع المنتجات الناجحة', 'en' => 'Similarity to best sellers'],
        'trend_fit' => ['ar' => 'التوافق مع توجهات السوق', 'en' => 'Market trend fit'],
        'saudi_fit' => ['ar' => 'ملاءمة السوق السعودي', 'en' => 'Saudi market fit'],
        'failure_risk' => ['ar' => 'البعد عن الإخفاقات السابقة', 'en' => 'Distance from past failures'],
        'saturation' => ['ar' => 'انخفاض تشبّع السوق', 'en' => 'Low market saturation'],
        'price_fit' => ['ar' => 'ملاءمة السعر', 'en' => 'Price fit'],
    ];

    public static function label(string $criterion, string $locale): string
    {
        return self::LABELS[$criterion][$locale] ?? $criterion;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{explanation_ar: string, explanation_en: string, top_factors: list<array<string, mixed>>, cultural_flags: list<array<string, mixed>>, advice: list<array<string, string>>}
     */
    public function write(array $result): array
    {
        $factors = $this->factors($result);
        $positives = array_values(array_filter($factors, fn ($f) => $f['impact'] === 'positive'));
        $negatives = array_values(array_filter($factors, fn ($f) => $f['impact'] === 'negative'));

        $decisionAr = ['accept' => 'القبول', 'reject' => 'الرفض', 'modify' => 'التعديل والمواءمة'][$result['recommendation']];
        $decisionEn = ['accept' => 'accept', 'reject' => 'reject', 'modify' => 'modify'][$result['recommendation']];

        $ar = "حصل المنتج على {$result['score']} من 100، والتوصية هي {$decisionAr}.";
        $en = "The product scored {$result['score']} out of 100; the recommendation is to {$decisionEn}.";

        if ($positives) {
            $ar .= ' أبرز نقاط القوة: '.implode('، ', array_column(array_slice($positives, 0, 2), 'label_ar')).'.';
            $en .= ' Main strengths: '.implode(', ', array_column(array_slice($positives, 0, 2), 'label_en')).'.';
        }

        if ($negatives) {
            $ar .= ' أبرز نقاط الضعف: '.implode('، ', array_column(array_slice($negatives, 0, 2), 'label_ar')).'.';
            $en .= ' Main weaknesses: '.implode(', ', array_column(array_slice($negatives, 0, 2), 'label_en')).'.';
        }

        $ev = $result['evidence'];
        if (! empty($ev['similar_stagnant'])) {
            $name = $ev['similar_stagnant'][0]['name'];
            $ar .= " يشبه المنتج «{$name}» الذي لم يحقق مبيعات جيدة في المتجر.";
            $en .= " It resembles “{$name}”, which did not sell well in this store.";
        }

        if (! empty($ev['launch'])) {
            $ar .= " التوقيت المقترح للطرح: {$ev['launch']['date']} استعدادًا لموسم {$ev['launch']['event_ar']}.";
            $en .= " Suggested launch: {$ev['launch']['date']}, ahead of {$ev['launch']['event_en']}.";
        }

        return [
            'explanation_ar' => $ar,
            'explanation_en' => $en,
            // factors() is already ranked by impact, strongest first.
            'top_factors' => array_slice($factors, 0, 5),
            'cultural_flags' => [],
            'advice' => [],
        ];
    }

    /**
     * Criteria ranked by how much they pulled the score up or down.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    public function factors(array $result): array
    {
        $ev = $result['evidence'];

        return collect($result['criteria'])
            ->map(function ($c, $key) use ($ev) {
                [$detailAr, $detailEn] = $this->detail($key, $c['score'], $ev);

                return [
                    'criterion' => $key,
                    'label_ar' => self::label($key, 'ar'),
                    'label_en' => self::label($key, 'en'),
                    'impact' => $c['score'] >= 60 ? 'positive' : 'negative',
                    'weight' => abs($c['score'] - 60) * $c['weight'],
                    'detail_ar' => $detailAr,
                    'detail_en' => $detailEn,
                ];
            })
            ->sortByDesc('weight')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $ev
     * @return array{0: string, 1: string}
     */
    private function detail(string $key, int $score, array $ev): array
    {
        return match ($key) {
            'success_similarity' => ! empty($ev['similar_winners'])
                ? ["الأقرب إليه من الرابحة: «{$ev['similar_winners'][0]['name']}» بنسبة تشابه {$ev['similar_winners'][0]['similarity']}%.",
                    "Closest best seller: “{$ev['similar_winners'][0]['name']}” ({$ev['similar_winners'][0]['similarity']}% similar)."]
                : ['لا توجد منتجات رابحة كافية للمقارنة في هذه الفئة.', 'Not enough best sellers in this category to compare.'],
            'trend_fit' => ! empty($ev['matched_trends'])
                ? ['يتوافق مع: '.implode('، ', array_column($ev['matched_trends'], 'name_ar')).'.',
                    'Matches: '.implode(', ', array_column($ev['matched_trends'], 'name_en')).'.']
                : ['لا يتوافق مع أي توجه صاعد حاليًا.', 'Does not match any rising trend right now.'],
            'saudi_fit' => ! empty($ev['launch'])
                ? ["مناسب لموسم {$ev['launch']['event_ar']} القادم.", "Fits the upcoming {$ev['launch']['event_en']} season."]
                : (! empty($ev['season_mismatch'])
                    ? ['الموسم المستهدف لا يتوافق مع المواسم القادمة.', 'The target season is not coming up soon.']
                    : ['ملاءمة جيدة للذوق المحلي عمومًا.', 'Generally suits local taste.']),
            'failure_risk' => ! empty($ev['similar_stagnant'])
                ? ["يشبه منتجًا راكدًا: «{$ev['similar_stagnant'][0]['name']}» ({$ev['similar_stagnant'][0]['similarity']}%).",
                    "Resembles a slow seller: “{$ev['similar_stagnant'][0]['name']}” ({$ev['similar_stagnant'][0]['similarity']}%)."]
                : ['لا يشبه أيًّا من المنتجات الراكدة.', 'Does not resemble any slow sellers.'],
            'saturation' => ["{$ev['competitor_overlap']['similar']} من {$ev['competitor_overlap']['total']} منتجًا لدى المنافسين مشابهة، و{$ev['self_repetition']} طرح مشابه حديث في المتجر.",
                "{$ev['competitor_overlap']['similar']} of {$ev['competitor_overlap']['total']} competitor products are similar; {$ev['self_repetition']} similar recent launches in the store."],
            'price_fit' => isset($ev['price_band'])
                ? ["الشريحة الناجحة في الفئة: {$ev['price_band']['low']}–{$ev['price_band']['high']} ر.س.",
                    "Winning price band in this category: SAR {$ev['price_band']['low']}–{$ev['price_band']['high']}."]
                : ['لا توجد بيانات سعرية كافية.', 'Not enough price data.'],
            default => ['', ''],
        };
    }

    /**
     * Human label for a vocabulary key (used in prompts).
     */
    public static function term(string $group, ?string $key): string
    {
        return $key ? (Vocabulary::label($group, $key, 'en') ?? $key) : '—';
    }
}
