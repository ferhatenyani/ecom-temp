<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The notification queue, read and retried — roadmap §90.
 *
 *   GET  /notifications
 *   GET  /notifications/{id}
 *   POST /notifications/{id}/retry
 *
 * §59d built no routes at all, and said why: *"§29 asks for an abstraction, not
 * an endpoint."* That was right for sending and it left an operator with no way
 * to answer "did the customer get their confirmation?" without `wp eval`. This
 * is the read surface, and nothing more — **nothing here sends**.
 *
 * `ac_manage_customers` on all three, and no new capability; see
 * `NotificationService` for the argument.
 */
final class NotificationController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly NotificationService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_CUSTOMERS);

        register_rest_route($this->restNamespace(), '/notifications', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'index']),
            'permission_callback' => $guard,
            'args' => $this->paginationArgs() + [
                'channel' => [
                    'type' => 'string',
                    // A key rather than an enum: §29's other four channels are
                    // one class plus one `add()` away, and a filter that had to
                    // be edited to see them would be found the hard way.
                    'pattern' => '^[a-z0-9_-]{1,32}$',
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [
                        NotificationRepository::STATUS_PENDING,
                        NotificationRepository::STATUS_SENT,
                        NotificationRepository::STATUS_FAILED,
                    ],
                    'validate_callback' => 'rest_validate_request_arg',
                ],
                /*
                 * The filter §90 exists for. The key is `event:subject_id` by
                 * construction, so `?dedupe_key=order.placed:1234` is "did the
                 * customer get their confirmation?" in one request. 191 to
                 * match the column, and no pattern beyond a length: an event
                 * name is ours but a recipient can end up in the key when a
                 * notification has no subject id.
                 */
                'dedupe_key' => [
                    'type' => 'string',
                    'maxLength' => 191,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'date_from' => $this->dateArg(),
                'date_to' => $this->dateArg(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/notifications/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => $this->handle([$this, 'show']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);

        register_rest_route($this->restNamespace(), '/notifications/(?P<id>\d+)/retry', [
            'methods' => 'POST',
            'callback' => $this->handle([$this, 'retry']),
            'permission_callback' => $guard,
            'args' => $this->idArg(),
        ]);
    }

    /** @return array<string, mixed> */
    private function dateArg(): array
    {
        return [
            'type' => 'string',
            'pattern' => '^\d{4}-\d{2}-\d{2}$',
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'sanitize_text_field',
            'description' => 'Y-m-d, UTC. Both ends cover the whole day.',
        ];
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->search([
            'page' => $page,
            'per_page' => $perPage,
            'channel' => (string) $request->get_param('channel'),
            'status' => (string) $request->get_param('status'),
            'dedupe_key' => (string) $request->get_param('dedupe_key'),
            'date_from' => (string) $request->get_param('date_from'),
            'date_to' => (string) $request->get_param('date_to'),
        ]);

        return Response::success(
            NotificationPresenter::list($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    /** The single read is the only place the frozen message is published. */
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            NotificationPresenter::full($this->service->get((int) $request->get_param('id')))
        );
    }

    /**
     * **202, not 200**, and the difference is the whole design. Nothing has
     * been sent when this returns; a row has been put back in a queue that
     * something else drains. Answering 200 would say the work is done, and the
     * first thing an operator would do is refresh and find the status still
     * `pending`. The drain command travels in the response for the same
     * reason — on a headless install nothing runs WP-Cron reliably
     * (CLAUDE.md, §63), so "wait for it" is not advice anybody can act on.
     */
    public function retry(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->service->retry((int) $request->get_param('id'));

        return Response::success(
            NotificationPresenter::row($result['row']),
            202,
            [
                'queued' => true,
                'already_pending' => $result['already_pending'],
                'drain' => 'wp algerian-commerce send-notifications',
            ]
        );
    }
}
