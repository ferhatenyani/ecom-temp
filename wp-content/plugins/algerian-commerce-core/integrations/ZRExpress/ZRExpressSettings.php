<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\ZRExpress;

/**
 * What one client configures about their ZR Express account.
 *
 * Pure — no WordPress. Deliberately much smaller than `YalidineSettings`, and
 * that is a fact about the two couriers rather than an omission: ZR Express
 * takes no origin, no insurance flag and no parcel dimensions on a parcel. The
 * supplier's origin lives on the account, so there is nothing here for a client
 * to get wrong.
 *
 * Stored as the `ac_zrexpress_settings` option and read in
 * `Plugin::shippingProviders()`. Nothing here throws: a bad option value must
 * not fatal the plugin on boot, so it falls back to the default and is
 * collected in `problems()`, which `wp algerian-commerce shipping-check`
 * prints.
 */
final class ZRExpressSettings
{
    public const DEFAULTS = [
        'base_url' => 'https://api.zrexpress.app/api/v1.0/',
        'timeout' => 15,
    ];

    /** @var list<string> */
    private array $problems;

    private function __construct(
        public readonly string $baseUrl,
        public readonly int $timeout,
        array $problems = []
    ) {
        $this->problems = $problems;
    }

    /** @param array<string, mixed> $settings as stored in the option */
    public static function fromArray(array $settings): self
    {
        $problems = [];

        foreach (array_keys($settings) as $key) {
            if (!array_key_exists((string) $key, self::DEFAULTS)) {
                $problems[] = "Unknown setting \"{$key}\" — ignored.";
            }
        }

        $baseUrl = (string) ($settings['base_url'] ?? self::DEFAULTS['base_url']);

        if (!str_starts_with($baseUrl, 'https://')) {
            // docs/SECURITY.md: every provider call is over TLS.
            $problems[] = 'The base URL must be https — using the default.';
            $baseUrl = (string) self::DEFAULTS['base_url'];
        }

        $timeout = $settings['timeout'] ?? self::DEFAULTS['timeout'];

        if (!is_numeric($timeout) || (int) $timeout < 1) {
            if (array_key_exists('timeout', $settings)) {
                $problems[] = '"timeout" must be a whole number of seconds — using the default.';
            }

            $timeout = self::DEFAULTS['timeout'];
        }

        return new self(rtrim($baseUrl, '/') . '/', (int) $timeout, $problems);
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['base_url' => $this->baseUrl, 'timeout' => $this->timeout];
    }
}
