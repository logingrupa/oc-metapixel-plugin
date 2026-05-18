<?php

namespace Logingrupa\Metapixel\Models;

use October\Rain\Database\Builder;
use October\Rain\Database\Model;

/**
 * Append-only Meta event log row. subject_type is an opaque alias string
 * (e.g. 'shopaholic.order'), NOT a class FQN — no MorphTo relation is
 * declared. UNIQUE on (subject_type, subject_id, event_name, channel,
 * site_id) at the DB layer gates EventLogWriter::record().
 */
class EventLog extends Model
{
    public const CHANNEL_CAPI = 'capi';

    public const CHANNEL_PIXEL = 'pixel';

    /** @var string */
    public $table = 'logingrupa_metapixel_event_log';

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'event_name',
        'channel',
        'subject_type',
        'subject_id',
        'secret_key',
        'site_id',
        'event_time',
        'fired_at',
        'payload',
    ];

    /** @var list<string> */
    protected $jsonable = ['payload'];

    /** @var array<string, string> */
    protected $casts = [
        'subject_id' => 'int',
        'site_id' => 'int',
        'event_time' => 'int',
    ];

    /**
     * Filter by the opaque alias + subject id pair. subject_type is a
     * vendor alias (e.g. 'shopaholic.order') — not a class FQN.
     *
     * @param  Builder  $obQuery
     * @return Builder
     */
    public function scopeForSubject($obQuery, string $sSubjectType, int $iSubjectId)
    {
        return $obQuery
            ->where('subject_type', $sSubjectType)
            ->where('subject_id', $iSubjectId);
    }
}
