<?php

namespace Logingrupa\Metapixel\Tests\Feature\Components;

use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * The base pixel partial must switch off fbevents.js's own PageView on
 * history.pushState / replaceState before the pixel initialises: that
 * PageView carries no event id, so it can never pair with a server event.
 */
final class PixelHeadPartialTest extends MetapixelTestCase
{
    public function test_partial_disables_push_state_page_view_before_init(): void
    {
        $sPartial = (string) file_get_contents(__DIR__.'/../../../components/pixelhead/default.htm');

        $iFlag = strpos($sPartial, 'fbq.disablePushState = true;');
        $iInit = strpos($sPartial, "fbq('init'");

        $this->assertNotFalse($iFlag, 'partial must set fbq.disablePushState');
        $this->assertNotFalse($iInit);
        $this->assertLessThan($iInit, $iFlag, 'the flag must be set before fbq(init)');
    }
}
