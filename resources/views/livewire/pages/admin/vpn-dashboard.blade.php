<div wire:poll.5s class="space-y-6">
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

  {{-- Active connections --}}
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
</div>