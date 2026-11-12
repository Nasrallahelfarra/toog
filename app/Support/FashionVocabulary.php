<?php

namespace App\Support;

/**
 * The AI fashion designer's own vocabulary: garments, fabrics and the
 * construction details a designer hands to a workshop. Keys are stored;
 * labels are bilingual. Fabrics carry a `family` that is an existing
 * Vocabulary material key, so evaluations still match older products.
 */
class FashionVocabulary
{
    /**
     * @return array<string, array{ar: string, en: string, store_words: list<string>}>
     */
    public static function garments(): array
    {
        return [
            'abaya' => ['ar' => 'عباية', 'en' => 'Abaya', 'store_words' => ['عباي', 'abaya']],
            'dress_daily' => ['ar' => 'فستان يومي', 'en' => 'Day dress', 'store_words' => ['فستان', 'فساتين', 'dress']],
            'dress_evening' => ['ar' => 'فستان سهرة', 'en' => 'Evening dress', 'store_words' => ['سهر', 'evening', 'فستان', 'فساتين']],
            'jalabiya' => ['ar' => 'جلابية', 'en' => 'Jalabiya', 'store_words' => ['جلابي', 'jalabiya', 'jalabia']],
            'kaftan' => ['ar' => 'قفطان', 'en' => 'Kaftan', 'store_words' => ['قفطان', 'قفاطين', 'kaftan', 'caftan']],
            'kimono' => ['ar' => 'كيمونو', 'en' => 'Kimono', 'store_words' => ['كيمون', 'kimono']],
            'coord_set' => ['ar' => 'طقم قطعتين', 'en' => 'Co-ord set', 'store_words' => ['طقم', 'أطقم', 'set']],
            'prayer_set' => ['ar' => 'جلال صلاة', 'en' => 'Prayer set', 'store_words' => ['جلال', 'صلاة', 'prayer']],
        ];
    }

    /**
     * Textile fabrics. `family` is a Vocabulary material key (or null),
     * `sheer` forces a lining, `seasons` hints when the fabric suits.
     *
     * @return array<string, array{ar: string, en: string, family: string|null, sheer: bool, weight: string, drape_ar: string, drape_en: string, seasons: list<string>}>
     */
    public static function fabrics(): array
    {
        $f = fn (string $ar, string $en, ?string $family, string $weight, string $drapeAr, string $drapeEn, array $seasons = [], bool $sheer = false) => [
            'ar' => $ar, 'en' => $en, 'family' => $family, 'sheer' => $sheer, 'weight' => $weight,
            'drape_ar' => $drapeAr, 'drape_en' => $drapeEn, 'seasons' => $seasons,
        ];

        return [
            'nada' => $f('ندى', 'Nada crepe', 'crepe', 'light', 'انسدال ناعم وثقيل قليلًا', 'soft, weighty drape', ['summer', 'ramadan']),
            'korean_crepe' => $f('كريب كوري', 'Korean crepe', 'crepe', 'medium', 'انسدال مرن لا يتجعّد', 'fluid, crease-resistant', ['eid_fitr', 'eid_adha']),
            'crepe' => $f('كريب', 'Crepe', 'crepe', 'medium', 'ملمس حبيبي وانسدال متوسط', 'grainy, medium drape'),
            'crinkle' => $f('كرنكل', 'Crinkle', 'crepe', 'light', 'تجعيد طبيعي خفيف', 'natural crinkled texture', ['summer']),
            'chiffon' => $f('شيفون', 'Chiffon', 'chiffon', 'light', 'شفاف وطائر يحتاج بطانة', 'sheer and airy, needs lining', ['summer', 'wedding'], true),
            'georgette' => $f('جورجيت', 'Georgette', 'chiffon', 'light', 'شبه شفاف بملمس حبيبي', 'semi-sheer, grainy', ['summer'], true),
            'organza' => $f('أورجانزا', 'Organza', 'chiffon', 'light', 'متماسك ولامع يصنع حجمًا', 'crisp, sheen, holds volume', ['wedding'], true),
            'tulle' => $f('تول', 'Tulle', 'chiffon', 'light', 'شبكي خفيف للطبقات', 'light net for layers', ['wedding'], true),
            'satin' => $f('ساتان', 'Satin', 'silk', 'medium', 'لمعة ناعمة وانسدال سائل', 'soft sheen, liquid drape', ['eid_fitr', 'eid_adha', 'wedding']),
            'silk' => $f('حرير', 'Silk', 'silk', 'light', 'لمعة فاخرة وانسدال ناعم', 'luxurious sheen and flow', ['wedding']),
            'taffeta' => $f('تفتا', 'Taffeta', 'silk', 'medium', 'متماسك ولامع بحفيف', 'crisp with a rustle', ['wedding']),
            'linen' => $f('كتان', 'Linen', 'linen', 'medium', 'مسامي ومنعش', 'breathable, relaxed', ['summer']),
            'cotton' => $f('قطن', 'Cotton', 'cotton', 'medium', 'مريح ومسامي', 'comfortable, breathable', ['summer', 'all_year']),
            'viscose' => $f('فسكوز', 'Viscose', 'cotton', 'light', 'ناعم وبارد على الجلد', 'soft and cool', ['summer', 'ramadan']),
            'velvet' => $f('مخمل', 'Velvet', 'velvet', 'heavy', 'وبري فاخر بعمق لوني', 'plush with rich colour depth', ['winter']),
            'lace' => $f('دانتيل', 'Lace', null, 'light', 'مخرّم يحتاج بطانة', 'open-work, needs lining', ['wedding'], true),
            'jacquard' => $f('جاكار', 'Jacquard', null, 'heavy', 'منسوج بنقشة بارزة متماسك', 'woven motif, structured', ['winter', 'eid_fitr']),
            'jersey' => $f('جيرسيه', 'Jersey', 'cotton', 'medium', 'مطاط مريح', 'stretchy and easy', ['all_year']),
            'wool_blend' => $f('صوف مخلوط', 'Wool blend', null, 'heavy', 'دافئ ومتماسك', 'warm and structured', ['winter']),
        ];
    }

    /**
     * @return array<string, array{ar: string, en: string, season: string}>
     */
    public static function occasions(): array
    {
        return [
            'daily' => ['ar' => 'يومي', 'en' => 'Everyday', 'season' => 'all_year'],
            'work' => ['ar' => 'دوام وجامعة', 'en' => 'Work & campus', 'season' => 'back_to_school'],
            'evening' => ['ar' => 'سهرة', 'en' => 'Evening', 'season' => 'all_year'],
            'wedding_guest' => ['ar' => 'أعراس ومناسبات', 'en' => 'Weddings & events', 'season' => 'wedding'],
            'ramadan' => ['ar' => 'رمضان', 'en' => 'Ramadan', 'season' => 'ramadan'],
            'eid' => ['ar' => 'العيد', 'en' => 'Eid', 'season' => 'eid_fitr'],
            'travel_umrah' => ['ar' => 'سفر وعمرة', 'en' => 'Travel & Umrah', 'season' => 'all_year'],
            'national' => ['ar' => 'اليوم الوطني ويوم التأسيس', 'en' => 'National & Founding Day', 'season' => 'national_day'],
        ];
    }

    /**
     * Silhouettes valid for each garment, with the base fabric metres at
     * 150 cm width (abaya length 56 / size M). Starting values only.
     *
     * @return array<string, array<string, array{ar: string, en: string, meters: float}>>
     */
    public static function silhouettes(): array
    {
        $s = fn (string $ar, string $en, float $meters) => ['ar' => $ar, 'en' => $en, 'meters' => $meters];

        $dress = [
            'a_line' => $s('قصة A', 'A-line', 3.2),
            'empire' => $s('إمباير', 'Empire waist', 3.2),
            'wrap' => $s('ملفوف', 'Wrap', 3.2),
            'column' => $s('مستقيم', 'Column', 2.8),
            'tiered' => $s('طبقات', 'Tiered', 4.0),
            'shirt_dress' => $s('قميصي', 'Shirt dress', 2.8),
            'trapeze' => $s('ترابيز', 'Trapeze', 3.2),
        ];

        return [
            'abaya' => [
                'cloche' => $s('كلوش', 'Cloche (flared)', 5.0),
                'butterfly' => $s('فراشة', 'Butterfly', 3.8),
                'straight' => $s('مستقيمة كاجوال', 'Straight casual', 3.0),
                'kimono' => $s('كيمونو', 'Kimono', 3.0),
                'bisht' => $s('بشت', 'Bisht', 3.5),
                'belted' => $s('مخصّرة بحزام', 'Belted', 3.2),
                'cape' => $s('كاب', 'Cape', 3.5),
                'pleated' => $s('بليسيه', 'Pleated', 5.0),
                'wrap' => $s('كروس', 'Wrap-over', 3.3),
            ],
            'dress_daily' => $dress,
            'dress_evening' => [
                ...$dress,
                'ball_gown' => $s('منفوش', 'Ball gown', 5.5),
                'mermaid' => $s('حورية', 'Mermaid', 3.5),
            ],
            'jalabiya' => [
                'straight' => $s('مستقيمة', 'Straight', 3.0),
                'kaftan' => $s('قفطانية', 'Kaftan cut', 3.0),
                'butterfly' => $s('فراشة', 'Butterfly', 3.4),
                'empire' => $s('إمباير', 'Empire waist', 3.1),
                'tiered' => $s('طبقات', 'Tiered', 3.8),
                'belted' => $s('بحزام', 'Belted', 3.1),
            ],
            'kaftan' => [
                'kaftan' => $s('قفطان كلاسيكي', 'Classic kaftan', 3.0),
                'straight' => $s('مستقيم', 'Straight', 3.0),
                'butterfly' => $s('فراشة', 'Butterfly', 3.4),
                'belted' => $s('بحزام', 'Belted', 3.1),
                'tiered' => $s('طبقات', 'Tiered', 3.8),
            ],
            'kimono' => [
                'open_long' => $s('طويل مفتوح', 'Long open', 2.5),
                'belted' => $s('بحزام', 'Belted', 2.6),
                'short' => $s('قصير', 'Short', 1.8),
            ],
            'coord_set' => [
                'tunic_wide_pants' => $s('تونيك وبنطال واسع', 'Tunic & wide pants', 3.5),
                'shirt_maxi_skirt' => $s('قميص وتنورة طويلة', 'Shirt & maxi skirt', 3.5),
                'cape_top_culottes' => $s('كاب وكلوت', 'Cape top & culottes', 3.6),
            ],
            'prayer_set' => [
                'one_piece' => $s('قطعة واحدة', 'One piece', 3.5),
                'two_piece' => $s('قطعتين', 'Two pieces', 3.7),
            ],
        ];
    }

    /**
     * Construction details: neckline, sleeve, length, closure,
     * embellishment and where it sits.
     *
     * @return array<string, array<string, array{ar: string, en: string}>>
     */
    public static function details(): array
    {
        $l = fn (string $ar, string $en) => ['ar' => $ar, 'en' => $en];

        return [
            'neckline' => [
                'round' => $l('دائرية', 'Round'), 'v_shallow' => $l('V خفيفة', 'Shallow V'), 'boat' => $l('قارب', 'Boat'),
                'mandarin' => $l('ياقة صينية', 'Mandarin collar'), 'shirt_collar' => $l('ياقة قميص', 'Shirt collar'),
                'cowl' => $l('كاول', 'Cowl'), 'square' => $l('مربعة', 'Square'), 'keyhole' => $l('فتحة مفتاح', 'Keyhole'),
                'hood' => $l('بقبعة', 'Hooded'),
            ],
            'sleeve' => [
                'straight' => $l('مستقيم', 'Straight'), 'wide' => $l('واسع', 'Wide'), 'bell' => $l('جرس', 'Bell'),
                'puff' => $l('منفوخ', 'Puff'), 'butterfly' => $l('فراشة', 'Butterfly'), 'cape' => $l('كاب', 'Cape'),
                'bishop' => $l('بالون بأسورة', 'Bishop'), 'kimono' => $l('كيمونو', 'Kimono'), 'ruffle' => $l('كشكش', 'Ruffled'),
                'cuffed' => $l('بكبك', 'Cuffed'),
            ],
            'length' => [
                'floor' => $l('حتى الأرض', 'Floor length'), 'ankle' => $l('حتى الكاحل', 'Ankle length'),
                'midi' => $l('ميدي', 'Midi'), 'tunic' => $l('تونيك', 'Tunic length'), 'train' => $l('بذيل', 'With a train'),
            ],
            'closure' => [
                'open_front' => $l('مفتوحة من الأمام', 'Open front'), 'hidden_snaps' => $l('كبسات مخفية', 'Hidden snaps'),
                'hidden_buttons' => $l('أزرار مخفية', 'Hidden buttons'), 'visible_buttons' => $l('أزرار ظاهرة', 'Visible buttons'),
                'back_zip' => $l('سحاب خلفي', 'Back zip'), 'tie_belt' => $l('ربطة أو حزام', 'Tie belt'), 'pull_on' => $l('بدون فتحة', 'Pull-on'),
            ],
            'embellishment' => [
                'none' => $l('بدون', 'None'), 'machine_embroidery' => $l('تطريز', 'Machine embroidery'),
                'hand_beading' => $l('شك يدوي', 'Hand beading'), 'beads' => $l('خرز', 'Beads'), 'crystals' => $l('كرستال', 'Crystals'),
                'sequins' => $l('ترتر', 'Sequins'), 'pearls' => $l('لؤلؤ', 'Pearls'), 'lace_trim' => $l('حواف دانتيل', 'Lace trim'),
                'panel_insert' => $l('تطعيم قماش', 'Fabric panel insert'), 'trim_tape' => $l('شريط أو برد', 'Trim tape'),
                'pleating' => $l('بليسيه', 'Pleating'), 'fabric_flowers' => $l('ورد قماش', 'Fabric flowers'),
            ],
            'placement' => [
                'neckline' => $l('الياقة', 'neckline'), 'front_placket' => $l('صدر الأمام', 'front placket'), 'sleeves' => $l('الأكمام', 'sleeves'),
                'cuffs' => $l('الأساور', 'cuffs'), 'hem' => $l('الذيل', 'hem'), 'back' => $l('الظهر', 'back'), 'belt' => $l('الحزام', 'belt'),
                'all_over' => $l('كامل القطعة', 'all over'),
            ],
        ];
    }

    /**
     * How each concept is drawn. On-model keeps the face out of frame.
     *
     * @return array<string, array{ar: string, en: string, ratio: string}>
     */
    public static function renders(): array
    {
        return [
            'ghost' => ['ar' => 'على مانيكان', 'en' => 'Ghost mannequin', 'ratio' => '3:4'],
            'on_model' => ['ar' => 'على عارضة محتشمة', 'en' => 'Modest on-model', 'ratio' => '3:4'],
            'sketch' => ['ar' => 'رسم أزياء', 'en' => 'Fashion sketch', 'ratio' => '3:4'],
            'flat' => ['ar' => 'رسم فني مسطّح', 'en' => 'Technical flat', 'ratio' => '1:1'],
        ];
    }

    /**
     * Each concept's role in the collection.
     *
     * @return array<string, array{ar: string, en: string}>
     */
    public static function roles(): array
    {
        return [
            'safe_bet' => ['ar' => 'الرهان الآمن', 'en' => 'Safe bet'],
            'trend_twist' => ['ar' => 'لمسة موضة', 'en' => 'Trend twist'],
            'competitor_gap' => ['ar' => 'فرصة من المنافسين', 'en' => 'Competitor gap'],
            'signature' => ['ar' => 'توقيع المتجر', 'en' => 'Signature piece'],
        ];
    }

    /**
     * The mix of roles for a creativity level (1 safe … 5 bold) and count.
     *
     * @return list<string>
     */
    public static function roleMix(int $creativity, int $count, bool $hasCompetitors): array
    {
        $mix = match (true) {
            $creativity <= 1 => ['safe_bet', 'safe_bet', 'trend_twist', 'trend_twist'],
            $creativity <= 3 => ['safe_bet', 'trend_twist', 'competitor_gap', 'signature'],
            default => ['trend_twist', 'competitor_gap', 'signature', 'signature'],
        };

        // Three concepts: a cautious brief keeps its signature piece out, a
        // braver one keeps it and leaves out one of the others.
        if ($count === 3 && $creativity >= 2 && $creativity <= 3) {
            $drop = $creativity === 2 ? 'signature' : 'competitor_gap';
            array_splice($mix, (int) array_search($drop, $mix, true), 1);
        }

        // Without competitor data there is no gap to fill; follow a trend.
        if (! $hasCompetitors) {
            $mix = array_map(fn (string $r) => $r === 'competitor_gap' ? 'trend_twist' : $r, $mix);
        }

        return array_slice($mix, 0, max(1, min(4, $count)));
    }

    /**
     * Guess the garment from a product's category or name.
     */
    public static function garmentFor(?string ...$texts): ?string
    {
        $haystack = mb_strtolower(implode(' ', array_filter($texts)));

        foreach (['dress_evening', 'abaya', 'jalabiya', 'kaftan', 'kimono', 'prayer_set', 'coord_set', 'dress_daily'] as $key) {
            $words = $key === 'dress_evening' ? ['سهر', 'evening'] : self::garments()[$key]['store_words'];
            foreach ($words as $word) {
                if (str_contains($haystack, mb_strtolower($word))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Fabric metres as an estimate range, and a sewing complexity (1–3),
     * worked out in code from the design sheet, never by the model.
     *
     * @param  array<string, mixed>  $concept
     * @return array{meters_min: float, meters_max: float, complexity: int}
     */
    public static function production(string $garment, array $concept): array
    {
        $base = self::silhouettes()[$garment][$concept['silhouette'] ?? '']['meters'] ?? 3.2;
        $fabric = self::fabrics()[$concept['fabric'] ?? ''] ?? null;

        if (in_array($concept['sleeve'] ?? null, ['wide', 'butterfly', 'cape'], true)) {
            $base += 0.4;
        }
        if (($concept['length'] ?? null) === 'train') {
            $base += 1.0;
        }
        if (($concept['fabric'] ?? null) === 'jacquard') {
            $base *= 1.1;
        }

        $points = 0;
        $embellishments = (array) ($concept['embellishments'] ?? []);
        $points += count(array_intersect($embellishments, ['hand_beading', 'pleating', 'panel_insert'])) * 2;
        $points += count(array_intersect($embellishments, ['crystals', 'pearls', 'sequins', 'lace_trim', 'fabric_flowers', 'machine_embroidery']));
        $points += ($fabric['sheer'] ?? false) ? 2 : 0;
        $points += ($concept['lining'] ?? 'none') === 'full' ? 1 : 0;
        $points += ($concept['length'] ?? null) === 'train' ? 2 : 0;
        $points += in_array($concept['silhouette'] ?? null, ['pleated', 'cloche', 'ball_gown', 'mermaid', 'tiered'], true) ? 1 : 0;

        return [
            'meters_min' => round($base * 0.85, 1),
            'meters_max' => round($base * 1.2, 1),
            'complexity' => $points >= 5 ? 3 : ($points >= 2 ? 2 : 1),
        ];
    }

    /**
     * Everything the studio needs to draw the brief and the design sheets.
     *
     * @return array<string, mixed>
     */
    public static function forFrontend(): array
    {
        $strip = fn (array $list, array $keep) => array_map(fn (array $v) => array_intersect_key($v, array_flip($keep)), $list);

        return [
            'garments' => $strip(self::garments(), ['ar', 'en']),
            'fabrics' => $strip(self::fabrics(), ['ar', 'en', 'sheer', 'weight', 'drape_ar', 'drape_en', 'seasons']),
            'occasions' => self::occasions(),
            'silhouettes' => array_map(fn (array $list) => $strip($list, ['ar', 'en']), self::silhouettes()),
            'details' => self::details(),
            'renders' => self::renders(),
            'roles' => self::roles(),
        ];
    }

    public static function label(string $group, ?string $key, string $locale, ?string $garment = null): ?string
    {
        if ($key === null) {
            return null;
        }

        $list = match ($group) {
            'garments' => self::garments(),
            'fabrics' => self::fabrics(),
            'occasions' => self::occasions(),
            'renders' => self::renders(),
            'roles' => self::roles(),
            'silhouettes' => $garment ? (self::silhouettes()[$garment] ?? []) : array_merge(...array_values(self::silhouettes())),
            default => self::details()[$group] ?? [],
        };

        return $list[$key][$locale === 'ar' ? 'ar' : 'en'] ?? $key;
    }
}
