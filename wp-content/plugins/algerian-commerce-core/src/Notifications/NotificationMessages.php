<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * What each notification actually says — docs/PLAN.md §30.
 *
 * Pure: strings in, strings out, no WordPress and no WooCommerce, so every
 * message is a unit test and none of them can accidentally reach for an order.
 *
 * **Plain text, and deliberately so.** An HTML template is a rendering concern,
 * and §61 and §62 both record what happens to rendering concerns in a headless
 * install: the half that renders never runs where anyone expected. A shop that
 * wants branded HTML mail has a mail service that does templating far better
 * than a PHP heredoc, and the seam for it is `NotificationChannelInterface` —
 * a channel is free to treat `body` as a payload and render its own.
 *
 * Text is also what SMS and WhatsApp need, which are the next two channels §29
 * names, so the common shape is the one that survives.
 *
 * No prices are formatted here beyond what the caller passes in: money arrives
 * as a decimal string that has already been through `wc_format_decimal()`, as
 * everywhere else in this API.
 */
final class NotificationMessages
{
    /**
     * @param array<string, mixed> $context
     * @return array{subject: string, body: string}
     */
    public static function render(string $event, string $shopName, array $context): array
    {
        $number = (string) ($context['order_number'] ?? '');
        $total = (string) ($context['total'] ?? '');
        $currency = (string) ($context['currency'] ?? '');
        $name = trim((string) ($context['customer_name'] ?? ''));
        $greeting = $name !== '' ? "Bonjour {$name}," : 'Bonjour,';
        $money = trim($total . ' ' . $currency);

        return match ($event) {
            NotificationEvent::ORDER_PLACED => [
                'subject' => "{$shopName} — order {$number} received",
                'body' => <<<TEXT
                {$greeting}

                We have received your order {$number}.

                Total: {$money}

                We will contact you to confirm it before dispatch.

                {$shopName}
                TEXT,
            ],

            NotificationEvent::PAYMENT_RECEIVED => [
                'subject' => "{$shopName} — payment received for order {$number}",
                'body' => <<<TEXT
                {$greeting}

                We have received your payment of {$money} for order {$number}.

                {$shopName}
                TEXT,
            ],

            NotificationEvent::SHIPMENT_SHIPPED => [
                'subject' => "{$shopName} — order {$number} is on its way",
                'body' => self::shipmentBody($greeting, $number, $shopName, $context, 'has been handed to'),
            ],

            NotificationEvent::SHIPMENT_DELIVERED => [
                'subject' => "{$shopName} — order {$number} delivered",
                'body' => <<<TEXT
                {$greeting}

                Order {$number} has been delivered. Thank you for shopping with us.

                {$shopName}
                TEXT,
            ],

            NotificationEvent::ORDER_CANCELLED => [
                'subject' => "{$shopName} — order {$number} cancelled",
                'body' => <<<TEXT
                {$greeting}

                Order {$number} has been cancelled. If this is unexpected, reply to this message.

                {$shopName}
                TEXT,
            ],

            NotificationEvent::ORDER_REFUNDED => [
                'subject' => "{$shopName} — order {$number} refunded",
                'body' => <<<TEXT
                {$greeting}

                Order {$number} has been refunded.

                {$shopName}
                TEXT,
            ],

            // Admin messages. Short, factual, and never sent to a customer —
            // NotificationEvent::ADMIN_EVENTS is what keeps that true.
            NotificationEvent::ADMIN_NEW_ORDER => [
                'subject' => "{$shopName} — new order {$number}",
                'body' => "Order {$number} was placed for {$money}.",
            ],

            NotificationEvent::STOCK_LOW => [
                'subject' => "{$shopName} — low stock: " . (string) ($context['product_name'] ?? ''),
                'body' => sprintf(
                    "%s (SKU %s) is down to %s in stock.",
                    (string) ($context['product_name'] ?? ''),
                    (string) ($context['sku'] ?? '—'),
                    (string) ($context['stock'] ?? '?')
                ),
            ],

            default => ['subject' => '', 'body' => ''],
        };
    }

    /** @param array<string, mixed> $context */
    private static function shipmentBody(
        string $greeting,
        string $number,
        string $shopName,
        array $context,
        string $verb
    ): string {
        $courier = (string) ($context['provider'] ?? '');
        $tracking = trim((string) ($context['tracking_number'] ?? ''));

        $line = $courier !== ''
            ? "Order {$number} {$verb} {$courier}."
            : "Order {$number} has been dispatched.";

        // A tracking number the courier has not issued yet is common — the
        // §57 adapter records that a parcel exists before its number is
        // readable — so the sentence has to work without one.
        if ($tracking !== '') {
            $line .= "\n\nTracking number: {$tracking}";
        }

        return <<<TEXT
        {$greeting}

        {$line}

        {$shopName}
        TEXT;
    }
}
