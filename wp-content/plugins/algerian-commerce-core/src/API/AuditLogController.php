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
            /*
             * A string, not an integer: the column is varchar(64) because the
             * things this trail records are not all numbered. A page is
             * audited by path, a FAQ category by slug, a menu by location.
             * `absint` would turn `conditions` into 0 and match every row that
             * has no resource id at all.
             *
             * This is how somebody gets from an audited object to its history,
             * and `AuditRepository::buildWhere()` has had the clause since the
             * table existed — the route simply never declared the argument, so
             * `?resource_id=` was accepted and silently ignored, which is §65's
             * failure mode: a filter that does not filter looks exactly like a
             * collection that all matches.
             */
            'resource_id' => [
                'type' => 'string',
                'maxLength' => 64,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_from' => $this->dateArg(),
            'date_to' => $this->dateArg(),
        ] + $this->idArg('actor_id', false);
    }

    /**
     * Y-m-d, UTC, both ends covering the whole day — the same contract
     * `/notifications` and `/orders` publish, and for the same reason: every
     * writer into this table stamps `gmdate('Y-m-d H:i:s')`, so a bound
     * interpreted in the shop's timezone would silently shift a day.
     *
     * @return array<string, mixed>
     */
    private function dateArg(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => 'Y-m-d, UTC. Both ends cover the whole day.',
        ];
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        // array_filter drops the empty strings and the zero, which is what
        // buildWhere() means by "not asked for". `resource_id` is included:
        // "0" is not an id anything here records, and a literal zero would be
        // indistinguishable from the default anyway.
        $filters = array_filter([
            'action' => (string) $request->get_param('action'),
            'resource_type' => (string) $request->get_param('resource_type'),
            'resource_id' => (string) $request->get_param('resource_id'),
            'actor_id' => (int) $request->get_param('actor_id'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ]);

        $total = $this->repository->count($filters);
        $items = $this->repository->paginate($filters, $page, $perPage);

        return Response::success($items, 200, Response::paginationMeta($total, $page, $perPage));
    }
}
