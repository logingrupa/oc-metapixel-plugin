<?php

namespace Logingrupa\Metapixel\Classes\Exception;

use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

/**
 * Transient Graph API failure: 408 / 429 / 5xx + ConnectException. Caller
 * (SendCapiEvent::handle) rethrows to trigger Laravel queue retry/backoff.
 * ShouldntReport keeps the per-attempt retry out of the log; the exhausted
 * job logs once from SendCapiEvent::failed().
 */
final class MetaApiTransientException extends MetaPixelException implements ShouldntReport
{
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
