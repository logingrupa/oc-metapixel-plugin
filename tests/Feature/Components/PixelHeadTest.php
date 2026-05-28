<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use Cms\Classes\Controller as CmsController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PixelHeadDeferredFlushBuffer;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Components\PixelHead;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-07 — PixelHead consumes ThemeEventCollector at cms.page.beforeRenderPage
 * via flushDeferredFromController() and emits one <script>fbq("track", ...)</script>
 * per pushed event into the PixelHeadDeferredFlushBuffer singleton.
 * also_dispatch_capi:true mirrors to the CAPI queue via SendCapiEvent::dispatch.
 * Mirror failures NEVER break page render (Tiger-Style, T-03-08-07 mitigation).
 */
#[Group('adapter')]
final class PixelHeadTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::singleton(ThemeEventCollector::class);
        App::singleton(PixelHeadDeferredFlushBuffer::class);
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        App::forgetInstance(PixelHeadDeferredFlushBuffer::class);
        Mockery::close();
        parent::tearDown();
    }

    public function test_flushDeferredFromController_emits_one_script_block_per_pushed_event(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'ViewContent', 'value' => 12.5]);
        $obCollector->push(['name' => 'AddToCart']);
        $obCollector->push(['name' => 'Search', 'query' => 'lipstick']);

        PixelHead::flushDeferredFromController($this->mockController());

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(3, $arBlocks);
        $this->assertStringContainsString('fbq("track"', $arBlocks[0]);
        $this->assertStringContainsString('"ViewContent"', $arBlocks[0]);
        $this->assertStringContainsString('"AddToCart"', $arBlocks[1]);
        $this->assertStringContainsString('"Search"', $arBlocks[2]);
    }

    public function test_flushDeferredFromController_skips_event_with_missing_or_empty_name(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['value' => 12.5]);
        $obCollector->push(['name' => '', 'value' => 1]);
        $obCollector->push(['name' => 'ValidOne']);

        PixelHead::flushDeferredFromController($this->mockController());

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(1, $arBlocks, 'Collector drops missing/empty-name pushes; PixelHead emits one block for the valid event');
        $this->assertStringContainsString('"ValidOne"', $arBlocks[0]);
    }

    public function test_flushDeferredFromController_mirrors_to_capi_when_also_dispatch_capi_is_true(): void
    {
        Bus::fake();
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'Lead', 'action_key' => 'lead:form1', 'also_dispatch_capi' => true]);

        PixelHead::flushDeferredFromController($this->mockController());

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'Lead'
                && $obJob->sAdapterClass === ThemeActionAdapter::class;
        });
        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(1, $arBlocks, 'Pixel block emitted even when CAPI mirror dispatches');
    }

    public function test_flushDeferredFromController_does_not_mirror_when_also_dispatch_capi_absent_or_false(): void
    {
        Bus::fake();
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'PageView']);

        PixelHead::flushDeferredFromController($this->mockController());

        Bus::assertNotDispatched(SendCapiEvent::class);
        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(1, $arBlocks);
    }

    public function test_flushDeferredFromController_swallows_mirror_exception_does_not_break_page_render(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'Lead', 'action_key' => 'lead:form1', 'also_dispatch_capi' => true]);

        Log::shouldReceive('warning')->atLeast()->once();
        // Force CAPI mirror dispatch to throw by binding a failing ThemeActionAdapter.
        App::singleton(ThemeActionAdapter::class, function () {
            throw new \RuntimeException('forced — adapter rehydrate failure simulation');
        });

        PixelHead::flushDeferredFromController($this->mockController());

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(1, $arBlocks, 'Pixel block still emitted even when mirror throws (Tiger-Style guarantee)');
        $this->assertStringContainsString('"Lead"', $arBlocks[0]);
    }

    public function test_flushDeferredFromController_flushes_collector_state(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'A']);
        $obCollector->push(['name' => 'B']);

        PixelHead::flushDeferredFromController($this->mockController());

        $this->assertSame(0, App::make(ThemeEventCollector::class)->count(),
            'Collector is consumed exactly once per deferred-flush invocation');
    }

    private function mockController(): CmsController
    {
        return Mockery::mock(CmsController::class);
    }
}
