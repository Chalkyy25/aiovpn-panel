<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class User extends Model
{
    // If your real User extends Authenticatable, change extends accordingly.

    /* ---------------------------------------------
     | Relationships
     | ---------------------------------------------*/
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /* ---------------------------------------------
     | Credits API
     | ---------------------------------------------*/
    public function hasCredits(int $amount): bool
    {
        return $amount >= 0 && (int) $this->credits >= $amount;
    }

    /**
     * Add credits and record a transaction.
     */
    public function addCredits(int $amount, ?string $reason = null, array $meta = []): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        DB::transaction(function () use ($amount, $reason, $meta) {
            // Increment balance
            $this->increment('credits', $amount);

            // Log transaction
            $this->creditTransactions()->create([
                'change' => +$amount,   // positive
                'reason' => $reason,
                'meta'   => $meta ?: null,
            ]);
        });

        $this->refresh();
    }

    /**
     * Deduct credits atomically and record a transaction.
     */
    public function deductCredits(int $amount, ?string $reason = null, array $meta = []): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        DB::transaction(function () use ($amount, $reason, $meta) {
            // Atomic guard against race conditions
            $affected = static::whereKey($this->getKey())
                ->where('credits', '>=', $amount)
                ->decrement('credits', $amount);

            if ($affected === 0) {
                throw new \RuntimeException('Not enough credits.');
            }

            // Log transaction
            $this->creditTransactions()->create([
                'change' => -$amount,   // negative
                'reason' => $reason,
                'meta'   => $meta ?: null,
            ]);
        });

        $this->refresh();
    }
}