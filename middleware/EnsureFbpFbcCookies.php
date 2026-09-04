<?php

namespace Logingrupa\Metapixel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Models\Settings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Server-side _fbp + _fbc cookie writer for Meta CAPI deduplication anchors.
 * Resolves the values BEFORE the page runs and primes them into the request,
 * so the CAPI readers of the very request that carries ?fbclid (the ad click
 * landing) already send the click id Meta sees from the browser pixel. A new
 * fbclid replaces the stored _fbc, mirroring the browser pixel. Honors the
 * operator kill switch, validates fbclid charset + length, and defers
 * host-trust resolution to the PSL-aware HostIndexResolver. Untrusted host
 * → middleware NO-OPs (no cookies set, no exception thrown).
 *
 * Operator MUST serve routes hitting this middleware with Cache-Control: private
 * to prevent shared-cache cookie leakage. See README "Cookie middleware" section.
 */
class EnsureFbpFbcCookies
{
    public const COOKIE_FBP = '_fbp';

    public const COOKIE_FBC = '_fbc';

    private const COOKIE_TTL_SECONDS = 60 * 60 * 24 * 90;

    private const FBCLID_MAX_LENGTH = 255;

    private const FBCLID_ALLOWED_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly HostIndexResolver $obResolver) {}

    public function handle(Request $obRequest, Closure $fnNext): Response
    {
        $arFreshCookies = $this->resolveFreshCookies($obRequest);
        foreach ($arFreshCookies as $sName => $sValue) {
            $obRequest->cookies->set($sName, $sValue);
            $_COOKIE[$sName] = $sValue;
        }

        $obResponse = $this->resolveResponse($fnNext, $obRequest);

        if ($arFreshCookies === []) {
            return $obResponse;
        }

        $iExpire = time() + self::COOKIE_TTL_SECONDS;
        $bSecure = $obRequest->secure();
        foreach ($arFreshCookies as $sName => $sValue) {
            $obResponse->headers->setCookie(
                Cookie::create($sName, $sValue, $iExpire, '/', null, $bSecure, false, false, 'lax')
            );
        }

        return $obResponse;
    }

    /**
     * Cookie name => value for every cookie this request must gain: a missing
     * _fbp, and a _fbc built from a valid ?fbclid the stored cookie does not
     * already carry. Empty when the middleware NO-OPs.
     *
     * @return array<string, string>
     */
    private function resolveFreshCookies(Request $obRequest): array
    {
        if ($this->shouldSkip($obRequest)) {
            return [];
        }

        $sHost = strtolower($obRequest->getHost());
        if (! in_array($sHost, $this->readTrustedHosts(), true)) {
            return [];
        }

        $iIndex = $this->obResolver->resolve($sHost);
        if ($iIndex === null) {
            return [];
        }

        $iCreationMs = (int) (microtime(true) * 1000);
        $arFresh = [];

        if ($this->readCookie($obRequest, self::COOKIE_FBP) === null) {
            $arFresh[self::COOKIE_FBP] = sprintf('fb.%d.%d.%s', $iIndex, $iCreationMs, bin2hex(random_bytes(8)));
        }

        $sFbc = $this->buildFbcFromClick($obRequest, $iIndex, $iCreationMs);
        if ($sFbc !== null) {
            $arFresh[self::COOKIE_FBC] = $sFbc;
        }

        return $arFresh;
    }

    /**
     * Resolve the inner pipeline response with a runtime type narrowing guard.
     * Closure return type is unconstrained — phpstan level 10 cannot prove the
     * Response shape statically. The helper mirrors the SendCapiEvent
     * firstEventRecord + MetaClient decodeBody + Settings::lookupForSite
     * runtime-guard idiom for level-10 narrowing without a phpstan-ignore.
     *
     * Fail-SAFE on non-Response return values — the rest of this middleware
     * NO-OPs on every error path (untrusted host, missing PSL, kill-switch
     * lookup throwing). A LogicException here would contradict the docblock
     * ("no exception thrown") and turn an upstream-middleware bug into a 500
     * before Laravel's Kernel::handle could wrap the value. Log the surprise
     * and cast to an empty Response so downstream cookie writes still attach
     * correctly.
     */
    private function resolveResponse(Closure $fnNext, Request $obRequest): Response
    {
        $mResponse = $fnNext($obRequest);
        if ($mResponse instanceof Response) {
            return $mResponse;
        }

        Log::warning(
            'metapixel: middleware pipeline returned non-Response — wrapping in empty Response (fail-safe)',
            ['got' => get_debug_type($mResponse)],
        );

        $sBody = is_scalar($mResponse) ? (string) $mResponse : '';

        return new Response($sBody, 200);
    }

    /**
     * Short-circuit predicate. Backend paths skip; PluginGuard-disabled
     * plugins skip; kill switch off skips. Settings::get throwing falls back
     * to enabled per Pitfall 8 — boundary fail-safe so the initial migration
     * HTTP request does not 500 when system_settings is absent.
     */
    private function shouldSkip(Request $obRequest): bool
    {
        $mBackendUri = config('cms.backendUri', 'backend');
        $sBackendUri = is_scalar($mBackendUri) ? (string) $mBackendUri : '';
        if ($sBackendUri !== '' && $obRequest->is(ltrim($sBackendUri, '/').'*')) {
            return true;
        }

        try {
            // PluginGuard reads Settings::get('pixel_id') — same boundary
            // fail-safe rationale as the kill-switch lookup below (initial
            // migration HTTP request must not 500 when system_settings is
            // absent). isDisabled() memoises the result, so the wrap is a
            // first-request-only cost.
            if (PluginGuard::isDisabled()) {
                return true;
            }

            $mToggle = Settings::get('ensure_fbp_fbc_server_side', true);

            return ! ($mToggle === true || $mToggle === 1 || $mToggle === '1');
        } catch (Throwable $obException) {
            // boundary fail-safe: missing system_settings table during initial
            // migration must not 500 the HTTP request — default to enabled and
            // let downstream readTrustedHosts handle the empty-list NO-OP.
            Log::warning(
                'metapixel: PluginGuard or kill-switch lookup threw — middleware defaults to enabled',
                ['exception' => get_class($obException)]
            );

            return false;
        }
    }

    /**
     * Read Settings.trusted_hosts as a lowercase-trimmed non-empty host list.
     * Returns an empty list on any read failure so the host-trust check below
     * NO-OPs the middleware (fail-safe — never throws).
     *
     * @return list<string>
     */
    private function readTrustedHosts(): array
    {
        try {
            $mRaw = Settings::get('trusted_hosts', '');
        } catch (Throwable $obException) {
            // boundary fail-safe — same rationale as shouldSkip's catch block.
            Log::warning(
                'metapixel: trusted_hosts lookup threw — middleware NO-OPs',
                ['exception' => get_class($obException)]
            );

            return [];
        }

        if (! is_string($mRaw) || $mRaw === '') {
            return [];
        }

        $mLines = preg_split('/\R/', $mRaw);
        if ($mLines === false) {
            return [];
        }

        $arHosts = [];
        foreach ($mLines as $sLine) {
            $sClean = strtolower(trim($sLine));
            if ($sClean !== '') {
                $arHosts[] = $sClean;
            }
        }

        return $arHosts;
    }

    /**
     * Build _fbc as fb.{subdomain-index}.{creation-time-ms}.{fbclid} from a
     * charset-valid, length-capped ?fbclid. Invalid input is skipped silently
     * (no throw) per CR-03. The newest click wins over a stored cookie; a
     * repeat of the click already stored keeps the cookie and its timestamp.
     */
    private function buildFbcFromClick(Request $obRequest, int $iIndex, int $iMillis): ?string
    {
        $mFbclid = $obRequest->query('fbclid', '');
        $sFbclid = is_scalar($mFbclid) ? (string) $mFbclid : '';

        if ($sFbclid === '' || strlen($sFbclid) > self::FBCLID_MAX_LENGTH) {
            return null;
        }
        if (preg_match(self::FBCLID_ALLOWED_PATTERN, $sFbclid) !== 1) {
            return null;
        }

        $sExisting = $this->readCookie($obRequest, self::COOKIE_FBC);
        if ($sExisting !== null && $this->carriesClick($sExisting, $sFbclid)) {
            return null;
        }

        return sprintf('fb.%d.%d.%s', $iIndex, $iMillis, $sFbclid);
    }

    /**
     * True when an _fbc value (fb.{index}.{ms}.{fbclid}[.{appendix}]) already
     * holds the given click id.
     */
    private function carriesClick(string $sFbc, string $sFbclid): bool
    {
        $arParts = explode('.', $sFbc, 4);
        if (count($arParts) !== 4) {
            return false;
        }

        $sTail = $arParts[3];

        return $sTail === $sFbclid || str_starts_with($sTail, $sFbclid.'.');
    }

    /** Non-empty string cookie value from the request, else null. */
    private function readCookie(Request $obRequest, string $sName): ?string
    {
        $mValue = $obRequest->cookies->get($sName);

        return is_string($mValue) && $mValue !== '' ? $mValue : null;
    }
}
