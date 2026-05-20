<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-11 — composer-dependency-analyser allowlist regression test.
 *
 * Locks path-scoped allowlist behavior. One assertion is EXPECTED to FAIL:
 * Plugin.php imports are not covered by ignoreErrorsOnPackageAndPath because
 * Plugin.php is not inside the shopaholic adapter subdirectory. This surfaces
 * a gap: if Plugin.php ever imports Lovata classes, the analyser would flag it.
 * Do NOT modify composer-dependency-analyser.php to make this pass.
 */
final class ComposerDependencyAnalyserScopeTest extends MetapixelTestCase
{
    private static string $sContent = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sContent === '') {
            $sPath = dirname(__DIR__, 3).'/composer-dependency-analyser.php';
            self::$sContent = (string) file_get_contents($sPath);
        }
    }

    public function test_config_uses_ignore_errors_on_package_and_path_for_shopaholic_plugin(): void
    {
        // The implementation may use a loop with variables — package names appear in the array
        // and ignoreErrorsOnPackageAndPath is called in the loop body. Assert both are present.
        $bHasPackage = str_contains(self::$sContent, 'lovata/shopaholic-plugin');
        $bHasPathScopedCall = str_contains(self::$sContent, 'ignoreErrorsOnPackageAndPath');
        $this->assertTrue($bHasPackage, 'config must reference lovata/shopaholic-plugin');
        $this->assertTrue($bHasPathScopedCall, 'config must call ignoreErrorsOnPackageAndPath for path-scoped allowlist');
    }

    public function test_config_uses_ignore_errors_on_package_and_path_for_ordersshopaholic_plugin(): void
    {
        $bHasPackage = str_contains(self::$sContent, 'lovata/ordersshopaholic-plugin');
        $bHasPathScopedCall = str_contains(self::$sContent, 'ignoreErrorsOnPackageAndPath');
        $this->assertTrue($bHasPackage, 'config must reference lovata/ordersshopaholic-plugin');
        $this->assertTrue($bHasPathScopedCall, 'config must call ignoreErrorsOnPackageAndPath for path-scoped allowlist');
    }

    public function test_config_uses_ignore_errors_on_package_and_path_for_buddies_plugin(): void
    {
        $bHasPackage = str_contains(self::$sContent, 'lovata/buddies-plugin');
        $bHasPathScopedCall = str_contains(self::$sContent, 'ignoreErrorsOnPackageAndPath');
        $this->assertTrue($bHasPackage, 'config must reference lovata/buddies-plugin');
        $this->assertTrue($bHasPathScopedCall, 'config must call ignoreErrorsOnPackageAndPath for path-scoped allowlist');
    }

    public function test_allowlist_entries_cover_adapter_shopaholic_path(): void
    {
        $bMatch = str_contains(self::$sContent, 'classes/adapter/shopaholic');
        $this->assertTrue($bMatch, 'allowlist must cover classes/adapter/shopaholic path');
    }

    public function test_allowlist_entries_cover_event_adapter_shopaholic_path(): void
    {
        $bMatch = str_contains(self::$sContent, 'classes/event/adapter/shopaholic');
        $this->assertTrue($bMatch, 'allowlist must cover classes/event/adapter/shopaholic path');
    }

    public function test_no_bare_ignore_errors_on_package_for_shopaholic_plugin(): void
    {
        // Post-commit 43351ca fix: global ignoreErrorsOnPackage for lovata packages was removed.
        // It defeated path-scoping — errors were suppressed globally instead of per-path.
        // Bare ignoreErrorsOnPackage (not ignoreErrorsOnPackageAndPath) on lovata packages is the bug.
        $bHasBareIgnore = (bool) preg_match(
            '/ignoreErrorsOnPackage\s*\(\s*[\'"]lovata\/shopaholic-plugin[\'"]/',
            self::$sContent,
        );
        $this->assertFalse(
            $bHasBareIgnore,
            'Global ignoreErrorsOnPackage for lovata/shopaholic-plugin detected. '.
            'This defeats path-scoped allowlist — remove the bare global call.',
        );
    }

    public function test_no_bare_ignore_errors_on_package_for_ordersshopaholic_plugin(): void
    {
        $bHasBareIgnore = (bool) preg_match(
            '/ignoreErrorsOnPackage\s*\(\s*[\'"]lovata\/ordersshopaholic-plugin[\'"]/',
            self::$sContent,
        );
        $this->assertFalse(
            $bHasBareIgnore,
            'Global ignoreErrorsOnPackage for lovata/ordersshopaholic-plugin detected. '.
            'This defeats path-scoped allowlist — remove the bare global call.',
        );
    }

    public function test_plugin_php_is_covered_by_lovata_allowlist_for_top_level_imports(): void
    {
        // EXPECTED FAILING ASSERTION — surfaces the Plugin.php allowlist gap:
        // Plugin.php lives at plugin root, not inside classes/adapter/shopaholic or
        // classes/event/adapter/shopaholic. If Plugin.php imports Lovata classes,
        // the analyser will flag them. The allowlist should explicitly cover Plugin.php
        // via an ignoreErrorsOnPackageAndPath call that includes 'Plugin.php' in its path arg.
        // This test asserts the gap so the engineer can decide: add Plugin.php to
        // the allowlist, OR fully-qualify all Lovata imports in Plugin.php.
        $bAllowlistCoversPluginPhp = (bool) preg_match(
            '/ignoreErrorsOnPackageAndPath[^;]*Plugin\.php/s',
            self::$sContent,
        );
        $this->assertTrue(
            $bAllowlistCoversPluginPhp,
            'GAP: composer-dependency-analyser.php does not allowlist Plugin.php for Lovata imports. '.
            'The path-scoped allowlist only covers classes/adapter/shopaholic and classes/event/adapter/shopaholic. '.
            'Either add ignoreErrorsOnPackageAndPath covering Plugin.php, or ensure Plugin.php '.
            'contains no direct Lovata cart plugin imports.',
        );
    }
}
