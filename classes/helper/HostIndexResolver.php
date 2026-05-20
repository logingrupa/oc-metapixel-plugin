<?php

namespace Logingrupa\Metapixel\Classes\Helper;

use Illuminate\Support\Facades\Log;
use Pdp\Domain;
use Pdp\Rules;
use Throwable;

/**
 * PSL-aware subdomain-index resolver. Returns 1 for apex, 2 for www, null for
 * unresolvable or unknown-TLD hosts. Emits one Log::warning per request when
 * the bundled PSL file is older than 180 days (D-10 operator nudge).
 */
final class HostIndexResolver
{
    /**
     * 180 days × 86_400 — the D-10 operator-feedback threshold. A constant
     * keeps the check cheaper than Carbon instantiation per request and
     * mirrors the literal grep gate in the plan acceptance criteria.
     */
    private const STALE_THRESHOLD_SECONDS = 15552000;

    private ?Rules $obRules = null;

    /** @var array<string, ?int> request-scoped memo */
    private array $arMemo = [];

    /**
     * D-10 one-shot latch — set to true after the first stale-PSL Log::warning
     * fires within the request lifecycle so subsequent resolve() calls do not
     * spam the log.
     */
    private bool $bStaleWarningEmitted = false;

    public function __construct(private readonly string $sPslPath) {}

    /**
     * Returns the Meta _fbp subdomain-index for $sHost (1 apex, 2 www, 3 a.b,
     * etc.) or null for any unresolvable / unknown-TLD input. Never throws.
     *
     * @param  string  $sHost  raw Host header value
     */
    public function resolve(string $sHost): ?int
    {
        $this->checkPslAge();

        $sHost = strtolower(trim($sHost));
        if ($sHost === '') {
            return null;
        }
        if (array_key_exists($sHost, $this->arMemo)) {
            return $this->arMemo[$sHost];
        }

        try {
            $obRules = $this->getRules();
            $obDomain = Domain::fromIDNA2008($sHost);
            $obResolved = $obRules->resolve($obDomain);
        } catch (Throwable $obException) {
            // silent: Pdp throws on syntactically invalid hosts (IPs, empty,
            // underscore labels) AND on missing / unreadable PSL files —
            // middleware NO-OPs is the safe path (HOST-04 contract).
            return $this->arMemo[$sHost] = null;
        }

        $obSuffix = $obResolved->suffix();
        if ($obSuffix->isPublicSuffix() === false || $obResolved->secondLevelDomain()->value() === null) {
            return $this->arMemo[$sHost] = null;
        }

        $iSubdomainLabels = count($obResolved->subDomain()->labels());

        return $this->arMemo[$sHost] = $iSubdomainLabels + 1;
    }

    /**
     * D-10 — emit one Log::warning per request when the bundled PSL is older
     * than 180 days. PSL is additive-only, so a stale snapshot still resolves
     * every pre-existing host correctly; the warning is operator feedback,
     * not a failure mode. The $bStaleWarningEmitted latch keeps the cost to
     * exactly one log line per resolver instance per request.
     */
    private function checkPslAge(): void
    {
        if ($this->bStaleWarningEmitted) {
            return;
        }
        if (! is_file($this->sPslPath)) {
            // silent: missing PSL file — the existing Throwable handler in
            // resolve() catches the downstream Rules::fromPath failure and
            // returns null; no stale-age signal applies.
            return;
        }

        $mModified = filemtime($this->sPslPath);
        if ($mModified === false) {
            return;
        }

        $iAgeSeconds = time() - $mModified;
        if ($iAgeSeconds > self::STALE_THRESHOLD_SECONDS) {
            $iAgeDays = (int) floor($iAgeSeconds / 86400);
            Log::warning('PSL snapshot is '.$iAgeDays.' days old — run php artisan metapixel:refresh-psl');
            $this->bStaleWarningEmitted = true;
        }
    }

    private function getRules(): Rules
    {
        return $this->obRules ??= Rules::fromPath($this->sPslPath);
    }
}
