<?php

declare(strict_types=1);

namespace AlgerianCommerce\Users;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Staff account endpoints — roadmap §87.
 *
 * Every route here is `ac_manage_users`, which is Super Admin's alone. An Admin
 * holding the other eleven management capabilities is refused, and that refusal
 * is the boundary that stops an Admin escalating — the same shape as §71's
 * settings endpoint.
 */
final class UserController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly UserService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_USERS);

        register_rest_route($this->restNamespace(), '/roles', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'roles']),
            'permission_callback' => $guard,
        ]);

        register_rest_route($this->restNamespace(), '/users', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
                'args' => $this->indexArgs(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'store']),
                'permission_callback' => $guard,
            ],
        ]);

        /*
         * Registered before `/users/{id}`, matching the convention every other
         * controller follows. WordPress anchors each route pattern, so the
         * order does not decide the match — it decides how the file reads.
         */
        register_rest_route($this->restNamespace(), '/users/(?P<id>\d+)/application-passwords', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'applicationPasswords']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'createApplicationPassword']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);

        /*
         * The uuid pattern is WordPress's own `wp_generate_uuid4()` shape.
         * Constraining it in the route means a malformed identifier is a 404
         * from the router rather than a lookup, which is one fewer place a
         * caller can learn whether an account exists.
         */
        register_rest_route(
            $this->restNamespace(),
            '/users/(?P<id>\d+)/application-passwords/(?P<uuid>[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})',
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'deleteApplicationPassword']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ]
        );

        register_rest_route($this->restNamespace(), '/users/(?P<id>\d+)', [
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
                'args' => $this->idArg(),
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function indexArgs(): array
    {
        return $this->paginationArgs() + [
            'search' => [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'role' => [
                'type' => 'string',
                'enum' => UserRoles::staff(),
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'status' => [
                'type' => 'string',
                'enum' => UserStatus::ALL,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'registered',
                'enum' => UserRepository::ORDERBY,
                'validate_callback' => 'rest_validate_request_arg',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
                'validate_callback' => 'rest_validate_request_arg',
            ],
        ];
    }

    public function roles(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->roles());
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'role' => (string) $request->get_param('role'),
            'status' => (string) $request->get_param('status'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            UserPresenter::toArrayList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    /**
     * The single read carries the account's application passwords; the list
     * does not — reading them per row is a meta read a page of twenty does not
     * need, which is `CustomerController`'s statistics decision again.
     */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        return Response::success(UserPresenter::toArray(
            $this->service->get($id),
            ApplicationPasswordPresenter::toArrayList($this->service->applicationPasswords($id))
        ));
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        return Response::success(
            UserPresenter::toArray($this->service->create(is_array($body) ? $body : [])),
            201
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();

        $result = $this->service->update(
            (int) $request->get_param('id'),
            is_array($body) ? $body : []
        );

        /*
         * A customer that has just become staff is reported in `meta` rather
         * than in the resource: it describes what this request did, not what
         * the account is, and it would round-trip back into a PATCH body if it
         * lived in `data`.
         */
        return Response::success(
            UserPresenter::toArray($result['user']),
            200,
            $result['promoted'] ? ['promoted_from_customer' => true] : []
        );
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $this->service->delete($id);

        return Response::success(['id' => $id, 'deleted' => true]);
    }

    public function applicationPasswords(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(ApplicationPasswordPresenter::toArrayList(
            $this->service->applicationPasswords((int) $request->get_param('id'))
        ));
    }

    /**
     * **The one response in this API that carries a usable credential.**
     *
     * `password` appears here and nowhere else — not on the collection, not in
     * the audit event, not in a log. A client shows it once and does not store
     * it; if it is lost, the answer is to revoke and mint another, which is
     * cheap and is why the name identifies a device.
     */
    public function createApplicationPassword(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $name = is_array($body) && isset($body['name']) && is_scalar($body['name'])
            ? trim((string) $body['name'])
            : '';

        if ($name === '') {
            throw ApiException::invalidRequest('The application password data is invalid', [
                'fields' => ['name' => 'Required. Name the device or client this credential is for.'],
            ]);
        }

        $created = $this->service->createApplicationPassword((int) $request->get_param('id'), $name);

        return Response::success(
            ApplicationPasswordPresenter::toArray($created['item']) + ['password' => $created['password']],
            201
        );
    }

    public function deleteApplicationPassword(WP_REST_Request $request): WP_REST_Response
    {
        $uuid = (string) $request->get_param('uuid');

        $this->service->deleteApplicationPassword((int) $request->get_param('id'), $uuid);

        return Response::success(['uuid' => $uuid, 'revoked' => true]);
    }
}
