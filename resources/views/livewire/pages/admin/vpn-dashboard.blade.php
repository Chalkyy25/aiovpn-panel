{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}
{{-- Real-time VPN dashboard (Reverb/Echo + Alpine) with 5s fallback polling + working Disconnect --}}

@php
    // Seed: group connections by server, include connection_id so Livewire disconnect works
    $seedUsersByServer = $activeConnections->groupBy('vpn_server_id')->map(function ($group) {
        return $group->map(function ($c) {
            return [
                'connection_id' => $c->id, // 👈 needed for Livewire disconnectUser
                'username'      => optional($c->vpnUser)->username ?? 'unknown',
                'client_ip'     => $c->client_ip,
                'virtual_ip'    => $c->virtual_ip,
                'connected_at'  => optional($c->connected_at)?->toIso8601String(),
                'bytes_in'      => (int) $c->bytes_received,
                'bytes_out'     => (int) $c->bytes_sent,
                'server_name'   => optional($c->vpnServer)->name,
            ];
        })->values();
    })->toArray();

    $seedServerMeta = $servers->mapWithKeys(fn($s) => [
        $s->id => ['id' => $s->id, 'name' => $s->name]
    ])->toArray();

    $seedTotals = [
        'active_servers'      => $servers->count(),
        'active_connections'  => $activeConnections->count(),
        'online_users'        => $activeConnections->pluck('vpnUser.username')->filter()->unique()->count(),
    ];
@endphp

<div
    x-data="vpnDashboard()"
    x-init="init(@js($seedServerMeta), @js($seedUsersByServer), @js($seedTotals))"
    class="space-y-6"
>
  {{-- Header --}}
  <div class="flex justify-between items-center">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Real-time monitoring of VPN connections</p>
    </div>
    <div class="text-sm text-[var(--aio-sub)]">
      Last updated: <span x-text="lastUpdated"></span>
    </div>
  </div>

  {{-- Flash --}}
  @if (session()->has('message'))
    <div class="aio-card border border-white/10 px-4 py-3 rounded-lg text-[var(--aio-ink)]">
      <span class="block sm:inline">{{ session('message') }}</span>
    </div>
  @endif

  {{-- Stat tiles (live) --}}
  <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <div class="pill-card outline-neon">
      <div class="p-4 flex items-center gap-4">
        <div class="h-10 w-10 rounded-full pill-neon flex items-center justify-center">🟢</div>
        <div>
          <div class="text-sm muted">Online Users</div>
          <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.online_users"></div>
        </div>
      </div>
    </div>

    <div class="pill-card outline-cya">
      <div class="p-4 flex items-center gap-4">
        <div class="h-10 w-10 rounded-full pill-cya flex items-center justify-center">📊</div>
        <div>
          <div class="text-sm muted">Active Connections</div>
          <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_connections"></div>
        </div>
      </div>
    </div>

    <div class="pill-card outline-pup">
      <div class="p-4 flex items-center gap-4">
        <div class="h-10 w-10 rounded-full pill-pup flex items-center justify-center">🖥️</div>
        <div>
          <div class="text-sm muted">Active Servers</div>
          <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_servers"></div>
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
      <button @click="selectServer(null)"
              class="aio-pill"
              :class="selectedServerId===null ? 'pill-cya shadow-glow' : ''">
        All Servers (<span x-text="totals.active_connections"></span>)
      </button>

      @foreach($servers as $server)
        <button @click="selectServer({{ $server->id }})"
                class="aio-pill"
                :class="selectedServerId==={{ $server->id }} ? 'pill-pup shadow-glow' : ''">
          {{ $server->name }}
          <span class="aio-pill ml-1"
                :class="(serverUsersCount({{ $server->id }})>0) ? 'pill-neon' : ''"
                x-text="serverUsersCount({{ $server->id }})"></span>
        </button>
      @endforeach
    </div>
  </div>

  {{-- Active Connections (live, filtered) --}}
  <div class="aio-card overflow-hidden">
    <div class="px-5 py-3 border-b aio-divider">
      <h3 class="text-lg font-semibold text-[var(--aio-ink)]">
        Active Connections
        <template x-if="selectedServerId">
          <span> — <span x-text="serverMeta[selectedServerId]?.name ?? 'Unknown Server'"></span></span>
        </template>
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
        <tbody id="active-rows">
          <template x-for="row in activeRows()" :key="row.key">
            <tr>
              <td class="py-3">
                <div class="flex items-center">
                  <span class="h-2 w-2 rounded-full bg-[var(--aio-neon)]"></span>
                  <div class="ml-3">
                    <div class="text-sm text-[var(--aio-ink)] font-medium" x-text="row.username"></div>
                    <div class="text-xs muted" x-text="row.device ?? 'Unknown Device'"></div>
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
                        @click.prevent="disconnect(row)">
                  Disconnect
                </button>
              </td>
            </tr>
          </template>

          <tr x-show="activeRows().length===0">
            <td colspan="7" class="py-6 text-center muted">No active connections found.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  {{-- Live Users by Server --}}
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
            <template x-if="serverUsersCount({{ $server->id }})===0">
              <li class="muted empty-msg">No users online</li>
            </template>
            <template x-for="u in (usersByServer[{{ $server->id }}] || [])" :key="u.username">
              <li x-text="u.username"></li>
            </template>
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
</div>

{{-- Alpine controller (GLOBAL, not module) --}}
<script>
window.vpnDashboard = function () {
  return {
    serverMeta: {},
    usersByServer: {}, // { [sid]: [{username, client_ip, virtual_ip, connected_at, down_mb, up_mb, ...}] }
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },
    selectedServerId: null,
    lastUpdated: new Date().toLocaleTimeString(),
    _pollTimer: null,

    init(meta, seedUsersByServer, seedTotals) {
      this.serverMeta = meta || {};

      // seed (normalize keys to strings)
      this.usersByServer = {};
      if (seedUsersByServer) {
        Object.keys(seedUsersByServer).forEach(k => {
          const sid = String(k);
          this.usersByServer[sid] = (seedUsersByServer[k] || []).map(u => this._normUser(u));
        });
      }

      this.totals = seedTotals || this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();

      // Realtime first, then 5s polling as fallback
      this._waitForEcho()
        .then(() => { this._subscribeFleet(); this._subscribePerServer(); })
        .catch(() => { console.warn('[VPN] Echo not available — starting 5s polling fallback'); this._startPolling(); });
    },

    /* ───────── helpers ───────── */

    _waitForEcho() {
      return new Promise((resolve, reject) => {
        const t = setInterval(() => { if (window.Echo) { clearInterval(t); resolve(); } }, 150);
        setTimeout(() => { clearInterval(t); if (!window.Echo) reject(); }, 5000);
      });
    },

    _subscribeFleet() {
      try {
        window.Echo.private('servers.dashboard')
          .subscribed(() => console.log('✅ subscribed servers.dashboard'))
          .listen('.mgmt.update', e => this.handleEvent(e))
          .listen('mgmt.update',  e => this.handleEvent(e));
      } catch (e) { console.error('subscribe servers.dashboard failed', e); }
    },

    _subscribePerServer() {
      Object.keys(this.serverMeta).forEach(sid => {
        try {
          window.Echo.private(`servers.${sid}`)
            .subscribed(() => console.log(`✅ subscribed servers.${sid}`))
            .listen('.mgmt.update', e => this.handleEvent(e))
            .listen('mgmt.update',  e => this.handleEvent(e));
        } catch (e) { console.error(`subscribe servers.${sid} failed`, e); }
      });
    },

    _startPolling() {
      if (this._pollTimer) clearInterval(this._pollTimer);
      this._pollTimer = setInterval(() => {
        if (!window.$wire?.getLiveStats) return;
        window.$wire.getLiveStats()
          .then(res => {
            if (!res) return;
            // expect { usersByServer: {sid: [users...]}, totals: {...} }
            const next = {};
            Object.keys(res.usersByServer || {}).forEach(sid => {
              next[String(sid)] = (res.usersByServer[sid] || []).map(u => this._normUser(u));
            });
            this.usersByServer = next;
            this.totals = res.totals || this.computeTotals();
            this.lastUpdated = new Date().toLocaleTimeString();
          })
          .catch(() => {});
      }, 5000);
    },

    _normUser(u = {}) {
      // Accept a variety of shapes from the backend and normalize for the UI
      const username     = u.username ?? u.cn ?? 'unknown';
      const client_ip    = u.client_ip ?? null;
      const virtual_ip   = u.virtual_ip ?? null;
      const connected_at = (typeof u.connected_at === 'number') ? u.connected_at
                           : (u.connected_at ? Date.parse(u.connected_at) / 1000 : null);

      // Bytes can come as bytes_in/out or bytes_received/sent
      const bytes_in     = Number(u.bytes_in ?? u.bytes_received ?? 0);   // client -> server
      const bytes_out    = Number(u.bytes_out ?? u.bytes_sent ?? 0);      // server -> client

      const down_mb      = bytes_in  > 0 ? +(bytes_in  / 1048576).toFixed(2) : 0;
      const up_mb        = bytes_out > 0 ? +(bytes_out / 1048576).toFixed(2) : 0;

      return {
        username,
        client_ip,
        virtual_ip,
        connected_at,
        connected_human: connected_at ? this._timeAgo(connected_at * 1000) : null,
        connected_fmt:   connected_at ? new Date(connected_at * 1000).toISOString() : null,
        formatted_bytes: null, // optional pretty total if you want to add one later
        down_mb,
        up_mb,
      };
    },

    _timeAgo(ms) {
      const s = Math.floor((Date.now() - ms) / 1000);
      if (s < 60) return `${s}s ago`;
      const m = Math.floor(s / 60);
      if (m < 60) return `${m}m ago`;
      const h = Math.floor(m / 60);
      if (h < 24) return `${h}h ago`;
      const d = Math.floor(h / 24);
      return `${d}d ago`;
    },

    /* ───────── event handling ───────── */

    handleEvent(e) {
      const sid = String(Number(e.server_id ?? e.serverId ?? 0));
      if (!sid || sid === '0') return;

      let users = [];

      // Preferred: rich payload
      if (Array.isArray(e.users) && e.users.length) {
        users = e.users.map(u => this._normUser(u));
      }
      // Fallback: names-only list "alice,bob"
      else if (typeof e.cn_list === 'string') {
        users = e.cn_list
          .split(',')
          .map(s => s.trim())
          .filter(Boolean)
          .map(name => this._normUser({ username: name }));
      }

      this.usersByServer[sid] = users;
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();
    },

    /* ───────── derived + actions ───────── */

    computeTotals() {
      let totalUsers = 0, totalConns = 0, activeServers = 0;
      Object.keys(this.serverMeta).forEach(sid => {
        const arr = this.usersByServer[String(sid)] || [];
        if (arr.length) activeServers++;
        totalUsers += new Set(arr.map(u => u.username)).size;
        totalConns += arr.length;
      });
      return {
        online_users: totalUsers,
        active_connections: totalConns,
        active_servers: activeServers || Object.keys(this.serverMeta).length,
      };
    },

    serverUsersCount(id) {
      return (this.usersByServer[String(id)] || []).length;
    },

    activeRows() {
      const ids = this.selectedServerId == null
        ? Object.keys(this.serverMeta).map(String)
        : [String(this.selectedServerId)];

      const rows = [];
      ids.forEach(sid => {
        (this.usersByServer[sid] || []).forEach(u => {
          rows.push({
            key: `${sid}:${u.username}`,
            server_id: Number(sid),
            server_name: this.serverMeta[sid]?.name ?? `Server ${sid}`,
            username: u.username ?? 'unknown',
            client_ip: u.client_ip ?? null,
            virtual_ip: u.virtual_ip ?? null,
            connected_human: u.connected_human ?? null,
            connected_fmt: u.connected_fmt ?? null,
            formatted_bytes: u.formatted_bytes ?? null,
            down_mb: u.down_mb ?? 0,
            up_mb: u.up_mb ?? 0,
          });
        });
      });
      return rows;
    },

    selectServer(id) { this.selectedServerId = id; },

    async disconnect(row) {
      if (!confirm(`Disconnect ${row.username} from ${row.server_name}?`)) return;
      try {
        const res = await fetch('{{ route('admin.vpn.disconnect') }}', {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ username: row.username, server_id: row.server_id }),
        });

        // Parse as JSON, fallback to text
        let data;
        try { data = await res.json(); } catch { data = { message: await res.text() }; }

        if (!res.ok) {
          const details = Array.isArray(data.output) ? data.output.join('\n') : (data.message || 'Unknown error');
          throw new Error(details);
        }

        // Optimistic UI update: remove the user from that server list
        const sid = String(row.server_id);
        this.usersByServer[sid] = (this.usersByServer[sid] || []).filter(u => u.username !== row.username);
        this.totals = this.computeTotals();
        this.lastUpdated = new Date().toLocaleTimeString();

        alert(data.message || 'Disconnected.');
      } catch (e) {
        console.error(e);
        alert('Error disconnecting user.\n\n' + (e.message || 'Unknown issue'));
      }
    },
  };
};
</script>