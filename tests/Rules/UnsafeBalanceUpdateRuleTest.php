<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Rules;

use Nayvo\LaravelRaceGuard\Rules\UnsafeBalanceUpdateRule;
use Nayvo\LaravelRaceGuard\Support\Severity;
use Nayvo\LaravelRaceGuard\Tests\AnalyzerTestCase;
use PHPUnit\Framework\Attributes\Test;

final class UnsafeBalanceUpdateRuleTest extends AnalyzerTestCase
{
    #[Test]
    public function it_flags_a_sufficiency_check_followed_by_an_unlocked_decrement(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $playerCoin = PlayerCoin::where('player_id', $id)->first();
            if (! $playerCoin || (int) $playerCoin->coins < $coins) {
                return;
            }
            $conversion = CoinMoneyConversion::create(['coins' => $coins]);
            $playerCoin->decrement('coins', $coins);
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertCount(1, $findings);
        $this->assertSame('unsafe_balance_update', $findings[0]->rule);
        $this->assertSame(Severity::HIGH, $findings[0]->severity);
    }

    #[Test]
    public function it_flags_a_threshold_check_followed_by_an_increment(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $account = Account::find($id);
            if ($account->used >= $limit) {
                return;
            }
            $account->increment('used', 1);
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function it_is_silent_when_the_row_is_locked_for_update(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $playerCoin = PlayerCoin::where('player_id', $id)->lockForUpdate()->first();
            if (! $playerCoin || $playerCoin->coins < $coins) {
                return;
            }
            $playerCoin->decrement('coins', $coins);
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_is_silent_for_an_atomic_conditional_decrement(): void
    {
        // The check and the debit are one statement — the safe fix.
        $findings = $this->analyze(
            <<<'PHP'
            $debited = PlayerCoin::where('player_id', $id)
                ->where('coins', '>=', $coins)
                ->decrement('coins', $coins);
            if ($debited === 0) {
                return;
            }
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_when_the_changed_attribute_differs_from_the_checked_one(): void
    {
        $findings = $this->analyze(
            <<<'PHP'
            $wallet = Wallet::find($id);
            if ($wallet->coins < $coins) {
                return;
            }
            $wallet->decrement('points', $coins);
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_threshold_check_on_a_non_model_variable(): void
    {
        // $cart is never fetched from the database, so this is not a balance race.
        $findings = $this->analyze(
            <<<'PHP'
            if ($cart->items < $limit) {
                return;
            }
            $cart->decrement('items', 1);
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertSame([], $findings);
    }

    #[Test]
    public function it_does_not_flag_a_decrement_without_any_prior_check(): void
    {
        // A bare atomic decrement is safe against lost updates on its own.
        $findings = $this->analyze(
            <<<'PHP'
            $wallet = Wallet::find($id);
            $wallet->decrement('coins', $coins);
            PHP,
            new UnsafeBalanceUpdateRule,
        );

        $this->assertSame([], $findings);
    }
}
