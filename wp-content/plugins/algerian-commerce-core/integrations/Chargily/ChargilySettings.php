<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Chargily;

/**
 * What one client configures about their Chargily account — roadmap §59.
 *
 * Pure — no WordPress. Stored as the `ac_chargily_settings` option and read in
 * `Plugin::paymentProviders()`, never `.env` and never a constant, for the
 * reason §56 settled for Yalidine: the plugin is cloned per client, and a
 * checkout page's language or a shop's choice about who pays the gateway fee is
 * configuration a client changes, not a credential.
 *
 * Nothing here throws. A bad option value must not be able to fatal the plugin
 * on boot, so it falls back to the default and is collected in `problems()`.
 *
 * ## The two URLs
 *
 * `success_url` is **required by Chargily on every checkout**, and it points at
 * the storefront, not at this API — the shopper's browser goes there when the
 * gateway is done with them. A caller may send its own `return_url` per payment
 * (`PaymentInput`); this is the fallback for when it does not, which is most of
 * the time. Whatever the shopper's browser arrives back carrying is a hint and
 * never proof: the money is confirmed by `verifyPayment()` or a
 * signature-verified webhook (docs/SECURITY.md, "Payments").
 *
 * ## The checkout lifetime is a fact about Chargily, kept as a setting
 *
 * Their reference states a checkout expires automatically after 30 minutes. It
 * lives here rather than as a constant because it is the kind of number a
 * provider changes without telling anyone, and `PaymentPoller` uses it to decide
 * when a `pending` transaction has been waiting long enough to be worth asking
 * about.
 */
final class ChargilySettings
{
    public const DEFAULTS = [
        'test_base_url' => 'https://pay.chargily.net/test/api/v2/',
        'live_base_url' => 'https://pay.chargily.net/api/v2/',
        'success_url' => '',
        'failure_url' => '',
        'locale' => 'fr',
        'payment_method' => '',
        'fees_allocation' => 'merchant',
        'checkout_lifetime' => 30,
        'timeout' => 15,
    ];

    /** Documented on the "Create a checkout" reference. Empty means "let the shopper choose". */
    public const PAYMENT_METHODS = ['edahabia', 'cib', 'chargily_app'];

    /** Documented values for `chargily_pay_fees_allocation`. */
    public const FEE_ALLOCATIONS = ['merchant', 'customer', 'split'];

    /** The checkout page's language. Chargily accepts these three. */
    public const LOCALES = ['ar', 'en', 'fr'];

    /**
     * The longest a gateway call may hold a PHP worker — docs/SECURITY.md, §55.
     * Same ceiling and reasoning as the courier adapters'.
     */
    public const MAX_TIMEOUT = 60;

    /** @var list<string> */
    private array $problems;

    private function __construct(
        public readonly string $testBaseUrl,
        public readonly string $liveBaseUrl,
        public readonly string $successUrl,
        public readonly string $failureUrl,
        public readonly string $locale,
        public readonly string $paymentMethod,
        public readonly string $feesAllocation,
        public readonly int $checkoutLifetime,
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

        $url = static function (string $key, bool $required) use ($settings, &$problems): string {
            $value = trim((string) ($settings[$key] ?? self::DEFAULTS[$key]));

            if ($value === '') {
                return '';
            }

            // docs/SECURITY.md: every provider call is over TLS, and a return
            // URL is a place a customer's browser is sent carrying whatever the
            // gateway appended to it.
            if (!str_starts_with($value, 'https://') || filter_var($value, FILTER_VALIDATE_URL) === false) {
                $problems[] = "\"{$key}\" must be an https URL — ignored.";

                return $required ? (string) self::DEFAULTS[$key] : '';
            }

            return $value;
        };

        $enum = static function (string $key, array $allowed, bool $allowEmpty) use ($settings, &$problems): string {
            $value = strtolower(trim((string) ($settings[$key] ?? self::DEFAULTS[$key])));

            if ($value === '' && $allowEmpty) {
                return '';
            }

            if (!in_array($value, $allowed, true)) {
                if (array_key_exists($key, $settings)) {
                    $problems[] = sprintf(
                        '"%s" must be one of %s — using the default.',
                        $key,
                        implode(', ', $allowed)
                    );
                }

                return (string) self::DEFAULTS[$key];
            }

            return $value;
        };

        $number = static function (string $key, int $min, int $max) use ($settings, &$problems): int {
            $value = $settings[$key] ?? self::DEFAULTS[$key];

            if (!is_numeric($value) || (int) $value < $min) {
                if (array_key_exists($key, $settings)) {
                    $problems[] = "\"{$key}\" must be a whole number — using the default.";
                }

                return (int) self::DEFAULTS[$key];
            }

            if ((int) $value > $max) {
                // Clamped rather than refused, as ZRExpressSettings does: the
                // client asked for patience and this is as much as is safe.
                $problems[] = sprintf('"%s" is capped at %d — using that.', $key, $max);

                return $max;
            }

            return (int) $value;
        };

        return new self(
            rtrim($url('test_base_url', true), '/') . '/',
            rtrim($url('live_base_url', true), '/') . '/',
            $url('success_url', false),
            $url('failure_url', false),
            $enum('locale', self::LOCALES, false),
            $enum('payment_method', self::PAYMENT_METHODS, true),
            $enum('fees_allocation', self::FEE_ALLOCATIONS, false),
            $number('checkout_lifetime', 1, 24 * 60),
            $number('timeout', 1, self::MAX_TIMEOUT),
            $problems
        );
    }

    /** Which environment a key belongs to is the key's business — see ChargilyCredentials. */
    public function baseUrl(ChargilyCredentials $credentials): string
    {
        return $credentials->isTestMode() ? $this->testBaseUrl : $this->liveBaseUrl;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'test_base_url' => $this->testBaseUrl,
            'live_base_url' => $this->liveBaseUrl,
            'success_url' => $this->successUrl,
            'failure_url' => $this->failureUrl,
            'locale' => $this->locale,
            'payment_method' => $this->paymentMethod,
            'fees_allocation' => $this->feesAllocation,
            'checkout_lifetime' => $this->checkoutLifetime,
            'timeout' => $this->timeout,
        ];
    }
}
