<?php

namespace Logingrupa\Metapixel\Console;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Refresh the bundled Public Suffix List from publicsuffix.org. Atomic-renames
 * the downloaded file into place + wipes the parsed-Rules cache directory
 * (HOST-03 / D-11). URL pinned to a constant per SSRF mitigation.
 */
final class RefreshPsl extends Command
{
    /** @var string */
    protected $signature = 'metapixel:refresh-psl';

    /** @var string */
    protected $description = 'Refresh the bundled Public Suffix List from publicsuffix.org.';

    public const UPSTREAM_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    public const SENTINEL = '// ===BEGIN ICANN DOMAINS===';

    /**
     * @param  ClientInterface|null  $obClient  optional test-injected Guzzle client
     */
    public function __construct(private readonly ?ClientInterface $obClient = null)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sBundlePath = base_path(
            'plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat'
        );
        $sTmpPath = $sBundlePath.'.tmp';

        $obClient = $this->obClient ?? new Client(['timeout' => 10]);

        try {
            $obResponse = $obClient->request('GET', self::UPSTREAM_URL);
            $sBody = (string) $obResponse->getBody();
        } catch (Throwable $obException) {
            // log-and-rethrow disposition: surface the upstream failure to the
            // operator-facing CLI + return non-zero so artisan exit-code-driven
            // monitors can detect refresh failures.
            $this->error('metapixel: PSL fetch failed: '.$obException->getMessage());

            return self::FAILURE;
        }

        if ($sBody === '' || ! str_contains($sBody, self::SENTINEL)) {
            $this->error('metapixel: downloaded PSL failed sentinel validation');

            return self::FAILURE;
        }

        if (file_put_contents($sTmpPath, $sBody) === false) {
            $this->error('metapixel: failed to write tmp PSL file');

            return self::FAILURE;
        }

        if (! rename($sTmpPath, $sBundlePath)) {
            // Atomic-rename failed; clean up the half-written tmp file so a
            // future retry does not see a stale .tmp lingering.
            @unlink($sTmpPath);
            $this->error('metapixel: atomic rename failed');

            return self::FAILURE;
        }

        $sCacheDir = storage_path('app/metapixel/psl');
        if (is_dir($sCacheDir)) {
            File::cleanDirectory($sCacheDir);
        }

        $this->info('metapixel: PSL refreshed ('.strlen($sBody).' bytes)');

        return self::SUCCESS;
    }
}
