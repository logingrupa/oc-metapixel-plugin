<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wave 0 RED — fails until plan 04-03 middleware production code ships.
 *
 * COOK-01 kill switch + COOK-02 CR-03 fbclid validation + CR-02 untrusted-host
 * NO-OP + D-20 fresh-derivation cookie format locks + Pitfall 8 Settings::get
 * boundary fail-safe. The MetapixelTestCase parent provides the hermetic
 * SQLite + system_settings boot; the resolver singleton is rebound against
 * the in-tree test_psl.dat fixture so the full ~280 KB PSL never loads.
 */
final class EnsureFbpFbcCookiesTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);

        // Bind the resolver against the hermetic 18-line PSL subset committed
        // in plan 04-02 Task 1 (tests/fixtures/data/test_psl.dat). example.com
        // + example.co.uk are present in the fixture.
        $this->app->instance(
            HostIndexResolver::class,
            new HostIndexResolver(__DIR__.'/../../fixtures/data/test_psl.dat')
        );

        // Seed Settings rows so the middleware reads non-default values.
        Settings::clearInternalCache();
        Settings::set([
            'trusted_hosts' => "example.com\nshop.example.co.uk",
            'ensure_fbp_fbc_server_side' => true,
        ]);
    }

    // -----------------------------------------------------------------------
    // Kill switch (COOK-01)
    // -----------------------------------------------------------------------

    public function test_kill_switch_off_skips_cookie_write(): void
    {
        Settings::set(['ensure_fbp_fbc_server_side' => false]);
        Settings::clearInternalCache();

        $obResponse = $this->dispatchRequest('example.com');

        $this->assertNull($this->extractCookie($obResponse, '_fbp'));
        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_kill_switch_default_true_writes_cookies_when_trusted(): void
    {
        $obResponse = $this->dispatchRequest('example.com');

        $this->assertNotNull($this->extractCookie($obResponse, '_fbp'));
    }

    public function test_kill_switch_on_with_string_value_1_writes_cookies(): void
    {
        Settings::set(['ensure_fbp_fbc_server_side' => '1']);
        Settings::clearInternalCache();

        $obResponse = $this->dispatchRequest('example.com');

        $this->assertNotNull($this->extractCookie($obResponse, '_fbp'));
    }

    // -----------------------------------------------------------------------
    // fbclid validation (COOK-02 / CR-03)
    // -----------------------------------------------------------------------

    public function test_valid_fbclid_writes_fbc_cookie(): void
    {
        $obResponse = $this->dispatchRequest('example.com', 'IwAR1abc_XYZ-123');

        $obCookie = $this->extractCookie($obResponse, '_fbc');
        $this->assertNotNull($obCookie);
        $this->assertMatchesRegularExpression(
            '/^fb\.\d+\.\d+\.IwAR1abc_XYZ-123$/',
            (string) $obCookie->getValue()
        );
    }

    public function test_invalid_fbclid_charset_skips_fbc(): void
    {
        $obResponse = $this->dispatchRequest('example.com', 'ab<script>');

        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_oversize_fbclid_skips_fbc(): void
    {
        // 256-char alphanumeric fbclid — exactly one over the CR-03 cap.
        $sOversize = str_repeat('A', 256);

        $obResponse = $this->dispatchRequest('example.com', $sOversize);

        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_exactly_255_char_fbclid_is_accepted(): void
    {
        $sExactly = str_repeat('A', 255);

        $obResponse = $this->dispatchRequest('example.com', $sExactly);

        $this->assertNotNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_existing_fbc_cookie_not_overwritten(): void
    {
        $obResponse = $this->dispatchRequest(
            'example.com',
            'IwAR1validfbclid_123',
            ['_fbc' => 'fb.1.1700000000.PREVIOUSFBCLID']
        );

        // _fbc was already on the request → middleware must NOT add a new one.
        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_no_fbclid_query_skips_fbc_but_still_writes_fbp(): void
    {
        $obResponse = $this->dispatchRequest('example.com');

        $this->assertNotNull($this->extractCookie($obResponse, '_fbp'));
        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    // -----------------------------------------------------------------------
    // Host trust (CR-02 / P-15)
    // -----------------------------------------------------------------------

    public function test_untrusted_host_writes_no_cookies(): void
    {
        $obResponse = $this->dispatchRequest('attacker.example.org', 'IwAR1abc_XYZ-123');

        $this->assertNull($this->extractCookie($obResponse, '_fbp'));
        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_psl_unresolvable_host_writes_no_cookies(): void
    {
        // Add an unresolvable host to trusted_hosts so the host-trust gate
        // passes. The resolver returns null for the unknown-TLD host → middleware
        // NO-OPs (defence in depth — Settings::beforeSave would normally reject
        // this at save time, but the middleware MUST also self-defend).
        Settings::set(['trusted_hosts' => "example.com\nwat.fakeylock"]);
        Settings::clearInternalCache();

        $obResponse = $this->dispatchRequest('wat.fakeylock', 'IwAR1abc_XYZ-123');

        $this->assertNull($this->extractCookie($obResponse, '_fbp'));
        $this->assertNull($this->extractCookie($obResponse, '_fbc'));
    }

    public function test_existing_fbp_cookie_not_overwritten(): void
    {
        $obResponse = $this->dispatchRequest(
            'example.com',
            null,
            ['_fbp' => 'fb.1.1700000000.0123456789abcdef']
        );

        // _fbp on request → middleware must NOT add a new Set-Cookie for it.
        $this->assertNull($this->extractCookie($obResponse, '_fbp'));
    }

    // -----------------------------------------------------------------------
    // Boundary fail-safe (Pitfall 8)
    // -----------------------------------------------------------------------

    public function test_settings_get_throwing_does_not_500(): void
    {
        // Drop the system_settings table so Settings::get throws when reading
        // the kill switch. The middleware MUST swallow the Throwable inside
        // shouldSkip, default to enabled, and let the pipeline response pass
        // through. A Log::warning fires (asserted via the facade swap).
        Log::shouldReceive('warning')->atLeast()->once();
        Schema::dropIfExists('system_settings');

        $obResponse = $this->dispatchRequest('example.com');

        // Middleware did not 500 → the inner pipeline's response carried through.
        $this->assertSame(200, $obResponse->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Cookie format (D-20 v1.x carry-forward)
    // -----------------------------------------------------------------------

    public function test_fbp_cookie_format_matches_fb_index_ms_random(): void
    {
        // shop.example.co.uk has 1 subdomain label → index = 2.
        $obResponse = $this->dispatchRequest('shop.example.co.uk');

        $obCookie = $this->extractCookie($obResponse, '_fbp');
        $this->assertNotNull($obCookie);
        $this->assertMatchesRegularExpression(
            '/^fb\.2\.\d{13}\.[0-9a-f]{16}$/',
            (string) $obCookie->getValue(),
            'COOKIE_FBP must encode fb.{subdomain-index}.{13-digit-ms}.{16-hex-CSPRNG}'
        );
    }

    public function test_fbc_cookie_format_matches_fb_index_ms_fbclid(): void
    {
        // example.com apex → subdomain index = 1.
        $obResponse = $this->dispatchRequest('example.com', 'FBCLID_TOKEN_123');

        $obCookie = $this->extractCookie($obResponse, '_fbc');
        $this->assertNotNull($obCookie);
        $this->assertMatchesRegularExpression(
            '/^fb\.1\.\d{13}\.FBCLID_TOKEN_123$/',
            (string) $obCookie->getValue(),
            'COOKIE_FBC must encode fb.{subdomain-index}.{13-digit-ms}.{fbclid}'
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a Request, resolve the middleware from the container, invoke
     * handle($obRequest, fn() => new Response('ok')), and return the response.
     *
     * @param  array<string, string>  $arExistingCookies
     */
    private function dispatchRequest(
        string $sHost,
        ?string $sFbclidQuery = null,
        array $arExistingCookies = []
    ): Response {
        $arServer = [
            'HTTP_HOST' => $sHost,
            'HTTPS' => 'on',
            'SERVER_PORT' => 443,
        ];
        $arQuery = $sFbclidQuery === null ? [] : ['fbclid' => $sFbclidQuery];

        $obRequest = Request::create(
            'https://'.$sHost.'/',
            'GET',
            $arQuery,
            $arExistingCookies,
            [],
            $arServer
        );

        $obMiddleware = $this->app->make(EnsureFbpFbcCookies::class);

        return $obMiddleware->handle(
            $obRequest,
            static fn (): Response => new Response('ok', 200)
        );
    }

    /**
     * Pluck a response cookie by name. Symfony's getCookies returns Cookie
     * objects; return null when not present.
     */
    private function extractCookie(Response $obResponse, string $sName): ?Cookie
    {
        foreach ($obResponse->headers->getCookies() as $obCookie) {
            if ($obCookie->getName() === $sName) {
                return $obCookie;
            }
        }

        return null;
    }
}
