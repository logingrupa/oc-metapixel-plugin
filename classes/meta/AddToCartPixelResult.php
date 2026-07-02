<?php

namespace Logingrupa\Metapixel\Classes\Meta;

/**
 * Readonly value object returned by CartPositionWatcher::resolveBrowserPixel:
 * the server-generated capi AddToCart event_id plus the browser-facing
 * custom_data copied from the capi EventLog row (so the AJAX boundary need not
 * re-derive it and the browser fbq is byte-identical to the server CAPI).
 */
final class AddToCartPixelResult
{
    /**
     * @param  array<string, mixed>  $arCustomData
     */
    public function __construct(
        public readonly string $sEventId,
        public readonly array $arCustomData,
    ) {}
}
