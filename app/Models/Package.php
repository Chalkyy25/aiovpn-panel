<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_credits',
        'max_connections',
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

    // Friendly text for max connections
    public function getMaxConnectionsTextAttribute(): string
    {
        return $this->max_connections === 0 ? 'Unlimited' : (string) $this->max_connections;
    }

    // Badge-style helpers
    public function getIsUnlimitedAttribute(): bool
    {
        return $this->max_connections === 0;
    }

    public function getLabelAttribute(): string
    {
        return sprintf(
            '%s — %d credits, %s devices, %d month%s',
            $this->name,
            $this->price_credits,
            $this->max_connections_text,
            $this->duration_months,
            $this->duration_months > 1 ? 's' : ''
        );
    }
}