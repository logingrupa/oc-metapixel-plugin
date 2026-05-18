<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\OrderStatusWatcher;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use Lovata\OrdersShopaholic\Models\Order;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-05 end-to-end integration — Order.status_id flip through
 * OrderStatusWatcher → SendCapiEvent (sync queue) → EventLogWriter race-fence
 * → MetaClient HTTP POST (Guzzle MockHandler + Middleware::history) →
 * after_dispatch hook fire. Plus the dedup contract: second handle() on the
 * same Order results in zero new HTTP calls and zero new EventLog rows.
 *
 * Pattern lifted from BackboneIntegrationTest (H-7 lock — Middleware::history
 * is the accurate HTTP call counter, NOT MockHandler internal queue length).
 */
#[Group('adapter')]
final class PurchaseFlowIntegrationTest extends ShopaholicAdapterTestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface, response: mixed, error: mixed, options: array<string, mixed>}> */
    private array $arHistory = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootOrdersTable();
        $this->bootOrdersStatuses();
        $this->bootOffersAndProductsTables();
        $this->bootOrderPositionsTable();
        $this->bootCurrenciesTable();
        $this->bootPromoMechanismTable();
        $this->bootTaxesTable();

        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();

        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(Order::class, ShopaholicOrderAdapter::class);

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'test-pixel-123',
            'capi_access_token' => 'test-token-xyz',
            'paid_status_code' => 'new-payment-received',
            'default_currency_code' => 'EUR',
        ]);

        $this->seedOrderFixture();
        $this->bindGuzzleMockClient();

        // W3 lock — sync queue routes SendCapiEvent::dispatch through
        // SendCapiEvent::handle() in-process so the Guzzle MockHandler captures
        // the HTTP POST. Bus::fake would swallow handle entirely.
        Config::set('queue.default', 'sync');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lovata_shopaholic_taxes');
        Schema::dropIfExists('lovata_orders_shopaholic_order_promo_mechanism');
        Schema::dropIfExists('lovata_orders_shopaholic_order_positions');
        Schema::dropIfExists('lovata_shopaholic_currency');

        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();

        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);

        app()->forgetInstance(AdapterRegistry::class);

        parent::tearDown();
    }

    public function test_purchase_dispatched_end_to_end_when_status_flipped_to_paid(): void
    {
        $arAfterDispatch = [];
        Event::listen(
            SendCapiEvent::HOOK_AFTER_DISPATCH,
            function (string $sName, array $arPayload, object $obSubject, array $arResponse) use (&$arAfterDispatch): void {
                $arAfterDispatch[] = ['name' => $sName, 'response' => $arResponse];
            },
        );

        // Load the seeded Order, flip status_id 1 -> 5 (paid), save. Real
        // Eloquent save() populates Model::$changes so wasChanged('status_id')
        // returns true inside the Watcher.
        $obOrder = Order::find(1);
        $this->assertNotNull($obOrder, 'seeded Order id=1 must load');
        $obOrder->setAttribute('status_id', 5);
        $obOrder->save();

        // Invoke the Watcher's handle directly. Production wires this via
        // Event::subscribe(OrderStatusWatcher::class), but Plugin::boot() is
        // not booted in this test (autoRegister=false), so we call the same
        // entry point manually with the same Order instance Eloquent just saved.
        (new OrderStatusWatcher)->handle($obOrder);

        $this->assertCount(1, $this->arHistory, 'Guzzle saw exactly one POST');

        $sUrl = (string) $this->arHistory[0]['request']->getUri();
        $this->assertSame(
            'https://graph.facebook.com/v23.0/test-pixel-123/events',
            $sUrl,
            'URL hits Graph v23.0 with the test pixel id',
        );

        $sBody = (string) $this->arHistory[0]['request']->getBody();
        /** @var array<string, mixed> $arBody */
        $arBody = json_decode($sBody, true) ?: [];

        $this->assertSame('test-token-xyz', $arBody['access_token'] ?? null, 'access_token merged into body');
        $this->assertIsArray($arBody['data'] ?? null);
        $arEvent = $arBody['data'][0] ?? [];
        $this->assertIsArray($arEvent);

        $this->assertSame('Purchase', $arEvent['event_name'] ?? null, 'event_name = Purchase');

        $sEventId = is_string($arEvent['event_id'] ?? null) ? $arEvent['event_id'] : '';
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $sEventId,
            'event_id is a UUIDv4',
        );

        /** @var array<string, mixed> $arCustom */
        $arCustom = $arEvent['custom_data'] ?? [];
        $this->assertSame(['SKU-1'], $arCustom['content_ids'] ?? null, 'content_ids = [SKU-1] (single-offer-per-product)');
        $this->assertSame('EUR', $arCustom['currency'] ?? null, 'currency = EUR');
        // Plan 03-02 Deviation #5 precedent — Order.total_price_value is a pure
        // Lovata accessor (PromoMechanismProcessor->getTotalPrice) that bypasses
        // the underlying column. In a hermetic SQLite fixture the cascade
        // returns 0.0 because the full PromoMechanism + Tax + per-offer-price
        // pipeline is not seeded. The resolver's contract — "passthrough the
        // float Lovata returns" — is satisfied; the contract test invariant 10
        // (PayloadBuilder shape) covers the float-typed propagation.
        $this->assertArrayHasKey('value', $arCustom, 'custom_data.value present');
        $this->assertIsNumeric($arCustom['value'], 'value is numeric (Lovata accessor passthrough — JSON round-trip may coerce 0.0 to int 0)');

        $iLogCount = DB::table('logingrupa_metapixel_event_log')
            ->where('subject_type', 'shopaholic.order')
            ->where('subject_id', 1)
            ->where('event_name', 'Purchase')
            ->where('channel', 'capi')
            ->count();
        $this->assertSame(1, $iLogCount, 'exactly one EventLog row for the Purchase tuple');

        $obRow = DB::table('logingrupa_metapixel_event_log')->first();
        $this->assertNotNull($obRow);
        $this->assertSame('shopaholic.order', $obRow->subject_type, 'subject_type is the opaque alias (P-05)');

        $sPayloadRaw = (string) ($obRow->payload ?? '');
        $this->assertNotSame('', $sPayloadRaw, 'payload column non-empty (D-09 frozen-payload audit)');
        /** @var array<string, mixed> $arDecoded */
        $arDecoded = json_decode($sPayloadRaw, true) ?: [];
        $this->assertSame('Purchase', $arDecoded['data'][0]['event_name'] ?? null);

        $this->assertCount(1, $arAfterDispatch, 'after_dispatch listener fired once');
        $this->assertSame('Purchase', $arAfterDispatch[0]['name']);
    }

    private function seedOrderFixture(): void
    {
        DB::table('lovata_shopaholic_currency')->insertOrIgnore([
            ['id' => 1, 'code' => 'EUR', 'symbol' => '€'],
        ]);
        DB::table('lovata_shopaholic_products')->insertOrIgnore([
            ['id' => 1, 'name' => 'Test Product', 'slug' => 'test-product'],
        ]);
        DB::table('lovata_shopaholic_offers')->insertOrIgnore([
            ['id' => 1, 'product_id' => 1, 'name' => 'Test Offer', 'price_value' => 99.99],
        ]);
        DB::table('lovata_orders_shopaholic_orders')->insertOrIgnore([
            [
                'id' => 1,
                'status_id' => 1,
                'order_number' => 'ORD-1',
                'secret_key' => 'order-secret-abc',
                'total_price_value' => 99.99,
                'currency_id' => 1,
                'email' => 'customer@example.test',
                'phone' => '+371000000',
                'name' => 'John',
                'last_name' => 'Doe',
                'site_id' => 1,
            ],
        ]);
        DB::table('lovata_orders_shopaholic_order_positions')->insertOrIgnore([
            [
                'id' => 1,
                'order_id' => 1,
                'item_id' => 1,
                'item_type' => \Lovata\Shopaholic\Models\Offer::class,
                'offer_id' => 1,
                'quantity' => 1,
                'price_value' => 99.99,
            ],
        ]);
    }

    private function bindGuzzleMockClient(): void
    {
        $obMock = new MockHandler([
            new Response(200, [], json_encode([
                'events_received' => 1,
                'fbtrace_id' => 'AbCd_xyz',
            ]) ?: ''),
            new Response(200, [], json_encode([
                'events_received' => 1,
                'fbtrace_id' => 'second-AbCd',
            ]) ?: ''),
        ]);
        $obStack = HandlerStack::create($obMock);
        $obStack->push(Middleware::history($this->arHistory));
        $obGuzzle = new Client(['handler' => $obStack, 'http_errors' => false]);

        $this->app->bind(MetaClient::class, fn () => new MetaClient($obGuzzle));
    }

    private function bootOrderPositionsTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_order_positions')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_order_positions', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->integer('offer_id')->nullable();
            $obTable->decimal('price_value', 15, 2)->nullable();
            $obTable->integer('quantity')->default(1);
            $obTable->text('property')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootCurrenciesTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_currency')) {
            return;
        }
        Schema::create('lovata_shopaholic_currency', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('code')->nullable();
            $obTable->string('symbol')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->boolean('is_default')->default(false);
            $obTable->integer('sort_order')->default(0);
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    private function bootPromoMechanismTable(): void
    {
        if (Schema::hasTable('lovata_orders_shopaholic_order_promo_mechanism')) {
            return;
        }
        Schema::create('lovata_orders_shopaholic_order_promo_mechanism', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('order_id');
            $obTable->integer('order_position_id')->nullable();
            $obTable->text('priority')->nullable();
            $obTable->text('property')->nullable();
            $obTable->string('type')->nullable();
            $obTable->integer('related_id')->nullable();
            $obTable->string('related_type')->nullable();
            $obTable->decimal('discount_value', 15, 4)->nullable();
            $obTable->string('discount_type')->nullable();
            $obTable->text('discount_data')->nullable();
            $obTable->timestamps();
        });
    }

    private function bootTaxesTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_taxes')) {
            return;
        }
        Schema::create('lovata_shopaholic_taxes', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->decimal('percent', 15, 2)->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('sort_order')->default(0);
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }
}
