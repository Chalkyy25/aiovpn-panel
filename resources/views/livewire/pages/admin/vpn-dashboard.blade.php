{{-- resources/views/livewire/pages/admin/vpn-dashboard.blade.php --}}

<div
  x-data="vpnDashboard(@this)"
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
            'protocol'=>$c->protocol ?? null,
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
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div class="min-w-0">
      <h1 class="text-2xl font-bold text-[var(--aio-ink)] truncate">VPN Dashboard</h1>
      <p class="text-sm text-[var(--aio-sub)]">Live overview of users, servers & connections</p>
    </div>

    <div class="flex items-center gap-3 shrink-0">
      <x-button
        type="button"
        variant="secondary"
        size="sm"
        x-bind:disabled="refreshing"
        @click.prevent="refreshNow()"
      >
        <span x-show="!refreshing">Refresh</span>
        <span x-show="refreshing" x-cloak>Refreshing…</span>
      </x-button>

      <div class="text-xs text-[var(--aio-sub)] text-right">
        <span class="hidden sm:inline">Updated</span>
        <span class="font-medium text-[var(--aio-ink)]" x-text="lastUpdated"></span>
      </div>
    </div>
  </div>

  {{-- STAT TILES --}}
  <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-4">
    <div class="aio-card p-4 flex items-center gap-3">
      <x-icon name="o-user-group" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Online</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.online_users"></div>
      </div>
    </div>

    <div class="aio-card p-4 flex items-center gap-3">
      <x-icon name="o-chart-bar" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Connections</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_connections"></div>
      </div>
    </div>

    <div class="aio-card p-4 flex items-center gap-3">
      <x-icon name="o-server" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Servers</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]" x-text="totals.active_servers"></div>
      </div>
    </div>

    <div class="hidden lg:flex aio-card p-4 items-center gap-3">
      <x-icon name="o-clock" class="w-6 h-6 text-[var(--aio-accent)]"/>
      <div>
        <div class="text-xs text-[var(--aio-sub)]">Avg. Session</div>
        <div class="text-2xl font-semibold text-[var(--aio-ink)]">
          @if($activeConnections->count() > 0)
            {{ number_format($activeConnections->avg(fn($c)=> $c->connection_duration ?? 0)/60,1) }}m
          @else
            0m
          @endif
        </div>
      </div>
    </div>
  </div>

  {{-- FILTER --}}
  <div class="flex items-center justify-between gap-3">
    <x-button
      type="button"
      variant="secondary"
      size="sm"
      @click="toggleFilters()"
      x-bind:aria-expanded="showFilters"
      class="gap-2"
    >
      <x-icon name="o-filter" class="w-4 h-4" />
      Filter
    </x-button>

    <div class="text-xs text-[var(--aio-sub)]" x-show="selectedServerId !== null" x-cloak>
      Showing:
      <span class="font-medium text-[var(--aio-ink)]" x-text="serverMeta[selectedServerId]?.name ?? 'Unknown'"></span>
    </div>
  </div>

  {{-- SERVER FILTER PANEL --}}
<div x-show="showFilters" x-transition x-cloak class="aio-card p-4 space-y-4">
  <div class="flex items-center justify-between gap-3">
    <h3 class="text-base font-semibold text-[var(--aio-ink)] flex items-center gap-2">
      <x-icon name="o-filter" class="h-4 w-4 text-[var(--aio-accent)]" />
      Filter by server
    </h3>

    <x-button type="button" variant="ghost" size="sm" @click="showFilters=false">
      Close
    </x-button>
  </div>

  <div class="flex flex-wrap gap-2">
    {{-- All --}}
    <button
      type="button"
      @click="selectServer(null)"
      class="aio-pill"
      :class="selectedServerId===null ? 'ring-2 ring-[var(--aio-accent)]' : ''"
    >
      All
      <x-badge tone="blue" size="sm">
        <span x-text="totals.active_connections"></span>
      </x-badge>
    </button>

    {{-- Per server --}}
    <template x-for="(meta, sid) in serverMeta" :key="sid">
      <button
        type="button"
        @click="selectServer(Number(sid))"
        class="aio-pill"
        :class="selectedServerId===Number(sid) ? 'ring-2 ring-[var(--aio-accent)]' : ''"
      >
        <span x-text="meta.name"></span>
        <x-badge tone="slate" size="sm">
          <span x-text="serverUsersCount(Number(sid))"></span>
        </x-badge>
      </button>
    </template>
  </div>
</div>

  {{-- ACTIVE CONNECTIONS (Desktop table) --}}
  <x-table title="Active Connections" subtitle="Live connections across all servers">
    <thead class="hidden md:table-header-group">
      <tr>
        <th>User</th>
        <th>Server</th>
        <th>Client IP</th>
        <th>Virtual IP</th>
        <th>Protocol</th>
        <th>Connected</th>
        <th>Transfer</th>
        <th class="cell-right">Actions</th>
      </tr>
    </thead>

    <tbody class="hidden md:table-row-group">
      <template x-for="row in activeRows()" :key="row.__key">
        <tr>
          <td><span class="font-medium" x-text="row.username"></span></td>
          <td class="cell-muted" x-text="row.server_name"></td>
          <td x-text="row.client_ip || '—'"></td>
          <td x-text="row.virtual_ip || '—'"></td>

          <td>
  <x-badge tone="slate" size="sm">
    <span x-text="(row.protocol || 'OPENVPN').toUpperCase()"></span>
  </x-badge>
</td>

          <td x-text="row.connected_human ?? '—'"></td>
          <td x-text="row.formatted_bytes ?? '—'"></td>

          <td class="cell-right">
            <x-button type="button" variant="danger" size="sm" @click.prevent="disconnect(row)">
              Disconnect
            </x-button>
          </td>
        </tr>
      </template>

      <tr x-show="activeRows().length===0" x-cloak>
        <td colspan="8" class="text-center text-[var(--aio-sub)] py-6">
          No active connections
        </td>
      </tr>
    </tbody>
  </x-table>

  {{-- Mobile cards --}}
  <div class="md:hidden aio-card overflow-hidden">
    <div class="px-4 py-3 border-b border-[var(--aio-border)] flex items-center justify-between">
      <div class="text-sm font-semibold text-[var(--aio-ink)]">Active Connections</div>
      <div class="text-xs text-[var(--aio-sub)]">
        <span x-text="activeRows().length"></span> rows
      </div>
    </div>

    <div class="divide-y" style="border-color: var(--aio-border)">
      <template x-for="row in activeRows()" :key="row.__key">
        <div class="p-4 space-y-3">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="font-medium text-[var(--aio-ink)] truncate" x-text="row.username"></div>
              <div class="text-xs text-[var(--aio-sub)] truncate" x-text="row.server_name"></div>

              <div class="mt-2">
  <x-badge tone="slate" size="sm">
    <span x-text="(row.protocol || 'OPENVPN').toUpperCase()"></span>
  </x-badge>
</div>
            </div>

            <x-button type="button" variant="danger" size="sm" @click.prevent="disconnect(row)">
              Disconnect
            </x-button>
          </div>

          <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Client IP</div>
              <div x-text="row.client_ip || '—'"></div>
            </div>

            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Virtual IP</div>
              <div x-text="row.virtual_ip || '—'"></div>
            </div>

            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Connected</div>
              <div x-text="row.connected_human || '—'"></div>
            </div>

            <div>
              <div class="text-[10px] text-[var(--aio-sub)]">Transfer</div>
              <div x-text="row.formatted_bytes || '—'"></div>
              <div class="text-[10px] text-[var(--aio-sub)]">
                ↓<span x-text="row.down_mb || '0.00'"></span>MB
                ↑<span x-text="row.up_mb || '0.00'"></span>MB
              </div>
            </div>
          </div>
        </div>
      </template>

      <div x-show="activeRows().length===0" x-cloak class="p-6 text-center text-[var(--aio-sub)]">
        No active connections
      </div>
    </div>
  </div>

</div>

{{-- KEEP YOUR EXISTING SCRIPT EXACTLY AS-IS --}}
<script>
  const vpnDisconnectFallbackPattern =
    @json(route('admin.servers.disconnect', ['server' => '__SID__']));
  const fallbackUrl = (sid) => vpnDisconnectFallbackPattern.replace('__SID__', String(sid));

  window.vpnDashboard = function (lw) {
    const toMB = n => (n ? (n / (1024 * 1024)).toFixed(2) : '0.00');

    const humanBytes = (inb, outb) => {
      const total = (inb || 0) + (outb || 0);
      if (total >= 1024 * 1024 * 1024) return (total / (1024*1024*1024)).toFixed(2) + ' GB';
      if (total >= 1024 * 1024)        return (total / (1024*1024)).toFixed(2) + ' MB';
      if (total >= 1024)               return (total / 1024).toFixed(2) + ' KB';
      return (total || 0) + ' B';
    };

    const toDate = (v) => {
      if (!v) return null;
      if (typeof v === 'number') return new Date(v * 1000);
      return new Date(v);
    };

    const ago = (v) => {
      try {
        const d = toDate(v); if (!d) return '—';
        const diff = Math.max(0, (Date.now() - d.getTime()) / 1000);
        const m = Math.floor(diff/60), h = Math.floor(m/60), dd = Math.floor(h/24);
        if (dd) return `${dd} day${dd>1?'s':''} ago`;
        if (h)  return `${h} hour${h>1?'s':''} ago`;
        if (m)  return `${m} min${m>1?'s':''} ago`;
        return 'just now';
      } catch { return '—'; }
    };

    return {
      lw,
      refreshing: false,

      serverMeta: {},
      usersByServer: {}, // { [sid]: { [stableKey]: row } }
      totals: { online_users: 0, active_connections: 0, active_servers: 0 },

      selectedServerId: null,
      showFilters: false,
      lastUpdated: new Date().toLocaleTimeString(),

      _pollTimer: null,
      _subscribed: false,

      init(meta, seedUsersByServer) {
        this.serverMeta = meta || {};
        Object.keys(this.serverMeta).forEach(sid => this.usersByServer[sid] = {});

        if (seedUsersByServer) {
          for (const sid in seedUsersByServer) {
            this._setExactList(Number(sid), seedUsersByServer[sid] || []);
          }
        }

        this.totals = this.computeTotals();
        this.lastUpdated = new Date().toLocaleTimeString();

        this._waitForEcho().then(() => {
          this._subscribeFleet();
          this._subscribePerServer();
        });

        this._startPolling(15000);

        try {
          const savedSid = localStorage.getItem('vpn.selectedServerId');
          if (savedSid !== null && savedSid !== '') this.selectedServerId = Number(savedSid);
          const sf = localStorage.getItem('vpn.showFilters');
          if (sf !== null) this.showFilters = sf === '1';
        } catch {}
      },

      toggleFilters() {
        this.showFilters = !this.showFilters;
        try { localStorage.setItem('vpn.showFilters', this.showFilters ? '1' : '0'); } catch {}
      },

      async refreshNow() {
        if (this.refreshing) return;
        this.refreshing = true;

        try {
          const res = await this.lw.call('getLiveStats');

          if (res?.usersByServer) {
            for (const sid in this.serverMeta) {
              this._setExactList(Number(sid), res.usersByServer[sid] || []);
            }
            this.totals = this.computeTotals();
          }

          this.lastUpdated = new Date().toLocaleTimeString();
        } catch (e) {
          console.error(e);
        } finally {
          this.refreshing = false;
        }
      },

      _waitForEcho() {
        return new Promise(resolve => {
          const t = setInterval(() => {
            if (window.Echo) { clearInterval(t); resolve(); }
          }, 150);
          setTimeout(() => { clearInterval(t); resolve(); }, 3000);
        });
      },

      _subscribeFleet() {
        if (this._subscribed) return;
        try {
          window.Echo.private('servers.dashboard')
            .listen('.mgmt.update', e => this.handleEvent(e))
            .listen('mgmt.update',   e => this.handleEvent(e));
        } catch (_) {}
      },

      _subscribePerServer() {
        if (this._subscribed) return;

        Object.keys(this.serverMeta).forEach(sid => {
          try {
            window.Echo.private(`servers.${sid}`)
              .listen('.mgmt.update', e => this.handleEvent(e))
              .listen('mgmt.update',   e => this.handleEvent(e));
          } catch (_) {}
        });

        this._subscribed = true;
      },

      _startPolling(ms) {
        if (this._pollTimer) clearInterval(this._pollTimer);
        this._pollTimer = setInterval(() => this.refreshNow(), ms);
      },

      _shapeProtocol(raw) {
        const protoRaw = (raw?.protocol || raw?.proto || '').toString().toLowerCase();
        if (protoRaw.startsWith('wire')) return 'WIREGUARD';
        if (protoRaw === 'ovpn' || protoRaw.startsWith('openvpn')) return 'OPENVPN';
        if (protoRaw) return protoRaw.toUpperCase();

        // fallback detection
        const u = (raw?.username || '').toString();
        if (/^[A-Za-z0-9+/=]{40,}$/.test(u)) return 'WIREGUARD';
        return 'OPENVPN';
      },

      _stableKey(serverId, raw, protocol, username) {
        const sessionKey = raw?.session_key ?? raw?.sessionKey ?? null;
        const cid = raw?.connection_id ?? raw?.id ?? null;

        if (sessionKey) return `sk:${sessionKey}`;
        if (cid !== null && cid !== undefined) return `cid:${cid}`;
        return `u:${serverId}:${username}:${protocol}`;
      },

   _shapeRow(serverId, raw, prevRow = null) {
  const meta = this.serverMeta[serverId] || {};

  const protocol = this._shapeProtocol(raw);
  const username = String(raw?.username ?? raw?.cn ?? prevRow?.username ?? 'unknown');

  // ✅ FIX: protocol-aware timestamp
  let connected_at;

  if (protocol === 'WIREGUARD') {
    // WG = last seen / handshake time
    connected_at =
      raw?.seen_at ??
      raw?.seenAt ??
      raw?.updated_at ??
      raw?.updatedAt ??
      prevRow?.connected_at ??
      null;
  } else {
    // OpenVPN = real session start
    connected_at =
      raw?.connected_at ??
      raw?.connectedAt ??
      prevRow?.connected_at ??
      null;
  }

  const bytes_in  = Number(raw?.bytes_in  ?? raw?.bytesIn  ?? raw?.bytes_received ?? prevRow?.bytes_in  ?? 0);
  const bytes_out = Number(raw?.bytes_out ?? raw?.bytesOut ?? raw?.bytes_sent     ?? prevRow?.bytes_out ?? 0);

  return {
    __key: `${serverId}:${username}:${protocol}`,
    connection_id: raw?.connection_id ?? raw?.id ?? prevRow?.connection_id ?? null,

    server_id: Number(serverId),
    server_name: meta.name || raw?.server_name || `Server ${serverId}`,

    username,
    client_ip:  raw?.client_ip  ?? prevRow?.client_ip  ?? null,
    virtual_ip: raw?.virtual_ip ?? prevRow?.virtual_ip ?? null,
    protocol,

    connected_at,
    connected_human: ago(connected_at),

    bytes_in,
    bytes_out,
    down_mb: toMB(bytes_in),
    up_mb:   toMB(bytes_out),
    formatted_bytes: humanBytes(bytes_in, bytes_out),
  };
}

      _setExactList(serverId, list) {
        const prevMap = this.usersByServer[serverId] || {};
        const arr = Array.isArray(list) ? list : [];
        const nextMap = {};

        // Build lookup indexes for old rows
        const prevBySession = new Map();
        const prevByConnId  = new Map();
        const prevByUserProto = new Map();

        Object.values(prevMap).forEach(r => {
          if (r.session_key) prevBySession.set(r.session_key, r);
          if (r.connection_id !== null && r.connection_id !== undefined) prevByConnId.set(String(r.connection_id), r);
          prevByUserProto.set(`${r.username}|${r.protocol}`, r);
        });

        arr.forEach(raw0 => {
          const raw = (typeof raw0 === 'string') ? { username: raw0 } : (raw0 || {});
          const protocol = this._shapeProtocol(raw);
          const username = String(raw?.username ?? raw?.cn ?? 'unknown');

          const sk = raw?.session_key ?? raw?.sessionKey ?? null;
          const cid = raw?.connection_id ?? raw?.id ?? null;

          const prevRow =
            (sk && prevBySession.get(sk)) ||
            (cid !== null && cid !== undefined && prevByConnId.get(String(cid))) ||
            prevByUserProto.get(`${username}|${protocol}`) ||
            null;

          const shaped = this._shapeRow(serverId, raw, prevRow);
          nextMap[shaped.__key] = shaped;
        });

        this.usersByServer[serverId] = nextMap;
      },

      handleEvent(e) {
        const sid = Number(e.server_id ?? e.serverId ?? 0);
        if (!sid) return;

        let list = [];
        if (Array.isArray(e.users) && e.users.length) {
          list = e.users;
        } else if (typeof e.cn_list === 'string') {
          list = e.cn_list.split(',').map(s => s.trim()).filter(Boolean);
        }

        this._setExactList(sid, list);
        this.totals = this.computeTotals();
        this.lastUpdated = new Date().toLocaleTimeString();
      },

      computeTotals() {
        const unique = new Set();
        let conns = 0, activeServers = 0;

        Object.keys(this.serverMeta).forEach(sid => {
          const arr = Object.values(this.usersByServer[sid] || {});
          if (arr.length) activeServers++;
          conns += arr.length;
          arr.forEach(u => unique.add(u.username));
        });

        if (activeServers === 0) activeServers = Object.keys(this.serverMeta).length;

        return {
          online_users: unique.size,
          active_connections: conns,
          active_servers: activeServers,
        };
      },

      serverUsersCount(id) {
        return Object.values(this.usersByServer[id] || {}).length;
      },

      activeRows() {
        const ids = this.selectedServerId == null
          ? Object.keys(this.serverMeta)
          : [String(this.selectedServerId)];

        const rows = [];
        ids.forEach(sid => rows.push(...Object.values(this.usersByServer[sid] || {})));

        rows.sort((a,b) =>
          (a.server_name||'').localeCompare(b.server_name||'') ||
          (a.username||'').localeCompare(b.username||'')
        );

        return rows;
      },

      selectServer(id) {
        this.selectedServerId = (id === null || id === '') ? null : Number(id);
        try { localStorage.setItem('vpn.selectedServerId', this.selectedServerId ?? ''); } catch {}
      },

      async disconnect(row) {
        if (!confirm(`Disconnect ${row.username} from ${row.server_name}?`)) return;

        try {
          const baseHeaders = {
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            'Content-Type': 'application/json',
          };

          // Prefer connection_id (OpenVPN management kill). For WG you probably want session_key/public_key
          let res = await fetch(`/admin/servers/${row.server_id}/disconnect`, {
            method: 'POST',
            headers: baseHeaders,
            body: JSON.stringify({
              client_id: row.connection_id,
              session_key: row.session_key,
              username: row.username,
              protocol: row.protocol
            }),
          });

          if (!res.ok) {
            const res2 = await fetch(fallbackUrl(row.server_id), {
              method: 'POST',
              headers: baseHeaders,
              body: JSON.stringify({ username: row.username, server_id: row.server_id }),
            });

            if (!res2.ok) {
              let data2;
              try { data2 = await res2.json(); } catch { data2 = { message: await res2.text() }; }
              throw new Error(Array.isArray(data2?.output) ? data2.output.join('\n') : (data2?.message || 'Unknown error'));
            }
          }

          const map = this.usersByServer[row.server_id] || {};
          delete map[row.__key];
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