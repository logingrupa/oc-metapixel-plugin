<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

/**
 * Thrown by `PayloadBuilder::buildPurchaseEventPayload` (plan 03-04) when
 * `Ramsey\Uuid\Uuid::isValid($sEventId) === false`. The event_id is a
 * server-generated UUIDv4 per the dedup contract (PROJECT.md key decision:
 * event_id direction = server→frontend only). A non-UUID here means a caller
 * violated the contract.
 *
 * Lang key: `logingrupa.metapixelshopaholic::lang.exception.invalid_event_id`.
 */
final class InvalidEventIdException extends MetaPixelException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
