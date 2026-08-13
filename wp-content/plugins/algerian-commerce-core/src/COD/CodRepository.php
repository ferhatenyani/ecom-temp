<?php

declare(strict_types=1);

namespace AlgerianCommerce\COD;

use AlgerianCommerce\Commerce\PaymentMethod;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderStatus;
use WC_Order;

/**
 * The WooCommerce adapter for cash-on-delivery state.
 *
 * The only place in this module that knows WC_Order exists. It reads and writes
 * order meta through the CRUD layer — never `get_post_meta()` and never `$wpdb`
 * against `wp_posts` — which is what makes the plugin's HPOS declaration true
 * for this domain as well as for orders.
 *
 * Orders are found through OrderRepository rather than `wc_get_order()` here,
 * so the rule that a refund is not an order lives in exactly one file.
 *
 * **What counts as a COD order.** Two things, and they have to agree or the API
 * contradicts itself:
 *
 *   flagged     `_ac_cod_enabled` is '1' — something happened to this order and
 *               we wrote the state down
 *   untouched   the order was paid `cod` and this module has never written to
 *               it, so CodState derives `enabled: true` on read
 *
 * `GET /orders/{id}/cod` reports the second group as COD orders, so the funnel
 * has to count them too — otherwise an order the API calls COD is missing from
 * the statistics that describe COD orders. Nothing is written eagerly to make
 * this go away: stamping every new order would put a row in the meta table for
 * an order nobody has done anything to yet, and it would still miss every order
 * that predates the module.
 */
final class CodRepository
{
    /**
     * WooCommerce's built-in cash-on-delivery gateway id.
     *
     * Defined in `Commerce/` because Shipping needs the same value to work out
     * what a driver must collect at the door, and one string with two owners
     * eventually becomes two strings.
     */
    public const PAYMENT_METHOD = PaymentMethod::COD;

    /** @var list<string> */
    private const META_KEYS = [
        CodState::META_ENABLED,
        CodState::META_STATUS,
        CodState::META_ATTEMPTS,
        CodState::META_CONFIRMED_AT,
        CodState::META_CANCELLED_AT,
        CodState::META_LAST_ATTEMPT_AT,
        CodState::META_REASON,
    ];

    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function find(int $id): ?WC_Order
    {
        return $this->orders->find($id);
    }

    public function state(WC_Order $order): CodState
    {
        $meta = [];

        foreach (self::META_KEYS as $key) {
            // Single, not a list: these are one-value-per-order settings, and
            // get_meta() returns '' for a key that was never written — which is
            // exactly the "never written" signal CodState::fromMeta expects.
            $meta[$key] = $order->get_meta($key, true);
        }

        return CodState::fromMeta($meta, $this->isCodPayment($order));
    }

    /**
     * Persist the state and hand back what the order now holds.
     *
     * Every key is written every time — see CodState::toMeta() on why a partial
     * write leaves timestamps contradicting the status they sit next to.
     */
    public function save(WC_Order $order, CodState $state): CodState
    {
        foreach ($state->toMeta() as $key => $value) {
            $order->update_meta_data($key, $value);
        }

        $order->save();

        return $this->state($order);
    }

    public function isCodPayment(WC_Order $order): bool
    {
        return $order->get_payment_method() === self::PAYMENT_METHOD;
    }

    /**
     * The counts CodStatistics turns into a funnel.
     *
     * Counts, not orders: every query below asks for one row and reads the
     * total off the paginated result, so this endpoint costs a fixed number of
     * `COUNT(*)`s no matter how large the order book is. Store-wide reporting
     * that needs more than this is §60's `ac_analytics_aggregates`, which
     * exists precisely so a dashboard never scans the order book.
     *
     * @param array{customer_id: int, date_from: string, date_to: string} $criteria
     * @return array{total: int, by_status: array<string, int>, ever_confirmed: int, delivered: int, returned: int}
     */
    public function counts(array $criteria): array
    {
        $byStatus = [];

        foreach (CodStatus::ALL as $status) {
            $byStatus[$status] = $this->count($criteria, [
                ['key' => CodState::META_ENABLED, 'value' => '1'],
                ['key' => CodState::META_STATUS, 'value' => $status],
            ]);
        }

        // An order paid COD that this module has never written to is awaiting
        // its first confirmation call, which is what `pending` means.
        $byStatus[CodStatus::PENDING] += $this->countUntouched($criteria);

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            /*
             * Ever confirmed, from the stamp rather than the current status, so
             * an order that was confirmed and later cancelled still counts as
             * a confirmation that happened. An untouched order has no stamp and
             * cannot have been confirmed, so this pass needs no second half.
             */
            'ever_confirmed' => $this->count($criteria, [
                ['key' => CodState::META_ENABLED, 'value' => '1'],
                ['key' => CodState::META_CONFIRMED_AT, 'value' => '', 'compare' => '!='],
            ]),
            'delivered' => $this->countByOrderStatus($criteria, OrderStatus::COMPLETED),
            'returned' => $this->countByOrderStatus($criteria, OrderStatus::REFUNDED),
        ];
    }

    /**
     * @param array{customer_id: int, date_from: string, date_to: string} $criteria
     */
    private function countByOrderStatus(array $criteria, string $orderStatus): int
    {
        return $this->count(
            $criteria,
            [['key' => CodState::META_ENABLED, 'value' => '1']],
            $orderStatus
        ) + $this->countUntouched($criteria, $orderStatus);
    }

    /**
     * COD by payment method, and never written to by this module.
     *
     * @param array{customer_id: int, date_from: string, date_to: string} $criteria
     */
    private function countUntouched(array $criteria, string $orderStatus = ''): int
    {
        return $this->count(
            $criteria,
            [['key' => CodState::META_ENABLED, 'compare' => 'NOT EXISTS']],
            $orderStatus,
            ['payment_method' => self::PAYMENT_METHOD]
        );
    }

    /**
     * @param array{customer_id: int, date_from: string, date_to: string} $criteria
     * @param list<array<string, mixed>> $metaQuery
     * @param array<string, mixed> $extra
     */
    private function count(array $criteria, array $metaQuery, string $orderStatus = '', array $extra = []): int
    {
        $args = $extra + [
            'type' => 'shop_order',
            // One row, because only the total is wanted. `paginate` is what
            // makes WooCommerce run the count query at all.
            'limit' => 1,
            'paginate' => true,
            'return' => 'ids',
            'meta_query' => $metaQuery,
        ];

        if ($orderStatus !== '') {
            $args['status'] = [$orderStatus];
        }

        if ($criteria['customer_id'] > 0) {
            $args['customer_id'] = $criteria['customer_id'];
        }

        $range = OrderRepository::dateRange($criteria['date_from'], $criteria['date_to']);

        if ($range !== '') {
            $args['date_created'] = $range;
        }

        $results = wc_get_orders($args);

        return is_object($results) ? (int) $results->total : 0;
    }
}
