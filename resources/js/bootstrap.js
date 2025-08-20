// ---- Axios ----
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// ---- Echo / Reverb ----
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Reverb uses the Pusher protocol — make global
window.Pusher = Pusher;

const REVERB_KEY    = import.meta.env.VITE_REVERB_APP_KEY;
const REVERB_HOST   = import.meta.env.VITE_REVERB_HOST;
const REVERB_PORT   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const REVERB_SCHEME = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

try {
    // Real Echo instance
    window.AIOEcho = new Echo({
        broadcaster: 'reverb',
        key: REVERB_KEY,
        wsHost: REVERB_HOST,
        wsPort: REVERB_SCHEME === 'http' ? REVERB_PORT : 80,
        wssPort: REVERB_SCHEME === 'https' ? REVERB_PORT : 443,
        forceTLS: REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // Debug connection state
    window.AIOEcho.connector.pusher.connection.bind('state_change', s => {
        console.info('[Echo] state:', s.previous, '→', s.current);
    });

    // Helper wrapper for server mgmt events
    window.AIOEchoHelper = {
        onServer(id, cb) {
            const ch = window.AIOEcho.private(`servers.${id}`);
            ch.listen('.mgmt.update',   e => cb('mgmt.update', e));
            ch.listen('.DeployEvent',   e => cb('DeployEvent', e));
            ch.listen('.DeployLogLine', e => cb('DeployLogLine', e));
            return { stop: () => window.AIOEcho.leave(`private-servers.${id}`) };
        }
    };

    console.info('[Echo] Reverb initialised');
} catch (e) {
    console.error('[Echo] init failed:', e);
}