<?php

namespace Logingrupa\Metapixelshopaholic\Tests\Feature;

require_once __DIR__.'/../MetapixelTestCase.php';

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Logingrupa\Metapixelshopaholic\Classes\Helper\PluginGuard;
use Logingrupa\Metapixelshopaholic\Components\PixelHead;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use Logingrupa\Metapixelshopaholic\Tests\MetapixelTestCase;

/**
 * Feature test locking the 7 SKEL-04 onRun behaviors + componentDetails
 * shape + the sMetaPixelId-to-PluginGuard::getPixelId() binding.
 *
 *   1. componentDetails() returns lang-keyed name + description.
 *   2. onRun() does NOT set page vars when PluginGuard reports disabled.
 *   3. onRun() populates exactly the 4 arMetaEvent keys when enabled.
 *   4. arMetaEvent.event_id matches UUID v4 canonical regex.
 *   5. arMetaEvent.event_time is an integer within ±2 seconds of time().
 *   6. arMetaEvent.event_name is the hardcoded literal 'PageView'.
 *   7. arMetaEvent.custom_data is the empty array [].
 *   8. sMetaPixelId === PluginGuard::instance()->getPixelId().
 *
 * Uses a stub CmsObject implementing ArrayAccess (mirrors the
 * Cms\Classes\CodeBase ArrayAccess contract — see
 * modules/cms/classes/CodeBase.php:76-103) so we can capture the page
 * vars that PixelHead::onRun() sets via `$this->page['key'] = $value`.
 * Avoids booting the full October page lifecycle for a unit-of-behavior
 * feature test.
 *
 * PluginGuard state is primed via reflection (`primePluginGuardEnabled`
 * + `primePluginGuardDisabled`) to sidestep HR-02 — the hermetic SQLite
 * harness's Multisite + Cache::remember interaction makes the
 * `Settings::set() → Settings::clearInternalCache() → Settings::get()`
 * round-trip flaky across multiple tests in the same class
 * (documented in SettingsRegistrationTest::test_pixel_id_round_trips_
 * through_settings PHPDoc lines 78-87). The reflection priming is
 * test-double for the upstream Settings read only — PluginGuard's
 * own `isDisabled()`/`getPixelId()` methods still execute the real
 * production code paths against the primed state.
 *
 * Extends MetapixelTestCase directly per the proven `extends` model in
 * BootsWithoutPixelIdTest + EnsureFbpFbcCookiesTest (Pest's
 * `uses()->in()` binding is currently flaky — see tests/Pest.php).
 */
final class PixelHeadTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Settings::clearInternalCache();
        PluginGuard::flush();
        Cache::flush();
    }

    public function test_componentDetails_returns_lang_keys(): void
    {
        $obComponent = new PixelHead;

        $arDetails = $obComponent->componentDetails();

        $this->assertArrayHasKey('name', $arDetails);
        $this->assertArrayHasKey('description', $arDetails);
        $this->assertSame(
            'logingrupa.metapixelshopaholic::lang.component.name',
            $arDetails['name'],
            'componentDetails().name must be the lang key for RainLab.Translate.'
        );
        $this->assertSame(
            'logingrupa.metapixelshopaholic::lang.component.description',
            $arDetails['description'],
            'componentDetails().description must be the lang key for RainLab.Translate.'
        );
    }

    public function test_onRun_does_not_set_page_vars_when_disabled(): void
    {
        $this->primePluginGuardDisabled();

        $arPageVars = $this->invokeOnRunAndCapturePageVars();

        $this->assertArrayNotHasKey(
            'arMetaEvent',
            $arPageVars,
            'arMetaEvent MUST NOT be set when PluginGuard reports disabled.'
        );
        $this->assertArrayNotHasKey(
            'sMetaPixelId',
            $arPageVars,
            'sMetaPixelId MUST NOT be set when PluginGuard reports disabled.'
        );
    }

    public function test_onRun_populates_four_keys_when_enabled(): void
    {
        $this->primePluginGuardEnabled('2291486191076331');

        $arPageVars = $this->invokeOnRunAndCapturePageVars();

        $this->assertArrayHasKey('arMetaEvent', $arPageVars);
        $this->assertIsArray($arPageVars['arMetaEvent']);
        $this->assertEqualsCanonicalizing(
            ['event_id', 'event_time', 'event_name', 'custom_data'],
            array_keys($arPageVars['arMetaEvent']),
            'arMetaEvent MUST contain exactly the 4 keys event_id, event_time, event_name, custom_data.'
        );
    }

    public function test_event_id_matches_uuid_v4_canonical_regex(): void
    {
        $this->primePluginGuardEnabled('2291486191076331');

        $arPageVars = $this->invokeOnRunAndCapturePageVars();

        $sEventId = $arPageVars['arMetaEvent']['event_id'];

        $this->assertIsString($sEventId);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $sEventId,
            'event_id MUST be a canonical UUID v4 string.'
        );
    }

    public function test_event_time_within_2_seconds_of_time_now(): void
    {
        $this->primePluginGuardEnabled('2291486191076331');

        $iNowBefore = time();
        $arPageVars = $this->invokeOnRunAndCapturePageVars();
        $iEventTime = $arPageVars['arMetaEvent']['event_time'];

        $this->assertIsInt($iEventTime);
        $this->assertEqualsWithDelta(
            $iNowBefore,
            $iEventTime,
            2.0,
            'event_time MUST be a Unix timestamp within ±2 seconds of time() at onRun() entry.'
        );
    }

    public function test_event_name_is_hardcoded_PageView(): void
    {
        $this->primePluginGuardEnabled('2291486191076331');

        $arPageVars = $this->invokeOnRunAndCapturePageVars();

        $this->assertSame(
            'PageView',
            $arPageVars['arMetaEvent']['event_name'],
            'event_name MUST be the hardcoded literal PageView in Phase 2 (Phase 4 FUN-01 will accept overrides).'
        );
    }

    public function test_custom_data_is_empty_array(): void
    {
        $this->primePluginGuardEnabled('2291486191076331');

        $arPageVars = $this->invokeOnRunAndCapturePageVars();

        $this->assertSame(
            [],
            $arPageVars['arMetaEvent']['custom_data'],
            'custom_data MUST be the empty array [] in Phase 2 — Phase 4 will populate with allowlisted keys.'
        );
    }

    public function test_sMetaPixelId_equals_PluginGuard_getPixelId(): void
    {
        $this->primePluginGuardEnabled('2291486191076331');

        $arPageVars = $this->invokeOnRunAndCapturePageVars();

        $this->assertSame(
            '2291486191076331',
            $arPageVars['sMetaPixelId'],
            'sMetaPixelId MUST equal the configured Settings value.'
        );
        $this->assertSame(
            PluginGuard::instance()->getPixelId(),
            $arPageVars['sMetaPixelId'],
            'sMetaPixelId MUST be sourced from PluginGuard::getPixelId() (CONTEXT Area 2 Q2 lock).'
        );
    }

    /**
     * Prime PluginGuard into the enabled state with a given pixel_id via
     * reflection. Bypasses the fragile Settings::set → Settings::get round-trip
     * in the hermetic SQLite harness (HR-02). The resulting PluginGuard
     * instance executes the real isDisabled()/getPixelId() production code
     * paths against the primed state.
     */
    private function primePluginGuardEnabled(string $sPixelId): void
    {
        PluginGuard::flush();
        $obGuard = PluginGuard::instance();
        $obReflect = new \ReflectionClass($obGuard);
        $obIsDisabled = $obReflect->getProperty('bIsDisabled');
        $obIsDisabled->setAccessible(true);
        $obIsDisabled->setValue($obGuard, false);
        $obPixelId = $obReflect->getProperty('sPixelId');
        $obPixelId->setAccessible(true);
        $obPixelId->setValue($obGuard, $sPixelId);

        // Re-bind the container singleton bridge so any downstream
        // `App::make('metapixel.disabled')` resolves to the primed state.
        App::singleton('metapixel.disabled', fn (): bool => false);
    }

    /**
     * Prime PluginGuard into the disabled state via reflection. Mirrors the
     * empty-pixel_id Settings path without depending on the Settings model.
     */
    private function primePluginGuardDisabled(): void
    {
        PluginGuard::flush();
        $obGuard = PluginGuard::instance();
        $obReflect = new \ReflectionClass($obGuard);
        $obIsDisabled = $obReflect->getProperty('bIsDisabled');
        $obIsDisabled->setAccessible(true);
        $obIsDisabled->setValue($obGuard, true);
        $obPixelId = $obReflect->getProperty('sPixelId');
        $obPixelId->setAccessible(true);
        $obPixelId->setValue($obGuard, null);

        App::singleton('metapixel.disabled', fn (): bool => true);
    }

    /**
     * Instantiate PixelHead with a stub CmsObject implementing ArrayAccess and
     * capture the page vars that onRun() writes via `$this->page['key'] = ...`.
     *
     * Stub mirrors Cms\Classes\CodeBase's ArrayAccess contract
     * (modules/cms/classes/CodeBase.php:76-103). We assign $controller = null
     * because PixelHead::onRun() never touches $this->controller — Phase 4
     * FUN-01 may extend this stub when CAPI dispatch needs request context.
     *
     * @return array<string, mixed>
     */
    private function invokeOnRunAndCapturePageVars(): array
    {
        $obStub = new class implements \ArrayAccess
        {
            /** @var array<string, mixed> */
            public array $vars = [];

            /** @var null */
            public $controller = null;

            #[\Override]
            public function offsetSet($offset, $value): void
            {
                $this->vars[(string) $offset] = $value;
            }

            #[\Override]
            public function offsetGet($offset): mixed
            {
                return $this->vars[(string) $offset] ?? null;
            }

            #[\Override]
            public function offsetExists($offset): bool
            {
                return array_key_exists((string) $offset, $this->vars);
            }

            #[\Override]
            public function offsetUnset($offset): void
            {
                unset($this->vars[(string) $offset]);
            }
        };

        $obComponent = new PixelHead($obStub);
        $obComponent->onRun();

        return $obStub->vars;
    }
}
