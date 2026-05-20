<?php

namespace Logingrupa\Metapixel\Models;

use Illuminate\Support\Carbon;
use October\Rain\Database\Model;

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
 * @property ?float $dedup_pct
 * @property ?float $emq
 * @property ?Carbon $dedup_checked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FailedEvent extends Model
{
    /** @var string */
    public $table = 'logingrupa_metapixel_failed_events';

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
        'dedup_pct',
        'emq',
        'dedup_checked_at',
    ];

    /** @var list<string> */
    protected $jsonable = ['payload'];

    /** @var array<string, string> */
    protected $casts = [
        'attempts' => 'int',
        'http_status' => 'int',
        'dedup_pct' => 'float',
        'emq' => 'float',
        'dedup_checked_at' => 'datetime',
    ];
}
