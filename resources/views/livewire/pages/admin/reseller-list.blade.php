<div class="p-6 bg-white shadow rounded">
    <h2 class="text-xl font-semibold mb-4">Resellers</h2>

    <input type="text" wire:model.debounce.500ms="search"
           placeholder="Search resellers..."
           class="border rounded px-3 py-2 mb-4 w-full" />

    @if ($resellers->count())
        <table class="w-full border-collapse">
            <thead>
                <tr class="bg-gray-100">
                    <th class="p-2 text-left">Name</th>
                    <th class="p-2 text-left">Email</th>
                    <th class="p-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resellers as $reseller)
                    <tr class="border-b">
                        <td class="p-2">{{ $reseller->name }}</td>
                        <td class="p-2">{{ $reseller->email }}</td>
                        <td class="p-2">
                            {{ $reseller->is_active ? 'Active' : 'Inactive' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $resellers->links() }}
    @else
        <p>No resellers found.</p>
    @endif
</div>