import { useEffect, useRef, useState } from 'react';
import { get, post, qs } from '../lib/api';
import { UA, WebSocketInterface, debug as sipDebug } from 'jssip';
import type { RTCSession } from 'jssip/lib/RTCSession';
import { Badge, Card, ErrorBox, Notice, Page, Pager, Table, useAction, useAsync } from '../components/ui';

/**
 * G14: a telecaller calls their own customers from the console. Click2Call: the telecaller's work phone rings first;
 * when they answer, the customer is dialled. The call, recording and outcome are kept exactly as for calls from the app.
 */
const STATE: Record<string, string> = { initiated: 'Ringing your phone…', agent_answered: 'Calling the customer…', ringing: 'Customer\'s phone is ringing…',
  answered: 'Connected', connected: 'Connected', completed: 'Call ended', disconnected: 'Call ended', failed: 'Call failed', cancelled: 'Cancelled', busy: 'Customer busy', no_answer: 'No answer' };

/**
 * SmartDCM WebRTC (23 Nov 2026): talk in the browser. ROVEXY asks SmartDCM for a one-time dial code and this person's SIP
 * login; the browser dials the code (never the customer's number) over SmartDCM's WSS; SmartDCM connects and records it.
 */
type Sip = { dial: string; wss_url: string; domain: string; username: string; password: string; display_name: string; ice_servers: RTCIceServer[] };

function useBrowserCall() {
  const [sipState, setSipState] = useState<string | null>(null);
  const [muted, setMuted] = useState(false);
  const ref = useRef<{ ua: UA; session?: RTCSession; audio: HTMLAudioElement } | null>(null);
  const stop = () => { try { ref.current?.session?.terminate(); } catch { /* already ended */ } try { ref.current?.ua.stop(); } catch { /* */ } ref.current = null; };
  useEffect(() => () => stop(), []);
  const start = (sip: Sip, callId: string) => {
    stop();
    sipDebug.disable();
    const audio = new Audio();
    audio.autoplay = true;
    const ua = new UA({ sockets: [new WebSocketInterface(sip.wss_url)], uri: `sip:${sip.username}@${sip.domain}`, password: sip.password,
      display_name: sip.display_name, register: false, session_timers: false });
    ref.current = { ua, audio };
    setSipState('connecting');
    setMuted(false);
    ua.on('connected', () => {
      const session = ua.call(`sip:${sip.dial}@${sip.domain}`, {
        mediaConstraints: { audio: true, video: false }, extraHeaders: [`X-Rovexy-Ref: ${callId}`],
        pcConfig: { iceServers: sip.ice_servers?.length ? sip.ice_servers : [{ urls: 'stun:stun.l.google.com:19302' }] },
      });
      if (ref.current) ref.current.session = session;
      session.on('peerconnection', (e: any) => e.peerconnection.addEventListener('track', (t: RTCTrackEvent) => { audio.srcObject = t.streams[0]; }));
      session.on('progress', () => setSipState('ringing'));
      session.on('confirmed', () => setSipState('connected'));
      session.on('ended', () => { setSipState('ended'); stop(); });
      session.on('failed', (e: any) => { setSipState(e?.cause === 'User Denied Media Access' ? 'mic_blocked' : `failed: ${e?.cause ?? 'call failed'}`); stop(); });
    });
    ua.on('disconnected', () => setSipState((s) => (s === 'connecting' ? 'failed: SmartDCM calling server not reachable' : s)));
    ua.start();
  };
  const toggleMute = () => { const s = ref.current?.session; if (!s) return; if (muted) s.unmute({ audio: true }); else s.mute({ audio: true }); setMuted(!muted); };
  return { sipState, start, stop, muted, toggleMute };
}

const SIP_STATE: Record<string, string> = { connecting: 'Connecting to SmartDCM…', ringing: 'Customer\'s phone is ringing…', connected: 'Connected — talk now', ended: 'Call ended',
  mic_blocked: 'Microphone blocked: allow the microphone for this site (lock icon in the address bar) and call again.' };

export function MyCallsPage() {
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const list = useAsync(() => get(`/my/customers${qs({ q, page })}`), [q, page]);
  const [call, setCall] = useState<any | null>(null);
  const a = useAction();
  useEffect(() => {
    if (!call || call.terminal) return;
    const t = setInterval(async () => { try { setCall({ ...(await get(`/my/calls/${call.call_id}`)), name: call.name, web: call.web }); } catch { /* keep the last state */ } }, 3000);
    return () => clearInterval(t);
  }, [call]);
  const d = list.data;
  const start = (c: any, which: string) => a.run(async () => setCall({ ...(await post(`/my/customers/${c.id}/call`, { which })), name: c.name }));
  const web = useBrowserCall();
  const startWeb = (c: any, which: string) => a.run(async () => {
    const r = await post(`/my/customers/${c.id}/webrtc`, { which });
    setCall({ ...r, name: c.name, web: true });
    web.start(r.sip, r.call_id);
  });
  // A browser call that was answered ends with the SIP hang-up; SmartDCM then reports talk time and the recording (polled).
  const hangUp = () => a.run(async () => { const talked = call.web && web.sipState === 'connected'; web.stop(); if (talked) return; await post(`/my/calls/${call.call_id}/hangup`); setCall({ ...(await get(`/my/calls/${call.call_id}`)), name: call.name, web: call.web }); });
  const busy = a.busy || (call && !call.terminal);
  return (
    <Page title="My calls" subtitle="Call your customers from here. Your work phone rings first; answer it and ROVEXY dials the customer. Calls are recorded and kept like calls from the app.">
      <ErrorBox error={list.error ?? a.error} />
      {d && !d.can_call && !d.can_webrtc && <Notice tone="warn">Click2Call is not enabled for you, or not set up for the company. Ask your administrator (Calling configuration → People &amp; access).</Notice>}
      {d?.can_call && <p className="muted small">Your work phone: {d.work_number ?? 'not set — ask your administrator'}</p>}
      {d?.can_webrtc && <p className="muted small">“In browser” calls go through SmartDCM: use a headset; the browser asks for the microphone the first time.</p>}
      {call && (
        <Card title={`Call · ${call.name}`} actions={<Badge value={call.terminal ? (call.state === 'failed' ? 'bad' : 'muted') : 'ok'} label={call.terminal ? 'ended' : 'live'} />}>
          <p style={{ marginTop: 0, fontSize: '1.1rem' }}>{call.web && web.sipState && !call.terminal ? (SIP_STATE[web.sipState] ?? web.sipState) : (STATE[call.state] ?? call.state)}{call.talk_seconds ? ` · ${call.talk_seconds} s` : ''}{call.failure_reason ? ` · ${call.failure_reason.replace(/_/g, ' ')}` : ''}</p>
          <div className="row">
            {!call.terminal && <button className="danger" disabled={a.busy} onClick={() => void hangUp()}>Hang up</button>}
            {call.web && web.sipState === 'connected' && <button className="secondary" onClick={web.toggleMute}>{web.muted ? 'Unmute' : 'Mute'}</button>}
            {call.terminal && <button className="secondary" onClick={() => setCall(null)}>Close</button>}
            {call.terminal && <small className="muted">Recording: {call.recording_status?.replace(/_/g, ' ') ?? '—'}. Record the outcome on the customer in the app or ask your manager.</small>}
          </div>
        </Card>
      )}
      <Card>
        <input placeholder="Search name, customer ID or phone" value={q} onChange={(e) => { setQ(e.target.value); setPage(1); }} style={{ maxWidth: 360, marginBottom: 10 }} />
        <Table rows={d?.data} empty="No customers are assigned to you." columns={[
          { key: 'name', label: 'Customer', render: (c: any) => <span>{c.name}<br /><small className="muted">{c.customer_ref} · {c.phone}{c.city ? ` · ${c.city}` : ''}</small></span> },
          { key: 'status', label: 'Status', render: (c: any) => <Badge value="info" label={String(c.status).replace(/_/g, ' ')} /> },
          { key: 'follow_up_on', label: 'Follow-up', render: (c: any) => c.follow_up_on?.slice(0, 10) ?? '' },
          { key: 'overdue_amount', label: 'Overdue / to collect ₹', align: 'right', render: (c: any) => (c.overdue_amount ? Number(c.overdue_amount).toLocaleString('en-IN') : '') },
          { key: 'call', label: '', render: (c: any) => <span className="row">
            {d?.can_call && <button disabled={busy} onClick={() => void start(c, 'primary')}>Call</button>}
            {d?.can_webrtc && <button className={d?.can_call ? 'secondary' : undefined} disabled={busy} onClick={() => void startWeb(c, 'primary')}>In browser</button>}
            {c.has_alternate && d?.can_call && <button className="secondary" disabled={busy} onClick={() => void start(c, 'alternate')}>Alternate</button>}
            {c.has_alternate && !d?.can_call && d?.can_webrtc && <button className="secondary" disabled={busy} onClick={() => void startWeb(c, 'alternate')}>Alternate</button>}
          </span> },
        ]} />
        <Pager meta={d} onPage={setPage} />
      </Card>
    </Page>
  );
}
