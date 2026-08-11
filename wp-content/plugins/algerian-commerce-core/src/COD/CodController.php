<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Cash-on-delivery endpoints — roadmap §52.
 *
 * The per-order routes hang off `/orders/{id}` because that is where they
 * belong in a client's mental model, not because `Orders/` owns them: a COD
 * record is about an order, so this module reads orders and `Orders/` never
 * hears of COD.
 *
 * A confirmation call is recorded by **creating an attempt**, not by calling a
 * `/confirm` verb. There is one state machine and one audit path whatever the
 * customer said, and adding an outcome later — a language barrier, a wrong
 * number — is a value in an enum rather than a fourth endpoint and a fourth set
 * of tests. It is also honest about what is being stored: the record is the
 * call, and its outcome is a property of it.
 *
 * There is no DELETE. An attempt is a record of a phone call that happened.
 */
final class CodController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CodService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $orders = Permissions::callback(Capabilities::MANAGE_ORDERS);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/cod', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'show']),
                'permission_callback' => $orders,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'update']),
                'permission_callback' => $orders,
                'args' => $this->idArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/cod/attempts', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'storeAttempt']),
            'permission_callback' => $orders,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/cod/statistics', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'statistics']),
            // Reading how the shop is performing is a different job from
            // phoning its customers, and the roles that need one frequently do
            // not need the other.
            'permission_callback' => Permissions::callback(Capabilities::VIEW_ANALYTICS),
            'args' => $this->statisticsArgs(),
        ]);
    }

    /**
     * Every arg with a sanitize_callback also declares a validate_callback —
     * see AbstractController::paginationArgs() for why leaving it out silently
     * disables `minimum` and `pattern`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function statisticsArgs(): array
    {
        return $this->idArg('customer_id', false) + [
            'date_from' => $this->dateArg(),
            'date_to' => $this->dateArg(),
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

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->state((int) $request->get_param('id'))->toArray());
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->setEnabled((int) $request->get_param('id'), $this->payload($request))->toArray()
        );
    }

    public function storeAttempt(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->recordAttempt((int) $request->get_param('id'), $this->payload($request))->toArray(),
            201
        );
    }

    public function statistics(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->statistics([
            'customer_id' => (int) $request->get_param('customer_id'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ]));
    }

    /**
     * The JSON body only. Route and query parameters are not COD fields, and
     * folding them in would let `?enabled=true` masquerade as one.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
