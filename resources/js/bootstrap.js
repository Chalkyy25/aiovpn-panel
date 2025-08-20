// make the global namespace *before* anything else
window.AIO = window.AIO || {};

// ---- Axios ----
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.headers.common['X-CSRF-TOKEN'] =
  document.querySelector('meta[name="csrf-token"]')?.content || '';

// ---- Echo / Reverb ----
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';         // Reverb speaks Pusher protocol
window.Pusher = Pusher;                 // must be global

const KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const HOST   = import.meta.env.VITE_REVERB_HOST;              // reverb.aiovpn.co.uk
const PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

const echo = new Echo({
  broadcaster: 'reverb',
  key: KEY,
  wsHost: HOST,
  wsPort:  SCHEME === 'http'  ? PORT : 80,
  wssPort: SCHEME === 'https' ? PORT : 443,
  forceTLS: SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
});

// handy debug
echo.connector.pusher.connection.bind('state_change', ({ previous, current }) => {
  console.info('[Echo] state:', previous, '→', current);
});

window.Echo = echo;        // <-- give yourself the classic alias
window.AIOEcho = echo;     //     keep your custom name too

// tiny helper
window.AIOEchoHelper = {
  onServer(id, cb) {
    const ch = echo.private(`servers.${id}`);
    ch.subscribed(() => console.log(`✅ subscribed to private-servers.${id}`))
      .error(e => console.error('❌ subscription error', e))
      .listen('.mgmt.update',   e => cb('mgmt.update', e))
      .listen('.DeployEvent',   e => cb('DeployEvent', e))
      .listen('.DeployLogLine', e => cb('DeployLogLine', e));
    return { stop: () => echo.leave(`private-servers.${id}`) };
  }
};

console.info('[Echo] Reverb initialised');