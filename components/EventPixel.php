<?php

namespace Logingrupa\Metapixel\Components;

use Cms\Classes\ComponentBase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use October\Rain\Support\Facades\Input;
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
        $iSubjectId = $this->lookupSubjectId($sSubjectClass, $sSlugField, $sSlug);
        if ($iSubjectId <= 0) {
            return;
        }
        $arCapiRow = $this->findCapiRow($sSubjectType, $iSubjectId, $sEventName);
        if ($arCapiRow === null) {
            return;
        }
        if ($this->pixelRowExists($sSubjectType, $iSubjectId, $sEventName)) {
            return;
        }
        $arCustomData = $this->extractCustomData($arCapiRow);
        $mEventId = $arCapiRow['event_id'] ?? null;
        $sEventId = is_string($mEventId) ? $mEventId : '';
        $this->page['eventPixelData'] = [
            'event_id' => $sEventId,
            'event_name' => $sEventName,
            'subject_type' => $sSubjectType,
            'subject_id' => $iSubjectId,
            'event_name_json' => (string) json_encode($sEventName, self::JS),
            'event_id_json' => (string) json_encode($sEventId, self::JS),
            'subject_type_json' => (string) json_encode($sSubjectType, self::JS),
            'custom_data_json' => (string) json_encode($arCustomData, self::JS),
        ];
    }

    /** @return array{ok: bool, error?: string} */
    public function onMarkFired(): array
    {
        $sSubjectType = $this->inputString('subject_type');
        $iSubjectId = $this->inputInt('subject_id');
        $sEventName = $this->inputString('event_name');
        $sServerEventId = $this->inputString('event_id');
        if ($sSubjectType === '' || $iSubjectId <= 0 || $sEventName === '' || $sServerEventId === '') {
            return ['ok' => false, 'error' => 'invalid params'];
        }
        $arCapiRow = $this->findCapiRow($sSubjectType, $iSubjectId, $sEventName);
        if ($arCapiRow === null) {
            return ['ok' => false, 'error' => 'no capi row'];
        }
        if (($arCapiRow['event_id'] ?? null) !== $sServerEventId) {
            return ['ok' => false, 'error' => 'event_id mismatch'];
        }

        return $this->insertPixelRow($sServerEventId, $sEventName, $sSubjectType, $iSubjectId, $arCapiRow);
    }

    private function inputString(string $sKey): string
    {
        $mValue = Input::get($sKey, '');

        return is_string($mValue) ? $mValue : '';
    }

    private function inputInt(string $sKey): int
    {
        $mValue = Input::get($sKey, 0);

        return is_numeric($mValue) ? (int) $mValue : 0;
    }

    private function lookupSubjectId(string $sSubjectClass, string $sSlugField, string $sSlug): int
    {
        try {
            if (! is_subclass_of($sSubjectClass, Model::class)) {
                return 0;
            }
            $mValue = $sSubjectClass::query()->where($sSlugField, $sSlug)->value('id');

            return is_numeric($mValue) ? (int) $mValue : 0;
        } catch (Throwable $obException) {
            Log::warning('metapixel: EventPixel subject id lookup failed', ['meta_pixel.subject_class' => $sSubjectClass, 'meta_pixel.exception' => get_class($obException)]);

            return 0;
        }
    }

    /** @return array<string, mixed>|null */
    private function findCapiRow(string $sSubjectType, int $iSubjectId, string $sEventName): ?array
    {
        $obRow = DB::table(self::TABLE)
            ->where('subject_type', $sSubjectType)->where('subject_id', $iSubjectId)
            ->where('event_name', $sEventName)->where('channel', 'capi')
            ->first(['event_id', 'event_time', 'secret_key', 'site_id', 'payload']);
        if ($obRow === null) {
            return null;
        }
        $arResult = [];
        foreach ((array) $obRow as $mKey => $mValue) {
            $arResult[(string) $mKey] = $mValue;
        }

        return $arResult;
    }

    private function pixelRowExists(string $sSubjectType, int $iSubjectId, string $sEventName): bool
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $sSubjectType)->where('subject_id', $iSubjectId)
            ->where('event_name', $sEventName)->where('channel', 'pixel')->exists();
    }

    /**
     * @param  array<string, mixed>  $arCapiRow
     * @return array<string, mixed>
     */
    private function extractCustomData(array $arCapiRow): array
    {
        $mPayloadRaw = $arCapiRow['payload'] ?? null;
        if (! is_string($mPayloadRaw) || $mPayloadRaw === '') {
            return [];
        }
        $mDecoded = json_decode($mPayloadRaw, true);
        if (! is_array($mDecoded)) {
            return [];
        }
        $mData = $mDecoded['data'] ?? null;
        if (! is_array($mData)) {
            return [];
        }
        $mFirst = $mData[0] ?? null;
        if (! is_array($mFirst)) {
            return [];
        }
        $mCustomData = $mFirst['custom_data'] ?? null;
        if (! is_array($mCustomData)) {
            return [];
        }
        $arResult = [];
        foreach ($mCustomData as $mKey => $mValue) {
            $arResult[(string) $mKey] = $mValue;
        }

        return $arResult;
    }

    /**
     * @param  array<string, mixed>  $arCapiRow
     * @return array{ok: bool, error?: string}
     */
    private function insertPixelRow(string $sEventId, string $sEventName, string $sSubjectType, int $iSubjectId, array $arCapiRow): array
    {
        try {
            $sNow = (string) Carbon::now();
            $mEventTime = $arCapiRow['event_time'] ?? 0;
            DB::table(self::TABLE)->insertOrIgnore([
                'event_id' => $sEventId,
                'event_name' => $sEventName,
                'channel' => 'pixel',
                'subject_type' => $sSubjectType,
                'subject_id' => $iSubjectId,
                'secret_key' => $arCapiRow['secret_key'] ?? null,
                'site_id' => $arCapiRow['site_id'] ?? null,
                'event_time' => is_numeric($mEventTime) ? (int) $mEventTime : 0,
                'fired_at' => $sNow,
                'created_at' => $sNow,
                'updated_at' => $sNow,
                'payload' => $arCapiRow['payload'] ?? null,
            ]);
        } catch (Throwable $obException) {
            Log::warning('metapixel: EventPixel onMarkFired insert failed', ['meta_pixel.event_id' => $sEventId, 'meta_pixel.exception' => get_class($obException)]);

            return ['ok' => false, 'error' => 'db error'];
        }

        return ['ok' => true];
    }
}
