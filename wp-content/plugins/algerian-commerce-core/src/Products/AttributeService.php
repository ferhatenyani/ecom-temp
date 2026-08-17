<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WP_Term;

/**
 * Global attribute rules — roadmap §88.
 *
 * `ac_manage_products` and **no new capability**: an attribute is part of the
 * catalogue, and the same capability already writes products, variations and
 * the attribute *assignments* on them. Inventing `ac_manage_attributes` would
 * mean a Product Manager who can build a variable product and cannot create the
 * attribute it varies on. §61's media gap set the precedent for naming a gap
 * rather than inventing a capability; this is the case where no gap exists.
 *
 * §82 is what makes this section necessary rather than convenient. **Only a
 * global attribute can be filtered or counted**, so a shop with none has a
 * faceted search that can never return a facet — and until now the only way to
 * create one was wp-admin, the dashboard PLAN §52 says routine administration
 * must not require.
 *
 * Two guards carry the weight, and they are the same guard at two grains:
 * deleting an attribute, or a term, detaches every product using it and leaves
 * no error anywhere.
 */
final class AttributeService
{
    /** How many product ids a 409 names. Enough to investigate, not a dump. */
    private const SAMPLE = 5;

    public function __construct(
        private readonly AttributeRepository $repository,
        private readonly AttributeCatalogue $catalogue,
        private readonly AuditLogger $audit
    ) {
    }

    /** @return list<object> */
    public function list(): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        return $this->repository->all();
    }

    public function get(int $id): object
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        return $this->require($id);
    }

    /** @return array{products: int, terms: int} */
    public function usage(object $attribute): array
    {
        $taxonomy = $this->repository->taxonomyFor($attribute);

        return [
            'products' => $this->repository->productUsage($taxonomy, 1)['total'],
            'terms' => $this->repository->termCount($taxonomy),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{attribute: object, registered: bool}
     */
    public function create(array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $input = GlobalAttributeInput::forCreate($payload);
        $this->guardType($input);

        $id = $this->repository->create([
            'name' => (string) $input->get('name'),
            'slug' => (string) ($input->get('slug') ?? ''),
            'type' => (string) ($input->get('type') ?? 'select'),
            'order_by' => (string) ($input->get('order_by') ?? 'menu_order'),
            'has_archives' => (bool) ($input->get('has_archives') ?? false),
        ]);

        /*
         * Both caches, and both matter. WooCommerce's own transient is cleared
         * by `wc_create_attribute()`; `AttributeCatalogue` memoises per
         * instance and is a singleton, so §82's filters would spend the rest of
         * the request insisting the attribute it just created does not exist.
         * That is §83's `ProductRepository`/`OptionSetRepository` bug in a new
         * place: a memoised document outliving the write that changed it.
         */
        $this->catalogue->forget();

        $registered = $this->repository->registerForRequest($id);

        $attribute = $this->require($id);

        $this->audit->record('attribute.created', 'attribute', $id, [
            'slug' => (string) $attribute->attribute_name,
            'taxonomy' => $this->repository->taxonomyFor($attribute),
        ]);

        return ['attribute' => $attribute, 'registered' => $registered];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{attribute: object, slug_changed: bool}
     */
    public function update(int $id, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $existing = $this->require($id);
        $input = GlobalAttributeInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $this->guardType($input);

        $before = (string) $existing->attribute_name;

        $args = [];

        foreach (['name', 'slug', 'type', 'order_by', 'has_archives'] as $field) {
            if ($input->has($field)) {
                $args[$field] = $input->get($field);
            }
        }

        $this->repository->update($id, $args);
        $this->catalogue->forget();

        $attribute = $this->require($id);
        $after = (string) $attribute->attribute_name;
        $slugChanged = $before !== $after;

        /*
         * The slug is recorded by value, not as a field name. §71's rule keeps
         * values out of the trail because a trade-register number is a secret;
         * a taxonomy slug is a public identifier that every saved filter and
         * every storefront link is built on, so "the slug changed" without
         * saying to what is a row nobody can act on. §87's role change made the
         * same call for the same reason.
         */
        $this->audit->record('attribute.updated', 'attribute', $id, [
            'fields' => array_keys($input->fields),
            'slug_from' => $slugChanged ? $before : null,
            'slug_to' => $slugChanged ? $after : null,
        ]);

        return ['attribute' => $attribute, 'slug_changed' => $slugChanged];
    }

    /** @return array{products: int} what the delete detached */
    public function delete(int $id, bool $force): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $attribute = $this->require($id);
        $taxonomy = $this->repository->taxonomyFor($attribute);

        $usage = $this->repository->productUsage($taxonomy, self::SAMPLE);

        if (!$force) {
            $this->guardNotInUse($usage, $taxonomy);
        }

        if (!$this->repository->delete($id)) {
            throw ApiException::internal('The attribute could not be deleted.');
        }

        $this->catalogue->forget();

        $this->audit->record('attribute.deleted', 'attribute', $id, [
            'slug' => (string) $attribute->attribute_name,
            'taxonomy' => $taxonomy,
            // The count that was overridden, so a shop can find out later why a
            // product lost an attribute nobody remembers removing.
            'products_detached' => $usage['total'],
            'forced' => $force,
        ]);

        return ['products' => $usage['total']];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WP_Term>, total: int}
     */
    public function terms(int $id, array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $attribute = $this->require($id);

        return $this->repository->terms($this->repository->taxonomyFor($attribute), $criteria);
    }

    /** @param array<string, mixed> $payload */
    public function createTerm(int $id, array $payload): WP_Term
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $attribute = $this->require($id);
        $taxonomy = $this->requireRegistered($attribute);
        $input = AttributeTermInput::forCreate($payload);

        if ($input->has('slug')
            && $this->repository->termSlugExists($taxonomy, (string) $input->get('slug'))
        ) {
            throw ApiException::conflict('That slug is already used by another term of this attribute.', [
                'slug' => (string) $input->get('slug'),
            ]);
        }

        $term = $this->repository->createTerm($taxonomy, $input);

        $this->audit->record('attribute.term_created', 'attribute', $id, [
            'taxonomy' => $taxonomy,
            'term_id' => (int) $term->term_id,
            'slug' => (string) $term->slug,
        ]);

        return $term;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{term: WP_Term, slug_changed: bool}
     */
    public function updateTerm(int $id, int $termId, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $attribute = $this->require($id);
        $taxonomy = $this->requireRegistered($attribute);
        $term = $this->requireTerm($taxonomy, $termId);

        $input = AttributeTermInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        if ($input->has('slug')
            && $this->repository->termSlugExists($taxonomy, (string) $input->get('slug'), $termId)
        ) {
            throw ApiException::conflict('That slug is already used by another term of this attribute.', [
                'slug' => (string) $input->get('slug'),
            ]);
        }

        $before = (string) $term->slug;
        $updated = $this->repository->updateTerm($taxonomy, $term, $input);
        $after = (string) $updated->slug;
        $slugChanged = $before !== $after;

        $this->audit->record('attribute.term_updated', 'attribute', $id, [
            'taxonomy' => $taxonomy,
            'term_id' => $termId,
            'fields' => array_keys($input->fields),
            'slug_from' => $slugChanged ? $before : null,
            'slug_to' => $slugChanged ? $after : null,
        ]);

        return ['term' => $updated, 'slug_changed' => $slugChanged];
    }

    /** @return array{products: int} */
    public function deleteTerm(int $id, int $termId, bool $force): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $attribute = $this->require($id);
        $taxonomy = $this->requireRegistered($attribute);
        $term = $this->requireTerm($taxonomy, $termId);

        $count = (int) $term->count;

        /*
         * The attribute guard at term grain, and it is the more likely of the
         * two to be hit. Deleting "M" from Taille detaches every product on it
         * and — worse — leaves any variation that resolved through it matching
         * nothing, a failure with no error and a long delay between cause and
         * symptom. §88 states the rule for the attribute; the argument does not
         * get weaker one level down.
         */
        if (!$force && $count > 0) {
            throw ApiException::conflict(
                sprintf(
                    '%d product(s) use this term. Deleting it detaches them and breaks any variation that resolved through it. Re-tag them first, or repeat with ?force=true.',
                    $count
                ),
                ['products' => $count, 'term_id' => $termId]
            );
        }

        if (!$this->repository->deleteTerm($taxonomy, $termId)) {
            throw ApiException::internal('The term could not be deleted.');
        }

        $this->audit->record('attribute.term_deleted', 'attribute', $id, [
            'taxonomy' => $taxonomy,
            'term_id' => $termId,
            'slug' => (string) $term->slug,
            'products_detached' => $count,
            'forced' => $force,
        ]);

        return ['products' => $count];
    }

    private function require(int $id): object
    {
        $attribute = $this->repository->find($id);

        if ($attribute === null) {
            throw ApiException::notFound('No attribute with that id.');
        }

        return $attribute;
    }

    private function requireTerm(string $taxonomy, int $termId): WP_Term
    {
        $term = $this->repository->findTerm($taxonomy, $termId);

        if ($term === null) {
            throw ApiException::notFound('No term with that id on this attribute.');
        }

        return $term;
    }

    /**
     * An attribute whose taxonomy is not registered has no term storage.
     *
     * On an ordinary request this cannot happen — WooCommerce registers every
     * attribute taxonomy on `init`. It happens in exactly one place: the same
     * request that created the attribute, where `registerForRequest()` is what
     * fixes it. Calling it here rather than trusting the create path means a
     * client that creates an attribute and immediately adds terms works, and a
     * fixture that does the same in one process works too.
     */
    private function requireRegistered(object $attribute): string
    {
        $taxonomy = $this->repository->taxonomyFor($attribute);

        if ($taxonomy === '') {
            throw ApiException::internal('That attribute has no taxonomy name.');
        }

        if (!taxonomy_exists($taxonomy)) {
            $this->repository->registerForRequest((int) $attribute->attribute_id);
        }

        if (!taxonomy_exists($taxonomy)) {
            throw ApiException::internal('That attribute\'s taxonomy could not be registered.');
        }

        return $taxonomy;
    }

    /**
     * Which types exist is `wc_get_attribute_types()`, a filtered list a plugin
     * can extend — so the vocabulary lives with the platform and the shape
     * check lives in `GlobalAttributeInput`. Naming the available types is what
     * turns a refusal into something a caller can act on, which is §82's rule
     * about `facetable_attributes` applied to a different field.
     */
    private function guardType(GlobalAttributeInput $input): void
    {
        if (!$input->has('type')) {
            return;
        }

        $types = array_keys(wc_get_attribute_types());

        if (!in_array((string) $input->get('type'), $types, true)) {
            throw ApiException::invalidRequest('The attribute data is invalid.', [
                'fields' => ['type' => 'Must be one of: ' . implode(', ', $types) . '.'],
                'available_types' => $types,
            ]);
        }
    }

    /**
     * @param array{total: int, ids: list<int>} $usage
     */
    private function guardNotInUse(array $usage, string $taxonomy): void
    {
        if ($usage['total'] === 0) {
            return;
        }

        throw ApiException::conflict(
            sprintf(
                '%d product(s) use this attribute. Deleting it removes every term and leaves those products referencing an attribute that no longer exists. Repeat with ?force=true to delete anyway.',
                $usage['total']
            ),
            [
                'products' => $usage['total'],
                'product_ids' => $usage['ids'],
                'taxonomy' => $taxonomy,
            ]
        );
    }
}
