<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class SendCapiEventHappyPathTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'PIXEL-42', 'capi_access_token' => 'TOKEN-XYZ']);
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        (new CreateMetapixelFailedEventsTable)->down();
        Event::forget(SendCapiEvent::HOOK_BEFORE_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_AFTER_DISPATCH);
        Event::forget(SendCapiEvent::HOOK_DEAD_LETTER);
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_happy_path_writes_event_log_calls_meta_fires_after_dispatch(): void
    {
        $arInvocations = [];
        Event::listen(
            SendCapiEvent::HOOK_AFTER_DISPATCH,
            function (string $sEventName, array $arPayload, object $obSubject, array $arResponse) use (&$arInvocations): void {
                $arInvocations[] = $arResponse;
            },
        );

        $obMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1, 'fbtrace_id' => 'trace-1']) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(), 'race-fence row written');
        $obRow = DB::table('logingrupa_metapixel_event_log')->first();
        $this->assertSame('capi', $obRow->channel);
        $this->assertSame('Purchase', $obRow->event_name);
        $this->assertSame('fake.subject', $obRow->subject_type);

        $this->assertCount(1, $arInvocations, 'after_dispatch listener fired once');
        $this->assertSame(1, $arInvocations[0]['events_received']);
        $this->assertSame(0, DB::table('logingrupa_metapixel_failed_events')->count());
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
