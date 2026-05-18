<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\Doubles\TestSubjectAdapter;
use Logingrupa\Metapixel\Tests\Doubles\ZeroIdSubjectAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use PHPUnit\Framework\Attributes\Group;

#[Group('adapter')]
final class EventLogWriterRaceFenceTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        app(AdapterRegistry::class)->register(TestSubject::class, TestSubjectAdapter::class);
    }

    protected function tearDown(): void
    {
        (new CreateMetapixelEventLogTable)->down();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_record_returns_true_on_first_insert_and_false_on_duplicate_unique_key(): void
    {
        $obSubject = new TestSubject;

        // M-3: SEQUENTIAL inserts with non-null site_id so the UNIQUE constraint
        // actually fires (SQLite + MySQL InnoDB both treat multiple NULL values
        // in a UNIQUE column as DISTINCT — see the dedicated NULL-distinct test
        // below). The race-fence INVARIANT (only-one-winner-per-key) is what
        // this test asserts; concurrency itself is not exercised here.
        $bWonFirst = EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, 1, []);
        $bWonSecond = EventLogWriter::record('uuid-2', 'Purchase', 'capi', $obSubject, null, 1700000001, 1, []);

        $this->assertTrue($bWonFirst);
        $this->assertFalse($bWonSecond);
        $this->assertSame(1, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_true_for_distinct_channel_same_subject(): void
    {
        $obSubject = new TestSubject;
        $bCapi = EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null, []);
        $bPixel = EventLogWriter::record('uuid-1', 'Purchase', 'pixel', $obSubject, null, 1700000000, null, []);

        $this->assertTrue($bCapi);
        $this->assertTrue($bPixel);
        $this->assertSame(2, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_true_for_distinct_site_id_same_subject(): void
    {
        $obSubject = new TestSubject;
        $bNullSite = EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null, []);
        $bSite7 = EventLogWriter::record('uuid-2', 'Purchase', 'capi', $obSubject, null, 1700000000, 7, []);

        $this->assertTrue($bNullSite, 'null site_id insert wins');
        $this->assertTrue($bSite7, 'site_id=7 insert wins — UNIQUE NULL-distinct semantics');
        $this->assertSame(2, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_false_when_no_adapter_registered_for_subject(): void
    {
        Log::shouldReceive('warning')->atLeast()->once();

        $bResult = EventLogWriter::record('uuid-1', 'Purchase', 'capi', new stdClass, null, 1700000000, null, []);
        $this->assertFalse($bResult);
        $this->assertSame(0, DB::table('logingrupa_metapixel_event_log')->count());
    }

    public function test_record_returns_false_on_non_positive_subject_id(): void
    {
        app()->forgetInstance(AdapterRegistry::class);
        $this->app->singleton(AdapterRegistry::class);
        app(AdapterRegistry::class)->register(TestSubject::class, ZeroIdSubjectAdapter::class);

        Log::shouldReceive('warning')->atLeast()->once();
        $bResult = EventLogWriter::record('uuid-1', 'Purchase', 'capi', new TestSubject, null, 1700000000, null, []);
        $this->assertFalse($bResult);
    }

    public function test_record_stores_subject_type_alias_not_class_fqn(): void
    {
        $obSubject = new TestSubject;
        EventLogWriter::record('uuid-1', 'Purchase', 'capi', $obSubject, null, 1700000000, null, []);

        $obRow = DB::table('logingrupa_metapixel_event_log')->first();
        $this->assertSame('fake.subject', $obRow->subject_type, 'opaque alias written, not class FQN');
        $this->assertStringNotContainsString('\\', $obRow->subject_type, 'no backslashes — alias not FQN');
    }

    public function test_record_returns_false_on_db_write_failure(): void
    {
        // Drop the table after setUp's up() — the insertOrIgnore call inside
        // record() will throw a QueryException, the outer try/catch fail-safe
        // catches it, logs critical, and returns false (peer-wins assumption).
        Schema::dropIfExists('logingrupa_metapixel_event_log');

        Log::shouldReceive('critical')->atLeast()->once();

        $bResult = EventLogWriter::record('uuid-1', 'Purchase', 'capi', new TestSubject, null, 1700000000, 1, []);
        $this->assertFalse($bResult, 'DB write failure returns false — fail-safe peer-wins');
    }
}
