<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Theme;

use Illuminate\Support\Facades\Request;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxRequestReader;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * ThemeAjaxRequestReader — payload parsing split out of ThemeAjaxHandler.
 * Reads both transport shapes (nested data[] + top-level $.request fields),
 * narrows the hybrid loadSubject context to string keys with an offer_id
 * overlay, and coerces int fields. Non-array / non-string-key payloads are
 * rejected as null (fail-safe).
 */
#[Group('adapter')]
final class ThemeAjaxRequestReaderTest extends MetapixelTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_read_event_data_merges_nested_and_top_level_fields(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn(['name' => 'ViewContent']);
        Request::shouldReceive('input')->with('subject_type')->andReturn('mall.product');
        Request::shouldReceive('input')->with('subject_id')->andReturn('5');
        Request::shouldReceive('input')->with('offer_id')->andReturnNull();
        Request::shouldReceive('input')->with('action_key')->andReturnNull();

        $arData = (new ThemeAjaxRequestReader)->readEventData();

        $this->assertSame([
            'name' => 'ViewContent',
            'subject_type' => 'mall.product',
            'subject_id' => '5',
        ], $arData);
    }

    public function test_read_event_data_returns_null_when_nested_payload_is_not_an_array(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn('not-an-array');

        $this->assertNull((new ThemeAjaxRequestReader)->readEventData());
    }

    public function test_read_event_data_returns_null_when_nested_payload_has_non_string_keys(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([0 => 'positional']);

        $this->assertNull((new ThemeAjaxRequestReader)->readEventData());
    }

    public function test_build_hybrid_context_keeps_string_keys_and_overlays_offer_id(): void
    {
        $arContext = (new ThemeAjaxRequestReader)->buildHybridContext([
            'context' => ['variant' => 'red', 99 => 'dropped'],
            'offer_id' => 42,
        ]);

        $this->assertSame(['variant' => 'red', 'offer_id' => 42], $arContext);
    }

    public function test_read_int_field_coerces_numeric_and_defaults_to_zero(): void
    {
        $obReader = new ThemeAjaxRequestReader;

        $this->assertSame(7, $obReader->readIntField(['offer_id' => '7'], 'offer_id'));
        $this->assertSame(0, $obReader->readIntField(['offer_id' => 'not-numeric'], 'offer_id'));
        $this->assertSame(0, $obReader->readIntField([], 'offer_id'));
    }
}
