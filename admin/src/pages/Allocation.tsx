import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { get, post } from '../lib/api';
import { Badge, Card, ErrorBox, Field, Notice, Page, useAction, useAsync } from '../components/ui';

type Who = 'all' | 'some' | 'pool';

/** Step 2 + 3, shared by "Assign a portfolio" and "Transfer cases": choose a group, then all / some of its employees, or its pool. */
function GroupAndEmployees({ teams, people, teamId, setTeamId, who, setWho, picked, setPicked, exclude }: {
  teams: any[]; people: any[]; teamId: string; setTeamId: (v: string) => void; who: Who; setWho: (v: Who) => void;
  picked: number[]; setPicked: (v: number[]) => void; exclude?: number;
}) {
  const members = people.filter((e: any) => e.status === 'active' && e.id !== exclude && e.teams.some((t: any) => String(t.id) === teamId && (t.pivot?.membership ?? 'member') === 'member'));
  const team = teams.find((t: any) => String(t.id) === teamId);
  return (
    <>
      <div className="step"><span className="step-no">2</span><div className="step-body">
        <Field label="Group">
          <select value={teamId} onChange={(e) => { setTeamId(e.target.value); setPicked([]); }}>
            <option value="">Choose a group…</option>
            {teams.map((t: any) => <option key={t.id} value={t.id}>{t.name} — {t.users_count} people{t.managers?.length ? `, manager ${t.managers.map((m: any) => m.name).join(', ')}` : ''}</option>)}
          </select>
        </Field>
      </div></div>
      <div className="step"><span className="step-no">3</span><div className="step-body">
        <label className="field"><span>Employees{team ? ` in ${team.name}` : ''}</span></label>
        {!teamId ? <p className="muted">Choose a group first.</p> : (
          <>
            <div className="choice-row">
              <label className={who === 'all' ? 'choice on' : 'choice'}><input type="radio" checked={who === 'all'} onChange={() => setWho('all')} /> All employees ({members.length})</label>
              <label className={who === 'some' ? 'choice on' : 'choice'}><input type="radio" checked={who === 'some'} onChange={() => setWho('some')} /> Choose employees</label>
              <label className={who === 'pool' ? 'choice on' : 'choice'}><input type="radio" checked={who === 'pool'} onChange={() => setWho('pool')} /> Nobody yet — the group’s manager assigns later</label>
            </div>
            {members.length === 0 && who !== 'pool' && <Notice tone="warn">This group has no active employees. Add them under Team → Groups.</Notice>}
            {who === 'some' && members.length > 0 && (
              <div className="pick-list">
                <div className="row"><button className="ghost" onClick={() => setPicked(members.map((m: any) => m.id))}>Select all</button><button className="ghost" onClick={() => setPicked([])}>Clear</button><span className="muted">{picked.length} selected</span></div>
                {members.map((m: any) => (
                  <label key={m.id} className={picked.includes(m.id) ? 'pick on' : 'pick'}>
                    <input type="checkbox" checked={picked.includes(m.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, m.id] : picked.filter((x) => x !== m.id))} />
                    <span>{m.name}</span><small className="muted">{m.employee_code}</small>
                  </label>
                ))}
              </div>
            )}
          </>
        )}
      </div></div>
    </>
  );
}

/** Assignment: give a portfolio's cases to a group and one, several or all of its employees — or move cases between employees. */
export function AllocationPage() {
  const [params] = useSearchParams();
  const [tab, setTab] = useState<'assign' | 'transfer'>('assign');
  const emps = useAsync(() => get('/employees'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const portfolios = useAsync(() => get<any[]>('/portfolios'));
  const facets = useAsync(() => get('/customers/facets'));
  const people: any[] = emps.data?.data ?? [];
  const a = useAction();
  // Step 1
  const [pf, setPf] = useState(params.get('portfolio') ?? '');
  const [from, setFrom] = useState('');
  const [onlyNew, setOnlyNew] = useState(true);
  const [more, setMore] = useState(false);
  const [loc, setLoc] = useState({ city: '', area: '', pincode: '', status: '' });
  const [part, setPart] = useState(false);
  const [limit, setLimit] = useState('');
  // Steps 2–3
  const [teamId, setTeamId] = useState('');
  const [who, setWho] = useState<Who>('all');
  const [picked, setPicked] = useState<number[]>([]);
  const [count, setCount] = useState<number | null>(null);
  const [done, setDone] = useState<string | null>(null);

  const portfolio = (portfolios.data ?? []).find((p: any) => String(p.id) === pf);
  const team = (teams.data ?? []).find((t: any) => String(t.id) === teamId);
  const fromP = people.find((p: any) => String(p.id) === from);
  const name = (id: any) => people.find((e: any) => e.id === Number(id))?.name ?? id;
  const filter = tab === 'assign'
    ? { import_id: pf, assigned: onlyNew ? 'none' : '', ...(more ? loc : {}) }
    : { assigned: from, ...(more ? loc : {}) };
  const ready1 = tab === 'assign' ? !!pf : !!from;
  const members = people.filter((e: any) => e.status === 'active' && e.teams.some((t: any) => String(t.id) === teamId && (t.pivot?.membership ?? 'member') === 'member') && String(e.id) !== from);
  const receivers = who === 'pool' ? [] : who === 'all' ? members : members.filter((m: any) => picked.includes(m.id));
  const n = part ? Math.min(Number(limit) || 0, count ?? 0) : count ?? 0;
  const ready = ready1 && !!teamId && n > 0 && (who === 'pool' || receivers.length > 0);

  useEffect(() => {
    if (!ready1) { setCount(null); return; }
    let stop = false;
    const t = setTimeout(async () => { try { const r = await post('/customers/allocate', { filter, dry_run: true }); if (!stop) setCount(r.matching); } catch { if (!stop) setCount(null); } }, 250);
    return () => { stop = true; clearTimeout(t); };
  }, [JSON.stringify(filter), ready1, done]);

  const reset = (t: 'assign' | 'transfer') => { setTab(t); setDone(null); setTeamId(''); setPicked([]); setWho('all'); setPart(false); setLimit(''); };
  const confirmAssign = () => a.run(async () => {
    const body: any = { filter, limit: part ? Number(limit) : null, pick: 'oldest', team_id: Number(teamId) };
    if (who === 'pool') body.target = 'group';
    else { body.target = 'random'; body.user_ids = receivers.map((m: any) => m.id); }
    const r = await post('/customers/allocate', body);
    setDone(`Done — ${r.updated} cases given to ` + (r.per_caller ? Object.entries(r.per_caller).map(([u, k]) => `${name(u)} (${k})`).join(', ') : `the ${team?.name} group (manager to assign)`) + '. Phones get them at the next sync.');
    void portfolios.reload();
  });

  const selects = (k: keyof typeof loc, label: string, vals?: string[]) => (
    <Field label={label}><select value={loc[k]} onChange={(e) => setLoc({ ...loc, [k]: e.target.value })}><option value="">Any</option>{(vals ?? []).map((v) => <option key={v} value={v}>{v}</option>)}</select></Field>
  );

  return (
    <Page title="Assignment" subtitle="Give cases to a group and its employees. Pick what, then to whom, check the summary, confirm.">
      <ErrorBox error={a.error ?? emps.error ?? teams.error ?? portfolios.error} />
      <div className="tabs" role="tablist">
        <button role="tab" aria-selected={tab === 'assign'} className={tab === 'assign' ? 'tab on' : 'tab'} onClick={() => reset('assign')}>Assign a portfolio</button>
        <button role="tab" aria-selected={tab === 'transfer'} className={tab === 'transfer' ? 'tab on' : 'tab'} onClick={() => reset('transfer')}>Transfer an employee’s cases</button>
      </div>

      <Card>
        <div className="step"><span className="step-no">1</span><div className="step-body">
          {tab === 'assign' ? (
            <>
              <Field label="Portfolio">
                <select value={pf} onChange={(e) => { setPf(e.target.value); setDone(null); }}>
                  <option value="">Choose a portfolio…</option>
                  {(portfolios.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.name} — {p.total} cases, {p.unassigned} not assigned</option>)}
                </select>
              </Field>
              {portfolio && (
                <div className="choice-row">
                  <label className={onlyNew ? 'choice on' : 'choice'}><input type="radio" checked={onlyNew} onChange={() => setOnlyNew(true)} /> Only cases not assigned yet ({portfolio.unassigned})</label>
                  <label className={!onlyNew ? 'choice on' : 'choice'}><input type="radio" checked={!onlyNew} onChange={() => setOnlyNew(false)} /> All cases of the portfolio ({portfolio.total}) — re-assigns</label>
                </div>
              )}
            </>
          ) : (
            <Field label="Move the cases of" hint="Their open follow-ups move with the cases; call history and payments stay with each customer.">
              <select value={from} onChange={(e) => { setFrom(e.target.value); setDone(null); }}>
                <option value="">Choose an employee…</option>
                {people.filter((p: any) => p.roles.some((r: any) => r.key === 'employee')).map((p: any) => <option key={p.id} value={p.id}>{p.name} ({p.employee_code}){p.teams[0] ? ` · ${p.teams[0].name}` : ''}</option>)}
              </select>
            </Field>
          )}
          {ready1 && (
            <>
              <div className="row" style={{ marginTop: 6 }}>
                <label className="row"><input type="checkbox" style={{ width: 'auto' }} checked={part} onChange={(e) => setPart(e.target.checked)} /> Only some:</label>
                <input type="number" min={1} value={limit} disabled={!part} onChange={(e) => setLimit(e.target.value)} placeholder="how many" style={{ width: 110 }} />
                <button className="ghost" onClick={() => setMore(!more)}>{more ? 'Hide' : 'Filter by'} city / area / pincode / status</button>
              </div>
              {more && <div className="form-grid">{selects('city', 'City', facets.data?.city)}{selects('area', 'Area / branch', facets.data?.area)}
                <Field label="Pincode (or its start)"><input value={loc.pincode} onChange={(e) => setLoc({ ...loc, pincode: e.target.value.replace(/\D/g, '') })} /></Field>
                {selects('status', 'Status', ['new', 'in_progress', 'follow_up', 'closed', 'do_not_call'])}</div>}
            </>
          )}
        </div></div>

        <GroupAndEmployees teams={teams.data ?? []} people={people} teamId={teamId} setTeamId={setTeamId} who={who} setWho={setWho} picked={picked} setPicked={setPicked} exclude={from ? Number(from) : undefined} />

        <div className="step"><span className="step-no">4</span><div className="step-body">
          <label className="field"><span>Check and confirm</span></label>
          <table className="kv summary"><tbody>
            <tr><th>{tab === 'assign' ? 'Portfolio' : 'From employee'}</th><td>{tab === 'assign' ? (portfolio ? <strong>{portfolio.name}</strong> : <span className="muted">—</span>) : (fromP ? <strong>{fromP.name} ({fromP.employee_code})</strong> : <span className="muted">—</span>)}</td></tr>
            <tr><th>Cases</th><td>{ready1 ? <strong>{n}</strong> : '—'}{ready1 && tab === 'assign' && <small className="muted"> {onlyNew ? 'not assigned yet' : 'all, re-assigned'}</small>}{more && Object.values(loc).some(Boolean) && <small className="muted"> · filtered: {Object.entries(loc).filter(([, v]) => v).map(([k, v]) => `${k} ${v}`).join(', ')}</small>}</td></tr>
            <tr><th>Group</th><td>{team ? <strong>{team.name}</strong> : <span className="muted">—</span>}{team?.managers?.length ? <small className="muted"> · manager {team.managers.map((m: any) => m.name).join(', ')}</small> : null}</td></tr>
            <tr><th>Employees</th><td>{!team ? <span className="muted">—</span> : who === 'pool' ? 'None yet — kept in the group for its manager to assign' : receivers.length === 0 ? <Badge value="pending" label="choose employees" /> : <>{receivers.map((m: any) => <span key={m.id} className="tag">{m.name}</span>)}<br /><small className="muted">Shared evenly at random: about {Math.ceil(n / receivers.length)} each</small></>}</td></tr>
          </tbody></table>
          <button disabled={a.busy || !ready} onClick={confirmAssign}>Confirm — {tab === 'assign' ? 'assign' : 'transfer'} {n || ''} cases</button>
          {done && <Notice tone="ok">{done}</Notice>}
        </div></div>
      </Card>
    </Page>
  );
}
