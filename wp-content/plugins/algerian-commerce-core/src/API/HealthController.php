<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Core\Migrations\MigrationRunner;
use WP_REST_Request;
use WP_REST_Response;

use const AlgerianCommerce\DB_VERSION;
use const AlgerianCommerce\VERSION;

/**
 * GET /health — liveness probe for the whole stack.
 *
 * Deliberately public: a health check that requires a credential cannot be
 * used by a load balancer or by scripts/health.sh. It therefore reports only
 * ok/error strings — no versions, paths, credentials, or query output
 * (docs/PLAN.md §42, "Do not expose sensitive diagnostics").
 */
final class HealthController extends AbstractController
{
    public function registerRoutes(): void
    {
        register_rest_route($this->restNamespace(), '/health', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'show']),
            // Public by design — see the class docblock.
            'permission_callback' => '__return_true',
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $checks = [
            'wordpress' => $this->checkWordPress(),
            'database' => $this->checkDatabase(),
            'woocommerce' => $this->checkWooCommerce(),
            'plugin' => $this->checkPlugin(),
            'schema' => $this->checkSchema(),
        ];

        $healthy = !in_array('error', $checks, true);
        $status = $healthy ? 'ok' : 'degraded';

        return Response::success(
            ['checks' => $checks],
            $healthy ? 200 : 503,
            [],
            ['status' => $status]
        );
    }

    private function checkWordPress(): string
    {
        return function_exists('get_bloginfo') ? 'ok' : 'error';
    }

    private function checkDatabase(): string
    {
        global $wpdb;

        if (!isset($wpdb)) {
            return 'error';
        }

        $result = $wpdb->get_var('SELECT 1');

        return $result === '1' || $result === 1 ? 'ok' : 'error';
    }

    private function checkWooCommerce(): string
    {
        return class_exists('WooCommerce') ? 'ok' : 'error';
    }

    private function checkPlugin(): string
    {
        return defined('AC_CORE_PATH') && VERSION !== '' ? 'ok' : 'error';
    }

    /**
     * Reports a schema behind the shipped code — a deploy that updated files
     * without running migrations. Only the ok/error verdict is exposed, not
     * the version numbers.
     */
    private function checkSchema(): string
    {
        return (int) get_option(MigrationRunner::VERSION_OPTION, 0) >= DB_VERSION ? 'ok' : 'error';
    }
}
