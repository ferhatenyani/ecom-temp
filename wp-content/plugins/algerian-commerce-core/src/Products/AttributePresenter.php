<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WP_Term;

/**
 * Shapes a global attribute and its terms for the API — roadmap §88.
 *
 * **`taxonomy` is published and `slug` is published beside it**, because they
 * are two different things a client needs and confusing them is the mistake
 * this endpoint exists to prevent. The slug is what you send back here; the
 * taxonomy is what `GET /products?attributes[pa_size]=m` matches and what
 * `meta.facets` keys its groups by. §82 accepts either, and a client that only
 * ever saw one would have to guess the other.
 */
final class AttributePresenter
{
    /**
     * @param object                    $attribute a row from `wc_get_attribute_taxonomies()`
     * @param array{products: int, terms: int}|null $usage omitted from list rows
     * @return array<string, mixed>
     */
    public static function toArray(object $attribute, ?array $usage = null): array
    {
        $slug = (string) ($attribute->attribute_name ?? '');

        $payload = [
            'id' => (int) ($attribute->attribute_id ?? 0),
            'name' => (string) ($attribute->attribute_label ?? $slug),
            'slug' => $slug,
            'taxonomy' => $slug === '' ? '' : wc_attribute_taxonomy_name($slug),
            'type' => (string) ($attribute->attribute_type ?? 'select'),
            'order_by' => (string) ($attribute->attribute_orderby ?? 'menu_order'),
            'has_archives' => (int) ($attribute->attribute_public ?? 0) === 1,
        ];

        if ($usage !== null) {
            $payload['term_count'] = $usage['terms'];
            $payload['product_count'] = $usage['products'];
        }

        return $payload;
    }

    /**
     * @param list<object> $attributes
     * @return list<array<string, mixed>>
     */
    public static function toArrayList(array $attributes): array
    {
        return array_values(array_map(
            static fn (object $attribute): array => self::toArray($attribute),
            $attributes
        ));
    }

    /** @return array<string, mixed> */
    public static function term(WP_Term $term): array
    {
        return [
            'id' => (int) $term->term_id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'description' => (string) $term->description,
            'menu_order' => self::menuOrder($term),
            /*
             * WordPress's own count, maintained by `_update_post_term_count`.
             * It is what the delete guard reads, so publishing it is what lets
             * a client explain a 409 before the user provokes one.
             */
            'count' => (int) $term->count,
        ];
    }

    /**
     * @param list<WP_Term> $terms
     * @return list<array<string, mixed>>
     */
    public static function terms(array $terms): array
    {
        return array_values(array_map(
            static fn (WP_Term $term): array => self::term($term),
            $terms
        ));
    }

    /**
     * WooCommerce stores a term's position in term meta named after the
     * taxonomy, not in a column — `order_pa_size`. Absent means unordered,
     * which is 0.
     */
    private static function menuOrder(WP_Term $term): int
    {
        $stored = get_term_meta((int) $term->term_id, 'order_' . $term->taxonomy, true);

        return is_numeric($stored) ? (int) $stored : 0;
    }
}
