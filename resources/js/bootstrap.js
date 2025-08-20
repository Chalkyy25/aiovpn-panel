import Echo from 'laravel-echo';
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const key    = import.meta.env.VITE_REVERB_APP_KEY;
const host   = import.meta.env.VITE_REVERB_HOST;
const port   = Number(import.meta.env.VITE_REVERB_PORT ?? 443);
const scheme = (import.meta.env.VITE_REVERB_SCHEME ?? 'https').toLowerCase();

window.Echo = new Echo({
  broadcaster: 'reverb',
  key,
  wsHost: host,
  wsPort: scheme === 'http'  ? port : 80,
  wssPort: scheme === 'https' ? port : 443,
  forceTLS: scheme === 'https',
  enabledTransports: ['ws', 'wss'],
});

// Optional helper
window.AIOEcho = {
  onServer(id, cb){
    const ch = window.Echo.channel(`servers.${id}`);
    ch.listen('.mgmt.update', e => cb('mgmt.update', e));
    ch.listen('.DeployEvent', e => cb('DeployEvent', e));
    ch.listen('.DeployLogLine', e => cb('DeployLogLine', e));
    return { stop: ()=> window.Echo.leaveChannel(`servers.${id}`) };
  }
};