import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';

const key    = import.meta.env.VITE_REVERB_APP_KEY;
const host   = import.meta.env.VITE_REVERB_HOST;
const port   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const scheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

try {
  window.Echo = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: scheme === 'http' ? port : 80,
    wssPort: scheme === 'https' ? port : 443,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
  });
  window.AIOEcho = {
    onServer(id, handler) {
      const ch = window.Echo.channel(`servers.${id}`);
      ch.listen('.mgmt.update', e => handler('mgmt.update', e));
      ch.listen('.DeployEvent', e => handler('DeployEvent', e));
      return { stop: () => window.Echo.leaveChannel(`servers.${id}`) };
    },
    onDashboard(handler) {
      const ch = window.Echo.channel('servers.dashboard');
      ch.listen('.ServerUpdated', e => handler('ServerUpdated', e));
      return { stop: () => window.Echo.leaveChannel('servers.dashboard') };
    }
  };
  console.info('[Echo] Reverb initialised');
} catch (e) {
  console.error('[Echo] init failed:', e);
}