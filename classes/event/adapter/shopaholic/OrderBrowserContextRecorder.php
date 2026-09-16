<?php

namespace Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic;

use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Logingrupa\Metapixel\Classes\Event\CapturesRequestUserData;
use Logingrupa\Metapixel\Classes\Helper\EventSourceUrl;
use Logingrupa\Metapixel\Models\OrderBrowserContext;
use Lovata\OrdersShopaholic\Models\Order;
use Throwable;

/**
 * Stores the buyer's browser identity on Order eloquent.created. The order is
 * created inside the buyer's checkout request, the only moment the request
 * IP, user agent and Meta cookies describe the buyer. Non-browser requests
 * (backend, 1C exchange) record nothing.
 */
final class OrderBrowserContextRecorder
{
    use CapturesRequestUserData;

    public function subscribe(Dispatcher $obDispatcher): void
    {
        $obDispatcher->listen('eloquent.created: '.Order::class, [$this, 'handle']);
    }

    public function handle(Order $obOrder): void
    {
        try {
            $arUserData = $this->collectRequestUserData();
            if (array_filter($arUserData) === []) {
                return;
            }
            $mOrderId = $obOrder->getAttribute('id');
            if (! is_int($mOrderId) || $mOrderId <= 0) {
                throw new InvalidArgumentException('order id must be a positive integer, got: '.var_export($mOrderId, true));
            }

            OrderBrowserContext::create(array_merge($arUserData, [
                'order_id' => $mOrderId,
                'event_source_url' => EventSourceUrl::current(),
            ]));
        } catch (Throwable $obException) {
            // Tiger-Style boundary: a failed context write must not break
            // Order::create inside Lovata OrderProcessor.
            Log::warning('metapixel: OrderBrowserContextRecorder write failed', [
                'meta_pixel.order_id' => $obOrder->getAttribute('id'),
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);
        }
    }
}
