{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}
{{-- VPN Dashboard — compact, mobile/desktop friendly, Echo/Reverb + Alpine, collapsible server filter --}}

{{-- AIO styling helpers --}}
<style>
  :root{
    --aio-neon:#3dff7f;--aio-cya:#39d9ff;--aio-pup:#9a79ff;--aio-mag:#ff4fd8;
    --aio-ink:#e6e8ef;--aio-sub:#9aa3b2;
  }
  .muted{color:var(--aio-sub)}
  .aio-divider{border-color:rgba(255,255,255,.08)}
  .aio-pill{display:inline-flex;align-items:center;gap:.35rem;border-radius:9999px;padding:.25rem .6rem;font-weight:600;font-size:.75rem;line-height:1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08)}
  .pill-neon{background:rgba(61,255,127,.12);border-color:rgba(61,255,127,.35);color:var(--aio-ink)}
  .pill-cya{background:rgba(57,217,255,.12);border-color:rgba(57,217,255,.35);color:var(--aio-ink)}
  .pill-pup{background:rgba(154,121,255,.12);border-color:rgba(154,121,255,.35);color:var(--aio-ink)}
  .pill-mag{background:rgba(255,79,216,.12);border-color:rgba(255,79,216,.35);color:var(--aio-ink)}
  .pill-card{border-radius:.75rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08)}
  .outline-neon{box-shadow:inset 0 0 0 1px rgba(61,255,127,.25)}
  .outline-cya{box-shadow:inset 0 0 0 1px rgba(57,217,255,.25)}
  .outline-pup{box-shadow:inset 0 0 0 1px rgba(154,121,255,.25)}
  .outline-mag{box-shadow:inset 0 0 0 1px rgba(255,79,216,.25)}
  .shadow-glow{box-shadow:0 0 0 3px rgba(61,255,127,.15),0 6px 18px rgba(0,0,0,.35)}
  .no-scrollbar::-webkit-scrollbar{display:none}.no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
</style>

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
  {{-- HEADER --}}
  <div class="flex items-end justify-between">
    <div>
      <h1 class="text-2xl font-bold text-[var(--aio-ink)]">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Live overview of users, servers & connections</p>
    </div>
    <div class="flex items-center gap-2">
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

  {{-- STAT TILES --}}
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

  {{-- FILTER TOGGLE --}}
  <button
    class="aio-pill bg-white/5 border border-white/10 text-xs inline-flex items-center gap-1"
    @click="showFilters = !showFilters; try{localStorage.setItem('vpn.showFilters', showFilters ? '1':'0')}catch{}"
    :aria-expanded="showFilters">
    <x-icon name="o-filter" class="w-4 h-4" /> Filter
  </button>

  {{-- SERVER FILTER --}}
  <div x-show="showFilters" x-transition x-cloak class="aio-card p-4 space-y-4">
    <div class="flex items-center justify-between">
      <h3 class="text-base sm:text-lg font-semibold text-[var(--aio-ink)] flex items-center gap-2">
        <x-icon name="o-filter" class="h-4 w-4 text-[var(--aio-cya)]" /> Filter by server
      </h3>
      <button class="text-xs aio-pill bg-white/5 hover:bg-white/10" @click="showFilters=false">Close</button>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <button
        @click="selectServer(null)"
        class="aio-pill flex items-center gap-1"
        :class="selectedServerId===null ? 'pill-cya shadow-glow' : 'bg-white/5'">
        <x-icon name="o-list-bullet" class="h-3.5 w-3.5" /> All
        <span class="aio-pill ml-1"
              :class="totals.active_connections>0 ? 'pill-neon' : 'bg-white/10 text-[var(--aio-sub)]'"
              x-text="totals.active_connections"></span>
      </button>
    </div>
    <div class="flex flex-wrap gap-3">
      <template x-for="(meta, sid) in serverMeta" :key="sid">
        <button
          @click="selectServer(Number(sid))"
          class="aio-pill flex items-center gap-1 whitespace-nowrap"
          :class="selectedServerId===Number(sid) ? 'pill-pup shadow-glow' : 'bg-white/5'">
          <x-icon name="o-server" class="h-3.5 w-3.5 text-[var(--aio-sub)]" />
          <span x-text="meta.name"></span>
          <span class="aio-pill ml-1"
                :class="serverUsersCount(Number(sid))>0 ? 'pill-neon' : 'bg-white/10 text-[var(--aio-sub)]'"
                x-text="serverUsersCount(Number(sid))"></span>
        </button>
      </template>
    </div>
  </div>

  {{-- ACTIVE CONNECTIONS --}}
  <div class="aio-card overflow-hidden mt-6">
    <div class="px-4 py-3 border-b aio-divider flex items-center justify-between">
      <div class="text-lg font-semibold text-[var(--aio-ink)] flex items-center gap-2">
        <x-icon name="o-list-bullet" class="w-5 h-5 text-[var(--aio-cya)]"/> Active Connections
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
              <td class="px-4 py-2"><span class="font-medium text-[var(--aio-ink)]" x-text="row.username"></span></td>
              <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.server_name"></td>
              <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.client_ip || '—'"></td>
              <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.virtual_ip || '—' "></td>
              <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.connected_human ?? '—'"></td>
              <td class="px-4 py-2 text-[var(--aio-ink)]" x-text="row.formatted_bytes ?? '—'"></td>
              <td class="px-4 py-2">
                <button class="aio-pill bg-red-500/15 text-red-300 hover:shadow-glow"
                        @click.prevent="disconnect(row)">Disconnect</button>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    {{-- Mobile cards --}}
    <div class="md:hidden divide-y divide-white/10">
      <template x-for="row in activeRows()" :key="row.key">
        <div class="p-4 flex items-start justify-between gap-3">
          <div>
            <div class="font-medium text-[var(--aio-ink)]" x-text="row.username"></div>
            <div class="text-xs muted" x-text="row.server_name"></div>
          </div>
          <button class="aio-pill bg-red-500/15 text-red-300" @click.prevent="disconnect(row)">Disconnect</button>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
window.vpnDashboard = function () {
  // --- tiny formatters -------------------------------------------------------
  const toMB = n => (n ? (n / (1024 * 1024)).toFixed(2) : '0.00');
  const humanBytes = (inb, outb) => {
    const total = (inb || 0) + (outb || 0);
    if (total >= 1024 * 1024 * 1024) return (total / (1024*1024*1024)).toFixed(2) + ' GB';
    if (total >= 1024 * 1024)        return (total / (1024*1024)).toFixed(2) + ' MB';
    if (total >= 1024)               return (total / 1024).toFixed(2) + ' KB';
    return (total || 0) + ' B';
  };
  const fmtDate = iso => {
    try { return iso ? new Date(iso).toLocaleString() : '—'; } catch { return '—'; }
  };
  const ago = iso => {
    try {
      if (!iso) return '—';
      const d = new Date(iso), diff = Math.max(0, (Date.now() - d.getTime()) / 1000);
      const m = Math.floor(diff/60), h = Math.floor(m/60), dyy = Math.floor(h/24);
      if (dyy) return `${dyy} day${dyy>1?'s':''} ago`;
      if (h)   return `${h} hour${h>1?'s':''} ago`;
      if (m)   return `${m} min${m>1?'s':''} ago`;
      return 'just now';
    } catch { return '—'; }
  };

  // --- Alpine component ------------------------------------------------------
  return {
    serverMeta: {},                   // { [sid]: { id, name } }
    // store as maps so we can merge updates by username
    usersByServer: {},               // { [sid]: { [username]: row } }
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },

    selectedServerId: null,
    showFilters: false,
    lastUpdated: new Date().toLocaleTimeString(),

    _pollTimer: null,
    _subscribed: false,

    // ---- lifecycle ----------------------------------------------------------
    init(meta, seedUsersByServer) {
      this.serverMeta = meta || {};
      Object.keys(this.serverMeta).forEach(sid => this.usersByServer[sid] = {});

      // seed from Blade (rich rows)
      if (seedUsersByServer) {
        for (const k in seedUsersByServer) {
          this._setExactList(+k, seedUsersByServer[k]);  // normalises + fills map
        }
      }
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();

      // subscribe & poll
      this._waitForEcho().then(() => { this._subscribeFleet(); this._subscribePerServer(); });
      this._startPolling(15000);
    },

    _waitForEcho() {
      return new Promise(resolve => {
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
      } catch (e) { console.warn('subscribe fleet failed', e); }
    },

    _subscribePerServer() {
      if (this._subscribed) return;
      Object.keys(this.serverMeta).forEach(sid => {
        try {
          window.Echo.private(`servers.${sid}`)
            .subscribed(() => console.log(`✅ subscribed servers.${sid}`))
            .listen('.mgmt.update', e => this.handleEvent(e))
            .listen('mgmt.update',   e => this.handleEvent(e));
        } catch (e) { console.warn(`subscribe ${sid} failed`, e); }
      });
      this._subscribed = true;
    },

    _startPolling(ms) {
      if (this._pollTimer) clearInterval(this._pollTimer);
      this._pollTimer = setInterval(async () => {
        if (!window.$wire?.getLiveStats) return;
        try {
          const res = await window.$wire.getLiveStats();
          const incoming = res?.usersByServer || {};
          for (const sidStr of Object.keys(this.serverMeta)) {
            const sid = +sidStr;
            this._setExactList(sid, incoming[sid] || []);
          }
          this.totals = this.computeTotals();
          this.lastUpdated = new Date().toLocaleTimeString();
        } catch {}
      }, ms);
    },

    // ---- shaping & merging --------------------------------------------------
    _shapeRow(serverId, raw) {
      const meta = this.serverMeta[serverId] || {};
      const username = (raw?.username ?? raw?.cn ?? 'unknown') + '';
      const connected_at = raw?.connected_at || raw?.connectedAt || null;

      const bytes_in  = Number(raw?.bytes_in  ?? raw?.bytesIn  ?? raw?.bytes_received ?? 0);
      const bytes_out = Number(raw?.bytes_out ?? raw?.bytesOut ?? raw?.bytes_sent     ?? 0);

      const row = {
        // identity
        key: `${serverId}:${username}`,
        connection_id: raw?.connection_id ?? raw?.id ?? null,   // mgmt client-id if you have it
        username,
        // server
        server_id: Number(serverId),
        server_name: meta.name || raw?.server_name || `Server ${serverId}`,
        // networking
        client_ip:  raw?.client_ip  ?? null,
        virtual_ip: raw?.virtual_ip ?? null,
        // time
        connected_at,
        connected_fmt: fmtDate(connected_at),
        connected_human: ago(connected_at),
        // traffic
        bytes_in, bytes_out,
        down_mb: toMB(bytes_in),
        up_mb:   toMB(bytes_out),
        formatted_bytes: humanBytes(bytes_in, bytes_out),
      };
      return row;
    },

    // Replace the list for a server with exactly the given users (merge details if we already have them)
    _setExactList(serverId, list) {
      const map = {};
      const arr = Array.isArray(list) ? list : [];

      // If we only get bare usernames (strings), turn them into objects first
      const normalised = arr.map(u => typeof u === 'string' ? { username: u } : u);

      // Build fresh map, merging with any existing details per username
      const prev = this.usersByServer[serverId] || {};
      normalised.forEach(raw => {
        const shaped = this._shapeRow(serverId, { ...(prev[raw.username] || {}), ...raw });
        map[shaped.username] = shaped;
      });

      this.usersByServer[serverId] = map;
    },

    // ---- events -------------------------------------------------------------
    handleEvent(e) {
      const sid = Number(e.server_id ?? e.serverId ?? 0);
      if (!sid) return;

      let list = [];
      if (Array.isArray(e.users) && e.users.length) {
        list = e.users;                      // may be rich objects
      } else if (typeof e.cn_list === 'string') {
        list = e.cn_list.split(',').map(s => s.trim()).filter(Boolean); // usernames only
      }

      this._setExactList(sid, list);
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();
    },

    // ---- derived data for UI -----------------------------------------------
    computeTotals() {
      const unique = new Set();
      let conns = 0, activeServers = 0;
      Object.keys(this.serverMeta).forEach(sid => {
        const map = this.usersByServer[sid] || {};
        const arr = Object.values(map);
        if (arr.length) activeServers++;
        conns += arr.length;
        arr.forEach(u => unique.add(u.username));
      });
      return { online_users: unique.size, active_connections: conns, active_servers: activeServers };
    },

    serverUsersCount(id) { return Object.values(this.usersByServer[id] || {}).length; },

    activeRows() {
      const ids = this.selectedServerId == null ? Object.keys(this.serverMeta) : [String(this.selectedServerId)];
      const rows = [];
      ids.forEach(sid => rows.push(...Object.values(this.usersByServer[sid] || {})));
      // stable sort by server then username
      rows.sort((a,b) => (a.server_name||'').localeCompare(b.server_name||'') || (a.username||'').localeCompare(b.username||''));
      return rows;
    },

    selectServer(id) {
      this.selectedServerId = (id === null || id === '') ? null : Number(id);
      try { localStorage.setItem('vpn.selectedServerId', this.selectedServerId ?? ''); } catch {}
    },

    // ---- actions ------------------------------------------------------------
    async disconnect(row) {
      if (!confirm(`Disconnect ${row.username} from ${row.server_name}?`)) return;

      try {
        // Prefer mgmt client_id route
        const url = `/admin/servers/${row.server_id}/disconnect`;
        const res = await fetch(url, {
          method: 'POST',
          headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ client_id: row.connection_id }),
        });

        // fallback to old endpoint if needed (username based)
        if (!res.ok) {
          const res2 = await fetch(fallback, {
            method: 'POST',
            headers: {
              'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({ username: row.username, server_id: row.server_id }),
          });
          if (!res2.ok) {
            let data2; try { data2 = await res2.json(); } catch { data2 = { message: await res2.text() }; }
            throw new Error(Array.isArray(data2?.output) ? data2.output.join('\n') : (data2?.message || 'Unknown error'));
          }
        }

        // remove from UI
        const map = this.usersByServer[row.server_id] || {};
        delete map[row.username];
        this.usersByServer[row.server_id] = map;
        this.totals = this.computeTotals();

        alert(`Disconnected ${row.username}`);
      } catch (e) {
        console.error(e);
        alert('Error disconnecting user.\n\n' + (e.message || 'Unknown issue'));
      }
    },
  };
};
</script>