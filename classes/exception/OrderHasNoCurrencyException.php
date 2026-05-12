<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown by `PayloadBuilder::buildPurchaseEventPayload` (plan 03-04) when
 * `$obOrder->currency_code` is null AND `$obOrder->currency` relation is
 * null. Should never happen on a persisted order — Lovata.OrdersShopaholic
 * seeds `currency_id` on OrderProcessor create-path. Fail-fast precondition
 * at the function boundary (Tiger-Style).
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.order_has_no_currency`.
 */
final class OrderHasNoCurrencyException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
