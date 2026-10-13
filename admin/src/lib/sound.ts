/** Notification + ringing tones, generated with Web Audio (no sound files to ship). */
let ctx: AudioContext | null = null;
const ac = () => (ctx ??= new AudioContext());
// Browsers start audio suspended until the user has clicked once on the page; resume on the first click/key.
if (typeof window !== 'undefined') ['pointerdown', 'keydown'].forEach((e) => window.addEventListener(e, () => void ctx?.resume(), { passive: true }));

/** Sound on/off switch in the top bar; remembered per browser. */
export const soundPref = {
  get on() { try { return localStorage.getItem('sound.on') !== '0'; } catch { return true; } },
  set on(v: boolean) { try { localStorage.setItem('sound.on', v ? '1' : '0'); } catch { /* private mode */ } },
};

function tone(freqs: number[], start: number, dur: number, vol = 0.15) {
  if (!soundPref.on) return;
  const c = ac();
  const g = c.createGain();
  g.gain.setValueAtTime(0, start);
  g.gain.linearRampToValueAtTime(vol, start + 0.02);
  g.gain.setValueAtTime(vol, start + dur - 0.05);
  g.gain.linearRampToValueAtTime(0, start + dur);
  g.connect(c.destination);
  for (const f of freqs) { const o = c.createOscillator(); o.frequency.value = f; o.connect(g); o.start(start); o.stop(start + dur); }
}

/** Short two-note chime for a new message. */
export function chime() {
  try { const t = ac().currentTime; tone([880], t, 0.14); tone([1320], t + 0.15, 0.22); } catch { /* no audio device */ }
}

/** Indian-style ringback (400+450 Hz, 0.4 on / 0.2 off / 0.4 on / 2 s off) until stop() is called. */
export function ringback(): () => void {
  let stopped = false;
  const ring = () => {
    if (stopped) return;
    try { const t = ac().currentTime; tone([400, 450], t, 0.4, 0.08); tone([400, 450], t + 0.6, 0.4, 0.08); } catch { /* no audio device */ }
  };
  ring();
  const timer = setInterval(ring, 3000);
  return () => { stopped = true; clearInterval(timer); };
}

/** Three falling beeps when a call ends. */
export function hangupTone() {
  try { const t = ac().currentTime; [0, 0.25, 0.5].forEach((d) => tone([480], t + d, 0.18, 0.1)); } catch { /* no audio device */ }
}

/** Desktop notification when the console tab is in the background. Permission is asked once, on a click. */
export function desktopNotify(title: string, body: string, onClick?: () => void) {
  if (typeof Notification === 'undefined' || Notification.permission !== 'granted' || !document.hidden) return;
  const n = new Notification(title, { body, tag: 'ammp-chat' });
  n.onclick = () => { window.focus(); onClick?.(); n.close(); };
}
export function askDesktopNotify() {
  if (typeof Notification !== 'undefined' && Notification.permission === 'default') void Notification.requestPermission();
}
