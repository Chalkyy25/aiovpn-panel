<div class="p-6 bg-white rounded shadow">
    <h2 class="text-xl font-bold mb-4">
        VPN Config Downloads for {{ $vpnUser->username }}
    </h2>

    <div class="space-y-2">
        @forelse ($vpnUser->vpnServers as $server)
            <div class="flex items-center justify-between bg-gray-50 p-3 rounded">
                <div>
                    <span class="font-semibold">{{ $server->name }}</span>
                    <span class="text-sm text-gray-500">({{ $server->location ?? 'Unknown' }})</span>
                </div>
                <a href="{{ route('clients.config.downloadForServer', [$vpnUser, $server]) }}"
                   class="bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 text-sm">
                    Download Config
                </a>
            </div>
        @empty
            <p class="text-gray-500">No servers assigned to this user yet.</p>
        @endforelse
    </div>

    <div class="mt-6">
        <a href="{{ route('clients.configs.downloadAll', $vpnUser) }}"
           class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
            Download All Configs (ZIP)
        </a>
    </div>
</div>
