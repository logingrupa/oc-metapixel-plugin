<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * SC1 round-trip smoke — FakeAdapter + PayloadBuilder produce the documented
 * envelope shape. Complements FakeAdapterContractTest (which covers the 10
 * adapter-shape invariants) by asserting the full PayloadBuilder envelope.
 */
final class ContractTestCaseSmokeTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_fake_adapter_round_trips_through_payload_builder_to_documented_envelope(): void
    {
        $obAdapter = (new FakeAdapter)
            ->withSubjectType('shopaholic.order')
            ->withSubjectId(42)
            ->withUserData(['em' => 'test@example.test'])
            ->withSiteId(1);

        $obResolver = new FakeValueResolver(
            arContentIds: ['SKU-42'],
            fValue: 99.99,
            sCurrency: 'EUR',
            arContents: [['id' => 'SKU-42', 'quantity' => 1, 'item_price' => 99.99]],
            iNumItems: 1,
        );

        $obBuilder = new PayloadBuilder(new UserDataHasher);
        $arEnvelope = $obBuilder->buildEventPayload(
            'Purchase',
            $obAdapter,
            new \stdClass,
            $obResolver,
            'uuid-1',
            1700000000,
            [],
        );

        $arRecord = $arEnvelope['data'][0];
        $this->assertSame('uuid-1', $arRecord['event_id']);
        $this->assertSame(1700000000, $arRecord['event_time']);
        $this->assertSame('Purchase', $arRecord['event_name']);
        $this->assertSame('website', $arRecord['action_source']);
        $this->assertSame(hash('sha256', 'test@example.test'), $arRecord['user_data']['em']);
        $this->assertSame('EUR', $arRecord['custom_data']['currency']);
        $this->assertSame(99.99, $arRecord['custom_data']['value']);
        $this->assertSame(['SKU-42'], $arRecord['custom_data']['content_ids']);
        $this->assertSame('product', $arRecord['custom_data']['content_type']);
    }

    public function test_fake_adapter_registry_round_trip(): void
    {
        /** @var AdapterRegistry $obRegistry */
        $obRegistry = app(AdapterRegistry::class);
        $obRegistry->register(\stdClass::class, FakeAdapter::class);

        $obResolved = $obRegistry->resolveFor(new \stdClass);
        $this->assertInstanceOf(FakeAdapter::class, $obResolved);
    }
}
