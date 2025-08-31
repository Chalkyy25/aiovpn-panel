<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable;

    /**
     * Mass-assignable columns.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',        // 'admin' | 'reseller' | 'client'
        'credits',     // int
        'is_active',   // bool
        'created_by',  // parent (e.g., admin -> reseller, reseller -> client)
    ];

    /**
     * Hidden on array/JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Casts.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active'         => 'boolean',
        'credits'           => 'integer',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    /* ---------------------------------------------------------
     | Roles / helpers
     --------------------------------------------------------- */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isReseller(): bool
    {
        return $this->role === 'reseller';
    }

    public function isClient(): bool
    {
        return $this->role === 'client';
    }

    /* ---------------------------------------------------------
     | Relationships
     --------------------------------------------------------- */

    /**
     * Credit ledger entries for this user.
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * (Optional) Children the user created (useful for reseller -> clients).
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(self::class, 'created_by');
    }

    /* ---------------------------------------------------------
     | Credits API
     --------------------------------------------------------- */

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
    // 🚫 Negative protection
    if ($amount < 0) {
        throw new \InvalidArgumentException('Amount cannot be negative.');
    }

    // ✅ Admins bypass credit deductions
    if ($this->hasRole('admin')) {
        $this->creditTransactions()->create([
            'change' => 0,
            'reason' => $reason ?? 'Admin bypass (no credits deducted)',
            'meta'   => $meta ?: null,
        ]);

        return;
    }

    DB::transaction(function () use ($amount, $reason, $meta) {
        if ($amount === 0) {
            // Non-admins shouldn’t normally have 0-credit packages,
            // but we’ll log it for traceability
            $this->creditTransactions()->create([
                'change' => 0,
                'reason' => $reason ?? 'No charge',
                'meta'   => $meta ?: null,
            ]);
            return;
        }

        // Normal deduction path
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