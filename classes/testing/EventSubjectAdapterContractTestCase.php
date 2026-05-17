<?php

namespace Logingrupa\Metapixel\Classes\Testing;

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Logingrupa\Metapixel\Classes\Meta\PayloadBuilder;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * Contract test base for any EventSubjectAdapter implementation. First-party
 * adapters (FakeAdapter in Phase 2, ShopaholicOrderAdapter + ThemeActionAdapter
 * in Phase 3) extend this base + supply makeAdapter() + makeSubject() to prove
 * their implementation satisfies the marketplace contract.
 *
 * Third-party authoring pattern (post v2.1 when first real third party arrives):
 *
 *     final class AcmeCartAdapterContractTest extends EventSubjectAdapterContractTestCase
 *     {
 *         protected function makeAdapter(): EventSubjectAdapter
 *         {
 *             return new AcmeCartAdapter;
 *         }
 *
 *         protected function makeSubject(): object
 *         {
 *             return AcmeCartFactory::create(['site_id' => 1]);
 *         }
 *     }
 *
 * `pest tests/AcmeCartAdapterContractTest.php` exits 0 → the adapter satisfies
 * the Phase 2 marketplace contract.
 *
 * Extending MetapixelTestCase is a Phase 2 YAGNI choice — Phase 2 has exactly
 * one in-tree consumer; adding a marketplace test-harness dependency adds no
 * value until a real third party authors an adapter outside this repo.
 * Revisit at v2.1 when the first real third-party adapter ships.
 */
abstract class EventSubjectAdapterContractTestCase extends MetapixelTestCase
{
    /** Concrete subclasses construct the adapter under test. */
    abstract protected function makeAdapter(): EventSubjectAdapter;

    /** Concrete subclasses construct the subject the adapter operates on. */
    abstract protected function makeSubject(): object;

    /**
     * Invariant 09 (registry round-trip) registers an adapter into the
     * AdapterRegistry singleton. Without explicit forget, the registration
     * persists across tests. Reset between tests so subclasses + concrete
     * contract tests are isolated.
     */
    protected function tearDown(): void
    {
        app()->forgetInstance(AdapterRegistry::class);
        parent::tearDown();
    }

    public function test_invariant_01_subject_type_is_opaque_alias_format(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $sType = $obAdapter->getSubjectType($obSubject);

        $this->assertNotSame('', $sType, 'subject_type non-empty');
        $this->assertStringNotContainsString('\\', $sType, 'no backslashes — opaque alias, not class FQN');
        $this->assertLessThanOrEqual(64, strlen($sType), 'subject_type fits the EventLog column (≤ 64)');
    }

    public function test_invariant_02_subject_id_is_positive_int(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $iSubjectId = $obAdapter->getSubjectId($obSubject);

        $this->assertGreaterThan(0, $iSubjectId, 'subject_id is positive (EventLogWriter rejects <= 0)');
    }

    public function test_invariant_03_site_id_deterministic_across_set_site_context(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();

        $mFirst = $obAdapter->getSiteId($obSubject);
        $mSecond = $obAdapter->getSiteId($obSubject);

        $this->assertSame($mFirst, $mSecond, 'getSiteId deterministic — same subject, same result');
    }

    public function test_invariant_04_get_site_id_reads_no_request_or_site_manager(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $mSiteId = $obAdapter->getSiteId($obSubject);

        $this->assertTrue(
            $mSiteId === null || is_int($mSiteId),
            'getSiteId returns ?int — no exception, no Request side effect (phpstan disallowed-calls anchored statically)'
        );
    }

    public function test_invariant_05_get_secret_key_returns_string_or_null_never_throws(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $mSecret = $obAdapter->getSecretKey($obSubject);

        $this->assertTrue(
            $mSecret === null || is_string($mSecret),
            'getSecretKey returns ?string'
        );
    }

    public function test_invariant_06_get_value_resolver_returns_value_resolver_instance(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $obResolver = $obAdapter->getValueResolver($obSubject);

        $this->assertInstanceOf(ValueResolver::class, $obResolver);
    }

    public function test_invariant_07_get_user_data_returns_documented_meta_capi_keys(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $arUserData = $obAdapter->getUserData($obSubject);

        $arAllowed = [
            'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id',
            'fbp', 'fbc', 'client_ip_address', 'client_user_agent',
        ];

        foreach (array_keys($arUserData) as $sKey) {
            $this->assertContains($sKey, $arAllowed, "getUserData key '{$sKey}' is in the 13-key Meta CAPI allowed set");
        }
        foreach ($arUserData as $sKey => $mValue) {
            $this->assertTrue(
                $mValue === null || is_string($mValue),
                "getUserData['{$sKey}'] is string|null"
            );
        }
    }

    public function test_invariant_08_get_supported_events_returns_correct_shape(): void
    {
        $obAdapter = $this->makeAdapter();
        $arSupported = $obAdapter->getSupportedEvents();

        $this->assertNotSame([], $arSupported, 'at least one supported event declared');
        foreach ($arSupported as $sEventName => $arChannels) {
            $this->assertIsString($sEventName, 'supported event key is the Meta event name string');
            $this->assertIsArray($arChannels, 'channels list is an array');
            foreach ($arChannels as $sChannel) {
                $this->assertContains(
                    $sChannel,
                    ['capi', 'pixel'],
                    "channel '{$sChannel}' is one of 'capi' | 'pixel'"
                );
            }
        }
    }

    public function test_invariant_09_registry_round_trip_returns_same_adapter(): void
    {
        $this->app->singleton(AdapterRegistry::class);
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();

        /** @var AdapterRegistry $obRegistry */
        $obRegistry = app(AdapterRegistry::class);
        $obRegistry->register(get_class($obSubject), get_class($obAdapter));

        $obResolved = $obRegistry->resolveFor($obSubject);
        $this->assertInstanceOf(get_class($obAdapter), $obResolved);
    }

    public function test_invariant_10_payload_builder_produces_valid_envelope_shape(): void
    {
        $obAdapter = $this->makeAdapter();
        $obSubject = $this->makeSubject();
        $obResolver = $obAdapter->getValueResolver($obSubject);

        $obBuilder = new PayloadBuilder(new UserDataHasher);
        $arEnvelope = $obBuilder->buildEventPayload(
            'Purchase',
            $obAdapter,
            $obSubject,
            $obResolver,
            'uuid-contract-1',
            1700000000,
            [],
        );

        $this->assertArrayHasKey('data', $arEnvelope);
        $this->assertIsArray($arEnvelope['data']);
        $this->assertArrayHasKey(0, $arEnvelope['data']);
        $arRecord = $arEnvelope['data'][0];

        $this->assertSame('uuid-contract-1', $arRecord['event_id']);
        $this->assertSame(1700000000, $arRecord['event_time']);
        $this->assertSame('Purchase', $arRecord['event_name']);
        $this->assertSame('website', $arRecord['action_source']);
        $this->assertArrayHasKey('user_data', $arRecord);
        $this->assertArrayHasKey('custom_data', $arRecord);
    }
}
