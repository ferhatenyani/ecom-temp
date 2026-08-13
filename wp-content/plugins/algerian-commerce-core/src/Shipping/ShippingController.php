<?php

declare(strict_types=1);

namespace AlgerianCommerce\Shipping;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shipping endpoints — roadmap §53.
 *
 * Every route carries `ac_manage_shipping`, including the read ones: a tracking
 * number and a customer's destination are delivery data, and Support Agent —
 * the least-privileged staff role — is deliberately not given them.
 *
 * There is no DELETE. A shipment is cancelled at the provider and kept, because
 * it is the record of a parcel that existed and, quite possibly, of a van that
 * drove somewhere.
 */
final class ShippingController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly ShippingService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_SHIPPING);

        register_rest_route($this->restNamespace(), '/shipping/providers', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'providers']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/shipping/rates', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'rates']),
            'permission_callback' => $guard,
            'args' => $this->rateArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/shipping/rules', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'indexRules']),
                'permission_callback' => $guard,
                'args' => $this->ruleFilterArgs(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storeRule']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/shipping/rules/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'showRule']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateRule']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroyRule']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/shipments', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'forOrder']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'store']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/shipments/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'cancel']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/shipments/(?P<id>\d+)/sync', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'sync']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/shipments/(?P<id>\d+)', [
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

        register_rest_route($this->restNamespace(), '/shipments', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => $guard,
            'args' => $this->indexArgs(),
        ]);
    }

    /**
     * Every arg with a sanitize_callback also declares a validate_callback —
     * see AbstractController::paginationArgs() for why leaving it out silently
     * disables `enum`, `minimum` and `pattern`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexArgs(): array
    {
        return $this->paginationArgs() + $this->idArg('order_id', false) + [
            'provider' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ShipmentStatus::ALL,
                'validate_callback' => 'rest_validate_request_arg',
                // No sanitize_callback: sanitize_key() would not survive a
                // future status with a character it strips, and the enum is
                // already the tighter check.
            ],
            'tracking_number' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_from' => $this->dateArg(),
            'date_to' => $this->dateArg(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function rateArgs(): array
    {
        return $this->idArg('wilaya_id') + $this->idArg('commune_id') + [
            'provider' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'delivery_type' => [
                'type' => 'string',
                'default' => Destination::HOME,
                'enum' => Destination::DELIVERY_TYPES,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'subtotal' => [
                'type' => 'string',
                // A decimal string, like every other amount this API carries.
                // Without it no free-shipping threshold is applied, because
                // "what does delivery here cost" and "what does delivering
                // this basket here cost" are different questions.
                'pattern' => '^\d+(\.\d{1,4})?$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'Goods total, for free-shipping thresholds.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function ruleFilterArgs(): array
    {
        return $this->idArg('wilaya_id', false) + $this->idArg('commune_id', false) + [
            'provider' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'delivery_type' => [
                'type' => 'string',
                'enum' => Destination::DELIVERY_TYPES,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'is_active' => [
                'type' => 'boolean',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dateArg(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => 'Y-m-d. Both ends cover the whole day.',
        ];
    }

    public function providers(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->availableProviders());
    }

    public function rates(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->rates([
            'provider' => (string) $request->get_param('provider'),
            'wilaya_id' => (int) $request->get_param('wilaya_id'),
            'commune_id' => (int) $request->get_param('commune_id'),
            'delivery_type' => (string) $request->get_param('delivery_type'),
            'subtotal' => (string) $request->get_param('subtotal'),
        ]));
    }

    public function indexRules(WP_REST_Request $request): WP_REST_Response
    {
        $active = $request->get_param('is_active');

        return Response::success(array_values(array_map(
            static fn (ShippingRule $rule): array => $rule->toArray(),
            $this->service->listRules([
                'wilaya_id' => (int) $request->get_param('wilaya_id'),
                'commune_id' => (int) $request->get_param('commune_id'),
                'provider' => (string) $request->get_param('provider'),
                'delivery_type' => (string) $request->get_param('delivery_type'),
                'is_active' => $active === null ? null : (bool) $active,
            ])
        )));
    }

    public function showRule(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->getRule((int) $request->get_param('id'))->toArray());
    }

    public function storeRule(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->createRule($this->payload($request))->toArray(), 201);
    }

    public function updateRule(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->updateRule((int) $request->get_param('id'), $this->payload($request))->toArray()
        );
    }

    public function destroyRule(WP_REST_Request $request): WP_REST_Response
    {
        $this->service->deleteRule((int) $request->get_param('id'));

        return Response::success(['deleted' => true, 'id' => (int) $request->get_param('id')]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list([
            'order_id' => (int) $request->get_param('order_id'),
            'provider' => (string) $request->get_param('provider'),
            'status' => (string) $request->get_param('status'),
            'tracking_number' => (string) $request->get_param('tracking_number'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ], $page, $perPage);

        return Response::success(
            self::present($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->get((int) $request->get_param('id'))->toArray());
    }

    public function forOrder(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(self::present($this->service->forOrder((int) $request->get_param('id'))));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->create((int) $request->get_param('id'), $this->payload($request))->toArray(),
            201
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->updateStatus((int) $request->get_param('id'), $this->payload($request))->toArray()
        );
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->cancel((int) $request->get_param('id'))->toArray());
    }

    public function sync(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->sync((int) $request->get_param('id'))->toArray());
    }

    /**
     * @param list<Shipment> $shipments
     * @return list<array<string, mixed>>
     */
    private static function present(array $shipments): array
    {
        return array_values(array_map(static fn (Shipment $s): array => $s->toArray(), $shipments));
    }

    /**
     * The JSON body only. Route and query parameters are not shipment fields,
     * and folding them in would let `?status=delivered` masquerade as one.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
