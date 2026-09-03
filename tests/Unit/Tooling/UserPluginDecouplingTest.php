<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-10 — user plugin decoupling guard.
 *
 * The shareable-plugin contract: metapixel runs on shops using either user plugin
 * (Lovata.Buddies or RainLab.User), which it guarantees by referencing NEITHER in
 * runtime code. The CI matrix installs Buddies to prove the integration; this test
 * pins the source-level decoupling so a hard reference goes red on every host.
 */
final class UserPluginDecouplingTest extends MetapixelTestCase
{
    public function test_runtime_sources_reference_no_user_plugin_classes(): void
    {
        $sPluginRoot = dirname(__DIR__, 3);
        $arScanList = ['classes', 'components', 'console', 'controllers', 'Plugin.php'];

        $arOffenderList = [];
        foreach ($arScanList as $sPath) {
            $sFullPath = $sPluginRoot.DIRECTORY_SEPARATOR.$sPath;
            if (!file_exists($sFullPath)) {
                continue;
            }

            foreach ($this->getPhpFileList($sFullPath) as $sFile) {
                $sSource = (string) file_get_contents($sFile);
                if (strpos($sSource, 'Lovata\\Buddies') !== false || strpos($sSource, 'RainLab\\User') !== false) {
                    $arOffenderList[] = substr($sFile, strlen($sPluginRoot) + 1);
                }
            }
        }

        self::assertSame([], $arOffenderList, 'runtime code must not hard-reference a user plugin; resolve users through Toolbox UserHelper');
    }

    /**
     * @return string[]
     */
    private function getPhpFileList(string $sPath): array
    {
        if (is_file($sPath)) {
            return [$sPath];
        }

        $arFileList = [];
        $obIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sPath, FilesystemIterator::SKIP_DOTS));
        foreach ($obIterator as $obFile) {
            if ($obFile->getExtension() === 'php') {
                $arFileList[] = $obFile->getPathname();
            }
        }

        return $arFileList;
    }
}
