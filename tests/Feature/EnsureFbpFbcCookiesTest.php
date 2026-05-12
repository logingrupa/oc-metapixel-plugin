<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Logingrupa\Metapixelshopaholic\Middleware\EnsureFbpFbcCookies;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Feature test covering SKEL-03's nine original invariants for EnsureFbpFbcCookies,
 * extended in REVIEW-FIX iteration 1 with four post-blocker invariants:
 *
 *   1. _fbp is set when missing on the apex domain — format
 *      `fb.1.{13-digit-ms-timestamp}.{16-hex-random}`
 *   2. _fbp subdomain-index = 2 for `www.` host
 *   3. Untrusted hosts (not in HOST_INDEX_MAP) set NO cookies (CR-02 lock).
 *      Replaces the old "cap at 2 for deep subdomains" case — the new
 *      allowlist-based design refuses untrusted hosts outright.
 *   4. Existing _fbp cookie is NOT overwritten
 *   5. _fbc is set with format `fb.{idx}.{ts}.{fbclid}` when valid ?fbclid
 *      present and no _fbc cookie was sent
 *   6. _fbc is NOT set when fbclid query is absent
 *   7. Existing _fbc cookie is NOT overwritten when fbclid is also present
 *   8. Cookie attributes match Meta spec on HTTPS request (90d, path=/,
 *      domain=null, secure=true, httpOnly=false, SameSite=lax)
 *   9. Middleware short-circuits when App::make('metapixel.disabled') is true
 *  10. Middleware short-circuits when ensure_fbp_fbc_server_side toggle is OFF
 *      (CR-01 lock — Settings is the operator's cookie-consent kill-switch).
 *  11. Over-length fbclid is rejected (CR-03 lock — Symfony does not cap).
 *  12. Malformed fbclid (forbidden characters) is rejected (CR-03 lock).
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

        // CR-01 lock: the Settings toggle defaults to true (fields.yaml). In
        // the hermetic SQLite harness the `system_settings` row is absent, so
        // Settings::get() returns the default — i.e. the middleware runs. The
        // CR-01 negative-path test (`short_circuits_when_settings_toggle_off`)
        // explicitly writes the row to flip the toggle off.
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

    public function test_does_not_set_cookies_on_untrusted_host(): void
    {
        // CR-02 lock: hosts not in HOST_INDEX_MAP must NOT receive cookies.
        // Replaces the legacy "deep subdomains cap at 2" case — the
        // allowlist design refuses untrusted hosts outright instead of
        // guessing an index for a spoofable `Host:` header.
        $obResponse = $this->invokeMiddleware('https://a.b.c.d.example.lv/foo');

        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbp'),
            '_fbp must not be set on untrusted host (CR-02).'
        );
        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbc'),
            '_fbc must not be set on untrusted host (CR-02).'
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

    public function test_short_circuits_when_settings_toggle_off(): void
    {
        // CR-01 lock: when the operator flips `ensure_fbp_fbc_server_side`
        // OFF, the middleware MUST become a no-op (no cookies set) even
        // when the plugin is otherwise enabled and a fbclid is present.
        // Provision the system_settings row directly so Settings::get()
        // returns false. The hermetic `system_settings` table is provisioned
        // by MetapixelTestCase::createApplication().
        \DB::table('system_settings')->insert([
            'item' => Settings::SETTINGS_CODE,
            'value' => json_encode(['ensure_fbp_fbc_server_side' => false]),
        ]);
        Settings::clearInternalCache();
        \Illuminate\Support\Facades\Cache::flush();

        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/?fbclid=ClickIdABC');

        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbp'),
            '_fbp must not be set when ensure_fbp_fbc_server_side toggle is OFF.'
        );
        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbc'),
            '_fbc must not be set when ensure_fbp_fbc_server_side toggle is OFF.'
        );
    }

    public function test_rejects_overlong_fbclid(): void
    {
        // CR-03 lock: Symfony Cookie::create does NOT length-cap. Local
        // enforcement prevents 4 KiB+ cookie poisoning.
        $sLong = str_repeat('A', 300); // > FBCLID_MAX_LENGTH (255)
        $obResponse = $this->invokeMiddleware('https://nailscosmetics.lv/?fbclid='.$sLong);

        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbc'),
            '_fbc must not be set when fbclid exceeds the length cap (CR-03).'
        );
    }

    public function test_rejects_malformed_fbclid_charset(): void
    {
        // CR-03 lock: fbclid is documented `[A-Za-z0-9_-]+`. Anything else is
        // a malformed/attacker-supplied value and must be rejected outright.
        $obResponse = $this->invokeMiddleware(
            'https://nailscosmetics.lv/?fbclid='.urlencode("bad';alert(1)//")
        );

        $this->assertNull(
            $this->getResponseCookie($obResponse, '_fbc'),
            '_fbc must not be set when fbclid contains disallowed characters (CR-03).'
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
