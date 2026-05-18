<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Hierarchy-walk fixtures local to this test file. Declared at global scope
 * so is_a() against the registered parent class resolves the child instance.
 */
class AdapterRegistryFixtureParent {}
class AdapterRegistryFixtureChild extends AdapterRegistryFixtureParent {}

#[Group('adapter')]
final class AdapterRegistryTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // H-8: bind AdapterRegistry directly via the container — NOT via
        // (new \Logingrupa\Metapixel\Plugin)->register() (PluginBase requires
        // container injection at construction).
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_register_and_resolve_for_returns_adapter_instance(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obRegistry->register(stdClass::class, FakeAdapter::class);

        $obAdapter = $obRegistry->resolveFor(new stdClass);

        $this->assertInstanceOf(FakeAdapter::class, $obAdapter);
        $this->assertInstanceOf(EventSubjectAdapter::class, $obAdapter);
    }

    public function test_resolve_for_walks_class_hierarchy_via_is_a(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obRegistry->register(AdapterRegistryFixtureParent::class, FakeAdapter::class);

        $obAdapter = $obRegistry->resolveFor(new AdapterRegistryFixtureChild);

        $this->assertInstanceOf(FakeAdapter::class, $obAdapter);
    }

    public function test_resolve_for_returns_null_when_subject_not_registered(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);

        $this->assertNull($obRegistry->resolveFor(new stdClass));
    }

    public function test_all_returns_list_of_registered_adapter_class_names(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obRegistry->register(stdClass::class, FakeAdapter::class);
        $obRegistry->register(AdapterRegistryFixtureChild::class, FakeStubAdapter::class);

        $arAll = $obRegistry->all();

        $this->assertCount(2, $arAll);
        $this->assertContains(FakeAdapter::class, $arAll);
        $this->assertContains(FakeStubAdapter::class, $arAll);
    }

    public function test_register_same_pair_twice_is_idempotent(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);
        $obRegistry->register(stdClass::class, FakeAdapter::class);
        $obRegistry->register(stdClass::class, FakeAdapter::class);

        $arAll = $obRegistry->all();

        $this->assertCount(1, $arAll);
        $this->assertSame([FakeAdapter::class], $arAll);
    }

    public function test_resolve_by_class_returns_adapter_instance_by_fqn(): void
    {
        $obRegistry = $this->app->make(AdapterRegistry::class);

        $obAdapter = $obRegistry->resolveByClass(FakeAdapter::class);

        $this->assertInstanceOf(FakeAdapter::class, $obAdapter);
        $this->assertInstanceOf(EventSubjectAdapter::class, $obAdapter);
    }
}
