<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

/**
 * Configuration and feature flags.
 *
 * Secrets are read from the environment only — never from the options table,
 * never from code (docs/SECURITY.md, "Secrets"). The environment map is
 * injected so this class is unit-testable without WordPress or real env vars.
 */
final class Config
{
    /** Feature flags, all default-off unless the environment enables them. */
    public const FLAGS = [
        'ENABLE_COD',
        'ENABLE_CHARGILY',
        'ENABLE_YALIDINE',
        'ENABLE_ZR_EXPRESS',
        'ENABLE_BLOG',
        'ENABLE_REVIEWS',
        'ENABLE_SMS',
        'ENABLE_WHATSAPP',
    ];

    /** @param array<string, string> $env */
    public function __construct(private readonly array $env = [])
    {
    }

    public static function fromEnvironment(): self
    {
        $keys = [
            ...self::FLAGS,
            /*
             * Yalidine authenticates with two headers, X-API-ID and
             * X-API-TOKEN (roadmap §56) — not a key/secret pair. Named for what
             * the provider calls them, because a credential renamed on the way
             * in is a credential nobody can match against the dashboard it came
             * from.
             */
            'YALIDINE_API_ID',
            'YALIDINE_API_TOKEN',
            'YALIDINE_WEBHOOK_SECRET',
            /*
             * ZR Express identifies the merchant with X-Tenant and authenticates
             * with X-Api-Key (roadmap §57). The webhook secret is Svix's signing
             * secret, which the webhook slice will need.
             */
            'ZR_EXPRESS_TENANT_ID',
            'ZR_EXPRESS_API_KEY',
            'ZR_EXPRESS_WEBHOOK_SECRET',
            /*
             * One key, not two. Chargily signs its webhooks with the API secret
             * key itself, so `CHARGILY_WEBHOOK_SECRET` was removed at §59 rather
             * than left as a slot nothing could correctly fill — see
             * `Integrations\Chargily\ChargilyCredentials` and docs/SECURITY.md.
             * The key also picks the environment: `test_sk_…` is Test Mode.
             */
            'CHARGILY_SECRET_KEY',
            'SMTP_HOST',
            'SMTP_USERNAME',
            'SMTP_PASSWORD',
            'AC_LOG_LEVEL',
            'AC_CORS_ORIGINS',
            'AC_RATE_LIMIT_READS',
            'AC_RATE_LIMIT_WRITES',
            'AC_RATE_LIMIT_AUTH_FAILURES',
            'AC_RATE_LIMIT_DISABLED',
            'AC_RATE_LIMIT_TRUSTED_IPS',
        ];

        $env = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        return new self($env);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->env[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->env[$key]) && $this->env[$key] !== '';
    }

    /**
     * Read a secret. Returns null rather than an empty string so callers must
     * handle "not configured" explicitly instead of sending a blank credential.
     */
    public function secret(string $key): ?string
    {
        $value = $this->env[$key] ?? '';

        return $value === '' ? null : $value;
    }

    public function isEnabled(string $flag): bool
    {
        return self::isTruthy($this->env[$flag] ?? null);
    }

    public static function isTruthy(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
