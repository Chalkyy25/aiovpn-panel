<?php

namespace App\Livewire\Pages\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ManageCredits extends Component
{
    use WithPagination;

    public string $search = '';
    public ?int $selectedUserId = null;

    public ?int $amount = null;         // positive integer
    public ?string $reason = null;      // optional free text
    public string $mode = 'add';        // 'add' | 'deduct'

    public function mount(): void
    {
        abort_unless(Gate::allows('manage-credits'), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->resetValidation();
    }

    public function submit(): void
    {
        $this->validate([
            'selectedUserId' => 'required|exists:users,id',
            'amount'         => 'required|integer|min:1',
            'reason'         => 'nullable|string|max:255',
            'mode'           => 'required|in:add,deduct',
        ]);

        /** @var User $target */
        $target = User::findOrFail($this->selectedUserId);

        if ($this->mode === 'add') {
            $target->addCredits($this->amount, $this->reason ?: 'Manual top‑up', [
                'by' => auth()->id(),
            ]);
            session()->flash('ok', "Added {$this->amount} credits to {$target->name}.");
        } else {
            try {
                $target->deductCredits($this->amount, $this->reason ?: 'Manual deduction', [
                    'by' => auth()->id(),
                ]);
                session()->flash('ok', "Deducted {$this->amount} credits from {$target->name}.");
            } catch (\RuntimeException $e) {
                $this->addError('amount', 'Not enough credits to deduct.');
                return;
            }
        }

        // refresh selected user’s row
        $target->refresh();
        $this->dispatch('credits-updated'); // optional hook for UI
        $this->reset(['amount', 'reason']);
    }

    public function getResellersProperty()
    {
        // Adjust this filter to your roles setup:
        // assumes users.role = 'reseller'
        return User::query()
            ->where('role', 'reseller')
            ->when($this->search, fn ($q) =>
                $q->where(function ($q2) {
                    $q2->where('name', 'like', "%{$this->search}%")
                       ->orWhere('email', 'like', "%{$this->search}%");
                })
            )
            ->orderByDesc('credits')
            ->paginate(12);
    }

    public function render()
    {
        return view('livewire.pages.admin.manage-credits', [
            'resellers' => $this->resellers,
        ]);
    }
}