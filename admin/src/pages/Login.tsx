import { useState, type FormEvent, type KeyboardEvent, type ReactNode } from 'react';
import QRCode from 'qrcode';
import { currentWorkspace, post } from '../lib/api';
import { useAuth } from '../lib/auth';
import { ErrorBox, Field, useAction } from '../components/ui';



export function Icon({ k }: { k: string }) {
  const p: Record<string, string> = {
    call: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2',
    pin: 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5',
    shield: 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6zM8.5 12l2.5 2.5 4.5-5',
    pay: 'M3 7h18v10H3zM3 11h18M7 15h3',
    chat: 'M4 5h16v11H9l-5 4z',
    mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
    lock: 'M6 11h12v9H6zM9 11V8a3 3 0 0 1 6 0v3',
    eye: 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12zM12 14.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5',
    eyeOff: 'M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.5 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.6 6.6C3.8 8.3 2 12 2 12s3.5 6 10 6a9.6 9.6 0 0 0 4.4-1',
    arrow: 'M5 12h14M13 6l6 6-6 6',
    people: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 12 0v1M16 3.5a4 4 0 0 1 0 7.5M22 21v-1a6 6 0 0 0-4-5.6',
    key: 'M15 7a4 4 0 1 1-3.9 5H9v2H7v2H4v-3l6.1-6.1A4 4 0 0 1 15 7zM16 8.5h.01',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
    spark: 'M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5 18 18M6 18l2.5-2.5M15.5 8.5 18 6',
    globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3c2.5 2.7 3.5 5.7 3.5 9S14.5 18.3 12 21c-2.5-2.7-3.5-5.7-3.5-9S9.5 5.7 12 3',
  };
  return <svg viewBox="0 0 24 24" aria-hidden="true"><path d={p[k]} /></svg>;
}

/** Email + password, then TOTP when the server requires it (default). First sign-in enrols an authenticator app. */
/** Shared sign-in frame ("classic split", chosen 13 Oct): navy brand panel on the left, plain white form on the right. Used by /admin and /super. */
export function AuthShell({ eyebrow, title, accent, points, children }: {
  eyebrow: string; title: string; accent: string; points: string[]; children: ReactNode;
}) {
  return (
    <div className="lb">
      <aside className="lb-brand">
        <img className="lb-wm" src="/brand/rovexy-r.svg" alt="" aria-hidden="true" />
        <img className="lb-logo" src="/brand/rovexy-logo-dark.svg" alt="ROVEXY — Your Workforce. In Motion." width="700" height="204" />
        <div className="lb-pitch">
          <h2>{title}<br /><span>{accent}</span></h2>
          <ul>{points.map((p) => <li key={p}><b aria-hidden="true">✓</b>{p}</li>)}</ul>
        </div>
        <small>© {new Date().getFullYear()} Ametecs India Pvt. Ltd.</small>
      </aside>
      <main className="lb-form">
        <div className="lb-in">
          <img className="lb-logo-sm" src="/brand/rovexy-logo.svg" alt="ROVEXY" width="700" height="204" />
          <div className="lb-tag">{eyebrow}</div>
          {children}
          <p className="lb-note"><i aria-hidden="true" />Protected connection · every sign-in is recorded</p>
        </div>
      </main>
    </div>
  );
}

/** Password field with show/hide and a Caps Lock warning. */
export function PasswordInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const [show, setShow] = useState(false);
  const [caps, setCaps] = useState(false);
  const capsCheck = (e: KeyboardEvent<HTMLInputElement>) => setCaps(e.getModifierState?.('CapsLock') ?? false);
  return (
    <div className="lx-field">
      <label htmlFor="lx-pw">Password</label>
      <div className="lx-input"><Icon k="lock" />
        <input id="lx-pw" type={show ? 'text' : 'password'} autoComplete="current-password" placeholder="••••••••" value={value}
          onChange={(e) => onChange(e.target.value)} onKeyUp={capsCheck} onKeyDown={capsCheck} required />
        <button type="button" className="lx-eye" onClick={() => setShow((s) => !s)} aria-label={show ? 'Hide password' : 'Show password'} aria-pressed={show}>
          <Icon k={show ? 'eyeOff' : 'eye'} />
        </button>
      </div>
      {caps && <small className="lx-caps">Caps Lock is on</small>}
    </div>
  );
}

export function EmailInput({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <div className="lx-field">
      <label htmlFor="lx-email">Email</label>
      <div className="lx-input"><Icon k="mail" />
        <input id="lx-email" type="email" autoComplete="username" placeholder="you@company.com" value={value} onChange={(e) => onChange(e.target.value)} required autoFocus />
      </div>
    </div>
  );
}

export function GoButton({ busy, label, busyLabel }: { busy: boolean; label: string; busyLabel?: string }) {
  return <button className="lx-go" disabled={busy} type="submit"><span>{busy && busyLabel ? busyLabel : label}</span><Icon k="arrow" /></button>;
}

/** Email + password, then TOTP when the server requires it (default). First sign-in enrols an authenticator app. */
export function LoginPage() {
  const { refresh } = useAuth();
  const [step, setStep] = useState<'password' | 'verify' | 'enrol'>('password');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [enrol, setEnrol] = useState<{ secret: string; qr: string } | null>(null);
  const a = useAction();

  const submitPassword = (e: FormEvent) => {
    e.preventDefault();
    void a.run(async () => {
      const r = await post<{ mfa: 'verify' | 'enrol' | 'none' }>('/auth/login', { email, password });
      setPassword('');
      if (r.mfa === 'none') return refresh(); // authenticator step switched off on the server
      if (r.mfa === 'enrol') {
        const s = await post<{ secret: string; otpauth_uri: string }>('/auth/mfa/setup');
        setEnrol({ secret: s.secret, qr: await QRCode.toDataURL(s.otpauth_uri, { margin: 1, width: 200, color: { dark: '#0f2238', light: '#ffffff' } }) });
      }
      setStep(r.mfa);
    });
  };
  const submitCode = (e: FormEvent) => {
    e.preventDefault();
    void a.run(async () => {
      await post('/auth/mfa/verify', { code });
      await refresh();
    });
  };
  const onCode = step !== 'password';

  return (
    <AuthShell eyebrow="Company console" title="Your workforce," accent="always on record."
      points={['Every call recorded with its outcome', "Field visits verified at the customer's address", 'Company phones under policy, live']}>
      <ol className="lx-steps" aria-label="Sign-in steps">
        <li className={onCode ? 'done' : 'on'}><b>{onCode ? '✓' : '1'}</b>Account</li>
        <li className={onCode ? 'on' : ''}><b>2</b>Two-step</li>
      </ol>
      {step === 'password' ? (
        <>
          <h1>Welcome back</h1>
          <p className="lx-sub">Sign in with your company administrator account.</p>
          <ErrorBox error={a.error} />
          <form onSubmit={submitPassword} className="lx-form">
            <EmailInput value={email} onChange={setEmail} />
            <PasswordInput value={password} onChange={setPassword} />
            <GoButton busy={a.busy} label="Continue" busyLabel="Signing in…" />
          </form>
          <p className="lb-alt"><a href={`/password-forgot${currentWorkspace() ? `?workspace=${currentWorkspace()}` : ''}`}>Forgot password?</a></p>
        </>
      ) : (
        <>
          <h1>{step === 'enrol' ? 'Set up two-step sign-in' : 'Check your authenticator'}</h1>
          <p className="lx-sub">{step === 'enrol' ? 'Required once for every administrator. Scan, then enter the code.' : 'Enter the 6-digit code from your authenticator app.'}</p>
          <ErrorBox error={a.error} />
          <form onSubmit={submitCode} className="lx-form">
            {step === 'enrol' && enrol && (
              <div className="lx-enrol">
                <img src={enrol.qr} alt="Authenticator QR code" width={132} height={132} />
                <div>
                  <p>Open Google Authenticator, Microsoft Authenticator or similar and scan this code.</p>
                  <p className="muted">Can't scan? Enter this key: <span className="code">{enrol.secret}</span></p>
                </div>
              </div>
            )}
            <Field label="6-digit code">
              <input className="lx-otp" inputMode="numeric" pattern="\d{6}" maxLength={6} autoComplete="one-time-code" placeholder="000000"
                value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required autoFocus />
            </Field>
            <GoButton busy={a.busy} label="Verify" />
          </form>
        </>
      )}
    </AuthShell>
  );
}
