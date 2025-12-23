export function createVpnDashboard(lw, options = {}) {
  const {
    serverMeta = {},
    seedUsersByServer = {},
    pollInterval = 15000,
    disconnectUrlPattern = null,
    csrfToken = null,
  } = options;

  const toMB = n => (n ? (n / (1024 * 1024)).toFixed(2) : '0.00');

  const humanBytes = (inb, outb) => {
    const total = (inb || 0) + (outb || 0);
    if (total >= 1024 ** 3) return (total / 1024 ** 3).toFixed(2) + ' GB';
    if (total >= 1024 ** 2) return (total / 1024 ** 2).toFixed(2) + ' MB';
    if (total >= 1024)      return (total / 1024).toFixed(2) + ' KB';
    return (total || 0) + ' B';
  };

  const ago = v => {
    if (!v) return '—';
    const d = new Date(v);
    if (isNaN(d)) return '—';

    const diff = Math.max(0, (Date.now() - d.getTime()) / 1000);
    const m = Math.floor(diff / 60);
    const h = Math.floor(m / 60);
    const d2 = Math.floor(h / 24);

    if (d2) return `${d2} day${d2 > 1 ? 's' : ''} ago`;
    if (h)  return `${h} hour${h > 1 ? 's' : ''} ago`;
    if (m)  return `${m} min${m > 1 ? 's' : ''} ago`;
    return 'just now';
  };

  return {
    lw,

    refreshing: false,
    serverMeta,
    usersByServer: {},
    totals: { online_users: 0, active_connections: 0, active_servers: 0 },

    selectedServerId: null,
    showFilters: false,
    lastUpdated: new Date().toLocaleTimeString(),

    _pollTimer: null,
    _subscribed: false,

    // ---------- INIT ----------
    init() {
      Object.keys(this.serverMeta).forEach(sid => {
        this.usersByServer[sid] = {};
      });

      Object.entries(seedUsersByServer).forEach(([sid, list]) => {
        this._setExactList(Number(sid), list);
      });

      this.totals = this.computeTotals();

      this._waitForEcho().then(() => {
        this._subscribeFleet();
        this._subscribePerServer();
      });

      this._startPolling(pollInterval);
    },

    // ---------- DATA SHAPING ----------
    _shapeRow(serverId, raw, prev = null) {
      const meta = this.serverMeta[serverId] || {};

      const username = String(raw?.username ?? prev?.username ?? 'unknown');
      const protocol = String(raw?.protocol ?? prev?.protocol ?? 'OPENVPN').toUpperCase();

      const connected_at =
        raw?.connected_at ??
        prev?.connected_at ??
        null;

      const bytes_in  = Number(raw?.bytes_received ?? prev?.bytes_in ?? 0);
      const bytes_out = Number(raw?.bytes_sent ?? prev?.bytes_out ?? 0);

      const key =
        raw?.session_key
          ? `sk:${raw.session_key}`
          : raw?.connection_id
            ? `cid:${raw.connection_id}`
            : `u:${serverId}:${username}:${protocol}`;

      return {
        __key: key,
        server_id: serverId,
        server_name: meta.name ?? `Server ${serverId}`,
        username,
        protocol,

        client_ip: raw?.client_ip ?? prev?.client_ip ?? null,
        virtual_ip: raw?.virtual_ip ?? prev?.virtual_ip ?? null,

        connected_at,
        connected_human: ago(connected_at),

        bytes_in,
        bytes_out,
        down_mb: toMB(bytes_in),
        up_mb: toMB(bytes_out),
        formatted_bytes: humanBytes(bytes_in, bytes_out),
      };
    },

    _setExactList(serverId, list) {
      const prev = this.usersByServer[serverId] || {};
      const next = {};

      (list || []).forEach(raw => {
        const shaped = this._shapeRow(serverId, raw, prev[raw?.__key]);
        next[shaped.__key] = shaped;
      });

      this.usersByServer[serverId] = next;
    },

    // ---------- EVENTS ----------
    handleEvent(e) {
      const sid = Number(e.server_id ?? e.serverId);
      if (!sid) return;

      const list = Array.isArray(e.users)
        ? e.users
        : typeof e.cn_list === 'string'
          ? e.cn_list.split(',').map(u => ({ username: u }))
          : [];

      this._setExactList(sid, list);
      this.totals = this.computeTotals();
      this.lastUpdated = new Date().toLocaleTimeString();
    },

    // ---------- COMPUTED ----------
    computeTotals() {
      const users = new Set();
      let conns = 0;
      let activeServers = 0;

      Object.entries(this.usersByServer).forEach(([sid, map]) => {
        const arr = Object.values(map);
        if (arr.length) activeServers++;
        conns += arr.length;
        arr.forEach(r => users.add(r.username));
      });

      return {
        online_users: users.size,
        active_connections: conns,
        active_servers: activeServers || Object.keys(this.serverMeta).length,
      };
    },

    activeRows() {
      const sids = this.selectedServerId == null
        ? Object.keys(this.serverMeta)
        : [String(this.selectedServerId)];

      return sids
        .flatMap(sid => Object.values(this.usersByServer[sid] || {}))
        .sort((a, b) =>
          a.server_name.localeCompare(b.server_name) ||
          a.username.localeCompare(b.username)
        );
    },

    // ---------- IO ----------
    async refreshNow() {
      if (this.refreshing) return;
      this.refreshing = true;

      try {
        const res = await this.lw.call('getLiveStats');
        Object.entries(res?.usersByServer || {}).forEach(([sid, list]) => {
          this._setExactList(Number(sid), list);
        });
        this.totals = this.computeTotals();
      } finally {
        this.refreshing = false;
        this.lastUpdated = new Date().toLocaleTimeString();
      }
    },

    _waitForEcho() {
      return new Promise(resolve => {
        const i = setInterval(() => {
          if (window.Echo) {
            clearInterval(i);
            resolve();
          }
        }, 150);
        setTimeout(resolve, 3000);
      });
    },

    _subscribeFleet() {
      if (this._subscribed) return;
      window.Echo.private('servers.dashboard')
        .listen('.mgmt.update', e => this.handleEvent(e));
    },

    _subscribePerServer() {
      Object.keys(this.serverMeta).forEach(sid => {
        window.Echo.private(`servers.${sid}`)
          .listen('.mgmt.update', e => this.handleEvent(e));
      });
      this._subscribed = true;
    },

    _startPolling(ms) {
      clearInterval(this._pollTimer);
      this._pollTimer = setInterval(() => this.refreshNow(), ms);
    },
  };
}