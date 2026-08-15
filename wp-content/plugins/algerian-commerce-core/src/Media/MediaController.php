<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\AbstractController;
use AlgerianCommerce\API\Response;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Media endpoints — roadmap §61, docs/PLAN.md §24.
 *
 *   POST   /media          multipart upload
 *   GET    /media          list, search, filter by type
 *   GET    /media/{id}
 *   PATCH  /media/{id}     alt text, title, caption
 *   DELETE /media/{id}
 *
 * `POST /media` is the highest-risk route in this API — the only one that
 * writes a file a web server might later execute — and the rules that decide
 * what it accepts live in `UploadPolicy`, not here. This class does what every
 * other controller does: parse, call one service, format.
 *
 * The upload takes `alt`, `title` and `caption` alongside the file, which are
 * the same three fields PATCH takes. A storefront image without alt text is an
 * accessibility defect, and making it a second request is how it gets skipped.
 */
final class MediaController extends AbstractController
{
    public function __construct(
        Logger $logger,
        private readonly MediaService $service
    ) {
        parent::__construct($logger);
    }

    public function registerRoutes(): void
    {
        $guard = Permissions::callback(Capabilities::MANAGE_CONTENT);

        register_rest_route($this->restNamespace(), '/media', [
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
                /*
                 * The file itself is not an arg: it arrives as multipart and is
                 * read through get_file_params(). Only the text fields beside
                 * it are declared here.
                 */
                'args' => $this->editableArgs(),
            ],
        ]);

        register_rest_route($this->restNamespace(), '/media/(?P<id>\d+)', [
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
            /*
             * A family (`image`) or a full type (`image/png`). WP_Query
             * understands both; the pattern is what keeps anything else out of
             * the query in the first place.
             */
            'type' => [
                'type' => 'string',
                'pattern' => '^[a-z]+(/[a-z0-9.+-]+)?$',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => MediaRepository::ORDERBY,
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

    /** @return array<string, array<string, mixed>> */
    private function editableArgs(): array
    {
        $args = [];

        foreach (MediaInput::allowedFields() as $field) {
            $args[$field] = [
                'type' => 'string',
                'validate_callback' => 'rest_validate_request_arg',
            ];
        }

        return $args;
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        $result = $this->service->list([
            'page' => $page,
            'per_page' => $perPage,
            'search' => (string) $request->get_param('search'),
            'type' => (string) $request->get_param('type'),
            'orderby' => (string) $request->get_param('orderby'),
            'order' => (string) $request->get_param('order'),
        ]);

        return Response::success(
            MediaPresenter::toArrayList($result['items']),
            200,
            Response::paginationMeta($result['total'], $page, $perPage)
        );
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $files = $request->get_file_params();

        $payload = [];

        foreach (MediaInput::allowedFields() as $field) {
            $value = $request->get_param($field);

            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        $attachment = $this->service->upload($files['file'] ?? null, $payload);

        return Response::success(MediaPresenter::toArray($attachment), 201);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return Response::success(
            MediaPresenter::toArray($this->service->get((int) $request->get_param('id')))
        );
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        /*
         * JSON or form-encoded. A media library edit is as likely to come from
         * a form as from a JSON client, and `get_json_params()` is null for the
         * second — which would answer "no supported fields were provided" to a
         * request that plainly provided some.
         */
        $body = $request->get_json_params();

        if (!is_array($body)) {
            $body = $request->get_body_params();
        }

        return Response::success(MediaPresenter::toArray(
            $this->service->update((int) $request->get_param('id'), is_array($body) ? $body : [])
        ));
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $this->service->delete($id);

        return Response::success(['id' => $id, 'deleted' => true]);
    }
}
