<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Theme;

use Cms\Classes\Controller;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxHandler;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * Identity firewall on the Metapixel::onFireEvent theme-action path. The
 * client controls ONLY name + action_key; every CAPI identity field,
 * secret_key, and site_id is server-derived. Also verifies the inverse:
 * server-captured client_ip_address / client_user_agent / fbp / fbc reach the
 * dispatched CAPI payload so Meta does not reject the event (subcode 2804050).
 */
#[Group('adapter')]
final class ThemeAjaxHandlerServerUserDataTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::shouldReceive('input')->andReturnNull()->byDefault();
        Request::shouldReceive('userAgent')->andReturnNull()->byDefault();
        Request::shouldReceive('cookie')->andReturnNull()->byDefault();
        $this->app->singleton(AdapterRegistry::class);
        App::make(AdapterRegistry::class)->register(
            ThemeActionEvent::class,
            ThemeActionAdapter::class,
        );
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
        ]);
        $this->app->forgetInstance(RateLimiter::class);
        Session::shouldReceive('getId')->andReturn('session-server-userdata');
        Request::shouldReceive('ip')->andReturn('203.0.113.9');
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(AdapterRegistry::class);
        $this->app->forgetInstance(RateLimiter::class);
        parent::tearDown();
    }

    public function test_client_supplied_identity_config_fields_are_stripped(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Lead',
            'action_key' => 'lead:contact-form',
            'em' => 'victim@example.com',
            'ph' => '+37120000000',
            'external_id' => 'victim-1',
            'fbp' => 'fb.1.forged.fbp',
            'fbc' => 'fb.1.forged.fbc',
            'client_ip_address' => '6.6.6.6',
            'client_user_agent' => 'Forged/1.0',
            'secret_key' => 'attacker-secret',
            'site_id' => 99,
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            $arUserData = $obJob->arPayload['data'][0]['user_data'] ?? [];
            $obSubject = $obJob->obSubject;

            // A surviving injected field would be a non-null hash/passthrough
            // string — `?? null` collapses "absent" and "stripped to null".
            return ($arUserData['em'] ?? null) === null
                && ($arUserData['ph'] ?? null) === null
                && ($arUserData['external_id'] ?? null) === null
                && ($arUserData['fbp'] ?? null) === null
                && ($arUserData['fbc'] ?? null) === null
                && ($arUserData['client_ip_address'] ?? null) === '203.0.113.9'
                && ($arUserData['client_user_agent'] ?? null) === null
                && $obSubject instanceof ThemeActionEvent
                && ($obSubject->arPayload['secret_key'] ?? null) === null
                && ($obSubject->arPayload['site_id'] ?? null) !== 99;
        });
    }

    public function test_server_captured_user_data_reaches_capi_payload(): void
    {
        Request::shouldReceive('userAgent')->andReturn('Honest/2.0');
        Request::shouldReceive('cookie')->with('_fbp', null)->andReturn('fb.1.111.222');
        Request::shouldReceive('cookie')->with('_fbc', null)->andReturn('fb.1.333.444');
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Subscribe',
            'action_key' => 'newsletter:footer',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            $arUserData = $obJob->arPayload['data'][0]['user_data'] ?? [];

            return ($arUserData['client_ip_address'] ?? null) === '203.0.113.9'
                && ($arUserData['client_user_agent'] ?? null) === 'Honest/2.0'
                && ($arUserData['fbp'] ?? null) === 'fb.1.111.222'
                && ($arUserData['fbc'] ?? null) === 'fb.1.333.444';
        });
    }
}
