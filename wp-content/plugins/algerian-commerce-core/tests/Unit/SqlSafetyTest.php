<?php

declare(strict_types=1);

namespace AlgerianCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Roadmap §65, "Security → SQL injection", and docs/SECURITY.md's baseline
 * requirement that **all SQL goes through `$wpdb->prepare()`**.
 *
 * This is a static check over the shipped source rather than a payload test.
 * The payload half lives in `tests/Api/security.php` and asks "does `' OR
 * '1'='1` widen a result set"; it can only ever cover the routes somebody
 * thought to point it at. This one covers every `$wpdb` call site there is, and
 * it is the half that catches the repository written *next* — which is the
 * mistake worth guarding against, because every repository in the codebase
 * today is already correct.
 *
 * **What it proves, and what it does not.** A call site passes when the SQL it
 * is handed either goes through `prepare()` or contains no variable at all
 * beyond an approved table expression. That refuses the mistake this rule
 * exists for — a request value concatenated into a query — and it does not
 * attempt to prove that the arguments *to* `prepare()` are the right ones, or
 * that a string assembled several statements earlier is clean. It is a guard,
 * not a proof, and `AnalyticsRepository`'s `statusClause()` is the worked
 * example of the thing a static check cannot see: placeholders counted in PHP
 * and values passed alongside them. Those are covered by their own unit tests.
 *
 * **It follows an assignment one step, and it has to.** Half the repositories
 * here write `$prepared = $params === [] ? $sql : …->prepare($sql, $params);`
 * on the line before the call, and the other half spell the same ternary inline
 * inside the call. Judging only the tokens at the call site passes the second
 * and fails the first, which would make the check a style rule about line
 * breaks rather than a check about SQL. So a variable is trusted when **every**
 * assignment to it in that file is either a `prepare()` expression or
 * `$wpdb->prefix . 'literal'` — one bad assignment anywhere in the file
 * disqualifies the name, which is the direction that fails safe. Two limits
 * come with it, both real: the match is by name within a file, so a file that
 * used one name for two different things would confuse it; and a ternary that
 * can yield the *unprepared* branch counts as prepared. That second one is
 * sound in every case here — the unprepared branch is taken only when there are
 * no placeholders to bind — but it is an argument this test cannot make, so it
 * is written down instead of being implied.
 *
 * The scanner asserts its own reach before it asserts anything else. A regex
 * that quietly matched nothing would report a clean codebase in exactly the
 * same way as a clean codebase does — the failure this repository has already
 * been bitten by once, in the media test that grepped an empty body.
 */
final class SqlSafetyTest extends TestCase
{
    /**
     * The `$wpdb` methods that take a SQL **string**.
     *
     * `insert()`, `update()`, `delete()` and `replace()` are deliberately not
     * here: they take a column => value array and build the statement
     * themselves, so there is no string for a value to be concatenated into.
     */
    private const SQL_STRING_METHODS = ['query', 'get_results', 'get_row', 'get_var', 'get_col'];

    /**
     * Variables allowed to appear inside an unprepared SQL string.
     *
     * `$this` and `$wpdb` reach table names and nothing else — see
     * `testTableNamesAreNeverBuiltFromInput()`, which is what makes that
     * sentence true rather than hopeful.
     */
    private const TABLE_VARIABLES = ['$this', '$wpdb'];

    /** How many call sites the scan must find before its silence means anything. */
    private const MINIMUM_CALL_SITES = 50;

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (['src', 'integrations', 'migrations'] as $dir) {
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

    /**
     * Every SQL string handed to `$wpdb` is either prepared or has no variable
     * in it.
     */
    public function testEverySqlStringIsPreparedOrConstant(): void
    {
        $sites = 0;
        $unsafe = [];

        foreach (self::sourceFiles() as $path) {
            $source = (string) file_get_contents($path);
            $label = basename(dirname($path)) . '/' . basename($path);

            $sites += count(self::callSites($source));

            foreach (self::scan($source) as $finding) {
                $unsafe[] = $label . ':' . $finding;
            }
        }

        self::assertGreaterThanOrEqual(
            self::MINIMUM_CALL_SITES,
            $sites,
            'the scanner found almost no $wpdb call sites, so it is not looking where it thinks it is'
        );

        self::assertSame([], $unsafe, "unprepared SQL carrying a variable:\n" . implode("\n", $unsafe));
    }

    /**
     * The scanner can still see a problem.
     *
     * Without this, every assertion above is satisfied just as well by a broken
     * regex as by a clean codebase, and the two are indistinguishable from the
     * outside — which is the failure mode this repository has already paid for
     * once, in a media assertion that grepped an empty response body and passed.
     *
     * Each of these is a way the real mistake gets written, and the last two are
     * the ones that look prepared at a glance.
     */
    public function testTheScannerCatchesUnpreparedSql(): void
    {
        $hostile = <<<'PHP'
            <?php
            final class Bad {
                public function a(wpdb $wpdb, string $search): void {
                    $wpdb->get_results("SELECT * FROM wp_posts WHERE title = '{$search}'");
                }
                public function b(wpdb $wpdb, string $order): void {
                    $wpdb->get_var('SELECT id FROM wp_posts ORDER BY ' . $order);
                }
                public function c(wpdb $wpdb, string $search): void {
                    $sql = "SELECT * FROM wp_posts WHERE title = '{$search}'";
                    $wpdb->get_row($sql);
                }
                public function d(wpdb $wpdb, string $search): void {
                    $sql = $wpdb->prepare('SELECT * FROM wp_posts WHERE id = %d', 1);
                    $sql = "SELECT * FROM wp_posts WHERE title = '{$search}'";
                    $wpdb->query($sql);
                }
            }
            PHP;

        self::assertSame(
            ['4  $wpdb->get_results()', '7  $wpdb->get_var()', '11  $wpdb->get_row()', '16  $wpdb->query()'],
            self::scan($hostile),
            'the scanner must flag a value concatenated into SQL, including one assigned first'
        );
    }

    /** The shapes this codebase actually uses must not be flagged. */
    public function testTheScannerAcceptsThePreparedSpellingsInUse(): void
    {
        $fine = <<<'PHP'
            <?php
            final class Fine {
                private function table(): string { return $this->wpdb->prefix . 'ac_shipments'; }
                public function a(array $params): void {
                    $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE id = %d";
                    $prepared = $params === [] ? $sql : $this->wpdb->prepare($sql, $params);
                    $this->wpdb->get_var($prepared);
                }
                public function b(): void {
                    $this->wpdb->get_results($this->wpdb->prepare('SELECT * FROM x WHERE id = %d', 1));
                }
                public function c(wpdb $wpdb): void {
                    $table = $wpdb->prefix . 'ac_shipments';
                    $wpdb->query("ALTER TABLE {$table} ADD COLUMN live_order_id bigint(20) NULL");
                }
                public function d(): void {
                    $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
                }
            }
            PHP;

        self::assertSame([], self::scan($fine));
    }

    /**
     * Every unsafe call site in one file, as `line  $wpdb->method()`.
     *
     * @return list<string>
     */
    private static function scan(string $source): array
    {
        $trusted = [...self::TABLE_VARIABLES, ...self::safeVariables($source)];
        $unsafe = [];

        foreach (self::callSites($source) as $site) {
            if (!self::isSafe($site['argument'], $trusted)) {
                $unsafe[] = sprintf('%d  $wpdb->%s()', $site['line'], $site['method']);
            }
        }

        return $unsafe;
    }

    /**
     * A table name is `$wpdb->prefix` plus a literal, or WooCommerce's own
     * utility for the orders table — never an argument.
     *
     * This is the assumption the check above rests on. Without it, "the only
     * variable in that string is `$this`" would be worth nothing: a
     * `table(string $name)` helper would make every unprepared query in the
     * codebase injectable through a variable this scanner had waved through.
     */
    public function testTableNamesAreNeverBuiltFromInput(): void
    {
        $helpers = 0;
        $suspect = [];

        foreach (self::sourceFiles() as $path) {
            $source = (string) file_get_contents($path);

            // Every zero-argument method whose name ends in `table` or `Table`,
            // with the expression it returns.
            if (
                preg_match_all(
                    '/function\s+(\w*[tT]able)\s*\(([^)]*)\)\s*:\s*string\s*\{\s*return\s+([^;]+);/',
                    $source,
                    $matches,
                    PREG_SET_ORDER
                ) === false
            ) {
                continue;
            }

            foreach ($matches as [$_, $name, $params, $returns]) {
                $helpers++;

                $returns = trim(preg_replace('/\s+/', ' ', $returns) ?? '');

                $safe = $params === ''
                    && (
                        // $wpdb->prefix . 'ac_shipments'
                        preg_match("/^\\\$(this->)?wpdb->prefix \. '[a-z0-9_]+'$/", $returns) === 1
                        // OrderUtil::get_table_for_orders()
                        || preg_match('/^OrderUtil::get_table_for_\w+\(\)$/', $returns) === 1
                    );

                if (!$safe) {
                    $suspect[] = sprintf(
                        '%s  %s(%s) returns %s',
                        basename(dirname($path)) . '/' . basename($path),
                        $name,
                        $params,
                        $returns
                    );
                }
            }
        }

        self::assertGreaterThanOrEqual(
            10,
            $helpers,
            'no table helpers were found, so this assertion is about nothing'
        );

        self::assertSame(
            [],
            $suspect,
            "a table name that is not a literal on \$wpdb->prefix:\n" . implode("\n", $suspect)
        );
    }

    /**
     * Find each `$wpdb->method(` call and return the tokens of its first
     * argument.
     *
     * Tokenised rather than grepped, because the first argument is routinely a
     * multi-line string containing brackets, quotes and `%s`, and a regex that
     * tries to find where it ends is wrong on the first interesting query.
     *
     * @return list<array{method: string, line: int, argument: list<array{0: int, 1: string, 2: int}|string>}>
     */
    private static function callSites(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if (!is_array($name) || $name[0] !== T_STRING
                || !in_array($name[1], self::SQL_STRING_METHODS, true)
            ) {
                continue;
            }

            // `->query(` on something that is not $wpdb — a WP_Query, an HTTP
            // client — is not this test's business.
            if (!self::receiverIsWpdb($tokens, $i)) {
                continue;
            }

            $open = self::nextSignificant($tokens, $i + 2);

            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $sites[] = [
                'method' => $name[1],
                'line' => $name[2],
                'argument' => self::firstArgument($tokens, $open),
            ];
        }

        return $sites;
    }

    /**
     * Whether the `->method(` at $i is being called on `$wpdb`.
     *
     * Both spellings in this codebase end in the same two tokens: `$wpdb` for a
     * global, and `->wpdb` for the injected property.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function receiverIsWpdb(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && $token[0] === T_VARIABLE) {
                return $token[1] === '$wpdb';
            }

            // $this->wpdb->get_results(...)
            return is_array($token) && $token[0] === T_STRING && $token[1] === 'wpdb';
        }

        return false;
    }

    /**
     * The tokens between `(` and the first top-level `,` (or the closing `)`).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function firstArgument(array $tokens, int $open): array
    {
        $depth = 0;
        $argument = [];
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(' || $token === '[') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($token === ')' || $token === ']') {
                $depth--;

                if ($depth === 0) {
                    return $argument;
                }
            } elseif ($token === ',' && $depth === 1) {
                return $argument;
            }

            $argument[] = $token;
        }

        return $argument;
    }

    /**
     * The variables in one file that are allowed to appear in SQL.
     *
     * Three assignment shapes qualify, and each is safe for its own reason:
     * a `prepare()` expression, a table name on `$wpdb->prefix`, and a class
     * constant or string literal — a `const` is fixed when the file is parsed
     * and cannot hold a request value, which is what migration 006's
     * `$column = self::COLUMN` relies on.
     *
     * Deliberately "every": a single assignment that is none of the three
     * disqualifies the name for the whole file, so the way to lose the
     * exemption is to write the dangerous thing, not to hide it behind a second
     * spelling.
     *
     * @return list<string>
     */
    private static function safeVariables(string $source): array
    {
        if (preg_match_all('/(\$\w+)\s*=\s*([^;]+);/s', $source, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        /** @var array<string, bool> $verdicts */
        $verdicts = [];

        foreach ($matches as [$_, $name, $expression]) {
            $expression = trim(preg_replace('/\s+/', ' ', $expression) ?? '');

            $safe = str_contains($expression, 'prepare(')
                || preg_match("/^\\\$(this->)?wpdb->prefix \. '[a-z0-9_]+'$/", $expression) === 1
                || preg_match('/^(self|static|[A-Z]\w*)::[A-Z][A-Z0-9_]*$/', $expression) === 1
                || preg_match("/^'[^']*'$/", $expression) === 1;

            $verdicts[$name] = ($verdicts[$name] ?? true) && $safe;
        }

        return array_keys(array_filter($verdicts));
    }

    /**
     * A first argument is safe when `prepare()` is applied to it, or when every
     * variable it mentions is one this file is allowed to put into SQL.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $argument
     * @param list<string> $trusted
     */
    private static function isSafe(array $argument, array $trusted): bool
    {
        $variables = [];

        // Interpolation needs no special case: in `"SELECT * FROM {$this->table()}"`
        // the tokeniser emits `$this` as its own T_VARIABLE, so the branch below
        // sees it exactly as it would outside a string. Heredocs behave the same.
        foreach ($argument as $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === 'prepare') {
                return true;
            }

            if ($token[0] === T_VARIABLE) {
                $variables[] = $token[1];
            }
        }

        foreach ($variables as $variable) {
            if (!in_array($variable, $trusted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
