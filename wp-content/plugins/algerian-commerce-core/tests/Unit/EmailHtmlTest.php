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
}
