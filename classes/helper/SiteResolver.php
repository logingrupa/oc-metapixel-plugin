<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Helper;

use Illuminate\Support\Facades\Log;
use System\Classes\SiteManager;
use Throwable;

/**
 * Phase 3.1 REFAC-04 multi-site site_id resolver.
 *
 * Reads the active site_id from October 4's SiteManager singleton. Returns
 * null in three cases:
 *   1. SiteManager class not registered (single-site install — plugin shipped
 *      to an operator who didn't install the multi-site module).
 *   2. SiteManager::instance()->getActiveSiteId() returns null (no active
 *      site bound — CLI / queue / single-site context outside a request
 *      lifecycle).
 *   3. Any Throwable during SDK probe (defensive — Tiger-Style boundary
 *      catch mirrors PluginGuard.php Settings-read defensive boundary).
 *
 * UNIQUE constraint on `logingrupa_metapixel_event_log` treats NULL site_id
 * as a distinct value under MySQL semantics, so single-site installs (this
 * method returns null) and multi-site installs (returns int) coexist
 * correctly on the same table without race-fence collision.
 *
 * Stateless — no Singleton trait, no container binding, no memoization.
 * Each call is a cheap `Config::get('system.active_site')` read (see
 * `modules/system/classes/sitemanager/HasActiveSite.php` line 84).
 *
 * Threat model:
 *   - T-3.1-09 (Information Disclosure): Log message contains exception
 *     class + message only — no PII, no Settings values. `meta_pixel.*`
 *     namespace is operator-only telemetry.
 *
 * @see plugins/logingrupa/metapixelshopaholic/.planning/phases/03.1-event-log-refactor/BRIEF.md REFAC-04
 * @see modules/system/classes/SiteManager.php — SDK singleton accessor
 * @see modules/system/classes/sitemanager/HasActiveSite.php — getActiveSiteId() returning Config value
 * @see plugins/logingrupa/metapixelshopaholic/classes/helper/PluginGuard.php — boundary-catch precedent
 */
final class SiteResolver
{
    /**
     * Return the active site_id from October 4 SDK, or null when absent /
     * disabled / errored. Callers MUST treat null as "no site scoping" —
     * the UNIQUE constraint still de-dupes because NULL is a distinct
     * value under MySQL UNIQUE semantics.
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
            // boundary catch. Log warning so operator sees infra drift
            // without breaking the calling event_log INSERT (T-3.1-09).
            Log::warning('Metapixel: SiteResolver SDK probe failed — treating as single-site', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
            ]);

            return null;
        }
    }
}
