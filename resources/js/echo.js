import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Reverb is optional: in development BROADCAST_CONNECTION is often "log", and
 * the live dashboard falls back to polling. Guard on the key so a missing
 * websocket server does not throw on every page load.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

window.Echo = key
    ? new Echo({
          broadcaster: 'reverb',
          key,
          wsHost: import.meta.env.VITE_REVERB_HOST,
          wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
          wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
          forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
          enabledTransports: ['ws', 'wss'],
      })
    : null;
