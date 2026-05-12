<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
// Migration filename is snake_case (October Updates Manager convention) — not PSR-4 discoverable.
require_once __DIR__.'/../../updates/create_table_failed_events.php';

use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Models\FailedEvent;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Updates\CreateTableFailedEvents;
use October\Rain\Database\ModelException;

/**
 * Feature test covering Plan 03-01 PAY-05 — the FailedEvent Eloquent model:
 *
 *   1. FailedEvent::create([...]) persists a row with all six business columns.
 *   2..4. Validation rejects empty event_id / oversized event_id / oversized
 *         event_name via `October\Rain\Database\ModelException`.
 *   5..7. FailedEvent::createFromPayloadAndException factory encodes payload as
 *         JSON, reads http_status + attempts from the exception's arContext,
 *         and defaults attempts to 0 when arContext is empty.
 *
 * The createFromPayloadAndException tests depend on the abstract base
 * MetaPixelException that plan 03-02 ships. Until then, those three tests
 * mark themselves skipped via `class_exists` — they auto-run the moment
 * 03-02 lands and PSR-4 resolves the class.
 *
 * Hermetic schema: CreateTableFailedEvents::up() is invoked directly per
 * MigrationsBootTest pattern; no October Updates Manager round-trip.
 */
final class FailedEventModelTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        (new CreateTableFailedEvents)->up();
    }

    public function test_create_persists_a_row_with_all_fillable_columns(): void
    {
        $arData = [
            'event_id' => 'abc-123',
            'event_name' => 'Purchase',
            'payload' => '{"data":[{"event_id":"abc-123"}]}',
            'graph_error' => 'sample error',
            'http_status' => 503,
            'attempts' => 4,
        ];

        $obFailed = FailedEvent::create($arData);

        $this->assertNotNull($obFailed->id, 'FailedEvent::create() must return a persisted row with an id.');

        $obRow = FailedEvent::find($obFailed->id);
        $this->assertNotNull($obRow);
        $this->assertSame('abc-123', $obRow->event_id);
        $this->assertSame('Purchase', $obRow->event_name);
        $this->assertSame('{"data":[{"event_id":"abc-123"}]}', $obRow->payload);
        $this->assertSame('sample error', $obRow->graph_error);
        $this->assertSame(503, $obRow->http_status);
        $this->assertSame(4, $obRow->attempts);
    }

    public function test_validation_rejects_empty_event_id(): void
    {
        $this->expectException(ModelException::class);

        FailedEvent::create([
            'event_id' => '',
            'event_name' => 'Purchase',
            'payload' => '{}',
            'attempts' => 0,
        ]);
    }

    public function test_validation_rejects_event_id_over_36_chars(): void
    {
        $this->expectException(ModelException::class);

        FailedEvent::create([
            'event_id' => str_repeat('x', 37),
            'event_name' => 'Purchase',
            'payload' => '{}',
            'attempts' => 0,
        ]);
    }

    public function test_validation_rejects_event_name_over_64_chars(): void
    {
        $this->expectException(ModelException::class);

        FailedEvent::create([
            'event_id' => 'abc-123',
            'event_name' => str_repeat('y', 65),
            'payload' => '{}',
            'attempts' => 0,
        ]);
    }

    public function test_create_from_payload_and_exception_encodes_payload_as_json(): void
    {
        $this->skipIfMetaPixelExceptionMissing();

        $arPayload = [
            'data' => [
                ['event_id' => 'abc-123', 'event_name' => 'Purchase'],
            ],
        ];
        $obException = $this->makeMetaPixelExceptionDouble('sample failure message', []);

        $obFailed = FailedEvent::createFromPayloadAndException($arPayload, $obException);

        $this->assertSame('abc-123', $obFailed->event_id);
        $this->assertSame('Purchase', $obFailed->event_name);
        $this->assertSame(
            json_encode($arPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $obFailed->payload,
            'payload must be json_encoded with UNESCAPED_SLASHES | UNESCAPED_UNICODE flags.'
        );
        $this->assertSame('sample failure message', $obFailed->graph_error);
    }

    public function test_create_from_payload_and_exception_reads_http_status_from_context(): void
    {
        $this->skipIfMetaPixelExceptionMissing();

        $obException = $this->makeMetaPixelExceptionDouble(
            'oops',
            ['http_status' => 503, 'attempts' => 4]
        );

        $obFailed = FailedEvent::createFromPayloadAndException(
            ['data' => [['event_id' => 'abc', 'event_name' => 'Purchase']]],
            $obException
        );

        $this->assertSame(503, $obFailed->http_status);
        $this->assertSame(4, $obFailed->attempts);
    }

    public function test_create_from_payload_and_exception_defaults_attempts_to_zero(): void
    {
        $this->skipIfMetaPixelExceptionMissing();

        $obException = $this->makeMetaPixelExceptionDouble('oops', []);

        $obFailed = FailedEvent::createFromPayloadAndException(
            ['data' => [['event_id' => 'abc', 'event_name' => 'Purchase']]],
            $obException
        );

        $this->assertSame(0, $obFailed->attempts);
        $this->assertNull($obFailed->http_status);
    }

    /**
     * Skip the current test if MetaPixelException has not yet been declared.
     * Once plan 03-02 ships the abstract base + concrete subclasses, this
     * skip-guard short-circuits and the dependent tests auto-run.
     */
    private function skipIfMetaPixelExceptionMissing(): void
    {
        if (!class_exists(\Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException::class)) {
            $this->markTestSkipped('MetaPixelException ships in plan 03-02. Once it lands, this test auto-runs.');
        }
    }

    /**
     * Build an anonymous-class subclass of MetaPixelException with a known
     * message + arContext for factory tests. Returned as the abstract base
     * type so callers can treat it polymorphically.
     *
     * The anonymous class forwards $arContext through `parent::__construct`
     * (the readonly property is set once by constructor promotion and
     * cannot be reassigned — T-03-06 immutability lock). It implements the
     * abstract `isRetryable()` returning false (matches the "permanent"
     * dead-letter contract exercised by the factory under test).
     *
     * @param  array<string,mixed>  $arContext
     */
    private function makeMetaPixelExceptionDouble(string $sMessage, array $arContext): \Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException
    {
        return new class($sMessage, $arContext) extends \Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException
        {
            public function isRetryable(): bool
            {
                return false;
            }
        };
    }
}
