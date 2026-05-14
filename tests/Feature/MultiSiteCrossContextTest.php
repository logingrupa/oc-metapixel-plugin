<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\MetaClient;
use Logingrupa\Metapixelshopaholic\Classes\Queue\SendCapiEvent;
use Logingrupa\Metapixelshopaholic\Components\PurchasePixel;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;
use Ramsey\Uuid\Uuid;

/**
 * Phase 3.1-07 REFAC-13 cross-context spec — production bug 2026-05-14
 * codified in CI.
 *
 * Locks writer (admin /back queue, active_site=null) + reader (frontend
 * /lv/checkout, active_site=int) reconcile via Order.site_id. Today's
 * call sites use SiteResolver::getActiveSiteId() — request-context-
 * dependent → divergence → Pixel never renders. Tests fail RED until
 * Tasks 6+7 rewire on forOrder.
 *
 * Three invariants:
 *   1. Admin write (active_site=null) stamps row site_id from Order.site_id.
 *   2. Frontend reader resolves via Order.site_id when active_site diverges.
 *   3. Single-site round-trip (Order.site_id=null) preserves NULL-distinct.
 *
 * Deterministic event_time literal 1715000000 (Tiger-Style determinism).
 * tearDown resets Config (T-3.1-25 mitigation).
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-13
 */
final class MultiSiteCrossContextTest extends MetapixelTestCase
{
    private const int FIXED_EVENT_TIME = 1715000000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        $this->bootEventLogTable();
        OrderFixtures::provisionHermeticOfferProductTables();
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();
        Carbon::setTestNow(Carbon::createFromTimestamp(self::FIXED_EVENT_TIME));
        $this->primePluginGuardEnabled('123456789012345');
        $this->primeSettings();
    }

    protected function tearDown(): void
    {
        // T-3.1-25 mitigation — reset Config so subsequent files do not
        // inherit multi-site binding.
        Config::set('system.active_site', null);
        Carbon::setTestNow();
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    /**
     * Invariant 1 — admin context writer stamps site_id from Order.site_id.
     * Today's writer calls getActiveSiteId() → null on admin → row gets NULL.
     * RED until Task 6 rewires writer to caller-supplied forOrder value.
     */
    public function test_admin_flip_with_null_active_site_writes_capi_using_order_site_id(): void
    {
        Config::set('system.active_site', null);           // admin /back context
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->site_id = 1;                              // Lovata stamps at create
        $obOrder->save();
        $obOrder = $obOrder->fresh();

        // Valid UUIDv4 — PayloadBuilder rejects malformed event_id even
        // though this test only asserts the writer side.
        $sEventId = Uuid::uuid4()->toString();
        $arPayload = $this->makePayload($sEventId);

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        SendCapiEvent::dispatchSync('Purchase', $arPayload, $obOrder);

        $obRow = EventLog::where('subject_id', $obOrder->id)
            ->where('channel', EventLog::CHANNEL_CAPI)
            ->first();
        $this->assertNotNull($obRow, 'CAPI row MUST persist after admin-context dispatch.');
        $this->assertSame(
            1,
            (int) $obRow->site_id,
            'Admin-context write MUST stamp site_id=1 from Order.site_id, not Config::get null.',
        );
    }

    /**
     * Invariant 2 — frontend reader resolves CAPI row via Order.site_id
     * even when active_site diverges. Today's PurchasePixel::findEventLogRow
     * uses getActiveSiteId() → 1; seeded row has site_id=2 → miss → null.
     * RED until Task 7 rewires reader to forOrder.
     */
    public function test_frontend_pixel_reads_capi_row_via_order_site_id_when_active_site_diverges(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->site_id = 2;
        $obOrder->save();
        $obOrder = $obOrder->fresh();

        // Seed CAPI row matching Order.site_id=2 (truth). Valid UUIDv4 —
        // PayloadBuilder rejects malformed event_id.
        $sUuid = Uuid::uuid4()->toString();
        EventLog::create([
            'event_id' => $sUuid,
            'event_name' => EventLog::EVENT_PURCHASE,
            'channel' => EventLog::CHANNEL_CAPI,
            'subject_type' => Order::class,
            'subject_id' => (int) $obOrder->id,
            'secret_key' => (string) $obOrder->secret_key,
            'site_id' => 2,
            'event_time' => self::FIXED_EVENT_TIME,
            'fired_at' => Carbon::now(),
        ]);

        // Frontend FPM lies — active_site=1, diverges from Order.site_id=2.
        Config::set('system.active_site', 1);

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNotNull(
            $obComponent->arMetaEvent,
            'Frontend reader MUST find CAPI row via SiteResolver::forOrder(\$obOrder).',
        );
        $this->assertSame($sUuid, $obComponent->arMetaEvent['event_id']);
    }

    /**
     * Invariant 3 — single-site round-trip (Order.site_id=null) preserves
     * NULL-distinct semantic. Today both resolvers agree (null=null) so
     * may pass already; regression-prevention guard once Task 6/7 lands.
     */
    public function test_cross_context_pair_round_trips_for_single_site_install(): void
    {
        Config::set('system.active_site', null);
        $obOrder = OrderFixtures::makePaidOrder();   // site_id stays NULL
        $obOrder = $obOrder->fresh();

        // Valid UUIDv4 — PayloadBuilder rejects malformed event_id.
        $sEventId = Uuid::uuid4()->toString();
        $arPayload = $this->makePayload($sEventId);

        $arHistory = [];
        $this->bindMetaClientWithMockResponses([
            new Response(200, [], '{"events_received": 1}'),
        ], $arHistory);

        SendCapiEvent::dispatchSync('Purchase', $arPayload, $obOrder);

        // CAPI row exists with site_id NULL.
        $obCapiRow = EventLog::where('subject_id', $obOrder->id)
            ->where('channel', EventLog::CHANNEL_CAPI)
            ->first();
        $this->assertNotNull($obCapiRow, 'Single-site CAPI row MUST persist.');
        $this->assertNull($obCapiRow->site_id, 'Single-site CAPI row MUST stamp site_id NULL.');

        // Pixel reader finds CAPI row + arMetaEvent populated.
        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();
        $this->assertNotNull(
            $obComponent->arMetaEvent,
            'Single-site Pixel reader MUST resolve CAPI row.',
        );
        $this->assertSame($sEventId, $obComponent->arMetaEvent['event_id']);
        $this->assertSame(self::FIXED_EVENT_TIME, $obComponent->arMetaEvent['event_time']);
    }

    /**
     * Build MetaClient backed by MockHandler-Guzzle. MC-04 by-ref history.
     *
     * @param  list<Response|\Exception>  $arResponses
     * @param  array<int, array<string, mixed>>  $arHistory  by-reference
     */
    private function bindMetaClientWithMockResponses(array $arResponses, array &$arHistory): MockHandler
    {
        $obMockHandler = new MockHandler($arResponses);
        $obStack = HandlerStack::create($obMockHandler);
        $obStack->push(Middleware::history($arHistory));
        $obGuzzle = new Client(['handler' => $obStack, 'http_errors' => false]);
        $this->app->instance(MetaClient::class, new MetaClient($obGuzzle));

        return $obMockHandler;
    }

    /**
     * Reflection-prime Settings (HR-02 multi-set flap workaround).
     */
    private function primeSettings(string $sPixelId = '2291486191076331', string $sCapiToken = 'EAA-test-token'): void
    {
        $obInstance = Settings::instance();
        $obInstance->setAttribute('pixel_id', $sPixelId);
        $obInstance->setAttribute('capi_access_token', $sCapiToken);
        $obInstance->setAttribute('test_event_code', '');
    }

    /**
     * Reflection-prime PluginGuard enabled — mirrors PurchasePixelEventLogGateTest.
     */
    private function primePluginGuardEnabled(string $sPixelId): void
    {
        PluginGuard::flush();
        $obGuard = PluginGuard::instance();
        $obReflect = new \ReflectionClass($obGuard);
        $obIsDisabled = $obReflect->getProperty('bIsDisabled');
        $obIsDisabled->setAccessible(true);
        $obIsDisabled->setValue($obGuard, false);
        $obPixelId = $obReflect->getProperty('sPixelId');
        $obPixelId->setAccessible(true);
        $obPixelId->setValue($obGuard, $sPixelId);

        App::singleton('metapixel.disabled', fn (): bool => false);
    }

    /**
     * Minimal valid CAPI envelope — bypasses PayloadBuilder/Order fixtures.
     *
     * @return array<string, mixed>
     */
    private function makePayload(string $sEventId): array
    {
        return [
            'data' => [
                [
                    'event_id' => $sEventId,
                    'event_name' => 'Purchase',
                    'event_time' => self::FIXED_EVENT_TIME,
                    'action_source' => 'website',
                ],
            ],
        ];
    }

    /**
     * Instantiate PurchasePixel with ArrayAccess stub — mirrors
     * PurchasePixelEventLogGateTest::makeComponent.
     */
    private function makeComponent(string $sOrderSlug): PurchasePixel
    {
        $obStub = new class implements \ArrayAccess
        {
            /** @var array<string, mixed> */
            public array $vars = [];

            /** @var null */
            public $controller = null;

            #[\Override]
            public function offsetSet($offset, $value): void
            {
                $this->vars[(string) $offset] = $value;
            }

            #[\Override]
            public function offsetGet($offset): mixed
            {
                return $this->vars[(string) $offset] ?? null;
            }

            #[\Override]
            public function offsetExists($offset): bool
            {
                return array_key_exists((string) $offset, $this->vars);
            }

            #[\Override]
            public function offsetUnset($offset): void
            {
                unset($this->vars[(string) $offset]);
            }
        };

        $obComponent = new PurchasePixel($obStub);
        $obComponent->setProperty('orderSlug', $sOrderSlug);

        return $obComponent;
    }
}
