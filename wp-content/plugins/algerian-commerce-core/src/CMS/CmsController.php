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
 * CMS endpoints — roadmap §61 (reads) and §89 (writes).
 *
 *   GET               /cms/homepage          PUT    /cms/homepage
 *   GET               /cms/pages             POST   /cms/pages
 *   GET               /cms/pages/{path}      PATCH  /cms/pages/{path}
 *                                            DELETE /cms/pages/{path}
 *   GET               /cms/banners           POST   /cms/banners
 *                                            PATCH  /cms/banners/{id}
 *                                            DELETE /cms/banners/{id}
 *   GET               /cms/faqs              POST   /cms/faqs
 *                                            PATCH  /cms/faqs/{id}
 *                                            DELETE /cms/faqs/{id}
 *   GET               /cms/faq-categories    POST   /cms/faq-categories
 *                                            PATCH  /cms/faq-categories/{id}
 *                                            DELETE /cms/faq-categories/{id}
 *   GET               /cms/menus/{location}  PUT    /cms/menus/{location}
 *
 * **Authenticated, like every other read in this plugin except `/locations`
 * and `/health`.** Content is not secret, but the storefront already reaches
 * `/products` through the Next.js server holding the credential
 * (docs/ARCHITECTURE.md §8), and a second, public path into the same backend
 * would be a second thing to rate limit, cache and reason about. Guarding it
 * costs nothing the products endpoint does not already cost.
 *
 * ## The page route captures `path`, and that was forced
 *
 * §61 called it `slug` while every route here was read-only. §88 added
 * `AbstractController::pinRouteParams()`, which makes the URL the authority for
 * every captured param — right for an address, and wrong for anything a body
 * may legitimately rewrite. A route capturing `slug` beside a `PATCH` body
 * carrying `slug` would answer **200 to a rename having renamed nothing**, and
 * `tests/Api/security.php` fails the build on any write route addressed by a
 * non-id name that is not listed there with its reason. So the capture is
 * `path` — which is what it always held, `legal/terms` and not `terms` — and
 * the body renames with `slug` and moves with `parent_path`.
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
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'homepage']),
                'permission_callback' => $guard,
            ],
            [
                'methods' => 'PUT',
                'callback' => $this->handle([$this, 'updateHomepage']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/banners', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'banners']),
                'permission_callback' => $guard,
                'args' => $this->paginationArgs() + $this->searchArg() + $this->statusArg() + [
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
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storeBanner']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/banners/(?P<id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateBanner']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroyBanner']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->forceArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/faqs', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'faqs']),
                'permission_callback' => $guard,
                'args' => $this->paginationArgs() + $this->searchArg() + $this->statusArg() + [
                    'category' => [
                        'type' => 'string',
                        'pattern' => '^[a-z0-9_-]{1,64}$',
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'sanitize_title',
                    ],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storeFaq']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/faqs/(?P<id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateFaq']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroyFaq']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->forceArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/faq-categories', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'faqCategories']),
                'permission_callback' => $guard,
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storeFaqCategory']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/faq-categories/(?P<id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateFaqCategory']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroyFaqCategory']),
                'permission_callback' => $guard,
                'args' => $this->idArg() + $this->forceArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/pages', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'pages']),
                'permission_callback' => $guard,
                'args' => $this->paginationArgs() + $this->searchArg() + $this->statusArg(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'storePage']),
                'permission_callback' => $guard,
            ],
        ]);

        /*
         * The path pattern allows a nested path, because `get_page_by_path()`
         * resolves one and a page tree is how legal pages are usually filed:
         * `legal/terms` must reach the child, not 404 on the slash.
         */
        register_rest_route($this->restNamespace(), '/cms/pages/(?P<path>[a-zA-Z0-9\-_/]+)', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'page']),
                'permission_callback' => $guard,
                'args' => $this->pathArg() + $this->statusArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updatePage']),
                'permission_callback' => $guard,
                'args' => $this->pathArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroyPage']),
                'permission_callback' => $guard,
                'args' => $this->pathArg() + $this->forceArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/cms/menus/(?P<location>[a-z0-9_-]+)', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'menu']),
                'permission_callback' => $guard,
                'args' => $this->locationArg(),
            ],
            [
                'methods' => 'PUT',
                'callback' => $this->handle([$this, 'updateMenu']),
                'permission_callback' => $guard,
                'args' => $this->locationArg(),
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

    /**
     * Which statuses a read asks for.
     *
     * `publish` is the default, so §61's contract and every existing caller are
     * unchanged. `any` is deliberately publish **plus draft** and not
     * WordPress's `any`, which includes the trash: `DELETE` is what puts
     * something there and a deleted page must not come back through a filter.
     *
     * @return array<string, array<string, mixed>>
     */
    private function statusArg(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'default' => 'publish',
                'enum' => ['publish', 'draft', 'any'],
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function pathArg(): array
    {
        return [
            'path' => [
                'type' => 'string',
                'required' => true,
                'pattern' => '^[a-zA-Z0-9\-_]+(/[a-zA-Z0-9\-_]+)*$',
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function locationArg(): array
    {
        return [
            'location' => [
                'type' => 'string',
                'required' => true,
                'pattern' => '^[a-z0-9_-]{1,64}$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }

    /**
     * `force` matches the products endpoints: without it a DELETE trashes, with
     * it the row is gone. A page is where the trash earns its keep — a legal
     * page removed by mistake is one a storefront still links to.
     *
     * @return array<string, array<string, mixed>>
     */
    private function forceArg(): array
    {
        return ['force' => ['type' => 'boolean', 'default' => false]];
    }

    /** @return list<string> */
    private function statuses(WP_REST_Request $request): array
    {
        return match ((string) $request->get_param('status')) {
            'draft' => ['draft'],
            'any' => ['publish', 'draft'],
            default => ['publish'],
        };
    }

    /** @return array<string, mixed> */
    private function body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }

    public function homepage(WP_REST_Request $request): WP_REST_Response
    {
        return $this->homepageResponse($this->service->homepage());
    }

    public function updateHomepage(WP_REST_Request $request): WP_REST_Response
    {
        return $this->homepageResponse($this->service->updateHomepage($this->body($request)));
    }

    /**
     * @param array{data: array<string, mixed>, problems: list<string>} $result
     */
    private function homepageResponse(array $result): WP_REST_Response
    {
        // Problems ride in meta rather than being silently dropped: a section
        // that vanished without a word is the failure a content manager cannot
        // diagnose. Absent when there are none — and after a PUT there can be
        // none, because the write refused what the read would have dropped.
        $meta = $result['problems'] === [] ? [] : ['problems' => $result['problems']];

        return Response::success($result['data'], 200, $meta);
    }

    public function pages(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->pages([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'statuses' => $this->statuses($request),
        ]);

        /*
         * `excluded_system` rather than a silent omission. The index leaves out
         * the pages whose body the shop generates — see `SystemPages` — and a
         * count that is quietly four short of what wp-admin shows is a count
         * somebody will eventually file a bug about. Reporting the number makes
         * the difference explicable without publishing the rows themselves.
         */
        return Response::success(
            CmsPresenter::pages($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
                + ['excluded_system' => $result['excluded']]
        );
    }

    public function page(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::page($this->service->page(
                (string) $request->get_param('path'),
                $this->statuses($request)
            ))
        );
    }

    public function storePage(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::page($this->service->createPage($this->body($request))),
            201
        );
    }

    public function updatePage(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->updatePage((string) $request->get_param('path'), $this->body($request));

        /*
         * Reported in `meta` rather than in the resource: it describes what this
         * request did, not what the page is. A moved or renamed page keeps every
         * link a storefront built on the old path pointing at a 404, and
         * WordPress leaves no redirect behind — §88's `slug_changed`, one
         * resource over.
         */
        return Response::success(
            CmsPresenter::page($result['page']),
            200,
            $result['path_changed'] ? ['path_changed' => true, 'path' => $result['path']] : []
        );
    }

    public function destroyPage(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->deletePage(
            (string) $request->get_param('path'),
            (bool) $request->get_param('force')
        );

        return Response::success([
            'id' => $result['id'],
            'path' => $result['path'],
            'deleted' => true,
            'trashed' => !(bool) $request->get_param('force'),
        ]);
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
            'statuses' => $this->statuses($request),
        ]);

        return Response::success(
            CmsPresenter::banners($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function storeBanner(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::banner($this->service->createBanner($this->body($request))),
            201
        );
    }

    public function updateBanner(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::banner($this->service->updateBanner(
                (int) $request->get_param('id'),
                $this->body($request)
            ))
        );
    }

    public function destroyBanner(WP_REST_Request $request): WP_REST_Response
    {
        $force = (bool) $request->get_param('force');

        return Response::success([
            'id' => $this->service->deleteBanner((int) $request->get_param('id'), $force),
            'deleted' => true,
            'trashed' => !$force,
        ]);
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
            'statuses' => $this->statuses($request),
        ]);

        return Response::success(
            CmsPresenter::faqs($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function storeFaq(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::faq($this->service->createFaq($this->body($request))),
            201
        );
    }

    public function updateFaq(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::faq($this->service->updateFaq(
                (int) $request->get_param('id'),
                $this->body($request)
            ))
        );
    }

    public function destroyFaq(WP_REST_Request $request): WP_REST_Response
    {
        $force = (bool) $request->get_param('force');

        return Response::success([
            'id' => $this->service->deleteFaq((int) $request->get_param('id'), $force),
            'deleted' => true,
            'trashed' => !$force,
        ]);
    }

    /**
     * Unpaginated, for `GET /attributes`'s reason: a shop has a handful of FAQ
     * categories and a client building a filter needs all of them to render one
     * screen. `meta.total` is still reported so the shape matches every other
     * list.
     */
    public function faqCategories(WP_REST_Request $request): WP_REST_Response
    {
        $terms = $this->service->faqCategories();

        return Response::success(CmsPresenter::faqCategories($terms), 200, ['total' => count($terms)]);
    }

    public function storeFaqCategory(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            CmsPresenter::faqCategory($this->service->createFaqCategory($this->body($request))),
            201
        );
    }

    public function updateFaqCategory(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->updateFaqCategory((int) $request->get_param('id'), $this->body($request));

        return Response::success(
            CmsPresenter::faqCategory($result['term']),
            200,
            $result['slug_changed'] ? ['slug_changed' => true] : []
        );
    }

    public function destroyFaqCategory(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->deleteFaqCategory(
            (int) $request->get_param('id'),
            (bool) $request->get_param('force')
        );

        return Response::success([
            'id' => $result['id'],
            'deleted' => true,
            'faqs_detached' => $result['faqs_detached'],
        ]);
    }

    public function menu(WP_REST_Request $request): WP_REST_Response
    {
        $location = (string) $request->get_param('location');
        $menu = $this->service->menu($location);

        return Response::success(CmsPresenter::menu($location, $menu['menu'], $menu['items']));
    }

    public function updateMenu(WP_REST_Request $request): WP_REST_Response
    {
        $location = (string) $request->get_param('location');
        $menu = $this->service->updateMenu($location, $this->body($request));

        return Response::success(CmsPresenter::menu($location, $menu['menu'], $menu['items']));
    }
}
