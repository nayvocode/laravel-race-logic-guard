<?php

declare(strict_types=1);

namespace App\Services;

class SafeWalletService
{
    public function withdraw(int $userId, int $amount): void
    {
        User::whereKey($userId)->decrement('balance', $amount);
    }
}
