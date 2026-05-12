<?php

namespace Logingrupa\Metapixelshopaholic\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixelshopaholic\Models\Settings;
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
 *   subdomain-index: looked up in `HOST_INDEX_MAP` against the request host.
 *     Hosts not in the allowlist short-circuit — refuse to poison Meta with a
 *     guessed index derived from a spoofable `Host:` header (CR-02 lock).
 *     `nailscosmetics.lv`        → 1
 *     `www.nailscosmetics.lv`    → 2
 *     untrusted host             → no cookies set
 *   creation-time-ms: `(int) (microtime(true) * 1000)` for true millisecond
 *     precision matching Meta's field name semantics (WR-08 lock). Meta also
 *     accepts seconds-times-1000.
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
 * `_fbc` is generated ONLY when `?fbclid=…` is present in the query, passes
 * length + charset validation, AND no `_fbc` cookie was sent. Never invents a
 * synthetic `_fbc` (CR-03 lock — Symfony Cookie::create does NOT length-cap).
 *
 * Master kill-switch: `Settings::get('ensure_fbp_fbc_server_side')` short-
 * circuits the middleware to a no-op when toggled OFF in the backend (CR-01
 * lock — the field is the operator's documented cookie-consent gate).
 *
 * Defense-in-depth: also short-circuits when the plugin disabled-flag container
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

    private const COOKIE_FBP = '_fbp';

    private const COOKIE_FBC = '_fbc';

    /**
     * Maximum permitted `fbclid` length. Meta documents fbclid as ~100 chars;
     * 255 leaves headroom while preventing 4 KiB+ cookie poisoning (CR-03).
     */
    private const FBCLID_MAX_LENGTH = 255;

    /** Allowed `fbclid` charset per Meta's spec (CR-03 lock). */
    private const FBCLID_ALLOWED_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Allowlist of trusted hosts and their corresponding Meta subdomain-index.
     * Hosts NOT in this map short-circuit the middleware (no cookies set) to
     * prevent Host-header spoofing from poisoning Meta's reconciliation logic
     * with a wrong subdomain-index (CR-02 lock — `Request::getHost()` reflects
     * `Host:` / `X-Forwarded-Host`; without trusted_hosts validation any
     * caller can supply arbitrary values).
     *
     * Multi-part-TLD limitation (deferred): apex/www mapping is explicit. A
     * Phase 5 deploy to a `.co.uk` or `.com.au` registry adds its hosts here.
     * The naive `count(explode('.', host)) - 1` formula is wrong by
     * construction for those suffixes and is NOT used.
     *
     * @var array<string, int>
     */
    private const HOST_INDEX_MAP = [
        'nailscosmetics.no' => 1,
        'www.nailscosmetics.no' => 2,
        'nailscosmetics.lv' => 1,
        'www.nailscosmetics.lv' => 2,
        'nailscosmetics.lt' => 1,
        'www.nailscosmetics.lt' => 2,
    ];

    /**
     * Handle an incoming request and ensure `_fbp` / `_fbc` cookies are set on
     * the outbound response per Meta's spec.
     *
     * @param  Closure(Request): Response  $fnNext
     */
    public function handle(Request $obRequest, Closure $fnNext): Response
    {
        $obResponse = $fnNext($obRequest);

        if ($this->shouldSkip($obRequest)) {
            return $obResponse;
        }

        $sHost = strtolower($obRequest->getHost());
        if (! isset(self::HOST_INDEX_MAP[$sHost])) {
            // CR-02: untrusted host → refuse to poison Meta with a guessed index.
            return $obResponse;
        }
        $iSubdomainIndex = self::HOST_INDEX_MAP[$sHost];

        // WR-08: microtime for true ms precision matching Meta's field name.
        // 64-bit PHP only — overflow impossible until year ~292 million.
        $iCreationTimeMs = (int) (microtime(true) * 1000);
        $bSecure = $obRequest->secure();
        $iExpire = time() + self::COOKIE_TTL_SECONDS;

        $this->maybeSetFbp($obRequest, $obResponse, $iSubdomainIndex, $iCreationTimeMs, $iExpire, $bSecure);
        $this->maybeSetFbc($obRequest, $obResponse, $iSubdomainIndex, $iCreationTimeMs, $iExpire, $bSecure);

        return $obResponse;
    }

    /**
     * Aggregate the short-circuit checks: backend path, plugin-disabled
     * container flag, and operator-controlled Settings toggle. Returning true
     * means handle() should leave the response untouched.
     */
    private function shouldSkip(Request $obRequest): bool
    {
        // WR-01: backend path short-circuit. Path-based check against the
        // resolved request URL — more reliable than boot-time
        // App::runningInBackend() on non-default BACKEND_URI deploys.
        $mBackendUri = config('cms.backendUri', 'backend');
        $sBackendUri = is_scalar($mBackendUri) ? (string) $mBackendUri : '';
        if ($sBackendUri !== '' && $obRequest->is(ltrim($sBackendUri, '/').'*')) {
            return true;
        }

        // Defense-in-depth: plugin-disabled flag (PluginGuard / Phase 3+
        // handlers also gate on this). `App::bound(...)` guards requests
        // arriving before Plugin::boot() has run (early service-provider
        // boot hooks).
        if (App::bound('metapixel.disabled') && App::make('metapixel.disabled')) {
            return true;
        }

        // CR-01 master kill-switch: operator-controlled Settings toggle.
        // Boundary catch matches SKEL-05 — a broken system_settings table
        // must NOT 500 the response; default ON mirrors fields.yaml.
        try {
            $bToggleEnabled = (bool) Settings::get('ensure_fbp_fbc_server_side', true);
        } catch (\Throwable $obException) {
            $bToggleEnabled = true;
        }

        return ! $bToggleEnabled;
    }

    /**
     * Set `_fbp` when missing. Random segment is 16 hex chars (8 random bytes
     * via libsodium-backed CSPRNG). Never overwrites an existing cookie.
     */
    private function maybeSetFbp(
        Request $obRequest,
        Response $obResponse,
        int $iSubdomainIndex,
        int $iCreationTimeMs,
        int $iExpire,
        bool $bSecure
    ): void {
        if ($obRequest->cookie(self::COOKIE_FBP) !== null) {
            return;
        }
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

    /**
     * Set `_fbc` ONLY when the request carries a well-formed `fbclid` query
     * AND no `_fbc` cookie was sent. fbclid is validated locally (CR-03):
     * Symfony's Cookie::create does NOT enforce a length cap, so an attacker
     * can otherwise deliver multi-KiB cookie payloads that propagate to CAPI
     * envelopes and bloat every subsequent request.
     */
    private function maybeSetFbc(
        Request $obRequest,
        Response $obResponse,
        int $iSubdomainIndex,
        int $iCreationTimeMs,
        int $iExpire,
        bool $bSecure
    ): void {
        $mFbclid = $obRequest->query('fbclid', '');
        $sFbclid = is_scalar($mFbclid) ? (string) $mFbclid : '';
        if ($sFbclid === '' || strlen($sFbclid) > self::FBCLID_MAX_LENGTH) {
            return;
        }
        if (preg_match(self::FBCLID_ALLOWED_PATTERN, $sFbclid) !== 1) {
            return;
        }
        if ($obRequest->cookie(self::COOKIE_FBC) !== null) {
            return;
        }
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
}
