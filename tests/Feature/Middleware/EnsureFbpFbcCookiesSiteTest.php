<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Middleware\EnsureFbpFbcCookies;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Support\Facades\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cookie middleware runs before CmsController applies the active site, so
 * it resolves the request's own site from host + route prefix and reads the
 * guard, kill switch and trusted hosts for that site. Backend paths skip.
 */
final class EnsureFbpFbcCookiesSiteTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        $this->app->instance(
            HostIndexResolver::class,
            new HostIndexResolver(__DIR__.'/../../fixtures/data/test_psl.dat')
        );
        $fnSeedSites = require __DIR__.'/../../fixtures/sites.php';
        $fnSeedSites(
            $this->app['db']->connection()->getSchemaBuilder(),
            $this->app['db']->connection(),
        );
        DB::table('system_site_definitions')->where('id', 2)->update(['is_prefixed' => 1, 'route_prefix' => '/two']);
        Site::resetCache();
    }

    protected function tearDown(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('system_site_definitions');
        Site::resetCache();
        parent::tearDown();
    }

    public function test_prefixed_site_with_its_own_pixel_gets_cookies_while_the_primary_is_off(): void
    {
        $this->insertRow(1, ['pixel_id' => '', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);
        $this->insertRow(2, ['pixel_id' => '1234567890', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);

        $this->assertTrue($this->hasFbp($this->dispatchPath('/two/page')));
        $this->assertFalse($this->hasFbp($this->dispatchPath('/page')));
    }

    public function test_disabled_site_prefix_falls_back_to_the_primary_site_settings(): void
    {
        DB::table('system_site_definitions')->where('id', 2)->update(['is_enabled' => 0]);
        Site::resetCache();
        $this->insertRow(1, ['pixel_id' => '1234567890', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);
        $this->insertRow(2, ['pixel_id' => 'owner@example.com', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);

        $this->assertTrue($this->hasFbp($this->dispatchPath('/two/page')));
    }

    public function test_request_matching_no_enabled_site_gets_no_cookies(): void
    {
        DB::table('system_site_definitions')->update(['is_enabled' => 0]);
        Site::resetCache();
        $this->insertRow(1, ['pixel_id' => '1234567890', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);

        $this->assertFalse($this->hasFbp($this->dispatchPath('/page')));
    }

    public function test_backend_uri_segments_skip_and_lookalike_frontend_paths_do_not(): void
    {
        config(['backend.uri' => '/back']);
        $this->insertRow(1, ['pixel_id' => '1234567890', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);

        $this->assertFalse($this->hasFbp($this->dispatchPath('/back')));
        $this->assertFalse($this->hasFbp($this->dispatchPath('/back/auth/signin')));
        $this->assertTrue($this->hasFbp($this->dispatchPath('/backoffice')));
    }

    public function test_backend_execution_context_gets_no_cookies(): void
    {
        config(['backend.uri' => '/back']);
        $this->insertRow(1, ['pixel_id' => '1234567890', 'trusted_hosts' => 'example.com', 'ensure_fbp_fbc_server_side' => true]);
        $this->app->instance('execution.context', 'backend');

        $this->assertFalse($this->hasFbp($this->dispatchPath('/backoffice')));
    }

    /** @param  array<string, mixed>  $arValue */
    private function insertRow(?int $iSiteId, array $arValue): void
    {
        DB::table('system_settings')->insert([
            'item' => 'logingrupa_metapixel_settings',
            'site_id' => $iSiteId,
            'value' => json_encode($arValue),
        ]);
    }

    private function dispatchPath(string $sPath): Response
    {
        $obRequest = Request::create('https://example.com'.$sPath, 'GET', [], [], [], [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SERVER_PORT' => 443,
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36',
        ]);

        return $this->app->make(EnsureFbpFbcCookies::class)->handle(
            $obRequest,
            static fn (Request $obInnerRequest): Response => new Response('ok', 200)
        );
    }

    private function hasFbp(Response $obResponse): bool
    {
        foreach ($obResponse->headers->getCookies() as $obCookie) {
            if ($obCookie->getName() === EnsureFbpFbcCookies::COOKIE_FBP) {
                return true;
            }
        }

        return false;
    }
}
