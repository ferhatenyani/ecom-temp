<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use WP_REST_Response;

/**
 * The single response envelope for algerian-commerce/v1.
 *
 * Payload builders are pure arrays so they can be unit-tested without loading
 * WordPress; the WP_REST_Response wrappers are thin adapters over them. No
 * controller should ever assemble an envelope by hand.
 *
 * Success: { "success": true, "data": {} }
 * Error:   { "success": false, "error": { "code", "message", "details" } }
 */
final class Response
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta Pagination and similar envelope metadata.
     * @param array<string, mixed> $root Extra root-level keys required by a
     *                                   specific contract (the health endpoint
     *                                   returns a root-level "status").
     * @return array<string, mixed>
     */
    public static function successPayload(mixed $data = [], array $meta = [], array $root = []): array
    {
        $payload = ['success' => true];

        foreach ($root as $key => $value) {
            $payload[$key] = $value;
        }

        $payload['data'] = $data === null ? [] : $data;

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public static function errorPayload(string $code, string $message, array $details = []): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return [
            'success' => false,
            'error' => $error,
        ];
    }

    /**
     * Uniform pagination metadata for every list endpoint.
     *
     * @return array<string, int>
     */
    public static function paginationMeta(int $total, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $total = max(0, $total);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page = max(1, $page);

        return [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @param mixed                $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $root
     */
    public static function success(mixed $data = [], int $status = 200, array $meta = [], array $root = []): WP_REST_Response
    {
        return new WP_REST_Response(self::successPayload($data, $meta, $root), $status);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $message, int $status = 400, array $details = []): WP_REST_Response
    {
        return new WP_REST_Response(self::errorPayload($code, $message, $details), $status);
    }

    public static function fromException(ApiException $exception): WP_REST_Response
    {
        return new WP_REST_Response($exception->toPayload(), $exception->statusCode());
    }
}
