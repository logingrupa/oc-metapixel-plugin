<?php

use Illuminate\Support\Carbon;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Exception\MetaApiPermanentException;
use Logingrupa\Metapixel\Classes\Meta\MetaClient;
use Logingrupa\Metapixel\Controllers\FailedEvents;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * onCheckDatasetQuality fetches Meta Dataset Quality once for the pixel and
 * renders one row per event name into the panel above the list. A Graph
 * failure flashes the error and leaves the panel untouched (empty response).
 */
final class FailedEventsDatasetQualityTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);

        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-DQ',
            'capi_access_token' => 'TOKEN-DQ',
        ]);

        $obFlash = Mockery::mock();
        $obFlash->shouldReceive('error')->andReturnNull();
        $obFlash->shouldReceive('success')->andReturnNull();
        $this->app->instance('flash', $obFlash);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_renders_one_row_per_event_name_into_the_panel(): void
    {
        $obFakeClient = new class extends MetaClient
        {
            public int $iCallCount = 0;

            public string $sLastPixelId = '';

            public function __construct()
            {
                parent::__construct(null);
            }

            public function fetchDatasetQuality(string $sPixelId, string $sToken): array
            {
                $this->iCallCount++;
                $this->sLastPixelId = $sPixelId;

                return [
                    'event_match_quality' => ['Purchase' => 8.4, 'PageView' => 3.9],
                    'event_coverage' => ['Purchase' => 83.0],
                    'raw' => [],
                ];
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = new TestableFailedEventsForDatasetQuality;
        $arResponse = $obController->onCheckDatasetQuality();

        $this->assertSame(1, $obFakeClient->iCallCount, 'one Graph call for the whole pixel');
        $this->assertSame('PIXEL-DQ', $obFakeClient->sLastPixelId);
        $this->assertArrayHasKey('#metapixelDatasetQuality', $arResponse);
        $this->assertSame([
            ['event_name' => 'PageView', 'emq' => 3.9, 'coverage' => null],
            ['event_name' => 'Purchase', 'emq' => 8.4, 'coverage' => 83.0],
        ], $obController->arRenderedRows);
    }

    public function test_graph_failure_flashes_and_returns_no_panel_update(): void
    {
        $obFakeClient = new class extends MetaClient
        {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function fetchDatasetQuality(string $sPixelId, string $sToken): array
            {
                throw new MetaApiPermanentException('metapixel: graph quality 400', 400);
            }
        };
        $this->app->instance(MetaClient::class, $obFakeClient);

        $obController = new TestableFailedEventsForDatasetQuality;

        $this->assertSame([], $obController->onCheckDatasetQuality());
        $this->assertNull($obController->arRenderedRows);
    }
}

/**
 * Bypasses backend Controller boot and captures the rows handed to the
 * view layer instead of rendering the partial.
 */
final class TestableFailedEventsForDatasetQuality extends FailedEvents
{
    /** @var ?list<array{event_name: string, emq: ?float, coverage: ?float}> */
    public ?array $arRenderedRows = null;

    public function __construct() {}

    protected function renderDatasetQuality(array $arRows, Carbon $obFetchedAt): string
    {
        $this->arRenderedRows = $arRows;

        return '<panel-stub />';
    }
}
