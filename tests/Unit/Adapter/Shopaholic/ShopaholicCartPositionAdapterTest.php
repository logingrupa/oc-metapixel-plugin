<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicCartPositionAdapter;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Lovata\OrdersShopaholic\Models\Cart;
use Lovata\OrdersShopaholic\Models\CartPosition;
use October\Rain\Support\Facades\Site;
use PHPUnit\Framework\Attributes\Group;

/**
 * SHOP-03 (carry-forward, Gap 2 closure) — ShopaholicCartPositionAdapter::getSiteId
 * regression coverage. Two branches:
 *   1. Primary: cart.site_id non-null → returned as int (operators with a
 *      custom migration adding the site_id column to lovata_orders_shopaholic_carts).
 *   2. Fallback: cart.site_id null → Site::getSiteIdFromContext() supplies
 *      the active request-context site id (D-15-style exception; Lovata Cart
 *      has no site_id column natively).
 *
 * The fallback path is the second documented P-01 exception alongside
 * ThemeActionAdapter (phpstan.neon allowIn entry per Gap 2).
 */
#[Group('adapter')]
final class ShopaholicCartPositionAdapterTest extends ShopaholicAdapterTestCase
{
    public function test_get_site_id_returns_cart_site_id_when_non_null_primary_source(): void
    {
        $obCart = new Cart;
        $obCart->setAttribute('id', 1);
        $obCart->setAttribute('site_id', 7);

        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setRelation('cart', $obCart);

        // Site facade MUST NOT be touched in the primary branch — the cart
        // attribute is the authoritative source. shouldNotReceive guards that.
        Site::shouldReceive('getSiteIdFromContext')->never();

        $this->assertSame(7, (new ShopaholicCartPositionAdapter)->getSiteId($obPosition));
    }

    public function test_get_site_id_returns_non_null_via_site_get_site_id_from_context_fallback(): void
    {
        $obCart = new Cart;
        $obCart->setAttribute('id', 1);
        $obCart->setAttribute('site_id', null);

        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setRelation('cart', $obCart);

        Site::shouldReceive('getSiteIdFromContext')->andReturn(3);

        $this->assertSame(3, (new ShopaholicCartPositionAdapter)->getSiteId($obPosition));
    }

    public function test_get_site_id_returns_null_when_cart_null_and_context_null(): void
    {
        // Edge case: both sources null (CLI / queue rehydrate) → ?int contract
        // returns null rather than 0. Documents that Site::getSiteIdFromContext
        // returning null does not get silently coerced.
        $obCart = new Cart;
        $obCart->setAttribute('id', 1);
        $obCart->setAttribute('site_id', null);

        $obPosition = new CartPosition;
        $obPosition->setAttribute('id', 1);
        $obPosition->setRelation('cart', $obCart);

        Site::shouldReceive('getSiteIdFromContext')->andReturn(null);

        $this->assertNull((new ShopaholicCartPositionAdapter)->getSiteId($obPosition));
    }
}
