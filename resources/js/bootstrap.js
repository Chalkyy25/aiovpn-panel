// Axios (keeps XSRF header)
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// --- Reverb + Echo ---
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';          // ⬅️ important
window.Pusher = Pusher;                  // ⬅️ important

const REVERB_KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const REVERB_HOST   = import.meta.env.VITE_REVERB_HOST;
const REVERB_PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const REVERB_SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

if (!window.__echoBooted) {
  window.Echo = new Echo({
    broadcaster: 'reverb',
    key: REVERB_KEY,
    wsHost: REVERB_HOST,
    wsPort: REVERB_SCHEME === 'http' ? (REVERB_PORT || 80) : 80,
    wssPort: REVERB_SCHEME === 'https' ? (REVERB_PORT || 443) : 443,
    forceTLS: REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
  });

  // optional console signal
  try {
    window.Echo.connector.pusher.connection.bind('state_change', s => {
      console.log('[Reverb] state:', s.previous, '→', s.current);
    });
  } catch {}

  // small helper
  window.AIOEcho = {
    onServer(id, handler) {
      const ch = window.Echo.channel(`servers.${id}`);
      ch.listen('.DeployEvent',   e => handler('DeployEvent', e));
      ch.listen('.DeployLogLine', e => handler('DeployLogLine', e));
      ch.listen('.MgmtSnapshot',  e => handler('MgmtSnapshot', e));
      return { stop: () => window.Echo.leaveChannel(`servers.${id}`) };
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
    },
  };

  window.__echoBooted = true;
}