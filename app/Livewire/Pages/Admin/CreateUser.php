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
    public $name = '';
    public $email = '';
    public $password = '';
    public $role = '';

    public function save()
    {
        $this->validate([
            'name' => 'required',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role' => 'required|in:admin,reseller,client',
        ]);

        User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => bcrypt($this->password),
            'role' => $this->role,
        ]);

        // Redirect to the manage users page (adjust route as needed)
        return redirect()->route('admin.users.index');
    }

    public function render()
    {
        return view('livewire.pages.admin.create-user');
    }
}
