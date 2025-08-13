<?php

namespace App\Livewire\Pages\Admin;

use App\Models\User;
use App\Models\VpnUser;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

class ResellerList extends Component
{
    use WithPagination;

    public string $search  = '';
    public string $sortBy  = 'created_at';   // credits|lines_count|last_login_at|name|email|created_at
    public string $sortDir = 'desc';         // asc|desc

    protected $queryString = ['search', 'sortBy', 'sortDir', 'page'];

    public function updatingSearch() { $this->resetPage(); }
    public function sort(string $column)
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function render()
    {
        // Subquery: all client IDs belonging to this reseller
        $clientIds = User::select('id')
            ->whereColumn('created_by', 'users.id')
            ->where('role', 'client');

        // Subquery: count of vpn users whose client_id is one of the reseller’s clients
        $linesCount = VpnUser::selectRaw('COUNT(*)')
            ->whereIn('client_id', $clientIds);

        $resellers = User::query()
            ->where('role', 'reseller')
            // add computed columns
            ->addSelect([
                'lines_count' => $linesCount,
            ])
            ->when($this->search, function (Builder $q) {
                $s = "%{$this->search}%";
                $q->where(function (Builder $qq) use ($s) {
                    $qq->where('name', 'like', $s)
                       ->orWhere('email', 'like', $s);
                });
            })
            // allow sorting by our computed fields + normal columns
            ->when(in_array($this->sortBy, ['credits', 'lines_count', 'last_login_at', 'name', 'email', 'created_at']), function (Builder $q) {
                $q->orderBy($this->sortBy, $this->sortDir);
            }, fn ($q) => $q->latest())
            ->paginate(12);

        return view('livewire.pages.admin.reseller-list', [
            'resellers' => $resellers,
        ]);
    }
}