<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Feature;

use Nayvo\LaravelRaceGuard\LaravelRaceGuardServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RaceCheckCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelRaceGuardServiceProvider::class];
    }

    private function fixture(string $name): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }

    #[Test]
    public function it_reports_a_race_condition_in_an_unsafe_file_and_fails(): void
    {
        $this->artisan('race:check', ['--path' => [$this->fixture('WalletService.php')]])
            ->expectsOutputToContain('Potential read-modify-write race condition.')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_reports_a_clean_result_for_a_safe_file_and_passes(): void
    {
        $this->artisan('race:check', ['--path' => [$this->fixture('SafeWalletService.php')]])
            ->expectsOutputToContain('No potential race conditions found.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_emit_json(): void
    {
        $this->artisan('race:check', [
            '--path' => [$this->fixture('WalletService.php')],
            '--format' => 'json',
        ])->assertExitCode(1);
    }

    #[Test]
    public function it_flags_a_cross_statement_balance_race_and_fails(): void
    {
        $this->artisan('race:check', ['--path' => [$this->fixture('CoinConversionService.php')]])
            ->expectsOutputToContain('Potential check-then-act race on a balance or inventory value.')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_category_filter_narrows_findings(): void
    {
        // The balance race in the fixture is in the "inventory" category.
        $this->artisan('race:check', [
            '--path' => [$this->fixture('CoinConversionService.php')],
            '--category' => ['inventory'],
        ])->assertExitCode(1);

        $this->artisan('race:check', [
            '--path' => [$this->fixture('CoinConversionService.php')],
            '--category' => ['queue'],
        ])
            ->expectsOutputToContain('No potential race conditions found.')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_merges_its_default_configuration(): void
    {
        $this->assertSame('medium', config('race-guard.minimum_severity'));
        $this->assertIsArray(config('race-guard.rules'));
    }
}
