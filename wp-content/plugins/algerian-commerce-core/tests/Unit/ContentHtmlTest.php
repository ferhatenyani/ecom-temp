<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\CMS\ContentHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The page-safe allowlist — §89's `wp_kses` rule, as far as it goes without
 * WordPress.
 *
 * `sanitize()` itself needs `wp_kses` and is covered in `tests/Api/cms.php`,
 * where it is asserted against the **stored row** rather than the response —
 * and run as an administrator, who bypasses core's own filters. What is
 * testable here is what decides `wp_kses`'s behaviour: the two lists cannot
 * contradict each other, no `on*` attribute is reachable on any tag, and the
 * routing predicate that keeps ordinary prose away from the sanitiser is
 * exactly as narrow as it claims.
 */
final class ContentHtmlTest extends TestCase
{
    #[DataProvider('forbiddenTags')]
    public function testForbiddenTagsAreNotOnTheAllowlist(string $tag): void
    {
        self::assertFalse(ContentHtml::allowsTag($tag), "<{$tag}> is on the page allowlist");
        self::assertArrayNotHasKey($tag, ContentHtml::ALLOWED);
    }

    /** @return list<array{string}> */
    public static function forbiddenTags(): array
    {
        return array_map(static fn (string $tag): array => [$tag], ContentHtml::FORBIDDEN_TAGS);
    }

    /** §89 names these by name. */
    public function testTheNamedRefusals(): void
    {
        self::assertFalse(ContentHtml::allowsTag('script'));
        self::assertFalse(ContentHtml::allowsTag('iframe'));
        self::assertFalse(ContentHtml::allowsTag('style'));
        self::assertFalse(ContentHtml::allowsAttribute('a', 'onclick'));
        self::assertFalse(ContentHtml::allowsAttribute('img', 'onerror'));
    }

    /**
     * Every `on*` handler on every allowed tag, by prefix rather than by a list
     * somebody remembered — a future `onwhatever` is refused without an edit.
     */
    #[DataProvider('handlers')]
    public function testEveryEventHandlerIsRefusedOnEveryAllowedTag(string $handler): void
    {
        foreach (array_keys(ContentHtml::ALLOWED) as $tag) {
            self::assertFalse(
                ContentHtml::allowsAttribute($tag, $handler),
                "{$handler} is allowed on <{$tag}>"
            );
        }
    }

    /** @return list<array{string}> */
    public static function handlers(): array
    {
        return [['onclick'], ['onerror'], ['onload'], ['onmouseover'], ['onfocus'], ['onanimationstart']];
    }

    /**
     * `data:` is the one worth its own test: `data:text/html` in an href runs
     * in the page's origin and `data:image/svg+xml` in a src is a script
     * container. A relative URL has no protocol and is unaffected by the list.
     */
    public function testTheProtocolList(): void
    {
        self::assertSame(['http', 'https', 'mailto', 'tel'], ContentHtml::PROTOCOLS);
        self::assertNotContains('data', ContentHtml::PROTOCOLS);
        self::assertNotContains('javascript', ContentHtml::PROTOCOLS);
    }

    /** The page allowlist is wider than the email one, which is the point of it. */
    public function testItIsWiderThanTheEmailAllowlist(): void
    {
        foreach (['figure', 'figcaption', 'pre', 'code', 'dl', 'section'] as $tag) {
            self::assertTrue(ContentHtml::allowsTag($tag), "a page may carry <{$tag}>");
        }
    }

    #[DataProvider('markup')]
    public function testWhatCountsAsMarkup(string $value, bool $expected): void
    {
        self::assertSame($expected, ContentHtml::looksLikeMarkup($value));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function markup(): array
    {
        return [
            'a tag' => ['<p>hi</p>', true],
            'a closing tag' => ['</p>', true],
            'a comment' => ['<!-- hi -->', true],
            'a script' => ['<script>x</script>', true],
            // The measurement that decided the design: `wp_kses` rewrites `&`
            // to `&amp;`, so ordinary prose must never reach it.
            'an ampersand' => ['Tapis & Kilims', false],
            'a query string' => ['https://example.test/?a=1&b=2', false],
            'arithmetic' => ['a < b', false],
            'a percentage' => ['Soldes 50%', false],
            'Arabic' => ['الجزائر', false],
            // The named cost of the predicate, asserted so it is a decision
            // rather than a surprise.
            'a < with no space before a letter' => ['a <b', true],
        ];
    }

    public function testAnOrdinaryStringIsNotTouchedByTheDocumentSanitiser(): void
    {
        $document = ['title' => 'Tapis & Kilims', 'url' => 'https://example.test/?a=1&b=2', 'limit' => 8];

        self::assertSame($document, ContentHtml::sanitizeDocument($document));
    }

    public function testMarkupInADocumentIsRouted(): void
    {
        $out = ContentHtml::sanitizeDocument(['body' => '<p onclick="x">hi</p>']);

        self::assertNotSame('<p onclick="x">hi</p>', $out['body']);
    }

    public function testNestedDocumentsAreWalked(): void
    {
        $out = ContentHtml::sanitizeDocument(['a' => ['b' => ['c' => 'Tapis & Kilims']]]);

        self::assertSame('Tapis & Kilims', $out['a']['b']['c']);
    }

    /** A hand-edited option must not turn one PUT into unbounded recursion. */
    public function testDepthIsCapped(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i <= ContentHtml::MAX_DOCUMENT_DEPTH + 2; $i++) {
            $deep = ['down' => $deep];
        }

        $out = ContentHtml::sanitizeDocument((array) $deep);

        self::assertIsArray($out);
    }

    /**
     * Without WordPress the sanitiser returns nothing rather than passing the
     * value through. A sanitiser that silently does nothing is worse than one
     * that is absent — the same contract `Campaigns\EmailHtml` has.
     */
    public function testItFailsClosedWithoutWordPress(): void
    {
        if (function_exists('wp_kses')) {
            self::markTestSkipped('WordPress is loaded; this asserts the no-WordPress path.');
        }

        self::assertSame('', ContentHtml::sanitize('<p>hi</p>'));
    }

    public function testTextFieldsLoseTagsAndControlCharacters(): void
    {
        self::assertSame('Soldes', ContentHtml::sanitizeText("Sol\x00des"));
        self::assertSame('Soldes', ContentHtml::sanitizeText('<b>Soldes</b>'));
        // §83's rule: a leading `=` is escaped at the CSV boundary, not here.
        self::assertSame('=A1', ContentHtml::sanitizeText('=A1'));
    }
}
