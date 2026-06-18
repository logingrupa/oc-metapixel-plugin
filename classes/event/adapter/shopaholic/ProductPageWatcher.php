<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductValueResolver;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\OfferSwitchResult;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Lovata\Shopaholic\Models\Product;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Subscribes Lovata Shopaholic shopaholic.product.open event (fired inside
 * ProductPage::getElementObject after active+site guards pass). Builds
 * ViewContent payload via PayloadBuilder + UserDataHasher; injects request
 * user_data; pushes the event onto ThemeEventCollector so PixelHead's
 * deferred-flush listener emits the browser fbq script with matching
 * event_id; dispatches SendCapiEvent to mirror to CAPI. Tiger-Style
 * boundary: page render MUST NOT 500 on pixel failure. Exposes
 * dispatchForOfferSwitch(int, int): OfferSwitchResult entry point for the AJAX
 * offer-switch path (ThemeAjaxHandler::dispatchViaAdapter delegates here
 * so the ViewContent payload contract has a single owner).
 */
class ProductPageWatcher
{
    use CapturesRequestUserData;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('shopaholic.product.open', [$this, 'handle']);
    }

    /**
     * Handle Lovata's shopaholic.product.open emission. Builds ViewContent
     * payload, pushes to the theme collector, dispatches CAPI.
     */
    public function handle(Product $obProduct): void
    {
        try {
            if (PluginGuard::isDisabled()) {
                return;
            }

            $obAdapter = new ShopaholicProductAdapter;
            $obResolver = new ShopaholicProductValueResolver;
            $obBuilder = new PayloadBuilder(new UserDataHasher);

            $sEventId = Uuid::uuid4()->toString();
            $iEventTime = time();

            $arPayload = $obBuilder->buildEventPayload(
                'ViewContent',
                $obAdapter,
                $obProduct,
                $obResolver,
                $sEventId,
                $iEventTime,
                [],
            );
            $arPayload = $this->injectRequestUserData($arPayload);

            $iProductId = $this->intAttr($obProduct, 'id');

            /** @var ThemeEventCollector $obCollector */
            $obCollector = App::make(ThemeEventCollector::class);
            $obCollector->push([
                'name' => 'ViewContent',
                'action_key' => 'viewcontent:'.$iProductId.':'.$sEventId,
                'event_id' => $sEventId,
                'content_ids' => $obResolver->resolveContentIds($obProduct),
                'content_name' => $this->stringAttr($obProduct, 'name'),
                'content_type' => 'product',
                'value' => $obResolver->resolveValue($obProduct),
                'currency' => $obResolver->resolveCurrency($obProduct),
                'product_id' => $iProductId,
            ]);

            SendCapiEvent::dispatch('ViewContent', $arPayload, $obProduct, ShopaholicProductAdapter::class);
        } catch (Throwable $obException) {
            // Tiger-Style fail-safe (T-6-03 mitigation): page render MUST NOT 500
            // on pixel failure — log and skip.
            Log::warning('metapixel: ProductPageWatcher emission failed', [
                'meta_pixel.product_id' => $obProduct->getAttribute('id'),
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }

    /**
     * Offer-switch entry point. Re-fires ViewContent with a fresh UUIDv4
     * event_id and content_ids containing SKU-{pid}-{oid_new}. Returns an
     * OfferSwitchResult carrying that event_id plus the browser-facing
     * ViewContent custom_data so the AJAX caller
     * (ThemeAjaxHandler::dispatchViaAdapter) can render the fbq script without
     * re-deriving content (the browser fbq's 4th-arg eventID dedups with the
     * CAPI mirror).
     *
     * action_key shape: viewcontent:{pid}:{oid_new}:{event_id} — server
     * appends the generated event_id to the wire-format action_key BEFORE
     * collector push. The EventLog UNIQUE race-fence on (subject_type,
     * subject_id, event_name, channel, site_id) collision-detects per-pageload
     * duplicates inside SendCapiEvent::handle; the action_key string is
     * informational only.
     *
     * Tiger-Style: throws on disabled-state / invalid input / missing
     * subject. The AJAX boundary (dispatchViaAdapter) translates each
     * throw into a 422/404/500 JsonResponse — page render is not involved
     * here so we surface failures to the JS soft-gate instead of swallowing.
     *
     * @return OfferSwitchResult server event_id + browser ViewContent custom_data
     */
    public function dispatchForOfferSwitch(int $iProductId, int $iOfferId): OfferSwitchResult
    {
        if ($iProductId <= 0 || $iOfferId <= 0) {
            throw new \InvalidArgumentException(
                'ProductPageWatcher::dispatchForOfferSwitch requires positive iProductId and iOfferId',
            );
        }

        if (PluginGuard::isDisabled()) {
            throw new \RuntimeException('metapixel disabled — offer-switch ViewContent suppressed');
        }

        $obAdapter = new ShopaholicProductAdapter;
        $obResolver = new ShopaholicProductValueResolver;
        $obBuilder = new PayloadBuilder(new UserDataHasher);

        $obProduct = $obAdapter->loadSubject($iProductId, ['offer_id' => $iOfferId]);
        if (! $obProduct instanceof Product) {
            throw new \RuntimeException(
                'ProductPageWatcher::dispatchForOfferSwitch: product not found or inactive',
            );
        }

        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = time();

        $arPayload = $obBuilder->buildEventPayload(
            'ViewContent',
            $obAdapter,
            $obProduct,
            $obResolver,
            $sEventId,
            $iEventTime,
            [],
        );

        $arForcedContentIds = ['SKU-'.$iProductId.'-'.$iOfferId];

        $mData = $arPayload['data'] ?? null;
        if (is_array($mData) && isset($mData[0]) && is_array($mData[0])) {
            $mEnvelope = $mData[0];
            $mCustomData = $mEnvelope['custom_data'] ?? null;
            $arCustomData = is_array($mCustomData) ? $mCustomData : [];
            $arCustomData['content_ids'] = $arForcedContentIds;
            $mEnvelope['custom_data'] = $arCustomData;
            $mData[0] = $mEnvelope;
            $arPayload['data'] = $mData;
        }

        $arPayload = $this->injectRequestUserData($arPayload);

        $arCustomData = [
            'content_ids' => $arForcedContentIds,
            'content_name' => $this->stringAttr($obProduct, 'name'),
            'content_type' => 'product',
            'value' => $obResolver->resolveValue($obProduct),
            'currency' => $obResolver->resolveCurrency($obProduct),
        ];

        /** @var ThemeEventCollector $obCollector */
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(array_merge($arCustomData, [
            'name' => 'ViewContent',
            'action_key' => 'viewcontent:'.$iProductId.':'.$iOfferId.':'.$sEventId,
            'event_id' => $sEventId,
            'product_id' => $iProductId,
            'offer_id' => $iOfferId,
        ]));

        SendCapiEvent::dispatch('ViewContent', $arPayload, $obProduct, ShopaholicProductAdapter::class);

        return new OfferSwitchResult($sEventId, $arCustomData);
    }

    private function intAttr(Product $obProduct, string $sAttr): int
    {
        $mValue = $obProduct->getAttribute($sAttr);

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    private function stringAttr(Product $obProduct, string $sAttr): string
    {
        $mValue = $obProduct->getAttribute($sAttr);

        return is_string($mValue) ? $mValue : '';
    }
}
