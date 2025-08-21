// ---- Echo / Reverb ----
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

const KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const HOST   = import.meta.env.VITE_REVERB_HOST;
const PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();
const CSRF   = document.querySelector('meta[name="csrf-token"]')?.content || '';

const echo = new Echo({
  broadcaster: 'reverb',
  key: KEY,
  wsHost: HOST,
  wsPort:  SCHEME === 'http'  ? PORT : 80,
  wssPort: SCHEME === 'https' ? PORT : 443,
  forceTLS: SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],

  // 🔐 make private channel auth work
  authEndpoint: '/broadcasting/auth',
  withCredentials: true,
  auth: {
    headers: {
      'X-CSRF-TOKEN': CSRF,
      'X-Requested-With': 'XMLHttpRequest',
    },
  },
});

// debug
echo.connector.pusher.connection.bind('state_change', ({ previous, current }) =>
  console.info('[Echo] state:', previous, '→', current)
);

window.Echo   = echo;   // classic alias
window.AIOEcho = echo;  // your alias

// helper (unchanged)
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