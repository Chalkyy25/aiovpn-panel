<div wire:poll.10s class="max-w-7xl mx-auto p-4">
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Password</th>
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
    <div class="flex items-center">
        <div class="flex-shrink-0 h-2.5 w-2.5 {{ $user->is_online ? 'bg-green-400' : 'bg-gray-400' }} rounded-full"></div>
        <div class="ml-3">
            <div class="text-sm font-medium text-gray-900">{{ $user->username }}</div>

            @php
                $onlineSince = $user->online_since;          // Carbon|null (from accessor)
                $lastSeen    = $user->last_seen_at;          // Carbon|null (cast)
            @endphp

            @if($user->is_online)
                <div class="text-xs text-green-600">
                    Online — {{ $onlineSince ? $onlineSince->diffForHumans(now(), true) : 'just now' }}
                </div>
            @elseif($lastSeen)
                <div class="text-xs text-gray-500">
                    Last seen {{ $lastSeen->diffForHumans() }}
                </div>
            @else
                <div class="text-xs text-gray-400">No activity recorded</div>
            @endif
        </div>
    </div>
</td>

                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900">
                                @if($user->plain_password)
                                    <span class="font-mono bg-gray-100 px-2 py-1 rounded text-xs">{{ $user->plain_password }}</span>
                                @else
                                    <span class="text-gray-400 text-xs">Password encrypted</span>
                                @endif
                            </div>
                        </td>

                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-500">
                                @if($user->vpnServers->count() > 0)
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($user->vpnServers as $server)
                                            <a href="{{ route('admin.servers.show', $server->id) }}" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 hover:bg-blue-200 cursor-pointer">
                                                {{ $server->name }}
                                            </a>
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
                            <div class="flex flex-col space-y-1">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $user->is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                    {{ $user->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if($user->is_online)
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        {{ $user->activeConnections->count() }} connection{{ $user->activeConnections->count() !== 1 ? 's' : '' }}
                                    </span>
                                @endif
                            </div>
                        </td>

                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                            <a href="{{ route('admin.vpn-users.edit', $user->id) }}" class="text-green-600 hover:text-green-900">Edit</a>
                            <button wire:click="generateOvpn({{ $user->id }})" class="text-indigo-600 hover:text-indigo-900">OpenVPN</button>
                            <button wire:click="generateWireGuard({{ $user->id }})" class="text-blue-600 hover:text-blue-900">WireGuard</button>
                            <form method="POST" action="{{ route('admin.impersonate', $user->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-purple-600 hover:text-purple-900" title="Login as this client">
                                    🔐 Login as Client
                                </button>
                            </form>
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
                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
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
