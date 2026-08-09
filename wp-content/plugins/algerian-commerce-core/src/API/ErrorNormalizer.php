<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use const AlgerianCommerce\REST_NAMESPACE;

/**
 * Rewrites WordPress-generated REST errors into our envelope.
 *
 * Controller errors already use the envelope, but errors raised by the REST
 * infrastructure itself — no matching route, a failed permission_callback, a
 * schema validation failure — never reach a controller and would otherwise be
 * returned in WordPress's native shape. A client cannot be asked to parse two
 * error formats from one API (docs/PLAN.md §2).
 *
 * Only responses inside algerian-commerce/v1 are touched; wp/v2 and wc/v3 keep
 * their own contracts.
 */
final class ErrorNormalizer
{
    /** WordPress error codes mapped onto ours. */
    private const CODE_MAP = [
        'rest_no_route' => 'not_found',
        'rest_post_invalid_id' => 'not_found',
        'rest_invalid_param' => 'invalid_request',
        'rest_missing_callback_param' => 'invalid_request',
        'rest_forbidden' => 'forbidden',
        'rest_cookie_invalid_nonce' => 'forbidden',
        'rest_not_logged_in' => 'unauthenticated',
    ];

    public function register(): void
    {
        add_filter('rest_post_dispatch', [$this, 'filterResponse'], 10, 3);
    }

    public function filterResponse(
        WP_REST_Response $response,
        WP_REST_Server $server,
        WP_REST_Request $request
    ): WP_REST_Response {
        if (!self::isOwnRoute((string) $request->get_route())) {
            return $response;
        }

        $normalized = self::normalize($response->get_data(), $response->get_status());

        if ($normalized !== null) {
            $response->set_data($normalized);
        }

        return $response;
    }

    public static function isOwnRoute(string $route): bool
    {
        return str_starts_with(ltrim($route, '/'), REST_NAMESPACE);
    }

    /**
     * Convert a WordPress error body into the envelope.
     *
     * Returns null when the payload needs no rewriting — either it is already
     * enveloped or it is a success body.
     *
     * @param mixed $data
     * @return array<string, mixed>|null
     */
    public static function normalize(mixed $data, int $status): ?array
    {
        if ($status < 400 || !is_array($data)) {
            return null;
        }

        // Already ours.
        if (array_key_exists('success', $data)) {
            return null;
        }

        // Not a WP_Error body.
        if (!isset($data['code']) || !is_string($data['code'])) {
            return null;
        }

        $message = isset($data['message']) && is_string($data['message'])
            ? $data['message']
            : 'The request could not be completed.';

        $details = [];
        if (isset($data['data']['params']) && is_array($data['data']['params'])) {
            $details['params'] = $data['data']['params'];
        }

        return Response::errorPayload(self::mapCode($data['code']), $message, $details);
    }

    public static function mapCode(string $wordpressCode): string
    {
        return self::CODE_MAP[$wordpressCode] ?? $wordpressCode;
    }
}
