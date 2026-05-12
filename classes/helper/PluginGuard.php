<?php

namespace Logingrupa\Metapixelshopaholic\Classes\Helper;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Models\Settings;
use October\Rain\Support\Traits\Singleton;

/**
 * Single source of truth for Logingrupa.Metapixelshopaholic boot-time disabled state.
 *
 * SKEL-05 contract:
 *   - Missing `pixel_id` in Settings = log Warning + return isDisabled() === true.
 *   - Never throws at boot (would cascade through Campaigns/PromoMechanism/Order).
 *
 * Container-singleton bridge:
 *   Every Phase 3+ event handler MUST short-circuit via:
 *
 *     if (App::make('metapixel.disabled')) { return; }
 *
 *   at the top of its handler body. The bridge is bound by init() and resolves
 *   to the memoized isDisabled() boolean for the lifetime of the request.
 *
 * Testing:
 *   Call PluginGuard::flush() in tearDown() to reset both the Singleton-trait
 *   instance and the container binding.
 *
 * @author Logingrupa
 *
 * The October\Rain Singleton trait exposes a final static `instance()` method
 * with no return type declaration. The @method declaration below makes the
 * trait's actual contract visible to phpstan (level 10 requires it for any
 * caller that chains an instance method onto `PluginGuard::instance()`).
 *
 * @method static self instance()
 */
class PluginGuard
{
    use Singleton;

    /**
     * Memoized disabled flag. `null` = unprimed, `true`/`false` after prime().
     *
     * @var bool|null
     */
    protected $bIsDisabled = null;

    /**
     * Memoized pixel_id. Populated by prime() when Settings returns a non-empty value.
     *
     * @var string|null
     */
    protected $sPixelId = null;

    /**
     * Return the memoized disabled flag.
     */
    public function isDisabled(): bool
    {
        $this->prime();

        return (bool) $this->bIsDisabled;
    }

    /**
     * Return the memoized pixel_id (null when disabled).
     */
    public function getPixelId(): ?string
    {
        $this->prime();

        return $this->sPixelId;
    }

    /**
     * Reset both the Singleton-trait instance and the container singleton bridge.
     * Intended for test teardown — flushes the disabled-flag memo between tests.
     */
    public static function flush(): void
    {
        if (App::bound('metapixel.disabled')) {
            App::forgetInstance('metapixel.disabled');
        }

        self::forgetInstance();
    }

    /**
     * Auto-invoked by the Singleton trait on the first instance() call.
     * Primes the memo and binds the `metapixel.disabled` container singleton.
     */
    protected function init(): void
    {
        $this->prime();

        App::singleton('metapixel.disabled', fn (): bool => $this->isDisabled());
    }

    /**
     * Read Settings::get('pixel_id') once, log a warning when empty, set the
     * memoized $bIsDisabled flag. Idempotent — early-returns when already primed.
     *
     * Wraps the Settings read in a Throwable catch as a deliberate boundary-layer
     * fallback: SKEL-05 forbids cascading boot failures, so an inaccessible
     * `system_settings` table (during early bootstrap, broken DB, fresh install
     * before migrations) must surface as `disabled = true` with a logged warning,
     * not a hard throw. This is the only catch in PluginGuard and matches the
     * CLAUDE.md Tiger-Style allowance for explicit, reason-documented boundary
     * catches (see Plugin::boot() PHPDoc).
     */
    protected function prime(): void
    {
        if ($this->bIsDisabled !== null) {
            return;
        }

        try {
            $mPixelId = Settings::get('pixel_id', '');
            $sPixelId = is_scalar($mPixelId) ? (string) $mPixelId : '';
        } catch (\Throwable $obException) {
            // Boundary catch: Settings table missing / DB unavailable at boot
            // must NOT cascade through Campaigns/PromoMechanism/Order. SKEL-05.
            Log::warning(
                'Metapixel: pixel_id not configured — plugin disabled',
                ['reason' => 'settings_read_failed', 'exception' => $obException->getMessage()]
            );
            $this->bIsDisabled = true;

            return;
        }

        if ($sPixelId === '') {
            Log::warning('Metapixel: pixel_id not configured — plugin disabled');
            $this->bIsDisabled = true;

            return;
        }

        $this->sPixelId = $sPixelId;
        $this->bIsDisabled = false;
    }
}
