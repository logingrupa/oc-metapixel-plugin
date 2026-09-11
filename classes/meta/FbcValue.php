<?php

namespace Logingrupa\Metapixel\Classes\Meta;

/**
 * Freshness gate for the Meta _fbc click identifier
 * (fb.{index}.{creationMs}.{fbclid}[.{appendix}]). Meta flags a fbc whose
 * click is older than the 90 day cookie lifetime as stale, so such values
 * are dropped from user_data instead of forwarded.
 */
final class FbcValue
{
    public const MAX_AGE_SECONDS = 60 * 60 * 24 * 90;

    private const PATTERN = '/^fb\.\d+\.(\d{13})\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)?$/';

    /**
     * The value unchanged when well formed and created within the last 90
     * days, else null.
     */
    public static function fresh(?string $sFbc, int $iNowMs): ?string
    {
        if ($iNowMs <= 0) {
            throw new \InvalidArgumentException("Clock must be a positive millisecond timestamp, got: {$iNowMs}");
        }
        if ($sFbc === null || preg_match(self::PATTERN, $sFbc, $arMatch) !== 1) {
            return null;
        }

        $iCreationMs = (int) $arMatch[1];
        if ($iCreationMs > $iNowMs) {
            return null;
        }
        if ($iNowMs - $iCreationMs > self::MAX_AGE_SECONDS * 1000) {
            return null;
        }

        return $sFbc;
    }
}
