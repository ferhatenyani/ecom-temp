<?php

declare(strict_types=1);

namespace AlgerianCommerce\Settings;

use AlgerianCommerce\API\ApiException;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Marketing\MarketingProviderRegistry;
use AlgerianCommerce\Payments\PaymentProviderRegistry;
use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Permissions;
use AlgerianCommerce\Shipping\ProviderRegistry;

/**
 * The client configuration document — roadmap §71, docs/PLAN.md §48.
 *
 * §71's instruction is "use configuration and feature flags rather than forks",
 * and PLAN §48's is "separate reusable code from client configuration". Both
 * describe an outcome rather than a mechanism, and the mechanism that fell out
 * of it is **one document assembled from the systems that already own each
 * value**, not one table that copies them.
 *
 * That distinction is the whole design. Before this, configuring a client meant
 * knowing that the store name is a WordPress option, the currency a WooCommerce
 * one, the courier settings four separate `ac_*_settings` rows, and the feature
 * flags environment variables — four systems, no list, and nothing that would
 * tell a new operator they had missed one. `GET /settings` is that list. It is
 * built live on every request, so it cannot drift from what it describes.
 *
 * **`ac_manage_settings` gets its first call site here.** The capability has
 * existed since roadmap §45's matrix, held by Super Admin alone and never
 * checked by anything — the same state `Permissions::assertOwnsOr()` was in
 * before §59c. No new capability was invented, and it stays Super Admin's:
 * this document names the shop's legal identity and the address of its
 * storefront, and a Support Agent editing either is a different job.
 */
final class SettingsService
{
    public function __construct(
        private readonly SettingsRepository $repository,
        private readonly Config $config,
        private readonly PaymentProviderRegistry $payments,
        private readonly ProviderRegistry $shipping,
        private readonly MarketingProviderRegistry $marketing,
        private readonly AuditLogger $audit
    ) {
    }

    /**
     * The whole configuration, assembled from its owners.
     *
     * @return array<string, mixed>
     */
    public function document(): array
    {
        Permissions::assert(Capabilities::MANAGE_SETTINGS);

        return $this->assemble();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the document as it now stands
     */
    public function update(array $payload): array
    {
        Permissions::assert(Capabilities::MANAGE_SETTINGS);

        $input = SettingsInput::fromPayload($payload);

        if ($input->isEmpty()) {
            throw ApiException::invalidRequest('No supported fields were provided.', [
                'fields' => array_keys(SettingsInput::SCHEMA),
            ]);
        }

        $store = $input->block('store');

        if ($store !== null && !empty($store['logo_id'])) {
            $this->requireImage((int) $store['logo_id']);
        }

        $this->repository->save($input->blocks);

        /*
         * Audited by field **name**, never by value. A change to the shop's
         * registered name or its trade-register numbers is exactly what an
         * audit trail is for; copying those values into every audit row would
         * duplicate the client's legal identity into a table nobody ever cleans.
         */
        $changed = [];

        foreach ($input->blocks as $block => $fields) {
            foreach (array_keys($fields) as $field) {
                $changed[] = "{$block}.{$field}";
            }
        }

        $this->audit->record('settings.updated', 'settings', 0, [
            'blocks' => array_keys($input->blocks),
            'fields' => $changed,
        ]);

        return $this->assemble();
    }

    /** @return array<string, mixed> */
    private function assemble(): array
    {
        $stored = $this->repository->stored();

        $store = $stored['store'] ?? [];
        $logoId = (int) ($store['logo_id'] ?? 0);

        return [
            'store' => [
                // WordPress's, live. Not a copy.
                'name' => $this->repository->storeName(),
                'description' => $this->repository->storeDescription(),
                'locale' => $this->repository->locale(),
                // WooCommerce's, live, and read-only here — see SettingsInput.
                'currency' => $this->repository->currency(),
                'currency_symbol' => $this->repository->currencySymbol(),
                /*
                 * The storefront's address, which this backend cannot derive.
                 * WordPress's own permalink points at the admin domain, and §62
                 * already refused to guess a canonical URL for exactly this
                 * reason — a guess would tell Google the shop lives here.
                 */
                'storefront_url' => (string) ($store['storefront_url'] ?? ''),
                'logo_id' => $logoId,
                'logo' => $logoId > 0 ? $this->image($logoId) : null,
            ],
            'contact' => $this->blockWithDefaults('contact', $stored),
            'legal' => $this->blockWithDefaults('legal', $stored),
            'social' => $this->blockWithDefaults('social', $stored),
            /*
             * Environment, not database — and reported rather than writable.
             * `Config::FLAGS` is the list; four of the nine gate nothing yet
             * (blog, reviews, SMS, WhatsApp), and they are reported as declared
             * so nobody has to grep .env.example to find out a flag exists.
             */
            'features' => $this->features(),
            /*
             * What actually registered, which is the question an operator is
             * really asking. A flag that is on with no credentials produces a
             * provider that never registers, and this is the only place that
             * difference is visible.
             */
            'providers' => [
                'payment' => $this->payments->names(),
                'shipping' => $this->shipping->names(),
                'marketing' => $this->marketing->names(),
            ],
        ];
    }

    /** @return array<string, bool> */
    private function features(): array
    {
        $out = [];

        foreach (Config::FLAGS as $flag) {
            // ENABLE_ZR_EXPRESS → zr_express, so the document reads as data
            // rather than as a list of environment variable names.
            $key = strtolower((string) preg_replace('/^ENABLE_/', '', $flag));
            $out[$key] = $this->config->isEnabled($flag);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, string>
     */
    private function blockWithDefaults(string $block, array $stored): array
    {
        $values = is_array($stored[$block] ?? null) ? $stored[$block] : [];
        $out = [];

        // Every known field appears, empty if unset. A client reading this to
        // build a settings form should not have to know the schema separately.
        foreach (SettingsInput::SCHEMA[$block] as $field) {
            $out[$field] = (string) ($values[$field] ?? '');
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function image(int $id): ?array
    {
        $url = wp_get_attachment_url($id);

        if (!is_string($url) || $url === '') {
            // The attachment was deleted after being set. Reported as absent
            // rather than as a broken URL a storefront would render as a gap.
            return null;
        }

        return [
            'id' => $id,
            'url' => $url,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
        ];
    }

    /**
     * A logo must be an image this shop actually holds.
     *
     * WordPress keeps posts, products, orders and attachments in one id space,
     * so an unchecked `logo_id` is the type confusion §65 tested for everywhere
     * else — it would accept an order id and render nothing.
     */
    private function requireImage(int $id): void
    {
        $post = get_post($id);

        if ($post === null || $post->post_type !== 'attachment' || !str_starts_with((string) $post->post_mime_type, 'image/')) {
            throw ApiException::invalidRequest('The settings are invalid.', [
                'fields' => ['store.logo_id' => 'No image with that id.'],
            ]);
        }
    }
}
