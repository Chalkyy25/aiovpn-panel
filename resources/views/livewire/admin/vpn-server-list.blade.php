<div class="p-6 bg-white shadow rounded-lg">
    <h2 class="text-lg font-semibold mb-4">VPN Servers</h2>

    @if ($adding)
        <form wire:submit="save" class="space-y-2 mb-6">
            <input wire:model.live="name" placeholder="Server Name" class="w-full border px-3 py-2 rounded" />
            <input wire:model.live="ip" placeholder="IP Address" class="w-full border px-3 py-2 rounded" />
            <select wire:model.live="protocol" class="w-full border px-3 py-2 rounded">
                <option value="OpenVPN">OpenVPN</option>
                <option value="WireGuard">WireGuard</option>
            </select>
            <button class="bg-green-500 text-white px-4 py-2 rounded">Save</button>
        </form>
    @else
        <button wire:click="$set('adding', true)" class="mb-4 text-blue-600 underline">➕ Add Server</button>
    @endif

    @if (session()->has('message'))
        <div class="text-green-600 mb-4">{{ session('message') }}</div>
    @endif

    <table class="w-full text-sm border">
        <thead class="bg-gray-100 text-xs uppercase">
            <tr>
                <th class="px-4 py-2">Name</th>
                <th class="px-4 py-2">IP</th>
                <th class="px-4 py-2">Protocol</th>
                <th class="px-4 py-2">Status</th>
                <th class="px-4 py-2">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($servers as $server)
                <tr class="border-t">
                    <td class="px-4 py-2">{{ $server->name }}</td>
                    <td class="px-4 py-2">{{ $server->ip }}</td>
                    <td class="px-4 py-2">{{ $server->protocol }}</td>
                    <td class="px-4 py-2">{{ $server->deployment_status }}</td>
                    <td class="px-4 py-2 space-x-2">
                        <button wire:click="confirmDelete({{ $server->id }})" class="text-red-500 underline">Delete</button>
                        @if ($confirmingDeleteId === $server->id)
                            <span class="text-sm text-gray-600">Confirm?
                                <button wire:click="deleteServer" class="text-red-600 underline ml-1">Yes</button>
                                <button wire:click="$set('confirmingDeleteId', null)" class="underline ml-1">Cancel</button>
                            </span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
