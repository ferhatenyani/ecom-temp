<?php

declare(strict_types=1);

namespace AlgerianCommerce\Settings;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a client-configuration write — roadmap §71, docs/PLAN.md §48.
 *
 * Pure: no WordPress, no options table, so every rule about what a client may
 * configure is a unit test.
 *
 * **What is refused matters more here than what is accepted.** This is the
 * endpoint whose whole purpose is "configure the template without forking it",
 * which makes it the natural place for somebody to try to configure the things
 * that are deliberately not configurable. Three groups are refused **by name**,
 * with the reason attached, rather than silently ignored — the `CustomerInput`
 * device, for the same reason: a caller who sets `currency` and gets a 200 will
 * believe the currency changed.
 *
 *  - **Secrets.** API keys, tokens and webhook secrets are environment
 *    variables and nothing else (docs/SECURITY.md). An options row is readable
 *    by any plugin, survives in a database dump, and is exactly what §48's
 *    "secrets remain environment variables" exists to prevent.
 *  - **Feature flags.** `ENABLE_*` decides which providers are *registered*, and
 *    registration happens once at bootstrap. A flag flipped in the database
 *    mid-request would disagree with the registry that was already built, so the
 *    document reports them and refuses to write them.
 *  - **Currency.** WooCommerce owns it and records it **per order**, so changing
 *    it later does not rewrite the orders already taken — it silently splits the
 *    order book into two currencies. It is set once at provisioning by
 *    `scripts/setup.sh`.
 */
final class SettingsInput
{
    /** Writable blocks, and the keys each may carry. */
    public const SCHEMA = [
        'store' => ['name', 'description', 'storefront_url', 'logo_id'],
        'contact' => ['email', 'phone', 'address', 'wilaya', 'hours'],
        'legal' => ['registered_name', 'rc', 'nif', 'nis', 'ai'],
        'social' => ['facebook', 'instagram', 'tiktok', 'youtube'],
    ];

    /** Refused by name, with the reason returned to the caller. */
    public const REFUSED = [
        'currency' => 'WooCommerce owns the currency and records it per order, so changing it here would '
            . 'split the order book rather than convert it. It is set once at provisioning by scripts/setup.sh.',
        'features' => 'Feature flags are environment variables read once at bootstrap (ENABLE_COD, '
            . 'ENABLE_CHARGILY, …). Set them in .env and restart, or the registry and this document disagree.',
        'providers' => 'Read-only: this reports which providers actually registered, which follows from '
            . 'their credentials and flags.',
        'secrets' => 'Secrets are environment variables and never the options table — docs/SECURITY.md.',
        'api_key' => 'Secrets are environment variables and never the options table — docs/SECURITY.md.',
        'api_token' => 'Secrets are environment variables and never the options table — docs/SECURITY.md.',
        'webhook_secret' => 'Secrets are environment variables and never the options table — docs/SECURITY.md.',
        'meta_capi_access_token' => 'Secrets are environment variables and never the options table.',
        'locale' => 'WordPress owns the locale. Set it with `wp option update WPLANG`.',
        'url' => 'Ambiguous — `store.storefront_url` is the shop customers visit. '
            . 'This backend\'s own address is WordPress\'s, and is not client configuration.',
    ];

    /** Matches WordPress option and WooCommerce address column widths. */
    private const MAX_LENGTH = 200;
    private const MAX_ADDRESS = 500;

    /** @param array<string, array<string, mixed>> $blocks */
    private function __construct(public readonly array $blocks)
    {
    }

    public function isEmpty(): bool
    {
        return $this->blocks === [];
    }

    /** @return array<string, mixed>|null */
    public function block(string $name): ?array
    {
        return $this->blocks[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @throws ApiException 400 naming every bad field, not just the first
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];
        $clean = [];

        foreach (self::REFUSED as $field => $why) {
            if (array_key_exists($field, $payload)) {
                $errors[$field] = $why;
            }
        }

        foreach (array_keys($payload) as $block) {
            $block = (string) $block;

            /*
             * JSON has no comments, and `client.json` is a file a human fills in
             * by hand for each new client (roadmap §73) — it needs somewhere to
             * say what a field is for. A leading underscore is the conventional
             * answer, so `_comment` is ignored rather than rejected as unknown.
             * Ignoring is safe here precisely because everything else is not:
             * a key that is neither known nor underscored is still an error.
             */
            if (str_starts_with($block, '_')) {
                continue;
            }

            if (!isset(self::SCHEMA[$block]) && !isset(self::REFUSED[$block])) {
                $errors[$block] = 'Unknown block. Known: ' . implode(', ', array_keys(self::SCHEMA)) . '.';
            }
        }

        foreach (self::SCHEMA as $block => $fields) {
            if (!array_key_exists($block, $payload)) {
                continue;
            }

            $value = $payload[$block];

            if (!is_array($value)) {
                $errors[$block] = 'Must be an object.';
                continue;
            }

            $unknown = array_diff(array_keys($value), $fields);

            if ($unknown !== []) {
                $errors[$block] = 'Unknown keys: ' . implode(', ', $unknown)
                    . '. Known: ' . implode(', ', $fields) . '.';
                continue;
            }

            $out = [];

            foreach ($value as $field => $raw) {
                $field = (string) $field;
                $result = self::field($block, $field, $raw);

                if (is_string($result)) {
                    $errors["{$block}.{$field}"] = $result;
                    continue;
                }

                $out[$field] = $result[0];
            }

            if ($out !== []) {
                $clean[$block] = $out;
            }
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The settings are invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @return array{0: mixed}|string the cleaned value wrapped in an array, or an error message
     */
    private static function field(string $block, string $field, mixed $raw): array|string
    {
        // Null clears. A client that has no TikTok must be able to say so, and
        // an empty string is how a form sends "cleared".
        if ($raw === null || $raw === '') {
            return [$field === 'logo_id' ? 0 : ''];
        }

        if ($field === 'logo_id') {
            if (!is_numeric($raw) || (int) $raw < 0) {
                return 'Must be an attachment id, or null to clear.';
            }

            return [(int) $raw];
        }

        if (!is_scalar($raw)) {
            return 'Must be text.';
        }

        $value = trim((string) $raw);
        $max = $field === 'address' ? self::MAX_ADDRESS : self::MAX_LENGTH;

        if (mb_strlen($value) > $max) {
            return "Must be {$max} characters or fewer.";
        }

        if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Must be an email address.';
        }

        if ($field === 'storefront_url' || $block === 'social') {
            if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                return 'Must be a URL, including https://.';
            }

            /*
             * `javascript:` and `data:` are valid URLs to filter_var and are
             * cross-site scripting the moment a storefront renders one as a
             * link. The scheme allowlist is the check, not the URL validator.
             */
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

            if (!in_array($scheme, ['http', 'https'], true)) {
                return 'Must be an http or https URL.';
            }
        }

        return [$value];
    }
}
