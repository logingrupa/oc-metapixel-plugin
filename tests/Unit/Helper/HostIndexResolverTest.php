<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Logingrupa\Metapixel\Classes\Adapter\AdapterRegistry;
use Logingrupa\Metapixel\Classes\Helper\HostIndexResolver;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Wave 0 RED — fails until plan 04-02 production code ships.
 *
 * HOST-02 / HOST-04 / HOST-05 / HOST-06 / D-10. Pure unit-style coverage of
 * the PSL-wrapped resolver: subdomain-index derivation across multi-TLD +
 * IDN inputs, null on unresolvable / unknown-TLD inputs, memoization smoke,
 * and the D-10 stale-PSL operator-feedback one-shot Log::warning latch.
 */
final class HostIndexResolverTest extends MetapixelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(AdapterRegistry::class);
    }

    private function fixturePath(): string
    {
        return __DIR__.'/../../fixtures/data/test_psl.dat';
    }

    /** @return iterable<string, array{0: string, 1: int}> */
    public static function provideKnownHosts(): iterable
    {
        return [
            'apex example.com' => ['example.com', 1],
            'www example.com' => ['www.example.com', 2],
            'apex example.co.uk' => ['example.co.uk', 1],
            'www example.co.uk' => ['www.example.co.uk', 2],
            'shop.example.com.br' => ['shop.example.com.br', 2],
            'a.b.example.com' => ['a.b.example.com', 3],
            'IDN xn--bcher-kva.example' => ['xn--bcher-kva.example', 1],
        ];
    }

    /**
     * Underscores are intentionally NOT tested here — Pdp accepts them at
     * sub-suffix label positions (PSL strictness applies to the suffix; not
     * the label set). The Settings::beforeSave partition layer enforces the
     * /^[a-z0-9.-]+$/ charset gate BEFORE calling the resolver, so the
     * resolver does not need to reject underscores itself.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function provideUnresolvableHosts(): iterable
    {
        return [
            'empty string' => [''],
            'IPv4 literal' => ['127.0.0.1'],
            'localhost' => ['localhost'],
            'unknown TLD' => ['host-with-unknown-tld.fakeylock'],
        ];
    }

    #[DataProvider('provideKnownHosts')]
    public function test_resolve_returns_expected_subdomain_index(string $sHost, int $iExpected): void
    {
        $obResolver = new HostIndexResolver($this->fixturePath());

        $this->assertSame($iExpected, $obResolver->resolve($sHost), "host: {$sHost}");
    }

    #[DataProvider('provideUnresolvableHosts')]
    public function test_resolve_returns_null_for_unresolvable_hosts(string $sHost): void
    {
        $obResolver = new HostIndexResolver($this->fixturePath());

        $this->assertNull($obResolver->resolve($sHost), "host: {$sHost}");
    }

    public function test_resolve_memoizes_repeated_lookups(): void
    {
        $obResolver = new HostIndexResolver($this->fixturePath());

        $iFirst = $obResolver->resolve('example.co.uk');
        $iSecond = $obResolver->resolve('example.co.uk');

        $this->assertSame(1, $iFirst);
        $this->assertSame($iFirst, $iSecond, 'memoized result must equal first call');
    }

    public function test_resolve_trims_and_lowercases_input(): void
    {
        $obResolver = new HostIndexResolver($this->fixturePath());

        $this->assertSame(2, $obResolver->resolve('  WWW.EXAMPLE.CO.UK  '));
    }

    public function test_resolve_returns_null_when_psl_path_is_unreadable(): void
    {
        $obResolver = new HostIndexResolver('/nonexistent/path/to/psl.dat');

        // Resolver must NEVER throw — middleware-callable contract (HOST-04).
        // Unreadable PSL → Rules::fromPath throws → caught → null.
        $this->assertNull($obResolver->resolve('example.com'));
    }

    public function test_stale_psl_emits_log_warning_once_when_filemtime_older_than_180_days(): void
    {
        $sTmpPath = $this->cloneFixtureToTmp();
        // Pin filemtime to 200 days in the past — past the 180-day operator-feedback threshold.
        $this->assertTrue(
            touch($sTmpPath, Carbon::now()->subDays(200)->getTimestamp()),
            'touch() must succeed when pinning fixture filemtime'
        );

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($sMessage): bool => is_string($sMessage) && str_starts_with($sMessage, 'PSL snapshot is '));

        $obResolver = new HostIndexResolver($sTmpPath);

        // Two successive resolve() calls — the D-10 one-shot latch must
        // keep the Log::warning to exactly one emission per resolver instance.
        $obResolver->resolve('example.co.uk');
        $obResolver->resolve('www.example.co.uk');

        @unlink($sTmpPath);
    }

    public function test_fresh_psl_emits_no_warning_when_filemtime_within_180_days(): void
    {
        $sTmpPath = $this->cloneFixtureToTmp();
        $this->assertTrue(
            touch($sTmpPath, Carbon::now()->subDays(30)->getTimestamp()),
            'touch() must succeed when pinning fresh-PSL fixture filemtime'
        );

        Log::shouldReceive('warning')->never();

        $obResolver = new HostIndexResolver($sTmpPath);
        $obResolver->resolve('example.com');

        @unlink($sTmpPath);
    }

    /**
     * Copy the hermetic PSL fixture to a writable tmp path so we can pin its
     * filemtime without mutating the committed fixture across tests.
     */
    private function cloneFixtureToTmp(): string
    {
        $sTmpPath = tempnam(sys_get_temp_dir(), 'metapixel-psl-');
        $this->assertIsString($sTmpPath);
        $this->assertNotFalse(copy($this->fixturePath(), $sTmpPath));

        return $sTmpPath;
    }
}
