import { useState } from 'react';
import { Link } from 'react-router-dom';
import { del, get, patch, post, put } from '../lib/api';
import { Badge, Card, ErrorBox, Notice, Page, Table, useAction, useAsync } from '../components/ui';

/**
 * Calling configuration, in the order an admin sets it up:
 *   Setup (checklist) → Calling options (SIM call, Click2Call) → Company numbers (vouchers, Smart31) → People & access.
 * Three things decide what an employee sees when they tap Call: the plan (LC), the option being switched on, and
 * whether that person is allowed (everyone, or selected groups / people).
 */
const NAME: Record<string, string> = { sim: 'SIM call', click2call: 'Click2Call', smart31: 'Smart31' };
const SHORT: Record<string, string> = { sim: 'Phone dials from its own SIM', click2call: 'Company number calls the customer', smart31: 'Rotates company numbers' };

function Switch({ on, disabled, onChange, label }: { on: boolean; disabled?: boolean; onChange: (v: boolean) => void; label?: string }) {
  return <label className="switch"><input type="checkbox" checked={on} disabled={disabled} onChange={(e) => onChange(e.target.checked)} /><span />{label}</label>;
}

function NotInPlan({ what }: { what: string }) {
  return <Notice tone="warn"><strong>{what} is not in your ROVEXY plan.</strong> Ask Ametecs to add it, or upgrade under <Link to="/licence">Plan &amp; licence</Link>.</Notice>;
}

// ───────────────────────────── 1. Setup ─────────────────────────────

/** Calling configuration → Setup: four steps with their status, and how each kind of call works. */
export function CallingSetupPage() {
  const acc = useAsync(() => get('/calling/access'));
  const c2c = useAsync(() => get('/calling/click2call'));
  const nums = useAsync(() => get('/calling/numbers'));
  const people = useAsync(() => get('/calling/users'));
  const a = acc.data, c = c2c.data, n = nums.data, p = people.data;
  if (!a || !c || !n || !p) return <Page title="Calling setup"><ErrorBox error={acc.error ?? c2c.error ?? nums.error ?? people.error} /><p className="muted">Loading…</p></Page>;

  const optionsOn = [a.sim.on && 'SIM call', a.click2call.on && 'Click2Call'].filter(Boolean) as string[];
  const assigned = n.numbers.filter((x: any) => x.click2call && x.groups.length).length;
  const c2cNumbersOk = !a.click2call.on || assigned > 0 || !!c.did;
  const noOption = p.users.filter((u: any) => !u.options.length);
  const noPhone = a.click2call.on ? p.users.filter((u: any) => u.access.click2call.allowed && !u.agent_number) : [];
  const steps = [
    { title: 'Your plan', to: '/licence', btn: 'View plan',
      text: ['sim', 'click2call', 'smart31'].map((f) => `${NAME[f]}: ${a[f].in_plan ? 'included' : 'not included'}`).join(' · '),
      done: a.sim.in_plan || a.click2call.in_plan },
    { title: 'Calling options', to: '/calling/options', btn: 'Choose options',
      text: optionsOn.length ? `Switched on: ${optionsOn.join(' and ')}.${optionsOn.length === 2 ? ' Employees choose on each call.' : ''}` : 'No calling option is switched on yet.',
      done: optionsOn.length > 0 && (!a.click2call.on || !!c.secret_hint) },
    { title: 'Company numbers', to: '/calling/numbers', btn: 'Manage numbers', skip: !a.click2call.on,
      text: !a.click2call.on ? 'Only needed for Click2Call.' : `${n.numbers.length} number(s), ${assigned} given to groups${a.smart31.on ? ' · Smart31 rotation on' : ''}.`,
      done: c2cNumbersOk },
    { title: 'Who can call', to: '/calling/people', btn: 'Set access',
      text: noOption.length ? `${noOption.length} employee(s) have no calling option.` : noPhone.length ? `${noPhone.length} employee(s) allowed Click2Call have no phone number.` : `All ${p.users.length} employee(s) can call.`,
      done: !noOption.length && !noPhone.length },
  ];
  return (
    <Page title="Calling setup" subtitle="Four steps decide what your employees see when they tap Call. Green means done.">
      <div className="cc-steps">
        {steps.map((s, i) => (
          <div key={s.title} className={`cc-step ${s.skip ? '' : s.done ? 'done' : 'todo'}`}>
            <span className="num">{s.done && !s.skip ? '✓' : i + 1}</span>
            <div><h3>{s.title} {!s.skip && <Badge value={s.done ? 'active' : 'pending'} label={s.done ? 'done' : 'needs attention'} />}</h3><p>{s.text}</p></div>
            <Link to={s.to}><button className="secondary">{s.btn}</button></Link>
          </div>
        ))}
      </div>
      <div className="cc-flow" style={{ marginTop: 16 }}>
        <Card title="SIM call: how it works">
          <ol>
            <li>The employee taps <strong>Call</strong> and picks <strong>SIM call</strong>.</li>
            <li>Their phone dials the customer from its own SIM.</li>
            <li>Talk time comes from the phone's call log; the phone's own recorder makes the recording.</li>
          </ol>
        </Card>
        <Card title="Click2Call: how it works">
          <ol>
            <li>The employee taps <strong>Call</strong> and picks <strong>Click2Call</strong> (and a company number, if they have several).</li>
            <li>Their phone rings first. They answer.</li>
            <li>The customer is called and sees your <strong>company number</strong> (with Smart31, the next number in rotation).</li>
            <li>The provider records the call; result and recording are copied here.</li>
          </ol>
        </Card>
      </div>
    </Page>
  );
}

// ───────────────────────────── 2. Calling options ─────────────────────────────

/** Calling configuration → Calling options: SIM call and Click2Call on/off, and the Click2Call connection. */
export function CallingOptionsPage() {
  const acc = useAsync(() => get('/calling/access'));
  const cfg = useAsync(() => get('/calling/click2call'));
  const a = useAction();
  const [f, setF] = useState<Record<string, string> | null>(null);
  const [result, setResult] = useState<{ ok: boolean; detail: string } | null>(null);
  const d = cfg.data, x = acc.data;
  if (!d || !x) return <Page title="Calling options"><ErrorBox error={cfg.error ?? acc.error} /><p className="muted">Loading…</p></Page>;
  const c2cOn = !!d.mode && d.mode !== 'off';
  const v = f ?? { did: d.did ?? '', agent_caller_id: d.agent_caller_id ?? '', gateway_secret: '', crm_id: d.crm_id ?? '', lc_secret: '', daily_limit: String(d.daily_limit ?? 100) };
  const set = (k: string, val: string) => setF({ ...v, [k]: val });
  const reload = async () => { setF(null); await cfg.reload(); await acc.reload(); };
  const saveC2c = (mode: string) => a.run(async () => {
    setResult(await put('/calling/click2call', { mode, did: v.did || null, agent_caller_id: v.agent_caller_id || null, gateway_secret: v.gateway_secret || null,
      crm_id: v.crm_id || null, lc_secret: v.lc_secret || null, daily_limit: v.daily_limit ? Number(v.daily_limit) : null }));
    await reload();
  });
  const setSim = (on: boolean) => a.run(async () => {
    await put('/calling/sim', { enabled: on });
    if (c2cOn && d.mode === 'only' && on) await put('/calling/click2call', { mode: 'choice' });   // older "Click2Call only" setting hid SIM
    await reload();
  });
  const simOn = x.sim.on && d.mode !== 'only';
  return (
    <Page title="Calling options" subtitle="Switch on the ways your employees may call. When both are on, the employee chooses on each call. Who may use each option is set in People & access.">
      <ErrorBox error={a.error} />
      <div className="cc-flow">
        <section className={`cc-opt ${simOn ? 'on' : ''}`}>
          <header><h3>SIM call</h3><Switch on={simOn} disabled={a.busy || !x.sim.in_plan} onChange={setSim} label={simOn ? 'On' : 'Off'} /></header>
          <p>The employee's phone dials the customer from its own SIM. Nothing to set up here.</p>
          <p>For recordings, employees switch on <em>automatic call recording</em> in their phone's dialer (Xiaomi: Phone → Settings → Call recording; Samsung: Phone → ⋮ → Settings → Record calls; OPPO / Realme / OnePlus: Phone → Settings → Call recording). Whether recording is required: <Link to="/policies">Policy profiles</Link>.</p>
          {!x.sim.in_plan && <NotInPlan what="Calling" />}
        </section>
        <section className={`cc-opt ${c2cOn ? 'on' : ''}`}>
          <header><h3>Click2Call</h3><Switch on={c2cOn} disabled={a.busy || (!c2cOn && !d.in_plan)} onChange={(on) => saveC2c(on ? 'choice' : 'off')} label={c2cOn ? 'On' : 'Off'} /></header>
          <p>Your calling provider rings the employee's phone first, then the customer, who sees your company number. The provider records every call.</p>
          {!d.in_plan && <NotInPlan what="Click2Call" />}
          {c2cOn && !d.secret_hint && <Notice tone="warn">Enter the gateway secret below and press Save, or calls cannot be placed.</Notice>}
        </section>
      </div>

      <div style={{ height: 16 }} />
      <Card title="Click2Call connection" actions={c2cOn ? <Badge value="active" label="on" /> : <Badge value="off" label="off" />}>
        <p className="muted">From your Ametecs / provider account. You only fill these once.</p>
        <div className="form-grid two">
          <label className="field"><span>Gateway secret</span><input type="password" autoComplete="new-password" value={v.gateway_secret} placeholder={d.secret_hint ? `Saved (${d.secret_hint}). Leave blank to keep` : 'From your provider'} onChange={(e) => set('gateway_secret', e.target.value)} /></label>
          <label className="field"><span>CRM id (for adding vouchers)</span><input value={v.crm_id} placeholder="From Ametecs" onChange={(e) => set('crm_id', e.target.value)} /></label>
          <label className="field"><span>CRM secret key (for adding vouchers)</span><input type="password" autoComplete="new-password" value={v.lc_secret} placeholder={d.lc_secret_set ? 'Saved. Leave blank to keep' : 'From Ametecs CRM'} onChange={(e) => set('lc_secret', e.target.value)} /></label>
          <label className="field"><span>Company number {d.voucher_numbers ? '(optional: you have voucher numbers)' : ''}</span><input value={v.did} placeholder="80XXXXXXXX" onChange={(e) => set('did', e.target.value)} /></label>
          <label className="field"><span>Calls to the same customer per day</span><input type="number" min={1} max={1000} value={v.daily_limit} onChange={(e) => set('daily_limit', e.target.value)} /></label>
        </div>
        <details>
          <summary className="muted" style={{ cursor: 'pointer', margin: '6px 0' }}>Advanced</summary>
          <div className="form-grid two">
            <label className="field"><span>Number the employee is rung from (only if not the company number)</span><input value={v.agent_caller_id} placeholder="Same as the company number" onChange={(e) => set('agent_caller_id', e.target.value)} /></label>
          </div>
          <p className="muted">Give your provider this address for call results: <span className="code">{d.callback_url?.replace('/telephony/v1/click2call', '/click2call/webhook')}</span> · Webhook secret on the server: {d.webhook_secret_set ? 'set' : 'not set'}</p>
        </details>
        {result && <Notice tone={result.ok ? 'ok' : 'warn'}>{result.detail}</Notice>}
        <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
          <button disabled={a.busy} onClick={() => saveC2c(d.mode ?? 'off')}>Save</button>
          <button className="secondary" disabled={a.busy || !c2cOn} onClick={() => a.run(async () => setResult(await post('/calling/click2call/test')))}>Test connection</button>
        </div>
      </Card>
    </Page>
  );
}

// ───────────────────────────── 3. Company numbers ─────────────────────────────

/** Calling configuration → Company numbers: voucher numbers given to groups, and Smart31 rotation. */
export function CompanyNumbersPage() {
  const [tab, setTab] = useState<'numbers' | 'smart31'>('numbers');
  return (
    <Page title="Company numbers" subtitle="The numbers your customers see on Click2Call calls. Add the voucher from Ametecs, then give each number to the groups that may use it.">
      <div className="tabs" role="tablist">
        <button role="tab" className={`tab${tab === 'numbers' ? ' on' : ''}`} onClick={() => setTab('numbers')}>Numbers</button>
        <button role="tab" className={`tab${tab === 'smart31' ? ' on' : ''}`} onClick={() => setTab('smart31')}>Smart31 rotation</button>
      </div>
      {tab === 'numbers' ? <VoucherNumbers /> : <Smart31Pool />}
    </Page>
  );
}

function GroupPicker({ teams, have, onAdd, label = '+ Add group' }: { teams: any[]; have: number[]; onAdd: (id: number) => void; label?: string }) {
  const left = teams.filter((t) => !have.includes(t.id));
  if (!left.length) return null;
  return (
    <select value="" style={{ width: 'auto' }} onChange={(e) => e.target.value && onAdd(Number(e.target.value))}>
      <option value="">{label}</option>{left.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
    </select>
  );
}

function VoucherNumbers() {
  const s = useAsync(() => get('/calling/numbers'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const a = useAction();
  const [code, setCode] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const d = s.data, t = teams.data ?? [];
  const setGroups = (x: any, ids: number[]) => a.run(async () => { await patch(`/calling/numbers/${x.id}`, { team_ids: ids }); await s.reload(); });
  const giveAll = (gid: number) => a.run(async () => { await post('/calling/numbers/bulk', { ids: d.numbers.filter((x: any) => x.click2call).map((x: any) => x.id), action: 'update', team_ids: [gid] }); await s.reload(); });
  return (
    <>
      <ErrorBox error={s.error ?? a.error} />
      <Card title="Add voucher">
        {d && (!d.crm_id_set || !d.licence_secret_set) && <Notice tone="warn">First {!d.crm_id_set && <>enter your <strong>CRM id</strong> in <Link to="/calling/options">Calling options</Link></>}{!d.crm_id_set && !d.licence_secret_set && ', and '}{!d.licence_secret_set && <>enter the <strong>CRM secret key</strong> in <Link to="/calling/options">Calling options</Link></>}.</Notice>}
        <p className="muted">A new voucher replaces your current numbers. Old numbers are kept in history.</p>
        <div style={{ display: 'flex', gap: 8 }}>
          <input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Voucher / LC code from Ametecs CRM" style={{ flex: 1 }} />
          <button disabled={a.busy || !code} onClick={() => a.run(async () => { const r = await post('/calling/vouchers', { voucher_code: code }); setMsg(r.message); setCode(''); await s.reload(); })}>Add voucher</button>
        </div>
        {msg && <Notice tone="ok">{msg}</Notice>}
        {d?.vouchers?.length > 0 && <p className="muted" style={{ marginTop: 8 }}>Latest: {d.vouchers.slice(0, 3).map((v: any) => `${v.voucher_code} · ${v.numbers} numbers · ${new Date(v.created_at).toLocaleDateString()}`).join('  |  ')}</p>}
      </Card>
      {d && (
        <Card title={`Your numbers (${d.numbers.length})`} actions={d.numbers.length > 0 && t.length > 0 ? <GroupPicker teams={t} have={[]} onAdd={giveAll} label="Give all Click2Call numbers to…" /> : undefined}>
          {d.numbers.length > 0 && <p className="muted">Each number can be used only by the groups listed next to it. To give every Click2Call number to a group at once, use the menu at the top right.</p>}
          <Table rows={d.numbers} empty="No numbers yet. Add your voucher above." columns={[
            { key: 'n', label: 'Number', render: (x: any) => <><strong>+91 {x.virtual_number}</strong>{x.tc_verified && <> <Badge value="active" label="verified" /></>}</> },
            { key: 'u', label: 'For', render: (x: any) => [x.click2call && 'Click2Call', x.obd && 'OBD', x.webrtc && 'WebRTC'].filter(Boolean).join(', ') || '—' },
            { key: 'g', label: 'Groups that may use it', render: (x: any) => (
              <div className="cc-chips">
                {x.groups.length === 0 && <span className="muted">Nobody yet</span>}
                {(x.team_ids ?? []).map((id: number, i: number) => <span key={id} className="cc-chip">{x.groups[i] ?? `#${id}`}<button title="Remove" disabled={a.busy} onClick={() => setGroups(x, x.team_ids.filter((y: number) => y !== id))}>×</button></span>)}
                <GroupPicker teams={t} have={x.team_ids ?? []} onAdd={(id) => setGroups(x, [...(x.team_ids ?? []), id])} />
              </div>
            ) },
            { key: 'e', label: 'Valid until', render: (x: any) => x.expires_at ? (x.available ? x.expires_at.slice(0, 10) : <Badge value="failed" label={`expired ${x.expires_at.slice(0, 10)}`} />) : '—' },
            { key: 'x', label: '', render: (x: any) => <button className="ghost" disabled={a.busy} onClick={() => a.run(async () => { await del(`/calling/numbers/${x.id}`); await s.reload(); })}>Remove</button> },
          ]} />
        </Card>
      )}
    </>
  );
}

function Smart31Pool() {
  const s = useAsync(() => get('/calling/smart31'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const a = useAction();
  const [n, setN] = useState({ number: '', label: '' });
  const d = s.data, t = teams.data ?? [];
  const upd = (x: any, body: any) => a.run(async () => { await patch(`/calling/smart31/numbers/${x.id}`, body); await s.reload(); });
  if (!d) return <ErrorBox error={s.error} />;
  return (
    <>
      <ErrorBox error={a.error} />
      {!d.in_plan && <NotInPlan what="Smart31" />}
      <section className={`cc-opt ${d.enabled ? 'on' : ''}`} style={{ marginBottom: 16 }}>
        <header><h3>Smart31 rotation</h3><Switch on={!!d.enabled} disabled={a.busy || !d.in_plan} onChange={(on) => a.run(async () => { await put('/calling/smart31', { enabled: on }); await s.reload(); })} label={d.enabled ? 'On' : 'Off'} /></header>
        <p>Each Click2Call call uses the next number below, one after another, so no single number is marked as spam. The employee doesn't choose a number. Numbers from your voucher appear here automatically.</p>
        {d.in_plan && !d.click2call_on && <Notice tone="warn">Smart31 works with Click2Call. <Link to="/calling/options">Switch on Click2Call</Link>.</Notice>}
      </section>
      <Card title={`Rotation numbers (${d.numbers.length})`}>
        <Table rows={d.numbers} empty="No numbers yet." columns={[
          { key: 'n', label: 'Number', render: (x: any) => <><strong>+91 {x.number}</strong>{x.type === '1600' && <> <Badge value="pending" label="1600" /></>}<br /><small className="muted">{x.source === 'voucher' ? `From voucher${x.group_name ? ` · ${x.group_name}` : ''}` : (x.label || 'Added here')}</small></> },
          { key: 'g', label: 'Groups', render: (x: any) => (
            <div className="cc-chips">
              {!(x.team_ids ?? []).length && <span className="muted">All groups</span>}
              {(x.team_ids ?? []).map((id: number, i: number) => <span key={id} className="cc-chip">{x.groups[i] ?? `#${id}`}<button disabled={a.busy} onClick={() => upd(x, { team_ids: x.team_ids.filter((y: number) => y !== id) })}>×</button></span>)}
              {d.in_plan && <GroupPicker teams={t} have={x.team_ids ?? []} onAdd={(id) => upd(x, { team_ids: [...(x.team_ids ?? []), id] })} />}
            </div>
          ) },
          { key: 'c', label: 'Calls', render: (x: any) => <>{x.calls}<br /><small className="muted">{x.last_used_at ? `last ${new Date(x.last_used_at).toLocaleString()}` : 'not used yet'}</small></> },
          { key: 'on', label: 'In rotation', render: (x: any) => x.expired ? <Badge value="failed" label="expired" /> : <Switch on={!!x.active} disabled={a.busy || !d.in_plan} onChange={(on) => upd(x, { active: on })} /> },
          { key: 'x', label: '', render: (x: any) => <button className="ghost" disabled={a.busy} onClick={() => a.run(async () => { await del(`/calling/smart31/numbers/${x.id}`); await s.reload(); })}>Remove</button> },
        ]} />
        {d.in_plan && (
          <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
            <input value={n.number} placeholder="Add a number: 1600XXXXXX or 80XXXXXXXX" onChange={(e) => setN({ ...n, number: e.target.value })} style={{ flex: 2 }} />
            <input value={n.label} placeholder="Label (optional)" onChange={(e) => setN({ ...n, label: e.target.value })} style={{ flex: 1 }} />
            <button disabled={a.busy || !n.number} onClick={() => a.run(async () => { await post('/calling/smart31/numbers', { number: n.number, label: n.label || null, team_ids: [] }); setN({ number: '', label: '' }); await s.reload(); })}>Add</button>
          </div>
        )}
      </Card>
    </>
  );
}

// ───────────────────────────── 4. People & access ─────────────────────────────

/** Calling configuration → People & access: who may use each option (everyone / groups / people) and each person's details. */
export function PeopleAccessPage() {
  const acc = useAsync(() => get('/calling/access'));
  const ppl = useAsync(() => get('/calling/users'));
  const teams = useAsync(() => get<any[]>('/teams'));
  const a = useAction();
  const [q, setQ] = useState('');
  const [edit, setEdit] = useState<Record<number, { agent: string; def: string }>>({});
  const x = acc.data, p = ppl.data, t = teams.data ?? [];
  const reload = async () => { await acc.reload(); await ppl.reload(); };
  if (!x || !p) return <Page title="People & access"><ErrorBox error={acc.error ?? ppl.error} /><p className="muted">Loading…</p></Page>;
  const feats = ['sim', 'click2call', 'smart31'].filter((f) => x[f].in_plan);
  const row = (u: any) => edit[u.id] ?? { agent: u.click_agent_number ?? '', def: u.c2c_default_number ?? '' };
  const shown = p.users.filter((u: any) => !q || `${u.name} ${u.employee_code} ${u.groups.join(' ')}`.toLowerCase().includes(q.toLowerCase()));
  const toggle = (u: any, f: string, on: boolean) => a.run(async () => { await patch(`/calling/users/${u.id}`, { [f]: on }); await reload(); });
  return (
    <Page title="People & access" subtitle="Decide who may use each calling option. Employees only see the options they are allowed.">
      <ErrorBox error={a.error} />
      <Card title="Who may use each option">
        {feats.map((f) => (
          <div key={f} className="cc-who">
            <div><strong>{NAME[f]}</strong><br /><small className="muted">{SHORT[f]}{!x[f].on && ' · switched off'}</small></div>
            <div className="seg" role="tablist">
              {(['everyone', 'selected'] as const).map((m) => <button key={m} role="tab" aria-selected={x[f].mode === m} className={x[f].mode === m ? 'on' : ''} disabled={a.busy}
                onClick={() => x[f].mode !== m && a.run(async () => { await put(`/calling/access/${f}`, { mode: m }); await reload(); })}>{m === 'everyone' ? 'Everyone' : 'Selected'}</button>)}
            </div>
            <div className="cc-chips">
              {x[f].mode === 'everyone' ? <span className="muted">All employees</span> : <>
                {x[f].grants.map((g: any) => <span key={g.id} className="cc-chip">{g.type === 'group' ? `👥 ${g.name}` : g.name}<button title="Remove" disabled={a.busy} onClick={() => a.run(async () => { await del(`/calling/access/${f}/${g.id}`); await reload(); })}>×</button></span>)}
                <GroupPicker teams={t} have={x[f].grants.filter((g: any) => g.type === 'group').map((g: any) => g.team_id)} onAdd={(id) => a.run(async () => { await post(`/calling/access/${f}`, { team_ids: [id] }); await reload(); })} />
                <span className="muted" style={{ fontSize: '.8rem' }}>or switch people on in the table below</span>
              </>}
            </div>
          </div>
        ))}
        {p.limit.limit > 0 && <p className="muted" style={{ marginTop: 8 }}>Click2Call licence: {p.limit.in_use} of {p.limit.limit} people in use.</p>}
      </Card>

      <Card title={`Employees (${p.users.length})`} actions={<input placeholder="Search name, ID or group" value={q} onChange={(e) => setQ(e.target.value)} style={{ width: 220 }} />}>
        <Table rows={shown} empty="No employees." columns={[
          { key: 'n', label: 'Employee', render: (u: any) => <>{u.name} <small className="muted">{u.employee_code}</small><br /><small className="muted">{u.groups.join(', ') || 'no group'}</small></> },
          ...feats.map((f) => ({ key: f, label: NAME[f], render: (u: any) => {
            const s = u.access[f];
            return (
              <div className="cc-cell">
                {s.via === 'everyone' || s.via === 'group'
                  ? <Badge value={s.allowed ? 'active' : 'off'} label={s.allowed ? 'yes' : 'no'} />
                  : <Switch on={s.allowed} disabled={a.busy} onChange={(on) => toggle(u, f, on)} />}
                <small>{s.via === 'everyone' ? 'everyone' : s.via === 'group' ? 'through group' : s.via === 'person' ? 'personally' : ''}</small>
              </div>
            );
          } })),
          { key: 'ph', label: 'Phone rung first (Click2Call)', render: (u: any) => <div className="cc-cell">
            <input value={row(u).agent} placeholder={u.phone ? `${u.phone} (work phone)` : 'Enter mobile number'} onChange={(e) => setEdit({ ...edit, [u.id]: { ...row(u), agent: e.target.value } })} style={{ maxWidth: 190 }} />
            {u.access.click2call?.allowed && !u.agent_number && <small style={{ color: 'var(--bad)' }}>needed for Click2Call</small>}
          </div> },
          { key: 'df', label: 'Default company number', render: (u: any) => u.numbers.length > 1
            ? <select value={row(u).def} onChange={(e) => setEdit({ ...edit, [u.id]: { ...row(u), def: e.target.value } })} style={{ width: 'auto' }}><option value="">First available</option>{u.numbers.map((nn: string) => <option key={nn} value={nn}>+91 {nn}</option>)}</select>
            : <span className="muted">{u.numbers[0] ? `+91 ${u.numbers[0]}` : 'none'}</span> },
          { key: 'sv', label: '', render: (u: any) => edit[u.id] ? <button disabled={a.busy} onClick={() => a.run(async () => {
            await patch(`/calling/users/${u.id}`, { click_agent_number: row(u).agent || null, c2c_default_number: row(u).def || null });
            const { [u.id]: _, ...rest } = edit; setEdit(rest); await reload();
          })}>Save</button> : null },
          { key: 'res', label: 'Sees on Call', render: (u: any) => u.options.length ? u.options.map((o: string) => (o === 'sim' ? 'SIM call' : 'Click2Call')).join(' / ') : <Badge value="failed" label="nothing" /> },
        ]} />
      </Card>
    </Page>
  );
}
