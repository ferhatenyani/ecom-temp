<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\ApiException;
use WP_Post;
use WP_Query;

/**
 * Attachment storage — roadmap §61, docs/PLAN.md §24.
 *
 * The only file in `Media/` that touches the filesystem or WordPress's upload
 * machinery. §61 is explicit that `wp_handle_upload()` and
 * `wp_insert_attachment()` are preferred over hand-rolled file handling, and
 * they are used here for exactly the reason it gives: they already know the
 * uploads directory layout, the year/month folders, unique-filename collision
 * handling and core's own type check — all of which a reimplementation would
 * get subtly wrong in a place nobody looks.
 *
 * The order below matters. The file is moved, **then sanitised, then**
 * registered as an attachment: a row that exists before the bytes are safe is a
 * row something else can find.
 */
final class MediaRepository
{
    /** What a client may sort the library by. */
    public const ORDERBY = ['date', 'title', 'id'];

    public function __construct(
        private readonly UploadPolicy $policy,
        private readonly ImageSanitizer $sanitizer
    ) {
    }

    /**
     * Move, sanitise, register.
     *
     * @return int the attachment id
     *
     * @throws ApiException
     */
    public function store(UploadedFile $file, string $storedName, string $mime): int
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        /*
         * The name WordPress is handed is ours, not the client's — see
         * UploadPolicy::storedFilename(). WordPress then applies
         * sanitize_file_name() and wp_unique_filename() on top, which is the
         * belt this is the braces for.
         *
         * A variable rather than an inline array: `wp_handle_upload()` takes
         * its first argument **by reference**, and passing a literal is a
         * fatal TypeError rather than a notice. Nothing before the `rest`
         * stage catches that — the policy refuses every hostile file earlier,
         * so only a legitimate upload ever reaches this line.
         */
        $upload = [
            'name' => $storedName,
            'type' => $mime,
            'tmp_name' => $file->tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => $file->size,
        ];

        $handled = wp_handle_upload(
            $upload,
            [
                /*
                 * There is no form and no nonce here: this route authenticates
                 * with an Application Password and authorizes with
                 * ac_manage_content, which is the check that matters. Leaving
                 * it on would fail every legitimate upload.
                 */
                'test_form' => false,
                // Core's own allowlist, generated from ours so the two cannot
                // drift apart. This is the fourth independent type check.
                'mimes' => UploadPolicy::wordPressMimes(),
            ]
        );

        if (!is_array($handled) || isset($handled['error'])) {
            throw new ApiException(
                'invalid_upload',
                'The file could not be stored.',
                400,
                ['reason' => is_array($handled) ? (string) $handled['error'] : 'unknown']
            );
        }

        $path = (string) $handled['file'];

        // Before the attachment row exists, so nothing can reference a file
        // that still carries whatever arrived with it.
        $this->sanitizer->sanitize($path, $mime);

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => $mime,
                'post_title' => $this->titleFrom($storedName),
                'post_content' => '',
                'post_status' => 'inherit',
            ],
            $path,
            0,
            true
        );

        if (is_wp_error($attachmentId)) {
            @unlink($path);

            throw ApiException::internal('The attachment could not be recorded.');
        }

        /*
         * Sub-sizes, generated from the already-stripped original — so every
         * derivative is clean too, without a second pass.
         */
        wp_update_attachment_metadata(
            (int) $attachmentId,
            wp_generate_attachment_metadata((int) $attachmentId, $path)
        );

        return (int) $attachmentId;
    }

    public function find(int $id): ?WP_Post
    {
        $post = get_post($id);

        if (!$post instanceof WP_Post || $post->post_type !== 'attachment') {
            return null;
        }

        return $post;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $args = [
            'post_type' => 'attachment',
            // Attachments are 'inherit', never 'publish'. Asking for 'publish'
            // returns an empty library and looks like a permissions bug.
            'post_status' => 'inherit',
            'paged' => max(1, (int) ($criteria['page'] ?? 1)),
            'posts_per_page' => max(1, (int) ($criteria['per_page'] ?? 20)),
            'orderby' => in_array((string) ($criteria['orderby'] ?? ''), self::ORDERBY, true)
                ? (string) $criteria['orderby']
                : 'date',
            'order' => strtoupper((string) ($criteria['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC',
            'no_found_rows' => false,
        ];

        $type = (string) ($criteria['type'] ?? '');

        if ($type !== '') {
            $args['post_mime_type'] = $type;
        }

        $search = (string) ($criteria['search'] ?? '');

        if ($search !== '') {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);

        return [
            'items' => array_values(array_filter($query->posts, static fn ($p): bool => $p instanceof WP_Post)),
            'total' => (int) $query->found_posts,
        ];
    }

    /** @throws ApiException */
    public function update(WP_Post $attachment, MediaInput $input): WP_Post
    {
        $fields = ['ID' => $attachment->ID];

        if ($input->has('title')) {
            $fields['post_title'] = sanitize_text_field((string) $input->get('title'));
        }

        if ($input->has('caption')) {
            $fields['post_excerpt'] = wp_kses_post((string) $input->get('caption'));
        }

        if (count($fields) > 1) {
            $result = wp_update_post($fields, true);

            if (is_wp_error($result)) {
                throw ApiException::internal('The attachment could not be updated.');
            }
        }

        if ($input->has('alt')) {
            update_post_meta(
                $attachment->ID,
                '_wp_attachment_image_alt',
                sanitize_text_field((string) $input->get('alt'))
            );
        }

        $updated = $this->find((int) $attachment->ID);

        if ($updated === null) {
            throw ApiException::internal('The attachment could not be re-read.');
        }

        return $updated;
    }

    /**
     * Permanent, files included.
     *
     * `force_delete` rather than the trash: an attachment in the trash still
     * has its file on disk at the same URL, so "deleted" would not be true of
     * the only thing anyone can reach. A customer photograph that a shop
     * removed has to actually go.
     */
    public function delete(int $id): bool
    {
        return wp_delete_attachment($id, true) instanceof WP_Post;
    }

    /** A readable default title: `tapis-berbere.jpg` becomes `tapis berbere`. */
    private function titleFrom(string $storedName): string
    {
        $stem = (string) preg_replace('/\.[^.]*$/', '', $storedName);

        return trim(str_replace(['-', '_'], ' ', $stem)) ?: 'image';
    }
}
