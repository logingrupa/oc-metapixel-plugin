<?php

namespace Logingrupa\Metapixel\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Logingrupa\Metapixel\Classes\Exception\MetaPixelException;
use October\Rain\Database\Model;
use Throwable;

/**
 * Dead-letter row for a permanently failed CAPI dispatch. Phase 4 admin
 * UI (FAIL-01..03) consumes this table; Phase 2 ships only the table +
 * model. subject_type + subject_id are populated by
 * SendCapiEvent::writeFailedEvent when the adapter is resolvable so the
 * admin UI can re-resolve the adapter for replay.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_name
 * @property ?string $adapter_type
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property array<string, mixed> $payload
 * @property ?int $http_status
 * @property ?string $graph_error
 * @property int $attempts
 * @property ?Carbon $replayed_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FailedEvent extends Model
{
    /** @var string */
    public $table = 'logingrupa_metapixel_failed_events';

    /** @var int Meta rejects event_time older than this, so a row past it cannot be replayed. */
    public const RETENTION_DAYS = 7;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'event_name',
        'adapter_type',
        'subject_type',
        'subject_id',
        'payload',
        'http_status',
        'graph_error',
        'attempts',
        'replayed_at',
    ];

    /** @var list<string> */
    protected $jsonable = ['payload'];

    /** @var array<string, string> */
    protected $casts = [
        'attempts' => 'int',
        'http_status' => 'int',
        'replayed_at' => 'datetime',
    ];

    /**
     * graph_error text for a failed dispatch: the exception message plus the
     * decoded Graph response when the exception carries one.
     */
    public static function graphErrorFrom(Throwable $obException): string
    {
        $arContext = $obException instanceof MetaPixelException ? $obException->getContext() : [];

        return $obException->getMessage()."\n".json_encode($arContext);
    }

    /**
     * Meta rejects event_time older than RETENTION_DAYS, so a row that failed
     * before the cutoff cannot be replayed.
     */
    public function isTooOldToReplay(): bool
    {
        $obFailedAt = $this->created_at;

        return $obFailedAt instanceof CarbonInterface
            && $obFailedAt->lt(Carbon::now()->subDays(self::RETENTION_DAYS));
    }
}
