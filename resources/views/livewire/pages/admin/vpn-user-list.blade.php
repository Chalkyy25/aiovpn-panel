<div class="max-w-7xl mx-auto p-4">
    <h2 class="text-xl font-semibold mb-4">VPN Users</h2>

    @if (session()->has('message'))
        <div class="p-3 bg-green-100 text-green-700 rounded border border-green-300 mb-4">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-4">
        <input wire:model.debounce.300ms="search"
               type="text"
               placeholder="Search users..."
               class="w-full md:w-1/3 px-4 py-2 border rounded-lg">
    </div>

    <div class="overflow-x-auto bg-white shadow-md rounded-lg">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Username</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Servers</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Expires</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse ($users as $user)
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ $user->username }}</div>
                        </td>

                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-500">
                                @if($user->vpnServers->count() > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($user->vpnServers as $server)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                {{ $server->name }}
                                            </span>
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-gray-400">No servers</span>
                                @endif
                            </div>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-500">
                                @if($user->expires_at)
                                    {{ \Carbon\Carbon::parse($user->expires_at)->format('d M Y') }}
                                @else
                                    <span class="text-gray-400">Never</span>
                                @endif
                            </div>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $user->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                            <button wire:click="generateOvpn({{ $user->id }})" class="text-indigo-600 hover:text-indigo-900">OpenVPN</button>
                            <button wire:click="generateWireGuard({{ $user->id }})" class="text-blue-600 hover:text-blue-900">WireGuard</button>
                            <button wire:click="toggleActive({{ $user->id }})" class="text-yellow-600 hover:text-yellow-900">
                                {{ $user->is_active ? 'Disable' : 'Enable' }}
                            </button>
                            <button wire:click="deleteUser({{ $user->id }})"
                                    class="text-red-600 hover:text-red-900"
                                    onclick="return confirm('Are you sure you want to delete this user?')">
                                Delete
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                            No VPN users found
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</div>
