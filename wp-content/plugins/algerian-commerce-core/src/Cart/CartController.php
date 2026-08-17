<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Cart endpoints — roadmap §59b, docs/PLAN.md §53.
 *
 * **Public, and this is the second place in the plugin that says
 * `__return_true`** — see `publicCart()` for the justification the README
 * requires. A cart exists before a shopper has any identity to check, which is
 * the whole reason the token is signed.
 *
 * The line key rather than the product id addresses a line, because the same
 * product can legitimately sit in a cart twice — a t-shirt in two sizes is two
 * lines with one parent — and `WC_Cart` keys on a hash of the whole line. Its
 * shape is fixed at 32 hex characters, which the route pattern enforces so a
 * malformed key is a 404 from the router rather than a lookup.
 */
final class CartController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CartService $service
    ) {
        parent::__construct($logger);
    }

    /**
     * The justification, as the house rules require for every `__return_true`.
     *
     * A cart contains nothing belonging to the shop or to any other customer:
     * it is the caller's own basket, and the caller is the person holding the
     * signed token that opens it. There is no capability that could gate it —
     * a shopper has no WordPress account at this point in the flow, and §44
     * forbids giving one an Application Password. Requiring authentication
     * would mean the Next.js server proxying every quantity change with an
     * admin credential, which is the arrangement §44 exists to prevent.
     *
     * What protects a cart is therefore the token, not a capability: it is
     * signed with the site salt, expires in 48 hours, and a forged one opens an
     * empty cart rather than somebody else's (`CartSession`). Rate limiting
     * still applies — `RateLimitGuard` is registered across the whole
     * namespace.
     *
     * **When §59c gives shoppers accounts this does not become owner-scoped**,
     * because it already is: the token *is* the owner. What changes then is
     * that a logged-in shopper's cart follows their account, which is
     * WooCommerce's own behaviour once `WC()->session` sees a user id.
     */
    private function publicCart(): callable
    {
        return '__return_true';
    }

    public function registerRoutes(): void
    {
        $public = $this->publicCart();

        register_rest_route($this->restNamespace(), '/cart', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'read']),
                'permission_callback' => $public,
                'args' => $this->tokenArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'clear']),
                'permission_callback' => $public,
                'args' => $this->tokenArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cart/items', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'addItem']),
            'permission_callback' => $public,
            'args' => $this->tokenArg() + $this->lineArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/cart/items/(?P<key>[a-f0-9]{32})', [
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateItem']),
                'permission_callback' => $public,
                'args' => $this->tokenArg() + $this->keyArg() + [
                    'quantity' => [
                        'type' => 'integer',
                        'required' => true,
                        'minimum' => 0,
                        'maximum' => CartService::MAX_QUANTITY,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'absint',
                        'description' => 'Zero removes the line.',
                    ],
                ],
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'removeItem']),
                'permission_callback' => $public,
                'args' => $this->tokenArg() + $this->keyArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cart/coupons', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'applyCoupon']),
            'permission_callback' => $public,
            'args' => $this->tokenArg() + $this->codeArg(true),
        ]);

        register_rest_route($this->restNamespace(), '/cart/coupons/(?P<code>[A-Za-z0-9_-]{1,64})', [
            'methods' => 'DELETE',
            'callback' => $this->handle([$this, 'removeCoupon']),
            'permission_callback' => $public,
            'args' => $this->tokenArg() + $this->codeArg(false),
        ]);
    }

    public function read(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($this->service->get($request));
    }

    public function addItem(WP_REST_Request $request): WP_REST_Response
    {
        $line = LineInput::fromArray($this->bodyFields($request, ['product_id', 'variation_id', 'quantity']));

        return $this->respond($this->service->addItem($request, $line->toArray()), 201);
    }

    public function updateItem(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($this->service->setQuantity(
            $request,
            (string) $request->get_param('key'),
            (int) $request->get_param('quantity')
        ));
    }

    public function removeItem(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($this->service->removeItem($request, (string) $request->get_param('key')));
    }

    public function clear(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($this->service->clear($request));
    }

    public function applyCoupon(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(
            $this->service->applyCoupon($request, (string) $request->get_param('code')),
            201
        );
    }

    public function removeCoupon(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($this->service->removeCoupon($request, (string) $request->get_param('code')));
    }

    /**
     * The cart, with its token in `meta`.
     *
     * **In the body rather than in a response header**, which is a deliberate
     * departure from Store API's `Cart-Token:` header. A header is only visible
     * to a test that can read one, and `rest_do_request()` cannot — that is the
     * same blindness that put §64's download headers in `scripts/test-api.sh`.
     * A token in `meta` is assertable from both stages, and a JSON client has
     * it in the object it already parsed. The header is still *accepted* on the
     * way in, so a storefront can send whichever it finds convenient.
     *
     * @param array{cart: array<string, mixed>, token: string, problems?: list<string>} $result
     */
    private function respond(array $result, int $status = 200): WP_REST_Response
    {
        $meta = [];

        // Absent rather than empty when there is nothing to carry: an empty
        // cart has no token, and a client that stores `""` would send it back.
        if ($result['token'] !== '') {
            $meta['cart_token'] = $result['token'];
        }

        /*
         * Roadmap §83. A line whose stored options no longer price keeps its
         * catalogue price and says so here, rather than quietly costing the
         * shop the surcharge. `CheckoutService` refuses to place an order
         * while this list is non-empty, so the storefront's job is to show it
         * and let the shopper choose again.
         */
        if (($result['problems'] ?? []) !== []) {
            $meta['problems'] = $result['problems'];
        }

        return Response::success($result['cart'], $status, $meta);
    }

    /**
     * Read the write fields out of the request body.
     *
     * `LineInput` does the validating, so this only collects what was sent —
     * including keys the route schema does not declare, because "unknown field"
     * is an error `LineInput` must be able to raise. Reading through
     * `get_json_params()` rather than `get_param()` is what makes that
     * possible: `get_param()` cannot distinguish a field that was absent from
     * one the schema silently dropped.
     *
     * @param list<string> $known
     * @return array<string, mixed>
     */
    private function bodyFields(WP_REST_Request $request, array $known): array
    {
        $body = $request->get_json_params();

        if (!is_array($body)) {
            $body = [];

            foreach ($known as $field) {
                if ($request->get_param($field) !== null) {
                    $body[$field] = $request->get_param($field);
                }
            }
        }

        return $body;
    }

    /**
     * The token is a header first and a parameter second, and it is declared
     * here so it survives `rest_do_request()`, which parses no headers of its
     * own in the in-process suites.
     *
     * @return array<string, array<string, mixed>>
     */
    private function tokenArg(): array
    {
        return [
            CartSession::PARAM => [
                'type' => 'string',
                'required' => false,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'The cart token. The ' . CartSession::HEADER . ' header takes precedence.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function keyArg(): array
    {
        return [
            'key' => [
                'type' => 'string',
                'required' => true,
                'pattern' => '^[a-f0-9]{32}$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function codeArg(bool $inBody): array
    {
        return [
            'code' => [
                'type' => 'string',
                'required' => true,
                'pattern' => '^[A-Za-z0-9_-]{1,64}$',
                'validate_callback' => 'rest_validate_request_arg',
                // Coupon codes are lower-cased by WooCommerce on save, so the
                // comparison has to be too or a shopper typing SUMMER10 into a
                // form gets "not valid for this cart" about a code that is.
                'sanitize_callback' => static fn (mixed $v): string => wc_format_coupon_code(
                    sanitize_text_field((string) $v)
                ),
                'description' => $inBody ? 'The coupon code to apply.' : 'The coupon code to remove.',
            ],
        ];
    }

    /**
     * Declared so the schema documents them; `LineInput` is what enforces the
     * rules, because it can say *why* a field is refused.
     *
     * @return array<string, array<string, mixed>>
     */
    private function lineArgs(): array
    {
        return [
            'product_id' => [
                'type' => 'integer',
                'required' => true,
                'minimum' => 1,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'variation_id' => [
                'type' => 'integer',
                'required' => false,
                'minimum' => 0,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'quantity' => [
                'type' => 'integer',
                'required' => false,
                'default' => 1,
                'minimum' => 1,
                'maximum' => CartService::MAX_QUANTITY,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];
    }
}
