<?php

declare(strict_types=1);

namespace AlgerianCommerce\Settings;

/**
 * Where client configuration is stored — roadmap §71.
 *
 * **Almost none of it is stored here, and that is the design.** The store name
 * belongs to WordPress, the currency to WooCommerce, the courier settings to
 * their own options, the feature flags to the environment. §71 asks for one
 * place to *configure a client*, which is not the same as one place to *keep the
 * data* — copying those values into a settings row would create a second source
 * of truth for each, and the copy is the one that goes stale. §63 refused an
 * analytics rollup on the same grounds and §68 refused a version table.
 *
 * So this holds exactly the fields that have no owner: the client's contact
 * details, its Algerian trade-register identifiers, its social links, its logo
 * and the address of the storefront that fronts this backend. Everything else in
 * the document is read from whoever owns it, live, on every request.
 */
final class SettingsRepository
{
    public const OPTION = 'ac_client_settings';

    /** WordPress's own, so a client's name is not stored twice. */
    private const WP_NAME = 'blogname';
    private const WP_DESCRIPTION = 'blogdescription';

    /** @return array<string, array<string, mixed>> */
    public function stored(): array
    {
        $raw = get_option(self::OPTION, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Merge a validated write into what is stored.
     *
     * A partial write updates the fields it names and leaves the rest alone —
     * a settings screen that saves one section must not blank the others.
     *
     * @param array<string, array<string, mixed>> $blocks
     */
    public function save(array $blocks): void
    {
        $current = $this->stored();

        foreach ($blocks as $block => $fields) {
            $current[$block] = [...($current[$block] ?? []), ...$fields];
        }

        /*
         * `store.name` and `store.description` are written through to WordPress
         * rather than kept here. They are the two fields §71 lists that already
         * have an owner, and WordPress's copy is the one WP-CLI, the admin
         * screens and every email template read.
         */
        if (isset($current['store']['name'])) {
            update_option(self::WP_NAME, (string) $current['store']['name']);
            unset($current['store']['name']);
        }

        if (isset($current['store']['description'])) {
            update_option(self::WP_DESCRIPTION, (string) $current['store']['description']);
            unset($current['store']['description']);
        }

        if (($current['store'] ?? null) === []) {
            unset($current['store']);
        }

        update_option(self::OPTION, $current, false);
    }

    public function storeName(): string
    {
        return (string) get_option(self::WP_NAME, '');
    }

    public function storeDescription(): string
    {
        return (string) get_option(self::WP_DESCRIPTION, '');
    }

    /** WooCommerce's, never a copy — it records the currency on every order. */
    public function currency(): string
    {
        return function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '';
    }

    public function currencySymbol(): string
    {
        return function_exists('get_woocommerce_currency_symbol')
            ? html_entity_decode((string) get_woocommerce_currency_symbol())
            : '';
    }

    public function locale(): string
    {
        return (string) get_locale();
    }
}
