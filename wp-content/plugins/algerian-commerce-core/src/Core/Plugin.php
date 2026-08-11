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
use AlgerianCommerce\Auth\AuthController;
use AlgerianCommerce\Auth\AuthService;
use AlgerianCommerce\CLI\MigrateCommand;
use AlgerianCommerce\CLI\RolesCommand;
use AlgerianCommerce\CLI\UnlockCommand;
use AlgerianCommerce\Core\Migrations\MigrationRunner;
use AlgerianCommerce\Inventory\InventoryController;
use AlgerianCommerce\Inventory\InventoryRepository;
use AlgerianCommerce\Inventory\InventoryService;
use AlgerianCommerce\Inventory\MovementRepository;
use AlgerianCommerce\Inventory\StockLedger;
use AlgerianCommerce\Customers\CustomerController;
use AlgerianCommerce\Customers\CustomerRepository;
use AlgerianCommerce\Customers\CustomerService;
use AlgerianCommerce\Orders\OrderController;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderService;
use AlgerianCommerce\Orders\OrderStockSubscriber;
use AlgerianCommerce\Permissions\Roles;
use AlgerianCommerce\Security\RateLimiter;
use AlgerianCommerce\Security\RateLimitGuard;
use AlgerianCommerce\Security\RateLimitStore;
use AlgerianCommerce\Products\ProductCategoryController;
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
    private ?AuthService $authService = null;
    private ?RateLimitStore $rateLimitStore = null;
    private ?RateLimiter $rateLimiter = null;
    private ?RateLimitGuard $rateLimitGuard = null;
    private ?ProductRepository $productRepository = null;
    private ?ProductService $productService = null;
    private ?VariationService $variationService = null;
    private ?InventoryRepository $inventoryRepository = null;
    private ?MovementRepository $movementRepository = null;
    private ?StockLedger $stockLedger = null;
    private ?InventoryService $inventoryService = null;
    private ?OrderRepository $orderRepository = null;
    private ?OrderService $orderService = null;
    private ?OrderStockSubscriber $orderStockSubscriber = null;
    private ?CustomerRepository $customerRepository = null;
    private ?CustomerService $customerService = null;
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
        // Before the routes run, and scoped to our namespace only.
        $this->rateLimitGuard()->register();
        /*
         * Not tied to the REST API: WooCommerce moves order stock from
         * wp-admin, WP-CLI, cron and payment gateways too, and the ledger has
         * to see all of it — see OrderStockSubscriber.
         */
        $this->orderStockSubscriber()->register();
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
        WP_CLI::add_command('algerian-commerce unlock', new UnlockCommand($this->rateLimiter(), $this->rateLimitStore()));
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
            new AuthController($this->logger(), $this->authService()),
            new AuditLogController($this->logger(), $this->auditRepository()),
            new ProductController($this->logger(), $this->productService()),
            new VariationController($this->logger(), $this->variationService()),
            new ProductCategoryController($this->logger()),
            new InventoryController($this->logger(), $this->inventoryService()),
            new OrderController($this->logger(), $this->orderService()),
            new CustomerController($this->logger(), $this->customerService()),
        ]);
    }

    public function customerRepository(): CustomerRepository
    {
        return $this->customerRepository ??= new CustomerRepository();
    }

    /**
     * Takes the order repository: a customer's history and lifetime statistics
     * are made of orders. The dependency runs one way — nothing in Orders/
     * reaches back for a customer object.
     */
    public function customerService(): CustomerService
    {
        return $this->customerService ??= new CustomerService(
            $this->customerRepository(),
            $this->orderRepository(),
            $this->auditLogger()
        );
    }

    public function orderRepository(): OrderRepository
    {
        return $this->orderRepository ??= new OrderRepository();
    }

    public function orderService(): OrderService
    {
        return $this->orderService ??= new OrderService(
            $this->orderRepository(),
            $this->auditLogger(),
            $this->auditRepository(),
            $this->movementRepository()
        );
    }

    /**
     * Shares the one StockLedger with the inventory and product services, so
     * an order-driven movement and a manual adjustment land in the same
     * history in the same shape.
     */
    public function orderStockSubscriber(): OrderStockSubscriber
    {
        return $this->orderStockSubscriber ??= new OrderStockSubscriber($this->stockLedger());
    }

    public function inventoryRepository(): InventoryRepository
    {
        global $wpdb;

        return $this->inventoryRepository ??= new InventoryRepository($wpdb);
    }

    public function movementRepository(): MovementRepository
    {
        global $wpdb;

        return $this->movementRepository ??= new MovementRepository($wpdb);
    }

    /**
     * Shared with the product and variation services, whose write endpoints
     * also accept a stock quantity. One ledger, every writer — otherwise the
     * movement history has gaps it cannot account for.
     */
    public function stockLedger(): StockLedger
    {
        return $this->stockLedger ??= new StockLedger($this->movementRepository(), $this->logger());
    }

    public function inventoryService(): InventoryService
    {
        return $this->inventoryService ??= new InventoryService(
            $this->inventoryRepository(),
            $this->movementRepository(),
            $this->stockLedger(),
            $this->auditLogger()
        );
    }

    public function productRepository(): ProductRepository
    {
        return $this->productRepository ??= new ProductRepository();
    }

    public function productService(): ProductService
    {
        return $this->productService ??= new ProductService(
            $this->productRepository(),
            $this->auditLogger(),
            $this->stockLedger()
        );
    }

    public function variationService(): VariationService
    {
        return $this->variationService ??= new VariationService(
            $this->productRepository(),
            new VariationRepository(),
            $this->auditLogger(),
            $this->stockLedger()
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

    public function authService(): AuthService
    {
        return $this->authService ??= new AuthService();
    }

    public function rateLimitStore(): RateLimitStore
    {
        return $this->rateLimitStore ??= new RateLimitStore();
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= new RateLimiter(
            $this->rateLimitStore(),
            $this->logger(),
            $this->config()
        );
    }

    public function rateLimitGuard(): RateLimitGuard
    {
        return $this->rateLimitGuard ??= new RateLimitGuard($this->rateLimiter());
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
