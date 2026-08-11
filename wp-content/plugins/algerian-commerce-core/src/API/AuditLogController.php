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
        return $this->paginationArgs() + [
            /*
             * Not sanitize_key(): it strips periods, and every action this
             * plugin records is dotted — `product.created`, `inventory.adjusted`.
             * Filtering by one therefore matched nothing at all, because the
             * filter value arrived at the query as `productcreated`. The
             * pattern keeps the value narrow without eating the separator.
             */
            'action' => [
                'type' => 'string',
                'pattern' => '^[a-z0-9._-]+$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'resource_type' => [
                'type' => 'string',
                'pattern' => '^[a-z0-9._-]+$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ] + $this->idArg('actor_id', false);
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
