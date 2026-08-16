<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Seed\Seeder;
use WP_CLI;
use WP_User;

/**
 * wp algerian-commerce seed
 *
 * Loads the development fixtures in `data/seed/` — roadmap §67, docs/PLAN.md
 * §46 and §47. `scripts/seed.sh` is the shell wrapper; this is the mechanism.
 *
 * **It runs as an administrator, and that is the honest arrangement.** Every
 * service asserts a capability (`Permissions::assert`), so a seeder with no
 * identity would have to go around the check — which is the one thing
 * `Seeder` exists not to do. WP-CLI has no current user, so one is chosen here:
 * `--as=<login>` when it matters, otherwise the first administrator on the site.
 * A seed written by nobody is a seed that proves nothing about the API.
 *
 * Geography is deliberately **not** seeded here. §51 already ships 69 wilayas
 * and 1,541 communes as generated JSON with its own importer, and a second
 * source of locations is a second source of truth — `scripts/seed.sh` calls
 * `import-algeria` first instead.
 */
final class SeedCommand
{
    public function __construct(private readonly Seeder $seeder)
    {
    }

    /**
     * Load categories, products, variations, customers, coupons and orders.
     *
     * Idempotent: products and variations are keyed on SKU, customers on email,
     * coupons on code, and orders on the `ac_seed_orders` option. Running it
     * twice updates rather than duplicates.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Validate the fixtures and report what would be written, writing nothing.
     *
     * [--as=<login>]
     * : Run as this account instead of the first administrator.
     *
     * [--keep-notifications]
     * : Leave the notifications the seeded orders queue. By default they are
     * discarded: a fictional order must not mail the shop's real admin address.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce seed --dry-run
     *     wp algerian-commerce seed
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        unset($args);

        $dryRun = isset($assoc['dry-run']);
        $keep = isset($assoc['keep-notifications']);

        if (!$dryRun) {
            $this->assumeIdentity((string) ($assoc['as'] ?? ''));
        }

        WP_CLI::log('Reading ' . $this->seeder->dataPath());

        $result = $this->seeder->seed($dryRun, $keep);

        if ($result['errors'] !== []) {
            foreach (array_slice($result['errors'], 0, 20) as $error) {
                WP_CLI::log('  ' . $error);
            }

            $extra = count($result['errors']) - 20;

            if ($extra > 0) {
                WP_CLI::log("  … and {$extra} more.");
            }
        }

        WP_CLI\Utils\format_items(
            'table',
            array_map(
                static fn (string $name): array => [
                    'dataset' => $name,
                    'created' => $result[$name]['created'],
                    'updated' => $result[$name]['updated'],
                ],
                ['categories', 'products', 'variations', 'customers', 'coupons', 'orders']
            ),
            ['dataset', 'created', 'updated']
        );

        if ($result['errors'] !== []) {
            // Non-zero, because this runs inside setup.sh: a shop seeded with
            // half a catalogue is a shop whose tests fail somewhere unrelated.
            WP_CLI::error(sprintf('%d problem(s) in the seed data.', count($result['errors'])));
        }

        if ($dryRun) {
            WP_CLI::success('Seed data is valid. Nothing was written.');

            return;
        }

        if ($result['notifications_discarded'] > 0) {
            WP_CLI::log(sprintf(
                '%d queued notification(s) discarded — seeded orders do not notify anybody. '
                . 'Use --keep-notifications to keep them.',
                $result['notifications_discarded']
            ));
        }

        WP_CLI::success('Seed data loaded.');
    }

    /**
     * Become somebody who can write.
     *
     * An explicit `--as` is checked rather than trusted: naming an account that
     * cannot manage products produces a wall of 403s from the services, which
     * reads as a broken seeder rather than as the wrong login.
     */
    private function assumeIdentity(string $login): void
    {
        if ($login !== '') {
            $user = get_user_by('login', $login);

            if (!$user instanceof WP_User) {
                WP_CLI::error("No account with the login \"{$login}\".");
            }

            if (!user_can($user, 'ac_manage_products')) {
                WP_CLI::error("\"{$login}\" cannot manage products, so it cannot seed.");
            }

            wp_set_current_user($user->ID);

            return;
        }

        $administrators = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
        $administrator = $administrators[0] ?? null;

        if (!$administrator instanceof WP_User) {
            WP_CLI::error('No administrator to run as. Pass --as=<login>.');
        }

        wp_set_current_user($administrator->ID);
        WP_CLI::log("Running as {$administrator->user_login}.");
    }
}
