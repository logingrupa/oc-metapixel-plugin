<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Feature/Lang/LangKeyCoverageTest.php) so the gate runs
 * under any Pest invocation shape.
 */

final class AssetsExistTest extends MetapixelTestCase
{
    /**
     * Hermetic load — reads CHANGELOG.md from disk relative to the plugin
     * root (dirname(__DIR__, 3) from tests/Feature/Docs/).
     */
    private function loadChangelog(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/CHANGELOG.md');
    }

    public function test_five_screenshots_present_with_padded_prefix(): void
    {
        $arMatches = glob(dirname(__DIR__, 3).'/docs/screenshots/0[1-5]-*.png');
        $arMatches = is_array($arMatches) ? $arMatches : [];
        $this->assertCount(
            5,
            $arMatches,
            'MKT-03 demands 5 PNG screenshots at docs/screenshots/0[1-5]-*.png (one per Settings tab + FailedEvents).',
        );
    }

    public function test_changelog_file_exists(): void
    {
        $sPath = dirname(__DIR__, 3).'/CHANGELOG.md';
        $this->assertFileExists(
            $sPath,
            'CHANGELOG.md must ship at plugin root for MKT-03 (Keep-a-Changelog 1.1.0 format).',
        );
    }

    public function test_changelog_has_v2_section_header_with_iso_date(): void
    {
        $sChangelog = $this->loadChangelog();
        $this->assertMatchesRegularExpression(
            '/^## \[2\.0\.0\] - \d{4}-\d{2}-\d{2}$/m',
            $sChangelog,
            'CHANGELOG.md must contain a `## [2.0.0] - YYYY-MM-DD` header (Keep-a-Changelog 1.1.0).',
        );
    }

    public function test_changelog_has_added_subsection(): void
    {
        $sChangelog = $this->loadChangelog();
        $this->assertStringContainsString(
            '### Added',
            $sChangelog,
            'CHANGELOG.md must contain a `### Added` subsection under the 2.0.0 release header (Keep-a-Changelog 1.1.0).',
        );
    }

    public function test_changelog_has_no_v1x_diff_text(): void
    {
        $sChangelog = $this->loadChangelog();
        $this->assertStringNotContainsString(
            'v1.1.1',
            $sChangelog,
            'D-22 lock — CHANGELOG.md is fresh v2.0 surface, no v1.x diff text.',
        );
        $this->assertStringNotContainsString(
            'legacy/v1',
            $sChangelog,
            'D-23 lock — CHANGELOG.md must not reference the legacy/v1 branch.',
        );
    }
}
