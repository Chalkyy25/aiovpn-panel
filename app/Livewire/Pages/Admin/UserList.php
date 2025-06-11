<?php

namespace App\Livewire\Pages\Admin;

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\User;

#[Layout('layouts.app')]
class UserList extends Component
{
    public $search = '';
    public $roleFilter = '';

    public $confirmingDeleteId = null;
    public $editingUser = null;
    public $editName, $editEmail, $editRole;

    public function toggleStatus($userId)
    {
        $user = User::find($userId);
        if ($user) {
            $user->is_active = !$user->is_active;
            $user->save();

            session()->flash('status-message', 'User status updated.');
        }
    }

    public function confirmDelete($userId)
    {
        $this->confirmingDeleteId = $userId;
    }

    public function deleteUser()
    {
        $user = User::find($this->confirmingDeleteId);
        if ($user) {
            $user->delete();
            session()->flash('status-message', 'User deleted.');
        }

        $this->confirmingDeleteId = null;
    }

    public function startEdit($userId)
    {
dd("Start edit clicked for user ID: $userId");
        $this->editingUser = User::find($userId);

        $this->editName = $this->editingUser->name;
        $this->editEmail = $this->editingUser->email;
        $this->editRole = $this->editingUser->role;
    }

    public function updateUser()
    {
        $this->validate([
            'editName' => 'required',
            'editEmail' => 'required|email|unique:users,email,' . $this->editingUser->id,
            'editRole' => 'required|in:admin,reseller,client',
        ]);

        $this->editingUser->update([
            'name' => $this->editName,
            'email' => $this->editEmail,
            'role' => $this->editRole,
        ]);

        session()->flash('status-message', 'User updated successfully.');
        $this->editingUser = null;
    }

    public function render()
    {
        $query = User::with('creator')->latest();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                  ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        if ($this->roleFilter) {
            $query->where('role', $this->roleFilter);
        }

        return view('livewire.pages.admin.user-list', [
            'users' => $query->get(),
        ]);
    }
}
