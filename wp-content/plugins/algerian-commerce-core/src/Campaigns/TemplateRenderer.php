<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

/**
 * Placeholders, not code — roadmap §85.
 *
 * Pure: strings in, strings out, no WordPress, no database, no clock. That is the
 * point rather than a convenience, because **rendering a user-authored template
 * as code is remote code execution granted to whoever holds
 * `ac_manage_marketing`.** So there is no `eval`, no `do_shortcode()`, no
 * `do_blocks()` and no callable in a token map — an allowlist of names and
 * `str_replace`, and nothing else can happen.
 *
 * ## The reversal §85 makes, stated where the code is
 *
 * `NotificationMessages`' docblock is explicit that it is plain text
 * *deliberately*: "an HTML template is a rendering concern", and text is the shape
 * that survives SMS and WhatsApp — §29's next two channels. **That argument is
 * correct and it does not transfer.** A campaign is email-only; there is no SMS
 * version of a newsletter layout. So campaigns get HTML, and the reversal is
 * written down here rather than made quietly.
 *
 * ## Multipart, always
 *
 * Every campaign renders an HTML part **and** a text part, and the text part is
 * *authored* rather than stripped from the HTML. A text-only client shows a blank
 * message otherwise, and HTML-only mail scores worse with spam filters. The two
 * parts share one token map, so they cannot disagree about what a merge field
 * says.
 *
 * ## Escaping, and why the HTML part escapes and the text part does not
 *
 * A customer's name is data the shop does not control — it arrived from a
 * registration form. Substituting it raw into HTML is stored XSS that fires in the
 * **admin's own preview** before it ever reaches an inbox, so every value is
 * escaped for the HTML part. The text part is not HTML and escaping it would mail
 * somebody `Ben&#039;Ali`.
 *
 * `htmlspecialchars` with `ENT_QUOTES` covers the attribute position too, which is
 * the case a naive `htmlspecialchars($v, ENT_COMPAT)` gets wrong: a token inside
 * `href="{{unsubscribe_url}}"` needs single **and** double quotes encoded, or a
 * value of `x' onclick='alert(1)` breaks out of the attribute.
 *
 * ## An unknown token renders empty and is reported
 *
 * §61's malformed-section precedent. A token nobody defined must not be left in
 * the message — `Bonjour {{prenom}},` is what a customer would receive — and it
 * must not vanish silently either, because the admin who wrote it has no other way
 * to find out. It renders as nothing and `unknown_tokens` names it in the
 * response.
 */
final class TemplateRenderer
{
    /**
     * Every token a template may use.
     *
     * An allowlist rather than "whatever is in the context", so a future caller
     * that put something sensitive in the context cannot expose it by a template
     * naming it.
     *
     * @var list<string>
     */
    public const TOKENS = [
        'customer_name',
        'first_name',
        'shop_name',
        'order_number',
        'unsubscribe_url',
    ];

    /** The one token that must reach every campaign, appended when absent. */
    public const UNSUBSCRIBE = 'unsubscribe_url';

    /** `{{ token }}` — whitespace inside the braces is tolerated. */
    private const PATTERN = '/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/i';

    /**
     * Render one recipient's copy.
     *
     * @param array<string, string> $context values for the tokens above
     * @return array{
     *     subject: string, html: string, text: string,
     *     unknown_tokens: list<string>, unsubscribe_appended: bool
     * }
     */
    public static function render(string $subject, string $html, string $text, array $context): array
    {
        $unknown = self::unknownTokens($subject . "\n" . $html . "\n" . $text);
        $url = trim($context[self::UNSUBSCRIBE] ?? '');

        /*
         * **Appended when absent rather than rejected**, which is §85's own
         * wording and the right way round: rejecting a template with no
         * unsubscribe link is the rule a hurried admin works around by pasting a
         * dead one. A link the system adds is a link the system can keep working.
         *
         * The subject is deliberately not searched for it — an unsubscribe URL in
         * a subject line is not a link anybody can click.
         */
        $appended = false;

        if ($url !== '' && !self::mentions($html)) {
            $html .= self::htmlFooter();
            $appended = true;
        }

        if ($url !== '' && !self::mentions($text)) {
            $text .= self::textFooter();
            $appended = true;
        }

        return [
            // The subject is a header, so it is neither HTML-escaped nor allowed
            // to carry a newline: a CR or LF in a Subject: is header injection.
            'subject' => self::oneLine(self::substitute($subject, $context, false)),
            'html' => self::substitute($html, $context, true),
            'text' => self::substitute($text, $context, false),
            'unknown_tokens' => $unknown,
            'unsubscribe_appended' => $appended,
        ];
    }

    /**
     * Tokens the template uses that this renderer does not know.
     *
     * @return list<string> lower-cased, unique, in the order they first appear
     */
    public static function unknownTokens(string $template): array
    {
        if (preg_match_all(self::PATTERN, $template, $matches) !== 1 && ($matches[1] ?? []) === []) {
            return [];
        }

        $unknown = [];

        foreach ($matches[1] as $token) {
            $token = strtolower($token);

            if (!in_array($token, self::TOKENS, true) && !in_array($token, $unknown, true)) {
                $unknown[] = $token;
            }
        }

        return $unknown;
    }

    /** Whether a body already carries the unsubscribe token. */
    public static function mentions(string $body): bool
    {
        return preg_match('/\{\{\s*' . self::UNSUBSCRIBE . '\s*\}\}/i', $body) === 1;
    }

    /**
     * @param array<string, string> $context
     */
    private static function substitute(string $template, array $context, bool $escape): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($context, $escape): string {
                $token = strtolower($match[1]);

                // An unknown token renders empty. Leaving `{{prenom}}` in place
                // would mail it to a customer; the admin is told through
                // `unknown_tokens` instead.
                if (!in_array($token, self::TOKENS, true)) {
                    return '';
                }

                $value = $context[$token] ?? '';

                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $template
        );
    }

    private static function htmlFooter(): string
    {
        return "\n<p style=\"font-size:12px;color:#666\">"
            . '<a href="{{' . self::UNSUBSCRIBE . '}}">Unsubscribe</a>'
            . "</p>\n";
    }

    private static function textFooter(): string
    {
        return "\n\nUnsubscribe: {{" . self::UNSUBSCRIBE . "}}\n";
    }

    /**
     * A subject is one line.
     *
     * Not cosmetic: `wp_mail()` writes the subject into a `Subject:` header, and a
     * CR or LF in it is header injection — a merge value carrying one could add a
     * `Bcc:`. The value is stripped rather than refused, because refusing at drain
     * time would park a whole campaign over one customer's name.
     */
    private static function oneLine(string $subject): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $subject));
    }
}
