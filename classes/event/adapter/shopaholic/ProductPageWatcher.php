<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductValueResolver;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Helper\RequestKind;
use Logingrupa\Metapixel\Classes\Meta\OfferSwitchResult;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Subscribes Lovata Shopaholic shopaholic.product.open event (fired inside
 * ProductPage::getElementObject after active+site guards pass). Builds
 * ViewContent payload via PayloadBuilder + UserDataHasher; injects request
 * user_data; pushes the event onto ThemeEventCollector so PixelHead's
 * deferred-flush listener emits the browser fbq script with matching
 * event_id; dispatches SendCapiEvent to mirror to CAPI via a per-view
 * ThemeActionEvent subject (action_key viewcontent:{pid}[:{oid}]:{eid}) so the
 * EventLog race-fence keys on the view, not the product. Tiger-Style
 * boundary: page render MUST NOT 500 on pixel failure. Exposes
 * dispatchForOfferSwitch(int, int): OfferSwitchResult entry point for the AJAX
 * offer-switch path (ThemeAjaxHandler::dispatchViaAdapter delegates here
 * so the ViewContent payload contract has a single owner).
 */
class ProductPageWatcher
{
    use CapturesRequestUserData;

    /**
     * Product ids already emitted during the current request. Multiple page
     * components can fire shopaholic.product.open for one render (observed
     * live: Lovata ProductPage + Logingrupa CustomProductPage both fire it),
     * and each emission would otherwise produce its own event_id + CAPI event.
     * Static state resets per PHP-FPM request; long-running runtimes must call
     * resetViewGuard() at request boundaries.
     *
     * @var array<int, true>
     */
    private static array $arEmittedProductIds = [];

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('shopaholic.product.open', [$this, 'handle']);
    }

    /** Clear the per-request duplicate-emission guard (request boundary / tests). */
    public static function resetViewGuard(): void
    {
        self::$arEmittedProductIds = [];
    }

    /**
     * Handle Lovata's shopaholic.product.open emission. Builds ViewContent
     * payload, pushes to the theme collector, dispatches CAPI. Emits at most
     * once per product per request — duplicate component emissions are dropped.
     */
    public function handle(Product $obProduct): void
    {
        try {
            if (PluginGuard::isDisabled()) {
                return;
            }

            // AJAX postbacks re-fire shopaholic.product.open without rendering
            // a page — no browser fbq twin can exist there (see RequestKind).
            if (! RequestKind::isPageRender()) {
                return;
            }

            $iGuardProductId = $this->intAttr($obProduct, 'id');
            if (isset(self::$arEmittedProductIds[$iGuardProductId])) {
                return;
            }
            self::$arEmittedProductIds[$iGuardProductId] = true;

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
            $sActionKey = 'viewcontent:'.$iProductId.':'.$sEventId;

            /** @var ThemeEventCollector $obCollector */
            $obCollector = App::make(ThemeEventCollector::class);
            $obCollector->push([
                'name' => 'ViewContent',
                'action_key' => $sActionKey,
                'event_id' => $sEventId,
                'content_ids' => $obResolver->resolveContentIds($obProduct),
                'content_name' => $this->stringAttr($obProduct, 'name'),
                'content_type' => 'product',
                'value' => $obResolver->resolveValue($obProduct),
                'currency' => $obResolver->resolveCurrency($obProduct),
                'product_id' => $iProductId,
            ]);

            $obDispatchEvent = $this->makeDispatchEvent($sActionKey, $obAdapter->getSiteId($obProduct));
            SendCapiEvent::dispatch('ViewContent', $arPayload, $obDispatchEvent, ThemeActionAdapter::class);
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
     * collector push. The dispatch wraps that action_key in a ThemeActionEvent
     * so the EventLog UNIQUE race-fence on (subject_type, subject_id,
     * event_name, channel, site_id) keys on the per-switch action_key (via
     * ThemeActionAdapter::getSubjectId crc32) — each offer switch is a distinct
     * fence row.
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

        $arOfferData = $this->resolveOfferContentData($obProduct, $iProductId, $iOfferId, $obResolver);

        $arPayload = $this->applyOfferCustomDataToPayload($arPayload, $arOfferData);
        $arPayload = $this->injectRequestUserData($arPayload);

        $arCustomData = $arOfferData;
        unset($arCustomData['contents']);

        $sActionKey = 'viewcontent:'.$iProductId.':'.$iOfferId.':'.$sEventId;

        /** @var ThemeEventCollector $obCollector */
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(array_merge($arCustomData, [
            'name' => 'ViewContent',
            'action_key' => $sActionKey,
            'event_id' => $sEventId,
            'product_id' => $iProductId,
            'offer_id' => $iOfferId,
        ]));

        $obDispatchEvent = $this->makeDispatchEvent($sActionKey, $obAdapter->getSiteId($obProduct));
        SendCapiEvent::dispatch('ViewContent', $arPayload, $obDispatchEvent, ThemeActionAdapter::class);

        return new OfferSwitchResult($sEventId, $arCustomData);
    }

    /**
     * Wrap a per-view action_key in a ThemeActionEvent so the CAPI dispatch
     * routes through ThemeActionAdapter — whose getSubjectId hashes the unique
     * action_key. The EventLog UNIQUE race-fence then keys on the per-view
     * subject instead of the product id, so a new view of a previously-viewed
     * product is never silently fenced. The prebuilt $arPayload (from
     * ShopaholicProductAdapter + resolver) is untouched; only the dispatch
     * subject/adapter routing changes. site_id is baked from the product
     * subject so queue-side resolution stays request-independent (P-01).
     */
    private function makeDispatchEvent(string $sActionKey, ?int $iSiteId): ThemeActionEvent
    {
        return ThemeActionEvent::fromArray([
            'name' => 'ViewContent',
            'action_key' => $sActionKey,
            'site_id' => $iSiteId,
        ]);
    }

    /**
     * Derive the switched offer's browser custom_data plus the forced
     * content_ids/value/contents the payload mutation needs downstream. The
     * switched offer owns the variant name and price the visitor now sees; the
     * product-level resolver values describe the FIRST offer. Tiger-Style: an
     * offer id that does not belong to the product throws — fabricating a
     * SKU-{pid}-{oid} absent from the Facebook Catalog feed would poison
     * catalog match quality with attacker- or bug-supplied ids.
     *
     * @return array<string, mixed>
     */
    private function resolveOfferContentData(Product $obProduct, int $iProductId, int $iOfferId, ShopaholicProductValueResolver $obResolver): array
    {
        $obOffer = $this->findOffer($obProduct, $iOfferId);
        if ($obOffer === null) {
            throw new \RuntimeException(
                'ProductPageWatcher::dispatchForOfferSwitch: offer '.$iOfferId.' does not belong to product '.$iProductId,
            );
        }

        $arForcedContentIds = ['SKU-'.$iProductId.'-'.$iOfferId];
        $sContentName = $this->stringAttr($obOffer, 'name');
        $mOfferPrice = $obOffer->getAttribute('price_value');
        $fOfferValue = is_numeric($mOfferPrice)
            ? (float) $mOfferPrice
            : $obResolver->resolveValue($obProduct);
        $arOfferContents = [['id' => $arForcedContentIds[0], 'quantity' => 1, 'item_price' => $fOfferValue]];

        return [
            'content_ids' => $arForcedContentIds,
            'content_name' => $sContentName,
            'content_type' => 'product',
            'value' => $fOfferValue,
            'currency' => $obResolver->resolveCurrency($obProduct),
            'contents' => $arOfferContents,
        ];
    }

    /**
     * Inject the offer's forced content_ids/value/contents into the prebuilt
     * payload's first data envelope. No-op when the envelope shape is absent.
     *
     * @param  array<string, mixed>  $arPayload
     * @param  array<string, mixed>  $arOfferData
     * @return array<string, mixed>
     */
    private function applyOfferCustomDataToPayload(array $arPayload, array $arOfferData): array
    {
        $mData = $arPayload['data'] ?? null;
        if (is_array($mData) && isset($mData[0]) && is_array($mData[0])) {
            $mEnvelope = $mData[0];
            $mCustomData = $mEnvelope['custom_data'] ?? null;
            $arCustomData = is_array($mCustomData) ? $mCustomData : [];
            $arCustomData['content_ids'] = $arOfferData['content_ids'];
            $arCustomData['value'] = $arOfferData['value'];
            $arCustomData['contents'] = $arOfferData['contents'];
            $mEnvelope['custom_data'] = $arCustomData;
            $mData[0] = $mEnvelope;
            $arPayload['data'] = $mData;
        }

        return $arPayload;
    }

    /** Resolve one offer of the product by id from its loaded offer relation. */
    private function findOffer(Product $obProduct, int $iOfferId): ?Offer
    {
        $mOffer = $obProduct->offer->firstWhere('id', $iOfferId);

        return $mOffer instanceof Offer ? $mOffer : null;
    }

    private function intAttr(Product $obProduct, string $sAttr): int
    {
        $mValue = $obProduct->getAttribute($sAttr);

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    private function stringAttr(Product|Offer $obModel, string $sAttr): string
    {
        $mValue = $obModel->getAttribute($sAttr);

        return is_string($mValue) ? $mValue : '';
    }
}
