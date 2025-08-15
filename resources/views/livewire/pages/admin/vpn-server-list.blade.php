<div class="p-6" wire:poll.30s="pollOnlineCounts">
    {{-- Flash message --}}
    @if (session()->has('status-message'))
        <div class="aio-pill pill-neon mb-4 inline-block">
            {{ session('status-message') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-bold text-[var(--aio-ink)]">🌐 VPN Servers</h2>
        <a href="{{ route('admin.servers.create') }}">
            <x-button class="aio-pill pill-cya shadow-glow">+ Add Server</x-button>
        </a>
    </div>

    {{-- Empty state --}}
    @if ($servers->isEmpty())
        <div class="aio-card text-center p-6">
            <p class="text-[var(--aio-sub)]">No VPN servers found.</p>
            <p class="mt-2 text-sm text-gray-500">Click "Add Server" above to get started.</p>
        </div>
    @else
        {{-- Table --}}
        <div class="aio-card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-white/5">
                <tr class="text-[var(--aio-sub)] uppercase text-xs">
                    <th class="px-4 py-2 text-left">ID</th>
                    <th class="px-4 py-2 text-left">Name</th>
                    <th class="px-4 py-2 text-left">IP Address</th>
                    <th class="px-4 py-2 text-left">Protocol</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-right">Actions</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-white/10">
                @php $highlightId = request('highlight'); @endphp

                @foreach ($servers as $server)
                    <tr class="{{ $highlightId == $server->id ? 'bg-yellow-500/10 animate-pulse' : 'hover:bg-white/5' }}"
                        wire:key="server-{{ $server->id }}">
                        
                        <td class="px-4 py-2 text-[var(--aio-sub)]">{{ $server->id }}</td>
                        <td class="px-4 py-2 font-medium text-[var(--aio-ink)]">{{ $server->name }}</td>
                        <td class="px-4 py-2 text-[var(--aio-sub)]">{{ $server->ip_address }}</td>
                        
                        <td class="px-4 py-2">
                            <span class="aio-pill pill-pup text-xs">
                                {{ strtoupper($server->protocol) }}
                            </span>
                        </td>
                        
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2">
                                <span class="text-sm {{ $server->status === 'online' ? 'text-[var(--aio-neon)]' : 'text-red-400' }}">
                                    {{ $server->status === 'online' ? '✅ Online' : '❌ Offline' }}
                                </span>

                                @if ($server->online_user_count !== null)
                                    <span class="aio-pill pill-cya text-xs">
                                        👤 {{ $server->online_user_count }} online
                                    </span>
                                @endif
                            </div>
                        </td>

                        <td class="px-4 py-2 text-right min-w-[300px]">
                            <div class="flex flex-wrap gap-2 justify-end">
                                <a href="{{ route('admin.servers.show', $server->id) }}">
                                    <x-button class="aio-pill pill-cya hover:shadow-glow">🔍 View</x-button>
                                </a>
                                <a href="{{ route('admin.servers.edit', $server->id) }}">
                                    <x-button class="aio-pill pill-mag hover:shadow-glow">✏️ Edit</x-button>
                                </a>
                                <x-button wire:click="syncServer({{ $server->id }})"
                                          class="aio-pill pill-pup hover:shadow-glow">
                                    🔁 Sync
                                </x-button>
                                <x-button wire:click="deleteServer({{ $server->id }})"
                                          class="aio-pill bg-red-500/20 text-red-400 hover:shadow-glow">
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