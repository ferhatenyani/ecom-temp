<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;

/**
 * The catalogue narrowing half of `GET /products` — roadmap §82.
 *
 * Pure: no WordPress. It parses and validates the *shape* of every filter — a
 * price is numeric and ordered, a term list is positive integers, a facet group
 * is one of six names — and stops there. Deciding whether `pa_size` is a
 * taxonomy this shop registers needs the database, so it belongs to
 * `AttributeCatalogue`, exactly as `AttributeInput` leaves term resolution to
 * `ProductRepository`.
 *
 * Every value here arrives from a browser. The route's own args carry the
 * enums, minimums and maximums WordPress can enforce (with the explicit
 * `validate_callback` CLAUDE.md requires beside every `sanitize_callback`);
 * this class carries the rules a per-arg schema cannot state — that `min_price`
 * may not exceed `max_price`, that `attributes` is a map of lists, and that a
 * comma-separated id list contains only ids.
 */
final class ProductFilters
{
    /**
     * The facet groups a caller may ask for.
     *
     * `rating` is included because WooCommerce computes it for free in the same
     * call; nothing in this API creates a review, so on a shop with none it
     * reports an empty list rather than being absent.
     */
    public const FACET_GROUPS = ['attributes', 'price', 'category', 'tag', 'stock_status', 'rating'];

    /** Values reported per facet group before the list is truncated — §82. */
    public const MAX_FACET_VALUES = 50;

    private function __construct(
        public readonly ?string $minPrice,
        public readonly ?string $maxPrice,
        /** @var array<string, list<string>> attribute key (or taxonomy) => term slugs */
        public readonly array $attributes,
        /** @var list<int> */
        public readonly array $categories,
        /** @var list<int> */
        public readonly array $tags,
        public readonly ?string $stockStatus,
        public readonly ?bool $onSale,
        public readonly ?bool $featured,
        public readonly ?float $ratingMin,
        /** @var list<string> */
        public readonly array $facets
    ) {
    }

    /** An unfiltered catalogue — the default for every caller that sends none. */
    public static function none(): self
    {
        return new self(null, null, [], [], [], null, null, null, null, []);
    }

    /**
     * Order a facet group's values and cap the list — §82's third refusal.
     *
     * "No unbounded facet list. Cap the values returned per group and report
     * that the list was truncated, per §66's rule that a bounded result which
     * does not say it is bounded reads as a complete one." A shop with two
     * thousand colour terms would otherwise put all of them in the response
     * body of every listing that asked for a colour facet, and a storefront
     * showing the first fifty would have no way to know there were more.
     *
     * Pure, and separate from the resolver that calls it, because the cap
     * cannot be reached through the API without inventing fifty-one terms that
     * each have a product — so a unit test is the only place it can actually be
     * exercised rather than assumed.
     *
     * @param list<array{term_id: int, slug: string, name: string, count: int}> $values
     * @return array{values: list<array<string, mixed>>, total_values: int, truncated: bool}
     */
    public static function capFacetValues(array $values): array
    {
        usort(
            $values,
            static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name'])
        );

        return [
            'values' => array_slice($values, 0, self::MAX_FACET_VALUES),
            'total_values' => count($values),
            'truncated' => count($values) > self::MAX_FACET_VALUES,
        ];
    }

    /**
     * @param array<string, mixed> $params the request's query parameters
     *
     * @throws ApiException
     */
    public static function fromParams(array $params): self
    {
        $errors = [];

        $minPrice = self::price($params['min_price'] ?? null, 'min_price', $errors);
        $maxPrice = self::price($params['max_price'] ?? null, 'max_price', $errors);

        /*
         * The one cross-field rule, and the reason it cannot live in the arg
         * schema: a range whose ends are the wrong way round matches nothing,
         * which is indistinguishable from a shop that sells nothing in that
         * band. Refusing it says which of the two happened.
         */
        if ($minPrice !== null && $maxPrice !== null && (float) $minPrice > (float) $maxPrice) {
            $errors['max_price'] = 'Must not be lower than min_price.';
        }

        $filters = new self(
            $minPrice,
            $maxPrice,
            self::attributes($params['attributes'] ?? null, $errors),
            self::ids($params['category'] ?? null, 'category', $errors),
            self::ids($params['tag'] ?? null, 'tag', $errors),
            self::stockStatus($params['stock_status'] ?? null, $errors),
            self::bool($params['on_sale'] ?? null),
            self::bool($params['featured'] ?? null),
            self::rating($params['rating_min'] ?? null, $errors),
            self::facets($params['facets'] ?? null, $errors)
        );

        if ($errors !== []) {
            throw ApiException::invalidRequest('The filter is invalid.', ['fields' => $errors]);
        }

        return $filters;
    }

    /**
     * The same filters with attribute keys replaced by resolved taxonomy names.
     *
     * `AttributeCatalogue` produces the map; this keeps the object immutable so
     * the listing query and the facet query are demonstrably given the same
     * filter set rather than two objects that have to be kept in step.
     *
     * @param array<string, list<string>> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            $this->minPrice,
            $this->maxPrice,
            $attributes,
            $this->categories,
            $this->tags,
            $this->stockStatus,
            $this->onSale,
            $this->featured,
            $this->ratingMin,
            $this->facets
        );
    }

    public function wantsFacets(): bool
    {
        return $this->facets !== [];
    }

    public function wants(string $group): bool
    {
        return in_array($group, $this->facets, true);
    }

    /** Whether anything at all narrows the catalogue. */
    public function isEmpty(): bool
    {
        return $this->minPrice === null
            && $this->maxPrice === null
            && $this->attributes === []
            && $this->categories === []
            && $this->tags === []
            && $this->stockStatus === null
            && $this->onSale === null
            && $this->featured === null
            && $this->ratingMin === null;
    }

    /** @param array<string, string> $errors */
    private static function price(mixed $raw, string $field, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            $errors[$field] = 'Must be a number.';

            return null;
        }

        if ((float) $raw < 0) {
            $errors[$field] = 'Must not be negative.';

            return null;
        }

        // Kept as a decimal string for the same reason prices are elsewhere in
        // this API: a float is the wrong type for money.
        return (string) $raw;
    }

    /**
     * `attributes[pa_size]=m,l` — a map of attribute key to term slugs.
     *
     * The key is not resolved here and the slugs are not looked up: both need
     * the database. What is enforced is that the value is a list of non-empty
     * slugs, so nothing shaped like a query ever reaches one.
     *
     * @param array<string, string> $errors
     * @return array<string, list<string>>
     */
    private static function attributes(mixed $raw, array &$errors): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        if (!is_array($raw)) {
            $errors['attributes'] = 'Must be a map of attribute name to values, e.g. attributes[pa_size]=m,l.';

            return [];
        }

        $map = [];

        foreach ($raw as $key => $value) {
            $key = is_string($key) ? trim($key) : '';

            if ($key === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $key) !== 1) {
                $errors['attributes'] = 'Attribute names may contain only letters, numbers, hyphens and underscores.';
                continue;
            }

            $values = self::slugs($value);

            if ($values === []) {
                $errors['attributes.' . $key] = 'Needs at least one value.';
                continue;
            }

            $map[$key] = $values;
        }

        return $map;
    }

    /** @return list<string> */
    private static function slugs(mixed $value): array
    {
        $parts = is_array($value)
            ? $value
            : (is_scalar($value) ? explode(',', (string) $value) : []);

        $slugs = [];

        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }

            $part = trim((string) $part);

            if ($part !== '') {
                $slugs[] = $part;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * A repeatable term filter: `category=12` or `category=12,15`.
     *
     * §82 asks for a repeatable category; a comma list keeps the single-id form
     * every existing caller sends working unchanged.
     *
     * @param array<string, string> $errors
     * @return list<int>
     */
    private static function ids(mixed $raw, string $field, array &$errors): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($parts as $part) {
            if (!is_scalar($part) || !is_numeric(trim((string) $part)) || (int) $part < 1) {
                $errors[$field] = 'Must be a term id, or a comma-separated list of them.';

                return [];
            }

            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, string> $errors */
    private static function stockStatus(mixed $raw, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = is_scalar($raw) ? trim((string) $raw) : '';

        if (!in_array($value, ProductInput::STOCK_STATUSES, true)) {
            $errors['stock_status'] = 'Must be one of: ' . implode(', ', ProductInput::STOCK_STATUSES) . '.';

            return null;
        }

        return $value;
    }

    /** @param array<string, string> $errors */
    private static function rating(mixed $raw, array &$errors): ?float
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_scalar($raw) || !is_numeric((string) $raw)) {
            $errors['rating_min'] = 'Must be a number.';

            return null;
        }

        $value = (float) $raw;

        if ($value < 0 || $value > 5) {
            $errors['rating_min'] = 'Must be between 0 and 5.';

            return null;
        }

        return $value;
    }

    /**
     * Facets are opt-in, because a facet query costs more than the listing it
     * decorates and the admin app — most of this route's traffic — never wants
     * one (§82).
     *
     * @param array<string, string> $errors
     * @return list<string>
     */
    private static function facets(mixed $raw, array &$errors): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return [];
        }

        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $groups = [];

        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }

            $part = trim((string) $part);

            if ($part === '') {
                continue;
            }

            if (!in_array($part, self::FACET_GROUPS, true)) {
                $errors['facets'] = "Unknown facet group \"{$part}\". Known groups: "
                    . implode(', ', self::FACET_GROUPS) . '.';

                return [];
            }

            $groups[] = $part;
        }

        return array_values(array_unique($groups));
    }

    private static function bool(mixed $raw): ?bool
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
