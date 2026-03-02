<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, HasApiTokens, Notifiable;

    /**
     * Mass-assignable columns.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',        // admin | reseller | client
        'credits',     // int
        'is_active',   // bool
        'created_by',  // parent (admin -> reseller, reseller -> client)
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
     | Filament access
     --------------------------------------------------------- */

    public function canAccessPanel(Panel $panel): bool
    {
        // Hard stop: inactive users get nothing
        if (!$this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin'    => $this->role === 'admin',
            'reseller' => $this->role === 'reseller',
            default    => false,
        };
    }

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

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

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
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount cannot be negative.');
        }

        // Admin bypass: log it (optional) but do not deduct
        if ($this->isAdmin()) {
            $this->creditTransactions()->create([
                'change' => 0,
                'reason' => $reason ?? 'Admin bypass (no credits deducted)',
                'meta'   => $meta ?: null,
            ]);
            return;
        }

        DB::transaction(function () use ($amount, $reason, $meta) {
            if ($amount === 0) {
                $this->creditTransactions()->create([
                    'change' => 0,
                    'reason' => $reason ?? 'No charge',
                    'meta'   => $meta ?: null,
                ]);
                return;
            }

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