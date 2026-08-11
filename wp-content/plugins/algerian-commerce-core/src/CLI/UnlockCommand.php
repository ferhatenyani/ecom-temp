<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Security\RateLimiter;
use AlgerianCommerce\Security\RateLimitStore;
use WP_CLI;

/**
 * wp algerian-commerce unlock <ip>
 *
 * Clears the authentication lockout for an address.
 *
 * Exists because the counter keys are hashed: without this the only way back
 * in is to guess a transient name or wait out the window, which is a poor
 * position to be in when the address locked out is your own application
 * server.
 */
final class UnlockCommand
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly RateLimitStore $store
    ) {
    }

    /**
     * Clear the authentication lockout for an IP address.
     *
     * ## OPTIONS
     *
     * <ip>
     * : The address to unlock, as WordPress sees it (REMOTE_ADDR).
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce unlock 203.0.113.7
     *
     * @param list<string> $args
     */
    public function __invoke(array $args): void
    {
        $ip = trim($args[0] ?? '');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            WP_CLI::error("\"{$ip}\" is not a valid IP address.");
        }

        $now = time();
        $before = $this->limiter->authFailuresFor($ip, $now);

        if ($this->limiter->isTrusted($ip)) {
            WP_CLI::log('This address is in AC_RATE_LIMIT_TRUSTED_IPS and is never locked out.');
        }

        $this->store->reset($this->limiter->authFailureLimit()->keyFor($ip, $now));

        WP_CLI::success(sprintf(
            'Cleared %d recorded failure(s) for %s (backend: %s).',
            $before,
            $ip,
            $this->store->backend()
        ));
    }
}
