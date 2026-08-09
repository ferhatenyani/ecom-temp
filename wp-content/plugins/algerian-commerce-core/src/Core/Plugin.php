<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

use AlgerianCommerce\API\HealthController;
use AlgerianCommerce\API\RestApi;
use AlgerianCommerce\CLI\MigrateCommand;
use AlgerianCommerce\Core\Migrations\MigrationRunner;
use Throwable;
use WP_CLI;

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

    private static ?self $instance = null;

    private ?Config $config = null;
    private ?Logger $logger = null;
    private ?RestApi $restApi = null;
    private ?MigrationRunner $migrations = null;
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
        $this->registerCliCommands();

        $this->logger()->debug('Plugin booted', ['version' => VERSION]);
    }

    private function registerCliCommands(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('algerian-commerce migrate', new MigrateCommand($this->migrations()));
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

    public function migrations(): MigrationRunner
    {
        global $wpdb;

        return $this->migrations ??= new MigrationRunner(
            AC_CORE_PATH . 'migrations',
            $this->logger(),
            $wpdb
        );
    }

    /**
     * Activation applies pending migrations, then records the plugin version.
     *
     * A failed migration must not be reported as a successful activation, so
     * the failure is logged and rethrown rather than swallowed — an install
     * with a half-built schema is worse than one that refuses to activate.
     */
    public static function activate(): void
    {
        $plugin = self::instance();

        try {
            $plugin->migrations()->run();
        } catch (Throwable $throwable) {
            $plugin->logger()->error('Activation failed while migrating', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }

        update_option(self::VERSION_OPTION, VERSION);

        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules(false);
    }
}
