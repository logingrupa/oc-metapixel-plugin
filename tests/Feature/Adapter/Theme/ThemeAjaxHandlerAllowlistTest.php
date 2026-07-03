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
 * THEM-05 allowlist semantics — Pitfall 9 guard, 422 on bad name, dispatch on
 * META_STANDARD name, dispatch on operator-supplied custom name.
 */
#[Group('adapter')]
final class ThemeAjaxHandlerAllowlistTest extends MetapixelTestCase
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
        // Fresh array-driver RateLimiter per test — array cache resets in setUp.
        $this->app->forgetInstance(RateLimiter::class);
        Session::shouldReceive('getId')->andReturn('test-session-allowlist');
        Request::shouldReceive('ip')->andReturn('127.0.0.1');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_returns_null_for_non_metapixel_handler(): void
    {
        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;

        $mResponse = $obHandler->onBeforeRun($obController, 'Cart::onAdd');

        $this->assertNull($mResponse);
    }

    public function test_returns_422_when_event_name_not_in_allowlist(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'NotAnEvent',
            'action_key' => 'whatever',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;
        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
        $arData = json_decode((string) $mResponse->getContent(), true);
        $this->assertSame(['error' => 'event_name not allowed'], $arData);
    }

    public function test_top_level_fields_resolve_october_request_transport_shape(): void
    {
        Bus::fake();
        Request::shouldReceive('input')->with('data', [])->andReturn([]);
        Request::shouldReceive('input')->with('name')->andReturn('AddToCart');
        Request::shouldReceive('input')->with('action_key')->andReturn('cart-add:top-level:1');

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;
        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(
            200,
            $mResponse->getStatusCode(),
            'October $.request posts fields top-level (no data[] nesting) — the handler must still resolve them',
        );
        Bus::assertDispatched(SendCapiEvent::class);
    }

    public function test_dispatches_when_event_name_in_meta_standard(): void
    {
        Bus::fake();
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'AddToCart',
            'action_key' => 'cart-add:1',
            'content_ids' => ['SKU-1'],
            'value' => 12.5,
            'currency' => 'EUR',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;
        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $this->assertArrayHasKey('event_id', $arBody);
        $this->assertArrayHasKey('script', $arBody);
        $this->assertStringContainsString('fbq("track"', (string) $arBody['script']);

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'AddToCart'
                && $obJob->sAdapterClass === ThemeActionAdapter::class;
        });
    }

    public function test_dispatches_when_event_name_in_operator_custom_list(): void
    {
        Settings::set(['theme_custom_event_names' => ['Logingrupa_SalonBooked']]);
        Bus::fake();
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Logingrupa_SalonBooked',
            'action_key' => 'booking:42',
        ]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;
        $mResponse = $obHandler->onBeforeRun($obController, 'Metapixel::onFireEvent');

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());

        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            return $obJob->sEventName === 'Logingrupa_SalonBooked';
        });
    }
}
