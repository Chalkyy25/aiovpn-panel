{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}
@php
  $seedUsersByServer = $activeConnections->groupBy('vpn_server_id')->map(function ($group) {
      return $group->map(function ($c) {
          return [
              'connection_id' => $c->id,
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

  $seedServerMeta = $servers->mapWithKeys(fn($s) => [$s->id => ['id'=>$s->id,'name'=>$s->name]])->toArray();
@endphp

<div x-data="vpnDashboardUI()" x-init="initDashboard(@js($seedServerMeta), @js($seedUsersByServer))"
     class="max-w-7xl mx-auto p-4">

  {{-- Header --}}
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-2 mb-4">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Live overview of users, servers & connections</p>
    </div>
    <div class="text-xs text-[var(--aio-sub)]">
      Updated <span class="font-medium text-[var(--aio-ink)]" x-text="lastUpdated"></span>
    </div>
  </div>

  {{-- Grid: Sidebar + Main --}}
  <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
    {{-- Sidebar / Filters --}}
    <aside class="md:col-span-3 space-y-4">
      {{-- Stats --}}
      <div class="grid grid-cols-3 md:grid-cols-1 gap-3">
        <div class="rounded bg-white/5 border border-white/10 p-3">
          <div class="text-[10px] muted">Online</div>
          <div class="text-xl font-semibold text-[var(--aio-ink)]" x-text="totals.online_users"></div>
        </div>
        <div class="rounded bg-white/5 border border-white/10 p-3">
          <div class="text-[10px] muted">Connections</div>
          <div class="text-xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_connections"></div>
        </div>
        <div class="rounded bg-white/5 border border-white/10 p-3">
          <div class="text-[10px] muted">Servers</div>
          <div class="text-xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_servers"></div>
        </div>
      </div>

      {{-- Server filter (dropdown on mobile, pills on desktop) --}}
      <div class="rounded bg-white/5 border border-white/10 p-3">
        <div class="text-sm font-semibold text-[var(--aio-ink)] mb-2">Filter by server</div>

        <div class="md:hidden">
          <select class="w-full bg-transparent border border-white/10 rounded px-2 py-1 text-sm"
                  @change="selectServer($event.target.value || null)">
            <option value="">All servers</option>
            <template x-for="(meta, sid) in serverMeta" :key="sid">
              <option :value="sid" x-text="meta.name"></option>
            </template>
          </select>
        </div>

        <div class="hidden md:flex flex-wrap gap-2">
          <button @click="selectServer(null)" class="aio-pill"
                  :class="selectedServerId===null ? 'pill-cya shadow-glow' : ''">
            All (<span x-text="totals.active_connections"></span>)
          </button>
          <template x-for="(meta, sid) in serverMeta" :key="sid">
            <button @click="selectServer(Number(sid))" class="aio-pill"
                    :class="selectedServerId===Number(sid) ? 'pill-pup shadow-glow' : ''">
              <span x-text="meta.name"></span>
              <span class="aio-pill ml-1"
                    :class="(serverUsersCount(Number(sid))>0) ? 'pill-neon' : 'bg-white/10 text-[var(--aio-sub)]'"
                    x-text="serverUsersCount(Number(sid))"></span>
            </button>
          </template>
        </div>
      </div>
    </aside>

    {{-- Main content --}}
    <section class="md:col-span-9 space-y-4">
      {{-- Tabs --}}
      <div class="flex gap-4 border-b border-white/10">
        <button class="pb-2 -mb-px border-b-2"
                :class="tab==='active' ? 'border-[var(--aio-neon)] text-[var(--aio-ink)]' : 'border-transparent text-[var(--aio-sub)]'"
                @click="tab='active'">Active</button>
        <button class="pb-2 -mb-px border-b-2"
                :class="tab==='recent' ? 'border-[var(--aio-neon)] text-[var(--aio-ink)]' : 'border-transparent text-[var(--aio-sub)]'"
                @click="tab='recent'">Recent</button>
        <button class="pb-2 -mb-px border-b-2"
                :class="tab==='servers' ? 'border-[var(--aio-neon)] text-[var(--aio-ink)]' : 'border-transparent text-[var(--aio-sub)]'"
                @click="tab='servers'">By Server</button>
      </div>

      {{-- TAB: Active --}}
      <div x-show="tab==='active'" class="aio-card overflow-hidden">
        <div class="px-4 py-3 border-b aio-divider flex items-center justify-between">
          <div class="text-lg font-semibold text-[var(--aio-ink)]">
            Active Connections
            <template x-if="selectedServerId">
              <span>— <span x-text="serverMeta[selectedServerId]?.name ?? 'Unknown'"></span></span>
            </template>
          </div>
          <div class="text-xs muted"><span x-text="activeRows().length"></span> rows</div>
        </div>

        {{-- Desktop table --}}
        <div class="hidden md:block overflow-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-white/5 sticky top-0 z-10">
              <tr class="text-[var(--aio-sub)] uppercase text-xs">
                <th class="px-4 py-2 text-left">User</th>
                <th class="px-4 py-2 text-left">Server</th>
                <th class="px-4 py-2 text-left">Client IP</th>
                <th class="px-4 py-2 text-left">Virtual IP</th>
                <th class="px-4 py-2 text-left">Connected</th>
                <th class="px-4 py-2 text-left">Transfer</th>
                <th class="px-4 py-2 text-left">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
              <template x-for="row in activeRows()" :key="row.key">
                <tr class="hover:bg-white/5">
                  <td class="px-4 py-2">
                    <div class="flex items-center gap-2">
                      <span class="h-2 w-2 rounded-full bg-[var(--aio-neon)]"></span>
                      <span class="font-medium text-[var(--aio-ink)]" x-text="row.username"></span>
                    </div>
                  </td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.server_name"></td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.client_ip || '—'"></td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.virtual_ip || '—' "></td>
                  <td class="px-4 py-2">
                    <div class="text-[var(--aio-ink)]" x-text="row.connected_human ?? '—'"></div>
                    <div class="text-xs muted" x-text="row.connected_fmt ?? ''"></div>
                  </td>
                  <td class="px-4 py-2">
                    <div class="text-[var(--aio-ink)]" x-text="row.formatted_bytes ?? '—'"></div>
                    <div class="text-xs muted">↓<span x-text="row.down_mb ?? '0.00'"></span>MB ↑<span x-text="row.up_mb ?? '0.00'"></span>MB</div>
                  </td>
                  <td class="px-4 py-2">
                    <button class="aio-pill bg-red-500/15 text-red-300 hover:shadow-glow"
                            @click.prevent="disconnect(row)">Disconnect</button>
                  </td>
                </tr>
              </template>
              <tr x-show="activeRows().length===0">
                <td colspan="7" class="px-4 py-6 text-center muted">No active connections</td>
              </tr>
            </tbody>
          </table>
        </div>

        {{-- Mobile cards --}}
        <div class="md:hidden divide-y divide-white/10">
          <template x-for="row in activeRows()" :key="row.key">
            <div class="p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-full bg-[var(--aio-neon)]"></span>
                    <span class="font-medium text-[var(--aio-ink)]" x-text="row.username"></span>
                  </div>
                  <div class="text-xs muted" x-text="row.server_name"></div>
                </div>
                <button class="aio-pill bg-red-500/15 text-red-300"
                        @click.prevent="disconnect(row)">Disconnect</button>
              </div>

              <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                <div><dt class="muted">Client IP</dt><dd class="text-[var(--aio-ink)]" x-text="row.client_ip || '—'"></dd></div>
                <div><dt class="muted">Virtual IP</dt><dd class="text-[var(--aio-ink)]" x-text="row.virtual_ip || '—'"></dd></div>
                <div><dt class="muted">Connected</dt><dd class="text-[var(--aio-ink)]" x-text="row.connected_human || '—'"></dd></div>
                <div><dt class="muted">Transfer</dt><dd class="text-[var(--aio-ink)]" x-text="row.formatted_bytes || '—'"></dd></div>
              </dl>
            </div>
          </template>
          <div x-show="activeRows().length===0" class="p-6 text-center muted">No active connections</div>
        </div>
      </div>

      {{-- TAB: Recent --}}
      <div x-show="tab==='recent'" class="aio-card overflow-hidden">
        <div class="px-4 py-3 border-b aio-divider text-lg font-semibold text-[var(--aio-ink)]">Recently Disconnected</div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-white/5 sticky top-0 z-10">
              <tr class="text-[var(--aio-sub)] uppercase text-xs">
                <th class="px-4 py-2 text-left">User</th>
                <th class="px-4 py-2 text-left">Server</th>
                <th class="px-4 py-2 text-left">Last IP</th>
                <th class="px-4 py-2 text-left">Disconnected</th>
                <th class="px-4 py-2 text-left">Duration</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
              @forelse($recentlyDisconnected as $c)
                <tr>
                  <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $c->vpnUser->username }}</td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $c->vpnServer->name }}</td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $c->client_ip ?? '—' }}</td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]">{{ $c->disconnected_at->diffForHumans() }}</td>
                  <td class="px-4 py-2 text-[var(--aio-ink)]">
                    @if($c->connected_at && $c->disconnected_at)
                      {{ $c->connected_at->diffInMinutes($c->disconnected_at) }}m
                    @else — @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="5" class="px-4 py-6 text-center muted">Nothing here yet</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>

        {{-- Mobile --}}
        <div class="md:hidden divide-y divide-white/10">
          @forelse($recentlyDisconnected as $c)
            <div class="p-4">
              <div class="flex items-center justify-between">
                <div class="font-medium text-[var(--aio-ink)]">{{ $c->vpnUser->username }}</div>
                <div class="text-xs muted">{{ $c->disconnected_at->diffForHumans() }}</div>
              </div>
              <dl class="mt-2 grid grid-cols-2 gap-2 text-xs">
                <div><dt class="muted">Server</dt><dd class="text-[var(--aio-ink)]">{{ $c->vpnServer->name }}</dd></div>
                <div><dt class="muted">Last IP</dt><dd class="text-[var(--aio-ink)]">{{ $c->client_ip ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="muted">Duration</dt>
                  <dd class="text-[var(--aio-ink)]">
                    @if($c->connected_at && $c->disconnected_at)
                      {{ $c->connected_at->diffInMinutes($c->disconnected_at) }}m
                    @else — @endif
                  </dd>
                </div>
              </dl>
            </div>
          @empty
            <div class="p-6 text-center muted">Nothing here yet</div>
          @endforelse
        </div>
      </div>

      {{-- TAB: By Server --}}
      <div x-show="tab==='servers'" class="aio-card p-4">
        <div class="text-lg font-semibold text-[var(--aio-ink)] mb-3">Live Users by Server</div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          @foreach($servers as $server)
            <div class="p-3 rounded bg-white/5 border border-white/10" data-server-id="{{ $server->id }}">
              <div class="flex justify-between items-center">
                <div class="font-medium text-[var(--aio-ink)]">{{ $server->name }}</div>
                <span class="aio-pill pill-neon" x-text="serverUsersCount({{ $server->id }})"></span>
              </div>
              <ul class="mt-2 text-sm text-[var(--aio-ink)] users-list">
                <template x-if="serverUsersCount({{ $server->id }})===0">
                  <li class="muted">No users online</li>
                </template>
                <template x-for="u in (usersByServer[{{ $server->id }}] || [])" :key="u.username">
                  <li class="truncate" x-text="u.username"></li>
                </template>
              </ul>
            </div>
          @endforeach
        </div>
      </div>
    </section>
  </div>
</div>

<script>
function vpnDashboardUI() {
  return {
    // ---- state (from your existing store) ----
    serverMeta: {}, usersByServer: {},
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },
    selectedServerId: null, lastUpdated: new Date().toLocaleTimeString(),
    _pollTimer: null, _subscribed: false,

    // ---- UI local state ----
    tab: 'active',

    // ---- lifecycle ----
    initDashboard(meta, seedUsersByServer) {
      this.serverMeta = meta || {};
      Object.keys(this.serverMeta).forEach(sid => this.usersByServer[sid] = []);
      if (seedUsersByServer) {
        for (const k in seedUsersByServer) this.usersByServer[+k] = this._normaliseUsers(+k, seedUsersByServer[k]);
      }
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();

      this._waitForEcho()
        .then(() => { this._subscribeFleet(); this._subscribePerServer(); })
        .finally(() => { this._startPolling(15000); });
    },

    // ---- same helpers you already had (trimmed but compatible) ----
    _waitForEcho() { return new Promise((resolve) => {
      const t = setInterval(() => { if (window.Echo) { clearInterval(t); resolve(); } }, 150);
      setTimeout(() => { clearInterval(t); resolve(); }, 3000);
    }); },

    _subscribeFleet() {
      if (this._subscribed) return;
      try {
        window.Echo.private('servers.dashboard')
          .listen('.mgmt.update', e => this.handleEvent(e))
          .listen('mgmt.update',   e => this.handleEvent(e));
      } catch {}
    },
    _subscribePerServer() {
      if (this._subscribed) return;
      Object.keys(this.serverMeta).forEach(sid => {
        try {
          window.Echo.private(`servers.${sid}`)
            .listen('.mgmt.update', e => this.handleEvent(e))
            .listen('mgmt.update',   e => this.handleEvent(e));
        } catch {}
      });
      this._subscribed = true;
    },

    _startPolling(ms=15000) {
      if (this._pollTimer) clearInterval(this._pollTimer);
      this._pollTimer = setInterval(() => {
        if (!window.$wire?.getLiveStats) return;
        window.$wire.getLiveStats().then(res => {
          const incoming = res?.usersByServer || {};
          const norm = {};
          for (const k in this.serverMeta) norm[+k] = this._normaliseUsers(+k, incoming[k] || []);
          this.usersByServer = norm;
          this.totals = this.computeTotals();
          this.lastUpdated = new Date().toLocaleTimeString();
        }).catch(()=>{});
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
      const sid = Number(e.server_id ?? e.serverId ?? 0); if (!sid) return;
      let list = [];
      if (Array.isArray(e.users) && e.users.length) list = e.users;
      else if (typeof e.cn_list === 'string') list = e.cn_list.split(',').map(s => s.trim()).filter(Boolean);
      this.usersByServer[sid] = this._normaliseUsers(sid, list);
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();
    },

    computeTotals() {
      const unique = new Set(); let conns=0;
      Object.keys(this.serverMeta).forEach(sid => {
        const arr = this.usersByServer[sid] || [];
        conns += arr.length; arr.forEach(u => unique.add(u.username));
      });
      const activeServers = Object.keys(this.serverMeta).filter(sid => (this.usersByServer[sid] || []).length > 0).length;
      return { online_users: unique.size, active_connections: conns, active_servers: activeServers };
    },

    serverUsersCount(id) { return (this.usersByServer[id] || []).length; },

    activeRows() {
      const ids = this.selectedServerId == null ? Object.keys(this.serverMeta) : [String(this.selectedServerId)];
      const rows = [];
      ids.forEach(sid => {
        (this.usersByServer[sid] || []).forEach(u => {
          rows.push({
            key: u.__key, server_id: Number(sid),
            server_name: this.serverMeta[sid]?.name ?? `Server ${sid}`,
            username: u.username ?? 'unknown',
            client_ip: u.client_ip ?? null, virtual_ip: u.virtual_ip ?? null,
            connected_human: u.connected_human ?? null, connected_fmt: u.connected_fmt ?? null,
            formatted_bytes: u.formatted_bytes ?? null, down_mb: u.down_mb ?? null, up_mb: u.up_mb ?? null,
          });
        });
      });
      return rows;
    },

    selectServer(id) { this.selectedServerId = id ? Number(id) : null; },

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
        let data; try { data = await res.json(); } catch { data = { message: await res.text() }; }
        if (!res.ok) throw new Error(data.message || 'Unknown error');

        this.usersByServer[row.server_id] =
          (this.usersByServer[row.server_id] || []).filter(u => u.username !== row.username);
        this.totals = this.computeTotals();
        alert(data.message || `Disconnected ${row.username}`);
      } catch (e) { alert('Error disconnecting user.\n\n' + (e.message || 'Unknown issue')); }
    },
  };
}
</script>