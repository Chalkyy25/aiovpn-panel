<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isReseller(): bool { return $this->role === 'reseller'; }


    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    public function hasCredits(int $amount): bool
    {
        return $amount >= 0 && (int) $this->credits >= $amount;
    }

    public function addCredits(int $amount, ?string $reason = null, array $meta = []): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        DB::transaction(function () use ($amount, $reason, $meta) {
            $this->increment('credits', $amount);

            $this->creditTransactions()->create([
                'change' => +$amount,
                'reason' => $reason,
                'meta'   => $meta ?: null,
            ]);
        });

        $this->refresh();
    }

    public function deductCredits(int $amount, ?string $reason = null, array $meta = []): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        DB::transaction(function () use ($amount, $reason, $meta) {
            $affected = static::whereKey($this->getKey())
                ->where('credits', '>=', $amount)
                ->decrement('credits', $amount);

            if ($affected === 0) {
                throw new \RuntimeException('Not enough credits.');
            }

            $this->creditTransactions()->create([
                'change' => -$amount,
                'reason' => $reason,
                'meta'   => $meta ?: null,
            ]);
        });

        $this->refresh();
    }
}