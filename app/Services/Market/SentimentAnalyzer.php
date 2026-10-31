<?php

namespace App\Services\Market;

use App\Models\CustomerReview;
use App\Models\Store;
use App\Services\Ai\Ai;
use Illuminate\Support\Collection;

/**
 * Customer review analysis (FR-2.4): sentiment and topics for Arabic —
 * including Saudi dialect — and English reviews. Gemini reads them in
 * batches; a keyword model answers in demo mode or on failure.
 */
class SentimentAnalyzer
{
    public const TOPICS = ['fabric', 'fit', 'sizing', 'color_accuracy', 'quality', 'price', 'delivery', 'packaging', 'design', 'service'];

    // Base forms only: Arabic matching is by substring, so "فخم" also
    // covers "فخمة" and each word counts once.
    private const POSITIVE = [
        'حلو', 'رهيب', 'فخم', 'ممتاز', 'رائع', 'يجنن', 'تجنن', 'جميل', 'راقي', 'انصح', 'أنصح', 'شكرا', 'تستاهل', 'يستاهل',
        'مرتب', 'ناعم', 'سريع', 'love', 'great', 'beautiful', 'perfect', 'lovely', 'excellent', 'amazing', 'fast',
    ];

    private const NEGATIVE = [
        'سيء', 'سيئ', 'غالي', 'تأخر', 'مكسور', 'يكرمش', 'تكرمش', 'ما عجب', 'مو حلو', 'غير حلو', 'خفيفة مره', 'أصغر من',
        'اصغر من', 'غير عن الصورة', 'قديمة', 'للأسف', 'bad', 'broken', 'late', 'disappoint', 'poor', 'small', 'expensive',
        'wrinkle',
    ];

    private const TOPIC_WORDS = [
        'fabric' => ['قماش', 'خامة', 'الخامة', 'كتان', 'fabric', 'linen', 'material'],
        'fit' => ['القصة', 'قصة', 'fit', 'cut'],
        'sizing' => ['مقاس', 'المقاس', 'أصغر', 'اصغر', 'size'],
        'color_accuracy' => ['اللون', 'لون', 'colour', 'color'],
        'quality' => ['جودة', 'الجودة', 'quality', 'ريحة'],
        'price' => ['سعر', 'السعر', 'غالي', 'price', 'expensive'],
        'delivery' => ['التوصيل', 'توصيل', 'وصل', 'وصلني', 'تأخر', 'delivery', 'shipping'],
        'packaging' => ['تغليف', 'التغليف', 'مكسور', 'packaging', 'box'],
        'design' => ['تصميم', 'نقشة', 'النقشة', 'تطريز', 'التطريز', 'الخط', 'design', 'pattern', 'calligraphy'],
        'service' => ['خدمة', 'العملاء', 'تجاوب', 'service'],
    ];

    public function __construct(private readonly Ai $ai) {}

    /**
     * Analyse up to $limit unanalysed reviews of a store.
     *
     * @return int reviews analysed
     */
    public function analyzePending(Store $store, int $limit = 40, ?int $userId = null): int
    {
        $reviews = CustomerReview::query()
            ->where('store_id', $store->id)
            ->whereNull('analyzed_at')
            ->limit($limit)
            ->get();

        if ($reviews->isEmpty()) {
            return 0;
        }

        $result = $this->ai->json(
            operation: 'sentiment',
            store: $store,
            system: $this->system(),
            prompt: $reviews->map(fn (CustomerReview $r) => "[{$r->id}] ".str_replace("\n", ' ', $r->text))->implode("\n"),
            schema: $this->schema(),
            fallback: fn () => ['results' => $reviews->map(fn (CustomerReview $r) => ['id' => $r->id, ...$this->keywords($r->text, $r->rating)])->all()],
            userId: $userId,
        );

        $byId = collect($result->data['results'] ?? [])->keyBy('id');

        foreach ($reviews as $review) {
            $row = $byId->get($review->id) ?? ['id' => $review->id, ...$this->keywords($review->text, $review->rating)];

            $review->update([
                'sentiment' => in_array($row['sentiment'] ?? null, ['positive', 'neutral', 'negative'], true) ? $row['sentiment'] : 'neutral',
                'sentiment_score' => max(-1, min(1, (float) ($row['score'] ?? 0))),
                'topics' => array_values(array_intersect($row['topics'] ?? [], self::TOPICS)) ?: null,
                'language' => $review->language ?? ($row['language'] ?? $this->language($review->text)),
                'analyzed_at' => now(),
            ]);
        }

        return $reviews->count();
    }

    /**
     * Deterministic sentiment: keyword hits, with the star rating as a tie-breaker.
     *
     * @return array{sentiment: string, score: float, topics: list<string>, language: string}
     */
    public function keywords(string $text, ?int $rating = null): array
    {
        $lower = mb_strtolower($text);
        $hits = fn (array $words) => collect($words)->filter(fn ($w) => str_contains($lower, mb_strtolower($w)))->count();

        $negative = $hits(self::NEGATIVE);
        // Negated praise ("ما عجبني") must not also count as praise.
        $positive = max(0, $hits(self::POSITIVE) - (str_contains($lower, 'ما عجب') ? 1 : 0));

        $score = ($positive - $negative) / max(1, $positive + $negative);
        if ($positive === $negative && $rating !== null) {
            $score = ($rating - 3) / 2;
        }

        return [
            'sentiment' => $score > 0.2 ? 'positive' : ($score < -0.2 ? 'negative' : 'neutral'),
            'score' => round($score, 2),
            'topics' => collect(self::TOPIC_WORDS)
                ->filter(fn ($words) => $hits($words) > 0)
                ->keys()
                ->values()
                ->all(),
            'language' => $this->language($text),
        ];
    }

    /**
     * Topic counts split by sentiment, for the insights panel.
     *
     * @param  Collection<int, CustomerReview>  $reviews
     * @return array{positive: array<string, int>, negative: array<string, int>}
     */
    public function topicSummary(Collection $reviews): array
    {
        $count = fn (string $sentiment) => $reviews->where('sentiment', $sentiment)
            ->pluck('topics')->flatten()->filter()->countBy()->sortDesc()->take(6)->all();

        return ['positive' => $count('positive'), 'negative' => $count('negative')];
    }

    private function language(string $text): string
    {
        return preg_match('/\p{Arabic}/u', $text) ? 'ar' : 'en';
    }

    private function system(): string
    {
        return 'You analyse e-commerce customer reviews for a Saudi store. Reviews are in Arabic '
            .'(often Saudi/Gulf dialect, e.g. "مره حلو", "رهيب", "ما عجبني") or English. For each review return '
            .'its id, sentiment (positive / neutral / negative), a score from -1 to 1, the topics it mentions '
            .'(only from the allowed list) and its language. Mixed reviews ("delivery was late but the abaya is '
            .'beautiful") are neutral unless one side clearly dominates.';
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'sentiment' => ['type' => 'string', 'enum' => ['positive', 'neutral', 'negative']],
                            'score' => ['type' => 'number'],
                            'topics' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::TOPICS]],
                            'language' => ['type' => 'string', 'enum' => ['ar', 'en']],
                        ],
                        'required' => ['id', 'sentiment', 'score', 'topics', 'language'],
                    ],
                ],
            ],
            'required' => ['results'],
        ];
    }
}
