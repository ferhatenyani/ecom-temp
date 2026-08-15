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
use AlgerianCommerce\CLI\ImportAlgeriaCommand;
use AlgerianCommerce\CLI\MigrateCommand;
use AlgerianCommerce\CLI\RolesCommand;
use AlgerianCommerce\CLI\ShippingCheckCommand;
use AlgerianCommerce\CLI\SyncDestinationsCommand;
use AlgerianCommerce\CLI\SyncPaymentsCommand;
use AlgerianCommerce\CLI\SyncShipmentsCommand;
use AlgerianCommerce\CLI\UnlockCommand;
use AlgerianCommerce\COD\CodController;
use AlgerianCommerce\COD\CodRepository;
use AlgerianCommerce\COD\CodService;
use AlgerianCommerce\COD\CodSubscriber;
use AlgerianCommerce\Core\Migrations\MigrationRunner;
use AlgerianCommerce\Inventory\InventoryController;
use AlgerianCommerce\Inventory\InventoryRepository;
use AlgerianCommerce\Inventory\InventoryService;
use AlgerianCommerce\Inventory\MovementRepository;
use AlgerianCommerce\Inventory\StockLedger;
use AlgerianCommerce\Customers\CustomerController;
use AlgerianCommerce\Geography\GeoImporter;
use AlgerianCommerce\Geography\GeoRepository;
use AlgerianCommerce\Geography\GeoService;
use AlgerianCommerce\Geography\LocationController;
use AlgerianCommerce\Customers\CustomerRepository;
use AlgerianCommerce\Customers\CustomerService;
use AlgerianCommerce\Orders\OrderController;
use AlgerianCommerce\Orders\OrderRepository;
use AlgerianCommerce\Orders\OrderService;
use AlgerianCommerce\Orders\OrderStockSubscriber;
use AlgerianCommerce\Payments\CashOnDeliveryProvider;
use AlgerianCommerce\Payments\PaymentController;
use AlgerianCommerce\Payments\PaymentPoller;
use AlgerianCommerce\Payments\PaymentProviderRegistry;
use AlgerianCommerce\Payments\PaymentService;
use AlgerianCommerce\Payments\PaymentWebhookController;
use AlgerianCommerce\Payments\TransactionRepository;
use AlgerianCommerce\Commerce\WebhookEventRepository;
use AlgerianCommerce\Permissions\Roles;
use AlgerianCommerce\Security\RateLimiter;
use AlgerianCommerce\Security\RateLimitGuard;
use AlgerianCommerce\Security\RateLimitStore;
use AlgerianCommerce\Http\WpHttpClient;
use AlgerianCommerce\Integrations\Chargily\ChargilyClient;
use AlgerianCommerce\Integrations\Chargily\ChargilyCredentials;
use AlgerianCommerce\Integrations\Chargily\ChargilyProvider;
use AlgerianCommerce\Integrations\Chargily\ChargilySettings;
use AlgerianCommerce\Integrations\Yalidine\YalidineClient;
use AlgerianCommerce\Integrations\Yalidine\YalidineCredentials;
use AlgerianCommerce\Integrations\Yalidine\YalidineProvider;
use AlgerianCommerce\Integrations\Yalidine\YalidineSettings;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressClient;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressCredentials;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressProvider;
use AlgerianCommerce\Integrations\ZRExpress\ZRExpressSettings;
use AlgerianCommerce\Shipping\DestinationSyncService;
use AlgerianCommerce\Shipping\GeoDestinationDirectory;
use AlgerianCommerce\Shipping\ManualProvider;
use AlgerianCommerce\Shipping\ProviderRegistry;
use AlgerianCommerce\Shipping\ShipmentPoller;
use AlgerianCommerce\Shipping\ShipmentRepository;
use AlgerianCommerce\Shipping\ShippingController;
use AlgerianCommerce\Shipping\ShippingRuleRepository;
use AlgerianCommerce\Shipping\ShippingService;
use AlgerianCommerce\Shipping\ShippingWebhookController;
use AlgerianCommerce\Products\ProductCategoryController;
use AlgerianCommerce\Products\ProductController;
use AlgerianCommerce\Products\ProductRepository;
use AlgerianCommerce\Products\ProductService;
use AlgerianCommerce\Products\VariationController;
use AlgerianCommerce\Products\VariationRepository;
use AlgerianCommerce\Products\VariationService;
use Throwable;
use WP_CLI;

use const AlgerianCommerce\REST_NAMESPACE;
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

    /** Per-client Yalidine configuration — roadmap §56, never credentials. */
    public const YALIDINE_SETTINGS_OPTION = 'ac_yalidine_settings';

    /** Per-client ZR Express configuration — roadmap §57. */
    public const ZR_EXPRESS_SETTINGS_OPTION = 'ac_zrexpress_settings';

    /** Per-client Chargily configuration — roadmap §59, never the secret key. */
    public const CHARGILY_SETTINGS_OPTION = 'ac_chargily_settings';

    /** The hourly "where are my parcels" event. */
    public const POLL_EVENT = 'ac_poll_shipments';

    /** The hourly "did that payment ever arrive" event — roadmap §59. */
    public const PAYMENT_POLL_EVENT = 'ac_poll_payments';

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
    private ?ShipmentRepository $shipmentRepository = null;
    private ?ShippingRuleRepository $shippingRuleRepository = null;
    private ?ProviderRegistry $shippingProviders = null;
    private ?ShippingService $shippingService = null;
    private ?YalidineSettings $yalidineSettings = null;
    private ?ZRExpressSettings $zrExpressSettings = null;
    private ?GeoDestinationDirectory $destinationDirectory = null;
    private ?DestinationSyncService $destinationSync = null;
    private ?ShipmentPoller $shipmentPoller = null;
    private ?CodRepository $codRepository = null;
    private ?CodService $codService = null;
    private ?CodSubscriber $codSubscriber = null;
    private ?PaymentProviderRegistry $paymentProviders = null;
    private ?PaymentService $paymentService = null;
    private ?TransactionRepository $transactionRepository = null;
    private ?WebhookEventRepository $webhookEventRepository = null;
    private ?PaymentPoller $paymentPoller = null;
    private ?ChargilySettings $chargilySettings = null;
    private ?GeoRepository $geoRepository = null;
    private ?GeoService $geoService = null;
    private ?GeoImporter $geoImporter = null;
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
        /*
         * Also not tied to the REST API: an order is cancelled from wp-admin,
         * WP-CLI, cron and gateways too, and a confirmation queue that keeps
         * calling customers about cancelled orders is the failure this stops.
         */
        $this->codSubscriber()->register();
        $this->registerShipmentPolling();
        $this->registerPaymentPolling();
        $this->registerCliCommands();

        $this->logger()->debug('Plugin booted', ['version' => VERSION]);
    }

    /**
     * The hourly parcel poll — roadmap §56.
     *
     * The listener is registered on every request; the schedule is created at
     * activation. Both halves are needed and they fail differently: a scheduled
     * event with no listener runs nothing forever, and a listener with no
     * schedule never runs at all.
     *
     * WP-Cron only fires when somebody visits the site, which is exactly the
     * wrong property for a shop that is quiet overnight while parcels keep
     * moving. That is a reason to prefer a real scheduler calling
     * `wp algerian-commerce sync-shipments`, not a reason to leave a shop with
     * no polling at all — so this is the floor, and DEPLOYMENT will say so.
     */
    private function registerShipmentPolling(): void
    {
        add_action(self::POLL_EVENT, function (): void {
            $this->shipmentPoller()->run();
        });

        /*
         * Scheduled here as well as at activation, because activation only
         * fires when somebody activates the plugin: an install that was
         * already running when this event was added would never schedule it,
         * and the failure looks exactly like a shop where no parcel ever
         * moves. `wp_next_scheduled()` reads the autoloaded `cron` option, so
         * the check costs nothing and the write happens once.
         */
        if (!wp_next_scheduled(self::POLL_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::POLL_EVENT);
        }
    }

    /**
     * The hourly "did that payment ever arrive" poll — roadmap §59.
     *
     * Registered exactly as the parcel poll is, and for the same two failure
     * modes: a scheduled event with no listener runs nothing forever, and a
     * listener with no schedule never runs at all. Scheduled here as well as at
     * activation, because an install that was already running when this event
     * was added would otherwise never schedule it.
     *
     * Hourly is the floor rather than the recommendation. A checkout lives for
     * thirty minutes, so a shop that wants a customer's browser to see "paid"
     * promptly points a real scheduler at
     * `wp algerian-commerce sync-payments` every few minutes — WP-Cron only
     * fires when somebody visits the site, which a quiet shop with open
     * checkouts cannot rely on.
     */
    private function registerPaymentPolling(): void
    {
        add_action(self::PAYMENT_POLL_EVENT, function (): void {
            $this->paymentPoller()->run();
        });

        if (!wp_next_scheduled(self::PAYMENT_POLL_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::PAYMENT_POLL_EVENT);
        }
    }

    private function registerCliCommands(): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        WP_CLI::add_command('algerian-commerce migrate', new MigrateCommand($this->migrations()));
        WP_CLI::add_command('algerian-commerce roles', new RolesCommand($this->roles()));
        WP_CLI::add_command('algerian-commerce unlock', new UnlockCommand($this->rateLimiter(), $this->rateLimitStore()));
        WP_CLI::add_command('algerian-commerce import-algeria', new ImportAlgeriaCommand($this->geoImporter()));
        WP_CLI::add_command('algerian-commerce sync-destinations', new SyncDestinationsCommand($this->destinationSync()));
        WP_CLI::add_command('algerian-commerce sync-shipments', new SyncShipmentsCommand($this->shipmentPoller()));
        WP_CLI::add_command('algerian-commerce sync-payments', new SyncPaymentsCommand($this->paymentPoller()));
        WP_CLI::add_command('algerian-commerce shipping-check', new ShippingCheckCommand(
            $this->shippingProviders(),
            $this->geoRepository(),
            $this->yalidineSettings(),
            $this->zrExpressSettings()
        ));
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
            new LocationController($this->logger(), $this->geoService()),
            new CodController($this->logger(), $this->codService()),
            new ShippingController($this->logger(), $this->shippingService()),
            new PaymentController($this->logger(), $this->paymentService()),
            /*
             * Registers one route per registered payment provider, so a shop
             * with no gateway configured has no inbound endpoint at all —
             * docs/SECURITY.md, "Webhooks".
             */
            new PaymentWebhookController(
                $this->logger(),
                $this->paymentProviders(),
                $this->paymentService()
            ),
            new ShippingWebhookController(
                $this->logger(),
                $this->shippingProviders(),
                $this->shippingService()
            ),
        ]);
    }

    public function shipmentRepository(): ShipmentRepository
    {
        global $wpdb;

        return $this->shipmentRepository ??= new ShipmentRepository($wpdb);
    }

    public function shippingRuleRepository(): ShippingRuleRepository
    {
        global $wpdb;

        return $this->shippingRuleRepository ??= new ShippingRuleRepository($wpdb);
    }

    /**
     * The couriers this shop has, in preference order — the first is the
     * default.
     *
     * This is where a provider is switched on for a client, and the only place
     * that reads its credentials and feature flag (docs/ARCHITECTURE.md §4).
     *
     * **Yalidine is registered first when it is on**, which makes it the
     * default for a shop that has it: a client who has gone to the trouble of
     * putting API credentials in `.env` ships with that courier and hands the
     * near ones to their own driver, not the other way round.
     *
     * Three conditions, all required: the feature flag, an API id and an API
     * token. A courier registered without credentials would appear in
     * `GET /shipping/providers`, be pickable in an admin UI, and fail at the
     * moment a parcel is created — which is the worst of the three places to
     * find out.
     *
     * In-house delivery is always registered: it needs no credentials, it is
     * what a shop falls back to when a courier is unreachable, and a store with
     * an empty registry could not create a shipment at all.
     */
    public function shippingProviders(): ProviderRegistry
    {
        if ($this->shippingProviders !== null) {
            return $this->shippingProviders;
        }

        $providers = [];
        $credentials = new YalidineCredentials(
            (string) $this->config()->secret('YALIDINE_API_ID'),
            (string) $this->config()->secret('YALIDINE_API_TOKEN'),
            (string) $this->config()->secret('YALIDINE_WEBHOOK_SECRET')
        );

        if ($this->config()->isEnabled('ENABLE_YALIDINE') && $credentials->isComplete()) {
            $settings = $this->yalidineSettings();

            $providers[] = new YalidineProvider(
                new YalidineClient(
                    new WpHttpClient($settings->timeout),
                    $credentials,
                    $settings,
                    $this->logger()
                ),
                $this->destinationDirectory(),
                $settings,
                $this->logger(),
                null,
                // Only the webhook verifier reads this — roadmap §60.
                $credentials
            );
        }

        $zrExpress = new ZRExpressCredentials(
            (string) $this->config()->secret('ZR_EXPRESS_TENANT_ID'),
            (string) $this->config()->secret('ZR_EXPRESS_API_KEY'),
            (string) $this->config()->secret('ZR_EXPRESS_WEBHOOK_SECRET')
        );

        if ($this->config()->isEnabled('ENABLE_ZR_EXPRESS') && $zrExpress->isComplete()) {
            $settings = $this->zrExpressSettings();

            $providers[] = new ZRExpressProvider(
                new ZRExpressClient(
                    new WpHttpClient($settings->timeout),
                    $zrExpress,
                    $settings,
                    $this->logger()
                ),
                $this->destinationDirectory(),
                $this->logger(),
                null,
                // Only the webhook verifier reads this — roadmap §60.
                $zrExpress
            );
        }

        $providers[] = new ManualProvider();

        return $this->shippingProviders = new ProviderRegistry($providers);
    }

    /**
     * ZR Express has far less to configure than Yalidine: no origin, no
     * insurance, no parcel defaults — the account carries all of it.
     */
    public function zrExpressSettings(): ZRExpressSettings
    {
        if ($this->zrExpressSettings !== null) {
            return $this->zrExpressSettings;
        }

        $stored = get_option(self::ZR_EXPRESS_SETTINGS_OPTION, []);

        return $this->zrExpressSettings = ZRExpressSettings::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * Everything about a Yalidine account that is not a secret.
     *
     * An option rather than `.env`, because roadmap §56 draws that line and it
     * is the right one: a warehouse's wilaya and a shop's default parcel weight
     * are configuration a client changes, not credentials. A bad value here
     * falls back to the default and is reported by
     * `wp algerian-commerce shipping-check` — an option must not be able to
     * fatal the plugin on boot.
     */
    public function yalidineSettings(): YalidineSettings
    {
        if ($this->yalidineSettings !== null) {
            return $this->yalidineSettings;
        }

        $stored = get_option(self::YALIDINE_SETTINGS_OPTION, []);

        return $this->yalidineSettings = YalidineSettings::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * What each courier calls the places in the §51 dataset.
     *
     * Shared by every adapter, and cached per request: a create call asks for
     * three destinations and a rates call for three more.
     */
    public function destinationDirectory(): GeoDestinationDirectory
    {
        return $this->destinationDirectory ??= new GeoDestinationDirectory($this->geoRepository());
    }

    public function destinationSync(): DestinationSyncService
    {
        return $this->destinationSync ??= new DestinationSyncService(
            $this->shippingProviders(),
            $this->geoRepository(),
            $this->auditLogger(),
            $this->logger()
        );
    }

    public function shipmentPoller(): ShipmentPoller
    {
        return $this->shipmentPoller ??= new ShipmentPoller(
            $this->shipmentRepository(),
            $this->shippingProviders(),
            $this->auditLogger(),
            $this->logger()
        );
    }

    /**
     * Takes the order and geography repositories: a shipment is *of* an order,
     * to a commune in the §51 dataset. Both dependencies run one way — nothing
     * in Orders/ or Geography/ knows shipping exists.
     */
    public function shippingService(): ShippingService
    {
        return $this->shippingService ??= new ShippingService(
            $this->shipmentRepository(),
            $this->shippingProviders(),
            $this->orderRepository(),
            $this->geoRepository(),
            $this->auditLogger(),
            $this->shippingRuleRepository(),
            $this->webhookEventRepository(),
            $this->logger()
        );
    }

    /**
     * Takes the order repository: a COD record is about an order, and this is
     * the direction the dependency runs — nothing in Orders/ knows COD exists.
     */
    public function codRepository(): CodRepository
    {
        return $this->codRepository ??= new CodRepository($this->orderRepository());
    }

    public function codService(): CodService
    {
        return $this->codService ??= new CodService(
            $this->codRepository(),
            $this->auditLogger()
        );
    }

    public function codSubscriber(): CodSubscriber
    {
        return $this->codSubscriber ??= new CodSubscriber($this->codRepository());
    }

    /**
     * The payment methods this shop offers, in preference order — the first is
     * the default (roadmap §58).
     *
     * The only place a payment provider's credentials and feature flag are read,
     * exactly as `shippingProviders()` is for couriers (docs/ARCHITECTURE.md §4).
     *
     * **This is where `ENABLE_COD` finally does something.** CLAUDE.md records
     * that `COD/` deliberately does not read it — that module is the
     * confirmation queue, and its state is order meta plus audit events. The
     * flag gates what checkout may *offer*, which is this list.
     *
     * A registry can legitimately be empty: a shop that has turned COD off and
     * not configured a gateway cannot take money, and `PaymentProviderRegistry`
     * answers 409 rather than pretending otherwise. That is a real
     * configuration, not a broken one — an operator midway through setup.
     *
     * Chargily joins here at §59, on the same three conditions Yalidine has: the
     * feature flag, and credentials that are actually present.
     */
    public function paymentProviders(): PaymentProviderRegistry
    {
        if ($this->paymentProviders !== null) {
            return $this->paymentProviders;
        }

        $providers = [];
        $chargily = new ChargilyCredentials((string) $this->config()->secret('CHARGILY_SECRET_KEY'));

        /*
         * Chargily first when it is on, which makes it the default for a shop
         * that has it: a client who has put a secret key in `.env` wants card
         * and EDAHABIA payment offered, with cash on delivery beside it.
         *
         * Two conditions rather than Yalidine's three, and that is a fact about
         * the gateway: Chargily authenticates with **one** secret key, which is
         * also what signs its webhooks — there is no second credential to check
         * for. See ChargilyCredentials on why `CHARGILY_WEBHOOK_SECRET` no
         * longer exists.
         */
        if ($this->config()->isEnabled('ENABLE_CHARGILY') && $chargily->isComplete()) {
            $settings = $this->chargilySettings();

            $providers[] = new ChargilyProvider(
                new ChargilyClient(
                    new WpHttpClient($settings->timeout),
                    $chargily,
                    $settings,
                    $this->logger()
                ),
                $settings,
                $chargily,
                $this->logger(),
                /*
                 * Deferred, and it has to be: this runs at `plugins_loaded`,
                 * where `$wp_rewrite` is null and `rest_url()` fatals inside
                 * `get_rest_url()`. Resolved when a checkout is created, it
                 * honours whatever this install is actually reachable at —
                 * pretty permalinks or `?rest_route=` — which is the only value
                 * Chargily could ever deliver to.
                 */
                static fn (): string => rest_url(REST_NAMESPACE . '/webhooks/' . ChargilyProvider::NAME)
            );
        }

        if ($this->config()->isEnabled('ENABLE_COD')) {
            $providers[] = new CashOnDeliveryProvider();
        }

        return $this->paymentProviders = new PaymentProviderRegistry($providers);
    }

    /**
     * Everything about a Chargily account that is not the secret key.
     *
     * An option rather than `.env`, on the line §56 drew for Yalidine and for
     * the same reason: a checkout page's language, and whether the shop or the
     * customer pays the gateway fee, are configuration a client changes — the
     * plugin is cloned per client. A bad value falls back to the default and is
     * reported by `problems()`; an option must never be able to fatal the plugin
     * on boot.
     */
    public function chargilySettings(): ChargilySettings
    {
        if ($this->chargilySettings !== null) {
            return $this->chargilySettings;
        }

        $stored = get_option(self::CHARGILY_SETTINGS_OPTION, []);

        return $this->chargilySettings = ChargilySettings::fromArray(is_array($stored) ? $stored : []);
    }

    /**
     * Takes the order repository: a payment is *for* an order, and the
     * dependency runs one way — nothing in `Orders/` knows payments exist.
     */
    public function paymentService(): PaymentService
    {
        return $this->paymentService ??= new PaymentService(
            $this->paymentProviders(),
            $this->orderRepository(),
            $this->auditLogger(),
            $this->transactionRepository(),
            $this->webhookEventRepository(),
            $this->logger()
        );
    }

    public function transactionRepository(): TransactionRepository
    {
        global $wpdb;

        return $this->transactionRepository ??= new TransactionRepository($wpdb);
    }

    public function webhookEventRepository(): WebhookEventRepository
    {
        global $wpdb;

        return $this->webhookEventRepository ??= new WebhookEventRepository($wpdb);
    }

    public function paymentPoller(): PaymentPoller
    {
        return $this->paymentPoller ??= new PaymentPoller(
            $this->transactionRepository(),
            $this->paymentService(),
            $this->logger()
        );
    }

    public function geoRepository(): GeoRepository
    {
        global $wpdb;

        return $this->geoRepository ??= new GeoRepository($wpdb);
    }

    public function geoService(): GeoService
    {
        return $this->geoService ??= new GeoService($this->geoRepository());
    }

    /**
     * The datasets ship inside the plugin, not at the repository root: the
     * plugin is what gets cloned per client and deployed, and an importer that
     * reaches outside its own directory has nothing to read on a real install.
     */
    public function geoImporter(): GeoImporter
    {
        return $this->geoImporter ??= new GeoImporter(
            $this->geoRepository(),
            $this->logger(),
            $this->auditLogger(),
            AC_CORE_PATH . 'data/algeria'
        );
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

        // Guarded rather than unconditional: reactivating a plugin must not
        // leave two events polling the same parcels twice an hour.
        if (!wp_next_scheduled(self::POLL_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::POLL_EVENT);
        }

        if (!wp_next_scheduled(self::PAYMENT_POLL_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::PAYMENT_POLL_EVENT);
        }

        flush_rewrite_rules(false);
    }

    public static function deactivate(): void
    {
        // A deactivated plugin leaves no timer behind asking couriers about
        // parcels, or gateways about payments, for a shop that has stopped
        // listening.
        wp_clear_scheduled_hook(self::POLL_EVENT);
        wp_clear_scheduled_hook(self::PAYMENT_POLL_EVENT);

        flush_rewrite_rules(false);
    }
}
