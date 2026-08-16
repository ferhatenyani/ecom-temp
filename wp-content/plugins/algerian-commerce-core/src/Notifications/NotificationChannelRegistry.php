<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

use AlgerianCommerce\API\ApiException;

/**
 * The channels this shop has configured — docs/PLAN.md §29.
 *
 * Deliberately the same shape as `Payments\PaymentProviderRegistry` and
 * `Shipping\ProviderRegistry`, down to the empty case: a shop with no channel
 * configured is a normal shop, not a broken one, and every caller has to be
 * able to ask rather than assume.
 */
final class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels = [];

    public function add(NotificationChannelInterface $channel): void
    {
        $this->channels[$channel->name()] = $channel;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function isEmpty(): bool
    {
        return $this->channels === [];
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->channels);
    }

    /** @return list<NotificationChannelInterface> */
    public function all(): array
    {
        return array_values($this->channels);
    }

    public function get(string $name): NotificationChannelInterface
    {
        if (!isset($this->channels[$name])) {
            throw ApiException::invalidRequest('That notification channel is not configured.', [
                'fields' => ['channel' => 'Available: ' . (implode(', ', $this->names()) ?: 'none') . '.'],
            ]);
        }

        return $this->channels[$name];
    }

    /**
     * The channels that can actually carry this notification.
     *
     * @return list<NotificationChannelInterface>
     */
    public function supporting(Notification $notification): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NotificationChannelInterface $c): bool => $c->supports($notification)
        ));
    }
}
