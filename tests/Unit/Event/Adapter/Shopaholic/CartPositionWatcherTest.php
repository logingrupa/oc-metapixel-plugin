<?php

namespace Logingrupa\Metapixel\Tests\Unit\Event\Adapter\Shopaholic;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-03 (carry-forward) — CartPositionWatcher dispatch logic. Exercises the
 * handlers directly (not via eloquent.* firing) and asserts SendCapiEvent::dispatch
 * is called on the AddToCart path; skipped when the EventLog row already exists
 * (dedup pre-check); skipped when MorphTo $item is null (Pitfall 1); never
 * rethrows on exception (Tiger-Style log+return).
 */
#[Group('adapter')]
final class CartPositionWatcherTest extends ShopaholicAdapterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCartPositionTable();
        $this->bootCartTable();
        $this->bootOffersAndProductsTables();
        $this->bootEventLogTable();
        $this->bootSupportTables();
        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(CartPosition::class, ShopaholicCartPositionAdapter::class);
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
            'default_currency_code' => 'EUR',
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('logingrupa_metapixel_event_log');
        Schema::dropIfExists('lovata_shopaholic_prices');
        Schema::dropIfExists('lovata_shopaholic_currency');
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    private function bootSupportTables(): void
    {
        if (! Schema::hasTable('lovata_shopaholic_prices')) {
            Schema::create('lovata_shopaholic_prices', function ($obTable): void {
                $obTable->increments('id');
                $obTable->integer('item_id');
                $obTable->string('item_type');
                $obTable->decimal('price', 15, 2)->nullable();
                $obTable->decimal('old_price', 15, 2)->nullable();
                $obTable->integer('price_type_id')->nullable();
                $obTable->timestamps();
            });
        }
        if (! Schema::hasTable('lovata_shopaholic_currency')) {
            Schema::create('lovata_shopaholic_currency', function ($obTable): void {
                $obTable->increments('id');
                $obTable->boolean('active')->default(false);
                $obTable->boolean('is_default')->default(false);
                $obTable->string('name')->nullable();
                $obTable->string('code')->nullable();
                $obTable->string('symbol')->nullable();
                $obTable->decimal('rate', 15, 4)->nullable();
                $obTable->integer('sort_order')->nullable();
                $obTable->softDeletes();
                $obTable->timestamps();
            });
            DB::table('lovata_shopaholic_currency')->insertOrIgnore([
                ['id' => 1, 'active' => 1, 'is_default' => 1, 'code' => 'EUR', 'symbol' => '€', 'rate' => 1, 'sort_order' => 1, 'name' => 'Euro'],
            ]);
        }
    }

    public function test_handle_created_dispatches_add_to_cart(): void
    {
        Bus::fake();
        $obPosition = $this->makePositionWithOffer();

        (new CartPositionWatcher)->handleCreated($obPosition);

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'AddToCart'
                && $obJob->sAdapterClass === ShopaholicCartPositionAdapter::class;
        });
    }

    public function test_dispatch_reserves_pixel_row_in_request_with_matching_event_id(): void
    {
        Bus::fake();
        $obPosition = $this->makePositionWithOffer();

        (new CartPositionWatcher)->handleCreated($obPosition);

        // The browser-pixel reservation is written synchronously in-request so
        // resolveBrowserPixel never races the queue worker on async drivers.
        $obPixelRow = DB::table('logingrupa_metapixel_event_log')
            ->where('subject_type', 'shopaholic.cart_position')
            ->where('subject_id', $obPosition->id)
            ->where('event_name', 'AddToCart')
            ->where('channel', 'pixel')
            ->first();
        $this->assertNotNull($obPixelRow, 'pixel reservation row written at dispatch time');

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob) use ($obPixelRow): bool {
            return ($obJob->arPayload['data'][0]['event_id'] ?? null) === $obPixelRow->event_id;
        });
    }

    public function test_handle_updated_dispatches_when_event_log_row_absent(): void
    {
        Bus::fake();
        $obPosition = $this->makePositionWithOffer();

        (new CartPositionWatcher)->handleUpdated($obPosition);

        Bus::assertDispatched(SendCapiEvent::class);
    }

    public function test_handle_updated_skips_when_event_log_row_present(): void
    {
        Bus::fake();
        $obPosition = $this->makePositionWithOffer();
        // Seed the pixel reservation row matching the dedup tuple — handler
        // should short-circuit on the DB::table exists() check (the pixel row
        // is written in-request by dispatchAddToCart, so it is the reliable
        // "already dispatched" marker independent of queue-worker latency).
        DB::table('logingrupa_metapixel_event_log')->insert([
            'event_id' => 'preexisting-uuid',
            'event_name' => 'AddToCart',
            'channel' => 'pixel',
            'subject_type' => 'shopaholic.cart_position',
            'subject_id' => $obPosition->id,
            'site_id' => 1,
            'event_time' => time(),
        ]);

        (new CartPositionWatcher)->handleUpdated($obPosition);

        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    public function test_handle_created_skips_when_item_morphto_null(): void
    {
        Bus::fake();
        Log::spy();
        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 99);
        $obPosition->setAttribute('quantity', 1);
        $obPosition->setRelation('item', null);
        // Cart relation present so getSiteId doesn't itself throw.
        $obCart = new \Lovata\OrdersShopaholic\Models\Cart;
        $obCart->setAttribute('id', 1);
        $obCart->setAttribute('site_id', 1);
        $obPosition->setRelation('cart', $obCart);

        (new CartPositionWatcher)->handleCreated($obPosition);

        Bus::assertNotDispatched(SendCapiEvent::class);
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $sMessage): bool {
                return str_contains($sMessage, 'item MorphTo not an Offer');
            })
            ->atLeast()->once();
    }

    public function test_handle_created_catches_exception_logs_warning_no_rethrow(): void
    {
        Bus::fake();
        Log::spy();
        // Bind a fake registry that throws during dispatch resolution via the
        // BindingResolutionException path inside SendCapiEvent::handle is too
        // deep — easier path: construct a CartPosition whose getRelationValue
        // throws by overriding it via a subclass.
        $obPosition = new class extends CartPosition
        {
            public function getRelationValue($key)
            {
                throw new \RuntimeException('forced failure for Tiger-Style catch test');
            }
        };
        $obPosition->setAttribute('id', 1);
        $obPosition->setAttribute('quantity', 1);

        (new CartPositionWatcher)->handleCreated($obPosition);

        Bus::assertNotDispatched(SendCapiEvent::class);
        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $sMessage): bool {
                return str_contains($sMessage, 'CartPositionWatcher created handler failed');
            })
            ->atLeast()->once();
    }

    private function makePositionWithOffer(): CartPosition
    {
        $obProduct = new Product;
        $obProduct->setAttribute('id', 1);
        $obProduct->setAttribute('name', 'Test Product');
        $obProduct->exists = true;

        $obOffer = new Offer;
        $obOffer->setAttribute('id', 1);
        $obOffer->setAttribute('product_id', 1);
        $obOffer->setRelation('product', $obProduct);

        $obCart = new \Lovata\OrdersShopaholic\Models\Cart;
        $obCart->setAttribute('id', 1);
        $obCart->setAttribute('site_id', 1);

        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setAttribute('cart_id', 1);
        $obPosition->setAttribute('item_id', 1);
        $obPosition->setAttribute('item_type', Offer::class);
        $obPosition->setAttribute('quantity', 2);
        $obPosition->setRelation('item', $obOffer);
        $obPosition->setRelation('cart', $obCart);

        return $obPosition;
    }

    private function bootEventLogTable(): void
    {
        if (Schema::hasTable('logingrupa_metapixel_event_log')) {
            return;
        }
        Schema::create('logingrupa_metapixel_event_log', function ($obTable): void {
            $obTable->bigIncrements('id');
            $obTable->string('event_id');
            $obTable->string('event_name');
            $obTable->string('channel');
            $obTable->string('subject_type');
            $obTable->integer('subject_id');
            $obTable->string('secret_key')->nullable();
            $obTable->integer('site_id')->nullable();
            $obTable->integer('event_time');
            $obTable->longText('payload')->nullable();
            $obTable->timestamp('fired_at')->nullable();
            $obTable->timestamps();
            $obTable->unique(['subject_type', 'subject_id', 'event_name', 'channel', 'site_id'], 'metapixel_event_log_dedup_unique');
        });
    }
}
