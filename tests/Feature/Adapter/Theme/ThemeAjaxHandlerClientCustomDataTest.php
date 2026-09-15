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
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * Client custom_data on the Metapixel::onFireEvent theme-action path. A Search
 * carries search_string, content_ids, content_type and num_items to both the
 * CAPI payload and the browser twin; malformed values are dropped, over-long
 * queries truncated, and every other client key stays behind the identity
 * firewall. Events without those fields still render {} custom_data.
 */
#[Group('adapter')]
final class ThemeAjaxHandlerClientCustomDataTest extends MetapixelTestCase
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
        PluginGuard::reset();
        $this->app->forgetInstance(RateLimiter::class);
        Session::shouldReceive('getId')->andReturn('session-client-custom-data');
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

    public function test_search_contract_reaches_capi_payload_and_browser_script(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Search',
            'action_key' => 'search:gel polish:a1b2',
            'search_string' => 'gel polish',
            'content_ids' => ['SKU-676-6841', 'SKU-12'],
            'content_type' => 'product',
            'num_items' => 2,
        ]);

        $mResponse = $this->fire();

        $this->assertSame(200, $mResponse->getStatusCode());
        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            $arCustomData = $obJob->arPayload['data'][0]['custom_data'] ?? [];

            return ($arCustomData['search_string'] ?? null) === 'gel polish'
                && ($arCustomData['content_ids'] ?? null) === ['SKU-676-6841', 'SKU-12']
                && ($arCustomData['content_type'] ?? null) === 'product'
                && ($arCustomData['num_items'] ?? null) === 2;
        });

        $sScript = $this->scriptOf($mResponse);
        $this->assertStringContainsString('fbq("track", "Search"', $sScript);
        $this->assertStringContainsString('"search_string":"gel polish"', $sScript);
        $this->assertStringContainsString('"content_ids":["SKU-676-6841","SKU-12"]', $sScript);
        $this->assertStringContainsString('"content_type":"product"', $sScript);
        $this->assertStringContainsString('"num_items":2', $sScript);
    }

    public function test_search_fields_posted_as_top_level_form_fields_are_read(): void
    {
        // Larajax flattens options.data into top-level form fields, which is
        // what the live theme sends: name=Search&search_string=gel&content_ids[]=...
        Request::shouldReceive('input')->with('data', [])->andReturn([]);
        Request::shouldReceive('input')->with('name')->andReturn('Search');
        Request::shouldReceive('input')->with('action_key')->andReturn('search:gel:x1');
        Request::shouldReceive('input')->with('search_string')->andReturn('gel');
        Request::shouldReceive('input')->with('content_ids')->andReturn(['SKU-429-6645', 'SKU-233']);
        Request::shouldReceive('input')->with('content_type')->andReturn('product');
        Request::shouldReceive('input')->with('num_items')->andReturn('13');

        $mResponse = $this->fire();

        $this->assertSame(200, $mResponse->getStatusCode());
        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            $arCustomData = $obJob->arPayload['data'][0]['custom_data'] ?? [];

            return ($arCustomData['search_string'] ?? null) === 'gel'
                && ($arCustomData['content_ids'] ?? null) === ['SKU-429-6645', 'SKU-233']
                && ($arCustomData['num_items'] ?? null) === 13;
        });
        $this->assertStringContainsString('"search_string":"gel"', $this->scriptOf($mResponse));
    }

    public function test_invalid_content_ids_and_unknown_keys_are_dropped(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Search',
            'action_key' => 'search:gel:c3d4',
            'search_string' => 'gel',
            'content_ids' => ['SKU-1', 'DROP-ME', 'SKU-x', 42, 'SKU-2-3'],
            'content_type' => 'product_group',
            'num_items' => -1,
            'em' => 'victim@example.com',
            'value' => 999,
            'currency' => 'USD',
        ]);

        $mResponse = $this->fire();

        $this->assertSame(200, $mResponse->getStatusCode());
        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            $arCustomData = $obJob->arPayload['data'][0]['custom_data'] ?? [];
            $arUserData = $obJob->arPayload['data'][0]['user_data'] ?? [];
            $obSubject = $obJob->obSubject;

            return ($arCustomData['content_ids'] ?? null) === ['SKU-1', 'SKU-2-3']
                && ($arCustomData['num_items'] ?? null) !== -1
                && ($arCustomData['value'] ?? 0.0) === 0.0
                && ($arCustomData['currency'] ?? null) !== 'USD'
                && ($arUserData['em'] ?? null) === null
                && $obSubject instanceof ThemeActionEvent
                && ($obSubject->arPayload['em'] ?? null) === null
                && ($obSubject->arPayload['value'] ?? null) === null;
        });

        $sScript = $this->scriptOf($mResponse);
        $this->assertStringContainsString('"content_ids":["SKU-1","SKU-2-3"]', $sScript);
        $this->assertStringNotContainsString('DROP-ME', $sScript);
        $this->assertStringNotContainsString('num_items', $sScript);
        $this->assertStringNotContainsString('victim', $sScript);
    }

    public function test_search_string_over_100_chars_is_truncated(): void
    {
        $sLong = str_repeat('a', 120);
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Search',
            'action_key' => 'search:long:e5f6',
            'search_string' => '  '.$sLong.'  ',
        ]);

        $mResponse = $this->fire();

        $this->assertSame(200, $mResponse->getStatusCode());
        $sExpected = str_repeat('a', 100);
        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob) use ($sExpected): bool {
            return ($obJob->arPayload['data'][0]['custom_data']['search_string'] ?? null) === $sExpected;
        });
        $this->assertStringContainsString('"search_string":"'.$sExpected.'"', $this->scriptOf($mResponse));
    }

    public function test_event_without_client_custom_data_renders_empty_object(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'Lead',
            'action_key' => 'lead:contact-form',
        ]);

        $mResponse = $this->fire();

        $this->assertSame(200, $mResponse->getStatusCode());
        Bus::assertDispatched(SendCapiEvent::class, function (SendCapiEvent $obJob): bool {
            return ($obJob->arPayload['data'][0]['custom_data'] ?? null) === [];
        });
        $this->assertStringContainsString('fbq("track", "Lead", {}, ', $this->scriptOf($mResponse));
    }

    private function fire(): JsonResponse
    {
        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );
        $this->assertInstanceOf(JsonResponse::class, $mResponse);

        return $mResponse;
    }

    private function scriptOf(JsonResponse $obResponse): string
    {
        $mBody = json_decode((string) $obResponse->getContent(), true);

        return is_array($mBody) ? (string) ($mBody['script'] ?? '') : '';
    }
}
