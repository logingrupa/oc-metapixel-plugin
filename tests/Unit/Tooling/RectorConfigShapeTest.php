<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-05 — rector.php php83-only regression test.
 *
 * Locks the rector.php config: php83 set present, php84 set absent, and
 * exactly the four permitted prepared sets.
 */
final class RectorConfigShapeTest extends MetapixelTestCase
{
    private static string $sContent = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sContent === '') {
            $sPath = dirname(__DIR__, 3).'/rector.php';
            self::$sContent = (string) file_get_contents($sPath);
        }
    }

    public function test_rector_contains_php83_set(): void
    {
        $bMatch = (bool) preg_match('/withPhpSets\s*\([^)]*php83\s*:\s*true/', self::$sContent);
        $this->assertTrue($bMatch, 'rector.php must call withPhpSets(php83: true)');
    }

    public function test_rector_does_not_contain_php84_set(): void
    {
        $bHasPhp84 = (bool) preg_match('/php84\s*:\s*true/', self::$sContent);
        $this->assertFalse($bHasPhp84, 'rector.php must NOT contain php84: true — caps upgrade rewrites at PHP 8.3');
    }

    public function test_rector_contains_dead_code_prepared_set(): void
    {
        $bMatch = (bool) preg_match('/deadCode\s*:\s*true/', self::$sContent);
        $this->assertTrue($bMatch, 'rector.php must include deadCode: true in withPreparedSets');
    }

    public function test_rector_contains_code_quality_prepared_set(): void
    {
        $bMatch = (bool) preg_match('/codeQuality\s*:\s*true/', self::$sContent);
        $this->assertTrue($bMatch, 'rector.php must include codeQuality: true in withPreparedSets');
    }

    public function test_rector_contains_type_declarations_prepared_set(): void
    {
        $bMatch = (bool) preg_match('/typeDeclarations\s*:\s*true/', self::$sContent);
        $this->assertTrue($bMatch, 'rector.php must include typeDeclarations: true in withPreparedSets');
    }

    public function test_rector_contains_early_return_prepared_set(): void
    {
        $bMatch = (bool) preg_match('/earlyReturn\s*:\s*true/', self::$sContent);
        $this->assertTrue($bMatch, 'rector.php must include earlyReturn: true in withPreparedSets');
    }
}
