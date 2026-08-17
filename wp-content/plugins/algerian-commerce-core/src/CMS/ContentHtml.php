<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

/**
 * The page-safe HTML allowlist — roadmap §89.
 *
 * `Campaigns\EmailHtml` is the model and the argument is identical: **stored
 * content is re-rendered, so the sanitiser runs on save**. A sanitiser on the
 * way out is one that a second reader — the storefront, §64's export, a search
 * indexer — does not run, and it leaves the hostile markup in the database
 * where the next reader may not sanitise at all.
 *
 * ## Why this is not `wp_kses_post()`, and not `EmailHtml` either
 *
 * `wp_kses_post()` allows what a *post* may contain, aimed at a WordPress theme:
 * `<style>`, `<iframe>` under some filters, `<audio>`, `<video>`, and a long
 * tail of attributes. A headless storefront renders this HTML inside its own
 * React tree, so anything that can load or execute is a hole in a page a content
 * manager could open by pasting an embed code from anywhere.
 *
 * `EmailHtml` is too narrow in the other direction: it is the set an email
 * client will honour, and a page legitimately carries a `<figure>`, a `<pre>`,
 * a definition list and heading anchors. §89 names the boundary — wider than
 * email-safe, narrower than `wp_kses_post`.
 *
 * ## Why this is load-bearing rather than belt-and-braces
 *
 * `CmsPresenter` recorded that WordPress "already runs `wp_kses_post` over
 * anything saved by a user without `unfiltered_html`". That is true, and the
 * clause carries the whole weight: **an administrator holds `unfiltered_html`,
 * so `kses_init()` removes every filter for exactly the caller most able to do
 * damage.** Measured 2026-08-17 in this stack — `wp_insert_post()` as an
 * administrator stored `<script>alert(1)</script>` and an `onclick` attribute
 * byte for byte, while the same call as a Marketing Manager did not. A property
 * that depends on who saved the row is not a property; §61's `ImageSanitizer`
 * pinned the image editor for the same reason.
 *
 * The tag list is pure data with pure predicates, so "no `<script>`, no
 * `<iframe>`, no `on*`, no `javascript:`" is a unit test rather than a claim
 * about what `wp_kses` happens to do. `sanitize()` is the one method that needs
 * WordPress.
 */
final class ContentHtml
{
    /**
     * Tags a page, banner or FAQ answer may use, and the attributes each may
     * carry.
     *
     * `class` and `id` are allowed widely on purpose: a storefront styles this
     * HTML from its own stylesheet, and `id` is how an in-page anchor works.
     * `style` is allowed only on layout tags, and `wp_kses` runs
     * `safecss_filter_attr()` over it, which is itself a property allowlist.
     *
     * @var array<string, array<string, bool>>
     */
    public const ALLOWED = [
        'a' => ['href' => true, 'title' => true, 'target' => true, 'rel' => true, 'class' => true, 'id' => true],
        'p' => ['style' => true, 'class' => true, 'id' => true],
        'br' => [],
        'hr' => ['class' => true],
        'strong' => ['class' => true], 'b' => ['class' => true],
        'em' => ['class' => true], 'i' => ['class' => true],
        'u' => ['class' => true], 's' => ['class' => true],
        'sup' => [], 'sub' => [],
        'small' => ['class' => true],
        'h1' => ['class' => true, 'id' => true], 'h2' => ['class' => true, 'id' => true],
        'h3' => ['class' => true, 'id' => true], 'h4' => ['class' => true, 'id' => true],
        'h5' => ['class' => true, 'id' => true], 'h6' => ['class' => true, 'id' => true],
        'ul' => ['class' => true], 'ol' => ['class' => true, 'start' => true, 'reversed' => true],
        'li' => ['class' => true],
        'dl' => ['class' => true], 'dt' => ['class' => true], 'dd' => ['class' => true],
        'blockquote' => ['cite' => true, 'class' => true],
        'pre' => ['class' => true], 'code' => ['class' => true],
        'span' => ['style' => true, 'class' => true, 'id' => true],
        'div' => ['style' => true, 'class' => true, 'id' => true],
        'section' => ['class' => true, 'id' => true],
        'figure' => ['class' => true], 'figcaption' => ['class' => true],
        'img' => [
            'src' => true, 'alt' => true, 'width' => true, 'height' => true,
            'class' => true, 'loading' => true, 'srcset' => true, 'sizes' => true,
        ],
        'table' => ['class' => true, 'style' => true],
        'caption' => ['class' => true],
        'colgroup' => [], 'col' => ['span' => true, 'class' => true],
        'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => ['class' => true],
        'td' => ['class' => true, 'style' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true],
        'th' => ['class' => true, 'style' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true, 'scope' => true],
    ];

    /**
     * The protocols an `href` or `src` may use.
     *
     * `data:` is absent and it is the one worth naming: `data:text/html,…` in an
     * `href` executes in the page's origin, and `data:image/svg+xml,…` in an
     * `src` is a script container. A relative URL carries no protocol at all and
     * is unaffected by this list, which is what lets a page link to
     * `/wp-content/uploads/…`.
     *
     * @var list<string>
     */
    public const PROTOCOLS = ['http', 'https', 'mailto', 'tel'];

    /**
     * Named so a test can assert each is absent from `ALLOWED`, rather than
     * asserting the allowlist equals a copy of itself.
     *
     * `style` and `link` are here because a page's styling belongs to the
     * storefront, and a `<style>` block pasted into a page can reposition
     * anything on the screen around it — the visual half of a clickjack.
     * `iframe` is the one a content manager will genuinely miss, and the answer
     * is a section type in the homepage document rather than a hole in every
     * page body.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet', 'style', 'link', 'meta',
        'base', 'form', 'input', 'button', 'select', 'textarea', 'label',
        'svg', 'math', 'video', 'audio', 'source', 'track', 'template',
        'noscript', 'frame', 'frameset', 'portal', 'dialog',
    ];

    /**
     * Attribute names that must never be allowed on anything.
     *
     * Every `on*` handler, plus the three that load or reinterpret remote
     * content as markup.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ATTRIBUTE_PREFIXES = ['on', 'srcdoc', 'formaction', 'xmlns'];

    /** Whether the allowlist would let this tag through. */
    public static function allowsTag(string $tag): bool
    {
        return isset(self::ALLOWED[strtolower($tag)]);
    }

    public static function allowsAttribute(string $tag, string $attribute): bool
    {
        $attribute = strtolower($attribute);

        foreach (self::FORBIDDEN_ATTRIBUTE_PREFIXES as $prefix) {
            if (str_starts_with($attribute, $prefix)) {
                return false;
            }
        }

        return (bool) (self::ALLOWED[strtolower($tag)][$attribute] ?? false);
    }

    /**
     * Run the allowlist. The one method here that needs WordPress.
     *
     * `wp_kses` rather than a hand-rolled parser, for `EmailHtml`'s reason: it
     * already handles an unclosed tag, an entity-encoded protocol, a null byte
     * in an attribute name and the other cases a naive `strip_tags` allowlist
     * gets wrong, and this project does not write its own HTML parser any more
     * than it writes its own credentials.
     */
    public static function sanitize(string $html): string
    {
        if (!function_exists('wp_kses')) {
            // No WordPress: refuse rather than pass it through. A sanitiser that
            // silently does nothing is worse than one that is absent.
            return '';
        }

        return wp_kses($html, self::ALLOWED, self::PROTOCOLS);
    }

    /**
     * Whether a string carries something that will parse as a tag.
     *
     * This is a **routing** predicate, not a denylist: it decides which
     * sanitiser a string gets, and the sanitiser it routes to is still an
     * allowlist. It exists because `wp_kses` is not free of side effects on
     * ordinary prose — measured 2026-08-17, it rewrites `Tapis & Kilims` to
     * `Tapis &amp; Kilims` and `?a=1&b=2` to `?a=1&amp;b=2`. Running it over
     * every string of a free-form document would corrupt titles and query
     * strings to protect fields that were never markup.
     *
     * `<` followed by a letter, `/` or `!` is a tag; `a < b` is arithmetic and
     * is left alone. The named cost is that `a <b` — a `<` with no space before
     * a letter — is treated as markup and its "tag" removed.
     */
    public static function looksLikeMarkup(string $value): bool
    {
        return preg_match('/<[a-zA-Z\/!]/', $value) === 1;
    }

    /**
     * Sanitise every string leaf of a free-form document that looks like markup.
     *
     * This is the homepage's, and §89 does not ask for it — the section names
     * page content, banner copy and FAQ answers. It is here because the
     * homepage is the one document with **no schema**: a section's `data` is
     * whatever its type needs, so there is no field to point the allowlist at,
     * and a `<script>` in a `text` section would otherwise be stored verbatim
     * and rendered by the storefront. Refusing markup outright was the
     * alternative and it would make a `text` section unable to carry `<strong>`.
     *
     * The depth cap stops a hand-edited option from turning one PUT into
     * unbounded recursion; past it the branch is dropped, which the caller
     * reports.
     *
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    public static function sanitizeDocument(array $document, int $depth = 0): array
    {
        if ($depth > self::MAX_DOCUMENT_DEPTH) {
            return [];
        }

        $out = [];

        foreach ($document as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::sanitizeDocument($value, $depth + 1);
                continue;
            }

            $out[$key] = is_string($value) && self::looksLikeMarkup($value)
                ? self::sanitize($value)
                : $value;
        }

        return $out;
    }

    /** How deep a section's `data` may nest before the branch is dropped. */
    public const MAX_DOCUMENT_DEPTH = 10;

    /**
     * A field that is text rather than markup — a page title, a banner
     * headline, an FAQ question.
     *
     * Tags here are a mistake rather than a threat, so they are removed
     * outright; control characters are what break a stored string and a CSV
     * cell alike. A leading `=` is deliberately left alone — §83 settled that,
     * and `ImportExport\CsvWriter` escapes at the boundary where the danger is.
     */
    public static function sanitizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
    }
}
