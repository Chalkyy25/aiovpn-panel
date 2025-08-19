// resources/js/bootstrap.js
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';

const REVERB_KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const REVERB_HOST   = import.meta.env.VITE_REVERB_HOST;
const REVERB_PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const REVERB_SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();
const TLS           = REVERB_SCHEME === 'https';

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: REVERB_KEY,
  wsHost: REVERB_HOST,
  wsPort:  TLS ? 80  : REVERB_PORT,   // Echo wants both set
  wssPort: TLS ? REVERB_PORT : 443,
  forceTLS: TLS,
  enabledTransports: ['ws', 'wss'],
});

// convenience helpers
window.AIOEcho = {
  onServer(serverId, handler) {
    const name = `servers.${serverId}`;
    const ch = window.Echo.channel(name);
    ch.listen('.mgmt.update', e => handler('mgmt.update', e));
    ch.listen('.DeployEvent', e => handler('DeployEvent', e));
    ch.listen('.DeployLogLine', e => handler('DeployLogLine', e));
    return { stop: () => window.Echo.leaveChannel(name) };
  },
  onDashboard(handler) {
    const name = 'servers.dashboard';
    const ch = window.Echo.channel(name);
    ch.listen('.ServerCreated', e => handler('ServerCreated', e));
    ch.listen('.ServerUpdated', e => handler('ServerUpdated', e));
    ch.listen('.ServerDeleted', e => handler('ServerDeleted', e));
    ch.listen('.mgmt.update',  e => handler('mgmt.update', e));
    return { stop: () => window.Echo.leaveChannel(name) };
  }
};