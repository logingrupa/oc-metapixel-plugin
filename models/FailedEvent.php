<?php

namespace Logingrupa\Metapixel\Models;

use October\Rain\Database\Model;

/**
 * Dead-letter row for a permanently failed CAPI dispatch. Phase 4 admin
 * UI (FAIL-01..03) consumes this table; Phase 2 ships only the table +
 * model. subject_type + subject_id are populated by
 * SendCapiEvent::writeFailedEvent when the adapter is resolvable so the
 * admin UI can re-resolve the adapter for replay.
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
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'attempts' => 'int',
        'http_status' => 'int',
    ];
}
