<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class VpnServer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ip',
        'protocol',
        'deployment_status',
        'deployment_log',
    ];

public function appendLog(string $line)
{
    $this->update([
        'deployment_log' => trim($this->deployment_log . "\n" . $line),
    ]);
}

}
