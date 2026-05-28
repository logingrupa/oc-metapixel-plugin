<?php

namespace Logingrupa\Metapixel\Tests\Contract\Adapter\Shopaholic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\Metapixel\Classes\Adapter\EventSubjectAdapter;
use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductAdapter;
use Logingrupa\Metapixel\Classes\Testing\EventSubjectAdapterContractTestCase;
use Logingrupa\Metapixel\Models\Settings;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Models\Product;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionProperty;

/**
 * VIEW-02 contract proof — ShopaholicProductAdapter satisfies all 10
 * invariants of EventSubjectAdapterContractTestCase. Hermetic Product +
 * Offer + Price + product-site-relation tables are provisioned inline
 * (the abstract base extends MetapixelTestCase, not
 * ShopaholicAdapterTestCase, so the Lovata.Shopaholic schema must be
 * created here).
 */
#[Group('adapter')]
final class ShopaholicProductAdapterContractTest extends EventSubjectAdapterContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootProductsTable();
        $this->bootOffersTable();
        $this->bootPricesTable();
        $this->bootProductSiteRelationTable();
        $this->bootSystemSiteDefinitionsTable();
        Settings::clearInternalCache();
        Settings::set(['default_currency_code' => 'EUR']);

        // Pre-stub CurrencyHelper singleton. Invariant 10 (PayloadBuilder)
        // drives ShopaholicProductValueResolver::resolveCurrency which calls
        // CurrencyHelper::instance() — its init() reads
        // lovata_shopaholic_currency AND CookieUserStorage (auth.helper
        // container binding not provided by MetapixelTestCase). Stub the
        // singleton via Reflection so resolveCurrency returns 'EUR' without
        // touching the DB or container.
        $this->stubCurrencyHelperWithCode('EUR');
    }

    protected function tearDown(): void
    {
        CurrencyHelper::forgetInstance();
        Schema::dropIfExists('system_site_definitions');
        Schema::dropIfExists('lovata_shopaholic_product_site_relation');
        Schema::dropIfExists('lovata_shopaholic_prices');
        Schema::dropIfExists('lovata_shopaholic_offers');
        Schema::dropIfExists('lovata_shopaholic_products');
        parent::tearDown();
    }

    protected function makeAdapter(): EventSubjectAdapter
    {
        return new ShopaholicProductAdapter;
    }

    protected function makeSubject(): object
    {
        DB::table('lovata_shopaholic_products')->insertOrIgnore([
            [
                'id' => 1,
                'name' => 'Test Product',
                'slug' => 'test-product',
                'active' => 1,
                'sort_order' => 0,
            ],
        ]);
        DB::table('lovata_shopaholic_product_site_relation')->insertOrIgnore([
            ['product_id' => 1, 'site_id' => 1],
        ]);

        $obProduct = Product::find(1);
        if ($obProduct === null) {
            $this->fail('Failed to load Product fixture');
        }

        return $obProduct;
    }

    private function bootProductsTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_products')) {
            return;
        }
        Schema::create('lovata_shopaholic_products', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('slug')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
            $obTable->softDeletes();
        });
    }

    private function bootOffersTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_offers')) {
            return;
        }
        Schema::create('lovata_shopaholic_offers', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('product_id')->nullable();
            $obTable->string('name')->nullable();
            $obTable->decimal('price_value', 18, 4)->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('sort_order')->default(0);
            $obTable->softDeletes();
            $obTable->timestamps();
        });
    }

    private function bootPricesTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_prices')) {
            return;
        }
        Schema::create('lovata_shopaholic_prices', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('item_id')->nullable();
            $obTable->string('item_type')->nullable();
            $obTable->integer('price_type_id')->nullable();
            $obTable->decimal('price_value', 18, 4)->nullable();
        });
    }

    private function bootProductSiteRelationTable(): void
    {
        if (Schema::hasTable('lovata_shopaholic_product_site_relation')) {
            return;
        }
        Schema::create('lovata_shopaholic_product_site_relation', function ($obTable): void {
            $obTable->integer('product_id');
            $obTable->integer('site_id');
        });
    }

    /**
     * Product::site() belongsToMany joins system_site_definitions through
     * lovata_shopaholic_product_site_relation; the MultisiteHelperTrait
     * accessor at Product::getSiteListAttribute() pluck()s ids off that
     * relation. Provision the system table so $obProduct->site_list resolves
     * deterministically to [1] for invariants 03/04.
     */
    private function bootSystemSiteDefinitionsTable(): void
    {
        if (Schema::hasTable('system_site_definitions')) {
            return;
        }
        Schema::create('system_site_definitions', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('name')->nullable();
            $obTable->string('code')->nullable();
            $obTable->boolean('is_enabled')->default(true);
            $obTable->boolean('is_primary')->default(false);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
        });
        DB::table('system_site_definitions')->insertOrIgnore([
            ['id' => 1, 'name' => 'Test Site', 'code' => 'test', 'is_enabled' => 1, 'is_primary' => 1, 'sort_order' => 1],
        ]);
    }

    /**
     * Stub CurrencyHelper::instance() so getActiveCurrencyCode() returns the
     * given code. Singleton trait declares `final protected __construct` —
     * instantiate via newInstanceWithoutConstructor (skips init() DB read
     * + auth.helper binding) and pin a stdClass with `code` into
     * $obActiveCurrency. tearDown's forgetInstance() resets the pin.
     */
    private function stubCurrencyHelperWithCode(string $sCode): void
    {
        $obReflectClass = new ReflectionClass(CurrencyHelper::class);
        $obStub = $obReflectClass->newInstanceWithoutConstructor();

        $obActiveCurrency = new \stdClass;
        $obActiveCurrency->code = $sCode;
        $obReflectActive = new ReflectionProperty(CurrencyHelper::class, 'obActiveCurrency');
        $obReflectActive->setAccessible(true);
        $obReflectActive->setValue($obStub, $obActiveCurrency);

        $obReflectProp = new ReflectionProperty(CurrencyHelper::class, 'instance');
        $obReflectProp->setAccessible(true);
        $obReflectProp->setValue(null, $obStub);
    }
}
