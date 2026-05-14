<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Components\PurchasePixel;
use Logingrupa\Metapixelshopaholic\Models\EventLog;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Lovata\OrdersShopaholic\Models\Order;
use Mockery;
use Ramsey\Uuid\Uuid;

/**
 * Phase 3.1 REFAC-11 — locks 6 invariants for the PurchasePixel event_log
 * gate + onMarkFired AJAX handler:
 *
 *   1. onRun() returns null when a `channel='pixel'` row exists in event_log
 *      (browser already fired across this/any device/session).
 *   2. onRun() returns null when the `channel='capi'` row is absent
 *      (server hasn't fired — don't pair half a contract).
 *   3. onRun() renders a script when CAPI row exists AND Pixel row absent
 *      (the happy path — event_id + event_time read from the CAPI row).
 *   4. onMarkFired() inserts the Pixel row and returns
 *      `['ok' => true, 'won_race' => true]` when the submitted event_id
 *      matches the server's CAPI row event_id.
 *   5. onMarkFired() second call with the same event_id returns
 *      `['ok' => true, 'won_race' => false]` — UNIQUE-constraint collapses
 *      the duplicate INSERT into a no-op; success-for-the-caller.
 *   6. onMarkFired() rejects event_id mismatch (T-3.1-18 spoofing) —
 *      returns `['ok' => false]` AND inserts no Pixel row AND
 *      Log::warning fires with "potential forgery" substring AND the
 *      submitted_event_id_LENGTH is in the log context (T-3.1-21 — never
 *      the value itself).
 *
 * Pattern: extends MetapixelTestCase for in-memory SQLite hermetic harness;
 * uses OrderFixtures::provisionHermeticOfferProductTables to bootstrap the
 * Lovata schema slice needed for PayloadBuilder to populate custom_data;
 * uses `Request::merge` to simulate the AJAX-handler input read pattern
 * (mirrors LazyPromoBlockLoader::onLoadPromoTab's `input(...)` call site).
 */
final class PurchasePixelEventLogGateTest extends MetapixelTestCase
{
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
        $this->primePluginGuardEnabled('123456789012345');
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        Mockery::close();
        parent::tearDown();
    }

    public function test_onrun_returns_null_when_pixel_row_exists(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $this->seedEventLogRow($obOrder, $sUuid, $iEventTime, EventLog::CHANNEL_CAPI);
        $this->seedEventLogRow($obOrder, $sUuid, $iEventTime, EventLog::CHANNEL_PIXEL);

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull(
            $obComponent->arMetaEvent,
            'Pixel row present in event_log → onRun MUST render nothing (cross-device-refire suppression).',
        );
    }

    public function test_onrun_returns_null_when_capi_row_absent(): void
    {
        // No event_log rows seeded — paid order, but server hasn't dispatched.
        $obOrder = OrderFixtures::makePaidOrder();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull(
            $obComponent->arMetaEvent,
            'CAPI row absent → onRun MUST render nothing (don\'t pair half a contract).',
        );
        $this->assertSame(
            0,
            EventLog::count(),
            'No event_log row should exist after a render-nothing path.',
        );
    }

    public function test_onrun_renders_script_when_capi_exists_and_pixel_absent(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $this->seedEventLogRow($obOrder, $sUuid, $iEventTime, EventLog::CHANNEL_CAPI);

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNotNull(
            $obComponent->arMetaEvent,
            'CAPI present + Pixel absent → onRun MUST populate arMetaEvent.',
        );
        $this->assertSame($sUuid, $obComponent->arMetaEvent['event_id']);
        $this->assertSame($iEventTime, $obComponent->arMetaEvent['event_time']);
        $this->assertSame('Purchase', $obComponent->arMetaEvent['event_name']);
        $this->assertIsArray($obComponent->arMetaEvent['custom_data']);
    }

    public function test_onmarkfired_inserts_pixel_row(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $this->seedEventLogRow($obOrder, $sUuid, $iEventTime, EventLog::CHANNEL_CAPI);

        Request::merge(['event_id' => $sUuid]);

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $arResult = $obComponent->onMarkFired();

        $this->assertSame(['ok' => true, 'won_race' => true], $arResult);
        $this->assertSame(
            1,
            EventLog::where('channel', EventLog::CHANNEL_PIXEL)->count(),
            'Exactly one Pixel row MUST exist after onMarkFired (race winner path).',
        );
        $obPixelRow = EventLog::where('channel', EventLog::CHANNEL_PIXEL)->first();
        $this->assertNotNull($obPixelRow);
        $this->assertSame($sUuid, (string) $obPixelRow->event_id);
        $this->assertSame($iEventTime, (int) $obPixelRow->event_time);
        $this->assertSame(Order::class, (string) $obPixelRow->subject_type);
        $this->assertSame((int) $obOrder->id, (int) $obPixelRow->subject_id);
    }

    public function test_onmarkfired_second_call_returns_ok_true_no_duplicate(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $this->seedEventLogRow($obOrder, $sUuid, $iEventTime, EventLog::CHANNEL_CAPI);

        Request::merge(['event_id' => $sUuid]);

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);

        // First call: race winner.
        $arFirst = $obComponent->onMarkFired();
        $this->assertSame(['ok' => true, 'won_race' => true], $arFirst);

        // Second call: race loser (UNIQUE constraint collapses the INSERT).
        $arSecond = $obComponent->onMarkFired();
        $this->assertSame(
            ['ok' => true, 'won_race' => false],
            $arSecond,
            'Second onMarkFired with same event_id MUST return ok=true, won_race=false (success-for-caller).',
        );

        $this->assertSame(
            1,
            EventLog::where('channel', EventLog::CHANNEL_PIXEL)->count(),
            'Exactly one Pixel row MUST exist after both calls (UNIQUE-constraint idempotency).',
        );
    }

    public function test_onmarkfired_rejects_event_id_mismatch(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sCapiUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $this->seedEventLogRow($obOrder, $sCapiUuid, $iEventTime, EventLog::CHANNEL_CAPI);

        $sForgedUuid = 'forged-uuid-different-from-the-capi-row';
        Request::merge(['event_id' => $sForgedUuid]);

        // SCE-05 capture-by-reference: Mockery::on closure inspects the
        // Log::warning message AND the context array to lock both the
        // T-3.1-18 (forgery substring) and T-3.1-21 (length-only context)
        // invariants in a single mock binding.
        $bForgeryCaptured = false;
        $bLengthOnlyCaptured = false;
        $iSubmittedLen = strlen($sForgedUuid);
        Log::shouldReceive('warning')
            ->with(
                Mockery::on(function ($sMessage) use (&$bForgeryCaptured): bool {
                    if (is_string($sMessage) && str_contains($sMessage, 'potential forgery')) {
                        $bForgeryCaptured = true;
                    }

                    return true;
                }),
                Mockery::on(function ($arContext) use (&$bLengthOnlyCaptured, $iSubmittedLen): bool {
                    if (is_array($arContext)
                        && isset($arContext['meta_pixel.submitted_event_id_len'])
                        && $arContext['meta_pixel.submitted_event_id_len'] === $iSubmittedLen
                        && ! isset($arContext['meta_pixel.submitted_event_id'])
                    ) {
                        $bLengthOnlyCaptured = true;
                    }

                    return true;
                }),
            )
            ->atLeast()
            ->once();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $arResult = $obComponent->onMarkFired();

        $this->assertSame(
            ['ok' => false],
            $arResult,
            'Mismatched event_id MUST return ok=false (T-3.1-18 spoofing mitigation).',
        );
        $this->assertSame(
            0,
            EventLog::where('channel', EventLog::CHANNEL_PIXEL)->count(),
            'No Pixel row MUST exist after a forgery attempt.',
        );
        $this->assertTrue(
            $bForgeryCaptured,
            'Log::warning MUST fire with "potential forgery" substring (T-3.1-18).',
        );
        $this->assertTrue(
            $bLengthOnlyCaptured,
            'Log context MUST carry submitted_event_id_len ONLY — never the value (T-3.1-21).',
        );
    }

    /**
     * Phase 3.1-07 REFAC-13 RED — findEventLogRow resolves via Order.site_id
     * (forOrder), not active_site (getActiveSiteId). Seed row site_id=2;
     * active_site=1 forces divergence. RED until Task 7 rewires reader.
     */
    public function test_findEventLogRow_uses_order_site_id_not_active_site(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->site_id = 2;
        $obOrder->save();
        $obOrder = $obOrder->fresh();

        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;

        // Seed CAPI row at site_id=2 (matches Order.site_id, NOT active_site).
        DB::table('logingrupa_metapixel_event_log')->insert([
            'event_id' => $sUuid,
            'event_name' => EventLog::EVENT_PURCHASE,
            'channel' => EventLog::CHANNEL_CAPI,
            'subject_type' => Order::class,
            'subject_id' => (int) $obOrder->id,
            'secret_key' => (string) $obOrder->secret_key,
            'site_id' => 2,
            'event_time' => $iEventTime,
            'fired_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Frontend FPM lies — active_site=1, diverges from Order.site_id=2.
        Config::set('system.active_site', 1);

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        // Reset Config so subsequent files do not inherit binding.
        Config::set('system.active_site', null);

        $this->assertNotNull(
            $obComponent->arMetaEvent,
            'Component MUST resolve via Order.site_id=2, not active_site=1.',
        );
        $this->assertSame($sUuid, $obComponent->arMetaEvent['event_id']);
    }

    /**
     * Seed an event_log row for the given Order on the given channel.
     * site_id=null mirrors the single-site test harness so the production
     * `findEventLogRow` query's `whereNull('site_id')` branch matches.
     */
    private function seedEventLogRow(Order $obOrder, string $sUuid, int $iEventTime, string $sChannel): EventLog
    {
        return EventLog::create([
            'event_id' => $sUuid,
            'event_name' => EventLog::EVENT_PURCHASE,
            'channel' => $sChannel,
            'subject_type' => Order::class,
            'subject_id' => (int) $obOrder->id,
            'secret_key' => (string) $obOrder->secret_key,
            'site_id' => null,
            'event_time' => $iEventTime,
            'fired_at' => Carbon::now(),
        ]);
    }

    /**
     * Instantiate PurchasePixel with the ArrayAccess stub from
     * PurchasePixelTest::makeComponent — mirrors Cms\Classes\CodeBase's
     * ArrayAccess contract. orderSlug property set via setProperty so
     * resolveOrder() reads the right key.
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

    /**
     * Reflection-prime PluginGuard into the enabled state with the given
     * pixel_id. Mirrors PurchasePixelTest::primePluginGuardEnabled — the
     * production isDisabled()/getPixelId() paths still execute against the
     * primed state.
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
}
