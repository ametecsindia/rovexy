import { useEffect, useState } from 'react';
import { get, post, fmtDate, qs } from '../lib/api';
import { useAuth } from '../lib/auth';
import { realtime } from '../lib/realtime';
import { Badge, Card, ErrorBox, Field, Modal, Notice, Page, Table, useAction, useAsync } from '../components/ui';

export function DevicesPage() {
  const { me, can } = useAuth();
  const [state, setState] = useState('');
  const devices = useAsync(() => get(`/devices${qs({ state })}`), [state]);
  const [approve, setApprove] = useState<any | null>(null);
  const [detail, setDetail] = useState<any | null>(null);

  useEffect(() => {
    const echo = me && realtime(me);
    if (!echo) return;
    const ch = echo.private(`company.${me.company.id}.admin`);
    ch.listen('.feed', (e: any) => { if (String(e.type).startsWith('device.')) void devices.reload(); });
    return () => echo.leave(`company.${me.company.id}.admin`);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [me]);
  // New requests and approvals also show up without live updates (WebSockets off / blocked): quiet refresh every 10 s.
  useEffect(() => {
    const t = setInterval(() => { if (!document.hidden) void devices.reload(); }, 10_000);
    return () => clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state]);

  return (
    <Page title="Mobile devices & approval requests" subtitle="Match the code shown on the phone before approving. The code identifies the request; it is not a password."
      actions={<><select value={state} onChange={(e) => setState(e.target.value)} aria-label="Filter by state"><option value="">All states</option>{['pending', 'approved', 'rejected', 'revoked'].map((s) => <option key={s}>{s}</option>)}</select>
        <button className="secondary" onClick={devices.reload}>Refresh</button></>}>
      <ErrorBox error={devices.error} />
      <Card>
        <Table rows={devices.data?.data} onRow={setDetail} columns={[
          { key: 'id', label: '#' },
          { key: 'approval_state', label: 'Approval', render: (d: any) => <Badge value={d.approval_state} /> },
          { key: 'approval_code', label: 'Code', render: (d: any) => d.approval_state === 'pending' ? <span className="approval-code">{d.approval_code}</span> : '—' },
          { key: 'model', label: 'Device', render: (d: any) => `${d.manufacturer ?? ''} ${d.model ?? ''} · Android ${d.os_version ?? '?'}` },
          { key: 'asset_tag', label: 'Asset tag' },
          { key: 'user', label: 'Employee', render: (d: any) => d.assigned_user?.name ?? '—' },
          { key: 'management_mode', label: 'Management', render: (d: any) => <Badge value={d.management_mode === 'amapi' ? 'supported' : 'requires_setup'} label={d.management_mode === 'amapi' ? 'Android Mgmt' : 'App only'} /> },
          { key: 'policy_state', label: 'Policy', render: (d: any) => <><Badge value={d.policy_state} /> {d.approval_state === 'approved' && <Badge value={d.compliant} label={d.compliant ? 'compliant' : 'not compliant'} />}</> },
          { key: 'presence', label: 'Presence', render: (d: any) => <Badge value={d.presence} /> },
          { key: 'last_contact_at', label: 'Last contact', render: (d: any) => fmtDate(d.last_contact_at) },
          { key: 'a', label: '', render: (d: any) => d.approval_state === 'pending' && can('devices.approve') && <button onClick={(e) => { e.stopPropagation(); setApprove(d); }}>Review</button> },
        ]} />
      </Card>
      {approve && <ApproveModal device={approve} onClose={() => setApprove(null)} onDone={() => { setApprove(null); void devices.reload(); }} />}
      {detail && <DeviceDetail id={detail.id} onClose={() => setDetail(null)} onChanged={devices.reload} />}
    </Page>
  );
}

function ApproveModal({ device, onClose, onDone }: { device: any; onClose: () => void; onDone: () => void }) {
  const emps = useAsync(() => get('/employees?role=employee&per_page=1000'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const profiles = useAsync(() => get<any[]>('/policies'));
  const [f, setF] = useState({ code: '', user_id: '', team_id: '', asset_tag: '', profile_id: '' });
  const [reason, setReason] = useState('');
  const a = useAction();
  const employees = (emps.data?.data ?? []).filter((e: any) => e.status === 'active' && e.roles.some((r: any) => r.key === 'employee'));
  return (
    <Modal title={`Device request #${device.id}`} onClose={onClose}>
      <p>{device.manufacturer} {device.model} · Android {device.os_version} · app {device.app_version}</p>
      <p>Code on phone: <span className="approval-code">{device.approval_code}</span></p>
      <Notice tone="info">Ask the employee to read the code on their screen and type it below. This confirms you are approving the phone in front of them.</Notice>
      <ErrorBox error={a.error} />
      <div className="form-grid">
        <Field label="Code read from the phone"><input value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.toUpperCase() })} placeholder="ABCD-2345" /></Field>
        <Field label="Assign employee"><select value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}><option value="">Select…</option>{employees.map((e: any) => <option key={e.id} value={e.id}>{e.name} ({e.employee_code})</option>)}</select></Field>
        <Field label="Team"><select value={f.team_id} onChange={(e) => setF({ ...f, team_id: e.target.value })}><option value="">—</option>{(teams.data ?? []).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Field>
        <Field label="Company asset tag"><input value={f.asset_tag} onChange={(e) => setF({ ...f, asset_tag: e.target.value })} /></Field>
        <Field label="Device policy override (optional)"><select value={f.profile_id} onChange={(e) => setF({ ...f, profile_id: e.target.value })}><option value="">Company/team policy</option>{(profiles.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
      </div>
      <div className="row">
        <button disabled={a.busy || !f.code || !f.user_id} onClick={() => a.run(async () => {
          await post(`/devices/${device.id}/approve`, { code: f.code, user_id: Number(f.user_id), team_id: f.team_id ? Number(f.team_id) : null, asset_tag: f.asset_tag || null, profile_id: f.profile_id ? Number(f.profile_id) : null });
          onDone();
        })}>Approve</button>
        <input style={{ maxWidth: 260 }} placeholder="Reason for rejection" value={reason} onChange={(e) => setReason(e.target.value)} />
        <button className="danger" disabled={a.busy || reason.length < 3} onClick={() => a.run(async () => { await post(`/devices/${device.id}/reject`, { reason }); onDone(); })}>Reject</button>
      </div>
    </Modal>
  );
}

function DeviceDetail({ id, onClose, onChanged }: { id: number; onClose: () => void; onChanged: () => void }) {
  const { can } = useAuth();
  const d = useAsync(() => get(`/devices/${id}`), [id]);
  const eff = useAsync(() => (can('policies.view') ? get(`/devices/${id}/effective-policy`) : Promise.resolve(null)), [id]);
  const [reason, setReason] = useState('');
  const a = useAction();
  const dev = d.data?.device;
  return (
    <Modal title={`Device #${id}`} onClose={onClose}>
      <ErrorBox error={d.error ?? a.error} />
      {dev && (
        <>
          <p><Badge value={dev.approval_state} /> <Badge value={dev.policy_state} /> <Badge value={dev.compliant} label={dev.compliant ? 'compliant' : 'not compliant'} /></p>
          <p className="muted">Installation {dev.installation_id} · epoch {dev.credential_epoch} · {dev.management_mode} · last contact {fmtDate(dev.last_contact_at)}</p>
          {dev.compliance_detail?.failing_mandatory?.length > 0 && <Notice tone="bad">Mandatory controls not verified: {dev.compliance_detail.failing_mandatory.join(', ')}</Notice>}
          {dev.approval_state === 'approved' && can('devices.revoke') && (
            <Card title="Revoke access">
              <p className="muted">Revocation blocks all work access immediately. It does not wipe the phone and does not remove management restrictions.</p>
              <div className="row"><input placeholder="Reason (e.g. lost device)" value={reason} onChange={(e) => setReason(e.target.value)} style={{ maxWidth: 300 }} />
                <button className="danger" disabled={a.busy || reason.length < 3} onClick={() => a.run(async () => { await post(`/devices/${id}/revoke`, { reason }); await d.reload(); onChanged(); })}>Revoke</button></div>
            </Card>
          )}
          {eff.data && (
            <Card title="Effective policy & capability">
              <Table rows={Object.entries(eff.data.values).map(([k, v]) => ({ id: k, key: k, v, src: eff.data.sources[k], cap: eff.data.capabilities[k] }))} columns={[
                { key: 'key', label: 'Control' }, { key: 'v', label: 'Value', render: (r: any) => <span className="code">{JSON.stringify(r.v)}</span> },
                { key: 'src', label: 'Source' }, { key: 'cap', label: 'Capability', render: (r: any) => <span title={r.cap?.reason ?? ''}>{r.cap?.status === 'requires_setup' && dev.management_mode !== 'amapi'
                  ? <Badge value="not_required" label="n/a · app-only phone" /> : <Badge value={r.cap?.status} />} <small className="muted">{r.cap?.scope}</small></span> },
              ]} />
            </Card>
          )}
          <Card title="History">
            <Table rows={d.data.history} columns={[{ key: 'created_at', label: 'When', render: (h: any) => fmtDate(h.created_at) }, { key: 'action', label: 'Action' }, { key: 'reason', label: 'Reason' }]} />
          </Card>
        </>
      )}
    </Modal>
  );
}
