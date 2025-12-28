<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-semibold mb-4 text-[var(--aio-ink)]">Manage Reseller Credits</h1>

    @if (session('ok'))
        <div class="mb-4 rounded border border-green-700 bg-green-900/40 text-green-100 px-4 py-2">
            {{ session('ok') }}
        </div>
    @endif

    @error('amount')
        <div class="mb-4 rounded border border-red-700 bg-red-900/40 text-red-100 px-4 py-2">
            {{ $message }}
        </div>
    @enderror

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Reseller list --}}
        <div class="lg:col-span-2 aio-card">
            <div class="aio-card-header">
                <input
                    type="text"
                    wire:model.debounce.300ms="search"
                    placeholder="Search resellers by name or email…"
                    class="form-input w-full"
                >
            </div>

            <div class="overflow-x-auto">
                <table class="aio-table min-w-full">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-600">
                        <tr>
                            <th class="px-4 py-2">Reseller</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Credits</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($resellers as $r)
                            <tr class="{{ $selectedUserId === $r->id ? 'bg-[var(--aio-accent)]/10' : '' }}">
                                <td class="px-4 py-2 font-medium text-[var(--aio-ink)]">{{ $r->name }}</td>
                                <td class="px-4 py-2 text-[var(--aio-sub)]">{{ $r->email }}</td>
                                <td class="px-4 py-2 font-mono text-[var(--aio-ink)]">{{ $r->credits }}</td>
                                <td class="px-4 py-2 text-right">
                                    <button wire:click="selectUser({{ $r->id }})"
                                            class="px-3 py-1 rounded border text-sm {{ $selectedUserId === $r->id ? 'border-[var(--aio-accent)] text-[var(--aio-accent)] bg-[var(--aio-accent)]/10' : 'border-[var(--aio-border)] text-[var(--aio-ink)]' }}">
                                        {{ $selectedUserId === $r->id ? 'Selected' : 'Select' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-[var(--aio-sub)]">No resellers found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="aio-card-footer">
                {{ $resellers->links() }}
            </div>
        </div>

        {{-- Credit form --}}
        <div class="aio-card">
            <div class="aio-card-header">
                <h2 class="font-semibold text-[var(--aio-ink)]">Adjust Credits</h2>
            </div>

            <div class="aio-card-body space-y-4">
                <div>
                    <label class="block text-sm text-[var(--aio-sub)] mb-1">Selected reseller</label>
                    <input class="form-input w-full"
                           value="{{ optional($resellers->firstWhere('id', $selectedUserId))->name ?? '—' }}"
                           readonly>
                    @error('selectedUserId') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-[var(--aio-sub)] mb-1">Amount</label>
                        <input type="number" min="1" wire:model.defer="amount"
                               class="form-input w-full">
                        @error('amount') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-[var(--aio-sub)] mb-1">Mode</label>
                        <select wire:model="mode" class="form-select w-full">
                            <option value="add">Add</option>
                            <option value="deduct">Deduct</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm text-[var(--aio-sub)] mb-1">Reason (optional)</label>
                    <input type="text" wire:model.defer="reason" class="form-input w-full" placeholder="e.g. Monthly top-up, chargeback, manual correction…">
                </div>

                <x-button
                    type="button"
                    wire:click="submit"
                    variant="primary"
                    class="w-full"
                    :disabled="!$selectedUserId || !$amount">
                    Apply
                </x-button>
            </div>
        </div>
    </div>
</div>
