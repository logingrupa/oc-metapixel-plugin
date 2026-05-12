<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use DB;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Exception\InvalidEventIdException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixelshopaholic\Classes\Exception\OrderHasNoItemsException;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Ramsey\Uuid\Uuid;

/**
 * Unit test locking the Plan 03-04 PAY-06 PayloadBuilder contract.
 *
 * Each test covers ONE invariant from the plan's <behavior> section:
 *
 *   1. test_envelope_has_expected_top_level_shape — Graph API data[0] wrapper.
 *   2. test_content_ids_match_single_and_multi_offer_skus — byte-for-byte
 *      SKU contract (StoreExtender\CartComponentHandler::buildSkuId).
 *   3. test_contents_array_has_id_quantity_item_price — content-entry keys.
 *   4. test_custom_data_order_id_equals_order_number — order_number string,
 *      NOT id (FUN-14 prerequisite).
 *   5. test_custom_data_currency_falls_back_to_settings — 3-step fallback
 *      chain per CONTEXT.md Specifics line 158.
 *   6. test_custom_data_value_equals_position_total — sum of price*qty.
 *   7. test_custom_data_num_items_equals_quantity_sum — sum of qty.
 *   8. test_throws_invalid_event_id_on_empty_string — PAY-09 precondition.
 *   9. test_throws_invalid_event_id_on_non_uuid_string — PAY-09 precondition.
 *  10. test_currency_falls_back_to_settings_when_order_relation_and_code_null
 *      — Settings-fallback path (no throw) per CONTEXT.md Specifics line 158.
 *  11. test_throws_order_has_no_currency_when_all_three_sources_empty —
 *      PAY-09 precondition (last line of defence).
 *  12. test_throws_order_has_no_items_when_no_positions — PAY-09 precondition.
 *  13. test_user_data_populated_from_hasher — UserDataHasher integration.
 *  14. test_passes_through_event_id_and_event_time_unchanged — envelope echo.
 *
 * Test isolation uses the same reflection-priming Settings helper pattern
 * from MetaClientTest (MC-02 deviation, plan 03-03 — HR-02 multi-Settings
 * round-trip flap workaround). Cache + PluginGuard flushed in setUp.
 */
final class PayloadBuilderTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSystemSettings();
        $this->bootOrdersStatuses();
        $this->bootOrdersTable();
        OrderFixtures::provisionHermeticOfferProductTables();
        Cache::flush();
        Settings::clearInternalCache();
        PluginGuard::flush();
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    public function test_envelope_has_expected_top_level_shape(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;

        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload($obOrder, $sEventId, $iEventTime);

        $this->assertArrayHasKey('data', $arPayload, 'envelope must wrap data[0].');
        $this->assertIsArray($arPayload['data']);
        $this->assertArrayHasKey(0, $arPayload['data']);

        $arEvent = $arPayload['data'][0];
        $this->assertSame($sEventId, $arEvent['event_id']);
        $this->assertSame($iEventTime, $arEvent['event_time']);
        $this->assertSame('Purchase', $arEvent['event_name']);
        $this->assertSame('website', $arEvent['action_source']);
        $this->assertArrayHasKey('event_source_url', $arEvent);
        $this->assertArrayHasKey('user_data', $arEvent);
        $this->assertArrayHasKey('custom_data', $arEvent);
    }

    public function test_content_ids_match_single_and_multi_offer_skus(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        $arContentIds = $arPayload['data'][0]['custom_data']['content_ids'];
        $this->assertSame(
            [OrderFixtures::EXPECTED_SINGLE_SKU, OrderFixtures::EXPECTED_MULTI_SKU],
            $arContentIds,
            'content_ids must be SKU-10 + SKU-11-102 byte-for-byte (StoreExtender format).',
        );
    }

    public function test_contents_array_has_id_quantity_item_price(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        $arContents = $arPayload['data'][0]['custom_data']['contents'];
        $this->assertCount(2, $arContents);
        foreach ($arContents as $arEntry) {
            $this->assertSame(['id', 'quantity', 'item_price'], array_keys($arEntry));
        }
        $this->assertSame(OrderFixtures::EXPECTED_SINGLE_SKU, $arContents[0]['id']);
        $this->assertSame(2, $arContents[0]['quantity']);
        $this->assertEqualsWithDelta(19.95, $arContents[0]['item_price'], 0.001);
    }

    public function test_custom_data_order_id_equals_order_number(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        $this->assertSame('260512-9001', $arPayload['data'][0]['custom_data']['order_id']);
        $this->assertNotSame((string) $obOrder->id, $arPayload['data'][0]['custom_data']['order_id']);
    }

    public function test_custom_data_currency_falls_back_to_settings(): void
    {
        // Hermetic test orders have no Currency relation (no currencies table),
        // so the resolveCurrency chain reaches step 3: Settings::get(currency_code, 'EUR').
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        $this->assertSame('EUR', $arPayload['data'][0]['custom_data']['currency']);
    }

    public function test_custom_data_value_equals_position_total(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        // 2 * 19.95 + 1 * 10.05 = 49.95
        $this->assertEqualsWithDelta(49.95, $arPayload['data'][0]['custom_data']['value'], 0.001);
    }

    public function test_custom_data_num_items_equals_quantity_sum(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        // 2 + 1 = 3
        $this->assertSame(3, $arPayload['data'][0]['custom_data']['num_items']);
    }

    public function test_throws_invalid_event_id_on_empty_string(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $bThrown = false;
        try {
            (new PayloadBuilder)->buildPurchaseEventPayload($obOrder, '', 1715000000);
        } catch (InvalidEventIdException $obException) {
            $bThrown = true;
            $this->assertSame('', $obException->arContext['event_id']);
            $this->assertSame((int) $obOrder->id, $obException->arContext['order_id']);
        }
        $this->assertTrue($bThrown, 'Empty event_id must throw InvalidEventIdException.');
    }

    public function test_throws_invalid_event_id_on_non_uuid_string(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $bThrown = false;
        try {
            (new PayloadBuilder)->buildPurchaseEventPayload($obOrder, 'not-a-uuid', 1715000000);
        } catch (InvalidEventIdException $obException) {
            $bThrown = true;
            $this->assertSame('not-a-uuid', $obException->arContext['event_id']);
        }
        $this->assertTrue($bThrown, 'Non-UUID string must throw InvalidEventIdException.');
    }

    public function test_throws_invalid_event_id_on_uuid_v1(): void
    {
        // CR-02 lock: server contract is UUIDv4 only. Uuid::isValid() alone
        // accepts v1/v3/v5/Nil — the validator must additionally check
        // getVersion() === 4 so a future column backfill from a non-v4
        // source (e.g. legacy plugin's UUIDv1, deterministic UUIDv5) cannot
        // silently corrupt Meta's dedup pairing.
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        // Generate a UUIDv1 (time-based). Ramsey provides Uuid::uuid1().
        $sUuidV1 = \Ramsey\Uuid\Uuid::uuid1()->toString();
        $this->assertSame(1, \Ramsey\Uuid\Uuid::fromString($sUuidV1)->getFields()->getVersion(), 'sanity: must be UUIDv1.');

        $bThrown = false;
        try {
            (new PayloadBuilder)->buildPurchaseEventPayload($obOrder, $sUuidV1, 1715000000);
        } catch (InvalidEventIdException $obException) {
            $bThrown = true;
            $this->assertSame($sUuidV1, $obException->arContext['event_id']);
            $this->assertSame('event_id is not a valid UUIDv4', $obException->getMessage());
        }
        $this->assertTrue($bThrown, 'UUIDv1 must throw InvalidEventIdException despite Uuid::isValid() returning true.');
    }

    public function test_throws_invalid_event_id_on_uuid_v5(): void
    {
        // CR-02 lock companion: UUIDv5 (deterministic / SHA1-namespaced) is
        // a likely future contender for deterministic event_id generation
        // ("the same order resolves to the same event_id"). MUST be rejected
        // by the validator — Meta's dedup semantics rely on v4 randomness.
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuidV5 = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_DNS, 'example.com')->toString();
        $this->assertSame(5, \Ramsey\Uuid\Uuid::fromString($sUuidV5)->getFields()->getVersion(), 'sanity: must be UUIDv5.');

        $bThrown = false;
        try {
            (new PayloadBuilder)->buildPurchaseEventPayload($obOrder, $sUuidV5, 1715000000);
        } catch (InvalidEventIdException $obException) {
            $bThrown = true;
            $this->assertSame($sUuidV5, $obException->arContext['event_id']);
        }
        $this->assertTrue($bThrown, 'UUIDv5 must throw InvalidEventIdException.');
    }

    public function test_currency_falls_back_to_settings_when_order_relation_and_code_null(): void
    {
        // Order relation already null in hermetic schema (no currencies table);
        // currency_code accessor → null (computed from $this->currency, also null).
        // This test asserts Settings::get('currency_code', 'EUR') is the
        // 3rd fallback step per CONTEXT.md Specifics line 158.
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();

        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        $this->assertSame('EUR', $arPayload['data'][0]['custom_data']['currency']);
    }

    public function test_throws_order_has_no_currency_when_all_three_sources_empty(): void
    {
        // Order relation null (hermetic). currency_code accessor → null.
        // Settings::get('currency_code') → '' (empty operator setting).
        // resolveCurrency exhausts all 3 fallback steps → throws.
        $this->setSetting('currency_code', '');
        $obOrder = OrderFixtures::makePaidOrder();

        $bThrown = false;
        try {
            (new PayloadBuilder)->buildPurchaseEventPayload(
                $obOrder,
                Uuid::uuid4()->toString(),
                1715000000,
            );
        } catch (OrderHasNoCurrencyException $obException) {
            $bThrown = true;
            $this->assertSame((int) $obOrder->id, $obException->arContext['order_id']);
            $this->assertSame('260512-9001', $obException->arContext['order_number']);
        }
        $this->assertTrue(
            $bThrown,
            'All three fallback sources empty must throw OrderHasNoCurrencyException.',
        );
    }

    public function test_throws_order_has_no_items_when_no_positions(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        // Delete all order positions to trigger the empty-positions branch.
        DB::table('lovata_orders_shopaholic_order_positions')
            ->where('order_id', $obOrder->id)
            ->delete();
        $obOrder = $obOrder->fresh();

        $bThrown = false;
        try {
            (new PayloadBuilder)->buildPurchaseEventPayload(
                $obOrder,
                Uuid::uuid4()->toString(),
                1715000000,
            );
        } catch (OrderHasNoItemsException $obException) {
            $bThrown = true;
            $this->assertSame((int) $obOrder->id, $obException->arContext['order_id']);
        }
        $this->assertTrue($bThrown, 'Empty positions must throw OrderHasNoItemsException.');
    }

    public function test_user_data_populated_from_hasher(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            Uuid::uuid4()->toString(),
            1715000000,
        );

        $arUserData = $arPayload['data'][0]['user_data'];
        $this->assertArrayHasKey('em', $arUserData);
        $this->assertIsString($arUserData['em']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $arUserData['em']);
        $this->assertArrayHasKey('external_id', $arUserData);
        $this->assertIsString($arUserData['external_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $arUserData['external_id']);
    }

    public function test_passes_through_event_id_and_event_time_unchanged(): void
    {
        $this->setSetting('currency_code', 'EUR');
        $obOrder = OrderFixtures::makePaidOrder();
        $sEventId = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;

        $arPayload = (new PayloadBuilder)->buildPurchaseEventPayload($obOrder, $sEventId, $iEventTime);

        $this->assertSame($sEventId, $arPayload['data'][0]['event_id']);
        $this->assertSame($iEventTime, $arPayload['data'][0]['event_time']);
    }

    /**
     * Reflection-priming Settings — mirrors MetaClientTest::setSetting (plan
     * 03-03 MC-02 deviation). The DB round-trip via `Settings::set()` flaps
     * under multi-Settings-per-test load (HR-02); priming the in-memory
     * instance directly is reliable.
     */
    private function setSetting(string $sKey, mixed $mValue): void
    {
        Settings::instance()->setAttribute($sKey, $mValue);
    }
}
