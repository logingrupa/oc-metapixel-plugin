<?php

use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\Doubles\TestSubject;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use October\Rain\Support\Facades\Site;

/**
 * Wave 0 RED — fails until plan 04-01 ships.
 *
 * MULT-05 / D-04: UNIQUE on (subject_type, subject_id, event_name, channel,
 * site_id) — NULL-distinct semantics on SQLite + MySQL InnoDB 8.0.13+ —
 * lets the 8-path matrix (2 subjects x 2 sites x 2 channels) all insert.
 */
final class MultisiteEventLogRoutingTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        $fnSeedSites = require __DIR__.'/../fixtures/sites.php';
        $fnSeedSites($this->app['db']->connection()->getSchemaBuilder(), $this->app['db']->connection());
    }

    protected function tearDown(): void
    {
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('system_site_definitions');
        Site::resetCache();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_matrix_subject_101_site_1_capi(): void
    {
        $this->assertTrue($this->recordOne(101, 1, 'capi', 'uuid-101-1-capi'));
    }

    public function test_matrix_subject_101_site_1_pixel(): void
    {
        $this->assertTrue($this->recordOne(101, 1, 'pixel', 'uuid-101-1-pixel'));
    }

    public function test_matrix_subject_101_site_2_capi(): void
    {
        $this->assertTrue($this->recordOne(101, 2, 'capi', 'uuid-101-2-capi'));
    }

    public function test_matrix_subject_101_site_2_pixel(): void
    {
        $this->assertTrue($this->recordOne(101, 2, 'pixel', 'uuid-101-2-pixel'));
    }

    public function test_matrix_subject_202_site_1_capi(): void
    {
        $this->assertTrue($this->recordOne(202, 1, 'capi', 'uuid-202-1-capi'));
    }

    public function test_matrix_subject_202_site_1_pixel(): void
    {
        $this->assertTrue($this->recordOne(202, 1, 'pixel', 'uuid-202-1-pixel'));
    }

    public function test_matrix_subject_202_site_2_capi(): void
    {
        $this->assertTrue($this->recordOne(202, 2, 'capi', 'uuid-202-2-capi'));
    }

    public function test_matrix_subject_202_site_2_pixel(): void
    {
        $this->assertTrue($this->recordOne(202, 2, 'pixel', 'uuid-202-2-pixel'));
    }

    public function test_8_path_matrix_full_insert_count_and_per_site_distinct(): void
    {
        $arMatrix = [];
        foreach ([101, 202] as $iSubjectId) {
            foreach ([1, 2] as $iSiteId) {
                foreach (['capi', 'pixel'] as $sChannel) {
                    $arMatrix[] = [$iSubjectId, $iSiteId, $sChannel];
                }
            }
        }

        foreach ($arMatrix as $arRow) {
            [$iSubjectId, $iSiteId, $sChannel] = $arRow;
            $bResult = $this->recordOne(
                $iSubjectId,
                $iSiteId,
                $sChannel,
                sprintf('uuid-matrix-%d-%d-%s', $iSubjectId, $iSiteId, $sChannel)
            );
            $this->assertTrue(
                $bResult,
                sprintf('matrix insert failed for subject=%d site=%d channel=%s', $iSubjectId, $iSiteId, $sChannel)
            );
        }

        $this->assertSame(8, DB::table(self::TABLE)->count(), '8-path matrix MUST all insert under (subject_type, subject_id, event_name, channel, site_id) UNIQUE.');
    }

    public function test_null_site_id_rows_are_distinct_under_unique(): void
    {
        // SQLite + MySQL InnoDB treat multiple NULL values in a UNIQUE column
        // as DISTINCT — two NULL-site_id inserts for the same subject win both.
        $bFirst = $this->recordOne(303, null, 'capi', 'uuid-null-1');
        $bSecond = $this->recordOne(303, null, 'capi', 'uuid-null-2');

        $this->assertTrue($bFirst, 'first NULL-site_id insert wins');
        $this->assertTrue($bSecond, 'second NULL-site_id insert wins (NULL-distinct UNIQUE semantics)');
        $this->assertSame(2, DB::table(self::TABLE)->count());
    }

    private function recordOne(int $iSubjectId, ?int $iSiteId, string $sChannel, string $sEventId): bool
    {
        $obSubject = new TestSubject;
        $obAdapter = (new FakeAdapter)
            ->withSubjectType('fake.subject')
            ->withSubjectId($iSubjectId)
            ->withSiteId($iSiteId);
        // Bind THIS adapter instance into the container so AdapterRegistry's
        // App::make(FakeAdapter::class) returns our configured one (not a
        // fresh default-state instance).
        $this->app->instance(FakeAdapter::class, $obAdapter);
        app(AdapterRegistry::class)->register(TestSubject::class, FakeAdapter::class);

        return EventLogWriter::record($sEventId, 'Purchase', $sChannel, $obSubject, null, 1700000000, $iSiteId, []);
    }
}
