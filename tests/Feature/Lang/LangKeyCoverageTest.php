<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Unit/PluginSanityTest.php) because Pest's $rootPath
 * resolution under `vendor/bin/pest --configuration phpunit.xml` does not
 * always pick up the Pest.php binding. The explicit extends keeps this
 * smoke test working under any Pest invocation shape.
 */

final class LangKeyCoverageTest extends MetapixelTestCase
{
    /**
     * Hermetic file-load — every assertion reads lang/{en,lv}/lang.php from
     * disk (no Translator binding required). Mirrors the helper used by
     * SettingsBeforeSaveTest::test_settings_fields_lang_keys_resolve_to_human_readable_strings.
     *
     * @return array<string, mixed>
     */
    private function loadEnLang(): array
    {
        return require dirname(__DIR__, 3).'/lang/en/lang.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadLvLang(): array
    {
        return require dirname(__DIR__, 3).'/lang/lv/lang.php';
    }

    /**
     * Flatten a nested lang array into a list of dot-notation leaf keys.
     * Per RESEARCH Pattern 11 lines 1380-1396.
     *
     * @param  array<string, mixed>  $arSource
     * @return list<string>
     */
    private function flattenKeys(array $arSource, string $sPrefix = ''): array
    {
        $arOut = [];
        foreach ($arSource as $sKey => $mValue) {
            $sFullKey = $sPrefix === '' ? (string) $sKey : "$sPrefix.$sKey";
            if (is_array($mValue)) {
                $arOut = array_merge($arOut, $this->flattenKeys($mValue, $sFullKey));
            } else {
                $arOut[] = $sFullKey;
            }
        }

        return $arOut;
    }

    /**
     * Resolve a dot-notation key against a nested array; returns null if any
     * segment is missing. Mirrors Lang::get('logingrupa.metapixel::lang.X.Y').
     *
     * @param  array<string, mixed>  $arSource
     */
    private function dotGet(array $arSource, string $sDotKey): mixed
    {
        $mCursor = $arSource;
        foreach (explode('.', $sDotKey) as $sSegment) {
            if (! is_array($mCursor) || ! array_key_exists($sSegment, $mCursor)) {
                return null;
            }
            $mCursor = $mCursor[$sSegment];
        }

        return $mCursor;
    }

    public function test_en_lang_file_exists_and_returns_array(): void
    {
        $sPath = dirname(__DIR__, 3).'/lang/en/lang.php';
        $this->assertFileExists($sPath, 'lang/en/lang.php must ship with the plugin.');

        $arEn = $this->loadEnLang();
        $this->assertIsArray($arEn);
    }

    public function test_lv_lang_file_exists_and_returns_array(): void
    {
        $sPath = dirname(__DIR__, 3).'/lang/lv/lang.php';
        $this->assertFileExists($sPath, 'lang/lv/lang.php must ship with the plugin.');

        $arLv = $this->loadLvLang();
        $this->assertIsArray($arLv);
    }

    public function test_no_ru_lang_file_shipped(): void
    {
        // D-17 lock — Russian translations dropped in v2.0; operators
        // self-service via custom lang overrides outside the plugin tree.
        $sPath = dirname(__DIR__, 3).'/lang/ru/lang.php';
        $this->assertFalse(
            file_exists($sPath),
            'lang/ru/lang.php must NOT ship — D-17 locks Russian out of v2.0 marketplace plugin.',
        );
    }

    public function test_en_lang_has_at_least_50_keys(): void
    {
        $arEn = $this->loadEnLang();
        $arEnKeys = $this->flattenKeys($arEn);

        $this->assertGreaterThanOrEqual(
            50,
            count($arEnKeys),
            'lang/en/lang.php must expose at least 50 leaf keys (D-19 coverage list — 4 tabs + 8 fields × 2 + 23 failed_events + menu + exception ~= 55-60).',
        );
    }

    public function test_lv_key_shape_matches_en(): void
    {
        $arEn = $this->loadEnLang();
        $arLv = $this->loadLvLang();

        $arEnKeys = $this->flattenKeys($arEn);
        $arLvKeys = $this->flattenKeys($arLv);

        // Pest matcher: toEqualCanonicalizing — order-insensitive equality.
        // Anchored as a literal in the source so the LANG-01 coverage gate
        // is grep-discoverable from the plan acceptance criteria.
        expect($arLvKeys)->toEqualCanonicalizing($arEnKeys);
    }

    public function test_required_phase_4_keys_exist_in_en(): void
    {
        $arEn = $this->loadEnLang();

        $arRequiredKeys = [
            'field.trusted_hosts_label',
            'field.trusted_hosts_comment',
            'field.ensure_fbp_fbc_label',
            'field.ensure_fbp_fbc_comment',
            'tab.pixel_and_capi',
            'tab.hosts_and_cookies',
            'tab.theme_tracking',
            'tab.advanced',
            'failed_events.list_title',
            'failed_events.column_dedup_pct',
            'failed_events.button_replay',
            'failed_events.button_check_dedup',
            'failed_events.confirm_replay',
            'menu.failed_events',
            'exception.missing_pixel_config',
            'exception.invalid_trusted_hosts',
        ];

        foreach ($arRequiredKeys as $sDotKey) {
            $mValue = $this->dotGet($arEn, $sDotKey);
            $this->assertIsString(
                $mValue,
                "Required Phase 4 lang key '$sDotKey' is missing from lang/en/lang.php.",
            );
            $this->assertGreaterThan(
                0,
                strlen((string) $mValue),
                "Required Phase 4 lang key '$sDotKey' resolves to an empty string.",
            );
        }
    }

    public function test_lv_strings_are_not_blank(): void
    {
        $arLv = $this->loadLvLang();
        $arLvKeys = $this->flattenKeys($arLv);

        foreach ($arLvKeys as $sDotKey) {
            $mValue = $this->dotGet($arLv, $sDotKey);
            $this->assertIsString(
                $mValue,
                "LV lang leaf '$sDotKey' is not a string.",
            );
            $this->assertGreaterThan(
                0,
                strlen((string) $mValue),
                "LV lang leaf '$sDotKey' resolves to an empty string (D-18 — native Latvian, no blank stubs).",
            );
        }
    }

    public function test_lv_strings_are_not_machine_translation_artifacts(): void
    {
        // D-18 lock — LV translations authored by a Latvian-fluent operator,
        // not auto-stubbed. Reject any leaf that retains the planner's
        // placeholder markers.
        $arLv = $this->loadLvLang();
        $arLvKeys = $this->flattenKeys($arLv);

        foreach ($arLvKeys as $sDotKey) {
            $sValue = (string) $this->dotGet($arLv, $sDotKey);
            $this->assertStringStartsNotWith(
                '[TODO]',
                $sValue,
                "LV lang leaf '$sDotKey' starts with '[TODO]' — placeholder leaked into shipped translations.",
            );
            $this->assertStringStartsNotWith(
                '[TRANSLATE]',
                $sValue,
                "LV lang leaf '$sDotKey' starts with '[TRANSLATE]' — placeholder leaked into shipped translations.",
            );
        }
    }
}
