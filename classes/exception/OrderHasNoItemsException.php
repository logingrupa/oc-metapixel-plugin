<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown by `PayloadBuilder::buildPurchaseEventPayload` (plan 03-04) when
 * `$obOrder->order_position->count() === 0`. CAPI Purchase events require
 * at least one item in the `contents[]` array — an empty contents array is
 * rejected by Meta Graph API as malformed payload. Fail-fast precondition.
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.order_has_no_items`.
 */
final class OrderHasNoItemsException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
