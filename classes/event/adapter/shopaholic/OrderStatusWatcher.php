<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Watches Order eloquent.updated|created; fires Purchase on transition to
 * paid_status_code. EventLog UNIQUE race-fence is the dedup anchor.
 */
final class OrderStatusWatcher
{
    use CapturesRequestUserData;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('eloquent.updated: '.Order::class, [$this, 'handle']);
        $obDispatcher->listen('eloquent.created: '.Order::class, [$this, 'handle']);
    }

    public function handle(Order $obOrder): void
    {
        try {
            $mPaidCode = Settings::get('paid_status_code', 'new-payment-received');
            $sPaidCode = is_string($mPaidCode) ? $mPaidCode : 'new-payment-received';

            $mStatus = $obOrder->getRelationValue('status');
            if (! is_object($mStatus) || ($mStatus->code ?? null) !== $sPaidCode) {
                return;
            }

            if ($obOrder->exists && ! $obOrder->wasChanged('status_id')) {
                return;
            }

            $obAdapter = new ShopaholicOrderAdapter;
            $obBuilder = new PayloadBuilder(new UserDataHasher);

            $arPayload = $obBuilder->buildEventPayload(
                'Purchase',
                $obAdapter,
                $obOrder,
                $obAdapter->getValueResolver($obOrder),
                Uuid::uuid4()->toString(),
                time(),
                [],
            );
            $arPayload = $this->injectRequestUserData($arPayload);

            SendCapiEvent::dispatch('Purchase', $arPayload, $obOrder, ShopaholicOrderAdapter::class);
        } catch (Throwable $obException) {
            // Tiger-Style: log + return. Do NOT rethrow — would cascade-break
            // Order::save() through Lovata OrderProcessor / Campaign / PromoMechanism.
            Log::warning('metapixel: OrderStatusWatcher payload-build failed', [
                'meta_pixel.order_id' => $obOrder->id,
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }
}
