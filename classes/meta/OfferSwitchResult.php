<?php

namespace Logingrupa\Metapixel\Classes\Meta;

/**
 * Readonly value object returned by ProductPageWatcher::dispatchForOfferSwitch:
 * the server-generated event_id plus the browser-facing ViewContent custom_data
 * the watcher already assembled (so the AJAX handler need not re-derive it).
 */
final class OfferSwitchResult
{
    /**
     * @param  array<string, mixed>  $arCustomData
     */
    public function __construct(
        public readonly string $sEventId,
        public readonly array $arCustomData,
    ) {}
}
