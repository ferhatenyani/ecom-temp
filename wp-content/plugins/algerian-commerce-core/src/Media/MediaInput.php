<?php

declare(strict_types=1);

namespace AlgerianCommerce\Media;

use AlgerianCommerce\API\ApiException;

/**
 * The editable fields of an attachment — roadmap §61.
 *
 * Exactly three: alt text, title, caption. Pure, so the field list is testable
 * on its own.
 *
 * **The list is short on purpose.** An attachment is a WordPress post, and a
 * write path that accepted arbitrary post fields would let `ac_manage_content`
 * change `post_type`, `post_status` or `post_author` on a row the media library
 * believes is an image. The bytes on disk are never editable at all: replacing
 * a file behind an id already in use would swap a product's photograph without
 * anything recording that it changed. Upload a new one and repoint the product.
 */
final class MediaInput
{
    /** Emitted on read, ignored on write, so a client can GET then PATCH back. */
    private const READ_ONLY = [
        'id', 'slug', 'mime_type', 'url', 'filename', 'filesize',
        'width', 'height', 'sizes', 'uploaded_by', 'date_created', 'date_modified',
    ];

    /** @var array<string, string> */
    private const REFUSED = [
        'file' => 'The stored file cannot be replaced; upload a new one.',
        'post_type' => 'Not editable.',
        'post_status' => 'Not editable.',
        'post_author' => 'Not editable.',
        'parent_id' => 'Not editable.',
    ];

    private const FIELDS = ['alt', 'title', 'caption'];

    private const MAX_LENGTH = 500;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return self::FIELDS;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::FIELDS) as $field) {
            $field = (string) $field;
            $errors[$field] = self::REFUSED[$field] ?? 'Unknown field.';
        }

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            // null clears the field, which is a real edit — an alt text that
            // was wrong is better absent than wrong.
            if ($payload[$field] === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($payload[$field])) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $payload[$field]);

            if (mb_strlen($value) > self::MAX_LENGTH) {
                $errors[$field] = 'Must be at most ' . self::MAX_LENGTH . ' characters.';
                continue;
            }

            $clean[$field] = $value;
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The media data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }
}
