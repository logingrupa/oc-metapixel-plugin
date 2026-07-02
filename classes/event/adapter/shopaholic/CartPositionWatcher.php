<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionValueResolver;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
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
 * capi, site_id) row — qty-bump dedup via UNIQUE race-fence.
 */
final class CartPositionWatcher
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
                ->where('channel', 'capi')
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

        $arPayload = $obBuilder->buildEventPayload(
            'AddToCart',
            $obAdapter,
            $obCartPosition,
            $obResolver,
            Uuid::uuid4()->toString(),
            time(),
            [],
        );
        $arPayload = $this->injectRequestUserData($arPayload);

        SendCapiEvent::dispatch('AddToCart', $arPayload, $obCartPosition, ShopaholicCartPositionAdapter::class);
    }

    /**
     * Browser-pixel resolver (D-07). Reads the already-generated capi AddToCart
     * event_id + custom_data for the current-session cart position, writes the
     * channel='pixel' twin row (idempotent race-fence), and returns the pair so
     * the AJAX boundary can emit a byte-identical browser fbq. Dispatches NO
     * SendCapiEvent — dispatchAddToCart on eloquent.created is the sole CAPI
     * emitter. Fail-safe: returns null (never throws) on every miss.
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

        $obCapiRow = $this->findCapiAddToCartRow($iPositionId);
        if ($obCapiRow === null) {
            return null;
        }

        $mEventId = $obCapiRow->event_id ?? null;
        $sEventId = is_string($mEventId) ? $mEventId : '';
        if ($sEventId === '') {
            return null;
        }
        $arCustomData = $this->extractCustomData($obCapiRow->payload ?? null);

        $this->writePixelTwin($sEventId, $iPositionId, $obCapiRow);

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

    /** Read the channel='capi' AddToCart EventLog row for this position, or null. */
    private function findCapiAddToCartRow(int $iPositionId): ?object
    {
        return DB::table('logingrupa_metapixel_event_log')
            ->where('subject_type', 'shopaholic.cart_position')
            ->where('subject_id', $iPositionId)
            ->where('event_name', 'AddToCart')
            ->where('channel', 'capi')
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

    /**
     * Write the channel='pixel' twin row via insertOrIgnore, copying event_id,
     * site_id, secret_key, event_time, and payload from the capi row. The
     * UNIQUE (subject_type, subject_id, event_name, channel, site_id) race-fence
     * makes a second call idempotent — no duplicate row, fail-safe on collision.
     */
    private function writePixelTwin(string $sEventId, int $iPositionId, object $obCapiRow): void
    {
        $sNow = (string) Carbon::now();
        $mEventTime = $obCapiRow->event_time ?? 0;
        DB::table('logingrupa_metapixel_event_log')->insertOrIgnore([
            'event_id' => $sEventId,
            'event_name' => 'AddToCart',
            'channel' => 'pixel',
            'subject_type' => 'shopaholic.cart_position',
            'subject_id' => $iPositionId,
            'secret_key' => $obCapiRow->secret_key ?? null,
            'site_id' => $obCapiRow->site_id ?? null,
            'event_time' => is_numeric($mEventTime) ? (int) $mEventTime : 0,
            'fired_at' => $sNow,
            'created_at' => $sNow,
            'updated_at' => $sNow,
            'payload' => $obCapiRow->payload ?? null,
        ]);
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
