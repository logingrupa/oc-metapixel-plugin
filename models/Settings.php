<?php

namespace Logingrupa\Metapixel\Models;

use Lovata\Toolbox\Models\CommonSettings;

/**
 * Plugin settings (single-row in Phase 2). Multisite per-field whitelist on
 * pixel_id + capi_access_token lands in Phase 4 (MULT-01..02).
 *
 * lookupForSite is the credential-lookup contract callers (SendCapiEvent::handle)
 * use. Phase 2 stub returns the default row regardless of $iSiteId; Phase 4
 * MULT-03 re-implements to honor the Multisite per-site row routing.
 *
 * @method static mixed get(string $sCode, mixed $mDefault = null)
 * @method static void set(array $arValues)
 */
class Settings extends CommonSettings
{
    /** @var string */
    public $settingsCode = 'logingrupa_metapixel_settings';

    /** @var string */
    public $settingsFields = 'fields.yaml';

    /** @var list<string> */
    protected $propagatable = [];

    /**
     * Multisite-aware credential lookup. Phase 2 stub ignores $iSiteId.
     *
     * @return array{pixel_id: string, capi_access_token: string}
     */
    public static function lookupForSite(?int $iSiteId): array
    {
        return [
            'pixel_id' => (string) self::get('pixel_id', ''),
            'capi_access_token' => (string) self::get('capi_access_token', ''),
        ];
    }
}
