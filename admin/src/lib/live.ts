import { useEffect, useRef } from 'react';
import { useAuth } from './auth';
import { realtime } from './realtime';

/**
 * Keeps a screen's numbers current: reloads the moment the server announces a change (calls, outcomes, payments,
 * case updates, imports, allocation), when the tab comes back into view, and every `fallbackMs` while visible in case
 * live updates are off or blocked. Bursts of changes collapse into one reload.
 * ponytail: reloads the whole screen per change; fine at console scale, scope by id if a screen gets heavy.
 */
export function useLiveReload(reload: () => unknown, prefixes = ['call.', 'case.', 'payment.', 'recording.'], fallbackMs = 10_000) {
  const { me } = useAuth();
  const fn = useRef(reload);
  fn.current = reload;
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | null = null;
    const soon = () => { if (timer) return; timer = setTimeout(() => { timer = null; if (!document.hidden) void fn.current(); }, 800); };
    const echo = me && realtime(me);
    const name = me ? `company.${me.company.id}.admin` : '';
    const onFeed = (e: any) => { if (prefixes.some((p) => String(e.type).startsWith(p))) soon(); };
    echo?.private(name).listen('.feed', onFeed);
    const poll = setInterval(() => { if (!document.hidden) void fn.current(); }, fallbackMs);
    const onShow = () => { if (!document.hidden) soon(); };
    document.addEventListener('visibilitychange', onShow);
    window.addEventListener('focus', onShow);
    return () => {
      clearInterval(poll); if (timer) clearTimeout(timer);
      document.removeEventListener('visibilitychange', onShow); window.removeEventListener('focus', onShow);
      echo?.private(name).stopListening('.feed', onFeed);   // only ours: other screens share the channel
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [me?.company.id, fallbackMs]);
}
