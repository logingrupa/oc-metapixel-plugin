<?php

namespace Logingrupa\Metapixel\Tests\Feature\Plugin;

use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Exception\UnknownSubjectTypeException;
use Logingrupa\Metapixel\Plugin;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Lovata\OrdersShopaholic\Models\Order;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use System\Classes\PluginManager;

/**
 * SHOP-04 — Plugin::boot conditionally registers ShopaholicOrderAdapter only
 * when Lovata.OrdersShopaholic is installed + enabled. The conditional gate
 * routes through isShopaholicEnabled() which resolves PluginManager via
 * App::make — tests swap a Mockery double via $this->app->instance().
 *
 * This avoids Mockery::mock('overload:') and its runInSeparateProcess
 * requirement (the rest of the plugin suite shares a single process).
 */
#[Group('adapter')]
final class ShopaholicConditionalRegistrationTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(AdapterRegistry::class, new AdapterRegistry);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        app()->forgetInstance(AdapterRegistry::class);
        app()->forgetInstance(PluginManager::class);
        parent::tearDown();
    }

    public function test_adapter_registered_when_plugin_manager_reports_exists_true(): void
    {
        $obFakePM = Mockery::mock(PluginManager::class);
        $obFakePM->shouldReceive('exists')->with('Lovata.OrdersShopaholic')->andReturn(true);
        $this->app->instance(PluginManager::class, $obFakePM);

        (new Plugin($this->app))->boot();

        /** @var AdapterRegistry $obRegistry */
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obResolved = $obRegistry->resolveFor(new Order);

        $this->assertInstanceOf(ShopaholicOrderAdapter::class, $obResolved);
    }

    public function test_adapter_not_registered_when_plugin_manager_reports_exists_false(): void
    {
        $obFakePM = Mockery::mock(PluginManager::class);
        $obFakePM->shouldReceive('exists')->with('Lovata.OrdersShopaholic')->andReturn(false);
        $this->app->instance(PluginManager::class, $obFakePM);

        (new Plugin($this->app))->boot();

        /** @var AdapterRegistry $obRegistry */
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obResolved = $obRegistry->resolveFor(new Order);

        $this->assertNull($obResolved);
    }

    public function test_product_page_watcher_subscribed_when_lovata_orders_shopaholic_present(): void
    {
        $obFakePM = Mockery::mock(PluginManager::class);
        $obFakePM->shouldReceive('exists')->with('Lovata.OrdersShopaholic')->andReturn(true);
        $this->app->instance(PluginManager::class, $obFakePM);

        (new Plugin($this->app))->boot();

        $this->assertTrue(
            Event::hasListeners('shopaholic.product.open'),
            'ProductPageWatcher MUST subscribe to shopaholic.product.open when OrdersShopaholic is enabled',
        );

        /** @var AdapterRegistry $obRegistry */
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $this->assertSame(
            ShopaholicProductAdapter::class,
            $obRegistry->resolveByAlias('shopaholic.product'),
            'ShopaholicProductAdapter MUST register under the shopaholic.product alias',
        );
    }

    public function test_product_page_watcher_not_subscribed_when_lovata_orders_shopaholic_absent(): void
    {
        $obFakePM = Mockery::mock(PluginManager::class);
        $obFakePM->shouldReceive('exists')->with('Lovata.OrdersShopaholic')->andReturn(false);
        $this->app->instance(PluginManager::class, $obFakePM);

        (new Plugin($this->app))->boot();

        $this->assertFalse(
            Event::hasListeners('shopaholic.product.open'),
            'ProductPageWatcher MUST NOT subscribe to shopaholic.product.open when OrdersShopaholic is absent',
        );

        /** @var AdapterRegistry $obRegistry */
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $this->expectException(UnknownSubjectTypeException::class);
        $obRegistry->resolveByAlias('shopaholic.product');
    }
}
