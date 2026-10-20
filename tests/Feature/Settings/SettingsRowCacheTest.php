<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\PluginGuard;
use Logingrupa\Metapixel\Classes\Helper\SettingsRowCache;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Support\Facades\Site;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every metapixel settings read, credential or not, is served from one cached
 * snapshot of the rows: one statement cold, none warm, per-site rows resolved
 * in PHP exactly as SettingModel's MultisiteScope query would.
 */
final class SettingsRowCacheTest extends MetapixelTestCase
{
    /** RainLab Translate cleans these up when a settings record is deleted. */
    private const TRANSLATE_TABLES = ['rainlab_translate_attributes', 'rainlab_translate_indexes'];

    /** @var list<string> */
    private array $arStatementList = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
        DB::listen(function ($obQuery): void {
            if (in_array(Settings::SETTINGS_CODE, $obQuery->bindings, true)) {
                $this->arStatementList[] = $obQuery->sql;
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (['system_site_definitions', ...self::TRANSLATE_TABLES] as $sTable) {
            $this->app['db']->connection()->getSchemaBuilder()->dropIfExists($sTable);
        }
        Site::resetCache();
        parent::tearDown();
    }

    public function test_one_statement_cold_and_none_warm_across_repeated_lookups(): void
    {
        $this->seedSites();
        $this->seedSettingsRow(1, ['pixel_id' => '1111111111', 'capi_access_token' => 'EAA1', 'default_currency_code' => 'EUR']);
        $this->arStatementList = [];

        $this->readEverySettingsPath();

        $this->assertCount(1, $this->arStatementList);
        $this->assertStringContainsString('order by', $this->arStatementList[0]);

        $this->arStatementList = [];
        Settings::clearInternalCache();
        PluginGuard::reset();

        $this->readEverySettingsPath();

        $this->assertSame([], $this->arStatementList, 'a fresh process reads the snapshot from the store');
    }

    public function test_settings_set_and_reset_default_forget_the_snapshot(): void
    {
        $this->seedSites();
        $this->seedSettingsRow(1, ['pixel_id' => '1111111111', 'capi_access_token' => 'EAA1']);
        $this->assertSame('1111111111', Settings::lookupForSite(1)['pixel_id']);
        $this->assertTrue(Cache::has(SettingsRowCache::CACHE_KEY));

        Site::withContext(1, static function (): void {
            Settings::clearInternalCache();
            Settings::set(['pixel_id' => '2222222222']);
        });

        $this->assertFalse(Cache::has(SettingsRowCache::CACHE_KEY));
        $this->assertSame('2222222222', Settings::lookupForSite(1)['pixel_id']);

        foreach (self::TRANSLATE_TABLES as $sTable) {
            $this->app['db']->connection()->getSchemaBuilder()->create($sTable, static function ($obTable): void {
                $obTable->increments('id');
                $obTable->string('model_id')->nullable();
                $obTable->string('model_type')->nullable();
            });
        }
        Site::withContext(1, static function (): void {
            Settings::clearInternalCache();
            (new Settings)->resetDefault();
        });

        $this->assertFalse(Cache::has(SettingsRowCache::CACHE_KEY));
        $this->assertSame('', Settings::lookupForSite(1)['pixel_id']);
    }

    /** @return array<string, array{0: int|string|null, 1: list<int|null>, 2: int|null}> */
    public static function contextProvider(): array
    {
        return [
            'own row in site context' => [1, [2, 1], 1],
            'row-less site' => [2, [1], null],
            'global context takes the first row of any site' => ['global', [2, 1], 0],
            'duplicate site rows, first id wins' => [1, [1, 1], 0],
            'null-site row with no site in context' => [null, [1, null], 1],
            'null-site row is no site row' => [1, [null], null],
        ];
    }

    /** @param  list<int|null>  $arRowSiteList */
    #[DataProvider('contextProvider')]
    public function test_settings_record_matches_the_multisite_scope_query(int|string|null $mContext, array $arRowSiteList, ?int $iExpectedIndex): void
    {
        $this->seedSites();
        if ($mContext === null) {
            DB::table('system_site_definitions')->delete();
            Site::resetCache();
        }
        foreach ($arRowSiteList as $iIndex => $iSiteId) {
            $this->seedSettingsRow($iSiteId, ['test_event_code' => 'ROW'.$iIndex]);
        }

        $fnRead = static fn (): array => [Settings::query()->first(), (new Settings)->getSettingsRecord()];
        [$obExpected, $obActual] = match (true) {
            $mContext === 'global' => Site::withGlobalContext($fnRead),
            is_int($mContext) => Site::withContext($mContext, $fnRead),
            default => $fnRead(),
        };

        $this->assertSame($iExpectedIndex === null ? null : $iExpectedIndex + 1, $obExpected?->getKey(), 'fixture: the scope query picks the expected row');
        $this->assertSame($obExpected?->getKey(), $obActual?->getKey());
        $this->assertEquals($obExpected?->getAttributes(), $obActual?->getAttributes());
        $this->assertSame($iExpectedIndex === null ? null : 'ROW'.$iExpectedIndex, $obActual?->getAttribute('test_event_code'));
    }

    public function test_context_switches_resolve_their_own_rows_from_one_snapshot(): void
    {
        $this->seedSites();
        $this->seedSettingsRow(1, ['pixel_id' => '1111111111', 'default_currency_code' => 'EUR']);
        $this->seedSettingsRow(2, ['default_currency_code' => 'NOK']);
        $this->seedSettingsRow(null, ['pixel_id' => '9999999999', 'capi_access_token' => 'EAA9', 'default_currency_code' => 'USD']);
        $this->arStatementList = [];

        $this->assertSame(['pixel_id' => '1111111111', 'capi_access_token' => 'EAA9'], Settings::lookupForSite(1));
        $this->assertSame(['pixel_id' => '9999999999', 'capi_access_token' => 'EAA9'], Settings::lookupForSite(2));
        $this->assertSame('EUR', Site::withGlobalContext(fn (): mixed => $this->readFresh('default_currency_code')));
        $this->assertSame('NOK', Site::withContext(2, fn (): mixed => $this->readFresh('default_currency_code')));
        $this->assertSame('EUR', Site::withContext(1, fn (): mixed => $this->readFresh('default_currency_code')));

        $this->assertCount(1, $this->arStatementList);
    }

    public function test_form_save_on_a_row_less_site_creates_its_row_and_leaves_the_default_row(): void
    {
        $this->seedSites();
        $this->seedSettingsRow(1, ['pixel_id' => '1111111111', 'capi_access_token' => 'EAA1', 'default_currency_code' => 'EUR']);
        $mDefaultValue = DB::table('system_settings')->where('site_id', 1)->value('value');

        Site::withContext(2, function (): void {
            $this->saveFresh('default_currency_code', 'NOK');
        });

        $this->assertFalse(Cache::has(SettingsRowCache::CACHE_KEY));
        $this->assertSame('NOK', Site::withContext(2, fn (): mixed => $this->readFresh('default_currency_code')));
        $this->assertSame('EUR', Site::withContext(1, fn (): mixed => $this->readFresh('default_currency_code')));
        $this->assertSame($mDefaultValue, DB::table('system_settings')->where('site_id', 1)->value('value'));

        Site::withContext(1, function (): void {
            $this->saveFresh('test_event_code', 'TEST1');
        });

        $this->assertSame(2, DB::table('system_settings')->where('item', Settings::SETTINGS_CODE)->count());
        $this->assertSame('TEST1', Site::withContext(1, fn (): mixed => $this->readFresh('test_event_code')));
        $this->assertNull(Site::withContext(2, fn (): mixed => $this->readFresh('test_event_code')));
    }

    private function readEverySettingsPath(): void
    {
        foreach ([1, 2, null] as $iSiteId) {
            PluginGuard::isDisabledForSite($iSiteId);
            Settings::lookupForSite($iSiteId);
        }
        foreach ([1, 2] as $iSiteId) {
            Site::withContext($iSiteId, function (): void {
                $this->readFresh('default_currency_code');
                Settings::isConfigured();
            });
        }
    }

    private function readFresh(string $sKey): mixed
    {
        Settings::clearInternalCache();

        return Settings::get($sKey);
    }

    private function saveFresh(string $sKey, string $sValue): void
    {
        Settings::clearInternalCache();
        $obSettings = Settings::instance();
        $obSettings->setAttribute($sKey, $sValue);
        $obSettings->save();
    }

    private function seedSites(): void
    {
        $fnSeedSites = require __DIR__.'/../../fixtures/sites.php';
        $fnSeedSites(
            $this->app['db']->connection()->getSchemaBuilder(),
            $this->app['db']->connection(),
        );
    }
}
