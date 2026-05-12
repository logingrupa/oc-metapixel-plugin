<?php

declare(strict_types=1);

namespace Logingrupa\Metapixelshopaholic\Classes\Exception;

use RuntimeException;
use Throwable;

/**
 * Abstract base for every Phase 3+ Meta Pixel / CAPI exception. Subclasses
 * MUST be `final` and MUST implement `isRetryable()`. The context array is
 * `readonly` (PHP 8.4 constructor promotion) — the canonical log sink is the
 * one-liner `Log::error($obException->getMessage(), $obException->arContext)`.
 *
 * Lang keys live under `logingrupa.metapixelshopaholic::lang.exception.*`.
 *
 * Per CONTEXT Area 3 Q4 — one file per class:
 *   - 1 abstract base (this file) + 7 concrete finals = 8 files in `classes/exception/`.
 *   - Phase 3 production classes catch the base type to handle every
 *     plugin-emitted CAPI exception polymorphically.
 *   - Subclasses inherit the constructor verbatim — they MUST NOT override it.
 *
 * The Phase-3 addition over the GoodsReceivedException analog is
 * `abstract public function isRetryable(): bool;`. CONTEXT Area 1 Q4 mandates
 * that transient-vs-permanent classification lives in the type — the matching
 * `SendCapiEvent::handle()` catch (plan 03-05) prefers `instanceof
 * MetaApiTransientException` for class-level clarity, but the boolean method
 * is also exposed for future-proofing and dynamic-dispatch contexts.
 *
 * Threat model (T-03-06..07):
 *   - `$arContext` is `public readonly` — PHP 8.4 enforces immutability so
 *     downstream code cannot inject attacker-controlled fields.
 *   - `jsonContext()` is the log-injection guard: `json_encode` escapes
 *     control characters (newline / CR / tab) into `\nXX` literals so an
 *     attacker-controlled field cannot forge fake log lines. Falls back
 *     to `'{}'` on unencodable input.
 *   - All concrete subclasses are `final` — a plugin consumer cannot subclass
 *     and flip retryability semantics.
 *
 * @property-read array<string, mixed> $arContext
 */
abstract class MetaPixelException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $arContext
     */
    public function __construct(
        string $sMessage,
        public readonly array $arContext = [],
        ?Throwable $obPrevious = null,
    ) {
        parent::__construct($sMessage, 0, $obPrevious);
    }

    /**
     * Whether the queue-job catch path should rethrow (true → Laravel queue
     * worker honours `$tries = 3` + `$backoff = [1, 4, 16]`) or persist a
     * FailedEvent row and mark the job succeeded (false → dead-letter).
     *
     * Only `MetaApiTransientException` returns true. Every other Phase-3
     * concrete returns false. The queue-job dispatch contract is encoded in
     * the exception type itself, not in a separate switch.
     */
    abstract public function isRetryable(): bool;

    /**
     * Encode a context array as a single-line JSON string safe for
     * `Log::error()` sinks. `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
     * keeps the payload readable; default `json_encode` behavior escapes
     * control characters (`\n`, `\r`, `\t`) into literal escape sequences,
     * blocking log-injection (T-03-06). Returns `'{}'` if `json_encode`
     * fails (e.g. resources, recursive refs).
     *
     * @param  array<string, mixed>  $arContext
     */
    protected static function jsonContext(array $arContext): string
    {
        $sJson = json_encode($arContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $sJson !== false ? $sJson : '{}';
    }
}
