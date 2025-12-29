// resources/js/dashboard/vpnDashboard.js
(function () {
  const cfg = window.VPN_DASHBOARD_CONFIG || {};
  const fallbackPattern = cfg.disconnectFallbackPattern || '';
  const csrfFromCfg = cfg.csrf || '';

  const WG_STALE_SECONDS = Number(cfg.wgStaleSeconds || 240);
  const fallbackUrl = (sid) => fallbackPattern.replace('__SID__', String(sid));

  // ---------------- helpers ----------------
  const isBlank = (v) =>
    v === null || v === undefined || v === '' || (typeof v === 'number' && Number.isNaN(v));

  const pick = (a, b) => (isBlank(a) ? b : a);

  const toDate = (v) => {
    if (isBlank(v)) return null;

    if (typeof v === 'number') return new Date(v >= 1000000000000 ? v : v * 1000);

    const s = String(v).trim();
    if (!s) return null;

    if (/^\d+$/.test(s)) {
      const n = Number(s);
      return new Date(n >= 1000000000000 ? n : n * 1000);
    }

    const d = new Date(s);
    return isNaN(d) ? null : d;
  };

  const tsToMs = (ts) => {
    const d = toDate(ts);
    return d ? d.getTime() : null;
  };

  const agoFrom = (nowMs, v) => {
    const d = toDate(v);
    if (!d) return '—';

    const diff = Math.max(0, (nowMs - d.getTime()) / 1000);
    const m = Math.floor(diff / 60);
    const h = Math.floor(m / 60);
    const dd = Math.floor(h / 24);

    if (dd) return `${dd} day${dd > 1 ? 's' : ''} ago`;
    if (h) return `${h} hour${h > 1 ? 's' : ''} ago`;
    if (m) return `${m} min${m > 1 ? 's' : ''} ago`;
    return 'just now';
  };

  const toMB = (n) => (n ? (n / (1024 * 1024)).toFixed(2) : '0.00');

  const humanBytes = (inb, outb) => {
    const total = (inb || 0) + (outb || 0);
    if (total >= 1024 ** 3) return (total / 1024 ** 3).toFixed(2) + ' GB';
    if (total >= 1024 ** 2) return (total / 1024 ** 2).toFixed(2) + ' MB';
    if (total >= 1024) return (total / 1024).toFixed(2) + ' KB';
    return (total || 0) + ' B';
  };

  const safeObj = (raw) => {
    if (!raw) return null;
    if (typeof raw === 'string') {
      const u = raw.trim();
      return u ? { username: u } : null;
    }
    return typeof raw === 'object' ? raw : null;
  };

  // ---------------- dashboard ----------------
  window.vpnDashboard = function (lw) {
    return {
      lw,
      refreshing: false,

      serverMeta: {},                 // { [sidStr]: {id,name} }
      usersByServer: {},              // { [sidStr]: { [rowKey]: row } }
      totals: { online_users: 0, active_connections: 0, active_servers: 0 },

      selectedServerId: null,
      showFilters: false,

      nowTick: Date.now(),
      _nowTimer: null,

      lastEventAt: null,
      _pollTimer: null,
      _subscribed: false,

      get lastUpdated() {
        if (!this.lastEventAt) return '—';
        return agoFrom(this.nowTick, this.lastEventAt);
      },

      init(meta, seedUsersByServer) {
        // normalize meta keys to strings
        this.serverMeta = meta || {};
        for (const sid of Object.keys(this.serverMeta)) {
          const sidStr = String(sid);
          this.usersByServer[sidStr] = {};
        }

        // seed snapshot (authoritative)
        if (seedUsersByServer) {
          for (const sid in seedUsersByServer) {
            this._setExactList(String(sid), seedUsersByServer[sid] || []);
          }
        }

        // restore UI state
        try {
          const savedSid = localStorage.getItem('vpn.selectedServerId');
          if (savedSid !== null && savedSid !== '') this.selectedServerId = Number(savedSid);
          const sf = localStorage.getItem('vpn.showFilters');
          if (sf !== null) this.showFilters = sf === '1';
        } catch {}

        // UI ticker
        if (this._nowTimer) clearInterval(this._nowTimer);
        this._nowTimer = setInterval(() => (this.nowTick = Date.now()), 1000);

        this._recalc();

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

      // "Connected" label:
      // - WG: show first_seen_at -> seen_at
      // - OVPN: show connected_at -> seen_at
      rowAgo(row) {
        const p = String(row?.protocol || '').toUpperCase();
        const base = p === 'WIREGUARD'
          ? pick(row?.first_seen_at, row?.seen_at)
          : pick(row?.connected_at, row?.seen_at);

        return agoFrom(this.nowTick, base);
      },

      rowHandshakeAgo(row) {
        return agoFrom(this.nowTick, row?.seen_at);
      },

      serverUsersCount(serverId) {
        const sidStr = String(serverId);
        const arr = Object.values(this.usersByServer[sidStr] || {});
        return arr.filter((u) => (u?.is_connected === undefined ? true : !!u.is_connected)).length;
      },

      activeRows() {
        const serverIds =
          this.selectedServerId == null ? Object.keys(this.serverMeta) : [String(this.selectedServerId)];

        const rows = [];
        for (const sidStr of serverIds) {
          rows.push(...Object.values(this.usersByServer[sidStr] || {}));
        }

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

          if (res?.usersByServer) {
            for (const sidStr of Object.keys(this.serverMeta)) {
              this._setExactList(sidStr, res.usersByServer[sidStr] || res.usersByServer[Number(sidStr)] || []);
            }
            this._recalc();
          }
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

        for (const sidStr of Object.keys(this.serverMeta)) {
          try {
            window.Echo.private(`servers.${sidStr}`).listen('.mgmt.update', (e) => this.handleEvent(e));
          } catch (_) {}
        }

        this._subscribed = true;
      },

      _startPolling(ms) {
        if (this._pollTimer) clearInterval(this._pollTimer);
        this._pollTimer = setInterval(() => this.refreshNow(), ms);
      },

      _normalizeProtocol(incoming, prevProtocol) {
        const raw = (incoming ?? '').toString().trim();
        if (!raw) return prevProtocol || 'OPENVPN';

        const p = raw.toLowerCase();
        if (p.startsWith('wire')) return 'WIREGUARD';
        if (p === 'ovpn' || p.startsWith('openvpn')) return 'OPENVPN';
        return raw.toUpperCase();
      },

      _rowKeyFrom(raw) {
        const sk = raw?.session_key ?? raw?.sessionKey ?? null;
        const cid = raw?.connection_id ?? raw?.id ?? null;

        if (!isBlank(sk)) return `sk:${String(sk)}`;
        if (!isBlank(cid)) return `cid:${String(cid)}`;

        const u = String(raw?.username ?? raw?.cn ?? 'unknown');
        return `u:${u.toLowerCase()}`;
      },

      _wireguardIsConnected(nowMs, seenAt) {
        const d = toDate(seenAt);
        if (!d) return false;
        const diffSec = Math.max(0, (nowMs - d.getTime()) / 1000);
        return diffSec <= WG_STALE_SECONDS;
      },

      _stableConnectedAt(newVal, prevVal) {
        const dNew = toDate(newVal);
        const dPrev = toDate(prevVal);

        if (!dPrev) return dNew ? newVal : prevVal;
        if (!dNew) return prevVal;

        const nowMs = Date.now();
        const newIsNowish = nowMs - dNew.getTime() < 10_000;
        const prevIsOlder = dPrev.getTime() < dNew.getTime() - 10_000;

        if (newIsNowish && prevIsOlder) return prevVal;
        return newVal;
      },

      _shapeRow(serverIdStr, raw, prevRow, forcedKey) {
        const meta = this.serverMeta[serverIdStr] || {};

        const username = String(raw?.username ?? raw?.cn ?? prevRow?.username ?? 'unknown');

        const protocol = this._normalizeProtocol(raw?.protocol ?? raw?.proto, prevRow?.protocol);

        const session_key = pick(raw?.session_key ?? raw?.sessionKey, prevRow?.session_key);
        const connection_id = pick(raw?.connection_id ?? raw?.id, prevRow?.connection_id);

        const seen_at = pick(raw?.seen_at ?? raw?.seenAt, prevRow?.seen_at ?? null);

        const connected_at_prev = prevRow?.connected_at ?? null;
        const connected_at_in = raw?.connected_at ?? raw?.connectedAt ?? null;
        const connected_at = this._stableConnectedAt(
          pick(connected_at_in, connected_at_prev),
          connected_at_prev
        );

        // WG: first_seen_at should never reset once set
        const first_seen_at =
          protocol === 'WIREGUARD' ? pick(prevRow?.first_seen_at, seen_at) : null;

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

        let is_connected = pick(raw?.is_connected, prevRow?.is_connected);
        if (is_connected === undefined || is_connected === null) {
          is_connected =
            protocol === 'WIREGUARD' ? this._wireguardIsConnected(this.nowTick, seen_at) : true;
        }

        return {
          __key: forcedKey,

          server_id: Number(serverIdStr),
          server_name: pick(raw?.server_name, meta.name) || `Server ${serverIdStr}`,

          username,
          protocol,

          session_key,
          connection_id,

          client_ip: pick(raw?.client_ip, prevRow?.client_ip) ?? null,
          virtual_ip: pick(raw?.virtual_ip, prevRow?.virtual_ip) ?? null,

          connected_at,
          seen_at,
          first_seen_at,

          bytes_in,
          bytes_out,
          down_mb: toMB(bytes_in),
          up_mb: toMB(bytes_out),
          formatted_bytes: humanBytes(bytes_in, bytes_out),

          is_connected: !!is_connected,
        };
      },

      _setExactList(serverIdStr, list) {
        const sidStr = String(serverIdStr);
        const prevMap = this.usersByServer[sidStr] || {};
        const nextMap = {};

        const arr = Array.isArray(list) ? list : [];

        for (const raw0 of arr) {
          const raw = safeObj(raw0);
          if (!raw) continue;

          // choose a stable key BEFORE protocol logic
          const key = this._rowKeyFrom(raw);

          // try to find previous row (so protocol stays stable even if incoming lacks it)
          const prevRow =
            prevMap[key] ||
            // if we only had username key previously, try that too
            prevMap[`u:${String(raw?.username ?? raw?.cn ?? '').toLowerCase()}`] ||
            null;

          nextMap[key] = this._shapeRow(sidStr, raw, prevRow, key);
        }

        this.usersByServer[sidStr] = nextMap;
      },

      handleEvent(e) {
        const sidStr = String(e?.server_id ?? e?.serverId ?? '');
        if (!sidStr) return;

        let list = [];

        if (Array.isArray(e?.users)) {
          list = e.users;
        } else if (typeof e?.cn_list === 'string') {
          // IMPORTANT: if event only gives usernames, DO NOT invent protocol.
          // We’ll keep existing protocol via prevRow matching on username.
          list = e.cn_list
            .split(',')
            .map((s) => s.trim())
            .filter(Boolean)
            .map((u) => ({ username: u }));
        } else if (Array.isArray(e?.connections)) {
          list = e.connections;
        }

        this._setExactList(sidStr, list);
        this._recalc();

        const eventMs = tsToMs(e?.ts) ?? Date.now();
        if (!this.lastEventAt || eventMs >= this.lastEventAt) this.lastEventAt = eventMs;
      },

      _recalc() {
        this.totals = this.computeTotals();
      },

      computeTotals() {
        const unique = new Set();
        let conns = 0;
        let activeServers = 0;

        for (const sidStr of Object.keys(this.serverMeta)) {
          const arr = Object.values(this.usersByServer[sidStr] || {});
          const online = arr.filter((u) => (u?.is_connected === undefined ? true : !!u.is_connected));

          if (online.length) activeServers++;
          conns += online.length;
          for (const u of online) unique.add(u.username);
        }

        if (activeServers === 0) activeServers = Object.keys(this.serverMeta).length;

        return {
          online_users: unique.size,
          active_connections: conns,
          active_servers: activeServers,
        };
      },

      async disconnect(row) {
        if (!row || isBlank(row.__key)) return;
        if (!confirm(`Disconnect ${row.username} from ${row.server_name}?`)) return;

        try {
          const csrf =
            document.querySelector('meta[name=csrf-token]')?.content ||
            csrfFromCfg ||
            '';

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

          let ok = res.ok;

          if (!ok) {
            const res2 = await fetch(fallbackUrl(row.server_id), {
              method: 'POST',
              headers,
              body: JSON.stringify({ username: row.username, server_id: row.server_id }),
            });
            ok = res2.ok;
          }

          if (!ok) {
            alert('Error disconnecting user.\n\nDisconnect failed');
            return;
          }

          const sidStr = String(row.server_id);
          const map = this.usersByServer[sidStr] || {};
          delete map[row.__key];
          this.usersByServer[sidStr] = map;
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