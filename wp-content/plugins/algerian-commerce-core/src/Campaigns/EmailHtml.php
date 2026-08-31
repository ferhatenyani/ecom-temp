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

    /**
     * Run the allowlist over every string leaf of a schemaless document that
     * looks like markup — `Campaign::$bodyFields`, and nothing else so far.
     *
     * ## Why a document sanitiser exists for a field that is not HTML
     *
     * `body_fields` holds the composer form's *answers*; `body_html` holds what
     * the panel generated from them. Only the second is ever mailed, and it is
     * sanitised on every write by `sanitize()` above. So the naive reading is
     * that this document needs no sanitising at all: the backend never renders
     * it, and a blob nobody parses is a blob nobody can be attacked through.
     *
     * That reading is wrong by one step, and the step is the panel. The blob's
     * whole purpose is to be handed back to a generator that **interpolates it
     * into HTML**. An admin holding `ac_manage_marketing` who writes
     * `{"headline": "<script>…</script>"}` is not attacking this API — they are
     * planting a value that the next person to open that campaign renders, in
     * their own browser, with their own session, before anything has been saved
     * and therefore before `sanitize()` has ever seen it. That is precisely the
     * failure this class's own docblock opens with: *"a stored XSS here fires in
     * the admin's own preview before it ever reaches an inbox"*. Storing the
     * answers unsanitised would re-open it around the side of the column that
     * was closed.
     *
     * **And the guarantee has to be structural, because the generator is not
     * ours.** The panel could escape every value correctly today and stop doing
     * so in a refactor next month, and nothing in this repository would notice.
     * What can be guaranteed here is a property of the *stored bytes*: every
     * string in `body_fields` has already been through the same allowlist as
     * `body_html`, so the markup a generator can possibly paste out of this blob
     * is a subset of the markup `body_html` was already allowed to carry. This
     * field therefore adds **no** new reachable markup — which is the only claim
     * worth making, since it survives whatever the panel does next.
     *
     * ## Why it walks the structure rather than a schema
     *
     * There is no schema to walk. The panel owns the shape and will change it as
     * the form grows, so anything keyed by field name would be wrong by the next
     * release and would refuse answers the form had legitimately started
     * collecting. Walking every leaf is shape-agnostic: it is correct for a form
     * that has three fields and for one that has thirty nested repeaters.
     *
     * ## Why only leaves that look like markup
     *
     * `looksLikeMarkup()` is the difference between a sanitiser and a corrupter.
     * A colour, a URL, an integer, a French or Arabic sentence, and the perfectly
     * ordinary headline `"Soldes : tout à < 500 DA"` all come back **byte for
     * byte identical**, because none of them contains `<` followed by a letter.
     * Only a value that is genuinely trying to be markup is rewritten, and it is
     * rewritten to what an email may contain. That is what makes it safe to run
     * a sanitiser over a document whose meaning is deliberately unknown here.
     *
     * `CMS\ContentHtml::sanitizeDocument()` (§89) reached this design first, for
     * the homepage, which has the same no-schema problem. This is not a call into
     * it and not a shared base class, for two reasons: the allowlists differ —
     * that one is a storefront's, this one is an email client's, and `<video>` is
     * the sort of tag that separates them — and `EmailHtml` must stay readable as
     * the single answer to "what markup may an email carry here", which it stops
     * being the moment part of that answer lives in the CMS module.
     *
     * The depth cap is a backstop, not the bound: `CampaignInput` refuses a
     * document deeper than it allows with a 400, so a caller is told rather than
     * quietly trimmed. This guards the other doors — a row written by CLI, an
     * import, a hand-edited column — where returning `[]` for a branch nobody can
     * be told about is the right end to fail at.
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

    /**
     * Whether a value is trying to be markup at all.
     *
     * `<` followed by a letter, a slash or a `!` — a tag, a closing tag or a
     * comment/doctype. A bare `<` used as "less than" is left alone, which is the
     * entire point: running `wp_kses` over every string in the document would
     * entity-encode ampersands and mangle prices in copy that was never markup.
     */
    public static function looksLikeMarkup(string $value): bool
    {
        return preg_match('/<[a-zA-Z\/!]/', $value) === 1;
    }

    /**
     * How deep a `body_fields` document may nest before a branch is dropped.
     *
     * Ten, matching `CMS\ContentHtml::MAX_DOCUMENT_DEPTH`, and for the same
     * reason: a form's answers are two or three levels deep — blocks, each with
     * fields, some with a list of items — and ten is far past any of that while
     * still stopping a hand-written document from turning one save into unbounded
     * recursion.
     */
    public const MAX_DOCUMENT_DEPTH = 10;
}
