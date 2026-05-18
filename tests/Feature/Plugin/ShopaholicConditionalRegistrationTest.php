<?php

namespace Logingrupa\Metapixel\Tests\Feature\Plugin;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
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
}
