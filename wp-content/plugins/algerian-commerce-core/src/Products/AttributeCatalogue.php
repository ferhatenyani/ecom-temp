<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use WP_Term;

/**
 * Which attributes this shop can filter and count on — roadmap §82.
 *
 * `AttributeInput` already draws the line this class enforces: a **global**
 * attribute is a registered taxonomy (`pa_size`) whose options are shared
 * terms, and a **custom** attribute is a string stored on one product. Only the
 * first can be faceted, because only the first has something to count — two
 * products both saying "Red" are two unrelated strings, not two of a thing.
 *
 * §82's rule is that the API *says so* rather than quietly returning nothing:
 * a shop whose filters do not work with no error anywhere concludes the feature
 * is broken, where a shop told "that attribute is custom, make it global"
 * fixes it in a minute. So an unknown attribute key is a 400 naming the
 * facetable ones, and the facet block reports the same list.
 *
 * This is also §82's security rule: an attribute taxonomy name arriving from a
 * request is matched against the registered taxonomies here, before it reaches
 * a query, and never interpolated into one.
 */
final class AttributeCatalogue
{
    /** @var array<string, array{id: int, taxonomy: string, label: string}>|null */
    private ?array $facetable = null;

    /**
     * The registered global attributes, keyed by taxonomy name.
     *
     * @return array<string, array{id: int, taxonomy: string, label: string}>
     */
    public function facetable(): array
    {
        if ($this->facetable !== null) {
            return $this->facetable;
        }

        $facetable = [];

        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $taxonomy = wc_attribute_taxonomy_name($attribute->attribute_name);

            // A registered row whose taxonomy is not actually registered would
            // pass validation and then match nothing.
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $facetable[$taxonomy] = [
                'id' => (int) $attribute->attribute_id,
                'taxonomy' => $taxonomy,
                'label' => (string) ($attribute->attribute_label ?: $attribute->attribute_name),
            ];
        }

        return $this->facetable = $facetable;
    }

    /** @return list<string> */
    public function taxonomies(): array
    {
        return array_keys($this->facetable());
    }

    /**
     * Turn the request's attribute keys into taxonomy names, or refuse.
     *
     * `pa_size` and the bare `size` both resolve, because a storefront that
     * holds the attribute's slug should not have to know WooCommerce's prefix.
     * Anything else is a 400 — never a silent empty result.
     *
     * @param array<string, list<string>> $raw
     * @return array<string, list<string>>
     *
     * @throws ApiException
     */
    public function resolveFilterKeys(array $raw): array
    {
        if ($raw === []) {
            return [];
        }

        $facetable = $this->facetable();
        $resolved = [];
        $unknown = [];

        foreach ($raw as $key => $values) {
            $taxonomy = $this->resolve($key);

            if ($taxonomy === null) {
                $unknown[] = $key;
                continue;
            }

            // Two keys naming one taxonomy (`size` and `pa_size`) would
            // otherwise produce two AND-ed clauses that can only match nothing.
            $resolved[$taxonomy] = array_values(array_unique(
                array_merge($resolved[$taxonomy] ?? [], $values)
            ));
        }

        if ($unknown !== []) {
            throw ApiException::invalidRequest('The filter is invalid.', [
                'fields' => [
                    'attributes' => sprintf(
                        'No global attribute named %s. Only a global attribute — a registered, '
                        . 'shared list of terms — can be filtered or counted; an attribute typed '
                        . 'directly onto a single product cannot, because it has no shared vocabulary. '
                        . 'Make it a global attribute to filter on it.',
                        implode(', ', array_map(static fn (string $k): string => "\"{$k}\"", $unknown))
                    ),
                ],
                'facetable_attributes' => array_keys($facetable),
            ]);
        }

        return $resolved;
    }

    /** The taxonomy a request key names, or null. */
    public function resolve(string $key): ?string
    {
        $facetable = $this->facetable();
        $key = strtolower(trim($key));

        if (isset($facetable[$key])) {
            return $key;
        }

        $prefixed = wc_attribute_taxonomy_name($key);

        return $prefixed !== '' && isset($facetable[$prefixed]) ? $prefixed : null;
    }

    public function label(string $taxonomy): string
    {
        return $this->facetable()[$taxonomy]['label'] ?? $taxonomy;
    }

    /**
     * Every term of one attribute, keyed by term id.
     *
     * The facet response comes back from WooCommerce as bare term ids, so this
     * is what turns `{"term": 23, "count": 2}` into something a storefront can
     * render — and what says which taxonomy a flat count belongs to.
     *
     * @return array<int, WP_Term>
     */
    public function terms(string $taxonomy): array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);

        if (!is_array($terms)) {
            return [];
        }

        $byId = [];

        foreach ($terms as $term) {
            if ($term instanceof WP_Term) {
                $byId[(int) $term->term_id] = $term;
            }
        }

        return $byId;
    }
}
