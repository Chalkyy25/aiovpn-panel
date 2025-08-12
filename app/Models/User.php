<?php
namespace App/Model;
// app/Models/User.php
use App\Models\CreditTransaction;
use Illuminate\Support\Facades\DB;

class User extends Model
{
public function creditTransactions()
{
    return $this->hasMany(CreditTransaction::class);
}

public function hasCredits(int $amount): bool
{
    return $amount >= 0 && $this->credits >= $amount;
}

public function addCredits(int $amount, ?string $reason = null, array $meta = []): void
{
    if ($amount <= 0) {
        throw new \InvalidArgumentException('Amount must be positive');
    }

    DB::transaction(function () use ($amount, $reason, $meta) {
        // increment balance
        $this->increment('credits', $amount);

        // log transaction
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
        throw new \InvalidArgumentException('Amount must be positive');
    }

    DB::transaction(function () use ($amount, $reason, $meta) {
        // atomic guard against race conditions
        $affected = static::whereKey($this->id)
            ->where('credits', '>=', $amount)
            ->decrement('credits', $amount);

        if ($affected === 0) {
            throw new \RuntimeException('Not enough credits');
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