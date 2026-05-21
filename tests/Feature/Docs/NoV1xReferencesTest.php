<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Feature/Lang/LangKeyCoverageTest.php + AssetsExistTest)
 * so the gate runs under any Pest invocation shape.
 */

/**
 * D-23 gate — public-shipped surface (Plugin.php, classes/, lang/) must
 * carry no "Phase N" docblock decorators, no "v1.x" or "legacy/v1"
 * narrative. Future PRs adding such markers fail this gate before merge.
 */
final class NoV1xReferencesTest extends MetapixelTestCase
{
    /**
     * Plugin root resolves three levels up from tests/Feature/Docs/.
     */
    private function pluginRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Recursive list of every *.php file under a directory relative to the
     * plugin root. Returned paths are absolute.
     *
     * @return list<string>
     */
    private function listPhpFilesUnder(string $sRelativeDir): array
    {
        $sAbsoluteDir = $this->pluginRoot().'/'.$sRelativeDir;
        if (! is_dir($sAbsoluteDir)) {
            return [];
        }

        $obIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sAbsoluteDir, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $arPaths = [];
        foreach ($obIterator as $obFile) {
            if ($obFile->isFile() && $obFile->getExtension() === 'php') {
                $arPaths[] = $obFile->getPathname();
            }
        }

        return $arPaths;
    }

    /**
     * Flatten a nested lang array into leaf string values for substring
     * scanning. Mirrors LangKeyCoverageTest::flattenKeys but keeps the
     * leaf values (not the keys).
     *
     * @param  array<string, mixed>  $arSource
     * @return list<string>
     */
    private function flattenValues(array $arSource): array
    {
        $arOut = [];
        foreach ($arSource as $mValue) {
            if (is_array($mValue)) {
                $arOut = array_merge($arOut, $this->flattenValues($mValue));
            } elseif (is_string($mValue)) {
                $arOut[] = $mValue;
            }
        }

        return $arOut;
    }

    public function test_plugin_php_has_no_phase_n_decorators(): void
    {
        $sSource = (string) file_get_contents($this->pluginRoot().'/Plugin.php');
        $this->assertDoesNotMatchRegularExpression(
            '/Phase\s+[0-9]/',
            $sSource,
            'D-23 lock — Plugin.php must carry no "Phase N" docblock decorators on public-shipped surface.',
        );
    }

    public function test_plugin_php_has_no_legacy_v1_references(): void
    {
        $sSource = (string) file_get_contents($this->pluginRoot().'/Plugin.php');
        $this->assertDoesNotMatchRegularExpression(
            '/legacy\/v1/',
            $sSource,
            'D-23 lock — Plugin.php must not reference the legacy/v1 branch.',
        );
    }

    public function test_classes_dir_has_no_phase_n_decorators(): void
    {
        $arPaths = $this->listPhpFilesUnder('classes');
        $this->assertNotSame([], $arPaths, 'classes/ must contain at least one *.php file.');

        foreach ($arPaths as $sPath) {
            $sSource = (string) file_get_contents($sPath);
            $this->assertDoesNotMatchRegularExpression(
                '/Phase\s+[0-9]/',
                $sSource,
                "D-23 lock — {$sPath} must carry no 'Phase N' docblock decorators on public-shipped surface.",
            );
        }
    }

    public function test_lang_en_has_no_v1x_references(): void
    {
        /** @var array<string, mixed> $arLang */
        $arLang = require $this->pluginRoot().'/lang/en/lang.php';
        $arValues = $this->flattenValues($arLang);

        foreach ($arValues as $sValue) {
            $this->assertStringNotContainsString(
                'v1.',
                $sValue,
                "D-23 lock — lang/en/lang.php must not surface the 'v1.' substring (saw: {$sValue}).",
            );
            $this->assertStringNotContainsString(
                'legacy/v1',
                $sValue,
                "D-23 lock — lang/en/lang.php must not reference the legacy/v1 branch (saw: {$sValue}).",
            );
        }
    }

    public function test_lang_lv_has_no_v1x_references(): void
    {
        /** @var array<string, mixed> $arLang */
        $arLang = require $this->pluginRoot().'/lang/lv/lang.php';
        $arValues = $this->flattenValues($arLang);

        foreach ($arValues as $sValue) {
            $this->assertStringNotContainsString(
                'v1.',
                $sValue,
                "D-23 lock — lang/lv/lang.php must not surface the 'v1.' substring (saw: {$sValue}).",
            );
            $this->assertStringNotContainsString(
                'legacy/v1',
                $sValue,
                "D-23 lock — lang/lv/lang.php must not reference the legacy/v1 branch (saw: {$sValue}).",
            );
        }
    }
}
