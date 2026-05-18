<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;
use PHPUnit\Framework\Attributes\Group;

/**
 * SC1 + SC5 end-to-end backbone integration — SendCapiEvent::handle through
 * FakeAdapter → AdapterRegistry::resolveByClass → EventLogWriter race-fence →
 * MetaClient mock → after_dispatch listener. Plus M-5 production-path serialize
 * round-trip smoke (proves SerializesModels survives the queue cycle).
 *
 * Uses TestSubject + TestSubjectAdapter (rather than FakeAdapter + stdClass)
 * because TestSubjectAdapter has a deterministic positive subject_id from
 * TestSubject.iId — sidesteps the App::make(FakeAdapter::class) round-trip
 * shape that EventLogWriter goes through under hood. The contract is the same
 * (EventSubjectAdapter), only the concrete shape changes per test concern.
 */
#[Group('adapter')]
final class BackboneIntegrationTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'PIXEL-1', 'capi_access_token' => 'TOKEN-1']);
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
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

    public function test_happy_path_fake_adapter_through_full_backbone_returns_capi_row_and_fires_after_dispatch(): void
    {
        $arResponses = [];
        Event::listen(
            SendCapiEvent::HOOK_AFTER_DISPATCH,
            function (string $sName, array $arPayload, object $obSubject, array $arResponse) use (&$arResponses): void {
                $arResponses[] = $arResponse;
            },
        );

        $obMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1, 'fbtrace_id' => 'trace-1']) ?: ''),
        ]);
        $obStack = HandlerStack::create($obMock);
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-backbone-1', 1700000001);
        $obJob = new SendCapiEvent('Purchase', $arPayload, new TestSubject, TestSubjectAdapter::class);
        $obJob->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(), 'EventLog has 1 row');
        $obRow = DB::table('logingrupa_metapixel_event_log')->first();
        $this->assertSame('capi', $obRow->channel);
        $this->assertSame('Purchase', $obRow->event_name);
        $this->assertSame('fake.subject', $obRow->subject_type);
        $this->assertSame('uuid-backbone-1', $obRow->event_id);
        $this->assertSame(0, DB::table('logingrupa_metapixel_failed_events')->count(), 'no FailedEvent');
        $this->assertCount(1, $arResponses, 'after_dispatch listener fired once');
        $this->assertSame(1, $arResponses[0]['events_received']);
        $this->assertSame('trace-1', $arResponses[0]['fbtrace_id']);
    }

    public function test_dedup_second_dispatch_for_same_subject_short_circuits_no_http_call(): void
    {
        // Force non-null site_id so the UNIQUE race-fence actually fires on the
        // second insert (SQLite + InnoDB treat multiple NULL site_ids as DISTINCT).
        $this->app->bind(TestSubjectAdapter::class, fn () => new TestSubjectAdapter(1));

        $arHistory = [];
        $obMock = new MockHandler([
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ]);
        $obStack = HandlerStack::create($obMock);
        $obStack->push(Middleware::history($arHistory));
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-dedup-1', 1700000002);

        $obFirstJob = new SendCapiEvent('Purchase', $arPayload, new TestSubject, TestSubjectAdapter::class);
        $obFirstJob->handle(app(AdapterRegistry::class), $obClient);

        $obSecondJob = new SendCapiEvent('Purchase', $arPayload, new TestSubject, TestSubjectAdapter::class);
        $obSecondJob->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count(), 'EventLog has 1 row (second dispatch deduped)');
        $this->assertCount(1, $arHistory, 'MetaClient called exactly once — race-fence short-circuit (history middleware accurate count, NOT MockHandler internal queue)');
    }

    /**
     * Production Laravel queue workers serialize jobs to the queue backend
     * (Redis/DB) and unserialize them on the worker side. Synchronous tests
     * skip this cycle. This smoke confirms SerializesModels handles the
     * subject + readonly properties correctly + handle() works on the
     * unserialized form.
     */
    public function test_serialize_round_trip_job_unserializes_and_runs_handle(): void
    {
        $obMock = new MockHandler([
            new Response(200, [], json_encode(['events_received' => 1]) ?: ''),
        ]);
        $obStack = HandlerStack::create($obMock);
        $obClient = new MetaClient(new Client(['handler' => $obStack]));

        $arPayload = $this->buildPayload('uuid-serialize-1', 1700000003);
        $obOriginalJob = new SendCapiEvent('Purchase', $arPayload, new TestSubject, TestSubjectAdapter::class);

        $sBlob = serialize($obOriginalJob);
        $obRehydrated = unserialize($sBlob);

        $this->assertInstanceOf(SendCapiEvent::class, $obRehydrated);
        $obRehydrated->handle(app(AdapterRegistry::class), $obClient);

        $this->assertSame(
            1,
            DB::table('logingrupa_metapixel_event_log')->count(),
            'EventLog row written after serialize/unserialize round-trip',
        );
    }

    /** @return array<string, mixed> */
    private function buildPayload(string $sEventId, int $iEventTime): array
    {
        $obBuilder = new PayloadBuilder(new UserDataHasher);

        return $obBuilder->buildEventPayload(
            'Purchase',
            new TestSubjectAdapter,
            new TestSubject,
            new \Logingrupa\Metapixel\Tests\Doubles\FakeValueResolver,
            $sEventId,
            $iEventTime,
            [],
        );
    }
}
