<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use ArrayAccess;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Components\PixelHead;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Logingrupa\Metapixel\Updates\CreateMetapixelEventLogTable;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;

/**
 * D-04 / Phase 5 base-pixel emission — PixelHead::onRun MUST emit the
 * fbevents.js loader + fbq('init', pixel_id) + base fbq('track', 'PageView')
 * + <noscript> Pixel + matching CAPI PageView dispatch on every page-load
 * where the plugin is enabled. Phase 3 (THEM-07) re-derive lost this
 * responsibility; this suite re-asserts it.
 */
#[Group('adapter')]
final class PixelHeadBasePixelTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new CreateMetapixelEventLogTable)->up();
        App::singleton(ThemeEventCollector::class);
        PluginGuard::reset();
    }

    protected function tearDown(): void
    {
        App::forgetInstance(ThemeEventCollector::class);
        PluginGuard::reset();
        (new CreateMetapixelEventLogTable)->down();
        parent::tearDown();
    }

    public function test_emits_base_pixel_when_pixel_id_configured(): void
    {
        Bus::fake();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '1234567890', 'capi_access_token' => 'TOKEN-X']);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $arPage = $this->runComponent(new PixelHead);

        $this->assertNotNull($arPage['pixelHeadBase'], 'pixelHeadBase Twig var MUST populate when pixel_id is configured');
        $this->assertSame('1234567890', $arPage['pixelHeadBase']['pixel_id']);
        $this->assertSame('"1234567890"', $arPage['pixelHeadBase']['pixel_id_js']);
        $this->assertSame('"PageView"', $arPage['pixelHeadBase']['event_name_js']);
        $this->assertSame('1234567890', $arPage['pixelHeadBase']['noscript_pixel_id']);
        $this->assertSame('PageView', $arPage['pixelHeadBase']['noscript_event_name']);
        $this->assertMatchesRegularExpression(
            '/^"[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"$/i',
            $arPage['pixelHeadBase']['event_id_js'],
            'event_id_js MUST be a JSON-encoded UUIDv4'
        );
        $this->assertMatchesRegularExpression(
            '/^"\d{10}"$|^\d{10}$/',
            $arPage['pixelHeadBase']['event_time_js'],
            'event_time_js MUST be unix timestamp (10 digits, JSON-encoded)'
        );
    }

    public function test_dispatches_capi_pageview_with_same_event_id_as_browser(): void
    {
        Bus::fake();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '1234567890', 'capi_access_token' => 'TOKEN-X']);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $arPage = $this->runComponent(new PixelHead);

        $sBrowserEventId = trim($arPage['pixelHeadBase']['event_id_js'], '"');

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob) use ($sBrowserEventId): bool {
            $arEventRecord = $obJob->arPayload['data'][0] ?? [];

            return $obJob->sEventName === 'PageView'
                && $obJob->sAdapterClass === ThemeActionAdapter::class
                && ($arEventRecord['event_id'] ?? null) === $sBrowserEventId
                && ($arEventRecord['event_name'] ?? null) === 'PageView'
                && $obJob->obSubject instanceof ThemeActionEvent
                && str_starts_with($obJob->obSubject->sActionKey, 'base:pageview');
        });
    }

    public function test_skips_emission_when_plugin_guard_disabled(): void
    {
        // pixel_id NOT set → PluginGuard treats plugin as disabled
        Bus::fake();

        $arPage = $this->runComponent(new PixelHead);

        $this->assertNull($arPage['pixelHeadBase'], 'pixelHeadBase MUST be null when PluginGuard reports disabled');
        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    public function test_skips_emission_when_settings_pixel_id_empty(): void
    {
        Bus::fake();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '', 'capi_access_token' => '']);
        Settings::clearInternalCache();
        PluginGuard::reset();

        $arPage = $this->runComponent(new PixelHead);

        $this->assertNull($arPage['pixelHeadBase'], 'Empty pixel_id → no base block, no CAPI dispatch');
        Bus::assertNotDispatched(SendCapiEvent::class);
    }

    public function test_collector_flush_still_runs_alongside_base_pixel(): void
    {
        Bus::fake();
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '1234567890', 'capi_access_token' => 'TOKEN-X']);
        Settings::clearInternalCache();
        PluginGuard::reset();
        App::make(ThemeEventCollector::class)->push(['name' => 'ViewContent', 'value' => 99.99]);

        $arPage = $this->runComponent(new PixelHead);

        $this->assertNotNull($arPage['pixelHeadBase'], 'Base block emits');
        $this->assertCount(1, $arPage['pixelHeadBlocks'], 'Collector flush still runs');
        $this->assertStringContainsString('"ViewContent"', $arPage['pixelHeadBlocks'][0]);
    }

    public function test_dispatch_failure_does_not_break_page_render(): void
    {
        Settings::clearInternalCache();
        Settings::set(['pixel_id' => '1234567890', 'capi_access_token' => 'TOKEN-X']);
        Settings::clearInternalCache();
        PluginGuard::reset();
        // Force SendCapiEvent::dispatch path to throw by removing the queue
        // connection binding. Bus::fake() also catches, but a raw throw from
        // the dispatch call site exercises the Tiger-Style boundary catch.
        Log::shouldReceive('warning')->atLeast()->once();
        App::singleton(ThemeActionAdapter::class, function () {
            throw new \RuntimeException('forced — adapter rehydrate failure simulation');
        });

        $arPage = $this->runComponent(new PixelHead);

        $this->assertNull($arPage['pixelHeadBase'], 'Failure path leaves pixelHeadBase null (fail-safe)');
    }

    /**
     * @return array{pixelHeadBase: ?array<string, mixed>, pixelHeadBlocks: array<int, string>}
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

        return [
            'pixelHeadBase' => $obFakePage->vars['pixelHeadBase'] ?? null,
            'pixelHeadBlocks' => is_array($obFakePage->vars['pixelHeadBlocks'] ?? null)
                ? array_values($obFakePage->vars['pixelHeadBlocks'])
                : [],
        ];
    }
}
