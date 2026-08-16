<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Meta;

/**
 * Everything about a Meta dataset that is not a credential — roadmap §62b.
 *
 * An option (`ac_meta_settings`) rather than `.env`, on the line §56 drew for
 * Yalidine and for the same reason: the plugin is cloned per client, and the
 * Graph API version a shop is pinned to, or the test code they are currently
 * debugging with, is configuration a person changes. A bad value falls back to
 * the default and is reported by `problems()`; an option must never be able to
 * fatal the plugin on boot.
 */
final class MetaSettings
{
    /**
     * Pinned, per roadmap §68 — never "latest".
     *
     * v26.0 was released 2026-07-29 and is current as of 2026-08-16. Meta
     * expires a version roughly two years after release and **changes payload
     * requirements between versions**, so an unpinned client would start
     * failing on a date nobody chose. Bump it deliberately, on a branch, and
     * re-run the suite.
     */
    public const DEFAULT_API_VERSION = 'v26.0';

    public const DEFAULT_BASE_URL = 'https://graph.facebook.com/';

    /** Seconds. The queue drains in the background, so this need not be brief. */
    public const DEFAULT_TIMEOUT = 15;

    /**
     * §55's ceiling, as `YalidineSettings::MAX_TIMEOUT` has.
     *
     * This adapter never runs on the checkout path, so a long timeout cannot
     * hold up a customer — but it can hold a cron worker, and a drain that
     * stalls for ten minutes per event stops being a drain.
     */
    public const MAX_TIMEOUT = 60;

    /** Events per request. Meta accepts up to 1,000; batching this side is the queue's job. */
    public const DEFAULT_BATCH = 25;

    /** @param list<string> $problems */
    private function __construct(
        public readonly string $apiVersion,
        public readonly string $baseUrl,
        public readonly int $timeout,
        public readonly string $testEventCode,
        public readonly array $problems
    ) {
    }

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        $problems = [];

        $version = trim((string) ($stored['api_version'] ?? self::DEFAULT_API_VERSION));

        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            if ($version !== '') {
                $problems[] = sprintf('api_version "%s" is not a Graph API version; using %s.', $version, self::DEFAULT_API_VERSION);
            }

            $version = self::DEFAULT_API_VERSION;
        }

        $baseUrl = trim((string) ($stored['base_url'] ?? self::DEFAULT_BASE_URL));

        // https only: this request carries an access token in it.
        if (!str_starts_with(strtolower($baseUrl), 'https://')) {
            if ($baseUrl !== '') {
                $problems[] = 'base_url must be https; using the default.';
            }

            $baseUrl = self::DEFAULT_BASE_URL;
        }

        $timeout = (int) ($stored['timeout'] ?? self::DEFAULT_TIMEOUT);

        if ($timeout < 1) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        if ($timeout > self::MAX_TIMEOUT) {
            $problems[] = sprintf('timeout %d is above the %d second ceiling; clamped.', $timeout, self::MAX_TIMEOUT);
            $timeout = self::MAX_TIMEOUT;
        }

        /*
         * `test_event_code` routes events to the Test Events view instead of
         * the live dataset. It is how §54's "verify against the live API" is
         * satisfied without polluting a client's attribution — and it is a
         * setting rather than a constant precisely so it can be set for an
         * afternoon and removed.
         */
        $testEventCode = trim((string) ($stored['test_event_code'] ?? ''));

        if ($testEventCode !== '' && preg_match('/^TEST\d+$/i', $testEventCode) !== 1) {
            $problems[] = 'test_event_code does not look like "TEST12345"; sending it anyway.';
        }

        return new self($version, rtrim($baseUrl, '/') . '/', $timeout, $testEventCode, $problems);
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /** `https://graph.facebook.com/v26.0/{pixel}/events` */
    public function eventsUrl(string $pixelId): string
    {
        return $this->baseUrl . $this->apiVersion . '/' . rawurlencode($pixelId) . '/events';
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }
}
