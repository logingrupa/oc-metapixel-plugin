<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-10 — composer qa chain regression test.
 *
 * Locks scripts.qa array shape and verifies each referenced script is defined
 * and delegates to the correct binary.
 */
final class ComposerQaChainTest extends MetapixelTestCase
{
    /** @var array<string, mixed> */
    private static array $arShape = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$arShape === []) {
            $sRaw = file_get_contents(dirname(__DIR__, 3).'/composer.json');
            self::$arShape = json_decode((string) $sRaw, true, 512, JSON_THROW_ON_ERROR);
        }
    }

    public function test_qa_script_is_an_array(): void
    {
        $arQa = self::$arShape['scripts']['qa'] ?? null;
        $this->assertIsArray($arQa, 'scripts.qa must be an array of script references');
    }

    public function test_qa_chain_contains_pint_test_analyse_phpmd_test_cov(): void
    {
        $arQa = self::$arShape['scripts']['qa'] ?? [];
        $this->assertContains('@pint-test', $arQa, 'scripts.qa must contain @pint-test');
        $this->assertContains('@analyse', $arQa, 'scripts.qa must contain @analyse');
        $this->assertContains('@phpmd', $arQa, 'scripts.qa must contain @phpmd');
        $this->assertContains('@test-cov', $arQa, 'scripts.qa must contain @test-cov');
    }

    public function test_pint_test_script_is_defined_and_invokes_pint(): void
    {
        $sScript = self::$arShape['scripts']['pint-test'] ?? '';
        $this->assertNotEmpty($sScript, 'scripts.pint-test must be defined');
        $bInvokesPint = str_contains((string) $sScript, 'pint');
        $this->assertTrue($bInvokesPint, 'scripts.pint-test must invoke pint');
        $bHasTestFlag = str_contains((string) $sScript, '--test');
        $this->assertTrue($bHasTestFlag, 'scripts.pint-test must pass --test flag');
    }

    public function test_analyse_script_is_defined_and_invokes_phpstan(): void
    {
        $sScript = self::$arShape['scripts']['analyse'] ?? '';
        $this->assertNotEmpty($sScript, 'scripts.analyse must be defined');
        $bInvokesPhpstan = str_contains((string) $sScript, 'phpstan');
        $this->assertTrue($bInvokesPhpstan, 'scripts.analyse must invoke phpstan');
    }

    public function test_phpmd_script_is_defined_and_invokes_phpmd(): void
    {
        $sScript = self::$arShape['scripts']['phpmd'] ?? '';
        $this->assertNotEmpty($sScript, 'scripts.phpmd must be defined');
        $bInvokesPhpmd = str_contains((string) $sScript, 'phpmd');
        $this->assertTrue($bInvokesPhpmd, 'scripts.phpmd must invoke phpmd');
    }

    public function test_test_cov_script_is_defined_and_invokes_pest_with_coverage(): void
    {
        $sScript = self::$arShape['scripts']['test-cov'] ?? '';
        $this->assertNotEmpty($sScript, 'scripts.test-cov must be defined');
        $bInvokesPest = str_contains((string) $sScript, 'pest');
        $this->assertTrue($bInvokesPest, 'scripts.test-cov must invoke pest');
        $bHasCoverage = str_contains((string) $sScript, '--coverage');
        $this->assertTrue($bHasCoverage, 'scripts.test-cov must pass --coverage');
        $bHasMin = str_contains((string) $sScript, '--min=90');
        $this->assertTrue($bHasMin, 'scripts.test-cov must enforce --min=90 coverage gate');
    }
}
