<?php

namespace Logingrupa\Metapixel\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionValueResolver;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * ThemeEventCollector consumer — emits one fbq('track', ...) <script> block
 * per accumulator entry. Optional also_dispatch_capi:true on a pushed event
 * mirrors to CAPI via SendCapiEvent::dispatch. Mirror failures NEVER break
 * page render (Tiger-Style: log + continue).
 */
class PixelHead extends ComponentBase
{
    private const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;

    /** @return array{name: string, description: string} */
    public function componentDetails(): array
    {
        return ['name' => 'PixelHead', 'description' => 'Renders accumulated theme-side pushEvent calls as fbq() <script> blocks. Place inside layout <head>.'];
    }

    /** @return array<string, array<string, mixed>> */
    public function defineProperties(): array
    {
        return [];
    }

    public function onRun(): void
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
     * Mirror a pushed event to the CAPI queue. Caller (onRun) catches any
     * throwable so page render never breaks on mirror failure.
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
