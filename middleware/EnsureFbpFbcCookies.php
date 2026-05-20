<?php

namespace Logingrupa\Metapixel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Models\Settings;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Server-side _fbp + _fbc cookie writer for Meta CAPI deduplication anchors.
 * Honors the operator kill switch, validates fbclid charset + length, and
 * defers host-trust resolution to the PSL-aware HostIndexResolver. Untrusted
 * host → middleware NO-OPs (no cookies set, no exception thrown).
 *
 * Operator MUST serve routes hitting this middleware with Cache-Control: private
 * to prevent shared-cache cookie leakage. See README "Cookie middleware" section.
 */
class EnsureFbpFbcCookies
{
    private const COOKIE_TTL_SECONDS = 60 * 60 * 24 * 90;

    private const COOKIE_FBP = '_fbp';

    private const COOKIE_FBC = '_fbc';

    private const FBCLID_MAX_LENGTH = 255;

    private const FBCLID_ALLOWED_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public function __construct(private readonly HostIndexResolver $obResolver) {}

    public function handle(Request $obRequest, Closure $fnNext): Response
    {
        $obResponse = $this->resolveResponse($fnNext, $obRequest);

        if ($this->shouldSkip($obRequest)) {
            return $obResponse;
        }

        $sHost = strtolower($obRequest->getHost());
        $arTrustedHosts = $this->readTrustedHosts();
        if (! in_array($sHost, $arTrustedHosts, true)) {
            return $obResponse;
        }

        $iIndex = $this->obResolver->resolve($sHost);
        if ($iIndex === null) {
            return $obResponse;
        }

        $iCreationMs = (int) (microtime(true) * 1000);
        $bSecure = $obRequest->secure();
        $iExpire = time() + self::COOKIE_TTL_SECONDS;

        $this->maybeSetFbp($obRequest, $obResponse, $iIndex, $iCreationMs, $iExpire, $bSecure);
        $this->maybeSetFbc($obRequest, $obResponse, $iIndex, $iCreationMs, $iExpire, $bSecure);

        return $obResponse;
    }

    /**
     * Resolve the inner pipeline response with a runtime type narrowing guard.
     * Closure return type is unconstrained — phpstan level 10 cannot prove the
     * Response shape statically. The helper mirrors the SendCapiEvent
     * firstEventRecord + MetaClient decodeBody + Settings::lookupForSite
     * runtime-guard idiom for level-10 narrowing without a phpstan-ignore.
     */
    private function resolveResponse(Closure $fnNext, Request $obRequest): Response
    {
        $mResponse = $fnNext($obRequest);
        if (! $mResponse instanceof Response) {
            throw new \LogicException(
                'metapixel: middleware pipeline returned non-Response — got '.get_debug_type($mResponse)
            );
        }

        return $mResponse;
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

        if (App::bound('metapixel.disabled') && App::make('metapixel.disabled')) {
            return true;
        }

        try {
            $mToggle = Settings::get('ensure_fbp_fbc_server_side', true);

            return ! ($mToggle === true || $mToggle === 1 || $mToggle === '1');
        } catch (Throwable $obException) {
            // boundary fail-safe: missing system_settings table during initial
            // migration must not 500 the HTTP request — default to enabled and
            // let downstream readTrustedHosts handle the empty-list NO-OP.
            Log::warning(
                'metapixel: kill-switch lookup threw — middleware defaults to enabled',
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
     * Write the _fbp cookie when the request does not already carry one.
     * Format: fb.{subdomain-index}.{creation-time-ms}.{16-hex CSPRNG}.
     */
    private function maybeSetFbp(
        Request $obRequest,
        Response $obResponse,
        int $iIndex,
        int $iMillis,
        int $iExpire,
        bool $bSecure
    ): void {
        if ($obRequest->cookies->has(self::COOKIE_FBP)) {
            return;
        }

        $sFbp = sprintf('fb.%d.%d.%s', $iIndex, $iMillis, bin2hex(random_bytes(8)));
        $obCookie = Cookie::create(
            self::COOKIE_FBP,
            $sFbp,
            $iExpire,
            '/',
            null,
            $bSecure,
            false,
            false,
            'lax'
        );
        $obResponse->headers->setCookie($obCookie);
    }

    /**
     * Write the _fbc cookie when the request carries a charset-valid + length-
     * capped fbclid query and no pre-existing _fbc. Invalid input is skipped
     * silently (no throw) per CR-03.
     */
    private function maybeSetFbc(
        Request $obRequest,
        Response $obResponse,
        int $iIndex,
        int $iMillis,
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
        if ($obRequest->cookies->has(self::COOKIE_FBC)) {
            return;
        }

        $sFbc = sprintf('fb.%d.%d.%s', $iIndex, $iMillis, $sFbclid);
        $obCookie = Cookie::create(
            self::COOKIE_FBC,
            $sFbc,
            $iExpire,
            '/',
            null,
            $bSecure,
            false,
            false,
            'lax'
        );
        $obResponse->headers->setCookie($obCookie);
    }
}
