<?php

namespace Logingrupa\Metapixel\Classes\Helper;

/**
 * Classifies the current HTTP request for pixel-emission decisions.
 *
 * Meta browser pixels only exist on rendered pages, so CAPI events that
 * mirror a browser pixel (PageView, ViewContent) may dispatch only when the
 * request actually renders a page. AJAX postbacks — October AJAX (handler
 * header) and Larajax (plain XHR, handler in payload) — re-run the page
 * component lifecycle without rendering, so any pixel emitted there reaches
 * Meta permanently unpaired.
 *
 * Reads PHP superglobals, not Request facades, so it is callable from every
 * layer including classes/event/ where the phpstan disallowed-calls rule
 * bans Illuminate\Http\Request (CapturesRequestUserData precedent).
 */
final class RequestKind
{
    /**
     * True when the current request is a plain GET page render — the only
     * request kind that produces browser pixels.
     */
    public static function isPageRender(): bool
    {
        $bHasOctoberAjaxHandler = isset($_SERVER['HTTP_X_OCTOBER_REQUEST_HANDLER']);
        if ($bHasOctoberAjaxHandler) {
            return false;
        }

        $bIsXmlHttpRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
        if ($bIsXmlHttpRequest) {
            return false;
        }

        $mRequestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return is_string($mRequestMethod) && strtoupper($mRequestMethod) === 'GET';
    }
}
