<?php

declare(strict_types=1);

namespace AlgerianCommerce\Products;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Inventory\StockLedger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Data_Exception;
use WC_Product;

/**
 * Product business rules.
 *
 * Authorization is asserted here as well as on the route. The route guard
 * stops an unauthenticated caller; this one holds even when a future WP-CLI
 * command, cron job or internal service calls in without passing through
 * REST (docs/SECURITY.md, "Authorization").
 */
final class ProductService
{
    public function __construct(
        private readonly ProductRepository $repository,
        private readonly AuditLogger $audit,
        /**
         * This endpoint accepts a stock_quantity, so it is one of the places a
         * quantity can change. Every such place writes to the ledger, or the
         * inventory history has gaps where a movement's quantity_before does
         * not match the previous row's quantity_after and nobody can say why
         * (roadmap §49).
         */
        private readonly StockLedger $ledger,
        private readonly AttributeCatalogue $attributes,
        private readonly FacetResolver $facets
    ) {
    }

    /**
     * The catalogue, narrowed and optionally counted — roadmap §47, §82.
     *
     * Attribute keys are resolved **once**, here, and the resolved filter set
     * is handed to both the listing query and the facet query. Two resolutions
     * would be two chances to disagree, and a facet block that counted a
     * different selection from the rows beside it is worse than none — §82's
     * whole point is that a facet is only useful if its counts describe the
     * result set the shopper is looking at.
     *
     * The refusal for an unknown attribute therefore happens before either
     * query runs, which is also §82's security rule: a taxonomy name from a
     * request is matched against the registered ones and never interpolated.
     *
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Product>, total: int, facets?: array<string, mixed>}
     */
    public function list(array $criteria, ?ProductFilters $filters = null): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $filters ??= ProductFilters::none();
        $filters = $filters->withAttributes($this->attributes->resolveFilterKeys($filters->attributes));

        $result = $this->repository->paginate($criteria, $filters);

        if ($filters->wantsFacets()) {
            $result['facets'] = $this->facets->resolve($filters, (string) ($criteria['search'] ?? ''));
        }

        return $result;
    }

    public function get(int $id): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        return $this->requireProduct($id);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $input = ProductInput::forCreate($payload);

        $this->guardSku((string) ($input->get('sku') ?? ''));

        $product = $this->save(fn (): WC_Product => $this->repository->create($input));

        // A product created with stock opens the ledger at that quantity, so
        // the history starts where the shelf did rather than at its first edit.
        $this->ledger->recordProductEdit($product, 0, $product->get_stock_quantity());

        $this->audit->record('product.created', 'product', $product->get_id(), [
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'status' => $product->get_status(),
        ]);

        return $product;
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $product = $this->requireProduct($id);
        $input = ProductInput::forUpdate($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        if ($input->has('sku')) {
            $this->guardSku((string) $input->get('sku'), $id);
        }

        $this->guardSalePriceAgainstStored($product, $input);

        $before = [
            'name' => $product->get_name(),
            'regular_price' => $product->get_regular_price(),
            'status' => $product->get_status(),
        ];

        // Read before the write: the repository mutates this object in place,
        // so afterwards it already carries the new quantity.
        $quantityBefore = $product->get_stock_quantity();

        $updated = $this->save(fn (): WC_Product => $this->repository->update($product, $input));

        $this->ledger->recordProductEdit($updated, $quantityBefore, $updated->get_stock_quantity());

        $this->audit->record('product.updated', 'product', $id, [
            'fields' => array_keys($input->fields),
            'before' => $before,
            'after' => [
                'name' => $updated->get_name(),
                'regular_price' => $updated->get_regular_price(),
                'status' => $updated->get_status(),
            ],
        ]);

        /*
         * Re-read, because the saved object is not always what was stored.
         *
         * Measured 2026-08-18: `PATCH /products/{id}` with an `attributes` list
         * that drops a variable product's variation attribute answered **500,
         * `Call to a member function is_taxonomy() on null`**, from
         * `ProductPresenter::attributes()`. `WC_Product_Variable::save()` sets
         * the dropped key to `null` in the in-memory attribute array rather than
         * unsetting it — variations are re-synced against those keys — so the
         * object handed back carries `['finition' => null, 'pa_couleur' => …]`
         * while a fresh read carries only the second. The presenter iterated the
         * first and fataled on the null.
         *
         * Skipping nulls in the presenter would have silenced this one symptom
         * and left the cause: the write response would still be a partly stale
         * object, and `docs/API.md`'s whole "a read body can be written back"
         * contract rests on the response being what a subsequent GET returns.
         * A panel that PATCHes the whole object and reseeds its form from the
         * response — which is what Part V's product form does — would otherwise
         * be editing a product that no longer matches the shop.
         *
         * `wc_get_product()` reads WooCommerce's own object cache here, so this
         * is not a second round trip to the database in the common case.
         */
        return $this->requireProduct($id);
    }

    public function delete(int $id, bool $force): void
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $product = $this->requireProduct($id);

        $name = $product->get_name();
        $sku = $product->get_sku();

        if (!$this->repository->delete($product, $force)) {
            throw ApiException::internal('The product could not be deleted.');
        }

        $this->audit->record($force ? 'product.deleted' : 'product.trashed', 'product', $id, [
            'name' => $name,
            'sku' => $sku,
            'permanent' => $force,
        ]);
    }

    public function duplicate(int $id): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $source = $this->requireProduct($id);
        $copy = $this->repository->duplicate($source);

        $this->audit->record('product.duplicated', 'product', $copy->get_id(), [
            'source_id' => $id,
            'source_name' => $source->get_name(),
            'variations_copied' => count($copy->get_children()),
        ]);

        return $copy;
    }

    /**
     * Apply an action to a batch, one item at a time.
     *
     * Each item goes through the ordinary single-item path, so validation,
     * authorization and audit are inherited rather than duplicated. A failure
     * is recorded against its item and the batch continues: a bulk price
     * update should not abandon 99 good products because the 40th has a
     * duplicate SKU.
     *
     * The response is 200 with a per-item result list rather than 207. Partial
     * success is the *expected* outcome here, not an exceptional one, and the
     * caller must read the per-item results in either case.
     *
     * @return array{results: list<array<string, mixed>>, succeeded: int, failed: int}
     */
    public function bulk(BulkRequest $request): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        $results = [];
        $succeeded = 0;

        foreach ($request->items as $item) {
            $id = (int) $item['id'];

            try {
                if ($request->action === 'delete') {
                    $this->delete($id, $request->force);
                } else {
                    $fields = $item;
                    unset($fields['id']);
                    $this->update($id, $fields);
                }

                $results[] = ['id' => $id, 'success' => true];
                $succeeded++;
            } catch (ApiException $exception) {
                $results[] = [
                    'id' => $id,
                    'success' => false,
                    'error' => $exception->toPayload()['error'],
                ];
            }
        }

        $failed = count($results) - $succeeded;

        $this->audit->record('product.bulk_' . $request->action, 'product', '', [
            'total' => $request->count(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'ids' => array_column($results, 'id'),
        ]);

        return ['results' => $results, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    private function requireProduct(int $id): WC_Product
    {
        $product = $this->repository->find($id);

        if ($product === null) {
            throw ApiException::notFound('No product with that id.');
        }

        return $product;
    }

    /**
     * A duplicate SKU is a conflict, not a validation error — the payload is
     * well formed, the catalogue simply already contains it.
     *
     * A **trashed** product still holds its SKU, and says so, because
     * "already in use" about a product that is not in the catalogue any more is
     * the sort of answer that costs somebody an afternoon. See
     * `ProductRepository::skuExists()` for why the trash has to be looked in at
     * all: WooCommerce refuses the insert either way, and without this the
     * refusal arrives as a 500 from inside `save()`.
     */
    private function guardSku(string $sku, int $ignoreId = 0): void
    {
        if ($sku === '' || !$this->repository->skuExists($sku, $ignoreId)) {
            return;
        }

        $trashed = $this->repository->trashedSkuOwner($sku, $ignoreId);

        if ($trashed > 0) {
            throw ApiException::conflict(
                'That SKU belongs to a product in the trash.',
                ['sku' => $sku, 'trashed_product_id' => $trashed]
            );
        }

        throw ApiException::conflict('That SKU is already in use.', ['sku' => $sku]);
    }

    /**
     * ProductInput compares the two prices when both arrive together. A PATCH
     * that sends only sale_price has to be checked against what is stored,
     * or a product can be put on "sale" above its own price.
     */
    private function guardSalePriceAgainstStored(WC_Product $product, ProductInput $input): void
    {
        if (!$input->has('sale_price') || $input->has('regular_price')) {
            return;
        }

        $sale = (string) $input->get('sale_price');
        $regular = (string) $product->get_regular_price();

        if ($sale === '' || $regular === '') {
            return;
        }

        if ((float) $sale > (float) $regular) {
            throw ApiException::invalidRequest('The product data is invalid.', [
                'fields' => ['sale_price' => 'Cannot be higher than the regular price.'],
            ]);
        }
    }

    /**
     * WooCommerce throws WC_Data_Exception for things it validates itself,
     * such as an invalid SKU. Translating it keeps the error envelope
     * consistent instead of surfacing a raw exception as a 500.
     *
     * @param callable(): WC_Product $operation
     */
    private function save(callable $operation): WC_Product
    {
        try {
            return $operation();
        } catch (WC_Data_Exception $exception) {
            throw ApiException::invalidRequest($exception->getMessage());
        }
    }
}
