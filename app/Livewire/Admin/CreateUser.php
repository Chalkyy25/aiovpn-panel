<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
            'is_active' => true, // Default to active
            'is_verified' => true, // Default to verified
            'is_suspended' => false, // Default to not suspended
            'is_banned' => false, // Default to not banned
            'is_deleted' => false, // Default to not deleted
            'last_login_at' => now(), // Set to current time
            'last_login_ip' => request()->ip(), // Set to current IP
            'created_by' => auth()->id(), // Set creator ID
            'updated_by' => auth()->id(), // Set updater ID
            'created_at' => now(), // Set creation time
            'updated_at' => now(), // Set update time
        ]);

        session()->flash('message', '✅ User created successfully.');
        $this->reset();
    }
    public function render()
    {
        return view('livewire.admin.create-user');
    }
}
