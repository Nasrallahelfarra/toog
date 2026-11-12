<?php

namespace App\Services\Market;

use App\Models\SocialPost;
use App\Support\FashionVocabulary;

/**
 * What a competitor post shows and how it is pitched, read from its own
 * caption, hashtags and type: the content format, the hooks (offer, new
 * arrival, styling, giveaway…), the garment, colours, pattern and the
 * occasion. Captions are untrusted text; these are keyword matches only.
 */
class PostTagger
{
    /** Hooks: how the post sells. Arabic and English keywords. */
    public const HOOKS = [
        // "كود المنتج" is a product code, not a discount code.
        'offer' => ['خصم', 'تخفيض', 'تخفيضات', 'عرض', 'عروض', 'كود خصم', 'كود الخصم', 'بكود', '%', 'sale', 'off', 'discount', 'promo'],
        'new_arrival' => ['جديد', 'جديدنا', 'وصل', 'وصلت', 'نزل', 'نزلت', 'كولكشن', 'تشكيلة', 'new in', 'new arrival', 'collection', 'launch'],
        'limited' => ['آخر قطع', 'اخر قطع', 'كمية محدودة', 'محدود', 'limited', 'last pieces'],
        'restock' => ['رجع', 'رجعت', 'توفر من جديد', 'متوفر من جديد', 'restock', 'back in stock'],
        'sold_out' => ['نفد', 'نفذ', 'خلصت', 'sold out'],
        'styling' => ['تنسيق', 'تنسيقة', 'لوك', 'طريقة لبس', 'ستايل', 'ootd', 'look', 'how to wear', 'styling'],
        'try_on' => ['مقاس', 'المقاس', 'طول العارضة', 'قياس', 'تجربة', 'try on', 'try-on', 'fit'],
        'price_shown' => ['ريال', 'ر.س', 'sar', 'بسعر', 'السعر'],
        'details' => ['تفاصيل', 'خامة', 'الخامة', 'قماش', 'تطريز يدوي', 'details', 'fabric', 'handmade'],
        'review' => ['رأي', 'تجربة عميلة', 'عميلتنا', 'ريفيو', 'review', 'feedback'],
        'collab' => ['تعاون', 'بالتعاون', 'collab', 'collaboration', 'ft.', 'feat'],
        'giveaway' => ['سحب', 'مسابقة', 'منشن', 'هدية للفائز', 'giveaway', 'contest'],
        'call_to_order' => ['للطلب', 'اطلبي', 'اطلبيها', 'الرابط في البايو', 'واتساب', 'link in bio', 'order now', 'dm'],
    ];

    /** Occasions read from the caption, beyond the designer's own list. */
    private const OCCASIONS = [
        'ramadan' => ['رمضان', 'رمضاني', 'ramadan'],
        'eid' => ['عيد', 'العيد', 'eid'],
        'wedding_guest' => ['زواج', 'عرس', 'أعراس', 'ملكة', 'مناسبة', 'مناسبات', 'wedding', 'occasion'],
        'national' => ['اليوم الوطني', 'يوم التأسيس', 'وطني', 'national day', 'founding day'],
        'graduation' => ['تخرج', 'التخرج', 'graduation'],
        'summer' => ['صيف', 'صيفي', 'summer'],
        'winter' => ['شتاء', 'شتوي', 'winter'],
        'work' => ['دوام', 'جامعة', 'work', 'office'],
        'evening' => ['سهرة', 'سهرات', 'evening'],
    ];

    /**
     * @return array{format: string, hooks: list<string>, garment: string|null, colors: list<string>, pattern: string|null, style: string|null, occasion: string|null, flags: list<string>, codes: list<string>}
     */
    public function tag(SocialPost $post): array
    {
        $hashtags = (array) ($post->evidence['hashtags'] ?? []);
        $text = self::normalize(trim(($post->caption ?? '').' '.implode(' ', $hashtags)));
        $features = $post->features ?? [];
        $hooks = array_keys(array_filter(self::HOOKS, fn (array $words) => $this->has($text, $words)));
        $occasion = collect(self::OCCASIONS)->search(fn (array $words) => $this->has($text, $words)) ?: null;

        $flags = [];
        if (in_array('giveaway', $hooks, true)) {
            $flags[] = 'giveaway';
        }
        // A mention of another account usually means a collaboration.
        if (in_array('collab', $hooks, true) || preg_match('/(^|\s)@[\p{L}\p{N}_.]{3,}/u', (string) $post->caption)) {
            $flags[] = 'collab';
        }
        if (! empty($post->evidence['likes_hidden'])) {
            $flags[] = 'comments_only';
        }

        return [
            'format' => $this->format($post),
            'hooks' => array_values($hooks),
            'garment' => FashionVocabulary::garmentFor($text),
            'colors' => array_values(array_filter((array) ($features['colors'] ?? []), 'is_string')),
            'pattern' => is_string($features['pattern'] ?? null) ? $features['pattern'] : null,
            'style' => is_string($features['style'] ?? null) ? $features['style'] : null,
            'occasion' => is_string($occasion) ? $occasion : (is_string($features['season'] ?? null) && $features['season'] !== 'all_year' ? $features['season'] : null),
            'flags' => $flags,
            'codes' => self::codes((string) $post->caption),
        ];
    }

    /**
     * Product codes the post names ("رقم المنتج: 11134", "كود المنتج 198634",
     * "SKU 55102"), to match the competitor's own store products.
     *
     * @return list<string>
     */
    public static function codes(string $caption): array
    {
        $caption = strtr($caption, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        preg_match_all('/(?:(?:رقم|كود|رمز)\s*(?:ال)?(?:منتج|موديل|قطعة)|sku|item\s*(?:no|#)|code)\s*[:#\-]?\s*([A-Za-z0-9][A-Za-z0-9\-]{2,20})/iu', $caption, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Lower-case, and fold stretched letters ("فسااتييين", "خياااال") so
     * keywords still match.
     */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return (string) preg_replace('/(\p{Arabic})\1+/u', '$1', $text);
    }

    /**
     * Video, album, image or text; older posts without a type that carry
     * views were videos.
     */
    public function format(SocialPost $post): string
    {
        $type = $post->evidence['type'] ?? null;

        return match (true) {
            in_array($type, ['video', 'reel', 'clip'], true) => 'video',
            in_array($type, ['carousel', 'sidecar', 'album'], true) => 'carousel',
            $type === 'text' => 'text',
            $type === 'image' => 'image',
            $post->views > 0 => 'video',
            default => 'image',
        };
    }

    /**
     * @param  list<string>  $words
     */
    private function has(string $text, array $words): bool
    {
        foreach ($words as $word) {
            $word = mb_strtolower($word);
            // Short Latin words must stand alone ("off" is not "office").
            $hit = preg_match('/^[a-z.]{1,4}$/', $word)
                ? preg_match('/(^|[^a-z])'.preg_quote($word, '/').'([^a-z]|$)/u', $text)
                : str_contains($text, $word);
            if ($hit) {
                return true;
            }
        }

        return false;
    }
}
