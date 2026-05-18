<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('adapter')]
final class AdapterRegistryFlushTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    /**
     * Per-test reset idiom — App::forgetInstance drops the resolved singleton,
     * the next make() returns a fresh empty registry. Ensures Phase 2 tests
     * never leak adapter registrations across test boundaries.
     */
    public function test_app_forget_instance_re_binds_fresh_singleton(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obRegistry->register(stdClass::class, FakeAdapter::class);
        $this->assertCount(1, $obRegistry->all());

        $this->app->forgetInstance(AdapterRegistry::class);
        $obFresh = $this->app->make(AdapterRegistry::class);

        $this->assertNotSame($obRegistry, $obFresh);
        $this->assertCount(0, $obFresh->all());
    }
}
