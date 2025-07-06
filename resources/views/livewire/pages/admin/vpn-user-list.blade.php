<div class="p-6">
    <h2 class="text-2xl font-semibold mb-4">VPN Users</h2>

    <input
        type="text"
        wire:model.debounce.300ms="search"
        placeholder="Search by username..."
        class="border rounded px-3 py-2 mb-4 w-full"
    />

    <table class="min-w-full bg-white">
        <thead>
            <tr>
                <th class="px-4 py-2 border">ID</th>
                <th class="px-4 py-2 border">Username</th>
                <th class="px-4 py-2 border">Password</th>
                <th class="px-4 py-2 border">Server</th>
                <th class="px-4 py-2 border">Created</th>
                <th class="px-4 py-2 border">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($users as $user)
            <tr>
                <td class="px-4 py-2 border">{{ $user->id }}</td>
                <td class="px-4 py-2 border">{{ $user->username }}</td>
                <td class="px-4 py-2 border">********</td>
                <td class="px-4 py-2 border">{{ $user->vpnServer->name ?? 'N/A' }}</td>
                <td class="px-4 py-2 border">{{ $user->created_at->diffForHumans() }}</td>
                <td class="px-4 py-2 border space-x-2">
                    <button class="bg-blue-500 text-white px-3 py-1 rounded">Download</button>
                    <button class="bg-red-500 text-white px-3 py-1 rounded">Delete</button>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-4 py-2 border text-center">No VPN users found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
