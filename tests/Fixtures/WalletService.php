<?php

declare(strict_types=1);

namespace App\Services;

class WalletService
{
    public function withdraw(int $userId, int $amount): void
    {
        $user = User::find($userId);

        $user->balance = $user->balance - $amount;

        $user->save();
    }
}
