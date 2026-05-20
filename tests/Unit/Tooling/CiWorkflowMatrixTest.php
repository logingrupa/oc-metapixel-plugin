<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-09 — CI matrix workflow correctness regression test.
 *
 * NOTE: One assertion is EXPECTED to FAIL on current master — it surfaces the
 * active BLOCKER: the workflow uses `--exclude-testsuite='Metapixel Adapter Tests'`
 * (stale; phpunit.xml has no such testsuite after Phase 3 migrated to #[Group('adapter')]).
 * The correct form is `--exclude-group=adapter`. Do NOT modify the YAML to make
 * this pass — the failing test IS the bug report.
 */
final class CiWorkflowMatrixTest extends MetapixelTestCase
{
    private static string $sContent = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sContent === '') {
            $sPath = dirname(__DIR__, 3).'/.github/workflows/metapixel-qa.yml';
            self::$sContent = (string) file_get_contents($sPath);
        }
    }

    public function test_matrix_declares_php_83_and_84(): void
    {
        $bHas83 = str_contains(self::$sContent, "'8.3'") || str_contains(self::$sContent, '"8.3"');
        $bHas84 = str_contains(self::$sContent, "'8.4'") || str_contains(self::$sContent, '"8.4"');
        $this->assertTrue($bHas83, 'CI matrix must include PHP 8.3');
        $this->assertTrue($bHas84, 'CI matrix must include PHP 8.4');
    }

    public function test_matrix_declares_full_lovata_and_minimal_install_modes(): void
    {
        $bHasFull = str_contains(self::$sContent, 'full-lovata');
        $bHasMinimal = str_contains(self::$sContent, 'minimal');
        $this->assertTrue($bHasFull, 'CI matrix must include full-lovata install mode');
        $this->assertTrue($bHasMinimal, 'CI matrix must include minimal install mode');
    }

    public function test_run_a_full_lovata_invokes_coverage_with_min_90_gate(): void
    {
        $bMatch = (bool) preg_match('/--coverage\s+--min=90/', self::$sContent);
        $this->assertTrue($bMatch, 'Run A (full-lovata) must invoke pest --coverage --min=90');
    }

    public function test_run_b_minimal_uses_exclude_group_adapter_not_stale_testsuite(): void
    {
        // EXPECTED FAILING ASSERTION — surfaces BLOCKER:
        // Workflow currently uses `--exclude-testsuite='Metapixel Adapter Tests'` which is
        // a no-op because phpunit.xml has no such testsuite (Phase 3 migrated to #[Group('adapter')]).
        // The correct flag is `--exclude-group=adapter`.
        $bHasCorrectFlag = str_contains(self::$sContent, '--exclude-group=adapter');
        $this->assertTrue(
            $bHasCorrectFlag,
            'Run B (minimal) must use --exclude-group=adapter (not --exclude-testsuite). '.
            'BLOCKER: phpunit.xml no longer ships "Metapixel Adapter Tests" testsuite; '.
            'the stale --exclude-testsuite flag is a silent no-op per CLAUDE.md Phase 3 migration.',
        );
    }

    public function test_stale_testsuite_string_does_not_appear_in_workflow(): void
    {
        // EXPECTED FAILING ASSERTION — same BLOCKER from the other direction:
        // If 'Metapixel Adapter Tests' is in the YAML, the dead-suite regression is present.
        $bHasDeadSuite = str_contains(self::$sContent, 'Metapixel Adapter Tests');
        $this->assertFalse(
            $bHasDeadSuite,
            'BLOCKER: workflow references "Metapixel Adapter Tests" testsuite which does not exist. '.
            'Replace --exclude-testsuite=\'Metapixel Adapter Tests\' with --exclude-group=adapter.',
        );
    }
}
