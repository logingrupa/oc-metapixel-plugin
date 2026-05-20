<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * TOOL-04 — phpstan.neon ban shape regression test.
 *
 * Locks level, phpVersion, disallowedAttributes (not disallowedClasses),
 * PHP 8.4 function bans, SiteManager/Request disallowIn paths, assert() ban.
 */
final class PhpstanConfigShapeTest extends MetapixelTestCase
{
    private static string $sContent = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$sContent === '') {
            $sPath = dirname(__DIR__, 3).'/phpstan.neon';
            self::$sContent = (string) file_get_contents($sPath);
        }
    }

    public function test_phpstan_level_is_10(): void
    {
        $bMatch = (bool) preg_match('/^\s*level:\s*10\s*$/m', self::$sContent);
        $this->assertTrue($bMatch, 'phpstan.neon must declare level: 10');
    }

    public function test_phpstan_php_version_is_80300(): void
    {
        $bMatch = (bool) preg_match('/^\s*phpVersion:\s*80300\s*$/m', self::$sContent);
        $this->assertTrue($bMatch, 'phpstan.neon must declare phpVersion: 80300');
    }

    public function test_deprecated_attribute_banned_via_disallowed_attributes_block(): void
    {
        // Must use disallowedAttributes: with key `attribute:`, NOT disallowedClasses: with `class:`
        $bHasBlock = str_contains(self::$sContent, 'disallowedAttributes:');
        $this->assertTrue($bHasBlock, 'phpstan.neon must contain a disallowedAttributes: block');

        $bHasAttr = (bool) preg_match("/attribute:\s*['\"]?Deprecated['\"]?/", self::$sContent);
        $this->assertTrue($bHasAttr, 'disallowedAttributes must ban the Deprecated attribute (PHP 8.4-only)');
    }

    public function test_all_four_php84_functions_are_banned(): void
    {
        $arBannedFunctions = ['array_find()', 'array_find_key()', 'array_any()', 'array_all()'];

        foreach ($arBannedFunctions as $sFunction) {
            $bMatch = str_contains(self::$sContent, $sFunction);
            $this->assertTrue($bMatch, "phpstan.neon must ban {$sFunction} in disallowedFunctionCalls");
        }
    }

    public function test_assert_is_banned_in_disallowed_function_calls(): void
    {
        $bMatch = (bool) preg_match("/function:\s*['\"]?assert\(\)['\"]?/", self::$sContent);
        $this->assertTrue($bMatch, 'phpstan.neon must ban assert() in disallowedFunctionCalls');
    }

    public function test_site_manager_banned_with_disallow_in_adapter_queue_event_paths(): void
    {
        $bHasSiteManager = str_contains(self::$sContent, 'SiteManager::');
        $this->assertTrue($bHasSiteManager, 'disallowedMethodCalls must include SiteManager::*');

        $arExpectedPaths = ['classes/queue/', 'classes/event/', 'classes/adapter/'];
        foreach ($arExpectedPaths as $sPath) {
            $bHasPath = str_contains(self::$sContent, $sPath);
            $this->assertTrue($bHasPath, "phpstan.neon disallowIn must cover path: {$sPath}");
        }
    }

    public function test_deprecated_is_not_only_in_disallowed_classes_block(): void
    {
        // The bug was using disallowedClasses with `class: Deprecated` — that is inert for attributes.
        // This test ensures the fix (disallowedAttributes) is the active mechanism.
        // If disallowedClasses still exists for Deprecated, it is a dead rule — warn by checking
        // that if disallowedClasses exists, disallowedAttributes also exists (belt-and-suspenders OK,
        // but bare disallowedClasses-only is the bug).
        $bHasAttributes = str_contains(self::$sContent, 'disallowedAttributes:');
        $this->assertTrue(
            $bHasAttributes,
            'disallowedAttributes: block is required; disallowedClasses: alone cannot reject #[Deprecated]',
        );
    }
}
