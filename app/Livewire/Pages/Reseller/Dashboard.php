<?php

namespace App\Livewire\Pages\Reseller;

use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $user = auth()->user();

        return view('livewire.pages.reseller.dashboard', [
            'credits'         => $user->credits,
            'recentTransactions' => $user->creditTransactions()->latest()->limit(5)->get(),
            // You can add quick stats (lines count, active lines, etc.)
        ])->layout('layouts.app');
    }
}