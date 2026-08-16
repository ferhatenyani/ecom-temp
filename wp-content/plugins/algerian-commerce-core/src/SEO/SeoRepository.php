<?php

declare(strict_types=1);

namespace AlgerianCommerce\SEO;

/**
 * The five stored SEO overrides, on any post — roadmap §62.
 *
 * The only file in `SEO/` that knows a meta key exists. Individual scalar keys
 * rather than one serialised blob, deliberately: §61 settled that content is
 * authored in WordPress, and a serialised array is not editable in the
 * custom-fields box or with `wp post meta update`, which are the two authoring
 * surfaces this project actually has.
 *
 * A field set to the empty string is **deleted**, not stored empty. "No
 * override" and "an override that happens to be blank" would otherwise be two
 * states that look identical in the database and behave differently in the
 * resolver.
 */
final class SeoRepository
{
    /**
     * @return array<string, string> field name → stored value, absent keys omitted
     */
    public function overridesFor(int $postId): array
    {
        $stored = [];

        foreach (SeoFields::META_KEYS as $field => $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);

            if (is_scalar($value) && trim((string) $value) !== '' && (string) $value !== '0') {
                $stored[$field] = trim((string) $value);
            }
        }

        return $stored;
    }

    /** @return list<string> the fields a person has actually set */
    public function overriddenFields(int $postId): array
    {
        return array_keys($this->overridesFor($postId));
    }

    public function save(int $postId, SeoInput $input): void
    {
        foreach (SeoFields::META_KEYS as $field => $metaKey) {
            if (!$input->has($field)) {
                continue;
            }

            $value = $input->get($field);

            if ($value === null || $value === '' || $value === 0) {
                delete_post_meta($postId, $metaKey);
                continue;
            }

            update_post_meta($postId, $metaKey, $value);
        }
    }
}
