<?php

namespace App\Livewire\Admin;

use Livewire\Component;  


class UserList extends Component
{
public function render()
{
    return view('livewire.admin.user-list', [
        'users' => \App\Models\User::latest()->get(),
    ]);
}

}