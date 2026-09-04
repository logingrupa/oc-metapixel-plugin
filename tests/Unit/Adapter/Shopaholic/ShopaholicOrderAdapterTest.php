<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicOrderAdapter;
use Logingrupa\Metapixel\Classes\Meta\UserDataHasher;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use Lovata\OrdersShopaholic\Models\Order;
use PHPUnit\Framework\Attributes\Group;

/**
 * ShopaholicOrderAdapter::getUserData reads the Lovata shipping address keys
 * (MakeOrder flattens the shipping_address input into the property JSON) into
 * the Meta ct / st / zp / country fields. Pickup orders carry no structured
 * address, so every field must degrade to null, never to an empty hash.
 */
#[Group('adapter')]
final class ShopaholicOrderAdapterTest extends ShopaholicAdapterTestCase
{
    public function test_get_user_data_maps_shipping_address_property_keys(): void
    {
        $obOrder = $this->makeOrder([
            'email' => 'a@b.test',
            'shipping_city' => 'Rīga',
            'shipping_state' => 'Rīga',
            'shipping_postcode' => 'LV-1045',
            'shipping_country' => 'LV',
        ]);

        $arUserData = (new ShopaholicOrderAdapter)->getUserData($obOrder);

        $this->assertSame('Rīga', $arUserData['ct']);
        $this->assertSame('Rīga', $arUserData['st']);
        $this->assertSame('LV-1045', $arUserData['zp']);
        $this->assertSame('LV', $arUserData['country']);
    }

    public function test_get_user_data_address_fields_are_null_for_pickup_orders(): void
    {
        $obOrder = $this->makeOrder([
            'email' => 'a@b.test',
            'shipping_address2' => 'Lielais prospekts 3/5 , VENTSPILS, LV3601, (Paku Skapis Tobago)',
        ]);

        $arUserData = (new ShopaholicOrderAdapter)->getUserData($obOrder);

        $this->assertNull($arUserData['ct']);
        $this->assertNull($arUserData['st']);
        $this->assertNull($arUserData['zp']);
        $this->assertNull($arUserData['country']);
    }

    public function test_hasher_drops_country_name_and_keeps_iso_code(): void
    {
        $obHasher = new UserDataHasher;
        $obAdapter = new ShopaholicOrderAdapter;

        $arNamed = $obHasher->forSubject($obAdapter, $this->makeOrder(['shipping_country' => 'Latvija']));
        $arIso = $obHasher->forSubject($obAdapter, $this->makeOrder(['shipping_country' => 'LV']));

        $this->assertNull($arNamed['country']);
        $this->assertSame(hash('sha256', 'lv'), $arIso['country']);
    }

    /**
     * @param  array<string, string>  $arProperty
     */
    private function makeOrder(array $arProperty): Order
    {
        $obOrder = new Order;
        $obOrder->setAttribute('id', 1);
        $obOrder->setAttribute('secret_key', 'test-secret-abc');
        $obOrder->setAttribute('property', $arProperty);

        return $obOrder;
    }
}
