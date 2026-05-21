<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Feature/Lang/LangKeyCoverageTest.php) because Pest's
 * $rootPath resolution under `vendor/bin/pest --configuration phpunit.xml`
 * does not always pick up the Pest.php binding.
 */

final class CustomAdaptersStructureTest extends MetapixelTestCase
{
    /**
     * Hermetic load — reads docs/CUSTOM-ADAPTERS.md from disk relative to
     * the plugin root (dirname(__DIR__, 3) from tests/Feature/Docs/).
     */
    private function loadCustomAdaptersDoc(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/docs/CUSTOM-ADAPTERS.md');
    }

    public function test_custom_adapters_doc_file_exists(): void
    {
        $sPath = dirname(__DIR__, 3).'/docs/CUSTOM-ADAPTERS.md';
        $this->assertFileExists(
            $sPath,
            'docs/CUSTOM-ADAPTERS.md must ship for DOCS-03 (third-party adapter authoring guide).',
        );
    }

    public function test_doc_contains_before_dispatch_hook_constant(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();
        $iCount = substr_count($sDoc, 'metapixel.event.before_dispatch');
        $this->assertGreaterThanOrEqual(
            1,
            $iCount,
            "docs/CUSTOM-ADAPTERS.md must reference the 'metapixel.event.before_dispatch' hook string (D-15 hook contract).",
        );
    }

    public function test_doc_contains_after_dispatch_hook_constant(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();
        $iCount = substr_count($sDoc, 'metapixel.event.after_dispatch');
        $this->assertGreaterThanOrEqual(
            1,
            $iCount,
            "docs/CUSTOM-ADAPTERS.md must reference the 'metapixel.event.after_dispatch' hook string (D-15 hook contract).",
        );
    }

    public function test_doc_contains_dead_letter_hook_constant(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();
        $iCount = substr_count($sDoc, 'metapixel.event.dead_letter');
        $this->assertGreaterThanOrEqual(
            1,
            $iCount,
            "docs/CUSTOM-ADAPTERS.md must reference the 'metapixel.event.dead_letter' hook string (D-15 hook contract).",
        );
    }

    public function test_doc_contains_offline_mall_inline_example(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();
        $this->assertStringContainsString(
            'OFFLINE\\Mall',
            $sDoc,
            "docs/CUSTOM-ADAPTERS.md must show the OFFLINE\\Mall inline subject example (D-14 opaque alias).",
        );
        $this->assertStringContainsString(
            'mall.order',
            $sDoc,
            "docs/CUSTOM-ADAPTERS.md must show the 'mall.order' opaque subject_type alias (D-14).",
        );
    }

    public function test_doc_contains_contract_testcase_reference(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();
        $this->assertStringContainsString(
            'EventSubjectAdapterContractTestCase',
            $sDoc,
            "docs/CUSTOM-ADAPTERS.md must reference EventSubjectAdapterContractTestCase (D-16 — contract-test reuse).",
        );
        $this->assertStringContainsString(
            'makeAdapter',
            $sDoc,
            "docs/CUSTOM-ADAPTERS.md must reference the makeAdapter() contract hook (D-16).",
        );
        $this->assertStringContainsString(
            'makeSubject',
            $sDoc,
            "docs/CUSTOM-ADAPTERS.md must reference the makeSubject() contract hook (D-16).",
        );
    }

    public function test_doc_shows_register_pattern(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();
        $bHasInstanceForm = str_contains($sDoc, 'AdapterRegistry::instance()->register');
        $bHasStaticForm = str_contains($sDoc, 'AdapterRegistry::register');
        $this->assertTrue(
            $bHasInstanceForm || $bHasStaticForm,
            'docs/CUSTOM-ADAPTERS.md must show the AdapterRegistry registration snippet (either AdapterRegistry::instance()->register or AdapterRegistry::register).',
        );
    }

    public function test_doc_contains_both_acme_cart_minimal_and_mall_full_examples(): void
    {
        $sDoc = $this->loadCustomAdaptersDoc();

        $bHasAcmeCart = str_contains($sDoc, 'AcmeCartAdapter') || str_contains($sDoc, 'class AcmeCart');
        $this->assertTrue(
            $bHasAcmeCart,
            'docs/CUSTOM-ADAPTERS.md must reference AcmeCartAdapter (DOCS-03 + ROADMAP.md Architecture-at-a-glance — minimal register snippet uses canonical AcmeCart name).',
        );

        $bHasMall = str_contains($sDoc, 'OFFLINE\\Mall') || str_contains($sDoc, 'class MallOrderAdapter');
        $this->assertTrue(
            $bHasMall,
            'docs/CUSTOM-ADAPTERS.md must reference OFFLINE\\Mall or MallOrderAdapter (CONTEXT.md D-14 — full inline example tracks OFFLINE\\Mall\\Models\\Order).',
        );
    }
}
