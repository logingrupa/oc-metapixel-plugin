<?php

namespace Logingrupa\Metapixel\Classes\Adapter\Shopaholic;

use Logingrupa\Metapixel\Classes\Adapter\SupportsHybridAjax;
use Logingrupa\Metapixel\Classes\Adapter\ValueResolver;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Support\Facades\Site;

/**
 * EventSubjectAdapter + SupportsHybridAjax for Lovata\Shopaholic\Models\Product.
 * Alias 'shopaholic.product'. Supports ViewContent on capi+pixel channels.
 *
 * site_id source: prefers $obProduct->site_list (MultisiteHelperTrait pivot
 * accessor reading lovata_shopaholic_product_site_relation) when single-site;
 * falls back to October's Site::getSiteIdFromContext() as the THIRD documented
 * P-01 exception (alongside ThemeActionAdapter + ShopaholicCartPositionAdapter;
 * CONTEXT.md D-15). Lovata Shopaholic ships Product without a site_id column —
 * the per-site relation is the only canonical source; without the fallback
 * MySQL UNIQUE index dedup is broken on multi-site rows (NULL != NULL).
 * phpstan disallowIn excludes this file from the Site/SiteManager/Request ban.
 *
 * loadSubject re-enforces Product::active()->find guards plus a site-match
 * check against $obProduct->site_list — T-6-05 mitigation prevents cross-site
 * subject_id spoofing through the hybrid AJAX path. Product mounts the
 * SoftDelete trait (verified Pitfall 6) and Product::active() scope excludes
 * both inactive AND trashed rows in a single query.
 */
final class ShopaholicProductAdapter implements SupportsHybridAjax
{
    private const SUBJECT_TYPE = 'shopaholic.product';

    /** @var array<string, list<string>> */
    private const SUPPORTED_EVENTS = ['ViewContent' => ['capi', 'pixel']];

    public function getSubjectType(object $obSubject): string
    {
        return self::SUBJECT_TYPE;
    }

    public function getSubjectId(object $obSubject): int
    {
        $mProductId = $this->productOf($obSubject)?->getAttribute('id');

        return is_numeric($mProductId) ? (int) $mProductId : 0;
    }

    public function getSiteId(object $obSubject): ?int
    {
        $obProduct = $this->productOf($obSubject);
        if ($obProduct === null) {
            return null;
        }

        $mSiteList = $obProduct->site_list ?? null;
        if (is_array($mSiteList) && count($mSiteList) === 1) {
            $mFirst = reset($mSiteList);
            if (is_numeric($mFirst) && (int) $mFirst > 0) {
                return (int) $mFirst;
            }
        }

        $mContextSiteId = Site::getSiteIdFromContext();

        return is_int($mContextSiteId) && $mContextSiteId > 0 ? $mContextSiteId : null;
    }

    public function getSecretKey(object $obSubject): ?string
    {
        return null;
    }

    public function getValueResolver(object $obSubject): ValueResolver
    {
        return new ShopaholicProductValueResolver;
    }

    /**
     * Anonymous product-view subjects carry no PII columns. All 13 Meta CAPI
     * keys stay null — theme-side cookies (fbp/fbc/client_ip/user_agent)
     * populate via the EventPixel render path; the cookie middleware sets
     * them at the request boundary. UserDataHasher honors null + omits the
     * field hash.
     *
     * @return array<string, ?string>
     */
    public function getUserData(object $obSubject): array
    {
        return [
            'em' => null, 'ph' => null, 'fn' => null, 'ln' => null,
            'ct' => null, 'st' => null, 'zp' => null, 'country' => null,
            'external_id' => null, 'fbp' => null, 'fbc' => null,
            'client_ip_address' => null, 'client_user_agent' => null,
        ];
    }

    /** @return array<string, list<string>> */
    public function getSupportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }

    /**
     * Hydrate Product from PK with active scope + site-match re-enforcement.
     * Returns null when subject is missing, inactive, soft-deleted, or fails
     * site-match — T-6-05 mitigation against cross-site subject_id spoofing
     * through the hybrid AJAX path. $arContext (carrying offer_id) is NOT
     * used for hydration here — the offer is resolved by the ValueResolver
     * at payload-build time from the product's offer relation.
     *
     * @param  array<string, mixed>  $arContext
     */
    public function loadSubject(int $iSubjectId, array $arContext): ?object
    {
        if ($iSubjectId <= 0) {
            return null;
        }

        $obProduct = Product::active()->find($iSubjectId);
        if ($obProduct === null) {
            return null;
        }

        $mContextSiteId = Site::getSiteIdFromContext();
        $mSiteList = $obProduct->site_list ?? null;
        if (
            is_int($mContextSiteId)
            && is_array($mSiteList)
            && ! in_array($mContextSiteId, $mSiteList, true)
        ) {
            return null;
        }

        return $obProduct;
    }

    private function productOf(object $obSubject): ?Product
    {
        return $obSubject instanceof Product ? $obSubject : null;
    }
}
