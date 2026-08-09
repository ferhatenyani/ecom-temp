<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

use AlgerianCommerce\API\HealthController;
use AlgerianCommerce\API\RestApi;

use const AlgerianCommerce\DB_VERSION;
use const AlgerianCommerce\VERSION;

/**
 * Plugin lifecycle and service wiring.
 *
 * A deliberately small container: services are constructed once here and
 * handed to whatever needs them, so nothing reaches for a global.
 */
final class Plugin
{
    public const VERSION_OPTION = 'ac_core_version';
    public const DB_VERSION_OPTION = 'ac_core_db_version';

    private static ?self $instance = null;

    private ?Config $config = null;
    private ?Logger $logger = null;
    private ?RestApi $restApi = null;
    private bool $booted = false;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $this->restApi()->register();

        $this->logger()->debug('Plugin booted', ['version' => VERSION]);
    }

    public function config(): Config
    {
        return $this->config ??= Config::fromEnvironment();
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger(
            'core',
            $this->config()->get('AC_LOG_LEVEL') ?? (defined('WP_DEBUG') && WP_DEBUG ? Logger::DEBUG : Logger::INFO)
        );
    }

    public function restApi(): RestApi
    {
        return $this->restApi ??= new RestApi($this->logger(), [
            new HealthController($this->logger()),
        ]);
    }

    /**
     * Activation is intentionally minimal. Roles, capabilities and migrations
     * arrive in their own milestones; adding them here early would run
     * untested schema changes on every activation.
     */
    public static function activate(): void
    {
        update_option(self::VERSION_OPTION, VERSION);
        update_option(self::DB_VERSION_OPTION, DB_VERSION);

        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }
}
