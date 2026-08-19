<?php

declare(strict_types=1);

namespace AlgerianCommerce\Seed;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Account\AccountSession;
use AlgerianCommerce\Campaigns\Consent;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Coupons\CouponService;
use AlgerianCommerce\Customers\CustomerService;
use AlgerianCommerce\Inventory\InventoryService;
use AlgerianCommerce\Notifications\NotificationRepository;
use AlgerianCommerce\Orders\OrderService;
use AlgerianCommerce\Products\ProductService;
use AlgerianCommerce\Products\VariationService;
use Throwable;

/**
 * Loads the development seed data — roadmap §67, docs/PLAN.md §46.
 *
 * **Everything goes through a service.** Not one row is written with `$wpdb`,
 * and that is the point rather than a stylistic preference: §64 already
 * established that an import must not be a back door around
 * `ac_inventory_movements`, and a seeder writing posts directly is the same
 * back door with a friendlier name. A seed that bypasses `ProductService` can
 * produce a product the API would refuse — a duplicate SKU, a sale price above
 * the regular one, a variation whose attribute the parent does not offer — and
 * then every test written against it is a test against a state the shop cannot
 * reach. Going through the services means the fixtures are *proof* the API can
 * build this shop.
 *
 * Two consequences follow, and both are deliberate.
 *
 * **It runs as somebody.** Services assert capabilities (`Permissions::assert`),
 * so the caller sets a current user first — `SeedCommand` uses an administrator.
 * A seeder that ran with no identity would have to bypass the check, which is
 * the one thing this class exists not to do.
 *
 * **It is idempotent, keyed on natural keys.** Products and variations by SKU,
 * customers by email, coupons by code — all three are unique in WooCommerce
 * already. Orders have no natural key, so the seed keeps its own ledger in the
 * `ac_seed_orders` option rather than writing a marker onto the order:
 * `OrderRepository` is the only file that touches an order (CLAUDE.md), and a
 * seeder is not the place to make the second exception.
 */
final class Seeder
{
    /** ref => order id, for orders this seeder has already created. */
    public const ORDER_LEDGER_OPTION = 'ac_seed_orders';

    public const CATALOGUE = 'catalogue.json';
    public const CUSTOMERS = 'customers.json';
    public const COUPONS = 'coupons.json';
    public const ORDERS = 'orders.json';

    public function __construct(
        private readonly ProductService $products,
        private readonly VariationService $variations,
        private readonly InventoryService $inventory,
        private readonly CustomerService $customers,
        private readonly Consent $consent,
        private readonly CouponService $coupons,
        private readonly OrderService $orders,
        private readonly NotificationRepository $notifications,
        private readonly Logger $logger,
        /** Absolute path to the directory holding the JSON fixtures. */
        private readonly string $dataPath
    ) {
    }

    public function dataPath(): string
    {
        return $this->dataPath;
    }

    /**
     * @return array{
     *     categories: array{created: int, updated: int},
     *     products: array{created: int, updated: int},
     *     variations: array{created: int, updated: int},
     *     customers: array{created: int, updated: int},
     *     coupons: array{created: int, updated: int},
     *     orders: array{created: int, updated: int},
     *     notifications_discarded: int,
     *     errors: list<string>
     * }
     */
    public function seed(bool $dryRun = false, bool $keepNotifications = false): array
    {
        $counts = ['created' => 0, 'updated' => 0];
        $result = [
            'categories' => $counts,
            'products' => $counts,
            'variations' => $counts,
            'customers' => $counts,
            'coupons' => $counts,
            'orders' => $counts,
            'notifications_discarded' => 0,
            'errors' => [],
        ];

        $files = [];

        foreach ([self::CATALOGUE, self::CUSTOMERS, self::COUPONS, self::ORDERS] as $name) {
            $decoded = $this->read($name, $result['errors']);

            if ($decoded === null) {
                return $result;
            }

            $files[$name] = $decoded;
        }

        /*
         * Validate every file before writing anything. A half-seeded shop is
         * worse than an unseeded one: orders would reference products that
         * exist while their coupons reference categories that do not, and
         * nothing would say which half ran.
         */
        $catalogue = SeedDataset::catalogue($files[self::CATALOGUE]);
        $categorySlugs = array_fill_keys(array_column($catalogue['categories'], 'slug'), true);

        $skus = [];

        foreach ($catalogue['products'] as $product) {
            $skus[strtolower($product['sku'])] = true;

            foreach ($product['variations'] as $variation) {
                $skus[strtolower($variation['sku'])] = true;
            }
        }

        $customers = SeedDataset::customers($files[self::CUSTOMERS]);
        $emails = array_fill_keys(array_column($customers['rows'], 'email'), true);

        $coupons = SeedDataset::coupons($files[self::COUPONS], $categorySlugs);
        $orders = SeedDataset::orders($files[self::ORDERS], $skus, $emails);

        $result['errors'] = [
            ...$catalogue['errors'],
            ...$customers['errors'],
            ...$coupons['errors'],
            ...$orders['errors'],
        ];

        if ($result['errors'] !== []) {
            return $result;
        }

        if ($dryRun) {
            $result['categories']['created'] = count($catalogue['categories']);
            $result['products']['created'] = count($catalogue['products']);
            $result['variations']['created'] = array_sum(
                array_map(static fn (array $p): int => count($p['variations']), $catalogue['products'])
            );
            $result['customers']['created'] = count($customers['rows']);
            $result['coupons']['created'] = count($coupons['rows']);
            $result['orders']['created'] = count($orders['rows']);

            return $result;
        }

        /*
         * Everything below this line writes, and writing an order sends mail
         * through **two** independent paths that have to be handled
         * differently.
         *
         * `ac_notifications` (roadmap step 34) is ours and is deferred: the
         * subscriber writes a row and `send-notifications` drains it later, so
         * it is enough to note the highest id now and drop what this run added.
         *
         * **WooCommerce's own mailer is neither.** `WC_Emails` sends
         * synchronously inside `woocommerce_order_status_changed`, so by the
         * time this method returns the mail has already left — there is nothing
         * to discard afterwards. Seeding eleven orders attempted 25 sends on
         * the first run here, visible only as `sendmail: can't connect` because
         * this machine has no MTA. On one that does, a fictional order would
         * mail the shop's real admin address. So it is short-circuited for the
         * duration, and `--keep-notifications` deliberately does **not** turn
         * it back on: our queue can be inspected and drained on purpose, while
         * a synchronous send has no such second look.
         */
        $watermark = $this->notifications->maxId();

        add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);

        try {
            $categoryIds = $this->seedCategories($catalogue['categories'], $result);
            $productIds = $this->seedProducts($catalogue['products'], $categoryIds, $result);
            $customerIds = $this->seedCustomers($customers['rows'], $result);

            $this->seedCoupons($coupons['rows'], $categoryIds, $result);
            $this->seedOrders($orders['rows'], $productIds, $customerIds, $customers['rows'], $result);
        } finally {
            remove_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
        }

        if (!$keepNotifications) {
            $result['notifications_discarded'] = $this->notifications->discardPendingAbove($watermark);
        }

        $this->logger->info('Seed data loaded', [
            'products' => $result['products'],
            'orders' => $result['orders'],
            // Not "discarded": Logger masks any key containing "card".
            'notifications_dropped' => $result['notifications_discarded'],
        ]);

        return $result;
    }

    /**
     * Categories are the one thing here with no service behind it.
     *
     * `/product-categories` is read-only by design — managing categories is
     * docs/PLAN.md §5 and has its own phase — so this uses WordPress's own term
     * API, which is the supported path and the same one WooCommerce uses.
     *
     * @param list<array{slug: string, name: string, description: string}> $rows
     * @param array<string, mixed>                                         $result by reference
     * @return array<string, int> slug => term id
     */
    private function seedCategories(array $rows, array &$result): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $existing = term_exists($row['slug'], 'product_cat');

            if (is_array($existing)) {
                $id = (int) $existing['term_id'];

                wp_update_term($id, 'product_cat', [
                    'name' => $row['name'],
                    'description' => $row['description'],
                ]);

                $result['categories']['updated']++;
            } else {
                $created = wp_insert_term($row['name'], 'product_cat', [
                    'slug' => $row['slug'],
                    'description' => $row['description'],
                ]);

                if (is_wp_error($created)) {
                    $result['errors'][] = "category {$row['slug']}: " . $created->get_error_message();
                    continue;
                }

                $id = (int) $created['term_id'];
                $result['categories']['created']++;
            }

            $ids[$row['slug']] = $id;
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>         $categoryIds
     * @param array<string, mixed>       $result by reference
     * @return array<string, array{product_id: int, variation_id: int}> lowercased SKU => ids
     */
    private function seedProducts(array $rows, array $categoryIds, array &$result): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $payload = $row['fields'];
            $payload['category_ids'] = array_values(array_map(
                static fn (string $slug): int => $categoryIds[$slug] ?? 0,
                $row['categories']
            ));

            /*
             * WooCommerce ignores set_stock_quantity() unless the product also
             * manages stock, so a fixture that states a quantity is a fixture
             * saying the shop counts this item. Derived here rather than
             * repeated on every row.
             */
            if (array_key_exists('stock_quantity', $payload) && !array_key_exists('manage_stock', $payload)) {
                $payload['manage_stock'] = true;
            }

            try {
                $existingId = (int) wc_get_product_id_by_sku($row['sku']);

                if ($existingId > 0) {
                    $product = $this->products->update($existingId, $payload);
                    $result['products']['updated']++;
                } else {
                    $product = $this->products->create($payload);
                    $result['products']['created']++;
                }
            } catch (ApiException | Throwable $e) {
                $result['errors'][] = "product {$row['sku']}: " . $e->getMessage();
                continue;
            }

            $productId = $product->get_id();
            $ids[strtolower($row['sku'])] = ['product_id' => $productId, 'variation_id' => 0];

            if ($row['low_stock_amount'] !== null) {
                try {
                    $this->inventory->updateSettings($productId, [
                        'low_stock_amount' => $row['low_stock_amount'],
                    ]);
                } catch (ApiException | Throwable $e) {
                    $result['errors'][] = "product {$row['sku']} low stock: " . $e->getMessage();
                }
            }

            foreach ($row['variations'] as $variation) {
                $id = $this->seedVariation($productId, $variation, $result);

                if ($id > 0) {
                    $ids[strtolower($variation['sku'])] = [
                        'product_id' => $productId,
                        'variation_id' => $id,
                    ];
                }
            }
        }

        return $ids;
    }

    /**
     * @param array{sku: string, attributes: array<string, string>, fields: array<string, mixed>} $row
     * @param array<string, mixed>                                                                $result by reference
     */
    private function seedVariation(int $productId, array $row, array &$result): int
    {
        $payload = $row['fields'];
        $payload['attributes'] = $row['attributes'];

        if (array_key_exists('stock_quantity', $payload) && !array_key_exists('manage_stock', $payload)) {
            $payload['manage_stock'] = true;
        }

        try {
            $existingId = (int) wc_get_product_id_by_sku($row['sku']);

            if ($existingId > 0) {
                $variation = $this->variations->update($productId, $existingId, $payload);
                $result['variations']['updated']++;
            } else {
                $variation = $this->variations->create($productId, $payload);
                $result['variations']['created']++;
            }

            return $variation->get_id();
        } catch (ApiException | Throwable $e) {
            $result['errors'][] = "variation {$row['sku']}: " . $e->getMessage();

            return 0;
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $result by reference
     * @return array<string, int> email => user id
     */
    private function seedCustomers(array $rows, array &$result): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $userId = (int) email_exists($row['email']);

            if ($userId === 0) {
                /*
                 * The same insert `AccountService::register()` makes, with the
                 * same forced role. A seeded shopper must be able to sign in at
                 * /account/login and read their own orders, which is what makes
                 * §59c's IDOR pair testable against real data — a customer
                 * created any other way is a different kind of account.
                 */
                $created = wp_insert_user([
                    'user_login' => $row['email'],
                    'user_email' => $row['email'],
                    'user_pass' => wp_generate_password(24),
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'role' => AccountSession::ROLE,
                ]);

                if (is_wp_error($created)) {
                    $result['errors'][] = "customer {$row['email']}: " . $created->get_error_message();
                    continue;
                }

                $userId = (int) $created;
                $result['customers']['created']++;
            } else {
                $result['customers']['updated']++;
            }

            $billing = $row['billing'];
            $billing['first_name'] = $row['first_name'];
            $billing['last_name'] = $row['last_name'];
            $billing['email'] = $row['email'];

            if ($row['phone'] !== '') {
                $billing['phone'] = $row['phone'];
            }

            try {
                $this->customers->update($userId, [
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'billing' => $billing,
                    // WooCommerce stores no shipping email, so it is dropped
                    // rather than copied — AddressInput would refuse it.
                    'shipping' => array_diff_key($billing, array_flip(['email'])),
                ]);
            } catch (ApiException | Throwable $e) {
                $result['errors'][] = "customer {$row['email']}: " . $e->getMessage();
                continue;
            }

            /*
             * Through `Consent`, not through `CustomerService`, because there is no
             * path through `CustomerService` and there must not be — the flag is
             * refused by name there. The seeder stands in for the shopper's own act
             * at registration, so it records `registration` as the source and leaves
             * a real audit row, which is what makes the seeded state indistinguishable
             * from one a person produced.
             *
             * Only ever called for a true: an unconsented seed row must not write a
             * withdrawal date onto a customer who never decided anything.
             */
            if ($row['marketing_consent'] === true) {
                $this->consent->set($userId, true, 'registration');
            }

            $ids[$row['email']] = $userId;
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, int>         $categoryIds
     * @param array<string, mixed>       $result by reference
     */
    private function seedCoupons(array $rows, array $categoryIds, array &$result): void
    {
        foreach ($rows as $row) {
            $payload = $row['fields'];

            if ($row['categories'] !== []) {
                $payload['product_categories'] = array_values(array_map(
                    static fn (string $slug): int => $categoryIds[$slug] ?? 0,
                    $row['categories']
                ));
            }

            try {
                $existingId = (int) wc_get_coupon_id_by_code($row['code']);

                if ($existingId > 0) {
                    // The code identifies the coupon and cannot be changed into
                    // itself: CouponInput treats a code that already belongs to
                    // another coupon as a conflict.
                    unset($payload['code']);
                    $this->coupons->update($existingId, $payload);
                    $result['coupons']['updated']++;
                } else {
                    $this->coupons->create($payload);
                    $result['coupons']['created']++;
                }
            } catch (ApiException | Throwable $e) {
                $result['errors'][] = "coupon {$row['code']}: " . $e->getMessage();
            }
        }
    }

    /**
     * @param list<array<string, mixed>>                              $rows
     * @param array<string, array{product_id: int, variation_id: int}> $productIds
     * @param array<string, int>                                       $customerIds
     * @param list<array<string, mixed>>                               $customerRows
     * @param array<string, mixed>                                     $result by reference
     */
    private function seedOrders(
        array $rows,
        array $productIds,
        array $customerIds,
        array $customerRows,
        array &$result
    ): void {
        $ledger = get_option(self::ORDER_LEDGER_OPTION, []);
        $ledger = is_array($ledger) ? $ledger : [];

        $byEmail = [];

        foreach ($customerRows as $customer) {
            $byEmail[$customer['email']] = $customer;
        }

        foreach ($rows as $row) {
            $ref = $row['ref'];
            $known = isset($ledger[$ref]) ? (int) $ledger[$ref] : 0;

            /*
             * The ledger is checked against reality, not believed. An order
             * deleted by hand (or by reset.sh against a stale option) would
             * otherwise make every later run report "updated" for something
             * that is not there.
             */
            if ($known > 0 && wc_get_order($known) !== false) {
                $result['orders']['updated']++;
                continue;
            }

            $billing = $row['billing'];
            $customerId = 0;

            if ($row['customer'] !== null) {
                $customerId = $customerIds[$row['customer']] ?? 0;
                $customer = $byEmail[$row['customer']] ?? null;

                if ($customer !== null) {
                    // The fixture's own billing block wins where it says
                    // anything; otherwise the order is addressed to the account.
                    $base = $customer['billing'];
                    $base['first_name'] = $customer['first_name'];
                    $base['last_name'] = $customer['last_name'];
                    $base['email'] = $customer['email'];

                    if ($customer['phone'] !== '') {
                        $base['phone'] = $customer['phone'];
                    }

                    $billing = [...$base, ...$billing];
                }
            }

            $lineItems = [];
            $missing = false;

            foreach ($row['items'] as $item) {
                $ids = $productIds[strtolower($item['sku'])] ?? null;

                if ($ids === null) {
                    $result['errors'][] = "order {$ref}: sku {$item['sku']} was not seeded.";
                    $missing = true;
                    break;
                }

                $line = ['product_id' => $ids['product_id'], 'quantity' => $item['quantity']];

                if ($ids['variation_id'] > 0) {
                    $line['variation_id'] = $ids['variation_id'];
                }

                $lineItems[] = $line;
            }

            if ($missing) {
                continue;
            }

            $payload = [
                ...$row['fields'],
                'status' => $row['status'],
                'customer_id' => $customerId,
                'billing' => $billing,
                'shipping' => $row['shipping'] !== []
                    ? $row['shipping']
                    : array_diff_key($billing, array_flip(['email'])),
                'line_items' => $lineItems,
            ];

            try {
                $order = $this->orders->create($payload);

                if ($row['final_status'] !== null) {
                    // Reached by a second write on purpose: `cancelled` and
                    // `refunded` are not creatable states (OrderStatus), and a
                    // seed that forced them would be recording a cancellation
                    // of something that was never placed.
                    $this->orders->update($order->get_id(), ['status' => $row['final_status']]);
                }

                $ledger[$ref] = $order->get_id();
                $result['orders']['created']++;
            } catch (ApiException | Throwable $e) {
                $result['errors'][] = "order {$ref}: " . $e->getMessage();
            }
        }

        update_option(self::ORDER_LEDGER_OPTION, $ledger, false);
    }

    /**
     * @param list<string> $errors by reference
     * @return array<mixed>|null null when the file is missing or unreadable
     */
    private function read(string $file, array &$errors): ?array
    {
        $path = rtrim($this->dataPath, '/') . '/' . $file;

        if (!is_readable($path)) {
            $errors[] = "{$file} is missing from {$this->dataPath}.";

            return null;
        }

        $contents = file_get_contents($path);
        $decoded = $contents === false ? null : json_decode($contents, true);

        if (!is_array($decoded)) {
            $errors[] = "{$file} is not valid JSON.";

            return null;
        }

        return $decoded;
    }
}
