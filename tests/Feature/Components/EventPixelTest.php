<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ArrayAccess;
use Logingrupa\Metapixel\Components\EventPixel;
use Logingrupa\Metapixel\Tests\Fixtures\Migrations\CreateTestSubjectsTable;
use Logingrupa\Metapixel\Tests\Fixtures\Models\TestSubject;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\AddPayloadToMetapixelEventLogTable;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * THEM-06 — EventPixel::onRun reads the EventLog row directly via DB::table
 * (D-09 lock; no adapter re-resolve at render time), explicit json_decode of
 * the payload column (Pitfall 8), and emits an inline fbq() script tag only
 * when a CAPI row exists AND the pixel-side row is absent for the tuple.
 */
#[Group('adapter')]
final class EventPixelTest extends MetapixelTestCase
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
        CreateTestSubjectsTable::down();
        (new AddPayloadToMetapixelEventLogTable)->down();
        (new CreateMetapixelEventLogTable)->down();
        parent::tearDown();
    }

    public function test_onRun_emits_fbq_script_when_capi_row_exists_and_pixel_row_absent(): void
    {
        $this->seedCapiRow(42, 'test-uuid-xyz', [
            'data' => [['custom_data' => ['value' => 99.99, 'currency' => 'EUR', 'content_ids' => ['SKU-1']]]],
        ]);
        TestSubject::create(['id' => 42, 'secret_key' => 'test-slug']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'test-slug',
        ]);

        $this->assertIsArray($arPageData);
        $this->assertSame('test-uuid-xyz', $arPageData['event_id']);
        $this->assertSame('Purchase', $arPageData['event_name']);
        $this->assertSame('shopaholic.order', $arPageData['subject_type']);
        $this->assertSame(42, $arPageData['subject_id']);

        $arDecoded = json_decode($arPageData['custom_data_json'], true);
        $this->assertIsArray($arDecoded);
        $this->assertSame(99.99, $arDecoded['value']);
        $this->assertSame('EUR', $arDecoded['currency']);
        $this->assertSame(['SKU-1'], $arDecoded['content_ids']);
    }

    public function test_onRun_returns_silently_when_no_capi_row_exists(): void
    {
        TestSubject::create(['id' => 7, 'secret_key' => 'no-capi-slug']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'no-capi-slug',
        ]);

        $this->assertNull($arPageData);
    }

    public function test_onRun_returns_silently_when_pixel_row_already_exists(): void
    {
        $this->seedCapiRow(11, 'cap-uuid', ['data' => [['custom_data' => ['value' => 5.0]]]]);
        $this->seedPixelRow(11, 'cap-uuid');
        TestSubject::create(['id' => 11, 'secret_key' => 'has-pixel']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'has-pixel',
        ]);

        $this->assertNull($arPageData);
    }

    public function test_onRun_returns_silently_when_subject_id_lookup_fails(): void
    {
        $this->seedCapiRow(99, 'unused-uuid', []);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'no-such-row',
        ]);

        $this->assertNull($arPageData);
    }

    public function test_onRun_decodes_payload_via_explicit_json_decode_not_jsonable_auto_cast(): void
    {
        $this->seedCapiRow(55, 'pitfall-uuid', [
            'data' => [['custom_data' => ['value' => 1.5, 'currency' => 'USD']]],
        ]);
        TestSubject::create(['id' => 55, 'secret_key' => 'pitfall-slug']);

        $arPageData = $this->runComponent([
            'subject_class' => TestSubject::class,
            'subject_slug_field' => 'secret_key',
            'subject_type' => 'shopaholic.order',
            'event_name' => 'Purchase',
            'slug' => 'pitfall-slug',
        ]);

        $obRawRow = DB::table(self::TABLE)
            ->where('subject_type', 'shopaholic.order')->where('subject_id', 55)
            ->where('channel', 'capi')->first(['payload']);
        $this->assertIsString($obRawRow->payload, 'DB::table returns longText payload as a string (Pitfall 8 anchor)');

        $this->assertIsArray($arPageData);
        $arDecoded = json_decode($arPageData['custom_data_json'], true);
        $this->assertSame(1.5, $arDecoded['value']);
        $this->assertSame('USD', $arDecoded['currency']);
    }

    /**
     * @param  array<string, mixed>  $arPayload
     */
    private function seedCapiRow(int $iSubjectId, string $sEventId, array $arPayload): void
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
            'payload' => $arPayload === [] ? null : (string) json_encode($arPayload),
        ]);
    }

    private function seedPixelRow(int $iSubjectId, string $sEventId): void
    {
        $sNow = (string) Carbon::now();
        DB::table(self::TABLE)->insert([
            'event_id' => $sEventId,
            'event_name' => 'Purchase',
            'channel' => 'pixel',
            'subject_type' => 'shopaholic.order',
            'subject_id' => $iSubjectId,
            'secret_key' => 'sk-'.$iSubjectId,
            'site_id' => 1,
            'event_time' => 1700000000,
            'fired_at' => $sNow,
            'created_at' => $sNow,
            'updated_at' => $sNow,
            'payload' => null,
        ]);
    }

    /**
     * Instantiate EventPixel with a fake CodeBase page so $this->page['x'] = ...
     * captures into an asserted array instead of crashing on null.
     *
     * @param  array<string, mixed>  $arProps
     * @return array<string, mixed>|null
     */
    private function runComponent(array $arProps): ?array
    {
        $obFakePage = new class implements ArrayAccess {
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
