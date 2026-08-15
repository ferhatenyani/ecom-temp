<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use AlgerianCommerce\Security\RateLimiter;
use WP_Post;

/**
 * Media business rules — roadmap §61, docs/PLAN.md §24.
 *
 * `upload()` is the sequence §61's checklist asks for, in one place:
 *
 * ```
 * authorize → rate limit → read the multipart entry → validate every way
 *           → move → strip metadata → register → audit
 * ```
 *
 * The capability is `ac_manage_content` on all five routes, and there is
 * deliberately no second, weaker one. **A Product Manager therefore cannot
 * upload**, only attach an image that already exists (roadmap §47c takes an
 * attachment id). That is a real gap and it is named rather than papered over:
 * docs/PLAN.md §3 defines no media capability, and both ways of closing it are
 * worse than the gap. Inventing `ac_manage_media` puts a capability in the
 * matrix that PLAN.md does not have; adding `ac_manage_content` to Product
 * Manager hands whoever edits the catalogue the homepage as well. Writing files
 * to the server is the one privilege least privilege should be strictest about.
 */
final class MediaService
{
    public function __construct(
        private readonly MediaRepository $repository,
        private readonly UploadPolicy $policy,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogger $audit,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param mixed                $fileParams the `file` entry of the multipart request
     * @param array<string, mixed> $payload    alt / title / caption, set at upload time
     *
     * @throws ApiException
     */
    public function upload(mixed $fileParams, array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);
        $this->guardUploadRate();

        $file = UploadedFile::fromParams($fileParams);
        $input = MediaInput::fromPayload($payload);

        $accepted = $this->policy->accept($file->name, $file->size, $file->tmpPath);

        $id = $this->repository->store($file, $accepted['filename'], $accepted['mime']);

        $attachment = $this->repository->find($id);

        if ($attachment === null) {
            throw ApiException::internal('The attachment could not be re-read.');
        }

        if (!$input->isEmpty()) {
            $attachment = $this->repository->update($attachment, $input);
        }

        $this->audit->record('media.uploaded', 'media', $id, [
            'filename' => $accepted['filename'],
            'mime_type' => $accepted['mime'],
            'size' => $file->size,
            // What the client said it was, kept beside what it turned out to
            // be. A gap between the two is the interesting line in this log.
            'client_filename' => $file->name,
            'client_mime' => $file->clientMime,
        ]);

        return $attachment;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Post>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        return $this->repository->paginate($criteria);
    }

    public function get(int $id): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        return $this->require($id);
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): WP_Post
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $attachment = $this->require($id);
        $input = MediaInput::fromPayload($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $before = [
            'title' => $attachment->post_title,
            'caption' => $attachment->post_excerpt,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];

        $updated = $this->repository->update($attachment, $input);

        $this->audit->record('media.updated', 'media', $id, [
            'fields' => array_keys($input->fields),
            'before' => $before,
            'after' => [
                'title' => $updated->post_title,
                'caption' => $updated->post_excerpt,
                'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            ],
        ]);

        return $updated;
    }

    /**
     * Delete, permanently.
     *
     * Nothing here checks whether the image is in use. A product holds an
     * attachment id and the presenters already answer `null` for one that has
     * gone (`MediaPresenter::image()`), so a deleted image is a missing picture
     * rather than a broken response — and a "you cannot delete this" that scans
     * every product, variation, banner and page on every delete would be a
     * table scan protecting against a recoverable mistake. The audit entry
     * records who removed what.
     */
    public function delete(int $id): void
    {
        Permissions::assert(Capabilities::MANAGE_CONTENT);

        $attachment = $this->require($id);

        $context = [
            'filename' => wp_basename((string) get_attached_file($id)),
            'mime_type' => $attachment->post_mime_type,
            'title' => $attachment->post_title,
        ];

        if (!$this->repository->delete($id)) {
            throw ApiException::internal('The attachment could not be deleted.');
        }

        $this->audit->record('media.deleted', 'media', $id, $context);
    }

    private function require(int $id): WP_Post
    {
        $attachment = $this->repository->find($id);

        if ($attachment === null) {
            throw ApiException::notFound('No media item with that id.');
        }

        return $attachment;
    }

    /**
     * A tighter limit than the namespace-wide write limit.
     *
     * `RateLimitGuard` already counts this request as a write, 120 a minute by
     * default. That number was chosen for endpoints that write a database row;
     * this one writes a file and re-encodes an image, so 120 a minute at the
     * size cap is hundreds of megabytes of disk and a CPU-bound decode loop,
     * from one authenticated credential. Keyed on the identity alone because an
     * upload always has one — the IP counter is applied by the guard.
     */
    private function guardUploadRate(): void
    {
        $userId = get_current_user_id();

        if ($userId <= 0) {
            return;
        }

        $this->rateLimiter->enforce(
            $this->rateLimiter->uploadLimit(),
            'user:' . $userId,
            time(),
            '/media'
        );
    }
}
