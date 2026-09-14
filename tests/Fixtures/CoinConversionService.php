<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Trimmed from a real coin->money conversion service. The sufficiency check on
 * $playerCoin->coins and the later $playerCoin->decrement('coins', ...) are
 * separate, unlocked operations — the check-then-act balance race.
 */
class CoinConversionService
{
    public function convert($player, int $coins)
    {
        if ($coins < 1) {
            ApiResponse::error(1324);
        }

        $rule = CoinToMoneyRule::where('currency', $player->currency)->first();
        if (! $rule) {
            ApiResponse::error(1320);
        }

        $playerCoin = PlayerCoin::where('player_id', $player->id)->first();
        if (! $playerCoin || (int) $playerCoin->coins < $coins) {
            ApiResponse::error(1323);
        }

        $conversion = CoinMoneyConversion::create([
            'player_id' => $player->id,
            'coins' => $coins,
            'status' => 'pending',
        ]);

        $playerCoin->decrement('coins', $coins);

        return $conversion;
    }
}
