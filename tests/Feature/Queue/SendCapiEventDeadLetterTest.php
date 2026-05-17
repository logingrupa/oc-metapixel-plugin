<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class SendCapiEventDeadLetterTest extends MetapixelTestCase
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

    public function test_permanent_failure_writes_failed_event_and_fires_dead_letter_with_h2_subject_type(): void
    {
        $arInvocations = [];
        Event::listen(
            SendCapiEvent::HOOK_DEAD_LETTER,
            function (string $sEventName, array $arPayload, object $obSubject, Throwable $obException) use (&$arInvocations): void {
                $arInvocations[] = ['exception_class' => get_class($obException)];
            },
        );

        $obMock = new MockHandler([
            new Response(400, [], json_encode(['error' => ['message' => 'invalid pixel_id']]) ?: ''),
        ]);
        $obClient = new Client(['handler' => HandlerStack::create($obMock)]);

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), new MetaClient($obClient));

        $this->assertSame(1, DB::table('logingrupa_metapixel_failed_events')->count(), 'FailedEvent row written');
        $obRow = DB::table('logingrupa_metapixel_failed_events')->first();
        $this->assertSame(400, (int) $obRow->http_status);
        $this->assertSame('fake.subject', $obRow->subject_type, 'H-2 subject_type populated from TestSubjectAdapter');
        $this->assertSame(42, (int) $obRow->subject_id, 'H-2 subject_id populated from TestSubject default iId');
        $this->assertSame(TestSubjectAdapter::class, $obRow->adapter_type);

        $this->assertCount(1, $arInvocations, 'dead_letter listener fired once');
        $this->assertSame(MetaApiPermanentException::class, $arInvocations[0]['exception_class']);
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
