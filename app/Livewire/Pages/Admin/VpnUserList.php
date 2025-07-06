<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\VpnUser;

class VpnUserList extends Component
{
    use WithPagination;

    public $search = '';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $users = VpnUser::with('vpnServer')
            ->when($this->search, fn($q) =>
                $q->where('username', 'like', '%'.$this->search.'%')
            )
            ->orderBy('id', 'desc')
            ->paginate(20);

        return view('livewire.pages.admin.vpn-user-list', compact('users'))
            ->layout('layouts.app');
    }
}
