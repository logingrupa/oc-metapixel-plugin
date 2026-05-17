<?php

namespace Logingrupa\Metapixel\Classes\Exception;

use Throwable;

/**
 * Permanent Graph API failure: 4xx (other than 408/429). Caller persists a
 * FailedEvent row and fires metapixel.event.dead_letter — does NOT retry.
 */
final class MetaApiPermanentException extends MetaPixelException
{
    /** @var int|null */
    private ?int $iHttpStatus;

    /**
     * @param  array<string, mixed>  $arContext
     */
    public function __construct(string $sMessage = '', ?int $iHttpStatus = null, ?Throwable $obPrevious = null, array $arContext = [])
    {
        parent::__construct($sMessage, $iHttpStatus ?? 0, $obPrevious, $arContext);
        $this->iHttpStatus = $iHttpStatus;
    }

    public function getHttpStatus(): ?int
    {
        return $this->iHttpStatus;
    }
}
