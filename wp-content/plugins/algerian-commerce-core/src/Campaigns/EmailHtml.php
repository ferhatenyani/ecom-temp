<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

/**
 * The email-safe HTML allowlist — roadmap §85.
 *
 * **A template is stored and re-rendered, so a stored XSS here fires in the
 * admin's own preview** before it ever reaches an inbox — and it fires with
 * whatever session the admin app holds. That is why the sanitiser runs on *save*
 * rather than on send: sanitising on the way out would leave the hostile markup in
 * the database, where the next thing to read it might not sanitise at all.
 *
 * The tag list is pure data with a pure `describe()`, so "no `<script>`, no
 * `<iframe>`, no `on*`, no `javascript:`" is a unit test rather than a claim about
 * what `wp_kses` happens to do. `sanitize()` is the one method that needs
 * WordPress.
 *
 * ## Why an allowlist rather than `wp_kses_post()`
 *
 * `wp_kses_post()` allows what a *post* may contain, which is a much wider set
 * aimed at a theme rendering in a browser — `<video>`, `<audio>`, `<iframe>` in
 * some configurations, `<style>` attributes on almost everything. An email client
 * renders none of that reliably and a mail filter treats several of them as
 * suspicious. The list below is what actually renders in Gmail, Outlook and a
 * phone, and nothing else.
 *
 * `style` is allowed on the layout tags on purpose, because inline CSS is the only
 * styling an email client can be relied on to honour — a `<style>` block is
 * stripped by several of them. `wp_kses` removes `javascript:` and `expression()`
 * from attribute values itself, which is the half that matters.
 */
final class EmailHtml
{
    /**
     * Tags an email template may use, and the attributes each may carry.
     *
     * @var array<string, array<string, bool>>
     */
    public const ALLOWED = [
        'a' => ['href' => true, 'title' => true, 'style' => true, 'target' => true],
        'p' => ['style' => true, 'align' => true],
        'br' => [],
        'hr' => ['style' => true],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'h1' => ['style' => true], 'h2' => ['style' => true], 'h3' => ['style' => true],
        'h4' => ['style' => true],
        'ul' => ['style' => true], 'ol' => ['style' => true], 'li' => ['style' => true],
        'blockquote' => ['style' => true],
        'span' => ['style' => true],
        'div' => ['style' => true, 'align' => true],
        // Tables, because they are still how an email is laid out.
        'table' => ['style' => true, 'width' => true, 'cellpadding' => true, 'cellspacing' => true, 'border' => true, 'align' => true],
        'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => ['style' => true],
        'td' => ['style' => true, 'width' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true],
        'th' => ['style' => true, 'width' => true, 'align' => true, 'valign' => true, 'colspan' => true, 'rowspan' => true],
        'img' => ['src' => true, 'alt' => true, 'width' => true, 'height' => true, 'style' => true],
    ];

    /**
     * Named so the test can assert each is absent from `ALLOWED`, rather than
     * asserting the allowlist equals a copy of itself.
     *
     * `form` and `input` are here for a reason worth stating: a form in an email
     * is a phishing pattern, most clients refuse to submit one, and a template
     * that appeared to collect a password would be doing so over whatever the
     * client allowed.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet', 'style', 'link', 'meta',
        'base', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math',
        'video', 'audio', 'source', 'template', 'noscript',
    ];

    /**
     * Attribute names that must never be allowed on anything.
     *
     * Every `on*` handler, plus the two that load remote content as script.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ATTRIBUTE_PREFIXES = ['on', 'srcdoc', 'formaction'];

    /**
     * Whether the allowlist would let this tag through.
     *
     * Pure, so the two lists above cannot drift apart: a tag added to `ALLOWED`
     * that is also in `FORBIDDEN_TAGS` fails a unit test rather than shipping.
     */
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
     * `wp_kses` rather than a hand-rolled parser: it already handles the cases a
     * naive `strip_tags` allowlist gets wrong — an unclosed tag, a `javascript:`
     * URL, an entity-encoded protocol, a null byte in an attribute name — and this
     * project does not write its own HTML parser for the same reason it does not
     * write its own credentials.
     */
    public static function sanitize(string $html): string
    {
        if (!function_exists('wp_kses')) {
            // No WordPress: refuse rather than pass it through. A sanitiser that
            // silently does nothing is worse than one that is absent.
            return '';
        }

        return wp_kses($html, self::ALLOWED, ['http', 'https', 'mailto']);
    }

    /**
     * The text part gets a different treatment: it is not HTML, so tags in it are
     * a mistake rather than a threat, and control characters are what break a
     * message body.
     */
    public static function sanitizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }
}
