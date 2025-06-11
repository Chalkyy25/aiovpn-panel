<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

#[Layout('layouts.app')]
class CreateUser extends Component
{
    public $name, $email, $password, $role;

    public function save()
    {
        $this->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:6',
            'role' => 'required|in:admin,reseller,client',
        ]);

        User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'role' => $this->role,
            'created_by' => Auth::id(),
            'is_active' => true,
        ]);

        session()->flash('message', '✅ User created successfully.');
        $this->reset();
    }

    public function render()
    {
        return view('livewire.pages.admin.create-user');
    }
}
