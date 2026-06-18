<?php

namespace Logingrupa\Metapixel\Classes\Meta;

/**
 * Single source of truth for fbq() track-block assembly. Pure string builder:
 * reads NO Settings (caller passes test_event_code) so it stays trivially
 * testable and side-effect free. JS-encode flags are the canonical const all
 * render sites share.
 */
final class FbqScriptBuilder
{
    public const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;

    /**
     * Build a full `<script>fbq("track", NAME, DATA[, OPTS]);</script>` line.
     *
     * @param  array<string, mixed>  $arCustomData
     */
    public static function build(string $sEventName, array $arCustomData, ?string $sEventId, ?string $sTestEventCode): string
    {
        $sNameJson = (string) json_encode($sEventName, self::JS);
        $sDataJson = (string) json_encode($arCustomData, self::JS);
        $sObjFragment = self::buildOptionsObject($sEventId, $sTestEventCode);

        if ($sObjFragment !== '') {
            return sprintf('<script>fbq("track", %s, %s, %s);</script>', $sNameJson, $sDataJson, $sObjFragment);
        }

        return sprintf('<script>fbq("track", %s, %s);</script>', $sNameJson, $sDataJson);
    }

    /**
     * Assemble the fbq() 4th-arg options object: eventID first, then
     * test_event_code. Returns '' when neither is present so the caller emits a
     * 3-arg fbq() call.
     */
    private static function buildOptionsObject(?string $sEventId, ?string $sTestEventCode): string
    {
        $arPairs = [];
        if (is_string($sEventId) && $sEventId !== '') {
            $arPairs[] = 'eventID: '.(string) json_encode($sEventId, self::JS);
        }
        if (is_string($sTestEventCode) && $sTestEventCode !== '') {
            $arPairs[] = 'test_event_code: '.(string) json_encode($sTestEventCode, self::JS);
        }

        return $arPairs === [] ? '' : '{'.implode(', ', $arPairs).'}';
    }
}
