<?php

use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\Doubles\SpyMetaClient;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;

final class BeforeDispatchPayloadMutationTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        app(AdapterRegistry::class)->register(stdClass::class, FakeStubAdapter::class);
        $this->app->instance(MetaClient::class, new SpyMetaClient);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        app()->forgetInstance(MetaClient::class);
        parent::tearDown();
    }

    public function test_listener_mutation_of_custom_data_propagates_to_outgoing_payload(): void
    {
        Event::listen(
            SendCapiEvent::HOOK_BEFORE_DISPATCH,
            function (string $sEventName, array &$arPayload, object $obSubject): void {
                $arPayload['data'][0]['custom_data']['campaign_tier'] = 'gold';
            },
        );

        $obSpyClient = new SpyMetaClient;
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new stdClass, FakeStubAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpyClient);

        $this->assertSame(1, $obSpyClient->iCallCount);
        $this->assertSame(
            'gold',
            $obSpyClient->arLastPayload['data'][0]['custom_data']['campaign_tier'] ?? null,
            'custom_data mutation propagates to outgoing payload',
        );
    }

    public function test_listener_mutation_of_event_id_is_reverted_to_snapshot(): void
    {
        Event::listen(
            SendCapiEvent::HOOK_BEFORE_DISPATCH,
            function (string $sEventName, array &$arPayload, object $obSubject): void {
                $arPayload['data'][0]['event_id'] = 'malicious-replacement';
                $arPayload['data'][0]['event_time'] = 9999999999;
            },
        );

        $obSpyClient = new SpyMetaClient;
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new stdClass, FakeStubAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obSpyClient);

        $this->assertSame(1, $obSpyClient->iCallCount);
        $this->assertSame(
            'uuid-1',
            $obSpyClient->arLastPayload['data'][0]['event_id'] ?? null,
            'event_id snapshot restored — dedup contract anchored',
        );
        $this->assertSame(
            1700000000,
            $obSpyClient->arLastPayload['data'][0]['event_time'] ?? null,
            'event_time snapshot restored',
        );
    }

    /** @return array<string, mixed> */
    private function makePayload(): array
    {
        return ['data' => [[
            'event_id' => 'uuid-1',
            'event_time' => 1700000000,
            'event_name' => 'Purchase',
            'action_source' => 'website',
            'user_data' => [],
            'custom_data' => [],
        ]]];
    }
}
