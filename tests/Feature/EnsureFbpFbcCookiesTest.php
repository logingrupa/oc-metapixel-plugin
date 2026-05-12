<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixelshopaholic\Middleware\EnsureFbpFbcCookies;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Feature test covering SKEL-03's nine invariants for EnsureFbpFbcCookies.
 *
 *   1. _fbp is set when missing on the apex domain — format
 *      `fb.1.{13-digit-ms-timestamp}.{16-hex-random}`
 *   2. _fbp subdomain-index = 2 for `www.` host
 *   3. _fbp subdomain-index capped at 2 for deep subdomains
 *   4. Existing _fbp cookie is NOT overwritten
 *   5. _fbc is set with format `fb.{idx}.{ts}.{fbclid}` when ?fbclid present
 *      and no _fbc cookie was sent
 *   6. _fbc is NOT set when fbclid query is absent
 *   7. Existing _fbc cookie is NOT overwritten when fbclid is also present
 *   8. Cookie attributes match Meta spec on HTTPS request (90d, path=/,
 *      domain=null, secure=true, httpOnly=false, SameSite=lax)
 *   9. Middleware short-circuits when App::make('metapixel.disabled') is true
 *
 * Uses direct-handle pattern (no HTTP routing) — synthesises Request via
 * Request::create and invokes EnsureFbpFbcCookies::handle() with a closure
 * returning a fresh Response. Avoids HTTP kernel boot overhead.
 *
 * W5 fix: HTTPS-secure assertion (Test 8) seeds `HTTPS=on` on the request
 * server bag so Request::secure() returns true deterministically without
 * depending on TrustedProxies / X-Forwarded-Proto headers.
 */
final class EnsureFbpFbcCookiesTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PluginGuard's container binding (`metapixel.disabled`) was bound by
        // Plugin::boot() during `createApplication()` and resolves to TRUE
        // because the hermetic `system_settings` table has no `pixel_id` row.
        // For these tests we want to exercise the middleware's cookie-setting
        // path, so explicitly bind `metapixel.disabled` to FALSE for the
        // duration of each test. Test 9 (`short_circuits_when_plugin_disabled`)
        // overrides this binding with `true` to exercise the short-circuit
        // branch.
        App::singleton('metapixel.disabled', fn (): bool => false);
    }

    public function test_sets_fbp_when_missing_on_apex_domain(): void
    {
        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/foo');

        $obFbp = $this->getResponseCookie($obResponse, '_fbp');

        $this->assertNotNull($obFbp, '_fbp cookie must be set when missing on apex domain.');
        $this->assertMatchesRegularExpression(
            '/^fb\.1\.\d{13}\.[0-9a-f]{16}$/',
            (string) $obFbp->getValue(),
            '_fbp must match Meta-spec format fb.1.{13-digit-ms}.{16-hex}.'
        );
    }

    public function test_sets_fbp_with_subdomain_index_2_for_www(): void
    {
        $obResponse = $this->invokeMiddleware('https://www.nailscosmetics.lv/foo');

        $obFbp = $this->getResponseCookie($obResponse, '_fbp');

        $this->assertNotNull($obFbp, '_fbp cookie must be set on www subdomain.');
        $this->assertMatchesRegularExpression(
            '/^fb\.2\./',
            (string) $obFbp->getValue(),
            '_fbp subdomain-index must be 2 for www host.'
        );
    }

    public function test_caps_subdomain_index_at_2_for_deep_subdomains(): void
    {
        $obResponse = $this->invokeMiddleware('https://a.b.c.d.example.lv/foo');

        $obFbp = $this->getResponseCookie($obResponse, '_fbp');

        $this->assertNotNull($obFbp, '_fbp cookie must be set on deep-subdomain host.');
        $this->assertMatchesRegularExpression(
            '/^fb\.2\./',
            (string) $obFbp->getValue(),
            '_fbp subdomain-index must be capped at 2 for deeper hosts.'
        );
    }

    public function test_does_not_overwrite_existing_fbp(): void
    {
        $obResponse = $this->invokeMiddleware(
            'https://nailscosmetics.lv/foo',
            ['_fbp' => 'fb.1.1234567890123.abc123def4567890']
        );

        $obFbp = $this->getResponseCookie($obResponse, '_fbp');

        $this->assertNull(
            $obFbp,
            'Existing _fbp cookie must not be overwritten — no Set-Cookie header expected.'
        );
    }

    public function test_sets_fbc_when_fbclid_present(): void
    {
        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/?fbclid=ClickIdABC');

        $obFbc = $this->getResponseCookie($obResponse, '_fbc');

        $this->assertNotNull($obFbc, '_fbc cookie must be set when fbclid query is present.');
        $this->assertMatchesRegularExpression(
            '/^fb\.1\.\d{13}\.ClickIdABC$/',
            (string) $obFbc->getValue(),
            '_fbc must match fb.{idx}.{ts}.{fbclid} format.'
        );
    }

    public function test_does_not_set_fbc_when_fbclid_absent(): void
    {
        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/');

        $obFbc = $this->getResponseCookie($obResponse, '_fbc');

        $this->assertNull(
            $obFbc,
            'Synthetic _fbc must NOT be invented when fbclid query is absent.'
        );
    }

    public function test_does_not_overwrite_existing_fbc(): void
    {
        $obResponse = $this->invokeMiddleware(
            'https://nailscosmetics.lv/?fbclid=ClickIdABC',
            ['_fbc' => 'fb.1.1234567890123.PreExistingClickId']
        );

        $obFbc = $this->getResponseCookie($obResponse, '_fbc');

        $this->assertNull(
            $obFbc,
            'Existing _fbc must not be overwritten even when fbclid query is present.'
        );
    }

    public function test_cookie_attributes_match_meta_spec(): void
    {
        // W5 fix: HTTPS=on seeded on the server bag so Request::secure() === true
        // deterministically, regardless of TrustedProxies configuration.
        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/foo', [], true);

        $obFbp = $this->getResponseCookie($obResponse, '_fbp');
        $this->assertNotNull($obFbp);

        $iExpectedExpiry = time() + (60 * 60 * 24 * 90);
        $this->assertEqualsWithDelta(
            $iExpectedExpiry,
            $obFbp->getExpiresTime(),
            2,
            '_fbp expiry must be ~90 days from now.'
        );
        $this->assertSame('/', $obFbp->getPath(), '_fbp path must be /.');
        $this->assertNull($obFbp->getDomain(), '_fbp domain must be null (implicit current-host).');
        $this->assertTrue($obFbp->isSecure(), '_fbp must be marked secure on HTTPS requests.');
        $this->assertFalse(
            $obFbp->isHttpOnly(),
            '_fbp must NOT be httpOnly — browser must read it for the fbq library.'
        );
        $this->assertSame('lax', $obFbp->getSameSite(), '_fbp must have SameSite=Lax.');
    }

    public function test_short_circuits_when_plugin_disabled(): void
    {
        App::singleton('metapixel.disabled', fn (): bool => true);

        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/?fbclid=ClickIdABC');

        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbp'),
            '_fbp must not be set when plugin is disabled.'
        );
        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbc'),
            '_fbc must not be set when plugin is disabled.'
        );
    }

    /**
     * Build a synthetic Request and pipe it through EnsureFbpFbcCookies.
     *
     * @param  array<string, string>  $arCookies
     */
    private function invokeMiddleware(string $sUrl, array $arCookies = [], bool $bHttps = false): SymfonyResponse
    {
        $arServer = $bHttps ? ['HTTPS' => 'on'] : [];
        $obRequest = Request::create($sUrl, 'GET', [], $arCookies, [], $arServer);

        $obMiddleware = new EnsureFbpFbcCookies;

        return $obMiddleware->handle(
            $obRequest,
            fn (Request $obReq): Response => new Response('ok')
        );
    }

    private function getResponseCookie(SymfonyResponse $obResponse, string $sName): ?Cookie
    {
        foreach ($obResponse->headers->getCookies() as $obCookie) {
            if ($obCookie->getName() === $sName) {
                return $obCookie;
            }
        }

        return null;
    }
}
