<?php

namespace App\Livewire\Pages\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ResellerList extends Component
{
    use WithPagination;

    public string $search = '';
    public string $sortBy = 'name';
    public string $sortDir = 'asc';
    public int $perPage = 15;

    /** Sort helper */
    public function sort(string $field): void
    {
        if ($this->sortBy === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // Subquery: count vpn_users where client_id belongs to a client created by this reseller
        $linesCount = DB::raw("
            (
              SELECT COUNT(*)
              FROM vpn_users vu
              WHERE vu.client_id IN (
                 SELECT u2.id
                 FROM users u2
                 WHERE u2.created_by = users.id
                   AND u2.role = 'client'
              )
            ) as lines_count
        ");

        $query = User::query()
            ->where('role', 'reseller')
            ->select('users.*', $linesCount)
            ->when($this->search !== '', function ($q) {
                $s = '%'.$this->search.'%';
                $q->where(function ($q2) use ($s) {
                    $q2->where('name', 'like', $s)
                       ->orWhere('email', 'like', $s)
                       ->orWhere('id', (int) trim($this->search));
                });
            });

        // Safe sort fields map
        $sortable = [
            'name'          => 'name',
            'email'         => 'email',
            'credits'       => 'credits',
            'lines_count'   => 'lines_count',
            'last_login_at' => 'last_login_at',
            'is_active'     => 'is_active',
        ];
        $column = $sortable[$this->sortBy] ?? 'name';

        $resellers = $query
            ->orderBy($column, $this->sortDir)
            ->paginate($this->perPage);

        return view('livewire.pages.admin.reseller-list', [
            'resellers' => $resellers,
        ]);
    }
}