<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

use AlgerianCommerce\Analytics\AnalyticsCache;
use AlgerianCommerce\Analytics\AnalyticsController;
use AlgerianCommerce\Analytics\AnalyticsRepository;
use AlgerianCommerce\Analytics\AnalyticsService;
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
use AlgerianCommerce\CMS\CmsController;
use AlgerianCommerce\CMS\CmsRepository;
use AlgerianCommerce\CMS\CmsService;
use AlgerianCommerce\CMS\ContentTypes;
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
use AlgerianCommerce\CLI\SyncMarketingCommand;
use AlgerianCommerce\Marketing\MarketingController;
use AlgerianCommerce\Marketing\MarketingEventRepository;
use AlgerianCommerce\Marketing\MarketingProviderRegistry;
use AlgerianCommerce\Marketing\MarketingService;
use AlgerianCommerce\Integrations\Meta\MetaClient;
use AlgerianCommerce\Integrations\Meta\MetaCredentials;
use AlgerianCommerce\Integrations\Meta\MetaProvider;
use AlgerianCommerce\Integrations\Meta\MetaSettings;
use AlgerianCommerce\Media\ImageSanitizer;
use AlgerianCommerce\Media\MediaController;
use AlgerianCommerce\Media\MediaRepository;
use AlgerianCommerce\Media\MediaService;
use AlgerianCommerce\Media\UploadPolicy;
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

    /** Per-client Meta configuration — roadmap §62b, never the access token. */
    public const META_SETTINGS_OPTION = 'ac_meta_settings';

    /** The hourly "where are my parcels" event. */
    public const POLL_EVENT = 'ac_poll_shipments';

    /** The hourly "did that payment ever arrive" event — roadmap §59. */
    public const PAYMENT_POLL_EVENT = 'ac_poll_payments';

    /** The "send what the shop has already sold" drain — roadmap §62b. */
    public const MARKETING_DRAIN_EVENT = 'ac_drain_marketing';

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
    private ?ContentTypes $contentTypes = null;
    private ?CmsRepository $cmsRepository = null;
    private ?CmsService $cmsService = null;
    private ?UploadPolicy $uploadPolicy = null;
    private ?MediaRepository $mediaRepository = null;
    private ?MediaService $mediaService = null;
    private ?MarketingProviderRegistry $marketingProviders = null;
    private ?MarketingEventRepository $marketingEventRepository = null;
    private ?MarketingService $marketingService = null;
    private ?MetaSettings $metaSettings = null;
    private ?AnalyticsRepository $analyticsRepository = null;
    private ?AnalyticsService $analyticsService = null;
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
        /*
         * Also not tied to the REST API: a post type has to exist on every
         * request, or WP_Query returns nothing and the editor screens are
         * absent — and it must be registered on `init`, which is later than
         * this. See ContentTypes.
         */
        $this->contentTypes()->register();
        $this->registerShipmentPolling();
        $this->registerPaymentPolling();
        $this->registerMarketingDrain();
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

    /**
     * The marketing queue drain — roadmap §62b.
     *
     * Registered exactly as the two polls are, and for the same pair of failure
     * modes: a scheduled event with no listener runs nothing forever, and a
     * listener with no schedule never runs at all.
     *
     * **Every outbound advertising call in this plugin happens here**, which is
     * the design rather than a convenience: §62b forbids calling Meta on the
     * checkout path, so an ad network being down costs a delay in reporting and
     * never an order.
     *
     * Five minutes rather than hourly. A conversion reported late still
     * attributes correctly, but Meta's own guidance is to report promptly, and
     * a shop watching its ads dashboard after a launch should not wait an hour
     * to see the first sale. WP-Cron still only fires when somebody visits, so
     * a real deployment points a scheduler at `sync-marketing`.
     */
    private function registerMarketingDrain(): void
    {
        add_action(self::MARKETING_DRAIN_EVENT, function (): void {
            $this->marketingService()->drain();
        });

        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['ac_five_minutes'] ??= [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display' => 'Every five minutes (Algerian Commerce)',
            ];

            return $schedules;
        });

        if (!wp_next_scheduled(self::MARKETING_DRAIN_EVENT)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'ac_five_minutes', self::MARKETING_DRAIN_EVENT);
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
        WP_CLI::add_command('algerian-commerce sync-marketing', new SyncMarketingCommand($this->marketingService()));
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
            new CmsController($this->logger(), $this->cmsService()),
            new MediaController($this->logger(), $this->mediaService()),
            new MarketingController($this->logger(), $this->marketingService()),
            new AnalyticsController($this->logger(), $this->analyticsService()),
        ]);
    }

    /**
     * The only place aggregate SQL over the order tables is constructed —
     * roadmap §63. See `AnalyticsRepository` for why that exception to
     * docs/ARCHITECTURE.md §7's "OrderRepository is the only file that touches
     * an order" is narrow enough to be safe.
     */
    public function analyticsRepository(): AnalyticsRepository
    {
        global $wpdb;

        return $this->analyticsRepository ??= new AnalyticsRepository($wpdb);
    }

    /**
     * Takes `CodService` and `InventoryRepository`: the COD funnel and the
     * low-stock count already exist, and a dashboard that recomputed either
     * would eventually disagree with the endpoint that owns it. The dependency
     * runs one way — neither module has heard of analytics.
     */
    public function analyticsService(): AnalyticsService
    {
        return $this->analyticsService ??= new AnalyticsService(
            $this->analyticsRepository(),
            new AnalyticsCache($this->analyticsCacheTtl()),
            $this->codService(),
            $this->inventoryRepository(),
            $this->logger(),
            VERSION
        );
    }

    /**
     * How long an analytics response may be reused, in seconds.
     *
     * `0` turns the cache off, which is what the test suite runs with so its
     * assertions see the shop as it is rather than as it was a minute ago.
     * A value that is not a number falls back to the default rather than to
     * zero: a typo in `.env` should not quietly turn a performance feature off.
     */
    private function analyticsCacheTtl(): int
    {
        $configured = $this->config()->get('AC_ANALYTICS_CACHE_TTL');

        if ($configured === null || !is_numeric($configured)) {
            return AnalyticsCache::DEFAULT_TTL;
        }

        return max(0, (int) $configured);
    }

    /**
     * The advertising destinations this shop reports to — roadmap §62b.
     *
     * The only place a pixel id, an access token or `ENABLE_MARKETING_PIXELS`
     * is read, exactly as `shippingProviders()` and `paymentProviders()` are for
     * their own credentials (docs/ARCHITECTURE.md §4).
     *
     * **An empty registry is the normal case**, and the difference from
     * payments matters: a shop with no gateway cannot take money and says so
     * with a 409, while a shop with no ad account simply has no pixel.
     * `GET /marketing/config` answers `enabled: false` and nothing errors.
     *
     * Both credentials are required, not either: a pixel id without a token
     * would put a working browser pixel on the storefront with no server-side
     * half at all — which looks configured and silently halves the match rate.
     */
    public function marketingProviders(): MarketingProviderRegistry
    {
        if ($this->marketingProviders !== null) {
            return $this->marketingProviders;
        }

        $providers = [];
        $meta = new MetaCredentials(
            (string) $this->config()->secret('META_PIXEL_ID'),
            (string) $this->config()->secret('META_CAPI_ACCESS_TOKEN')
        );

        if ($this->config()->isEnabled('ENABLE_MARKETING_PIXELS') && $meta->isComplete()) {
            $settings = $this->metaSettings();

            $providers[] = new MetaProvider(
                new MetaClient(
                    new WpHttpClient($settings->timeout),
                    $meta,
                    $settings,
                    $this->logger()
                ),
                $meta,
                $settings
            );
        }

        return $this->marketingProviders = new MarketingProviderRegistry($providers);
    }

    /**
     * Everything about a Meta dataset that is not a credential.
     *
     * An option rather than `.env`, on the line §56 drew: the Graph API version
     * a shop is pinned to, and the `test_event_code` somebody is debugging with
     * this afternoon, are configuration a person changes. A bad value falls back
     * to the default and is reported by `problems()`.
     */
    public function metaSettings(): MetaSettings
    {
        if ($this->metaSettings !== null) {
            return $this->metaSettings;
        }

        $stored = get_option(self::META_SETTINGS_OPTION, []);

        return $this->metaSettings = MetaSettings::fromArray(is_array($stored) ? $stored : []);
    }

    public function marketingEventRepository(): MarketingEventRepository
    {
        global $wpdb;

        return $this->marketingEventRepository ??= new MarketingEventRepository($wpdb);
    }

    /**
     * Takes the order repository: a conversion is *about* an order, and the
     * dependency runs one way — nothing in `Orders/` knows marketing exists.
     */
    public function marketingService(): MarketingService
    {
        return $this->marketingService ??= new MarketingService(
            $this->marketingProviders(),
            $this->marketingEventRepository(),
            $this->orderRepository(),
            $this->auditLogger(),
            $this->logger()
        );
    }

    public function contentTypes(): ContentTypes
    {
        return $this->contentTypes ??= new ContentTypes();
    }

    public function cmsRepository(): CmsRepository
    {
        return $this->cmsRepository ??= new CmsRepository();
    }

    public function cmsService(): CmsService
    {
        return $this->cmsService ??= new CmsService($this->cmsRepository(), $this->logger());
    }

    /**
     * What `POST /media` will accept — roadmap §61.
     *
     * The cap is resolved once, here, from the configured value and PHP's own
     * `upload_max_filesize`. Both are needed: a 20 MB setting on a host that
     * accepts 2 MB is a promise the web server breaks before this plugin ever
     * sees the request.
     */
    public function uploadPolicy(): UploadPolicy
    {
        return $this->uploadPolicy ??= UploadPolicy::withCap(
            $this->config()->get('AC_MEDIA_MAX_BYTES'),
            function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : null
        );
    }

    public function mediaRepository(): MediaRepository
    {
        return $this->mediaRepository ??= new MediaRepository(
            $this->uploadPolicy(),
            new ImageSanitizer($this->logger())
        );
    }

    /**
     * Takes the rate limiter, which no other service does: an upload is the
     * one write whose cost is measured in megabytes and CPU rather than in
     * rows, so it carries a limit of its own on top of the namespace-wide one.
     */
    public function mediaService(): MediaService
    {
        return $this->mediaService ??= new MediaService(
            $this->mediaRepository(),
            $this->uploadPolicy(),
            $this->rateLimiter(),
            $this->auditLogger(),
            $this->logger()
        );
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
        // listening — nor one reporting conversions to an ad account.
        wp_clear_scheduled_hook(self::POLL_EVENT);
        wp_clear_scheduled_hook(self::PAYMENT_POLL_EVENT);
        wp_clear_scheduled_hook(self::MARKETING_DRAIN_EVENT);

        flush_rewrite_rules(false);
    }
}
