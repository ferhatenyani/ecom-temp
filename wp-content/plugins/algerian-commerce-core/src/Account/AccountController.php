<?php

declare(strict_types=1);

namespace AlgerianCommerce\Account;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Orders\OrderStatus;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shopper account endpoints — roadmap §59c, docs/PLAN.md §53.
 *
 * **Every route here is `__return_true`, and that is not the same as
 * unguarded.** A capability cannot express "the person holding this session",
 * which is the only thing that identifies a shopper; the check is
 * `AccountSession::require()` inside the service, one layer down, and it throws
 * 401 when the session is missing or invalid. That is the arrangement
 * docs/SECURITY.md → "Authorization" describes as the second layer, used here
 * as the only one because there is no capability to put in the first.
 *
 * Which means the route table cannot tell you these are protected, so
 * `tests/Api/account.php` asserts it instead: every route below is called with
 * no session, with a forged session, and with another shopper's session.
 *
 * **There is no `customer_id` anywhere in this file.** `/account/orders` cannot
 * be asked for somebody else's orders because no parameter exists that could
 * name them — roadmap §59c's first rule, enforced by the shape of the API
 * rather than by a check that could be forgotten.
 */
final class AccountController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly AccountService $service
    ) {
        parent::__construct($logger);
    }

    /**
     * See the class docblock: the session check is in the service, because a
     * `permission_callback` cannot see a token this API defines itself.
     */
    private function sessionChecked(): callable
    {
        return '__return_true';
    }

    public function registerRoutes(): void
    {
        $guard = $this->sessionChecked();

        register_rest_route($this->restNamespace(), '/account/register', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'register']),
            'permission_callback' => $guard,
            'args' => $this->credentialArgs() + $this->nameArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/account/login', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'login']),
            'permission_callback' => $guard,
            'args' => $this->credentialArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/account/logout', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'logout']),
            'permission_callback' => $guard,
            'args' => $this->tokenArg(),
        ]);

        register_rest_route($this->restNamespace(), '/account', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'profile']),
                'permission_callback' => $guard,
                'args' => $this->tokenArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateProfile']),
                'permission_callback' => $guard,
                'args' => $this->tokenArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/account/password', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'changePassword']),
            'permission_callback' => $guard,
            'args' => $this->tokenArg(),
        ]);

        register_rest_route($this->restNamespace(), '/account/orders', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'orders']),
            'permission_callback' => $guard,
            'args' => $this->tokenArg() + $this->paginationArgs(10) + [
                'status' => [
                    'type' => 'string',
                    'required' => false,
                    'default' => '',
                    'enum' => array_merge([''], OrderStatus::ALL),
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/account/orders/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'order']),
            'permission_callback' => $guard,
            'args' => $this->tokenArg() + $this->idArg(),
        ]);
    }

    public function register(WP_REST_Request $request): WP_REST_Response
    {
        $input = AccountInput::forRegistration($this->body($request));

        return Response::success($this->service->register($request, [
            'email' => $input->email,
            'password' => $input->password,
            'first_name' => $input->firstName,
            'last_name' => $input->lastName,
        ]), 201);
    }

    public function login(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->login(
            $request,
            (string) $request->get_param('email'),
            (string) $request->get_param('password')
        ));
    }

    public function logout(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->logout($request));
    }

    public function profile(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->profile($request));
    }

    public function updateProfile(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $this->body($request);

        unset($payload[AccountSession::PARAM]);

        return Response::success($this->service->updateProfile($request, $payload));
    }

    /**
     * The payload is handed over unvalidated on purpose: `AccountService`
     * requires the session *before* it validates, so an anonymous caller gets
     * 401 rather than a 400 describing the fields. Authentication first.
     */
    public function changePassword(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->changePassword($request, $this->body($request)));
    }

    public function orders(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->orders($request, [
            'page' => (int) $request->get_param('page'),
            'per_page' => (int) $request->get_param('per_page'),
            'status' => (string) $request->get_param('status'),
        ]);

        return Response::success(
            $result['items'],
            200,
            Response::paginationMeta(
                $result['total'],
                (int) $request->get_param('page'),
                (int) $request->get_param('per_page')
            )
        );
    }

    public function order(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->service->order($request, (int) $request->get_param('id')));
    }

    /**
     * The JSON body, or an empty array.
     *
     * Read whole rather than field by field so `AccountInput` can refuse an
     * unknown or forbidden key — `get_param()` cannot tell a field that was
     * absent from one the schema dropped.
     *
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function tokenArg(): array
    {
        return [
            AccountSession::PARAM => [
                'type' => 'string',
                'required' => false,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'The session token. The ' . AccountSession::HEADER . ' header takes precedence.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function credentialArgs(): array
    {
        return [
            'email' => [
                'type' => 'string',
                'required' => true,
                'format' => 'email',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_email',
            ],
            'password' => [
                'type' => 'string',
                'required' => true,
                'minLength' => 1,
                'maxLength' => AccountInput::MAX_PASSWORD,
                'validate_callback' => 'rest_validate_request_arg',
                // Deliberately no sanitize_callback: a password is bytes, and
                // sanitize_text_field() would silently strip characters from a
                // passphrase, making it un-typeable ever after.
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function nameArgs(): array
    {
        return [
            'first_name' => [
                'type' => 'string',
                'required' => false,
                'default' => '',
                'maxLength' => 100,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'last_name' => [
                'type' => 'string',
                'required' => false,
                'default' => '',
                'maxLength' => 100,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}
