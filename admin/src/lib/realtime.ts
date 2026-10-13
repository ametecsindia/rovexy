import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { headers } from './api';
import type { Me } from './auth';

// Live updates over Reverb (Pusher protocol). Optional: every screen also loads via HTTP, so the console works
// (with manual refresh/polling) when WebSockets are unavailable.
let echo: Echo<'reverb'> | null = null;

export function realtime(me: Me): Echo<'reverb'> | null {
  if (!me.realtime?.key) return null;
  if (echo) return echo;
  (window as any).Pusher = Pusher;
  echo = new Echo({
    broadcaster: 'reverb',
    key: me.realtime.key,
    wsHost: me.realtime.host,
    wsPort: me.realtime.port,
    wssPort: me.realtime.port,
    forceTLS: me.realtime.scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: { headers: headers() },
    withCredentials: true,
  } as any);
  return echo;
}

export function disconnectRealtime() {
  echo?.disconnect();
  echo = null;
}
