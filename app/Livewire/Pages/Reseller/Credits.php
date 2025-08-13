<?php

namespace App\Livewire\Pages\Reseller;

use Livewire\Component;

class Credits extends Component
{
    public function render()
    {
        $user = auth()->user();

        return view('livewire.pages.reseller.credits', [
            'balance'       => $user->credits,
            'transactions'  => $user->creditTransactions()->latest()->paginate(20),
        ])->layout('layouts.app');
    }
}