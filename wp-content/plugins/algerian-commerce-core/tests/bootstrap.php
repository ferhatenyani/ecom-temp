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

require_once dirname(__DIR__) . '/src/Core/Autoloader.php';

(new AlgerianCommerce\Core\Autoloader('AlgerianCommerce\\', dirname(__DIR__) . '/src'))->register();
// Provider adapters live outside src/ — roadmap §37. Same two registrations as
// the plugin bootstrap, so a unit test loads an adapter exactly as WordPress
// would.
(new AlgerianCommerce\Core\Autoloader('AlgerianCommerce\\Integrations\\', dirname(__DIR__) . '/integrations'))->register();
// Test doubles — a scripted HTTP transport and an in-memory destination map,
// which is what makes an adapter for an API with no sandbox testable at all.
(new AlgerianCommerce\Core\Autoloader('AlgerianCommerce\\Tests\\', __DIR__))->register();

/*
 * Registered first, because DB_VERSION is derived from Core\Schema rather than
 * written out again here. It used to be a second, independent literal, which
 * meant the guard in MigrationPlanTest asserted against this copy instead of
 * the one the plugin actually ships — bumping this and forgetting the plugin
 * file passed the suite and shipped an install that skipped a migration.
 */
if (!defined('AlgerianCommerce\VERSION')) {
    define('AlgerianCommerce\VERSION', '0.1.0');
    define('AlgerianCommerce\DB_VERSION', AlgerianCommerce\Core\Schema::VERSION);
    define('AlgerianCommerce\REST_NAMESPACE', 'algerian-commerce/v1');
}
