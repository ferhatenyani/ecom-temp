<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Core\Logger;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

use const AlgerianCommerce\REST_NAMESPACE;

/**
 * Base class for every controller in the plugin.
 *
 * Controllers parse the request, call exactly one service, and format the
 * result — no business logic, no direct $wpdb or WC_* access
 * (docs/ARCHITECTURE.md §2).
 */
abstract class AbstractController
{
    public function __construct(protected readonly Logger $logger)
    {
    }

    abstract public function registerRoutes(): void;

    protected function restNamespace(): string
    {
        return REST_NAMESPACE;
    }

    /**
     * Uniform pagination args for a list endpoint.
     *
     * **The explicit `validate_callback` is load-bearing.** WordPress runs an
     * arg's validate_callback only when one is registered — see
     * `WP_REST_Request::has_valid_params()`, which skips any arg without it.
     * Schema constraints normally still apply because `sanitize_params()`
     * defaults a *missing* sanitize_callback to `rest_parse_request_arg()`,
     * which validates before sanitizing. Supplying our own sanitize_callback
     * replaces that default and silently takes validation with it: `maximum`
     * becomes advisory and `per_page=100000` reaches the database.
     *
     * Anywhere a `sanitize_callback` sits next to a `minimum`, `maximum`,
     * `enum` or `pattern`, the validate_callback has to be spelled out too.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function paginationArgs(int $defaultPerPage = Response::DEFAULT_PER_PAGE): array
    {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => $defaultPerPage,
                'minimum' => 1,
                'maximum' => Response::MAX_PER_PAGE,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * A required positive integer — a resource id, or a filter on one.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function idArg(string $name = 'id', bool $required = true): array
    {
        return [
            $name => [
                'type' => 'integer',
                'required' => $required,
                'minimum' => 1,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Wrap a handler so every route shares one error contract.
     *
     * An ApiException becomes its declared code and status. Anything else is
     * logged with its real message and returned as a generic internal error —
     * clients never see a stack trace or an implementation detail.
     */
    protected function handle(callable $handler): callable
    {
        return function (WP_REST_Request $request) use ($handler): WP_REST_Response {
            try {
                $this->pinRouteParams($request);

                return $handler($request);
            } catch (ApiException $exception) {
                return Response::fromException($exception);
            } catch (Throwable $throwable) {
                $this->logger->error('Unhandled exception in REST handler', [
                    'route' => $request->get_route(),
                    'method' => $request->get_method(),
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);

                return Response::fromException(ApiException::internal());
            }
        };
    }

    /**
     * Make the URL the authority for every id the route captured.
     *
     * **`WP_REST_Request::get_param()` reads the JSON body before the URL.**
     * `get_parameter_order()` returns `JSON, POST, URL, QUERY, defaults` for a
     * request with a JSON body, so a payload carrying `id` silently outranks
     * the id in the path. Every controller in this plugin reads
     * `get_param('id')`, and measured on 2026-08-17 that meant:
     *
     *  - `PATCH /products/1801` with `{"id": 1802}` edited **product 1802**.
     *    The URL was decorative. Both callers need `ac_manage_products`, so it
     *    is not privilege escalation — but a route whose address can be
     *    overridden by its own body is not a route, and nothing in the audit
     *    trail would show the two disagreed.
     *  - `PATCH /products/{id}/variations/{variation_id}` with the variation's
     *    own read body answered **409 "Only variable products have
     *    variations"**, because the body's `id` is the variation's and the
     *    service loaded it as the parent. That breaks the promise
     *    `docs/API.md` makes in "Things that will bite you": *GET then PATCH
     *    the whole object works*.
     *
     * Found while building §88, whose `/attributes/{id}/terms/{term_id}` has
     * exactly that shape — a sub-resource that emits an `id` of its own.
     *
     * The fix is here rather than at ten call sites because a rule that has to
     * be remembered in every new controller is one the eleventh forgets.
     * `get_url_params()` returns only what the route regex captured, which is
     * exactly the resource's address, and `set_param()` overwrites the key in
     * every slot that already holds it — so after this, `get_param('id')`
     * cannot disagree with the path. Bodies still round-trip: an `id` a
     * presenter emitted is now equal to the URL's and every `*Input` class
     * drops it as read-only, which is what it did before.
     */
    private function pinRouteParams(WP_REST_Request $request): void
    {
        foreach ($request->get_url_params() as $key => $value) {
            $request->set_param($key, $value);
        }
    }
}
