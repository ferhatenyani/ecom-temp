<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\ApiException;

/**
 * One entry of `$_FILES`, as a value object — roadmap §61.
 *
 * Pure, so PHP's upload error codes are translated in a place that can be
 * tested without a web server. Those codes matter more than they look: a file
 * larger than `upload_max_filesize` never reaches the application as data at
 * all — PHP discards it and leaves `UPLOAD_ERR_INI_SIZE` behind — so an
 * endpoint that only checks `size` reports "the file is empty" for the one
 * failure that has a precise answer.
 *
 * `clientMime` is carried and **never used to decide anything**. It is what the
 * browser claimed, it is recorded in the audit entry because a mismatch is
 * worth being able to look back at, and `UploadPolicy` is what the claim is
 * checked against.
 */
final class UploadedFile
{
    private function __construct(
        public readonly string $name,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly string $clientMime
    ) {
    }

    /**
     * @param mixed $params the `file` entry of WP_REST_Request::get_file_params()
     *
     * @throws ApiException
     */
    public static function fromParams(mixed $params): self
    {
        if (!is_array($params)) {
            throw new ApiException(
                'invalid_upload',
                'Send the file as multipart/form-data in a field named "file".',
                400
            );
        }

        /*
         * A multi-file field arrives as arrays under each key. One file per
         * request, refused explicitly: silently taking the first would make
         * "did my other three upload?" unanswerable.
         */
        foreach (['name', 'tmp_name', 'size'] as $key) {
            if (is_array($params[$key] ?? null)) {
                throw new ApiException('invalid_upload', 'Upload one file per request.', 400);
            }
        }

        self::assertUploadSucceeded((int) ($params['error'] ?? UPLOAD_ERR_NO_FILE));

        return new self(
            (string) ($params['name'] ?? ''),
            (string) ($params['tmp_name'] ?? ''),
            (int) ($params['size'] ?? 0),
            (string) ($params['type'] ?? '')
        );
    }

    /** @throws ApiException */
    private static function assertUploadSucceeded(int $error): void
    {
        switch ($error) {
            case UPLOAD_ERR_OK:
                return;

            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new ApiException(
                    'file_too_large',
                    'The file is larger than this server accepts.',
                    413
                );

            case UPLOAD_ERR_PARTIAL:
                throw new ApiException('invalid_upload', 'The upload did not complete.', 400);

            case UPLOAD_ERR_NO_FILE:
                throw new ApiException(
                    'invalid_upload',
                    'Send the file as multipart/form-data in a field named "file".',
                    400
                );

            default:
                /*
                 * No temporary directory, a failed write, or an extension that
                 * stopped the upload. None of these is the caller's fault and
                 * none of them is theirs to fix, so it is a 500 — and the
                 * message says nothing about the filesystem.
                 */
                throw ApiException::internal('The upload could not be stored.');
        }
    }
}
