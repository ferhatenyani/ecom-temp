<?php

declare(strict_types=1);

namespace AlgerianCommerce\Cart;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Commerce\AddressInput;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Shipping\Destination;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Checkout endpoints — roadmap §59b.
 *
 * Public for the same reason `CartController` is: a shopper has no account at
 * this point in the flow and §44 forbids giving one an Application Password.
 * What identifies a checkout is the cart token it carries.
 *
 * Two routes, and the split is deliberate. A storefront needs to show delivery
 * cost **before** asking for payment, so quoting is a separate, side-effect-free
 * GET that can be called on every wilaya change. `POST /checkout` is the one
 * that creates something.
 */
final class CheckoutController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CheckoutService $service
    ) {
        parent::__construct($logger);
    }

    /** See CartController::publicCart() for the justification; it is the same one. */
    private function publicCheckout(): callable
    {
        return '__return_true';
    }

    public function registerRoutes(): void
    {
        $public = $this->publicCheckout();

        register_rest_route($this->restNamespace(), '/checkout/shipping-rates', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'shippingRates']),
            'permission_callback' => $public,
            'args' => $this->tokenArg() + $this->destinationArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/checkout', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'place']),
            'permission_callback' => $public,
            'args' => $this->tokenArg() + $this->destinationArgs() + [
                'payment_method' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                    'pattern' => '^[a-z0-9_-]{0,40}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'One of GET /payments/methods. Empty picks the shop default.',
                ],
                /*
                 * Which courier the shopper picked — backend step 2.
                 *
                 * **Declared exactly like `payment_method` above, on purpose.**
                 * The two are the same kind of field: an optional slug naming
                 * one of a small registered set, where empty means "the shop
                 * decides". Same type, same `required`, same `default`, same
                 * pattern, same pair of callbacks — so a storefront that has
                 * learned how one behaves has learned how the other does, and
                 * the two cannot drift into accepting different strings for the
                 * same shape of value.
                 *
                 * The pattern is doing real work and is not decoration:
                 * `sanitize_text_field()` strips tags but happily returns
                 * `Yalidine ` or a kilobyte, and without a `validate_callback`
                 * WordPress silently ignores `pattern` altogether — the trap
                 * `AbstractController::paginationArgs()` documents. Provider
                 * names are lowercase slugs (`manual`, `yalidine`,
                 * `zrexpress`), so the rejection belongs at the schema where
                 * it costs nothing rather than in a service that then has to
                 * describe it.
                 *
                 * **`zrexpress`, not `zr_express`** — this line said the latter
                 * and was wrong. `ZRExpressProvider::NAME` is `'zrexpress'`,
                 * and that constant is the only spelling that matters here,
                 * because it is what `ProviderRegistry::has()` and `get()`
                 * answer to. `zr_express` is a real string in this codebase and
                 * names a *different* thing — the feature flag key
                 * `SettingsService::features()` derives from `ENABLE_ZR_EXPRESS`
                 * — which is exactly how the two came to be confused. The
                 * pattern accepts both shapes either way, so nothing behaved
                 * differently; what was wrong was a reader being told the wrong
                 * name for the courier.
                 *
                 * What the schema deliberately does *not* do is enumerate the
                 * couriers. An `enum` here would have to be built from
                 * `Plugin::shippingProviders()` at `rest_api_init`, which is
                 * before a request exists, and it would publish the registered
                 * set in the route's own OPTIONS schema to an anonymous caller
                 * — the leak `requireShippingQuote()` refuses by hand for the
                 * error message. Membership is a question about *this*
                 * destination anyway, and only the service can answer it.
                 */
                'shipping_provider' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                    'pattern' => '^[a-z0-9_-]{0,40}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'One of the providers GET /checkout/shipping-rates quoted. '
                        . 'Empty takes the cheapest.',
                ],
                'customer_note' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                    'maxLength' => 500,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);
    }

    public function shippingRates(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->shippingRates($request, [
            'wilaya_id' => (int) $request->get_param('wilaya_id'),
            'commune_id' => (int) $request->get_param('commune_id'),
            'delivery_type' => (string) $request->get_param('delivery_type'),
        ]));
    }

    public function place(WP_REST_Request $request): WP_REST_Response
    {
        $errors = [];

        // The same AddressInput both `Orders/` and this use, so a checkout
        // address and an admin-entered address are validated by one set of
        // rules — §51's reasoning about place names included.
        $billing = AddressInput::forBilling($request->get_param('billing'), $errors);

        // **A shipping address is optional and its absence is not an error.**
        // Most Algerian orders are delivered to the address that was typed
        // once; requiring the shopper to enter it twice, or the storefront to
        // duplicate it into the payload, is a form nobody fills correctly.
        // Omitted means "the same as billing", which `CheckoutService` applies.
        // Distinguish absent from malformed: `null` is a choice, `"nonsense"`
        // is a mistake and still has to be reported.
        $rawShipping = $request->get_param('shipping');
        $shipping = $rawShipping === null
            ? AddressInput::forShipping([], $errors)
            : AddressInput::forShipping($rawShipping, $errors);

        if ($billing->isEmpty()) {
            $errors['billing'] = 'A billing address is required.';
        }

        if ($errors !== []) {
            throw \AlgerianCommerce\API\ApiException::invalidRequest(
                'The checkout details are invalid.',
                ['fields' => $errors]
            );
        }

        return Response::success($this->service->place($request, [
            'billing' => $billing->fields,
            'shipping' => $shipping->fields,
            'wilaya_id' => (int) $request->get_param('wilaya_id'),
            'commune_id' => (int) $request->get_param('commune_id'),
            'delivery_type' => (string) $request->get_param('delivery_type'),
            'payment_method' => (string) $request->get_param('payment_method'),
            'shipping_provider' => (string) $request->get_param('shipping_provider'),
            'customer_note' => (string) $request->get_param('customer_note'),
        ]), 201);
    }

    /** @return array<string, array<string, mixed>> */
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

    /**
     * Where it is going — the §51 identifiers, never a free-text place name.
     *
     * `Shipping\ShipmentInput` refuses to fuzzy-match a commune name and §63
     * refuses to guess a wilaya out of an address; a checkout that accepted
     * "Ouled Fayet" here would push that same guess one step earlier, into the
     * one place where getting it wrong sends a parcel to the wrong province.
     * The storefront picks from `GET /locations/*`, which is public for exactly
     * this reason.
     *
     * @return array<string, array<string, mixed>>
     */
    private function destinationArgs(): array
    {
        return [
            'wilaya_id' => [
                'type' => 'integer',
                'required' => true,
                'minimum' => 1,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'commune_id' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'minimum' => 0,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'delivery_type' => [
                'type' => 'string',
                'required' => false,
                'default' => Destination::HOME,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'home or desk.',
            ],
        ];
    }
}
