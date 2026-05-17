<?php

namespace Logingrupa\Metapixel\Tests\Doubles;

use Logingrupa\Metapixel\Classes\Meta\MetaClient;

/**
 * Test spy: records sendForPixel call count + last payload. Pair with hook unit
 * tests (plans 02-06 / 02-07) to assert race-fence + listener behavior without
 * hitting Guzzle.
 */
class SpyMetaClient extends MetaClient
{
    public int $iCallCount = 0;

    /** @var array<string, mixed> */
    public array $arLastPayload = [];

    public string $sLastPixelId = '';

    public string $sLastToken = '';

    public function __construct()
    {
        parent::__construct(null);
    }

    public function sendForPixel(string $sPixelId, string $sToken, array $arPayload): array
    {
        $this->iCallCount++;
        $this->sLastPixelId = $sPixelId;
        $this->sLastToken = $sToken;
        $this->arLastPayload = $arPayload;

        return ['events_received' => 1];
    }
}
