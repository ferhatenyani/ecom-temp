<?php
/**
 * Plugin Name:       Algerian Commerce Core
 * Description:       Headless commerce application layer for WordPress/WooCommerce. Exposes the algerian-commerce/v1 REST API consumed by the Next.js storefront and admin.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.3
 * Requires Plugins:  woocommerce
 * Text Domain:       algerian-commerce-core
 * License:           proprietary
 *
 * These two floors are support commitments, not syntax minimums — a client
 * reads them and deploys accordingly, so they must not name a version nobody
 * is patching any more.
 *
 * `Requires at least: 6.9` because `Requires Plugins: woocommerce` makes
 * WooCommerce mandatory and WooCommerce 11 requires WordPress 6.9. The header
 * said 6.5, which no install could satisfy while also running the WooCommerce
 * this plugin needs — a promise with nothing behind it.
 *
 * `Requires PHP: 8.3` because 8.1 reached end of life on 2025-12-31 and 8.2
 * follows on 2026-12-31; declaring either would tell a shop that an unpatched
 * runtime is supported. 8.3 has security support until 2027-12-31 and is what
 * the suite actually runs on.
 *
 * @package AlgerianCommerce
 */

declare(strict_types=1);

namespace AlgerianCommerce;

use AlgerianCommerce\Core\Autoloader;
use AlgerianCommerce\Core\Plugin;
use AlgerianCommerce\Security\ClientIp;

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
 * Composer is optional. When vendor/autoload.php is present it wins; the
 * bundled PSR-4 autoloader is registered behind it either way, so the plugin
 * activates on a host that has never run `composer install`.
 *
 * **Behind it either way, not only in the else branch** — §60 found out why.
 * `optimize-autoloader` is on, so Composer dumps a *classmap*: a snapshot of
 * the files that existed when somebody last ran `dump-autoload`. Moving one
 * class between two directories under src/ made every request fatal on a
 * perfectly healthy checkout, because the map still pointed at the old path and
 * nothing else was listening. This is the same reasoning the Integrations\
 * registration below already carried; it simply had not been applied to src/,
 * where a classmap makes it *more* necessary rather than less. Composer is
 * asked first, so nothing here can shadow it.
 */
if (is_readable(AC_CORE_PATH . 'vendor/autoload.php')) {
    require_once AC_CORE_PATH . 'vendor/autoload.php';
}

require_once AC_CORE_PATH . 'src/Core/Autoloader.php';
(new Autoloader('AlgerianCommerce\\', AC_CORE_PATH . 'src'))->register();

/*
 * Third-party adapters live outside src/ (roadmap §37), so that "which of this
 * code is ours and which is shaped by somebody else's API" is a directory
 * rather than a convention.
 *
 * Registered even when Composer's autoloader is present, unlike src/. A dumped
 * autoloader is a snapshot: a site that pulls a release adding an adapter and
 * does not re-run `composer dump-autoload` has a vendor/ map with no
 * Integrations\ prefix in it, and the failure is a fatal error on every request
 * rather than a missing courier. This costs one spl_autoload_register and
 * cannot shadow Composer, which is asked first.
 */
require_once AC_CORE_PATH . 'src/Core/Autoloader.php';
(new Autoloader('AlgerianCommerce\\Integrations\\', AC_CORE_PATH . 'integrations'))->register();

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
 * Roadmap §86 — behind a reverse proxy, TLS ends at the proxy and WordPress
 * would otherwise build http:// URLs for an https:// site.
 *
 * At file load rather than on a hook, and before anything else here runs:
 * `is_ssl()` is consulted the moment WordPress builds a URL, which includes
 * `wp_is_application_passwords_supported()` on the §44 path. Later is a
 * redirect loop that only appears in production.
 *
 * Reads `getenv()` directly rather than `Config`, which is a deliberate
 * exception to "one place reads the environment": `Config` belongs to the
 * container, the container is built in `Plugin::boot()` on `plugins_loaded`,
 * and that is already too late. `ClientIp::applyForwardedScheme()` does nothing
 * at all unless the peer is a named trusted proxy, so the exception cannot be
 * exploited by a caller.
 */
ClientIp::applyForwardedScheme($_SERVER, getenv('AC_TRUSTED_PROXIES') ?: null);

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
