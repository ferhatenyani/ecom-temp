<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Audit\AuditRepository;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /audit-logs — read the audit trail.
 *
 * The first authenticated route in the plugin, and the one that proves the
 * authorization layer works end to end: without ac_view_audit_logs it returns
 * 403, signed out it returns 401.
 *
 * Read-only by design. Audit records are append-only, so there is no POST,
 * PATCH or DELETE here and there never should be.
 */
final class AuditLogController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly AuditRepository $repository
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        register_rest_route($this->restNamespace(), '/audit-logs', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => Permissions::callback(Capabilities::VIEW_AUDIT_LOGS),
            'args' => $this->indexArgs(),
        ]);
    }

    /**
     * Args are validated by WordPress before the callback runs; a bad
     * per_page never reaches the query. ErrorNormalizer turns the resulting
     * rest_invalid_param into our envelope.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexArgs(): array
    {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => Response::DEFAULT_PER_PAGE,
                'minimum' => 1,
                'maximum' => Response::MAX_PER_PAGE,
                'sanitize_callback' => 'absint',
            ],
            'action' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
            'resource_type' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_key',
            ],
            'actor_id' => [
                'type' => 'integer',
                'minimum' => 0,
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $filters = array_filter([
            'action' => (string) $request->get_param('action'),
            'resource_type' => (string) $request->get_param('resource_type'),
            'actor_id' => (int) $request->get_param('actor_id'),
        ]);

        $total = $this->repository->count($filters);
        $items = $this->repository->paginate($filters, $page, $perPage);

        return Response::success($items, 200, Response::paginationMeta($total, $page, $perPage));
    }
}
