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
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-05 rate-limit semantics — 30 req / 60s decay window per IP+session key.
 * Backed by Illuminate\Cache\RateLimiter against the test `array` cache driver.
 */
#[Group('adapter')]
final class ThemeAjaxHandlerRateLimitTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::shouldReceive('input')->andReturnNull()->byDefault();
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
        // RateLimiter is resolved fresh per test — the array cache backing
        // it resets when the framework rebuilds the container.
        $this->app->forgetInstance(RateLimiter::class);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(AdapterRegistry::class);
        $this->app->forgetInstance(RateLimiter::class);
        parent::tearDown();
    }

    public function test_rate_limiter_allows_30_requests_in_60_seconds_window(): void
    {
        Request::shouldReceive('ip')->andReturn('10.0.0.1');
        Session::shouldReceive('getId')->andReturn('session-rate-1');
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'PageView',
            'action_key' => 'pv:1',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;

        for ($iAttempt = 1; $iAttempt <= 30; $iAttempt++) {
            $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');
            $this->assertInstanceOf(JsonResponse::class, $mResponse);
            $this->assertSame(200, $mResponse->getStatusCode(), "attempt {$iAttempt} expected 200");
        }
    }

    public function test_31st_request_returns_429_too_many_attempts(): void
    {
        Request::shouldReceive('ip')->andReturn('10.0.0.2');
        Session::shouldReceive('getId')->andReturn('session-rate-2');
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'PageView',
            'action_key' => 'pv:1',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;

        for ($iAttempt = 1; $iAttempt <= 30; $iAttempt++) {
            $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');
        }

        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');
        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(429, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertSame(['error' => 'rate limit exceeded'], $arBody);
    }

    public function test_rate_limiter_key_isolates_by_ip_session_pair(): void
    {
        Session::shouldReceive('getId')->andReturn('session-iso');
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'PageView',
            'action_key' => 'pv:iso',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;

        // Switch IP via a shared state read by an andReturnUsing closure so
        // both phases of the test draw from the same Mockery expectation.
        $sCurrentIp = '1.1.1.1';
        Request::shouldReceive('ip')->andReturnUsing(static function () use (&$sCurrentIp): string {
            return $sCurrentIp;
        });

        // First IP burns 30 allowed requests.
        for ($iAttempt = 1; $iAttempt <= 30; $iAttempt++) {
            $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');
            $this->assertSame(200, $mResponse->getStatusCode(), "attempt {$iAttempt} expected 200");
        }

        // Second IP still has its own bucket — should also see 200 on first request.
        $sCurrentIp = '2.2.2.2';
        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');
        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode(), 'second IP must not share the bucket of first IP');
    }
}
