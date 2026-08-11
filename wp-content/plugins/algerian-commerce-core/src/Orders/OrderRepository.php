<?php

declare(strict_types=1);

namespace AlgerianCommerce\Orders;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Commerce\AddressInput;
use WC_Order;
use WC_Product;
use WC_Product_Variation;

/**
 * The WooCommerce adapter for orders.
 *
 * The only place that knows WC_Order exists. Everything above it works with
 * validated input objects and plain arrays, so the domain never depends on
 * WooCommerce's data model (docs/ARCHITECTURE.md §2).
 *
 * No direct SQL and no `get_post()`: orders are reached through wc_get_order()
 * and the CRUD layer, which is what makes the plugin's HPOS compatibility
 * declaration true. Reading wp_posts here would silently break every install
 * that has switched to the orders tables — and every install created after
 * WooCommerce 8.2, where HPOS is the default.
 */
final class OrderRepository
{
    /** Sortable columns WooCommerce's order query actually understands. */
    public const ORDERBY = ['date', 'id', 'modified', 'total'];

    public function find(int $id): ?WC_Order
    {
        $order = wc_get_order($id);

        // wc_get_order() also returns refunds, which are a different object
        // type sharing the id space. WC_Order_Refund extends the abstract, not
        // WC_Order, so this check excludes them.
        return $order instanceof WC_Order ? $order : null;
    }

    /**
     * @param array{page: int, per_page: int, search: string, status: string, customer_id: int, date_from: string, date_to: string, orderby: string, order: string} $criteria
     * @return array{items: list<WC_Order>, total: int}
     */
    public function paginate(array $criteria): array
    {
        $args = [
            'limit' => $criteria['per_page'],
            'page' => $criteria['page'],
            'paginate' => true,
            /*
             * Orders only. WooCommerce's default here is every type registered
             * for order views, which includes `shop_order_refund` — refunds
             * share the id space and are a different object that this endpoint
             * neither presents nor promises.
             */
            'type' => 'shop_order',
            'orderby' => in_array($criteria['orderby'], self::ORDERBY, true) ? $criteria['orderby'] : 'date',
            'order' => strtoupper($criteria['order']) === 'ASC' ? 'ASC' : 'DESC',
        ];

        // Left unset when no status filter is given: WooCommerce's default is
        // every registered order status, which is what "all orders" means and
        // already excludes the storefront's internal checkout drafts.
        if ($criteria['status'] !== '') {
            $args['status'] = [$criteria['status']];
        }

        if ($criteria['customer_id'] > 0) {
            $args['customer_id'] = $criteria['customer_id'];
        }

        if ($criteria['search'] !== '') {
            /*
             * Free-text search across order id, customer and product names.
             * This is an HPOS capability — OrdersTableSearchQuery joins the
             * address and items tables to do it. Under legacy post storage `s`
             * degrades to a post-content search and finds almost nothing,
             * which is one more reason this install runs on HPOS.
             */
            $args['s'] = $criteria['search'];
            $args['search_filter'] = 'all';
        }

        $range = self::dateRange($criteria['date_from'], $criteria['date_to']);

        if ($range !== '') {
            $args['date_created'] = $range;
        }

        $results = wc_get_orders($args);

        return [
            'items' => is_object($results) ? $results->orders : [],
            'total' => is_object($results) ? (int) $results->total : 0,
        ];
    }

    /**
     * Create an order, then bring it to the requested status.
     *
     * The sequence is deliberate and the comments are the reason it is not
     * shorter:
     *
     *  1. Resolve every line to a real product *before* anything is written.
     *     There is no transaction across a WooCommerce save, so a line naming
     *     a product that does not exist has to fail while there is still
     *     nothing to undo — otherwise a 400 leaves an empty order behind in
     *     the order book.
     *  2. Save the bare order. `add_product()` stamps the item with
     *     `$order->get_id()` and saves it immediately, so adding a line to an
     *     unsaved order orphans that line against order 0.
     *  3. Add the lines and calculate totals from the catalogue.
     *  4. Only then set the requested status. `WC_Order::save()` fires the
     *     status transition *after* the items are persisted, and the
     *     transition into `processing`, `on-hold` or `completed` is what makes
     *     WooCommerce reduce stock — so the lines have to exist first or the
     *     order takes stock for nothing.
     */
    public function create(OrderInput $input): WC_Order
    {
        $lines = $this->resolveLines($input->lineItems());

        $order = new WC_Order();

        $this->applyProps($order, $input);
        $order->save();

        $this->replaceLineItems($order, $lines);
        $order->calculate_totals();

        return $this->applyStatus($order, $input);
    }

    /**
     * The caller has already checked that the status change is a legal
     * transition and that line items may be rewritten — those are policy, and
     * policy lives in the service.
     */
    public function update(WC_Order $order, OrderInput $input): WC_Order
    {
        // Resolved first for the same reason as on create, and one more:
        // replaceLineItems() deletes the existing lines before adding the new
        // ones, so a bad product id partway through would leave the order
        // stripped of the items it had.
        $lines = $input->has('line_items') ? $this->resolveLines($input->lineItems()) : null;

        $this->applyProps($order, $input);

        if ($lines !== null) {
            $this->rewriteLineItems($order, $lines);
        } else {
            $order->save();
        }

        return $this->applyStatus($order, $input);
    }

    /**
     * Replace an order's lines, returning and re-taking stock if it held any.
     *
     * An order that has already reduced stock carries a `_reduced_stock` marker
     * on each item recording how many units that line took. Replacing the items
     * destroys the markers, and nothing afterwards can return those units — the
     * ledger is left with a reduction and no matching restoration.
     *
     * So the stock is unwound first and re-taken after, through the same two
     * functions WooCommerce uses itself. The `maybe_` wrappers are the right
     * entry points because they also maintain the order's `stock_reduced` flag;
     * calling wc_increase_stock_levels() directly would move the units and
     * leave the flag saying they were still held.
     *
     * Both fire the hooks OrderStockSubscriber listens to, so the ledger records
     * the whole manoeuvre — a restoration of the old lines, then a reduction for
     * the new ones — instead of a quantity that changes with nothing to explain
     * it. That verbosity is the point: the shelf really did move twice.
     *
     * WooCommerce's own helper for adjusting a single line in place,
     * wc_maybe_adjust_line_item_product_stock(), is not usable here — it lives
     * in an admin-only file that is not loaded during a REST request.
     *
     * @param list<array{product: WC_Product, quantity: int}> $lines
     */
    private function rewriteLineItems(WC_Order $order, array $lines): void
    {
        $orderId = $order->get_id();
        $heldStock = self::stockReduced($order);

        if ($heldStock) {
            wc_maybe_increase_stock_levels($orderId);
        }

        $this->replaceLineItems($order, $lines);
        // Saves, so the new lines are in the database before the re-reduction
        // below — and before any status transition can act on them.
        $order->calculate_totals();

        if ($heldStock) {
            /*
             * Guarded on $heldStock rather than run unconditionally: reducing
             * here for an order that was never holding stock — a pending one —
             * would take units off the shelf for an order nobody has confirmed.
             */
            wc_maybe_reduce_stock_levels($orderId);
        }
    }

    private function applyStatus(WC_Order $order, OrderInput $input): WC_Order
    {
        $status = (string) ($input->get('status') ?? '');

        if ($status !== '' && $status !== $order->get_status()) {
            $order->set_status($status);
            $order->save();
        }

        return $this->find($order->get_id()) ?? $order;
    }

    /**
     * Every order a customer has placed, oldest first, projected to the few
     * fields the statistics need.
     *
     * One query rather than a COUNT per status: the counts, the revenue and the
     * first and last order all fall out of a single pass, and they cannot
     * disagree with each other the way five separate queries can.
     *
     * `limit => -1` is bounded by *one customer's* order count, not the shop's,
     * which is a few dozen rows for a retail buyer. Store-wide reporting is a
     * different problem with a different answer — §60's `ac_analytics_aggregates`,
     * which exists precisely so a dashboard never scans the order book.
     *
     * @return list<array{id: int, status: string, total: string, date_created: ?string}>
     */
    public function customerOrderSummaries(int $customerId): array
    {
        $orders = wc_get_orders([
            'customer_id' => $customerId,
            'type' => 'shop_order',
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $summaries = [];

        foreach (is_array($orders) ? $orders : [] as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }

            $summaries[] = [
                'id' => $order->get_id(),
                'status' => $order->get_status(),
                'total' => (string) $order->get_total(),
                'date_created' => self::iso($order->get_date_created()),
            ];
        }

        return $summaries;
    }

    private static function iso(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date') ? $date->date('c') : null;
    }

    /**
     * Write a note against an order.
     *
     * `$isCustomerNote` is passed straight through because WooCommerce acts on
     * it: a customer note is emailed to the buyer as it is saved. The third
     * argument marks the note as added by the signed-in user rather than by
     * "WooCommerce", so the timeline can attribute it.
     *
     * @return int the new note id
     */
    public function addNote(WC_Order $order, OrderNoteInput $note): int
    {
        return (int) $order->add_order_note($note->note, $note->customerNote ? 1 : 0, true);
    }

    /**
     * @return list<array<string, mixed>> newest first, in the shape
     *         OrderTimeline and OrderPresenter both consume
     */
    public function notes(int $orderId, int $limit): array
    {
        $notes = wc_get_order_notes([
            'order_id' => $orderId,
            'limit' => $limit,
            'orderby' => 'date_created',
            'order' => 'DESC',
        ]);

        $rows = [];

        foreach (is_array($notes) ? $notes : [] as $note) {
            $rows[] = [
                'id' => (int) $note->id,
                'content' => (string) $note->content,
                'customer_note' => (bool) $note->customer_note,
                // WooCommerce reports its own automatic notes as "system".
                'added_by' => (string) $note->added_by,
                // Normalized to the 'Y-m-d H:i:s' UTC that the audit and
                // ledger tables store, so the timeline sorts one kind of value.
                'created_at' => self::utc($note->date_created),
            ];
        }

        return $rows;
    }

    /**
     * A WC_DateTime to 'Y-m-d H:i:s' in UTC.
     *
     * WooCommerce hands back a WC_DateTime carrying the *site* timezone. Taking
     * its ->date('Y-m-d H:i:s') would mix local wall-clock times into a feed
     * sorted against two tables that store UTC, and the timeline would
     * interleave wrongly by exactly the UTC offset.
     */
    private static function utc(mixed $date): string
    {
        if (!is_object($date) || !method_exists($date, 'getTimestamp')) {
            return '';
        }

        return gmdate('Y-m-d H:i:s', $date->getTimestamp());
    }

    /** The caller has already checked that the transition is legal. */
    public function changeStatus(WC_Order $order, string $status): WC_Order
    {
        if ($status !== $order->get_status()) {
            $order->set_status($status);
            $order->save();
        }

        return $this->find($order->get_id()) ?? $order;
    }

    /**
     * Whether WooCommerce has already taken this order's stock.
     *
     * A flag on the order, not a derivation from its status: WooCommerce
     * reduces stock once and records that it did, so an order can be `on-hold`
     * — a status that reduces stock — and still be holding none, because it
     * arrived there from `cancelled`.
     *
     * Static because the presenter needs the same answer, and this is the only
     * file allowed to ask a data store for it.
     *
     * `has_callable()` rather than `method_exists()`, and the difference is not
     * cosmetic: WC_Data_Store is a decorator that forwards to the real store
     * through `__call()`, so `method_exists()` answers "no" for every method
     * the store actually has. Using it here silently pinned this to false —
     * every order reported holding no stock, and the guard that stops line
     * items being rewritten on a committed order never fired.
     */
    public static function stockReduced(WC_Order $order): bool
    {
        $store = $order->get_data_store();

        if (!is_object($store) || !$store->has_callable('get_stock_reduced')) {
            return false;
        }

        return (bool) $store->get_stock_reduced($order->get_id());
    }

    /** Everything except status and line items, both of which are ordered. */
    private function applyProps(WC_Order $order, OrderInput $input): void
    {
        foreach (['payment_method' => 'set_payment_method',
            'payment_method_title' => 'set_payment_method_title',
            'customer_note' => 'set_customer_note'] as $field => $setter) {
            if ($input->has($field)) {
                $order->{$setter}((string) $input->get($field));
            }
        }

        if ($input->has('customer_id')) {
            $order->set_customer_id($this->assertCustomer((int) $input->get('customer_id')));
        }

        foreach (['billing', 'shipping'] as $type) {
            if (!$input->has($type)) {
                continue;
            }

            /** @var AddressInput $address */
            $address = $input->get($type);

            foreach ($address->fields as $field => $value) {
                $order->{"set_{$type}_{$field}"}($value);
            }
        }
    }

    /**
     * A customer id must belong to a real user.
     *
     * WooCommerce stores whatever it is given, so an unchecked id produces an
     * order attributed to a user that does not exist — invisible in the
     * customer's order history and impossible to reconcile later. 0 is the
     * documented value for a guest order.
     */
    private function assertCustomer(int $customerId): int
    {
        if ($customerId === 0 || get_userdata($customerId) !== false) {
            return $customerId;
        }

        throw ApiException::invalidRequest('The order data is invalid.', [
            'fields' => ['customer_id' => "No user with id {$customerId}."],
        ]);
    }

    /**
     * Replace the product lines wholesale.
     *
     * A PATCH that sends `line_items` states what the order now contains, so
     * anything absent is removed. Partial line edits would need a stable line
     * id in the payload and a per-line stock reconciliation; the service only
     * allows this path on an order that has not yet taken stock, which is what
     * makes wholesale replacement safe.
     *
     * @param list<array{product: WC_Product, quantity: int}> $lines
     */
    private function replaceLineItems(WC_Order $order, array $lines): void
    {
        /*
         * remove_item(), not remove_order_items(). The plural form deletes the
         * rows immediately and unsets the in-memory group; the next
         * add_product() then re-reads that group from the database, and the
         * line it just saved is dropped on the following save — an order that
         * carries the right total and no items at all. remove_item() instead
         * queues the item, and save_items() processes the deletions before it
         * writes the new lines, which is the order that actually works.
         *
         * Only line items are touched. Shipping, fee and tax lines belong to
         * other phases and must survive a line edit.
         */
        foreach ($order->get_items('line_item') as $existing) {
            $order->remove_item($existing->get_id());
        }

        foreach ($lines as $line) {
            // add_product() prices the line from the catalogue — see
            // LineItemInput on why no price arrives from the caller.
            $order->add_product($line['product'], $line['quantity']);
        }
    }

    /**
     * @param list<LineItemInput> $items
     * @return list<array{product: WC_Product, quantity: int}>
     */
    private function resolveLines(array $items): array
    {
        $lines = [];

        foreach ($items as $index => $item) {
            $lines[] = [
                'product' => $this->resolveProduct($item, $index),
                'quantity' => $item->quantity,
            ];
        }

        return $lines;
    }

    /**
     * Resolve a line to the product WooCommerce should price and stock.
     *
     * A variation is addressed by its own id, and the parent must match: an
     * order line pairing product A with a variation of product B would price
     * from one and reduce stock on the other.
     */
    private function resolveProduct(LineItemInput $item, int $index): WC_Product
    {
        $field = "line_items.{$index}";

        if ($item->variationId === 0) {
            $product = wc_get_product($item->productId);

            if (!$product instanceof WC_Product) {
                throw ApiException::invalidRequest('The order data is invalid.', [
                    'fields' => ["{$field}.product_id" => "No product with id {$item->productId}."],
                ]);
            }

            if ($product->is_type('variable')) {
                throw ApiException::invalidRequest('The order data is invalid.', [
                    'fields' => ["{$field}.variation_id" => 'This is a variable product; name the variation to order.'],
                ]);
            }

            return $product;
        }

        $variation = wc_get_product($item->variationId);

        if (!$variation instanceof WC_Product_Variation) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ["{$field}.variation_id" => "No variation with id {$item->variationId}."],
            ]);
        }

        if ((int) $variation->get_parent_id() !== $item->productId) {
            throw ApiException::invalidRequest('The order data is invalid.', [
                'fields' => ["{$field}.variation_id" => 'That variation belongs to a different product.'],
            ]);
        }

        return $variation;
    }

    /**
     * WooCommerce's own range syntax for a date query var.
     *
     * A bare `Y-m-d` covers the whole day at both ends, so from == to returns
     * that day rather than nothing.
     *
     * Public because COD's funnel counts orders over the same windows this
     * endpoint filters on, and two implementations of "what does date_from mean
     * at the edges" would eventually disagree by a day.
     */
    public static function dateRange(string $from, string $to): string
    {
        if ($from !== '' && $to !== '') {
            return "{$from}...{$to}";
        }

        if ($from !== '') {
            return ">={$from}";
        }

        return $to !== '' ? "<={$to}" : '';
    }
}
