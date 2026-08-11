<?php

declare(strict_types=1);

namespace AlgerianCommerce\Customers;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Orders\OrderPresenter;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Customer endpoints — roadmap §50.
 *
 * There is no POST and no DELETE. Accounts are created by registration or
 * checkout and removed through WordPress's own user tools, both of which carry
 * consequences — password mail, content reassignment, GDPR erasure — that
 * belong to `ac_manage_users`, not to customer management.
 */
final class CustomerController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CustomerService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_CUSTOMERS);

        register_rest_route($this->restNamespace(), '/customers', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => $guard,
            'args' => $this->indexArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/customers/(?P<id>\d+)/orders', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'orders']),
            'permission_callback' => $guard,
            'args' => $this->idArg() + $this->paginationArgs() + [
                'status' => [
                    'type' => 'string',
                    'enum' => OrderStatus::ALL,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                'orderby' => [
                    'type' => 'string',
                    'default' => 'date',
                    'enum' => OrderRepository::ORDERBY,
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                'order' => [
                    'type' => 'string',
                    'default' => 'desc',
                    'enum' => ['asc', 'desc'],
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/customers/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'show']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'update']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function indexArgs(): array
    {
        return $this->paginationArgs() + [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'registered',
                'enum' => CustomerRepository::ORDERBY,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            CustomerPresenter::toArrayList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    /**
     * The single-customer read carries the statistics; the list does not.
     * Computing them per row would mean a query per customer on a page of
     * twenty, and a list is read to find someone, not to study them.
     */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        return Response::success(CustomerPresenter::toArray(
            $this->service->get($id),
            $this->service->statistics($id)
        ));
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        return Response::success(CustomerPresenter::toArray(
            $this->service->update((int) $request->get_param('id'), is_array($body) ? $body : [])
        ));
    }

    public function orders(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->orders((int) $request->get_param('id'), [
            'page' => $page,
            'per_page' => $perPage,
            'search' => '',
            'status' => (string) $request->get_param('status'),
            'date_from' => '',
            'date_to' => '',
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            OrderPresenter::toArrayList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }
}
