<?php

declare(strict_types=1);

namespace AlgerianCommerce\Settings;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET / PATCH /settings — roadmap §71, docs/PLAN.md §48.
 *
 * Two routes, both `ac_manage_settings`, which until now was a capability with
 * no call site.
 *
 * **Deliberately not public, and the temptation to make it so is worth naming.**
 * A storefront footer wants the shop's name, logo, contact details and trade
 * register, and it is one line to serve them without a credential. But the same
 * document reports which providers registered and which feature flags are on,
 * which is a map of the shop's integrations for anyone who asks. The storefront
 * already reaches this API through its own server with a credential (§44), so
 * the public door would buy nothing and disclose the shop's configuration to
 * anybody who found the URL.
 */
final class SettingsController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly SettingsService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_SETTINGS);

        register_rest_route($this->restNamespace(), '/settings', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'show']),
                'permission_callback' => $guard,
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'update']),
                'permission_callback' => $guard,
            ],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        return Response::success($this->service->document());
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        /*
         * The body is validated by `SettingsInput` rather than by registered
         * args. Registered args would have to restate the schema in a second
         * place, and `rest_validate_request_arg` cannot express "unknown block,
         * here are the known ones" or "refused, and here is why".
         */
        $payload = $request->get_json_params();

        return Response::success($this->service->update(is_array($payload) ? $payload : []));
    }
}
