<?php

use Logingrupa\Metapixel\Classes\Meta\DatasetQualityRows;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class DatasetQualityRowsTest extends MetapixelTestCase
{
    public function test_merges_both_maps_into_one_sorted_row_per_event_name(): void
    {
        $arRows = DatasetQualityRows::fromResponse([
            'event_match_quality' => ['ViewContent' => 7.15, 'Purchase' => 8.4],
            'event_coverage' => ['Purchase' => 83.333, 'PageView' => 3.5],
            'raw' => [],
        ]);

        $this->assertSame([
            ['event_name' => 'PageView', 'emq' => null, 'coverage' => 3.5],
            ['event_name' => 'Purchase', 'emq' => 8.4, 'coverage' => 83.33],
            ['event_name' => 'ViewContent', 'emq' => 7.15, 'coverage' => null],
        ], $arRows);
    }

    public function test_non_array_maps_and_non_numeric_values_yield_no_rows(): void
    {
        $this->assertSame([], DatasetQualityRows::fromResponse([
            'event_match_quality' => 'not-an-array',
            'event_coverage' => null,
        ]));

        $this->assertSame([], DatasetQualityRows::fromResponse([
            'event_match_quality' => ['Purchase' => 'nine', '' => 1.0, 0 => 2.0],
            'event_coverage' => ['Purchase' => ['nested']],
        ]));
    }
}
