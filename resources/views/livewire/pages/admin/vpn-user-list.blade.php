<div class="max-w-7xl mx-auto p-4">
    <h2 class="text-xl font-semibold mb-4">VPN Users</h2>

    @if (session()->has('message'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300 mb-4">
            {{ session('message') }}
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full bg-white shadow rounded">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-2 text-left">Username</th>
                    <th class="px-4 py-2 text-left">Password</th>
                    <th class="px-4 py-2 text-left">Device Name</th>
                    <th class="px-4 py-2 text-left">Servers Assigned</th>
                    <th class="px-4 py-2 text-left">Created</th>
                    <th class="px-4 py-2 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr class="border-b">
                        <td class="px-4 py-2">{{ $user->username }}</td>
                        <td class="px-4 py-2">
                            @if ($user->plain_password)
                                {{ $user->plain_password }}
                            @else
                                <span class="text-gray-400 italic">N/A</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $user->device_name ?? 'N/A' }}</td>
                        <td class="px-4 py-2">
                            @if ($user->vpnServers->isNotEmpty())
                                <ul class="list-disc ml-4">
                                    @foreach ($user->vpnServers as $server)
                                        <li>{{ $server->name }} ({{ $server->ip_address }})</li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="text-gray-500">No servers assigned</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            {{ $user->created_at->diffForHumans() }}
                        </td>
                        <td class="px-4 py-2 space-y-1">
                            <!-- WireGuard Download -->
                            <a href="{{ route('admin.clients.config.download', $user->id) }}" 
                               class="block bg-green-600 text-white px-3 py-1 rounded hover:bg-green-700">
                                Download WG
                            </a>

                            <!-- OpenVPN per server download -->
                            @foreach ($user->vpnServers as $server)
                                <a href="{{ route('admin.clients.config.downloadForServer', [$user->id, $server->id]) }}" 
                                   class="block bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700">
                                    OVPN {{ $server->name }}
                                </a>
                            @endforeach

                            <!-- Download All -->
                            <a href="{{ route('admin.clients.configs.downloadAll', $user->id) }}" 
                               class="block bg-purple-600 text-white px-3 py-1 rounded hover:bg-purple-700">
                                Download All
                            </a>

                            <!-- Generate WireGuard Peer -->
                            <button 
                                wire:click="generateWireGuard({{ $user->id }})" 
                                class="block w-full bg-yellow-600 text-white px-3 py-1 rounded hover:bg-yellow-700">
                                Generate Peer
                            </button>

                            <!-- Delete User -->
                            <button 
                                wire:click="deleteUser({{ $user->id }})" 
                                onclick="return confirm('Are you sure you want to delete this user and remove WireGuard peers?')" 
                                class="block w-full bg-red-600 text-white px-3 py-1 rounded hover:bg-red-700">
                                Delete
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
