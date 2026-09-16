<?php

namespace Logingrupa\Metapixel\Models;

use Illuminate\Support\Carbon;
use October\Rain\Database\Model;

/**
 * The buyer's browser identity (IP, user agent, _fbp, _fbc, page URL) at
 * order creation. A Purchase usually fires later from a gateway webhook, a
 * backend save or the 1C exchange, whose request describes the caller, so
 * OrderStatusWatcher reads the buyer's context from here instead.
 *
 * @property int $id
 * @property int $order_id
 * @property ?string $client_ip_address
 * @property ?string $client_user_agent
 * @property ?string $fbp
 * @property ?string $fbc
 * @property ?string $event_source_url
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class OrderBrowserContext extends Model
{
    /** @var int Rows older than this are purged; the _fbc click id itself expires at 90 days. */
    public const RETENTION_DAYS = 90;

    /** @var list<string> */
    public const USER_DATA_FIELDS = ['client_ip_address', 'client_user_agent', 'fbp', 'fbc'];

    /** @var string */
    public $table = 'logingrupa_metapixel_order_browser_contexts';

    /** @var list<string> */
    protected $fillable = ['order_id', 'client_ip_address', 'client_user_agent', 'fbp', 'fbc', 'event_source_url'];

    /** @var array<string, string> */
    protected $casts = ['order_id' => 'int'];

    public static function findForOrder(int $iOrderId): ?self
    {
        if ($iOrderId <= 0) {
            return null;
        }
        $obContext = self::query()->where('order_id', $iOrderId)->first();

        return $obContext instanceof self ? $obContext : null;
    }

    /**
     * Passthrough user_data keys, null where the buyer's request carried nothing.
     *
     * @return array<string, ?string>
     */
    public function toUserData(): array
    {
        $arResult = [];
        foreach (self::USER_DATA_FIELDS as $sField) {
            $mValue = $this->getAttribute($sField);
            $arResult[$sField] = is_string($mValue) && $mValue !== '' ? $mValue : null;
        }

        return $arResult;
    }
}
