<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use ArrayAccess;
use Cms\Classes\Controller as CmsController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PixelHeadDeferredFlushBuffer;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Components\PixelHead;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * VIEW-01 — PixelHead drains ThemeEventCollector at cms.page.beforeRenderPage
 * via flushDeferredFromController (NOT onRun). Base PageView stays in onRun.
 * action_key shape unchanged. test_event_code flows to fbq base script block.
 * Pushed events with event_id render fbq's 4th eventID argument.
 */
#[Group('adapter')]
final class PixelHeadDeferredFlushTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelEventLogTable)->up();
        App::singleton(ThemeEventCollector::class);
        App::singleton(PixelHeadDeferredFlushBuffer::class);
        PluginGuard::reset();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => 'TEST-PIXEL-1', 'capi_access_token' => 'TEST-TOKEN-1']);
        Settings::clearInternalCache();
        PluginGuard::reset();
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        App::forgetInstance(PixelHeadDeferredFlushBuffer::class);
        PluginGuard::reset();
        (new CreateMetapixelEventLogTable)->down();
        Mockery::close();
        parent::tearDown();
    }

    public function test_emit_collected_events_flushes_on_cms_page_beforeRenderPage_not_onRun(): void
    {
        Bus::fake();
        App::make(ThemeEventCollector::class)->push([
            'name' => 'ViewContent',
            'event_id' => 'eid-001',
            'site_id' => 1,
            'content_ids' => ['SKU-42'],
        ]);

        PixelHead::flushDeferredFromController(Mockery::mock(CmsController::class));

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(1, $arBlocks, 'one deferred block emitted from the pushed event');
        $this->assertStringContainsString('fbq("track", "ViewContent"', $arBlocks[0]);
        $this->assertStringContainsString('eventID: "eid-001"', $arBlocks[0]);
        $this->assertStringContainsString('SKU-42', $arBlocks[0]);

        // onRun() MUST NOT emit pixelHeadBlocks — base pixel only.
        $arPage = $this->runComponent(new PixelHead);
        $this->assertArrayNotHasKey('pixelHeadBlocks', $arPage['raw'], 'onRun no longer populates pixelHeadBlocks');
        $this->assertNotNull($arPage['pixelHeadBase'], 'onRun still emits base PageView');
    }

    public function test_collector_push_between_onRun_and_beforeRenderPage_is_flushed(): void
    {
        Bus::fake();

        // Component pass: onRun runs first; collector empty at this point.
        $arPage = $this->runComponent(new PixelHead);
        $this->assertNotNull($arPage['pixelHeadBase']);

        // Simulate page-tier ProductPage pushing AFTER onRun.
        App::make(ThemeEventCollector::class)->push([
            'name' => 'ViewContent',
            'event_id' => 'eid-late',
            'content_ids' => ['SKU-99'],
        ]);

        // Deferred-flush listener fires at cms.page.beforeRenderPage AFTER all onRuns.
        PixelHead::flushDeferredFromController(Mockery::mock(CmsController::class));

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(1, $arBlocks, 'late push still flushed at beforeRenderPage');
        $this->assertStringContainsString('"ViewContent"', $arBlocks[0]);
        $this->assertStringContainsString('eventID: "eid-late"', $arBlocks[0]);
        $this->assertStringContainsString('SKU-99', $arBlocks[0]);
    }

    public function test_base_pageview_action_key_shape_unchanged(): void
    {
        Bus::fake();

        $this->runComponent(new PixelHead);

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            if (! $obJob->obSubject instanceof ThemeActionEvent) {
                return false;
            }

            // base:pageview:{site_id}:{UUIDv4}
            return (bool) preg_match(
                '/^base:pageview:\d+:[0-9a-f-]{36}$/',
                $obJob->obSubject->sActionKey,
            );
        });
    }

    public function test_test_event_code_flows_to_fbq_script_block(): void
    {
        Bus::fake();
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PX',
            'capi_access_token' => 'TOK',
            'test_event_code' => 'TEST123',
        ]);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $arPage = $this->runComponent(new PixelHead);

        $this->assertNotNull($arPage['pixelHeadBase']);
        $this->assertSame('"TEST123"', $arPage['pixelHeadBase']['test_event_code_js']);
    }

    public function test_deferred_blocks_inject_test_event_code_when_set(): void
    {
        Bus::fake();
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PX',
            'capi_access_token' => 'TOK',
            'test_event_code' => 'TEST123',
        ]);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push([
            'name' => 'ViewContent',
            'event_id' => 'eid-001',
            'site_id' => 1,
            'content_ids' => ['SKU-42'],
        ]);
        $obCollector->push([
            'name' => 'PageView',
            'site_id' => 1,
        ]);

        PixelHead::flushDeferredFromController(Mockery::mock(CmsController::class));

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(2, $arBlocks, 'two deferred blocks emitted');

        // event_id branch carries BOTH eventID and test_event_code.
        $this->assertStringContainsString('eventID: "eid-001"', $arBlocks[0]);
        $this->assertStringContainsString('test_event_code: "TEST123"', $arBlocks[0]);

        // no-event_id branch carries test_event_code in a 4th-arg object, no eventID.
        $this->assertStringContainsString('test_event_code: "TEST123"', $arBlocks[1]);
        $this->assertStringContainsString('fbq("track"', $arBlocks[1]);
        $this->assertStringNotContainsString('eventID', $arBlocks[1]);
    }

    public function test_deferred_blocks_omit_test_event_code_when_unset(): void
    {
        Bus::fake();

        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push([
            'name' => 'ViewContent',
            'event_id' => 'eid-002',
            'site_id' => 1,
            'content_ids' => ['SKU-7'],
        ]);
        $obCollector->push([
            'name' => 'PageView',
            'site_id' => 1,
        ]);

        PixelHead::flushDeferredFromController(Mockery::mock(CmsController::class));

        $arBlocks = App::make(PixelHeadDeferredFlushBuffer::class)->getBlocks();
        $this->assertCount(2, $arBlocks, 'two deferred blocks emitted');

        // event_id branch unchanged: {eventID: X}, no test_event_code.
        $this->assertStringContainsString('eventID: "eid-002"', $arBlocks[0]);
        $this->assertStringNotContainsString('test_event_code', $arBlocks[0]);

        // no-event_id branch unchanged: 3-arg call, no 4th-arg object, no test_event_code.
        $this->assertStringNotContainsString('test_event_code', $arBlocks[1]);
        $this->assertStringNotContainsString('eventID', $arBlocks[1]);
    }

    /**
     * @return array{pixelHeadBase: ?array<string, mixed>, raw: array<string, mixed>}
     */
    private function runComponent(PixelHead $obComponent): array
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
        $obReflection = new ReflectionProperty(PixelHead::class, 'page');
        $obReflection->setAccessible(true);
        $obReflection->setValue($obComponent, $obFakePage);
        $obComponent->onRun();

        $mBase = $obFakePage->vars['pixelHeadBase'] ?? null;

        return [
            'pixelHeadBase' => is_array($mBase) ? $mBase : null,
            'raw' => $obFakePage->vars,
        ];
    }
}
