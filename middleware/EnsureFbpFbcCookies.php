<?php

namespace Logingrupa\Metapixelshopaholic\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global storefront HTTP middleware that guarantees `_fbp` and `_fbc` cookies
 * are present on every response per Meta's spec.
 *
 * Bug context (production observed 2026-04-22): `fbevents.js` does not run
 * early enough on SSR-cached responses or non-JS bots, so `_fbp`/`_fbc` arrive
 * empty in CAPI envelopes → Meta cannot match users → EMQ collapses below 8.
 * Setting both cookies server-side fixes this for 100 % of storefront requests.
 *
 * Meta-spec format: `fb.{subdomain-index}.{creation-time-ms}.{random}`
 *
 *   subdomain-index: derived from `Request::getHost()`:
 *     `nailscosmetics.lv`        → 1
 *     `www.nailscosmetics.lv`    → 2
 *     deeper subdomains          → capped at 2
 *   creation-time-ms: `time() * 1000` (Meta accepts seconds-times-1000 even
 *     though the field name says ms)
 *   random: `bin2hex(random_bytes(8))` — 16 hex chars (64 bits CSPRNG entropy)
 *
 * Cookie attributes (CONTEXT Area 3 Q3 + Specifics line 108):
 *   - TTL: 90 days
 *   - path: `/`
 *   - domain: NULL (implicit current-host; multi-site .no/.lv/.lt safe)
 *   - secure: mirrors `Request::secure()` so HTTPS gets secure cookies
 *   - httpOnly: false (browser must read `_fbp` for the `fbq` library)
 *   - SameSite: Lax
 *
 * `_fbc` is generated ONLY when `?fbclid=…` is present in the query AND no
 * `_fbc` cookie was sent. Never invent a synthetic `_fbc`.
 *
 * Defense-in-depth: short-circuits when the plugin disabled-flag container
 * binding resolves to truthy — even if PluginGuard's prime path is bypassed
 * somehow, this middleware refuses to set cookies for a disabled plugin
 * (T-02-15).
 *
 * Phase 5 deployment note: routes hitting this middleware MUST be served with
 * `Cache-Control: private` to prevent shared-cache cookie leakage (T-02-16).
 * README HARD-05 documents the ops requirement.
 *
 * @author Logingrupa
 */
class EnsureFbpFbcCookies
{
    /** Meta-spec 90-day `_fbp` / `_fbc` cookie expiry. */
    private const COOKIE_TTL_SECONDS = 60 * 60 * 24 * 90;

    /** Meta-spec subdomain-index cap (CONTEXT Specifics line 107). */
    private const SUBDOMAIN_INDEX_CAP = 2;

    private const COOKIE_FBP = '_fbp';

    private const COOKIE_FBC = '_fbc';

    /**
     * Handle an incoming request and ensure `_fbp` / `_fbc` cookies are set on
     * the outbound response per Meta's spec.
     *
     * @param  Closure(Request): Response  $fnNext
     */
    public function handle(Request $obRequest, Closure $fnNext): Response
    {
        $obResponse = $fnNext($obRequest);

        // Defense-in-depth: short-circuit when the plugin is disabled.
        // `App::bound(...)` guards against requests that arrive BEFORE
        // Plugin::boot() has run (e.g. early service-provider boot hooks).
        if (App::bound('metapixel.disabled') && App::make('metapixel.disabled')) {
            return $obResponse;
        }

        $iSubdomainIndex = min(
            self::SUBDOMAIN_INDEX_CAP,
            max(0, count(explode('.', $obRequest->getHost())) - 1)
        );
        $iCreationTimeMs = time() * 1000;
        $bSecure = $obRequest->secure();
        $iExpire = time() + self::COOKIE_TTL_SECONDS;

        // `_fbp` — set when missing. Random segment is 16 hex chars (8 random
        // bytes via libsodium-backed CSPRNG). Never overwrites an existing cookie.
        if ($obRequest->cookie(self::COOKIE_FBP) === null) {
            $sFbp = sprintf(
                'fb.%d.%d.%s',
                $iSubdomainIndex,
                $iCreationTimeMs,
                bin2hex(random_bytes(8))
            );
            $obResponse->headers->setCookie(
                Cookie::create('_fbp', $sFbp, $iExpire, '/', null, $bSecure, false, false, 'lax')
            );
        }

        // `_fbc` — set ONLY when the request carries a non-empty `fbclid`
        // query AND no `_fbc` cookie was sent. Embed the raw fbclid via
        // sprintf %s (T-02-11: Symfony Cookie::create rejects overlong values
        // via InvalidArgumentException — fail-fast at the boundary is correct).
        $sFbclid = (string) $obRequest->query('fbclid', '');
        if ($sFbclid !== '' && $obRequest->cookie(self::COOKIE_FBC) === null) {
            $sFbc = sprintf(
                'fb.%d.%d.%s',
                $iSubdomainIndex,
                $iCreationTimeMs,
                $sFbclid
            );
            $obResponse->headers->setCookie(
                Cookie::create('_fbc', $sFbc, $iExpire, '/', null, $bSecure, false, false, 'lax')
            );
        }

        return $obResponse;
    }
}
