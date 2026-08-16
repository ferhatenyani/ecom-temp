<?php

declare(strict_types=1);

namespace AlgerianCommerce\Integrations\Meta;

use AlgerianCommerce\Marketing\MarketingEvent;
use AlgerianCommerce\Marketing\MarketingProviderInterface;
use AlgerianCommerce\Marketing\MarketingResult;

/**
 * Meta, behind `MarketingProviderInterface` — roadmap §62b.
 *
 * The adapter's whole job is translation: one of our `MarketingEvent`s into one
 * of Meta's server events. It never sees a `WC_Order`, never reads an option or
 * a feature flag, and never receives a raw customer email — `UserData` hashed
 * everything before the event was built.
 *
 * **The browser half is not here and must not be.** `fbevents.js` and the
 * `fbq()` calls belong to the Next.js storefront, because WordPress renders no
 * page in this architecture. §62b is explicit that a WooCommerce pixel plugin
 * cannot do it either: those inject their script through WooCommerce template
 * hooks, and in a headless install those templates never run — the plugin is
 * inert and looks installed, which is the worst of both.
 *
 * What this side owns is the Conversions API: an outbound HTTP call carrying
 * order data and a long-lived token, which is a third-party integration like
 * any other and gets §54 and §55 in full.
 */
final class MetaProvider implements MarketingProviderInterface
{
    public const NAME = 'meta';

    public function __construct(
        private readonly MetaClient $client,
        private readonly MetaCredentials $credentials,
        private readonly MetaSettings $settings
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Meta (Facebook & Instagram)';
    }

    /**
     * What the storefront is allowed to know.
     *
     * The pixel id, because it ships in browser JavaScript anyway, and the API
     * version, because the browser pixel and the server events should agree.
     * **Never the access token** — `tests/Api/marketing.php` asserts that the
     * response body does not contain it, which is a check that survives someone
     * adding a field here in a hurry.
     *
     * @return array<string, mixed>
     */
    public function publicConfig(): array
    {
        return [
            'pixel_id' => $this->credentials->pixelId,
            'api_version' => $this->settings->apiVersion,
            // A storefront can then show its own "test mode" banner rather than
            // wondering why nothing reaches the live dataset.
            'test_mode' => $this->settings->testEventCode !== '',
        ];
    }

    public function send(MarketingEvent $event): MarketingResult
    {
        return $this->client->sendEvents([self::toServerEvent($event)]);
    }

    /**
     * Our event, in Meta's shape.
     *
     * Field names and requirements from Meta's Conversions API documentation,
     * read 2026-08-16: `event_name` and `event_time` are required; `event_id`,
     * `event_source_url`, `action_source`, `user_data` and `custom_data` are
     * optional — though `event_id` is what the whole deduplication contract
     * rests on, and `value` and `currency` are required *for a Purchase*.
     *
     * @return array<string, mixed>
     */
    public static function toServerEvent(MarketingEvent $event): array
    {
        $server = [
            'event_name' => $event->name,
            'event_time' => $event->occurredAt,
            'event_id' => $event->eventId,
            'action_source' => $event->actionSource,
            'user_data' => $event->userData->toArray(),
        ];

        /*
         * Omitted rather than sent empty. Meta validates `event_source_url` as
         * a URL, so `""` is a rejected event where an absent field is simply an
         * event with less context.
         */
        if ($event->sourceUrl !== '') {
            $server['event_source_url'] = $event->sourceUrl;
        }

        if ($event->custom !== []) {
            $server['custom_data'] = self::customData($event->custom);
        }

        return $server;
    }

    /**
     * @param array<string, mixed> $custom
     * @return array<string, mixed>
     */
    private static function customData(array $custom): array
    {
        $data = [];

        /*
         * `value` is a number and `currency` a three-letter ISO 4217 code, both
         * required for a Purchase. The value is cast rather than trusted:
         * WooCommerce hands totals about as strings, and a quoted number is one
         * of the things Meta's validator is fussy about.
         */
        if (isset($custom['value'])) {
            $data['value'] = (float) $custom['value'];
        }

        if (!empty($custom['currency'])) {
            $data['currency'] = strtoupper((string) $custom['currency']);
        }

        foreach (['order_id', 'content_type'] as $key) {
            if (!empty($custom[$key])) {
                $data[$key] = (string) $custom[$key];
            }
        }

        if (!empty($custom['content_ids']) && is_array($custom['content_ids'])) {
            $data['content_ids'] = array_values(array_map('strval', $custom['content_ids']));
        }

        if (!empty($custom['num_items'])) {
            $data['num_items'] = (int) $custom['num_items'];
        }

        if (!empty($custom['contents']) && is_array($custom['contents'])) {
            $data['contents'] = array_values(array_map(
                static fn (array $line): array => [
                    'id' => (string) ($line['id'] ?? ''),
                    'quantity' => (int) ($line['quantity'] ?? 0),
                    'item_price' => (float) ($line['item_price'] ?? 0),
                ],
                array_filter($custom['contents'], 'is_array')
            ));
        }

        return $data;
    }
}
