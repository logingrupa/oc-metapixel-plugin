<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('adapter')]
final class AdapterRegistrySingletonBindingTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_singleton_binding_returns_same_instance(): void
    {
        $obFirst = $this->app->make(AdapterRegistry::class);
        $obSecond = $this->app->make(AdapterRegistry::class);

        $this->assertSame($obFirst, $obSecond);
    }

    public function test_app_instance_swaps_fresh_registry_for_test_isolation(): void
    {
        $obFresh = new AdapterRegistry;
        $this->app->instance(AdapterRegistry::class, $obFresh);

        $obResolved = $this->app->make(AdapterRegistry::class);

        $this->assertSame($obFresh, $obResolved);
    }
}
