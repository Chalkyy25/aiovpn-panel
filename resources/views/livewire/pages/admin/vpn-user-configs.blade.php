<div class="p-6">
    <div class="aio-card">
        <div class="p-6">
            <h2 class="text-xl font-bold mb-4 text-[var(--aio-ink)]">
                VPN Config Downloads for {{ $vpnUser->username }}
            </h2>

            <div class="space-y-2">
                @forelse ($vpnUser->vpnServers as $server)
                    <div class="flex items-center justify-between bg-[var(--aio-hover)] p-3 rounded">
                        <div>
                            <span class="font-semibold text-[var(--aio-ink)]">{{ $server->name }}</span>
                            <span class="text-sm text-[var(--aio-sub)]">({{ $server->location ?? 'Unknown' }})</span>
                        </div>
                        <x-button
                            href="{{ route('clients.config.downloadForServer', [$vpnUser, $server]) }}"
                            variant="primary"
                            size="sm">
                            Download Config
                        </x-button>
                    </div>
                @empty
                    <p class="text-[var(--aio-sub)]">No servers assigned to this user yet.</p>
                @endforelse
            </div>

            <div class="mt-6">
                <x-button
                    href="{{ route('clients.configs.downloadAll', $vpnUser) }}"
                    variant="success">
                    Download All Configs (ZIP)
                </x-button>
            </div>
        </div>
    </div>
</div>
