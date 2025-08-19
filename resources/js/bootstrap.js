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

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available to Echo
window.Pusher = Pusher;

// Read env (Vite injects `import.meta.env.*`)
const PUSHER_KEY      = import.meta.env.VITE_PUSHER_APP_KEY;
const PUSHER_CLUSTER  = import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1';
const PUSHER_HOST     = import.meta.env.VITE_PUSHER_HOST || ''; // if set → self-host
const PUSHER_PORT     = Number(import.meta.env.VITE_PUSHER_PORT ?? (location.protocol === 'https:' ? 443 : 6001));
const PUSHER_SCHEME   = (import.meta.env.VITE_PUSHER_SCHEME ?? (location.protocol === 'https:' ? 'https' : 'http')).toLowerCase();
const USE_TLS         = PUSHER_SCHEME === 'https';

// Decide config depending on whether you set VITE_PUSHER_HOST
const echoConfig = {
  broadcaster: 'pusher',
  key: PUSHER_KEY,
  cluster: PUSHER_CLUSTER,
  forceTLS: USE_TLS,
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
};

// If no host → assume Pusher Cloud defaults
if (!PUSHER_HOST) {
  echoConfig.wsHost  = `ws-${PUSHER_CLUSTER}.pusher.com`;
  echoConfig.wsPort  = 80;
  echoConfig.wssPort = 443;
} else {
  // Self-hosted laravel-websockets
  echoConfig.wsHost  = PUSHER_HOST;
  echoConfig.wsPort  = PUSHER_PORT;
  echoConfig.wssPort = PUSHER_PORT;
}

// Initialize Echo
window.Echo = new Echo(echoConfig);

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