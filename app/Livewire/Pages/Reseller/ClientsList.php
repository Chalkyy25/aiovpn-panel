<?php

namespace App\Livewire\Pages\Reseller;

use App\Models\VpnUser;
use Livewire\Component;
use Livewire\WithPagination;

class ClientsList extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch() { $this->resetPage(); }

    public function render()
    {
        $resellerId = auth()->id();

        // Show VPN lines this reseller created (assuming you set client_id = reseller->id OR you track created_by)
        $lines = VpnUser::query()
            ->where('client_id', $resellerId) // if you’re using client_id to mean owner
            ->when($this->search, fn($q) => $q->where('username', 'like', "%{$this->search}%"))
            ->withCount(['activeConnections'])
            ->latest()
            ->paginate(20);

        return view('livewire.pages.reseller.clients-list', compact('lines'))
            ->layout('layouts.app');
    }
}