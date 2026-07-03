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
use Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxHandler;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * Server user_data capture on the generic hybrid-AJAX dispatch branch. An
 * anonymous subject (the documented guest-order adapter pattern) yields
 * all-null user_data from the hasher; the handler must merge the
 * request-captured client_ip_address / client_user_agent / fbp / fbc into the
 * CAPI payload or Meta rejects every guest event (subcode 2804050) into a
 * permanent dead-letter. Adapter-supplied non-null values must win, and
 * site_id must never leak into user_data (hybrid subjects carry site via the
 * adapter contract).
 */
#[Group('adapter')]
final class ThemeAjaxHandlerGenericAdapterServerUserDataTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::shouldReceive('input')->andReturnNull()->byDefault();
        Request::shouldReceive('userAgent')->andReturnNull()->byDefault();
        Request::shouldReceive('cookie')->andReturnNull()->byDefault();
        $this->app->singleton(AdapterRegistry::class);
        Settings::clearInternalCache();
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
        ]);
        PluginGuard::reset();
        $this->app->forgetInstance(RateLimiter::class);
        Session::shouldReceive('getId')->andReturn('session-generic-server-userdata');
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

    public function test_anonymous_subject_generic_dispatch_carries_server_captured_user_data(): void
    {
        $this->registerHybridAdapter([]);
        Request::shouldReceive('userAgent')->andReturn('Honest/2.0');
        Request::shouldReceive('cookie')->with('_fbp', null)->andReturn('fb.1.111.222');
        Request::shouldReceive('cookie')->with('_fbc', null)->andReturn('fb.1.333.444');
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'mall.order',
            'subject_id' => 7,
            'action_key' => 'viewcontent:7',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob): bool {
            $arUserData = $obJob->arPayload['data'][0]['user_data'] ?? [];
            if (! is_array($arUserData)) {
                return false;
            }

            return ($arUserData['client_ip_address'] ?? null) === '203.0.113.9'
                && ($arUserData['client_user_agent'] ?? null) === 'Honest/2.0'
                && ($arUserData['fbp'] ?? null) === 'fb.1.111.222'
                && ($arUserData['fbc'] ?? null) === 'fb.1.333.444'
                && ! array_key_exists('site_id', $arUserData);
        });
    }

    public function test_adapter_supplied_user_data_wins_over_request_capture(): void
    {
        $this->registerHybridAdapter([
            'client_ip_address' => '198.51.100.7',
            'fbp' => 'fb.1.subject.fbp',
        ]);
        Request::shouldReceive('userAgent')->andReturn('Honest/2.0');
        Request::shouldReceive('cookie')->with('_fbp', null)->andReturn('fb.1.request.fbp');
        Request::shouldReceive('cookie')->with('_fbc', null)->andReturn('fb.1.333.444');
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'mall.order',
            'subject_id' => 7,
            'action_key' => 'viewcontent:7',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());

        Bus::assertDispatched(SendCapiEvent::class, static function (SendCapiEvent $obJob): bool {
            $arUserData = $obJob->arPayload['data'][0]['user_data'] ?? [];

            return ($arUserData['client_ip_address'] ?? null) === '198.51.100.7'
                && ($arUserData['fbp'] ?? null) === 'fb.1.subject.fbp'
                && ($arUserData['client_user_agent'] ?? null) === 'Honest/2.0'
                && ($arUserData['fbc'] ?? null) === 'fb.1.333.444';
        });
    }

    /**
     * Register an anonymous-class hybrid adapter for the mall.order alias
     * whose getUserData returns the supplied (passthrough-field) map.
     *
     * @param  array<string, ?string>  $arUserData
     */
    private function registerHybridAdapter(array $arUserData): void
    {
        $obFakeSubject = new \stdClass;
        $obFakeAdapter = new class($obFakeSubject, $arUserData) implements SupportsHybridAjax
        {
            /** @param array<string, ?string> $arUserData */
            public function __construct(private object $obFakeSubject, private array $arUserData) {}

            public function getSubjectType(object $obSubject): string
            {
                return 'mall.order';
            }

            public function getSubjectId(object $obSubject): int
            {
                return 7;
            }

            public function getSiteId(object $obSubject): ?int
            {
                return 1;
            }

            public function getSecretKey(object $obSubject): ?string
            {
                return null;
            }

            public function getValueResolver(object $obSubject): ValueResolver
            {
                return new class implements ValueResolver
                {
                    /** @return list<string> */
                    public function resolveContentIds(object $obSubject): array
                    {
                        return [];
                    }

                    public function resolveValue(object $obSubject): float
                    {
                        return 0.0;
                    }

                    public function resolveCurrency(object $obSubject): string
                    {
                        return 'EUR';
                    }

                    /** @return list<array{id: string, quantity: int, item_price: float}> */
                    public function resolveContents(object $obSubject): array
                    {
                        return [];
                    }

                    public function resolveNumItems(object $obSubject): int
                    {
                        return 0;
                    }
                };
            }

            /** @return array<string, ?string> */
            public function getUserData(object $obSubject): array
            {
                return $this->arUserData;
            }

            /** @return array<string, list<string>> */
            public function getSupportedEvents(): array
            {
                return ['ViewContent' => ['capi', 'pixel']];
            }

            /**
             * @param  array<string, mixed>  $arContext
             */
            public function loadSubject(int $iSubjectId, array $arContext): ?object
            {
                return $this->obFakeSubject;
            }
        };

        $sAdapterClass = get_class($obFakeAdapter);
        $this->app->instance($sAdapterClass, $obFakeAdapter);
        App::make(AdapterRegistry::class)->register($sAdapterClass, $sAdapterClass);
    }
}
