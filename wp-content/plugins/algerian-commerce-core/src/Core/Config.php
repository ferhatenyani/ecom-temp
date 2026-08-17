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
        'ENABLE_MARKETING_PIXELS',
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
            /*
             * The mail transport (roadmap §29, §30). WordPress sends through
             * PHP's `mail()` unless something configures PHPMailer, and there
             * is no MTA in these containers — so without SMTP_HOST every
             * message fails with "sendmail: can't connect". `MailTransport`
             * is what feeds these to WordPress; before it existed they were
             * documented, read here, and wired to nothing.
             *
             * Port and encryption are separate because they genuinely vary:
             * 587 with STARTTLS and 465 with implicit TLS are both common, and
             * guessing one from the other is how mail silently stops sending.
             */
            'SMTP_HOST',
            'SMTP_PORT',
            'SMTP_ENCRYPTION',
            'SMTP_USERNAME',
            'SMTP_PASSWORD',
            /*
             * The From: address on transactional mail, and where operational
             * alerts go. Read here rather than through `getenv()` at the call
             * site so both are testable without real environment variables.
             */
            'AC_MAIL_FROM',
            'AC_ADMIN_EMAIL',
            /*
             * Addresses whose `X-Forwarded-For` may be believed — the reverse
             * proxy, and nothing else. Empty means the header is never read,
             * which is the behaviour every call site had before §86. See
             * `Security\ClientIp`.
             */
            'AC_TRUSTED_PROXIES',
            'AC_LOG_LEVEL',
            'AC_CORS_ORIGINS',
            'AC_RATE_LIMIT_READS',
            'AC_RATE_LIMIT_WRITES',
            'AC_RATE_LIMIT_UPLOADS',
            /*
             * `GET /orders/track` only (roadmap §84), on top of the read limit.
             * The read limit is 600 a minute and was sized for a dashboard
             * holding a credential; this route is unauthenticated and its key is
             * a MAC, so it gets its own, much smaller allowance.
             */
            'AC_RATE_LIMIT_TRACKING',
            'AC_RATE_LIMIT_AUTH_FAILURES',
            'AC_RATE_LIMIT_DISABLED',
            'AC_RATE_LIMIT_TRUSTED_IPS',
            /*
             * The upload size cap in bytes (roadmap §61). PHP's own
             * `upload_max_filesize` still wins — `UploadPolicy::withCap()`
             * takes the lower of the two, because a cap the web server refuses
             * first is a number that lies.
             */
            'AC_MEDIA_MAX_BYTES',
            /*
             * How many seconds an analytics response may be reused (roadmap
             * §63). `0` disables the cache; anything unparseable falls back to
             * the default rather than to zero, so a typo cannot silently turn
             * it off. It is a cache and never a rollup — see `AnalyticsCache`.
             */
            'AC_ANALYTICS_CACHE_TTL',
            /*
             * Meta's two values, and they are not the same kind of thing
             * (roadmap §62b). META_PIXEL_ID is public — it ships inside the
             * storefront's JavaScript, so `GET /marketing/config` serves it.
             * META_CAPI_ACCESS_TOKEN authorises writing conversions into an ad
             * account and appears in no response ever.
             */
            'META_PIXEL_ID',
            'META_CAPI_ACCESS_TOKEN',
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
