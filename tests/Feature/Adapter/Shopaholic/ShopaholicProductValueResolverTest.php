<?php

namespace Logingrupa\Metapixel\Tests\Feature\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\Shopaholic\ShopaholicProductValueResolver;
use Logingrupa\Metapixel\Classes\Exception\OrderHasNoCurrencyException;
use Logingrupa\Metapixel\Models\Settings;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use ReflectionProperty;

/**
 * VIEW-03 — ShopaholicProductValueResolver resolves value (default-offer
 * price_value), currency (CurrencyHelper -> Settings fallback -> throw chain),
 * content_ids (SKU-{pid}[-{oid}] per D-5 + D-10), contents (1-item with
 * quantity 1), num_items (1 constant).
 *
 * CurrencyHelper uses October's Singleton trait — its static $instance field
 * is set via Reflection in each currency-related test, then forgetInstance()
 * resets it in tearDown to keep tests isolated. App::singleton() binding
 * would not work because Singleton::instance() bypasses the container.
 */
#[Group('adapter')]
final class ShopaholicProductValueResolverTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Settings::clearInternalCache();
        Settings::set(['default_currency_code' => 'EUR']);

        // Pre-stub CurrencyHelper singleton so Offer's getPriceValueAttribute
        // accessor (which calls CurrencyHelper::instance()->convert) never
        // triggers init() — which would read lovata_shopaholic_currency that
        // is not seeded in this hermetic test.
        $this->stubCurrencyHelperWithCode('EUR');
    }

    protected function tearDown(): void
    {
        CurrencyHelper::forgetInstance();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: int, 1: list<array{0: int, 1: float, 2: int, 3: bool}>, 2: list<string>}>
     */
    public static function provideOfferShapes(): array
    {
        return [
            'zero offers' => [42, [], ['SKU-42']],
            'single offer' => [42, [[100, 9.99, 0, true]], ['SKU-42']],
            'multi-offer first-active by sort_order' => [
                42,
                [[101, 5.00, 1, true], [100, 9.99, 0, true]],
                ['SKU-42-100'],
            ],
            'multi-offer skipping inactive first' => [
                42,
                [[101, 5.00, 0, false], [100, 9.99, 1, true]],
                ['SKU-42-100'],
            ],
        ];
    }

    /**
     * @param  list<array{0: int, 1: float, 2: int, 3: bool}>  $arOffers
     * @param  list<string>  $arExpected
     */
    #[DataProvider('provideOfferShapes')]
    public function test_resolveContentIds_matches_sku_format_for_offer_shapes(
        int $iProductId,
        array $arOffers,
        array $arExpected,
    ): void {
        $obProduct = $this->makeProduct($iProductId, $arOffers);

        $arActual = (new ShopaholicProductValueResolver)->resolveContentIds($obProduct);

        $this->assertSame($arExpected, $arActual);
    }

    public function test_resolveValue_reads_default_offer_price_value_raw(): void
    {
        $obProduct = $this->makeProduct(42, [[100, 12.50, 0, true]]);
        $obEmpty = $this->makeProduct(42, []);

        $this->assertSame(12.50, (new ShopaholicProductValueResolver)->resolveValue($obProduct));
        $this->assertSame(0.0, (new ShopaholicProductValueResolver)->resolveValue($obEmpty));
    }

    public function test_resolveCurrency_returns_active_currency_code_when_available(): void
    {
        $this->stubCurrencyHelperWithCode('EUR');
        $obProduct = $this->makeProduct(42, [[100, 1.00, 0, true]]);

        $this->assertSame('EUR', (new ShopaholicProductValueResolver)->resolveCurrency($obProduct));
    }

    public function test_resolveCurrency_falls_back_to_settings_default_currency_code(): void
    {
        $this->stubCurrencyHelperWithCode(null);
        Settings::set(['default_currency_code' => 'NOK']);
        $obProduct = $this->makeProduct(42, [[100, 1.00, 0, true]]);

        $this->assertSame('NOK', (new ShopaholicProductValueResolver)->resolveCurrency($obProduct));
    }

    public function test_resolveCurrency_throws_when_both_sources_empty(): void
    {
        $this->stubCurrencyHelperWithCode(null);
        Settings::set(['default_currency_code' => '']);
        $obProduct = $this->makeProduct(42, [[100, 1.00, 0, true]]);

        $this->expectException(OrderHasNoCurrencyException::class);
        (new ShopaholicProductValueResolver)->resolveCurrency($obProduct);
    }

    public function test_resolveContents_returns_one_item_with_quantity_one(): void
    {
        $obProduct = $this->makeProduct(42, [[100, 7.77, 0, true]]);

        $arActual = (new ShopaholicProductValueResolver)->resolveContents($obProduct);

        $this->assertSame([
            ['id' => 'SKU-42', 'quantity' => 1, 'item_price' => 7.77],
        ], $arActual);
    }

    public function test_resolveNumItems_returns_one_constant(): void
    {
        $obSingle = $this->makeProduct(42, [[100, 1.00, 0, true]]);
        $obMulti = $this->makeProduct(42, [
            [100, 1.00, 0, true],
            [101, 2.00, 1, true],
        ]);
        $obEmpty = $this->makeProduct(42, []);

        $obResolver = new ShopaholicProductValueResolver;
        $this->assertSame(1, $obResolver->resolveNumItems($obSingle));
        $this->assertSame(1, $obResolver->resolveNumItems($obMulti));
        $this->assertSame(1, $obResolver->resolveNumItems($obEmpty));
    }

    /**
     * Build a Product with an in-memory Collection of Offer rows. Each offer
     * tuple is [id, price_value, sort_order, active].
     *
     * @param  list<array{0: int, 1: float, 2: int, 3: bool}>  $arOffers
     */
    private function makeProduct(int $iProductId, array $arOffers): Product
    {
        $obProduct = new Product;
        $obProduct->setAttribute('id', $iProductId);
        $obProduct->setAttribute('name', 'Test Product');

        $arOfferModels = [];
        foreach ($arOffers as [$iOfferId, $fPrice, $iSortOrder, $bActive]) {
            $arOfferModels[] = $this->makeOffer($iOfferId, $fPrice, $iSortOrder, $bActive);
        }
        $obProduct->setRelation('offer', new Collection($arOfferModels));

        return $obProduct;
    }

    private function makeOffer(int $iOfferId, float $fPrice, int $iSortOrder, bool $bActive): Offer
    {
        $obOffer = new Offer;
        $obOffer->setAttribute('id', $iOfferId);
        $obOffer->setAttribute('sort_order', $iSortOrder);
        $obOffer->setAttribute('active', $bActive);

        // Lovata's Offer::getPriceValueAttribute reads $fSavedPrice first
        // (protected field) — if set, it skips the lovata_shopaholic_prices
        // DB read that the test does not seed. Inject directly via Reflection
        // so the resolver's floatAttr($obOffer, 'price_value') returns $fPrice.
        $obReflect = new ReflectionProperty(Offer::class, 'fSavedPrice');
        $obReflect->setAccessible(true);
        $obReflect->setValue($obOffer, $fPrice);

        return $obOffer;
    }

    /**
     * Stub CurrencyHelper::instance() so getActiveCurrencyCode() returns the
     * given code (or null). October's Singleton trait declares
     * `final protected __construct()`, so subclassing with a public ctor is
     * impossible — instead, instantiate CurrencyHelper directly via
     * ReflectionClass::newInstanceWithoutConstructor (skips init()'s DB
     * read of lovata_shopaholic_currency) and pin a stdClass with a `code`
     * property into the protected $obActiveCurrency field; CurrencyHelper's
     * getActiveCurrencyCode() returns $this->getActive()->code which will
     * resolve to our stubbed code. For the null-code branch, leave
     * $obActiveCurrency unset (null) — getActiveCurrencyCode returns null.
     * tearDown() calls forgetInstance() to reset between tests.
     */
    private function stubCurrencyHelperWithCode(?string $sCode): void
    {
        $obReflectClass = new ReflectionClass(CurrencyHelper::class);
        $obStub = $obReflectClass->newInstanceWithoutConstructor();

        if ($sCode !== null) {
            $obActiveCurrency = new \stdClass;
            $obActiveCurrency->code = $sCode;

            $obReflectActive = new ReflectionProperty(CurrencyHelper::class, 'obActiveCurrency');
            $obReflectActive->setAccessible(true);
            $obReflectActive->setValue($obStub, $obActiveCurrency);
        }

        $obReflectProp = new ReflectionProperty(CurrencyHelper::class, 'instance');
        $obReflectProp->setAccessible(true);
        $obReflectProp->setValue(null, $obStub);
    }
}
