<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Theme;

use Cms\Classes\ThisVariable;
use Illuminate\Support\Facades\App;
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
        $obThis = new ThisVariable(['controller' => null]);
        $obThis->config['metapixel'] = $obCollector;

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
