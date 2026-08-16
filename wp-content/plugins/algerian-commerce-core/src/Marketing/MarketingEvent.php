<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

/**
 * The value object that crosses the provider boundary — roadmap §62b.
 *
 * Pure, and the only thing an adapter is handed: a `MetaProvider` never sees a
 * `WC_Order`, exactly as a courier adapter never does. It carries hashes rather
 * than customer details (see `UserData`), so an adapter cannot leak an email
 * address it was never given.
 *
 * ## Only what the server witnessed
 *
 * `PageView`, `Search` and `ViewContent` are **browser** facts. A backend that
 * reports them is guessing, and a guessed conversion event is worse than a
 * missing one because it silently reprices somebody's ad spend. So the vocabulary
 * below is the full §62 list — the storefront sends the browser half through the
 * pixel — while `serverSide()` names the two this side is entitled to state.
 */
final class MarketingEvent
{
    public const PAGE_VIEW = 'PageView';
    public const VIEW_CONTENT = 'ViewContent';
    public const SEARCH = 'Search';
    public const ADD_TO_CART = 'AddToCart';
    public const INITIATE_CHECKOUT = 'InitiateCheckout';
    public const PURCHASE = 'Purchase';

    /** The six §62 names, in Meta's spelling, which TikTok also uses. */
    public const ALL = [
        self::PAGE_VIEW,
        self::VIEW_CONTENT,
        self::SEARCH,
        self::ADD_TO_CART,
        self::INITIATE_CHECKOUT,
        self::PURCHASE,
    ];

    /**
     * Events a server may state as fact.
     *
     * `Purchase` is one: the order exists in this database. `InitiateCheckout`
     * joins it only where checkout is what creates the order — which is this
     * shop's flow — and both are recorded here rather than inferred from a page
     * the backend never rendered.
     */
    public const SERVER_SIDE = [self::PURCHASE, self::INITIATE_CHECKOUT];

    /** Meta's `action_source` for an event that happened on a website. */
    public const SOURCE_WEBSITE = 'website';

    /**
     * @param array<string, mixed> $custom value, currency, order_id, contents…
     */
    public function __construct(
        public readonly string $name,
        public readonly string $eventId,
        public readonly int $occurredAt,
        public readonly UserData $userData,
        public readonly array $custom = [],
        public readonly string $sourceUrl = '',
        public readonly string $actionSource = self::SOURCE_WEBSITE,
        public readonly ?int $orderId = null
    ) {
    }

    public static function isKnown(string $name): bool
    {
        return in_array($name, self::ALL, true);
    }

    public static function isServerSide(string $name): bool
    {
        return in_array($name, self::SERVER_SIDE, true);
    }

    /**
     * The deduplication id, derived from the order rather than from randomness.
     *
     * This is the whole of §62b's dedup contract. Meta discards the browser's
     * copy of an event only when both carry the same `event_name` **and** the
     * same `event_id`, and two systems cannot each invent one — so the backend
     * mints it and tells the storefront, never the reverse.
     *
     * Derived, so a retried request, a refreshed confirmation page and a second
     * browser tab all produce the same string and therefore one conversion. A
     * random id would produce three, and the shop would believe it sold three
     * times.
     *
     * Hashed rather than `purchase-1042`, because this string is handed to an
     * advertising network on every order and a sequential id would publish the
     * shop's order volume to anyone counting.
     */
    public static function idFor(string $name, int $orderId): string
    {
        return substr(hash('sha256', strtolower($name) . '|order|' . $orderId), 0, 40);
    }

    /** @return array<string, mixed> the stored payload — see migration 009 */
    public function toArray(): array
    {
        return [
            'event_name' => $this->name,
            'event_id' => $this->eventId,
            'event_time' => $this->occurredAt,
            'action_source' => $this->actionSource,
            'event_source_url' => $this->sourceUrl,
            'user_data' => $this->userData->toArray(),
            'custom_data' => $this->custom,
            'order_id' => $this->orderId,
        ];
    }

    /**
     * Rebuild an event from its stored payload.
     *
     * The queue drains long after the request that created it, and it replays
     * **what was frozen at claim time** rather than re-reading the order. A
     * refund, an edited line item or a changed address between the sale and the
     * drain must not change the conversion value that gets reported.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $userData = $payload['user_data'] ?? [];
        $userData = is_array($userData) ? $userData : [];

        $hashed = array_diff_key($userData, array_flip(UserData::PLAIN_KEYS));
        $plain = array_intersect_key($userData, array_flip(UserData::PLAIN_KEYS));

        return new self(
            (string) ($payload['event_name'] ?? ''),
            (string) ($payload['event_id'] ?? ''),
            (int) ($payload['event_time'] ?? 0),
            UserData::fromStored(
                array_map('strval', $hashed),
                array_map('strval', $plain)
            ),
            is_array($payload['custom_data'] ?? null) ? $payload['custom_data'] : [],
            (string) ($payload['event_source_url'] ?? ''),
            (string) ($payload['action_source'] ?? self::SOURCE_WEBSITE),
            isset($payload['order_id']) ? (int) $payload['order_id'] : null
        );
    }
}
