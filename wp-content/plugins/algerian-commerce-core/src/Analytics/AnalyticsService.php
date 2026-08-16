<?php

declare(strict_types=1);

namespace AlgerianCommerce\Analytics;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\COD\CodService;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Inventory\InventoryRepository;
use AlgerianCommerce\Orders\OrderStatus;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use AlgerianCommerce\Shipping\ShipmentStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The analytics dashboard — roadmap §63, docs/PLAN.md §27 and §28.
 *
 * **Two capabilities, not one, and the rule behind the split is worth stating
 * before the code.** `ac_view_analytics` is the named capability for this
 * section and every role in `Permissions\Capabilities` holds it, Support Agent
 * included — an account whose whole job is `ac_manage_customers` and answering
 * the phone. Wiring turnover to it would hand the shop's revenue to every
 * account in the building.
 *
 * So money obeys one further rule: **analytics may not disclose in aggregate
 * what the caller cannot already read in detail.** An order's total is readable
 * through `GET /orders` with `ac_manage_orders`, so summing those totals for a
 * caller who already holds it discloses nothing new; for a caller who does not,
 * it discloses the entire order book one figure at a time. Counts, rates and
 * operational metrics need only `ac_view_analytics`.
 *
 * The effect is that Super Admin, Admin, Manager and Order Manager see money,
 * while Product Manager, Marketing Manager and Support Agent see volumes and
 * rates. **No new capability was invented** to achieve it: PLAN §3 defines the
 * vocabulary, and §61 already settled that naming a gap beats widening that
 * list on our own authority. A shop that wants its marketing manager to see
 * revenue grants that account `ac_manage_orders`, which is a deliberate act
 * with a visible consequence — they can then read the orders too.
 *
 * Every response says which of the two it was built for, in
 * `meta.money_visible`, so a client can hide a card rather than render an
 * empty one.
 */
final class AnalyticsService
{
    /** Best sellers are a leaderboard, not a catalogue export. */
    private const BEST_SELLER_LIMIT = 10;

    public function __construct(
        private readonly AnalyticsRepository $repository,
        private readonly AnalyticsCache $cache,
        private readonly CodService $cod,
        private readonly InventoryRepository $inventory,
        private readonly Logger $logger,
        private readonly string $version
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function overview(array $params): array
    {
        return $this->report('overview', $params, function (AnalyticsRange $range, bool $money): array {
            $orders = $this->orderTotals($range);
            $customers = $this->customerTotals($range);
            $shipments = $this->shipmentTotals($range);

            $data = [
                'orders' => $orders['summary'],
                'customers' => $customers,
                'cod' => $this->codHeadline($range),
                'shipping' => [
                    'shipments' => $shipments['total'],
                    'delivered' => $shipments['delivered'],
                    'live' => $shipments['live'],
                    'delivery_rate' => Metrics::rate($shipments['delivered'], $shipments['total']),
                ],
                'inventory' => ['low_stock' => $this->lowStockCount()],
            ];

            if ($money) {
                $data['revenue'] = $this->revenueReport($range, $orders);
            }

            return $data;
        });
    }

    /**
     * Financial reporting — docs/PLAN.md §28.
     *
     * The whole resource is money, so this one refuses outright rather than
     * returning a body with nothing in it. `Permissions::assert()` inside the
     * service is the second of the two layers docs/SECURITY.md requires; the
     * route's own callback has already checked `ac_view_analytics`.
     *
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function revenue(array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        return $this->report('revenue', $params, function (AnalyticsRange $range): array {
            return $this->revenueReport($range, $this->orderTotals($range));
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function orders(array $params): array
    {
        return $this->report('orders', $params, function (AnalyticsRange $range, bool $money): array {
            $orders = $this->orderTotals($range);
            $data = $orders['summary'];

            if ($money) {
                $scale = Metrics::scale($this->decimals());
                $gross = Metrics::toMinor($orders['gross'], $scale);

                $data['average_order_value'] = Metrics::format(
                    Metrics::average($gross, $orders['counted']),
                    $scale,
                    $this->decimals()
                );
                $data['currency'] = $this->currency();
            }

            return $data;
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function products(array $params): array
    {
        return $this->report('products', $params, function (AnalyticsRange $range, bool $money): array {
            $rows = $this->repository->bestSellers(
                $range,
                RevenueReport::COUNTED_STATUSES,
                $this->currency(),
                $this->decimals(),
                self::BEST_SELLER_LIMIT
            );

            $sellers = [];

            foreach ($rows as $row) {
                $seller = [
                    'product_id' => $row['product_id'],
                    'name' => $row['name'],
                    'units' => $row['units'],
                    'orders' => $row['orders'],
                ];

                if ($money) {
                    $seller['revenue'] = $this->money($row['revenue']);
                }

                $sellers[] = $seller;
            }

            return [
                'best_sellers' => $sellers,
                'best_sellers_limit' => self::BEST_SELLER_LIMIT,
                /*
                 * A count, not a second copy of the list. `GET
                 * /inventory/low-stock` already serves the products themselves
                 * behind `ac_manage_inventory`, and re-implementing that query
                 * here would be a parallel answer to a question that already
                 * has one — the rule §61 followed when it built the CMS out of
                 * post types instead of tables.
                 */
                'low_stock' => ['products' => $this->lowStockCount()],
            ];
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function customers(array $params): array
    {
        return $this->report('customers', $params, function (AnalyticsRange $range): array {
            return $this->customerTotals($range);
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function shipping(array $params): array
    {
        return $this->report('shipping', $params, function (AnalyticsRange $range, bool $money): array {
            $shipments = $this->shipmentTotals($range);

            $data = [
                'shipments' => [
                    'total' => $shipments['total'],
                    'by_status' => $shipments['by_status'],
                    'live' => $shipments['live'],
                ],
                /*
                 * One denominator — every parcel created in the window — for
                 * the reason `COD\CodStatistics` gives for its own funnel:
                 * rates with different denominators cannot be compared, and
                 * dividing by "parcels that reached an outcome" changes meaning
                 * as the in-transit queue drains. A window that includes today
                 * is depressed by the vans still out, and `live` beside it is
                 * how to read that honestly.
                 */
                'rates' => [
                    'delivery' => Metrics::rate($shipments['delivered'], $shipments['total']),
                    'return' => Metrics::rate($shipments['returned'], $shipments['total']),
                ],
                'providers' => $shipments['providers'],
                'unavailable' => [
                    'shipping_cost' => RevenueReport::unavailable()['shipping_cost'],
                ],
            ];

            $data += $this->wilayaBreakdown($range, $money);

            if ($money) {
                $extras = $this->repository->revenueExtras(
                    $range,
                    RevenueReport::COUNTED_STATUSES,
                    $this->currency(),
                    $this->decimals()
                );

                $data['shipping_revenue'] = $this->money($extras['shipping_revenue']);
                $data['currency'] = $this->currency();
            }

            return $data;
        });
    }

    /**
     * The cash-on-delivery funnel over a reporting window.
     *
     * **Delegated, not re-implemented.** `COD\CodStatistics` already owns the
     * arithmetic and `GET /cod/statistics` already serves it; a second
     * definition of "confirmation rate" living here would eventually disagree
     * with the first, and the shop would have two numbers and no way to tell
     * which was right. The dependency runs one way — `Analytics/` reads `COD/`,
     * and `COD/` has never heard of analytics — which is the direction
     * CLAUDE.md allows where the business genuinely nests.
     *
     * The window crosses that boundary as the shop's own calendar days, which
     * is exactly what `CodRepository` passes to `wc_get_orders()`. Both sides
     * therefore mean the same days even where the site's timezone is not UTC.
     *
     * @param array<string, mixed> $params
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function cod(array $params): array
    {
        return $this->report('cod', $params, function (AnalyticsRange $range): array {
            return $this->cod->statistics([
                'customer_id' => 0,
                'date_from' => $range->from,
                'date_to' => $range->to,
            ]);
        });
    }

    /**
     * The shape every endpoint above shares: authorize, resolve the window,
     * serve from cache or compute, and stamp the result.
     *
     * @param array<string, mixed> $params
     * @param callable(AnalyticsRange, bool): array<string, mixed> $build
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function report(string $endpoint, array $params, callable $build): array
    {
        Permissions::assert(Capabilities::VIEW_ANALYTICS);

        $this->assertSupported();

        $range = AnalyticsRange::fromParams(
            [
                'range' => (string) ($params['range'] ?? AnalyticsRange::LAST_30),
                'date_from' => (string) ($params['date_from'] ?? ''),
                'date_to' => (string) ($params['date_to'] ?? ''),
            ],
            $this->timezone(),
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );

        $money = $this->moneyVisible();
        $key = AnalyticsCache::key($endpoint, $range->fingerprint(), $money, $this->version);
        $cached = $this->cache->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $report = [
            'data' => ['range' => $range->toArray()] + $build($range, $money),
            'meta' => [
                'generated_at' => gmdate('c'),
                'cache_ttl' => $this->cache->ttl(),
                'money_visible' => $money,
                // Named rather than implied: a client showing a disabled card
                // should be able to say which capability would enable it.
                'money_requires' => Capabilities::MANAGE_ORDERS,
            ],
        ];

        $this->cache->put($key, $report);

        return $report;
    }

    /**
     * Refuse rather than answer zero on an install these queries cannot read.
     *
     * 501 rather than 500: nothing is broken, this build simply does not
     * implement analytics for legacy post storage — see
     * `AnalyticsRepository::isSupported()`.
     */
    private function assertSupported(): void
    {
        if ($this->repository->isSupported()) {
            return;
        }

        $this->logger->error('Analytics requested on an install without HPOS', [
            'hint' => 'Orders are in wp_posts; these aggregates only read the WooCommerce order tables.',
        ]);

        throw new ApiException(
            'analytics_unsupported',
            'Analytics requires WooCommerce High-Performance Order Storage, which is not enabled on this install.',
            501
        );
    }

    private function moneyVisible(): bool
    {
        return Permissions::can(Capabilities::MANAGE_ORDERS);
    }

    /**
     * The one order query, shaped for everything that reads it.
     *
     * @return array{
     *     summary: array<string, mixed>,
     *     gross: string,
     *     counted: int,
     *     rows: array{order_total: string, collected: string, tax: string, placed: int}
     * }
     */
    private function orderTotals(AnalyticsRange $range): array
    {
        $rows = $this->repository->ordersByStatus($range, $this->currency(), $this->decimals());

        $scale = Metrics::scale($this->decimals());
        $byStatus = array_fill_keys(OrderStatus::ALL, 0);

        $placed = 0;
        $guests = 0;
        $counted = 0;
        $placedInCurrency = 0;
        $countedInCurrency = 0;
        $grossMinor = 0;
        $collectedMinor = 0;
        $orderTotalMinor = 0;
        $taxMinor = 0;

        foreach ($rows as $row) {
            $status = $row['status'];

            // A status outside our vocabulary — a storefront checkout draft, or
            // one another plugin registered — still counts toward the total, so
            // `placed` never disagrees with the order list.
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] += $row['orders'];
            }

            $placed += $row['orders'];
            $guests += $row['guest_orders'];
            $placedInCurrency += $row['orders_in_currency'];
            $orderTotalMinor += Metrics::toMinor($row['total'], $scale);

            if (in_array($status, RevenueReport::COUNTED_STATUSES, true)) {
                $counted += $row['orders'];
                $countedInCurrency += $row['orders_in_currency'];
                $grossMinor += Metrics::toMinor($row['total'], $scale);
                $taxMinor += Metrics::toMinor($row['tax'], $scale);
            }

            if (in_array($status, RevenueReport::COLLECTED_STATUSES, true)) {
                $collectedMinor += Metrics::toMinor($row['total'], $scale);
            }
        }

        return [
            'summary' => [
                'placed' => $placed,
                'by_status' => $byStatus,
                'cancelled' => $byStatus[OrderStatus::CANCELLED],
                'completed' => $byStatus[OrderStatus::COMPLETED],
                /*
                 * WooCommerce has no `returned` status — `CustomerStatistics`
                 * settled that `refunded` is the state where the money went
                 * back, which is what PLAN §27's "returns" is counting here. A
                 * parcel the courier brought back is a different event and is
                 * reported as `returned` under /analytics/shipping.
                 */
                'refunded' => $byStatus[OrderStatus::REFUNDED],
                'guest_orders' => $guests,
                'counted_as_revenue' => $counted,
            ],
            'gross' => Metrics::format($grossMinor, $scale, $this->decimals()),
            /*
             * The denominator of the average, and it is the *currency-scoped*
             * count rather than `$counted`: gross only contains orders priced
             * in the store's currency, so dividing it by a count that includes
             * the others understates every sale. The two agree in any shop that
             * has only ever traded in one currency, which is every shop that
             * never ran on the WooCommerce default before someone set DZD.
             */
            'counted' => $countedInCurrency,
            'rows' => [
                'order_total' => Metrics::format($orderTotalMinor, $scale, $this->decimals()),
                'collected' => Metrics::format($collectedMinor, $scale, $this->decimals()),
                'tax' => Metrics::format($taxMinor, $scale, $this->decimals()),
                'placed' => $placedInCurrency,
            ],
        ];
    }

    /**
     * @param array{summary: array<string, mixed>, gross: string, counted: int, rows: array<string, mixed>} $orders
     * @return array<string, mixed>
     */
    private function revenueReport(AnalyticsRange $range, array $orders): array
    {
        $currency = $this->currency();
        $statuses = RevenueReport::COUNTED_STATUSES;

        $extras = $this->repository->revenueExtras($range, $statuses, $currency, $this->decimals());
        $refunds = $this->repository->refunds($range, $statuses, $currency, $this->decimals());

        $report = RevenueReport::compute(
            [
                'order_total' => (string) $orders['rows']['order_total'],
                'orders_placed' => (int) $orders['rows']['placed'],
                'gross' => $orders['gross'],
                'orders_counted' => $orders['counted'],
                'collected' => (string) $orders['rows']['collected'],
                'tax' => (string) $orders['rows']['tax'],
                'shipping_revenue' => $extras['shipping_revenue'],
                'discounts' => $extras['discounts'],
                'refunds' => $refunds['refunds'],
            ],
            $currency,
            $this->decimals(),
            $this->otherCurrencies($range, $currency)
        );

        $report['refund_count'] = $refunds['refund_count'];
        $report['refunded_orders'] = $refunds['refunded_orders'];

        return $report;
    }

    /**
     * Orders in the window that were taken in some other currency.
     *
     * @return array<string, int>
     */
    private function otherCurrencies(AnalyticsRange $range, string $currency): array
    {
        $counts = $this->repository->ordersByCurrency($range);

        unset($counts[strtoupper($currency)]);

        return $counts;
    }

    /** @return array<string, mixed> */
    private function customerTotals(AnalyticsRange $range): array
    {
        $counts = $this->repository->customers($range, RevenueReport::COUNTED_STATUSES);
        $orders = $this->repository->ordersByStatus($range, $this->currency(), $this->decimals());

        $guests = 0;

        foreach ($orders as $row) {
            if (in_array($row['status'], RevenueReport::COUNTED_STATUSES, true)) {
                $guests += $row['guest_orders'];
            }
        }

        $returning = max(0, $counts['customers'] - $counts['new_customers']);

        return [
            'customers' => $counts['customers'],
            'new' => $counts['new_customers'],
            'returning' => $returning,
            /*
             * Orders, not customers. A guest checkout has no identity, so a
             * guest cannot be new or returning and counting each guest order as
             * a customer would inflate both figures above.
             */
            'guest_orders' => $guests,
            'rates' => [
                'new' => Metrics::rate($counts['new_customers'], $counts['customers']),
                'returning' => Metrics::rate($returning, $counts['customers']),
            ],
        ];
    }

    /**
     * @return array{
     *     total: int, delivered: int, returned: int, live: int,
     *     by_status: array<string, int>, providers: list<array<string, mixed>>
     * }
     */
    private function shipmentTotals(AnalyticsRange $range): array
    {
        $rows = $this->repository->shipmentsByProvider($range);

        $byStatus = array_fill_keys(ShipmentStatus::ALL, 0);
        $providers = [];
        $total = 0;
        $live = 0;

        foreach ($rows as $row) {
            $status = ShipmentStatus::normalize($row['status']);
            $count = $row['shipments'];
            $total += $count;

            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] += $count;
            }

            if (ShipmentStatus::isLive($status)) {
                $live += $count;
            }

            $provider = $row['provider'];
            $providers[$provider] ??= [
                'provider' => $provider,
                'shipments' => 0,
                'delivered' => 0,
                'returned' => 0,
                'cancelled' => 0,
                'failed' => 0,
                'live' => 0,
            ];

            $providers[$provider]['shipments'] += $count;

            foreach ([ShipmentStatus::DELIVERED => 'delivered',
                ShipmentStatus::RETURNED => 'returned',
                ShipmentStatus::CANCELLED => 'cancelled',
                ShipmentStatus::FAILED => 'failed'] as $known => $bucket) {
                if ($status === $known) {
                    $providers[$provider][$bucket] += $count;
                }
            }

            if (ShipmentStatus::isLive($status)) {
                $providers[$provider]['live'] += $count;
            }
        }

        foreach ($providers as $name => $provider) {
            $providers[$name]['rates'] = [
                'delivery' => Metrics::rate($provider['delivered'], $provider['shipments']),
                'return' => Metrics::rate($provider['returned'], $provider['shipments']),
            ];
        }

        // Busiest first, so a courier comparison reads top to bottom.
        uasort($providers, static fn (array $a, array $b): int => $b['shipments'] <=> $a['shipments']);

        return [
            'total' => $total,
            'delivered' => $byStatus[ShipmentStatus::DELIVERED],
            'returned' => $byStatus[ShipmentStatus::RETURNED],
            'live' => $live,
            'by_status' => $byStatus,
            'providers' => array_values($providers),
        ];
    }

    /**
     * Revenue by wilaya, with the orders no wilaya could be established for
     * reported rather than dropped.
     *
     * @return array<string, mixed>
     */
    private function wilayaBreakdown(AnalyticsRange $range, bool $money): array
    {
        $rows = $this->repository->ordersByWilaya(
            $range,
            RevenueReport::COUNTED_STATUSES,
            $this->currency(),
            $this->decimals()
        );

        $names = $this->repository->wilayaNames(array_column($rows, 'wilaya_id'));

        $byWilaya = [];
        $unattributed = ['orders' => 0];

        if ($money) {
            $unattributed['revenue'] = $this->money('0');
        }

        foreach ($rows as $row) {
            if ($row['wilaya_id'] === 0) {
                $unattributed['orders'] = $row['orders'];

                if ($money) {
                    $unattributed['revenue'] = $this->money($row['revenue']);
                }

                continue;
            }

            $name = $names[$row['wilaya_id']] ?? ['code' => '', 'name' => '', 'name_ar' => ''];

            $entry = [
                'wilaya_id' => $row['wilaya_id'],
                'code' => $name['code'],
                'name' => $name['name'],
                'name_ar' => $name['name_ar'],
                'orders' => $row['orders'],
            ];

            if ($money) {
                $entry['revenue'] = $this->money($row['revenue']);
            }

            $byWilaya[] = $entry;
        }

        return [
            'by_wilaya' => $byWilaya,
            /*
             * Not a rounding error. An order is only attributed to a wilaya
             * once a parcel has been created for it from the §51 dropdowns, so
             * everything not yet shipped lands here — and saying so is the
             * difference between a map with gaps and a map that is quietly
             * wrong.
             */
            'unattributed' => $unattributed + [
                'reason' => 'Orders with no shipment carry no canonical wilaya; '
                    . 'an order address stores it as free text, which is never guessed at.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function codHeadline(AnalyticsRange $range): array
    {
        $funnel = $this->cod->statistics([
            'customer_id' => 0,
            'date_from' => $range->from,
            'date_to' => $range->to,
        ]);

        return [
            'total_orders' => $funnel['total_orders'] ?? 0,
            'confirmed_orders' => $funnel['confirmed_orders'] ?? 0,
            'confirmation_rate' => $funnel['rates']['confirmation'] ?? Metrics::rate(0, 0),
            'delivery_rate' => $funnel['rates']['delivery'] ?? Metrics::rate(0, 0),
        ];
    }

    /**
     * How many products are at or below their low-stock threshold.
     *
     * `InventoryRepository` rather than `InventoryService`, because the service
     * asserts `ac_manage_inventory` and this route is already gated on
     * `ac_view_analytics` — the count of products running low is an operational
     * number, not a disclosure of the catalogue. One page of one row is asked
     * for because only the total is wanted.
     */
    private function lowStockCount(): int
    {
        return $this->inventory->lowStock(1, 1)['total'];
    }

    private function money(string $amount): string
    {
        $scale = Metrics::scale($this->decimals());

        return Metrics::format(Metrics::toMinor($amount, $scale), $scale, $this->decimals());
    }

    /**
     * The store's currency, which every sum is filtered to.
     *
     * CLAUDE.md records why this is not cosmetic: a fresh install reports `USD`
     * until somebody sets `DZD`, WooCommerce stores the currency per order, and
     * changing it later does not rewrite the orders already taken.
     */
    private function currency(): string
    {
        return strtoupper((string) get_woocommerce_currency());
    }

    private function decimals(): int
    {
        return max(0, (int) wc_get_price_decimals());
    }

    /**
     * The shop's own timezone, not the server's.
     *
     * "Today" ends at midnight where the shop is. An Algiers shop reading a
     * UTC server's day would see the last hour of every day fall into tomorrow.
     */
    private function timezone(): DateTimeZone
    {
        return wp_timezone();
    }
}
