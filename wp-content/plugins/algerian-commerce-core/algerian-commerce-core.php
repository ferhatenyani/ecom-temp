<?php
/**
 * Plugin Name:       Algerian Commerce Core
 * Description:       Headless commerce application layer for WordPress/WooCommerce. Exposes the algerian-commerce/v1 REST API consumed by the Next.js storefront and admin.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Text Domain:       algerian-commerce-core
 * License:           proprietary
 *
 * @package AlgerianCommerce
 */

declare(strict_types=1);

namespace AlgerianCommerce;

use AlgerianCommerce\Core\Autoloader;
use AlgerianCommerce\Core\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

const VERSION = '0.1.0';

/** REST namespace every route in this plugin registers under. */
const REST_NAMESPACE = 'algerian-commerce/v1';

define('AC_CORE_FILE', __FILE__);
define('AC_CORE_PATH', plugin_dir_path(__FILE__));
define('AC_CORE_URL', plugin_dir_url(__FILE__));

/*
 * Composer is optional. When vendor/autoload.php is present it wins; otherwise
 * the bundled PSR-4 autoloader covers src/, so the plugin activates on a host
 * that has never run `composer install`.
 */
if (is_readable(AC_CORE_PATH . 'vendor/autoload.php')) {
    require_once AC_CORE_PATH . 'vendor/autoload.php';
} else {
    require_once AC_CORE_PATH . 'src/Core/Autoloader.php';
    (new Autoloader('AlgerianCommerce\\', AC_CORE_PATH . 'src'))->register();
}

/**
 * Schema version for custom tables — see docs/ARCHITECTURE.md §7.
 *
 * Derived from Core\Schema rather than written out again, so this and the unit
 * test bootstrap cannot drift apart. Declared after the autoloader because it
 * reads a class. **Bump Schema::VERSION when adding a migration.**
 */
const DB_VERSION = Core\Schema::VERSION;

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

/*
 * Declare compatibility with High-Performance Order Storage.
 *
 * WooCommerce blocks the HPOS switch when any active plugin has not declared
 * itself compatible, and treats silence as "unknown", not "fine". This must be
 * registered at file load — `before_woocommerce_init` fires before the
 * `plugins_loaded` boot below — and the second argument has to be this file,
 * because WooCommerce keys compatibility by plugin main file.
 *
 * The claim is honest: order data is reached through wc_get_order() and the
 * WC_Order CRUD, never through get_post()/get_post_meta() or $wpdb, so the
 * storage backend is invisible to this plugin. Keep it that way — writing to
 * wp_posts directly is what the declaration promises not to do.
 */
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            AC_CORE_FILE,
            true
        );
    }
});

/*
 * plugins_loaded rather than an immediate call: WooCommerce must be loaded
 * before anything here inspects it.
 */
add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
