<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CMS endpoints — roadmap §61.
 *
 *   GET /cms/homepage
 *   GET /cms/pages/{slug}
 *   GET /cms/banners
 *   GET /cms/faqs
 *   GET /cms/menus/{location}
 *
 * **Authenticated, like every other read in this plugin except `/locations`
 * and `/health`.** Content is not secret, but the storefront already reaches
 * `/products` through the Next.js server holding the credential
 * (docs/ARCHITECTURE.md §8), and a second, public path into the same backend
 * would be a second thing to rate limit, cache and reason about. Guarding it
 * costs nothing the products endpoint does not already cost.
 */
final class CmsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CmsService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_CONTENT);

        register_rest_route($this->restNamespace(), '/cms/homepage', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'homepage']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/cms/banners', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'banners']),
            'permission_callback' => $guard,
            'args' => $this->paginationArgs() + $this->searchArg() + [
                'placement' => [
                    'type' => 'string',
                    // A free key rather than an enum: where a shop puts a
                    // banner is a shop's decision, and this plugin is cloned
                    // per client.
                    'pattern' => '^[a-z0-9_-]{1,32}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/faqs', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'faqs']),
            'permission_callback' => $guard,
            'args' => $this->paginationArgs() + $this->searchArg() + [
                'category' => [
                    'type' => 'string',
                    'pattern' => '^[a-z0-9_-]{1,64}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        /*
         * The slug pattern allows a nested path, because `get_page_by_path()`
         * resolves one and a page tree is how legal pages are usually filed:
         * `legal/terms` must reach the child, not 404 on the slash.
         */
        register_rest_route($this->restNamespace(), '/cms/pages/(?P<slug>[a-zA-Z0-9\-_/]+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'page']),
            'permission_callback' => $guard,
            'args' => [
                'slug' => [
                    'type' => 'string',
                    'required' => true,
                    'pattern' => '^[a-zA-Z0-9\-_]+(/[a-zA-Z0-9\-_]+)*$',
                    'validate_callback' => 'rest_validate_request_arg',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/menus/(?P<location>[a-z0-9_-]+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'menu']),
            'permission_callback' => $guard,
            'args' => [
                'location' => [
                    'type' => 'string',
                    'required' => true,
                    'pattern' => '^[a-z0-9_-]{1,64}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function searchArg(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    public function homepage(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->homepage();

        // Problems ride in meta rather than being silently dropped: a section
        // that vanished without a word is the failure a content manager cannot
        // diagnose. Absent when there are none.
        $meta = $result['problems'] === [] ? [] : ['problems' => $result['problems']];

        return Response::success($result['data'], 200, $meta);
    }

    public function page(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::page($this->service->page((string) $request->get_param('slug')))
        );
    }

    public function banners(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->banners([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'placement' => (string) $request->get_param('placement'),
        ]);

        return Response::success(
            CmsPresenter::banners($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function faqs(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->faqs([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'category' => (string) $request->get_param('category'),
        ]);

        return Response::success(
            CmsPresenter::faqs($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function menu(WP_REST_Request $request): WP_REST_Response
    {
        $location = (string) $request->get_param('location');
        $menu = $this->service->menu($location);

        return Response::success(CmsPresenter::menu($location, $menu['menu'], $menu['items']));
    }
}
