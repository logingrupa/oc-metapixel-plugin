<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\SiteResolver;
use Logingrupa\Metapixel\Tests\Doubles\FakeAdapter;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

final class SiteResolverTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    public function test_for_subject_delegates_to_adapter_get_site_id(): void
    {
        $obAdapter = (new FakeAdapter)->withSiteId(7);
        $iResult = SiteResolver::forSubject(new stdClass, $obAdapter);
        $this->assertSame(7, $iResult);
    }

    public function test_for_subject_propagates_null_from_adapter(): void
    {
        $obAdapter = new FakeAdapter; // default site_id is null
        $iResult = SiteResolver::forSubject(new stdClass, $obAdapter);
        $this->assertNull($iResult);
    }

    /**
     * Static defence: SiteResolver.php must contain no reference to Request,
     * SiteManager, Site facade, or the global request() helper. Cross-context
     * determinism is enforced statically here in addition to the phpstan rule.
     */
    public function test_site_resolver_makes_no_request_or_site_manager_calls(): void
    {
        $sSource = file_get_contents(__DIR__.'/../../../classes/helper/SiteResolver.php');
        $this->assertIsString($sSource);
        $this->assertDoesNotMatchRegularExpression('/\bSiteManager\b/', $sSource);
        $this->assertDoesNotMatchRegularExpression('/\bSite::/', $sSource);
        $this->assertDoesNotMatchRegularExpression('/\bRequest::/', $sSource);
        $this->assertDoesNotMatchRegularExpression('/\brequest\s*\(/', $sSource);
    }
}
