<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use WP_REST_Request;
use WP_Term;

/**
 * Facet counts for `GET /products?facets=…` — roadmap §82.
 *
 * **This is an adapter over WooCommerce, and §82's step 1 is why.** That
 * section orders a measurement before a design, because the alternative is
 * forty columns of hand-written aggregate SQL beside a rollup WooCommerce
 * already ships — the mistake §64 avoided by measuring `WC_Product_CSV_Exporter`
 * rather than forking it, and the one §62b avoided over "Meta for WooCommerce".
 * §59b is the precedent *and* the warning: the Store API's cart half was
 * reusable here and its shipping and checkout halves were not, and only
 * measurement told the two apart.
 *
 * Measured on this install 2026-08-17, `is_admin()` false, against a fixture of
 * six products carrying one global attribute with three terms:
 *
 *  - `wc/store/v1/products/collection-data` answers **200** through
 *    `rest_do_request()` and accepts every filter §82 names — `min_price`,
 *    `max_price`, `attributes`, `tag`, `stock_status`, `on_sale`, `featured`,
 *    `category`, `rating`.
 *  - **It obeys §82's rule for free.** Filtered to one attribute term with
 *    `query_type => 'or'`, the counts came back 2 / 2 / 2 — that group's own
 *    filter lifted, its siblings still reporting real numbers — while
 *    `query_type => 'and'` reported only the selected term. `price_range` lifts
 *    its own `min_price`/`max_price`, `stock_status_counts` lifts its own
 *    `stock_status`, and `calculate_taxonomy_counts` lifts its own `category`.
 *    That is the one property §82 says the naive implementation gets wrong, and
 *    WooCommerce has it. **`or` is therefore not a preference; it is the rule.**
 *  - Cost: five queries and ~15 ms for four groups at once.
 *
 * Three things it does that this class has to correct:
 *
 *  - **An unknown taxonomy is a 200 with an empty list**, not an error, so
 *    `AttributeCatalogue` refuses one before the call rather than after.
 *  - **Prices are in minor units** — 59000 for 590.00 DZD — and this API
 *    publishes decimal strings everywhere else.
 *  - **It counts published products only.** Our listing shows drafts to an
 *    admin, so counts and rows can legitimately disagree; the block says so in
 *    `scope` rather than leaving somebody to discover it, which is §61's
 *    malformed-section rule.
 *
 * Nothing here loads a `WC_Product` and nothing here writes SQL.
 */
final class FacetResolver
{
    private const ROUTE = '/wc/store/v1/products/collection-data';

    public function __construct(private readonly AttributeCatalogue $attributes)
    {
    }

    /**
     * @return array<string, mixed> the `facets` block for the response meta
     */
    public function resolve(ProductFilters $filters, string $search): array
    {
        $problems = [];
        $data = $this->call($filters, $search, $problems);

        $facets = [
            /*
             * Named, not implied. WooCommerce's collection counts published,
             * catalogue-visible products; `GET /products` lists drafts as well
             * unless a status filter says otherwise, so an admin can see seven
             * rows beside a count of six. Saying which is which costs one field
             * and saves the afternoon that discovering it costs.
             */
            'scope' => 'publish',
            'scope_note' => 'Counts cover published products; drafts and pending products are not counted.',
        ];

        if ($filters->wants('price')) {
            $facets['price'] = $this->price($data['price_range'] ?? null);
        }

        if ($filters->wants('stock_status')) {
            $facets['stock_status'] = $this->stockStatus($data['stock_status_counts'] ?? null);
        }

        if ($filters->wants('rating')) {
            $facets['rating'] = $this->rating($data['rating_counts'] ?? null);
        }

        if ($filters->wants('attributes')) {
            $facets['attributes'] = $this->attributeGroups($data['attribute_counts'] ?? null);
        }

        $taxonomyCounts = $this->taxonomyCounts($data['taxonomy_counts'] ?? null);

        if ($filters->wants('category')) {
            $facets['category'] = $this->termGroup('product_cat', $taxonomyCounts);
        }

        if ($filters->wants('tag')) {
            $facets['tag'] = $this->termGroup('product_tag', $taxonomyCounts);
        }

        if ($problems !== []) {
            $facets['problems'] = $problems;
        }

        return $facets;
    }

    /**
     * @param list<string> $problems
     * @return array<string, mixed>
     */
    private function call(ProductFilters $filters, string $search, array &$problems): array
    {
        $request = new WP_REST_Request('GET', self::ROUTE);

        foreach ($this->params($filters, $search) as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);

        if ($response->is_error() || $response->get_status() !== 200) {
            /*
             * A facet block is a decoration on a listing, so a failure here
             * does not fail the listing — but it is reported rather than
             * returned as an empty group, because a facet that reads zero and a
             * facet that could not be computed look identical to a storefront
             * and only one of them is the shop's own data.
             */
            $problems[] = 'The facet counts could not be computed (WooCommerce answered '
                . $response->get_status() . ').';

            return [];
        }

        // The Store API returns objects in places; one decode gives arrays
        // throughout so nothing downstream has to know which is which.
        $data = json_decode((string) wp_json_encode($response->get_data()), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function params(ProductFilters $filters, string $search): array
    {
        $params = [];

        if ($search !== '') {
            $params['search'] = $search;
        }

        if ($filters->attributes !== []) {
            $attributes = [];

            foreach ($filters->attributes as $taxonomy => $slugs) {
                $attributes[] = ['attribute' => $taxonomy, 'slug' => $slugs, 'operator' => 'in'];
            }

            $params['attributes'] = $attributes;
            // Two different attributes narrow together; values within one are
            // alternatives. The shopper's mental model, and WooCommerce's.
            $params['attribute_relation'] = 'and';
        }

        if ($filters->categories !== []) {
            $params['category'] = implode(',', $filters->categories);
        }

        if ($filters->tags !== []) {
            $params['tag'] = implode(',', $filters->tags);
        }

        if ($filters->stockStatus !== null) {
            $params['stock_status'] = $filters->stockStatus;
        }

        if ($filters->onSale !== null) {
            $params['on_sale'] = $filters->onSale;
        }

        if ($filters->featured !== null) {
            $params['featured'] = $filters->featured;
        }

        if ($filters->minPrice !== null) {
            $params['min_price'] = (string) $this->toMinorUnits($filters->minPrice);
        }

        if ($filters->maxPrice !== null) {
            $params['max_price'] = (string) $this->toMinorUnits($filters->maxPrice);
        }

        if ($filters->ratingMin !== null) {
            /*
             * WooCommerce counts by whole star, so a minimum becomes the set of
             * stars at or above it. **The listing is finer than the count**:
             * `ProductRepository` compares `_wc_average_rating >= 4.5` exactly,
             * while the counts here are bucketed to 5. Named rather than
             * smoothed over, because they answer different questions — "which
             * products clear this bar" and "how many products sit in each
             * star" — and a fractional `rating_min` is the only input that can
             * tell them apart.
             */
            $params['rating'] = range((int) max(1, ceil($filters->ratingMin)), 5);
        }

        if ($filters->wants('price')) {
            $params['calculate_price_range'] = true;
        }

        if ($filters->wants('stock_status')) {
            $params['calculate_stock_status_counts'] = true;
        }

        if ($filters->wants('rating')) {
            $params['calculate_rating_counts'] = true;
        }

        if ($filters->wants('attributes')) {
            $params['calculate_attribute_counts'] = array_map(
                /*
                 * `or` is §82's "except its own" rule, and it is the whole
                 * reason this section is an adapter rather than a rewrite. With
                 * `and`, selecting `size=m` makes every sibling size read zero
                 * and the shopper's only way out of the dead end is the back
                 * button.
                 */
                static fn (string $taxonomy): array => ['taxonomy' => $taxonomy, 'query_type' => 'or'],
                $this->attributes->taxonomies()
            );
        }

        $taxonomies = [];

        if ($filters->wants('category')) {
            $taxonomies[] = 'product_cat';
        }

        if ($filters->wants('tag')) {
            $taxonomies[] = 'product_tag';
        }

        if ($taxonomies !== []) {
            $params['calculate_taxonomy_counts'] = $taxonomies;
        }

        return $params;
    }

    private function toMinorUnits(string $price): int
    {
        return (int) round((float) $price * (10 ** wc_get_price_decimals()));
    }

    /**
     * @param mixed $range
     * @return array<string, mixed>
     */
    private function price(mixed $range): array
    {
        if (!is_array($range) || !isset($range['min_price'], $range['max_price'])) {
            return ['min' => null, 'max' => null];
        }

        $minorUnit = (int) ($range['currency_minor_unit'] ?? wc_get_price_decimals());
        $divisor = 10 ** $minorUnit;

        return [
            'min' => wc_format_decimal((string) ((float) $range['min_price'] / $divisor), $minorUnit),
            'max' => wc_format_decimal((string) ((float) $range['max_price'] / $divisor), $minorUnit),
            'currency' => (string) ($range['currency_code'] ?? get_woocommerce_currency()),
        ];
    }

    /**
     * @param mixed $counts
     * @return list<array<string, mixed>>
     */
    private function stockStatus(mixed $counts): array
    {
        $rows = [];

        foreach (is_array($counts) ? $counts : [] as $row) {
            if (!is_array($row) || !isset($row['status'])) {
                continue;
            }

            $rows[] = ['value' => (string) $row['status'], 'count' => (int) ($row['count'] ?? 0)];
        }

        return $rows;
    }

    /**
     * @param mixed $counts
     * @return list<array<string, mixed>>
     */
    private function rating(mixed $counts): array
    {
        $rows = [];

        foreach (is_array($counts) ? $counts : [] as $row) {
            if (!is_array($row) || !isset($row['rating'])) {
                continue;
            }

            $rows[] = ['rating' => (int) $row['rating'], 'count' => (int) ($row['count'] ?? 0)];
        }

        return $rows;
    }

    /**
     * WooCommerce returns one flat list of `{term, count}` across every
     * taxonomy asked for, so the grouping is ours to do.
     *
     * @param mixed $counts
     * @return array<int, int> term id => count
     */
    private function taxonomyCounts(mixed $counts): array
    {
        $byTerm = [];

        foreach (is_array($counts) ? $counts : [] as $row) {
            if (is_array($row) && isset($row['term'])) {
                $byTerm[(int) $row['term']] = (int) ($row['count'] ?? 0);
            }
        }

        return $byTerm;
    }

    /**
     * Every facetable attribute with its counts.
     *
     * §82: the API reports **which attributes are facetable** rather than
     * silently omitting the rest. A shop with no global attributes therefore
     * gets an empty list and the reason attached, not a missing key.
     *
     * @param mixed $counts the flat `attribute_counts` list
     * @return array<string, mixed>
     */
    private function attributeGroups(mixed $counts): array
    {
        $byTerm = [];

        foreach (is_array($counts) ? $counts : [] as $row) {
            if (is_array($row) && isset($row['term'])) {
                $byTerm[(int) $row['term']] = (int) ($row['count'] ?? 0);
            }
        }

        $groups = [];

        foreach ($this->attributes->facetable() as $taxonomy => $attribute) {
            $groups[] = $this->termGroup($taxonomy, $byTerm) + [
                'taxonomy' => $taxonomy,
                'label' => $attribute['label'],
            ];
        }

        return [
            'facetable' => $this->attributes->taxonomies(),
            'note' => 'Only global attributes can be filtered or counted. An attribute typed '
                . 'directly onto a single product has no shared vocabulary and no term to count.',
            'groups' => $groups,
        ];
    }

    /**
     * One taxonomy's counted terms, ordered by count and capped.
     *
     * §66's rule, which §82 restates: a bounded list that does not say it is
     * bounded reads as a complete one. A term with no products in the current
     * selection is left out — WooCommerce omits it, and the "except its own"
     * rule already guarantees that a *sibling* of the selected value reports
     * its real count rather than zero.
     *
     * @param array<int, int> $counts term id => count
     * @return array<string, mixed>
     */
    private function termGroup(string $taxonomy, array $counts): array
    {
        $terms = $this->attributes->terms($taxonomy);
        $values = [];

        foreach ($counts as $termId => $count) {
            $term = $terms[$termId] ?? null;

            if (!$term instanceof WP_Term) {
                continue;
            }

            $values[] = [
                'term_id' => $termId,
                'slug' => (string) $term->slug,
                'name' => (string) $term->name,
                'count' => $count,
            ];
        }

        return ProductFilters::capFacetValues($values);
    }
}
