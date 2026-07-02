<?php

namespace Logingrupa\Metapixel\Components;

use Cms\Classes\ComponentBase;
use Cms\Classes\Controller;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionValueResolver;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PixelHeadDeferredFlushBuffer;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\FbqScriptBuilder;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Layout-level head-tag base pixel + ThemeEventCollector consumer.
 *
 * LIFECYCLE TIMING CONTRACT (locked v2.0):
 *  - onRun() runs during execPageCycle (cms.page.start → cms.page.end window).
 *    Emits base PageView synchronously — same as Phase 5.
 *  - flushDeferredFromController() drains ThemeEventCollector at
 *    cms.page.beforeRenderPage, AFTER every page-tier component's onRun() has
 *    completed. This permits page-tier components (e.g. Shopaholic ProductPage
 *    firing shopaholic.product.open) to push to the collector during their own
 *    onRun and still be flushed in time.
 *  - The fbq() <script> blocks render via PixelHead::renderDeferredBlocks()
 *    Twig markup helper, invoked from components/pixelhead/default.htm during
 *    renderPageContents() — immediately after beforeRenderPage fires. The
 *    PixelHeadDeferredFlushBuffer singleton bridges the two phases.
 *
 * If you push to ThemeEventCollector AFTER cms.page.beforeRenderPage, the push
 * is silently dropped — emit point has passed. Push during component onRun()
 * or earlier.
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

        // AJAX postbacks run onRun without rendering the page — the browser
        // base pixel never re-fires, so a CAPI PageView dispatched here would
        // reach Meta permanently unpaired (one per Cart::onAdd click, observed
        // live 2026-07-02). Covers October AJAX (handler header), Larajax
        // (plain XHR, no October header), and any non-GET. A PageView is a
        // plain GET page render.
        if (Request::header('X_OCTOBER_REQUEST_HANDLER') !== null
            || Request::ajax()
            || ! Request::isMethod('get')
        ) {
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
            $arUserData = $this->collectRequestUserData();
            $obEvent = ThemeActionEvent::fromArray(array_merge($arUserData, [
                'name' => 'PageView',
                'action_key' => $sActionKey,
                'site_id' => $iSiteId,
            ]));

            $this->dispatchBasePageViewCapi($obAdapter, $obEvent, $sEventId, $iEventTime);

            $mTestCode = Settings::get('test_event_code', '');
            $sTestCode = is_string($mTestCode) ? $mTestCode : '';

            $this->page['pixelHeadBase'] = [
                'pixel_id' => $sPixelId,
                'pixel_id_js' => (string) json_encode($sPixelId, self::JS),
                'event_name_js' => (string) json_encode('PageView', self::JS),
                'event_id_js' => (string) json_encode($sEventId, self::JS),
                'event_time_js' => (string) json_encode($iEventTime, self::JS),
                'test_event_code_js' => $sTestCode !== '' ? (string) json_encode($sTestCode, self::JS) : null,
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
     * Collect Meta CAPI user_data fields from the request context. PixelHead
     * lives in `components/` so the PHPStan disallowed-calls ban on Request /
     * SiteManager (scoped to classes/queue|event|adapter per D-15+D-16) does
     * not apply here — request-context capture is exactly what this layer is
     * for. The values are then propagated through ThemeActionEvent.arPayload
     * so the queue-side ThemeActionAdapter.getUserData can read them without
     * touching the request itself.
     *
     * Captured: client_ip_address (Request::ip), client_user_agent
     * (Request::userAgent), fbp (_fbp cookie set by EnsureFbpFbcCookies
     * middleware), fbc (_fbc cookie set by same middleware when ?fbclid
     * present). Meta requires at least one customer-info parameter or it
     * rejects the event with HTTP 400 subcode 2804050.
     *
     * @return array<string, ?string>
     */
    protected function collectRequestUserData(): array
    {
        $sClientIp = (string) Request::ip();
        $sClientUa = (string) Request::userAgent();
        $mFbp = Cookie::get('_fbp');
        $mFbc = Cookie::get('_fbc');

        return [
            'client_ip_address' => $sClientIp !== '' ? $sClientIp : null,
            'client_user_agent' => $sClientUa !== '' ? $sClientUa : null,
            'fbp' => is_string($mFbp) && $mFbp !== '' ? $mFbp : null,
            'fbc' => is_string($mFbc) && $mFbc !== '' ? $mFbc : null,
        ];
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
     * Drain ThemeEventCollector at cms.page.beforeRenderPage and persist the
     * rendered fbq script blocks into the PixelHeadDeferredFlushBuffer
     * singleton for the Twig partial to consume via renderDeferredBlocks().
     *
     * @param  Controller  $obController  unused; reserved for future per-controller metadata reads (theme name, page var) without re-resolving the controller singleton from the container.
     */
    public static function flushDeferredFromController(Controller $obController): void
    {
        try {
            $mTestCode = Settings::get('test_event_code', '');
            $sTestCode = is_string($mTestCode) && $mTestCode !== '' ? $mTestCode : null;

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
                $arCustomData = self::extractCustomData($arEvent);
                $mEventId = $arEvent['event_id'] ?? null;
                $sEventId = is_string($mEventId) && $mEventId !== '' ? $mEventId : null;
                $arScriptBlocks[] = FbqScriptBuilder::build($sName, $arCustomData, $sEventId, $sTestCode);
                if ((bool) ($arEvent['also_dispatch_capi'] ?? false)) {
                    try {
                        self::dispatchCapiMirror($sName, $arEvent);
                    } catch (Throwable $obException) {
                        // Tiger-Style: mirror failure MUST NOT 500 the page
                        // render; log + continue.
                        Log::warning('metapixel: PixelHead CAPI mirror failed', [
                            'meta_pixel.event_name' => $sName,
                            'meta_pixel.exception' => get_class($obException),
                            'meta_pixel.message' => $obException->getMessage(),
                        ]);
                    }
                }
            }
            App::make(PixelHeadDeferredFlushBuffer::class)->setBlocks($arScriptBlocks);
        } catch (Throwable $obException) {
            // Tiger-Style boundary: deferred flush failure MUST NOT break
            // page render; the page CAN render without fbq scripts if the
            // collector is malformed.
            Log::warning('metapixel: PixelHead deferred flush failed', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }

    /**
     * Twig markup helper. Concatenates the deferred-flush buffer contents
     * with newlines for inline rendering in components/pixelhead/default.htm.
     */
    public static function renderDeferredBlocks(): string
    {
        $obBuffer = App::make(PixelHeadDeferredFlushBuffer::class);

        return implode("\n", $obBuffer->getBlocks());
    }

    /**
     * Extract the fbq custom_data subset from a collector event: prefer an
     * explicit `custom_data` array, else strip the routing/meta keys.
     *
     * @param  array<string, mixed>  $arEvent
     * @return array<string, mixed>
     */
    private static function extractCustomData(array $arEvent): array
    {
        if (isset($arEvent['custom_data']) && is_array($arEvent['custom_data'])) {
            $arCustomData = [];
            foreach ($arEvent['custom_data'] as $mKey => $mValue) {
                if (is_string($mKey)) {
                    $arCustomData[$mKey] = $mValue;
                }
            }

            return $arCustomData;
        }

        return array_diff_key($arEvent, [
            'name' => true,
            'action_key' => true,
            'also_dispatch_capi' => true,
            'site_id' => true,
            'event_id' => true,
            'product_id' => true,
        ]);
    }

    /**
     * Mirror a pushed event to the CAPI queue. Caller (flushDeferredFromController)
     * catches any throwable so page render never breaks on mirror failure.
     *
     * @param  array<string, mixed>  $arEvent
     */
    private static function dispatchCapiMirror(string $sName, array $arEvent): void
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
