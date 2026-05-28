<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Doubles {

    use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
    use Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax;
    use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
    use Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver;

    /**
     * Test-local EventSubjectAdapter that returns alias 'fake.foo'. Lives in a
     * sub-namespace under tests/ — never autoloads as production code.
     */
    final class FakeFooSubjectAdapter implements EventSubjectAdapter
    {
        public function getSubjectType(object $obSubject): string
        {
            return 'fake.foo';
        }

        public function getSubjectId(object $obSubject): int
        {
            return 1;
        }

        public function getSiteId(object $obSubject): ?int
        {
            return null;
        }

        public function getSecretKey(object $obSubject): ?string
        {
            return null;
        }

        public function getValueResolver(object $obSubject): ValueResolver
        {
            return new FakeValueResolver;
        }

        /** @return array<string, ?string> */
        public function getUserData(object $obSubject): array
        {
            return [
                'em' => null, 'ph' => null, 'fn' => null, 'ln' => null,
                'ct' => null, 'st' => null, 'zp' => null, 'country' => null,
                'external_id' => null, 'fbp' => null, 'fbc' => null,
                'client_ip_address' => null, 'client_user_agent' => null,
            ];
        }

        /** @return array<string, list<string>> */
        public function getSupportedEvents(): array
        {
            return ['Purchase' => ['capi', 'pixel']];
        }
    }

    /**
     * Test-local SupportsHybridAjax adapter that returns alias 'fake.bar' and
     * a non-null stdClass from loadSubject. Exercises the subinterface path
     * through the alias index.
     */
    final class FakeBarHybridAdapter implements SupportsHybridAjax
    {
        public function getSubjectType(object $obSubject): string
        {
            return 'fake.bar';
        }

        public function getSubjectId(object $obSubject): int
        {
            return 2;
        }

        public function getSiteId(object $obSubject): ?int
        {
            return null;
        }

        public function getSecretKey(object $obSubject): ?string
        {
            return null;
        }

        public function getValueResolver(object $obSubject): ValueResolver
        {
            return new FakeValueResolver;
        }

        /** @return array<string, ?string> */
        public function getUserData(object $obSubject): array
        {
            return [
                'em' => null, 'ph' => null, 'fn' => null, 'ln' => null,
                'ct' => null, 'st' => null, 'zp' => null, 'country' => null,
                'external_id' => null, 'fbp' => null, 'fbc' => null,
                'client_ip_address' => null, 'client_user_agent' => null,
            ];
        }

        /** @return array<string, list<string>> */
        public function getSupportedEvents(): array
        {
            return ['ViewContent' => ['capi', 'pixel']];
        }

        public function loadSubject(int $iSubjectId, array $arContext): ?object
        {
            return new \stdClass;
        }
    }
}

namespace Logingrupa\Metapixel\Tests\Unit\Adapter {

    use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
    use Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax;
    use Logingrupa\Metapixel\Classes\Exception\UnknownSubjectTypeException;
    use Logingrupa\Metapixel\Tests\MetapixelTestCase;
    use Logingrupa\Metapixel\Tests\Unit\Adapter\Doubles\FakeBarHybridAdapter;
    use Logingrupa\Metapixel\Tests\Unit\Adapter\Doubles\FakeFooSubjectAdapter;
    use PHPUnit\Framework\Attributes\Group;

    #[Group('adapter')]
    final class AdapterRegistryResolveByAliasTest extends MetapixelTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            // Fresh registry per test — alias-index state must not leak.
            $this->app->singleton(AdapterRegistry::class, fn () => new AdapterRegistry);
        }

        public function test_resolveByAlias_returns_registered_adapter_class_for_known_alias(): void
        {
            $obRegistry = $this->app->make(AdapterRegistry::class);
            $obRegistry->register(\stdClass::class, FakeFooSubjectAdapter::class);

            $sResolved = $obRegistry->resolveByAlias('fake.foo');

            $this->assertSame(FakeFooSubjectAdapter::class, $sResolved);
        }

        public function test_resolveByAlias_throws_UnknownSubjectTypeException_for_unknown_alias(): void
        {
            $obRegistry = $this->app->make(AdapterRegistry::class);

            $this->expectException(UnknownSubjectTypeException::class);
            $this->expectExceptionMessage("No adapter registered for subject_type alias 'mall.product'");

            $obRegistry->resolveByAlias('mall.product');
        }

        public function test_alias_index_persists_across_multiple_register_calls(): void
        {
            $obRegistry = $this->app->make(AdapterRegistry::class);
            $obRegistry->register(\stdClass::class, FakeFooSubjectAdapter::class);
            $obRegistry->register(\ArrayObject::class, FakeBarHybridAdapter::class);

            $this->assertSame(FakeFooSubjectAdapter::class, $obRegistry->resolveByAlias('fake.foo'));
            $this->assertSame(FakeBarHybridAdapter::class, $obRegistry->resolveByAlias('fake.bar'));
        }

        public function test_alias_index_overwrites_when_same_alias_re_registered(): void
        {
            $obRegistry = $this->app->make(AdapterRegistry::class);
            $obRegistry->register(\stdClass::class, FakeFooSubjectAdapter::class);
            $obRegistry->register(\stdClass::class, FakeFooSubjectAdapter::class);

            $this->assertSame(FakeFooSubjectAdapter::class, $obRegistry->resolveByAlias('fake.foo'));
        }

        public function test_SupportsHybridAjax_adapter_resolves_through_alias_index_too(): void
        {
            $obRegistry = $this->app->make(AdapterRegistry::class);
            $obRegistry->register(\ArrayObject::class, FakeBarHybridAdapter::class);

            $sResolved = $obRegistry->resolveByAlias('fake.bar');
            $this->assertSame(FakeBarHybridAdapter::class, $sResolved);

            $obAdapter = $this->app->make(FakeBarHybridAdapter::class);
            $this->assertInstanceOf(SupportsHybridAjax::class, $obAdapter);
        }

        public function test_register_throws_when_adapter_does_not_implement_EventSubjectAdapter(): void
        {
            $obRegistry = $this->app->make(AdapterRegistry::class);

            $this->expectException(\InvalidArgumentException::class);

            $obRegistry->register(\stdClass::class, \stdClass::class);
        }
    }
}
