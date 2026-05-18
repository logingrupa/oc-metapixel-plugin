<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use ArrayAccess;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Components\PixelHead;
use Logingrupa\Metapixel\Tests\Fixtures\Components\PixelHeadExceptionFixture;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * THEM-07 — PixelHead consumes ThemeEventCollector via flush() and emits one
 * <script>fbq("track", ...)</script> per pushed event. also_dispatch_capi:true
 * mirrors to the CAPI queue via SendCapiEvent::dispatch. Mirror failures NEVER
 * break page render (Tiger-Style, T-03-08-07 mitigation).
 */
#[Group('adapter')]
final class PixelHeadTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::singleton(ThemeEventCollector::class);
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        Mockery::close();
        parent::tearDown();
    }

    public function test_onRun_emits_one_script_block_per_pushed_event(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'ViewContent', 'value' => 12.5]);
        $obCollector->push(['name' => 'AddToCart']);
        $obCollector->push(['name' => 'Search', 'query' => 'lipstick']);

        $arBlocks = $this->runComponent(new PixelHead);

        $this->assertCount(3, $arBlocks);
        $this->assertStringContainsString('fbq("track"', $arBlocks[0]);
        $this->assertStringContainsString('"ViewContent"', $arBlocks[0]);
        $this->assertStringContainsString('"AddToCart"', $arBlocks[1]);
        $this->assertStringContainsString('"Search"', $arBlocks[2]);
    }

    public function test_onRun_skips_event_with_missing_or_empty_name(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['value' => 12.5]);
        $obCollector->push(['name' => '', 'value' => 1]);
        $obCollector->push(['name' => 'ValidOne']);

        $arBlocks = $this->runComponent(new PixelHead);

        $this->assertCount(1, $arBlocks, 'Collector drops missing/empty-name pushes; PixelHead emits one block for the valid event');
        $this->assertStringContainsString('"ValidOne"', $arBlocks[0]);
    }

    public function test_onRun_mirrors_to_capi_when_also_dispatch_capi_is_true(): void
    {
        Bus::fake();
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'Lead', 'action_key' => 'lead:form1', 'also_dispatch_capi' => true]);

        $arBlocks = $this->runComponent(new PixelHead);

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'Lead'
                && $obJob->sAdapterClass === ThemeActionAdapter::class;
        });
        $this->assertCount(1, $arBlocks, 'Pixel block emitted even when CAPI mirror dispatches');
    }

    public function test_onRun_does_not_mirror_when_also_dispatch_capi_absent_or_false(): void
    {
        Bus::fake();
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'PageView']);

        $arBlocks = $this->runComponent(new PixelHead);

        Bus::assertNotDispatched(SendCapiEvent::class);
        $this->assertCount(1, $arBlocks);
    }

    public function test_onRun_swallows_mirror_exception_does_not_break_page_render(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'Lead', 'action_key' => 'lead:form1', 'also_dispatch_capi' => true]);

        Log::shouldReceive('warning')->once();

        $obFixture = new PixelHeadExceptionFixture;
        $arBlocks = $this->runComponent($obFixture);

        $this->assertCount(1, $arBlocks, 'Pixel block still emitted even when mirror throws (Tiger-Style guarantee)');
        $this->assertStringContainsString('"Lead"', $arBlocks[0]);
    }

    public function test_onRun_flushes_collector_state(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'A']);
        $obCollector->push(['name' => 'B']);

        $this->runComponent(new PixelHead);

        $this->assertSame(0, App::make(ThemeEventCollector::class)->count(),
            'Collector is consumed exactly once per PixelHead render');
    }

    /**
     * @return list<string>
     */
    private function runComponent(PixelHead $obComponent): array
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
        $obReflection = new ReflectionProperty(PixelHead::class, 'page');
        $obReflection->setAccessible(true);
        $obReflection->setValue($obComponent, $obFakePage);
        $obComponent->onRun();
        $mBlocks = $obFakePage->vars['pixelHeadBlocks'] ?? [];

        return is_array($mBlocks) ? array_values($mBlocks) : [];
    }
}
