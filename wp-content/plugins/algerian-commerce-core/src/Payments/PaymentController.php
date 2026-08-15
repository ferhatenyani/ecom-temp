<?php

declare(strict_types=1);

namespace AlgerianCommerce\Payments;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Payment endpoints — roadmap §59, docs/PLAN.md §18–§19.
 *
 * Every route carries `ac_manage_payments`, the read ones included. A
 * transaction row names an order, a sum and a gateway reference; Support Agent —
 * the least-privileged staff role — is deliberately not given them, on the same
 * reasoning that keeps tracking numbers behind `ac_manage_shipping`.
 *
 * Privileged calls go browser → Next.js server → here (CLAUDE.md), so the
 * storefront asking "which methods can I offer" is a server-side call like any
 * other. None of these routes is public, and the webhook — which is — lives in
 * `PaymentWebhookController` where its one deliberate exception can be argued in
 * one place.
 *
 * There is no DELETE. A payment attempt is a record of something that happened
 * to somebody's money, and the only thing that ever removes one is a retention
 * policy nobody has written yet.
 */
final class PaymentController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly PaymentService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_PAYMENTS);

        register_rest_route($this->restNamespace(), '/payments/methods', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'methods']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/orders/(?P<id>\d+)/payments', [
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

        /*
         * POST, not GET, and it is not a REST purity slip: this asks the gateway
         * a question over the network and writes down the answer, which may
         * settle an order and reduce stock. A GET that changes things is one
         * browser prefetch away from doing it by accident.
         */
        register_rest_route($this->restNamespace(), '/payments/(?P<id>\d+)/verify', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'verify']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/payments/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'show']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/payments', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => $guard,
            'args' => $this->indexArgs(),
        ]);
    }

    public function methods(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->availableMethods());
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->service->createPayment((int) $request->get_param('id'), $this->payload($request))->toArray(),
            201
        );
    }

    public function forOrder(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(self::present($this->service->forOrder((int) $request->get_param('id'))));
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->find((int) $request->get_param('id'))->toArray());
    }

    /**
     * The server-side confirmation.
     *
     * Returns both the gateway's report and the transaction as it now stands,
     * because the two can legitimately differ: a report may be refused by
     * `PaymentStatus::accepts()` — a late `pending` against a settled payment —
     * and a caller shown only the report would believe a change happened that
     * did not.
     */
    public function verify(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $report = $this->service->verify($id);

        return Response::success([
            'report' => $report->toArray(),
            'transaction' => $this->service->find($id)->toArray(),
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->paginate([
            'order_id' => (int) $request->get_param('order_id'),
            'provider' => (string) $request->get_param('provider'),
            'status' => (string) $request->get_param('status'),
            'provider_transaction_id' => (string) $request->get_param('provider_transaction_id'),
            'reference' => (string) $request->get_param('reference'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ], $page, $perPage);

        return Response::success(
            self::present($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
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
                'enum' => PaymentStatus::ALL,
                'validate_callback' => 'rest_validate_request_arg',
                // No sanitize_callback: the enum is already the tighter check,
                // and sanitize_key() would not survive a future status with a
                // character it strips.
            ],
            'provider_transaction_id' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'reference' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
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

    /**
     * @param list<Transaction> $transactions
     * @return list<array<string, mixed>>
     */
    private static function present(array $transactions): array
    {
        return array_values(array_map(static fn (Transaction $t): array => $t->toArray(), $transactions));
    }

    /**
     * The JSON body only. Route and query parameters are not payment fields,
     * and folding them in would let `?provider=…` masquerade as one.
     *
     * @return array<string, mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }
}
