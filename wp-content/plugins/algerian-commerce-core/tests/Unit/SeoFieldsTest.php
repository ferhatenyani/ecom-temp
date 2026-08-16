<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use AlgerianCommerce\SEO\SeoFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules that decide what a search engine is finally shown — roadmap §62,
 * docs/PLAN.md §25.
 *
 * These run on every content payload the storefront renders, so the failure
 * mode is not an exception: it is a shop quietly serving a truncated title or a
 * meta description made of raw shortcodes to every visitor for a month.
 */
final class SeoFieldsTest extends TestCase
{
    public function testTheFirstNonEmptyValueWins(): void
    {
        self::assertSame('excerpt', SeoFields::firstNonEmpty('', '  ', 'excerpt', 'body'));
        self::assertSame('', SeoFields::firstNonEmpty('', '   '));
    }

    // ------------------------------------------------------------- truncation --

    public function testShortTextIsLeftAlone(): void
    {
        self::assertSame('Tapis berbère fait main', SeoFields::truncate('Tapis berbère fait main', 60));
    }

    public function testWhitespaceIsCollapsedBeforeMeasuring(): void
    {
        self::assertSame('a b c', SeoFields::truncate("a  \n b\t\tc ", 60));
    }

    /** A description cut mid-word reads as broken rather than as abbreviated. */
    public function testTruncationFallsBackToTheLastWordBoundary(): void
    {
        $result = SeoFields::truncate('Tapis berbère fait main en laine naturelle', 20);

        self::assertStringEndsWith('…', $result);
        self::assertLessThanOrEqual(20, mb_strlen($result));
        self::assertStringNotContainsString('natu…', $result);
    }

    /** Dangling punctuation before an ellipsis is the giveaway of a naive cut. */
    public function testTrailingPunctuationIsRemovedBeforeTheEllipsis(): void
    {
        self::assertSame('Rouge, vert…', SeoFields::truncate('Rouge, vert, bleu et jaune', 16));
    }

    public function testMultibyteTextIsMeasuredInCharactersNotBytes(): void
    {
        // Arabic: 20 bytes would be 10 characters, and a byte-wise cut would
        // also split a codepoint and emit invalid UTF-8.
        $result = SeoFields::truncate('تابس بربري مصنوع يدويا من الصوف', 12);

        self::assertLessThanOrEqual(12, mb_strlen($result));
        self::assertSame($result, mb_convert_encoding($result, 'UTF-8', 'UTF-8'));
    }

    // ------------------------------------------------------------------ titles --

    public function testATitleIsComposedWithTheSiteName(): void
    {
        self::assertSame('Tapis — Boutique', SeoFields::composeTitle('Tapis', 'Boutique'));
    }

    /** Common on a home page, and "Boutique — Boutique" looks broken. */
    public function testATitleIsNotDoubledWhenItMatchesTheSiteName(): void
    {
        self::assertSame('Boutique', SeoFields::composeTitle('Boutique', 'Boutique'));
        // Matched case-insensitively, but the page keeps the casing its editor
        // chose rather than being rewritten to the site's.
        self::assertSame('boutique', SeoFields::composeTitle('boutique', 'Boutique'));
    }

    public function testAMissingHalfIsNotDecoratedWithASeparator(): void
    {
        self::assertSame('Tapis', SeoFields::composeTitle('Tapis', ''));
        self::assertSame('Boutique', SeoFields::composeTitle('', 'Boutique'));
        self::assertSame('', SeoFields::composeTitle('', ''));
    }

    public function testAComposedTitleIsStillTruncated(): void
    {
        $title = SeoFields::composeTitle(str_repeat('Tapis ', 20), 'Boutique');

        self::assertLessThanOrEqual(SeoFields::TITLE_LIMIT, mb_strlen($title));
    }

    // ------------------------------------------------------------------ robots --

    /** @return array<string, array{0: string, 1: bool, 2: bool}> */
    public static function robotsProvider(): array
    {
        return [
            'empty keeps the default' => ['', true, true],
            'noindex' => ['noindex', false, true],
            'both' => ['noindex,nofollow', false, false],
            'spaced' => ['noindex, nofollow', false, false],
            'uppercase' => ['NOINDEX, NOFOLLOW', false, false],
            'explicit positives' => ['index, follow', true, true],
            'mixed' => ['index, nofollow', true, false],
        ];
    }

    #[DataProvider('robotsProvider')]
    public function testRobotsIsParsedPermissively(string $stored, bool $index, bool $follow): void
    {
        self::assertSame(['index' => $index, 'follow' => $follow], SeoFields::parseRobots($stored));
    }

    /**
     * This value is typed by hand into a custom-fields box. A typo must not be
     * the reason a page leaves the index.
     */
    public function testAnUnrecognisedDirectiveLeavesTheDefaultAlone(): void
    {
        self::assertSame(['index' => true, 'follow' => true], SeoFields::parseRobots('no-index, nofollowx'));
    }

    /** A draft is reachable through this API long before it is meant to be public. */
    public function testTheIndexDefaultIsCallerSupplied(): void
    {
        self::assertSame(['index' => false, 'follow' => true], SeoFields::parseRobots('', false));
        // ...and an explicit override still beats it, because somebody meant it.
        self::assertSame(['index' => true, 'follow' => true], SeoFields::parseRobots('index', false));
    }

    public function testTheDirectiveIsWhatAMetaTagWants(): void
    {
        self::assertSame('index, follow', SeoFields::robotsDirective(true, true));
        self::assertSame('noindex, nofollow', SeoFields::robotsDirective(false, false));
    }

    // -------------------------------------------------------------- plain text --

    /**
     * Shortcodes go before tags. An unrendered `[products limit="4"]` in a meta
     * description is worse than no description at all.
     */
    public function testShortcodesAndTagsAreStripped(): void
    {
        self::assertSame(
            'Nos tapis Fait main',
            SeoFields::toPlainText('<h2>Nos tapis</h2>[products limit="4"]<p>Fait main</p>')
        );
    }

    public function testEntitiesAreDecoded(): void
    {
        self::assertSame("Tapis d'Alger & co", SeoFields::toPlainText('<p>Tapis d&#039;Alger &amp; co</p>'));
    }

    /**
     * `strip_tags()` removes the tags of a script element and keeps what is
     * between them, so a body ending in an analytics snippet would publish
     * real JavaScript as its meta description.
     */
    public function testScriptContentDoesNotBecomeADescription(): void
    {
        self::assertSame('Tapis', SeoFields::toPlainText('<p>Tapis</p><script>var x = 1;</script>'));
        self::assertSame('Tapis', SeoFields::toPlainText('<p>Tapis</p><style>.a{color:red}</style>'));
        // An unclosed script tag must not let the remainder through either.
        self::assertSame('Tapis', SeoFields::toPlainText('<p>Tapis</p><script>var x = 1;'));
    }

    /** Removing a block element must not weld the words on either side together. */
    public function testAdjacentBlocksKeepTheirWordBoundary(): void
    {
        self::assertSame('Tapis berbère', SeoFields::toPlainText('<p>Tapis</p><p>berbère</p>'));
        self::assertSame('a b c', SeoFields::toPlainText('<ul><li>a</li><li>b</li><li>c</li></ul>'));
        self::assertSame('a b', SeoFields::toPlainText('a<br>b'));
    }

    /**
     * ...and an inline element must not introduce one, which is the other half
     * of the same problem: spacing every tag turns "58 wilayas." into
     * "58 wilayas .".
     */
    public function testInlineMarkupDoesNotIntroduceSpaces(): void
    {
        self::assertSame(
            'Nous livrons dans les 58 wilayas.',
            SeoFields::toPlainText('<p>Nous livrons dans les <strong>58 wilayas</strong>.</p>')
        );
        self::assertSame('Tapis berbère', SeoFields::toPlainText('Tapis <em>berbère</em>'));
    }

    // --------------------------------------------------------------- canonical --

    /** @return array<string, array{0: string, 1: bool}> */
    public static function canonicalProvider(): array
    {
        return [
            'empty is fine — it is optional' => ['', true],
            'https' => ['https://boutique.dz/tapis', true],
            'http is refused' => ['http://boutique.dz/tapis', false],
            'a path is not a canonical' => ['/tapis', false],
            'javascript' => ['javascript:alert(1)', false],
            'nonsense' => ['not a url', false],
        ];
    }

    #[DataProvider('canonicalProvider')]
    public function testACanonicalMustBeAnAbsoluteHttpsUrl(string $url, bool $acceptable): void
    {
        self::assertSame($acceptable, SeoFields::isAcceptableCanonical($url));
    }
}
