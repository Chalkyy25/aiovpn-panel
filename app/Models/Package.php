<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    protected $fillable = ['name', 'price_credits', 'max_connections'];

    // (optional) if you ever cast/validate later
    protected $casts = [
        'price_credits'  => 'int',
        'max_connections'=> 'int',
    ];

    // expose a friendly label for 0 = Unlimited
    public function getMaxConnectionsTextAttribute(): string
    {
        return ($this->max_connections === 0) ? 'Unlimited' : (string) $this->max_connections;
    }

    // handy helpers (optional)
    public function getLabelAttribute(): string
    {
        return sprintf(
            '%s — %d credits (max %s conn)',
            $this->name,
            $this->price_credits,
            $this->max_connections_text
        );
    }

    public function getIsUnlimitedAttribute(): bool
    {
        return $this->max_connections === 0;
    }
}