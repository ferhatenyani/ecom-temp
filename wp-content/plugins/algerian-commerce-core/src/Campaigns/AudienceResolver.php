<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Analytics\RevenueReport;
use AlgerianCommerce\Orders\OrderStatus;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WP_User_Query;
use wpdb;

/**
 * Turns a definition into people — roadmap §85.
 *
 * **The consent filter lives here and nowhere else**, which is §85's central
 * structural requirement: "the consent filter lives in the repository that resolves
 * an audience, not in the caller." Same reason `AccountService::order()` checks
 * ownership in the service layer — a check living only in the admin app is one the
 * second client removes. Every path through this class, including an explicit list
 * of customer ids an admin typed, goes through `candidates()`, and `candidates()`
 * asks WordPress for users holding the `customer` role **and** the consent meta.
 * There is no argument that turns it off.
 *
 * ## This is the second file in the plugin that runs aggregate SQL over the order
 * tables, and the deviation is named rather than smuggled
 *
 * `Analytics/AnalyticsRepository` is described in `Plugin` as "the only place
 * aggregate SQL over the order tables is constructed", and `Tracking\TrackingService`
 * repeats that this is not a second one. This *is* a second one, and the reason is a
 * measurement rather than a preference.
 *
 * §85's criteria are per-customer aggregates: total spent, order count, last-order
 * date, whether a given product was ever bought. WooCommerce publishes no API for
 * any of them — `wc_get_orders()` can count, which is how `COD\CodRepository` builds
 * its funnel, and it cannot sum, group or rank. The alternative is one
 * `OrderRepository::customerOrderSummaries()` per candidate, which is a query per
 * customer on the request path that resolves a five-thousand-person audience.
 *
 * **And the table that would answer it exists and holds nothing.** Measured on this
 * install, 2026-08-17: `wc_customer_lookup` held **8 rows against 15 customers and
 * 302 orders**, and `wc_order_stats` and `wc_order_product_lookup` held **0**.
 * That is §63's finding again — WooCommerce Admin's rollups are filled by an Action
 * Scheduler importer, and nothing drives WP-Cron on a headless backend nobody
 * browses — and it is worse than an absent table, because reading them returns rows
 * rather than failing.
 *
 * So the same four rules that bound `AnalyticsRepository` bound this class, and they
 * are the reason it is safe to be a second exception:
 *
 *  - **No `WC_Order` is ever loaded or returned.** Every method answers with ids and
 *    scalars.
 *  - **The orders table name comes from `OrderUtil::get_table_for_orders()`**, never
 *    a literal.
 *  - **A legacy install is refused, not answered with zeros.** See `isSupported()`.
 *  - **Every query is read-only**, and every aggregate is in this one file so the
 *    SQL surface of the whole feature is reviewable in one sitting.
 *
 * ## Where the filtering happens, and why some of it is in PHP
 *
 * The candidate set is bounded by consent before any order is looked at, which is
 * usually a small fraction of the customer list. Money and count thresholds are then
 * applied in PHP to rows the aggregate query already returned, rather than in a
 * `HAVING` clause. That is deliberate: the comparison is then the same code a unit
 * test can drive, and a `HAVING` over a `SUM(CASE …)` is exactly the kind of clause
 * that is quietly wrong for a customer with no orders at all. Product membership and
 * wilaya *are* pushed into SQL, because they are set operations over id lists rather
 * than comparisons.
 */
final class AudienceResolver
{
    /**
     * A ceiling on one campaign's audience.
     *
     * Not arbitrary: the audience is frozen into `ac_campaign_recipients` on a
     * request path, and twenty thousand rows is a hundred batched inserts. Beyond
     * that a shop needs batching or a real ESP, and **the trigger for revisiting is
     * named**: when a client's customer list outgrows this, the resolve moves behind
     * the drain and `POST /campaigns/{id}/send` starts returning `queued` instead of
     * a count.
     */
    public const MAX_AUDIENCE = 20_000;

    /** The statuses whose money counts, shared with §63 rather than restated. */
    private const SPEND_STATUSES = RevenueReport::COUNTED_STATUSES;

    public function __construct(private readonly wpdb $wpdb)
    {
    }

    /**
     * Whether this install stores orders where these queries look.
     *
     * Not defensive politeness — `AnalyticsRepository::isSupported()` records the
     * whole argument. Under legacy post storage the HPOS query returns **zero rows
     * and no error**, so a segment would silently resolve to nobody and a shop would
     * conclude the feature was broken rather than the storage mode.
     */
    public function isSupported(): bool
    {
        return class_exists(OrderUtil::class) && OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Everyone this campaign should reach, frozen-ready.
     *
     * @return list<array{customer_id: int, email: string, name: string, context: array<string, string>}>
     *
     * @throws ApiException 501 on a legacy install, 409 when the audience is too big
     */
    public function resolve(Campaign $campaign, ?Segment $segment): array
    {
        $criteria = null;

        if ($campaign->audienceType === Campaign::AUDIENCE_SEGMENT) {
            if ($segment === null) {
                throw ApiException::conflict('That campaign names a segment that no longer exists.', [
                    'segment_id' => $campaign->segmentId,
                ]);
            }

            if (!$segment->isResolvable()) {
                /*
                 * A segment whose criteria document lost every criterion would
                 * otherwise resolve to *everyone*, which is the one mistake in this
                 * module that cannot be undone once the mail has gone out. "Everyone
                 * eligible" is a legitimate audience and has its own `audience_type`.
                 */
                throw ApiException::conflict('That segment has no usable criteria, so it cannot be resolved.', [
                    'segment_id' => $segment->id,
                    'problems' => $segment->problems,
                ]);
            }

            $criteria = $segment->criteria;
        }

        $ids = $this->candidates(
            $campaign->audienceType === Campaign::AUDIENCE_IDS ? $campaign->audienceIds : []
        );

        if ($criteria !== null) {
            $ids = $this->applyCriteria($ids, $criteria);
        }

        if (count($ids) > self::MAX_AUDIENCE) {
            throw ApiException::conflict(
                sprintf('That audience is %d people; this shop sends at most %d in one campaign.', count($ids), self::MAX_AUDIENCE),
                ['matched' => count($ids), 'maximum' => self::MAX_AUDIENCE]
            );
        }

        return $this->hydrate($ids);
    }

    /**
     * How many people a definition currently matches, without freezing anything.
     *
     * What `GET /campaigns/{id}` reports and what an admin checks before sending.
     * Deliberately a *live* count: a segment is a stored query, so its size is a
     * fact about today and not about the day it was written.
     */
    public function countFor(Campaign $campaign, ?Segment $segment): int
    {
        try {
            return count($this->resolve($campaign, $segment));
        } catch (ApiException $exception) {
            // A count is informational — a campaign whose segment was deleted still
            // has to be readable so somebody can fix it. The send path throws.
            if ($exception->statusCode() === 409) {
                return 0;
            }

            throw $exception;
        }
    }

    /**
     * The consenting customers this shop may mail — the gate every path passes.
     *
     * `WP_User_Query` rather than SQL, on purpose. The role check and the meta check
     * are exactly what it is for, it produces the `INNER JOIN` on `usermeta` that a
     * hand-written query would, and it keeps "who is a customer" WordPress's answer
     * rather than a `wp_capabilities LIKE '%customer%'` of our own — which is how
     * that check goes wrong when a role name contains another.
     *
     * @param list<int> $restrictTo an explicit id list, or [] for everyone eligible
     * @return array<int, array{email: string, name: string, registered: string}> keyed by user id
     */
    private function candidates(array $restrictTo): array
    {
        // An empty restriction means "everyone eligible"; a non-empty one narrows
        // to those ids *and still applies consent*, which is the point.
        $args = [
            // Consent and the customer role, together, before any order is read.
            'role' => 'customer',
            'meta_query' => [
                [
                    'key' => Consent::META,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
            'fields' => ['ID', 'user_email', 'display_name', 'user_registered'],
            'number' => -1,
            'count_total' => false,
        ];

        if ($restrictTo !== []) {
            $args['include'] = array_values(array_map('intval', $restrictTo));
        }

        $out = [];

        foreach ((new WP_User_Query($args))->get_results() as $user) {
            $id = (int) ($user->ID ?? 0);
            $email = trim((string) ($user->user_email ?? ''));

            if ($id <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                // An address that cannot be mailed is not a recipient. Dropped here
                // rather than at drain time, where it would spend an attempt and a
                // slot in the rate cap to learn the same thing.
                continue;
            }

            $out[$id] = [
                'email' => $email,
                'name' => trim((string) ($user->display_name ?? '')),
                'registered' => (string) ($user->user_registered ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Narrow a candidate set by a segment's criteria.
     *
     * @param array<int, array{email: string, name: string, registered: string}> $candidates
     * @return array<int, array{email: string, name: string, registered: string, context?: array<string, string>}>
     */
    private function applyCriteria(array $candidates, SegmentCriteria $criteria): array
    {
        if ($candidates === []) {
            return [];
        }

        // Registration date first: it needs no query at all, and narrowing here
        // makes every statement below smaller.
        foreach ($candidates as $id => $row) {
            $registered = substr($row['registered'], 0, 10);

            if ($criteria->has('registered_after') && $registered < (string) $criteria->get('registered_after')) {
                unset($candidates[$id]);

                continue;
            }

            if ($criteria->has('registered_before') && $registered > (string) $criteria->get('registered_before')) {
                unset($candidates[$id]);
            }
        }

        if ($candidates === []) {
            return [];
        }

        $needsOrders = $criteria->has('min_spent') || $criteria->has('max_spent')
            || $criteria->has('min_orders') || $criteria->has('max_orders')
            || $criteria->has('ordered_after') || $criteria->has('ordered_before');

        if ($needsOrders) {
            $this->requireHpos();

            $stats = $this->orderStats(array_keys($candidates));

            foreach ($candidates as $id => $row) {
                $stat = $stats[$id] ?? ['orders' => 0, 'spent' => '0.00', 'last_order_at' => '', 'last_order_number' => ''];

                if (!self::matchesOrderStats($stat, $criteria)) {
                    unset($candidates[$id]);

                    continue;
                }

                $candidates[$id]['context'] = [
                    'order_number' => (string) $stat['last_order_number'],
                ];
            }
        }

        if ($criteria->has('wilaya_id')) {
            $this->requireHpos();

            $inWilaya = $this->customersShippedTo((int) $criteria->get('wilaya_id'), array_keys($candidates));
            $candidates = array_intersect_key($candidates, array_flip($inWilaya));
        }

        foreach (['bought_product_id' => true, 'not_bought_product_id' => false] as $field => $keep) {
            if (!$criteria->has($field) || $candidates === []) {
                continue;
            }

            $this->requireHpos();

            $buyers = array_flip($this->customersWhoBought((int) $criteria->get($field), array_keys($candidates)));

            $candidates = $keep
                ? array_intersect_key($candidates, $buyers)
                : array_diff_key($candidates, $buyers);
        }

        return $candidates;
    }

    /**
     * The money and count comparisons, in one pure place.
     *
     * Static and pure so `tests/Unit/AudienceCriteriaTest` can drive it without a
     * database — the boundary cases here ("spent exactly the minimum", "a customer
     * with no orders against min_orders 0") are the ones an off-by-one hides in.
     *
     * @param array{orders: int, spent: string, last_order_at: string, last_order_number: string} $stat
     */
    public static function matchesOrderStats(array $stat, SegmentCriteria $criteria): bool
    {
        $orders = (int) $stat['orders'];
        $spent = (float) $stat['spent'];
        $lastOrder = substr((string) $stat['last_order_at'], 0, 10);

        if ($criteria->has('min_orders') && $orders < (int) $criteria->get('min_orders')) {
            return false;
        }

        if ($criteria->has('max_orders') && $orders > (int) $criteria->get('max_orders')) {
            return false;
        }

        if ($criteria->has('min_spent') && $spent < (float) $criteria->get('min_spent')) {
            return false;
        }

        if ($criteria->has('max_spent') && $spent > (float) $criteria->get('max_spent')) {
            return false;
        }

        /*
         * A customer who has never ordered has no last-order date, so an
         * `ordered_after` bound excludes them — which is the answer a shop means by
         * "ordered in the last 90 days". Treating an empty date as "before
         * everything" would be the same outcome; saying so explicitly is what stops
         * somebody later "fixing" it into including them.
         */
        if ($criteria->has('ordered_after') && ($lastOrder === '' || $lastOrder < (string) $criteria->get('ordered_after'))) {
            return false;
        }

        if ($criteria->has('ordered_before') && ($lastOrder === '' || $lastOrder > (string) $criteria->get('ordered_before'))) {
            return false;
        }

        return true;
    }

    /**
     * Order count, money spent and last order per customer.
     *
     * **Counts are of every revenue-status order; only the sum has a currency.**
     * §63 settled this and the reason still applies: this install holds hundreds of
     * orders recorded in `USD` from before anyone set `DZD`, so a `WHERE currency =`
     * would put "3 orders" beside a total of nothing, while summing across
     * currencies would add dollars to dinars. The currency lives in a `CASE`.
     *
     * @param list<int> $customerIds
     * @return array<int, array{orders: int, spent: string, last_order_at: string, last_order_number: string}>
     */
    private function orderStats(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $ids = implode(', ', array_fill(0, count($customerIds), '%d'));
        $statuses = implode(', ', array_fill(0, count(self::SPEND_STATUSES), '%s'));

        $params = [
            self::currency(),
            ...array_map('intval', $customerIds),
            ...array_map(static fn (string $s): string => 'wc-' . OrderStatus::normalize($s), self::SPEND_STATUSES),
        ];

        /*
         * `MAX(id)` beside `MAX(date_created_gmt)` rather than a correlated
         * subquery for the last order's *number*: the number a customer recognises
         * is `#{id}` unless a plugin renumbers orders, and one extra aggregate is
         * free where a subquery per customer is not. A shop that renumbers gets the
         * id, which is still a real handle on the order.
         */
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT o.customer_id AS customer_id,
                        COUNT(*) AS orders,
                        SUM(CASE WHEN o.currency = %s THEN o.total_amount ELSE 0 END) AS spent,
                        MAX(o.date_created_gmt) AS last_order_at,
                        MAX(o.id) AS last_order_id
                   FROM ' . $this->ordersTable() . " o
                  WHERE o.type = 'shop_order'
                    AND o.customer_id IN ({$ids})
                    AND o.status IN ({$statuses})
                  GROUP BY o.customer_id",
                $params
            ),
            ARRAY_A
        );

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['customer_id']] = [
                'orders' => (int) $row['orders'],
                'spent' => (string) $row['spent'],
                'last_order_at' => (string) $row['last_order_at'],
                'last_order_number' => (string) $row['last_order_id'],
            ];
        }

        return $out;
    }

    /**
     * Customers with a parcel to this wilaya.
     *
     * **The wilaya comes off the shipment and never off the address.**
     * `Shipping\ShipmentInput` refuses to fuzzy-match a commune name and §63 refused
     * to guess a wilaya out of an order's free-text `state`; a segment that made that
     * guess would mail the wrong province an offer about free delivery in another.
     * The consequence is stated rather than hidden: **an order nobody has shipped has
     * no wilaya and cannot match**, so a wilaya segment reaches customers who have
     * received a parcel, which is usually who a shop means anyway.
     *
     * `JSON_VALID` guards the extraction exactly as `AnalyticsRepository` does:
     * `metadata` is a `longtext` that is normally JSON, and `JSON_EXTRACT` raises on
     * a row that is neither JSON nor NULL.
     *
     * @param list<int> $customerIds
     * @return list<int>
     */
    private function customersShippedTo(int $wilayaId, array $customerIds): array
    {
        if ($customerIds === [] || $wilayaId <= 0) {
            return [];
        }

        $ids = implode(', ', array_fill(0, count($customerIds), '%d'));

        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT DISTINCT o.customer_id
                   FROM ' . $this->ordersTable() . ' o
                   INNER JOIN ' . $this->shipmentsTable() . " s ON s.order_id = o.id
                  WHERE o.type = 'shop_order'
                    AND o.customer_id IN ({$ids})
                    AND JSON_VALID(s.metadata)
                    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(s.metadata, '$.wilaya_id')) AS UNSIGNED) = %d",
                [...array_map('intval', $customerIds), $wilayaId]
            )
        );

        return array_values(array_map('intval', is_array($rows) ? $rows : []));
    }

    /**
     * Customers who have ever ordered a given product.
     *
     * Joined through `woocommerce_order_itemmeta` on `_product_id`, which is where
     * WooCommerce records it and the same join `AnalyticsRepository` uses to rank
     * products. A **variation** counts toward its parent as well, because
     * `_product_id` on a variation line is the parent id and `_variation_id` carries
     * the variation — so "everyone who bought the rug" means everyone who bought any
     * size of it, which is what a shop asking the question means.
     *
     * @param list<int> $customerIds
     * @return list<int>
     */
    private function customersWhoBought(int $productId, array $customerIds): array
    {
        if ($customerIds === [] || $productId <= 0) {
            return [];
        }

        $ids = implode(', ', array_fill(0, count($customerIds), '%d'));

        $rows = $this->wpdb->get_col(
            $this->wpdb->prepare(
                'SELECT DISTINCT o.customer_id
                   FROM ' . $this->ordersTable() . ' o
                   INNER JOIN ' . $this->itemsTable() . " i ON i.order_id = o.id AND i.order_item_type = 'line_item'
                   INNER JOIN " . $this->itemMetaTable() . " m ON m.order_item_id = i.order_item_id
                  WHERE o.type = 'shop_order'
                    AND o.customer_id IN ({$ids})
                    AND m.meta_key IN ('_product_id', '_variation_id')
                    AND m.meta_value = %s",
                [...array_map('intval', $customerIds), (string) $productId]
            )
        );

        return array_values(array_map('intval', is_array($rows) ? $rows : []));
    }

    /**
     * @param array<int, array{email: string, name: string, registered: string, context?: array<string, string>}> $candidates
     * @return list<array{customer_id: int, email: string, name: string, context: array<string, string>}>
     */
    private function hydrate(array $candidates): array
    {
        $out = [];

        foreach ($candidates as $id => $row) {
            $out[] = [
                'customer_id' => (int) $id,
                'email' => $row['email'],
                'name' => $row['name'],
                // Frozen here, so the drain runs no query per recipient and a
                // customer who orders again mid-drain does not change the message
                // the admin previewed — migrations 009 and 010's rule.
                'context' => $row['context'] ?? [],
            ];
        }

        return $out;
    }

    /** @throws ApiException 501 */
    private function requireHpos(): void
    {
        if ($this->isSupported()) {
            return;
        }

        throw new ApiException(
            'order_storage_unsupported',
            'Audience criteria that read orders need WooCommerce\'s high-performance order storage, which this install does not use.',
            501,
            ['fix' => 'Enable HPOS, or use an explicit customer list or audience_type "all".']
        );
    }

    private function ordersTable(): string
    {
        return OrderUtil::get_table_for_orders();
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

    private static function currency(): string
    {
        return function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : 'DZD';
    }
}
