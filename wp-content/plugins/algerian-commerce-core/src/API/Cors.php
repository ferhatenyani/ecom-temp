<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Core\Logger;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Enforces the CORS allowlist on algerian-commerce/v1.
 *
 * WordPress core's `rest_send_cors_headers()` reflects **whatever** Origin
 * the request carried, together with `Access-Control-Allow-Credentials:
 * true`, and never consults `allowed_http_origins` — that filter only governs
 * `is_allowed_http_origin()`, which the REST CORS path does not call. Core
 * gets away with it because its own endpoints require a nonce or an
 * Authorization header that a foreign page cannot obtain.
 *
 * That is not good enough here: roadmap §46 and docs/SECURITY.md require an
 * environment-specific allowlist on the private API. So core's handler is
 * removed and replaced with one that emits headers only for allowed origins
 * on our routes, while leaving wp/v2, wc/v3 and everything else on core's
 * default behaviour.
 */
final class Cors
{
    private const METHODS = 'OPTIONS, GET, POST, PUT, PATCH, DELETE';

    public function __construct(
        private readonly OriginPolicy $policy,
        private readonly Logger $logger
    ) {
    }

    public function register(): void
    {
        /*
         * Core registers its handler in rest_api_default_filters() on
         * rest_api_init at priority 10, so the swap has to happen after that.
         */
        add_action('rest_api_init', [$this, 'takeOverCorsHeaders'], 15);
    }

    public function takeOverCorsHeaders(): void
    {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', [$this, 'sendHeaders'], 10, 4);
    }

    /**
     * @param mixed $served
     * @param mixed $result
     * @return mixed the $served value, untouched — this only sets headers
     */
    public function sendHeaders(
        mixed $served,
        mixed $result = null,
        ?WP_REST_Request $request = null,
        ?WP_REST_Server $server = null
    ): mixed {
        $route = $request instanceof WP_REST_Request ? (string) $request->get_route() : '';

        // Anything outside our namespace keeps WordPress's default behaviour.
        if (!ErrorNormalizer::isOwnRoute($route)) {
            if (function_exists('rest_send_cors_headers')) {
                return rest_send_cors_headers($served, $result, $request, $server);
            }

            return $served;
        }

        $origin = (string) get_http_origin();

        /*
         * Always vary on Origin, including when refusing: without it a shared
         * cache can hand an allowed origin's CORS-approved response to a
         * different origin.
         */
        header('Vary: Origin', false);

        if ($origin === '') {
            // Same-origin or a non-browser client. No CORS headers needed.
            return $served;
        }

        if (!$this->policy->allows($origin)) {
            /*
             * Send nothing. Omitting the header is what makes the browser
             * block the response; there is no "deny" header to send. Logged
             * because a blocked call leaves no other server-side trace and is
             * miserable to debug from the Next.js side.
             */
            $this->logger->warning('CORS origin rejected', [
                'origin' => $origin,
                'route' => $route,
                'allowed' => $this->policy->origins(),
            ]);

            return $served;
        }

        /*
         * Only these three. Access-Control-Expose-Headers and
         * Access-Control-Allow-Headers are already sent unconditionally by
         * WP_REST_Server::serve_request(), and they are inert without an
         * Allow-Origin — the browser discards the whole response.
         */
        header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
        header('Access-Control-Allow-Methods: ' . self::METHODS);
        header('Access-Control-Allow-Credentials: true');

        return $served;
    }
}
