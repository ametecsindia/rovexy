import { useMemo, useState } from 'react';
import { get, post, put, fmtDate } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Notice, Page, Table, useAction, useAsync } from '../components/ui';

type Control = { key: string; type: string; default: any; scope: 'device' | 'app'; label: string; enum?: string[] | null; min?: number | null; max?: number | null; min_sdk: number; setup_note?: string | null };

export function PoliciesPage() {
  const { can } = useAuth();
  const catalog = useAsync(() => get<Control[]>('/policy/catalog'));
  const profiles = useAsync(() => get<any[]>('/policies'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const [selected, setSelected] = useState<number | null>(null);
  const [name, setName] = useState('');
  const a = useAction();
  const profile = profiles.data?.find((p) => p.id === selected);
  return (
    <Page title="Policy profiles" subtitle="Precedence: company default → team override → device override. Device-level controls need Android Management enrolment.">
      <ErrorBox error={catalog.error ?? profiles.error ?? a.error} />
      <div className="grid2">
        <Card title="Profiles" actions={can('policies.edit') && <><input placeholder="New profile name" value={name} onChange={(e) => setName(e.target.value)} style={{ width: 180 }} />
          <button disabled={a.busy || !name} onClick={() => a.run(async () => { await post('/policies', { name }); setName(''); await profiles.reload(); })}>Create</button></>}>
          <Table rows={profiles.data} onRow={(p: any) => setSelected(p.id)} columns={[
            { key: 'name', label: 'Profile' },
            { key: 'v', label: 'Published', render: (p: any) => { const v = p.versions.find((x: any) => x.status === 'published'); return v ? `v${v.version}` : <Badge value="draft" label="none" />; } },
            { key: 'assignments', label: 'Assigned to', render: (p: any) => p.assignments.map((x: any) => x.target_type === 'company' ? 'Company' : `${x.target_type} #${x.target_id}`).join(', ') || '—' },
          ]} />
        </Card>
        {profile && catalog.data && <ProfileEditor key={profile.id} profile={profile} catalog={catalog.data} teams={teams.data ?? []} onChanged={profiles.reload} />}
      </div>
    </Page>
  );
}

function ProfileEditor({ profile, catalog, teams, onChanged }: { profile: any; catalog: Control[]; teams: any[]; onChanged: () => void }) {
  const { can } = useAuth();
  const latest = profile.versions[0];
  const [settings, setSettings] = useState<Record<string, any>>(() => ({ ...(latest?.settings ?? {}) }));
  const [addKey, setAddKey] = useState('');
  const [target, setTarget] = useState({ type: 'company', id: '' });
  const a = useAction();
  const byKey = useMemo(() => Object.fromEntries(catalog.map((c) => [c.key, c])), [catalog]);
  const draft = profile.versions.find((v: any) => v.status === 'draft');
  return (
    <Card title={`${profile.name}`}>
      <ErrorBox error={a.error} />
      <h3>Overrides in this profile</h3>
      <Table rows={Object.keys(settings).map((k) => ({ id: k, k }))} empty="No overrides — catalogue defaults apply." columns={[
        { key: 'k', label: 'Control', render: (r: any) => <><div>{byKey[r.k]?.label ?? r.k}</div><small className="muted">{r.k} · {byKey[r.k]?.scope === 'device' ? 'device-level (Android Management)' : 'app-level'}</small></> },
        { key: 'v', label: 'Value', render: (r: any) => <ValueInput control={byKey[r.k]} value={settings[r.k]} onChange={(v) => setSettings({ ...settings, [r.k]: v })} /> },
        { key: 'x', label: '', render: (r: any) => <button className="ghost" onClick={() => { const s = { ...settings }; delete s[r.k]; setSettings(s); }}>Remove</button> },
      ]} />
      {can('policies.edit') && (
        <div className="row" style={{ margin: '8px 0 16px' }}>
          <select value={addKey} onChange={(e) => setAddKey(e.target.value)} style={{ maxWidth: 320 }} aria-label="Add control">
            <option value="">Add a control…</option>
            {catalog.filter((c) => !(c.key in settings)).map((c) => <option key={c.key} value={c.key}>{c.label} ({c.scope})</option>)}
          </select>
          <button className="secondary" disabled={!addKey} onClick={() => { setSettings({ ...settings, [addKey]: byKey[addKey].default }); setAddKey(''); }}>Add</button>
          <button disabled={a.busy} onClick={() => a.run(async () => { await post(`/policies/${profile.id}/versions`, { settings }); onChanged(); })}>Save as draft</button>
        </div>
      )}
      {draft && (
        <Card title={`Draft v${draft.version}`}>
          {(draft.conflicts ?? []).length === 0 ? <Notice tone="ok">No conflicts detected.</Notice> : (draft.conflicts as any[]).map((c) => (
            <Notice key={c.code} tone={c.severity === 'error' ? 'bad' : 'warn'}><strong>{c.severity === 'error' ? 'Blocking' : 'Warning'}:</strong> {c.message} {c.devices ? `(${c.devices} device(s))` : ''}</Notice>
          ))}
          {can('policies.publish') && (
            <div className="row">
              <button disabled={a.busy} onClick={() => a.run(async () => { await post(`/policies/${profile.id}/versions/${draft.id}/publish`, { accept_warnings: false }); onChanged(); })}>Publish</button>
              {(draft.conflicts ?? []).some((c: any) => c.severity === 'warning') && !(draft.conflicts ?? []).some((c: any) => c.severity === 'error') &&
                <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await post(`/policies/${profile.id}/versions/${draft.id}/publish`, { accept_warnings: true }); onChanged(); })}>Publish, accepting warnings</button>}
            </div>
          )}
        </Card>
      )}
      <h3>Versions</h3>
      <Table rows={profile.versions} columns={[{ key: 'version', label: 'v' }, { key: 'status', label: 'Status', render: (v: any) => <Badge value={v.status} /> },
        { key: 'notes', label: 'Notes' }, { key: 'published_at', label: 'Published', render: (v: any) => fmtDate(v.published_at) }]} />
      {can('policies.publish') && (
        <div className="row" style={{ marginTop: 12 }}>
          <button className="secondary" disabled={a.busy} onClick={() => a.run(async () => { await post(`/policies/${profile.id}/rollback`); onChanged(); })}>Roll back to previous version</button>
          <select value={target.type} onChange={(e) => setTarget({ type: e.target.value, id: '' })} aria-label="Assignment target"><option value="company">Company default</option><option value="team">Team</option><option value="device">Device</option></select>
          {target.type === 'team' && <select value={target.id} onChange={(e) => setTarget({ ...target, id: e.target.value })} aria-label="Team"><option value="">Team…</option>{teams.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}
          {target.type === 'device' && <input placeholder="Device #" value={target.id} onChange={(e) => setTarget({ ...target, id: e.target.value })} style={{ width: 100 }} />}
          <button disabled={a.busy || (target.type !== 'company' && !target.id)} onClick={() => a.run(async () => { await put('/policy-assignments', { profile_id: profile.id, target_type: target.type, target_id: target.id ? Number(target.id) : null }); onChanged(); })}>Assign</button>
        </div>
      )}
    </Card>
  );
}

function ValueInput({ control, value, onChange }: { control?: Control; value: any; onChange: (v: any) => void }) {
  if (!control) return <span className="code">{JSON.stringify(value)}</span>;
  switch (control.type) {
    case 'bool':
      return <select value={String(value)} onChange={(e) => onChange(e.target.value === 'true')}><option value="true">Yes / enabled</option><option value="false">No / disabled</option></select>;
    case 'enum':
      return <select value={value} onChange={(e) => onChange(e.target.value)}>{control.enum!.map((o) => <option key={o} value={o}>{o.replace(/_/g, ' ')}</option>)}</select>;
    case 'int':
      return <input type="number" min={control.min ?? undefined} max={control.max ?? undefined} value={value} onChange={(e) => onChange(Number(e.target.value))} />;
    case 'list':
      return <textarea rows={2} value={(value as string[]).join('\n')} onChange={(e) => onChange(e.target.value.split('\n').map((s) => s.trim()).filter(Boolean))} placeholder="One per line" />;
    case 'hours':
      return (
        <div className="row">
          <input type="time" value={value.start} onChange={(e) => onChange({ ...value, start: e.target.value })} style={{ width: 110 }} />–
          <input type="time" value={value.end} onChange={(e) => onChange({ ...value, end: e.target.value })} style={{ width: 110 }} />
          <input value={value.days.join(',')} onChange={(e) => onChange({ ...value, days: e.target.value.split(',').map(Number).filter((n) => n >= 1 && n <= 7) })} style={{ width: 120 }} title="ISO days 1=Mon … 7=Sun" />
        </div>
      );
    default:
      return <Field label=""><input value={value} onChange={(e) => onChange(e.target.value)} /></Field>;
  }
}
