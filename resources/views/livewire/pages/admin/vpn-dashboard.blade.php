{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}
{{-- Real-time VPN dashboard (Echo optional) + 15s fallback polling + Disconnect --}}

@php
  // Seed rows (keep tiny)
  $seedUsersByServer = $activeConnections->groupBy('vpn_server_id')->map(function ($rows) {
      return $rows->map(function ($c) {
          return [
              'connection_id' => $c->id,
              'username'      => optional($c->vpnUser)->username ?? 'unknown',
              'client_ip'     => $c->client_ip,
              'virtual_ip'    => $c->virtual_ip,
              'connected_human'=> optional($c->connected_at)?->diffForHumans(),
              'connected_fmt' => optional($c->connected_at)?->toDateTimeString(),
              'formatted_bytes'=> number_format((int)($c->bytes_received + $c->bytes_sent) / (1024*1024), 2) . ' MB',
              'down_mb'       => number_format((int)$c->bytes_received / (1024*1024), 2),
              'up_mb'         => number_format((int)$c->bytes_sent / (1024*1024), 2),
              'server_name'   => optional($c->vpnServer)->name,
          ];
      })->values();
  })->toArray();

  $seedServerMeta = $servers->mapWithKeys(fn($s) => [
      $s->id => ['id' => $s->id, 'name' => $s->name]
  ])->toArray();

  $seedTotals = [
      'active_servers'     => $servers->count(),
      'active_connections' => $activeConnections->count(),
      'online_users'       => $activeConnections->pluck('vpnUser.username')->filter()->unique()->count(),
  ];
@endphp

<div
  x-data="vpnDashboard()"
  x-init="init(@js($seedServerMeta), @js($seedUsersByServer), @js($seedTotals))"
  class="space-y-6"
>
  {{-- Header --}}
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-semibold text-[var(--aio-ink)]">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Live status for users & servers</p>
    </div>
    <div class="text-xs text-[var(--aio-sub)]">
      Last updated: <span x-text="lastUpdated"></span>
    </div>
  </div>

  {{-- Stat tiles --}}
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="pill-card outline-neon p-4 flex items-center gap-4">
      <div class="h-10 w-10 rounded-full pill-neon grid place-items-center">🟢</div>
      <div>
        <div class="text-xs muted">Online Users</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.online_users"></div>
      </div>
    </div>

    <div class="pill-card outline-cya p-4 flex items-center gap-4">
      <div class="h-10 w-10 rounded-full pill-cya grid place-items-center">📊</div>
      <div>
        <div class="text-xs muted">Active Connections</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_connections"></div>
      </div>
    </div>

    <div class="pill-card outline-pup p-4 flex items-center gap-4">
      <div class="h-10 w-10 rounded-full pill-pup grid place-items-center">🖥️</div>
      <div>
        <div class="text-xs muted">Active Servers</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_servers"></div>
      </div>
    </div>

    <div class="pill-card outline-mag p-4 flex items-center gap-4">
      <div class="h-10 w-10 rounded-full pill-mag grid place-items-center">⏱️</div>
      <div>
        <div class="text-xs muted">Avg. Connection Time</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]">
          @if($activeConnections->count() > 0)
            {{ number_format($activeConnections->avg(fn($c)=> $c->connection_duration ?? 0)/60,1) }}m
          @else 0m @endif
        </div>
      </div>
    </div>
  </div>

  {{-- Server filter --}}
  <div class="aio-card p-5">
    <div class="flex flex-wrap items-center gap-2">
      <button @click="selectServer(null)" class="aio-pill"
              :class="selectedServerId===null ? 'pill-cya shadow-glow' : ''">
        All Servers (<span x-text="totals.active_connections"></span>)
      </button>
      @foreach($servers as $server)
        <button @click="selectServer({{ $server->id }})" class="aio-pill"
                :class="selectedServerId==={{ $server->id }} ? 'pill-pup shadow-glow' : ''">
          {{ $server->name }}
          <span class="aio-pill ml-1" :class="serverUsersCount({{ $server->id }})>0 ? 'pill-neon' : ''"
                x-text="serverUsersCount({{ $server->id }})"></span>
        </button>
      @endforeach
    </div>
  </div>

  {{-- Active connections table --}}
  <div class="aio-card overflow-hidden">
    <div class="px-5 py-3 border-b aio-divider">
      <h3 class="text-lg font-semibold text-[var(--aio-ink)]">
        Active Connections
        <template x-if="selectedServerId">
          <span> — <span x-text="serverMeta[selectedServerId]?.name ?? 'Unknown'"></span></span>
        </template>
      </h3>
    </div>

    <div class="overflow-x-auto">
      <table class="table-dark w-full">
        <thead class="bg-white/5 text-xs uppercase tracking-wide muted">
          <tr>
            <th>User</th><th>Server</th><th>Client IP</th><th>Virtual IP</th>
            <th>Connected Since</th><th>Data Transfer</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="row in activeRows()" :key="row.key">
            <tr>
              <td class="py-3">
                <div class="flex items-center">
                  <span class="h-2 w-2 rounded-full bg-[var(--aio-neon)]"></span>
                  <div class="ml-3">
                    <div class="text-sm text-[var(--aio-ink)] font-medium" x-text="row.username"></div>
                    <div class="text-xs muted" x-text="row.device ?? '—'"></div>
                  </div>
                </div>
              </td>
              <td class="text-sm text-[var(--aio-ink)]" x-text="row.server_name"></td>
              <td class="text-sm text-[var(--aio-ink)]" x-text="row.client_ip || 'N/A'"></td>
              <td class="text-sm text-[var(--aio-ink)]" x-text="row.virtual_ip || 'N/A'"></td>
              <td class="text-sm text-[var(--aio-ink)]">
                <span x-text="row.connected_human ?? 'N/A'"></span>
                <div class="text-xs muted" x-text="row.connected_fmt ?? ''"></div>
              </td>
              <td class="text-sm text-[var(--aio-ink)]">
                <span x-text="row.formatted_bytes ?? '—'"></span>
                <div class="text-xs muted">
                  ↓<span x-text="row.down_mb ?? '0.00'"></span>MB
                  ↑<span x-text="row.up_mb ?? '0.00'"></span>MB
                </div>
              </td>
              <td class="text-sm">
                <button class="aio-pill bg-red-500/15 text-red-300 hover:shadow-glow"
                        @click.prevent="disconnect(row)">Disconnect</button>
              </td>
            </tr>
          </template>

          <tr x-show="activeRows().length===0">
            <td colspan="7" class="py-6 text-center muted">No active connections.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  {{-- Live users by server --}}
  <div class="aio-card p-5">
    <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Live Users by Server</h3>
    <p class="text-xs muted mb-3">Realtime via Reverb/Echo; falls back to polling</p>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
      @foreach($servers as $server)
        <div class="p-3 rounded bg-white/5 border border-white/10">
          <div class="flex justify-between items-center">
            <div class="font-medium text-[var(--aio-ink)]">{{ $server->name }}</div>
            <div class="text-xs muted">ID: {{ $server->id }}</div>
          </div>
          <ul class="mt-2 text-sm text-[var(--aio-ink)]">
            <template x-if="serverUsersCount({{ $server->id }})===0">
              <li class="muted">No users online</li>
            </template>
            <template x-for="u in (usersByServer[{{ $server->id }}] || [])" :key="u.__key">
              <li x-text="u.username"></li>
            </template>
          </ul>
        </div>
      @endforeach
    </div>
  </div>

  {{-- Recently disconnected --}}
  @if($recentlyDisconnected->count() > 0)
    <div class="aio-card overflow-hidden">
      <div class="px-5 py-3 border-b aio-divider">
        <h3 class="text-lg font-semibold text-[var(--aio-ink)]">Recently Disconnected</h3>
      </div>
      <div class="overflow-x-auto">
        <table class="table-dark w-full">
          <thead class="bg-white/5 text-xs uppercase tracking-wide muted">
            <tr><th>User</th><th>Server</th><th>Last IP</th><th>Disconnected</th><th>Session</th></tr>
          </thead>
          <tbody>
            @foreach($recentlyDisconnected as $c)
              <tr>
                <td class="py-3">
                  <div class="flex items-center">
                    <span class="h-2 w-2 rounded-full bg-white/30"></span>
                    <div class="ml-3 text-sm text-[var(--aio-ink)] font-medium">
                      {{ $c->vpnUser->username }}
                    </div>
                  </div>
                </td>
                <td class="text-sm text-[var(--aio-ink)]">{{ $c->vpnServer->name }}</td>
                <td class="text-sm text-[var(--aio-ink)]">{{ $c->client_ip ?? 'N/A' }}</td>
                <td class="text-sm text-[var(--aio-ink)]">{{ $c->disconnected_at->diffForHumans() }}</td>
                <td class="text-sm text-[var(--aio-ink)]">
                  @if($c->connected_at && $c->disconnected_at)
                    {{ $c->connected_at->diffInMinutes($c->disconnected_at) }}m
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

<script>
document.addEventListener('alpine:init', () => {
  Alpine.data('vpnDash', () => ({
    // ---- state ----
    serverMeta: {},
    usersByServer: {},
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },
    selectedServerId: null,
    lastUpdated: new Date().toLocaleTimeString(),
    _pollTimer: null,
    _subscribed: false,

    // ---- lifecycle ----
    boot(meta, seedUsersByServer, seedTotals) {
      this.serverMeta = meta || {};
      this.usersByServer = {};
      Object.keys(this.serverMeta).forEach(sid => this.usersByServer[sid] = []);
      if (seedUsersByServer) {
        for (const k in seedUsersByServer) {
          this.usersByServer[+k] = this._normaliseUsers(+k, seedUsersByServer[k]);
        }
      }
      if (seedTotals) this.totals = seedTotals; else this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();

      this._waitForEcho()
        .then(() => { this._subscribeFleet(); this._subscribePerServer(); })
        .finally(() => { this._startPolling(15000); });
    },

    // ---- helpers (unchanged from your version) ----
    _waitForEcho() {
      return new Promise((resolve) => {
        const t = setInterval(() => { if (window.Echo) { clearInterval(t); resolve(); } }, 150);
        setTimeout(() => { clearInterval(t); resolve(); }, 3000);
      });
    },
    _subscribeFleet() {
      if (this._subscribed || !window.Echo) return;
      try {
        window.Echo.private('servers.dashboard')
          .subscribed(() => console.log('✅ subscribed servers.dashboard'))
          .listen('.mgmt.update', e => this.handleEvent(e))
          .listen('mgmt.update',   e => this.handleEvent(e));
      } catch (e) { console.error('subscribe servers.dashboard failed', e); }
    },
    _subscribePerServer() {
      if (this._subscribed || !window.Echo) return;
      Object.keys(this.serverMeta).forEach(sid => {
        try {
          window.Echo.private(`servers.${sid}`)
            .subscribed(() => console.log(`✅ subscribed servers.${sid}`))
            .listen('.mgmt.update', e => this.handleEvent(e))
            .listen('mgmt.update',   e => this.handleEvent(e));
        } catch (e) { console.error(`subscribe servers.${sid} failed`, e); }
      });
      this._subscribed = true;
    },
    _startPolling(ms = 15000) {
      if (this._pollTimer) clearInterval(this._pollTimer);
      this._pollTimer = setInterval(() => {
        if (!window.$wire?.getLiveStats) return;
        window.$wire.getLiveStats().then(res => {
          const incoming = res?.usersByServer || {};
          const norm = {};
          for (const k in this.serverMeta) {
            const sid = +k;
            norm[sid] = this._normaliseUsers(sid, incoming[sid] || []);
          }
          this.usersByServer = norm;
          this.totals = this.computeTotals();
          this.lastUpdated = new Date().toLocaleTimeString();
        }).catch(() => {});
      }, ms);
    },
    _normaliseUsers(serverId, list) {
      const arr = Array.isArray(list) ? list : [];
      const mapped = arr.map(u => (typeof u === 'string') ? { username: u } : { ...u })
        .map(u => {
          const name = u.username ?? u.cn ?? 'unknown';
          return { ...u, username: name, __key: `${serverId}:${name}` };
        });
      const seen = new Set();
      return mapped.filter(u => (seen.has(u.__key) ? false : (seen.add(u.__key), true)));
    },
    handleEvent(e) {
      const sid = Number(e.server_id ?? e.serverId ?? 0);
      if (!sid) return;
      let list = [];
      if (Array.isArray(e.users) && e.users.length) list = e.users;
      else if (typeof e.cn_list === 'string') list = e.cn_list.split(',').map(s => s.trim()).filter(Boolean);
      this.usersByServer[sid] = this._normaliseUsers(sid, list);
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();
    },
    computeTotals() {
      const unique = new Set(); let conns = 0;
      Object.keys(this.serverMeta).forEach(sid => {
        const arr = this.usersByServer[sid] || [];
        conns += arr.length; arr.forEach(u => unique.add(u.username));
      });
      const activeServers = Object.keys(this.serverMeta)
        .filter(sid => (this.usersByServer[sid] || []).length > 0).length;
      return { online_users: unique.size, active_connections: conns, active_servers: activeServers };
    },
    serverUsersCount(id) { return (this.usersByServer[id] || []).length; },
    activeRows() {
      const ids = this.selectedServerId == null ? Object.keys(this.serverMeta) : [String(this.selectedServerId)];
      const rows = [];
      ids.forEach(sid => (this.usersByServer[sid] || []).forEach(u => rows.push({
        key: u.__key, connection_id: u.connection_id ?? null, server_id: Number(sid),
        server_name: this.serverMeta[sid]?.name ?? `Server ${sid}`,
        username: u.username ?? 'unknown', client_ip: u.client_ip ?? null, virtual_ip: u.virtual_ip ?? null,
        connected_human: u.connected_human ?? null, connected_fmt: u.connected_fmt ?? null,
        formatted_bytes: u.formatted_bytes ?? null, down_mb: u.down_mb ?? null, up_mb: u.up_mb ?? null,
      })));
      return rows;
    },
    selectServer(id) { this.selectedServerId = id; },
    async disconnect(row) {
      if (!confirm(`Disconnect ${row.username} from ${row.server_name}?`)) return;
      try {
        const res = await fetch('{{ route('admin.vpn.disconnect') }}', {
          method: 'POST',
          headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Content-Type': 'application/json' },
          body: JSON.stringify({ username: row.username, server_id: row.server_id }),
        });
        const data = await (async () => { try { return await res.json(); } catch { return { message: await res.text() }; } })();
        if (!res.ok) throw new Error(Array.isArray(data.output) ? data.output.join('\n') : (data.message || 'Unknown error'));
        this.usersByServer[row.server_id] = (this.usersByServer[row.server_id] || []).filter(u => u.username !== row.username);
        this.totals = this.computeTotals();
        alert(data.message || `Disconnected ${row.username}`);
      } catch (e) { console.error(e); alert('Error disconnecting user.\n\n' + (e.message || 'Unknown issue')); }
    },
  }));
});
</script>