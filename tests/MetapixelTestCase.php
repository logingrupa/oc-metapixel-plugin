<?php

namespace Logingrupa\Metapixelshopaholic\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Model;
use October\Rain\Database\Model as ActiveRecord;
use October\Rain\Database\Pivot;
use October\Tests\Concerns\InteractsWithAuthentication;
use October\Tests\Concerns\PerformsMigrations;
use October\Tests\Concerns\PerformsRegistrations;

/**
 * PHPUnit 12 / Pest 4 compatible test case for October CMS plugins.
 *
 * October's PluginTestCase declares setUp() as public, which conflicts
 * with PHPUnit 12's protected setUp(). This class reimplements the same
 * bootstrap logic with correct visibility.
 *
 * Phase 2 update (Plan 02-01): autoMigrate and autoRegister are now `false`.
 * Plugin.php now declares
 *   public $require = ['Lovata.Toolbox', 'Lovata.Shopaholic', 'Lovata.OrdersShopaholic'].
 * Running the full Lovata.Shopaholic + Lovata.OrdersShopaholic migration chain on
 * SQLite-in-memory is prohibitively slow (>4 minutes) and unreliable (SQLite
 * cannot drop indexed columns — see goodsreceivedshopaholic SettingsAccessorTest
 * forensic trace). Tests now opt-in to hermetic schemas via `bootSystemSettings()`
 * and `bootOrdersStatuses()` helpers below — matching the established
 * GoodsReceivedTestCase pattern (D-22).
 *
 * Tests that need the full Shopaholic stack should set $autoMigrate = true in
 * their own subclass override.
 */
abstract class MetapixelTestCase extends TestCase
{
    use InteractsWithAuthentication;
    use PerformsMigrations;
    use PerformsRegistrations;

    /** @var bool */
    protected $autoMigrate = false;

    /** @var bool */
    protected $autoRegister = false;

    public function createApplication()
    {
        $app = require __DIR__.'/../../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        // Force the test DB configuration AFTER kernel bootstrap.
        //
        // Laravel's dotenv loader loads `.env` during bootstrap which overwrites
        // the PHPUnit `<env force="true">` directives — leaving config('database
        // .default') pointing at the production MySQL connection. We force the
        // SQLite-in-memory connection programmatically here so plugin event
        // handlers that fire `Settings::getValue()` during boot (e.g. Lovata.
        // GoodNews ArticleModelHandler) hit the ephemeral SQLite, not prod MySQL.
        config([
            'app.env'           => 'testing',
            'database.default'  => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default'    => 'array',
            'session.driver'   => 'array',
        ]);

        // Disconnect any pre-bootstrap PDO so subsequent queries pick up the
        // sqlite connection just configured.
        $app['db']->purge();

        $app->singleton('auth', function ($app) {
            $app['auth.loaded'] = true;

            return AuthManager::instance();
        });

        // Provision the system_settings table EARLY (before plugin boot fires
        // deferred mail.manager callbacks that read MailSetting). Without this,
        // System module's extendMailerService callback queries `system_settings`
        // during `\Mail::pretend()` resolution and trips a missing-table error
        // before any test body runs.
        $this->ensureSystemSettingsTable($app);

        return $app;
    }

    /**
     * Create the minimal system_settings table on the sqlite connection so
     * `SettingModel::get()` calls during plugin boot do not fail. Safe to call
     * multiple times.
     */
    private function ensureSystemSettingsTable($app): void
    {
        $obSchema = $app['db']->connection()->getSchemaBuilder();
        if ($obSchema->hasTable('system_settings')) {
            return;
        }

        $obSchema->create('system_settings', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('item')->nullable()->index();
            $obTable->mediumtext('value')->nullable();
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedInteger('site_root_id')->nullable();
            $obTable->unsignedInteger('site_group_id')->nullable();
        });
    }

    protected function setUp(): void
    {
        $this->pluginTestCaseMigratedPlugins = [];
        $this->pluginTestCaseLoadedPlugins = [];

        parent::setUp();

        if ($this->autoRegister === true) {
            $this->loadCurrentPlugin();
        }

        if ($this->autoMigrate === true) {
            $this->migrateModules();
            $this->migrateCurrentPlugin();
        }

        \Mail::pretend();
    }

    protected function tearDown(): void
    {
        // Flush model event listeners BEFORE dropping hermetic schemas, since
        // flushEventListeners triggers Model::bootIfNotBooted which fires
        // Article::extend() callbacks that query Settings::getValue() →
        // dropping system_settings first would error here.
        $this->flushModelEventListeners();
        $this->flushPluginSingletons();
        $this->dropHermeticSchemas();
        parent::tearDown();
        unset($this->app);
    }

    /**
     * Reset plugin-owned singletons between tests so the PluginGuard
     * disabled-flag memo does not bleed across tests. Mirrors
     * GoodsReceivedTestCase::flushPluginSingletons() per Plan 02-02 (S2).
     *
     * Each new singleton (Stores, Caches, Helpers) MUST add a static
     * flush() method and a corresponding line here. Subsequent Phase 2-5
     * plans MAY add lines but MUST NOT remove PluginGuard::flush().
     */
    protected function flushPluginSingletons(): void
    {
        \Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard::flush();
    }

    /**
     * Provision the minimal `system_settings` table October's SettingModel
     * touches (id + item + value + per-site columns). Mirrors the
     * goodsreceivedshopaholic SettingsAccessorTestCase hermetic pattern.
     *
     * Subclasses call this in setUp() AFTER parent::setUp() when they need
     * to round-trip Settings::get()/set() but cannot afford the full
     * Lovata.Shopaholic + Lovata.OrdersShopaholic migration chain.
     */
    protected function bootSystemSettings(): void
    {
        if (Schema::hasTable('system_settings')) {
            return;
        }

        Schema::create('system_settings', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('item')->nullable()->index();
            $obTable->mediumtext('value')->nullable();
            // Multisite trait writes these even when not configured; nullable
            // so a single-site SettingModel save() doesn't trip NOT NULL.
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedInteger('site_root_id')->nullable();
            $obTable->unsignedInteger('site_group_id')->nullable();
        });
    }

    /**
     * Provision a minimal `lovata_orders_shopaholic_statuses` table seeded with
     * the canonical Lovata statuses + the custom `new-payment-received` row.
     * `Settings::getPaidStatusCodeOptions()` queries this via
     * `Status::lists('name','code')`.
     */
    protected function bootOrdersStatuses(): void
    {
        if (! Schema::hasTable('lovata_orders_shopaholic_statuses')) {
            Schema::create('lovata_orders_shopaholic_statuses', function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('name');
                $obTable->string('code')->unique();
                $obTable->string('color')->nullable();
                $obTable->text('preview_text')->nullable();
                $obTable->boolean('is_user_show')->default(true);
                $obTable->unsignedInteger('user_status_id')->nullable();
                $obTable->integer('sort_order')->default(0);
                $obTable->timestamps();
            });
        }

        \DB::table('lovata_orders_shopaholic_statuses')->insertOrIgnore([
            ['id' => 1, 'name' => 'New',                    'code' => 'new',                    'sort_order' => 1, 'is_user_show' => 1],
            ['id' => 2, 'name' => 'In progress',            'code' => 'in_progress',            'sort_order' => 2, 'is_user_show' => 1],
            ['id' => 3, 'name' => 'Complete',               'code' => 'complete',               'sort_order' => 3, 'is_user_show' => 1],
            ['id' => 4, 'name' => 'Canceled',               'code' => 'canceled',               'sort_order' => 4, 'is_user_show' => 1],
            ['id' => 5, 'name' => 'New payment received',   'code' => 'new-payment-received',   'sort_order' => 5, 'is_user_show' => 1],
        ]);
    }

    /**
     * Provision the minimal `lovata_orders_shopaholic_orders` table — only the
     * columns this plugin's Phase 3 work touches (status_id, secret_key,
     * order_number, total_price_value, currency_id, customer fields). Mirrors
     * the RetryPaymentTestCase hermetic pattern (lines 93-115). Plan 03-01
     * MigrationsBootTest calls this before running the Phase 3 migration so
     * `Schema::table(..., function (Blueprint $obTable) { $obTable->string(...) })`
     * has a table to mutate. Subsequent Phase 3 plans (03-06 OrderStatusWatcher)
     * also call this to land hermetic Order rows.
     *
     * Guarded — safe to call multiple times in a single test.
     */
    protected function bootOrdersTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_orders')) {
            return;
        }

        Schema::create('lovata_orders_shopaholic_orders', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('status_id')->nullable();
            $obTable->string('order_number')->nullable();
            $obTable->string('secret_key')->nullable();
            // Phase 3 plan 03-06: dedup-fence + event_time companion columns.
            // Both are persisted atomically by OrderStatusWatcher::handleUpdated
            // via a single saveQuietly() so the browser-side PurchasePixel twin
            // (components/PurchasePixel.php) reads the SAME event_time as the
            // server-side CAPI dispatch — required for Meta to dedup Pixel +
            // CAPI by event_id within its ±10 s event_time window.
            $obTable->string('meta_purchase_event_id', 36)->nullable()->index();
            $obTable->unsignedBigInteger('meta_purchase_event_time')->nullable();
            $obTable->decimal('total_price_value', 15, 2)->nullable();
            $obTable->integer('currency_id')->nullable();
            $obTable->string('email')->nullable();
            $obTable->string('phone')->nullable();
            $obTable->string('name')->nullable();
            $obTable->string('last_name')->nullable();
            $obTable->timestamps();
        });
    }

    /**
     * Drop hermetic tables in tearDown() so each test starts from a clean
     * schema slate. Safe to call when no hermetic table was created.
     */
    protected function dropHermeticSchemas(): void
    {
        // Phase 3 plan 03-06: drop fixture-side hermetic tables (offers,
        // products, order_positions) provisioned by OrderFixtures, in
        // reverse-FK order so SQLite is happy even if FK checks are
        // enabled in a future configuration. SQLite-in-memory currently
        // runs with `foreign_key_constraints = false` (see createApplication
        // config block), so ordering is correctness-positive but not yet
        // load-bearing — kept for portability.
        Schema::dropIfExists('lovata_orders_shopaholic_order_positions');
        Schema::dropIfExists('lovata_shopaholic_offers');
        Schema::dropIfExists('lovata_shopaholic_products');
        Schema::dropIfExists('logingrupa_metapixel_failed_events');
        Schema::dropIfExists('lovata_orders_shopaholic_orders');
        Schema::dropIfExists('lovata_orders_shopaholic_statuses');
        Schema::dropIfExists('system_settings');
    }

    protected function flushModelEventListeners()
    {
        foreach (get_declared_classes() as $class) {
            if ($class == Pivot::class) {
                continue;
            }

            $reflectClass = new \ReflectionClass($class);
            if (
                ! $reflectClass->isInstantiable() ||
                ! $reflectClass->isSubclassOf(Model::class) ||
                $reflectClass->isSubclassOf(Pivot::class)
            ) {
                continue;
            }

            $class::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    protected function guessPluginCodeFromTest()
    {
        $reflect = new \ReflectionClass($this);
        $path = $reflect->getFilename();
        $pluginPath = $this->app->pluginsPath();

        if (strpos($path, $pluginPath) === 0) {
            $result = ltrim(str_replace('\\', '/', substr($path, strlen($pluginPath))), '/');
            $result = implode('.', array_slice(explode('/', $result), 0, 2));

            return $result;
        }

        return false;
    }

    protected function isAppCodeFromTest()
    {
        return false;
    }
}
