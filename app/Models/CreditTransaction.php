<?php

// app/Models/CreditTransaction.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditTransaction extends Model
{
    protected $fillable = ['user_id','change','reason','meta'];
    protected $casts = ['meta' => 'array'];

    public function user(){ return $this->belongsTo(User::class); }
}