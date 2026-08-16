<?php

declare(strict_types=1);

namespace AlgerianCommerce\ImportExport;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Customers\CustomerRepository;
use AlgerianCommerce\Inventory\InventoryRepository;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use WC_Customer;
use WC_Order;
use WC_Product;

/**
 * CSV exports — roadmap §64, docs/PLAN.md §33.
 *
 * **Each export carries the capability of the thing it exports.** An order
 * export is the order book in one file, so it needs `ac_manage_orders`; a
 * customer export is every customer's name, phone and address, so it needs
 * `ac_manage_customers`. There is no separate "export" capability and there
 * should not be: §63's rule was that reporting may not disclose in aggregate
 * what the caller cannot read in detail, and an export is the strongest form
 * of that — not a summary but the records themselves, in a file that leaves
 * the building.
 *
 * **Every export is bounded.** `MAX_ROWS` is the same argument
 * `AnalyticsRange::MAX_DAYS` makes: this pipeline is stateless by §64's design,
 * so one request does the whole job, and "export everything" from a shop with
 * two hundred thousand orders is a request with no upper cost. The refusal
 * names the limit and the filters that narrow it, which is something a person
 * can act on; a timeout is not.
 *
 * Products reuse WooCommerce's own exporter (see `WooCsv`). The other three
 * formats are ours, because WooCommerce has no CSV exporter for orders,
 * customers or stock — and they are written through `CsvWriter`, which carries
 * the formula escaping that stops an export executing on the shop owner's
 * laptop.
 */
final class ExportService
{
    /**
     * The most rows one export will produce.
     *
     * Deliberately the same order of magnitude as `CsvReader::MAX_ROWS`: the
     * two halves of §64 should agree about what "one request's worth" means, or
     * a shop can export a file it cannot import back.
     */
    public const MAX_ROWS = 2000;

    /** Read in pages so no export holds the whole result set as objects. */
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly CustomerRepository $customers,
        private readonly InventoryRepository $inventory
    ) {
    }

    /**
     * Products, in WooCommerce's own 40-column format.
     *
     * @param array<string, mixed> $params
     * @return array{filename: string, body: string, rows: int}
     */
    public function products(array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_PRODUCTS);

        WooCsv::load();

        $limit = $this->limit($params);

        /*
         * Referenced only after WooCsv::load(): ProductCsvExporter extends a
         * class that is not autoloaded in a non-admin request, and PHP resolves
         * a parent when the subclass loads.
         */
        $exporter = new ProductCsvExporter();
        $exporter->set_limit($limit);

        return [
            'filename' => $this->filename('products'),
            // WooCommerce's exporter escapes formulas itself
            // (WC_CSV_Exporter::escape_data) — CsvWriter matches it, so a shop
            // opening a product export and an order export finds both safe.
            'body' => $exporter->toCsv(),
            'rows' => (int) $exporter->get_total_exported(),
        ];
    }

    /**
     * Stock levels, in the narrow format `InventoryRow` imports back.
     *
     * Round-tripping is the point: a warehouse exports this, counts, edits the
     * quantity column and uploads the same file. Any column added here that
     * the importer ignores is a column somebody will edit expecting an effect.
     *
     * @param array<string, mixed> $params
     * @return array{filename: string, body: string, rows: int}
     */
    public function inventory(array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_INVENTORY);

        $writer = new CsvWriter([
            InventoryRow::SKU,
            InventoryRow::QUANTITY,
            InventoryRow::STATUS,
            InventoryRow::MANAGE,
            'name',
            'product_id',
        ]);

        $limit = $this->limit($params);
        $page = 1;

        while ($writer->rowCount() < $limit) {
            $batch = $this->inventory->paginate([
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'search' => '',
                'sku' => '',
                'status' => '',
                'category' => '',
                'stock_status' => '',
                'manage_stock' => '',
                'include_variations' => true,
            ]);

            /** @var list<WC_Product> $items */
            $items = $batch['items'];

            if ($items === []) {
                break;
            }

            foreach ($items as $product) {
                if ($writer->rowCount() >= $limit) {
                    break;
                }

                $writer->append([
                    InventoryRow::SKU => $product->get_sku(),
                    // An unmanaged product has a null quantity, and '' is the
                    // honest rendering — 0 would read as "none in stock".
                    InventoryRow::QUANTITY => $product->get_manage_stock()
                        ? (int) $product->get_stock_quantity()
                        : '',
                    InventoryRow::STATUS => $product->get_stock_status(),
                    InventoryRow::MANAGE => $product->get_manage_stock() ? '1' : '0',
                    'name' => $product->get_name(),
                    'product_id' => $product->get_id(),
                ]);
            }

            $page++;
        }

        return $this->finish($writer, 'inventory');
    }

    /**
     * The order book — roadmap §64's "orders export".
     *
     * One row per order, not per line item: a shop exports this for its
     * accountant, and a file where one order occupies four rows has to be
     * pivoted before it can be summed. Line detail belongs to a products
     * report, which is `/analytics/products`.
     *
     * @param array<string, mixed> $params
     * @return array{filename: string, body: string, rows: int}
     */
    public function orders(array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_ORDERS);

        $writer = new CsvWriter([
            'order_id', 'date_created', 'status', 'currency', 'total', 'shipping_total',
            'discount_total', 'payment_method', 'customer_id', 'billing_name', 'billing_phone',
            'billing_email', 'shipping_city', 'shipping_state', 'items',
        ]);

        $limit = $this->limit($params);
        $page = 1;

        while ($writer->rowCount() < $limit) {
            $batch = $this->orders->paginate([
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'search' => '',
                'status' => (string) ($params['status'] ?? ''),
                'customer_id' => 0,
                'date_from' => (string) ($params['date_from'] ?? ''),
                'date_to' => (string) ($params['date_to'] ?? ''),
                'orderby' => 'date',
                'order' => 'DESC',
            ]);

            /** @var list<WC_Order> $items */
            $items = $batch['items'];

            if ($items === []) {
                break;
            }

            foreach ($items as $order) {
                if ($writer->rowCount() >= $limit) {
                    break;
                }

                $created = $order->get_date_created();

                $writer->append([
                    'order_id' => $order->get_id(),
                    'date_created' => is_object($created) ? $created->date('c') : '',
                    'status' => $order->get_status(),
                    'currency' => $order->get_currency(),
                    'total' => $order->get_total(),
                    'shipping_total' => $order->get_shipping_total(),
                    'discount_total' => $order->get_discount_total(),
                    'payment_method' => $order->get_payment_method(),
                    'customer_id' => $order->get_customer_id(),
                    'billing_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'billing_phone' => $order->get_billing_phone(),
                    'billing_email' => $order->get_billing_email(),
                    'shipping_city' => $order->get_shipping_city(),
                    'shipping_state' => $order->get_shipping_state(),
                    'items' => $order->get_item_count(),
                ]);
            }

            $page++;
        }

        return $this->finish($writer, 'orders');
    }

    /**
     * Customer records — roadmap §64's "customers export".
     *
     * **No lifetime statistics.** `CustomerStatistics` is computed from a
     * customer's whole order history, so a column of it here would be one query
     * per row — two thousand of them in one request. A shop that wants spend
     * per customer has `GET /customers/{id}`, which computes it for one, and
     * `/analytics/customers` for the shape of the whole base.
     *
     * @param array<string, mixed> $params
     * @return array{filename: string, body: string, rows: int}
     */
    public function customersExport(array $params): array
    {
        Permissions::assert(Capabilities::MANAGE_CUSTOMERS);

        $writer = new CsvWriter([
            'customer_id', 'email', 'first_name', 'last_name', 'phone',
            'address_1', 'city', 'state', 'country', 'date_registered',
        ]);

        $limit = $this->limit($params);
        $page = 1;

        while ($writer->rowCount() < $limit) {
            $batch = $this->customers->paginate([
                'page' => $page,
                'per_page' => self::PAGE_SIZE,
                'search' => '',
                'orderby' => 'registered',
                'order' => 'DESC',
            ]);

            /** @var list<WC_Customer> $items */
            $items = $batch['items'];

            if ($items === []) {
                break;
            }

            foreach ($items as $customer) {
                if ($writer->rowCount() >= $limit) {
                    break;
                }

                $registered = $customer->get_date_created();

                $writer->append([
                    'customer_id' => $customer->get_id(),
                    'email' => $customer->get_email(),
                    'first_name' => $customer->get_first_name(),
                    'last_name' => $customer->get_last_name(),
                    'phone' => $customer->get_billing_phone(),
                    'address_1' => $customer->get_billing_address_1(),
                    'city' => $customer->get_billing_city(),
                    'state' => $customer->get_billing_state(),
                    'country' => $customer->get_billing_country(),
                    'date_registered' => is_object($registered) ? $registered->date('c') : '',
                ]);
            }

            $page++;
        }

        return $this->finish($writer, 'customers');
    }

    /**
     * @return array{filename: string, body: string, rows: int}
     */
    private function finish(CsvWriter $writer, string $subject): array
    {
        return [
            'filename' => $this->filename($subject),
            'body' => $writer->toString(),
            'rows' => $writer->rowCount(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @throws ApiException 400 when asked for more than one request can do
     */
    private function limit(array $params): int
    {
        $limit = (int) ($params['limit'] ?? self::MAX_ROWS);

        if ($limit < 1) {
            $limit = self::MAX_ROWS;
        }

        if ($limit > self::MAX_ROWS) {
            throw ApiException::invalidRequest('That export is too large for one request.', [
                'fields' => [
                    'limit' => 'At most ' . self::MAX_ROWS . ' rows. Narrow the export with the '
                        . 'available filters and take it in parts.',
                ],
            ]);
        }

        return $limit;
    }

    /**
     * A stable, sortable filename — never built from anything a caller sent.
     *
     * `FileDownload` sanitises it again on the way into the header, which is
     * the layer that does not depend on this one being right.
     */
    private function filename(string $subject): string
    {
        return sprintf('%s-export-%s.csv', $subject, gmdate('Y-m-d'));
    }
}
