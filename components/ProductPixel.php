<?php

namespace Logingrupa\Metapixel\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;

/**
 * PDP-level Meta Pixel browser layer for Phase 6 ViewContent funnel.
 *
 * Renders two inline <script> blocks via Twig page vars:
 *  (1) productPixelProductGlobalJs — window.__metapixelProduct = {id: N}
 *      server-injected global, sourced from ThemeEventCollector's most recent
 *      product_id push by ProductPageWatcher.
 *  (2) productPixelOfferSwitchJs — delegated change-listener on document for
 *      [name="offer_id"] that posts to Metapixel::onFireEvent via October's
 *      native $.request (hybrid alias subject_type='shopaholic.product') and
 *      injects the returned script as an executable fragment.
 *
 * Disabled-state contract: PluginGuard::isDisabled() OR no product_id in
 * collector → both Twig vars null, default.htm renders nothing.
 *
 * Soft-gate (T-6-06 mitigation): the JS only fires when window.__metapixelProduct
 * is set, which only happens on PDP renders. Cart-modal [name="offer_id"]
 * outside PDP cannot trigger ViewContent.
 */
final class ProductPixel extends ComponentBase
{
    /** @return array{name: string, description: string} */
    public function componentDetails(): array
    {
        return [
            'name' => 'ProductPixel',
            'description' => 'PDP-level Meta Pixel ViewContent + offer-switch trigger. Place inside the layout/page hosting Shopaholic [ProductPage].',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function defineProperties(): array
    {
        return [];
    }

    public function onRun(): void
    {
        $this->page['productPixelProductGlobalJs'] = null;
        $this->page['productPixelOfferSwitchJs'] = null;

        if (PluginGuard::isDisabled()) {
            return;
        }

        // Offer-switch JS attaches unconditionally when the plugin is enabled.
        // The JS body self-no-ops when window.__metapixelProduct is absent
        // (Pitfall 8 soft-gate — T-6-06 mitigation).
        $this->page['productPixelOfferSwitchJs'] = $this->buildOfferSwitchJs();

        /** @var ThemeEventCollector $obCollector */
        $obCollector = App::make(ThemeEventCollector::class);
        $iProductId = $this->findProductId($obCollector->peek());
        if ($iProductId > 0) {
            // Direct sprintf is XSS-safe — $iProductId is integer cast above.
            $this->page['productPixelProductGlobalJs'] = sprintf(
                '<script>window.__metapixelProduct={id:%d};</script>',
                $iProductId,
            );
        }
    }

    /**
     * Scan the collector's events for the first one whose product_id is a
     * positive numeric value.
     *
     * @param  list<array<string, mixed>>  $arEvents
     */
    private function findProductId(array $arEvents): int
    {
        foreach ($arEvents as $arEvent) {
            $mProductId = $arEvent['product_id'] ?? null;
            if (is_numeric($mProductId) && (int) $mProductId > 0) {
                return (int) $mProductId;
            }
        }

        return 0;
    }

    /**
     * Build the inline offer-switch JS block. Posts via October's native
     * $.request (the theme ships jQuery + the October AJAX framework, not
     * Larajax) and injects the returned <script> via createContextualFragment
     * so the fbq() call actually executes (innerHTML-parsed scripts do not).
     * Wire-format action_key is two-segment (viewcontent:{pid}:{oid}) — the
     * server appends the freshly-minted event_id to produce the canonical
     * four-segment viewcontent:{pid}:{oid}:{eid} shape before EventLog insert
     * (per CONTEXT.md Claude's Discretion). Nowdoc (no $ interpolation).
     */
    private function buildOfferSwitchJs(): string
    {
        return <<<'JS'
<script>
(function () {
    if (window.__metapixelProductPixelInit) return;
    window.__metapixelProductPixelInit = true;
    document.addEventListener('change', function (ev) {
        if (!window.__metapixelProduct || !window.__metapixelProduct.id) return;
        var el = ev.target;
        if (!el || el.name !== 'offer_id') return;
        var iProductId = parseInt(window.__metapixelProduct.id, 10);
        var iOfferId   = parseInt(el.value || '0', 10);
        if (!iProductId || !iOfferId) return;
        if (typeof $ === 'undefined' || !$.request) return;
        $.request('Metapixel::onFireEvent', {
            data: {
                name: 'ViewContent',
                subject_type: 'shopaholic.product',
                subject_id: iProductId,
                offer_id: iOfferId,
                action_key: 'viewcontent:' + iProductId + ':' + iOfferId
            },
            success: function (oResp) {
                if (oResp && oResp.script) {
                    var oRange = document.createRange();
                    oRange.selectNode(document.body);
                    var oFrag = oRange.createContextualFragment(oResp.script);
                    document.body.appendChild(oFrag);
                }
            }
        });
    }, false);
})();
</script>
JS;
    }
}
