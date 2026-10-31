<?php

namespace App\Support;

/**
 * The fixed attribute vocabulary used to describe products (FR-1.4) and
 * candidates (FR-3.1). Keys are stored in the database; labels are shown
 * in Arabic or English. The evaluation engine compares products by these
 * keys, so free text never has to be matched.
 */
class Vocabulary
{
    /**
     * @return array<string, array{ar: string, en: string, hex: string}>
     */
    public static function colors(): array
    {
        return [
            'black' => ['ar' => 'أسود', 'en' => 'Black', 'hex' => '#1C1C1E'],
            'white' => ['ar' => 'أبيض', 'en' => 'White', 'hex' => '#F7F7F5'],
            'ivory' => ['ar' => 'عاجي', 'en' => 'Ivory', 'hex' => '#EFE8D8'],
            'beige' => ['ar' => 'بيج', 'en' => 'Beige', 'hex' => '#D9C7A7'],
            'sand' => ['ar' => 'رملي', 'en' => 'Sand', 'hex' => '#C8A97E'],
            'gold' => ['ar' => 'ذهبي', 'en' => 'Gold', 'hex' => '#C9A227'],
            'brown' => ['ar' => 'بني', 'en' => 'Brown', 'hex' => '#7B5134'],
            'terracotta' => ['ar' => 'تيراكوتا', 'en' => 'Terracotta', 'hex' => '#C0623F'],
            'burgundy' => ['ar' => 'عنابي', 'en' => 'Burgundy', 'hex' => '#7A2335'],
            'dusty_pink' => ['ar' => 'وردي مطفي', 'en' => 'Dusty pink', 'hex' => '#D7A3A6'],
            'lavender' => ['ar' => 'لافندر', 'en' => 'Lavender', 'hex' => '#B6A6D6'],
            'navy' => ['ar' => 'كحلي', 'en' => 'Navy', 'hex' => '#1B2F55'],
            'sky_blue' => ['ar' => 'أزرق سماوي', 'en' => 'Sky blue', 'hex' => '#8FC1E3'],
            'teal' => ['ar' => 'تركوازي غامق', 'en' => 'Teal', 'hex' => '#2F6F73'],
            'emerald' => ['ar' => 'زمردي', 'en' => 'Emerald', 'hex' => '#1F7A55'],
            'sage' => ['ar' => 'أخضر مريمية', 'en' => 'Sage', 'hex' => '#A3B49A'],
            'olive' => ['ar' => 'زيتي', 'en' => 'Olive', 'hex' => '#6B7034'],
            'grey' => ['ar' => 'رمادي', 'en' => 'Grey', 'hex' => '#8E8E93'],
            'red' => ['ar' => 'أحمر', 'en' => 'Red', 'hex' => '#C0392B'],
            'mustard' => ['ar' => 'خردلي', 'en' => 'Mustard', 'hex' => '#D4A017'],
        ];
    }

    /**
     * @return array<string, array{ar: string, en: string}>
     */
    public static function patterns(): array
    {
        return [
            'solid' => ['ar' => 'سادة', 'en' => 'Solid'],
            'floral' => ['ar' => 'ورود', 'en' => 'Floral'],
            'geometric' => ['ar' => 'هندسي', 'en' => 'Geometric'],
            'arabesque' => ['ar' => 'زخرفة عربية', 'en' => 'Arabesque'],
            'calligraphy' => ['ar' => 'خط عربي', 'en' => 'Calligraphy'],
            'stripes' => ['ar' => 'خطوط', 'en' => 'Stripes'],
            'abstract' => ['ar' => 'تجريدي', 'en' => 'Abstract'],
            'embroidery' => ['ar' => 'تطريز', 'en' => 'Embroidery'],
            'sadu' => ['ar' => 'نقشة السدو', 'en' => 'Sadu weave'],
        ];
    }

    /**
     * @return array<string, array{ar: string, en: string}>
     */
    public static function materials(): array
    {
        return [
            'crepe' => ['ar' => 'كريب', 'en' => 'Crepe'],
            'chiffon' => ['ar' => 'شيفون', 'en' => 'Chiffon'],
            'linen' => ['ar' => 'كتان', 'en' => 'Linen'],
            'cotton' => ['ar' => 'قطن', 'en' => 'Cotton'],
            'silk' => ['ar' => 'حرير', 'en' => 'Silk'],
            'velvet' => ['ar' => 'مخمل', 'en' => 'Velvet'],
            'leather' => ['ar' => 'جلد', 'en' => 'Leather'],
            'ceramic' => ['ar' => 'سيراميك', 'en' => 'Ceramic'],
            'wood' => ['ar' => 'خشب', 'en' => 'Wood'],
            'metal' => ['ar' => 'معدن', 'en' => 'Metal'],
            'glass' => ['ar' => 'زجاج', 'en' => 'Glass'],
            'paper' => ['ar' => 'ورق فاخر', 'en' => 'Fine paper'],
        ];
    }

    /**
     * @return array<string, array{ar: string, en: string}>
     */
    public static function styles(): array
    {
        return [
            'modern' => ['ar' => 'عصري', 'en' => 'Modern'],
            'classic' => ['ar' => 'كلاسيكي', 'en' => 'Classic'],
            'minimal' => ['ar' => 'بسيط', 'en' => 'Minimal'],
            'luxury' => ['ar' => 'فاخر', 'en' => 'Luxury'],
            'heritage' => ['ar' => 'تراثي', 'en' => 'Heritage'],
            'bohemian' => ['ar' => 'بوهيمي', 'en' => 'Bohemian'],
            'sporty' => ['ar' => 'رياضي', 'en' => 'Sporty'],
            'playful' => ['ar' => 'مرح', 'en' => 'Playful'],
        ];
    }

    /**
     * Seasons and occasions — keys match the seasonal calendar events.
     *
     * @return array<string, array{ar: string, en: string}>
     */
    public static function seasons(): array
    {
        return [
            'all_year' => ['ar' => 'طوال السنة', 'en' => 'All year'],
            'ramadan' => ['ar' => 'رمضان', 'en' => 'Ramadan'],
            'eid_fitr' => ['ar' => 'عيد الفطر', 'en' => 'Eid al-Fitr'],
            'eid_adha' => ['ar' => 'عيد الأضحى', 'en' => 'Eid al-Adha'],
            'national_day' => ['ar' => 'اليوم الوطني', 'en' => 'National Day'],
            'founding_day' => ['ar' => 'يوم التأسيس', 'en' => 'Founding Day'],
            'back_to_school' => ['ar' => 'العودة للمدارس', 'en' => 'Back to school'],
            'summer' => ['ar' => 'الصيف', 'en' => 'Summer'],
            'winter' => ['ar' => 'الشتاء', 'en' => 'Winter'],
            'white_friday' => ['ar' => 'الجمعة البيضاء', 'en' => 'White Friday'],
            'wedding' => ['ar' => 'موسم الأعراس', 'en' => 'Wedding season'],
        ];
    }

    /**
     * Colours that consistently over-index with Saudi shoppers. Admin can
     * change this list in platform settings.
     *
     * @return list<string>
     */
    public static function saudiPalette(): array
    {
        return \App\Models\PlatformSetting::get('saudi_palette', ['black', 'white', 'ivory', 'beige', 'sand', 'gold', 'olive', 'burgundy', 'emerald', 'navy']);
    }

    public static function label(string $group, ?string $key, string $locale): ?string
    {
        if ($key === null) {
            return null;
        }

        $list = match ($group) {
            'colors' => self::colors(),
            'patterns' => self::patterns(),
            'materials' => self::materials(),
            'styles' => self::styles(),
            'seasons' => self::seasons(),
            default => [],
        };

        return $list[$key][$locale] ?? $key;
    }

    /**
     * Everything the frontend needs to render pickers and labels.
     *
     * @return array<string, mixed>
     */
    public static function forFrontend(): array
    {
        return [
            'colors' => self::colors(),
            'patterns' => self::patterns(),
            'materials' => self::materials(),
            'styles' => self::styles(),
            'seasons' => self::seasons(),
        ];
    }
}
