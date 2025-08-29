<div class="p-6 space-y-6">
    {{-- Impersonation banner --}}
    @if(session()->has('impersonating_admin_id'))
        <div class="aio-card border border-orange-500/30 bg-orange-500/10 p-4 rounded">
            <div class="flex items-center justify-between">
                <div class="text-sm">
                    <div class="font-medium">🔐 Admin Impersonation Active — viewing as <span class="text-[var(--aio-ink)]">{{ $user->username }}</span></div>
                    <div class="text-xs text-[var(--aio-sub)]">Admin: {{ session('impersonating_admin_name') }}</div>
                </div>
                <form method="POST" action="{{ route('admin.stop-impersonation') }}">
                    @csrf
                    <button class="aio-pill bg-orange-600/90 hover:shadow-glow">Stop Impersonation</button>
                </form>
            </div>
        </div>
    @endif

    {{-- Header + logout --}}
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-[var(--aio-ink)]">Welcome, {{ $user->username }}</h2>
            @if($user->email)
                <p class="text-[var(--aio-sub)] text-sm mt-1">Your email: {{ $user->email }}</p>
            @endif
        </div>
        <form method="POST" action="{{ route('client.logout') }}">
            @csrf
            <button class="aio-pill bg-red-600/90 hover:shadow-glow">🚪 Logout</button>
        </form>
    </div>

    <div class="aio-card p-5">
        <h3 class="text-lg font-semibold text-[var(--aio-ink)] mb-3">Your VPN Servers</h3>

        @if ($vpnServers->isEmpty())
            <p class="muted">You have no assigned VPN servers yet.</p>
        @else
            <div class="space-y-3">
                @foreach ($vpnServers as $server)
                    <div class="flex items-center justify-between bg-white/5 border border-white/10 rounded-lg p-4">
                        <div>
                            <div class="text-[var(--aio-ink)] font-medium text-lg">{{ $server->name }}</div>
                            <div class="text-sm text-[var(--aio-sub)]">
                                Status:
                                <span class="{{ $server->is_online ? 'text-green-400' : 'text-red-400' }} font-semibold">
                                    {{ $server->is_online ? 'Online' : 'Offline' }}
                                </span>
                                @if($server->location) · <span>{{ $server->location }}</span>@endif
                            </div>
                        </div>

                        <div class="flex gap-2">
                            {{-- OpenVPN download --}}
                            <a href="{{ route('client.vpn.download', ['vpnserver' => $server->id]) }}"
                               class="aio-pill bg-blue-600/90 hover:shadow-glow">📥 Download OpenVPN</a>

                            {{-- Optional WireGuard download if you add route later --}}
                            @if(!empty($user->wireguard_public_key))
                                <a href="{{ route('client.vpn.download', ['vpnserver' => $server->id, 'proto' => 'wg']) }}"
                                   class="aio-pill bg-indigo-600/90 hover:shadow-glow hidden md:inline-flex">
                                   ⚡ WireGuard
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>