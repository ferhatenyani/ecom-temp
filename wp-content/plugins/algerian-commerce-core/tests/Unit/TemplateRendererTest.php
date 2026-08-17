<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Campaigns\TemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * §85's pure test list, in the order §85 writes it: every token, an unknown token, a
 * token in an attribute position, a template with no `{{unsubscribe_url}}`, and a
 * template containing `<script>`.
 *
 * The last one needs a word about the division of labour. **Stripping `<script>` from
 * a stored template is `EmailHtml`'s job, on save** — see `EmailHtmlTest`. What the
 * *renderer* must guarantee is narrower and is the half a sanitiser cannot cover: a
 * merge **value** is data the shop does not control, so substituting a customer name
 * of `<script>alert(1)</script>` into the HTML part must not produce markup. That is
 * the assertion here.
 */
final class TemplateRendererTest extends TestCase
{
    /** @return array<string, string> */
    private function context(): array
    {
        return [
            'customer_name' => 'Amina Belkacem',
            'first_name' => 'Amina',
            'shop_name' => 'Tapis d\'Alger',
            'order_number' => '4211',
            'unsubscribe_url' => 'https://shop.example.test/marketing/unsubscribe?token=7.abc',
        ];
    }

    #[DataProvider('everyToken')]
    public function testEveryTokenRenders(string $token, string $expected): void
    {
        $rendered = TemplateRenderer::render('s', "<p>{{{$token}}}</p>", "{{{$token}}}", $this->context());

        self::assertStringContainsString($expected, $rendered['text'], "{$token} did not render in the text part");
        self::assertStringContainsString('<p>', $rendered['html']);
    }

    /** @return array<string, array{string, string}> */
    public static function everyToken(): array
    {
        return [
            'customer_name' => ['customer_name', 'Amina Belkacem'],
            'first_name' => ['first_name', 'Amina'],
            'shop_name' => ['shop_name', "Tapis d'Alger"],
            'order_number' => ['order_number', '4211'],
            'unsubscribe_url' => ['unsubscribe_url', 'https://shop.example.test/marketing/unsubscribe?token=7.abc'],
        ];
    }

    /**
     * The documented list and the tested list must agree.
     *
     * `TOKENS` is public so the API can publish it, and a token that appeared there
     * without a case above would be documented and untested at once — which is how a
     * merge field ships rendering empty.
     */
    public function testEveryDocumentedTokenHasACaseAbove(): void
    {
        $covered = array_column(self::everyToken(), 0);

        self::assertSame(
            [],
            array_values(array_diff(TemplateRenderer::TOKENS, $covered)),
            'a documented token has no case in everyToken()'
        );
        self::assertSame(
            [],
            array_values(array_diff($covered, TemplateRenderer::TOKENS)),
            'a case tests a token the renderer does not document'
        );
    }

    public function testTheSubjectIsMergedToo(): void
    {
        $rendered = TemplateRenderer::render('{{shop_name}} — a gift for {{first_name}}', '', '', $this->context());

        self::assertSame("Tapis d'Alger — a gift for Amina", $rendered['subject']);
    }

    /**
     * A merge value in a `Subject:` header carrying a newline is header injection — it
     * could add a `Bcc:`. Stripped rather than refused, because refusing at drain time
     * would park a whole campaign over one customer's name.
     */
    public function testASubjectCannotCarryANewline(): void
    {
        $context = $this->context();
        $context['first_name'] = "Amina\r\nBcc: attacker@example.test";

        $rendered = TemplateRenderer::render('Hello {{first_name}}', '', '', $context);

        self::assertStringNotContainsString("\n", $rendered['subject']);
        self::assertStringNotContainsString("\r", $rendered['subject']);
        self::assertStringContainsString('Bcc: attacker@example.test', $rendered['subject'], 'the text should survive as text');
    }

    // ------------------------------------------------------- unknown tokens --

    /**
     * §61's malformed-section precedent: an unknown token renders **empty** and is
     * **reported**. Leaving `{{prenom}}` in place would mail it to a customer;
     * dropping it silently means the admin who wrote it never finds out.
     */
    public function testAnUnknownTokenRendersEmptyAndIsReported(): void
    {
        $rendered = TemplateRenderer::render('Bonjour {{prenom}}', '<p>{{promo_code}}</p>', 'x {{prenom}}', $this->context());

        self::assertSame('Bonjour', $rendered['subject']);
        self::assertStringNotContainsString('{{', $rendered['html']);
        self::assertStringNotContainsString('prenom', $rendered['text']);
        self::assertSame(['prenom', 'promo_code'], $rendered['unknown_tokens']);
    }

    public function testAKnownTokenIsNotReportedAsUnknown(): void
    {
        $rendered = TemplateRenderer::render('{{shop_name}}', '{{first_name}}', '{{unsubscribe_url}}', $this->context());

        self::assertSame([], $rendered['unknown_tokens']);
    }

    public function testUnknownTokensAreDeduplicatedAndLowerCased(): void
    {
        self::assertSame(['prenom'], TemplateRenderer::unknownTokens('{{prenom}} {{PRENOM}} {{ prenom }}'));
    }

    public function testWhitespaceInsideTheBracesIsTolerated(): void
    {
        $rendered = TemplateRenderer::render('s', '', '{{ first_name }}', $this->context());

        self::assertStringContainsString('Amina', $rendered['text']);
    }

    // --------------------------------------------------------------- escaping --

    /**
     * **The renderer's own security property.** A customer name arrived from a
     * registration form, so substituting it raw into HTML is stored XSS that fires in
     * the admin's preview before it reaches any inbox.
     */
    public function testAHostileMergeValueCannotIntroduceMarkup(): void
    {
        $context = $this->context();
        $context['customer_name'] = '<script>alert(1)</script>';

        $rendered = TemplateRenderer::render('s', '<p>Hello {{customer_name}}</p>', '', $context);

        self::assertStringNotContainsString('<script>', $rendered['html']);
        self::assertStringContainsString('&lt;script&gt;', $rendered['html']);
    }

    /**
     * §85 names this case specifically: a token in an attribute position.
     * `ENT_QUOTES` is what covers it — with `ENT_COMPAT` a single quote survives and
     * a value of `x' onclick='…` breaks out of the attribute.
     */
    public function testATokenInAnAttributePositionCannotBreakOut(): void
    {
        $context = $this->context();
        $context['unsubscribe_url'] = "https://x.test/?a=b' onclick='alert(1)";

        $rendered = TemplateRenderer::render('s', '<a href="{{unsubscribe_url}}">Stop</a>', '', $context);

        self::assertStringNotContainsString("onclick='alert(1)'", $rendered['html']);
        self::assertStringContainsString('&#039;', $rendered['html']);
    }

    public function testADoubleQuoteInAnAttributeIsAlsoEncoded(): void
    {
        $context = $this->context();
        $context['first_name'] = 'A" onmouseover="alert(1)';

        $rendered = TemplateRenderer::render('s', '<a title="{{first_name}}">x</a>', '', $context);

        self::assertStringNotContainsString('onmouseover="alert(1)"', $rendered['html']);
    }

    /** The text part is not HTML, and escaping it would mail somebody `Ben&#039;Ali`. */
    public function testTheTextPartIsNotEscaped(): void
    {
        $context = $this->context();
        $context['customer_name'] = "Ben'Ali & Fils";

        $rendered = TemplateRenderer::render('s', '', 'Bonjour {{customer_name}}', $context);

        self::assertStringContainsString("Ben'Ali & Fils", $rendered['text']);
        self::assertStringNotContainsString('&amp;', $rendered['text']);
    }

    // ---------------------------------------------------------- unsubscribe --

    /**
     * §85: appended when absent rather than rejected. "Rejecting it is the rule a
     * hurried admin works around by pasting a dead link."
     */
    public function testTheUnsubscribeLinkIsAppendedWhenAbsent(): void
    {
        $rendered = TemplateRenderer::render('s', '<p>Sale!</p>', 'Sale!', $this->context());

        self::assertTrue($rendered['unsubscribe_appended']);
        self::assertStringContainsString('shop.example.test/marketing/unsubscribe', $rendered['html']);
        self::assertStringContainsString('shop.example.test/marketing/unsubscribe', $rendered['text']);
        self::assertStringContainsString('<a href=', $rendered['html']);
    }

    /** An author who placed it themselves keeps their layout. */
    public function testAnAuthoredUnsubscribeLinkIsNotDuplicated(): void
    {
        $html = '<p>Sale! <a href="{{unsubscribe_url}}">no more of this</a></p>';
        $rendered = TemplateRenderer::render('s', $html, 'Sale! {{unsubscribe_url}}', $this->context());

        self::assertFalse($rendered['unsubscribe_appended']);
        self::assertSame(1, substr_count($rendered['html'], 'marketing/unsubscribe'));
        self::assertStringContainsString('no more of this', $rendered['html']);
    }

    /**
     * Appended per part. A template with the token in its HTML and not in its text is
     * the shape an author actually produces, and the text part still has to carry a
     * way out.
     */
    public function testTheFooterIsAppendedToWhicheverPartIsMissingIt(): void
    {
        $rendered = TemplateRenderer::render('s', '<p>{{unsubscribe_url}}</p>', 'no link here', $this->context());

        self::assertTrue($rendered['unsubscribe_appended']);
        self::assertStringContainsString('marketing/unsubscribe', $rendered['text']);
        self::assertSame(1, substr_count($rendered['html'], 'marketing/unsubscribe'));
    }

    /**
     * With no URL there is nothing to append, and a footer with an empty `href` is
     * worse than none. `CampaignService` is what guarantees a URL exists — it falls
     * back to this API's own route when §71 has no storefront.
     */
    public function testNothingIsAppendedWithNoUrl(): void
    {
        $context = $this->context();
        $context['unsubscribe_url'] = '';

        $rendered = TemplateRenderer::render('s', '<p>Sale!</p>', 'Sale!', $context);

        self::assertFalse($rendered['unsubscribe_appended']);
        self::assertSame('<p>Sale!</p>', $rendered['html']);
    }

    /** A subject is not a place a link can be clicked, so it is not searched. */
    public function testTheSubjectIsNotGivenAnUnsubscribeFooter(): void
    {
        $rendered = TemplateRenderer::render('Big sale', '<p>x</p>', 'x', $this->context());

        self::assertSame('Big sale', $rendered['subject']);
    }

    public function testMentionsDetectsTheTokenInEitherSpelling(): void
    {
        self::assertTrue(TemplateRenderer::mentions('{{unsubscribe_url}}'));
        self::assertTrue(TemplateRenderer::mentions('{{ UNSUBSCRIBE_URL }}'));
        self::assertFalse(TemplateRenderer::mentions('unsubscribe_url'));
        self::assertFalse(TemplateRenderer::mentions('{{unsubscribe}}'));
    }

    // ------------------------------------------------------------ multipart --

    /** Both parts share one token map, so they cannot disagree about a merge field. */
    public function testBothPartsSeeTheSameValues(): void
    {
        $rendered = TemplateRenderer::render('s', '<p>{{first_name}}</p>', '{{first_name}}', $this->context());

        self::assertStringContainsString('Amina', $rendered['html']);
        self::assertStringContainsString('Amina', $rendered['text']);
    }

    /**
     * A missing context value renders empty rather than leaving the token. A campaign
     * to a customer with no orders should say "your order " and not
     * "your order {{order_number}}".
     */
    public function testAKnownTokenWithNoValueRendersEmpty(): void
    {
        $rendered = TemplateRenderer::render('s', '', 'Order [{{order_number}}]', ['shop_name' => 'X']);

        self::assertStringContainsString('Order []', $rendered['text']);
    }

    public function testNoCodeIsEverExecuted(): void
    {
        // No shortcodes, no PHP, no blocks — an allowlist and str_replace. A template
        // is authored by a user and rendering it as code is remote code execution
        // granted to whoever holds ac_manage_marketing.
        $rendered = TemplateRenderer::render(
            's',
            '<p>[gallery] <?php echo 1; ?> <!-- wp:paragraph --></p>',
            '',
            $this->context()
        );

        self::assertStringContainsString('[gallery]', $rendered['html'], 'a shortcode is inert text');
        self::assertStringContainsString('<?php', $rendered['html'], 'PHP is inert text here; kses strips it on save');
    }
}
