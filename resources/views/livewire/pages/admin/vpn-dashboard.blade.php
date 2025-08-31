{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}
{{-- VPN Dashboard — compact, mobile/desktop friendly, Echo/Reverb + Alpine, collapsible server filter --}}

<div
  x-data="vpnDashboard()"
  x-init="
    init(
      @js($servers->mapWithKeys(fn($s)=>[$s->id=>['id'=>$s->id,'name'=>$s->name]])),
      @js(
        $activeConnections->groupBy('vpn_server_id')->map(
          fn($g)=>$g->map(fn($c)=>[
            'connection_id'=>$c->id,
            'username'=>optional($c->vpnUser)->username ?? 'unknown',
            'client_ip'=>$c->client_ip,
            'virtual_ip'=>$c->virtual_ip,
            'connected_at'=>optional($c->connected_at)?->toIso8601String(),
            'bytes_in'=>(int) $c->bytes_received,
            'bytes_out'=>(int) $c->bytes_sent,
            'server_name'=>optional($c->vpnServer)->name,
          ])->values()
        )->toArray()
      )
    )
  "
  class="space-y-6"
>
  {{-- HEADER + TOOLBAR --}}
  <div class="flex items-end justify-between">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Live overview of users, servers & connections</p>
    </div>

    <div class="flex items-center gap-2">
      {{-- Filter toggle --}}
      <button
        class="aio-pill bg-white/5 border border-white/10 text-xs inline-flex items-center gap-1"
        @click="showFilters = !showFilters; try{localStorage.setItem('vpn.showFilters', showFilters ? '1':'0')}catch{}"
        :aria-expanded="showFilters"
      >
        <x-icon name="o-filter" class="w-4 h-4" /> {{-- or use o-list-bullet --}}
        Filter
      </button>

      {{-- Manual refresh --}}
      <button
        class="aio-pill pill-cya text-xs"
        @click.prevent="
          if(window.$wire?.getLiveStats){
            $el.disabled=true;
            window.$wire.getLiveStats()
              .then(()=>{ lastUpdated=new Date().toLocaleTimeString(); })
              .finally(()=>{ $el.disabled=false });
          }">
        Refresh
      </button>

      <div class="text-xs text-[var(--aio-sub)]">
        <span class="hidden sm:inline">Updated</span>
        <span class="font-medium text-[var(--aio-ink)]" x-text="lastUpdated"></span>
      </div>
    </div>
  </div>

  {{-- STAT TILES (with color + icons) --}}
<div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-4">
  <div class="pill-card outline-neon p-4 flex items-center gap-3">
    <x-icon name="o-user-group" class="w-6 h-6 text-[var(--aio-neon)]"/>
    <div>
      <div class="text-xs muted">Online</div>
      <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.online_users"></div>
    </div>
  </div>

  <div class="pill-card outline-cya p-4 flex items-center gap-3">
    <x-icon name="o-chart-bar" class="w-6 h-6 text-[var(--aio-cya)]"/>
    <div>
      <div class="text-xs muted">Connections</div>
      <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_connections"></div>
    </div>
  </div>

  <div class="pill-card outline-pup p-4 flex items-center gap-3">
    <x-icon name="o-server" class="w-6 h-6 text-[var(--aio-pup)]"/>
    <div>
      <div class="text-xs muted">Servers</div>
      <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_servers"></div>
    </div>
  </div>

  <div class="hidden lg:flex pill-card outline-mag p-4 items-center gap-3">
    <x-icon name="o-clock" class="w-6 h-6 text-[var(--aio-mag)]"/>
    <div>
      <div class="text-xs muted">Avg. Session</div>
      <div class="text-2xl font-semibold text-[var(--aio-ink)]">
        @if($activeConnections->count() > 0)
          {{ number_format($activeConnections->avg(fn($c)=> $c->connection_duration ?? 0)/60,1) }}m
        @else 0m @endif
      </div>
    </div>
  </div>
</div>

  {{-- SERVER FILTER (collapsible) --}}
<div
  x-show="showFilters"
  x-transition
  x-cloak
  @keydown.escape.window="showFilters=false"
  class="aio-card p-4 space-y-4"
>
  {{-- Header --}}
  <div class="flex items-center justify-between">
    <h3 class="text-base sm:text-lg font-semibold text-[var(--aio-ink)] flex items-center gap-2">
      <x-icon name="o-filter" class="h-4 w-4 text-[var(--aio-cya)]" />
      Filter by server
    </h3>
    <button class="text-xs aio-pill bg-white/5 hover:bg-white/10" @click="showFilters=false">
      Close
    </button>
  </div>

  {{-- Quick action: All --}}
  <div class="flex flex-wrap items-center gap-2">
    <button
      @click="selectServer(null)"
      class="aio-pill flex items-center gap-1"
      :class="selectedServerId===null ? 'pill-cya shadow-glow' : 'bg-white/5'"
      title="Show all servers"
    >
      <x-icon name="o-list-bullet" class="h-3.5 w-3.5" />
      All
      <span class="aio-pill ml-1"
            :class="totals.active_connections>0 ? 'pill-neon' : 'bg-white/10 text-[var(--aio-sub)]'">
        <span x-text="totals.active_connections"></span>
      </span>
    </button>
  </div>

  {{-- Individual server chips --}}
  <div class="flex flex-wrap gap-3">
    <template x-for="(meta, sid) in serverMeta" :key="sid">
      <button
        @click="selectServer(Number(sid))"
        class="aio-pill flex items-center gap-1 whitespace-nowrap"
        :class="selectedServerId===Number(sid) ? 'pill-pup shadow-glow' : 'bg-white/5'"
      >
        <x-icon name="o-server" class="h-3.5 w-3.5 text-[var(--aio-sub)]" />
        <span x-text="meta.name"></span>
        <span class="aio-pill ml-1"
              :class="(serverUsersCount(Number(sid))>0) ? 'pill-neon' : 'bg-white/10 text-[var(--aio-sub)]'"
              x-text="serverUsersCount(Number(sid))"></span>
      </button>
    </template>
  </div>
</div>

  {{-- ACTIVE CONNECTIONS --}}
<div class="aio-card overflow-hidden mt-6">
  <div class="px-4 py-3 border-b aio-divider flex items-center justify-between">
    <div class="text-lg font-semibold text-[var(--aio-ink)] flex items-center gap-2">
      <x-icon name="o-list-bullet" class="w-5 h-5 text-[var(--aio-cya)]"/>
      Active Connections
      <template x-if="selectedServerId">
        <span> — <span x-text="serverMeta[selectedServerId]?.name ?? 'Unknown'"></span></span>
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
                <span class="h-2.5 w-2.5 rounded-full bg-[var(--aio-neon)]"></span>
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
              <div class="aio-pill pill-cya text-xs" x-text="row.formatted_bytes ?? '—'"></div>
              <div class="text-xs muted">
                ↓<span x-text="row.down_mb ?? '0.00'"></span>MB ↑<span x-text="row.up_mb ?? '0.00'"></span>MB
              </div>
            </td>
            <td class="px-4 py-2">
              <button class="aio-pill pill-neon bg-red-500/15 text-red-300 hover:shadow-glow"
                      @click.prevent="disconnect(row)">
                Disconnect
              </button>
            </td>
          </tr>
        </template>
        <tr x-show="activeRows().length===0">
          <td colspan="7" class="px-4 py-6 text-center muted">No active connections</td>
        </tr>
      </tbody>
    </table>
  </div>
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

          <div class="mt-3 grid grid-cols-2 gap-3">
            <div>
              <div class="text-[10px] muted">Client IP</div>
              <div class="text-sm text-[var(--aio-ink)]" x-text="row.client_ip || '—'"></div>
            </div>
            <div>
              <div class="text-[10px] muted">Virtual IP</div>
              <div class="text-sm text-[var(--aio-ink)]" x-text="row.virtual_ip || '—'"></div>
            </div>
            <div>
              <div class="text-[10px] muted">Connected</div>
              <div class="text-sm text-[var(--aio-ink)]" x-text="row.connected_human || '—'"></div>
            </div>
            <div>
              <div class="text-[10px] muted">Transfer</div>
              <div class="text-sm text-[var(--aio-ink)]" x-text="row.formatted_bytes || '—'"></div>
            </div>
          </div>
        </div>
      </template>
      <div x-show="activeRows().length===0" class="p-6 text-center muted">No active connections</div>
    </div>
  </div>
</div>

{{-- Helper: hide scrollbars on iOS for pill filters --}}
<style>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>

<script>
window.vpnDashboard = function () {
  return {
    serverMeta: {},
    usersByServer: {},
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },

    selectedServerId: null,
    showFilters: false,
    lastUpdated: new Date().toLocaleTimeString(),

    _pollTimer: null,
    _subscribed: false,

    init(meta, seedUsersByServer) {
      this.serverMeta = meta || {};
      // restore persisted UI state
      try {
        this.showFilters = localStorage.getItem('vpn.showFilters') === '1';
        const saved = localStorage.getItem('vpn.selectedServerId');
        this.selectedServerId = saved === '' || saved === null ? null : Number(saved);
      } catch {}

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

    _waitForEcho() {
      return new Promise((resolve) => {
        const t = setInterval(() => { if (window.Echo) { clearInterval(t); resolve(); } }, 150);
        setTimeout(() => { clearInterval(t); resolve(); }, 3000);
      });
    },

    _subscribeFleet() {
      if (this._subscribed) return;
      try {
        window.Echo.private('servers.dashboard')
          .subscribed(() => console.log('✅ subscribed servers.dashboard'))
          .listen('.mgmt.update', e => this.handleEvent(e))
          .listen('mgmt.update',   e => this.handleEvent(e));
      } catch (e) { console.error('subscribe servers.dashboard failed', e); }
    },

    _subscribePerServer() {
      if (this._subscribed) return;
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
          for (const k in this.serverMeta) norm[+k] = this._normaliseUsers(+k, incoming[k] || []);
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
      const unique = new Set();
      let conns = 0;
      Object.keys(this.serverMeta).forEach(sid => {
        const arr = this.usersByServer[sid] || [];
        conns += arr.length;
        arr.forEach(u => unique.add(u.username));
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
            key: u.__key,
            connection_id: u.connection_id ?? null,
            server_id: Number(sid),
            server_name: this.serverMeta[sid]?.name ?? `Server ${sid}`,
            username: u.username ?? 'unknown',
            client_ip: u.client_ip ?? null,
            virtual_ip: u.virtual_ip ?? null,
            connected_human: u.connected_human ?? null,
            connected_fmt: u.connected_fmt ?? null,
            formatted_bytes: u.formatted_bytes ?? null,
            down_mb: u.down_mb ?? null,
            up_mb: u.up_mb ?? null,
          });
        });
      });
      return rows;
    },

    selectServer(id) {
      this.selectedServerId = (id === '' || id === null) ? null : Number(id);
      try { localStorage.setItem('vpn.selectedServerId', this.selectedServerId ?? ''); } catch {}
    },

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
        if (!res.ok) throw new Error(Array.isArray(data.output) ? data.output.join('\n') : (data.message || 'Unknown error'));

        this.usersByServer[row.server_id] = (this.usersByServer[row.server_id] || []).filter(u => u.username !== row.username);
        this.totals = this.computeTotals();
        alert(data.message || `Disconnected ${row.username}`);
      } catch (e) {
        console.error(e); alert('Error disconnecting user.\n\n' + (e.message || 'Unknown issue'));
      }
    },
  };
};
</script>