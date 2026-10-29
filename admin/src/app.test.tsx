import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import { api, ApiError, headers } from './lib/api';
import { AuthProvider } from './lib/auth';
import { App, NAV } from './App';
import { Badge, LogoPlaceholder } from './components/ui';

type Route = (url: string, init?: RequestInit) => { status: number; body: unknown } | undefined;
function mockFetch(route: Route) {
  const calls: { url: string; init?: RequestInit }[] = [];
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, init });
    const r = route(url, init) ?? { status: 404, body: { error: 'not_found' } };
    return new Response(r.body === undefined ? null : JSON.stringify(r.body), { status: r.status });
  }));
  return calls;
}

const ME = { id: 1, name: 'Admin', email: 'a@x.test', mfa_verified: true, permissions: ['devices.view', 'devices.approve'], roles: ['it_admin'],
  company: { id: 7, slug: 'alpha', name: 'Alpha', timezone: 'Asia/Kolkata' }, product_name: 'ROVEXY', realtime: null };

afterEach(() => { vi.unstubAllGlobals(); document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'; });

describe('api client', () => {
  it('sends CSRF header from cookie and never stores tokens in web storage', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D';
    expect(headers()['X-XSRF-TOKEN']).toBe('abc=');
    const calls = mockFetch((u) => (u.includes('csrf-cookie') ? { status: 204, body: undefined } : { status: 200, body: { ok: true } }));
    await api('POST', '/devices/1/revoke', { reason: 'lost' });
    const req = calls.find((c) => c.url === '/api/admin/devices/1/revoke')!;
    expect((req.init!.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
    expect(req.init!.credentials).toBe('same-origin');
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
  });

  it('surfaces server error codes', async () => {
    mockFetch(() => ({ status: 422, body: { error: 'approval_code_mismatch', message: 'The code does not match the code shown on the phone.' } }));
    await expect(api('GET', '/x')).rejects.toMatchObject({ code: 'approval_code_mismatch', status: 422 } satisfies Partial<ApiError>);
  });
});

describe('navigation', () => {
  it('lists every module and filters by permission', async () => {
    expect(NAV).toHaveLength(30);   // menu as of 29 Oct 2026 (+ Field planning) (portfolios, assignment, address report, payments, calling configuration…)
    mockFetch((u) => {
      if (u.endsWith('/auth/me')) return { status: 200, body: ME };
      if (u.endsWith('/branding')) return { status: 200, body: { display_name: 'Alpha', logo_url: null } };
      if (u.includes('/overview')) return { status: 200, body: {
        kpis: { calls: 0, connected: 0, talk_seconds: 0, avg_talk: 0, avg_ring: 0, callers: 0, customers: 0, prev: { calls: 0, connected: 0, talk_seconds: 0 } },
        status: { total: 1, online: 1, active: 0, idle: 0, on_call: 0, offline: 0, signed_out: 0 },
        devices: { pending: 2, approved: 1, non_compliant: 0, low_battery: 0, silent_24h: 0 }, recordings: { available: 0, pending: 0, failed: 0 },
        recordings_failed_7d: 0, follow_ups: { due_today: 0, overdue: 0 }, trend: [], hourly: [], leaderboard: [], outcomes: [], dispositions: [] } };
      return undefined;
    });
    render(<MemoryRouter><AuthProvider><App /></AuthProvider></MemoryRouter>);
    expect(await screen.findByText('Devices & approvals')).toBeInTheDocument();
    expect(screen.queryByText('Calls & recordings')).not.toBeInTheDocument();     // lacks calls.view
    expect(await screen.findByText(/2 phone\(s\) waiting for approval/)).toBeInTheDocument();
    expect(screen.getByLabelText('Logo placeholder')).toBeInTheDocument();          // no logo uploaded → neutral placeholder
  });
});

describe('login', () => {
  it('requires MFA enrolment before the console opens', async () => {
    let verified = false;
    mockFetch((u, init) => {
      if (u.endsWith('/auth/me')) return verified ? { status: 200, body: ME } : { status: 401, body: { error: 'unauthenticated' } };
      if (u.includes('csrf-cookie')) return { status: 204, body: undefined };
      if (u.endsWith('/auth/login')) return { status: 200, body: { mfa: 'enrol' } };
      if (u.endsWith('/auth/mfa/setup')) return { status: 200, body: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP' } };
      if (u.endsWith('/auth/mfa/verify')) { verified = JSON.parse(String(init!.body)).code === '123456'; return verified ? { status: 200, body: ME } : { status: 422, body: { error: 'invalid_code' } }; }
      if (u.endsWith('/branding')) return { status: 200, body: { display_name: 'Alpha', logo_url: null } };
      return { status: 200, body: { devices: { pending: 0, approved: 0, online: 0, non_compliant: 0, on_call: 0 }, calls_today: { total: 0, answered: 0, talk_seconds: 0 }, recordings_failed_7d: 0, recordings_pending: 0, follow_ups: { due_today: 0, overdue: 0 }, trend_7d: [{ date: '2026-10-01', calls: 3, connected: 1, talk_seconds: 42 }], employees_today: [] } };
    });
    render(<MemoryRouter><AuthProvider><App /></AuthProvider></MemoryRouter>);
    fireEvent.change(await screen.findByLabelText('Email'), { target: { value: 'a@x.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'pw' } });
    fireEvent.click(screen.getByText('Continue'));
    expect(await screen.findByText(/JBSWY3DPEHPK3PXP/)).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('6-digit code'), { target: { value: '000000' } });
    fireEvent.click(screen.getByText('Verify'));
    expect(await screen.findByRole('alert')).toHaveTextContent(/not valid/);
    fireEvent.change(screen.getByLabelText('6-digit code'), { target: { value: '123456' } });
    fireEvent.click(screen.getByText('Verify'));
    await waitFor(() => expect(screen.getByText('Sign out')).toBeInTheDocument());
  });
});

describe('ui', () => {
  it('maps statuses to accessible labelled badges', () => {
    render(<><Badge value="revoked" /><Badge value="requires_setup" /><LogoPlaceholder name="Demo Collections" /></>);
    expect(screen.getByText('revoked')).toHaveClass('bad');
    expect(screen.getByText('requires setup')).toHaveClass('warn');
    expect(screen.getByLabelText('Logo placeholder')).toHaveTextContent('DC');
  });
});
