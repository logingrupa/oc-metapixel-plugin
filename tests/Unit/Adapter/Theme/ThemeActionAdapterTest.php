<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Theme;

use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeActionEvent;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use October\Rain\Support\Facades\Site;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/**
 * THEM-02 unit coverage — alias, subjectId, D-15 site_id fallback chain,
 * supported events const completeness.
 */
#[Group('adapter')]
final class ThemeActionAdapterTest extends MetapixelTestCase
{
    public function test_get_subject_type_returns_theme_action_alias(): void
    {
        $obAdapter = new ThemeActionAdapter;
        $obEvent = ThemeActionEvent::fromArray(['name' => 'X', 'action_key' => 'y']);

        $this->assertSame('theme.action', $obAdapter->getSubjectType($obEvent));
    }

    public function test_get_subject_id_returns_synthetic_id_when_subject_is_theme_action_event(): void
    {
        $obAdapter = new ThemeActionAdapter;
        $obEvent = ThemeActionEvent::fromArray(['name' => 'ViewContent', 'action_key' => 'product-view:42']);

        $this->assertSame($obEvent->iSyntheticId, $obAdapter->getSubjectId($obEvent));
    }

    public function test_get_subject_id_returns_zero_for_non_event_subject(): void
    {
        $obAdapter = new ThemeActionAdapter;

        $this->assertSame(0, $obAdapter->getSubjectId(new stdClass));
    }

    public function test_get_site_id_reads_payload_site_id_first_when_int(): void
    {
        $obAdapter = new ThemeActionAdapter;
        $obEvent = ThemeActionEvent::fromArray([
            'name' => 'PageView',
            'action_key' => 'pv:1',
            'site_id' => 7,
        ]);

        $this->assertSame(7, $obAdapter->getSiteId($obEvent));
    }

    public function test_get_site_id_reads_payload_site_id_when_numeric_string(): void
    {
        $obAdapter = new ThemeActionAdapter;
        $obEvent = ThemeActionEvent::fromArray([
            'name' => 'PageView',
            'action_key' => 'pv:2',
            'site_id' => '7',
        ]);

        $this->assertSame(7, $obAdapter->getSiteId($obEvent));
    }

    public function test_get_site_id_falls_back_to_site_context_when_payload_missing(): void
    {
        Site::shouldReceive('getSiteIdFromContext')->andReturn(3);

        $obAdapter = new ThemeActionAdapter;
        $obEvent = ThemeActionEvent::fromArray(['name' => 'PageView', 'action_key' => 'pv:3']);

        $this->assertSame(3, $obAdapter->getSiteId($obEvent));
    }

    public function test_get_site_id_returns_null_in_cli_when_site_context_returns_null(): void
    {
        Site::shouldReceive('getSiteIdFromContext')->andReturn(null);

        $obAdapter = new ThemeActionAdapter;
        $obEvent = ThemeActionEvent::fromArray(['name' => 'PageView', 'action_key' => 'pv:4']);

        $this->assertNull($obAdapter->getSiteId($obEvent));
    }

    public function test_get_supported_events_contains_all_18_meta_standard_names(): void
    {
        $obAdapter = new ThemeActionAdapter;
        $arSupported = $obAdapter->getSupportedEvents();

        $this->assertCount(18, $arSupported);
        $this->assertArrayHasKey('Purchase', $arSupported);
        $this->assertArrayHasKey('PageView', $arSupported);
        $this->assertArrayHasKey('ViewContent', $arSupported);
        $this->assertArrayHasKey('AddToCart', $arSupported);
        $this->assertSame(['capi', 'pixel'], $arSupported['Purchase']);
    }
}
