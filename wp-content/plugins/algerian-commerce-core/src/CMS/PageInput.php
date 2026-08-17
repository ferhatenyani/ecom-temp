<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\SEO\SeoInput;

/**
 * Validates a page write payload — roadmap §89.
 *
 * Pure: no WordPress, so the field list is testable on its own.
 *
 * ## `slug` is the field and `path` is the address
 *
 * `/cms/pages/(?P<path>…)` captures **`path`**, and that name is forced rather
 * than chosen. `AbstractController::pinRouteParams()` makes the URL the
 * authority for every captured param, so a route capturing `slug` beside a body
 * carrying `slug` would answer 200 to a rename having renamed nothing — the
 * failure §88 wrote down for §89 to avoid. The parameter always took a full
 * path (`legal/terms`), so `path` is the more honest name for it anyway, and
 * `tests/Api/security.php` lists it with that reason.
 *
 * So: the URL addresses the page by path, the body renames it with `slug` (one
 * segment, never a path) and moves it with `parent_path`. `parent_id` is
 * emitted by the presenter and dropped here, like every other id in this
 * plugin; `parent_path` is emitted beside it so the writable form is visible in
 * the read body rather than only in the documentation.
 */
final class PageInput
{
    /** WordPress's own vocabulary, narrowed to what §89 needs. */
    public const STATUSES = ['draft', 'publish'];

    /**
     * Emitted on read, dropped on write, so `GET` → edit → `PATCH` round trips.
     *
     * `path` is here because it is the address, `parent_id` because ids are
     * dropped everywhere in this plugin and `parent_path` is the field that
     * moves a page.
     */
    private const READ_ONLY = ['id', 'path', 'parent_id', 'image', 'date_created', 'date_modified'];

    /** @var array<string, string> refused by name, with the reason */
    private const REFUSED = [
        'author' => 'A page has no author in this API — the audit trail names who wrote it.',
        'post_type' => 'This route writes pages only.',
        'template' => 'A page template is a theme concern, and this backend renders nothing.',
        'password' => 'A password-protected page is a WordPress rendering feature; a headless storefront cannot honour it.',
        'comment_status' => 'Comments are not part of this API.',
        'content_raw' => 'Use "content". It is sanitised on save either way.',
    ];

    private const MAX_TITLE = 200;
    private const MAX_SLUG = 190;
    private const MAX_CONTENT = 200000;
    private const MAX_EXCERPT = 5000;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['title', 'slug', 'parent_path', 'content', 'excerpt', 'status', 'menu_order', 'image_id', 'seo'];
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
            throw ApiException::invalidRequest('The page data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The page data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $errors collected by reference so one
     *                                      response names every bad field
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

        if (array_key_exists('slug', $payload)) {
            $slug = is_scalar($payload['slug']) ? trim((string) $payload['slug']) : '';

            /*
             * One segment. A slash here would mean two different things at once
             * — rename and move — and `parent_path` already means the second.
             * Refusing is the diagnosable answer; silently splitting it is not.
             */
            if ($slug === '') {
                $errors['slug'] = 'Must be a non-empty string, or omitted to derive it from the title.';
            } elseif (str_contains($slug, '/')) {
                $errors['slug'] = 'A slug is one segment. Use "parent_path" to move the page under another one.';
            } elseif (preg_match('/^[a-zA-Z0-9\-_]+$/', $slug) !== 1) {
                $errors['slug'] = 'May contain letters, digits, hyphens and underscores only.';
            } elseif (strlen($slug) > self::MAX_SLUG) {
                $errors['slug'] = 'Must be at most ' . self::MAX_SLUG . ' bytes.';
            } else {
                $clean['slug'] = strtolower($slug);
            }
        }

        if (array_key_exists('parent_path', $payload)) {
            $parent = $payload['parent_path'];

            // `null` and `""` both mean the root, because a client clearing a
            // field sends one or the other and neither is a mistake.
            if ($parent === null || $parent === '') {
                $clean['parent_path'] = '';
            } elseif (!is_string($parent) || preg_match('/^[a-zA-Z0-9\-_]+(\/[a-zA-Z0-9\-_]+)*$/', $parent) !== 1) {
                $errors['parent_path'] = 'Must be a page path such as "legal", or "" for the root.';
            } else {
                $clean['parent_path'] = strtolower($parent);
            }
        }

        foreach (['content' => self::MAX_CONTENT, 'excerpt' => self::MAX_EXCERPT] as $field => $max) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($value === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_string($value)) {
                $errors[$field] = 'Must be a string.';
                continue;
            }

            if (strlen($value) > $max) {
                $errors[$field] = "Must be at most {$max} bytes.";
                continue;
            }

            // The allowlist runs here, on the way in — §89. What is stored is
            // what a second reader gets, and a second reader may not sanitise.
            $clean[$field] = ContentHtml::sanitize($value);
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? (string) $payload['status'] : '';

            if (!in_array($status, self::STATUSES, true)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', self::STATUSES) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        if (array_key_exists('menu_order', $payload)) {
            if (!is_int($payload['menu_order']) && !(is_string($payload['menu_order']) && ctype_digit($payload['menu_order']))) {
                $errors['menu_order'] = 'Must be an integer.';
            } else {
                $clean['menu_order'] = (int) $payload['menu_order'];
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

        /*
         * §62's rule, unchanged: a page's SEO is written through the page, and
         * an SEO error lands in the same `fields` list as the rest of the write
         * rather than in a second, differently shaped response. There is no SEO
         * endpoint and §89 does not add one.
         */
        if (array_key_exists('seo', $payload)) {
            $clean['seo'] = SeoInput::fromPayload($payload['seo'], $errors);
        }

        return $clean;
    }
}
