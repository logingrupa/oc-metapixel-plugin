<?php

namespace Logingrupa\Metapixel\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionValueResolver;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Layout-level head-tag base pixel + ThemeEventCollector consumer.
 *
 * Two responsibilities, one component (D-04 lock — Phase 5 design):
 *  1. Base pixel — fbevents.js loader + fbq('init', pixel_id) + base
 *     fbq('track', 'PageView', {}, {eventID}) + <noscript> img + matching
 *     CAPI PageView dispatch. Fires once per page-load.
 *  2. Theme-event accumulator — flushes ThemeEventCollector and emits one
 *     fbq('track', ...) <script> block per pushed event. Optional
 *     also_dispatch_capi:true on a pushed event mirrors to CAPI via
 *     SendCapiEvent::dispatch. Mirror failures NEVER break page render
 *     (Tiger-Style: log + continue).
 *
 * Disabled-state contract: PluginGuard::isDisabled() true OR Settings
 * lookup returns empty pixel_id → no base block, no CAPI dispatch, no
 * collector flush leaking into Twig. Page renders cleanly.
 */
class PixelHead extends ComponentBase
{
    private const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;

    private const BASE_ACTION_KEY_PREFIX = 'base:pageview';

    /** @return array{name: string, description: string} */
    public function componentDetails(): array
    {
        return ['name' => 'PixelHead', 'description' => 'Head-tag base pixel (fbevents.js loader + fbq init + PageView + noscript) plus ThemeEventCollector fbq() emitter. Place inside layout <head>.'];
    }

    /** @return array<string, array<string, mixed>> */
    public function defineProperties(): array
    {
        return [];
    }

    public function onRun(): void
    {
        $this->emitBasePixel();
        $this->emitCollectedEvents();
    }

    /**
     * Resolve per-site credentials, generate event_id, emit CAPI twin,
     * stash Twig variables for default.htm to render the browser-side
     * loader + init + track + noscript. Fail-safe: every failure path
     * leaves $this->page['pixelHeadBase'] = null so the template renders
     * nothing.
     */
    protected function emitBasePixel(): void
    {
        $this->page['pixelHeadBase'] = null;

        if (PluginGuard::isDisabled()) {
            return;
        }

        try {
            $obAdapter = App::make(ThemeActionAdapter::class);
            $obProbeEvent = ThemeActionEvent::fromArray([
                'name' => 'PageView',
                'action_key' => self::BASE_ACTION_KEY_PREFIX,
            ]);
            $iSiteId = $obAdapter->getSiteId($obProbeEvent);
            $arCreds = Settings::lookupForSite($iSiteId);
            $sPixelId = $arCreds['pixel_id'];
            if ($sPixelId === '') {
                return;
            }

            $sEventId = Uuid::uuid4()->toString();
            $iEventTime = time();
            // PageView is per-pageload, not per-subject. action_key MUST be
            // request-unique so the EventLog UNIQUE race-fence
            // (subject_type, subject_id, event_name, channel, site_id) does
            // not silently drop every row after the first via INSERT IGNORE.
            // The event_id (UUIDv4) makes crc32 per-request unique.
            $sActionKey = self::BASE_ACTION_KEY_PREFIX.':'.($iSiteId ?? 0).':'.$sEventId;
            $obEvent = ThemeActionEvent::fromArray([
                'name' => 'PageView',
                'action_key' => $sActionKey,
                'site_id' => $iSiteId,
            ]);

            $this->dispatchBasePageViewCapi($obAdapter, $obEvent, $sEventId, $iEventTime);

            $this->page['pixelHeadBase'] = [
                'pixel_id' => $sPixelId,
                'pixel_id_js' => (string) json_encode($sPixelId, self::JS),
                'event_name_js' => (string) json_encode('PageView', self::JS),
                'event_id_js' => (string) json_encode($sEventId, self::JS),
                'event_time_js' => (string) json_encode($iEventTime, self::JS),
                'noscript_pixel_id' => rawurlencode($sPixelId),
                'noscript_event_name' => rawurlencode('PageView'),
            ];
        } catch (Throwable $obException) {
            // Tiger-Style boundary fail-safe: base-pixel emission failure
            // MUST NOT break page render. Log + skip.
            Log::warning('metapixel: PixelHead base PageView emission failed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
            $this->page['pixelHeadBase'] = null;
        }
    }

    /**
     * Mirror base PageView to CAPI queue. Caller's try/catch wraps this so
     * dispatch failure logs + continues without breaking page render.
     */
    protected function dispatchBasePageViewCapi(
        ThemeActionAdapter $obAdapter,
        ThemeActionEvent $obEvent,
        string $sEventId,
        int $iEventTime,
    ): void {
        $obResolver = new ThemeActionValueResolver;
        $obBuilder = new PayloadBuilder(new UserDataHasher);
        $arPayload = $obBuilder->buildEventPayload('PageView', $obAdapter, $obEvent, $obResolver, $sEventId, $iEventTime, []);
        SendCapiEvent::dispatch('PageView', $arPayload, $obEvent, ThemeActionAdapter::class);
    }

    /**
     * Flush ThemeEventCollector → render one fbq('track', ...) script per
     * pushed event. Existing behavior unchanged (this is the original
     * onRun body extracted into its own method).
     */
    protected function emitCollectedEvents(): void
    {
        /** @var ThemeEventCollector $obCollector */
        $obCollector = App::make(ThemeEventCollector::class);
        $arEvents = $obCollector->flush();
        $arScriptBlocks = [];
        foreach ($arEvents as $arEvent) {
            $mNameRaw = $arEvent['name'] ?? null;
            if (! is_string($mNameRaw) || $mNameRaw === '') {
                continue;
            }
            $sName = $mNameRaw;
            $arCustomData = isset($arEvent['custom_data']) && is_array($arEvent['custom_data'])
                ? $arEvent['custom_data']
                : array_diff_key($arEvent, ['name' => true, 'action_key' => true, 'also_dispatch_capi' => true, 'site_id' => true]);
            $sNameJson = (string) json_encode($sName, self::JS);
            $sDataJson = (string) json_encode($arCustomData, self::JS);
            $arScriptBlocks[] = sprintf('<script>fbq("track", %s, %s);</script>', $sNameJson, $sDataJson);
            if ((bool) ($arEvent['also_dispatch_capi'] ?? false)) {
                try {
                    $this->dispatchCapiMirror($sName, $arEvent);
                } catch (Throwable $obException) {
                    Log::warning('metapixel: PixelHead CAPI mirror failed', [
                        'meta_pixel.event_name' => $sName,
                        'meta_pixel.exception' => get_class($obException),
                        'meta_pixel.message' => $obException->getMessage(),
                    ]);
                }
            }
        }
        $this->page['pixelHeadBlocks'] = $arScriptBlocks;
    }

    /**
     * Mirror a pushed event to the CAPI queue. Caller (emitCollectedEvents)
     * catches any throwable so page render never breaks on mirror failure.
     *
     * @param  array<string, mixed>  $arEvent
     */
    protected function dispatchCapiMirror(string $sName, array $arEvent): void
    {
        $arEventArgs = $arEvent;
        if (! isset($arEventArgs['action_key']) || ! is_string($arEventArgs['action_key']) || $arEventArgs['action_key'] === '') {
            $arEventArgs['action_key'] = 'theme:'.$sName;
        }
        $obEvent = ThemeActionEvent::fromArray($arEventArgs);
        $obAdapter = App::make(ThemeActionAdapter::class);
        $obResolver = new ThemeActionValueResolver;
        $obBuilder = new PayloadBuilder(new UserDataHasher);
        $sEventId = Uuid::uuid4()->toString();
        $arPayload = $obBuilder->buildEventPayload($sName, $obAdapter, $obEvent, $obResolver, $sEventId, time(), []);
        SendCapiEvent::dispatch($sName, $arPayload, $obEvent, ThemeActionAdapter::class);
    }
}
