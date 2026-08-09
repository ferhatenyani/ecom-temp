<?php

declare(strict_types=1);

/**
 * Unit test bootstrap.
 *
 * No WordPress here by design: everything under tests/Unit must be runnable
 * without booting WordPress (docs/ARCHITECTURE.md §2). Only the plugin
 * constants that pure classes reference are defined.
 *
 * define() rather than const: const cannot be declared inside a conditional,
 * and these must not collide when the plugin file has already run.
 */

if (!defined('AC_CORE_PATH')) {
    define('AC_CORE_PATH', dirname(__DIR__) . '/');
}

if (!defined('AlgerianCommerce\VERSION')) {
    define('AlgerianCommerce\VERSION', '0.1.0');
    define('AlgerianCommerce\DB_VERSION', 1);
    define('AlgerianCommerce\REST_NAMESPACE', 'algerian-commerce/v1');
}

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';

(new AlgerianCommerce\Core\Autoloader('AlgerianCommerce\\', dirname(__DIR__) . '/src'))->register();
