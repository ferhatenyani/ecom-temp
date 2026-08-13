<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Yalidine;

/**
 * What one client configures about their Yalidine account.
 *
 * Pure — no WordPress. Roadmap §56 is emphatic about the split, because this
 * plugin is cloned per client: **credentials come from `.env`, everything else
 * is a setting, and nothing courier-specific may be hard-coded.** A shop in
 * Béjaïa and a shop in Oran run the same code and differ only in this object.
 *
 * The reference implementation hard-codes a 58-case wilaya-name→id `switch`
 * with a default of "Béjaïa", which is one client's origin compiled into the
 * application. Here the origin is `originWilayaId` — an id in the §51 dataset,
 * translated to Yalidine's own spelling through the destination sync — and an
 * unset origin refuses to create a parcel rather than guessing at someone
 * else's warehouse.
 *
 * Stored as the `ac_yalidine_settings` option and read in
 * `Plugin::shippingProviders()`. It is deliberately not part of `.env`: a
 * warehouse address is configuration a shop owner changes, not a secret.
 *
 * ```
 * wp option update ac_yalidine_settings --format=json '{"origin_wilaya_id":6,"weight":1}'
 * ```
 *
 * **Nothing here throws.** A bad option value must not fatal the plugin on
 * boot, so unusable input falls back to the default and is collected in
 * `problems()`, which `wp algerian-commerce shipping-check` prints.
 */
final class YalidineSettings
{
    /**
     * ASSUMPTION (unverified — no merchant account, no sandbox): the parcel
     * payload's dimension and weight fields are optional and may be omitted.
     * Sources 1 and 2 both send them only when set, which is why the defaults
     * here are 0 = "do not send". If the live API turns out to require them,
     * this is the one place to give them a real default.
     */
    public const DEFAULTS = [
        'origin_wilaya_id' => 0,
        'do_insurance' => false,
        'has_exchange' => false,
        'freeshipping' => true,
        'length' => 0,
        'width' => 0,
        'height' => 0,
        'weight' => 0,
        'base_url' => 'https://api.yalidine.app/v1/',
        'timeout' => 15,
    ];

    /** @var list<string> */
    private array $problems;

    private function __construct(
        public readonly int $originWilayaId,
        public readonly bool $doInsurance,
        public readonly bool $hasExchange,
        /**
         * Whether the courier is told delivery is already paid for.
         *
         * ASSUMPTION (unverified): `freeshipping: true` means Yalidine collects
         * exactly `price` and does not add its own delivery fee on top. That is
         * the correct default *for this codebase* — what a shop charges for
         * delivery is its own tariff (`ac_shipping_rates`), it is already inside
         * the order total, and the driver must not collect it twice. A client
         * who wants Yalidine's fee added at the door sets this false.
         */
        public readonly bool $freeshipping,
        public readonly int $length,
        public readonly int $width,
        public readonly int $height,
        public readonly int $weight,
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
                // Reported rather than ignored: a typo like "origin_wilaya"
                // otherwise looks configured and behaves as if it were never
                // set, which is the failure mode hardest to see.
                $problems[] = "Unknown setting \"{$key}\" — ignored.";
            }
        }

        $int = static function (string $key, int $min) use ($settings, &$problems): int {
            $value = $settings[$key] ?? self::DEFAULTS[$key];

            if (!is_numeric($value) || (int) $value < $min) {
                if (array_key_exists($key, $settings)) {
                    $problems[] = "\"{$key}\" must be a whole number of at least {$min} — using the default.";
                }

                return (int) self::DEFAULTS[$key];
            }

            return (int) $value;
        };

        $bool = static function (string $key) use ($settings, &$problems): bool {
            $value = $settings[$key] ?? self::DEFAULTS[$key];

            if (!is_bool($value)) {
                $problems[] = "\"{$key}\" must be true or false — using the default.";

                return (bool) self::DEFAULTS[$key];
            }

            return $value;
        };

        $baseUrl = (string) ($settings['base_url'] ?? self::DEFAULTS['base_url']);

        if (!str_starts_with($baseUrl, 'https://')) {
            // docs/SECURITY.md: every provider call is over TLS. A base URL is
            // a setting, so it is also a way to turn that off by accident.
            $problems[] = 'The base URL must be https — using the default.';
            $baseUrl = (string) self::DEFAULTS['base_url'];
        }

        return new self(
            $int('origin_wilaya_id', 0),
            $bool('do_insurance'),
            $bool('has_exchange'),
            $bool('freeshipping'),
            $int('length', 0),
            $int('width', 0),
            $int('height', 0),
            $int('weight', 0),
            rtrim($baseUrl, '/') . '/',
            $int('timeout', 1),
            $problems
        );
    }

    /** A shop that has not said where it ships from cannot create a parcel. */
    public function hasOrigin(): bool
    {
        return $this->originWilayaId > 0;
    }

    /**
     * What was wrong with the stored option, in words an operator can act on.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The dimension fields, omitting the ones left at zero.
     *
     * @return array<string, int>
     */
    public function parcelDimensions(): array
    {
        $dimensions = [];

        foreach (['length' => $this->length, 'width' => $this->width, 'height' => $this->height, 'weight' => $this->weight] as $field => $value) {
            if ($value > 0) {
                $dimensions[$field] = $value;
            }
        }

        return $dimensions;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'origin_wilaya_id' => $this->originWilayaId,
            'do_insurance' => $this->doInsurance,
            'has_exchange' => $this->hasExchange,
            'freeshipping' => $this->freeshipping,
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'weight' => $this->weight,
            'base_url' => $this->baseUrl,
            'timeout' => $this->timeout,
        ];
    }
}
