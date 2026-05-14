<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Models\Order;
use System\Classes\SiteManager;
use Throwable;

/**
 * Phase 3.1 site_id resolvers — Order-scoped + active-site.
 *
 * forOrder(Order): canonical Order-scoped resolver. Reads Order.site_id
 * stamped by Lovata MakeOrder at create. Deterministic across writer
 * (admin/queue) + reader (frontend) — same Order, same value.
 *
 * getActiveSiteId(): non-Order subjects (future Lead, AddToCart, ViewContent
 * in Phase 4). Reads October 4 SiteManager singleton. Request-context-
 * dependent — DO NOT use for Order-scoped paths.
 *
 * getActiveSiteId null cases:
 *   1. SiteManager class absent (single-site install w/o multi-site module).
 *   2. SiteManager::instance()->getActiveSiteId() null (CLI / queue / no req).
 *   3. Throwable on SDK probe (Tiger-Style boundary catch, T-3.1-09).
 *
 * UNIQUE on `logingrupa_metapixel_event_log` treats NULL as distinct under
 * MySQL — single-site (null) + multi-site (int) coexist on same table.
 *
 * Stateless — each call cheap `Config::get('system.active_site')` read
 * (modules/system/classes/sitemanager/HasActiveSite.php line 84). Tests
 * inject via Config::set('system.active_site', $i).
 *
 * T-3.1-09 (Info Disclosure): log carries exception class + message only —
 * no PII, no Settings values. `meta_pixel.*` namespace = operator telemetry.
 *
 * Phase 3.1-07 REFAC-12: forOrder is canonical Order-scoped resolver;
 * getActiveSiteId stays for non-Order subjects.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-04
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-07-multi-site-site-id-symmetry/BRIEF.md REFAC-12
 * @see modules/system/classes/SiteManager.php
 * @see modules/system/classes/sitemanager/HasActiveSite.php
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php boundary-catch precedent
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
