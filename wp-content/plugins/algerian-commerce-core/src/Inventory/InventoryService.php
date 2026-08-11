<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Product;

/**
 * Inventory business rules — roadmap §49, docs/PLAN.md §7.
 *
 * The rule that shapes everything else: a quantity changes only through
 * adjust(), and every adjustment writes a ledger row. Settings — whether stock
 * is tracked, the backorder policy, the low-stock threshold — change through
 * updateSettings(), which writes no ledger row because no units moved.
 *
 * A *manual* adjustment writes to both tables: a movement (what happened to
 * the stock) and an audit event (a person changed stock). They answer
 * different questions and have different retention policies.
 *
 * Authorization is asserted here as well as on the route. The route guard
 * stops an unauthenticated caller; this one holds when a WP-CLI command, cron
 * job or another service calls in without passing through REST
 * (docs/SECURITY.md, "Authorization").
 */
final class InventoryService
{
    public function __construct(
        private readonly InventoryRepository $repository,
        private readonly MovementRepository $movementRepository,
        private readonly StockLedger $ledger,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: list<WC_Product>, total: int}
     */
    public function list(array $criteria): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        return $this->repository->paginate($criteria);
    }

    public function get(int $id): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        return $this->requireProduct($id);
    }

    /**
     * Exact SKU lookup — the barcode-scanner path.
     *
     * Separate from a list filter because the caller wants the item or a 404,
     * not a collection they have to unwrap and check the length of.
     */
    public function getBySku(string $sku): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        $product = $this->repository->findBySku($sku);

        if ($product === null) {
            throw ApiException::notFound('No product with that SKU.');
        }

        return $product;
    }

    /**
     * @param list<string> $statuses
     * @return array{items: list<WC_Product>, total: int}
     */
    public function lowStock(int $page, int $perPage, array $statuses): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        return $this->repository->lowStock($page, $perPage, $statuses);
    }

    /**
     * Apply a manual adjustment.
     *
     * @param array<string, mixed> $payload
     * @return array{product: WC_Product, movement: InventoryMovement}
     */
    public function adjust(int $id, array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        $product = $this->requireProduct($id);
        $adjustment = StockAdjustment::fromPayload($payload);

        $this->guardManagesStock($product);

        $before = (int) ($product->get_stock_quantity() ?? 0);
        $this->guardBackorders($product, $adjustment, $before);

        $after = $this->repository->applyStock($product, $adjustment->mode, $adjustment->quantity);

        /*
         * quantity_after is what WooCommerce reported, and quantity_before is
         * derived from it rather than from our earlier read. A concurrent
         * adjustment can land between the two, and a row pairing a stale
         * before with a fresh after would not balance.
         */
        $movement = InventoryMovement::fromDelta(
            $this->ledger->stockManagedId($product),
            $adjustment->deltaFor($before, $after),
            $after,
            $adjustment->reason,
            $adjustment->note,
            0,
            $this->currentUserId()
        );

        $this->ledger->record($movement);

        $this->audit->record('inventory.adjusted', 'product', $id, [
            'mode' => $adjustment->mode,
            'quantity' => $adjustment->quantity,
            'reason' => $adjustment->reason,
            'note' => $adjustment->note,
            'stock_managed_by_id' => $movement->productId,
            'before' => $movement->quantityBefore,
            'after' => $movement->quantityAfter,
        ]);

        return [
            'product' => $this->repository->find($id) ?? $product,
            'movement' => $movement,
        ];
    }

    /**
     * Change stock settings. Never moves a quantity, so never writes a ledger
     * row — InventorySettingsInput rejects `stock_quantity` outright.
     *
     * @param array<string, mixed> $payload
     */
    public function updateSettings(int $id, array $payload): WC_Product
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        $product = $this->requireProduct($id);
        $input = InventorySettingsInput::fromPayload($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.');
        }

        $before = [
            'manage_stock' => $product->get_manage_stock(),
            'stock_status' => $product->get_stock_status(),
            'backorders' => $product->get_backorders(),
            'low_stock_amount' => $product->get_low_stock_amount(),
        ];

        // Captured before the save: saveSettings() mutates this very object
        // before persisting it, so reading the quantity afterwards would
        // return the new value under the name of the old one.
        $quantityBefore = $product->get_stock_quantity();

        $updated = $this->repository->saveSettings($product, $input);

        /*
         * Turning stock management on gives a product a quantity it did not
         * have — WooCommerce starts it at 0. That is a real opening balance,
         * so it belongs in the ledger even though the caller adjusted no units.
         */
        $this->ledger->recordProductEdit(
            $updated,
            $quantityBefore,
            $updated->get_stock_quantity()
        );

        $this->audit->record('inventory.settings_updated', 'product', $id, [
            'fields' => array_keys($input->fields),
            'before' => $before,
            'after' => [
                'manage_stock' => $updated->get_manage_stock(),
                'stock_status' => $updated->get_stock_status(),
                'backorders' => $updated->get_backorders(),
                'low_stock_amount' => $updated->get_low_stock_amount(),
            ],
        ]);

        return $updated;
    }

    /**
     * Apply a batch of adjustments, one at a time.
     *
     * Each item runs the ordinary single-item path, so validation,
     * authorization, the ledger and audit are inherited rather than
     * duplicated. A failure is recorded against its item and the batch
     * continues: a stocktake should not abandon 99 correct lines because the
     * 40th names a product that no longer manages stock.
     *
     * @return array{results: list<array<string, mixed>>, succeeded: int, failed: int}
     */
    public function bulk(BulkStockRequest $request): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        $results = [];
        $succeeded = 0;

        foreach ($request->items as $item) {
            $id = $item['id'];

            try {
                $outcome = $this->adjust($id, $item['payload']);
                $movement = $outcome['movement'];

                $results[] = [
                    'id' => $id,
                    'success' => true,
                    'delta' => $movement->delta,
                    'stock_quantity' => $movement->quantityAfter,
                ];
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

        $this->audit->record('inventory.bulk_adjusted', 'product', '', [
            'total' => $request->count(),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'ids' => array_column($results, 'id'),
        ]);

        return ['results' => $results, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function movements(array $filters, int $page, int $perPage): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        return [
            'items' => $this->movementRepository->paginate($filters, $page, $perPage),
            'total' => $this->movementRepository->count($filters),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, array{net: int, movements: int}>
     */
    public function movementSummary(array $filters): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        return $this->movementRepository->summariseByReason($filters);
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
     * wc_update_product_stock() silently does nothing for a product that is
     * not managing stock, so without this the API would answer 200 to an
     * adjustment that changed nothing. It is a conflict rather than a
     * validation error: the payload is fine, the product is in the wrong state.
     */
    private function guardManagesStock(WC_Product $product): void
    {
        if ($product->managing_stock()) {
            return;
        }

        throw ApiException::conflict(
            'This product does not manage stock. Enable manage_stock before adjusting a quantity.',
            ['id' => $product->get_id(), 'manage_stock' => $product->get_manage_stock()]
        );
    }

    /**
     * Refuse to drive stock below zero unless the product takes backorders.
     *
     * WooCommerce itself permits negative stock — that is how a backordered
     * line is represented. Allowing it by accident on a product that does not
     * accept backorders turns a typo into a shop overselling goods it has not
     * got.
     */
    private function guardBackorders(WC_Product $product, StockAdjustment $adjustment, int $current): void
    {
        $projected = $adjustment->project($current);

        if ($projected >= 0 || $product->backorders_allowed()) {
            return;
        }

        throw ApiException::conflict(
            'That adjustment would take stock below zero, and this product does not allow backorders.',
            [
                'stock_quantity' => $current,
                'projected' => $projected,
                'backorders' => $product->get_backorders(),
            ]
        );
    }

    private function currentUserId(): int
    {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }
}
