<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Settings\SettingsService;
use WP_CLI;
use WP_User;

/**
 * wp algerian-commerce settings
 *
 * Read or apply the client configuration — roadmap §71, and the step §73's
 * provisioning flow could not previously automate.
 *
 * **This is what turns §73's diagram into a script.** That flow reads: clone the
 * template, copy `.env`, *set client configuration*, `docker compose up`,
 * `setup.sh`, configure integrations, deploy. Every step but the third was
 * already a command. `--from=client.json` is the third, so provisioning a new
 * client is a file somebody fills in rather than a checklist somebody follows.
 *
 * It runs as an administrator for the same reason `SeedCommand` does: the
 * service asserts `ac_manage_settings`, and a command that went around the
 * check would be the only writer in this codebase that does.
 */
final class SettingsCommand
{
    public function __construct(private readonly SettingsService $service)
    {
    }

    /**
     * Show the configuration, or apply a JSON document to it.
     *
     * ## OPTIONS
     *
     * [--from=<file>]
     * : Apply this JSON file. Use "-" to read from STDIN.
     *
     * [--format=<format>]
     * : json (default) or table, for reads.
     *
     * [--as=<login>]
     * : Run as this account instead of the first administrator.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce settings
     *     wp algerian-commerce settings --from=client.json
     *     wp algerian-commerce settings --format=table
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke(array $args, array $assoc = []): void
    {
        unset($args);

        $this->assumeIdentity((string) ($assoc['as'] ?? ''));

        $from = (string) ($assoc['from'] ?? '');

        if ($from !== '') {
            $this->apply($from);

            return;
        }

        $document = $this->service->document();

        if (($assoc['format'] ?? 'json') === 'table') {
            $rows = [];

            foreach ($document as $block => $fields) {
                foreach ((array) $fields as $key => $value) {
                    $rows[] = [
                        'setting' => "{$block}.{$key}",
                        'value' => is_scalar($value) || $value === null
                            ? var_export($value, true)
                            : wp_json_encode($value),
                    ];
                }
            }

            WP_CLI\Utils\format_items('table', $rows, ['setting', 'value']);

            return;
        }

        WP_CLI::log((string) wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function apply(string $file): void
    {
        $contents = $file === '-' ? (string) file_get_contents('php://stdin') : @file_get_contents($file);

        if ($contents === false) {
            WP_CLI::error("Cannot read {$file}.");
        }

        $payload = json_decode((string) $contents, true);

        if (!is_array($payload)) {
            WP_CLI::error("{$file} is not valid JSON.");
        }

        try {
            $this->service->update($payload);
        } catch (ApiException $e) {
            /*
             * The per-field breakdown is the whole value of the input class, and
             * a provisioning run that printed only "the settings are invalid"
             * would send somebody back to guess which line of their file is
             * wrong. The refusals carry their reason with them.
             */
            foreach ((array) ($e->details()['fields'] ?? []) as $field => $why) {
                WP_CLI::log("  {$field}: {$why}");
            }

            WP_CLI::error($e->getMessage());
        }

        WP_CLI::success("Applied {$file}.");
    }

    private function assumeIdentity(string $login): void
    {
        if ($login !== '') {
            $user = get_user_by('login', $login);

            if (!$user instanceof WP_User) {
                WP_CLI::error("No account with the login \"{$login}\".");
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
    }
}
