// resources/js/dashboard/vpnDashboard.js
(function () {
  const cfg = window.VPN_DASHBOARD_CONFIG || {};
  const fallbackPattern = cfg.disconnectFallbackPattern || '';
  const fallbackUrl = (sid) => fallbackPattern.replace('__SID__', String(sid));

  window.vpnDashboard = function (lw) {
    // ---------------- helpers ----------------
    const toMB = (n) => (n ? (n / (1024 * 1024)).toFixed(2) : '0.00');

    const humanBytes = (inb, outb) => {
      const total = (inb || 0) + (outb || 0);
      if (total >= 1024 ** 3) return (total / 1024 ** 3).toFixed(2) + ' GB';
      if (total >= 1024 ** 2) return (total / 1024 ** 2).toFixed(2) + ' MB';
      if (total >= 1024) return (total / 1024).toFixed(2) + ' KB';
      return (total || 0) + ' B';
    };

    const isBlank = (v) =>
      v === null || v === undefined || v === '' || (typeof v === 'number' && Number.isNaN(v));

    const pick = (primary, fallback) => (isBlank(primary) ? fallback : primary);

    // accepts ISO string, unix seconds, unix ms, numeric string
    const toDate = (v) => {
      if (v === null || v === undefined || v === '') return null;

      if (typeof v === 'number') {
        return new Date(v > 2000000000000 ? v : v * 1000);
      }

      const s = String(v).trim();
      if (!s) return null;

      if (/^\d+$/.test(s)) {
        const n = Number(s);
        return new Date(n > 2000000000000 ? n : n * 1000);
      }

      const d = new Date(s);
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
      if (h) return `${h} hour${h > 1 ? 's' : ''} ago`;
      if (m) return `${m} min${m > 1 ? 's' : ''} ago`;
      return 'just now';
    };

    const safeObj = (raw) => {
      if (!raw) return null;
      if (typeof raw === 'string') {
        const u = raw.trim();
        return u ? { username: u } : null;
      }
      if (typeof raw !== 'object') return null;
      return raw;
    };

    // ---------------- component ----------------
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

      init(meta, seedUsersByServer) {
        this.serverMeta = meta || {};
        Object.keys(this.serverMeta).forEach((sid) => (this.usersByServer[sid] = {}));

        // seed snapshot (authoritative)
        if (seedUsersByServer) {
          for (const sid in seedUsersByServer) {
            this._setExactList(Number(sid), seedUsersByServer[sid] || []);
          }
        }

        // load saved UI state
        try {
          const savedSid = localStorage.getItem('vpn.selectedServerId');
          if (savedSid !== null && savedSid !== '') this.selectedServerId = Number(savedSid);
          const sf = localStorage.getItem('vpn.showFilters');
          if (sf !== null) this.showFilters = sf === '1';
        } catch {}

        this._recalc();
        this.lastUpdated = new Date().toLocaleTimeString();

        this._waitForEcho().then(() => this._subscribe());
        this._startPolling(15000);
      },

      toggleFilters() {
        this.showFilters = !this.showFilters;
        try {
          localStorage.setItem('vpn.showFilters', this.showFilters ? '1' : '0');
        } catch {}
      },

      selectServer(id) {
        this.selectedServerId = id === null || id === '' ? null : Number(id);
        try {
          localStorage.setItem('vpn.selectedServerId', this.selectedServerId ?? '');
        } catch {}
      },

      serverUsersCount(id) {
        const arr = Object.values(this.usersByServer[id] || {});
        return arr.filter((u) => (u?.is_connected === undefined ? true : !!u.is_connected)).length;
      },

            activeRows() {
        const serverIds =
          this.selectedServerId == null
            ? Object.keys(this.serverMeta)
            : [String(this.selectedServerId)];

        const rows = [];
        serverIds.forEach((sid) => {
          const map = this.usersByServer[sid] || {};
          rows.push(...Object.values(map));
        });

        return rows
          .filter((r) => r && typeof r === 'object' && !isBlank(r.__key))
          .filter((r) => (r.is_connected === undefined ? true : !!r.is_connected))
          .sort(
            (a, b) =>
              (a.server_name || '').localeCompare(b.server_name || '') ||
              (a.username || '').localeCompare(b.username || '') ||
              (a.__key || '').localeCompare(b.__key || '')
          );
      },

      async refreshNow() {
        if (this.refreshing) return;
        this.refreshing = true;

        try {
          const res = await this.lw.call('getLiveStats');

          // polling snapshot is authoritative
          if (res?.usersByServer) {
            for (const sid in this.serverMeta) {
              this._setExactList(Number(sid), res.usersByServer[sid] || []);
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

      _waitForEcho() {
        return new Promise((resolve) => {
          const t = setInterval(() => {
            if (window.Echo) {
              clearInterval(t);
              resolve();
            }
          }, 150);
          setTimeout(() => {
            clearInterval(t);
            resolve();
          }, 3000);
        });
      },

      _subscribe() {
        if (this._subscribed) return;

        try {
          window.Echo.private('servers.dashboard').listen('.mgmt.update', (e) => this.handleEvent(e));
        } catch (_) {}

        Object.keys(this.serverMeta).forEach((sid) => {
          try {
            window.Echo.private(`servers.${sid}`).listen('.mgmt.update', (e) => this.handleEvent(e));
          } catch (_) {}
        });

        this._subscribed = true;
      },

      _startPolling(ms) {
        if (this._pollTimer) clearInterval(this._pollTimer);
        this._pollTimer = setInterval(() => this.refreshNow(), ms);
      },

      _shapeProtocol(raw) {
        const p = String(raw?.protocol ?? raw?.proto ?? '').toLowerCase();
        if (p.startsWith('wire')) return 'WIREGUARD';
        if (p === 'ovpn' || p.startsWith('openvpn')) return 'OPENVPN';
        return p ? p.toUpperCase() : 'OPENVPN';
      },

      // base key (may collide if provider doesn't send connection/session id)
      _baseKey(serverId, raw, protocol, username) {
        const sk = raw?.session_key ?? raw?.sessionKey ?? null;
        const cid = raw?.connection_id ?? raw?.id ?? null;

        if (!isBlank(sk)) return `sk:${String(sk)}`;          // sk:wg:...
        if (!isBlank(cid)) return `cid:${String(cid)}`;       // cid:123
        return `u:${serverId}:${username}:${protocol}`;       // fallback
      },

      _shapeRow(serverId, raw, prevRow, forcedKey) {
        const meta = this.serverMeta[serverId] || {};
        const protocol = this._shapeProtocol(raw);
        const username = String(raw?.username ?? raw?.cn ?? prevRow?.username ?? 'unknown');

        const session_key = pick(raw?.session_key ?? raw?.sessionKey, prevRow?.session_key);
        const connection_id = pick(raw?.connection_id ?? raw?.id, prevRow?.connection_id);

        const seen_at = pick(raw?.seen_at ?? raw?.seenAt, prevRow?.seen_at ?? null);
        const connected_at = pick(raw?.connected_at ?? raw?.connectedAt, prevRow?.connected_at ?? null);

        const bytes_in = Number(
          pick(
            raw?.bytes_in ?? raw?.bytesIn ?? raw?.bytes_received ?? raw?.bytesReceived,
            prevRow?.bytes_in ?? 0
          )
        );

        const bytes_out = Number(
          pick(
            raw?.bytes_out ?? raw?.bytesOut ?? raw?.bytes_sent ?? raw?.bytesSent,
            prevRow?.bytes_out ?? 0
          )
        );

        // WireGuard displays "last seen" (handshake). OpenVPN displays "connected at".
        const displayTime = protocol === 'WIREGUARD' ? seen_at : connected_at;

        return {
          __key: forcedKey,               // IMPORTANT: use the exact key we computed
          session_key,
          connection_id,

          server_id: Number(serverId),
          server_name: pick(raw?.server_name, meta.name) || `Server ${serverId}`,

          username,
          protocol,

          client_ip: pick(raw?.client_ip, prevRow?.client_ip) ?? null,
          virtual_ip: pick(raw?.virtual_ip, prevRow?.virtual_ip) ?? null,

          connected_at,
          seen_at,

          connected_human: ago(displayTime),

          bytes_in,
          bytes_out,
          down_mb: toMB(bytes_in),
          up_mb: toMB(bytes_out),
          formatted_bytes: humanBytes(bytes_in, bytes_out),

          // if your backend ever sends it, keep it. otherwise undefined -> treated as online in your totals
          is_connected: pick(raw?.is_connected, prevRow?.is_connected),
        };
      },

      // authoritative replace (used by polling + initial seed)
      _setExactList(serverId, list) {
        const prevMap = this.usersByServer[serverId] || {};
        const nextMap = {};
        const arr = Array.isArray(list) ? list : [];

        // Track collisions in THIS snapshot so keys are unique even if payload is messy
        const seenKeys = new Map(); // baseKey -> count

        for (const raw0 of arr) {
          const raw = safeObj(raw0);
          if (!raw) continue;

          const protocol = this._shapeProtocol(raw);
          const username = String(raw?.username ?? raw?.cn ?? 'unknown');

          const baseKey = this._baseKey(serverId, raw, protocol, username);

          const n = (seenKeys.get(baseKey) || 0) + 1;
          seenKeys.set(baseKey, n);

          // If duplicates exist, suffix them deterministically
          const finalKey = n === 1 ? baseKey : `${baseKey}#${n}`;

          // Prev row match: try exact, else try baseKey (in case suffix count changed)
          const prevRow = prevMap[finalKey] || prevMap[baseKey] || null;

          nextMap[finalKey] = this._shapeRow(serverId, raw, prevRow, finalKey);
        }

        this.usersByServer[serverId] = nextMap;
      },

      handleEvent(e) {
        const sid = Number(e?.server_id ?? e?.serverId ?? 0);
        if (!sid) return;

        let list = [];

        if (Array.isArray(e?.users)) {
          list = e.users;
        } else if (typeof e?.cn_list === 'string') {
          list = e.cn_list
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean)
            .map((u) => ({ username: u }));
        } else if (Array.isArray(e?.connections)) {
          // optional support if you ever switch payload shape
          list = e.connections;
        }

        // Echo payload is authoritative snapshot for that server
        this._setExactList(sid, list);

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
          const online = arr.filter((u) => (u?.is_connected === undefined ? true : !!u.is_connected));

          if (online.length) activeServers++;
          conns += online.length;
          online.forEach((u) => unique.add(u.username));
        });

        if (activeServers === 0) activeServers = Object.keys(this.serverMeta).length;

        return { online_users: unique.size, active_connections: conns, active_servers: activeServers };
      },

      async disconnect(row) {
        if (!row || isBlank(row.__key)) return;
        if (!confirm(`Disconnect ${row.username} from ${row.server_name}?`)) return;

        try {
          const csrf = document.querySelector('meta[name=csrf-token]')?.content || cfg.csrf || '';
          const headers = { 'X-CSRF-TOKEN': csrf, 'Content-Type': 'application/json' };

          let res = await fetch(`/admin/servers/${row.server_id}/disconnect`, {
            method: 'POST',
            headers,
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
              headers,
              body: JSON.stringify({ username: row.username, server_id: row.server_id }),
            });
            if (!res2.ok) throw new Error('Disconnect failed');
          }

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
})();