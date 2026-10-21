import { useEffect, useRef, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { api, get, post, fmtDate, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { realtime } from '../lib/realtime';
import { Link, useSearchParams } from 'react-router-dom';
import { Badge, Card, ErrorBox, Field, Icon, Initials, Notice, Page, Table, useAction, useAsync } from '../components/ui';
import { ago } from '../lib/unread';
import { chime, hangupTone, ringback } from '../lib/sound';

export function LocationPage() {
  const { me } = useAuth();
  const presence = useAsync(() => get<any[]>('/presence'));
  const [userId, setUserId] = useState('');
  const [day, setDay] = useState(() => new Date().toLocaleDateString('en-CA'));   // local YYYY-MM-DD (toISOString would give the UTC date)
  const [focus, setFocus] = useState<number | null>(null);
  useEffect(() => setFocus(null), [userId, day]);
  const sel = presence.data?.find((d: any) => String(d.assigned_user_id) === userId);
  const fixes = useAsync(() => (userId ? get<any[]>(`/locations${qs({ user_id: userId, from: `${day}T00:00:00`, to: `${day}T23:59:59` })}`) : Promise.resolve([])), [userId, day]);
  return (
    <Page title="Employee status & location" subtitle="Location is collected only during work sessions, as configured by policy. Every view is audited.">
      <ErrorBox error={presence.error ?? fixes.error} />
      <LiveCard tz={me?.company.timezone} onRoute={(uid) => { setUserId(String(uid)); setTimeout(() => document.getElementById('trail')?.scrollIntoView({ behavior: 'smooth' }), 300); }} />
      <Card title="Status (observed signals only)">
        <Table rows={presence.data} columns={[{ key: 'u', label: 'Employee', render: (d: any) => d.assigned_user?.name ?? '—' }, { key: 'asset_tag', label: 'Device', render: (d: any) => d.asset_tag ?? `Phone #${d.id}` },
          { key: 'presence', label: 'Connection', render: (d: any) => <Badge value={d.presence} /> }, { key: 'work_state', label: 'In-app state', render: (d: any) => <Badge value={d.work_state} /> },
          { key: 'battery_pct', label: 'Battery', align: 'right', render: (d: any) => (d.battery_pct ?? '—') + (d.battery_pct != null ? '%' : '') }, { key: 'network_type', label: 'Network' },
          { key: 'compliant', label: 'Policy', render: (d: any) => <Badge value={d.compliant} label={d.compliant ? 'compliant' : 'not compliant'} /> },
          { key: 'last_contact_at', label: 'Last contact', render: (d: any) => fmtDate(d.last_contact_at, me?.company.timezone) },
          { key: 'v', label: '', render: (d: any) => <button className="secondary" onClick={() => setUserId(String(d.assigned_user_id))}>Locations</button> }]} />
      </Card>
      {userId && (
        <><div id="trail" />
        <Card title={`Location fixes${sel?.assigned_user?.name ? ` · ${sel.assigned_user.name}` : ''}`} actions={<input type="date" value={day} onChange={(e) => setDay(e.target.value)} aria-label="Day" />}>
          {(fixes.data?.length ?? 0) > 0 && <LocationMap fixes={fixes.data!} focus={focus} tz={me?.company.timezone} />}
          <Table onRow={(f: any) => setFocus(f.id)} rows={fixes.data?.map((f, i) => ({ ...f, id: i }))} empty={sel?.presence === 'offline' ? `No location for this day. ${sel.assigned_user?.name ?? 'This employee'}'s phone was last seen ${fmtDate(sel.last_contact_at, me?.company.timezone)} — fixes arrive while the app is open and signed in.`
            : 'No location received for this day yet. On the phone, allow Location for ROVEXY (Settings → Apps → ROVEXY → Permissions → Location) and keep the app signed in; the first fix arrives within a minute.'} columns={[
            { key: 'captured_at', label: 'Captured', render: (f: any) => fmtDate(f.captured_at, me?.company.timezone) },
            { key: 'pos', label: 'Position', render: (f: any) => <a href={`https://www.openstreetmap.org/?mlat=${f.lat}&mlon=${f.lng}#map=17/${f.lat}/${f.lng}`} target="_blank" rel="noreferrer noopener">{Number(f.lat).toFixed(5)}, {Number(f.lng).toFixed(5)}</a> },
            { key: 'accuracy_m', label: 'Accuracy', align: 'right', render: (f: any) => (f.accuracy_m != null ? `±${Math.round(f.accuracy_m)} m` : '—') },
            { key: 'stale', label: '', render: (f: any) => (f.stale ? <Badge value="expired" label="stale" /> : null) },
          ]} />
        </Card></>
      )}
    </Page>
  );
}

export function MediaPage({ kind }: { kind: 'screen' | 'camera' }) {
  const { me } = useAuth();
  const presence = useAsync(() => get<any[]>('/presence'));
  const sessions = useAsync(() => get('/media-sessions'));
  const [deviceId, setDeviceId] = useState('');
  const [purpose, setPurpose] = useState('');
  const [active, setActive] = useState<any | null>(null);
  const a = useAction();
  const echo = me ? realtime(me) : null;

  useEffect(() => {
    if (!echo || !me) return;
    const name = `company.${me.company.id}.admin`;
    echo.private(name).listen('.feed', (e: any) => { if (e.type === 'media.updated') void sessions.reload(); });
    return () => echo.leave(name);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [echo, me]);
  useEffect(() => {
    if (!active) return;
    const t = setInterval(() => void sessions.reload(), 4000);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [active]);
  const current = active ? sessions.data?.data.find((s: any) => s.uuid === active.uuid) ?? active : null;

  return (
    <Page title={kind === 'screen' ? 'Live screen sessions' : 'Camera session requests'}
      subtitle={kind === 'screen'
        ? 'The employee must accept and then approve Android’s screen-capture prompt. A visible indicator is shown on the phone. Sessions are not recorded.'
        : 'The employee must accept and grant camera permission. The camera preview and an indicator are visible on the phone. Either side can stop.'}>
      <ErrorBox error={a.error ?? sessions.error} />
      {!echo && <Notice tone="info">Realtime service (Reverb) is not running, so the console connects by polling every second. Video works; starting may take a few seconds longer.</Notice>}
      <Card title="Request a session">
        <div className="form-grid">
          <Field label="Employee device">
            <select value={deviceId} onChange={(e) => setDeviceId(e.target.value)}><option value="">Select…</option>
              {(presence.data ?? []).filter((d) => d.work_state !== 'signed_out').map((d) => <option key={d.id} value={d.id} disabled={d.presence !== 'online'}>{d.presence === 'online' ? '● ' : '○ '}{d.assigned_user?.name} · {d.asset_tag ?? `Phone #${d.id}`}{d.presence !== 'online' ? ' — offline' : ''}</option>)}</select>
          </Field>
          <Field label="Purpose (shown to the employee and audited)"><input value={purpose} onChange={(e) => setPurpose(e.target.value)} /></Field>
        </div>
        <button disabled={a.busy || !deviceId || purpose.length < 5} onClick={() => a.run(async () => { setActive(await post('/media-sessions', { device_id: Number(deviceId), kind, purpose })); await sessions.reload(); })}>Send request</button>
      </Card>
      {current && <Viewer session={current} kind={kind} onEnd={() => { setActive(null); void sessions.reload(); }} />}
      <Card title="Session log">
        <Table rows={sessions.data?.data.filter((s: any) => s.kind === kind)} columns={[{ key: 'requested_at', label: 'Requested', render: (s: any) => fmtDate(s.requested_at, me?.company.timezone) },
          { key: 'user_id', label: 'Employee #' }, { key: 'purpose', label: 'Purpose' }, { key: 'state', label: 'State', render: (s: any) => <Badge value={s.state} /> },
          { key: 'end_reason', label: 'End reason' }, { key: 'protected_content_reported', label: 'Protected content', render: (s: any) => (s.protected_content_reported ? 'Reported' : '—') },
          { key: 'o', label: '', render: (s: any) => ['accepted', 'active', 'requested'].includes(s.state) ? <button className="secondary" onClick={() => setActive(s)}>Open</button> : null }]} />
      </Card>
    </Page>
  );
}

function Viewer({ session, kind, onEnd }: { session: any; kind: string; onEnd: () => void }) {
  const { me } = useAuth();
  const video = useRef<HTMLVideoElement>(null);
  const stage = useRef<HTMLDivElement>(null);
  const [rot, setRot] = useState(0);   // view-only rotation (phone streams in its native orientation)
  const [status, setStatus] = useState('Waiting for the employee to accept…');
  const a = useAction();
  const ready = ['accepted', 'active'].includes(session.state);

  useEffect(() => {
    if (!ready || !me) return;
    let pc: RTCPeerConnection | null = null;
    let poll: ReturnType<typeof setInterval> | null = null;
    let after = 0;
    const signal = (type: string, payload: unknown) => post(`/media-sessions/${session.uuid}/signal`, { type, payload });
    (async () => {
      const { ice_servers } = await get(`/media-sessions/${session.uuid}/ice`);
      pc = new RTCPeerConnection({ iceServers: ice_servers });
      pc.ontrack = (ev) => { if (video.current) video.current.srcObject = ev.streams[0]; setStatus('Connected'); };
      pc.onicecandidate = (ev) => { if (ev.candidate) void signal('candidate', ev.candidate.toJSON()); };
      pc.onconnectionstatechange = () => { if (pc && ['failed', 'disconnected'].includes(pc.connectionState)) setStatus(`Connection ${pc.connectionState}`); };
      const handle = async (m: any) => {
        if (m.from !== 'device' || !pc) return;
        if (m.type === 'offer') {
          await pc.setRemoteDescription({ type: m.payload.type, sdp: String(m.payload.sdp).trimEnd() + '\r\n' });   // server input trimming drops the final CRLF
          const ans = await pc.createAnswer();
          await pc.setLocalDescription(ans);
          await signal('answer', { type: ans.type, sdp: ans.sdp });
        } else if (m.type === 'candidate') await pc.addIceCandidate(m.payload).catch(() => undefined);
        else if (m.type === 'bye') setStatus('Ended by the employee');
      };
      // Always poll the signal mailbox (ordered, from the start): a WebSocket-only listener misses the phone's offer
      // when it was sent before this page subscribed, and the video never opens.
      let busy = false;
      const tick = async () => {
        if (busy) return; busy = true;
        try {
          const r = await get(`/media-sessions/${session.uuid}/signals${qs({ after })}`);
          for (const m of r.signals) { after = m.id; await handle(m); }
          if (['ended', 'expired', 'declined'].includes(r.state)) setStatus('Session ended');
        } catch { /* transient — next tick retries */ } finally { busy = false; }
      };
      setStatus('Waiting for the phone to start sharing…');
      void tick();
      poll = setInterval(tick, 500);
    })().catch((e) => setStatus(`Could not connect: ${e}`));
    return () => { pc?.close(); if (poll) clearInterval(poll); };
  }, [ready, me, session.uuid]);

  const screenshot = () => a.run(async () => {
    const v = video.current!;
    const c = document.createElement('canvas');
    c.width = v.videoWidth;
    c.height = v.videoHeight;
    c.getContext('2d')!.drawImage(v, 0, 0);
    const blob: Blob = await new Promise((res) => c.toBlob((b) => res(b!), 'image/png'));
    const fd = new FormData();
    fd.append('image', blob, 'screenshot.png');
    await api('POST', `/media-sessions/${session.uuid}/screenshots`, fd);
  });

  return (
    <Card title={`${kind === 'screen' ? 'Screen' : 'Camera'} session`} actions={<><Badge value={session.state} />
      {kind === 'screen' && session.options?.screenshots && session.state === 'active' && <button className="secondary" disabled={a.busy} onClick={screenshot}>Take screenshot</button>}
      <button className="danger" onClick={() => a.run(async () => { await post(`/media-sessions/${session.uuid}/end`); onEnd(); })}>End session</button></>}>
      <ErrorBox error={a.error} />
      {['declined', 'expired', 'ended'].includes(session.state) ? <Notice tone="info">Session {session.state}{session.end_reason ? ` (${session.end_reason.replace(/_/g, ' ')})` : ''}.</Notice> : <p className="muted">{status}</p>}
      {session.protected_content_reported && <Notice tone="warn">The phone reported protected content that cannot be captured (it appears black).</Notice>}
      <div className="video-tools">
        <button className="secondary" onClick={() => setRot((rot + 270) % 360)} title="Rotate left">⟲ Rotate left</button>
        <button className="secondary" onClick={() => setRot((rot + 90) % 360)} title="Rotate right">⟳ Rotate right</button>
        {rot !== 0 && <button className="ghost" onClick={() => setRot(0)}>Reset</button>}
        <button className="ghost" onClick={() => stage.current?.requestFullscreen?.()}>⛶ Full screen</button>
      </div>
      <div ref={stage} className="video-stage">
        <video ref={video} autoPlay playsInline muted className={rot % 180 ? 'sideways' : ''} style={{ transform: `rotate(${rot}deg)` }} />
      </div>
    </Card>
  );
}

export function ChatPage() {
  const { me } = useAuth();
  const tz = me?.company.timezone;
  const [params, setParams] = useSearchParams();
  const open = Number(params.get('c')) || null;
  const setOpen = (id: number | null) => setParams(id ? { c: String(id) } : {}, { replace: true });
  const convs = useAsync(() => get<any[]>('/conversations'));
  const emps = useAsync(() => get('/employees'));
  const [q, setQ] = useState('');
  const [picking, setPicking] = useState(false);
  const a = useAction();
  // Keep previews and unread counts fresh while the page is open.
  useEffect(() => { const t = setInterval(() => void convs.reload(), 10000); return () => clearInterval(t); }, [convs.reload]);

  const other = (c: any) => c.participants.find((p: any) => p.id !== me?.id) ?? { name: 'Conversation' };
  const list = (convs.data ?? []).filter((c) => !q || `${other(c).name} ${other(c).employee_code ?? ''}`.toLowerCase().includes(q.toLowerCase()));
  const current = (convs.data ?? []).find((c) => c.id === open);
  const start = (userId: number) => a.run(async () => { const c = await post('/conversations', { user_id: userId }); await convs.reload(); setPicking(false); setOpen(c.id); });

  return (
    <Page title="Messages" subtitle="Chat with your employees. Employees reply from the ROVEXY app.">
      <ErrorBox error={convs.error ?? a.error} />
      <div className={`chat2 ${open ? 'has-open' : ''}`}>
        <aside className="chat2-list">
          <div className="chat2-list-head">
            <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search people" aria-label="Search conversations" />
            <button className="sm" onClick={() => setPicking(!picking)}>{picking ? 'Close' : '+ New'}</button>
          </div>
          {picking && (
            <div className="chat2-pick">
              <small className="muted">Start a chat with</small>
              {(emps.data?.data ?? []).filter((e: any) => e.id !== me?.id).map((e: any) => (
                <button key={e.id} className="chat2-row" disabled={a.busy} onClick={() => start(e.id)}>
                  <Initials name={e.name} size={32} /><span className="who"><strong>{e.name}</strong><small>{e.employee_code}</small></span>
                </button>
              ))}
            </div>
          )}
          {!picking && list.length === 0 && <p className="muted chat2-empty">{convs.loading ? 'Loading…' : q ? 'No one matches that search.' : 'No conversations yet. Tap “+ New” to message an employee.'}</p>}
          {!picking && list.map((c) => {
            const o = other(c);
            return (
              <button key={c.id} className={`chat2-row ${open === c.id ? 'on' : ''} ${c.unread ? 'unread' : ''}`} onClick={() => setOpen(c.id)}>
                <Initials name={o.name} size={40} />
                <span className="who">
                  <span className="line"><strong>{o.name}</strong><small>{c.last ? ago(c.last.at) : ''}</small></span>
                  <span className="line"><small className="preview">{c.last ? `${c.last.mine ? 'You: ' : ''}${c.last.body}` : 'No messages yet'}</small>{c.unread > 0 && <span className="pill">{c.unread}</span>}</span>
                </span>
              </button>
            );
          })}
        </aside>
        {open ? <Thread key={open} id={open} userId={current ? other(current).id : undefined} title={current ? other(current).name : 'Conversation'} code={current ? other(current).employee_code : ''} tz={tz} onBack={() => setOpen(null)} onRead={() => void convs.reload()} />
          : <div className="chat2-blank"><Icon name="chat" size={40} /><p>Select a conversation, or start a new one.</p></div>}
      </div>
    </Page>
  );
}

const dayKey = (iso: string, tz?: string) => new Date(iso).toLocaleDateString('en-CA', { timeZone: tz });
function dayLabel(iso: string, tz?: string) {
  const k = dayKey(iso, tz), today = dayKey(new Date().toISOString(), tz), yest = dayKey(new Date(Date.now() - 864e5).toISOString(), tz);
  return k === today ? 'Today' : k === yest ? 'Yesterday' : new Date(iso).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: tz });
}

function Thread({ id, userId, title, code, tz, onBack, onRead }: { id: number; userId?: number; title: string; code?: string; tz?: string; onBack: () => void; onRead: () => void }) {
  const { me } = useAuth();
  const [voice, setVoice] = useState<any | null>(null);
  const call = useAction();
  const [msgs, setMsgs] = useState<any[]>([]);
  const [text, setText] = useState('');
  const a = useAction();
  const box = useRef<HTMLDivElement>(null);
  const last = useRef(0);
  const load = async () => {
    const more = await get<any[]>(`/conversations/${id}/messages?after=${last.current}`);
    if (more.length) {
      if (last.current > 0 && more.some((m) => m.sender_user_id !== me?.id)) chime();
      last.current = more[more.length - 1].id; setMsgs((m) => [...m, ...more]); onRead();
    }
  };
  useEffect(() => {
    const echo = me ? realtime(me) : null;
    const name = `company.${me?.company.id}.conversation.${id}`;
    echo?.private(name).listen('.conversation', () => void load());
    const t = setInterval(() => void load(), echo ? 30000 : 4000); // polling fallback
    void load();
    return () => { clearInterval(t); echo?.leave(name); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);
  useEffect(() => { box.current?.scrollTo({ top: box.current.scrollHeight }); }, [msgs.length]);
  // Ask for the microphone inside the click (Chrome only shows its prompt reliably on a user gesture), before ringing.
  const startCall = () => call.run(async () => {
    const mic = await getMic();
    setVoice({ session: await post('/media-sessions', { user_id: userId, kind: 'voice' }), mic });
  });
  const send = () => { if (text.trim() && !a.busy) void a.run(async () => { await post(`/conversations/${id}/messages`, { body: text }); setText(''); await load(); }); };

  return (
    <section className="chat2-thread">
      <header className="chat2-head">
        <button className="ghost chat2-back" onClick={onBack} aria-label="Back to conversations"><Icon name="chevron_left" size={20} /></button>
        <Initials name={title} size={36} />
        <span className="who"><strong>{title}</strong>{code && <small className="muted">{code}</small>}</span>
        {userId && !voice && <button className="secondary sm chat2-call" disabled={call.busy} title="Call over the internet (no SIM, no phone bill)"
          onClick={startCall}><Icon name="call" size={16} /> Call</button>}
      </header>
      <ErrorBox error={a.error ?? call.error} />
      {voice && <VoiceCall key={voice.session.uuid} session={voice.session} mic={voice.mic} name={title} onEnd={() => setVoice(null)}
        onRedial={() => { setVoice(null); void startCall(); }}
        onLog={(note) => void post(`/conversations/${id}/messages`, { body: note }).then(load).catch(() => undefined)} />}
      <div className="chat2-msgs" ref={box} aria-live="polite">
        {msgs.length === 0 && <p className="muted chat2-empty">No messages yet. Say hello.</p>}
        {msgs.map((m, i) => {
          const mine = m.sender_user_id === me?.id;
          const newDay = i === 0 || dayKey(msgs[i - 1].created_at, tz) !== dayKey(m.created_at, tz);
          return (
            <div key={m.id} className="chat2-item">
              {newDay && <div className="chat2-day"><span>{dayLabel(m.created_at, tz)}</span></div>}
              <div className={`bubble ${mine ? 'mine' : ''}`}>
                {m.body && <div className="txt">{m.body}</div>}
                {m.attachment && <div className="att">📎 {m.attachment.name}</div>}
                <div className="meta">{new Date(m.created_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZone: tz })}
                  {mine && <span className={`tick ${m.read ? 'read' : ''}`} title={m.read ? 'Read' : m.delivered ? 'Delivered' : 'Sent'}>{m.read || m.delivered ? '✓✓' : '✓'}</span>}</div>
              </div>
            </div>
          );
        })}
      </div>
      <form className="chat2-compose" onSubmit={(e) => { e.preventDefault(); send(); }}>
        <textarea value={text} rows={1} onChange={(e) => setText(e.target.value)} placeholder="Type a message…  (Enter to send, Shift+Enter for a new line)" aria-label="Message"
          onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }} />
        <button disabled={!text.trim() || a.busy} aria-label="Send">Send</button>
      </form>
    </section>
  );
}

/** Microphone for an internet call, or a reason why not (the call still connects so you can at least hear the employee). */
async function getMic(): Promise<{ stream: MediaStream | null; problem: string | null }> {
  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia)
    return { stream: null, problem: `Microphone needs a secure address — open the console at http://localhost:8000 (not ${location.host}).` };
  try {
    return { stream: await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } }), problem: null };
  } catch (e: any) {
    // Chrome says "Allowed" but Windows privacy settings can still block it — that also surfaces as NotAllowedError.
    const site = await navigator.permissions?.query({ name: 'microphone' as PermissionName }).then((p) => p.state).catch(() => 'unknown');
    if (e?.name === 'NotAllowedError' && site === 'granted')
      return { stream: null, problem: 'Windows is blocking the microphone: Settings → Privacy & security → Microphone → turn ON "Microphone access" and "Let desktop apps access your microphone", then tap Retry mic.' };
    if (e?.name === 'NotAllowedError') return { stream: null, problem: 'Microphone blocked in Chrome: click the icon left of the address bar → Microphone → Allow, then tap Retry mic.' };
    if (e?.name === 'NotFoundError') return { stream: null, problem: 'No microphone found on this computer. Plug in a headset and tap Retry mic.' };
    if (e?.name === 'NotReadableError') return { stream: null, problem: 'The microphone is busy in another app (Zoom, Teams, WhatsApp…). Close it and tap Retry mic.' };
    return { stream: null, problem: `Microphone error (${e?.name}): ${e?.message ?? e}. Tap Retry mic.` };
  }
}

/** Internet (WebRTC) voice call with the employee's app. The phone offers; this browser answers with its microphone. */
function VoiceCall({ session, mic: firstMic, name, onEnd, onRedial, onLog }: { session: any; mic: { stream: MediaStream | null; problem: string | null }; name: string; onEnd: () => void; onRedial: () => void; onLog: (note: string) => void }) {
  const { me } = useAuth();
  const [mic, setMic] = useState(firstMic);
  const micRef = useRef(firstMic);
  const sender = useRef<RTCRtpSender | null>(null);
  const audio = useRef<HTMLAudioElement>(null);
  const [state, setState] = useState<string>(session.state);
  const [status, setStatus] = useState('Ringing — waiting for the employee to answer in the app…');
  const [heard, setHeard] = useState(false);
  const [muted, setMuted] = useState(false);
  const [secs, setSecs] = useState(0);
  const uuid = session.uuid;
  const over = ['ended', 'expired', 'declined'].includes(state);

  // Ringback while the employee's phone rings; three beeps when the call ends.
  useEffect(() => { if (state !== 'requested' || heard) return; return ringback(); }, [state, heard]);
  useEffect(() => { if (over) hangupTone(); }, [over]);
  // Call history in the conversation (both sides see it): "📞 Internet call · 1:30", "📞 Missed call", …
  const logged = useRef(false);
  const secsRef = useRef(0); secsRef.current = secs;
  const heardRef = useRef(false); heardRef.current = heard;
  useEffect(() => {
    if (!over || logged.current) return;
    logged.current = true;
    const t = secsRef.current;
    onLog(state === 'declined' ? '📞 Call declined' : state === 'expired' ? '📞 Missed internet call'
      : heardRef.current ? `📞 Internet call · ${Math.floor(t / 60)}:${String(t % 60).padStart(2, '0')}` : '📞 Call cancelled');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [over]);
  useEffect(() => { if (state !== 'active' || !heard) return; const t = setInterval(() => setSecs((x) => x + 1), 1000); return () => clearInterval(t); }, [state, heard]);

  // One loop from the first second: follows the state AND picks up the phone's offer the moment it arrives (no waiting for "accepted").
  useEffect(() => {
    if (!me) return;
    let pc: RTCPeerConnection | null = null;
    let closed = false;
    let after = 0;
    const signal = (type: string, payload: unknown) => post(`/media-sessions/${uuid}/signal`, { type, payload });
    const ice = get(`/media-sessions/${uuid}/ice`).then((r) => r.ice_servers).catch(() => []);
    const handle = async (m: any) => {
      if (m.from !== 'device' || closed) return;
      if (m.type === 'offer') {
        pc?.close();
        pc = new RTCPeerConnection({ iceServers: await ice });
        pc.ontrack = (ev) => {
          const el = audio.current; if (!el) return;
          el.srcObject = ev.streams[0] ?? new MediaStream([ev.track]);
          el.play().then(() => setHeard(true)).catch(() => setStatus('Click anywhere on the page to hear the call (browser blocked autoplay).'));
        };
        pc.onicecandidate = (ev) => { if (ev.candidate) void signal('candidate', ev.candidate.toJSON()); };
        pc.onconnectionstatechange = () => {
          const st = pc?.connectionState;
          if (st === 'connected') setStatus(micRef.current.problem ?? 'Connected');
          else if (st === 'failed') setStatus('Connection failed — the phone and this computer cannot reach each other (different networks need a TURN server).');
        };
        await pc.setRemoteDescription({ type: 'offer', sdp: String(m.payload.sdp).trimEnd() + '\r\n' });
        // Answer on the phone's own audio transceiver so both voices travel on one line.
        const tr = pc.getTransceivers().find((t) => t.receiver.track?.kind === 'audio');
        // Always sendrecv: without a mic the sender is just silent, and "Retry mic" can plug one in later without renegotiating.
        if (tr) { tr.direction = 'sendrecv'; sender.current = tr.sender; const s = micRef.current.stream; if (s) await tr.sender.replaceTrack(s.getAudioTracks()[0]); }
        const ans = await pc.createAnswer();
        await pc.setLocalDescription(ans);
        await signal('answer', { type: ans.type, sdp: ans.sdp });
        setStatus('Connecting audio…');
      } else if (m.type === 'candidate' && pc) await pc.addIceCandidate(m.payload).catch(() => undefined);
      else if (m.type === 'bye') setStatus('Ended by the employee');
    };
    // Always read the ordered mailbox: a WebSocket-only listener misses an offer sent before it subscribed.
    let busy = false;
    const poll = setInterval(async () => {
      if (busy || closed) return; busy = true;
      try {
        const r = await get(`/media-sessions/${uuid}/signals${qs({ after })}`);
        for (const m of r.signals) { after = m.id; await handle(m); }
        if (r.state) setState(r.state);
      } catch { /* transient — next tick retries */ } finally { busy = false; }
    }, 500);
    return () => { closed = true; clearInterval(poll); pc?.close(); };
  }, [me, uuid]);

  const retryMic = async () => {
    const m = await getMic();
    if (m.stream) { micRef.current.stream?.getTracks().forEach((t) => t.stop()); await sender.current?.replaceTrack(m.stream.getAudioTracks()[0]); setStatus('Connected'); }
    micRef.current = m; setMic(m);
  };

  useEffect(() => { if (over) micRef.current.stream?.getTracks().forEach((t) => t.stop()); }, [over]);
  useEffect(() => () => micRef.current.stream?.getTracks().forEach((t) => t.stop()), []);

  const hangUp = async () => { await post(`/media-sessions/${uuid}/end`).catch(() => undefined); setState('ended'); };   // bar stays: Call again / Close
  const toggleMute = () => { mic.stream?.getAudioTracks().forEach((t) => { t.enabled = muted; }); setMuted(!muted); };
  const mmss = `${Math.floor(secs / 60)}:${String(secs % 60).padStart(2, '0')}`;
  return (
    <div className={`voice-bar ${over ? 'over' : ''} ${mic.problem && !over ? 'warn' : ''}`} role="status" onClick={() => void audio.current?.play().then(() => setHeard(true)).catch(() => undefined)}>
      <audio ref={audio} autoPlay playsInline />
      <span className="voice-dot" />
      <span className="who"><strong>{over ? 'Call ended' : heard ? `On call with ${name} · ${mmss}` : state === 'requested' ? `Calling ${name}…` : `Connecting to ${name}…`}</strong>
        <small>{over ? (state === 'declined' ? 'The employee declined.' : state === 'expired' ? 'No answer.' : 'Call finished.') : mic.problem && heard ? mic.problem : status}</small></span>
      {!over && mic.stream && <button className="secondary sm" onClick={toggleMute}>{muted ? 'Unmute' : 'Mute'}</button>}
      {!over && !mic.stream && <button className="secondary sm" onClick={(e) => { e.stopPropagation(); void retryMic(); }}>Retry mic</button>}
      {over && <button className="secondary sm" onClick={onRedial}><Icon name="call" size={14} /> Call again</button>}
      {over ? <button className="secondary sm" onClick={onEnd}>Close</button> : <button className="danger sm" onClick={() => void hangUp()}>Hang up</button>}
    </div>
  );
}

/** OpenStreetMap with the day's route: line in time order, a dot per fix, the latest fix highlighted with its accuracy circle. */
const LIVE_TONE: Record<string, [string, string]> = { live: ['#16a34a', 'Live'], stale: ['#d97706', 'Last seen earlier'], none: ['#94a3b8', 'No location (24 h)'] };
const initials = (n?: string | null) => (n ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('');
const esc = (t: unknown) => String(t ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]!);

/** useState that survives a page refresh in this tab (sessionStorage); falls back to plain state when storage is off. */
function useTabState<T>(key: string, init: T): [T, (v: T) => void] {
  const [v, setV] = useState<T>(() => { try { const s = sessionStorage.getItem(key); return s === null ? init : (JSON.parse(s) as T); } catch { return init; } });
  const set = (x: T) => { setV(x); try { sessionStorage.setItem(key, JSON.stringify(x)); } catch { /* private mode */ } };
  return [v, set];
}

/** All employees on one map: latest position each, refreshed every 30 s (fixes arrive at the policy's location interval). */
function LiveCard({ tz, onRoute }: { tz?: string; onRoute: (userId: number) => void }) {
  const [data, setData] = useState<any | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [auto, setAuto] = useState(true);
  const [q, setQ] = useState('');
  // Filters, the chosen employee and a past date survive a page refresh (this tab only); the data itself is re-read.
  const [show, setShow] = useTabState<'all' | 'live' | 'stale' | 'none' | 'on_call'>('live.show', 'all');
  const [sel, setSel] = useTabState<number | null>('live.sel', null);
  const [savedDay, setDay] = useTabState('live.day', '');
  const day = savedDay || new Date().toLocaleDateString('en-CA');   // '' = today, also after midnight
  const pickDay = (d: string) => setDay(d === new Date().toLocaleDateString('en-CA') ? '' : d);
  const [pf, setPf] = useTabState('live.pf', '');
  const [team, setTeam] = useTabState('live.team', '');
  const [empF, setEmpF] = useTabState('live.emp', '');
  const pfs = useAsync(() => get<any[]>('/portfolios').catch(() => []));
  const teams = useAsync(() => get<any[]>('/teams').catch(() => []));
  const [journey, setJourney] = useState<any | null>(null);
  const [focusVisit, setFocusVisit] = useState<number | null>(null);
  const today = day === new Date().toLocaleDateString('en-CA');
  // Only the newest answer is shown: after a quick filter change a slower, older reply used to overwrite it.
  const jSeq = useRef(0), lSeq = useRef(0);
  const loadJourney = () => {
    const n = ++jSeq.current;
    return sel == null ? Promise.resolve(setJourney(null))
      : get(`/day-route${qs({ user_id: sel, date: day, portfolio: pf })}`).then((d) => { if (n === jSeq.current) { setJourney(d); setError(null); } }, (e) => n === jSeq.current && setError(String(e.message ?? e)));
  };
  useEffect(() => { setJourney(null); setFocusVisit(null); void loadJourney(); }, [sel, day, pf]);
  const load = (refresh: boolean) => {
    const n = ++lSeq.current;
    return get(`/live-locations${qs({ refresh: refresh ? 1 : undefined, date: day, portfolio: pf, team, employee: empF })}`)
      .then((d) => { if (n === lSeq.current) { setData(d); setError(null); } }, (e) => n === lSeq.current && setError(String(e.message ?? e)));
  };
  useEffect(() => { void load(false); }, [day, pf, team, empF]);
  // Auto-refresh only while the tab is visible (a background tab polled all day); catch up at once when it comes back.
  useEffect(() => {
    if (!auto) return;
    const tick = () => { if (document.hidden) return; void load(true); if (today) void loadJourney(); };
    const t = setInterval(tick, 30000);
    const back = () => { if (!document.hidden) tick(); };
    document.addEventListener('visibilitychange', back);
    return () => { clearInterval(t); document.removeEventListener('visibilitychange', back); };
  }, [auto, sel, day, pf, team, empF]);
  const all: any[] = data?.employees ?? [];
  const [people, setPeople] = useState<any[]>([]);
  useEffect(() => { if (!empF && data) setPeople(data.employees); }, [data, empF]);
  const activeF = [day !== new Date().toLocaleDateString('en-CA'), pf, team, empF].filter(Boolean).length;
  const count = (k: string) => all.filter((e) => (k === 'on_call' ? e.work_state === 'on_call' : e.status === k)).length;
  const rows = all.filter((e) => (show === 'all' || (show === 'on_call' ? e.work_state === 'on_call' : e.status === show))
    && (!q || `${e.name} ${e.employee_code} ${e.groups.join(' ')}`.toLowerCase().includes(q.toLowerCase())))
    .sort((a, b) => ['live', 'stale', 'none'].indexOf(a.status) - ['live', 'stale', 'none'].indexOf(b.status) || String(a.name).localeCompare(String(b.name)));
  const time = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: tz }) : '—');
  const FILTERS: [typeof show, string, number][] = [['all', 'All', all.length], ['live', 'Live', count('live')], ['stale', data?.today === false ? 'Seen that day' : 'Seen earlier', count('stale')], ['none', 'No location', count('none')], ['on_call', 'On a call', count('on_call')]];
  return (
    <Card title="Live map · all employees" actions={<>
      <small className="muted">{data ? `Updated ${time(data.at)}` : 'Loading…'}</small>
      <label className="row" style={{ gap: 6, margin: '0 8px' }}><input type="checkbox" checked={auto} onChange={(e) => setAuto(e.target.checked)} style={{ width: 'auto' }} />Auto-refresh</label>
      <button className="secondary" onClick={() => void load(true)}>Refresh</button></>}>
      <ErrorBox error={error} />
      <div className="filters filter-bar live-top">
        <label>Date<input type="date" value={day} max={new Date().toLocaleDateString('en-CA')} onChange={(e) => e.target.value && pickDay(e.target.value)} /></label>
        <label>Portfolio<select value={pf} onChange={(e) => setPf(e.target.value)}><option value="">All portfolios</option>{(pfs.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name}</option>)}<option value="manual">Added by hand</option></select></label>
        <label>Team<select value={team} onChange={(e) => setTeam(e.target.value)}><option value="">All teams</option>{(teams.data ?? []).map((t: any) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
        <label>Employee<select value={empF} onChange={(e) => { setEmpF(e.target.value); setSel(e.target.value ? Number(e.target.value) : null); }}><option value="">All employees</option>{people.map((e: any) => <option key={e.user_id} value={e.user_id}>{e.name} ({e.employee_code})</option>)}</select></label>
        {activeF > 0 && <button className="ghost" onClick={() => { setDay(''); setPf(''); setTeam(''); setEmpF(''); setSel(null); }}>Clear filters ({activeF})</button>}
      </div>
      {data?.today === false && <Notice tone="info">Showing {new Date(data.date + 'T00:00:00').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })}: each employee's last position that day, with that day's calls and visits.</Notice>}
      <div className="live-filters">
        {FILTERS.map(([k, l, n]) => <button key={k} className={`pill${show === k ? ' on' : ''}`} onClick={() => setShow(k)}>{k !== 'all' && k !== 'on_call' && <i style={{ background: LIVE_TONE[k][0] }} />}{l} · {n}</button>)}
        <input type="search" placeholder="Search employee or group" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search employees" />
      </div>
      <div className="live-grid">
        <LiveMap rows={rows} sel={sel} onSelect={setSel} tz={tz} journey={journey} focusVisit={focusVisit} />
        {sel != null ? <Journey j={journey} emp={all.find((e) => e.user_id === sel)} day={day} setDay={pickDay} tz={tz} focus={focusVisit} onFocus={setFocusVisit}
          onBack={() => setSel(null)} onFixes={() => onRoute(sel)} /> :
        <div className="live-list" role="list">
          {rows.length === 0 && <p className="muted" style={{ padding: 12 }}>{all.length ? 'No employees match.' : 'No employees with an approved phone yet.'}</p>}
          {rows.map((e) => (
            <div key={e.user_id} role="listitem" id={`live-${e.user_id}`} className={`live-row${sel === e.user_id ? ' on' : ''}`} onClick={() => setSel(e.user_id)}>
              <span className="live-av" style={{ background: LIVE_TONE[e.status][0] }}>{initials(e.name)}</span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <strong>{e.name}</strong> <small className="muted">{e.employee_code}</small>
                <div className="muted small">{e.location ? `${LIVE_TONE[e.status][1]} · ${time(e.location.captured_at)}${e.location.accuracy_m ? ` · ±${Math.round(e.location.accuracy_m)} m` : ''}` : `No location · phone last seen ${fmtDate(e.last_contact_at, tz)}`}</div>
                <div className="small">{e.calls_today} calls ({e.connected_today} connected) · {e.visits_today} visits {data?.today === false ? 'that day' : 'today'}{e.battery_pct != null ? ` · 🔋${e.battery_pct}%` : ''}</div>
              </div>
              <div className="live-side">
                {e.work_state === 'on_call' ? <Badge value="on_call" label="on call" /> : <Badge value={e.presence} />}
                <button className="ghost" onClick={(ev) => { ev.stopPropagation(); setSel(e.user_id); }}>Route &amp; visits</button>
              </div>
            </div>
          ))}
        </div>}
      </div>
      <div className="map-legend">{Object.entries(LIVE_TONE).map(([k, [c, l]]) => <span key={k}><i style={{ display: 'inline-block', width: 10, height: 10, borderRadius: '50%', background: c }} />{l}</span>)}<span>Live = position in the last 15 min · click an employee for their route and the customers they visited</span></div>
    </Card>
  );
}

const km = (m: number) => (m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`);
/** Right-hand panel for one employee: day picker, totals, and the customers visited in order (click = show on map). */
function Journey({ j, emp, day, setDay, tz, focus, onFocus, onBack, onFixes }: { j: any | null; emp: any; day: string; setDay: (d: string) => void; tz?: string;
  focus: number | null; onFocus: (n: number) => void; onBack: () => void; onFixes: () => void }) {
  const t = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: tz }) : '—');
  const s = j?.summary;
  return (
    <div className="live-list jr-panel">
      <div className="jr-head">
        <button className="ghost" onClick={onBack}>← All employees</button>
        <div className="row" style={{ gap: 8, alignItems: 'center' }}>
          <span className="live-av" style={{ background: LIVE_TONE[emp?.status ?? 'none'][0] }}>{initials(emp?.name ?? j?.employee?.name)}</span>
          <div style={{ flex: 1 }}><strong>{emp?.name ?? j?.employee?.name}</strong><div className="muted small">{emp?.employee_code}{emp?.location ? ` · ${LIVE_TONE[emp.status][1].toLowerCase()} ${t(emp.location.captured_at)}` : ''}</div></div>
          <input type="date" value={day} max={new Date().toLocaleDateString('en-CA')} onChange={(e) => e.target.value && setDay(e.target.value)} aria-label="Day" style={{ width: 'auto' }} />
        </div>
      </div>
      {!j ? <p className="muted" style={{ padding: 12 }}>Loading route…</p> : <>
        <div className="jr-stats">
          <div><b>{km(s.distance_m)}</b><small>travelled</small></div>
          <div><b>{s.customers}</b><small>customers visited</small></div>
          <div><b>{s.connected}/{s.calls}</b><small>calls connected</small></div>
          <div><b>{t(s.first_at)}–{t(s.last_at)}</b><small>on the move</small></div>
        </div>
        <div className="jr-sub">Customers covered · in order</div>
        {j.visits.length === 0 && <p className="muted" style={{ padding: '0 12px 12px' }}>No customer check-ins on this day. Visits appear when the employee taps "I've reached · Check in" within 100 m of a customer.</p>}
        {j.visits.map((v: any) => (
          <div key={v.n} className={`jr-row${focus === v.n ? ' on' : ''}`} onClick={() => onFocus(v.n)}>
            <span className="jr-num">{v.n}</span>
            <div style={{ flex: 1, minWidth: 0 }}>
              <strong>{v.name ?? 'Customer'}</strong> <small className="muted">{v.customer_ref}</small>
              <div className="muted small">{t(v.at)}{v.distance_m != null ? ` · ${v.distance_m} m from the pin` : ''}{v.pin ? '' : ' · customer has no pin'}</div>
              {v.address && <div className="small jr-addr">{v.address}</div>}
            </div>
            <Link className="ghost small" to={`/customers/${v.customer_id}`} onClick={(e) => e.stopPropagation()}>Case →</Link>
          </div>
        ))}
        {j.fixes.length === 0 && <p className="muted" style={{ padding: 12 }}>No location received on this day.</p>}
        <div style={{ padding: 12 }}><button className="secondary" onClick={onFixes}>All {s.fixes} location fixes ↓</button></div>
      </>}
    </div>
  );
}

function LiveMap({ rows, sel, onSelect, tz, journey, focusVisit }: { rows: any[]; sel: number | null; onSelect: (id: number) => void; tz?: string; journey: any | null; focusVisit: number | null }) {
  const layer = useRef<L.LayerGroup | null>(null);
  const visitPins = useRef<L.Marker[]>([]);
  const el = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const pins = useRef<Map<number, L.Marker>>(new Map());
  const fitted = useRef(false);
  const pick = useRef(onSelect); pick.current = onSelect;
  useEffect(() => {
    if (!el.current) return;
    const m = L.map(el.current, { scrollWheelZoom: true }).setView([20.59, 78.96], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
    map.current = m;
    layer.current = L.layerGroup().addTo(m);
    return () => { m.remove(); map.current = null; pins.current.clear(); fitted.current = false; };
  }, []);
  // Selected employee's day: trail (start → now), numbered customer visits, dashed line from check-in spot to the customer's pin.
  useEffect(() => {
    const m = map.current, g = layer.current; if (!m || !g) return;
    g.clearLayers(); visitPins.current = [];
    pins.current.forEach((p, id) => p.setOpacity(journey && id !== journey.employee.id ? 0.35 : 1));
    if (!journey) return;
    const t = (iso: string) => new Date(iso).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: tz });
    const trail = journey.fixes.filter((f: any) => f[3] == null || f[3] <= 100).map((f: any) => [f[0], f[1]] as [number, number]);
    const bounds: [number, number][] = [...trail];
    if (trail.length > 1) {
      L.polyline(trail, { color: '#fff', weight: 8, opacity: 0.9 }).addTo(g);
      L.polyline(trail, { color: '#1f3a5f', weight: 4, opacity: 0.95 }).addTo(g);
      journey.fixes.forEach((f: any) => L.circleMarker([f[0], f[1]], { radius: 2.5, color: '#1f3a5f', weight: 1, fillColor: '#fff', fillOpacity: 1 }).bindTooltip(t(f[2])).addTo(g));
    }
    if (trail.length) L.marker(trail[0], { icon: L.divIcon({ className: '', html: `<div class="jr-start">Start ${esc(t(journey.fixes[0][2]))}</div>`, iconSize: [0, 0] }) }).addTo(g);
    journey.visits.forEach((v: any) => {
      const at = v.pin ?? v.checkin; if (!at) return;
      bounds.push([at.lat, at.lng]);
      if (v.pin && v.checkin && (v.distance_m ?? 0) > 5) L.polyline([[v.checkin.lat, v.checkin.lng], [v.pin.lat, v.pin.lng]], { color: '#c9a35e', weight: 2, dashArray: '4 4' }).addTo(g);
      const html = `<div class="jr-visit"><span>${v.n}</span><b>${esc(v.name ?? 'Customer')}<i>${esc(t(v.at))}</i></b></div>`;
      visitPins.current[v.n - 1] = L.marker([at.lat, at.lng], { icon: L.divIcon({ className: '', html, iconSize: [0, 0] }), zIndexOffset: 2000 })
        .bindPopup(`<b>${v.n}. ${esc(v.name)}</b> (${esc(v.customer_ref)})<br>Checked in ${esc(t(v.at))}${v.distance_m != null ? ` · ${v.distance_m} m from the pin` : ''}${v.address ? `<br>${esc(v.address)}` : ''}`).addTo(g);
    });
    if (bounds.length === 1) m.setView(bounds[0], 16); else if (bounds.length) m.fitBounds(L.latLngBounds(bounds), { padding: [50, 50], maxZoom: 17 });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [journey, tz]);
  useEffect(() => {
    const p = focusVisit != null ? visitPins.current[focusVisit - 1] : null;
    if (p && map.current) { map.current.flyTo(p.getLatLng(), Math.max(map.current.getZoom(), 17), { duration: 0.6 }); p.openPopup(); }
  }, [focusVisit]);
  // Markers are moved in place on each refresh (no flicker); employees who left the filter are removed.
  useEffect(() => {
    const m = map.current; if (!m) return;
    const seen = new Set<number>();
    rows.filter((e) => e.location).forEach((e) => {
      seen.add(e.user_id);
      const ll: [number, number] = [e.location.lat, e.location.lng];
      const html = `<div class="live-pin${sel === e.user_id ? ' on' : ''}"><span style="background:${LIVE_TONE[e.status][0]}">${esc(initials(e.name))}</span><b>${esc(e.name)}</b></div>`;
      const icon = L.divIcon({ className: '', html, iconSize: [0, 0], iconAnchor: [0, 0] });
      const tip = `<b>${esc(e.name)}</b> (${esc(e.employee_code)})<br>${LIVE_TONE[e.status][1]} · ${new Date(e.location.captured_at).toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', timeZone: tz })}${e.work_state === 'on_call' ? '<br>On a call now' : ''}`;
      let p = pins.current.get(e.user_id);
      if (!p) { p = L.marker(ll, { icon }).addTo(m).on('click', () => pick.current(e.user_id)); pins.current.set(e.user_id, p); }
      else { p.setLatLng(ll); p.setIcon(icon); }
      p.unbindTooltip().bindTooltip(tip, { direction: 'top', offset: [0, -18] });
      p.setZIndexOffset(sel === e.user_id ? 1000 : e.status === 'live' ? 500 : 0);
    });
    pins.current.forEach((p, id) => { if (!seen.has(id)) { p.remove(); pins.current.delete(id); } });
    if (!fitted.current && seen.size) { fitted.current = true; fitAll(); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rows, sel, tz]);
  useEffect(() => {
    const p = sel != null ? pins.current.get(sel) : null;
    if (p && map.current && sel == null) map.current.flyTo(p.getLatLng(), Math.max(map.current.getZoom(), 15), { duration: 0.6 });
    if (sel != null) document.getElementById(`live-${sel}`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }, [sel]);
  const fitAll = () => {
    const pts = [...pins.current.values()].map((p) => p.getLatLng());
    if (!map.current || !pts.length) return;
    if (pts.length === 1) map.current.setView(pts[0], 15); else map.current.fitBounds(L.latLngBounds(pts), { padding: [40, 40], maxZoom: 15 });
  };
  return (
    <div className="live-map-wrap">
      <div ref={el} className="map live-map" role="region" aria-label="Map of all employees" />
      <button className="secondary live-fit" onClick={fitAll}>Show all</button>
    </div>
  );
}

function LocationMap({ fixes, focus, tz }: { fixes: any[]; focus: number | null; tz?: string }) {
  const el = useRef<HTMLDivElement>(null);
  const map = useRef<L.Map | null>(null);
  const dots = useRef<L.CircleMarker[]>([]);
  useEffect(() => {
    if (!el.current) return;
    const m = L.map(el.current, { scrollWheelZoom: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(m);
    map.current = m;
    return () => { m.remove(); map.current = null; };
  }, []);
  useEffect(() => {
    const m = map.current; if (!m) return;
    m.eachLayer((l) => { if (!(l instanceof L.TileLayer)) m.removeLayer(l); });
    const pts = fixes.map((f) => [Number(f.lat), Number(f.lng)] as [number, number]);
    if (pts.length > 1) L.polyline(pts, { color: '#1f3a5f', weight: 3, opacity: 0.7 }).addTo(m);
    const last = fixes.length - 1;
    dots.current = fixes.map((f, i) => {
      const when = new Date(f.captured_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZone: tz });
      if (i === last && f.accuracy_m) L.circle(pts[i], { radius: Number(f.accuracy_m), color: '#c9a35e', weight: 1, fillOpacity: 0.12 }).addTo(m);
      return L.circleMarker(pts[i], { radius: i === last ? 9 : 5, color: '#fff', weight: 2, fillColor: i === last ? '#c9a35e' : '#2563a8', fillOpacity: 1 })
        .bindPopup(`<b>${i === last ? 'Latest · ' : ''}${when}</b><br>${pts[i][0].toFixed(5)}, ${pts[i][1].toFixed(5)}${f.accuracy_m ? `<br>±${Math.round(f.accuracy_m)} m` : ''}`)
        .addTo(m);
    });
    if (pts.length === 1) m.setView(pts[0], 17); else m.fitBounds(L.latLngBounds(pts), { padding: [30, 30], maxZoom: 17 });
  }, [fixes, tz]);
  useEffect(() => {
    const d = focus != null ? dots.current[focus] : null;
    if (d && map.current) { map.current.setView(d.getLatLng(), Math.max(map.current.getZoom(), 17)); d.openPopup(); el.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }, [focus]);
  return (
    <div className="map-wrap">
      <div ref={el} className="map" role="region" aria-label="Map of location fixes" />
      <div className="map-legend"><span><i className="dot-latest" />Latest</span><span><i className="dot-fix" />Earlier fix</span><span><i className="line" />Route</span><span className="muted">{fixes.length} fixes · click a row to show it</span></div>
    </div>
  );
}
