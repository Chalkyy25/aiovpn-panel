<div class="p-6">
    {{-- Flash message --}}
    @if (session()->has('status-message'))
        <div class="mb-4 px-4 py-2 bg-green-100 text-green-800 border border-green-300 rounded">
            {{ session('status-message') }}
        </div>
    @endif

    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold">🌐 VPN Servers</h2>
        <a href="{{ route('admin.servers.create') }}">
            <x-button>Add Server</x-button>
        </a>
    </div>

    @if($servers->isEmpty())
        <div class="bg-white p-6 rounded shadow text-center">
            <p class="text-gray-600">No VPN servers found.</p>
            <p class="mt-2 text-sm text-gray-500">Click "Add Server" above to get started.</p>
        </div>
    @else
        <div class="overflow-x-auto rounded">
            <table class="min-w-full text-sm border bg-white rounded shadow">
                <thead class="bg-gray-100 text-xs uppercase text-gray-600">
                <tr>
                    <th class="px-4 py-2">ID</th>
                    <th class="px-4 py-2">Name</th>
                    <th class="px-4 py-2">IP Address</th>
                    <th class="px-4 py-2">Protocol</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
                </thead>
                @php
                    $highlightId = request('highlight');
                @endphp
                <tbody>
                @foreach ($servers as $server)
                    <tr class="border-t hover:bg-gray-50 {{ $highlightId == $server->id ? 'bg-yellow-100 animate-pulse' : '' }}" wire:key="server-{{ $server->id }}">
                        <td class="px-4 py-2 text-gray-500">{{ $server->id }}</td>
                        <td class="px-4 py-2 font-medium text-gray-800">{{ $server->name }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $server->ip_address }}</td>
                        <td class="px-4 py-2">
                                <span class="inline-block px-2 py-1 text-xs bg-indigo-100 text-indigo-700 rounded">
                                    {{ $server->protocol }}
                                </span>
                        </td>
                        <td class="px-4 py-2">
                                <span class="text-sm {{ $server->status === 'online' ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $server->status === 'online' ? '✅ Online' : '❌ Offline' }}
                                </span>
                        </td>
                        <td class="px-4 py-2 text-right min-w-[300px]">
                            <div class="flex flex-wrap gap-2 justify-end">
                                <a href="{{ route('admin.servers.show', $server->id) }}">
                                    <x-button class="bg-blue-600 hover:bg-blue-700 text-white">🔍 View</x-button>
                                </a>
                                <a href="{{ route('admin.servers.edit', $server->id) }}">
                                    <x-button class="bg-yellow-500 hover:bg-yellow-600 text-white">✏️ Edit</x-button>
                                </a>
                                <x-button wire:click="syncServer({{ $server->id }})" class="bg-indigo-600 hover:bg-indigo-700 text-white">
                                    🔁 Sync Now
                                </x-button>
                                <x-button wire:click="deleteServer({{ $server->id }})" class="bg-red-600 hover:bg-red-700 text-white">
                                    🗑️ Delete
                                </x-button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
