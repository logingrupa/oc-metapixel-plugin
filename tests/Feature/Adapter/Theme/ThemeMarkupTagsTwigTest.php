<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Theme;

use Cms\Classes\Controller as CmsController;
use Cms\Classes\Theme;
use Cms\Classes\ThisVariable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Logingrupa\Metapixel\Classes\Adapter\Theme\ThemeEventCollector;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * THEM-04 dot-notation hard-contract proof.
 *
 * Renders `{% do this.metapixel.pushEvent(arEvent) %}` against a Twig environment
 * with `this` bound to a Cms\Classes\ThisVariable carrying the ThemeEventCollector
 * in its config slot. This mirrors the Plugin::boot mount which writes
 * `$obController->vars['this']->config['metapixel'] = App::make(ThemeEventCollector::class)`
 * during `page.beforeRenderPage` — `vars['this']` is the ThisVariable October
 * constructed at runPage time; mutating its public `config` array post-construction
 * is the only mount that survives ThisVariable's `__call` fallback (which would
 * otherwise swallow `pushEvent` calls).
 */
#[Group('adapter')]
final class ThemeMarkupTagsTwigTest extends MetapixelTestCase
{
    /** @var bool */
    protected $autoRegister = true;

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

    public function test_this_metapixel_pushevent_resolves_via_controller_extend_mount(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obThis = $this->makeThisVariableWithCollector($obCollector);

        $sRendered = $this->renderTwigString(
            "{% do this.metapixel.pushEvent({'name': 'ViewContent', 'action_key': 'pdp:42', 'content_ids': ['SKU-42'], 'value': 1.0, 'currency': 'EUR'}) %}OK",
            ['this' => $obThis],
        );

        $this->assertSame('OK', $sRendered);
        $this->assertSame(1, $obCollector->count());

        $arFlushed = $obCollector->flush();
        $this->assertSame('ViewContent', $arFlushed[0]['name']);
        $this->assertSame(['SKU-42'], $arFlushed[0]['content_ids']);
    }

    public function test_dot_notation_drops_malformed_event_silently(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obThis = $this->makeThisVariableWithCollector($obCollector);

        $sRendered = $this->renderTwigString(
            "{% do this.metapixel.pushEvent({'value': 12.5}) %}OK",
            ['this' => $obThis],
        );

        $this->assertSame('OK', $sRendered);
        $this->assertSame(0, $obCollector->count());
    }

    public function test_dot_notation_supports_multiple_pushes_in_same_template(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obThis = $this->makeThisVariableWithCollector($obCollector);

        $sTemplate = "{% do this.metapixel.pushEvent({'name': 'PageView'}) %}".
            "{% do this.metapixel.pushEvent({'name': 'ViewContent'}) %}".
            "{% do this.metapixel.pushEvent({'name': 'AddToCart'}) %}OK";

        $sRendered = $this->renderTwigString($sTemplate, ['this' => $obThis]);

        $this->assertSame('OK', $sRendered);
        $this->assertSame(3, $obCollector->count());

        $arFlushed = $obCollector->flush();
        $this->assertSame('PageView', $arFlushed[0]['name']);
        $this->assertSame('ViewContent', $arFlushed[1]['name']);
        $this->assertSame('AddToCart', $arFlushed[2]['name']);
    }

    public function test_plugin_boot_listener_mounts_collector_on_thisvariable_when_event_fires(): void
    {
        $obController = new CmsController(Theme::load('logingrupa-naisstore'));
        $obController->vars['this'] = new ThisVariable(['controller' => $obController]);

        Event::dispatch('cms.page.beforeRenderPage', [$obController, null]);

        $mThis = $obController->vars['this'];
        $this->assertInstanceOf(ThisVariable::class, $mThis);
        $this->assertSame(
            App::make(ThemeEventCollector::class),
            $mThis->config['metapixel'] ?? null,
        );
    }

    public function test_register_markup_tags_exposes_renderDeferredBlocks_function(): void
    {
        $obPlugin = new \Logingrupa\Metapixel\Plugin($this->app);

        $arTags = $obPlugin->registerMarkupTags();

        $this->assertArrayHasKey('renderDeferredBlocks', $arTags['functions']);
        $this->assertIsCallable($arTags['functions']['renderDeferredBlocks']);
        $this->assertSame([], $arTags['filters']);
    }

    public function test_plugin_boot_listener_is_noop_when_thisvariable_missing(): void
    {
        $obController = new CmsController(Theme::load('logingrupa-naisstore'));
        // Intentionally do NOT seed vars['this'] — emulate a controller that
        // somehow fires cms.page.beforeRenderPage before runPage seeds ThisVariable.

        Event::dispatch('cms.page.beforeRenderPage', [$obController, null]);

        $this->assertArrayNotHasKey('this', $obController->vars);
    }

    public function test_collector_is_per_request_singleton_across_two_renders(): void
    {
        $obCollector = App::make(ThemeEventCollector::class);
        $obThis = $this->makeThisVariableWithCollector($obCollector);

        $this->renderTwigString(
            "{% do this.metapixel.pushEvent({'name': 'PageView'}) %}OK",
            ['this' => $obThis],
        );
        $this->renderTwigString(
            "{% do this.metapixel.pushEvent({'name': 'ViewContent'}) %}OK",
            ['this' => $obThis],
        );

        $this->assertSame(2, $obCollector->count());
    }

    /**
     * Build a ThisVariable seeded with the collector under the `metapixel` key.
     * This is the exact post-construction mutation Plugin::boot performs inside
     * the `cms.page.beforeRenderPage` listener — `$controller->vars['this']` is
     * the ThisVariable; the listener writes `$controller->vars['this']->config['metapixel']`.
     */
    private function makeThisVariableWithCollector(ThemeEventCollector $obCollector): ThisVariable
    {
        $obThis = new ThisVariable(['controller' => null]);
        $obThis->config['metapixel'] = $obCollector;

        return $obThis;
    }

    /**
     * Render a Twig string template against a context array using a hermetic
     * ArrayLoader. Mirrors the same Twig env defaults the CMS Controller uses
     * (no extensions needed for attribute access — that is core behaviour).
     *
     * @param  array<string, mixed>  $arContext
     */
    private function renderTwigString(string $sTemplate, array $arContext): string
    {
        $obLoader = new ArrayLoader(['tpl' => $sTemplate]);
        $obTwig = new Environment($obLoader);

        return $obTwig->render('tpl', $arContext);
    }
}
