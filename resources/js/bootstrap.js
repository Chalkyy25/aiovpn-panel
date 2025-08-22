// ---- Echo / Reverb ----
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Load env + CSRF
const KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const HOST   = import.meta.env.VITE_REVERB_HOST;
const PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();
const CSRF   = document.querySelector('meta[name="csrf-token"]')?.content || '';

/**
 * Echo instance
 */
const echo = new Echo({
<<<<<<< HEAD
  broadcaster: 'reverb',
  key: KEY,
  wsHost: HOST,
  wsPort: PORT,
  wssPort: PORT,
  forceTLS: SCHEME === 'https',
  enabledTransports: ['ws', 'wss'],
=======

    authEndpoint: '/broadcasting/auth',
    withCredentials: true,
    auth: {
        headers: {
            'X-CSRF-TOKEN': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
        },
    },
});

// Debug state changes
echo.connector.pusher.connection.bind('state_change', ({ previous, current }) =>
    console.info(`[Echo] ${previous} → ${current}`)
);

// Global references
window.Echo    = echo;
window.AIOEcho = echo;

/**
 * Helper for VPN server channels
 */
window.AIOEchoHelper = {
    onServer(serverId, callback) {
        const channelName = `servers.${serverId}`;
        const ch = echo.private(channelName);

        ch.subscribed(() => console.log(`✅ Subscribed: ${channelName}`))
          .error(err => console.error(`❌ Subscription error: ${channelName}`, err))
          .listen('.mgmt.update',   e => callback('mgmt.update', e))
          .listen('.DeployEvent',   e => callback('DeployEvent', e))
          .listen('.DeployLogLine', e => callback('DeployLogLine', e));

        return {
            stop: () => echo.leave(channelName),
        };
    }
};

console.info('[Echo] Reverb initialised ✅');
>>>>>>> 4db82cd (random changes)
