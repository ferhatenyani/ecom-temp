<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Marketing endpoints — roadmap §62b.
 *
 *   GET  /marketing/config              public ids only, for the storefront
 *   POST /marketing/events/purchase     → { event_id, event_name, ... }
 *
 * Two routes, and the second one is the deduplication contract: the backend
 * mints the `event_id` and the storefront is told what it is, so the browser can
 * fire `fbq('track', 'Purchase', {...}, {eventID})` with the same value the
 * server sends. Meta discards the duplicate only when both halves agree, and two
 * systems cannot each invent the same string.
 *
 * Behind `ac_manage_marketing`, like every other private route here: the
 * storefront reaches this through its Next.js server, which already holds a
 * credential for `/products`. The *ids* being public does not make the route
 * public — a second, unauthenticated path into this backend would be another
 * thing to rate limit and reason about.
 */
final class MarketingController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly MarketingService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_MARKETING);

        register_rest_route($this->restNamespace(), '/marketing/config', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'config']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/marketing/events/purchase', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'purchase']),
            'permission_callback' => $guard,
            'args' => $this->idArg('order_id') + [
                /*
                 * The browser's own identifiers. Only the storefront can read
                 * them, they are the strongest match signal Meta has, and none
                 * of them is hashed — see UserData::PLAIN_KEYS.
                 */
                'fbc' => [
                    'type' => 'string',
                    'pattern' => '^fb\.[0-2]\.\d+\..+$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'fbp' => [
                    'type' => 'string',
                    'pattern' => '^fb\.[0-2]\.\d+\.\d+$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_ip_address' => [
                    'type' => 'string',
                    'format' => 'ip',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'client_user_agent' => [
                    'type' => 'string',
                    'maxLength' => 500,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'event_source_url' => [
                    'type' => 'string',
                    'format' => 'uri',
                    'maxLength' => 500,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);
    }

    public function config(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->config());
    }

    /**
     * Queue the Purchase and hand back its id.
     *
     * **200, not 201.** The interesting result is the `event_id`, which is the
     * same on every call for a given order — a second request has created
     * nothing, and answering 201 would say it had. It answers with the id
     * either way, because the second browser tab still has to render the pixel
     * with that value.
     */
    public function purchase(WP_REST_Request $request): WP_REST_Response
    {
        /*
         * The forwarded client address comes from the storefront's server,
         * which is the only thing that saw the browser — REMOTE_ADDR here is
         * the Next.js server itself. Unlike the rate limiter, which must not
         * trust a client-supplied address because it decides access, this only
         * decides ad attribution quality: an operator who lies to it degrades
         * their own match rate.
         */
        $context = [];

        foreach (['fbc', 'fbp', 'client_ip_address', 'client_user_agent', 'event_source_url'] as $key) {
            $value = (string) $request->get_param($key);

            if ($value !== '') {
                $context[$key] = $value;
            }
        }

        return Response::success(
            $this->service->recordPurchase((int) $request->get_param('order_id'), $context)
        );
    }
}
