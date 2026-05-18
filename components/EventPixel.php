<?php

namespace Logingrupa\Metapixel\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-confirmed pixel emitter. Reads EventLog DIRECTLY via DB::table (D-09
 * lock — no adapter re-resolve at render time) and emits inline fbq with the
 * server event_id. onMarkFired writes the channel='pixel' twin row via the
 * INSERT IGNORE race-fence on EventLog's UNIQUE key.
 */
final class EventPixel extends ComponentBase
{
    private const TABLE = 'logingrupa_metapixel_event_log';

    private const JS = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;

    /** @return array{name: string, description: string} */
    public function componentDetails(): array
    {
        return ['name' => 'EventPixel', 'description' => 'Emits server-confirmed fbq() pixel for a subject (Order, theme action, custom adapter).'];
    }

    /** @return array<string, array<string, mixed>> */
    public function defineProperties(): array
    {
        return [
            'subject_class' => ['title' => 'Subject FQN', 'type' => 'string', 'required' => true],
            'subject_slug_field' => ['title' => 'Slug field name', 'type' => 'string', 'default' => 'slug'],
            'subject_type' => ['title' => 'Subject alias', 'type' => 'string', 'required' => true],
            'event_name' => ['title' => 'Event name', 'type' => 'string', 'default' => 'Purchase'],
            'slug' => ['title' => 'Subject slug', 'type' => 'string', 'default' => '{{ :slug }}'],
        ];
    }

    public function onRun(): void
    {
        $sSubjectClass = (string) $this->property('subject_class', '');
        $sSlugField = (string) $this->property('subject_slug_field', 'slug');
        $sSubjectType = (string) $this->property('subject_type', '');
        $sEventName = (string) $this->property('event_name', 'Purchase');
        $sSlug = (string) $this->property('slug', '');
        if ($sSubjectClass === '' || $sSubjectType === '' || $sSlug === '') {
            return;
        }
        try {
            $iSubjectId = (int) $sSubjectClass::query()->where($sSlugField, $sSlug)->value('id');
        } catch (Throwable $obException) {
            Log::warning('metapixel: EventPixel subject id lookup failed', ['meta_pixel.subject_class' => $sSubjectClass, 'meta_pixel.exception' => get_class($obException)]);

            return;
        }
        if ($iSubjectId <= 0) {
            return;
        }
        $obCapiRow = $this->findCapiRow($sSubjectType, $iSubjectId, $sEventName, ['event_id', 'event_time', 'payload']);
        if ($obCapiRow === null) {
            return;
        }
        if ($this->pixelRowExists($sSubjectType, $iSubjectId, $sEventName)) {
            return;
        }
        $sPayloadRaw = is_string($obCapiRow->payload) ? $obCapiRow->payload : '';
        $arPayload = $sPayloadRaw !== '' ? (array) json_decode($sPayloadRaw, true) : [];
        $arCustomData = is_array($arPayload['data'][0]['custom_data'] ?? null) ? (array) $arPayload['data'][0]['custom_data'] : [];
        $sEventId = is_string($obCapiRow->event_id) ? $obCapiRow->event_id : '';
        $this->page['eventPixelData'] = [
            'event_id' => $sEventId, 'event_name' => $sEventName,
            'subject_type' => $sSubjectType, 'subject_id' => $iSubjectId,
            'event_name_json' => (string) json_encode($sEventName, self::JS),
            'event_id_json' => (string) json_encode($sEventId, self::JS),
            'subject_type_json' => (string) json_encode($sSubjectType, self::JS),
            'custom_data_json' => (string) json_encode($arCustomData, self::JS),
        ];
    }

    /** @return array{ok: bool, error?: string} */
    public function onMarkFired(): array
    {
        $sSubjectType = (string) Input::get('subject_type', '');
        $iSubjectId = (int) Input::get('subject_id', 0);
        $sEventName = (string) Input::get('event_name', '');
        $sServerEventId = (string) Input::get('event_id', '');
        if ($sSubjectType === '' || $iSubjectId <= 0 || $sEventName === '' || $sServerEventId === '') {
            return ['ok' => false, 'error' => 'invalid params'];
        }
        $obCapiRow = $this->findCapiRow($sSubjectType, $iSubjectId, $sEventName, ['event_id', 'event_time', 'secret_key', 'site_id', 'payload']);
        if ($obCapiRow === null) {
            return ['ok' => false, 'error' => 'no capi row'];
        }
        if ($obCapiRow->event_id !== $sServerEventId) {
            return ['ok' => false, 'error' => 'event_id mismatch'];
        }
        try {
            $sNow = (string) Carbon::now();
            DB::table(self::TABLE)->insertOrIgnore([
                'event_id' => $sServerEventId, 'event_name' => $sEventName, 'channel' => 'pixel',
                'subject_type' => $sSubjectType, 'subject_id' => $iSubjectId,
                'secret_key' => $obCapiRow->secret_key, 'site_id' => $obCapiRow->site_id,
                'event_time' => (int) $obCapiRow->event_time,
                'fired_at' => $sNow, 'created_at' => $sNow, 'updated_at' => $sNow,
                'payload' => $obCapiRow->payload,
            ]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: EventPixel onMarkFired insert failed', ['meta_pixel.event_id' => $sServerEventId, 'meta_pixel.exception' => get_class($obException)]);

            return ['ok' => false, 'error' => 'db error'];
        }

        return ['ok' => true];
    }

    /**
     * @param  list<string>  $arColumns
     * @return object|null
     */
    private function findCapiRow(string $sSubjectType, int $iSubjectId, string $sEventName, array $arColumns)
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $sSubjectType)->where('subject_id', $iSubjectId)
            ->where('event_name', $sEventName)->where('channel', 'capi')
            ->first($arColumns);
    }

    private function pixelRowExists(string $sSubjectType, int $iSubjectId, string $sEventName): bool
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $sSubjectType)->where('subject_id', $iSubjectId)
            ->where('event_name', $sEventName)->where('channel', 'pixel')->exists();
    }
}
