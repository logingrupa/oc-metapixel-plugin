<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\AddToCartPixelResult;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Models\Cart;
use Lovata\Shopaholic\Models\Offer;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * D-07 — CartPositionWatcher::resolveBrowserPixel reads the server-generated
 * capi AddToCart event_id + custom_data for the current-session cart position,
 * writes the channel='pixel' twin row (idempotent race-fence), dispatches NO
 * second SendCapiEvent, and null-returns on every fail-safe branch (disabled
 * guard, non-positive offer_id, no cart, no matching position, no capi row).
 */
#[Group('adapter')]
final class CartPositionWatcherBrowserPixelTest extends ShopaholicAdapterTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    private const KNOWN_EVENT_ID = '11111111-2222-4333-8444-555566667777';

    private const OFFER_ID = 100;

    private const POSITION_ID = 500;

    private const CART_ID = 10;

    /** @var array<string, mixed> */
    private const CUSTOM_DATA = [
        'content_ids' => ['SKU-1-100'],
        'contents' => [['id' => 'SKU-1-100', 'quantity' => 2, 'item_price' => 9.99]],
        'num_items' => 2,
        'value' => 19.98,
        'currency' => 'EUR',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCartPositionTable();
        $this->bootCartTable();
        $this->bootOffersAndProductsTables();
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();

        PluginGuard::reset();
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
        ]);
        Settings::clearInternalCache();

        Bus::fake();
    }

    protected function tearDown(): void
    {
        CartProcessor::forgetInstance();
        PluginGuard::reset();
        Schema::dropIfExists(self::TABLE);
        parent::tearDown();
    }

    public function test_returns_capi_event_id_and_custom_data_on_happy_path(): void
    {
        $this->seedPosition();
        $this->seedCapiRow();
        $this->stubCartProcessor(self::CART_ID);

        $obResult = (new CartPositionWatcher)->resolveBrowserPixel(self::OFFER_ID);

        $this->assertInstanceOf(AddToCartPixelResult::class, $obResult);
        $this->assertSame(self::KNOWN_EVENT_ID, $obResult->sEventId);
        $this->assertSame(self::CUSTOM_DATA, $obResult->arCustomData);
    }

    public function test_writes_single_pixel_twin_row_idempotent_under_repeat(): void
    {
        $this->seedPosition();
        $this->seedCapiRow();
        $this->stubCartProcessor(self::CART_ID);

        $obWatcher = new CartPositionWatcher;
        $obWatcher->resolveBrowserPixel(self::OFFER_ID);
        $obWatcher->resolveBrowserPixel(self::OFFER_ID);

        $iPixelRows = DB::table(self::TABLE)
            ->where('subject_type', 'shopaholic.cart_position')
            ->where('subject_id', self::POSITION_ID)
            ->where('event_name', 'AddToCart')
            ->where('channel', 'pixel')
            ->count();
        $this->assertSame(1, $iPixelRows, 'exactly one idempotent pixel twin row');

        $obPixelRow = DB::table(self::TABLE)
            ->where('channel', 'pixel')
            ->where('subject_id', self::POSITION_ID)
            ->first();
        $this->assertNotNull($obPixelRow);
        $this->assertSame(self::KNOWN_EVENT_ID, $obPixelRow->event_id);
        $this->assertSame(1, (int) $obPixelRow->site_id);
    }

    public function test_dispatches_no_send_capi_event(): void
    {
        $this->seedPosition();
        $this->seedCapiRow();
        $this->stubCartProcessor(self::CART_ID);

        (new CartPositionWatcher)->resolveBrowserPixel(self::OFFER_ID);

        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    public function test_returns_null_when_plugin_disabled(): void
    {
        Settings::set(['pixel_id' => '']);
        Settings::clearInternalCache();
        PluginGuard::reset();
        $this->seedPosition();
        $this->seedCapiRow();
        $this->stubCartProcessor(self::CART_ID);

        $this->assertNull((new CartPositionWatcher)->resolveBrowserPixel(self::OFFER_ID));
    }

    public function test_returns_null_when_offer_id_non_positive(): void
    {
        $this->stubCartProcessor(self::CART_ID);

        $this->assertNull((new CartPositionWatcher)->resolveBrowserPixel(0));
        $this->assertNull((new CartPositionWatcher)->resolveBrowserPixel(-5));
    }

    public function test_returns_null_when_cart_has_no_id(): void
    {
        // getCartObject() always returns a Cart post-init (Lovata creates one),
        // so the reachable no-cart fail-safe is a session cart with no id yet.
        $this->seedPosition();
        $this->seedCapiRow();
        $this->stubCartProcessor(null);

        $this->assertNull((new CartPositionWatcher)->resolveBrowserPixel(self::OFFER_ID));
    }

    public function test_returns_null_when_no_matching_position(): void
    {
        // Cart present but no position for this offer.
        $this->seedCapiRow();
        $this->stubCartProcessor(self::CART_ID);

        $this->assertNull((new CartPositionWatcher)->resolveBrowserPixel(self::OFFER_ID));
    }

    public function test_returns_null_when_no_capi_row(): void
    {
        $this->seedPosition();
        $this->stubCartProcessor(self::CART_ID);

        $this->assertNull((new CartPositionWatcher)->resolveBrowserPixel(self::OFFER_ID));
        $this->assertSame(
            0,
            DB::table(self::TABLE)->where('channel', 'pixel')->count(),
            'no pixel twin written when no capi row exists',
        );
    }

    private function seedPosition(): void
    {
        DB::table('lovata_orders_shopaholic_carts')->insertOrIgnore([
            ['id' => self::CART_ID, 'site_id' => 1],
        ]);
        DB::table('lovata_orders_shopaholic_cart_positions')->insertOrIgnore([
            [
                'id' => self::POSITION_ID,
                'cart_id' => self::CART_ID,
                'item_id' => self::OFFER_ID,
                'item_type' => Offer::class,
                'quantity' => 2,
            ],
        ]);
    }

    private function seedCapiRow(): void
    {
        DB::table(self::TABLE)->insert([
            'event_id' => self::KNOWN_EVENT_ID,
            'event_name' => 'AddToCart',
            'channel' => 'capi',
            'subject_type' => 'shopaholic.cart_position',
            'subject_id' => self::POSITION_ID,
            'secret_key' => null,
            'site_id' => 1,
            'event_time' => time(),
            'payload' => (string) json_encode([
                'data' => [[
                    'event_name' => 'AddToCart',
                    'event_id' => self::KNOWN_EVENT_ID,
                    'custom_data' => self::CUSTOM_DATA,
                ]],
            ]),
        ]);
    }

    /**
     * Pin the CartProcessor singleton to return a Cart with the given id. A
     * null $iCartId yields an id-less Cart (the reachable "no established cart"
     * fail-safe — getCartObject() itself never returns null).
     */
    private function stubCartProcessor(?int $iCartId): void
    {
        $obReflectClass = new \ReflectionClass(CartProcessor::class);
        $obProcessor = $obReflectClass->newInstanceWithoutConstructor();

        $obCart = new Cart;
        $obCart->setAttribute('site_id', 1);
        if ($iCartId !== null) {
            $obCart->setAttribute('id', $iCartId);
        }

        $obCartProp = new ReflectionProperty(CartProcessor::class, 'obCart');
        $obCartProp->setAccessible(true);
        $obCartProp->setValue($obProcessor, $obCart);

        $obInstanceProp = new ReflectionProperty(CartProcessor::class, 'instance');
        $obInstanceProp->setAccessible(true);
        $obInstanceProp->setValue(null, $obProcessor);
    }
}
