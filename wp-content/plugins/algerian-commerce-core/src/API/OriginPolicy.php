<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

/**
 * The CORS allowlist.
 *
 * Pure parsing and matching, no WordPress — the decision "may this origin
 * call us" is exactly the kind of thing that must be testable in isolation,
 * because a loose match here hands any website a session-authenticated
 * request against the admin API.
 *
 * Matching is exact on scheme, host and port. No wildcards, no subdomain
 * matching, no prefix matching: `https://store.example.dz` does not admit
 * `https://store.example.dz.attacker.com`, and `http://` does not admit
 * `https://`.
 */
final class OriginPolicy
{
    /** Roadmap §46 — the Next.js storefront and admin in development. */
    public const DEVELOPMENT_DEFAULTS = [
        'http://localhost:3000',
        'http://localhost:3001',
    ];

    /** @var list<string> */
    private array $origins;

    /** @param list<string> $origins */
    public function __construct(array $origins)
    {
        $normalized = [];

        foreach ($origins as $origin) {
            $clean = self::normalize($origin);

            if ($clean !== null && !in_array($clean, $normalized, true)) {
                $normalized[] = $clean;
            }
        }

        $this->origins = $normalized;
    }

    /**
     * Build from a comma-separated environment value.
     *
     * An unset or empty value falls back to the development origins. In
     * production the variable must be set explicitly — see the plugin README.
     */
    public static function fromList(?string $list): self
    {
        if ($list === null || trim($list) === '') {
            return new self(self::DEVELOPMENT_DEFAULTS);
        }

        return new self(array_map('trim', explode(',', $list)));
    }

    /**
     * Reduce an origin to `scheme://host[:port]`, or reject it.
     *
     * Anything that is not a bare http/https origin is rejected rather than
     * coerced — including `*` and `null`, which are the two values that would
     * turn this into an open API.
     */
    public static function normalize(string $origin): ?string
    {
        $origin = strtolower(trim($origin));
        $origin = rtrim($origin, '/');

        if ($origin === '' || $origin === '*' || $origin === 'null') {
            return null;
        }

        $parts = parse_url($origin);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (!in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        // An origin carries no path, query, fragment or credentials.
        foreach (['path', 'query', 'fragment', 'user', 'pass'] as $unwanted) {
            if (isset($parts[$unwanted]) && $parts[$unwanted] !== '') {
                return null;
            }
        }

        if (str_contains($parts['host'], '*')) {
            return null;
        }

        $normalized = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }

        return $normalized;
    }

    public function allows(string $origin): bool
    {
        $normalized = self::normalize($origin);

        return $normalized !== null && in_array($normalized, $this->origins, true);
    }

    /** @return list<string> */
    public function origins(): array
    {
        return $this->origins;
    }

    public function isEmpty(): bool
    {
        return $this->origins === [];
    }
}
