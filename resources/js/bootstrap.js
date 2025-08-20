import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

import Echo from 'laravel-echo';

(function initEchoSafely() {
  try {
    const key    = import.meta.env.VITE_REVERB_APP_KEY;
    const host   = import.meta.env.VITE_REVERB_HOST;
    const port   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
    const scheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

    if (!key || !host) {
      console.info('[Echo] Reverb env not set; skipping realtime init');
      return;
    }

    window.Echo = new Echo({
      broadcaster: 'reverb',
      key,
      wsHost: host,
      wsPort: scheme === 'http' ? port : 80,
      wssPort: scheme === 'https' ? port : 443,
      forceTLS: scheme === 'https',
      enabledTransports: ['ws','wss'],
    });

    window.AIOEcho = {
      onServer(id, handler){
        const chName = `servers.${id}`;
        const ch = window.Echo.channel(chName);
        ch.listen('.mgmt.update',   e => handler('mgmt.update', e));
        ch.listen('.DeployEvent',   e => handler('DeployEvent', e));
        ch.listen('.DeployLogLine', e => handler('DeployLogLine', e));
        return { stop: () => window.Echo.leaveChannel(chName) };
      },
      onDashboard(handler){
        const name = 'servers.dashboard';
        const ch = window.Echo.channel(name);
        ch.listen('.ServerCreated', e => handler('ServerCreated', e));
        ch.listen('.ServerUpdated', e => handler('ServerUpdated', e));
        ch.listen('.ServerDeleted', e => handler('ServerDeleted', e));
        return { stop: () => window.Echo.leaveChannel(name) };
      }
    };

    console.info('[Echo] Reverb initialised →', host);
  } catch (err) {
    console.error('[Echo] init failed:', err);
  }
})();