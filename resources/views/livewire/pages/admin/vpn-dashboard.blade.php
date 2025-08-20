{{-- Live-only VPN dashboard (no cron/supervisor logs), Reverb/Echo driven --}}
@php
    // Seed from backend so UI shows instantly (even before first broadcast)
    // Build per-server user snapshot from $activeConnections
    $seedUsersByServer = $activeConnections->groupBy('vpn_server_id')->map(function ($group) {
        return $group->map(function ($c) {
            return [
                'username'     => optional($c->vpnUser)->username ?? 'unknown',
                'client_ip'    => $c->client_ip,
                'virtual_ip'   => $c->virtual_ip,
                'connected_at' => optional($c->connected_at)?->toIso8601String(),
                'bytes_in'     => $c->bytes_received,
                'bytes_out'    => $c->bytes_sent,
                'server_name'  => optional($c->vpnServer)->name,
            ];
        })->values();
    })->toArray();

    $seedServerMeta = $servers->mapWithKeys(fn($s) => [$s->id => ['id'=>$s->id,'name'=>$s->name]])->toArray();

    $seedTotals = [
        'active_servers'      => $servers->count(),
        'active_connections'  => $activeConnections->count(),
        'online_users'        => $activeConnections->pluck('vpnUser.username')->filter()->unique()->count(),
    ];
@endphp

<div x-data="vpnDashboard()"
     x-init="init(@json($seedServerMeta), @json($seedUsersByServer), @json($seedTotals))"
     class="space-y-6">

  {{-- Header --}}
  <div class="flex justify-between items-center">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Real‑time monitoring of VPN connections</p>
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
          {{-- Keep old calc as snapshot; optional to make this live later --}}
          <div class="text-2xl font-semibold text-[var(--aio-ink)]">
            @if($activeConnections->count() > 0)
              {{ number_format($activeConnections->avg(fn($c)=> $c->connection_duration ?? 0)/60,1) }}m
            @else 0m @endif
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Server filter (live badge counts) --}}
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
          <!-- Rows rendered by Alpine from live store -->
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

  {{-- Recently Disconnected (kept as snapshot) --}}
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

{{-- Scripts (pure Echo; no optional chaining) --}}
<script>
function vpnDashboard() {
  return {
    serverMeta: {},
    usersByServer: {}, // { serverId: [ {username, ...} ] }
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },
    selectedServerId: null,
    lastUpdated: new Date().toLocaleTimeString(),

    init(meta, seedUsersByServer, seedTotals) {
      this.serverMeta = meta || {};
      this.usersByServer = {};
      for (const sid in seedUsersByServer) {
        this.usersByServer[Number(sid)] = seedUsersByServer[sid] || [];
      }
      this.totals = seedTotals || this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();

      // Subscribe after seed
      if (typeof window.Echo !== 'undefined') {
        // Fleet channel
        window.Echo.private('servers.dashboard')
          .listen('.ServerUpdated', (e) => this.handleEvent(e))
          .listen('ServerUpdated',  (e) => this.handleEvent(e))
          .listen('App\\Events\\ServerUpdated', (e) => this.handleEvent(e))
          .listen('.mgmt.update',   (e) => this.handleEvent(e));

        // Per-server channels
        Object.keys(this.serverMeta).forEach((sid) => {
          window.Echo.private('servers.' + sid)
            .listen('.ServerUpdated', (e) => this.handleEvent(e))
            .listen('ServerUpdated',  (e) => this.handleEvent(e))
            .listen('App\\Events\\ServerUpdated', (e) => this.handleEvent(e))
            .listen('.mgmt.update',   (e) => this.handleEvent(e));
        });
      } else {
        console.warn('Echo not found. Ensure @vite(["resources/js/app.js"]) is loaded.');
      }
    },

    selectServer(id) { this.selectedServerId = id; },

    normalizeUsers(payload, fallbackServerId) {
      // Accept users: ['alice', ...] OR users: [{ username, ... }]
      let arr = [];
      if (Array.isArray(payload.users)) {
        if (payload.users.length && typeof payload.users[0] === 'string') {
          arr = payload.users.map(u => ({ username: String(u) }));
        } else {
          arr = payload.users;
        }
      } else if (typeof payload.cn_list === 'string') {
        arr = payload.cn_list.split(',').map(s => ({ username: s.trim() })).filter(u => u.username);
      }

      // Enrich basics
      return arr.map(u => {
        const sname = payload.server_name || (this.serverMeta[fallbackServerId]?.name) || 'Server ' + fallbackServerId;
        const connectedAt = u.connected_at ? new Date(u.connected_at) : null;
        return {
          username: u.username || 'unknown',
          client_ip: u.client_ip || null,
          virtual_ip: u.virtual_ip || null,
          connected_at: connectedAt ? connectedAt.toISOString() : null,
          connected_human: connectedAt ? this.human(connectedAt) : null,
          connected_fmt: connectedAt ? connectedAt.toLocaleString() : null,
          bytes_in: u.bytes_in || null,
          bytes_out: u.bytes_out || null,
          down_mb: u.bytes_in ? (u.bytes_in/1024/1024).toFixed(2) : '0.00',
          up_mb: u.bytes_out ? (u.bytes_out/1024/1024).toFixed(2) : '0.00',
          formatted_bytes: (u.bytes_in || u.bytes_out) ? this.prettyBytes((u.bytes_in||0)+(u.bytes_out||0)) : null,
          server_name: sname,
        };
      });
    },

    handleEvent(e) {
      var sid = (typeof e.server_id !== 'undefined') ? Number(e.server_id) : null;
      if (sid === null) return;

      const users = this.normalizeUsers(e, sid);

      // Treat payload.users / cn_list as snapshot; merge only if explicit incremental events later
      this.usersByServer[sid] = users;

      // Update totals + time
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();
    },

    computeTotals() {
      let totalUsers = 0;
      let totalConns = 0;
      let activeServers = 0;
      for (const sid in this.serverMeta) {
        const arr = this.usersByServer[sid] || [];
        if (arr.length > 0) activeServers++;
        totalUsers += new Set(arr.map(u => u.username)).size;
        totalConns += arr.length;
      }
      return {
        online_users: totalUsers,
        active_connections: totalConns,
        active_servers: activeServers || Object.keys(this.serverMeta).length, // show fleet count if none active
      };
    },

    serverUsersCount(id) { return (this.usersByServer[id] || []).length; },

    activeRows() {
      const rows = [];
      const ids = (this.selectedServerId===null)
        ? Object.keys(this.serverMeta)
        : [String(this.selectedServerId)];

      ids.forEach((sid) => {
        (this.usersByServer[sid] || []).forEach((u) => {
          rows.push({
            key: (sid + ':' + u.username),
            server_name: this.serverMeta[sid]?.name || ('Server ' + sid),
            username: u.username,
            client_ip: u.client_ip,
            virtual_ip: u.virtual_ip,
            connected_human: u.connected_human,
            connected_fmt: u.connected_fmt,
            formatted_bytes: u.formatted_bytes,
            down_mb: u.down_mb,
            up_mb: u.up_mb,
          });
        });
      });
      return rows;
    },

    prettyBytes(b) {
      const units = ['B','KB','MB','GB','TB']; let i = 0;
      while (b >= 1024 && i < units.length-1) { b /= 1024; i++; }
      return (Math.round(b*100)/100) + units[i];
    },

    human(d) {
      const diff = (Date.now() - d.getTime())/1000; // seconds
      if (diff < 60) return 'just now';
      const mins = Math.floor(diff/60);
      if (mins < 60) return mins + ' minute' + (mins===1?'':'s') + ' ago';
      const hrs = Math.floor(mins/60);
      return hrs + ' hour' + (hrs===1?'':'s') + ' ago';
    },

    disconnect(row) {
      // Optional: wire this up to a Livewire action or API call
      // window.Livewire?.dispatch('disconnect-user', { username: row.username, server: row.server_name });
      alert('Disconnect not wired yet for ' + row.username);
    },
  }
}
</script>