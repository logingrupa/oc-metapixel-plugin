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

    /**
     * The human sentence Meta put in the Graph error body, when the context
     * carries a decoded response. error_user_msg is the operator-facing text,
     * message the generic one ("Invalid parameter").
     */
    public function metaReason(): ?string
    {
        $mResponse = $this->arContext['response'] ?? null;
        $mError = is_array($mResponse) ? ($mResponse['error'] ?? null) : null;
        if (! is_array($mError)) {
            return null;
        }
        foreach (['error_user_msg', 'message'] as $sKey) {
            $mText = $mError[$sKey] ?? null;
            if (is_string($mText) && $mText !== '') {
                return $mText;
            }
        }

        return null;
    }
}
