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
     * Verified 2026-08-14: the dimension and weight fields are optional. A
     * parcel created without any of them was accepted and read back with
     * `length`, `width`, `height` and `weight` all null — as are the account's
     * real parcels. The defaults here are therefore 0 = "do not send".
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

    /**
     * The longest a courier call may hold a PHP worker — docs/SECURITY.md, §55.
     *
     * §55 asks for explicit timeouts, and a setting that can raise one to ten
     * minutes removes the bound as surely as never setting it: a hung courier
     * would hold a worker for that long on every request, checkout included.
     * Sixty seconds is far beyond any answer worth waiting for and still short
     * of a request that has effectively stopped.
     */
    public const MAX_TIMEOUT = 60;

    /** @var list<string> */
    private array $problems;

    private function __construct(
        public readonly int $originWilayaId,
        public readonly bool $doInsurance,
        public readonly bool $hasExchange,
        /**
         * Whether the courier is told delivery is already paid for.
         *
         * Partly verified 2026-08-14. The flag is accepted and read back as
         * `freeshipping: 1`, and the parcel still reports a `delivery_fee` of
         * its own (650 DZD, Béjaïa → Alger Centre) — so the fee is always
         * *quoted*; what the flag changes is who absorbs it. The reference
         * implementation's production parcels are the mirror image: they send
         * `freeshipping: 0` with `price` set to the goods alone, and Yalidine
         * adds its fee at the door.
         *
         * ASSUMPTION (unverified): that with the flag on, the driver collects
         * exactly `price`. It cannot be read back from the API — only from a
         * delivered parcel's payout — but it is the correct default *for this
         * codebase* either way: what a shop charges for delivery is its own
         * tariff (`ac_shipping_rates`), it is already inside the order total,
         * and the driver must not collect it twice. A client who wants
         * Yalidine's fee charged at the door sets this false.
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
            self::boundedTimeout($int('timeout', 1), $problems),
            $problems
        );
    }

    /**
     * Clamp rather than reject: a client who asked for a long timeout wants
     * patience, and the ceiling gives them as much of it as is safe. Reported
     * like every other corrected value, so `wp algerian-commerce shipping-check`
     * says what actually applies.
     *
     * @param list<string> $problems
     */
    private static function boundedTimeout(int $timeout, array &$problems): int
    {
        if ($timeout <= self::MAX_TIMEOUT) {
            return $timeout;
        }

        $problems[] = sprintf('"timeout" is capped at %d seconds — using that.', self::MAX_TIMEOUT);

        return self::MAX_TIMEOUT;
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
