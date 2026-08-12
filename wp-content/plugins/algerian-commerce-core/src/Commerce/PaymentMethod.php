<?php

declare(strict_types=1);

namespace AlgerianCommerce\Commerce;

/**
 * Payment method identifiers, as WooCommerce stores them on an order.
 *
 * Pure — no WordPress. Here in `Commerce/` rather than in whichever domain
 * needed it first, because two of them need the same value: COD reads it to
 * decide whether an order belongs in the confirmation queue, and Shipping reads
 * it to decide what the driver must collect at the door
 * (docs/ARCHITECTURE.md §3).
 *
 * Literals rather than a lookup through `WC()->payment_gateways()`: the
 * gateways are not loaded on every REST request, and these strings are what is
 * already written on every order in the database — the gateway object is a
 * description of them, not their source.
 */
final class PaymentMethod
{
    /** WooCommerce's built-in cash-on-delivery gateway. */
    public const COD = 'cod';

    private function __construct()
    {
    }
}
