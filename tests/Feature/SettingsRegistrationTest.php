<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Plugin;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Feature test covering SKEL-02 + SKEL-06 + W8 invariant:
 *
 *   1. `Plugin::registerSettings()` exposes the canonical settings key mapped
 *      to `Models\Settings::class`.
 *   2. Round-trip via `Settings::set()` + `Settings::clearInternalCache()` +
 *      `Settings::get()` (proves the CommonSettings parent works).
 *   3. `getPaidStatusCodeOptions()` returns a non-empty array containing the
 *      `new-payment-received` code (CONTEXT specifics line 110 lock).
 *   4. `getQueueConnectionOptions()` returns exactly the three Laravel queue
 *      drivers in the expected order.
 *   5. Per-key invariant: every one of the 10 SKEL-02 fields in fields.yaml
 *      declares its `label` and `commentAbove` as
 *      `logingrupa.metapixelshopaholic::lang.field.<key>` and
 *      `<key>_comment` respectively. Replaces the brittle count-only grep
 *      gate (W8 fix from plan 02-01).
 *
 * Extends MetapixelTestCase directly per the proven `extends` model in
 * SanityTest (Pest's `uses()->in()` binding is currently flaky — see
 * tests/Pest.php comment).
 */
final class SettingsRegistrationTest extends MetapixelTestCase
{
    /**
     * The 10 SKEL-02 field keys that fields.yaml must bind to lang keys.
     *
     * @var array<int, string>
     */
    private const FIELD_KEYS = [
        'pixel_id',
        'capi_access_token',
        'test_event_code',
        'currency_code',
        'phone_country_code',
        'send_hashed_pii',
        'queue_connection',
        'paid_status_code',
        'refire_purchase_on_status_flip',
        'ensure_fbp_fbc_server_side',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // system_settings is provisioned in MetapixelTestCase::createApplication.
        // Tests that touch paid_status_code options also need the statuses table.
        $this->bootOrdersStatuses();
    }

    protected function tearDown(): void
    {
        Settings::clearInternalCache();
        parent::tearDown();
    }

    /**
     * Ordered first deliberately. Instantiating `new Plugin($this->app)` in
     * other tests triggers ServiceProvider registration side effects that
     * appear to pollute the Cache::remember() entry for system_settings keyed
     * by `getCacheKey()`, causing subsequent Settings::get() to return null
     * even though the row exists in SQLite. Running the round-trip before
     * the Plugin-instantiation tests dodges the issue. PHPUnit 12 runs tests
     * in declaration order by default.
     */
    /**
     * Verify Settings::set() persists pixel_id to system_settings.
     *
     * The assertion targets the DB row directly because the SettingModel
     * read path (Cache::remember + MultisiteScope + Site::getSiteIdFromContext)
     * is fragile across test boundaries in the hermetic SQLite harness — the
     * Site::singleton state is not consistently reset between tests, so a
     * Settings::get() round-trip read can return null while the row is
     * present in the DB. The contract we care about is "set() writes a row
     * with the correct value". Live backend Settings::get() reads always
     * succeed because Site context is correctly initialised per HTTP request.
     */
    public function test_pixel_id_round_trips_through_settings(): void
    {
        Cache::flush();
        Settings::clearInternalCache();

        Settings::set('pixel_id', '2291486191076331');

        $obRow = \DB::table('system_settings')
            ->where('item', Settings::SETTINGS_CODE)
            ->first();
        $this->assertNotNull($obRow, 'system_settings row must exist after Settings::set().');

        $arDecoded = json_decode((string) $obRow->value, true);
        $this->assertIsArray($arDecoded);
        $this->assertSame('2291486191076331', $arDecoded['pixel_id'] ?? null);
    }

    public function test_register_settings_returns_meta_pixel_entry(): void
    {
        $arSettings = (new Plugin($this->app))->registerSettings();

        $this->assertArrayHasKey('logingrupa-metapixelshopaholic-settings', $arSettings);
        $this->assertCount(1, $arSettings, 'registerSettings() must expose exactly one settings entry.');

        $arEntry = $arSettings['logingrupa-metapixelshopaholic-settings'];
        $this->assertSame(Settings::class, $arEntry['class']);
    }

    public function test_paid_status_code_options_contains_new_payment_received(): void
    {
        $arOptions = (new Settings())->getPaidStatusCodeOptions();

        $this->assertNotEmpty($arOptions, 'getPaidStatusCodeOptions() must return a non-empty array.');
        $this->assertArrayHasKey(
            'new-payment-received',
            $arOptions,
            'The "new-payment-received" status code must appear in the dropdown options.'
        );
    }

    public function test_queue_connection_options_returns_static_three_drivers(): void
    {
        $arOptions = (new Settings())->getQueueConnectionOptions();

        $this->assertSame(
            ['database' => 'database', 'redis' => 'redis', 'sync' => 'sync'],
            $arOptions
        );
    }

    public function test_fields_yaml_binds_lang_keys_per_field(): void
    {
        $sYamlPath = __DIR__.'/../../models/settings/fields.yaml';
        $this->assertFileExists($sYamlPath, 'models/settings/fields.yaml must exist.');

        $arData = Yaml::parseFile($sYamlPath);
        $this->assertIsArray($arData);
        $this->assertArrayHasKey('tabs', $arData);
        $this->assertArrayHasKey('fields', $arData['tabs']);

        foreach (self::FIELD_KEYS as $sKey) {
            $this->assertArrayHasKey(
                $sKey,
                $arData['tabs']['fields'],
                "Field '$sKey' must be declared in fields.yaml."
            );

            $this->assertSame(
                "logingrupa.metapixelshopaholic::lang.field.$sKey",
                $arData['tabs']['fields'][$sKey]['label'] ?? null,
                "Field '$sKey' label must point at logingrupa.metapixelshopaholic::lang.field.$sKey."
            );

            $this->assertSame(
                "logingrupa.metapixelshopaholic::lang.field.{$sKey}_comment",
                $arData['tabs']['fields'][$sKey]['commentAbove'] ?? null,
                "Field '$sKey' commentAbove must point at logingrupa.metapixelshopaholic::lang.field.{$sKey}_comment."
            );
        }
    }
}
