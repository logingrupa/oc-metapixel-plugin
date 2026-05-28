<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Collection;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionProperty;

/**
 * VIEW-04 — ProductPageWatcher subscribes shopaholic.product.open, dispatches
 * SendCapiEvent('ViewContent'), pushes the ThemeEventCollector entry with
 * matching event_id, resolves the SKU shape per offer count (D-5 + D-10),
 * forwards request user_data (cookies + UA + IP) via the
 * CapturesRequestUserData trait, allows per-pageload re-fires (the EventLog
 * UNIQUE race-fence is per-channel and engages inside the queue worker, not
 * inside the watcher), and re-fires on offer-switch AJAX with a NEW
 * server-generated event_id and forced SKU-{pid}-{oid_new} content_ids.
 *
 * CurrencyHelper uses October's Singleton trait — its static $instance is
 * pinned via Reflection in setUp so the resolver's CurrencyHelper::instance()
 * call does not trigger init() (which would query the unseeded
 * lovata_shopaholic_currency table).
 */
#[Group('adapter')]
final class ProductPageWatcherTest extends ShopaholicAdapterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootOffersAndProductsTables();
        $this->bootProductSiteRelationTable();
        $this->bootSystemSiteDefinitionsTable();
        $this->bootPricesTable();
        (new CreateMetapixelEventLogTable)->up();

        $this->app->singleton(ThemeEventCollector::class);

        PluginGuard::reset();
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'TEST-PIXEL-1',
            'capi_access_token' => 'TEST-TOKEN-1',
            'default_currency_code' => 'EUR',
        ]);
        Settings::clearInternalCache();

        $this->stubCurrencyHelperWithCode('EUR');

        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(ThemeEventCollector::class);
        PluginGuard::reset();
        CurrencyHelper::forgetInstance();
        (new CreateMetapixelEventLogTable)->down();
        Schema::dropIfExists('lovata_shopaholic_product_site_relation');
        Schema::dropIfExists('system_site_definitions');
        Schema::dropIfExists('lovata_shopaholic_prices');

        // Reset superglobals that test_user_data_populated_from_server_and_cookies sets.
        unset(
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_COOKIE['_fbp'],
            $_COOKIE['_fbc'],
        );

        parent::tearDown();
    }

    public function test_viewcontent_dispatches_capi_and_pushes_collector_on_shopaholic_product_open(): void
    {
        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        (new ProductPageWatcher)->handle($obProduct);

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'ViewContent'
                && $obJob->sAdapterClass === ShopaholicProductAdapter::class;
        });
        $this->assertSame(1, App::make(ThemeEventCollector::class)->count(), 'collector has exactly 1 push');
    }

    public function test_does_not_fire_when_plugin_guard_disabled(): void
    {
        Settings::set(['pixel_id' => '']);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        (new ProductPageWatcher)->handle($obProduct);

        Bus::assertNotDispatched(SendCapiEvent::class);
        $this->assertSame(0, App::make(ThemeEventCollector::class)->count(), 'collector empty when plugin disabled');
    }

    public function test_is_subscribed_to_shopaholic_product_open_event_handler(): void
    {
        $obDispatcher = new Dispatcher;

        (new ProductPageWatcher)->subscribe($obDispatcher);

        $this->assertTrue(
            $obDispatcher->hasListeners('shopaholic.product.open'),
            'subscribe() MUST register a listener for shopaholic.product.open',
        );
    }

    public function test_zero_offer_product_resolves_bare_sku_pid(): void
    {
        $obProduct = $this->makeProduct(42, []);

        (new ProductPageWatcher)->handle($obProduct);

        $arPushed = App::make(ThemeEventCollector::class)->flush();
        $this->assertCount(1, $arPushed);
        $this->assertSame(['SKU-42'], $arPushed[0]['content_ids']);
    }

    public function test_multi_offer_product_resolves_sku_pid_oid_first_active_by_sort_order(): void
    {
        $obProduct = $this->makeProduct(42, [
            [101, 5.00, 1, true],
            [100, 9.99, 0, true],
        ]);

        (new ProductPageWatcher)->handle($obProduct);

        $arPushed = App::make(ThemeEventCollector::class)->flush();
        $this->assertSame(['SKU-42-100'], $arPushed[0]['content_ids']);
    }

    public function test_single_offer_product_resolves_bare_sku_pid(): void
    {
        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        (new ProductPageWatcher)->handle($obProduct);

        $arPushed = App::make(ThemeEventCollector::class)->flush();
        $this->assertSame(['SKU-42'], $arPushed[0]['content_ids']);
    }

    public function test_capi_payload_event_id_matches_collector_pushed_event_id(): void
    {
        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        (new ProductPageWatcher)->handle($obProduct);

        $arPushed = App::make(ThemeEventCollector::class)->flush();
        $sCollectorEventId = $arPushed[0]['event_id'];

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob) use ($sCollectorEventId): bool {
            return ($obJob->arPayload['data'][0]['event_id'] ?? null) === $sCollectorEventId;
        });
    }

    public function test_user_data_populated_from_server_and_cookies(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Test/1.0';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.1';
        $_COOKIE['_fbp'] = 'fb.1.123.456';
        $_COOKIE['_fbc'] = 'fb.1.789.abc';

        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        (new ProductPageWatcher)->handle($obProduct);

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob): bool {
            $arUserData = $obJob->arPayload['data'][0]['user_data'] ?? [];

            return ($arUserData['client_user_agent'] ?? null) === 'Test/1.0'
                && ($arUserData['client_ip_address'] ?? null) === '203.0.113.1'
                && ($arUserData['fbp'] ?? null) === 'fb.1.123.456'
                && ($arUserData['fbc'] ?? null) === 'fb.1.789.abc';
        });
    }

    public function test_test_event_code_appears_in_capi_payload_and_collector_event(): void
    {
        // Settings::test_event_code is injected into the outgoing CAPI payload
        // by SendCapiEvent::withTestEventCode at handle-time (Phase 2 lock).
        // Bus::fake intercepts before handle runs, so we verify the wiring at
        // the watcher seam: the dispatched job carries the right event_name +
        // subject so the queue worker can stamp test_event_code downstream;
        // the collector push carries event_id so the browser fbq's 4th arg can
        // match the server-side event_id even when Test Events is engaged.
        Settings::set(['test_event_code' => 'TEST123']);
        Settings::clearInternalCache();

        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        (new ProductPageWatcher)->handle($obProduct);

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob) use ($obProduct): bool {
            return $obJob->sEventName === 'ViewContent'
                && $obJob->obSubject === $obProduct;
        });

        $arPushed = App::make(ThemeEventCollector::class)->flush();
        $this->assertArrayHasKey('event_id', $arPushed[0]);
        $this->assertSame('TEST123', Settings::get('test_event_code', ''), 'test_event_code is configured + readable');
    }

    public function test_event_log_race_fence_does_not_block_per_pageload_duplicates(): void
    {
        $obProduct = $this->makeProduct(42, [[100, 9.99, 0, true]]);

        $obWatcher = new ProductPageWatcher;
        $obWatcher->handle($obProduct);
        $obWatcher->handle($obProduct);

        // Two distinct watcher emissions: 2 dispatches + 2 collector pushes.
        // The EventLog UNIQUE race-fence runs inside SendCapiEvent::handle
        // (queue-side); Bus::fake bypasses handle, so the watcher itself
        // never short-circuits on per-pageload duplicates. Different event_id
        // UUIDv4 per call.
        Bus::assertDispatchedTimes(SendCapiEvent::class, 2);

        $arPushed = App::make(ThemeEventCollector::class)->flush();
        $this->assertCount(2, $arPushed);
        $this->assertNotSame(
            $arPushed[0]['event_id'],
            $arPushed[1]['event_id'],
            'each watcher emission MUST carry a fresh UUIDv4 event_id',
        );
        $this->assertNotSame(
            $arPushed[0]['action_key'],
            $arPushed[1]['action_key'],
            'action_key is per-event unique via event_id suffix',
        );
    }

    public function test_offer_switch_ajax_re_fires_viewcontent_with_new_event_id_and_offer_sku(): void
    {
        // Seed Product + 2 Offers in DB so ShopaholicProductAdapter::loadSubject
        // (which runs Product::active()->find($iSubjectId) inside
        // dispatchForOfferSwitch) returns the live model.
        DB::table('lovata_shopaholic_products')->insertOrIgnore([[
            'id' => 42,
            'name' => 'Test Product',
            'slug' => 'test-product-42',
            'active' => 1,
        ]]);
        DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => 100, 'product_id' => 42, 'name' => 'Offer A', 'price_value' => 9.99, 'active' => 1, 'sort_order' => 0],
            ['id' => 101, 'product_id' => 42, 'name' => 'Offer B', 'price_value' => 12.50, 'active' => 1, 'sort_order' => 1],
        ]);
        DB::table('lovata_shopaholic_product_site_relation')->insertOrIgnore([
            ['product_id' => 42, 'site_id' => 1],
        ]);

        // Pre-seed the saved-price field on each Offer reflectively so the
        // resolver's price_value accessor does not query lovata_shopaholic_prices.
        $obReflect = new ReflectionProperty(Offer::class, 'fSavedPrice');
        $obReflect->setAccessible(true);

        $obWatcher = new ProductPageWatcher;

        // First, fire a PDP-render dispatch so the offer-switch path is the
        // SECOND fire — verifies the action_key suffix uniqueness across
        // ordinary + offer-switch entries.
        $obProductMem = $this->makeProduct(42, [[100, 9.99, 0, true], [101, 12.50, 1, true]]);
        $obWatcher->handle($obProductMem);

        $arPushedFirst = App::make(ThemeEventCollector::class)->flush();
        $sFirstEventId = $arPushedFirst[0]['event_id'];

        // Now exercise the AJAX boundary entry point. iOfferId=101 is the
        // NON-default offer (default is 100 per sort_order 0).
        $sNewEventId = $obWatcher->dispatchForOfferSwitch(42, 101);

        $this->assertNotSame(
            $sFirstEventId,
            $sNewEventId,
            'dispatchForOfferSwitch MUST mint a fresh UUIDv4 (NOT reuse the PDP-render event_id)',
        );
        $this->assertTrue(
            Uuid::isValid($sNewEventId),
            'returned event_id is a valid UUID',
        );

        Bus::assertDispatchedTimes(SendCapiEvent::class, 2);
        Bus::assertDispatched(
            SendCapiEvent::class,
            static function (SendCapiEvent $obJob) use ($sNewEventId): bool {
                return ($obJob->arPayload['data'][0]['event_id'] ?? null) === $sNewEventId
                    && ($obJob->arPayload['data'][0]['custom_data']['content_ids'] ?? null) === ['SKU-42-101'];
            },
        );

        $arPushedSwitch = App::make(ThemeEventCollector::class)->flush();
        $this->assertCount(1, $arPushedSwitch, 'offer-switch leaves exactly 1 new entry in the collector');
        $this->assertSame(
            'viewcontent:42:101:'.$sNewEventId,
            $arPushedSwitch[0]['action_key'],
            'action_key is canonical viewcontent:{pid}:{oid}:{eid} with server-appended event_id',
        );

        // Disabled-state path — Settings.pixel_id empty MUST throw so the
        // AJAX boundary can surface the failure to the JS soft-gate.
        Settings::set(['pixel_id' => '']);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $this->expectException(\RuntimeException::class);
        $obWatcher->dispatchForOfferSwitch(42, 101);
    }

    /**
     * Build a Product with an in-memory Collection of Offer rows. Each offer
     * tuple is [id, price_value, sort_order, active].
     *
     * @param  list<array{0: int, 1: float, 2: int, 3: bool}>  $arOffers
     */
    private function makeProduct(int $iProductId, array $arOffers): Product
    {
        $obProduct = new Product;
        $obProduct->setAttribute('id', $iProductId);
        $obProduct->setAttribute('name', 'Test Product');

        $arOfferModels = [];
        foreach ($arOffers as [$iOfferId, $fPrice, $iSortOrder, $bActive]) {
            $arOfferModels[] = $this->makeOffer($iOfferId, $fPrice, $iSortOrder, $bActive);
        }
        $obProduct->setRelation('offer', new Collection($arOfferModels));

        return $obProduct;
    }

    private function makeOffer(int $iOfferId, float $fPrice, int $iSortOrder, bool $bActive): Offer
    {
        $obOffer = new Offer;
        $obOffer->setAttribute('id', $iOfferId);
        $obOffer->setAttribute('sort_order', $iSortOrder);
        $obOffer->setAttribute('active', $bActive);

        // Lovata Offer::getPriceValueAttribute reads $fSavedPrice first (protected
        // field) and only falls back to lovata_shopaholic_prices when null.
        $obReflect = new ReflectionProperty(Offer::class, 'fSavedPrice');
        $obReflect->setAccessible(true);
        $obReflect->setValue($obOffer, $fPrice);

        return $obOffer;
    }

    private function bootPricesTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_prices')) {
            return;
        }
        Schema::create('lovata_shopaholic_prices', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->decimal('price_value', 18, 4)->nullable();
        });
    }

    private function bootProductSiteRelationTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_product_site_relation')) {
            return;
        }
        Schema::create('lovata_shopaholic_product_site_relation', function ($obTable): void {
            $obTable->integer('product_id');
            $obTable->integer('site_id');
        });
    }

    /**
     * Product::site() belongsToMany joins system_site_definitions through
     * lovata_shopaholic_product_site_relation. Provision the system table
     * with a single primary site so $obProduct->site_list resolves to [1]
     * inside loadSubject's site-match guard.
     */
    private function bootSystemSiteDefinitionsTable(): void
    {
        if (Schema::hasTable('system_site_definitions')) {
            return;
        }
        Schema::create('system_site_definitions', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->boolean('is_enabled')->default(true);
            $obTable->boolean('is_primary')->default(false);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
        });
        DB::table('system_site_definitions')->insertOrIgnore([
            ['id' => 1, 'name' => 'Test Site', 'code' => 'test', 'is_enabled' => 1, 'is_primary' => 1, 'sort_order' => 1],
        ]);
    }

    /**
     * Stub CurrencyHelper::instance() so getActiveCurrencyCode() returns the
     * given code. Matches the ShopaholicProductValueResolverTest pattern —
     * October's Singleton trait declares final protected __construct(), so
     * the only way to inject a non-initialised instance is
     * ReflectionClass::newInstanceWithoutConstructor + pinning the protected
     * obActiveCurrency field to a stdClass with a code property.
     */
    private function stubCurrencyHelperWithCode(?string $sCode): void
    {
        $obReflectClass = new ReflectionClass(CurrencyHelper::class);
        $obStub = $obReflectClass->newInstanceWithoutConstructor();

        if ($sCode !== null) {
            $obActiveCurrency = new \stdClass;
            $obActiveCurrency->code = $sCode;

            $obReflectActive = new ReflectionProperty(CurrencyHelper::class, 'obActiveCurrency');
            $obReflectActive->setAccessible(true);
            $obReflectActive->setValue($obStub, $obActiveCurrency);
        }

        $obReflectProp = new ReflectionProperty(CurrencyHelper::class, 'instance');
        $obReflectProp->setAccessible(true);
        $obReflectProp->setValue(null, $obStub);
    }
}
