<?php

// app/Models/DeployKey.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeployKey extends Model
{
    protected $fillable = ['name','private_path','public_key','is_active'];

    protected $casts = ['is_active' => 'bool'];

    public function scopeActive($q) { return $q->where('is_active', true); }

    public function privateAbsolutePath(): string
    {
        $path = $this->private_path;
        // allow absolute paths; otherwise treat as storage/app/ssh_keys/<file>
        return str_starts_with($path, '/') ? $path : storage_path('app/ssh_keys/'.$path);
    }
}
