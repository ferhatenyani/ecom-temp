<?php

declare(strict_types=1);

namespace AlgerianCommerce\Coupons;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Coupon endpoints — docs/PLAN.md §21, roadmap step 33.
 *
 * Administrative throughout: every route carries `ac_manage_coupons`. A shopper
 * never reaches these — applying a coupon is `POST /cart/coupons` (§59b), which
 * needs no capability because it validates the code against the cart rather
 * than disclosing what coupons exist.
 *
 * **That split is the security property worth naming.** A public endpoint that
 * listed coupons would hand every visitor the shop's whole discount schedule,
 * including codes meant for one customer's apology. Applying a code you already
 * know is a different act from finding out which codes exist.
 */
final class CouponController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CouponService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_COUPONS);

        register_rest_route($this->restNamespace(), '/coupons', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
                'args' => $this->listArgs(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'create']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/coupons/(?P<id>\d+)', [
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
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroy']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + [
                    'force' => [
                        'type' => 'boolean',
                        'default' => false,
                        'validate_callback' => 'rest_validate_request_arg',
                        'description' => 'Permanently delete instead of trashing.',
                    ],
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'status' => (string) $request->get_param('status'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            array_map([CouponPresenter::class, 'toArray'], $result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CouponPresenter::toArray($this->service->get((int) $request->get_param('id')))
        );
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CouponPresenter::toArray($this->service->create($this->body($request))),
            201
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(CouponPresenter::toArray(
            $this->service->update((int) $request->get_param('id'), $this->body($request))
        ));
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $this->service->delete((int) $request->get_param('id'), (bool) $request->get_param('force'));

        return Response::success(['deleted' => true]);
    }

    /**
     * The write body, read whole.
     *
     * No `args` schema is declared for POST and PATCH on purpose: `CouponInput`
     * has to be able to answer "unknown field", and a declared schema silently
     * drops anything it does not know — which would turn a typo into a field
     * that vanished rather than an error. The same reason
     * `Products\ProductController` reads its body this way.
     *
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function listArgs(): array
    {
        return $this->paginationArgs() + [
            'search' => [
                'type' => 'string',
                'default' => '',
                'maxLength' => 200,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'type' => 'string',
                'default' => '',
                'enum' => array_merge([''], CouponInput::STATUSES),
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => CouponRepository::ORDERBY,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}
