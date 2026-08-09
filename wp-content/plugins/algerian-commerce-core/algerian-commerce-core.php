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

/**
 * Schema version for custom tables. Bump when a migration is added under
 * migrations/ — see docs/ARCHITECTURE.md §7.
 */
const DB_VERSION = 1;

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

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

/*
 * plugins_loaded rather than an immediate call: WooCommerce must be loaded
 * before anything here inspects it.
 */
add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
