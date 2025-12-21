<div class="p-4">
    <h2 class="text-xl font-semibold mb-4 text-[var(--aio-ink)]">VPN Test Users</h2>

    <div class="overflow-x-auto">
        <div class="aio-card overflow-hidden">
            <table class="aio-table min-w-full">
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left">Username</th>
                        <th class="px-4 py-2 text-left">Server</th>
                        <th class="px-4 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                    <tr>
                        <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $user->username }}</td>
                        <td class="px-4 py-2 text-[var(--aio-sub)]">{{ $user->vpnServer->name ?? 'N/A' }}</td>
                        <td class="px-4 py-2 space-x-2">
                            <x-button wire:click="generateOvpn({{ $user->id }})" variant="primary" size="sm">Download</x-button>
                            <x-button wire:click="deleteUser({{ $user->id }})" variant="danger" size="sm">Delete</x-button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
