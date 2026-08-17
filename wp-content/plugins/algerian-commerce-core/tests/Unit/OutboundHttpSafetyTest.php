<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every outbound HTTP call uses WordPress's *safe* wrapper — docs/SECURITY.md
 * → "Input and output".
 *
 * **This is a scan rather than a runtime test, for the reason `SqlSafetyTest`
 * is.** The property is "no call site anywhere spells it the unsafe way", and
 * no runtime test can assert something about a line nobody executed. The §86
 * audit found `wp_remote_request()` in `WpHttpClient` against a rule the
 * documentation had stated since it was written, which is exactly the drift a
 * scan catches and a code review had not.
 *
 * `wp_safe_remote_*` is the same call with `reject_unsafe_urls` on, which runs
 * `wp_http_validate_url()` and refuses loopback, link-local and private ranges.
 * On a VPS that matters: an unsafe fetch reaches the metadata service, the
 * database and anything else bound to the host.
 *
 * The scanner asserts its own reach before it asserts anything else. A regex
 * that matches nothing reports a fully safe codebase exactly as a fully safe
 * one does — §68's argument, and §70's.
 */
final class OutboundHttpSafetyTest extends TestCase
{
    /**
     * The unsafe spellings. Each has a `wp_safe_remote_*` twin, so there is
     * never a reason to reach for one of these.
     */
    private const UNSAFE = [
        'wp_remote_request',
        'wp_remote_get',
        'wp_remote_post',
        'wp_remote_head',
    ];

    /** How many safe call sites the scan must see before its silence means anything. */
    private const MINIMUM_SAFE_CALL_SITES = 1;

    public function testNoOutboundCallUsesTheUnsafeWrapper(): void
    {
        $found = [];

        foreach (self::sourceFiles() as $path) {
            $label = basename(dirname($path)) . '/' . basename($path);

            foreach (self::scan((string) file_get_contents($path)) as $finding) {
                $found[] = $label . ':' . $finding;
            }
        }

        self::assertSame(
            [],
            $found,
            "outbound HTTP through an unsafe wrapper:\n" . implode("\n", $found)
        );
    }

    /**
     * The scan is looking where it thinks it is. Without this, deleting the
     * only outbound client in the plugin would make this suite greener.
     */
    public function testTheScanSeesTheSafeCallSitesItIsGuarding(): void
    {
        $safe = 0;

        foreach (self::sourceFiles() as $path) {
            $safe += preg_match_all('/wp_safe_remote_(?:request|get|post|head)\s*\(/', (string) file_get_contents($path));
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_SAFE_CALL_SITES,
            $safe,
            'the scanner found no outbound HTTP at all, so it is not looking where it thinks it is'
        );
    }

    /** The scanner can still fail. */
    public function testTheScannerCatchesTheUnsafeSpelling(): void
    {
        $hostile = <<<'PHP'
            <?php
            final class Bad {
                public function fetch(string $url): void {
                    $response = wp_remote_get($url, ['timeout' => 5]);
                }
            }
            PHP;

        self::assertNotSame([], self::scan($hostile));
    }

    /**
     * And it does not fire on the safe spelling, which shares every one of
     * those characters — `wp_safe_remote_get` contains no `wp_remote_get`, but
     * a lazier pattern than this one would still have to prove it.
     */
    public function testTheScannerAcceptsTheSafeSpelling(): void
    {
        $fine = <<<'PHP'
            <?php
            final class Fine {
                public function fetch(string $url): void {
                    $response = wp_safe_remote_get($url, ['timeout' => 5]);
                    $other = wp_safe_remote_request($url, ['method' => 'POST']);
                }
            }
            PHP;

        self::assertSame([], self::scan($fine));
    }

    /**
     * A mention in a comment is documentation, not a call. This class's own
     * docblock names the unsafe functions, and so does `WpHttpClient`'s.
     */
    public function testANameInACommentIsNotACallSite(): void
    {
        $documented = <<<'PHP'
            <?php
            /**
             * Uses wp_safe_remote_request() rather than wp_remote_request(),
             * because the latter does not reject unsafe URLs.
             */
            final class Documented {}
            PHP;

        self::assertSame([], self::scan($documented));
    }

    /**
     * Call sites of an unsafe wrapper, as `line: snippet`.
     *
     * @return list<string>
     */
    private static function scan(string $source): array
    {
        $found = [];
        $names = implode('|', self::UNSAFE);

        foreach (self::codeLines($source) as $number => $line) {
            /*
             * `(?<![a-z_])` is the whole trick: without it `wp_safe_remote_get`
             * contains `wp_remote_get` at no offset — but `wp_safe_remote_get`
             * would still match a naive `/wp_remote_get\(/` if someone wrote
             * `wp_safe_wp_remote_get`. The guard makes the name a whole token.
             */
            if (preg_match('/(?<![a-z_])(' . $names . ')\s*\(/i', $line, $m) === 1) {
                $found[] = $number . ': ' . trim($line);
            }
        }

        return $found;
    }

    /**
     * Source lines with comments removed, so a docblock naming a function is
     * not read as calling it.
     *
     * @return array<int, string>
     */
    private static function codeLines(string $source): array
    {
        $lines = [];
        $number = 0;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $number = $token[2];

                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $text = $token[1];
            } else {
                $text = $token;
            }

            $lines[$number] = ($lines[$number] ?? '') . $text;
        }

        return $lines;
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['src', 'integrations'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
