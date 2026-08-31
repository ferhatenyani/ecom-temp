<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use WP_Error;
use WP_Term;

/**
 * The WooCommerce adapter for global attributes — roadmap §88.
 *
 * Everything here goes through `wc_create_attribute()`, `wc_update_attribute()`
 * and `wc_delete_attribute()` rather than through `$wpdb`, and that is not
 * merely the layering rule. `wc_update_attribute()` delegates to
 * `wc_create_attribute()` with an `old_slug`, and a rename then migrates the
 * `term_taxonomy` rows, every product's `_product_attributes` meta, every
 * variation's `attribute_pa_*` meta key, the term-order meta and the in-request
 * globals. Writing that table directly would rename the attribute and silently
 * orphan every product on it.
 */
final class AttributeRepository
{
    /** @return list<object> rows as `wc_get_attribute_taxonomies()` returns them */
    public function all(): array
    {
        return array_values(wc_get_attribute_taxonomies());
    }

    public function find(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        foreach ($this->all() as $attribute) {
            if ((int) $attribute->attribute_id === $id) {
                return $attribute;
            }
        }

        return null;
    }

    public function taxonomyFor(object $attribute): string
    {
        $slug = (string) ($attribute->attribute_name ?? '');

        return $slug === '' ? '' : wc_attribute_taxonomy_name($slug);
    }

    /**
     * The slug this payload actually states, or null when it leaves one to be
     * derived — the one fact `fromWpError()` needs and cannot recover.
     *
     * `AttributeService::create()` always passes a `slug` key and passes `''`
     * for an omitted one, which is exactly what `wc_create_attribute()` reads as
     * "derive it from the name" (`empty( $args['slug'] )`). So the empty string
     * is not a stated slug and must not be reported as one.
     *
     * @param array<string, mixed> $args
     */
    private static function statedSlug(array $args): ?string
    {
        $slug = isset($args['slug']) && is_scalar($args['slug']) ? (string) $args['slug'] : '';

        return $slug === '' ? null : $slug;
    }

    /** @param array<string, mixed> $args */
    public function create(array $args): int
    {
        $id = wc_create_attribute($args);

        if ($id instanceof WP_Error) {
            throw self::fromWpError($id, self::statedSlug($args));
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $args
     *
     * A slug refusal on an update is always about a *stated* slug, and the
     * `statedSlug()` call still runs rather than being short-circuited to
     * `'slug'`. `wc_update_attribute()` back-fills every argument the caller
     * omitted from the stored attribute — `$args['slug'] = $args['slug'] ??
     * $attribute->slug`, wc-attribute-functions.php:711 — so an update that
     * changes only the name re-checks the *existing* slug, which by definition
     * already passed. A refusal there would be WooCommerce disagreeing with
     * itself about a stored value, and pointing at `name` is then the honest
     * answer: it is the only thing this request changed.
     */
    public function update(int $id, array $args): int
    {
        $result = wc_update_attribute($id, $args + ['id' => $id]);

        if ($result instanceof WP_Error) {
            throw self::fromWpError($result, self::statedSlug($args));
        }

        return (int) $result;
    }

    /**
     * `wc_delete_attribute()` removes the row **and every term on it**, and
     * does not touch the `_product_attributes` meta of products that used it.
     * That is why `AttributeService::guardNotInUse()` exists.
     */
    public function delete(int $id): bool
    {
        return wc_delete_attribute($id) === true;
    }

    /**
     * Make a just-created attribute usable for the rest of *this* request.
     *
     * **WooCommerce does not do this, including in its own REST controller** —
     * measured against WooCommerce 11.0.1. `wc_create_attribute()` writes the
     * row, clears the `wc_attribute_taxonomies` transient and schedules a
     * rewrite flush; the taxonomy itself is registered on `init` by
     * `WC_Post_Types::register_taxonomies()`, which already ran. So for the
     * remainder of the request the attribute exists in the database and
     * `taxonomy_exists()` is false, `wp_insert_term()` fails, and §82's facet
     * counter skips it — the trap CLAUDE.md records against §82's fixtures.
     *
     * Re-running WooCommerce's registration is not available: it returns early
     * on `taxonomy_exists('product_type')`, so a second call is a no-op. So the
     * taxonomy is registered here with the **minimum that makes the write path
     * work**, deliberately not a copy of WooCommerce's sixty lines of labels,
     * rewrite rules and capabilities — that would be forking their data model
     * to drift on the next upgrade. This registration lives for one request and
     * WooCommerce's own supersedes it on the next one.
     *
     * `update_count_callback` is the one argument that is load-bearing rather
     * than incidental: it maintains `$term->count`, which the term delete guard
     * reads.
     *
     * The `$wc_product_attributes` global matters just as much as the
     * registration. `taxonomy_is_product_attribute()` checks both, and §82's
     * `ProductCollectionData` skips any taxonomy failing it — so without this
     * line the facet counter answers 200 with an empty list.
     */
    public function registerForRequest(int $id): bool
    {
        $attribute = $this->find($id);

        if ($attribute === null) {
            return false;
        }

        $taxonomy = $this->taxonomyFor($attribute);

        if ($taxonomy === '') {
            return false;
        }

        global $wc_product_attributes;

        if (!is_array($wc_product_attributes)) {
            $wc_product_attributes = [];
        }

        $wc_product_attributes[$taxonomy] = $attribute;

        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, ['product'], [
                'hierarchical' => false,
                'update_count_callback' => '_update_post_term_count',
                'show_ui' => false,
                'show_in_menu' => false,
                'meta_box_cb' => false,
                'query_var' => false,
                'rewrite' => false,
                'public' => false,
                'sort' => false,
            ]);
        }

        return taxonomy_exists($taxonomy);
    }

    /**
     * How many products carry a term of this attribute, and a few of their ids.
     *
     * `wc_get_products()` with a `tax_query` is §82's measured-good path — the
     * same measurement found `meta_query` and `attribute` silently ignored. The
     * status list is explicit because a draft product using the attribute is
     * still a product that would break.
     *
     * **Guarded on `taxonomy_exists()` and the guard is load-bearing**: a
     * `tax_query` naming an unregistered taxonomy does not error, it produces a
     * clause WP_Query drops, and the query then returns *every* product. A
     * false positive here refuses a legitimate delete forever.
     *
     * @return array{total: int, ids: list<int>}
     */
    public function productUsage(string $taxonomy, int $sample = 5): array
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return ['total' => 0, 'ids' => []];
        }

        $result = wc_get_products([
            'limit' => max(1, $sample),
            'paginate' => true,
            'return' => 'ids',
            'status' => ['publish', 'draft', 'pending', 'private'],
            'tax_query' => [[
                'taxonomy' => $taxonomy,
                'operator' => 'EXISTS',
            ]],
        ]);

        return [
            'total' => (int) ($result->total ?? 0),
            'ids' => array_map('intval', (array) ($result->products ?? [])),
        ];
    }

    public function termCount(string $taxonomy): int
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return 0;
        }

        return (int) wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    }

    /**
     * @param array{page: int, per_page: int, search: string, orderby: string, order: string, hide_empty: bool} $criteria
     * @return array{items: list<WP_Term>, total: int}
     */
    public function terms(string $taxonomy, array $criteria): array
    {
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return ['items' => [], 'total' => 0];
        }

        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => $criteria['hide_empty'],
            'orderby' => $criteria['orderby'],
            'order' => strtoupper($criteria['order']) === 'DESC' ? 'DESC' : 'ASC',
        ];

        if ($criteria['search'] !== '') {
            $args['search'] = $criteria['search'];
        }

        $total = (int) wp_count_terms($args + ['fields' => 'count']);

        $terms = get_terms($args + [
            'number' => $criteria['per_page'],
            'offset' => max(0, ($criteria['page'] - 1) * $criteria['per_page']),
        ]);

        $items = [];

        foreach (is_array($terms) ? $terms : [] as $term) {
            if ($term instanceof WP_Term) {
                $items[] = $term;
            }
        }

        return ['items' => $items, 'total' => $total];
    }

    public function findTerm(string $taxonomy, int $termId): ?WP_Term
    {
        if ($taxonomy === '' || $termId <= 0 || !taxonomy_exists($taxonomy)) {
            return null;
        }

        $term = get_term($termId, $taxonomy);

        return $term instanceof WP_Term ? $term : null;
    }

    public function termSlugExists(string $taxonomy, string $slug, int $ignoreId = 0): bool
    {
        $term = get_term_by('slug', $slug, $taxonomy);

        return $term instanceof WP_Term && (int) $term->term_id !== $ignoreId;
    }

    public function createTerm(string $taxonomy, AttributeTermInput $input): WP_Term
    {
        $args = [];

        foreach (['slug' => 'slug', 'description' => 'description'] as $field => $key) {
            if ($input->has($field)) {
                $args[$key] = (string) $input->get($field);
            }
        }

        $result = wp_insert_term((string) $input->get('name'), $taxonomy, $args);

        if ($result instanceof WP_Error) {
            // `$args['slug']` is present only when the input stated one — the
            // loop above copies the field rather than defaulting it — so this
            // is the same "stated, or derived from the name" fact the attribute
            // writes pass, arrived at from the other direction.
            throw self::fromWpError($result, self::statedSlug($args));
        }

        $termId = (int) ($result['term_id'] ?? 0);

        $this->applyMenuOrder($termId, $taxonomy, $input);

        $term = $this->findTerm($taxonomy, $termId);

        if ($term === null) {
            throw ApiException::internal('The term was created but could not be read back.');
        }

        return $term;
    }

    public function updateTerm(string $taxonomy, WP_Term $term, AttributeTermInput $input): WP_Term
    {
        $args = [];

        foreach (['name', 'slug', 'description'] as $field) {
            if ($input->has($field)) {
                $args[$field] = (string) $input->get($field);
            }
        }

        if ($args !== []) {
            $result = wp_update_term((int) $term->term_id, $taxonomy, $args);

            if ($result instanceof WP_Error) {
                throw self::fromWpError($result, self::statedSlug($args));
            }
        }

        $this->applyMenuOrder((int) $term->term_id, $taxonomy, $input);

        return $this->findTerm($taxonomy, (int) $term->term_id) ?? $term;
    }

    public function deleteTerm(string $taxonomy, int $termId): bool
    {
        $result = wp_delete_term($termId, $taxonomy);

        return $result === true;
    }

    private function applyMenuOrder(int $termId, string $taxonomy, AttributeTermInput $input): void
    {
        if ($input->has('menu_order')) {
            update_term_meta($termId, 'order_' . $taxonomy, (int) $input->get('menu_order'));
        }
    }

    /**
     * The codes whose refusal is about the **name**, whichever call produced
     * them.
     *
     * `missing_attribute_name` is `wc_create_attribute()`'s
     * (wc-attribute-functions.php:520, 11.0.1); the other three are
     * `wp_insert_term()`'s and `wp_update_term()`'s (taxonomy.php:2452, 2456,
     * 2486, 3266). None is reachable through this API today — both input
     * classes require a non-empty name before the repository is called — and
     * they are mapped anyway, because the alternative is that the day one
     * becomes reachable it arrives under a key no control has, which is the
     * whole defect this table exists to close.
     *
     * @var list<string>
     */
    private const NAME_CODES = [
        'missing_attribute_name',
        'invalid_term_id',
        'empty_term_name',
        'invalid_term_name',
    ];

    /**
     * The codes about a slug, which is the field the caller may or may not have
     * stated — see `slugField()`.
     *
     * Both are `wc_create_attribute()`'s, and `wc_update_attribute()` reaches
     * them by delegating to it with an `old_slug`
     * (wc-attribute-functions.php:535, 547, 729).
     *
     * @var list<string>
     */
    private const SLUG_CODES = [
        'invalid_product_attribute_slug_too_long',
        'invalid_product_attribute_slug_reserved_name',
    ];

    /**
     * A refusal WooCommerce or WordPress raised, keyed so a form can find it.
     *
     * ## The defect this replaces, because it was not a small one
     *
     * Every non-conflict `WP_Error` used to be filed under
     * `details.fields.attribute` — one literal string, for all of them. **No
     * form control has or could have that key.** `GlobalAttributeInput` accepts
     * `name`, `slug`, `type`, `order_by` and `has_archives`, `AttributeTermInput`
     * accepts `name`, `slug`, `description` and `menu_order`, and neither
     * accepts an `attribute`; so a screen binding `details.fields` to its inputs
     * by key — which is what every write screen in the panel does, and what this
     * API's own envelope invites by keying that way everywhere else — rendered
     * **nothing at all** for the two most likely slug failures a real shop
     * meets: a derived slug over the byte budget, and a reserved word like
     * `type` typed as an attribute label.
     *
     * The old docblock's reasoning was right about the *code* and wrong about
     * what followed from it. A client must not match on
     * `invalid_product_attribute_slug_reserved_name`, because that couples it to
     * WooCommerce's internals through our envelope — that stands, and no code
     * reaches a response body here. What did not follow is that the *field* was
     * therefore unknowable. It is knowable: the code says which field, and this
     * class knows whether the caller stated a slug or left one to be derived.
     * Translating a code into a field name is exactly the adapter's job, and
     * declining to do it pushed a WooCommerce sentence onto a screen with
     * nothing to attach it to.
     *
     * ## Three answers, not one
     *
     *  - **A conflict**, for the duplicates. Still a 409 rather than a 400: the
     *    payload is well formed and the state is what refuses it — the rule
     *    `docs/API.md` states and `ProductService` already follows for a
     *    duplicate SKU. **No `fields` key**, because `fields` is this API's
     *    validation channel and every 409 in this plugin keeps out of it; what a
     *    409 carries instead is the offending *value* at the top of `details`,
     *    the shape `ProductService` uses for `['sku' => …]` and
     *    `AttributeService::createTerm()` already uses for `['slug' => …]` on
     *    the duplicate it catches itself. That consistency is the point: the
     *    panel meets one duplicate-slug refusal, not two shapes of it, whether
     *    the service caught it or WooCommerce did.
     *  - **A 400 naming a field**, for the refusals that are about one. Same
     *    envelope as `GlobalAttributeInput`'s and `AttributeTermInput`'s own
     *    validation errors, so a form cannot tell WooCommerce's refusal from the
     *    plugin's and does not have to.
     *  - **A 400 naming nothing**, for the rest — `cannot_create_attribute`,
     *    `cannot_update_attribute`, `invalid_taxonomy`, `db_insert_error`,
     *    `missing_parent`. These are a failed write, an unregistered taxonomy or
     *    a field this API does not accept, and **an orphan summary line is
     *    honest where an invented key is not**: a screen renders the sentence
     *    above the form, which is where a person can read it, instead of
     *    reddening a box whose value had nothing to do with it. The sentence
     *    moves into the *message* rather than being dropped, because it is now
     *    the only thing carrying the reason.
     *
     * ## `term_exists` carries the id, and that is borrowed
     *
     * `wp_insert_term()` puts the colliding term's id in the error's data
     * (taxonomy.php:2571, 2574), and `CmsRepository::createFaqCategory()`
     * already reads it out that way for the same refusal on FAQ categories.
     * Same idiom here, so a client that wants to offer *"open the term you
     * already have"* has the id rather than a name to search for.
     *
     * Note what it is about: `term_exists` fires on the **name**, not the slug —
     * WordPress's own message says so — while `duplicate_term_slug` is the slug
     * one. They are two refusals and they are kept apart.
     *
     * @param ?string $statedSlug the slug the caller actually sent, or null when
     *                            it was left to be derived from the name
     */
    private static function fromWpError(WP_Error $error, ?string $statedSlug): ApiException
    {
        $code = (string) $error->get_error_code();
        $message = wp_strip_all_tags((string) $error->get_error_message());

        if ($code === 'term_exists') {
            $termId = (int) $error->get_error_data('term_exists');

            return ApiException::conflict(
                $message === '' ? 'A term with that name already exists.' : $message,
                $termId > 0 ? ['term_id' => $termId] : []
            );
        }

        /*
         * The substring test survives beside the two exact codes deliberately.
         * It is what classified these before, and dropping it would mean a
         * future WooCommerce code ending `_already_exists` silently became a
         * 400 — the status regressing quietly is worse than the field being
         * generic, and the field is the thing this method was rewritten for.
         */
        if ($code === 'invalid_product_attribute_slug_already_exists'
            || $code === 'duplicate_term_slug'
            || str_contains($code, 'already_exists')
        ) {
            return ApiException::conflict(
                $message === '' ? 'That slug is already in use.' : $message,
                $statedSlug === null ? [] : ['slug' => $statedSlug]
            );
        }

        $field = null;

        if (in_array($code, self::SLUG_CODES, true)) {
            $field = self::slugField($statedSlug);
        } elseif (in_array($code, self::NAME_CODES, true)) {
            $field = 'name';
        }

        if ($field === null) {
            return ApiException::invalidRequest(
                $message === '' ? 'WooCommerce refused the write.' : $message
            );
        }

        return ApiException::invalidRequest('The attribute data is invalid.', [
            'fields' => [$field => $message === '' ? 'WooCommerce refused the write.' : $message],
        ]);
    }

    /**
     * Which control a slug refusal belongs to, which is not always the slug.
     *
     * `wc_create_attribute()` derives the slug from the *name* when the payload
     * states none — `wc_sanitize_taxonomy_name( $args['name'] )`,
     * wc-attribute-functions.php:525 — and both slug refusals are raised against
     * the derived value. So a shop that types a long French label and leaves the
     * slug box empty is told *"Slug … is too long"* about a string it never
     * wrote, and reddening the empty box it did not fill would say nothing about
     * what to change. The name is the field that produced it, and it is the
     * field that can fix it.
     *
     * Stating a slug is the other case, and then it is straightforwardly the
     * slug: `GlobalAttributeInput` caps a *stated* slug at 29 bytes itself, so
     * a stated slug reaching WooCommerce's own length check has to have grown
     * inside `wc_sanitize_taxonomy_name()`, and the reserved-word check has no
     * length involved at all — `type`, the one a real shop reaches, is four
     * bytes.
     *
     * The same rule covers terms without a second thought: `wp_insert_term()`
     * derives a slug from the name the same way, so "the field the caller
     * stated, or the one it was derived from" is one sentence for four call
     * sites.
     */
    private static function slugField(?string $statedSlug): string
    {
        return $statedSlug === null ? 'name' : 'slug';
    }
}
