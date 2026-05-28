<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Theme;

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED STUB — turns GREEN in plan 06-06. Asserts VIEW-07 + VIEW-09:
 * ThemeAjaxHandler::onBeforeRun hybrid path for shopaholic.product subject_type
 * — unknown alias → 422, valid alias routes through registered adapter +
 * dispatches SendCapiEvent, adapter lacking SupportsHybridAjax → 422,
 * non-positive subject_id → 422, loadSubject returning null → 404.
 */
#[Group('adapter')]
final class ThemeAjaxHandlerSubjectTypeTest extends MetapixelTestCase
{
    public function test_unknown_subject_type_alias_returns_422(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 2 — ThemeAjaxHandler::onBeforeRun MUST catch Logingrupa\Metapixel\Classes\Exception\UnknownSubjectTypeException → JsonResponse status 422');
    }

    public function test_valid_alias_routes_through_registered_adapter_and_dispatches_send_capi_event(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 2 — subject_type=shopaholic.product MUST resolve ShopaholicProductAdapter via AdapterRegistry::resolveByAlias and dispatch SendCapiEvent');
    }

    public function test_adapter_lacking_supports_hybrid_ajax_returns_422(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 2 — adapter NOT implementing Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax MUST return 422 (refuse server-load hybrid path)');
    }

    public function test_non_positive_subject_id_returns_422(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 2 — subject_id <= 0 MUST return 422 (Tiger-Style positive-space invariant)');
    }

    public function test_loadSubject_returning_null_returns_404(): void
    {
        $this->fail('GREEN in plan 06-06 — Task 2 — adapter::loadSubject() returning null (inactive / soft-deleted / cross-site) MUST return 404');
    }
}
