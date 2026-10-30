import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, del, download as downloadFile, get, patch, post, put, fmtDate } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Badge, Card, ErrorBox, Field, Modal, Notice, Page, Table, useAction, useAsync } from '../components/ui';

/** Built-in roles in plain words. Manager and supervisor are the same thing: the person who runs a team. */
const ROLE_INFO: Record<string, { label: string; hint: string }> = {
  company_admin: { label: 'Admin', hint: 'Full access to everything in all branches and teams.' },
  supervisor: { label: 'Manager / Supervisor', hint: 'Runs one or more teams; sees only those teams’ people, customers, calls and reports.' },
  employee: { label: 'Telecaller / Employee', hint: 'Uses the phone app only: calls assigned customers.' },
  telecaller: { label: 'Telecaller (console)', hint: 'Sits at a desk: signs in to the console (needs an e-mail) and calls own customers from My calls (Click2Call). Add it next to Employee for someone who also uses the app.' },
  it_admin: { label: 'IT / Device admin', hint: 'Phones, policies, branding and system health; no customer data.' },
  auditor: { label: 'Auditor (read-only)', hint: 'Sees everything, changes nothing.' },
};
const AREAS: [string, string][] = [['employees', 'Branches, teams & people'], ['customers', 'Customers & leads'], ['calls', 'Calls'], ['payments', 'Payments'], ['recordings', 'Recordings'],
  ['reports', 'Reports'], ['location', 'Location'], ['chat', 'Messages'], ['media', 'Live screen / camera'], ['devices', 'Phones'], ['policies', 'Policies'],
  ['telephony', 'Calling setup'], ['branding', 'Branding'], ['settings', 'Storage & retention'], ['audit', 'Audit'], ['system', 'System'], ['scope', 'Data scope'], ['mobile', 'Phone app']];
const roleLabel = (r: any) => ROLE_INFO[r.key]?.label ?? r.name;
const CONSOLE_FREE = ['mobile.work', 'calls.make', 'chat.use'];

export function EmployeesPage() {
  const { can } = useAuth();
  const manage = can('employees.manage');
  const [tab, setTab] = useState<'branches' | 'people' | 'groups' | 'roles'>('branches');
  const [f, setF] = useState({ q: '', role: '', team: '', branch: '' });
  const emps = useAsync(() => get('/employees'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const branches = useAsync(() => get<any[]>('/branches'));
  const [branch, setBranch] = useState<any | null>(null);
  const roles = useAsync(() => get('/roles'));
  const [person, setPerson] = useState<any | null>(null);
  const [group, setGroup] = useState<any | null>(null);
  const [role, setRole] = useState<any | null>(null);
  const [bulk, setBulk] = useState(false);
  const a = useAction();
  const people: any[] = emps.data?.data ?? [];
  const reload = () => { void emps.reload(); void teams.reload(); void roles.reload(); void branches.reload(); };
  const branchOf = (teamId: number) => (branches.data ?? []).find((b: any) => b.id === (teams.data ?? []).find((t: any) => t.id === teamId)?.branch_id);
  // Reports to: the managers of the groups a person belongs to as a member; managers report to the admins.
  const managersOf = (teamId: number) => (teams.data ?? []).find((t: any) => t.id === teamId)?.managers ?? [];
  const reportsTo = (u: any) => {
    const m = u.teams.filter((t: any) => t.pivot?.membership !== 'supervisor').flatMap((t: any) => managersOf(t.id)).filter((x: any) => x.id !== u.id);
    if (m.length) return [...new Map(m.map((x: any) => [x.id, x.name])).values()].join(', ');
    return u.roles.some((r: any) => r.key === 'company_admin') ? '—' : people.filter((p: any) => p.roles.some((r: any) => r.key === 'company_admin')).map((p: any) => p.name).join(', ') || '—';
  };
  const shown = people.filter((u: any) => (!f.q || `${u.name} ${u.employee_code} ${u.email ?? ''}`.toLowerCase().includes(f.q.toLowerCase()))
    && (!f.role || u.roles.some((r: any) => r.key === f.role)) && (!f.team || u.teams.some((t: any) => String(t.id) === f.team))
    && (!f.branch || u.teams.some((t: any) => String(branchOf(t.id)?.id ?? '') === f.branch)));
  return (
    <Page title="Branches, teams & people" subtitle="Branch → team → people. Each team has a manager; managers see only the teams they run, admins see everything."
      actions={manage && <><button onClick={() => setBranch({})}>Add branch</button><button className="secondary" onClick={() => setGroup({})}>Add team</button><button className="secondary" onClick={() => setPerson({})}>Add person</button><button className="secondary" onClick={() => setBulk(true)}>Bulk upload</button></>}>
      <ErrorBox error={emps.error ?? teams.error ?? roles.error ?? branches.error ?? a.error} />
      <div className="tabs" role="tablist">
        {([['branches', `Branches (${branches.data?.length ?? 0})`], ['groups', `Teams (${teams.data?.length ?? 0})`], ['people', `All people (${people.length})`], ['roles', 'Roles & permissions']] as const).map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'tab on' : 'tab'} onClick={() => setTab(k)}>{l}</button>
        ))}
      </div>

      {tab === 'people' && (
        <Card>
          <div className="filters filter-bar">
            <input placeholder="Search name, User ID, e-mail" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} style={{ maxWidth: 260 }} />
            <select value={f.role} onChange={(e) => setF({ ...f, role: e.target.value })} aria-label="Role"><option value="">All roles</option>{(roles.data?.roles ?? []).map((r: any) => <option key={r.key} value={r.key}>{roleLabel(r)}</option>)}</select>
            {(branches.data?.length ?? 0) > 0 && <select value={f.branch} onChange={(e) => setF({ ...f, branch: e.target.value, team: '' })} aria-label="Branch"><option value="">All branches</option>{(branches.data ?? []).map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}</select>}
            <select value={f.team} onChange={(e) => setF({ ...f, team: e.target.value })} aria-label="Team"><option value="">All teams</option>{(teams.data ?? []).filter((t: any) => !f.branch || String(t.branch_id) === f.branch).map((t: any) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
          </div>
          <Table rows={shown} empty="Nobody matches." onRow={manage ? (u: any) => setPerson(u) : undefined} columns={[
            { key: 'employee_code', label: 'User ID' }, { key: 'name', label: 'Name' },
            { key: 'roles', label: 'Role', render: (u: any) => u.roles.map(roleLabel).join(', ') },
            { key: 'branch', label: 'Branch', render: (u: any) => [...new Set(u.teams.map((t: any) => branchOf(t.id)?.name).filter(Boolean))].join(', ') || <span className="muted">—</span> },
            { key: 'teams', label: 'Team', render: (u: any) => u.teams.length ? u.teams.map((t: any) => <span key={t.id} className="tag">{t.name}{t.pivot?.membership === 'supervisor' ? ' · manager' : ''}</span>) : <span className="muted">—</span> },
            { key: 'reports', label: 'Reports to', render: (u: any) => reportsTo(u) },
            { key: 'contact', label: 'Login', render: (u: any) => <small>{u.email ?? <span className="muted">phone app only</span>}</small> },
            { key: 'status', label: 'Status', render: (u: any) => <Badge value={u.status === 'active' ? 'active' : 'revoked'} label={u.status} /> },
            { key: 'last_login_at', label: 'Last sign-in', render: (u: any) => fmtDate(u.last_login_at) },
            { key: 'actions', label: '', render: (u: any) => manage && (
              <button className="secondary" disabled={a.busy} onClick={(e) => { e.stopPropagation(); void a.run(async () => { await patch(`/employees/${u.id}`, { status: u.status === 'active' ? 'suspended' : 'active' }); await emps.reload(); }); }}>
                {u.status === 'active' ? 'Suspend' : 'Reactivate'}
              </button>) },
          ]} />
        </Card>
      )}

      {tab === 'branches' && <BranchBoard branches={branches.data ?? []} teams={teams.data ?? []} people={people} manage={manage} onAddBranch={() => setBranch({})} onAddTeam={(b) => setGroup({ branch_id: b.id })} onTeams={() => setTab('groups')} onChanged={reload} />}
      {tab === 'groups' && <GroupBoard teams={teams.data ?? []} branches={branches.data ?? []} people={people} manage={manage} onAddGroup={() => setGroup({})} onPerson={manage ? setPerson : undefined} onChanged={reload} />}

      {tab === 'roles' && (
        <Card actions={manage && <button className="secondary" onClick={() => setRole({ name: '', permissions: [] })}>New custom role</button>}>
          <p className="muted" style={{ marginTop: 0 }}>Built-in roles are fixed. For a different mix (e.g. “Report viewer”, “Team leader without recordings”) create a custom role and tick what it may access.</p>
          <Table rows={roles.data?.roles} onRow={(r: any) => setRole(r)} columns={[
            { key: 'name', label: 'Role', render: (r: any) => <><strong>{roleLabel(r)}</strong><br /><small className="muted">{ROLE_INFO[r.key]?.hint ?? 'Custom role'}</small></> },
            { key: 'system', label: 'Type', render: (r: any) => <Badge value={r.system ? 'info' : 'active'} label={r.system ? 'built-in' : 'custom'} /> },
            { key: 'users_count', label: 'People', align: 'right' },
            { key: 'p', label: 'Permissions', align: 'right', render: (r: any) => r.key === 'company_admin' ? 'all' : r.permissions.length },
          ]} />
        </Card>
      )}

      {person && <PersonModal person={person} roles={roles.data?.roles ?? []} teams={teams.data ?? []} branches={branches.data ?? []} people={people} onClose={() => setPerson(null)} onDone={reload} />}
      {group && <GroupModal group={group} branches={branches.data ?? []} people={people} onClose={() => setGroup(null)} onDone={() => { setGroup(null); reload(); }} />}
      {branch && <BranchModal onClose={() => setBranch(null)} onDone={() => { setBranch(null); reload(); }} />}
      {role && <RoleModal role={role} all={roles.data?.permissions ?? []} canEdit={manage} onClose={() => setRole(null)} onDone={() => { setRole(null); reload(); }} />}
      {bulk && <BulkUpload onClose={() => setBulk(false)} onDone={reload} />}
    </Page>
  );
}

/** Branches: each with its teams, managers and head count; rename / delete / add a team here. */
function BranchBoard({ branches, teams, people, manage, onAddBranch, onAddTeam, onTeams, onChanged }: { branches: any[]; teams: any[]; people: any[]; manage: boolean;
  onAddBranch: () => void; onAddTeam: (b: any) => void; onTeams: () => void; onChanged: () => void }) {
  const a = useAction();
  const headcount = (ts: any[]) => people.filter((p: any) => p.teams.some((t: any) => ts.some((x: any) => x.id === t.id))).length;
  const loose = teams.filter((t: any) => !t.branch_id);
  return (
    <>
      <ErrorBox error={a.error} />
      {branches.length === 0 && <Notice tone="info">No branches yet. Add a branch (e.g. Hyderabad, Vijayawada), then add its teams and people. Teams without a branch keep working.</Notice>}
      <div className="group-board">
        {branches.map((b: any) => {
          const ts = teams.filter((t: any) => t.branch_id === b.id);
          return (
            <div key={b.id} className="group-card">
              <div className="group-head">
                <div><h3>{b.name}</h3><small className="muted">{b.code} · {ts.length} teams · {headcount(ts)} people</small></div>
                {manage && <span className="member-actions" style={{ opacity: 1 }}>
                  <button className="ghost" onClick={() => { const n = prompt('New name for the branch', b.name); if (n && n.trim() && n !== b.name) void a.run(async () => { await patch(`/branches/${b.id}`, { name: n.trim() }); onChanged(); }); }}>Rename</button>
                  <button className="ghost" title="Delete branch (its teams stay, without a branch)" onClick={() => confirm(`Delete branch ${b.name}? Its teams and people stay; the teams just have no branch.`) && void a.run(async () => { await del(`/branches/${b.id}`); onChanged(); })}>✕</button>
                </span>}
              </div>
              <ul className="member-list">
                {ts.map((t: any) => <li key={t.id}><span className="linkish" onClick={onTeams}>{t.name}</span><small className="muted">{t.users_count} people · manager: {t.managers?.map((m: any) => m.name).join(', ') || '—'}</small></li>)}
                {ts.length === 0 && <li className="muted">No teams in this branch yet.</li>}
              </ul>
              {manage && <button className="secondary" onClick={() => onAddTeam(b)}>+ Add team to {b.name}</button>}
            </div>
          );
        })}
        {loose.length > 0 && (
          <div className="group-card muted-card">
            <div className="group-head"><div><h3>Teams without a branch</h3><small className="muted">{loose.length} teams</small></div></div>
            <ul className="member-list">
              {loose.map((t: any) => (
                <li key={t.id}><span>{t.name}</span>
                  {manage && branches.length > 0 && <select value="" aria-label={`Branch for ${t.name}`} style={{ width: 'auto' }} onChange={(e) => e.target.value && void a.run(async () => { await patch(`/teams/${t.id}`, { branch_id: Number(e.target.value) }); onChanged(); })}>
                    <option value="">Move to branch…</option>{branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
                  </select>}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
      {manage && branches.length > 0 && <p><button className="secondary" onClick={onAddBranch}>+ New branch</button></p>}
    </>
  );
}

function BranchModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const a = useAction();
  return (
    <Modal title="Add branch" onClose={onClose}>
      <ErrorBox error={a.error} />
      <div className="form-grid">
        <Field label="Branch name" hint="e.g. Hyderabad, Kondapur, Vijayawada"><input value={name} onChange={(e) => setName(e.target.value)} autoFocus /></Field>
        <Field label="Code (optional)" hint="Generated from the name if blank"><input value={code} onChange={(e) => setCode(e.target.value)} /></Field>
      </div>
      <button disabled={a.busy || !name.trim()} onClick={() => a.run(async () => { await post('/branches', { name: name.trim(), code: code.trim() || null }); onDone(); })}>Create branch</button>
    </Modal>
  );
}

/** Teams as cards, under their branch: name, manager(s) and every member, with add / remove / make-manager right on the card. */
function GroupBoard({ teams, branches, people, manage, onAddGroup, onPerson, onChanged }: { teams: any[]; branches: any[]; people: any[]; manage: boolean; onAddGroup: () => void; onPerson?: (p: any) => void; onChanged: () => void }) {
  const a = useAction();
  const [q, setQ] = useState('');
  const inGroup = (t: any) => people.filter((p: any) => p.teams.some((x: any) => x.id === t.id)).map((p: any) => ({ ...p, membership: p.teams.find((x: any) => x.id === t.id)?.pivot?.membership ?? 'member' }))
    .sort((x: any, y: any) => (x.membership === 'supervisor' ? 0 : 1) - (y.membership === 'supervisor' ? 0 : 1) || x.name.localeCompare(y.name));
  const save = (t: any, members: { id: number; membership: string }[]) => a.run(async () => {
    await put(`/teams/${t.id}/members`, { members: members.map((m) => ({ user_id: m.id, membership: m.membership })) });
    onChanged();
  });
  const noGroup = people.filter((p: any) => p.teams.length === 0 && p.status === 'active');
  const shown = teams.filter((t: any) => !q || t.name.toLowerCase().includes(q.toLowerCase()) || inGroup(t).some((m: any) => m.name.toLowerCase().includes(q.toLowerCase())));
  return (
    <>
      <ErrorBox error={a.error} />
      <div className="row" style={{ marginBottom: 12 }}>
        <input placeholder="Find a team or person" value={q} onChange={(e) => setQ(e.target.value)} style={{ maxWidth: 280 }} />
        {manage && <button className="secondary" onClick={onAddGroup}>+ New team</button>}
      </div>
      {teams.length === 0 && <Notice tone="info">No teams yet. Create one inside a branch and add its manager and telecallers.</Notice>}
      {[...branches, { id: null, name: branches.length ? 'No branch' : '' }].map((b: any) => {
        const inBranch = shown.filter((t: any) => (t.branch_id ?? null) === b.id);
        if (inBranch.length === 0) return null;
        return (
      <div key={b.id ?? 'none'}>
      {b.name && <div className="branch-head"><h2>{b.name}</h2><small className="muted">{inBranch.length} teams</small></div>}
      <div className="group-board">
        {inBranch.map((t: any) => {
          const members = inGroup(t);
          const managers = members.filter((m: any) => m.membership === 'supervisor');
          const staff = members.filter((m: any) => m.membership !== 'supervisor');
          const others = people.filter((p: any) => p.status === 'active' && !members.some((m: any) => m.id === p.id));
          return (
            <div key={t.id} className="group-card">
              <div className="group-head">
                <div><h3>{t.name}</h3><small className="muted">{t.code} · {staff.length} employees</small></div>
                {manage && branches.length > 0 && <select value={t.branch_id ?? ''} aria-label={`Branch of ${t.name}`} title="Branch" style={{ width: 'auto', maxWidth: 130 }}
                  onChange={(e) => void a.run(async () => { await patch(`/teams/${t.id}`, { branch_id: e.target.value ? Number(e.target.value) : null }); onChanged(); })}>
                  <option value="">No branch</option>{branches.map((x: any) => <option key={x.id} value={x.id}>{x.name}</option>)}
                </select>}
                {manage && <button className="ghost" title="Rename team" onClick={() => { const n = prompt('New name for the team', t.name); if (n && n.trim() && n !== t.name) void a.run(async () => { await patch(`/teams/${t.id}`, { name: n.trim() }); onChanged(); }); }}>Rename</button>}
              </div>
              <div className="group-mgr"><span className="muted">Manager:</span> {managers.length ? <strong>{managers.map((m: any) => m.name).join(', ')}</strong> : <Badge value="pending" label="no manager" />}</div>
              <ul className="member-list">
                {members.map((m: any) => (
                  <li key={m.id}>
                    <span className={onPerson ? 'linkish' : undefined} onClick={() => onPerson?.(m)}>{m.name}</span>
                    <small className="muted">{m.employee_code}</small>
                    {m.membership === 'supervisor' ? <span className="tag">manager</span> : m.status !== 'active' ? <Badge value="revoked" label={m.status} /> : null}
                    {manage && (
                      <span className="member-actions">
                        <button className="ghost" title={m.membership === 'supervisor' ? 'Make an employee of this team' : 'Make manager of this team'} disabled={a.busy}
                          onClick={() => save(t, members.map((x: any) => x.id === m.id ? { ...x, membership: m.membership === 'supervisor' ? 'member' : 'supervisor' } : x))}>{m.membership === 'supervisor' ? '↓ employee' : '↑ manager'}</button>
                        <button className="ghost" title="Remove from team" disabled={a.busy} onClick={() => confirm(`Remove ${m.name} from ${t.name}?`) && save(t, members.filter((x: any) => x.id !== m.id))}>✕</button>
                      </span>
                    )}
                  </li>
                ))}
                {members.length === 0 && <li className="muted">Nobody in this team yet.</li>}
              </ul>
              {manage && (
                <select value="" aria-label={`Add to ${t.name}`} onChange={(e) => { const id = Number(e.target.value); if (id) void save(t, [...members, { id, membership: 'member' }]); }}>
                  <option value="">+ Add employee to {t.name}…</option>
                  {others.map((p: any) => <option key={p.id} value={p.id}>{p.name} ({p.employee_code}){p.teams[0] ? ` — now in ${p.teams.map((x: any) => x.name).join(', ')}` : ''}</option>)}
                </select>
              )}
            </div>
          );
        })}
      </div>
      </div>
        );
      })}
      <div className="group-board">
        {noGroup.length > 0 && (
          <div className="group-card muted-card">
            <div className="group-head"><div><h3>Not in any team</h3><small className="muted">{noGroup.length} people</small></div></div>
            <ul className="member-list">{noGroup.map((p: any) => <li key={p.id}><span className={onPerson ? 'linkish' : undefined} onClick={() => onPerson?.(p)}>{p.name}</span><small className="muted">{p.employee_code} · {p.roles.map(roleLabel).join(', ')}</small></li>)}</ul>
          </div>
        )}
      </div>
    </>
  );
}

/** Add or edit a person. The role decides the rest: console roles need an e-mail; managers become the manager of the chosen group. */
function PersonModal({ person, roles, teams, branches, people, onClose, onDone }: { person: any; roles: any[]; teams: any[]; branches: any[]; people: any[]; onClose: () => void; onDone: () => void }) {
  const isNew = !person.id;
  const [f, setF] = useState(() => ({
    name: person.name ?? '', employee_code: person.employee_code ?? '', email: person.email ?? '', phone: person.phone ?? '', password: '',
    role: person.roles?.[0]?.key ?? 'employee', team: person.teams?.[0]?.id ? String(person.teams[0].id) : '', status: person.status ?? 'active',
  }));
  const [created, setCreated] = useState<any | null>(null);
  const [br, setBr] = useState(() => String(teams.find((t: any) => String(t.id) === f.team)?.branch_id ?? ''));
  const a = useAction();
  const r = roles.find((x: any) => x.key === f.role);
  const console = f.role === 'company_admin' || (r?.permissions ?? []).some((p: any) => !CONSOLE_FREE.includes(p.key));
  const isManager = f.role === 'supervisor';
  const suggest = () => {
    const prefix = f.role === 'employee' ? 'TC' : f.role === 'supervisor' ? 'MGR' : 'ADM';
    for (let i = 1; ; i++) { const c = `${prefix}${String(i).padStart(4, '0')}`; if (!people.some((p: any) => p.employee_code.toLowerCase() === c.toLowerCase())) return c; }
  };
  const teamsPayload = f.team ? [{ id: Number(f.team), membership: isManager ? 'supervisor' : 'member' }] : [];
  const save = () => a.run(async () => {
    if (isNew) {
      const res = await post('/employees', { employee_code: f.employee_code || suggest(), name: f.name, email: f.email || null, phone: f.phone || null, password: f.password || null, roles: [f.role], teams: teamsPayload });
      setCreated(res);
    } else {
      await patch(`/employees/${person.id}`, { name: f.name, email: f.email || null, phone: f.phone || null, roles: [f.role], teams: teamsPayload, status: f.status, ...(f.password ? { password: f.password } : {}) });
      onClose();
    }
    onDone();
  });
  if (created) return (
    <Modal title="Person added" onClose={onClose}>
      <Notice tone="ok">Share these login details. {created.password ? 'The password is shown only now.' : ''}</Notice>
      <table className="kv"><tbody>
        <tr><th>{console ? 'Admin console' : 'Phone app'} login</th><td className="code">{console ? created.email : created.employee_code}</td></tr>
        {console && <tr><th>Phone app User ID</th><td className="code">{created.employee_code}</td></tr>}
        <tr><th>Password</th><td className="code">{created.password ?? '(the one you typed)'}</td></tr>
      </tbody></table>
      <button onClick={onClose}>Done</button>
    </Modal>
  );
  return (
    <Modal title={isNew ? 'Add person' : `Edit ${person.name}`} onClose={onClose}>
      <ErrorBox error={a.error} />
      <Field label="Type of person">
        <div className="role-pick">
          {roles.map((x: any) => (
            <button key={x.key} type="button" className={f.role === x.key ? 'role-card on' : 'role-card'} onClick={() => setF({ ...f, role: x.key })}>
              <strong>{roleLabel(x)}</strong><small>{ROLE_INFO[x.key]?.hint ?? `Custom role · ${x.permissions.length} permissions`}</small>
            </button>
          ))}
        </div>
      </Field>
      <div className="form-grid">
        <Field label="Name"><input value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></Field>
        <Field label="User ID (typed on the phone)" hint={isNew ? 'Leave blank to generate (TC0001, MGR0001…)' : 'Cannot be changed'}>
          <input value={f.employee_code} disabled={!isNew} onChange={(e) => setF({ ...f, employee_code: e.target.value })} placeholder={isNew ? suggest() : ''} />
        </Field>
        <Field label={console ? 'E-mail (admin console login) *' : 'E-mail (optional)'}><input type="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} /></Field>
        <Field label="Phone (work number)" hint="Click2Call rings this number first."><input value={f.phone} onChange={(e) => setF({ ...f, phone: e.target.value })} /></Field>
        {!isNew && <small className="muted">Who may use Click2Call and Smart31: <Link to="/calling/people">Calling configuration → People &amp; access</Link>.</small>}
        {branches.length > 0 && <Field label="Branch">
          <select value={br} onChange={(e) => { setBr(e.target.value); setF({ ...f, team: '' }); }}>
            <option value="">All branches</option>{branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}
          </select>
        </Field>}
        <Field label={isManager ? 'Manager of team' : 'Team'} hint={isManager ? 'They will see only this team’s data.' : undefined}>
          <select value={f.team} onChange={(e) => setF({ ...f, team: e.target.value })}>
            <option value="">— no team —</option>{teams.filter((t: any) => !br || String(t.branch_id) === br).map((t: any) => <option key={t.id} value={t.id}>{t.name}{t.managers?.length ? ` (manager: ${t.managers.map((m: any) => m.name).join(', ')})` : ''}</option>)}
          </select>
        </Field>
        <Field label={isNew ? 'Password' : 'New password (optional)'} hint={isNew ? 'Leave blank to generate one' : 'At least 8 characters'}>
          <input type="password" autoComplete="new-password" value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
        </Field>
        {!isNew && <Field label="Status"><select value={f.status} onChange={(e) => setF({ ...f, status: e.target.value })}><option value="active">Active</option><option value="suspended">Suspended</option><option value="left">Left the company</option></select></Field>}
      </div>
      {!isNew && (person.teams?.length ?? 0) > 1 && <Notice tone="warn">This person is in several teams; saving keeps only the team chosen here.</Notice>}
      <button disabled={a.busy || !f.name || (console && !f.email)} onClick={save}>{isNew ? 'Add person' : 'Save'}</button>
    </Modal>
  );
}

function GroupModal({ group, branches, people, onClose, onDone }: { group: any; branches: any[]; people: any[]; onClose: () => void; onDone: () => void }) {
  const isNew = !group.id;
  const [name, setName] = useState('');
  const [br, setBr] = useState(group.branch_id ? String(group.branch_id) : '');
  const [code, setCode] = useState('');
  const [mgr, setMgr] = useState('');
  const [members, setMembers] = useState<Record<number, string>>(() =>
    Object.fromEntries(people.flatMap((e) => e.teams.filter((t: any) => t.id === group.id).map((t: any) => [e.id, t.pivot?.membership ?? 'member']))));
  const [q, setQ] = useState('');
  const a = useAction();
  const managers = people.filter((p: any) => p.status === 'active' && !p.roles.every((r: any) => r.key === 'employee'));
  const list = useMemo(() => people.filter((e) => !q || `${e.name} ${e.employee_code}`.toLowerCase().includes(q.toLowerCase()))
    .sort((x, y) => Number(!!members[y.id]) - Number(!!members[x.id])), [people, q, members]);
  return (
    <Modal title={isNew ? 'Add team' : `Team ${group.name}`} onClose={onClose}>
      <ErrorBox error={a.error} />
      {isNew ? (
        <>
          <div className="form-grid">
            {branches.length > 0 && <Field label="Branch"><select value={br} onChange={(e) => setBr(e.target.value)}><option value="">— no branch —</option>{branches.map((b: any) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>}
            <Field label="Team name" hint="e.g. Akshitha team, Field team 1"><input value={name} onChange={(e) => setName(e.target.value)} /></Field>
            <Field label="Code (optional)" hint="Generated from the name if blank"><input value={code} onChange={(e) => setCode(e.target.value)} /></Field>
            <Field label="Manager"><select value={mgr} onChange={(e) => setMgr(e.target.value)}><option value="">— choose later —</option>{managers.map((m: any) => <option key={m.id} value={m.id}>{m.name} ({m.employee_code})</option>)}</select></Field>
          </div>
          <button disabled={a.busy || !name.trim()} onClick={() => a.run(async () => { await post('/teams', { name: name.trim(), code: code.trim() || null, branch_id: br ? Number(br) : null, manager_ids: mgr ? [Number(mgr)] : [] }); onDone(); })}>Create team</button>
        </>
      ) : (
        <>
          <p className="muted">Manager = sees and manages this team’s people, customers, calls and payments. Member = telecaller in this team.</p>
          <input placeholder="Search people" value={q} onChange={(e) => setQ(e.target.value)} style={{ maxWidth: 260, marginBottom: 8 }} />
          <Table rows={list} columns={[{ key: 'name', label: 'Person', render: (e: any) => <>{e.name} <small className="muted">{e.employee_code} · {e.roles.map(roleLabel).join(', ')}</small></> },
            { key: 'm', label: 'In this team', render: (e: any) => (
              <select value={members[e.id] ?? ''} onChange={(ev) => setMembers((m) => { const n = { ...m }; if (ev.target.value) n[e.id] = ev.target.value; else delete n[e.id]; return n; })}>
                <option value="">Not in team</option><option value="member">Member (telecaller)</option><option value="supervisor">Manager</option>
              </select>) }]} />
          <button disabled={a.busy} onClick={() => a.run(async () => { await put(`/teams/${group.id}/members`, { members: Object.entries(members).map(([user_id, membership]) => ({ user_id: Number(user_id), membership })) }); onDone(); })}>Save members</button>
        </>
      )}
    </Modal>
  );
}

/** Permission ticks grouped by area. Built-in roles are read-only; "Copy as custom role" starts from them. */
function RoleModal({ role, all, canEdit, onClose, onDone }: { role: any; all: any[]; canEdit: boolean; onClose: () => void; onDone: () => void }) {
  const { me } = useAuth();
  const isNew = !role.id;
  const fixed = role.system || !canEdit;
  const [name, setName] = useState(role.system ? '' : role.name);
  const [perms, setPerms] = useState<string[]>(() => role.key === 'company_admin' ? all.map((p) => p.key) : (role.permissions ?? []).map((p: any) => p.key ?? p));
  const [copy, setCopy] = useState(false);
  const a = useAction();
  const editable = !fixed || copy;
  const mine = new Set([...(me?.permissions ?? []), 'mobile.work', 'calls.make']);
  const grouped = AREAS.map(([k, l]) => [l, all.filter((p) => p.key.split('.')[0] === k)] as const).filter(([, ps]) => ps.length);
  return (
    <Modal title={isNew ? 'New custom role' : copy ? `Copy of ${roleLabel(role)}` : roleLabel(role)} onClose={onClose}>
      <ErrorBox error={a.error} />
      {role.system && !copy && <Notice tone="info">Built-in role — fixed. {canEdit && <>Use <b>Copy as custom role</b> to make a variant.</>}</Notice>}
      {editable && <Field label="Role name"><input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Report viewer" /></Field>}
      <div className="perm-grid">
        {grouped.map(([label, ps]) => (
          <fieldset key={label} className="perm-area">
            <legend>{label}</legend>
            {ps.map((p) => (
              <label key={p.key} className="row" title={p.key}>
                <input type="checkbox" style={{ width: 'auto' }} disabled={!editable || !mine.has(p.key)} checked={perms.includes(p.key)}
                  onChange={(e) => setPerms((x) => e.target.checked ? [...x, p.key] : x.filter((k) => k !== p.key))} /> {p.description}
              </label>
            ))}
          </fieldset>
        ))}
      </div>
      <div className="row">
        {editable && <button disabled={a.busy || !name.trim() || perms.length === 0} onClick={() => a.run(async () => {
          const body = { name: name.trim(), permissions: perms };
          if (isNew || copy) await post('/roles', body); else await put(`/roles/${role.id}`, body);
          onDone();
        })}>Save role</button>}
        {role.system && canEdit && !copy && <button className="secondary" onClick={() => { setCopy(true); setName(`${roleLabel(role)} (custom)`); }}>Copy as custom role</button>}
        {!role.system && !isNew && canEdit && <button className="danger" disabled={a.busy} onClick={() => a.run(async () => { if (!confirm('Delete this role?')) return; await del(`/roles/${role.id}`); onDone(); })}>Delete role</button>}
      </div>
    </Modal>
  );
}

const csvCell = (v: unknown) => /[",\n]/.test(String(v ?? '')) ? `"${String(v).replace(/"/g, '""')}"` : String(v ?? '');
function download(name: string, text: string) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['\uFEFF' + text], { type: 'text/csv' }));
  a.download = name;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

/** SmartDCM-style bulk upload: groups + managers + telecallers from one sheet; logins are generated and shown once. */
function BulkUpload({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { me } = useAuth();
  const [domain, setDomain] = useState(() => (me?.email ?? '').split('@')[1] ?? '');
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<any | null>(null);
  const [rows, setRows] = useState<any[] | null>(null);
  const a = useAction();
  const upload = () => a.run(async () => {
    setRows(null);
    const fd = new FormData();
    fd.append('file', file!);
    fd.append('email_domain', domain.trim());
    try {
      setRes(await api('POST', '/employees/bulk', fd));
      onDone();
    } catch (e: any) {
      if (e?.body?.rows) setRows(e.body.rows);
      throw e;
    }
  });
  const creds = () => download(`logins-${new Date().toISOString().slice(0, 10)}.csv`, ['user_id,name,role,branch,team,manager,email,password',
    ...res.created.map((c: any) => [c.employee_code, c.name, c.role, c.branch, c.group, c.manager, c.email, c.password].map(csvCell).join(','))].join('\n') + '\n');
  return (
    <Modal title="Bulk upload — branches, teams, managers, telecallers" onClose={onClose}>
      <ErrorBox error={a.error} />
      {!res ? (
        <>
          <Notice tone="info">One row per person: <b>name</b>, <b>branch</b>, <b>team</b>, <b>role</b> = telecaller or manager. The Excel template has drop-downs with your branches and teams; a new name typed in is created. Leave <b>employee_code</b> and <b>email</b> blank to generate them (TC0001…, MGR0001…). Each team’s manager sees only that team’s data. Admins keep full access.</Notice>
          <p><button className="ghost" disabled={a.busy} onClick={() => a.run(() => downloadFile('/employees/bulk-template.xlsx'))}>Download template (Excel, with drop-downs)</button></p>
          <Field label="E-mail domain for generated e-mails" hint="Generated e-mail = userid@domain, e.g. tc0001@ametecsindia.com"><input value={domain} onChange={(e) => setDomain(e.target.value)} placeholder="ametecsindia.com" /></Field>
          <Field label="File (.csv or .xlsx)"><input type="file" accept=".csv,.xlsx" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
          <button disabled={a.busy || !file || !domain.includes('.')} onClick={upload}>Upload and create logins</button>
          {rows && <Table rows={rows.map((r, i) => ({ ...r, id: i }))} columns={[{ key: 'line', label: 'Row' }, { key: 'name', label: 'Name' }, { key: 'errors', label: 'Problem', render: (r: any) => r.errors.join('; ') }]} />}
        </>
      ) : (
        <>
          <Notice tone="ok">{res.created.length} logins created. <b>Download the logins now</b> — passwords are shown only once.</Notice>
          {res.groups_without_manager?.length > 0 && <Notice tone="warn">No manager yet for: {res.groups_without_manager.join(', ')}. Add one with role “manager”, or use ↑ manager on the team card.</Notice>}
          <p><button onClick={creds}>Download logins (CSV)</button></p>
          <Table rows={res.created.map((c: any, i: number) => ({ ...c, id: i }))} columns={[{ key: 'employee_code', label: 'User ID' }, { key: 'name', label: 'Name' }, { key: 'role', label: 'Role' },
            { key: 'branch', label: 'Branch' }, { key: 'group', label: 'Team' }, { key: 'manager', label: 'Manager' }, { key: 'email', label: 'E-mail' }, { key: 'password', label: 'Password', render: (c: any) => <span className="code">{c.password}</span> }]} />
        </>
      )}
    </Modal>
  );
}
