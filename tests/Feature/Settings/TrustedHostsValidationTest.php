<?php

use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Database\ModelException;

/**
 * Wave 0 RED — fails until plan 04-02 production code ships.
 *
 * HOST-01 + D-14 strict-halt validation. Settings::beforeSave partitions
 * operator-supplied trusted_hosts via the PSL-wrapped HostIndexResolver:
 * unknown-TLD lines or charset violations halt the save via ModelException;
 * valid input persists normalised (lowercased + trimmed + deduped).
 */
final class TrustedHostsValidationTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);

        // The Settings::beforeSave partitionHosts helper resolves the
        // HostIndexResolver via App::make — register the singleton against
        // the hermetic PSL fixture so the test runs without the bundled
        // 280 KB PSL data file.
        $this->app->singleton(
            HostIndexResolver::class,
            fn () => new HostIndexResolver(__DIR__.'/../../fixtures/data/test_psl.dat')
        );

        Settings::clearInternalCache();
    }

    public function test_save_with_only_valid_hosts_persists_normalized_lowercase_string(): void
    {
        $obSettings = Settings::instance();
        $obSettings->setAttribute('trusted_hosts', "  Example.Co.UK\nWWW.example.co.uk  \n");
        $obSettings->save();

        $obFresh = Settings::instance();
        $this->assertSame(
            "example.co.uk\nwww.example.co.uk",
            $obFresh->getAttribute('trusted_hosts')
        );
    }

    public function test_save_rejects_unknown_tld_line(): void
    {
        $obSettings = Settings::instance();
        $obSettings->setAttribute('trusted_hosts', "example.co.uk\nbogus.fakeylock\n");

        $this->expectException(ModelException::class);
        $this->expectExceptionMessageMatches('/bogus\.fakeylock/');

        $obSettings->save();
    }

    public function test_save_rejects_charset_violations(): void
    {
        $obSettings = Settings::instance();
        $obSettings->setAttribute('trusted_hosts', "example.com/path\n");

        $this->expectException(ModelException::class);

        $obSettings->save();
    }

    public function test_save_with_empty_input_is_noop(): void
    {
        $obSettings = Settings::instance();
        $obSettings->setAttribute('trusted_hosts', '');
        $obSettings->save();

        $this->assertSame('', (string) Settings::instance()->getAttribute('trusted_hosts'));
    }

    public function test_save_skips_blank_lines_and_persists_non_empty_lines(): void
    {
        $obSettings = Settings::instance();
        $obSettings->setAttribute(
            'trusted_hosts',
            "\n\nexample.com\n  \nwww.example.com\n\n"
        );
        $obSettings->save();

        $this->assertSame(
            "example.com\nwww.example.com",
            (string) Settings::instance()->getAttribute('trusted_hosts'),
            'Blank / whitespace-only lines must be dropped during the '
                .'partition pass; only non-empty trimmed hosts persist.'
        );
    }

    public function test_save_preserves_existing_theme_custom_event_names_pipeline(): void
    {
        $obSettings = Settings::instance();
        $obSettings->setAttribute('trusted_hosts', "example.com\n");
        $obSettings->setAttribute('theme_custom_event_names', "FormSubmit\nBADNAME!!\n");
        $obSettings->save();

        $obFresh = Settings::instance();
        // Theme custom event names pipeline must still drop invalids
        // (BADNAME!! fails the alphanum-underscore regex) — regression guard
        // for the parallel partition pipelines living in beforeSave.
        $this->assertSame('FormSubmit', $obFresh->getAttribute('theme_custom_event_names'));
        $this->assertSame('example.com', $obFresh->getAttribute('trusted_hosts'));
    }
}
