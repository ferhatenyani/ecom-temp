<?php

declare(strict_types=1);

namespace AlgerianCommerce\Inventory;

/**
 * Why a stock movement happened.
 *
 * A closed vocabulary rather than free text, because the ledger exists to be
 * grouped and summed: "how much did we write off to damage last month" is a
 * GROUP BY, and it stops working the moment one operator types "Damaged" and
 * another types "damage".
 *
 * The split between manual and system reasons is a security boundary, not
 * bookkeeping. If the adjustment endpoint accepted `order_reduced`, a person
 * could write ledger rows that look as though an order caused them, and the
 * history would no longer distinguish what the shop did from what a human did.
 */
final class MovementReason
{
    /** Reasons a human may supply through the inventory endpoints. */
    public const CORRECTION = 'correction';
    public const RESTOCK = 'restock';
    public const DAMAGE = 'damage';
    public const LOSS = 'loss';
    public const CUSTOMER_RETURN = 'customer_return';
    public const OTHER = 'other';

    /** Reasons only this plugin writes. */
    public const PRODUCT_EDIT = 'product_edit';
    public const ORDER_REDUCED = 'order_reduced';
    public const ORDER_RESTORED = 'order_restored';

    /** @var list<string> */
    public const MANUAL = [
        self::CORRECTION,
        self::RESTOCK,
        self::DAMAGE,
        self::LOSS,
        self::CUSTOMER_RETURN,
        self::OTHER,
    ];

    /**
     * Written by the plugin itself.
     *
     * `product_edit` covers a quantity changed through the product or
     * variation write endpoints — without it the ledger would have gaps it
     * could not explain. The two order reasons are written by
     * Orders\OrderStockSubscriber, from WooCommerce's own stock hooks, so they
     * cover every order-driven movement rather than only the ones this API
     * caused.
     *
     * @var list<string>
     */
    public const SYSTEM = [
        self::PRODUCT_EDIT,
        self::ORDER_REDUCED,
        self::ORDER_RESTORED,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::MANUAL, ...self::SYSTEM];
    }

    public static function isManual(string $reason): bool
    {
        return in_array($reason, self::MANUAL, true);
    }

    public static function isKnown(string $reason): bool
    {
        return in_array($reason, self::all(), true);
    }
}
