<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\Campaigns\EmailHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The email-safe allowlist — §85's `wp_kses` rule, as far as it can be tested without
 * WordPress.
 *
 * `sanitize()` itself needs `wp_kses` and is covered in `tests/Api/campaigns.php`.
 * What is testable here is the thing that decides what `wp_kses` will do: the two
 * lists cannot contradict each other, and the names §85 forbids are absent.
 */
final class EmailHtmlTest extends TestCase
{
    #[DataProvider('forbiddenTags')]
    public function testForbiddenTagsAreNotOnTheAllowlist(string $tag): void
    {
        self::assertFalse(EmailHtml::allowsTag($tag), "<{$tag}> is on the email allowlist");
        self::assertArrayNotHasKey($tag, EmailHtml::ALLOWED);
    }

    /** @return list<array{string}> */
    public static function forbiddenTags(): array
    {
        return array_map(static fn (string $tag): array => [$tag], EmailHtml::FORBIDDEN_TAGS);
    }

    /** §85 names these four explicitly. */
    public function testTheFourNamedRefusals(): void
    {
        self::assertFalse(EmailHtml::allowsTag('script'));
        self::assertFalse(EmailHtml::allowsTag('iframe'));
        self::assertFalse(EmailHtml::allowsAttribute('a', 'onclick'));
        self::assertFalse(EmailHtml::allowsAttribute('img', 'onerror'));
    }

    /**
     * Every `on*` handler, not a list somebody remembered. A future `onwhatever`
     * attribute is refused by the prefix rather than by an update to a list.
     */
    #[DataProvider('handlers')]
    public function testEveryEventHandlerIsRefusedOnEveryAllowedTag(string $handler): void
    {
        foreach (array_keys(EmailHtml::ALLOWED) as $tag) {
            self::assertFalse(
                EmailHtml::allowsAttribute((string) $tag, $handler),
                "{$handler} is allowed on <{$tag}>"
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function handlers(): array
    {
        $out = [];

        foreach ([
            'onclick', 'onload', 'onerror', 'onmouseover', 'onfocus', 'onanimationstart',
            'ontoggle', 'onbeforeprint', 'srcdoc', 'formaction',
        ] as $handler) {
            $out[$handler] = [$handler];
        }

        return $out;
    }

    /**
     * The two lists must not contradict each other. A tag added to `ALLOWED` that is
     * also named forbidden would make `allowsTag()` and the documentation disagree,
     * and the documentation is what a reviewer reads.
     */
    public function testTheListsDoNotContradictEachOther(): void
    {
        foreach (EmailHtml::FORBIDDEN_TAGS as $tag) {
            self::assertArrayNotHasKey($tag, EmailHtml::ALLOWED);
        }
    }

    public function testTheTagsAnEmailActuallyNeedsAreAllowed(): void
    {
        foreach (['a', 'p', 'br', 'strong', 'table', 'tr', 'td', 'img', 'div', 'span', 'ul', 'li', 'h1'] as $tag) {
            self::assertTrue(EmailHtml::allowsTag($tag), "<{$tag}> should render in an email");
        }
    }

    /**
     * Inline `style` is allowed on the layout tags on purpose: it is the only styling
     * an email client can be relied on to honour, since a `<style>` block is stripped
     * by several of them — which is also why `style` is a forbidden *tag*.
     */
    public function testInlineStyleIsAllowedWhileStyleBlocksAreNot(): void
    {
        self::assertTrue(EmailHtml::allowsAttribute('td', 'style'));
        self::assertTrue(EmailHtml::allowsAttribute('p', 'style'));
        self::assertFalse(EmailHtml::allowsTag('style'));
    }

    public function testAnUnknownAttributeIsRefused(): void
    {
        self::assertFalse(EmailHtml::allowsAttribute('a', 'ping'));
        self::assertFalse(EmailHtml::allowsAttribute('p', 'href'));
        self::assertFalse(EmailHtml::allowsAttribute('nosuchtag', 'style'));
    }

    public function testTagNamesAreCaseInsensitive(): void
    {
        self::assertTrue(EmailHtml::allowsTag('P'));
        self::assertFalse(EmailHtml::allowsTag('SCRIPT'));
        self::assertFalse(EmailHtml::allowsAttribute('A', 'ONCLICK'));
    }

    /**
     * With no WordPress, `sanitize()` returns nothing rather than passing the input
     * through. A sanitiser that silently does nothing is worse than one that is
     * absent, because the caller cannot tell.
     */
    public function testSanitizeRefusesRatherThanPassingThroughWithoutWordPress(): void
    {
        self::assertSame('', EmailHtml::sanitize('<p>hello</p>'));
    }

    // ------------------------------------------------------------ text part --

    public function testTextNormalisesLineEndings(): void
    {
        self::assertSame("a\nb\nc", EmailHtml::sanitizeText("a\r\nb\rc"));
    }

    /**
     * Control characters are what break a message body — and a bare `\r` in one is
     * how a header gets injected once the body is assembled.
     */
    public function testTextStripsControlCharacters(): void
    {
        self::assertSame('ab', EmailHtml::sanitizeText("a\x00\x08b"));
        self::assertSame("a\nb", EmailHtml::sanitizeText("a\nb"));
        self::assertSame("a\tb", EmailHtml::sanitizeText("a\tb"), 'a tab is legitimate in a text body');
    }

    public function testTextLeavesOrdinaryUnicodeAlone(): void
    {
        self::assertSame('Tapis d’Alger — الجزائر', EmailHtml::sanitizeText('Tapis d’Alger — الجزائر'));
    }

    // -------------------------------------------- the composer's answers --
    //
    // `sanitizeDocument()` is what stops `body_fields` from being a way round the
    // allowlist. It cannot assert the *sanitising* here — that needs `wp_kses`, and
    // `tests/Api/campaigns.php` does it in-process — but the two halves that decide
    // whether it corrupts anything are pure: which leaves it touches, and which it
    // leaves alone.

    /**
     * The half that makes it safe to run a sanitiser over a document whose meaning
     * is deliberately unknown: a value that is not trying to be markup comes back
     * byte for byte.
     */
    #[DataProvider('valuesThatAreNotMarkup')]
    public function testAnAnswerThatIsNotMarkupIsUntouched(string $value): void
    {
        self::assertFalse(EmailHtml::looksLikeMarkup($value), "\"{$value}\" was read as markup");
        self::assertSame(['k' => $value], EmailHtml::sanitizeDocument(['k' => $value]));
    }

    /** @return list<array{string}> */
    public static function valuesThatAreNotMarkup(): array
    {
        return [
            ['Soldes d’été'],
            ['الجزائر'],
            ['#c41e3a'],
            ['https://example.test/promo?a=1&b=2'],
            ['1500.00'],
            // The one that would break if this ran `wp_kses` over every string:
            // an ordinary price in ordinary copy.
            ['Tout à < 500 DA'],
            ['5 < 7 and 9 > 3'],
            [''],
        ];
    }

    #[DataProvider('valuesThatAreMarkup')]
    public function testAnAnswerThatIsMarkupIsRecognised(string $value): void
    {
        self::assertTrue(EmailHtml::looksLikeMarkup($value), "\"{$value}\" was not read as markup");
    }

    /** @return list<array{string}> */
    public static function valuesThatAreMarkup(): array
    {
        return [
            ['<script>alert(1)</script>'],
            ['<img src=x onerror=alert(1)>'],
            ['<p>hello</p>'],
            ['</div>'],
            ['<!-- comment -->'],
            ['<style>body{}</style>'],
            ['before <iframe src="//evil.test"></iframe> after'],
        ];
    }

    /**
     * Non-string leaves are not the sanitiser's business and must survive with
     * their types intact — a number that came back as a string would be a bug the
     * panel's generator would have to work around forever.
     */
    public function testNonStringLeavesKeepTheirTypes(): void
    {
        $document = ['n' => 42, 'f' => 1.5, 'b' => true, 'z' => null];

        self::assertSame($document, EmailHtml::sanitizeDocument($document));
    }

    /**
     * It walks the shape rather than a schema, so a form that grows a repeater is
     * covered without this file knowing the repeater exists. Lists stay lists.
     */
    public function testItWalksNestedStructureAndKeepsListsAsLists(): void
    {
        $document = [
            'headline' => 'Soldes',
            'blocks' => [
                ['type' => 'text', 'value' => 'plain'],
                ['type' => 'text', 'value' => 'also plain'],
            ],
        ];

        $out = EmailHtml::sanitizeDocument($document);

        self::assertSame($document, $out);
        self::assertTrue(array_is_list($out['blocks']), 'a repeater is a list on purpose');
    }

    /**
     * The depth cap is a backstop for a document that did not come through
     * `CampaignInput` — a CLI write, an import, a hand-edited column. Past it the
     * branch is dropped rather than recursed into.
     */
    public function testTheDepthCapDropsTheBranchRatherThanRecursingForever(): void
    {
        $document = ['leaf' => 'ok'];

        for ($i = 0; $i <= EmailHtml::MAX_DOCUMENT_DEPTH + 2; $i++) {
            $document = ['nested' => $document];
        }

        $out = EmailHtml::sanitizeDocument($document);

        // The top level is depth 0, so levels 0..MAX are walked and the first one
        // past the cap is replaced with an empty branch.
        for ($i = 0; $i <= EmailHtml::MAX_DOCUMENT_DEPTH; $i++) {
            self::assertArrayHasKey('nested', $out, "level {$i} should still have been walked");
            $out = $out['nested'];
        }

        self::assertSame([], $out, 'past the cap the branch is dropped');
    }

    /**
     * Without WordPress `sanitize()` returns `''`, so a markup-shaped leaf is
     * emptied rather than passed through. Asserted so the "refuse rather than pass
     * through" stance is known to hold one level down as well — the failure a
     * document sanitiser could plausibly get wrong is leaking the original string
     * back when the sanitiser is unavailable.
     */
    public function testAMarkupLeafIsNeverPassedThroughWithoutWordPress(): void
    {
        $out = EmailHtml::sanitizeDocument(['headline' => '<script>alert(1)</script>']);

        self::assertSame('', $out['headline']);
        self::assertStringNotContainsString('script', (string) $out['headline']);
    }
}
