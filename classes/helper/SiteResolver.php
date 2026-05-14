<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Models\Order;
use System\Classes\SiteManager;
use Throwable;

/**
 * Multi-site `site_id` resolver. Two public statics: forOrder for Order-
 * scoped writes/reads (canonical, REFAC-12); getActiveSiteId for request-
 * scoped non-Order subjects (Phase 4 Lead/AddToCart/ViewContent). Method
 * docblocks below carry null-case + threat-model detail.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-04
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-12
 */
final class SiteResolver
{
    /**
     * Read Order.site_id stamped by Lovata MakeOrder at create. Null on
     * single-site install or pre-v1.33 Order. Deterministic across request
     * contexts — writer (admin/queue) + reader (frontend) resolve same value
     * for same Order. SRP: getAttribute() is black-box; only this method
     * moves if storage moves. is_numeric narrow → non-numeric attr returns
     * null. No Throwable catch — getAttribute pure accessor, never throws.
     *
     * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-12
     * @see plugins/lovata/ordersshopaholic/classes/processor/OrderProcessor.php site_id write path
     */
    public static function forOrder(Order $obOrder): ?int
    {
        $mId = $obOrder->getAttribute('site_id');

        return is_numeric($mId) ? (int) $mId : null;
    }

    /**
     * Active site_id from October 4 SDK, or null when absent / disabled /
     * errored. Callers MUST treat null as "no site scoping" — UNIQUE
     * NULL-distinct under MySQL keeps de-dup correct.
     */
    public static function getActiveSiteId(): ?int
    {
        try {
            if (!class_exists(SiteManager::class)) {
                return null;
            }

            $mId = SiteManager::instance()->getActiveSiteId();

            if ($mId === null) {
                return null;
            }

            return is_numeric($mId) ? (int) $mId : null;
        } catch (Throwable $obException) {
            // silent: SDK probe failure must NOT cascade — Tiger-Style
            // boundary catch. Log warning surfaces infra drift to operator
            // without breaking caller event_log INSERT (T-3.1-09).
            Log::warning('Metapixel: SiteResolver SDK probe failed — treating as single-site', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return null;
        }
    }
}
