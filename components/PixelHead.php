<?php

namespace Logingrupa\Metapixelshopaholic\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Http\Response;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Ramsey\Uuid\Uuid;

/**
 * PixelHead — renders Meta Pixel `fbq('init')` + `fbq('track', 'PageView')`
 * with a server-generated UUIDv4 eventID. Sits ALONGSIDE the theme's existing
 * `partials/facebook_pixel.htm` (SKEL-04 / CONTEXT Area 2 Q1 — coexistence,
 * NOT replacement). Phase 5 README documents the cutover migration where
 * the theme owner removes the legacy `fbq('track', 'PageView')` line from
 * the partial once `{% component 'pixelHead' %}` is included.
 *
 * Phase 2 fires PageView client-side only. The CAPI twin lands in
 * Phase 4 FUN-01 — at which point this component will also dispatch
 * SendCapiEvent with the same event_id + event_time.
 *
 * Disabled-state contract: when PluginGuard reports the plugin is
 * disabled (missing pixel_id), `onRun()` returns early and the Twig
 * partial's `{% if sMetaPixelId is not empty and arMetaEvent is not empty %}`
 * guard renders nothing.
 *
 * Twig variables emitted on the page:
 *   - `sMetaPixelId` (string)  — the configured Pixel ID from PluginGuard.
 *   - `arMetaEvent`  (array)   — {event_id: UUIDv4, event_time: int,
 *                                 event_name: 'PageView', custom_data: []}.
 *
 * @author Logingrupa
 */
class PixelHead extends ComponentBase
{
    /**
     * Backend display metadata (lang-keyed for RainLab.Translate).
     *
     * @return array{name: string, description: string}
     */
    #[\Override]
    public function componentDetails(): array
    {
        return [
            'name' => 'logingrupa.metapixelshopaholic::lang.component.name',
            'description' => 'logingrupa.metapixelshopaholic::lang.component.description',
        ];
    }

    /**
     * Phase 2 has no configurable properties. Phase 4 FUN-01 will add
     * `event_name` override + `dispatch_capi` switch.
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function defineProperties(): array
    {
        return [];
    }

    /**
     * Page lifecycle hook. Builds the Twig variables consumed by
     * `components/pixelhead/default.htm`. No explicit return type — preserves
     * the parent `ComponentBase::onRun()` signature so Phase 4 FUN-01 can
     * return an `\Illuminate\Http\Response` from this method when CAPI
     * dispatch needs to short-circuit page rendering (e.g. redirect on a
     * critical dispatch failure). Matches sibling-plugin precedent
     * `LazyPromoBlockLoader::onRun()`.
     *
     * @return void|Response
     */
    #[\Override]
    public function onRun()
    {
        $obGuard = PluginGuard::instance();
        if ($obGuard->isDisabled()) {
            return;
        }

        $this->page['arMetaEvent'] = [
            'event_id' => Uuid::uuid4()->toString(),
            'event_time' => time(),
            'event_name' => 'PageView',
            'custom_data' => [],
        ];
        $this->page['sMetaPixelId'] = $obGuard->getPixelId();
    }
}
