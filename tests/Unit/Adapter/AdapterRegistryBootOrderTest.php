<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * P-02 boot-order race prevention — two unrelated subject classes resolve
 * to their respective adapters regardless of registration order.
 */
class BootOrderFixtureSubjectA {}
class BootOrderFixtureSubjectB {}

final class AdapterRegistryBootOrderTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    /**
     * The foreach order in is_a-walk is map-insertion order, but for unrelated
     * subject classes the resolution outcome MUST be invariant across boot
     * orderings. Sibling-class collision (shared ancestor) is a separate
     * concern documented on AdapterRegistry::resolveFor.
     */
    public function test_resolution_outcome_is_invariant_across_registration_order(): void
    {
        $obRegistryAB = new AdapterRegistry;
        $obRegistryAB->register(BootOrderFixtureSubjectA::class, FakeAdapter::class);
        $obRegistryAB->register(BootOrderFixtureSubjectB::class, FakeStubAdapter::class);

        $obRegistryBA = new AdapterRegistry;
        $obRegistryBA->register(BootOrderFixtureSubjectB::class, FakeStubAdapter::class);
        $obRegistryBA->register(BootOrderFixtureSubjectA::class, FakeAdapter::class);

        $this->assertInstanceOf(
            FakeAdapter::class,
            $obRegistryAB->resolveFor(new BootOrderFixtureSubjectA),
        );
        $this->assertInstanceOf(
            FakeStubAdapter::class,
            $obRegistryAB->resolveFor(new BootOrderFixtureSubjectB),
        );

        $this->assertInstanceOf(
            FakeAdapter::class,
            $obRegistryBA->resolveFor(new BootOrderFixtureSubjectA),
        );
        $this->assertInstanceOf(
            FakeStubAdapter::class,
            $obRegistryBA->resolveFor(new BootOrderFixtureSubjectB),
        );
    }
}
