<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

use AlgerianCommerce\Orders\OrderStatus;
use Automattic\WooCommerce\Utilities\OrderUtil;
use wpdb;

/**
 * The only file in this plugin that runs SQL against WooCommerce's order
 * tables, and the only one that reads `ac_shipments` in aggregate.
 *
 * **This is an explicit exception to two standing rules, and it is narrow.**
 * docs/ARCHITECTURE.md §7 says `Orders/OrderRepository` is the only file
 * allowed to touch an order at all, and `Shipping/ShipmentRepository` says the
 * same about its table. Both rules exist to stop the data model being forked and
 * to stop order storage being read in a way that silently breaks under HPOS.
 * Neither is broken here:
 *
 *  - **No order object is ever loaded.** Nothing in `Analytics/` sees a
 *    `WC_Order`, and nothing here returns one. Every method answers with
 *    scalars. Loading nine hundred order objects to add up their totals is the
 *    thing roadmap §63 forbids in so many words.
 *  - **The table name is never hard-coded.** `OrderUtil::get_table_for_orders()`
 *    is WooCommerce's own public utility for the question, so this file states
 *    no opinion about where orders live.
 *  - **A legacy install gets an error, not a zero.** See `isSupported()`.
 *  - Every query is read-only, and every aggregate lives in this one file so
 *    the SQL surface of the whole feature is reviewable in one sitting.
 *
 * The reason no WooCommerce API is used instead is that there is not one:
 * `wc_get_orders()` can count, which is why `COD\CodRepository` builds its
 * funnel out of counts, but it cannot sum, group or rank. A revenue figure
 * assembled in PHP from `wc_get_orders(['limit' => -1])` is the order-book scan
 * §63 exists to prevent.
 *
 * **Why the companion table names are built from the prefix.** Only the orders
 * table itself legitimately differs between storage modes, which is why it comes
 * from `OrderUtil`. `wc_order_operational_data` exists only under HPOS, and this
 * class only ever runs under HPOS, so `$wpdb->prefix` is exactly as sound a
 * derivation there as `OrderUtil` is for the orders table.
 */
final class AnalyticsRepository
{
    /** WooCommerce's own type for a refund row; it shares the order id space. */
    private const REFUND_TYPE = 'shop_order_refund';

    private const ORDER_TYPE = 'shop_order';

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    /**
     * Whether this install stores orders where these queries look.
     *
     * The check is not defensive politeness. Under legacy post storage the same
     * figures would need a completely different query — `wp_posts` joined to
     * `wp_postmeta` for `_order_total` — and running the HPOS query there
     * returns **zero rows and no error**, which is a dashboard reporting a
     * trading shop as having taken nothing. CLAUDE.md names that failure shape
     * as the worst possible one, and §63 answers it by refusing rather than by
     * guessing: `AnalyticsService` turns a false here into a 501 that says what
     * is wrong.
     *
     * Supporting the legacy shape as well is deliberately not done. The plugin
     * declares `custom_order_tables` compatibility, this install runs HPOS, and
     * HPOS has been WooCommerce's default for new installs since 8.2 — a second
     * set of untested queries for a storage mode nothing here runs on is the
     * speculative work the house rules forbid.
     */
    public function isSupported(): bool
    {
        return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
    }

    private function ordersTable(): string
    {
        return OrderUtil::get_table_for_orders();
    }

    private function operationalTable(): string
    {
        return $this->wpdb->prefix . 'wc_order_operational_data';
    }

    private function itemsTable(): string
    {
        return $this->wpdb->prefix . 'woocommerce_order_items';
    }

    private function itemMetaTable(): string
    {
        return $this->wpdb->prefix . 'woocommerce_order_itemmeta';
    }

    private function shipmentsTable(): string
    {
        return $this->wpdb->prefix . 'ac_shipments';
    }

    private function wilayasTable(): string
    {
        return $this->wpdb->prefix . 'ac_geo_wilayas';
    }

    /**
     * Every order in the window, grouped by status — one query behind almost
     * every figure this module reports.
     *
     * Counts, totals, tax and the guest split all fall out of a single pass, so
     * they cannot disagree with each other the way five separate queries can —
     * the argument `OrderRepository::customerOrderSummaries()` already makes for
     * one customer, applied to a window.
     *
     * The window filter is `(type, status, date_created_gmt)`, which is the
     * `type_status_date` index WooCommerce ships on this table.
     *
     * **Counts are of every order; sums are of one currency**, which is why the
     * currency appears in a `CASE` rather than in the `WHERE`. Filtering the
     * whole query would make "orders placed" report only the orders that
     * happened to be priced in the store's currency — this install has 890
     * orders in `USD` from before anyone set `DZD`, and doing it the other way
     * had the dashboard reporting 22 orders beside a COD funnel reporting 615.
     * An order count is a fact about the shop's activity; only money belongs to
     * a currency.
     *
     * @return list<array{
     *     status: string, orders: int, guest_orders: int,
     *     orders_in_currency: int, total: string, tax: string
     * }>
     */
    public function ordersByStatus(AnalyticsRange $range, string $currency, int $decimals): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT o.status AS status,
                        COUNT(*) AS orders,
                        SUM(CASE WHEN o.customer_id = 0 THEN 1 ELSE 0 END) AS guest_orders,
                        SUM(CASE WHEN o.currency = %s THEN 1 ELSE 0 END) AS orders_in_currency,
                        ROUND(SUM(CASE WHEN o.currency = %s THEN o.total_amount ELSE 0 END), %d) AS total,
                        ROUND(SUM(CASE WHEN o.currency = %s THEN o.tax_amount ELSE 0 END), %d) AS tax
                   FROM ' . $this->ordersTable() . ' o
                  WHERE o.type = %s
                    AND o.date_created_gmt >= %s
                    AND o.date_created_gmt < %s
                  GROUP BY o.status',
                $currency,
                $currency,
                $decimals,
                $currency,
                $decimals,
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                // Stored as `wc-processing`, reported everywhere else in this
                // API as `processing`.
                'status' => OrderStatus::normalize((string) $row['status']),
                'orders' => (int) $row['orders'],
                'guest_orders' => (int) $row['guest_orders'],
                'orders_in_currency' => (int) $row['orders_in_currency'],
                'total' => (string) ($row['total'] ?? '0'),
                'tax' => (string) ($row['tax'] ?? '0'),
            ];
        }

        return $out;
    }

    /**
     * How many orders in the window were taken in each currency.
     *
     * Not a curiosity. WooCommerce records the currency **per order**, so a shop
     * that traded before anyone set `woocommerce_currency` has orders in the
     * default currency forever, and every sum above filters to one currency to
     * avoid adding them together. This is what lets the response say so out
     * loud instead of quietly reporting a smaller number than the shop expects.
     *
     * @return array<string, int> currency => order count
     */
    public function ordersByCurrency(AnalyticsRange $range): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT o.currency AS currency, COUNT(*) AS orders
                   FROM ' . $this->ordersTable() . ' o
                  WHERE o.type = %s
                    AND o.date_created_gmt >= %s
                    AND o.date_created_gmt < %s
                  GROUP BY o.currency',
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $currency = strtoupper(trim((string) $row['currency']));

            if ($currency !== '') {
                $out[$currency] = (int) $row['orders'];
            }
        }

        return $out;
    }

    /**
     * Shipping charged to customers and discounts given, from HPOS's
     * operational data table.
     *
     * These two live there rather than on the order row itself, which is why
     * this is a join and not another column in the query above.
     *
     * @param list<string> $statuses
     * @return array{shipping_revenue: string, discounts: string}
     */
    public function revenueExtras(AnalyticsRange $range, array $statuses, string $currency, int $decimals): array
    {
        [$statusSql, $statusParams] = $this->statusClause($statuses);

        if ($statusSql === '') {
            return ['shipping_revenue' => '0', 'discounts' => '0'];
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT ROUND(SUM(CASE WHEN o.currency = %s THEN d.shipping_total_amount ELSE 0 END), %d)
                            AS shipping_revenue,
                        ROUND(SUM(CASE WHEN o.currency = %s THEN d.discount_total_amount ELSE 0 END), %d)
                            AS discounts
                   FROM ' . $this->ordersTable() . ' o
                   INNER JOIN ' . $this->operationalTable() . ' d ON d.order_id = o.id
                  WHERE o.type = %s
                    AND o.date_created_gmt >= %s
                    AND o.date_created_gmt < %s
                    AND ' . $statusSql,
                $currency,
                $decimals,
                $currency,
                $decimals,
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc,
                ...$statusParams
            ),
            ARRAY_A
        );

        return [
            'shipping_revenue' => (string) ($row['shipping_revenue'] ?? '0'),
            'discounts' => (string) ($row['discounts'] ?? '0'),
        ];
    }

    /**
     * Money given back against orders in the window.
     *
     * **Keyed on the parent order's date, not the refund's**, and that choice is
     * load-bearing: `net = gross − refunds` is only a true sentence when both
     * halves describe the same set of orders. A refund issued in September
     * against an August order therefore reduces August, which is also how a
     * shop reads its own books — the sale and its reversal belong to the sale.
     *
     * A refund row carries a negative `total_amount`, so the sign is flipped
     * here and every figure above the repository is positive.
     *
     * @param list<string> $statuses the parent order's statuses
     * @return array{refunds: string, refund_count: int, refunded_orders: int}
     */
    public function refunds(AnalyticsRange $range, array $statuses, string $currency, int $decimals): array
    {
        [$statusSql, $statusParams] = $this->statusClause($statuses);

        if ($statusSql === '') {
            return ['refunds' => '0', 'refund_count' => 0, 'refunded_orders' => 0];
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT ROUND(SUM(-r.total_amount), %d) AS refunds,
                        COUNT(*) AS refund_count,
                        COUNT(DISTINCT o.id) AS refunded_orders
                   FROM ' . $this->ordersTable() . ' r
                   INNER JOIN ' . $this->ordersTable() . ' o ON o.id = r.parent_order_id
                  WHERE r.type = %s
                    AND o.type = %s
                    AND o.date_created_gmt >= %s
                    AND o.date_created_gmt < %s
                    AND o.currency = %s
                    AND ' . $statusSql,
                $decimals,
                self::REFUND_TYPE,
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc,
                $currency,
                ...$statusParams
            ),
            ARRAY_A
        );

        return [
            'refunds' => (string) ($row['refunds'] ?? '0'),
            'refund_count' => (int) ($row['refund_count'] ?? 0),
            'refunded_orders' => (int) ($row['refunded_orders'] ?? 0),
        ];
    }

    /**
     * Best sellers — docs/PLAN.md §27.
     *
     * Grouped by **product**, with a variation's units folded into its parent:
     * "which product sells" is the question a buyer asks, and splitting a
     * t-shirt across five sizes answers a different one.
     *
     * The name is the one recorded on the order line rather than the product's
     * name today. It is what was actually sold, it survives the product being
     * renamed or deleted, and reading the catalogue instead would mean a
     * `WC_Product` load per row — which is `Products/ProductRepository`'s job
     * and not something a ranking query should be doing.
     *
     * The three joins onto `woocommerce_order_itemmeta` are WooCommerce's own
     * shape for line items and there is no narrower one; `meta_key` is indexed
     * and the whole thing is bounded by the window.
     *
     * Units and order counts are of every sale; only `revenue` is scoped to one
     * currency — see `ordersByStatus()` for why the two are separated. So a
     * ranking is a ranking of what sold, and cannot reorder itself because a
     * shop once took an order in another currency.
     *
     * @param list<string> $statuses
     * @return list<array{product_id: int, name: string, units: int, orders: int, revenue: string}>
     */
    public function bestSellers(
        AnalyticsRange $range,
        array $statuses,
        string $currency,
        int $decimals,
        int $limit
    ): array {
        [$statusSql, $statusParams] = $this->statusClause($statuses);

        if ($statusSql === '') {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT CAST(pid.meta_value AS UNSIGNED) AS product_id,
                        MAX(i.order_item_name) AS name,
                        SUM(CAST(qty.meta_value AS SIGNED)) AS units,
                        COUNT(DISTINCT o.id) AS orders,
                        ROUND(SUM(CASE WHEN o.currency = %s
                                       THEN CAST(line.meta_value AS DECIMAL(26,8))
                                       ELSE 0 END), %d) AS revenue
                   FROM ' . $this->ordersTable() . ' o
                   INNER JOIN ' . $this->itemsTable() . ' i
                           ON i.order_id = o.id AND i.order_item_type = %s
                   INNER JOIN ' . $this->itemMetaTable() . ' pid
                           ON pid.order_item_id = i.order_item_id AND pid.meta_key = %s
                   INNER JOIN ' . $this->itemMetaTable() . ' qty
                           ON qty.order_item_id = i.order_item_id AND qty.meta_key = %s
                   LEFT JOIN ' . $this->itemMetaTable() . ' line
                          ON line.order_item_id = i.order_item_id AND line.meta_key = %s
                  WHERE o.type = %s
                    AND o.date_created_gmt >= %s
                    AND o.date_created_gmt < %s
                    AND ' . $statusSql . '
                  GROUP BY product_id
                  ORDER BY units DESC, product_id ASC
                  LIMIT %d',
                $currency,
                $decimals,
                'line_item',
                '_product_id',
                '_qty',
                '_line_total',
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc,
                ...[...$statusParams, max(1, $limit)]
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'product_id' => (int) $row['product_id'],
                'name' => (string) $row['name'],
                'units' => (int) $row['units'],
                'orders' => (int) $row['orders'],
                'revenue' => (string) ($row['revenue'] ?? '0'),
            ];
        }

        return $out;
    }

    /**
     * Customers who ordered in the window, and how many of them were new.
     *
     * **New means the shop had never seen them before**, not "created an
     * account recently": the first order they ever placed falls inside this
     * window. That is the definition a shop can act on, and it is why the
     * correlated `MIN(date_created_gmt)` is here rather than a cheaper count.
     * It runs once per *distinct customer in the window*, not once per order,
     * and each run is an index lookup on `customer_id_status`.
     *
     * Guests are counted separately and are deliberately not folded in: an
     * order with `customer_id = 0` has no identity to be new or returning, and
     * counting each guest order as a customer would inflate both figures.
     *
     * @param list<string> $statuses
     * @return array{customers: int, new_customers: int}
     */
    public function customers(AnalyticsRange $range, array $statuses): array
    {
        [$statusSql, $statusParams] = $this->statusClause($statuses);

        if ($statusSql === '') {
            return ['customers' => 0, 'new_customers' => 0];
        }

        $orders = $this->ordersTable();

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT COUNT(*) AS customers,
                        SUM(CASE WHEN t.first_order_gmt >= %s THEN 1 ELSE 0 END) AS new_customers
                   FROM (
                        SELECT o.customer_id,
                               (SELECT MIN(p.date_created_gmt)
                                  FROM ' . $orders . ' p
                                 WHERE p.type = %s AND p.customer_id = o.customer_id) AS first_order_gmt
                          FROM ' . $orders . ' o
                         WHERE o.type = %s
                           AND o.customer_id > 0
                           AND o.date_created_gmt >= %s
                           AND o.date_created_gmt < %s
                           AND ' . $statusSql . '
                         GROUP BY o.customer_id
                   ) t',
                $range->startUtc,
                self::ORDER_TYPE,
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc,
                ...$statusParams
            ),
            ARRAY_A
        );

        return [
            'customers' => (int) ($row['customers'] ?? 0),
            'new_customers' => (int) ($row['new_customers'] ?? 0),
        ];
    }

    /**
     * Parcels created in the window, by courier and by state — roadmap §63's
     * "provider performance" and "delivery success".
     *
     * Keyed on the shipment's own `created_at`, which is indexed, because this
     * is a question about parcels rather than about orders. A parcel handed over
     * today for an order taken last week belongs to today's dispatch numbers.
     *
     * @return list<array{provider: string, status: string, shipments: int}>
     */
    public function shipmentsByProvider(AnalyticsRange $range): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT provider, status, COUNT(*) AS shipments
                   FROM ' . $this->shipmentsTable() . '
                  WHERE created_at >= %s AND created_at < %s
                  GROUP BY provider, status',
                $range->startUtc,
                $range->endUtc
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'provider' => (string) $row['provider'],
                'status' => (string) $row['status'],
                'shipments' => (int) $row['shipments'],
            ];
        }

        return $out;
    }

    /**
     * Orders and revenue by wilaya — roadmap §63.
     *
     * **The wilaya comes off the shipment, because the order does not have
     * one.** `Shipping\ShipmentInput` records why: an order's `state` and `city`
     * are free text in two languages, and turning "Ouled Fayet" into a commune
     * means fuzzy-matching a name that is spelled several ways. A shipment
     * carries the canonical `wilaya_id` from the §51 dataset because an admin
     * picked it from a dropdown. Deriving a wilaya from the address here would
     * be the guess that module refuses to make, wearing a report as a disguise.
     *
     * The consequence is stated rather than hidden: an order nobody has shipped
     * has no wilaya, and its money lands in the `unattributed` bucket the
     * service reports beside the list.
     *
     * When an order has been shipped more than once — a re-send after a failed
     * delivery, which migration 006 explicitly allows — the **latest** parcel
     * decides, and the correlated subquery is what stops the order's total being
     * counted once per parcel.
     *
     * `JSON_VALID` guards the extraction: `metadata` is a `longtext` that is
     * normally JSON, and `JSON_EXTRACT` raises on a row that is neither JSON nor
     * NULL rather than returning nothing.
     *
     * Order counts are of every order; only `revenue` is scoped to one
     * currency, as in `ordersByStatus()`.
     *
     * This is the most expensive query in the module — one index lookup per
     * order in the window — which is why the window is capped at
     * `AnalyticsRange::MAX_DAYS` and the response is cached. If any query here
     * ever earns `ac_analytics_aggregates`, it is this one.
     *
     * @param list<string> $statuses
     * @return list<array{wilaya_id: int, orders: int, revenue: string}> wilaya_id 0 means unattributed
     */
    public function ordersByWilaya(AnalyticsRange $range, array $statuses, string $currency, int $decimals): array
    {
        [$statusSql, $statusParams] = $this->statusClause($statuses);

        if ($statusSql === '') {
            return [];
        }

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT COALESCE(t.wilaya_id, 0) AS wilaya_id,
                        COUNT(*) AS orders,
                        ROUND(SUM(CASE WHEN t.currency = %s THEN t.total_amount ELSE 0 END), %d) AS revenue
                   FROM (
                        SELECT o.id,
                               o.total_amount,
                               o.currency,
                               (SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(s.metadata, \'$.wilaya_id\')) AS UNSIGNED)
                                  FROM ' . $this->shipmentsTable() . ' s
                                 WHERE s.order_id = o.id
                                   AND s.metadata IS NOT NULL
                                   AND JSON_VALID(s.metadata)
                                   AND JSON_EXTRACT(s.metadata, \'$.wilaya_id\') IS NOT NULL
                                 ORDER BY s.id DESC
                                 LIMIT 1) AS wilaya_id
                          FROM ' . $this->ordersTable() . ' o
                         WHERE o.type = %s
                           AND o.date_created_gmt >= %s
                           AND o.date_created_gmt < %s
                           AND ' . $statusSql . '
                   ) t
                  GROUP BY COALESCE(t.wilaya_id, 0)
                  ORDER BY orders DESC, wilaya_id ASC',
                $currency,
                $decimals,
                self::ORDER_TYPE,
                $range->startUtc,
                $range->endUtc,
                ...$statusParams
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'wilaya_id' => (int) $row['wilaya_id'],
                'orders' => (int) $row['orders'],
                'revenue' => (string) ($row['revenue'] ?? '0'),
            ];
        }

        return $out;
    }

    /**
     * Names for the wilayas a breakdown actually mentioned.
     *
     * A second small query rather than a fourth join: the breakdown above is
     * already the most expensive statement here, and there are 69 wilayas in
     * total — joining the geography onto an aggregate to fetch two strings is
     * work the database should not be asked to do inside it.
     *
     * @param list<int> $ids
     * @return array<int, array{code: string, name: string, name_ar: string}>
     */
    public function wilayaNames(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT id, code, name, name_ar FROM ' . $this->wilayasTable() . "
                  WHERE id IN ({$placeholders})",
                $ids
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['id']] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'name_ar' => (string) $row['name_ar'],
            ];
        }

        return $out;
    }

    /**
     * An `IN (…)` over normalized statuses, with the `wc-` prefix put back.
     *
     * The vocabulary above the repository is `processing`; the column stores
     * `wc-processing`. Translating in one place means a status list can be
     * written the way the rest of the API writes it.
     *
     * @param list<string> $statuses
     * @return array{0: string, 1: list<string>}
     */
    private function statusClause(array $statuses): array
    {
        $prefixed = [];

        foreach ($statuses as $status) {
            $normalized = OrderStatus::normalize((string) $status);

            if ($normalized !== '') {
                $prefixed[] = 'wc-' . $normalized;
            }
        }

        if ($prefixed === []) {
            return ['', []];
        }

        $placeholders = implode(', ', array_fill(0, count($prefixed), '%s'));

        return ["o.status IN ({$placeholders})", $prefixed];
    }
}
