import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';

// Reverb (first‑party websockets) – Vite injects these from .env
const REVERB_KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const REVERB_HOST   = import.meta.env.VITE_REVERB_HOST;
const REVERB_PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const REVERB_SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: REVERB_KEY,
  wsHost: REVERB_HOST,
  wsPort: REVERB_SCHEME === 'http' ? REVERB_PORT : 80,   // Echo still requires both
  wssPort: REVERB_SCHEME === 'https' ? REVERB_PORT : 443,
  forceTLS: REVERB_SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
});

// Optional: helper your dashboard can use
window.AIOEcho = {
  onServer(serverId, handler) {
    const name = `servers.${serverId}`;
    const ch = window.Echo.channel(name);
    ch.listen('.DeployEvent',   e => handler('DeployEvent', e));
    ch.listen('.DeployLogLine', e => handler('DeployLogLine', e));
    ch.listen('.MgmtSnapshot',  e => handler('MgmtSnapshot', e));
    return { stop: () => window.Echo.leaveChannel(name) };
  },
  onDashboard(handler) {
    const name = 'servers.dashboard';
    const ch = window.Echo.channel(name);
    ch.listen('.ServerCreated', e => handler('ServerCreated', e));
    ch.listen('.ServerUpdated', e => handler('ServerUpdated', e));
    ch.listen('.ServerDeleted', e => handler('ServerDeleted', e));
    ch.listen('.DeployEvent',   e => handler('DeployEvent', e));
    ch.listen('.MgmtSnapshot',  e => handler('MgmtSnapshot', e));
    return { stop: () => window.Echo.leaveChannel(name) };
  }
};