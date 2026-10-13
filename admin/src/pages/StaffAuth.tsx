import { useEffect, useRef, useState, type FormEvent } from 'react';
import QRCode from 'qrcode';
import { ApiError, post, put } from '../lib/api';
import { Badge, Card, ErrorBox, Field, Modal, Notice, Page, toast, useAction } from '../components/ui';
import { AuthShell, EmailInput, GoButton, PasswordInput } from './Login';

/*
 * Sign-in and "My profile" for Ametecs staff, shared by the Super Admin console (/super) and the Accountant panel
 * (/accounts). Both talk to /api/super/auth/*; the `portal` decides which accounts may sign in where.
 */

export type StaffMe = {
  id: number; name: string; email: string; phone: string | null; role: string; role_label: string; mfa_enabled: boolean; mfa_compulsory: boolean;
  last_login_at: string | null; access: Record<string, 'none' | 'view' | 'manage'>;
};
export type MfaPending = { mfa_required: 'verify' | 'enrol'; name: string; email: string };

type Portal = 'staff' | 'accounts';
const OTHER: Record<Portal, { url: string; label: string }> = {
  staff: { url: '/accounts', label: 'Accountant sign-in' },
  accounts: { url: '/super', label: 'Super Admin sign-in' },
};

/** Email + password, then the 2-step code when the account has it (first sign-in enrols when it is compulsory). */
export function StaffSignIn({ portal, pending, onIn, shell }: {
  portal: Portal; pending?: MfaPending | null; onIn: (m: StaffMe) => void;
  shell: { eyebrow: string; title: string; accent: string; points: string[]; heading: string; sub: string; button: string };
}) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [step, setStep] = useState<'password' | 'verify' | 'enrol'>(pending?.mfa_required ?? 'password');
  const [code, setCode] = useState('');
  const [enrol, setEnrol] = useState<{ secret: string; qr: string } | null>(null);
  const [wrongPortal, setWrongPortal] = useState(false);
  const a = useAction();

  const startEnrol = async () => {
    const s = await post<{ secret: string; otpauth_uri: string }>('/auth/mfa/setup');
    setEnrol({ secret: s.secret, qr: await QRCode.toDataURL(s.otpauth_uri, { margin: 1, width: 200, color: { dark: '#072654', light: '#ffffff' } }) });
  };
  // Reloaded mid-enrolment (GET /auth/me said "enrol"): fetch a secret once.
  const asked = useRef(false);
  useEffect(() => {
    if (step === 'enrol' && !enrol && !asked.current) { asked.current = true; void a.run(startEnrol); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [step]);

  const submitPassword = (e: FormEvent) => {
    e.preventDefault();
    setWrongPortal(false);
    void a.run(async () => {
      try {
        const r = await post<StaffMe & { mfa?: 'verify' | 'enrol' }>('/auth/login', { email, password, portal });
        setPassword('');
        if (r.mfa === 'verify' || r.mfa === 'enrol') {
          if (r.mfa === 'enrol') { asked.current = true; await startEnrol(); }
          setStep(r.mfa);
          return;
        }
        onIn(r);
      } catch (err) {
        if (err instanceof ApiError && err.code === 'wrong_portal') setWrongPortal(true);
        throw err;
      }
    });
  };
  const submitCode = (e: FormEvent) => {
    e.preventDefault();
    void a.run(async () => onIn(await post<StaffMe>('/auth/mfa/verify', { code })));
  };
  const other = OTHER[portal];

  return (
    <AuthShell eyebrow={shell.eyebrow} title={shell.title} accent={shell.accent} points={shell.points}>
      {step === 'password' ? (
        <>
          <h1>{shell.heading}</h1>
          <p className="lx-sub">{shell.sub}</p>
          <ErrorBox error={a.error} />
          {wrongPortal && <p className="lb-switch"><a href={other.url}>Go to the {other.label} →</a></p>}
          <form onSubmit={submitPassword} className="lx-form">
            <EmailInput value={email} onChange={setEmail} />
            <PasswordInput value={password} onChange={setPassword} />
            <GoButton busy={a.busy} label={shell.button} busyLabel="Signing in…" />
          </form>
          <p className="lb-alt">{portal === 'staff' ? 'Accountant?' : 'Super Admin or sales staff?'} <a href={other.url}>{other.label}</a></p>
        </>
      ) : (
        <>
          <h1>{step === 'enrol' ? 'Set up 2-step sign-in' : 'Enter your 2-step code'}</h1>
          <p className="lx-sub">{step === 'enrol' ? '2-step sign-in is required for staff. Scan the code with an authenticator app, then enter the 6 digits.' : 'Open your authenticator app and enter the 6-digit code for ROVEXY Staff.'}</p>
          <ErrorBox error={a.error} />
          <form onSubmit={submitCode} className="lx-form">
            {step === 'enrol' && enrol && (
              <div className="lx-enrol">
                <img src={enrol.qr} alt="Authenticator QR code" width={132} height={132} />
                <div>
                  <p>Google Authenticator, Microsoft Authenticator or similar.</p>
                  <p className="muted">Can't scan? Key: <span className="code">{enrol.secret}</span></p>
                </div>
              </div>
            )}
            <Field label="6-digit code">
              <input className="lx-otp" inputMode="numeric" pattern="\d{6}" maxLength={6} autoComplete="one-time-code" placeholder="000000" value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required autoFocus />
            </Field>
            <GoButton busy={a.busy} label="Verify" />
          </form>
          <p className="lb-alt"><a href="#" onClick={(e) => { e.preventDefault(); void post('/auth/logout').catch(() => undefined).finally(() => location.reload()); }}>Use a different account</a></p>
        </>
      )}
    </AuthShell>
  );
}

/** My profile: name and phone, password, 2-step sign-in. Same screen in both consoles. */
export function MyProfile({ me, onChange, onSignOut }: { me: StaffMe; onChange: (m: StaffMe) => void; onSignOut: () => void }) {
  const [p, setP] = useState({ name: me.name, phone: me.phone ?? '' });
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [mfa, setMfa] = useState<'off' | 'enrol' | null>(null);
  const prof = useAction();
  const pass = useAction();
  const saveProfile = (e: FormEvent) => {
    e.preventDefault();
    void prof.run(async () => { onChange(await put<StaffMe>('/auth/profile', p)); toast('Profile saved'); });
  };
  const savePassword = (e: FormEvent) => {
    e.preventDefault();
    if (pw.password !== pw.password_confirmation) { pass.setError('The two new passwords do not match.'); return; }
    void pass.run(async () => {
      await put('/auth/password', pw);
      setPw({ current_password: '', password: '', password_confirmation: '' });
      toast('Password changed. Your other sign-ins have been signed out.');
    });
  };
  return (
    <Page title="My profile" subtitle={`${me.email} · ${me.role_label}`} actions={<button className="secondary" onClick={onSignOut}>Sign out</button>}>
      <div className="grid-2">
        <Card title="Profile">
          <ErrorBox error={prof.error} />
          <form onSubmit={saveProfile} className="form-grid">
            <Field label="Full name"><input value={p.name} onChange={(e) => setP({ ...p, name: e.target.value })} required maxLength={150} /></Field>
            <Field label="Phone" hint="Optional"><input value={p.phone} onChange={(e) => setP({ ...p, phone: e.target.value })} inputMode="tel" maxLength={20} /></Field>
            <Field label="E-mail" hint="Ask a super admin to change your e-mail or role."><input value={me.email} disabled /></Field>
            <div className="row"><button disabled={prof.busy}>{prof.busy ? 'Saving…' : 'Save profile'}</button></div>
          </form>
        </Card>
        <Card title="Change password">
          <ErrorBox error={pass.error} />
          <form onSubmit={savePassword} className="form-grid">
            <Field label="Current password"><input type="password" autoComplete="current-password" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} required /></Field>
            <Field label="New password" hint="At least 12 characters, with letters and numbers."><input type="password" autoComplete="new-password" minLength={12} value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} required /></Field>
            <Field label="New password again"><input type="password" autoComplete="new-password" minLength={12} value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} required /></Field>
            <div className="row"><button disabled={pass.busy}>{pass.busy ? 'Changing…' : 'Change password'}</button></div>
          </form>
        </Card>
        <Card title="2-step sign-in" actions={<Badge value={me.mfa_enabled ? 'active' : 'pending'} label={me.mfa_enabled ? 'On' : 'Off'} />}>
          <p className="muted">{me.mfa_enabled ? 'Each sign-in asks for a 6-digit code from your authenticator app.' : 'Add a 6-digit code from your phone to every sign-in. Strongly recommended for anyone who can see money.'}</p>
          {me.mfa_compulsory && <Notice tone="info">2-step sign-in is compulsory for all staff.</Notice>}
          <div className="row">
            {!me.mfa_enabled && <button onClick={() => setMfa('enrol')}>Switch on</button>}
            {me.mfa_enabled && !me.mfa_compulsory && <button className="secondary" onClick={() => setMfa('off')}>Switch off</button>}
          </div>
        </Card>
        <Card title="This sign-in">
          <dl className="kv">
            <dt>Role</dt><dd>{me.role_label}</dd>
            <dt>Last sign-in</dt><dd>{me.last_login_at ? new Date(me.last_login_at).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}</dd>
            <dt>Idle sign-out</dt><dd>After 30 minutes without activity</dd>
          </dl>
        </Card>
      </div>
      {mfa === 'enrol' && <MfaEnrol onClose={() => setMfa(null)} onDone={(m) => { onChange(m); setMfa(null); toast('2-step sign-in is on'); }} />}
      {mfa === 'off' && <MfaOff onClose={() => setMfa(null)} onDone={(m) => { onChange(m); setMfa(null); toast('2-step sign-in is off'); }} />}
    </Page>
  );
}

function MfaEnrol({ onClose, onDone }: { onClose: () => void; onDone: (m: StaffMe) => void }) {
  const [enrol, setEnrol] = useState<{ secret: string; qr: string } | null>(null);
  const [code, setCode] = useState('');
  const a = useAction();
  const asked = useRef(false);
  useEffect(() => {
    if (asked.current) return;
    asked.current = true;
    void a.run(async () => {
      const s = await post<{ secret: string; otpauth_uri: string }>('/auth/mfa/setup');
      setEnrol({ secret: s.secret, qr: await QRCode.toDataURL(s.otpauth_uri, { margin: 1, width: 200, color: { dark: '#072654', light: '#ffffff' } }) });
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  return (
    <Modal title="Switch on 2-step sign-in" onClose={onClose}>
      <ErrorBox error={a.error} />
      {enrol && (
        <form className="form-grid" onSubmit={(e) => { e.preventDefault(); void a.run(async () => onDone(await post<StaffMe>('/auth/mfa/verify', { code }))); }}>
          <div className="lx-enrol"><img src={enrol.qr} alt="Authenticator QR code" width={132} height={132} />
            <div><p>Scan with Google Authenticator, Microsoft Authenticator or similar.</p><p className="muted">Can't scan? Key: <span className="code">{enrol.secret}</span></p></div></div>
          <Field label="6-digit code"><input inputMode="numeric" pattern="\d{6}" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required autoFocus /></Field>
          <div className="row"><button disabled={a.busy}>{a.busy ? 'Checking…' : 'Verify and switch on'}</button><button type="button" className="secondary" onClick={onClose}>Cancel</button></div>
        </form>
      )}
    </Modal>
  );
}

function MfaOff({ onClose, onDone }: { onClose: () => void; onDone: (m: StaffMe) => void }) {
  const [password, setPassword] = useState('');
  const a = useAction();
  return (
    <Modal title="Switch off 2-step sign-in" onClose={onClose}>
      <ErrorBox error={a.error} />
      <form className="form-grid" onSubmit={(e) => { e.preventDefault(); void a.run(async () => onDone(await post<StaffMe>('/auth/mfa/disable', { password }))); }}>
        <p className="muted">Your sign-ins will need only your password.</p>
        <Field label="Current password"><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoFocus /></Field>
        <div className="row"><button className="danger" disabled={a.busy}>Switch off</button><button type="button" className="secondary" onClick={onClose}>Keep it on</button></div>
      </form>
    </Modal>
  );
}
