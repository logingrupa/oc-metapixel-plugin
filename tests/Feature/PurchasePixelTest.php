<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixelshopaholic\Components\PurchasePixel;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;
use Ramsey\Uuid\Uuid;

/**
 * Feature test locking the Plan 03-06 PAY-10 PurchasePixel browser-twin
 * dedup contract.
 *
 * Each test covers ONE invariant from the plan's <behavior> section:
 *
 *   1. test_paid_order_with_persisted_event_id_populates_ar_meta_event
 *      — happy path: paid + event_id + event_time set → arMetaEvent
 *      populated with all 4 keys and Phase-3 content_ids/value/currency.
 *   2. test_non_paid_order_does_not_populate_ar_meta_event
 *      — status fence rejects even if event_id is somehow set.
 *   3. test_paid_order_without_persisted_event_id_does_not_populate_ar_meta_event
 *      — IPN-race protection: user lands on /checkout/{slug} before the
 *      PayPal IPN flips the status — Pixel renders nothing.
 *   4. test_paid_order_without_persisted_event_time_does_not_populate_ar_meta_event
 *      — column-pair contract: event_time null = render nothing.
 *   5. test_plugin_disabled_does_not_populate_ar_meta_event
 *      — PluginGuard short-circuit at component entry.
 *   6. test_order_slug_not_found_does_not_populate_ar_meta_event
 *      — bad slug = render nothing (defensive).
 *   7. test_custom_data_matches_capi_envelope_byte_for_byte
 *      — the contract that Meta-dedup-on-event-id relies on: Pixel
 *      side's custom_data === CAPI side's custom_data byte-for-byte.
 *
 * Component is instantiated via the ArrayAccess stub pattern from
 * PixelHeadTest (no full October page-lifecycle boot needed for a
 * unit-of-behavior feature test).
 */
final class PurchasePixelTest extends MetapixelTestCase
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
        $this->primePluginGuardEnabled('123456789012345');
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    public function test_paid_order_with_persisted_event_id_populates_ar_meta_event(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $obOrder->forceFill([
            'meta_purchase_event_id' => $sUuid,
            'meta_purchase_event_time' => $iEventTime,
        ])->save();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNotNull($obComponent->arMetaEvent, 'paid order with both persisted columns must populate arMetaEvent.');
        $this->assertSame($sUuid, $obComponent->arMetaEvent['event_id']);
        $this->assertSame($iEventTime, $obComponent->arMetaEvent['event_time']);
        $this->assertSame('Purchase', $obComponent->arMetaEvent['event_name']);
        $this->assertIsArray($obComponent->arMetaEvent['custom_data']);
        $this->assertArrayHasKey('content_ids', $obComponent->arMetaEvent['custom_data']);
        $this->assertArrayHasKey('value', $obComponent->arMetaEvent['custom_data']);
        $this->assertArrayHasKey('currency', $obComponent->arMetaEvent['custom_data']);
        $this->assertArrayHasKey('order_id', $obComponent->arMetaEvent['custom_data']);
        $this->assertArrayHasKey('num_items', $obComponent->arMetaEvent['custom_data']);
    }

    public function test_non_paid_order_does_not_populate_ar_meta_event(): void
    {
        // Build the paid order, then forceFill the status BACK down to pending.
        // event_id is set so we can prove the status fence (not the event_id
        // fence) is what's rejecting the dispatch.
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'status_id' => 1, // 'new' (pending) per bootOrdersStatuses seed.
            'meta_purchase_event_id' => Uuid::uuid4()->toString(),
            'meta_purchase_event_time' => 1715000000,
        ])->save();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'status != paid_status_code MUST render nothing.');
    }

    public function test_paid_order_without_persisted_event_id_does_not_populate_ar_meta_event(): void
    {
        // IPN-race scenario: user reaches /checkout/{slug} before PayPal IPN
        // flips the status — OrderStatusWatcher hasn't fired yet so event_id
        // is null. Pixel correctly renders nothing rather than guess.
        $obOrder = OrderFixtures::makePaidOrder();
        // makePaidOrder leaves event_id null by default; explicit set for clarity.
        $obOrder->forceFill([
            'meta_purchase_event_id' => null,
            'meta_purchase_event_time' => null,
        ])->save();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'event_id null MUST render nothing.');
    }

    public function test_paid_order_without_persisted_event_time_does_not_populate_ar_meta_event(): void
    {
        // Defensive: column-pair contract violation. Should never happen in
        // production (OrderStatusWatcher writes BOTH atomically) but lock
        // the guard so a future refactor can't sneak past it.
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'meta_purchase_event_id' => Uuid::uuid4()->toString(),
            'meta_purchase_event_time' => null,
        ])->save();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'event_time null MUST render nothing.');
    }

    public function test_plugin_disabled_does_not_populate_ar_meta_event(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'meta_purchase_event_id' => Uuid::uuid4()->toString(),
            'meta_purchase_event_time' => 1715000000,
        ])->save();

        // Reflection-prime PluginGuard into disabled state — sidesteps HR-02
        // (the Settings::set('pixel_id', '') round-trip flap inside the
        // hermetic SQLite harness).
        $this->primePluginGuardDisabled();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'PluginGuard disabled MUST render nothing.');
    }

    public function test_order_slug_not_found_does_not_populate_ar_meta_event(): void
    {
        OrderFixtures::makePaidOrder(); // exists, but we'll query by a different slug

        $obComponent = $this->makeComponent('this-slug-does-not-exist');
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'slug not matching any order_secret_key MUST render nothing.');
    }

    public function test_component_details_returns_name_and_description(): void
    {
        $obComponent = new PurchasePixel;

        $arDetails = $obComponent->componentDetails();

        $this->assertArrayHasKey('name', $arDetails);
        $this->assertArrayHasKey('description', $arDetails);
        $this->assertSame('Purchase Pixel', $arDetails['name']);
        $this->assertStringContainsString('event_id', $arDetails['description']);
    }

    public function test_define_properties_exposes_order_slug(): void
    {
        $obComponent = new PurchasePixel;

        $arProperties = $obComponent->defineProperties();

        $this->assertArrayHasKey('orderSlug', $arProperties);
        $this->assertSame('string', $arProperties['orderSlug']['type']);
        $this->assertSame('{{ :slug }}', $arProperties['orderSlug']['default']);
        $this->assertSame('^[a-zA-Z0-9-]+$', $arProperties['orderSlug']['validationPattern']);
    }

    public function test_status_fence_falls_back_to_status_id_lookup_when_relation_missing(): void
    {
        // Exercises the fallback path in isAtPaidStatus() — status_id points
        // to a non-existent row (status was deleted between Order save and
        // thank-you-page render). Both the relation load AND the Status::where
        // lookup return null → render nothing (status fence rejects).
        $obOrder = OrderFixtures::makePaidOrder();
        // Point at an orphaned status_id — relation lookup returns null and
        // Status::where('id', 9999)->value('code') returns null too.
        $obOrder->forceFill([
            'status_id' => 9999,
            'meta_purchase_event_id' => Uuid::uuid4()->toString(),
            'meta_purchase_event_time' => 1715000000,
        ])->save();
        // Avoid eager-loaded `status` relation — fresh from DB.
        $obFresh = $obOrder->fresh();

        $obComponent = $this->makeComponent((string) $obFresh->secret_key);
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'orphan status_id MUST render nothing via fallback fence.');
    }

    public function test_status_fence_passes_via_fallback_lookup_for_paid_status_id(): void
    {
        // Same path as above but with status_id pointing at the paid status —
        // the fallback should resolve 'new-payment-received' via Status::where
        // and return true.
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'meta_purchase_event_id' => Uuid::uuid4()->toString(),
            'meta_purchase_event_time' => 1715000000,
        ])->save();
        // unsetRelation forces a fresh lookup; on the fresh fetch the relation
        // is not loaded so getRelationValue triggers the BelongsTo query.
        $obFresh = $obOrder->fresh();

        $obComponent = $this->makeComponent((string) $obFresh->secret_key);
        $obComponent->onRun();

        $this->assertNotNull($obComponent->arMetaEvent, 'fallback lookup MUST resolve paid status when relation is null.');
    }

    public function test_empty_order_slug_property_resolves_no_order(): void
    {
        // Exercises the early-return branch in resolveOrder() when the
        // orderSlug property is the empty string (e.g. route binding
        // didn't populate :slug on the actual page).
        OrderFixtures::makePaidOrder();
        $obComponent = $this->makeComponent('');
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'empty orderSlug MUST render nothing.');
    }

    /**
     * CR-03 lock: runtime slug validation. October's defineProperties
     * validationPattern is backend-edit only; the runtime guard in
     * resolveOrder() must reject malformed inputs before they reach the DB
     * query. Each input is a documented attacker shape: oversized payload,
     * shell metachars, path traversal, control char, trailing newline.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedSlugProvider(): array
    {
        return [
            'too-short' => ['ab', 'length < 8'],
            'oversized-129-chars' => [str_repeat('a', 129), 'length > 128'],
            'shell-metachar' => ['valid-but$injected', '$ is not in regex set'],
            'path-traversal' => ['../etc/passwd', '. and / not allowed'],
            'space-injection' => ['has space here', 'whitespace not allowed'],
            'trailing-newline' => ["valid-slug-aaa\n", 'trailing newline must not match anchored regex'],
            'unicode-payload' => ['valid-slug-äöü', 'non-ASCII rejected'],
            'sql-quote' => ["valid'or'1=1", 'single-quote not in regex (parameterized anyway)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSlugProvider')]
    public function test_resolve_order_rejects_malformed_slug(string $sSlug, string $sReason): void
    {
        OrderFixtures::makePaidOrder();
        $obComponent = $this->makeComponent($sSlug);
        $obComponent->onRun();

        $this->assertNull(
            $obComponent->arMetaEvent,
            sprintf('runtime validator MUST reject slug (%s).', $sReason),
        );
    }

    public function test_payload_builder_exception_logs_warning_and_renders_nothing(): void
    {
        // The PayloadBuilder catch branch in onRun() — exercises the boundary
        // catch that prevents a thank-you-page 500. Force the throw by
        // deleting all order_positions on a paid order so resolveOrderPositions
        // raises OrderHasNoItemsException (a MetaPixelException subclass).
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill([
            'meta_purchase_event_id' => Uuid::uuid4()->toString(),
            'meta_purchase_event_time' => 1715000000,
        ])->save();
        // Delete positions: PayloadBuilder reads relation values → empty
        // collection → OrderHasNoItemsException MetaPixelException subclass.
        \DB::table('lovata_orders_shopaholic_order_positions')
            ->where('order_id', $obOrder->id)
            ->delete();
        $obOrder = $obOrder->fresh();

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNull($obComponent->arMetaEvent, 'PayloadBuilder throw MUST result in render-nothing (T-03-35 acceptable degradation).');
    }

    public function test_custom_data_json_contains_no_script_close_tag_even_with_hostile_order_number(): void
    {
        // CR-01 regression: force an attacker-controlled string into the
        // server-built order_number column (a value that normally flows
        // through Lovata's own OrderProcessor::generateOrderNumber so the
        // attack surface is small today — but the fence we lock here is
        // defense-in-depth against any future change to that generator).
        // The encoded JSON must contain neither `</script>` nor `<script>`
        // substrings: JSON_HEX_TAG escapes `<` and `>` to < / >.
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $obOrder->forceFill([
            'order_number' => '</script><script>alert(1)</script>',
            'meta_purchase_event_id' => $sUuid,
            'meta_purchase_event_time' => $iEventTime,
        ])->save();

        $obComponent = $this->makeComponent((string) $obOrder->fresh()->secret_key);
        $obComponent->onRun();

        $this->assertNotNull($obComponent->sCustomDataJson, 'sCustomDataJson MUST be populated when arMetaEvent is set.');
        $this->assertStringNotContainsString('</script>', $obComponent->sCustomDataJson, 'JSON_HEX_TAG MUST prevent </script> break-out.');
        $this->assertStringNotContainsString('<script>', $obComponent->sCustomDataJson, 'JSON_HEX_TAG MUST prevent <script> injection.');
        // Sanity: the order_number IS in the encoded payload (the attacker
        // string isn't filtered out — it's unicode-escape-encoded so it
        // renders as literal text inside the JS object literal, not as a tag).
        // JSON_HEX_TAG in PHP encodes `<` to `<` and `>` to `>`.
        $this->assertStringContainsString('\u003C', $obComponent->sCustomDataJson, 'angle bracket < MUST be unicode-escaped to \u003C via JSON_HEX_TAG.');
        $this->assertStringContainsString('\u003E', $obComponent->sCustomDataJson, 'angle bracket > MUST be unicode-escaped to \u003E via JSON_HEX_TAG.');
    }

    public function test_extract_custom_data_drops_integer_keys(): void
    {
        // WR-09 lock: extractCustomData must filter to string-keyed entries
        // and DROP integer-keyed entries rather than coercing via (string).
        // Today's PayloadBuilder produces only string keys; this lock
        // protects against a future envelope shape change.
        $obComponent = new PurchasePixel;
        $obReflect = new \ReflectionMethod(PurchasePixel::class, 'extractCustomData');
        $obReflect->setAccessible(true);

        $arPayload = [
            'data' => [
                [
                    'custom_data' => [
                        0 => 'unexpected-int-keyed',
                        'value' => 49.95,
                        'currency' => 'EUR',
                        42 => 'another-int-keyed',
                    ],
                ],
            ],
        ];
        $arResult = $obReflect->invoke($obComponent, $arPayload);

        $this->assertIsArray($arResult);
        $this->assertArrayHasKey('value', $arResult);
        $this->assertArrayHasKey('currency', $arResult);
        $this->assertArrayNotHasKey(0, $arResult, 'integer key 0 MUST be dropped, not coerced to "0".');
        $this->assertArrayNotHasKey('0', $arResult, 'integer key 0 MUST NOT survive as string "0" either.');
        $this->assertArrayNotHasKey(42, $arResult, 'integer key 42 MUST be dropped.');
        $this->assertArrayNotHasKey('42', $arResult, 'integer key 42 MUST NOT survive as string "42".');
    }

    public function test_custom_data_matches_capi_envelope_byte_for_byte(): void
    {
        // The dedup contract: Pixel side's custom_data MUST equal the CAPI
        // side's data[0].custom_data byte-for-byte. Build the CAPI envelope
        // independently via PayloadBuilder and compare deep-equal.
        $obOrder = OrderFixtures::makePaidOrder();
        $sUuid = Uuid::uuid4()->toString();
        $iEventTime = 1715000000;
        $obOrder->forceFill([
            'meta_purchase_event_id' => $sUuid,
            'meta_purchase_event_time' => $iEventTime,
        ])->save();
        $obOrder = $obOrder->fresh();

        $arCapiEnvelope = (new PayloadBuilder)->buildPurchaseEventPayload(
            $obOrder,
            $sUuid,
            $iEventTime,
        );

        $obComponent = $this->makeComponent((string) $obOrder->secret_key);
        $obComponent->onRun();

        $this->assertNotNull($obComponent->arMetaEvent);
        $this->assertSame(
            $arCapiEnvelope['data'][0]['custom_data'],
            $obComponent->arMetaEvent['custom_data'],
            'Pixel custom_data MUST equal CAPI custom_data byte-for-byte (Meta dedup contract).',
        );
    }

    /**
     * Instantiate PurchasePixel with the ArrayAccess stub from PixelHeadTest
     * (mirrors Cms\Classes\CodeBase's ArrayAccess contract). The stub gives
     * the component a controller-shaped collaborator without booting the
     * full October page lifecycle. The orderSlug property is set via
     * setProperty so the component's onRun() reads the right key.
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
     * pixel_id. Mirrors PixelHeadTest::primePluginGuardEnabled — the
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

    private function primePluginGuardDisabled(): void
    {
        PluginGuard::flush();
        $obGuard = PluginGuard::instance();
        $obReflect = new \ReflectionClass($obGuard);
        $obIsDisabled = $obReflect->getProperty('bIsDisabled');
        $obIsDisabled->setAccessible(true);
        $obIsDisabled->setValue($obGuard, true);
        $obPixelId = $obReflect->getProperty('sPixelId');
        $obPixelId->setAccessible(true);
        $obPixelId->setValue($obGuard, null);

        App::singleton('metapixel.disabled', fn (): bool => true);
    }
}
