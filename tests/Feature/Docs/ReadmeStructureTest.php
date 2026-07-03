<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Feature/Lang/LangKeyCoverageTest.php) because Pest's
 * $rootPath resolution under `vendor/bin/pest --configuration phpunit.xml`
 * does not always pick up the Pest.php binding. The explicit extends keeps
 * the gate working under any Pest invocation shape.
 */

final class ReadmeStructureTest extends MetapixelTestCase
{
    /**
     * Hermetic README load — reads README.md from disk relative to the
     * plugin root (dirname(__DIR__, 3) from tests/Feature/Docs/). No
     * Filesystem facade, no Translator binding required.
     */
    private function loadReadme(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/README.md');
    }

    /**
     * Flatten a nested lang array into a list of dot-notation leaf keys.
     * Mirrors LangKeyCoverageTest::flattenKeys (RESEARCH § Pattern 11).
     *
     * @param  array<string, mixed>  $arSource
     * @return list<array{key: string, value: string}>
     */
    private function flattenStringLeaves(array $arSource, string $sPrefix = ''): array
    {
        $arOut = [];
        foreach ($arSource as $sKey => $mValue) {
            $sFullKey = $sPrefix === '' ? (string) $sKey : "$sPrefix.$sKey";
            if (is_array($mValue)) {
                $arOut = array_merge($arOut, $this->flattenStringLeaves($mValue, $sFullKey));
            } elseif (is_string($mValue) && $mValue !== '') {
                $arOut[] = ['key' => $sFullKey, 'value' => $mValue];
            }
        }

        return $arOut;
    }

    public function test_readme_file_exists(): void
    {
        $sPath = dirname(__DIR__, 3).'/README.md';
        $this->assertFileExists($sPath, 'README.md must ship at plugin root for DOCS-01.');
    }

    public function test_readme_contains_seven_named_sections(): void
    {
        $sReadme = $this->loadReadme();
        $iCount = preg_match_all(
            '/^## (Install|Configure|Acquire|Shopaholic|Theme|FailedEvents|Troubleshoot)/m',
            $sReadme,
        );
        $this->assertGreaterThanOrEqual(
            7,
            $iCount,
            'README must include 7 named H2 sections (Install, Configure, Acquire, Shopaholic, Theme, FailedEvents, Troubleshoot) per DOCS-01.',
        );
    }

    public function test_readme_has_no_v1x_references(): void
    {
        $sReadme = $this->loadReadme();
        $this->assertStringNotContainsString(
            'v1.1.1',
            $sReadme,
            'D-22 lock — README is fresh v2.0 surface, no v1.x diff text.',
        );
        $this->assertStringNotContainsString(
            'legacy/v1',
            $sReadme,
            'D-23 lock — no legacy branch references on public surface.',
        );
    }

    public function test_readme_install_block_shows_october_migrate(): void
    {
        $sReadme = $this->loadReadme();
        $this->assertStringContainsString(
            'php artisan october:migrate',
            $sReadme,
            'README install block must show `php artisan october:migrate` — October 4.3 deprecated the old migrate-on-install command to a no-op; only october:migrate applies plugin migrations.',
        );
    }

    public function test_readme_install_block_shows_vcs_repositories_pattern(): void
    {
        $sReadme = $this->loadReadme();
        $bHasCompact = str_contains($sReadme, '"type":"vcs"');
        $bHasSpaced = str_contains($sReadme, '"type": "vcs"');
        $this->assertTrue(
            $bHasCompact || $bHasSpaced,
            'README install block must show the Composer VCS repositories pattern (D-25 — `"type":"vcs"` or `"type": "vcs"`).',
        );
    }

    /**
     * DOCS-02 walkthrough fidelity — every non-empty `field.*_label` value
     * shipped in lang/en/lang.php must appear verbatim in README content.
     * Anchors the README as the executable walkthrough for the Settings UI.
     */
    public function test_readme_anchors_field_labels_from_lang_en(): void
    {
        $sReadme = $this->loadReadme();
        /** @var array<string, mixed> $arEn */
        $arEn = require dirname(__DIR__, 3).'/lang/en/lang.php';

        $arFieldNode = $arEn['field'] ?? [];
        $this->assertIsArray($arFieldNode, 'lang/en/lang.php must define a `field` sub-array.');

        $arLeaves = $this->flattenStringLeaves($arFieldNode);

        foreach ($arLeaves as $arLeaf) {
            if (! str_ends_with($arLeaf['key'], '_label')) {
                continue;
            }
            $this->assertStringContainsString(
                $arLeaf['value'],
                $sReadme,
                "README.md must include the lang/en label '{$arLeaf['value']}' (field.{$arLeaf['key']}) — DOCS-02 walkthrough fidelity.",
            );
        }
    }

    /**
     * DOCS-01 install fidelity — the Install section must document the two
     * fresh-install prerequisites proven by the 2026-07-03 clean-room run:
     * the `project:set` gateway registration and the `-W` require flag.
     */
    public function test_readme_install_documents_fresh_install_prerequisites(): void
    {
        $sReadme = $this->loadReadme();
        $this->assertStringContainsString(
            'php artisan project:set',
            $sReadme,
            'README install must document `php artisan project:set <license>` — registers the October gateway so october/system + lovata/* resolve on a fresh install (DOCS-01).',
        );
        $this->assertStringContainsString(
            'oc-metapixel-plugin -W',
            $sReadme,
            'README require command must carry the `-W` flag — a fresh October lockfile pins composer/installers that toolbox ^2.2 must move (DOCS-01).',
        );
    }

    /**
     * DOCS-01 quick-start — the README must ship one ordered quick-start box
     * reaching the first Meta Test Events hit without detouring through the
     * full Shopaholic/Theme walkthroughs.
     */
    public function test_readme_ships_ordered_quick_start(): void
    {
        $sReadme = $this->loadReadme();
        $this->assertStringContainsString(
            'Quick start',
            $sReadme,
            'README must ship a "Quick start" box (DOCS-01 ordered zero-to-first-event path).',
        );
        $this->assertStringContainsString(
            'project:set',
            $sReadme,
            'Quick start must include the `project:set` gateway step (DOCS-01).',
        );
        $this->assertStringContainsString(
            'Test Events',
            $sReadme,
            'Quick start must reach the Meta Test Events verification (DOCS-01).',
        );
    }
}
