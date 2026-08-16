<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * A way of reaching somebody — docs/PLAN.md §29.
 *
 * The same seam as `Shipping\ShippingProviderInterface` and
 * `Payments\PaymentProviderInterface`, and it exists for the same reason: §29
 * names five channels (email, SMS, WhatsApp, push, in-app) and this project can
 * configure exactly one. Writing the interface now is what makes the second one
 * additive instead of a rewrite — and §29's "only activate configured
 * providers" is `Plugin::notificationChannels()`, the one place a channel's
 * credentials and feature flag are read.
 *
 * **A channel never sees a `WC_Order`.** It receives a `Notification`, whose
 * subject and body were rendered when the row was queued. An SMS provider that
 * could reach into the order model would grow a dependency the business does
 * not have, and would render a message describing the order as it is now rather
 * than as it was when the event happened.
 */
interface NotificationChannelInterface
{
    /** Stable, lower-case; stored in `ac_notifications.channel`. */
    public function name(): string;

    /**
     * Whether this channel can carry that notification at all.
     *
     * An email channel needs an address; an SMS channel would need a phone
     * number. Answering false here is not a failure — the notification is
     * simply not queued for this channel, and nothing is written.
     */
    public function supports(Notification $notification): bool;

    public function send(Notification $notification): NotificationResult;
}
