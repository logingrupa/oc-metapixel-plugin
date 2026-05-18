<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Theme;

use Illuminate\Support\Facades\App;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * THEM-03 unit coverage — push validation, pushEvent alias, flush idempotency,
 * singleton lifecycle.
 */
#[Group('adapter')]
final class ThemeEventCollectorTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ThemeEventCollector::class);
    }

    protected function tearDown(): void
    {
        $this->app->forgetInstance(ThemeEventCollector::class);

        parent::tearDown();
    }

    public function test_push_appends_event_when_name_is_non_empty_string(): void
    {
        $obCollector = new ThemeEventCollector;
        $arEvent = ['name' => 'ViewContent', 'value' => 12.5];

        $obCollector->push($arEvent);

        $this->assertSame([$arEvent], $obCollector->flush());
    }

    public function test_push_drops_event_when_name_missing(): void
    {
        $obCollector = new ThemeEventCollector;

        $obCollector->push(['value' => 12.5]);

        $this->assertSame(0, $obCollector->count());
    }

    public function test_push_drops_event_when_name_is_empty_string(): void
    {
        $obCollector = new ThemeEventCollector;

        $obCollector->push(['name' => '']);

        $this->assertSame(0, $obCollector->count());
    }

    public function test_push_drops_event_when_name_not_string(): void
    {
        $obCollector = new ThemeEventCollector;

        $obCollector->push(['name' => 42]);

        $this->assertSame(0, $obCollector->count());
    }

    public function test_pushevent_alias_calls_push(): void
    {
        $obCollector = new ThemeEventCollector;

        $obCollector->pushEvent(['name' => 'AddToCart']);

        $this->assertSame(1, $obCollector->count());
    }

    public function test_flush_returns_accumulator_and_resets_state(): void
    {
        $obCollector = new ThemeEventCollector;
        $obCollector->push(['name' => 'PageView']);
        $obCollector->push(['name' => 'ViewContent']);
        $obCollector->push(['name' => 'AddToCart']);

        $arFirst = $obCollector->flush();

        $this->assertCount(3, $arFirst);
        $this->assertSame([], $obCollector->flush());
    }

    public function test_flush_on_empty_collector_returns_empty_list_idempotent(): void
    {
        $obCollector = new ThemeEventCollector;

        $this->assertSame([], $obCollector->flush());
        $this->assertSame([], $obCollector->flush());
    }

    public function test_singleton_returns_same_instance_across_app_make_calls(): void
    {
        $obFirst = App::make(ThemeEventCollector::class);
        $obSecond = App::make(ThemeEventCollector::class);

        $this->assertSame($obFirst, $obSecond);
    }

    public function test_singleton_resets_between_tests_via_forget_instance(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obCollector->push(['name' => 'PageView']);

        $this->assertSame(1, $obCollector->count());

        $this->app->forgetInstance(ThemeEventCollector::class);
        $this->app->singleton(ThemeEventCollector::class);
        $obFresh = App::make(ThemeEventCollector::class);

        $this->assertSame(0, $obFresh->count());
    }
}
