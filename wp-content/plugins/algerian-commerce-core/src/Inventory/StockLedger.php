<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

use AlgerianCommerce\Core\Logger;
use Throwable;
use WC_Product;

/**
 * Writes to the stock ledger.
 *
 * Injected wherever a quantity can change — the inventory service, and the
 * product and variation services, whose write endpoints accept a
 * `stock_quantity`. Without that second set of callers the history would have
 * holes it could not explain: a movement's quantity_before would not match the
 * previous row's quantity_after, and nobody could say why.
 *
 * Sits alongside AuditLogger rather than inside it. The two answer different
 * questions — *what happened to the stock* against *who did something* — and
 * a manual adjustment writes to both.
 */
final class StockLedger
{
    public function __construct(
        private readonly MovementRepository $repository,
        private readonly Logger $logger
    ) {
    }

    /**
     * @return int|null the ledger row id, or null when the write failed
     */
    public function record(InventoryMovement $movement): ?int
    {
        try {
            $id = $this->repository->insert($movement);

            if ($id === null) {
                $this->logger->error('Inventory movement write failed', [
                    'product_id' => $movement->productId,
                    'reason' => $movement->reason,
                    'delta' => $movement->delta,
                ]);
            }

            return $id;
        } catch (Throwable $throwable) {
            /*
             * The stock has already moved by the time we get here — there is
             * no transaction spanning WooCommerce's write and ours, so there
             * is nothing to roll back. Failing the request would report an
             * error for a change that did happen, which is worse than a gap in
             * the ledger. It is logged loudly instead, and the health endpoint
             * surfaces a broken database separately.
             */
            $this->logger->error('Inventory movement write threw', [
                'product_id' => $movement->productId,
                'reason' => $movement->reason,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record a quantity changed through a product or variation write endpoint.
     *
     * Does nothing when the quantity did not move, or when the product stopped
     * managing stock — there is no quantity left to record, and the audit trail
     * already carries the settings change.
     */
    public function recordProductEdit(WC_Product $product, int|float|null $before, int|float|null $after): ?int
    {
        if ($after === null) {
            return null;
        }

        /*
         * Only a product managing its *own* stock has a quantity of its own.
         * WooCommerce reports a variation that inherits its parent's stock as
         * manage_stock === 'parent', and get_stock_quantity() then returns the
         * parent's figure — so creating such a variation against a parent
         * holding 50 units would otherwise write a phantom movement of +50
         * against the parent, which never moved at all.
         */
        if ($product->get_manage_stock() !== true) {
            return null;
        }

        $before = (int) ($before ?? 0);
        $after = (int) $after;

        if ($before === $after) {
            return null;
        }

        return $this->record(new InventoryMovement(
            $this->stockManagedId($product),
            $after - $before,
            $before,
            $after,
            MovementReason::PRODUCT_EDIT,
            '',
            0,
            $this->currentUserId()
        ));
    }

    /**
     * Record stock that moved because an order changed status.
     *
     * `$before` and `$after` are WooCommerce's own figures, read from the hook
     * that fires immediately after it wrote the new quantity, so they describe
     * the row as it actually stands rather than what the caller expected.
     *
     * Deliberately without the `get_manage_stock() !== true` guard that
     * recordProductEdit() carries. WooCommerce only reaches these hooks for a
     * product whose `managing_stock()` is true, and for a variation inheriting
     * its parent's stock that is true while `get_manage_stock()` returns
     * 'parent' — the same case the other guard exists to *exclude*. Here the
     * stock genuinely moved, on the parent, and stockManagedId() names it.
     *
     * Writes no audit row: order-driven movements are the shop working, not a
     * person changing a quantity, and the status change that caused them is
     * already audited (roadmap §49).
     */
    public function recordOrderMovement(
        WC_Product $product,
        int $before,
        int $after,
        string $reason,
        int $orderId
    ): ?int {
        if ($before === $after) {
            return null;
        }

        return $this->record(new InventoryMovement(
            $this->stockManagedId($product),
            $after - $before,
            $before,
            $after,
            $reason,
            '',
            $orderId,
            $this->currentUserId()
        ));
    }

    /**
     * The id whose stock actually moved.
     *
     * A variation that inherits stock management from its parent has no stock
     * row of its own — WooCommerce decrements the parent. Recording the
     * variation id there would produce a ledger that never balances against
     * the quantity anyone can read back.
     */
    public function stockManagedId(WC_Product $product): int
    {
        $id = (int) $product->get_stock_managed_by_id();

        return $id > 0 ? $id : (int) $product->get_id();
    }

    private function currentUserId(): int
    {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }
}
