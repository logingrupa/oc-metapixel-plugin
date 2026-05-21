<?php

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Symfony\Component\Yaml\Yaml;

/*
 * Note: PHPUnit's classic `extends MetapixelTestCase` model is used here
 * (mirrors tests/Feature/Lang/LangKeyCoverageTest.php) so the gate runs
 * under any Pest invocation shape. Parses plugin.yaml directly via the
 * Symfony Yaml component (transitive October dependency — no new require).
 */

final class PluginYamlSanityTest extends MetapixelTestCase
{
    /**
     * Hermetic plugin.yaml load — parses the YAML file from disk relative
     * to the plugin root (dirname(__DIR__, 3) from tests/Feature/Plugin/).
     *
     * @return array<string, mixed>
     */
    private function loadPluginYaml(): array
    {
        $arParsed = Yaml::parseFile(dirname(__DIR__, 3).'/plugin.yaml');

        return is_array($arParsed) ? $arParsed : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPluginNode(): array
    {
        $arYaml = $this->loadPluginYaml();
        $arPlugin = $arYaml['plugin'] ?? [];

        return is_array($arPlugin) ? $arPlugin : [];
    }

    public function test_plugin_yaml_parses(): void
    {
        $arYaml = $this->loadPluginYaml();
        $this->assertArrayHasKey(
            'plugin',
            $arYaml,
            'plugin.yaml must parse to an array with a top-level `plugin` key.',
        );
    }

    public function test_plugin_name_is_lang_key(): void
    {
        $arPlugin = $this->loadPluginNode();
        $this->assertSame(
            'logingrupa.metapixel::lang.plugin.name',
            $arPlugin['name'] ?? null,
            "plugin.yaml `name` must be the lang key 'logingrupa.metapixel::lang.plugin.name' (MKT-02 — no hardcoded English string).",
        );
    }

    public function test_plugin_description_is_lang_key(): void
    {
        $arPlugin = $this->loadPluginNode();
        $this->assertSame(
            'logingrupa.metapixel::lang.plugin.description',
            $arPlugin['description'] ?? null,
            "plugin.yaml `description` must be the lang key 'logingrupa.metapixel::lang.plugin.description' (MKT-02).",
        );
    }

    public function test_plugin_author_is_logingrupa(): void
    {
        $arPlugin = $this->loadPluginNode();
        $this->assertSame(
            'Logingrupa',
            $arPlugin['author'] ?? null,
            "plugin.yaml `author` must be 'Logingrupa' (MKT-02 — vendor identity).",
        );
    }

    public function test_plugin_icon_is_icon_bullseye(): void
    {
        $arPlugin = $this->loadPluginNode();
        $this->assertSame(
            'icon-bullseye',
            $arPlugin['icon'] ?? null,
            "plugin.yaml `icon` must be 'icon-bullseye' (D-20 lock — generic Meta/tracking glyph).",
        );
    }

    public function test_plugin_homepage_matches_github_vcs_url(): void
    {
        $arPlugin = $this->loadPluginNode();
        $this->assertMatchesRegularExpression(
            '#^https://github\.com/logingrupa/oc-metapixel-plugin$#',
            (string) ($arPlugin['homepage'] ?? ''),
            "plugin.yaml `homepage` must be 'https://github.com/logingrupa/oc-metapixel-plugin' (MKT-02 — VCS source-of-truth URL).",
        );
    }
}
