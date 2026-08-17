<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Campaign, segment, template and unsubscribe endpoints — roadmap §85.
 *
 * **Every route carries `ac_manage_marketing` except one, and the exception is the
 * point.** `/marketing/unsubscribe` is `__return_true` because §85 requires "one
 * click, no login": a customer holding a link from an email has no session, and
 * requiring an account to unsubscribe is how a shop's domain ends up on a blocklist.
 * What identifies the request is a signed token, checked in
 * `CampaignService::unsubscribe()` — the arrangement `CartController`,
 * `AccountController` and §84's `TrackingController` all use, and the reason
 * `tests/Api/security.php` has to carry an allowlist entry for it.
 *
 * **The second capability is asserted in the service, not on the route.** `send`,
 * the recipient list and a segment count additionally require
 * `ac_manage_customers`, and a `permission_callback` takes one capability — so the
 * route guards the first and `Permissions::assert()` guards the second, one layer
 * down. That is the same two-layer arrangement docs/SECURITY.md describes, and it is
 * why `tests/Api/campaigns.php` asserts the pair directly: a Marketing Manager can
 * draft, preview and test, and cannot send.
 *
 * `GET` on unsubscribe is deliberate and is not a mistake about safe methods. A link
 * in an email is a GET, and the alternative — a page that POSTs — needs a storefront
 * that may not exist. The action is idempotent, which is the property that actually
 * matters: clicking twice is the same as clicking once.
 */
final class CampaignController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly CampaignService $campaigns,
        private readonly SegmentService $segments
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_MARKETING);

        register_rest_route($this->restNamespace(), '/campaigns', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'index']),
                'permission_callback' => $guard,
                'args' => $this->listArgs(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'create']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/campaigns/(?P<id>\d+)', [
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

        register_rest_route($this->restNamespace(), '/campaigns/(?P<id>\d+)/preview', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'preview']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/campaigns/(?P<id>\d+)/test', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'test']),
            'permission_callback' => $guard,
            'args' => $this->idArg() + [
                'to' => [
                    'type' => 'string',
                    'required' => true,
                    'format' => 'email',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ]);

        register_rest_route($this->restNamespace(), '/campaigns/(?P<id>\d+)/send', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'send']),
            // The route guards `ac_manage_marketing`; `CampaignService::send()`
            // asserts `ac_manage_customers` as well. See the class docblock.
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/campaigns/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'cancel']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/campaigns/(?P<id>\d+)/recipients', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'recipients']),
            'permission_callback' => $guard,
            'args' => $this->idArg() + $this->paginationArgs() + [
                'status' => [
                    'type' => 'string',
                    'default' => '',
                    'enum' => ['', RecipientRepository::STATUS_PENDING, RecipientRepository::STATUS_SENT, RecipientRepository::STATUS_FAILED],
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        // ------------------------------------------------------------ segments --

        register_rest_route($this->restNamespace(), '/segments', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'indexSegments']),
                'permission_callback' => $guard,
                'args' => $this->paginationArgs() + [
                    'orderby' => [
                        'type' => 'string',
                        'default' => 'name',
                        'enum' => SegmentRepository::ORDERBY,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'order' => [
                        'type' => 'string',
                        'default' => 'asc',
                        'enum' => ['asc', 'desc'],
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'createSegment']),
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route($this->restNamespace(), '/segments/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'showSegment']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'PATCH',
                'callback' => $this->handle([$this, 'updateSegment']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
            [
                'methods' => 'DELETE',
                'callback' => $this->handle([$this, 'destroySegment']),
                'permission_callback' => $guard,
                'args' => $this->idArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/segments/(?P<id>\d+)/preview', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'previewSegment']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        // ----------------------------------------------------------- templates --
        //
        // Read-only, exactly as §61's CMS is: templates are authored in wp-admin,
        // where revisions and the media library already are, and a write surface is
        // PLAN §52's admin coverage rather than this. `EmailTemplates` sanitises
        // every save whoever made it.

        register_rest_route($this->restNamespace(), '/email-templates', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'indexTemplates']),
            'permission_callback' => $guard,
            'args' => $this->paginationArgs(),
        ]);

        register_rest_route($this->restNamespace(), '/email-templates/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'showTemplate']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        // --------------------------------------------------------- unsubscribe --

        register_rest_route($this->restNamespace(), '/marketing/unsubscribe', [
            [
                'methods' => 'GET',
                'callback' => $this->handle([$this, 'unsubscribe']),
                // One click, no login — see the class docblock. The signed token is
                // the authorization, checked in CampaignService::unsubscribe().
                'permission_callback' => '__return_true',
                'args' => $this->tokenArg(),
            ],
            [
                'methods' => 'POST',
                'callback' => $this->handle([$this, 'unsubscribe']),
                'permission_callback' => '__return_true',
                'args' => $this->tokenArg(),
            ],
        ]);
    }

    // ------------------------------------------------------------- campaigns --

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->campaigns->list([
            'page' => $page,
            'per_page' => $perPage,
            'status' => (string) $request->get_param('status'),
            'search' => (string) $request->get_param('search'),
            'segment_id' => (int) $request->get_param('segment_id'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            array_map(static fn (Campaign $c): array => $c->toArray(), $result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->get((int) $request->get_param('id'))->toArray());
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->create($this->body($request))->toArray(), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->campaigns->update((int) $request->get_param('id'), $this->body($request))->toArray()
        );
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $this->campaigns->delete((int) $request->get_param('id'));

        return Response::success(['deleted' => true]);
    }

    public function preview(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->preview((int) $request->get_param('id')));
    }

    public function test(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->test(
            (int) $request->get_param('id'),
            (string) $request->get_param('to')
        ));
    }

    public function send(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->send((int) $request->get_param('id')), 202);
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->cancel((int) $request->get_param('id'))->toArray());
    }

    public function recipients(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->campaigns->recipientList((int) $request->get_param('id'), [
            'page' => $page,
            'per_page' => $perPage,
            'status' => (string) $request->get_param('status'),
        ]);

        return Response::success(
            $result['items'],
            200,
            Response::paginationMeta($result['total'], $page, $perPage) + ['purged' => $result['purged']]
        );
    }

    // -------------------------------------------------------------- segments --

    public function indexSegments(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->segments->list(
            $page,
            $perPage,
            (string) $request->get_param('orderby'),
            (string) $request->get_param('order')
        );

        return Response::success(
            array_map(static fn (Segment $s): array => $s->toArray(), $result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function showSegment(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->segments->get((int) $request->get_param('id'))->toArray());
    }

    public function createSegment(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->segments->create($this->body($request))->toArray(), 201);
    }

    public function updateSegment(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            $this->segments->update((int) $request->get_param('id'), $this->body($request))->toArray()
        );
    }

    public function destroySegment(WP_REST_Request $request): WP_REST_Response
    {
        $this->segments->delete((int) $request->get_param('id'));

        return Response::success(['deleted' => true]);
    }

    public function previewSegment(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->segments->preview((int) $request->get_param('id')));
    }

    // ------------------------------------------------------------- templates --

    public function indexTemplates(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $result = EmailTemplates::paginate($page, $perPage);

        return Response::success(
            $result['items'],
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function showTemplate(WP_REST_Request $request): WP_REST_Response
    {
        Permissions::assert(Capabilities::MANAGE_MARKETING);

        $template = EmailTemplates::read((int) $request->get_param('id'));

        if ($template === null) {
            throw \AlgerianCommerce\API\ApiException::notFound('No email template with that id.');
        }

        return Response::success($template);
    }

    // ----------------------------------------------------------- unsubscribe --

    public function unsubscribe(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success($this->campaigns->unsubscribe((string) $request->get_param('token')));
    }

    /** @return array<string, array<string, mixed>> */
    private function tokenArg(): array
    {
        return [
            'token' => [
                'type' => 'string',
                'required' => false,
                'default' => '',
                'maxLength' => 64,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'The signed unsubscribe token from the email.',
            ],
        ];
    }

    /**
     * The write body, read whole.
     *
     * No `args` schema on POST and PATCH: `CampaignInput` has to be able to answer
     * "unknown field", and a declared schema silently drops anything it does not
     * know — which turns a typo into a field that vanished rather than an error.
     * `Coupons\CouponController` and `Products\ProductController` read their bodies
     * the same way for the same reason.
     *
     * @return array<string, mixed>
     */
    private function body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();

        return is_array($body) ? $body : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function listArgs(): array
    {
        return $this->paginationArgs() + [
            'status' => [
                'type' => 'string',
                'default' => '',
                'enum' => array_merge([''], CampaignStatus::ALL),
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'search' => [
                'type' => 'string',
                'default' => '',
                'maxLength' => 200,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'segment_id' => [
                'type' => 'integer',
                'default' => 0,
                'minimum' => 0,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'created_at',
                'enum' => CampaignRepository::ORDERBY,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'desc',
                'enum' => ['asc', 'desc'],
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
        ];
    }
}
