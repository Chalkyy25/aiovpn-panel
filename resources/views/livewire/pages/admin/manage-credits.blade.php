<div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-semibold mb-4">Manage Reseller Credits</h1>

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
        <div class="lg:col-span-2 bg-white rounded shadow divide-y">
            <div class="p-4">
                <input
                    type="text"
                    wire:model.debounce.300ms="search"
                    placeholder="Search resellers by name or email…"
                    class="w-full border rounded px-3 py-2"
                >
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-600">
                        <tr>
                            <th class="px-4 py-2">Reseller</th>
                            <th class="px-4 py-2">Email</th>
                            <th class="px-4 py-2">Credits</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($resellers as $r)
                            <tr class="{{ $selectedUserId === $r->id ? 'bg-blue-50' : '' }}">
                                <td class="px-4 py-2 font-medium">{{ $r->name }}</td>
                                <td class="px-4 py-2 text-gray-600">{{ $r->email }}</td>
                                <td class="px-4 py-2 font-mono">{{ $r->credits }}</td>
                                <td class="px-4 py-2 text-right">
                                    <button wire:click="selectUser({{ $r->id }})"
                                            class="px-3 py-1 rounded border text-sm {{ $selectedUserId === $r->id ? 'border-blue-500 text-blue-700' : 'border-gray-300' }}">
                                        {{ $selectedUserId === $r->id ? 'Selected' : 'Select' }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">No resellers found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-4">
                {{ $resellers->links() }}
            </div>
        </div>

        {{-- Credit form --}}
        <div class="bg-white rounded shadow p-5">
            <h2 class="font-semibold mb-3">Adjust Credits</h2>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1">Selected reseller</label>
                    <input class="w-full border rounded px-3 py-2 bg-gray-50"
                           value="{{ optional($resellers->firstWhere('id', $selectedUserId))->name ?? '—' }}"
                           readonly>
                    @error('selectedUserId') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Amount</label>
                        <input type="number" min="1" wire:model.defer="amount"
                               class="w-full border rounded px-3 py-2">
                        @error('amount') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm text-gray-700 mb-1">Mode</label>
                        <select wire:model="mode" class="w-full border rounded px-3 py-2">
                            <option value="add">Add</option>
                            <option value="deduct">Deduct</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm text-gray-700 mb-1">Reason (optional)</label>
                    <input type="text" wire:model.defer="reason" class="w-full border rounded px-3 py-2" placeholder="e.g. Monthly top-up, chargeback, manual correction…">
                </div>

                <button wire:click="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded px-4 py-2 font-medium disabled:opacity-50"
                        @disabled(!$selectedUserId || !$amount)>
                    Apply
                </button>
            </div>
        </div>
    </div>
</div>