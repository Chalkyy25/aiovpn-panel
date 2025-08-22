<div wire:poll.10s class="max-w-7xl mx-auto p-4 space-y-4">

    <h2 class="text-xl font-semibold">VPN Users</h2>

    @if (session()->has('message'))
        <div class="aio-pill pill-neon inline-block">
            {{ session('message') }}
        </div>
    @endif

    {{-- Search --}}
    <div>
        <input wire:model.debounce.300ms="search"
               type="text"
               placeholder="Search users..."
               class="w-full md:w-1/3 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-[var(--aio-ink)] placeholder-[var(--aio-sub)] focus:outline-none focus:ring-2 focus:ring-[var(--aio-cya)]">
    </div>

    {{-- Table --}}
    <div class="aio-card overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-white/5">
                <tr class="text-[var(--aio-sub)] uppercase text-xs">
                    <th class="px-6 py-3 text-left">Username</th>
                    <th class="px-6 py-3 text-left">Password</th>
                    <th class="px-6 py-3 text-left">Servers</th>
                    <th class="px-6 py-3 text-left">Expires</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
                @forelse ($users as $user)
                    <tr>
                        {{-- Username + online/offline --}}
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-3">
                                <div class="h-2.5 w-2.5 rounded-full {{ $user->is_online ? 'bg-[var(--aio-neon)]' : 'bg-gray-500' }}"></div>
                                <div>
                                    <div class="font-medium text-[var(--aio-ink)]">{{ $user->username }}</div>
                                    @if($user->is_online && $user->online_since)
                                        <div class="text-xs text-[var(--aio-neon)]">Online – {{ $user->online_since->diffForHumans() }}</div>
                                    @elseif($user->last_disconnected_at)
                                        <div class="text-xs text-gray-400">Offline – {{ $user->last_disconnected_at->diffForHumans() }}</div>
                                    @else
                                        <div class="text-xs text-gray-500">Never connected</div>
                                    @endif
                                </div>
                            </div>
                        </td>

                        {{-- Password --}}
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($user->plain_password)
                                <span class="font-mono aio-pill pill-cya text-xs">{{ $user->plain_password }}</span>
                            @else
                                <span class="text-gray-500 text-xs">Encrypted</span>
                            @endif
                        </td>

                        {{-- Servers --}}
                        <td class="px-6 py-4">
                            @if($user->vpnServers->count())
                                <div class="flex flex-wrap gap-1">
                                    @foreach($user->vpnServers as $server)
                                        <a href="{{ route('admin.servers.show', $server->id) }}" class="aio-pill pill-mag text-xs hover:shadow-glow">
                                            {{ $server->name }}
                                        </a>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-gray-500">No servers</span>
                            @endif
                        </td>

                        {{-- Expiry --}}
                        <td class="px-6 py-4 whitespace-nowrap">
                            {{ $user->expires_at ? \Carbon\Carbon::parse($user->expires_at)->format('d M Y') : 'Never' }}
                        </td>

                        {{-- Status --}}
                        <td class="px-6 py-4 whitespace-nowrap space-y-1">
                            <span class="aio-pill {{ $user->is_active ? 'pill-neon' : 'bg-red-500/20 text-red-400' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            @if($user->is_online)
                                <span class="aio-pill pill-cya">
                                    {{ $user->activeConnections->count() }} conn
                                </span>
                            @endif
                        </td>

                        {{-- Actions --}}
                        <td class="px-6 py-4 whitespace-nowrap text-xs space-x-2">
                            <a href="{{ route('admin.vpn-users.edit', $user->id) }}" class="text-[var(--aio-neon)] hover:underline">Edit</a>
                            <button wire:click="generateOvpn({{ $user->id }})" class="text-[var(--aio-cya)] hover:underline">OpenVPN</button>
                            <button wire:click="generateWireGuard({{ $user->id }})" class="text-[var(--aio-mag)] hover:underline">WireGuard</button>
                            <form method="POST" action="{{ route('admin.impersonate', $user->id) }}" class="inline">
                                @csrf
                                <button type="submit" class="text-[var(--aio-pup)] hover:underline" title="Login as this client">
                                    Login
                                </button>
                            </form>
                            <button wire:click="toggleActive({{ $user->id }})" class="text-yellow-400 hover:underline">
                                {{ $user->is_active ? 'Disable' : 'Enable' }}
                            </button>
                            <button wire:click="deleteUser({{ $user->id }})" onclick="return confirm('Delete this user?')" class="text-red-400 hover:underline">
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

    <div>
        {{ $users->links() }}
    </div>
</div>