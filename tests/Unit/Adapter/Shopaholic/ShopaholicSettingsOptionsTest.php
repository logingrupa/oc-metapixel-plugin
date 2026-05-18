<?php

namespace Logingrupa\Metapixel\Tests\Unit\Adapter\Shopaholic;

use Illuminate\Support\Facades\DB;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicSettingsOptions;
use Logingrupa\Metapixel\Tests\ShopaholicAdapterTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dropdown helper coverage for the Settings YAML option callbacks. Currency
 * options are static; status code options pull from the seeded statuses table.
 */
#[Group('adapter')]
final class ShopaholicSettingsOptionsTest extends ShopaholicAdapterTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootOrdersStatuses();
    }

    public function test_get_paid_status_code_options_returns_status_rows_keyed_by_code(): void
    {
        $arOptions = ShopaholicSettingsOptions::getPaidStatusCodeOptions();

        $this->assertArrayHasKey('new-payment-received', $arOptions);
        $this->assertSame('New payment received', $arOptions['new-payment-received']);
        $this->assertArrayHasKey('new', $arOptions);
    }

    public function test_get_paid_status_code_options_orders_by_sort_order(): void
    {
        DB::table('lovata_orders_shopaholic_statuses')->insertOrIgnore([
            ['id' => 99, 'name' => 'AfterAll', 'code' => 'after-all', 'sort_order' => 99, 'is_user_show' => 1],
        ]);

        $arOptions = ShopaholicSettingsOptions::getPaidStatusCodeOptions();
        $arKeys = array_keys($arOptions);

        $this->assertSame('after-all', end($arKeys));
    }

    public function test_get_default_currency_code_options_returns_static_currency_map(): void
    {
        $arOptions = ShopaholicSettingsOptions::getDefaultCurrencyCodeOptions();

        $this->assertSame(['EUR', 'NOK', 'USD', 'GBP'], array_keys($arOptions));
        $this->assertSame('EUR', $arOptions['EUR']);
    }

}
