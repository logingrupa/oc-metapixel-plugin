<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionValueResolver;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Helper\EventLogWriter;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Meta\AddToCartPixelResult;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Models\CartPosition;
use Lovata\Shopaholic\Models\Offer;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Watches CartPosition eloquent.created|updated; fires AddToCart on creation
 * always, on update only when EventLog has no prior (cart_position, AddToCart,
 * pixel, site_id) row — qty-bump dedup keys on the request-time pixel
 * reservation row written by dispatchAddToCart (visible immediately,
 * independent of queue-worker latency).
 */
class CartPositionWatcher
{
    use CapturesRequestUserData;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('eloquent.created: '.CartPosition::class, [$this, 'handleCreated']);
        $obDispatcher->listen('eloquent.updated: '.CartPosition::class, [$this, 'handleUpdated']);
    }

    public function handleCreated(CartPosition $obCartPosition): void
    {
        try {
            $this->dispatchAddToCart($obCartPosition);
        } catch (Throwable $obException) {
            $this->logFailure('created', $obCartPosition, $obException);
        }
    }

    public function handleUpdated(CartPosition $obCartPosition): void
    {
        try {
            $obAdapter = new ShopaholicCartPositionAdapter;
            $bAlreadyLogged = DB::table('logingrupa_metapixel_event_log')
                ->where('subject_type', 'shopaholic.cart_position')
                ->where('subject_id', $obAdapter->getSubjectId($obCartPosition))
                ->where('event_name', 'AddToCart')
                ->where('channel', 'pixel')
                ->where('site_id', $obAdapter->getSiteId($obCartPosition))
                ->exists();
            if (! $bAlreadyLogged) {
                $this->dispatchAddToCart($obCartPosition);
            }
        } catch (Throwable $obException) {
            $this->logFailure('updated', $obCartPosition, $obException);
        }
    }

    private function dispatchAddToCart(CartPosition $obCartPosition): void
    {
        if (! $obCartPosition->getRelationValue('item') instanceof Offer) {
            Log::info('metapixel: CartPositionWatcher — item MorphTo not an Offer, skipping', [
                'meta_pixel.cart_position_id' => $obCartPosition->id,
            ]);

            return;
        }

        $obAdapter = new ShopaholicCartPositionAdapter;
        $obResolver = new ShopaholicCartPositionValueResolver;
        $obBuilder = new PayloadBuilder(new UserDataHasher);

        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = time();
        $arPayload = $obBuilder->buildEventPayload(
            'AddToCart',
            $obAdapter,
            $obCartPosition,
            $obResolver,
            $sEventId,
            $iEventTime,
            [],
        );
        $arPayload = $this->injectRequestUserData($arPayload);

        // Reserve the browser-pixel twin IN-REQUEST: the theme JS calls
        // Metapixel::onMarkAddToCart milliseconds after Cart::onAdd, so the
        // browser pixel must not depend on the queue worker having executed
        // (on async drivers the worker-written capi row usually does not exist
        // yet). Return value intentionally unchecked — on fence collision or
        // DB failure the browser pixel is skipped (fail-safe) while the CAPI
        // dispatch still proceeds.
        EventLogWriter::record(
            $sEventId,
            'AddToCart',
            'pixel',
            $obCartPosition,
            $obAdapter->getSecretKey($obCartPosition),
            $iEventTime,
            $obAdapter->getSiteId($obCartPosition),
            $arPayload,
        );

        SendCapiEvent::dispatch('AddToCart', $arPayload, $obCartPosition, ShopaholicCartPositionAdapter::class);
    }

    /**
     * Browser-pixel resolver (D-07). Reads the channel='pixel' AddToCart
     * EventLog row that dispatchAddToCart reserved IN-REQUEST for the
     * current-session cart position and returns its event_id + custom_data so
     * the AJAX boundary can emit a browser fbq that Meta dedups with the CAPI
     * twin by event_id. Dispatches NO SendCapiEvent — dispatchAddToCart on
     * eloquent.created is the sole CAPI emitter. Reading the request-time
     * reservation (never the worker-written capi row) keeps this path correct
     * on async queue drivers. Fail-safe: returns null on every resolution
     * miss; infrastructure failures propagate to the AJAX boundary's catch.
     */
    public function resolveBrowserPixel(int $iOfferId): ?AddToCartPixelResult
    {
        if (PluginGuard::isDisabled() || $iOfferId <= 0) {
            return null;
        }

        $iCartId = $this->resolveCurrentCartId();
        if ($iCartId <= 0) {
            return null;
        }

        $iPositionId = $this->resolvePositionId($iCartId, $iOfferId);
        if ($iPositionId <= 0) {
            return null;
        }

        $obPixelRow = $this->findPixelAddToCartRow($iPositionId);
        if ($obPixelRow === null) {
            Log::info('metapixel: resolveBrowserPixel — no pixel reservation row for position', [
                'meta_pixel.cart_position_id' => $iPositionId,
                'meta_pixel.offer_id' => $iOfferId,
            ]);

            return null;
        }

        $mEventId = $obPixelRow->event_id ?? null;
        $sEventId = is_string($mEventId) ? $mEventId : '';
        if ($sEventId === '') {
            return null;
        }
        $arCustomData = $this->extractCustomData($obPixelRow->payload ?? null);

        return new AddToCartPixelResult($sEventId, $arCustomData);
    }

    /** Resolve the current-session cart id, or 0 when none is established. */
    private function resolveCurrentCartId(): int
    {
        $obProcessor = CartProcessor::instance();
        if (! $obProcessor instanceof CartProcessor) {
            return 0;
        }
        $mCartId = $obProcessor->getCartObject()->getAttribute('id');

        return is_numeric($mCartId) ? (int) $mCartId : 0;
    }

    /**
     * Resolve the latest cart position id for this offer scoped to the current
     * cart (T-05G-01: session-scoping selects only the caller's own positions).
     */
    private function resolvePositionId(int $iCartId, int $iOfferId): int
    {
        $obPosition = CartPosition::getByCart($iCartId)
            ->getByItemID($iOfferId)
            ->getByItemType(Offer::class)
            ->orderBy('id', 'desc')
            ->first();
        if ($obPosition === null) {
            return 0;
        }
        $mPositionId = $obPosition->getAttribute('id');

        return is_numeric($mPositionId) ? (int) $mPositionId : 0;
    }

    /** Read the channel='pixel' AddToCart EventLog row for this position, or null. */
    private function findPixelAddToCartRow(int $iPositionId): ?object
    {
        return DB::table('logingrupa_metapixel_event_log')
            ->where('subject_type', 'shopaholic.cart_position')
            ->where('subject_id', $iPositionId)
            ->where('event_name', 'AddToCart')
            ->where('channel', 'pixel')
            ->first(['event_id', 'event_time', 'secret_key', 'site_id', 'payload']);
    }

    /**
     * Walk the stored capi payload → data[0] → custom_data so the browser
     * custom_data is byte-identical to the server CAPI custom_data.
     *
     * @return array<string, mixed>
     */
    private function extractCustomData(mixed $mPayloadRaw): array
    {
        if (! is_string($mPayloadRaw) || $mPayloadRaw === '') {
            return [];
        }
        $mDecoded = json_decode($mPayloadRaw, true);
        if (! is_array($mDecoded)) {
            return [];
        }
        $mData = $mDecoded['data'] ?? null;
        if (! is_array($mData)) {
            return [];
        }
        $mFirst = $mData[0] ?? null;
        if (! is_array($mFirst)) {
            return [];
        }
        $mCustomData = $mFirst['custom_data'] ?? null;
        if (! is_array($mCustomData)) {
            return [];
        }
        $arResult = [];
        foreach ($mCustomData as $mKey => $mValue) {
            $arResult[(string) $mKey] = $mValue;
        }

        return $arResult;
    }

    private function logFailure(string $sPhase, CartPosition $obCartPosition, Throwable $obException): void
    {
        Log::warning('metapixel: CartPositionWatcher '.$sPhase.' handler failed', [
            'meta_pixel.cart_position_id' => $obCartPosition->id,
            'meta_pixel.exception' => get_class($obException),
            'meta_pixel.message' => $obException->getMessage(),
        ]);
    }
}
