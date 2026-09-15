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
        Request::shouldReceive('input')->with('search_string')->andReturnNull();
        Request::shouldReceive('input')->with('content_ids')->andReturnNull();
        Request::shouldReceive('input')->with('content_type')->andReturnNull();
        Request::shouldReceive('input')->with('num_items')->andReturnNull();

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

    public function test_read_client_custom_data_keeps_only_valid_search_fields(): void
    {
        $arResult = (new ThemeAjaxRequestReader)->readClientCustomData([
            'search_string' => ' nail file ',
            'content_ids' => ['SKU-1', 'SKU-2-3', 'sku-4', 'SKU-', 'SKU-5-x', 7],
            'content_type' => 'product',
            'num_items' => '3',
            'em' => 'nope@example.com',
            'value' => 12.5,
        ]);

        $this->assertSame([
            'search_string' => 'nail file',
            'content_ids' => ['SKU-1', 'SKU-2-3'],
            'content_type' => 'product',
            'num_items' => 3,
        ], $arResult);
    }

    public function test_read_client_custom_data_applies_caps_and_drops_out_of_range(): void
    {
        $obReader = new ThemeAjaxRequestReader;
        $arManyIds = array_map(static fn (int $iIndex): string => 'SKU-'.$iIndex, range(1, 25));

        $arResult = $obReader->readClientCustomData([
            'search_string' => str_repeat('x', 150),
            'content_ids' => $arManyIds,
            'num_items' => 1001,
        ]);

        $this->assertSame(100, mb_strlen((string) $arResult['search_string']));
        $this->assertCount(20, $arResult['content_ids']);
        $this->assertSame('product', $arResult['content_type']);
        $this->assertArrayNotHasKey('num_items', $arResult);

        $this->assertSame([], $obReader->readClientCustomData([
            'search_string' => '   ',
            'content_ids' => 'SKU-1',
            'content_type' => 'product_group',
            'num_items' => -2,
        ]));
        $this->assertSame([], $obReader->readClientCustomData([]));
    }

    public function test_read_int_field_coerces_numeric_and_defaults_to_zero(): void
    {
        $obReader = new ThemeAjaxRequestReader;

        $this->assertSame(7, $obReader->readIntField(['offer_id' => '7'], 'offer_id'));
        $this->assertSame(0, $obReader->readIntField(['offer_id' => 'not-numeric'], 'offer_id'));
        $this->assertSame(0, $obReader->readIntField([], 'offer_id'));
    }
}
