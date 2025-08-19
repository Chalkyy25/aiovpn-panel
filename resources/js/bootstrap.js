/**
 * Axios for HTTP (keeps CSRF header via XSRF-TOKEN cookie)
 */
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Laravel Echo + Pusher
 *
 * This bootstraps Echo in a way that works with:
 *  - Pusher Cloud (no VITE_PUSHER_HOST set)
 *  - Self-hosted websockets (Laravel WebSockets) when VITE_PUSHER_HOST is set
 *
 * Required env (in .env + vite):
 *   VITE_PUSHER_APP_KEY=xxxx
 *   # If using Pusher Cloud:
 *   VITE_PUSHER_APP_CLUSTER=mt1
 *   VITE_PUSHER_SCHEME=https
 *   VITE_PUSHER_PORT=443
 *
 *   # If using self-hosted websockets (laravel-websockets):
 *   VITE_PUSHER_HOST=your-domain-or-ip
 *   VITE_PUSHER_PORT=6001            # or 443 if behind TLS proxy
 *   VITE_PUSHER_SCHEME=https         # or http
 */

// resources/js/bootstrap.js

import Echo from 'laravel-echo';

// ---- Reverb (first‑party websockets) ----
// Vite exposes these from your .env as VITE_REVERB_*
const REVERB_KEY     = import.meta.env.VITE_REVERB_APP_KEY;
const REVERB_HOST    = import.meta.env.VITE_REVERB_HOST;     // e.g. reverb.aiovpn.co.uk
const REVERB_PORT    = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const REVERB_SCHEME  = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();
const FORCE_TLS      = REVERB_SCHEME === 'https';

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: REVERB_KEY,
  wsHost: REVERB_HOST,
  wsPort: REVERB_PORT,     // used for ws://
  wssPort: REVERB_PORT,    // used for wss://
  forceTLS: FORCE_TLS,
  enabledTransports: ['ws', 'wss'],
});

// Optional: tiny helper to see connection state in the console
window.Echo.connector.pusher.connection.bind('state_change', (s) => {
  console.log('[Reverb] state:', s.previous, '→', s.current);
});

/**
 * Small helper so your dashboard components can easily listen to server events.
 * 
 * Example usage in a page/component:
 *   window.AIOEcho.onServer(113, (event, payload) => {
 *     console.log('Server 113 event:', event, payload);
 *     // update UI...
 *   });
 *
 * Adjust channel/event names to match your Broadcasts (see comments below).
 */
window.AIOEcho = {
  /**
   * Subscribe to a per-server **public** channel (no auth). If you use private
   * channels, switch to `private()` and ensure auth endpoints are set up.
   *
   * @param {Number|String} serverId
   * @param {(eventName: string, payload: any) => void} handler
   * @returns {object} a handle with .stop() to unsubscribe
   */
  onServer(serverId, handler) {
    // Choose a name and keep it consistent with your PHP events:
    // e.g., channel: "servers.{id}" and events like:
    //   - ".DeployEvent"
    //   - ".DeployLogLine"
    //   - ".MgmtSnapshot"
    const channelName = `servers.${serverId}`;
    const channel = window.Echo.channel(channelName);

    // Wire up any events you plan to broadcast from Laravel:
    const off = [];

    off.push(channel.listen('.DeployEvent', (e) => handler('DeployEvent', e)));
    off.push(channel.listen('.DeployLogLine', (e) => handler('DeployLogLine', e)));
    off.push(channel.listen('.MgmtSnapshot', (e) => handler('MgmtSnapshot', e)));

    // Return a tiny disposer so you can stop listening when navigating away
    return {
      stop() {
        try {
          window.Echo.leaveChannel(channelName);
        } catch (_) {}
      }
    };
  },

  /**
   * Listen to a global dashboard stream if you broadcast “server created /
   * status changed” style events to a single channel.
   */
  onDashboard(handler) {
    const channelName = `servers.dashboard`;
    const channel = window.Echo.channel(channelName);

    const off = [];
    off.push(channel.listen('.ServerCreated',  (e) => handler('ServerCreated', e)));
    off.push(channel.listen('.ServerUpdated',  (e) => handler('ServerUpdated', e)));
    off.push(channel.listen('.ServerDeleted',  (e) => handler('ServerDeleted', e)));
    off.push(channel.listen('.DeployEvent',    (e) => handler('DeployEvent', e)));
    off.push(channel.listen('.MgmtSnapshot',   (e) => handler('MgmtSnapshot', e)));

    return {
      stop() {
        try {
          window.Echo.leaveChannel(channelName);
        } catch (_) {}
      }
    };
  }
};
