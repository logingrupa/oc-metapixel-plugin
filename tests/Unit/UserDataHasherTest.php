<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Unit;

require_once __DIR__.'/../MetapixelTestCase.php';
require_once __DIR__.'/../Support/OrderFixtures.php';

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Logingrupa\Metapixelshopaholic\Tests\Support\OrderFixtures;

/**
 * Unit test locking the Plan 03-04 PAY-07 + PAY-08 UserDataHasher contract.
 *
 * 11 test methods covering each behavior bullet from the plan:
 *
 *   1. test_em_is_sha256_of_lowercase_trimmed_email — PII hashing.
 *   2. test_em_is_null_when_email_null — null guard.
 *   3. test_external_id_is_sha256_of_lowercase_trimmed_secret_key — PAY-08.
 *   4. test_phone_normalised_prepends_country_code_when_missing — phone fix.
 *   5. test_phone_normalised_unchanged_when_country_code_present — dedup.
 *   6. test_phone_uses_settings_country_code_override — multi-site (.no=47).
 *   7. test_fn_ln_hashed — sha256(lowercase(trim)) for given/family names.
 *   8. test_plaintext_fields_present_when_request_available — cookies + IP/UA.
 *   9. test_plaintext_fields_null_when_no_request — queue-worker fallback.
 *  10. test_cache_memoization_returns_same_array — per-request CCache hit.
 *  11. test_determinism_across_instances — same Order → same hashes.
 *
 * Settings reflection-priming + Cache::flush + PluginGuard::flush in setUp
 * (MC-02 deviation pattern from plan 03-03 — HR-02 multi-test flap fix).
 */
final class UserDataHasherTest extends MetapixelTestCase
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
        $this->app->forgetInstance(Request::class);
    }

    protected function tearDown(): void
    {
        OrderFixtures::dropHermeticOfferProductTables();
        Cache::flush();
        parent::tearDown();
    }

    public function test_em_is_sha256_of_lowercase_trimmed_email(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill(['email' => 'Guest@Example.com '])->save();
        $obOrder = $obOrder->fresh();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(hash('sha256', 'guest@example.com'), $arData['em']);
    }

    public function test_em_is_null_when_email_null(): void
    {
        $obOrder = OrderFixtures::makeGuestOrderWithoutEmail();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertNull($arData['em']);
    }

    public function test_external_id_is_sha256_of_lowercase_trimmed_secret_key(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(
            hash('sha256', 'test-secret-aaaaaaaaa'),
            $arData['external_id'],
            'PAY-08: guest external_id must be sha256(mb_strtolower(trim(secret_key))).',
        );
    }

    public function test_phone_normalised_prepends_country_code_when_missing(): void
    {
        $this->setSetting('phone_country_code', '371');
        $obOrder = OrderFixtures::makePaidOrder();
        // Override phone to a no-country-code variant.
        $obOrder->forceFill(['phone' => '20 000 000'])->save();
        $obOrder = $obOrder->fresh();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(hash('sha256', '37120000000'), $arData['ph']);
    }

    public function test_phone_normalised_unchanged_when_country_code_present(): void
    {
        $this->setSetting('phone_country_code', '371');
        $obOrder = OrderFixtures::makePaidOrder();
        // Default phone in fixture is '+371 20 000 000' — the '+' strips,
        // digits become '37120000000', country code already prefixed →
        // no double-prefix.
        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(hash('sha256', '37120000000'), $arData['ph']);
    }

    public function test_phone_uses_settings_country_code_override(): void
    {
        $this->setSetting('phone_country_code', '47');
        $obOrder = OrderFixtures::makePaidOrder();
        $obOrder->forceFill(['phone' => '20 000 000'])->save();
        $obOrder = $obOrder->fresh();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(hash('sha256', '4720000000'), $arData['ph']);
    }

    public function test_fn_ln_hashed(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(hash('sha256', 'test'), $arData['fn']);
        $this->assertSame(hash('sha256', 'user'), $arData['ln']);
    }

    public function test_plaintext_fields_present_when_request_available(): void
    {
        $obRequest = Request::create('/foo', 'POST');
        $obRequest->cookies->set('_fbp', 'fb.1.x.y');
        $obRequest->cookies->set('_fbc', 'fb.1.x.z');
        $obRequest->headers->set('User-Agent', 'Mozilla/Test');
        $obRequest->server->set('REMOTE_ADDR', '198.51.100.1');
        $this->app->instance(Request::class, $obRequest);

        $obOrder = OrderFixtures::makePaidOrder();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame('198.51.100.1', $arData['client_ip_address']);
        $this->assertSame('Mozilla/Test', $arData['client_user_agent']);
        $this->assertSame('fb.1.x.y', $arData['fbp']);
        $this->assertSame('fb.1.x.z', $arData['fbc']);
    }

    public function test_plaintext_fields_null_when_no_request(): void
    {
        $this->app->forgetInstance(Request::class);
        // When no Request is bound, the container will create a default one
        // with no cookies + 127.0.0.1 — we only assert fbp/fbc are null since
        // those have no default Symfony Request equivalent.
        $obOrder = OrderFixtures::makePaidOrder();

        $arData = (new UserDataHasher)->forOrder($obOrder);

        $this->assertArrayHasKey('client_ip_address', $arData);
        $this->assertArrayHasKey('client_user_agent', $arData);
        $this->assertNull($arData['fbp']);
        $this->assertNull($arData['fbc']);
    }

    public function test_cache_memoization_returns_same_array(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();
        $obHasher = new UserDataHasher;

        $arFirst = $obHasher->forOrder($obOrder);
        $arSecond = $obHasher->forOrder($obOrder);

        $this->assertSame($arFirst, $arSecond, 'Second call must return cached array byte-for-byte.');
    }

    public function test_determinism_across_instances(): void
    {
        $obOrder = OrderFixtures::makePaidOrder();

        $arFirst = (new UserDataHasher)->forOrder($obOrder);
        Cache::flush();
        $arSecond = (new UserDataHasher)->forOrder($obOrder);

        $this->assertSame(
            $arFirst,
            $arSecond,
            'Two distinct UserDataHasher instances must produce identical output for the same Order.',
        );
    }

    /**
     * Reflection-priming Settings — mirrors MetaClientTest::setSetting (plan
     * 03-03 MC-02 deviation; HR-02 multi-test flap workaround).
     */
    private function setSetting(string $sKey, mixed $mValue): void
    {
        Settings::instance()->setAttribute($sKey, $mValue);
    }
}
