<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'name',
        'description',
        // credits PER MONTH (e.g. 1, 3, 6, 12)
        'price_credits',
        // 0 = Unlimited
        'max_connections',
        // total months this package grants (e.g. 12)
        'duration_months',
        'is_featured',
        'is_active',
    ];

    protected $casts = [
        'price_credits'   => 'integer',
        'max_connections' => 'integer',
        'duration_months' => 'integer',
        'is_featured'     => 'boolean',
        'is_active'       => 'boolean',
    ];

    // Include helpful virtuals when toArray()/JSON
    protected $appends = [
        'max_connections_text',
        'is_unlimited',
        'total_credits',
        'label',
    ];

    /* ----------------- Query Scopes ----------------- */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeFeatured(Builder $q): Builder
    {
        return $q->active()->where('is_featured', true);
    }

    /* ----------------- Accessors ----------------- */
    public function getMaxConnectionsTextAttribute(): string
    {
        return $this->max_connections === 0 ? 'Unlimited' : (string) $this->max_connections;
    }

    public function getIsUnlimitedAttribute(): bool
    {
        return $this->max_connections === 0;
    }

    public function getTotalCreditsAttribute(): int
    {
        // credits per month × months
        return (int) $this->price_credits * (int) $this->duration_months;
    }

    public function getLabelAttribute(): string
    {
        return sprintf(
            '%s — %d months • %s devices • %d cr/mo (total %d)',
            $this->name,
            $this->duration_months,
            $this->max_connections_text,
            $this->price_credits,
            $this->total_credits
        );
    }

    /* ----------------- Guards / Mutators (optional hardening) ----------------- */
    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $p->price_credits   = max(0, (int) $p->price_credits);
            $p->duration_months = max(1, (int) $p->duration_months);
            $p->max_connections = max(0, (int) $p->max_connections); // 0 = Unlimited
        });
    }
}