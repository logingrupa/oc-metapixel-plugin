<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiTransientException;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelFailedEventsTable;

final class SendCapiEventFailedHandlerTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new CreateMetapixelFailedEventsTable)->up();
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

    public function test_failed_handler_writes_failed_event_with_adapter_resolved(): void
    {
        $arInvocations = [];
        Event::listen(
            SendCapiEvent::HOOK_DEAD_LETTER,
            function (string $sEventName, array $arPayload, object $obSubject, Throwable $obException) use (&$arInvocations): void {
                $arInvocations[] = ['class' => get_class($obException)];
            },
        );

        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, TestSubjectAdapter::class);
        $obJob->failed(new MetaApiTransientException('queue retry exhausted', 503));

        $this->assertSame(1, DB::table('logingrupa_metapixel_failed_events')->count());
        $obRow = DB::table('logingrupa_metapixel_failed_events')->first();
        $this->assertSame('fake.subject', $obRow->subject_type, 'L-5 adapter-resolve populates subject_type');
        $this->assertSame(42, (int) $obRow->subject_id, 'L-5 adapter-resolve populates subject_id');
        $this->assertSame(503, (int) $obRow->http_status);

        $this->assertCount(1, $arInvocations, 'dead_letter fired on retry-exhaustion');
        $this->assertSame(MetaApiTransientException::class, $arInvocations[0]['class']);
    }

    public function test_failed_handler_with_unresolvable_adapter_writes_null_subject_columns(): void
    {
        $obJob = new SendCapiEvent('Purchase', $this->makePayload(), new TestSubject, 'NonExistent\Foo\BarAdapter');
        $obJob->failed(new RuntimeException('worker death'));

        $this->assertSame(1, DB::table('logingrupa_metapixel_failed_events')->count());
        $obRow = DB::table('logingrupa_metapixel_failed_events')->first();
        $this->assertNull($obRow->subject_type, 'L-5 unresolvable adapter → null subject_type');
        $this->assertNull($obRow->subject_id, 'L-5 unresolvable adapter → null subject_id');
        $this->assertSame('NonExistent\Foo\BarAdapter', $obRow->adapter_type);
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
