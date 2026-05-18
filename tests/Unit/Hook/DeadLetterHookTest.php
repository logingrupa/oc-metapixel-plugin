<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\FakeStubAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class DeadLetterHookTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'PIXEL-42', 'capi_access_token' => 'TOKEN-XYZ']);
        app(AdapterRegistry::class)->register(stdClass::class, FakeStubAdapter::class);
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_permanent_failure_fires_dead_letter_listener_with_exception(): void
    {
        $arInvocations = [];
        Event::listen(
            SendCapiEvent::HOOK_DEAD_LETTER,
            function (string $sEventName, array $arPayload, object $obSubject, Throwable $obException) use (&$arInvocations): void {
                $arInvocations[] = [
                    'event_name' => $sEventName,
                    'exception_class' => get_class($obException),
                ];
            },
        );

        $obThrowingClient = new class extends MetaClient
        {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
            {
                throw new MetaApiPermanentException('metapixel: 400 graph error', 400, null, ['response' => []]);
            }
        };

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new stdClass, FakeStubAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obThrowingClient);

        $this->assertCount(1, $arInvocations, 'dead_letter listener fired once');
        $this->assertSame('Purchase', $arInvocations[0]['event_name']);
        $this->assertSame(MetaApiPermanentException::class, $arInvocations[0]['exception_class']);

        $this->assertSame(1, DB::table('logingrupa_metapixel_failed_events')->count(), 'FailedEvent row written');
        $obRow = DB::table('logingrupa_metapixel_failed_events')->first();
        $this->assertSame('fake.subject', $obRow->subject_type, 'H-2 subject_type populated from adapter');
        $this->assertSame(1, (int) $obRow->subject_id, 'H-2 subject_id populated from FakeStubAdapter');
        $this->assertSame(400, (int) $obRow->http_status);
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
