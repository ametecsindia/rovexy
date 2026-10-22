import { useMemo, useState } from 'react';
import { get, post, put, fmtDate } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, ErrorBox, Icon, Modal, Notice, Page, toast, useAction, useAsync } from '../components/ui';

type Control = { key: string; type: string; default: any; scope: 'device' | 'app'; label: string; enum?: string[] | null; min?: number | null; max?: number | null; min_sdk: number; setup_note?: string | null };
type Profile = { id: number; name: string; description?: string | null; recommended: boolean; standard: Record<string, any> | null; versions: any[]; assignments: { target_type: string; target_id: number; label: string }[] };
type Text = { title: string; help: string; options?: Record<string, string>; unit?: string };

/** Plain-language names and help for every control (the API keys stay technical; admins never need to see them). */
const T: Record<string, Text> = {
  'calling.enabled': { title: 'Customer calling', help: 'Employees can call their assigned customers from the app.' },
  'calling.hours': { title: 'Calling hours', help: 'Calls can only be placed on these days and between these times (company time zone).' },
  'calling.allowed_prefixes': { title: 'Allowed number prefixes', help: 'Only numbers starting with these codes can be called, e.g. +91. One per line.' },
  'calling.manual_dial': { title: 'Dial any number', help: 'Let employees type and call numbers that are not assigned customers.' },
  'calling.agent_leg': { title: 'How the employee joins a company call', help: 'Normal phone call back (works everywhere) or an internet calling line (SIP).', options: { pstn_callback: 'Phone rings (recommended)', sip_endpoint: 'Internet line (SIP)' } },
  'recording.mode': { title: 'Call recording', help: 'Optional: recorded when the phone can. Required: the phone must record every call.', options: { off: 'Off', optional: 'Optional', mandatory: 'Required' } },
  'recording.on_failure': { title: 'If a required recording fails', help: 'What happens when a call should be recorded but cannot be.', options: { continue_flagged: 'Allow the call, flag it for review', terminate: 'End the call' } },
  'recording.notice': { title: 'Recording notice', help: 'Text shown to employees about call recording.' },
  'location.enabled': { title: 'Location during work', help: 'Shares the phone’s location while the employee is signed in for work. A notification is always shown.' },
  'location.interval_seconds': { title: 'Location update every', help: 'How often a new position is sent.', unit: 'seconds' },
  'location.background': { title: 'Location while the app is closed', help: 'Keep sharing location when the app is in the background (work time only). Not available on the Play Store app.' },
  'live_view.enabled': { title: 'Live screen view', help: 'Supervisors can ask to see the phone screen live. The employee must accept every time.' },
  'live_view.screenshots': { title: 'Screenshots during live view', help: 'Supervisors can save a screenshot while watching.' },
  'live_view.quality': { title: 'Picture quality', help: 'Higher quality uses more mobile data.', options: { low: 'Low (saves data)', medium: 'Medium', high: 'High' } },
  'live_view.fps': { title: 'Smoothness', help: 'Frames per second. 10 is smooth enough for most screens.', unit: 'frames / second' },
  'live_view.timeout_minutes': { title: 'Live view ends after', help: 'A session stops automatically after this time.', unit: 'minutes' },
  'live_view.max_concurrent': { title: 'Live views at the same time', help: 'Maximum live screen sessions running at once in the company.' },
  'camera_session.enabled': { title: 'Camera sessions', help: 'Supervisors can ask to see through the phone camera. The employee must accept every time.' },
  'camera_session.timeout_minutes': { title: 'Camera session ends after', help: 'A camera session stops automatically after this time.', unit: 'minutes' },
  'attachments.allowed_types': { title: 'File types in chat', help: 'File types employees can send in chat. One per line (e.g. image/jpeg, application/pdf).' },
  'attachments.max_mb': { title: 'Largest chat file', help: 'Maximum size of one attachment.', unit: 'MB' },
  'sharing.destinations': { title: 'Share to', help: 'Where employees may share content from the app. One per line.' },
  'app.speaker_allowed': { title: 'Speaker button', help: 'Show the speaker button during calls.' },
  'app.mute_allowed': { title: 'Mute button', help: 'Show the mute button during calls.' },
  'offline.access_minutes': { title: 'Work offline for up to', help: 'How long the app keeps working without internet before asking to reconnect. 0 = never offline.', unit: 'minutes' },
  'revocation.active_call': { title: 'Call in progress when access is removed', help: 'What happens to a running call if you remove a phone’s access.', options: { terminate: 'End it immediately', allow_finish: 'Let it finish' } },
  'compliance.mandatory': { title: 'Must be working before the employee can start', help: 'If any of these cannot be applied on a phone, the employee is blocked until it is fixed.' },
  'retention.recording_days': { title: 'Keep call recordings for', help: 'Recordings older than this are deleted.', unit: 'days' },
  'retention.chat_days': { title: 'Keep chat messages for', help: 'Messages older than this are deleted.', unit: 'days' },
  'retention.location_days': { title: 'Keep location history for', help: 'Location points older than this are deleted.', unit: 'days' },
  'apps.mode': { title: 'App control', help: 'Allow only listed apps, or block listed apps.', options: { allowlist: 'Allow only listed apps', blocklist: 'Block listed apps' } },
  'apps.packages': { title: 'Listed apps', help: 'Android package names, one per line (ROVEXY itself is always allowed).' },
  'apps.unknown_sources_allowed': { title: 'Install apps from outside the Play Store', help: 'Allow installing APK files.' },
  'kiosk.enabled': { title: 'Kiosk mode', help: 'The phone only shows approved apps.' },
  'kiosk.settings_access': { title: 'Settings in kiosk mode', help: 'Whether employees can open phone settings in kiosk mode.', options: { allowed: 'Allowed', blocked: 'Blocked' } },
  'device.camera': { title: 'Phone camera', help: 'Block the camera for all apps.', options: { allowed: 'Allowed', blocked: 'Blocked' } },
  'device.microphone': { title: 'Phone microphone', help: 'Block the microphone for all apps (Android 12+).', options: { allowed: 'Allowed', blocked: 'Blocked' } },
  'device.adjust_volume_disabled': { title: 'Block volume changes', help: 'Employees cannot change the volume.' },
  'device.set_wallpaper_disabled': { title: 'Block wallpaper changes', help: 'Employees cannot change the wallpaper.' },
  'device.screen_capture_disabled': { title: 'Block screenshots', help: 'Blocks screenshots and screen recording. Turns off live screen view too.' },
  'device.usb_data': { title: 'USB file transfer', help: 'Control copying files over USB.', options: { allowed: 'Allowed', no_file_transfer: 'Charging + no file transfer', no_data: 'Charging only' } },
  'device.external_storage_disabled': { title: 'Block SD cards / USB drives', help: 'External storage cannot be used.' },
  'device.bluetooth_config_disabled': { title: 'Block Bluetooth settings', help: 'Employees cannot change Bluetooth settings.' },
  'device.location_mode': { title: 'Phone location setting', help: 'Force location on, or let the employee choose.', options: { enforced: 'Always on', user_choice: 'Employee chooses' } },
};

/** Groups shown on the page; `main` is the on/off switch shown in the group header. */
const GROUPS: { id: string; title: string; icon: string; blurb: string; main?: string; keys: string[]; advanced?: boolean }[] = [
  { id: 'calling', title: 'Customer calling', icon: 'phone', main: 'calling.enabled', blurb: 'Who can be called, and when.', keys: ['calling.hours', 'calling.allowed_prefixes', 'calling.manual_dial', 'calling.agent_leg', 'app.speaker_allowed', 'app.mute_allowed'] },
  { id: 'recording', title: 'Call recording', icon: 'mic', blurb: 'Recording of customer calls.', keys: ['recording.mode', 'recording.on_failure', 'recording.notice'] },
  { id: 'location', title: 'Location', icon: 'pin', main: 'location.enabled', blurb: 'Position on the field map during work time.', keys: ['location.interval_seconds', 'location.background'] },
  { id: 'live', title: 'Live screen view', icon: 'phone', main: 'live_view.enabled', blurb: 'Watch an employee’s screen with their permission.', keys: ['live_view.screenshots', 'live_view.quality', 'live_view.fps', 'live_view.timeout_minutes', 'live_view.max_concurrent'] },
  { id: 'camera', title: 'Camera sessions', icon: 'event', main: 'camera_session.enabled', blurb: 'See through the phone camera with the employee’s permission.', keys: ['camera_session.timeout_minutes'] },
  { id: 'chat', title: 'Chat & files', icon: 'chat', blurb: 'What can be shared in chat.', keys: ['attachments.allowed_types', 'attachments.max_mb', 'sharing.destinations'] },
  { id: 'access', title: 'Access & safety', icon: 'shield', blurb: 'Offline use, removing access and required features.', keys: ['compliance.mandatory', 'offline.access_minutes', 'revocation.active_call'] },
  { id: 'retention', title: 'Data kept for', icon: 'chart', blurb: 'How long data is stored before it is deleted.', keys: ['retention.recording_days', 'retention.chat_days', 'retention.location_days'] },
  { id: 'device', title: 'Phone restrictions', icon: 'settings', advanced: true, blurb: 'Only for phones enrolled with Android Management. Ignored on other phones.',
    keys: ['apps.mode', 'apps.packages', 'apps.unknown_sources_allowed', 'kiosk.enabled', 'kiosk.settings_access', 'device.camera', 'device.microphone', 'device.screen_capture_disabled', 'device.usb_data', 'device.external_storage_disabled', 'device.bluetooth_config_disabled', 'device.location_mode', 'device.adjust_volume_disabled', 'device.set_wallpaper_disabled'] },
];
const MANDATORY_CHOICES = ['calling.enabled', 'recording.mode', 'location.enabled'];
const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);
const published = (p: Profile) => p.versions.find((v) => v.status === 'published');

export function PoliciesPage() {
  const { can } = useAuth();
  const catalog = useAsync(() => get<Control[]>('/policy/catalog'));
  const profiles = useAsync(() => get<Profile[]>('/policies'));
  const [selected, setSelected] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);
  const a = useAction();
  const list = profiles.data ?? [];
  const profile = list.find((p) => p.id === selected) ?? list.find((p) => p.recommended) ?? list[0];
  const standard = list.find((p) => p.recommended);
  const companyWide = list.find((p) => p.assignments.some((x) => x.target_type === 'company'));

  return (
    <Page title="Policies" subtitle="Rules for the employee app: what phones may do, and what supervisors can see. Changes reach phones within a minute."
      actions={can('policies.edit') && <button onClick={() => setCreating(true)}>+ New policy</button>}>
      <ErrorBox error={catalog.error ?? profiles.error ?? a.error} />
      {profiles.data && !companyWide && (
        <Notice tone="warn">
          <strong>No policy is applied to all phones yet.</strong> Phones use built-in settings, where live screen view and camera sessions are off.
          {standard && can('policies.publish') && <> <button className="pol-inline" disabled={a.busy} onClick={() => a.run(async () => {
            await put('/policy-assignments', { profile_id: standard.id, target_type: 'company' });
            toast('ROVEXY Standard now applies to all phones'); await profiles.reload();
          })}>Use ROVEXY Standard for all phones</button></>}
        </Notice>
      )}
      <div className="pol-layout">
        <nav className="pol-list" aria-label="Policies">
          {list.map((p) => {
            const v = published(p);
            return (
              <button key={p.id} className={`pol-item${profile?.id === p.id ? ' on' : ''}`} onClick={() => setSelected(p.id)}>
                <span className="pol-item-top"><strong>{p.name}</strong>{p.recommended && <span className="badge info">Recommended</span>}</span>
                <span className="pol-item-sub">{v ? `Live · version ${v.version}` : 'Not live yet'}</span>
                <span className="pol-item-sub">{p.assignments.length ? p.assignments.map((x) => x.label).join(' · ') : 'Not applied to any phone'}</span>
              </button>
            );
          })}
          {!profiles.data && <p className="muted">Loading…</p>}
        </nav>
        {profile && catalog.data && <Editor key={`${profile.id}-${profile.versions[0]?.id ?? 0}`} profile={profile} catalog={catalog.data} onChanged={profiles.reload} />}
      </div>
      {creating && <CreateModal profiles={list} onClose={() => setCreating(false)} onCreated={async (id) => { setCreating(false); await profiles.reload(); setSelected(id); }} />}
    </Page>
  );
}

function CreateModal({ profiles, onClose, onCreated }: { profiles: Profile[]; onClose: () => void; onCreated: (id: number) => void }) {
  const [name, setName] = useState('');
  const [from, setFrom] = useState(String(profiles.find((p) => p.recommended)?.id ?? ''));
  const a = useAction();
  return (
    <Modal title="New policy" onClose={onClose}>
      <ErrorBox error={a.error} />
      <label className="field"><span>Name</span><input autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Field collectors" /></label>
      <label className="field"><span>Start from</span>
        <select value={from} onChange={(e) => setFrom(e.target.value)}>
          <option value="">Built-in settings (nothing changed)</option>
          {profiles.map((p) => <option key={p.id} value={p.id}>A copy of “{p.name}”</option>)}
        </select>
      </label>
      <div className="row" style={{ justifyContent: 'flex-end', marginTop: 12 }}>
        <button className="secondary" onClick={onClose}>Cancel</button>
        <button disabled={a.busy || !name.trim()} onClick={() => a.run(async () => {
          const { id } = await post<{ id: number }>('/policies', { name: name.trim() });
          const src = profiles.find((p) => String(p.id) === from);
          const settings = src ? (src.versions[0]?.settings ?? {}) : {};
          if (Object.keys(settings).length) await post(`/policies/${id}/versions`, { settings, notes: `Copied from ${src!.name}` });
          toast('Policy created. Review it, then save to make it live.');
          onCreated(id);
        })}>Create</button>
      </div>
    </Modal>
  );
}

function Editor({ profile, catalog, onChanged }: { profile: Profile; catalog: Control[]; onChanged: () => void }) {
  const { can } = useAuth();
  const byKey = useMemo(() => Object.fromEntries(catalog.map((c) => [c.key, c])), [catalog]);
  const latest = profile.versions[0];
  const live = published(profile);
  const base = latest?.settings ?? {};
  const [settings, setSettings] = useState<Record<string, any>>(() => ({ ...base }));
  const [search, setSearch] = useState('');
  const [showDevice, setShowDevice] = useState(false);
  const [issues, setIssues] = useState<{ draftId: number; list: any[] } | null>(null);
  const [history, setHistory] = useState(false);
  const a = useAction();
  const editable = can('policies.edit');
  const dirty = !same(settings, base);
  const pendingDraft = latest?.status === 'draft';

  const value = (k: string) => (k in settings ? settings[k] : byKey[k]?.default);
  const set = (k: string, v: any) => setSettings((s) => ({ ...s, [k]: v }));
  const reset = (k: string) => setSettings((s) => { const n = { ...s }; delete n[k]; return n; });
  const q = search.trim().toLowerCase();
  const match = (k: string) => !q || `${T[k]?.title} ${T[k]?.help}`.toLowerCase().includes(q);

  const publish = async (draftId: number, accept: boolean) => {
    await post(`/policies/${profile.id}/versions/${draftId}/publish`, { accept_warnings: accept });
    setIssues(null);
    toast(profile.assignments.length ? 'Saved. Phones using this policy update within a minute.' : 'Saved. Apply it to phones below to use it.');
    onChanged();
  };
  const save = () => a.run(async () => {
    const v = dirty || !pendingDraft ? await post<any>(`/policies/${profile.id}/versions`, { settings }) : latest;
    const list = (v.conflicts ?? []) as any[];
    if (!can('policies.publish')) { toast('Saved as a draft. Someone who can publish must make it live.'); onChanged(); return; }
    if (list.length) { setIssues({ draftId: v.id, list }); onChanged(); return; }
    await publish(v.id, false);
  });
  const blocking = issues?.list.some((c) => c.severity === 'error');
  const flagged = new Set((issues?.list ?? []).flatMap((c) => c.keys as string[]));

  return (
    <section className="pol-editor">
      <header className="pol-head">
        <div>
          <h2>{profile.name} {profile.recommended && <span className="badge info">Recommended</span>}</h2>
          <p className="muted">{profile.description || 'Only the settings marked “Changed” differ from the built-in settings.'}</p>
          <div className="row">
            {live ? <Badge value="published" label={`Live · version ${live.version}`} /> : <Badge value="draft" label="Not live yet" />}
            {pendingDraft && !dirty && <Badge value="draft" label={`Unsaved draft v${latest.version}`} />}
          </div>
        </div>
        <input className="pol-search" type="search" placeholder="Find a setting…" value={search} onChange={(e) => setSearch(e.target.value)} aria-label="Find a setting" />
      </header>

      <ErrorBox error={a.error} />
      {issues && (
        <div className={`alert ${blocking ? 'bad' : 'warn'}`} role="alert">
          <strong>{blocking ? 'Fix these before the policy can go live:' : 'Please check before going live:'}</strong>
          <ul className="pol-issues">{issues.list.map((c) => <li key={c.code}>{c.message}</li>)}</ul>
          {!blocking && <button disabled={a.busy} onClick={() => a.run(() => publish(issues.draftId, true))}>Make it live anyway</button>}
        </div>
      )}

      <Applies profile={profile} onChanged={onChanged} />

      {GROUPS.filter((g) => !g.advanced || showDevice || q).map((g) => {
        const keys = g.keys.filter(match);
        const mainShown = g.main && match(g.main);
        if (!keys.length && !mainShown) return null;
        const off = g.main ? value(g.main) === false : false;
        return (
          <div key={g.id} className={`pol-group${off ? ' off' : ''}`}>
            <div className="pol-group-head">
              <span className="pol-icon"><Icon name={g.icon} size={18} /></span>
              <div><h3>{g.title}</h3><p>{g.blurb}</p></div>
              {g.main && <MainSwitch k={g.main} on={value(g.main) === true} changed={g.main in settings} disabled={!editable} onChange={(v) => set(g.main!, v)} onReset={() => reset(g.main!)} />}
            </div>
            {!off && keys.map((k) => byKey[k] && (
              <Row key={k} k={k} c={byKey[k]} value={value(k)} changed={k in settings} flagged={flagged.has(k)} disabled={!editable} onChange={(v) => set(k, v)} onReset={() => reset(k)} labelOf={(x) => T[x]?.title ?? x} />
            ))}
            {off && <p className="pol-off-note">Off for phones using this policy.</p>}
          </div>
        );
      })}
      {!q && <button className="ghost pol-more" onClick={() => setShowDevice((x) => !x)}>{showDevice ? 'Hide' : 'Show'} phone restrictions (Android Management only)</button>}

      <div className="pol-history">
        <button className="ghost" onClick={() => setHistory((x) => !x)}>{history ? '▾' : '▸'} Version history ({profile.versions.length})</button>
        {history && (
          <>
            <table><thead><tr><th>Version</th><th>Status</th><th>Note</th><th>Live since</th></tr></thead>
              <tbody>{profile.versions.map((v) => <tr key={v.id}><td>v{v.version}</td><td><Badge value={v.status} label={v.status === 'published' ? 'live' : v.status} /></td><td>{v.notes ?? '—'}</td><td>{fmtDate(v.published_at)}</td></tr>)}</tbody></table>
            {can('policies.publish') && profile.versions.some((v) => v.status === 'superseded') && (
              <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await post(`/policies/${profile.id}/rollback`); toast('Previous version is live again.'); onChanged(); })}>Go back to the previous version</button>
            )}
          </>
        )}
      </div>

      {editable && (dirty || pendingDraft) && (
        <div className="pol-savebar" role="region" aria-label="Unsaved changes">
          <span>{dirty ? 'You have unsaved changes.' : `Draft v${latest.version} is not live yet.`}</span>
          <div className="row">
            {dirty && <button className="secondary" onClick={() => { setSettings({ ...base }); setIssues(null); }}>Discard</button>}
            <button disabled={a.busy} onClick={save}>{a.busy ? 'Saving…' : can('policies.publish') ? 'Save & make live' : 'Save draft'}</button>
          </div>
        </div>
      )}
    </section>
  );
}

function MainSwitch({ k, on, changed, disabled, onChange, onReset }: { k: string; on: boolean; changed: boolean; disabled: boolean; onChange: (v: boolean) => void; onReset: () => void }) {
  return (
    <div className="pol-main">
      {changed && !disabled && <button className="ghost pol-reset" onClick={onReset} title="Use the built-in setting">Reset</button>}
      <label className="switch" aria-label={T[k]?.title}>
        <input type="checkbox" checked={on} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
        <span /><b className={on ? 'pol-on' : 'pol-offtxt'}>{on ? 'On' : 'Off'}</b>
      </label>
    </div>
  );
}

function Row({ k, c, value, changed, flagged, disabled, onChange, onReset, labelOf }: { k: string; c: Control; value: any; changed: boolean; flagged: boolean; disabled: boolean; onChange: (v: any) => void; onReset: () => void; labelOf: (k: string) => string }) {
  const t = T[k] ?? { title: c.label, help: '' };
  return (
    <div className={`pol-row${flagged ? ' flagged' : ''}`}>
      <div className="pol-text">
        <div className="pol-title">{t.title} {changed ? <span className="badge info">Changed</span> : <span className="pol-default">Built-in</span>}</div>
        <div className="pol-help">{t.help}{c.setup_note ? ` ${c.setup_note}` : ''}</div>
      </div>
      <div className="pol-input">
        <Input c={c} t={t} value={value} disabled={disabled} onChange={onChange} labelOf={labelOf} />
        {changed && !disabled && <button className="ghost pol-reset" onClick={onReset} title="Use the built-in setting">Reset</button>}
      </div>
    </div>
  );
}

function Input({ c, t, value, disabled, onChange, labelOf }: { c: Control; t: Text; value: any; disabled: boolean; onChange: (v: any) => void; labelOf: (k: string) => string }) {
  switch (c.type) {
    case 'bool':
      return <label className="switch"><input type="checkbox" checked={!!value} disabled={disabled} onChange={(e) => onChange(e.target.checked)} /><span /><b className={value ? 'pol-on' : 'pol-offtxt'}>{value ? 'On' : 'Off'}</b></label>;
    case 'enum':
      return c.enum!.length <= 3
        ? <div className="seg pol-seg" role="radiogroup" aria-label={t.title}>{c.enum!.map((o) => <button key={o} type="button" role="radio" aria-checked={value === o} className={value === o ? 'on' : ''} disabled={disabled} onClick={() => onChange(o)}>{t.options?.[o] ?? o.replace(/_/g, ' ')}</button>)}</div>
        : <select value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)}>{c.enum!.map((o) => <option key={o} value={o}>{t.options?.[o] ?? o.replace(/_/g, ' ')}</option>)}</select>;
    case 'int':
      return <span className="pol-num"><input type="number" min={c.min ?? undefined} max={c.max ?? undefined} value={value} disabled={disabled} onChange={(e) => onChange(Number(e.target.value))} />{t.unit && <small>{t.unit}</small>}</span>;
    case 'list':
      if (c.key === 'compliance.mandatory') {
        return <div className="pol-checks">{MANDATORY_CHOICES.map((k) => <label key={k}><input type="checkbox" disabled={disabled} checked={(value as string[]).includes(k)} onChange={(e) => onChange(e.target.checked ? [...value, k] : (value as string[]).filter((x) => x !== k))} /> {labelOf(k)}</label>)}</div>;
      }
      return <textarea rows={2} value={(value as string[]).join('\n')} disabled={disabled} onChange={(e) => onChange(e.target.value.split('\n').map((s) => s.trim()).filter(Boolean))} placeholder="One per line" />;
    case 'hours':
      return (
        <div className="pol-hours">
          <div className="pol-days">{DAYS.map((d, i) => { const n = i + 1; const on = value.days.includes(n); return <button key={d} type="button" className={on ? 'on' : ''} aria-pressed={on} disabled={disabled} onClick={() => onChange({ ...value, days: on ? value.days.filter((x: number) => x !== n) : [...value.days, n].sort() })}>{d}</button>; })}</div>
          <span className="row"><input type="time" value={value.start} disabled={disabled} onChange={(e) => onChange({ ...value, start: e.target.value })} /> to <input type="time" value={value.end} disabled={disabled} onChange={(e) => onChange({ ...value, end: e.target.value })} /></span>
        </div>
      );
    default:
      return <textarea rows={2} value={value ?? ''} disabled={disabled} onChange={(e) => onChange(e.target.value)} />;
  }
}

/** Where the policy applies: chips with remove, plus "apply to" (all phones / a team / one phone). */
function Applies({ profile, onChanged }: { profile: Profile; onChanged: () => void }) {
  const { can } = useAuth();
  const teams = useAsync(() => get<any[]>('/teams'));
  const devices = useAsync(() => get<any>('/devices?state=approved'));
  const [target, setTarget] = useState({ type: 'company', id: '' });
  const list = profile.assignments;
  const a = useAction();
  const live = !!published(profile);
  const apply = (type: string, id: number | null, profileId: number | null, msg: string) => a.run(async () => {
    await put('/policy-assignments', { profile_id: profileId, target_type: type, target_id: id });
    toast(msg);
    onChanged();
  });
  return (
    <div className="pol-applies">
      <div className="pol-applies-head"><h3>Applies to</h3><span className="muted">A team or phone setting wins over “All phones”.</span></div>
      <ErrorBox error={a.error} />
      <div className="cc-chips">
        {list.length === 0 && <span className="muted">Not applied to any phone yet.</span>}
        {list.map((x) => (
          <span key={`${x.target_type}${x.target_id}`} className="cc-chip">{x.label}
            {can('policies.publish') && <button aria-label={`Stop applying to ${x.label}`} title="Stop applying here" disabled={a.busy} onClick={() => apply(x.target_type, x.target_type === 'company' ? null : x.target_id, null, `No longer applied to ${x.label}`)}>×</button>}
          </span>
        ))}
      </div>
      {can('policies.publish') && (
        live ? (
          <div className="row pol-apply">
            <select value={target.type} onChange={(e) => setTarget({ type: e.target.value, id: '' })} aria-label="Apply to">
              <option value="company">All phones</option><option value="team">A team</option><option value="device">One phone</option>
            </select>
            {target.type === 'team' && <select value={target.id} onChange={(e) => setTarget({ ...target, id: e.target.value })} aria-label="Team"><option value="">Choose team…</option>{(teams.data ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}
            {target.type === 'device' && <select value={target.id} onChange={(e) => setTarget({ ...target, id: e.target.value })} aria-label="Phone"><option value="">Choose phone…</option>{(devices.data?.data ?? []).map((d: any) => <option key={d.id} value={d.id}>#{d.id} · {d.assigned_user?.name ?? 'unassigned'} · {d.manufacturer} {d.model}</option>)}</select>}
            <button className="secondary" disabled={a.busy || (target.type !== 'company' && !target.id)} onClick={() => apply(target.type, target.id ? Number(target.id) : null, profile.id, `“${profile.name}” now applies to ${target.type === 'company' ? 'all phones' : target.type === 'team' ? 'the team' : 'the phone'}`)}>Apply</button>
          </div>
        ) : <p className="muted small">Save the policy to make it live, then you can apply it to phones.</p>
      )}
    </div>
  );
}
