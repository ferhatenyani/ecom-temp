<?php

declare(strict_types=1);

namespace AlgerianCommerce\Geography;

/**
 * Accent-folded slugs for Algerian place names.
 *
 * Pure — no WordPress — because it is the natural key the importer upserts on,
 * and a key that depends on `sanitize_title()` would depend on WordPress's
 * locale filters. Two runs of the same import have to produce the same slug on
 * every install, forever.
 *
 * Folding accents is the point rather than a nicety. Algerian place names reach
 * us spelled both ways — Béjaïa and Bejaia, M'Sila and M'Sila with a curly
 * apostrophe, Aïn Témouchent and Ain Temouchent — and a dataset that corrects
 * its spelling next year must update the row rather than insert a second
 * commune beside it.
 *
 * A fixed replacement table, not iconv()//TRANSLIT: transliteration output
 * varies with the C library and the locale, so the same name can slug
 * differently on two servers, which is precisely what a natural key must never
 * do.
 */
final class GeoSlug
{
    /**
     * Every accented character that occurs in Algerian wilaya and commune
     * names, plus the apostrophes that vary by keyboard.
     *
     * @var array<string, string>
     */
    private const FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        // Curly and typographic apostrophes, which land in M'Sila and
        // M'Ghair depending on where the data was typed.
        '’' => "'", '‘' => "'", '`' => "'", '´' => "'",
    ];

    public static function make(string $name): string
    {
        $slug = mb_strtolower(trim($name), 'UTF-8');
        $slug = strtr($slug, self::FOLD);

        // Anything still not a-z or 0-9 becomes a separator. Arabic names slug
        // to '' by this rule, which is why the importer keys on the Latin name
        // and keeps name_ar as a label.
        $slug = preg_replace('/[^a-z0-9]+/u', '-', $slug) ?? '';

        return trim($slug, '-');
    }
}
