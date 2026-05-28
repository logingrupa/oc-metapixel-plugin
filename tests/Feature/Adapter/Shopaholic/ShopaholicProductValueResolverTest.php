<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic;

use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * RED STUB — turns GREEN in plan 06-04. Asserts VIEW-03:
 * ShopaholicProductValueResolver resolves value (default-offer price_value),
 * currency (CurrencyHelper → Settings fallback → throw chain),
 * content_ids (SKU-{pid}[-{oid}] per offer count per D-5 + D-10),
 * contents (1-item with quantity 1), num_items (1 constant).
 */
#[Group('adapter')]
final class ShopaholicProductValueResolverTest extends MetapixelTestCase
{
    /**
     * Fixture matrix for content_ids shape per offer count.
     * Shape rows: zero-offer, single-offer, multi-offer (first active by sort_order),
     * multi-offer (offer NOT first by sort_order — must still return first active).
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function provideOfferShapes(): array
    {
        return [
            'zero-offer'                       => ['fixture:zero', ['SKU-42']],
            'single-offer'                     => ['fixture:single', ['SKU-42']],
            'multi-offer first active'         => ['fixture:multi-first', ['SKU-42-100']],
            'multi-offer not first by order'   => ['fixture:multi-not-first', ['SKU-42-200']],
        ];
    }

    #[DataProvider('provideOfferShapes')]
    public function test_resolveContentIds_matches_sku_format_for_offer_shapes(string $sFixtureKey, array $arExpected): void
    {
        $this->fail("GREEN in plan 06-04 — Task 2 — Logingrupa\\Metapixel\\Classes\\Adapter\\Shopaholic\\ShopaholicProductValueResolver::resolveContentIds fixture={$sFixtureKey} expected=" . json_encode($arExpected));
    }

    public function test_resolveValue_reads_default_offer_price_value_raw(): void
    {
        $this->fail('GREEN in plan 06-04 — Task 2 — ShopaholicProductValueResolver::resolveValue MUST read default-offer price_value (raw float, no rounding)');
    }

    public function test_resolveCurrency_returns_active_currency_code_when_available(): void
    {
        $this->fail('GREEN in plan 06-04 — Task 2 — ShopaholicProductValueResolver::resolveCurrency MUST return Lovata\Shopaholic\Classes\Helper\CurrencyHelper::instance()->getActiveCurrencyCode() when set');
    }

    public function test_resolveCurrency_falls_back_to_settings_default_currency_code(): void
    {
        $this->fail('GREEN in plan 06-04 — Task 2 — ShopaholicProductValueResolver::resolveCurrency MUST fall back to Settings::get(default_currency_code) when CurrencyHelper empty');
    }

    public function test_resolveCurrency_throws_when_both_sources_empty(): void
    {
        $this->fail('GREEN in plan 06-04 — Task 2 — ShopaholicProductValueResolver::resolveCurrency MUST throw fail-fast when both CurrencyHelper AND Settings empty');
    }

    public function test_resolveContents_returns_one_item_with_quantity_one(): void
    {
        $this->fail('GREEN in plan 06-04 — Task 2 — ShopaholicProductValueResolver::resolveContents MUST return [{id, quantity:1, item_price}] single-item shape for PDP view');
    }

    public function test_resolveNumItems_returns_one_constant(): void
    {
        $this->fail('GREEN in plan 06-04 — Task 2 — ShopaholicProductValueResolver::resolveNumItems MUST return 1 constant (PDP view = 1 item viewed)');
    }
}
