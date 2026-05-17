<?php

namespace Logingrupa\Metapixel\Classes\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for all Logingrupa.Metapixel plugin failures. Carries an
 * optional context array for structured Log::* payloads.
 */
abstract class MetaPixelException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $arContext = [];

    /**
     * @param  array<string, mixed>  $arContext
     */
    public function __construct(string $sMessage = '', int $iCode = 0, ?Throwable $obPrevious = null, array $arContext = [])
    {
        parent::__construct($sMessage, $iCode, $obPrevious);
        $this->arContext = $arContext;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->arContext;
    }
}
