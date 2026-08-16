<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * The vocabulary — docs/PLAN.md §29's six kinds and §30's transactional list.
 *
 * A closed set, because a typo in an event name is otherwise a notification
 * that is queued, deduplicated against nothing and never recognised by a
 * template. `OrderStatus` and `ShipmentStatus` make the same argument.
 */
final class NotificationEvent
{
    // §30's transactional messages, in its order.
    public const ORDER_PLACED = 'order.placed';
    public const PAYMENT_RECEIVED = 'payment.received';
    public const SHIPMENT_SHIPPED = 'shipment.shipped';
    public const SHIPMENT_DELIVERED = 'shipment.delivered';
    public const ORDER_CANCELLED = 'order.cancelled';
    public const ORDER_REFUNDED = 'order.refunded';

    // §29's admin and stock notifications.
    public const STOCK_LOW = 'stock.low';
    public const ADMIN_NEW_ORDER = 'admin.new_order';

    /** @var list<string> */
    public const ALL = [
        self::ORDER_PLACED,
        self::PAYMENT_RECEIVED,
        self::SHIPMENT_SHIPPED,
        self::SHIPMENT_DELIVERED,
        self::ORDER_CANCELLED,
        self::ORDER_REFUNDED,
        self::STOCK_LOW,
        self::ADMIN_NEW_ORDER,
    ];

    /**
     * Which of these go to the shop rather than to a customer.
     *
     * Kept here rather than decided at each call site, because getting it wrong
     * in one place sends a customer an operational alert about their own order,
     * or sends the shop's stock warning to a shopper.
     *
     * @var list<string>
     */
    public const ADMIN_EVENTS = [
        self::STOCK_LOW,
        self::ADMIN_NEW_ORDER,
    ];

    public static function isKnown(string $event): bool
    {
        return in_array($event, self::ALL, true);
    }

    public static function isAdmin(string $event): bool
    {
        return in_array($event, self::ADMIN_EVENTS, true);
    }

    /**
     * Password reset is **not** here, and its absence is the record.
     *
     * §30 lists it, and the token half is trivial. The delivery half is what is
     * missing: this queue drains on a WP-CLI command and a cron, which is right
     * for a shipment update and wrong for a password reset — a shopper who has
     * asked to sign in will not wait five minutes for the link. Building it
     * would mean an inline send on that one path, with the SMTP timeout on a
     * user-facing request that §29's whole queue exists to avoid. It belongs
     * with a synchronous mail path, which this project does not have yet.
     */
    private function __construct()
    {
    }
}
