<script>
  const vpnDisconnectFallbackPattern =
    @json(route('admin.servers.disconnect', ['server' => '__SID__']));
  const fallbackUrl = (sid) => vpnDisconnectFallbackPattern.replace('__SID__', String(sid));

  window.vpnDashboard = function (lw) {
    // ---------------- helpers ----------------
    const toMB = (n) => (n ? (n / (1024 * 1024)).toFixed(2) : '0.00');

    const humanBytes = (inb, outb) => {
      const total = (inb || 0) + (outb || 0);
      if (total >= 1024 ** 3) return (total / 1024 ** 3).toFixed(2) + ' GB';
      if (total >= 1024 ** 2) return (total / 1024 ** 2).toFixed(2) + ' MB';
      if (total >= 1024)      return (total / 1024).toFixed(2) + ' KB';
      return (total || 0) + ' B';
    };

    const toDate = (v) => {
      if (!v) return null;
      if (typeof v === 'number') return new Date(v * 1000);
      const d = new Date(v);
      return isNaN(d) ? null : d;
    };

    const ago = (v) => {
      const d = toDate(v);
      if (!d) return '—';
      const diff = Math.max(0, (Date.now() - d.getTime()) / 1000);
      const m = Math.floor(diff / 60);
      const h = Math.floor(m / 60);
      const dd = Math.floor(h / 24);
      if (dd) return `${dd} day${dd > 1 ? 's' : ''} ago`;
      if (h)  return `${h} hour${h > 1 ? 's' : ''} ago`;
      if (m)  return `${m} min${m > 1 ? 's' : ''} ago`;
      return 'just now';
    };

    const isBlank = (v) =>
      v === null || v === undefined || v === '' || (typeof v === 'number' && Number.isNaN(v));

    const pick = (primary, fallback) => (isBlank(primary) ? fallback : primary);

    // ---------------- dashboard object ----------------
    return {
      lw,
      refreshing: false,

      serverMeta: {},
      usersByServer: {}, // { [sid]: { [key]: row } }
      totals: { online_users: 0, active_connections: 0, active_servers: 0 },

      selectedServerId: null,
      showFilters: false,
      lastUpdated: new Date().toLocaleTimeString(),

      _pollTimer: null,
      _subscribed: false,

      // -------- init --------
      init(meta, seedUsersByServer) {
        this.serverMeta = meta || {};
        Object.keys(this.serverMeta).forEach((sid) => (this.usersByServer[sid] = {}));

        // seed from server-rendered snapshot
        if (seedUsersByServer) {
          for (const sid in seedUsersByServer) {
            this._mergeList(Number(sid), seedUsersByServer[sid] || []);
          }
        }

        this._recalc();
        this.lastUpdated = new Date().toLocaleTimeString();

        this._waitForEcho().then(() => {
          this._subscribe();
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
              this._mergeList(Number(sid), res.usersByServer[sid] || []);
            }
            this._recalc();
          }
          this.lastUpdated = new Date().toLocaleTimeString();
        } catch (e) {
          console.error(e);
        } finally {
          this.refreshing = false;
        }
      },

      // -------- echo --------
      _waitForEcho() {
        return new Promise((resolve) => {
          const t = setInterval(() => {
            if (window.Echo) { clearInterval(t); resolve(); }
          }, 150);
          setTimeout(() => { clearInterval(t); resolve(); }, 3000);
        });
      },

      _subscribe() {
        if (this._subscribed) return;

        // ✅ subscribe once, and only to ".mgmt.update" (avoid double events)
        try {
          window.Echo.private('servers.dashboard')
            .listen('.mgmt.update', (e) => this.handleEvent(e));
        } catch (_) {}

        Object.keys(this.serverMeta).forEach((sid) => {
          try {
            window.Echo.private(`servers.${sid}`)
              .listen('.mgmt.update', (e) => this.handleEvent(e));
          } catch (_) {}
        });

        this._subscribed = true;
      },

      _startPolling(ms) {
        if (this._pollTimer) clearInterval(this._pollTimer);
        this._pollTimer = setInterval(() => this.refreshNow(), ms);
      },

      // -------- shaping --------
      _shapeProtocol(raw) {
        const p = String(raw?.protocol ?? raw?.proto ?? '').toLowerCase();
        if (p.startsWith('wire')) return 'WIREGUARD';
        if (p === 'ovpn' || p.startsWith('openvpn')) return 'OPENVPN';
        return p ? p.toUpperCase() : 'OPENVPN';
      },

      _stableKey(serverId, raw, protocol, username) {
        const sk  = raw?.session_key ?? raw?.sessionKey ?? null;
        const cid = raw?.connection_id ?? raw?.id ?? null;

        if (!isBlank(sk))  return `sk:${sk}`;
        if (!isBlank(cid)) return `cid:${cid}`;
        return `u:${serverId}:${username}:${protocol}`;
      },

      _shapeRow(serverId, raw, prevRow = null) {
        const meta = this.serverMeta[serverId] || {};

        const protocol = this._shapeProtocol(raw);
        const username = String(raw?.username ?? raw?.cn ?? prevRow?.username ?? 'unknown');

        const session_key   = pick(raw?.session_key ?? raw?.sessionKey, prevRow?.session_key);
        const connection_id = pick(raw?.connection_id ?? raw?.id, prevRow?.connection_id);

        // ✅ timestamp rules:
        // OpenVPN: connected_at = session start (don’t overwrite with null)
        // WireGuard: connected_at = last seen (seen_at) (don’t overwrite with null)
        let connected_at = prevRow?.connected_at ?? null;

        if (protocol === 'WIREGUARD') {
          connected_at = pick(
            raw?.seen_at ?? raw?.seenAt ?? raw?.connected_at ?? raw?.connectedAt ?? raw?.updated_at ?? raw?.updatedAt,
            connected_at
          );
        } else {
          connected_at = pick(raw?.connected_at ?? raw?.connectedAt, connected_at);
        }

        // ✅ bytes: never go backwards to 0 just because an event didn’t include them
        const bytes_in = Number(pick(
          raw?.bytes_in ?? raw?.bytesIn ?? raw?.bytes_received ?? raw?.bytesReceived,
          prevRow?.bytes_in ?? 0
        ));

        const bytes_out = Number(pick(
          raw?.bytes_out ?? raw?.bytesOut ?? raw?.bytes_sent ?? raw?.bytesSent,
          prevRow?.bytes_out ?? 0
        ));

        const __key = this._stableKey(serverId, raw, protocol, username);

        return {
          __key,

          // keep these (disconnect + stability)
          session_key,
          connection_id,

          server_id: Number(serverId),
          server_name: pick(raw?.server_name, meta.name) || `Server ${serverId}`,

          username,
          protocol,

          client_ip:  pick(raw?.client_ip,  prevRow?.client_ip)  ?? null,
          virtual_ip: pick(raw?.virtual_ip, prevRow?.virtual_ip) ?? null,

          connected_at,
          connected_human: ago(connected_at),

          bytes_in,
          bytes_out,
          down_mb: toMB(bytes_in),
          up_mb:   toMB(bytes_out),
          formatted_bytes: humanBytes(bytes_in, bytes_out),
        };
      },

      // ✅ merge list instead of replacing hard
      _mergeList(serverId, list) {
        const prevMap = this.usersByServer[serverId] || {};
        const nextMap = { ...prevMap };

        const arr = Array.isArray(list) ? list : [];

        arr.forEach((raw0) => {
          const raw = (typeof raw0 === 'string') ? { username: raw0 } : (raw0 || {});
          const protocol = this._shapeProtocol(raw);
          const username = String(raw?.username ?? raw?.cn ?? 'unknown');

          const key = this._stableKey(serverId, raw, protocol, username);
          const prevRow = prevMap[key] || null;

          nextMap[key] = this._shapeRow(serverId, raw, prevRow);
        });

        // 🔥 IMPORTANT: we do NOT delete rows here.
        // Deletion should happen only when backend explicitly says someone disconnected,
        // otherwise polling/echo races cause flicker.
        this.usersByServer[serverId] = nextMap;
      },

      handleEvent(e) {
        const sid = Number(e.server_id ?? e.serverId ?? 0);
        if (!sid) return;

        let list = [];
        if (Array.isArray(e.users)) {
          list = e.users;
        } else if (typeof e.cn_list === 'string') {
          list = e.cn_list.split(',').map((s) => ({ username: s.trim() })).filter((x) => x.username);
        }

        this._mergeList(sid, list);
        this._recalc();
        this.lastUpdated = new Date().toLocaleTimeString();
      },

      _recalc() {
        this.totals = this.computeTotals();
      },

      computeTotals() {
        const unique = new Set();
        let conns = 0;
        let activeServers = 0;

        Object.keys(this.serverMeta).forEach((sid) => {
          const arr = Object.values(this.usersByServer[sid] || {});
          if (arr.length) activeServers++;
          conns += arr.length;
          arr.forEach((u) => unique.add(u.username));
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
        ids.forEach((sid) => rows.push(...Object.values(this.usersByServer[sid] || {})));

        rows.sort((a, b) =>
          (a.server_name || '').localeCompare(b.server_name || '') ||
          (a.username || '').localeCompare(b.username || '')
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
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
            'Content-Type': 'application/json',
          };

          let res = await fetch(`/admin/servers/${row.server_id}/disconnect`, {
            method: 'POST',
            headers: baseHeaders,
            body: JSON.stringify({
              client_id: row.connection_id,
              session_key: row.session_key,
              username: row.username,
              protocol: row.protocol,
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

          // ✅ locally remove the row you disconnected
          const map = this.usersByServer[row.server_id] || {};
          delete map[row.__key];
          this.usersByServer[row.server_id] = map;
          this._recalc();

          alert(`Disconnected ${row.username}`);
        } catch (e) {
          console.error(e);
          alert('Error disconnecting user.\n\n' + (e.message || 'Unknown issue'));
        }
      },
    };
  };
</script>