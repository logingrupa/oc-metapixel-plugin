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
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeAjaxHandler;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher;
use Logingrupa\Metapixel\Classes\Meta\OfferSwitchResult;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * VIEW-07 + VIEW-09 — ThemeAjaxHandler::onBeforeRun hybrid path for
 * shopaholic.product subject_type. Unknown alias → 422 (T-6-04), valid alias
 * routes through registered adapter + delegates to ProductPageWatcher,
 * adapter lacking SupportsHybridAjax → 422, non-positive subject_id → 422,
 * loadSubject returning null → 404 (T-6-05).
 */
#[Group('adapter')]
final class ThemeAjaxHandlerSubjectTypeTest extends MetapixelTestCase
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
        Session::shouldReceive('getId')->andReturn('test-session-subjecttype');
        Request::shouldReceive('ip')->andReturn('127.0.0.1');
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_unknown_subject_type_alias_returns_422(): void
    {
        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'mall.product',
            'subject_id' => 1,
            'action_key' => 'foo',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'unknown subject_type'],
            json_decode((string) $mResponse->getContent(), true),
        );
    }

    public function test_valid_alias_routes_through_registered_adapter_and_dispatches_send_capi_event(): void
    {
        $obFakeProduct = new \stdClass;
        $obFakeAdapter = new class($obFakeProduct) implements SupportsHybridAjax
        {
            public bool $bLoadCalled = false;

            /** @var array{0: int, 1: array<string, mixed>}|null */
            public ?array $arLoadArgs = null;

            public function __construct(private object $obFakeProduct) {}

            public function getSubjectType(object $obSubject): string
            {
                return 'shopaholic.product';
            }

            public function getSubjectId(object $obSubject): int
            {
                return 42;
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
                        return ['SKU-42'];
                    }

                    public function resolveValue(object $obSubject): float
                    {
                        return 1.0;
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
                        return 1;
                    }
                };
            }

            /** @return array<string, ?string> */
            public function getUserData(object $obSubject): array
            {
                return [];
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
                $this->bLoadCalled = true;
                $this->arLoadArgs = [$iSubjectId, $arContext];

                return $this->obFakeProduct;
            }
        };

        $sFakeAdapterAlias = 'shopaholic.product';
        // Register the adapter under the canonical class FQN so the handler's
        // shopaholic-product branch delegates correctly. We bind a test-double
        // INSTANCE of ShopaholicProductAdapter into the container so
        // AdapterRegistry::resolveByAlias returns the FQN and App::make
        // resolves to our fake (avoids touching the real Product::active()->find chain).
        $this->app->instance(ShopaholicProductAdapter::class, $obFakeAdapter);
        App::make(AdapterRegistry::class)->register(
            ShopaholicProductAdapter::class,
            ShopaholicProductAdapter::class,
        );
        // Re-derive alias-map entry: the register call above used the real
        // adapter's getSubjectType (which returns 'shopaholic.product').
        // Confirm by resolving back:
        $this->assertSame(
            ShopaholicProductAdapter::class,
            App::make(AdapterRegistry::class)->resolveByAlias($sFakeAdapterAlias),
        );

        // Bind a Mockery test-double for the final ProductPageWatcher that
        // returns a deterministic event_id so assertions are stable.
        $sFakeEventId = '00000000-0000-4000-8000-000000000042';
        $obFakeWatcher = Mockery::mock(ProductPageWatcher::class);
        $obFakeWatcher->shouldReceive('dispatchForOfferSwitch')
            ->once()
            ->with(42, 100)
            ->andReturn(new OfferSwitchResult($sFakeEventId, [
                'content_ids' => ['SKU-42-100'],
                'content_name' => 'Test Product',
                'content_type' => 'product',
                'value' => 9.99,
                'currency' => 'EUR',
            ]));
        $this->app->instance(ProductPageWatcher::class, $obFakeWatcher);

        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
            'test_event_code' => 'TEST123',
        ]);
        Settings::clearInternalCache();

        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'shopaholic.product',
            'subject_id' => 42,
            'offer_id' => 100,
            'action_key' => 'viewcontent:42:100',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $this->assertSame($sFakeEventId, $arBody['event_id'] ?? null);
        $this->assertIsString($arBody['script'] ?? null);
        $sScript = (string) $arBody['script'];
        $this->assertStringContainsString('fbq("track", "ViewContent"', $sScript);
        $this->assertStringContainsString($sFakeEventId, $sScript);
        $this->assertStringContainsString('SKU-42-100', $sScript);
        $this->assertStringContainsString('9.99', $sScript);
        $this->assertStringContainsString('test_event_code: "TEST123"', $sScript);

        // Mockery::close() in tearDown verifies dispatchForOfferSwitch was
        // called exactly once with (42, 100).
        // Adapter's loadSubject was called via the handler BEFORE delegation.
        $this->assertTrue($obFakeAdapter->bLoadCalled);
        $this->assertSame([42, ['offer_id' => 100]], $obFakeAdapter->arLoadArgs);
    }

    public function test_generic_hybrid_adapter_alias_dispatches_capi_and_returns_empty_custom_data_script(): void
    {
        Bus::fake();
        $obFakeSubject = new \stdClass;
        $obFakeAdapter = new class($obFakeSubject) implements SupportsHybridAjax
        {
            public function __construct(private object $obFakeSubject) {}

            public function getSubjectType(object $obSubject): string
            {
                return 'mall.product';
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
                return [];
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

        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
            'test_event_code' => 'TEST123',
        ]);
        Settings::clearInternalCache();

        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'mall.product',
            'subject_id' => 7,
            'action_key' => 'viewcontent:7',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $sScript = (string) ($arBody['script'] ?? '');
        $sEventId = (string) ($arBody['event_id'] ?? '');
        $this->assertNotSame('', $sEventId);
        // Generic-alias theme actions are contentless — empty {} custom_data.
        $this->assertStringContainsString('fbq("track", "ViewContent", {}', $sScript);
        $this->assertStringContainsString('eventID: "'.$sEventId.'"', $sScript);
        $this->assertStringContainsString('test_event_code: "TEST123"', $sScript);
    }

    public function test_generic_theme_branch_carries_test_event_code_and_event_i_d_with_empty_custom_data(): void
    {
        Settings::set([
            'pixel_id' => 'PIXEL-1',
            'capi_access_token' => 'TOKEN-1',
            'test_event_code' => 'TEST123',
        ]);
        Settings::clearInternalCache();

        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'action_key' => 'theme:viewcontent',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(200, $mResponse->getStatusCode());
        $arBody = json_decode((string) $mResponse->getContent(), true);
        $this->assertIsArray($arBody);
        $sScript = (string) ($arBody['script'] ?? '');
        $sEventId = (string) ($arBody['event_id'] ?? '');
        // Empty custom_data — no content invented; forced to `{}` for JS object semantics.
        $this->assertStringContainsString('fbq("track", "ViewContent", {}', $sScript);
        $this->assertStringContainsString('eventID: "'.$sEventId.'"', $sScript);
        $this->assertStringContainsString('test_event_code: "TEST123"', $sScript);
    }

    public function test_adapter_lacking_supports_hybrid_ajax_returns_422(): void
    {
        $obBareAdapter = new class implements EventSubjectAdapter
        {
            public function getSubjectType(object $obSubject): string
            {
                return 'fake.bare';
            }

            public function getSubjectId(object $obSubject): int
            {
                return 1;
            }

            public function getSiteId(object $obSubject): ?int
            {
                return null;
            }

            public function getSecretKey(object $obSubject): ?string
            {
                return null;
            }

            public function getValueResolver(object $obSubject): ValueResolver
            {
                throw new \LogicException('not used in this test');
            }

            /** @return array<string, ?string> */
            public function getUserData(object $obSubject): array
            {
                return [];
            }

            /** @return array<string, list<string>> */
            public function getSupportedEvents(): array
            {
                return [];
            }
        };
        $sBareClass = get_class($obBareAdapter);
        $this->app->instance($sBareClass, $obBareAdapter);
        App::make(AdapterRegistry::class)->register($sBareClass, $sBareClass);

        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'fake.bare',
            'subject_id' => 1,
            'action_key' => 'viewcontent:1',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'subject_type does not support hybrid AJAX'],
            json_decode((string) $mResponse->getContent(), true),
        );
    }

    public function test_non_positive_subject_id_returns_422(): void
    {
        // Register a hybrid adapter for shopaholic.product so the alias
        // resolves; the subject_id=0 guard is what we are gating here.
        $obStub = new class implements SupportsHybridAjax
        {
            public function getSubjectType(object $obSubject): string
            {
                return 'shopaholic.product';
            }

            public function getSubjectId(object $obSubject): int
            {
                return 1;
            }

            public function getSiteId(object $obSubject): ?int
            {
                return null;
            }

            public function getSecretKey(object $obSubject): ?string
            {
                return null;
            }

            public function getValueResolver(object $obSubject): ValueResolver
            {
                throw new \LogicException('not reached');
            }

            /** @return array<string, ?string> */
            public function getUserData(object $obSubject): array
            {
                return [];
            }

            /** @return array<string, list<string>> */
            public function getSupportedEvents(): array
            {
                return [];
            }

            /**
             * @param  array<string, mixed>  $arContext
             */
            public function loadSubject(int $iSubjectId, array $arContext): ?object
            {
                throw new \LogicException('loadSubject MUST NOT be reached when subject_id <= 0');
            }
        };
        $this->app->instance(ShopaholicProductAdapter::class, $obStub);
        App::make(AdapterRegistry::class)->register(
            ShopaholicProductAdapter::class,
            ShopaholicProductAdapter::class,
        );

        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'shopaholic.product',
            'subject_id' => 0,
            'offer_id' => 100,
            'action_key' => 'viewcontent:0:100',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(422, $mResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'invalid subject_id'],
            json_decode((string) $mResponse->getContent(), true),
        );
    }

    public function test_load_subject_returning_null_returns_404(): void
    {
        // Bind a fake adapter whose loadSubject returns null (subject missing /
        // inactive / cross-site — T-6-05 mitigation).
        $obFakeAdapter = new class implements SupportsHybridAjax
        {
            public function getSubjectType(object $obSubject): string
            {
                return 'shopaholic.product';
            }

            public function getSubjectId(object $obSubject): int
            {
                return 99999;
            }

            public function getSiteId(object $obSubject): ?int
            {
                return null;
            }

            public function getSecretKey(object $obSubject): ?string
            {
                return null;
            }

            public function getValueResolver(object $obSubject): ValueResolver
            {
                throw new \LogicException('not reached');
            }

            /** @return array<string, ?string> */
            public function getUserData(object $obSubject): array
            {
                return [];
            }

            /** @return array<string, list<string>> */
            public function getSupportedEvents(): array
            {
                return [];
            }

            /**
             * @param  array<string, mixed>  $arContext
             */
            public function loadSubject(int $iSubjectId, array $arContext): ?object
            {
                return null;
            }
        };
        $this->app->instance(ShopaholicProductAdapter::class, $obFakeAdapter);
        App::make(AdapterRegistry::class)->register(
            ShopaholicProductAdapter::class,
            ShopaholicProductAdapter::class,
        );

        Request::shouldReceive('input')->with('data', [])->andReturn([
            'name' => 'ViewContent',
            'subject_type' => 'shopaholic.product',
            'subject_id' => 99999,
            'offer_id' => 1,
            'action_key' => 'viewcontent:99999:1',
        ]);

        $mResponse = (new ThemeAjaxHandler)->onBeforeRun(
            Mockery::mock(Controller::class),
            'Metapixel::onFireEvent',
        );

        $this->assertInstanceOf(JsonResponse::class, $mResponse);
        $this->assertSame(404, $mResponse->getStatusCode());
        $this->assertSame(
            ['error' => 'subject not found'],
            json_decode((string) $mResponse->getContent(), true),
        );
    }
}
