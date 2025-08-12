<?php

// app/Models/User.php
public function creditTransactions() { return $this->hasMany(\App\Models\CreditTransaction::class); }

public function hasCredits(int $amount): bool { return $this->credits >= $amount; }

public function addCredits(int $amount, string $reason = null, array $meta = []): void
{
    $this->increment('credits', $amount);
    \App\Models\CreditTransaction::create([
        'user_id'=>$this->id,'change'=>$amount,'reason'=>$reason,'meta'=>$meta ?: null,
    ]);
}

public function deductCredits(int $amount, string $reason = null, array $meta = []): void
{
    if ($amount < 0) throw new \InvalidArgumentException('amount must be positive');
    $ok = static::where('id',$this->id)->where('credits','>=',$amount)->decrement('credits',$amount);
    if ($ok === 0) throw new \RuntimeException('Not enough credits');
    \App\Models\CreditTransaction::create([
        'user_id'=>$this->id,'change'=>-$amount,'reason'=>$reason,'meta'=>$meta ?: null,
    ]);
    $this->refresh();
}