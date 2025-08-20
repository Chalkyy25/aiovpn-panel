<div wire:poll.1s class="space-y-6">
    {{-- Header --}}
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-[var(--aio-ink)]">VPN Dashboard</h1>
            <p class="text-sm text-[var(--aio-sub)]">Real‑time monitoring of VPN connections</p>
        </div>
        <div class="text-sm text-[var(--aio-sub)]">
            Last updated: {{ now()->format('H:i:s') }}
        </div>
    </div>

    {{-- Flash --}}
    @if (session()->has('message'))
        <div class="aio-card border border-white/10 px-4 py-3 rounded-lg text-[var(--aio-ink)]">
            <span class="block sm:inline">{{ session('message') }}</span>
        </div>
    @endif

    {{-- Stat tiles --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="pill-card outline-neon">
            <div class="p-4 flex items-center gap-4">
                <div class="h-10 w-10 rounded-full pill-neon flex items-center justify-center">🟢</div>
                <div>
                    <div class="text-sm muted">Online Users</div>
                    <div class="text-2xl font-semibold text-[var(--aio-ink)]">{{ $totalOnlineUsers }}</div>
                </div>
            </div>
        </div>

        <div class="pill-card outline-cya">
            <div class="p-4 flex items-center gap-4">
                <div class="h-10 w-10 rounded-full pill-cya flex items-center justify-center">📊</div>
                <div>
                    <div class="text-sm muted">Active Connections</div>
                    <div class="text-2xl font-semibold text-[var(--aio-ink)]">{{ $totalActiveConnections }}</div>
                </div>
            </div>
        </div>

        <div class="pill-card outline-pup">
            <div class="p-4 flex items-center gap-4">
                <div class="h-10 w-10 rounded-full pill-pup flex items-center justify-center">🖥️</div>
                <div>
                    <div class="text-sm muted">Active Servers</div>
                    <div class="text-2xl font-semibold text-[var(--aio-ink)]">{{ $servers->count() }}</div>
                </div>
            </div>
        </div>

        <div class="pill-card outline-mag">
            <div class="p-4 flex items-center gap-4">
                <div class="h-10 w-10 rounded-full pill-mag flex items-center justify-center">⏱️</div>
                <div>
                    <div class="text-sm muted">Avg. Connection Time</div>
                    <div class="text-2xl font-semibold text-[var(--aio-ink)]">
                        @if($activeConnections->count() > 0)
                            {{ number_format($activeConnections->avg(fn($c)=> $c->connection_duration ?? 0)/60,1) }}m
                        @else 0m @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Live Bandwidth --}}
    <div class="aio-card p-5 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Bandwidth (Live)</h3>

            {{-- Optional: change projection hours on the fly --}}
            <div class="flex items-center gap-2 text-sm">
                <span class="muted">Projection:</span>
                <select wire:model="hoursPerDay" class="aio-pill bg-white/5 px-3 py-1 rounded">
                    <option value="2">2 h/day</option>
                    <option value="3">3 h/day</option>
                    <option value="4">4 h/day</option>
                    <option value="6">6 h/day</option>
                </select>
            </div>
        </div>

        {{-- Fleet totals --}}
        <livewire:widgets.bandwidth-totals :hoursPerDay="$hoursPerDay" />

        {{-- Per-server cards (respect server filter) --}}
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach(($showAllServers ? $servers : $servers->where('id', $selectedServerId)) as $server)
                <livewire:widgets.server-bandwidth-card
                    :server="$server"
                    :hoursPerDay="$hoursPerDay"
                    :key="'bw-'.$server->id"
                />
            @endforeach
        </div>
    </div>

    {{-- Server filter --}}
    <div class="aio-card p-5">
        <h3 class="text-lg font-semibold text-[var(--aio-ink)] mb-3">Servers</h3>

        <div class="flex flex-wrap gap-2">
            <button wire:click="selectServer(null)"
                    class="aio-pill {{ $showAllServers ? 'pill-cya shadow-glow' : '' }}">
                All Servers ({{ $totalActiveConnections }})
            </button>

            @foreach($servers as $server)
                <button wire:click="selectServer({{ $server->id }})"
                        class="aio-pill {{ $selectedServerId == $server->id ? 'pill-pup shadow-glow' : '' }}">
                    {{ $server->name }}
                    <span class="aio-pill ml-1 {{ $server->active_connections_count > 0 ? 'pill-neon' : '' }}">
            {{ $server->active_connections_count }}
          </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Active connections (server-filtered snapshot from Livewire) --}}
    <div class="aio-card overflow-hidden">
        <div class="px-5 py-3 border-b aio-divider">
            <h3 class="text-lg font-semibold text-[var(--aio-ink)]">
                Active Connections
                @if(!$showAllServers && $selectedServerId)
                    — {{ $servers->find($selectedServerId)->name ?? 'Unknown Server' }}
                @endif
            </h3>
        </div>

        <div class="overflow-x-auto">
            <table class="table-dark w-full">
                <thead class="bg-white/5">
                <tr class="text-xs uppercase tracking-wide muted">
                    <th>User</th>
                    <th>Server</th>
                    <th>Client IP</th>
                    <th>Virtual IP</th>
                    <th>Connected Since</th>
                    <th>Data Transfer</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse($activeConnections as $connection)
                    <tr>
                        <td class="py-3">
                            <div class="flex items-center">
                                <span class="h-2 w-2 rounded-full bg-[var(--aio-neon)]"></span>
                                <div class="ml-3">
                                    <div class="text-sm text-[var(--aio-ink)] font-medium">
                                        {{ $connection->vpnUser->username }}
                                    </div>
                                    <div class="text-xs muted">{{ $connection->vpnUser->device_name ?? 'Unknown Device' }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="text-sm text-[var(--aio-ink)]">{{ $connection->vpnServer->name }}</td>
                        <td class="text-sm text-[var(--aio-ink)]">{{ $connection->client_ip ?? 'N/A' }}</td>
                        <td class="text-sm text-[var(--aio-ink)]">{{ $connection->virtual_ip ?? 'N/A' }}</td>
                        <td class="text-sm text-[var(--aio-ink)]">
                            @if($connection->connected_at)
                                {{ $connection->connected_at->diffForHumans() }}
                                <div class="text-xs muted">{{ $connection->connected_at->format('M d, H:i') }}</div>
                            @else N/A @endif
                        </td>
                        <td class="text-sm text-[var(--aio-ink)]">
                            {{ $connection->formatted_bytes }}
                            <div class="text-xs muted">
                                ↓{{ number_format($connection->bytes_received/1024/1024,2) }}MB
                                ↑{{ number_format($connection->bytes_sent/1024/1024,2) }}MB
                            </div>
                        </td>
                        <td class="text-sm">
                            <button
                                wire:click="disconnectUser({{ $connection->id }})"
                                wire:confirm="Disconnect this user?"
                                class="aio-pill bg-red-500/15 text-red-300 hover:shadow-glow">
                                Disconnect
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-6 text-center muted">No active connections found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Live Users by Server (Echo-driven, no page reload) --}}
    <div class="aio-card p-5">
        <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Live Users by Server</h3>
        <p class="text-xs muted mb-3">Updates instantly from Reverb events</p>

        <div id="live-users" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @foreach($servers as $server)
                <div class="p-3 rounded bg-white/5 border border-white/10" data-server-id="{{ $server->id }}">
                    <div class="flex justify-between items-center">
                        <div class="font-medium text-[var(--aio-ink)]">{{ $server->name }}</div>
                        <div class="text-xs muted">ID: {{ $server->id }}</div>
                    </div>
                    <ul class="mt-2 text-sm text-[var(--aio-ink)] users-list">
                        <li class="muted empty-msg">No users online</li>
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Recently Disconnected --}}
    @if($recentlyDisconnected->count() > 0)
        <div class="aio-card overflow-hidden">
            <div class="px-5 py-3 border-b aio-divider">
                <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Recently Disconnected</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="table-dark w-full">
                    <thead class="bg-white/5">
                    <tr class="text-xs uppercase tracking-wide muted">
                        <th>User</th>
                        <th>Server</th>
                        <th>Last IP</th>
                        <th>Disconnected</th>
                        <th>Session Duration</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($recentlyDisconnected as $connection)
                        <tr>
                            <td class="py-3">
                                <div class="flex items-center">
                                    <span class="h-2 w-2 rounded-full bg-white/30"></span>
                                    <div class="ml-3 text-sm text-[var(--aio-ink)] font-medium">
                                        {{ $connection->vpnUser->username }}
                                    </div>
                                </div>
                            </td>
                            <td class="text-sm text-[var(--aio-ink)]">{{ $connection->vpnServer->name }}</td>
                            <td class="text-sm text-[var(--aio-ink)]">{{ $connection->client_ip ?? 'N/A' }}</td>
                            <td class="text-sm text-[var(--aio-ink)]">{{ $connection->disconnected_at->diffForHumans() }}</td>
                            <td class="text-sm text-[var(--aio-ink)]">
                                @if($connection->connected_at && $connection->disconnected_at)
                                    {{ $connection->connected_at->diffInMinutes($connection->disconnected_at) }}m
                                @else N/A @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Live Server Logs --}}
    <div class="aio-card overflow-hidden">
        <div class="px-5 py-3 border-b aio-divider">
            <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Live Server Logs</h3>
            <p class="text-xs muted mt-1">Real-time management events from deployment scripts</p>
        </div>
        <div class="p-5">
            <div id="live-logs" class="bg-black/20 rounded-lg p-4 h-64 overflow-y-auto font-mono text-sm">
                <div class="text-[var(--aio-sub)] text-center py-8">
                    Waiting for live logs...
                </div>
            </div>
            <div class="mt-3 flex justify-between items-center text-xs muted">
                <span>Auto-scroll: <span id="auto-scroll-status" class="text-[var(--aio-neon)]">ON</span></span>
                <button id="clear-logs" class="aio-pill bg-red-500/15 text-red-300 hover:shadow-glow">Clear Logs</button>
            </div>
        </div>
    </div>
</div>

{{-- ====== Scripts (no optional chaining, no naked-leading dots) ====== --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const logsContainer = document.getElementById('live-logs');
        const autoScrollStatus = document.getElementById('auto-scroll-status');
        const clearLogsBtn = document.getElementById('clear-logs');
        const liveUsersRoot = document.getElementById('live-users');

        // serverId -> Set(usernames)
        const serverUsers = new Map();

        let autoScroll = true;
        const maxLogs = 100;

        function normalizeUsers(payload) {
            // Accept users:[], or cn_list:"a,b", or raw strings
            if (Array.isArray(payload.users)) {
                return payload.users.map(String).filter(Boolean);
            }
            if (typeof payload.cn_list === 'string') {
                return payload.cn_list.split(',').map(s => s.trim()).filter(Boolean);
            }
            if (typeof payload.clients === 'number' && payload.clients === 0) {
                return [];
            }
            // Fallback: try payload.raw
            return [];
        }

        function updateLiveUsersUI(serverId) {
            const card = liveUsersRoot.querySelector('[data-server-id="' + serverId + '"]');
            if (!card) return;

            const listEl = card.querySelector('.users-list');
            listEl.innerHTML = ''; // clear

            const users = Array.from(serverUsers.get(serverId) || []);
            if (users.length === 0) {
                const li = document.createElement('li');
                li.className = 'muted empty-msg';
                li.textContent = 'No users online';
                listEl.appendChild(li);
                return;
            }

            users.forEach(u => {
                const li = document.createElement('li');
                li.textContent = u;
                listEl.appendChild(li);
            });
        }

        function addLogEntry(data, source) {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            logEntry.className = 'mb-2 p-2 rounded bg-white/5 border-l-2 border-[var(--aio-neon)]';
            var clients = (typeof data.clients !== 'undefined') ? data.clients : (normalizeUsers(data).length);

            logEntry.innerHTML =
                '<div class="flex justify-between items-start">' +
                '<div class="flex-1">' +
                '<span class="text-[var(--aio-neon)]">[' + timestamp + ']</span>' +
                '<span class="text-[var(--aio-pup)] ml-2">' + source + '</span>' +
                '<span class="text-[var(--aio-ink)] ml-2">Clients: ' + clients + '</span>' +
                '</div>' +
                '<span class="text-xs text-[var(--aio-sub)]">Server #' + (data.server_id ?? '?') + '</span>' +
                '</div>' +
                '<div class="mt-1 text-[var(--aio-sub)] text-xs">' +
                'Connected users: [' + (normalizeUsers(data).join(',') || 'none') + ']' +
                '</div>' +
                (data.raw ? '<div class="mt-1 text-[var(--aio-sub)] text-xs opacity-70">Raw: ' + data.raw + '</div>' : '');

            // Remove waiting message
            const waitingMsg = logsContainer.querySelector('.text-center');
            if (waitingMsg) waitingMsg.remove();

            logsContainer.appendChild(logEntry);

            // Limit entries
            const entries = logsContainer.querySelectorAll('.mb-2');
            if (entries.length > maxLogs) entries[0].remove();

            // Auto-scroll
            if (autoScroll) {
                logsContainer.scrollTop = logsContainer.scrollHeight;
            }
        }

        // Toggle auto-scroll on manual scroll
        logsContainer.addEventListener('scroll', function () {
            autoScroll = logsContainer.scrollTop + logsContainer.clientHeight >= logsContainer.scrollHeight - 5;
            autoScrollStatus.textContent = autoScroll ? 'ON' : 'OFF';
            autoScrollStatus.className = autoScroll ? 'text-[var(--aio-neon)]' : 'text-[var(--aio-sub)]';
        });

        // Clear logs
        clearLogsBtn.addEventListener('click', function () {
            logsContainer.innerHTML = '<div class="text-[var(--aio-sub)] text-center py-8">Logs cleared. Waiting for new events...</div>';
            autoScroll = true;
            autoScrollStatus.textContent = 'ON';
            autoScrollStatus.className = 'text-[var(--aio-neon)]';
        });

        // --- Echo subscriptions (IMPORTANT: no naked-leading dots or optional chaining) ---
        if (typeof window.Echo !== 'undefined') {
            // Dashboard-wide
            window.Echo.private('servers.dashboard')
                .listen('.ServerUpdated', function (e) { handleEvent(e, 'dashboard'); })
                .listen('.mgmt.update',  function (e) { handleEvent(e, 'dashboard'); });

            // Per-server channels
            @foreach($servers as $server)
            window.Echo.private('servers.{{ $server->id }}')
                .listen('.ServerUpdated', function (e) { handleEvent(e, '{{ $server->name }}'); })
                .listen('.mgmt.update',  function (e) { handleEvent(e, '{{ $server->name }}'); });
            @endforeach
        } else {
            console.warn('Echo not found. Ensure @vite(["resources/js/app.js"]) is in your layout head.');
        }

        function handleEvent(e, source) {
            try {
                var serverId = (typeof e.server_id !== 'undefined') ? e.server_id : null;
                if (serverId !== null) {
                    var users = normalizeUsers(e);
                    if (!serverUsers.has(serverId)) serverUsers.set(serverId, new Set());
                    var set = serverUsers.get(serverId);
                    // If event provides full snapshot, replace; otherwise, merge
                    var isSnapshot = Array.isArray(e.users) || typeof e.cn_list === 'string';
                    if (isSnapshot) {
                        set.clear();
                    }
                    users.forEach(function (u) { set.add(u); });
                    updateLiveUsersUI(serverId);
                }
                addLogEntry(e, source);

                // Optional: nudge Livewire to refresh counts if you want
                if (window.Livewire && typeof window.Livewire.dispatch === 'function') {
                    window.Livewire.dispatch('echo-server-update', { server_id: serverId, users: Array.from(serverUsers.get(serverId) || []) });
                }
            } catch (err) {
                console.error('handleEvent error', err, e);
            }
        }

        // Seed example (remove in prod)
        setTimeout(function () {
            if (logsContainer.querySelector('.text-center')) {
                var demo = { server_id: 1, clients: 2, cn_list: 'alice,bob', raw: 'ts=2025-08-20T08:56:00Z clients=2 [alice,bob]' };
                handleEvent(demo, 'Test Server');
            }
        }, 1200);
    });
</script>
