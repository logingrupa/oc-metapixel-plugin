<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use ArrayAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Components\EventPixel;
use Logingrupa\Metapixel\Tests\Fixtures\Migrations\CreateTestSubjectsTable;
use Logingrupa\Metapixel\Tests\Fixtures\Models\TestSubject;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * Edge-case branches in EventPixel — empty-property early return,
 * non-Model subject_class, lookup Throwable catch, extractCustomData
 * tolerant returns (non-string payload, non-array decode, missing data
 * key, non-array first row, non-array custom_data), and insertPixelRow's
 * Throwable catch.
 */
#[Group('adapter')]
final class EventPixelEdgeCasesTest extends MetapixelTestCase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelEventLogTable)->up();
        (new AddPayloadToMetapixelEventLogTable)->up();
        CreateTestSubjectsTable::up();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        CreateTestSubjectsTable::down();
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // componentDetails (L28) — defensive coverage of the metadata getter.
    // -----------------------------------------------------------------------

    public function test_component_details_returns_name_and_description(): void
    {
        $arDetails = (new EventPixel)->componentDetails();

        $this->assertSame('EventPixel', $arDetails['name']);
        $this->assertNotSame('', $arDetails['description']);
    }

    public function test_define_properties_declares_required_subject_class_and_subject_type(): void
    {
        $arProps = (new EventPixel)->defineProperties();

        $this->assertTrue($arProps['subject_class']['required'] ?? false);
        $this->assertTrue($arProps['subject_type']['required'] ?? false);
    }

    // -----------------------------------------------------------------------
    // onRun early-return (L50-52)
    // -----------------------------------------------------------------------

    public function test_on_run_returns_silently_when_subject_class_is_empty(): void
    {
        $arPageData = $this->runComponent([
            'subject_class' => '',
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'whatever',
        ]);

        $this->assertNull($arPageData);
    }

    public function test_on_run_returns_silently_when_slug_is_empty(): void
    {
        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => '',
        ]);

        $this->assertNull($arPageData);
    }

    // -----------------------------------------------------------------------
    // lookupSubjectId (L117-127)
    // -----------------------------------------------------------------------

    public function test_lookup_subject_id_returns_zero_when_subject_class_not_a_model(): void
    {
        // subject_class is a real class but not a subclass of Illuminate Model
        // → is_subclass_of false → method returns 0 (L117-119) → onRun bails on
        // iSubjectId <= 0 (L54-56), no page data written.
        $arPageData = $this->runComponent([
            'subject_class' => \stdClass::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'something',
        ]);

        $this->assertNull($arPageData);
    }

    public function test_lookup_subject_id_swallows_throwable_and_logs(): void
    {
        // Drop the test_subjects table to force the where()->value('id') call
        // to throw a QueryException. lookupSubjectId catches Throwable, logs a
        // warning, returns 0. The Tiger-Style "page render must not break"
        // guarantee for D-09 lock.
        CreateTestSubjectsTable::down();

        Log::shouldReceive('warning')->atLeast()->once();

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'orphan',
        ]);

        $this->assertNull($arPageData);

        // Recreate so tearDown's CreateTestSubjectsTable::down() doesn't error.
        CreateTestSubjectsTable::up();
    }

    // -----------------------------------------------------------------------
    // extractCustomData (L161-187) — five tolerant-return branches.
    // -----------------------------------------------------------------------

    public function test_on_run_yields_empty_custom_data_when_payload_is_null(): void
    {
        // payload column NULL → ! is_string branch (L162) → return [].
        $this->seedCapiRowRawPayload(42, 'pld-null', null);
        TestSubject::create(['id' => 42, 'secret_key' => 'null-payload']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'null-payload',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('[]', $arPageData['custom_data_json']);
    }

    public function test_on_run_yields_empty_custom_data_when_payload_is_empty_string(): void
    {
        // payload column '' → is_string true, ''=== '' → return [] (L162).
        $this->seedCapiRowRawPayload(43, 'pld-empty', '');
        TestSubject::create(['id' => 43, 'secret_key' => 'empty-payload']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'empty-payload',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('[]', $arPageData['custom_data_json']);
    }

    public function test_on_run_yields_empty_custom_data_when_payload_decodes_to_scalar(): void
    {
        // payload JSON-decodes to a scalar (string), not an array → ! is_array
        // (L166) → return [].
        $this->seedCapiRowRawPayload(44, 'pld-scalar', '"just-a-string"');
        TestSubject::create(['id' => 44, 'secret_key' => 'scalar-payload']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'scalar-payload',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('[]', $arPageData['custom_data_json']);
    }

    public function test_on_run_yields_empty_custom_data_when_data_key_missing(): void
    {
        // payload['data'] absent → ! is_array $mData (L170) → return [].
        $this->seedCapiRowRawPayload(45, 'pld-nodata', '{"other":"field"}');
        TestSubject::create(['id' => 45, 'secret_key' => 'no-data-key']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'no-data-key',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('[]', $arPageData['custom_data_json']);
    }

    public function test_on_run_yields_empty_custom_data_when_first_data_row_not_array(): void
    {
        // payload['data'][0] is a scalar → ! is_array $mFirst (L174) → return [].
        $this->seedCapiRowRawPayload(46, 'pld-data-scalar', '{"data":["scalar"]}');
        TestSubject::create(['id' => 46, 'secret_key' => 'data-scalar']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'data-scalar',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('[]', $arPageData['custom_data_json']);
    }

    public function test_on_run_yields_empty_custom_data_when_custom_data_not_array(): void
    {
        // payload['data'][0]['custom_data'] is a string → ! is_array
        // $mCustomData (L178) → return [].
        $this->seedCapiRowRawPayload(47, 'pld-cd-str', '{"data":[{"custom_data":"not-array"}]}');
        TestSubject::create(['id' => 47, 'secret_key' => 'cd-not-array']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'cd-not-array',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('[]', $arPageData['custom_data_json']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function seedCapiRowRawPayload(int $iSubjectId, string $sEventId, ?string $sPayload): void
    {
        $sNow = (string) Carbon::now();
        DB::table(self::TABLE)->insert([
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'channel' => 'capi',
            'subject_type' => 'shopaholic.order',
            'subject_id' => $iSubjectId,
            'secret_key' => 'sk-'.$iSubjectId,
            'site_id' => 1,
            'event_time' => 1700000000,
            'fired_at' => $sNow,
            'created_at' => $sNow,
            'updated_at' => $sNow,
            'payload' => $sPayload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arProps
     * @return array<string, mixed>|null
     */
    private function runComponent(array $arProps): ?array
    {
        $obFakePage = new class implements ArrayAccess
        {
            /** @var array<string, mixed> */
            public array $vars = [];

            public function offsetExists($offset): bool
            {
                return isset($this->vars[$offset]);
            }

            public function offsetGet($offset): mixed
            {
                return $this->vars[$offset] ?? null;
            }

            public function offsetSet($offset, $value): void
            {
                if ($offset === null) {
                    $this->vars[] = $value;

                    return;
                }
                $this->vars[$offset] = $value;
            }

            public function offsetUnset($offset): void
            {
                unset($this->vars[$offset]);
            }
        };

        $obComponent = new EventPixel;
        $obComponent->setProperties($arProps);

        $obReflection = new ReflectionProperty(EventPixel::class, 'page');
        $obReflection->setAccessible(true);
        $obReflection->setValue($obComponent, $obFakePage);

        $obComponent->onRun();

        return $obFakePage->vars['eventPixelData'] ?? null;
    }
}
