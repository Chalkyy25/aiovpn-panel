<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class VpnServerEdit extends Component
{
    public function render()
    {
        return view('livewire.pages.admin.vpn-server-edit');
    }
}
