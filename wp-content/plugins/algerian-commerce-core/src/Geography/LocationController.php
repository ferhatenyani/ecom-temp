<?php

declare(strict_types=1);

namespace AlgerianCommerce\Geography;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Location endpoints — docs/PLAN.md §10.
 *
 * **Public, and every route says so in one place.** These serve Algeria's
 * administrative divisions: the same wilaya and commune lists every delivery
 * site in the country shows on its address form. `__return_true` is used here
 * with the justification the README asks for — see `publicRead()` below and
 * GeoService for the reasoning.
 *
 * Read-only. The dataset changes through `wp algerian-commerce import-algeria`,
 * a deployment step rather than a request, so there is no write surface to
 * guard in the first place.
 */
final class LocationController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly GeoService $service
    ) {
        parent::__construct($logger);
    }

    /**
     * The one place this plugin returns true from a permission_callback.
     *
     * Justification, as required: the payload is public reference data with no
     * customer, order or shop information in it, and it is needed by an
     * unauthenticated storefront to render an address form. Guarding it would
     * force the Next.js server to proxy every commune autocomplete keystroke to
     * fetch a list that is on Wikipedia. Rate limiting still applies — the
     * guard is registered across the whole namespace.
     */
    private function publicRead(): callable
    {
        return '__return_true';
    }

    public function registerRoutes(): void
    {
        $public = $this->publicRead();

        register_rest_route($this->restNamespace(), '/locations/wilayas', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'wilayas']),
            'permission_callback' => $public,
            'args' => $this->searchArgs(),
        ]);

        // Before the {id} route, so "coverage" is never matched as an id.
        register_rest_route($this->restNamespace(), '/locations/coverage', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'coverage']),
            'permission_callback' => $public,
        ]);

        register_rest_route($this->restNamespace(), '/locations/wilayas/(?P<id>\d+)/communes', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'communes']),
            'permission_callback' => $public,
            'args' => $this->idArg() + $this->searchArgs() + [
                'postal_code' => [
                    'type' => 'string',
                    'pattern' => '^\d{1,10}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/locations/wilayas/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'wilaya']),
            'permission_callback' => $public,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/locations/communes/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'commune']),
            'permission_callback' => $public,
            'args' => $this->idArg(),
        ]);
    }

    /**
     * No pagination.
     *
     * There are 58 wilayas, and the largest wilaya has a few dozen communes —
     * both are a single small response, and an address form needs the whole
     * list to populate a select. Paginating would make every client page
     * through data that fits in one payload.
     *
     * @return array<string, array<string, mixed>>
     */
    private function searchArgs(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'active_only' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'Exclude places switched off for delivery.',
            ],
        ];
    }

    public function wilayas(WP_REST_Request $request): WP_REST_Response
    {
        $wilayas = $this->service->wilayas([
            'search' => (string) $request->get_param('search'),
            'active_only' => (bool) $request->get_param('active_only'),
        ]);

        return Response::success($wilayas, 200, ['total' => count($wilayas)]);
    }

    public function wilaya(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->wilaya((int) $request->get_param('id')));
    }

    public function communes(WP_REST_Request $request): WP_REST_Response
    {
        $communes = $this->service->communesOf((int) $request->get_param('id'), [
            'search' => (string) $request->get_param('search'),
            'postal_code' => (string) $request->get_param('postal_code'),
            'active_only' => (bool) $request->get_param('active_only'),
        ]);

        return Response::success($communes, 200, ['total' => count($communes)]);
    }

    public function commune(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->commune((int) $request->get_param('id')));
    }

    public function coverage(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->coverage());
    }
}
