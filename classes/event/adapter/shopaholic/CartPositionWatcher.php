<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionValueResolver;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
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

    private function logFailure(string $sPhase, CartPosition $obCartPosition, Throwable $obException): void
    {
        Log::warning('metapixel: CartPositionWatcher '.$sPhase.' handler failed', [
            'meta_pixel.cart_position_id' => $obCartPosition->id,
            'meta_pixel.exception' => get_class($obException),
            'meta_pixel.message' => $obException->getMessage(),
        ]);
    }
}
