<?php

declare(strict_types=1);

namespace AlgerianCommerce\SEO;

/**
 * The SEO field set and the rules that resolve it — roadmap §62,
 * docs/PLAN.md §25.
 *
 * Pure: no WordPress, so the fallback order, the robots grammar and the
 * truncation limits — everything that decides what a search engine is finally
 * shown — is unit-testable on its own.
 *
 * **Five stored fields, and the rest is derived.** Open Graph values fall back
 * to the SEO title and description rather than being stored twice: two fields
 * that are the same value in every real case are two fields that drift apart in
 * one of them, and nobody notices which. An editor who genuinely needs a
 * different social title sets it in the description of the share card, not in a
 * duplicated column.
 */
final class SeoFields
{
    public const META_TITLE = '_ac_seo_title';
    public const META_DESCRIPTION = '_ac_seo_description';
    public const META_CANONICAL = '_ac_seo_canonical';
    public const META_ROBOTS = '_ac_seo_robots';
    public const META_IMAGE_ID = '_ac_seo_image_id';

    /** The writable field names, as the API spells them. */
    public const FIELDS = ['title', 'description', 'canonical', 'robots', 'image_id'];

    /** @var array<string, string> field name → meta key */
    public const META_KEYS = [
        'title' => self::META_TITLE,
        'description' => self::META_DESCRIPTION,
        'canonical' => self::META_CANONICAL,
        'robots' => self::META_ROBOTS,
        'image_id' => self::META_IMAGE_ID,
    ];

    /**
     * Google truncates a title around 60 characters and a description around
     * 160. These are not enforced — a shop may have a good reason, and an API
     * that refuses a 61-character title is an API people work around — they are
     * what the derived values are trimmed to.
     */
    public const TITLE_LIMIT = 60;
    public const DESCRIPTION_LIMIT = 160;

    /** Separator between a page title and the site name. */
    public const SEPARATOR = ' — ';

    /**
     * The first non-empty value wins.
     *
     * Written out rather than inlined as `?:` chains because "which of these
     * five things becomes the meta description" is the whole of this module's
     * behaviour, and it should be readable in one place.
     */
    public static function firstNonEmpty(string ...$candidates): string
    {
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Trim to a length without cutting a word in half, and without leaving a
     * dangling comma or space before the ellipsis.
     */
    public static function truncate(string $text, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '' || mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > 0) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:.-") . '…';
    }

    /**
     * Compose "Page — Site", and never "Site — Site".
     *
     * A page literally called the same thing as the shop is common on a home
     * page, and doubling it is the sort of output that makes a client think the
     * SEO is broken.
     */
    public static function composeTitle(string $title, string $siteName): string
    {
        $title = trim($title);
        $siteName = trim($siteName);

        if ($siteName === '' || $title === '' || strcasecmp($title, $siteName) === 0) {
            return self::truncate(self::firstNonEmpty($title, $siteName), self::TITLE_LIMIT);
        }

        return self::truncate($title . self::SEPARATOR . $siteName, self::TITLE_LIMIT);
    }

    /**
     * Parse a stored robots directive.
     *
     * Permissive on the way in — `noindex,nofollow`, `noindex, nofollow`,
     * `NOINDEX` and an empty string all mean something obvious — because this
     * value is typed by hand into a custom-fields box. Anything unrecognised
     * leaves the default in place rather than failing: a typo in one page's
     * robots field must not take that page out of the index.
     *
     * @return array{index: bool, follow: bool}
     */
    public static function parseRobots(string $stored, bool $defaultIndex = true): array
    {
        $tokens = array_filter(array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', $stored)
        ));

        $robots = ['index' => $defaultIndex, 'follow' => true];

        foreach ($tokens as $token) {
            match ($token) {
                'index' => $robots['index'] = true,
                'noindex' => $robots['index'] = false,
                'follow' => $robots['follow'] = true,
                'nofollow' => $robots['follow'] = false,
                default => null,
            };
        }

        return $robots;
    }

    /** The directive a storefront puts in `<meta name="robots">`. */
    public static function robotsDirective(bool $index, bool $follow): string
    {
        return ($index ? 'index' : 'noindex') . ', ' . ($follow ? 'follow' : 'nofollow');
    }

    /**
     * Strip a stored HTML body down to something a meta description can be.
     *
     * Three passes, and each one exists because the pass after it would get the
     * wrong answer alone:
     *
     *  1. **Script and style *elements*, contents included.** `strip_tags()`
     *     removes the tags and keeps what is between them, so a body ending in
     *     an analytics snippet yields a meta description reading
     *     `var x = 1;` — real JavaScript, published to every search engine.
     *  2. **Shortcodes.** An unrendered `[products limit="4"]` in a description
     *     is worse than no description, and rendering one here would execute
     *     arbitrary plugin code to produce sixty characters of text.
     *  3. **Block boundaries become a space**, and only block boundaries.
     *     `<p>a</p><p>b</p>` must not read `ab`, while `les
     *     <strong>58 wilayas</strong>.` must not read `les 58 wilayas .` —
     *     inserting whitespace around every tag fixes the first and breaks the
     *     second, which is the bug this list exists to record.
     *  4. The remaining inline tags, then entities.
     */
    public static function toPlainText(string $html): string
    {
        $text = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#is', ' ', $html);
        // An unclosed <script> would survive the pass above; drop everything
        // from an opening tag to the end rather than publishing the remainder.
        $text = (string) preg_replace('#<(script|style|template)\b[^>]*>.*#is', ' ', $text);
        $text = (string) preg_replace('/\[[^\]]*\]/', ' ', $text);
        $text = (string) preg_replace(
            '#</?(p|div|li|ul|ol|h[1-6]|tr|td|th|table|section|article|aside|header|footer'
            . '|blockquote|figure|figcaption|br|hr)\b[^>]*>#i',
            ' ',
            $text
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A canonical URL is only ever what somebody set.
     *
     * Deliberately never derived. WordPress's own permalink points at *this*
     * backend, and the storefront is on another origin with its own routing —
     * a canonical guessed here would confidently tell Google that the shop's
     * pages live on the admin domain. The payload carries the slug and path, so
     * the storefront, which is the only side that knows its own URL scheme,
     * builds it.
     */
    public static function isAcceptableCanonical(string $url): bool
    {
        if ($url === '') {
            return true;
        }

        // https only: a canonical is a public claim about where content lives,
        // and an http one invites the downgrade it is supposed to prevent.
        return (bool) filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://');
    }
}
