import { useEffect, useState } from 'react';
import { get } from './api';

export type Unread = { total: number; items: { conversation_id: number; from: string; body: string; at: string; count: number }[] };

/** Messages employees sent me that I haven't opened. Polled — Reverb isn't always running. */
export function useUnread(enabled: boolean, everyMs = 15000) {
  const [data, setData] = useState<Unread | null>(null);
  useEffect(() => {
    if (!enabled) return;
    let alive = true;
    const tick = () => get<Unread>('/chat/unread').then((d) => alive && setData(d)).catch(() => undefined);
    tick();
    const t = setInterval(tick, everyMs);
    return () => { alive = false; clearInterval(t); };
  }, [enabled, everyMs]);
  return [data, setData] as const;
}

export function ago(iso?: string | null) {
  if (!iso) return '';
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
  return s < 60 ? 'just now' : s < 3600 ? `${Math.round(s / 60)} min ago` : s < 86400 ? `${Math.round(s / 3600)} h ago` : `${Math.round(s / 86400)} d ago`;
}
