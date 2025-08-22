<?php

namespace App\Livewire\Pages\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Component;

class CreateReseller extends Component
{
    public $name;
    public $email;
    public $password;
    public $credits = 0;
    public $is_active = true;

    protected $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email:rfc,dns|unique:users,email',
        'password' => 'required|string|min:8',
        'credits' => 'nullable|integer|min:0',
        'is_active' => 'boolean',
    ];

    public function mount()
    {
        // Auto-generate a random password
        $this->password = Str::random(12);
    }

    public function save()
    {
        $this->validate();

        $reseller = User::create([
            'name'       => $this->name,
            'email'      => $this->email,
            'password'   => Hash::make($this->password),
            'role'       => 'reseller',
            'credits'    => $this->credits,
            'is_active'  => $this->is_active,
            'created_by' => auth()->id(),
        ]);

        session()->flash('message', "Reseller {$reseller->name} created successfully with password: {$this->password}");

        return redirect()->route('admin.resellers.index');
    }

    public function render()
    {
        return view('livewire.pages.admin.create-reseller');
    }
}