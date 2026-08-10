<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

use AlgerianCommerce\API\AuditLogController;
use AlgerianCommerce\API\Cors;
use AlgerianCommerce\API\HealthController;
use AlgerianCommerce\API\OriginPolicy;
use AlgerianCommerce\API\RestApi;
use AlgerianCommerce\Audit\AuditLogger;
use AlgerianCommerce\Audit\AuditRepository;
use AlgerianCommerce\CLI\MigrateCommand;
use AlgerianCommerce\CLI\RolesCommand;
use AlgerianCommerce\Core\Migrations\MigrationRunner;
use AlgerianCommerce\Permissions\Roles;
use AlgerianCommerce\Products\ProductController;
use AlgerianCommerce\Products\ProductRepository;
use AlgerianCommerce\Products\ProductService;
use AlgerianCommerce\Products\VariationController;
use AlgerianCommerce\Products\VariationRepository;
use AlgerianCommerce\Products\VariationService;
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
    private ?Cors $cors = null;
    private ?Roles $roles = null;
    private ?AuditRepository $auditRepository = null;
    private ?AuditLogger $auditLogger = null;
    private ?ProductRepository $productRepository = null;
    private ?ProductService $productService = null;
    private ?VariationService $variationService = null;
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
        $this->cors()->register();
        $this->registerCliCommands();

        $this->logger()->debug('Plugin booted', ['version' => VERSION]);
    }

    private function registerCliCommands(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('algerian-commerce migrate', new MigrateCommand($this->migrations()));
        WP_CLI::add_command('algerian-commerce roles', new RolesCommand($this->roles()));
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
            new AuditLogController($this->logger(), $this->auditRepository()),
            new ProductController($this->logger(), $this->productService()),
            new VariationController($this->logger(), $this->variationService()),
        ]);
    }

    public function productRepository(): ProductRepository
    {
        return $this->productRepository ??= new ProductRepository();
    }

    public function productService(): ProductService
    {
        return $this->productService ??= new ProductService(
            $this->productRepository(),
            $this->auditLogger()
        );
    }

    public function variationService(): VariationService
    {
        return $this->variationService ??= new VariationService(
            $this->productRepository(),
            new VariationRepository(),
            $this->auditLogger()
        );
    }

    public function cors(): Cors
    {
        return $this->cors ??= new Cors(
            OriginPolicy::fromList($this->config()->get('AC_CORS_ORIGINS')),
            $this->logger()
        );
    }

    public function roles(): Roles
    {
        return $this->roles ??= new Roles($this->logger());
    }

    public function auditRepository(): AuditRepository
    {
        global $wpdb;

        return $this->auditRepository ??= new AuditRepository($wpdb);
    }

    public function auditLogger(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger($this->auditRepository(), $this->logger());
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
            // After migrations: roles are meaningless without the tables the
            // capabilities eventually guard.
            $plugin->roles()->install();
        } catch (Throwable $throwable) {
            $plugin->logger()->error('Activation failed', [
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
