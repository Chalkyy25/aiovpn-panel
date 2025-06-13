<div class="p-6">
    <h2 class="text-2xl font-semibold mb-4">Welcome, {{ $user->name }}</h2>
    <p class="mb-6">Your email: {{ $user->email }}</p>

    <h3 class="text-xl mb-3">Your VPN Servers</h3>
    @if ($vpnServers->isEmpty())
        <p>You have no assigned VPN servers yet.</p>
    @else
        <ul class="list-disc list-inside space-y-2">
            @foreach ($vpnServers as $server)
                <li>
                    <strong>{{ $server->name }}</strong> - {{ $server->location }} - Status: 
                    <span class="{{ $server->is_online ? 'text-green-600' : 'text-red-600' }}">
                        {{ $server->is_online ? 'Online' : 'Offline' }}
                    </span>
                    <!-- Later: add download config button here -->
                </li>
            @endforeach
        </ul>
    @endif
</div>
