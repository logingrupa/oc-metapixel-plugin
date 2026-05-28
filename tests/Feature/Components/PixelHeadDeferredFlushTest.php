<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED STUB — turns GREEN in plan 06-02. Asserts VIEW-01: PixelHead flushes
 * ThemeEventCollector at cms.page.beforeRenderPage (NOT onRun), base
 * PageView stays in onRun, action_key shape unchanged, test_event_code
 * flows to fbq script block.
 */
#[Group('adapter')]
final class PixelHeadDeferredFlushTest extends MetapixelTestCase
{
    public function test_emit_collected_events_flushes_on_cms_page_beforeRenderPage_not_onRun(): void
    {
        $this->fail('GREEN in plan 06-02 — Task 3 — Logingrupa\Metapixel\Components\PixelHead::flushDeferredFromController not yet shipped');
    }

    public function test_collector_push_between_onRun_and_beforeRenderPage_is_flushed(): void
    {
        $this->fail('GREEN in plan 06-02 — Task 3 — ThemeEventCollector push between onRun and beforeRenderPage requires Plugin.php cms.page.beforeRenderPage listener registration');
    }

    public function test_base_pageview_action_key_shape_unchanged(): void
    {
        $this->fail('GREEN in plan 06-02 — Task 3 — base:pageview:{site_id}:{event_id} action_key shape must remain after deferred-flush refactor');
    }

    public function test_test_event_code_flows_to_fbq_script_block(): void
    {
        $this->fail('GREEN in plan 06-02 — Task 2 — Logingrupa\Metapixel\Components\PixelHead::renderDeferredBlocks must propagate test_event_code into emitted fbq script');
    }
}
