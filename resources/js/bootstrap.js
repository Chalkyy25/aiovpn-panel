import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Reverb uses the Pusher protocol — make the client global
window.Pusher = Pusher;

// ---- Reverb (first‑party websockets) ----
const REVERB_KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const REVERB_HOST   = import.meta.env.VITE_REVERB_HOST;        // reverb.aiovpn.co.uk
const REVERB_PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const REVERB_SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

try {
  window.Echo = new Echo({
    broadcaster: 'reverb',
    key: REVERB_KEY,
    wsHost: REVERB_HOST,
    wsPort: REVERB_SCHEME === 'http' ? REVERB_PORT : 80,
    wssPort: REVERB_SCHEME === 'https' ? REVERB_PORT : 443,
    forceTLS: REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
  });

  // Simple visibility for debugging
  window.Echo.connector.pusher.connection.bind('state_change', s => {
    console.info('[Echo] state:', s.previous, '→', s.current);
  });

  // Helper you can call from the console
  window.AIOEcho = {
    onServer(id, cb){
      const ch = window.Echo.private(`servers.${id}`);
      ch.listen('.mgmt.update',   e => cb('mgmt.update', e));
      ch.listen('.DeployEvent',   e => cb('DeployEvent', e));
      ch.listen('.DeployLogLine', e => cb('DeployLogLine', e));
      return { stop: () => window.Echo.leaveChannel(`private-servers.${id}`) };
    }
  };

  console.info('[Echo] Reverb initialised');
} catch (e) {
  console.error('[Echo] init failed:', e);
}
