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

        /*
         * The two picker sources — roadmap step 33, added when the admin panel
         * tried to build a restriction picker and could not.
         *
         * A coupon's restrictions are ids. Turning them into a chooser needs a list
         * of products and a list of categories, and both of those live behind
         * `ac_manage_products` — which **Marketing Manager does not hold**, though
         * it holds `ac_manage_coupons`. The result was a coupon form that worked
         * for Admin and Manager and showed a 403 to the role whose job coupons are.
         *
         * These are not `/products` behind a second capability. They carry id, name
         * and SKU and nothing else: no price, no stock, no cost, no attributes, no
         * facets, no write. That is the smallest thing a picker can be built from,
         * and it is strictly less than `/products` discloses — which matters,
         * because widening `ac_manage_products` to cover reads would have handed
         * this role the catalogue in order to give it a label.
         *
         * Registered before `/coupons/(?P<id>\d+)` for readability only; `\d+`
         * cannot match `eligible-products`, so no ordering here is load-bearing.
         */
        register_rest_route($this->restNamespace(), '/coupons/eligible-products', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'eligibleProducts']),
            'permission_callback' => $guard,
            'args' => $this->pickerArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/coupons/eligible-categories', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'eligibleCategories']),
            'permission_callback' => $guard,
            'args' => $this->pickerArgs(),
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
        return Response::success($this->detail($this->service->get((int) $request->get_param('id'))));
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->detail($this->service->create($this->body($request))), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->detail(
            $this->service->update((int) $request->get_param('id'), $this->body($request))
        ));
    }

    /**
     * Products a coupon may be restricted to — id, name, SKU, status.
     *
     * `search` matches the name or the SKU, because a shop looks a product up by
     * whichever it has to hand, and `include` resolves a known set of ids in one
     * request rather than one request per id.
     */
    public function eligibleProducts(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->eligibleProducts([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'include' => self::idList($request->get_param('include')),
        ]);

        return Response::success(
            $result['items'],
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    /** Categories a coupon may be restricted to — id, name, slug, product count. */
    public function eligibleCategories(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->eligibleCategories([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'include' => self::idList($request->get_param('include')),
        ]);

        return Response::success(
            $result['items'],
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    /**
     * A coupon with its restrictions resolved.
     *
     * Every route that returns *one* coupon returns the names too, including the
     * write routes: a form that saves and then re-renders from the response would
     * otherwise lose every label it was showing a moment earlier.
     *
     * @return array<string, mixed>
     */
    private function detail(\WC_Coupon $coupon): array
    {
        return CouponPresenter::toArray($coupon, CouponRestrictions::resolve($coupon));
    }

    /**
     * `?include=12,16` — a comma list, the form every other list parameter in this
     * API takes. Non-numeric entries are dropped rather than refused: `include` is
     * a narrowing convenience, and a client that sends a stray empty segment wants
     * the ids it did send, not a 400.
     *
     * @return list<int>
     */
    private static function idList(mixed $raw): array
    {
        if (!is_scalar($raw) || (string) $raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', (string) $raw) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
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

    /**
     * Shared by both picker routes. `include` is a string rather than an array so
     * `?include=12,16` works from a query string without PHP's bracket syntax.
     *
     * @return array<string, array<string, mixed>>
     */
    private function pickerArgs(): array
    {
        return $this->paginationArgs(Response::MAX_PER_PAGE) + [
            'search' => [
                'type' => 'string',
                'default' => '',
                'maxLength' => 200,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include' => [
                'type' => 'string',
                'default' => '',
                'pattern' => '^$|^[0-9]+(,[0-9]+)*$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
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
