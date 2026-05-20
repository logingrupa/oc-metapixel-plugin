<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Console\RefreshPsl;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * Wave 0 RED — fails until plan 04-02 production code ships.
 *
 * HOST-03 / D-09 / D-11. metapixel:refresh-psl pulls upstream PSL via
 * Guzzle, validates the ICANN sentinel, atomic-renames into place, and
 * wipes the parsed-Rules cache directory under storage/app/metapixel/psl/.
 * Failure modes (network, sentinel-missing, empty body, write fail) MUST
 * leave the bundled file untouched and clean up any half-written tmp file.
 */
final class RefreshPslTest extends MetapixelTestCase
{
    private string $sBundlePath;

    private string $sBundleBackup;

    private string $sCacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);

        $this->sBundlePath = base_path(
            'plugins/logingrupa/metapixel/resources/data/public_suffix_list.dat'
        );
        $this->sBundleBackup = $this->sBundlePath.'.test-backup';

        if (! is_dir(dirname($this->sBundlePath))) {
            mkdir(dirname($this->sBundlePath), 0775, true);
        }

        // Backup any existing bundle so a failed refresh test cannot corrupt
        // the shipped file. The setUp seeds a deterministic baseline so tests
        // can assert "bundle unchanged on failure".
        if (is_file($this->sBundlePath)) {
            copy($this->sBundlePath, $this->sBundleBackup);
        }
        file_put_contents($this->sBundlePath, "// baseline test PSL\n");

        $this->sCacheDir = storage_path('app/metapixel/psl');
        if (! is_dir($this->sCacheDir)) {
            mkdir($this->sCacheDir, 0775, true);
        }
        file_put_contents($this->sCacheDir.'/cache.bin', 'stale');
    }

    protected function tearDown(): void
    {
        // Clean up half-written tmp files first (atomic-rename failure path).
        $sTmpPath = $this->sBundlePath.'.tmp';
        if (is_file($sTmpPath)) {
            @unlink($sTmpPath);
        }

        if (is_file($this->sBundleBackup)) {
            rename($this->sBundleBackup, $this->sBundlePath);
        } else {
            @unlink($this->sBundlePath);
        }

        if (is_dir($this->sCacheDir)) {
            File::cleanDirectory($this->sCacheDir);
        }

        parent::tearDown();
    }

    public function test_refresh_psl_writes_atomic_rename_on_valid_upstream(): void
    {
        $sValidBody = "// ===BEGIN ICANN DOMAINS===\n"
            .str_repeat("com\nco.uk\ncom.br\nexample\n", 64)
            ."// ===END ICANN DOMAINS===\n";

        $this->bindFakeClient([
            new Response(200, [], $sValidBody),
        ]);

        $iExit = Artisan::call('metapixel:refresh-psl');

        $this->assertSame(0, $iExit);
        $this->assertStringContainsString(
            '// ===BEGIN ICANN DOMAINS===',
            (string) file_get_contents($this->sBundlePath)
        );
        // Cache wiped — sentinel file removed.
        $this->assertFileDoesNotExist($this->sCacheDir.'/cache.bin');
    }

    public function test_refresh_psl_rejects_response_missing_sentinel(): void
    {
        $sBaselineHash = (string) md5_file($this->sBundlePath);

        $this->bindFakeClient([
            new Response(200, [], "no sentinel in this body\nstill no sentinel\n"),
        ]);

        $iExit = Artisan::call('metapixel:refresh-psl');

        $this->assertNotSame(0, $iExit);
        $this->assertSame(
            $sBaselineHash,
            (string) md5_file($this->sBundlePath),
            'bundle file MUST be unchanged on sentinel-validation failure'
        );
    }

    public function test_refresh_psl_rejects_empty_response(): void
    {
        $sBaselineHash = (string) md5_file($this->sBundlePath);

        $this->bindFakeClient([
            new Response(200, [], ''),
        ]);

        $iExit = Artisan::call('metapixel:refresh-psl');

        $this->assertNotSame(0, $iExit);
        $this->assertSame($sBaselineHash, (string) md5_file($this->sBundlePath));
    }

    public function test_refresh_psl_wipes_parsed_rules_cache_on_success(): void
    {
        $sValidBody = "// ===BEGIN ICANN DOMAINS===\n"
            .str_repeat("com\n", 64)
            ."// ===END ICANN DOMAINS===\n";

        // Seed multiple cache files so the directory-clean assertion is
        // non-trivial — the wipe MUST clear every file under the cache dir.
        file_put_contents($this->sCacheDir.'/a.bin', 'old');
        file_put_contents($this->sCacheDir.'/b.bin', 'older');

        $this->bindFakeClient([new Response(200, [], $sValidBody)]);

        $this->assertSame(0, Artisan::call('metapixel:refresh-psl'));

        $this->assertFileDoesNotExist($this->sCacheDir.'/a.bin');
        $this->assertFileDoesNotExist($this->sCacheDir.'/b.bin');
        $this->assertFileDoesNotExist($this->sCacheDir.'/cache.bin');
    }

    public function test_refresh_psl_handles_connect_exception(): void
    {
        $sBaselineHash = (string) md5_file($this->sBundlePath);

        $this->bindFakeClient([
            new ConnectException(
                'connection refused',
                new Request('GET', 'https://publicsuffix.org/list/public_suffix_list.dat'),
            ),
        ]);

        $iExit = Artisan::call('metapixel:refresh-psl');

        $this->assertNotSame(0, $iExit);
        $this->assertSame(
            $sBaselineHash,
            (string) md5_file($this->sBundlePath),
            'bundle file MUST be unchanged on ConnectException'
        );
        $this->assertFileDoesNotExist(
            $this->sBundlePath.'.tmp',
            'half-written tmp file MUST be cleaned up on fetch failure'
        );
    }

    /**
     * Bind a constructor-injected Guzzle client wired to the provided queue
     * of responses (or exceptions) so the command never touches the network.
     *
     * @param  list<Response|ConnectException>  $arQueue
     */
    private function bindFakeClient(array $arQueue): void
    {
        $obStack = HandlerStack::create(new MockHandler($arQueue));
        $arHistory = [];
        $obStack->push(Middleware::history($arHistory));
        $obFakeClient = new Client(['handler' => $obStack, 'timeout' => 10]);

        $this->app->bind(RefreshPsl::class, fn () => new RefreshPsl($obFakeClient));

        $obKernel = $this->app->make(Kernel::class);
        $obKernel->registerCommand($this->app->make(RefreshPsl::class));
    }
}
