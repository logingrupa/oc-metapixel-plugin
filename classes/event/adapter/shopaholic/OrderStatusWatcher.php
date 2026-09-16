<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderValueResolver;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\PayloadRequestContext;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\OrderBrowserContext;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Watches Order eloquent.updated|created; fires Purchase on transition to
 * paid_status_code. EventLog UNIQUE race-fence is the dedup anchor.
 * Buyer identity precedence: stored checkout context, then the current
 * request when it is the buyer's browser, then the account's last IP.
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
            $mStatus = $obOrder->getRelationValue('status');
            if (! is_object($mStatus) || ! in_array($mStatus->code ?? null, $this->paidStatusCodes(), true)) {
                return;
            }

            if ($obOrder->exists && ! $obOrder->wasChanged('status_id')) {
                return;
            }

            $obAdapter = new ShopaholicOrderAdapter;
            $obResolver = new ShopaholicOrderValueResolver;
            $obBuilder = new PayloadBuilder(new UserDataHasher);

            $arPayload = $obBuilder->buildEventPayload(
                'Purchase',
                $obAdapter,
                $obOrder,
                $obResolver,
                Uuid::uuid4()->toString(),
                time(),
                [],
            );
            $arPayload = $this->injectStoredBrowserContext($obOrder, $arPayload);
            $arPayload = $this->injectRequestUserData('Purchase', $obAdapter->getSubjectType($obOrder), $arPayload);
            $arPayload = PayloadRequestContext::merge($arPayload, ['client_ip_address' => $this->userLastIp($obOrder)], null);

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

    /**
     * Fill user_data and event_source_url from the buyer's checkout request,
     * stored by OrderBrowserContextRecorder. Wins over the current request,
     * which for a webhook, backend save or 1C exchange describes the caller.
     *
     * @param  array<string, mixed>  $arPayload
     * @return array<string, mixed>
     */
    private function injectStoredBrowserContext(Order $obOrder, array $arPayload): array
    {
        $mOrderId = $obOrder->getAttribute('id');
        $obContext = OrderBrowserContext::findForOrder(is_int($mOrderId) ? $mOrderId : 0);
        if ($obContext === null) {
            return $arPayload;
        }
        $arUserData = $obContext->toUserData();
        $arUserData['fbc'] = (new UserDataHasher)->freshFbc($arUserData['fbc']);

        return PayloadRequestContext::merge($arPayload, $arUserData, $obContext->event_source_url);
    }

    /** last_ip_address of the order's user account (RainLab.User / Buddies), the fallback when no context was stored. */
    private function userLastIp(Order $obOrder): ?string
    {
        if (! $obOrder->hasRelation('user')) {
            return null;
        }
        $mUser = $obOrder->getRelationValue('user');
        if (! is_object($mUser) || ! method_exists($mUser, 'getAttribute')) {
            return null;
        }
        $mLastIp = $mUser->getAttribute('last_ip_address');

        return is_string($mLastIp) && $mLastIp !== '' ? $mLastIp : null;
    }

    /**
     * Status codes that mean paid. The setting is a checkbox list, older
     * installs still hold a single string.
     *
     * @return list<string>
     */
    private function paidStatusCodes(): array
    {
        $mSetting = Settings::get('paid_status_code', ['new-payment-received']);
        $arRaw = is_array($mSetting) ? $mSetting : [$mSetting];
        $arCodes = array_values(array_filter($arRaw, static fn (mixed $mCode): bool => is_string($mCode) && $mCode !== ''));

        return $arCodes === [] ? ['new-payment-received'] : $arCodes;
    }
}
