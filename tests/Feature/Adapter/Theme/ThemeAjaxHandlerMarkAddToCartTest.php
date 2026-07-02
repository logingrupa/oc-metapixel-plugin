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
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\CartPositionWatcher;
use Logingrupa\Metapixel\Classes\Meta\AddToCartPixelResult;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * D-07 — ThemeAjaxHandler::onMarkAddToCart PIXEL-ONLY branch. Returns
 * {event_id, script} with the server capi event_id for a resolvable offer;
 * 422 on non-positive offer_id; 429 on rate limit; 200 empty-script on null
 * resolver result (fail-safe). onFireEvent path is unchanged (no regression).
 */
#[Group('adapter')]
final class ThemeAjaxHandlerMarkAddToCartTest extends MetapixelTestCase
{
    private const HANDLER = 'Metapixel::onMarkAddToCart';

    protected function setUp(): void
    {
        parent::setUp();
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
        Session::shouldReceive('getId')->andReturn('session-mark-atc');
        Request::shouldReceive('ip')->andReturn('127.0.0.1');
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(AdapterRegistry::class);
        $this->app->forgetInstance(RateLimiter::class);
        parent::tearDown();
    }

    public function test_returns_event_id_and_script_for_resolvable_offer(): void
    {
        $sEventId = '11111111-2222-4333-8444-555566667777';
        $obWatcher = Mockery::mock(CartPositionWatcher::class);
        $obWatcher->shouldReceive('resolveBrowserPixel')
            ->once()
            ->with(100)
            ->andReturn(new AddToCartPixelResult($sEventId, [
                'content_ids' => ['SKU-1-100'],
                'contents' => [['id' => 'SKU-1-100', 'quantity' => 2, 'item_price' => 9.99]],
                'num_items' => 2,
                'value' => 19.98,
                'currency' => 'EUR',
            ]));
        $this->app->instance(CartPositionWatcher::class, $obWatcher);

        Request::shouldReceive('input')->with('data', [])->andReturn(['offer_id' => 100]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            self::HANDLER,
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $this->assertSame($sEventId, $arBody['event_id'] ?? null);
        $sScript = (string) ($arBody['script'] ?? '');
        $this->assertStringContainsString('fbq("track", "AddToCart"', $sScript);
        $this->assertStringContainsString('eventID: "'.$sEventId.'"', $sScript);
        $this->assertStringContainsString('SKU-1-100', $sScript);
        $this->assertStringContainsString('19.98', $sScript);
    }

    public function test_non_positive_offer_id_returns_422(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn(['offer_id' => 0]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            self::HANDLER,
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'invalid offer_id'],
            json_decode((string) $mResponse->getContent(), true),
        );
    }

    public function test_missing_offer_id_returns_422(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            self::HANDLER,
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
    }

    public function test_rate_limit_exceeded_returns_429(): void
    {
        $obWatcher = Mockery::mock(CartPositionWatcher::class);
        $obWatcher->shouldReceive('resolveBrowserPixel')->andReturn(null);
        $this->app->instance(CartPositionWatcher::class, $obWatcher);

        Request::shouldReceive('input')->with('data', [])->andReturn(['offer_id' => 100]);

        $obController = Mockery::mock(Controller::class);
        $obHandler = new ThemeAjaxHandler;

        for ($iAttempt = 1; $iAttempt <= 30; $iAttempt++) {
            $obHandler->onBeforeRun($obController, self::HANDLER);
        }

        $mResponse = $obHandler->onBeforeRun($obController, self::HANDLER);
        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(429, $mResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'rate limit exceeded'],
            json_decode((string) $mResponse->getContent(), true),
        );
    }

    public function test_null_resolver_result_returns_200_empty_script(): void
    {
        $obWatcher = Mockery::mock(CartPositionWatcher::class);
        $obWatcher->shouldReceive('resolveBrowserPixel')->once()->with(100)->andReturn(null);
        $this->app->instance(CartPositionWatcher::class, $obWatcher);

        Request::shouldReceive('input')->with('data', [])->andReturn(['offer_id' => 100]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            self::HANDLER,
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $this->assertArrayHasKey('event_id', $arBody);
        $this->assertNull($arBody['event_id']);
        $this->assertSame('', $arBody['script']);
    }

    public function test_on_fire_event_path_unchanged_for_unknown_handler(): void
    {
        // A handler name matching neither recognized name must short-circuit
        // to null (regression guard: the new branch does not swallow others).
        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Cart::onAdd',
        );

        $this->assertNull($mResponse);
    }

    public function test_on_fire_event_still_routes_to_its_own_path(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'PageView',
            'action_key' => 'pv:1',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $this->assertStringContainsString('fbq("track", "PageView"', (string) ($arBody['script'] ?? ''));
    }
}
