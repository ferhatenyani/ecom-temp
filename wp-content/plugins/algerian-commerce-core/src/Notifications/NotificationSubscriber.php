<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\Shipping\Shipment;
use AlgerianCommerce\Shipping\ShipmentStatus;
use WC_Order;
use WC_Product;

/**
 * Turns things that happen into things worth saying — docs/PLAN.md §29, §30.
 *
 * **Everything here queues and returns.** Not one of these callbacks sends
 * anything: they run inside an order save, a status transition or a stock
 * write, which are exactly the request paths §29's queue exists to keep an SMTP
 * connection off. `NotificationService::drain()` does the sending, from the CLI
 * command or the cron.
 *
 * The hooks are WooCommerce's own wherever one exists, so this class adds no
 * coupling that a future notification channel would inherit. The single
 * exception is `ac_shipment_saved`, emitted by `ShipmentRepository` because
 * WooCommerce has no opinion about our parcels — see that emission for why it
 * sits in the repository rather than the service.
 *
 * **Duplicate suppression is not this class's job.** Several of these hooks
 * fire more than once for one real event — `woocommerce_order_status_changed`
 * runs on every transition, `ac_shipment_saved` on every write — and none of
 * them is filtered here. `ac_notifications`' unique claim
 * (`channel` + `event:subject_id`) is what makes one message out of many
 * firings, which is a database guarantee rather than a comparison that has to
 * be right in eight places.
 */
final class NotificationSubscriber
{
    public function __construct(private readonly NotificationService $service)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_new_order', [$this, 'onNewOrder'], 10, 2);
        add_action('woocommerce_order_status_changed', [$this, 'onStatusChanged'], 10, 4);
        add_action('ac_shipment_saved', [$this, 'onShipmentSaved'], 10, 1);

        // WooCommerce's own low-stock signal, fired when a purchase takes a
        // product to or below its threshold.
        add_action('woocommerce_low_stock', [$this, 'onLowStock'], 10, 1);

        // ...and the other half, which WooCommerce does not provide: a product
        // restocked above its threshold clears the claim, so the shop can be
        // warned again next time. Without this a line warns once, ever.
        add_action('woocommerce_product_set_stock', [$this, 'onStockSet'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'onStockSet'], 10, 1);
    }

    /** @param mixed $order */
    public function onNewOrder(int $orderId, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $this->queueOrderEvent(NotificationEvent::ORDER_PLACED, $order);
        $this->queueOrderEvent(NotificationEvent::ADMIN_NEW_ORDER, $order);
    }

    public function onStatusChanged(int $orderId, string $from, string $to, $order = null): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $event = match ($to) {
            // `processing` and `completed` both mean the shop has the money for
            // a prepaid order; COD reaches `processing` without payment, which
            // is why the message says "payment received" only for orders that
            // record one. `is_paid()` is WooCommerce's own answer to that.
            'processing', 'completed' => $order->is_paid() ? NotificationEvent::PAYMENT_RECEIVED : '',
            'cancelled' => NotificationEvent::ORDER_CANCELLED,
            'refunded' => NotificationEvent::ORDER_REFUNDED,
            default => '',
        };

        if ($event !== '') {
            $this->queueOrderEvent($event, $order);
        }
    }

    public function onShipmentSaved(mixed $shipment): void
    {
        if (!$shipment instanceof Shipment) {
            return;
        }

        /*
         * `ShipmentStatus` has no "shipped" — its vocabulary is the courier's,
         * and a parcel goes `created` → `picked_up` → `in_transit` →
         * `out_for_delivery` → `delivered`. "On its way" is the first of those
         * a customer cares about, which is `picked_up`: the courier physically
         * has the parcel. `in_transit` maps to the same message because a
         * courier that never reports a pickup would otherwise say nothing until
         * delivery, and the claim makes the pair produce one message either way.
         */
        $event = match ($shipment->status) {
            ShipmentStatus::PICKED_UP, ShipmentStatus::IN_TRANSIT => NotificationEvent::SHIPMENT_SHIPPED,
            ShipmentStatus::DELIVERED => NotificationEvent::SHIPMENT_DELIVERED,
            default => '',
        };

        if ($event === '') {
            return;
        }

        $order = wc_get_order($shipment->orderId);

        if (!$order instanceof WC_Order) {
            return;
        }

        $this->queueOrderEvent($event, $order, [
            'provider' => $shipment->provider,
            'tracking_number' => $shipment->trackingNumber,
        ]);
    }

    public function onLowStock(mixed $product): void
    {
        if (!$product instanceof WC_Product) {
            return;
        }

        $recipient = $this->adminAddress();

        if ($recipient === '') {
            return;
        }

        $context = [
            'product_name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'stock' => (string) $product->get_stock_quantity(),
        ];

        $message = NotificationMessages::render(NotificationEvent::STOCK_LOW, $this->shopName(), $context);

        $this->service->notify(Notification::toAdmin(
            NotificationEvent::STOCK_LOW,
            $recipient,
            $message['subject'],
            $message['body'],
            'product',
            $product->get_id(),
            $context
        ));
    }

    /**
     * Clear a low-stock claim once the line is back above its threshold.
     *
     * The claim is what stops an hourly email about a line that has been low
     * all week; this is what stops that same claim silencing the *next*
     * warning forever.
     */
    public function onStockSet(mixed $product): void
    {
        if (!$product instanceof WC_Product || !$product->managing_stock()) {
            return;
        }

        $quantity = $product->get_stock_quantity();
        $threshold = (int) wc_get_low_stock_amount($product);

        if ($quantity !== null && (int) $quantity > $threshold) {
            $this->service->repository()->forget(NotificationEvent::STOCK_LOW, $product->get_id());
        }
    }

    /** @param array<string, mixed> $extra */
    private function queueOrderEvent(string $event, WC_Order $order, array $extra = []): void
    {
        $admin = NotificationEvent::isAdmin($event);
        $recipient = $admin ? $this->adminAddress() : $order->get_billing_email();

        if (trim((string) $recipient) === '') {
            // A guest order with no email, or a shop with no admin address
            // configured. Nothing to queue and nothing wrong.
            return;
        }

        $context = [
            'order_number' => $order->get_order_number(),
            'total' => (string) wc_format_decimal($order->get_total(), wc_get_price_decimals()),
            'currency' => $order->get_currency(),
            'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
        ] + $extra;

        $message = NotificationMessages::render($event, $this->shopName(), $context);

        if ($message['subject'] === '') {
            return;
        }

        $notification = $admin
            ? Notification::toAdmin($event, $recipient, $message['subject'], $message['body'], 'order', $order->get_id(), $context)
            : Notification::toCustomer($event, $recipient, $message['subject'], $message['body'], 'order', $order->get_id(), $context);

        $this->service->notify($notification);
    }

    /**
     * Where admin alerts go.
     *
     * `AC_ADMIN_EMAIL` first so a deployment can send operational mail
     * somewhere other than the WordPress administrator's own inbox, which on a
     * headless install is often a placeholder nobody reads.
     */
    private function adminAddress(): string
    {
        $configured = (string) getenv('AC_ADMIN_EMAIL');

        if (trim($configured) !== '') {
            return trim($configured);
        }

        return (string) get_option('admin_email', '');
    }

    private function shopName(): string
    {
        $name = (string) get_option('blogname', '');

        return $name !== '' ? $name : 'The shop';
    }
}
