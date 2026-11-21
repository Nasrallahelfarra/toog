<?php

namespace App\Support;

/**
 * Products the platform leaves out altogether: accessories. The platform
 * advises on clothing, so an accessory (or anything in an accessories
 * section) is kept out of every page, count, sales total and AI prompt.
 *
 * The product row stays in the database, marked `excluded`, so nothing is
 * lost and the mark is recomputed whenever the name or category changes.
 */
class ExcludedProducts
{
    /** Accessory words, matched after Arabic letters are normalised. */
    private const WORDS = ['اكسسو', 'اكسوار', 'accessor'];

    /**
     * Words that name a garment. "Set" words are left out on purpose: an
     * accessory set ("طقم اكسسوار") is not a two-piece outfit.
     */
    private const CLOTHING = ['عباي', 'abaya', 'فستان', 'فساتين', 'dress', 'جلابي', 'jalabiya', 'jalabia', 'قفطان', 'قفاطين', 'kaftan', 'caftan', 'كيمون', 'kimono', 'جلال'];

    /**
     * True for an accessory: its section or its name says so, unless the
     * name leads with a garment. "فستان مع اكسسوار" is a dress sold with an
     * accessory; "اكسسوارات عباية" is an accessory for an abaya.
     */
    public static function matches(?string $category, ?string $name): bool
    {
        $name = self::normalise($name);
        $accessory = self::first($name, self::WORDS);

        if ($accessory === null && self::first(self::normalise($category), self::WORDS) === null) {
            return false;
        }

        $garment = self::first($name, self::CLOTHING);

        return $garment === null || ($accessory !== null && $accessory < $garment);
    }

    /**
     * Where the earliest of the words starts in the text, if any is there.
     *
     * @param  list<string>  $words
     */
    private static function first(string $text, array $words): ?int
    {
        $positions = array_filter(array_map(fn (string $word) => mb_strpos($text, $word), $words), fn ($at) => $at !== false);

        return $positions === [] ? null : min($positions);
    }

    /**
     * Lower case, one form of alef, no diacritics or tatweel, so
     * "إكسسوارات", "أكسسوار" and "اكسسوار" all read the same.
     */
    private static function normalise(?string $text): string
    {
        $text = mb_strtolower((string) $text);
        $text = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $text);

        return (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
    }
}
