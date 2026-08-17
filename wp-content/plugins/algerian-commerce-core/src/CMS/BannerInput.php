<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a banner write payload — roadmap §89.
 *
 * Pure. The field names are `CmsPresenter::banner()`'s, so a read body written
 * back is a no-op rather than a 400 — `position` rather than `menu_order`,
 * `caption` rather than `content`, and the image as `image_id` beside the
 * `image` block the presenter emits and this class drops.
 *
 * `placement` stays the free key §61 made it: where a shop puts a banner is a
 * shop's decision, and this plugin is cloned per client. The shape is checked;
 * the vocabulary is not.
 */
final class BannerInput
{
    /** Emitted on read, dropped on write, so `GET` → edit → `PATCH` round trips. */
    private const READ_ONLY = ['id', 'image', 'date_created', 'date_modified'];

    /** @var array<string, string> refused by name, with the reason */
    private const REFUSED = [
        'image_url' => 'Upload through POST /media and send the attachment id as "image_id".',
        'url' => 'The destination is "link".',
        'menu_order' => 'Use "position", which is what the read body carries.',
        'content' => 'Use "caption", which is what the read body carries.',
    ];

    private const MAX_TITLE = 200;
    private const MAX_CAPTION = 20000;
    private const MAX_LINK = 2000;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['title', 'caption', 'link', 'placement', 'position', 'image_id', 'status'];
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
    public static function forCreate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if (!array_key_exists('title', $payload)) {
            $errors['title'] = 'Required.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The banner data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The banner data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private static function common(array $payload, array &$errors): array
    {
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $field = (string) $field;
            $errors[$field] = self::REFUSED[$field] ?? 'Unknown field.';
        }

        if (array_key_exists('title', $payload)) {
            $title = is_scalar($payload['title']) ? ContentHtml::sanitizeText(trim((string) $payload['title'])) : '';

            if ($title === '') {
                $errors['title'] = 'Must be a non-empty string.';
            } elseif (mb_strlen($title) > self::MAX_TITLE) {
                $errors['title'] = 'Must be at most ' . self::MAX_TITLE . ' characters.';
            } else {
                $clean['title'] = $title;
            }
        }

        if (array_key_exists('caption', $payload)) {
            $caption = $payload['caption'];

            if ($caption === null) {
                $clean['caption'] = '';
            } elseif (!is_string($caption)) {
                $errors['caption'] = 'Must be a string.';
            } elseif (strlen($caption) > self::MAX_CAPTION) {
                $errors['caption'] = 'Must be at most ' . self::MAX_CAPTION . ' bytes.';
            } else {
                $clean['caption'] = ContentHtml::sanitize($caption);
            }
        }

        if (array_key_exists('link', $payload)) {
            $link = $payload['link'];

            if ($link === null || $link === '') {
                $clean['link'] = '';
            } elseif (!is_string($link) || strlen($link) > self::MAX_LINK) {
                $errors['link'] = 'Must be a URL of at most ' . self::MAX_LINK . ' bytes.';
            } elseif (!MenuInput::isSafeUrl($link)) {
                // §71's rule: `javascript:` is a valid URL, and a banner is a
                // link a shopper clicks. Shared with the menu writer rather
                // than restated, so the two cannot drift apart.
                $errors['link'] = 'Must be an http or https URL, or a path beginning with "/".';
            } else {
                $clean['link'] = trim($link);
            }
        }

        if (array_key_exists('placement', $payload)) {
            $placement = is_scalar($payload['placement']) ? strtolower(trim((string) $payload['placement'])) : '';

            if (preg_match('/^[a-z0-9_-]{1,32}$/', $placement) !== 1) {
                $errors['placement'] = 'Must be 1–32 characters of a–z, 0–9, hyphen or underscore.';
            } else {
                $clean['placement'] = $placement;
            }
        }

        if (array_key_exists('position', $payload)) {
            if (!is_int($payload['position']) && !(is_string($payload['position']) && ctype_digit($payload['position']))) {
                $errors['position'] = 'Must be an integer.';
            } else {
                $clean['position'] = (int) $payload['position'];
            }
        }

        if (array_key_exists('image_id', $payload)) {
            $imageId = $payload['image_id'];

            if ($imageId === null || $imageId === '' || $imageId === 0) {
                $clean['image_id'] = 0;
            } elseif (!is_numeric($imageId) || (int) $imageId < 0) {
                $errors['image_id'] = 'Must be an attachment id, or 0 to clear.';
            } else {
                $clean['image_id'] = (int) $imageId;
            }
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? (string) $payload['status'] : '';

            if (!in_array($status, PageInput::STATUSES, true)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', PageInput::STATUSES) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        return $clean;
    }
}
