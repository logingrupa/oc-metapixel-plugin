<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic;

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED STUB — turns GREEN in plan 06-05. Asserts VIEW-04 + VIEW-10:
 * ProductPageWatcher subscribes shopaholic.product.open, dispatches
 * SendCapiEvent, pushes ThemeEventCollector, resolves SKU shape per offer
 * count, copies event_id into both channels, populates user_data,
 * propagates test_event_code, allows per-pageload re-fires (EventLog
 * race-fence is per-channel only), and re-fires on offer-switch AJAX with
 * a new event_id.
 */
#[Group('adapter')]
final class ProductPageWatcherTest extends MetapixelTestCase
{
    public function test_viewcontent_dispatches_capi_and_pushes_collector_on_shopaholic_product_open(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — Logingrupa\Metapixel\Classes\Event\Adapter\Shopaholic\ProductPageWatcher not yet shipped');
    }

    public function test_does_not_fire_when_plugin_guard_disabled(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — PluginGuard::isDisabled() short-circuit must skip dispatch + collector push');
    }

    public function test_does_not_subscribe_when_lovata_shopaholic_absent(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 2 — Plugin::boot() conditional registration gated on PluginManager::exists(Lovata.OrdersShopaholic)');
    }

    public function test_zero_offer_product_resolves_bare_sku_pid(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — zero-offer product MUST resolve content_ids ["SKU-{pid}"] via ShopaholicProductValueResolver');
    }

    public function test_multi_offer_product_resolves_sku_pid_oid_first_active_by_sort_order(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — multi-offer product MUST resolve content_ids ["SKU-{pid}-{oid}"] for first active offer by sort_order');
    }

    public function test_single_offer_product_resolves_bare_sku_pid(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — single-offer product MUST resolve content_ids ["SKU-{pid}"] per D-5 + D-10');
    }

    public function test_capi_payload_event_id_matches_collector_pushed_event_id(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — server-generated event_id MUST flow into both SendCapiEvent payload AND ThemeEventCollector entry');
    }

    public function test_user_data_populated_from_server_and_cookies(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — CapturesRequestUserData trait MUST populate ip + ua + _fbp + _fbc into payload user_data');
    }

    public function test_test_event_code_appears_in_capi_payload_and_collector_event(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — Settings test_event_code MUST propagate into both CAPI payload + collector entry for Meta Test Events tooling');
    }

    public function test_event_log_race_fence_does_not_block_per_pageload_duplicates(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — EventLog UNIQUE race-fence is (subject_type, subject_id, event_name, channel, site_id); per-pageload ViewContent re-fires MUST succeed (different event_id, NULL-distinct)');
    }

    public function test_offer_switch_ajax_re_fires_viewcontent_with_new_event_id_and_offer_sku(): void
    {
        $this->fail('GREEN in plan 06-05 — Task 1 — ProductPageWatcher::dispatchForOfferSwitch entry point exercised by ThemeAjaxHandler hybrid path; emits new event_id + offer-shaped SKU');
    }
}
