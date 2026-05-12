<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixelshopaholic\Classes\Exception\MetaPixelException;
use Model;
use October\Rain\Database\Builder;
use October\Rain\Database\Traits\Validation;

/**
 * Class FailedEvent
 *
 * Plain Model audit log of permanently-failed Meta CAPI events. NOT a Toolbox
 * Item-wrapped model — admin-only, never exposed to frontend Twig (PROJECT.md
 * key decision). Phase 5 HARD-01 ships the backend list controller against this
 * table.
 *
 * Written by SendCapiEvent::handle() (plan 03-05) via
 * `FailedEvent::createFromPayloadAndException` when the retry chain terminates
 * on a MetaApiPermanentException.
 *
 * @property int $id
 * @property string $event_id UUIDv4 — paired with the Pixel browser twin.
 * @property string $event_name Purchase, ViewContent, AddToCart, ...
 * @property string $payload Raw JSON envelope sent to Meta (LONGTEXT).
 * @property string|null $graph_error Meta Graph API error message.
 * @property int|null $http_status 4xx / 5xx classification.
 * @property int $attempts Queue-job retry counter.
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @mixin Builder
 */
class FailedEvent extends Model
{
    use Validation;

    /** @var string */
    public $table = 'logingrupa_metapixel_failed_events';

    /** @var array<string,string> */
    public $rules = [
        'event_id' => 'required|string|max:36',
        'event_name' => 'required|string|max:64',
        'payload' => 'required|string',
        'http_status' => 'nullable|integer',
        'attempts' => 'required|integer',
    ];

    /** @var list<string> */
    public $fillable = [
        'event_id',
        'event_name',
        'payload',
        'graph_error',
        'http_status',
        'attempts',
    ];

    /** @var list<string> — payload stored as longtext per CONTEXT Area 4 Q1 (raw JSON string, no auto-decode). */
    public $jsonable = [];

    /** @var list<string> */
    public $dates = [
        'created_at',
        'updated_at',
    ];

    /** @var array<string,string> */
    public $casts = [
        'http_status' => 'int',
        'attempts' => 'int',
    ];

    /**
     * Persist a FailedEvent row built from an outgoing CAPI payload + the
     * permanent exception that terminated the retry chain.
     *
     * @param  array<string,mixed>  $arPayload  Raw envelope sent to Meta (`['data' => [['event_id' => ..., 'event_name' => ..., ...]]]`).
     * @param  MetaPixelException  $obException  Permanent exception with `arContext` containing `http_status` + `attempts`.
     */
    public static function createFromPayloadAndException(array $arPayload, MetaPixelException $obException): self
    {
        $arFirstEvent = self::extractFirstEvent($arPayload);
        $arContext = $obException->arContext;

        // WR-04 lock: surface malformed payloads via sentinel placeholders +
        // a critical log rather than silently losing the dead-letter row to
        // the model `rules` `required` validator (and then to the silent
        // catch in SendCapiEvent::writeFailedEvent). The placeholders pass
        // validation (`required|string|max:N`) while remaining clearly-
        // distinguishable in the backend list controller so the operator
        // sees a malformed row instead of nothing.
        $sEventId = self::extractStringField($arFirstEvent, 'event_id');
        if ($sEventId === '') {
            $sEventId = 'unknown:'.substr(sha1($obException->getMessage()), 0, 8);
            Log::critical('Metapixel: FailedEvent payload missing event_id — substituted sentinel', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.message' => $obException->getMessage(),
                'meta_pixel.sentinel_event_id' => $sEventId,
            ]);
        }
        $sEventName = self::extractStringField($arFirstEvent, 'event_name');
        if ($sEventName === '') {
            $sEventName = '__unknown__';
            Log::critical('Metapixel: FailedEvent payload missing event_name — substituted sentinel', [
                'meta_pixel.exception' => get_class($obException),
                'meta_pixel.sentinel_event_name' => $sEventName,
            ]);
        }

        /** @var self $obFailed */
        $obFailed = self::create([
            'event_id' => $sEventId,
            'event_name' => $sEventName,
            'payload' => self::encodePayload($arPayload),
            'graph_error' => $obException->getMessage(),
            'http_status' => self::extractHttpStatus($arContext),
            'attempts' => self::extractAttempts($arContext),
        ]);

        return $obFailed;
    }

    /**
     * Extract the first event row out of a CAPI envelope (`['data' => [[...]]]`).
     *
     * @param  array<int|string,mixed>  $arPayload
     * @return array<int|string,mixed>
     */
    private static function extractFirstEvent(array $arPayload): array
    {
        $arData = is_array($arPayload['data'] ?? null) ? $arPayload['data'] : [];

        return is_array($arData[0] ?? null) ? $arData[0] : [];
    }

    /**
     * Pull a scalar field out of an event row and coerce to string.
     *
     * @param  array<int|string,mixed>  $arRow
     */
    private static function extractStringField(array $arRow, string $sKey): string
    {
        return isset($arRow[$sKey]) && is_scalar($arRow[$sKey]) ? (string) $arRow[$sKey] : '';
    }

    /**
     * JSON-encode the payload with stable flags; degrade to `{}` on encode failure.
     *
     * @param  array<int|string,mixed>  $arPayload
     */
    private static function encodePayload(array $arPayload): string
    {
        $sJson = json_encode($arPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $sJson === false ? '{}' : $sJson;
    }

    /**
     * Read `http_status` out of the exception context; null if absent or non-int.
     *
     * @param  array<int|string,mixed>  $arContext
     */
    private static function extractHttpStatus(array $arContext): ?int
    {
        $mxStatus = $arContext['http_status'] ?? null;

        return is_int($mxStatus) ? $mxStatus : null;
    }

    /**
     * Read `attempts` out of the exception context; 0 if absent or non-numeric.
     *
     * @param  array<int|string,mixed>  $arContext
     */
    private static function extractAttempts(array $arContext): int
    {
        $mxAttempts = $arContext['attempts'] ?? null;

        return is_numeric($mxAttempts) ? (int) $mxAttempts : 0;
    }
}
