<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a menu write payload — roadmap §89.
 *
 * Pure, and the shape is the shop's rather than WordPress's. A nav-menu item is
 * a post carrying `_menu_item_type`, `_menu_item_object`, `_menu_item_object_id`
 * and an ordering field, and exposing that would make the admin panel implement
 * WordPress's data model instead of the shop's. So the panel sends
 * `{label, type, …, children}` and this class is the only place the translation
 * lives.
 *
 * ## It also accepts what a read returns
 *
 * `CmsPresenter::menu()` publishes WordPress's own vocabulary — `type` is
 * `post_type`, `taxonomy` or `custom`, and the label is `title`. That was §61's
 * read contract and changing it would break every existing caller, so instead
 * this class takes either form: `title` is an alias for `label`, and a
 * `post_type`/`page` pair normalises to `page`. Without it, "GET the menu, drag
 * one item, PUT it back" — the only interaction a menu screen has — would be a
 * 400, and `docs/API.md`'s round-trip promise would have an exception in it.
 *
 * ## Two levels, fifty items
 *
 * Both are §89's, and both are bounds rather than preferences: a third level of
 * navigation is a site map, and a PUT that can carry an unbounded tree is one
 * request that can write unbounded rows.
 */
final class MenuInput
{
    /** What a menu item may point at, in this API's vocabulary. */
    public const TYPES = ['page', 'category', 'product', 'url'];

    /** Every node, at both levels, counted together. */
    public const MAX_ITEMS = 50;

    public const MAX_DEPTH = 2;

    private const MAX_LABEL = 200;
    private const MAX_URL = 2000;

    /**
     * Emitted per item on read, dropped on write.
     *
     * `target` and `classes` are dropped rather than honoured: neither is in
     * §89's shape, and a menu written through this API is described entirely by
     * label, destination and order. A named limitation, in `docs/API.md`.
     */
    private const ITEM_READ_ONLY = ['id', 'position', 'object', 'classes', 'target'];

    /**
     * @param list<array{label: string, type: string, object_id: int, path: string, url: string, children: list<mixed>}> $items
     */
    private function __construct(public readonly array $items)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['items'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $errors[(string) $field] = 'Unknown field. A menu is replaced whole and carries only "items".';
        }

        if (!array_key_exists('items', $payload)) {
            $errors['items'] = 'Required. Send [] to empty the menu.';
        } elseif (!is_array($payload['items']) || !array_is_list($payload['items'])) {
            $errors['items'] = 'Must be a list.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The menu data is invalid.', ['fields' => $errors]);
        }

        /** @var list<mixed> $raw */
        $raw = $payload['items'];
        $count = 0;
        $items = self::level($raw, 1, 'items', $errors, $count);

        if ($count > self::MAX_ITEMS) {
            $errors['items'] = sprintf(
                'A menu carries at most %d items across both levels; this one has %d.',
                self::MAX_ITEMS,
                $count
            );
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The menu data is invalid.', ['fields' => $errors]);
        }

        return new self($items);
    }

    /**
     * A URL a menu item or a banner may point at.
     *
     * §71's rule: `javascript:` is a valid URL and `parse_url()` will happily
     * return it, so the check is an allowlist of schemes rather than a search
     * for bad ones. A root-relative path is accepted because a storefront's own
     * routes are the common case and they carry no scheme at all; a
     * scheme-relative `//host` is not, because it inherits the page's scheme
     * and reads as a path to everyone who is not thinking about it.
     *
     * Shared with `BannerInput` rather than restated, so the two cannot drift.
     */
    public static function isSafeUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '' || str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        return preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url) === 1;
    }

    /**
     * @param list<mixed>           $raw
     * @param array<string, string> $errors
     * @return list<array<string, mixed>>
     */
    private static function level(array $raw, int $depth, string $path, array &$errors, int &$count): array
    {
        $items = [];

        foreach ($raw as $index => $entry) {
            $count++;
            $where = "{$path}[{$index}]";

            if ($count > self::MAX_ITEMS) {
                // Counted, not validated: the cap is reported once, against
                // `items`, rather than as fifty identical field errors.
                continue;
            }

            if (!is_array($entry)) {
                $errors[$where] = 'Must be an object.';
                continue;
            }

            $item = self::item($entry, $where, $errors);

            if ($item === null) {
                continue;
            }

            $children = $entry['children'] ?? [];

            if ($children === null) {
                $children = [];
            }

            if (!is_array($children) || !array_is_list($children)) {
                $errors["{$where}.children"] = 'Must be a list.';
                continue;
            }

            if ($children !== [] && $depth >= self::MAX_DEPTH) {
                $errors["{$where}.children"] = sprintf(
                    'A menu is %d levels deep. Move these items up, or make this one a page of its own.',
                    self::MAX_DEPTH
                );
                continue;
            }

            $item['children'] = $children === []
                ? []
                : self::level($children, $depth + 1, "{$where}.children", $errors, $count);

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $errors
     * @return array<string, mixed>|null
     */
    private static function item(array $entry, string $where, array &$errors): ?array
    {
        /*
         * The original is kept because `object` is one of the dropped fields
         * *and* half of what identifies a read-shape item: `type: post_type`
         * alone cannot tell a page from a product. Dropping it before reading
         * it would make the round-trip this class exists for fail on exactly
         * the items a real menu contains.
         */
        $original = $entry;
        $entry = array_diff_key($entry, array_flip(self::ITEM_READ_ONLY));

        $known = ['label', 'title', 'type', 'object_id', 'path', 'url', 'children'];

        foreach (array_diff(array_keys($entry), $known) as $field) {
            $errors["{$where}.{$field}"] = 'Unknown field.';
        }

        // `title` is what a read returns; `label` is what §89 specifies. Either
        // is accepted and `label` wins when both are present.
        $label = $entry['label'] ?? $entry['title'] ?? null;
        $label = is_scalar($label) ? ContentHtml::sanitizeText(trim((string) $label)) : '';

        if ($label === '') {
            $errors["{$where}.label"] = 'Required, and must be a non-empty string.';

            return null;
        }

        if (mb_strlen($label) > self::MAX_LABEL) {
            $errors["{$where}.label"] = 'Must be at most ' . self::MAX_LABEL . ' characters.';

            return null;
        }

        $type = self::normaliseType($original);

        if ($type === null) {
            $errors["{$where}.type"] = 'Must be one of: ' . implode(', ', self::TYPES) . '.';

            return null;
        }

        $item = ['label' => $label, 'type' => $type, 'object_id' => 0, 'path' => '', 'url' => ''];

        if ($type === 'url') {
            $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : '';

            if ($url === '' || strlen($url) > self::MAX_URL) {
                $errors["{$where}.url"] = 'Required for a "url" item, and at most ' . self::MAX_URL . ' bytes.';

                return null;
            }

            if (!self::isSafeUrl($url)) {
                $errors["{$where}.url"] = 'Must be an http or https URL, or a path beginning with "/".';

                return null;
            }

            $item['url'] = $url;

            return $item;
        }

        /*
         * A page may be addressed by path or by id; a category and a product by
         * id only, because neither has a path in this API. The path form exists
         * so a client that has only ever seen `/cms/pages/legal/terms` never has
         * to learn a post id — the same argument `parent_path` makes.
         */
        if ($type === 'page' && isset($entry['path'])) {
            $pagePath = is_string($entry['path']) ? strtolower(trim($entry['path'])) : '';

            if (preg_match('/^[a-z0-9\-_]+(\/[a-z0-9\-_]+)*$/', $pagePath) !== 1) {
                $errors["{$where}.path"] = 'Must be a page path such as "legal/terms".';

                return null;
            }

            $item['path'] = $pagePath;

            return $item;
        }

        $objectId = $entry['object_id'] ?? null;

        if (!is_numeric($objectId) || (int) $objectId <= 0) {
            $errors["{$where}.object_id"] = $type === 'page'
                ? 'A "page" item needs a "path" or an "object_id".'
                : sprintf('A "%s" item needs an "object_id".', $type);

            return null;
        }

        $item['object_id'] = (int) $objectId;

        return $item;
    }

    /**
     * This API's four types, or WordPress's own pair normalised to them.
     *
     * @param array<string, mixed> $entry
     */
    private static function normaliseType(array $entry): ?string
    {
        $type = is_scalar($entry['type'] ?? null) ? strtolower(trim((string) $entry['type'])) : '';

        if (in_array($type, self::TYPES, true)) {
            return $type;
        }

        // The read shape, which needs `object` as well as `type` — so this is
        // handed the caller's original entry, before the read-only fields are
        // dropped. See `item()`.
        $object = is_scalar($entry['object'] ?? null) ? strtolower(trim((string) $entry['object'])) : '';

        return match (true) {
            $type === 'custom' => 'url',
            $type === 'post_type' && $object === 'page' => 'page',
            $type === 'post_type' && $object === 'product' => 'product',
            $type === 'taxonomy' && $object === 'product_cat' => 'category',
            default => null,
        };
    }
}
