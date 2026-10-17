// ROVEXY live demo: the Android app (android/app/src/main/kotlin/.../ui/*.kt) rebuilt for the browser.
// Same screens, labels, colours and rules as the Kotlin code; the server is replaced by in-memory sample data.
// Nothing here talks to a ROVEXY server. Only map tiles (OpenStreetMap) and road routes (OSRM) are fetched.
import { html, render, useState, useEffect, useRef, useMemo, useErrorBoundary } from './vendor/preact-htm.js';
import * as D from './data.js';

const L = window.L;
const params = new URLSearchParams(location.search);
const TOUR = params.has('tour');   // home page "How it works": scenes played by script, no visitor input
const EMBED = params.has('embed') || TOUR;

// ───────────────────────────── design (Design.kt) ─────────────────────────────
const Ds = { Navy: '#1F3A5F', NavyDeep: '#0B1A2E', NavyInk: '#07111F', Gold: '#C9A35E', GoldSoft: '#E8D3A6', Bg: '#F3F5F9', Ink: '#15202E', Muted: '#66768A',
  Line: '#E3E8EF', Green: '#16A34A', Blue: '#2563EB', Amber: '#B7791F', Teal: '#0F766E', Red: '#DC2626' };
const P = {
  Call: 'M20.01,15.38c-1.23,0 -2.42,-0.2 -3.53,-0.56 -0.35,-0.12 -0.74,-0.03 -1.01,0.24l-1.57,1.97c-2.83,-1.35 -5.48,-3.9 -6.89,-6.83l1.95,-1.66c0.27,-0.28 0.35,-0.67 0.24,-1.02 -0.37,-1.11 -0.56,-2.3 -0.56,-3.53 0,-0.54 -0.45,-0.99 -0.99,-0.99H4.19C3.65,3 3,3.24 3,3.99 3,13.28 10.73,21 20.01,21c0.71,0 0.99,-0.63 0.99,-1.18v-3.45c0,-0.54 -0.45,-0.99 -0.99,-0.99z',
  Mic: 'M12,14c1.66,0 2.99,-1.34 2.99,-3L15,5c0,-1.66 -1.34,-3 -3,-3S9,3.34 9,5v6c0,1.66 1.34,3 3,3zM17.3,11c0,3 -2.54,5.1 -5.3,5.1S6.7,14 6.7,11L5,11c0,3.41 2.72,6.23 6,6.72L11,21h2v-3.28c3.28,-0.48 6,-3.3 6,-6.72h-1.7z',
  MicOff: 'M19,11h-1.7c0,0.74 -0.16,1.43 -0.43,2.05l1.23,1.23c0.56,-0.98 0.9,-2.09 0.9,-3.28zM14.98,11.17c0,-0.06 0.02,-0.11 0.02,-0.17L15,5c0,-1.66 -1.34,-3 -3,-3S9,3.34 9,5v0.18l5.98,5.99zM4.27,3L3,4.27l6.01,6.01L9,11c0,1.66 1.33,3 2.99,3 0.22,0 0.44,-0.03 0.65,-0.08l1.66,1.66c-0.71,0.33 -1.5,0.52 -2.31,0.52 -2.76,0 -5.3,-2.1 -5.3,-5.1L5,11c0,3.41 2.72,6.23 6,6.72L11,21h2v-3.28c0.91,-0.13 1.77,-0.45 2.54,-0.9L19.73,21 21,19.73 4.27,3z',
  Speaker: 'M3,9v6h4l5,5V4L7,9H3zM16.5,12c0,-1.77 -1.02,-3.29 -2.5,-4.03v8.05c1.48,-0.73 2.5,-2.25 2.5,-4.02zM14,3.23v2.06c2.89,0.86 5,3.54 5,6.71s-2.11,5.85 -5,6.71v2.06c4.01,-0.91 7,-4.49 7,-8.77s-2.99,-7.86 -7,-8.77z',
  Keypad: 'M12,19c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM6,1c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM6,7c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM6,13c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM18,5c1.1,0 2,-0.9 2,-2s-0.9,-2 -2,-2 -2,0.9 -2,2 0.9,2 2,2zM12,13c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM18,13c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM18,7c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM12,7c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2zM12,1c-1.1,0 -2,0.9 -2,2s0.9,2 2,2 2,-0.9 2,-2 -0.9,-2 -2,-2z',
  Search: 'M15.5,14h-0.79l-0.28,-0.27C15.41,12.59 16,11.11 16,9.5 16,5.91 13.09,3 9.5,3S3,5.91 3,9.5 5.91,16 9.5,16c1.61,0 3.09,-0.59 4.23,-1.57l0.27,0.28v0.79l5,4.99L20.49,19l-4.99,-5zM9.5,14C7.01,14 5,11.99 5,9.5S7.01,5 9.5,5 14,7.01 14,9.5 11.99,14 9.5,14z',
  Refresh: 'M17.65,6.35C16.2,4.9 14.21,4 12,4c-4.42,0 -7.99,3.58 -7.99,8s3.57,8 7.99,8c3.73,0 6.84,-2.55 7.73,-6h-2.08c-0.82,2.33 -3.04,4 -5.65,4 -3.31,0 -6,-2.69 -6,-6s2.69,-6 6,-6c1.66,0 3.14,0.69 4.22,1.78L13,11h7V4l-2.35,2.35z',
  Clock: 'M11.99,2C6.47,2 2,6.48 2,12s4.47,10 9.99,10C17.52,22 22,17.52 22,12S17.52,2 11.99,2zM12,20c-4.42,0 -8,-3.58 -8,-8s3.58,-8 8,-8 8,3.58 8,8 -3.58,8 -8,8zM12.5,7H11v6l5.25,3.15 0.75,-1.23 -4.5,-2.67z',
  Check: 'M12,2C6.48,2 2,6.48 2,12s4.48,10 10,10 10,-4.48 10,-10S17.52,2 12,2zM10,17l-5,-5 1.41,-1.41L10,14.17l7.59,-7.59L19,8l-9,9z',
  Event: 'M17,12h-5v5h5v-5zM16,1v2H8V1H6v2H5c-1.11,0 -1.99,0.9 -1.99,2L3,19c0,1.1 0.89,2 2,2h14c1.1,0 2,-0.9 2,-2V5c0,-1.1 -0.9,-2 -2,-2h-1V1h-2zM19,19H5V8h14v11z',
  Logout: 'M17,7l-1.41,1.41L18.17,11H8v2h10.17l-2.58,2.58L17,17l5,-5zM4,5h8V3H4c-1.1,0 -2,0.9 -2,2v14c0,1.1 0.9,2 2,2h8v-2H4V5z',
  Shield: 'M12,1L3,5v6c0,5.55 3.84,10.74 9,12 5.16,-1.26 9,-6.45 9,-12V5l-9,-4z',
  Warning: 'M1,21h22L12,2 1,21zM13,18h-2v-2h2v2zM13,14h-2v-4h2v4z',
  Place: 'M12,2C8.13,2 5,5.13 5,9c0,5.25 7,13 7,13s7,-7.75 7,-13c0,-3.87 -3.13,-7 -7,-7zM12,11.5c-1.38,0 -2.5,-1.12 -2.5,-2.5s1.12,-2.5 2.5,-2.5 2.5,1.12 2.5,2.5 -1.12,2.5 -2.5,2.5z',
  Filter: 'M10,18h4v-2h-4v2zM3,6v2h18V6H3zM6,13h12v-2H6v2z',
  ArrowBack: 'M20,11H7.83l5.59,-5.59L12,4l-8,8 8,8 1.41,-1.41L7.83,13H20v-2z',
  Close: 'M19,6.41L17.59,5 12,10.59 6.41,5 5,6.41 10.59,12 5,17.59 6.41,19 12,13.41 17.59,19 19,17.59 13.41,12z',
  FitAll: 'M15,3l2.3,2.3 -2.89,2.87 1.42,1.42L18.7,6.7 21,9V3zM3,9l2.3,-2.3 2.87,2.89 1.42,-1.42L6.7,5.3 9,3H3zM9,21l-2.3,-2.3 2.89,-2.87 -1.42,-1.42L5.3,17.3 3,15v6zM21,15l-2.3,2.3 -2.87,-2.89 -1.42,1.42 2.89,2.87L15,21h6z',
  Route: 'M19,15.18V7c0,-2.21 -1.79,-4 -4,-4s-4,1.79 -4,4v10c0,1.1 -0.9,2 -2,2s-2,-0.9 -2,-2V8.82C8.16,8.4 9,7.3 9,6c0,-1.66 -1.34,-3 -3,-3S3,4.34 3,6c0,1.3 0.84,2.4 2,2.82V17c0,2.21 1.79,4 4,4s4,-1.79 4,-4V7c0,-1.1 0.9,-2 2,-2s2,0.9 2,2v8.18c-1.16,0.41 -2,1.51 -2,2.82c0,1.66 1.34,3 3,3s3,-1.34 3,-3C21,16.7 20.16,15.6 19,15.18z',
  Navigate: 'M12,2L4.5,20.29l0.71,0.71L12,18l6.79,3 0.71,-0.71z',
  MyLocation: 'M12,8c-2.21,0 -4,1.79 -4,4s1.79,4 4,4 4,-1.79 4,-4 -1.79,-4 -4,-4zM20.94,11c-0.46,-4.17 -3.77,-7.48 -7.94,-7.94V1h-2v2.06C6.83,3.52 3.52,6.83 3.06,11H1v2h2.06c0.46,4.17 3.77,7.48 7.94,7.94V23h2v-2.06c4.17,-0.46 7.48,-3.77 7.94,-7.94H23v-2h-2.06zM12,19c-3.87,0 -7,-3.13 -7,-7s3.13,-7 7,-7 7,3.13 7,7 -3.13,7 -7,7z',
  People: 'M16,11c1.66,0 2.99,-1.34 2.99,-3S17.66,5 16,5c-1.66,0 -3,1.34 -3,3s1.34,3 3,3zM8,11c1.66,0 2.99,-1.34 2.99,-3S9.66,5 8,5C6.34,5 5,6.34 5,8s1.34,3 3,3zM8,13c-2.33,0 -7,1.17 -7,3.5V19h14v-2.5c0,-2.33 -4.67,-3.5 -7,-3.5zM16,13c-0.29,0 -0.62,0.02 -0.97,0.05 1.16,0.84 1.97,1.97 1.97,3.45V19h6v-2.5c0,-2.33 -4.67,-3.5 -7,-3.5z',
  CallEnd: 'M12,9c-1.6,0 -3.15,0.25 -4.6,0.72v3.1c0,0.39 -0.23,0.74 -0.56,0.9 -0.98,0.49 -1.87,1.12 -2.66,1.85 -0.18,0.18 -0.43,0.28 -0.7,0.28 -0.28,0 -0.53,-0.11 -0.71,-0.29L0.29,13.08c-0.18,-0.17 -0.29,-0.42 -0.29,-0.7 0,-0.28 0.11,-0.53 0.29,-0.71C3.34,8.78 7.46,7 12,7s8.66,1.78 11.71,4.67c0.18,0.18 0.29,0.43 0.29,0.71 0,0.28 -0.11,0.53 -0.29,0.71l-2.48,2.48c-0.18,0.18 -0.43,0.29 -0.71,0.29 -0.27,0 -0.52,-0.11 -0.7,-0.28 -0.79,-0.74 -1.69,-1.36 -2.67,-1.85 -0.33,-0.16 -0.56,-0.5 -0.56,-0.9v-3.1C15.15,9.25 13.6,9 12,9z',
};
const Icon = ({ n, s = 24, c, style }) => html`<svg class="ic" width=${s} height=${s} viewBox="0 0 24 24" style=${{ color: c, ...style }} aria-hidden="true"><path d=${P[n]} fill="currentColor"/></svg>`;
const alpha = (hex, a) => hex + Math.round(a * 255).toString(16).padStart(2, '0');

// ───────────────────────────── formatting ─────────────────────────────
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const p2 = (n) => String(n).padStart(2, '0');
// ponytail: sample numbers always masked, like the app (last 4 digits only)
const maskNo = (n) => n ? '•••••• ' + String(n).slice(-4) : n;
const toD = (s) => s == null ? null : s instanceof Date ? s : typeof s === 'number' ? new Date(s) : new Date(s.length === 10 ? s + 'T00:00:00' : s);
const isoDay = (d) => { d = toD(d); return `${d.getFullYear()}-${p2(d.getMonth() + 1)}-${p2(d.getDate())}`; };
const todayIso = () => isoDay(new Date());
const plusDays = (n) => isoDay(D.day(n));
const hm = (d) => { let h = d.getHours(); const ap = h < 12 ? 'am' : 'pm'; h = h % 12 || 12; return `${h}:${p2(d.getMinutes())} ${ap}`; };
const fmtDate = (s) => { const d = toD(s); return d ? `${d.getDate()} ${MON[d.getMonth()]} ${d.getFullYear()}` : '—'; };
const fmtWhen = (s) => { const d = toD(s); return d ? `${d.getDate()} ${MON[d.getMonth()]}, ${hm(d)}` : '—'; };
const fmtFull = (s) => { const d = toD(s); return `${WD[d.getDay()]} ${d.getDate()} ${MON[d.getMonth()]}, ${hm(d)}`; };
const inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
const money = (v) => v == null ? '—' : inr.format(v);
const mmss = (s) => s >= 3600 ? `${Math.floor(s / 3600)}h ${p2(Math.floor(s / 60) % 60)}m` : `${Math.floor(s / 60)}:${p2(s % 60)}`;
const cap = (s) => s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
const daysSince = (iso) => Math.round((D.day(0) - toD(iso)) / D.DAY);
const initialsOf = (name) => name.split(' ').filter((w) => /[\p{L}\p{N}]/u.test(w[0] || '')).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '•';
const km = (m) => m < 1000 ? `${Math.round(m)} m` : `${(m / 1000).toFixed(1)} km`;
const eta = (s) => { const m = Math.max(1, Math.floor(s / 60)); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min`; };
function distM(a, b) {   // haversine, metres (Location.distanceBetween)
  const R = 6371000, r = Math.PI / 180, dLa = (b.lat - a.lat) * r, dLo = (b.lng - a.lng) * r;
  const x = Math.sin(dLa / 2) ** 2 + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLo / 2) ** 2;
  return 2 * R * Math.asin(Math.sqrt(x));
}
const outcomeLabel = (c) => D.OUTCOMES.find((o) => o.code === c)?.label || cap(c.replace(/_/g, ' ').toLowerCase());

// ───────────────────────────── state ─────────────────────────────
const defaultFilter = () => ({ query: '', status: '', portfolio: '', dpd: '', amount: '', calls: '', callback: '', assigned: '', tile: '', sort: 'dpd' });
function fresh(start) {
  const dash = start === 'dashboard';
  return {
    screen: dash ? 'dashboard' : 'connect', company: dash ? D.COMPANY.name : 'ROVEXY', server: D.COMPANY.server, workspace: D.COMPANY.workspace,
    approvalCode: '', ended: null, policyFailing: [], busy: false, error: null,
    cases: D.sampleCases(), today: { ...D.TODAY }, sync: { online: true, last: Date.now() - 3 * 60000, pending: 0 },
    filter: defaultFilter(), profileId: null, profileTab: 0, profileDialog: null, fieldOpen: false, fieldFocus: null,
    call: null, dialer: null, chat: D.sampleChat(), chatOpen: null, unread: false, notif: null,
    pendingMedia: null, activeMedia: null, sys: null, toast: null,
    perms: true, recordingOn: true, recordingGate: false, recordingProven: true, locPerm: true,
    fix: { ...D.HOME_FIX, time: Date.now() }, failPolicy: false, done: {}, panel: false,
  };
}
const S = fresh(params.get('start') || (EMBED ? 'dashboard' : 'connect'));
let redraw = () => {};
const set = (patch) => { Object.assign(S, typeof patch === 'function' ? patch(S) : patch); redraw(); };
const timers = new Set();
const later = (ms, fn) => { const t = setTimeout(() => { timers.delete(t); fn(); }, ms); timers.add(t); return t; };
const mark = (k) => { if (!S.done[k]) set({ done: { ...S.done, [k]: true } }); };
const toast = (text) => { set({ toast: { text, id: Date.now() } }); later(2600, () => S.toast?.text === text && set({ toast: null })); };
const missingPerms = () => S.perms ? [] : ['Phone', 'Call log', 'Music and audio'];
const caseById = (id) => S.cases.find((c) => c.id === id);
const updateCase = (id, fn) => set({ cases: S.cases.map((c) => c.id === id ? fn({ ...c }) : c) });
const fixOrNull = () => S.locPerm ? S.fix : null;

/** CaseRow (Cases.kt): what the list shows, derived exactly like the server-sent summary. */
function row(c) {
  const sum = (st) => c.payments.filter((p) => p.status === st).reduce((a, p) => a + p.amount, 0);
  const verified = sum('verified'), pending = sum('pending');
  const overdue = Math.max(0, c.overdueUploaded - verified);
  const dpd = c.oldestDue && overdue > 0 ? Math.max(0, daysSince(c.oldestDue)) : null;
  const pr = c.promises[0];
  const lastPay = c.payments.filter((p) => p.status !== 'rejected').sort((a, b) => b.paidOn.localeCompare(a.paidOn))[0];
  return { c, verified, pending, overdue, dpd, outstanding: c.outstanding, remaining: c.outstanding - verified,
    promiseAmount: pr?.amount, promiseDate: pr?.date, promiseStatus: pr?.status,
    nextCallback: c.callback?.at || null, callbackDate: c.callback ? isoDay(c.callback.at) : null,
    lastPaymentOn: lastPay?.paidOn || null, lastPaymentAmount: lastPay?.amount || null, lat: c.lat, lng: c.lng };
}

// ───────────────────────────── filters (Cases.kt applyCaseFilter) ─────────────────────────────
const dpdBucket = (d) => d == null || d === 0 ? '0' : d <= 30 ? '1-30' : d <= 60 ? '31-60' : d <= 90 ? '61-90' : '90+';
const isActive = (f) => ['status', 'portfolio', 'dpd', 'amount', 'calls', 'callback', 'assigned', 'tile'].some((k) => f[k]);
const by = (fn, desc) => (a, b) => { const x = fn(a), y = fn(b); return (x < y ? -1 : x > y ? 1 : 0) * (desc ? -1 : 1); };
function applyCaseFilter(all, f, here) {
  const today = todayIso(), in7 = plusDays(7), q = f.query.trim().toLowerCase(), digits = q.replace(/\D/g, '');
  const list = all.filter((r) => {
    const o = r.overdue || 0;
    return (!q || r.c.name.toLowerCase().includes(q) || r.c.ref.toLowerCase().includes(q) || (digits.length >= 4 && (r.c.phone.endsWith(digits) || (r.c.alt || '').endsWith(digits)))) &&
      (!f.status || r.c.status === f.status) &&
      (!f.portfolio || (r.c.portfolio || 'Added by hand') === f.portfolio) &&
      (!f.dpd || dpdBucket(r.dpd) === f.dpd) &&
      (!f.amount || (f.amount === 'none' ? o <= 0 : f.amount === 'lt10k' ? o > 0 && o < 10000 : f.amount === '10-50k' ? o >= 10000 && o <= 50000 : o > 50000)) &&
      (!f.calls || (f.calls === 'none' ? r.c.calls === 0 : f.calls === 'no_connect' ? r.c.calls > 0 && r.c.connected === 0 : r.c.connected > 0)) &&
      (!f.callback || (f.callback === 'today' ? r.callbackDate === today : f.callback === 'overdue' ? !!r.callbackDate && r.callbackDate < today : f.callback === 'scheduled' ? !!r.nextCallback : !r.nextCallback)) &&
      (!f.assigned || (f.assigned === 'pool') === r.c.pool) &&
      (!f.tile || ({
        overdue: (r.dpd || 0) > 0, due_today: r.c.dueDate === today, upcoming: r.c.dueDate >= today && r.c.dueDate <= in7,
        ptp_today: r.promiseStatus === 'open' && r.promiseDate === today, ptp_broken: r.promiseStatus === 'broken',
        cb_today: !!r.callbackDate && r.callbackDate <= today, not_contacted: r.c.calls === 0, paid_today: r.lastPaymentOn === today, verified: r.verified > 0,
      })[f.tile] !== false);
  });
  const MAX = Number.MAX_SAFE_INTEGER;
  const sorts = {
    amount: by((r) => r.overdue ?? -1, true), outstanding: by((r) => r.outstanding ?? -1, true), dpd_asc: by((r) => r.dpd ?? MAX),
    due: by((r) => r.c.dueDate || '9999'), callback: by((r) => r.nextCallback ?? MAX),
    promise: (a, b) => { const k = (r) => r.promiseStatus === 'open' ? 0 : r.promiseStatus === 'broken' ? 1 : 2; return k(a) - k(b) || (a.promiseDate || '9999').localeCompare(b.promiseDate || '9999'); },
    last_call: by((r) => r.c.lastCallAt ?? -1), recent_call: by((r) => r.c.lastCallAt ?? -1, true),
    nearest: by((r) => here && r.lat != null ? distM(here, r) : Infinity), paid: by((r) => r.lastPaymentOn || '0000', true),
    name: by((r) => r.c.name.toLowerCase()),
  };
  return list.sort(sorts[f.sort] || ((a, b) => ((b.dpd ?? -1) - (a.dpd ?? -1)) || ((b.overdue || 0) - (a.overdue || 0))));
}

// ───────────────────────────── shared pieces (Design.kt) ─────────────────────────────
const PALETTE = ['#1F3A5F', '#0F766E', '#7C3AED', '#B7791F', '#2563EB', '#BE185D'];
const jhash = (s) => { let h = 0; for (let i = 0; i < s.length; i++) h = (Math.imul(31, h) + s.charCodeAt(i)) | 0; return h; };
const Avatar = ({ name, size = 44 }) => {
  const c = PALETTE[(jhash(name) & 0x7fffffff) % PALETTE.length];
  return html`<div class="avatar" style=${{ width: size, height: size, fontSize: size * 0.36, background: `linear-gradient(to bottom right, ${alpha(c, 0.85)}, ${c})` }}>${initialsOf(name)}</div>`;
};
const IconTile = ({ n, tint, size = 36 }) => html`<div class="itile" style=${{ width: size, height: size, borderRadius: size / 3, background: alpha(tint, 0.12) }}><${Icon} n=${n} s=${size * 0.55} c=${tint}/></div>`;
const statusColor = (s) => ({ new: Ds.Blue, follow_up: Ds.Amber, in_progress: Ds.Teal, closed: Ds.Green, paid: Ds.Green, do_not_call: Ds.Red })[s] || Ds.Muted;
const StatusTag = ({ status }) => html`<span class="stag" style=${{ color: statusColor(status), background: alpha(statusColor(status), 0.10) }}>${cap(status.replace(/_/g, ' '))}</span>`;
const Tag = ({ text, c }) => html`<span class="stag" style=${{ color: c, background: alpha(c, 0.10) }}>${cap(text.replace(/_/g, ' '))}</span>`;
const Pill = ({ text, color = Ds.Navy, selected = true, onClick }) => html`<button type="button" class="pill" onClick=${onClick}
  style=${{ background: selected ? color : '#fff', borderColor: selected ? color : Ds.Line, color: selected ? '#fff' : Ds.Ink }}>${text}</button>`;
const Spinner = ({ s = 20, c = Ds.Navy, w = 2 }) => html`<span class="spin" style=${{ width: s, height: s, borderWidth: w, borderColor: alpha(c, 0.25), borderTopColor: c }}></span>`;
const SurfaceCard = ({ onClick, children, style }) => html`<div class=${'scard' + (onClick ? ' click' : '')} style=${style} onClick=${onClick}>${children}</div>`;
const Banner = ({ n, tint, title, body, action, onClick }) => html`<${SurfaceCard} onClick=${onClick}><div class="banner"><${IconTile} n=${n} tint=${tint} size=${40}/>
  <div class="grow"><div class="b-t">${title}</div><div class="small">${body}</div></div>${action && html`<span class="b-a" style=${{ color: tint }}>${action}</span>`}</div><//>`;
const PrimaryButton = ({ text, onClick, busy, enabled = true, color = Ds.Navy, full, style }) => html`<button type="button" class=${'pbtn' + (full ? ' full' : '')} disabled=${!enabled || busy}
  style=${{ background: (!enabled || busy) ? alpha(color, 0.4) : color, ...style }} onClick=${onClick}>${busy ? html`<${Spinner} c="#fff"/>` : text}</button>`;
const TextBtn = ({ children, onClick, color, disabled, style }) => html`<button type="button" class="tbtn" disabled=${disabled} style=${{ color: disabled ? undefined : color, ...style }} onClick=${onClick}>${children}</button>`;
const OutBtn = ({ children, onClick, disabled, style, cls = '' }) => html`<button type="button" class=${'obtn ' + cls} disabled=${disabled} style=${style} onClick=${onClick}>${children}</button>`;

/** OutlinedTextField with a floating label. */
function Field({ value, onInput, label, disabled, password, reveal, type = 'text', lead, trailing, area, rounded = true, inputMode }) {
  const props = { value, disabled, placeholder: ' ', onInput: (e) => onInput(e.target.value), inputmode: inputMode };
  return html`<label class=${'tf' + (lead ? ' lead' : '') + (disabled ? ' dis' : '') + (area ? ' area' : '') + (rounded ? '' : ' sq')}>
    ${lead && html`<${Icon} n=${lead} s=${20} c=${Ds.Muted} style=${{ marginLeft: 12 }}/>`}
    ${area ? html`<textarea rows="3" ...${props}></textarea>` : html`<input type=${password && !reveal ? 'password' : type} ...${props}/>`}
    <span class="tf-l">${label}</span>${trailing}</label>`;
}

/** AlertDialog (Material 3). */
function Dialog({ icon, title, children, confirm, dismiss, onDismiss, wide }) {
  return html`<div class="scrim" onClick=${(e) => e.target === e.currentTarget && onDismiss && onDismiss()}>
    <div class=${'dlg' + (wide ? ' wide' : '')} role="dialog" aria-modal="true">
      ${icon && html`<div class="dlg-ic">${icon}</div>`}
      ${title && html`<h2 class=${'dlg-t' + (icon ? ' c' : '')}>${title}</h2>`}
      <div class="dlg-b">${children}</div>
      <div class="dlg-a">${dismiss}${confirm}</div>
    </div></div>`;
}

/** Native date / date-time picker behind a row (Android DatePickerDialog + TimePickerDialog). */
function PickRow({ value, placeholder, tint = Ds.Amber, kind = 'datetime-local', min, max, onPick, onClear, text, icon = true }) {
  const ref = useRef();
  const open = () => { const i = ref.current; try { i.showPicker(); } catch { i.focus(); i.click(); } };
  return html`<div class="pickrow" onClick=${open}>
    ${icon && html`<${Icon} n="Event" s=${20} c=${tint}/>`}
    <span class="grow" style=${{ color: value ? Ds.Ink : Ds.Muted }}>${text ?? placeholder}</span>
    ${value && onClear && html`<span class="lnk red" onClick=${(e) => { e.stopPropagation(); onClear(); }}>Clear</span>`}
    <input ref=${ref} class="ghost-input" type=${kind} min=${min} max=${max} tabindex="-1" aria-label=${placeholder}
      onChange=${(e) => e.target.value && onPick(e.target.value)} onClick=${(e) => e.stopPropagation()}/></div>`;
}
const nowLocal = (plusMin = 0) => { const d = new Date(Date.now() + plusMin * 60000); return `${isoDay(d)}T${p2(d.getHours())}:${p2(d.getMinutes())}`; };

function Logo({ name, size = 72 }) {
  return html`<div class="logo-ph" style=${{ width: size, height: size, fontSize: size * 0.33 }} aria-label="Logo placeholder">${initialsOf(name)}</div>`;
}

// ───────────────────────────── app root (Screens.kt AppRoot) ─────────────────────────────
function AppRoot() {
  const s = S;
  const [crash, recover] = useErrorBoundary((e) => console.error(e));
  if (crash) return html`<div class="app"><${CenterMessage} n="Warning" tint=${Ds.Red} title="The demo hit a snag" body="Something in this demo went wrong. Reset it to start again.">
    <${PrimaryButton} text="Reset the demo" onClick=${() => { resetDemo(); recover(); }}/><//></div>`;
  let body;
  if (s.screen === 'connect' || s.screen === 'login') body = html`<${AuthLayout} mode=${s.screen}/>`;
  else if (s.screen === 'pending') body = html`<${PendingScreen}/>`;
  else if (s.screen === 'ended') body = html`<${EndedScreen}/>`;
  else if (s.screen === 'policy') body = html`<${PolicyScreen}/>`;
  else if (s.screen === 'locked') body = html`<${CenterMessage} n="Shield" tint=${Ds.Amber} title="Locked" body="Offline access period has ended. Connect to the internet to continue.">
      <${PrimaryButton} text="Retry" busy=${s.busy} onClick=${retryLocked}/><//>`;
  else body = html`<${Dashboard}/>`;
  return html`<div class="app">
    ${s.activeMedia && html`<${MonitoringBanner}/>`}
    <div class="app-body">${body}</div>
    ${s.pendingMedia && html`<${MediaRequestDialog} r=${s.pendingMedia}/>`}
    ${s.sys && html`<${SystemDialog} d=${s.sys}/>`}
    ${s.dialer && html`<${SimDialer}/>`}
    ${s.notif && html`<${HeadsUp} n=${s.notif}/>`}
    ${s.toast && html`<div class="toast" key=${s.toast.id}>${s.toast.text}</div>`}
  </div>`;
}

// ───────────────────────────── sign-in / connect ─────────────────────────────
function connect(server, workspace) {
  const url = server.trim();
  if (!url) return set({ error: 'Enter the server address your office gave you.' });
  if (!/^https?:\/\/[^\s/]+\.[^\s]+$/i.test(url) && !/^[\w-]+(\.[\w-]+)+$/.test(url)) return set({ error: 'That is not a server address. It looks like https://yourcompany.rovexy.app' });
  set({ busy: true, error: null });
  later(1100, () => {
    set({ busy: false, server: url.startsWith('http') ? url : 'https://' + url, workspace, company: D.COMPANY.name, screen: 'pending',
      approvalCode: String(100000 + Math.floor(Math.random() * 899999)).replace(/(\d{3})(\d{3})/, '$1 $2') });
    mark('connect');
    later(9000, () => S.screen === 'pending' && approvePhone());
  });
}
function approvePhone() { if (S.screen === 'pending') { set({ screen: 'login', error: null }); toast('Phone approved — you can sign in'); } }
function login(user, pass) {
  if (!user.trim() || !pass) return set({ error: 'Enter your User ID and password.' });
  set({ busy: true, error: null });
  later(900, () => {
    if (pass !== 'demo') return set({ busy: false, error: 'Wrong User ID or password.' });
    if (S.failPolicy) return set({ busy: false, screen: 'policy', policyFailing: ['Screen lock', 'Google Play Protect'] });
    enterWork();
  });
}
function enterWork() {
  set({ busy: false, screen: 'dashboard', error: null, sync: { ...S.sync, last: Date.now() } });
  mark('signin');
  if (!S.chat[0].messages.some((m) => m.id === 3)) later(14000, () => S.screen === 'dashboard' && supervisorMessage('Reminder: upload slips for every cash payment today. 👍', 3));
}
function retryLocked() { set({ busy: true }); later(900, () => S.sync.online ? enterWork() : set({ busy: false })); }
function logout() { set({ busy: true }); later(500, () => set({ busy: false, screen: 'login', profileId: null, fieldOpen: false, call: null, chatOpen: null, activeMedia: null })); }

function AuthLayout({ mode }) {
  const login_ = mode === 'login';
  const [server, setServer] = useState(S.server);
  const [workspace, setWorkspace] = useState(S.workspace);
  const [user, setUser] = useState(login_ ? 'priya' : '');
  const [pass, setPass] = useState(login_ ? 'demo' : '');
  const [reveal, setReveal] = useState(false);
  const navy = '#072654', navyBrand = '#0A2F6B', gold = '#C89B2D', goldHi = '#E0B84D';
  return html`<div class="auth" style=${{ background: navy }}>
    <div class="auth-top">
      <img src="/brand/rovexy-logo-dark.svg" alt="ROVEXY — Your Workforce. In Motion." width="200"/>
      <div class="kick" style=${{ color: goldHi }}><i style=${{ background: goldHi }}></i>${login_ ? 'FIELD STAFF' : 'DEVICE SETUP'}</div>
      <div class="auth-h">Your workforce,</div><div class="auth-h" style=${{ color: goldHi }}>always on record.</div>
      ${['Calls recorded with their outcome', 'Visits verified at the customer\'s address'].map((p) => html`<div class="auth-pt"><span style=${{ color: goldHi, background: alpha(goldHi, 0.15) }}>✓</span>${p}</div>`)}
    </div>
    <div class="auth-sheet">
      <div class="grab-gold" style=${{ background: `linear-gradient(90deg, ${gold}, ${goldHi})` }}></div>
      <div class="co-row"><div class="co-logo" style=${{ background: navyBrand }}><${Logo} name=${S.company} size=${40}/></div>
        <div class="grow"><div class="small">${login_ ? 'Signing in to' : 'Connecting to'}</div><div class="co-name">${S.company}</div></div></div>
      <div class="auth-title" style=${{ color: navy }}>${login_ ? 'Welcome back' : 'Connect this phone'}</div>
      <div class="auth-sub">${login_ ? 'Use the User ID and password from your office.' : 'Enter the server address your office gave you.'}</div>
      <${Field} value=${server} onInput=${setServer} label="Server URL" disabled=${login_} lead="Shield" inputMode="url"/>
      ${!login_ && html`<${Field} value=${workspace} onInput=${setWorkspace} label="Company workspace (if given)" lead="People"/>`}
      ${login_ && html`<${Field} value=${user} onInput=${setUser} label="User ID" lead="People"/>
        <${Field} value=${pass} onInput=${setPass} label="Password" password reveal=${reveal} lead="Shield"
          trailing=${html`<button type="button" class="tbtn" style=${{ color: navyBrand, fontSize: 13 }} onClick=${(e) => { e.preventDefault(); setReveal(!reveal); }}>${reveal ? 'Hide' : 'Show'}</button>`}/>`}
      ${S.error && html`<div class="err-box">${S.error}</div>`}
      <${PrimaryButton} full text=${login_ ? 'Sign in  →' : 'Connect / Register  →'} busy=${S.busy} color=${navyBrand} style=${{ marginTop: 16 }}
        onClick=${() => login_ ? login(user, pass) : connect(server, workspace)}/>
      <div class="auth-foot"><${Icon} n="Shield" s=${14} c=${gold}/>Company-managed device · activity is visible to you under Monitoring</div>
      ${login_ && html`<div class="dhint">Demo login is filled in · password <b>demo</b></div>`}
    </div></div>`;
}

function CenterMessage({ n, tint, title, body, children }) {
  return html`<div class="center-msg"><${SurfaceCard}><div class="cm-in"><${IconTile} n=${n} tint=${tint} size=${56}/>
    <div class="h-small">${title}</div><div class="body-m">${body}</div><div class="cm-c">${children}</div></div><//></div>`;
}
function PendingScreen() {
  return html`<${CenterMessage} n="Clock" tint=${Ds.Gold} title="Waiting for approval" body="Read this code to your administrator. It only matches this phone to the request; it is not a password.">
    <div class="code">${S.approvalCode}</div>
    <div class="row-c small" style=${{ marginTop: 16, gap: 8 }}><${Spinner} s=${16}/>${S.company} · checking automatically</div>
    <div class="dhint">Demo: the office approves this phone in a few seconds — or <a href="#" onClick=${(e) => { e.preventDefault(); approvePhone(); }}>approve now</a></div><//>`;
}
function EndedScreen() {
  const [confirm, setConfirm] = useState(false);
  return html`<${CenterMessage} n="Warning" tint=${Ds.Red} title=${S.ended === 'rejected' ? 'Request rejected' : 'Access revoked'}
      body="This phone can no longer access work data. Company device restrictions remain in place. Contact your IT administrator.">
    <${OutBtn} onClick=${() => setConfirm(true)} style=${{ borderRadius: 14 }}>Register again (administrator asked me to)<//>
    ${confirm && html`<${Dialog} title="New registration" onDismiss=${() => setConfirm(false)}
      confirm=${html`<${TextBtn} onClick=${() => { setConfirm(false); set({ screen: 'connect', ended: null, company: 'ROVEXY', error: null }); }}>Start new registration<//>`}
      dismiss=${html`<${TextBtn} onClick=${() => setConfirm(false)}>Cancel<//>`}><p class="body-m">A new identity will be created and must be approved again.</p><//>`}<//>`;
}
function PolicyScreen() {
  return html`<${CenterMessage} n="Shield" tint=${Ds.Amber} title="Device policy not yet applied" body="Work features stay locked until all required company settings are applied and verified (state: failed).">
    <div class="small">Pending: ${S.policyFailing.join(', ')}</div>
    <div class="row" style=${{ marginTop: 14, gap: 10 }}><${PrimaryButton} text="Check again" busy=${S.busy} onClick=${() => { set({ busy: true, failPolicy: false }); later(1000, enterWork); }}/>
      <${OutBtn} onClick=${logout} style=${{ height: 52, borderRadius: 14 }}>Sign out<//></div><//>`;
}

// ───────────────────────────── dashboard ─────────────────────────────
const greeting = () => { const h = new Date().getHours(); return h >= 5 && h <= 11 ? 'Good morning' : h >= 12 && h <= 16 ? 'Good afternoon' : 'Good evening'; };
function refresh() {
  if (S.busy) return;
  set({ busy: true });
  later(900, () => {
    if (!S.sync.online) return set({ busy: false, error: 'No connection. Your changes are kept on the phone and sent when you are back online.' });
    set({ busy: false, error: null, sync: { ...S.sync, last: Date.now(), pending: 0 } });
  });
}
const HeroIcon = ({ n, label, onClick, enabled = true }) => html`<button type="button" class="hero-ic" aria-label=${label} disabled=${!enabled} onClick=${onClick}><${Icon} n=${n} s=${20} c=${enabled ? '#fff' : 'rgba(255,255,255,.4)'}/></button>`;

function Dashboard() {
  const [showFilters, setShowFilters] = useState(false);
  const [menu, setMenu] = useState(false);
  const [disclosure, setDisclosure] = useState(false);
  const rows = useMemo(() => S.cases.map(row), [S.cases]);
  const f = S.filter;
  const here = fixOrNull();
  const shown = applyCaseFilter(rows, f, here);
  const worked = S.cases.filter((c) => c.status !== 'new').length;
  const openCase = S.profileId != null && rows.find((r) => r.c.id === S.profileId);
  const setF = (nf) => set({ filter: nf });
  const missing = missingPerms();
  const followUpsDue = rows.filter((r) => r.callbackDate && r.callbackDate <= todayIso()).length;
  let main;
  if (openCase) main = html`<${CustomerProfile} r=${openCase}/>`;
  else if (S.fieldOpen) main = html`<${FieldMapScreen} rows=${rows}/>`;
  else {
    const age = Math.floor((Date.now() - S.sync.last) / 60000);
    const pinned = rows.filter((r) => r.lat != null).length;
    main = html`<div class="scroll dash">
      <div class="dash-hero">
        <div class="row-c"><${Logo} name=${S.company} size=${36}/><span class="co-tag">${S.company.toUpperCase()}</span>
          <${HeroIcon} n="Place" label="Field map" onClick=${() => openFieldMap()}/>
          <${HeroIcon} n="Refresh" label="Sync" enabled=${!S.busy} onClick=${refresh}/>
          <div class="anchor"><${HeroIcon} n="People" label="Menu" onClick=${() => setMenu(true)}/>
            ${menu && html`<${Menu} onClose=${() => setMenu(false)} items=${[
              ['People', Ds.Navy, 'Messages', null, openChat],
              ['Shield', Ds.Navy, 'What my company can see', null, () => setDisclosure(true)],
              ['Call', Ds.Navy, 'Calls: phone dialer + its recorder', 'Tap to set up the Phone app and recording', askPermissions],
              ['Logout', Ds.Red, 'Sign out', null, logout]]}/>`}</div></div>
        <div class="greet">${greeting()},</div><div class="greet-n">${D.EMPLOYEE.name.split(' ')[0]}</div>
        <div class="sync-pill"><i style=${{ background: S.sync.online ? '#4ADE80' : '#94A3B8' }}></i>
          ${(S.sync.online ? 'Online' : 'Offline') + ' · synced ' + (age < 1 ? 'just now' : `${age} min ago`) + (S.sync.pending > 0 ? ` · ${S.sync.pending} waiting` : '')}</div>
        <div class="row-c" style=${{ marginTop: 18 }}><span class="grow" style=${{ color: '#C8D3E0', fontSize: 12 }}>Portfolio worked</span>
          <span style=${{ color: Ds.GoldSoft, fontSize: 12, fontWeight: 600 }}>${worked} of ${S.cases.length}</span></div>
        <div class="prog"><i style=${{ width: (worked / Math.max(1, S.cases.length) * 100) + '%' }}></i></div>
      </div>
      <div class="today-wrap"><${TodayCard} t=${{ ...S.today, followUpsDue }}/></div>
      <div class="alerts">
        ${missing.length > 0 && html`<${Banner} n="Warning" tint=${Ds.Amber} title="Finish call setup" body=${'Still needed: ' + missing.join(', ')} action="Fix" onClick=${askPermissions}/>`}
        ${S.error && html`<${Banner} n="Warning" tint=${Ds.Red} title="Something went wrong" body=${S.error}/>`}
        ${S.unread && html`<${Banner} n="People" tint=${Ds.Blue} title="New message" body="Your supervisor sent you a message." action="Open" onClick=${openChat}/>`}
      </div>
      <${MapShortcut} text=${pinned > 0 ? `${pinned} of ${rows.length} cases on the map · road distance & navigation` : 'Road distance & navigation to your customers'}/>
      <div style=${{ paddingTop: 20 }}><div class="row-c sec-h"><span class="grow">Today's priorities</span><span class="small">Tap to filter</span></div>
        <${PriorityTiles} all=${rows} f=${f} onTile=${(k) => { setF({ ...f, tile: f.tile === k ? '' : k }); mark('tile'); }}/></div>
      <div style=${{ paddingTop: 20 }}><${SearchFilterBar} f=${f} shown=${shown.length} total=${rows.length} onChange=${setF} onOpen=${() => setShowFilters(true)}/></div>
      ${shown.length === 0 && html`<div class="empty"><${IconTile} n="People" tint=${Ds.Muted} size=${56}/><div class="t-m">${rows.length ? 'No matches' : 'No cases yet'}</div>
        <div class="small">${rows.length ? 'Try another name or clear the filters.' : 'Tap Sync after your supervisor assigns cases.'}</div>
        ${(isActive(f) || f.query.trim()) && html`<${TextBtn} color=${Ds.Navy} onClick=${() => setF({ ...defaultFilter(), sort: f.sort })}>Clear search and filters<//>`}</div>`}
      ${shown.map((r) => html`<${CaseCard} key=${r.c.id} r=${r} enabled=${!S.busy && !S.call} onOpen=${() => openProfile(r.c.id)} onCall=${() => placeCall(r.c, 'primary')}/>`)}
      <div style=${{ height: 32 }}></div>
    </div>`;
  }
  return html`${main}
    ${S.call && html`<${CallPanel}/>`}
    ${S.recordingGate && html`<${RecordingGateDialog}/>`}
    ${disclosure && html`<${DisclosureDialog} onClose=${() => setDisclosure(false)}/>`}
    ${showFilters && html`<${FilterSheet} all=${rows} start=${f} onApply=${(nf) => { setF(nf); setShowFilters(false); mark('tile'); }} onDismiss=${() => setShowFilters(false)}/>`}
    ${S.chatOpen != null && html`<${ChatDialog}/>`}`;
}

function Menu({ items, onClose }) {
  return html`<div class="menu-scrim" onClick=${onClose}></div><div class="menu">${items.map(([n, c, t, sub, fn]) => html`
    <button type="button" class="menu-i" onClick=${() => { onClose(); fn(); }}><${Icon} n=${n} s=${20} c=${c}/>
      <span class="grow">${t}${sub && html`<span class="small" style=${{ display: 'block' }}>${sub}</span>`}</span></button>`)}</div>`;
}

function TodayCard({ t }) {
  const items = [[String(t.calls), 'Calls', Ds.Blue], [t.connected + (t.calls > 0 ? ` · ${Math.floor(100 * t.connected / t.calls)}%` : ''), 'Connected', Ds.Green],
    [mmss(t.talkSeconds), 'Talk time', Ds.Teal], [String(t.followUpsDue), 'Follow-ups', t.followUpsDue > 0 ? Ds.Amber : Ds.Ink]];
  return html`<div class="today">${items.map(([v, l, c], i) => html`${i > 0 && html`<i class="vline"></i>`}<div class="t-cell"><b style=${{ color: c }}>${v}</b><small>${l}</small></div>`)}</div>`;
}

function PriorityTiles({ all, f, onTile }) {
  const today = todayIso(), in7 = plusDays(7);
  const tiles = [['overdue', 'Overdue', all.filter((r) => (r.dpd || 0) > 0).length], ['due_today', 'Due today', all.filter((r) => r.c.dueDate === today).length],
    ['ptp_today', 'Promises today', all.filter((r) => r.promiseStatus === 'open' && r.promiseDate === today).length],
    ['cb_today', 'Callbacks due', all.filter((r) => r.callbackDate && r.callbackDate <= today).length],
    ['ptp_broken', 'Broken promises', all.filter((r) => r.promiseStatus === 'broken').length], ['not_contacted', 'Not contacted', all.filter((r) => r.c.calls === 0).length],
    ['upcoming', 'Due in 7 days', all.filter((r) => r.c.dueDate >= today && r.c.dueDate <= in7).length], ['paid_today', 'Paid today', all.filter((r) => r.lastPaymentOn === today).length]];
  return html`<div class="hscroll tiles">${tiles.map(([k, l, n]) => {
    const on = f.tile === k, warn = ['ptp_broken', 'cb_today', 'due_today', 'ptp_today'].includes(k) && n > 0;
    return html`<button type="button" class="tile" onClick=${() => onTile(k)} style=${{ background: on ? Ds.Navy : '#fff', borderColor: on ? Ds.Navy : warn ? alpha(Ds.Amber, 0.5) : Ds.Line }}>
      <b style=${{ color: on ? '#fff' : warn ? Ds.Amber : Ds.Ink }}>${n}</b><small style=${{ color: on ? Ds.GoldSoft : Ds.Muted }}>${l}</small></button>`;
  })}</div>`;
}

const SORTS = [['dpd', 'Most days overdue'], ['dpd_asc', 'Least days overdue'], ['amount', 'Highest overdue'], ['outstanding', 'Highest outstanding'], ['due', 'Next due date'],
  ['callback', 'Next callback'], ['promise', 'Promise date'], ['last_call', 'Not called longest'], ['recent_call', 'Called most recently'], ['paid', 'Latest payment'],
  ['nearest', 'Nearest to me'], ['name', 'Name A–Z']];
const TILE_LABELS = [['overdue', 'Overdue'], ['due_today', 'Due today'], ['upcoming', 'Due in 7 days'], ['ptp_today', 'Promises today'], ['ptp_broken', 'Broken promises'],
  ['cb_today', 'Callbacks due'], ['not_contacted', 'Not contacted'], ['paid_today', 'Paid today'], ['verified', 'Verified collections']];
const STATUS = [['new', 'New'], ['in_progress', 'In progress'], ['follow_up', 'Follow-up'], ['closed', 'Closed']];
const DPD = [['0', 'Not overdue'], ['1-30', '1–30 days'], ['31-60', '31–60 days'], ['61-90', '61–90 days'], ['90+', '90+ days']];
const AMOUNT = [['none', 'Nothing overdue'], ['lt10k', 'Up to ₹10k'], ['10-50k', '₹10k–50k'], ['gt50k', 'Over ₹50k']];
const CALLS = [['none', 'Not called'], ['no_connect', 'Called, no answer'], ['connected', 'Spoke to customer']];
const CALLBACK = [['today', 'Due today'], ['overdue', 'Overdue'], ['scheduled', 'Scheduled'], ['none', 'No callback']];
const ASSIGN = [['mine', 'Assigned to me'], ['pool', 'Group pool']];
const lbl = (list, k) => (list.find((x) => x[0] === k) || [k, k])[1];

function activeChips(f) {
  return [f.tile && [lbl(TILE_LABELS, f.tile), { ...f, tile: '' }], f.status && ['Status: ' + lbl(STATUS, f.status), { ...f, status: '' }],
    f.dpd && [lbl(DPD, f.dpd), { ...f, dpd: '' }], f.amount && [lbl(AMOUNT, f.amount), { ...f, amount: '' }], f.calls && [lbl(CALLS, f.calls), { ...f, calls: '' }],
    f.callback && ['Callback: ' + lbl(CALLBACK, f.callback), { ...f, callback: '' }], f.portfolio && ['Portfolio: ' + f.portfolio, { ...f, portfolio: '' }],
    f.assigned && [lbl(ASSIGN, f.assigned), { ...f, assigned: '' }]].filter(Boolean);
}

function SearchFilterBar({ f, shown, total, onChange, onOpen }) {
  const [sortOpen, setSortOpen] = useState(false);
  const chips = activeChips(f);
  return html`<div class="pad16">
    <div class="row-c" style=${{ gap: 10 }}>
      <label class="search"><${Icon} n="Search" s=${20} c=${Ds.Muted}/><input value=${f.query} placeholder="Name, case ID or last 4 digits" onInput=${(e) => onChange({ ...f, query: e.target.value })}/>
        ${f.query && html`<button type="button" class="x" aria-label="Clear search" onClick=${() => onChange({ ...f, query: '' })}><${Icon} n="Close" s=${20} c=${Ds.Muted}/></button>`}</label>
      <button type="button" class="filter-btn" onClick=${onOpen} style=${{ background: chips.length ? Ds.Navy : '#fff', color: chips.length ? '#fff' : Ds.Navy }}>
        <${Icon} n="Filter" s=${18}/>${chips.length ? `Filter · ${chips.length}` : 'Filter'}</button>
    </div>
    ${chips.length > 0 && html`<div class="hscroll chips">${chips.map(([l, w]) => html`<button type="button" class="chip" onClick=${() => onChange(w)}>${l}<${Icon} n="Close" s=${14}/></button>`)}
      <button type="button" class="lnk red" onClick=${() => onChange({ ...defaultFilter(), query: f.query, sort: f.sort })}>Clear all</button></div>`}
    <div class="row-c" style=${{ paddingTop: 14 }}>
      <span class="list-h">${chips.length === 0 && !f.query.trim() ? 'My cases' : 'Matching cases'}</span><span class="small grow">  ${shown} of ${total}</span>
      <div class="anchor"><button type="button" class="sort-btn" onClick=${() => setSortOpen(true)}>Sort: ${lbl(SORTS, f.sort)} ▾</button>
        ${sortOpen && html`<div class="menu-scrim" onClick=${() => setSortOpen(false)}></div><div class="menu up">${SORTS.map(([k, l]) => html`
          <button type="button" class="menu-i" style=${{ fontWeight: f.sort === k ? 600 : 400 }} onClick=${() => { setSortOpen(false); onChange({ ...f, sort: k }); }}>${l}</button>`)}</div>`}</div>
    </div></div>`;
}

function FilterSheet({ all, start, onApply, onDismiss }) {
  const [f, setF] = useState(start);
  const count = applyCaseFilter(all, f, fixOrNull()).length;
  const portfolios = [...new Set(all.map((r) => r.c.portfolio || 'Added by hand'))].sort().map((p) => [p, p]);
  const Group = (title, cur, opts, pick) => html`<div class="fg-t">${title}</div><div class="flow">${opts.map(([k, l]) => html`
    <button type="button" class="fchip" onClick=${() => pick(cur === k ? '' : k)} style=${{ background: cur === k ? Ds.Navy : '#fff', color: cur === k ? '#fff' : Ds.Ink, borderColor: cur === k ? Ds.Navy : Ds.Line }}>${l}</button>`)}</div>`;
  return html`<${Sheet} onDismiss=${onDismiss}>
    <div class="row-c" style=${{ padding: '0 20px' }}><span class="grow sheet-t">Filter cases</span><button type="button" class="lnk red" onClick=${() => setF({ ...defaultFilter(), query: f.query, sort: f.sort })}>Reset</button></div>
    <div class="sheet-scroll">
      ${Group('Sort by', f.sort, SORTS, (v) => setF({ ...f, sort: v || 'dpd' }))}
      ${Group('Priority', f.tile, TILE_LABELS, (v) => setF({ ...f, tile: v }))}
      ${Group('Case status', f.status, STATUS, (v) => setF({ ...f, status: v }))}
      ${Group('Days overdue', f.dpd, DPD, (v) => setF({ ...f, dpd: v }))}
      ${Group('Overdue amount', f.amount, AMOUNT, (v) => setF({ ...f, amount: v }))}
      ${Group('Calls', f.calls, CALLS, (v) => setF({ ...f, calls: v }))}
      ${Group('Callback', f.callback, CALLBACK, (v) => setF({ ...f, callback: v }))}
      ${portfolios.length > 1 && Group('Portfolio', f.portfolio, portfolios, (v) => setF({ ...f, portfolio: v }))}
      ${Group('Assignment', f.assigned, ASSIGN, (v) => setF({ ...f, assigned: v }))}
      <div style=${{ height: 16 }}></div></div>
    <div class="row" style=${{ padding: '12px 20px', gap: 10 }}>
      <${OutBtn} cls="grow1" style=${{ height: 50, borderRadius: 14, color: Ds.Ink }} onClick=${onDismiss}>Cancel<//>
      <button type="button" class="pbtn grow2" style=${{ height: 50, background: Ds.Navy }} onClick=${() => onApply(f)}>Show ${count} case${count === 1 ? '' : 's'}</button></div>
  <//>`;
}

/** ModalBottomSheet. */
function Sheet({ children, onDismiss }) {
  return html`<div class="scrim bottom" onClick=${(e) => e.target === e.currentTarget && onDismiss()}><div class="msheet"><div class="grab" onClick=${onDismiss}></div>${children}</div></div>`;
}

function MapShortcut({ text }) {
  return html`<button type="button" class="map-short" onClick=${() => openFieldMap()}>
    <span class="ms-ic"><${Icon} n="Place" s=${22} c=${Ds.NavyInk}/></span>
    <span class="grow"><b>Customers near me</b><small>${text}</small></span><span class="ms-a">Open map ›</span></button>`;
}

function CaseCard({ r, enabled, onOpen, onCall }) {
  const today = todayIso();
  const flags = [r.nextCallback && [(r.callbackDate > today ? 'Callback ' : 'Callback due ') + fmtWhen(r.nextCallback), Ds.Amber],
    r.promiseStatus === 'broken' ? [`Broken promise ${money(r.promiseAmount)} (${fmtDate(r.promiseDate)})`, Ds.Red] : r.promiseStatus === 'open' ? [`Promise ${money(r.promiseAmount)} on ${fmtDate(r.promiseDate)}`, Ds.Blue] : null,
    r.c.pool && ['Group pool', Ds.Muted]].filter(Boolean);
  return html`<div class="pad-card"><${SurfaceCard} onClick=${onOpen}><div class="card-in">
    <div class="row-c"><${Avatar} name=${r.c.name} size=${40}/>
      <div class="grow ell" style=${{ marginLeft: 10 }}><div class="cc-name ell">${r.c.name}</div><div class="small ell">${[r.c.ref, r.c.portfolio, maskNo(r.c.phone)].join(' · ')}</div></div>
      <button type="button" class="call-fab" disabled=${!enabled} aria-label=${'Call ' + r.c.name} onClick=${(e) => { e.stopPropagation(); onCall(); }}><${Icon} n="Call" s=${20} c="#fff"/></button></div>
    <div class="row" style=${{ marginTop: 10 }}>
      <${Metric} label="Overdue" value=${money(r.overdue)} color=${(r.dpd || 0) > 0 ? Ds.Red : Ds.Ink}/>
      <${Metric} label="Days overdue" value=${r.dpd != null ? String(r.dpd) : '—'} color=${(r.dpd || 0) > 60 ? Ds.Red : Ds.Ink}/>
      <${Metric} label="Outstanding" value=${money(r.outstanding)}/></div>
    <div class="row-c" style=${{ marginTop: 8, gap: 8 }}><${StatusTag} status=${r.c.status}/>
      <span class="small clamp2">${[r.c.dueDate && 'Due ' + fmtDate(r.c.dueDate), r.c.lastCallAt ? 'Last call ' + fmtWhen(r.c.lastCallAt) + (r.c.lastOutcome ? ' · ' + outcomeLabel(r.c.lastOutcome) : '') : 'Not called yet'].filter(Boolean).join(' · ')}</span></div>
    ${flags.length > 0 && html`<div class="hscroll" style=${{ marginTop: 6, gap: 6 }}>${flags.map(([t, c]) => html`<${Tag} text=${t} c=${c}/>`)}</div>`}
  </div><//></div>`;
}
const Metric = ({ label, value, color = Ds.Ink }) => html`<div class="metric"><b style=${{ color }}>${value}</b><small>${label}</small></div>`;

function DisclosureDialog({ onClose }) {
  const d = D.DISCLOSURE;
  const lines = [d.recorded ? `Calls are recorded. ${d.notice}` : 'Calls are not recorded.', 'Call duration comes from this phone\'s call log; recorded calls are uploaded to your company.',
    d.location ? `Location: ${d.location}.` : 'Location is not collected.', d.liveView && 'Screen sharing can be requested; you must accept and Android asks for permission each time.',
    d.camera && 'Camera sessions can be requested; you must accept and the camera indicator is shown.', 'Nothing is captured in the background or without your knowledge.'].filter(Boolean);
  return html`<${Dialog} icon=${html`<${IconTile} n="Shield" tint=${Ds.Navy} size=${48}/>`} title="What your company can see" onDismiss=${onClose}
    confirm=${html`<${TextBtn} onClick=${onClose}>OK<//>`}>${lines.map((l) => html`<p class="body-m">${l}</p>`)}<//>`;
}

function RecordingGateDialog() {
  const close = () => set({ recordingGate: false });
  return html`<${Dialog} icon=${html`<${IconTile} n="Mic" tint=${Ds.Red} size=${48}/>`} title="Turn on call recording" onDismiss=${close}
    confirm=${html`<${PrimaryButton} text="Open call settings" onClick=${() => set({ recordingGate: false, sys: { kind: 'callsettings' } })}/>`}
    dismiss=${html`<${TextBtn} onClick=${close}>Not now<//>`}>
    <p class="body-m pre">${'Company calls must be recorded. Calls are blocked until your Phone app records them.\n\n1. Tap Open call settings.\n2. Open Record calls (Samsung: Record calls → Auto record calls; OPPO/OnePlus: Call recording → Auto-record all calls).\n3. Switch auto-recording ON for all calls.\n4. Make one short test call from your Phone app (any number that answers).\n5. Come back and tap Call again.\n\nCalling unlocks as soon as the recording of that test call is found on the phone.'}</p><//>`;
}

// ───────────────────────────── calls (AppViewModel.placeCall + SIM route) ─────────────────────────────
const NEVER_ANSWERS = ['SWITCHED_OFF', 'NOT_REACHABLE'];
function placeCall(c, which, from = 'list') {
  if (S.call || S.busy) return;
  if (!S.sync.online) return set({ error: 'Calling needs a connection to the company server.' });
  if (!S.perms) { set({ error: 'Allow Phone, Call log and Music/Audio permissions, then tap Call again.', sys: { kind: 'perms' } }); return; }
  if (!S.recordingProven) return set({ recordingGate: true });
  const number = which === 'alternate' && c.alt ? c.alt : c.phone;
  set({ error: null, call: { id: Date.now(), state: 'dialing', talk: 0, recording: 'pending', route: 'sim', customerId: c.id, name: c.name, which, number } });
  later(900, () => S.call?.state === 'dialing' && set({ dialer: { phase: 'calling', start: Date.now(), answeredAt: null, mute: false, speaker: false, keypad: false } }));
  mark('call');
}
/** The employee hung up in the Phone app → back in ROVEXY: the call is read from the call log and saved. */
function endSimCall() {
  const d = S.dialer, call = S.call;
  if (!d || !call) return;
  const talk = d.answeredAt ? Math.max(1, Math.round((Date.now() - d.answeredAt) / 1000)) : 0;
  set({ dialer: null, call: { ...call, state: 'saving' } });
  later(1300, () => S.call && set({ call: { ...S.call, state: talk > 0 ? 'completed' : 'no_answer', talk, recording: talk > 0 ? 'available' : 'not_required' } }));
}
function hangup() {   // before the Phone app took over
  if (!S.call) return;
  set({ dialer: null, call: { ...S.call, state: 'cancelled', recording: 'not_required' } });
}
const TERMINAL = ['completed', 'disconnected', 'busy', 'no_answer', 'rejected', 'failed', 'cancelled', 'agent_unanswered'];

function SimDialer() {
  const d = S.dialer, call = S.call;
  const [, tick] = useState(0);
  useEffect(() => { const t = setInterval(() => tick((x) => x + 1), 500); return () => clearInterval(t); }, []);
  useEffect(() => {
    const c = caseById(call.customerId), never = NEVER_ANSWERS.includes(c?.lastOutcome);
    const a = later(1800, () => S.dialer && set({ dialer: { ...S.dialer, phase: 'ringing' } }));
    const b = never ? later(14000, () => S.dialer && endSimCall()) : later(4200, () => S.dialer && set({ dialer: { ...S.dialer, phase: 'active', answeredAt: Date.now() } }));
    return () => { clearTimeout(a); clearTimeout(b); };
  }, []);
  const secs = d.answeredAt ? Math.floor((Date.now() - d.answeredAt) / 1000) : 0;
  const tog = (k) => set({ dialer: { ...S.dialer, [k]: !S.dialer[k] } });
  return html`<div class="dialer">
    <div class="dhint top">Your phone's own Phone app (simulated) · the call goes out on the company SIM</div>
    <div class="dl-who"><${Avatar} name=${call.name} size=${84}/><div class="dl-name">${call.name}</div><div class="dl-num">Mobile ${maskNo(call.number)}</div>
      <div class="dl-state">${d.phase === 'active' ? mmss(secs) : d.phase === 'ringing' ? 'Ringing…' : 'Calling…'}</div>
      ${d.phase === 'active' && html`<div class="dl-rec"><i></i>Recording</div>`}</div>
    <div class="dl-grid">${[['MicOff', 'Mute', 'mute'], ['Keypad', 'Keypad', 'keypad'], ['Speaker', 'Speaker', 'speaker']].map(([n, l, k]) => html`
      <button type="button" class=${'dl-b' + (d[k] ? ' on' : '')} onClick=${() => tog(k)}><span><${Icon} n=${n} s=${26}/></span>${l}</button>`)}</div>
    <button type="button" class="dl-end" aria-label="End call" onClick=${endSimCall}><${Icon} n="CallEnd" s=${34} c="#fff"/></button></div>`;
}

function CallPanel() {
  const c = S.call;
  const ended = TERMINAL.includes(c.state);
  const [code, setCode] = useState('');
  const [notes, setNotes] = useState('');
  const [followUp, setFollowUp] = useState(null);
  const [amount, setAmount] = useState('');
  const [mode, setMode] = useState('');
  const [ref, setRef] = useState('');
  const [paidOn, setPaidOn] = useState(todayIso());
  const [slip, setSlip] = useState(null);
  const fileRef = useRef(), camRef = useRef();
  const sel = D.OUTCOMES.find((o) => o.code === code);
  const captures = sel?.captures || 'none';
  const amountOk = Number(amount) > 0;
  const canSave = !!code && (captures === 'promise' ? amountOk && !!followUp : captures === 'payment' ? amountOk && !!mode : !sel?.needsFollowUp || !!followUp);
  const headline = { dialing: 'Calling…', on_sim_call: 'On call', saving: 'Saving the call…', completed: `Call completed · ${mmss(c.talk)}`, no_answer: 'Not answered' }[c.state]
    || cap(c.state.replace(/_/g, ' ')) + (c.talk > 0 ? ` · ${mmss(c.talk)}` : '');
  const rec = c.recording && c.recording !== 'not_required' && (c.recording === 'available' ? ['Check', Ds.Green, 'Recording saved']
    : c.recording === 'failed' ? ['Warning', Ds.Red, 'Recording not saved — auto call recording is OFF. Calls are blocked until it is switched on.'] : ['Mic', Ds.Amber, 'Recording: ' + c.recording.replace(/_/g, ' ')]);
  const amt = (v) => setAmount(v.replace(/[^\d.]/g, '').slice(0, 11));
  const save = () => {
    const err = saveFeedback(c, { code, notes, followUp, amount: captures !== 'none' ? Number(amount) : null, mode: captures === 'payment' ? mode : null,
      ref: captures === 'payment' ? ref.trim() : null, paidOn, slip: captures === 'payment' ? slip : null, captures });
    if (err) set({ error: err });
  };
  const confirm = ended ? html`<div class="col-end">${S.error && html`<div class="err-t">${S.error}</div>`}<${PrimaryButton} text="Save feedback" enabled=${canSave} busy=${S.busy} onClick=${save}/></div>`
    : c.route === 'sim' && html`<${TextBtn} onClick=${() => S.dialer ? endSimCall() : null}>Finish call<//>`;
  const dismiss = !ended && (c.route === 'sim' && c.state !== 'dialing' ? html`<${TextBtn} onClick=${() => set({ call: null })}>Close<//>` : html`<${TextBtn} onClick=${hangup}>Hang up<//>`);
  return html`<${Dialog} confirm=${confirm} dismiss=${dismiss}>
    <div class="row-c"><${Avatar} name=${c.name} size=${48}/><div class="grow" style=${{ marginLeft: 12 }}><div class="t-m">${c.name}</div>
      <div style=${{ color: c.state === 'completed' ? Ds.Green : Ds.Muted, fontSize: 13, fontWeight: 500 }}>${headline}</div></div>${!ended && html`<${Spinner}/>`}</div>
    ${c.state === 'dialing' && html`<div class="small" style=${{ marginTop: 8 }}>Opening your Phone app…</div>`}
    ${rec && html`<div class="recbox" style=${{ color: rec[1], background: alpha(rec[1], 0.08) }}><${Icon} n=${rec[0]} s=${18}/>${rec[2]}</div>`}
    ${ended && html`
      <div class="lab-s" style=${{ marginTop: 16 }}>CALL FEEDBACK</div>
      ${D.OUTCOMES.map((o) => html`<button type="button" class=${'opt' + (code === o.code ? ' on' : '')} onClick=${() => setCode(o.code)}><i class="radio"></i>${o.label}</button>`)}
      ${captures === 'promise' && html`<div class="lab-s" style=${{ marginTop: 10 }}>PROMISE</div><${Field} value=${amount} onInput=${amt} label="Promised amount (₹)" inputMode="decimal"/>
        <div class="small">Pick the promise date below.</div>`}
      ${captures === 'payment' && html`<div class="lab-s" style=${{ marginTop: 10 }}>PAYMENT</div>
        <${Field} value=${amount} onInput=${amt} label="Amount received (₹)" inputMode="decimal"/>
        <div class="small" style=${{ margin: '4px 0' }}>Payment mode</div>
        <div class="flow" style=${{ gap: 6 }}>${D.PAYMENT_MODES.map(([k, l]) => html`<${Pill} text=${l} selected=${mode === k} onClick=${() => setMode(k)}/>`)}</div>
        <${Field} value=${ref} onInput=${(v) => setRef(v.slice(0, 64))} label=${mode === 'CASH' ? 'Receipt no. (optional)' : 'Reference / UTR / cheque no.'}/>
        <${PickRow} kind="date" value=${paidOn} max=${todayIso()} tint=${Ds.Navy} text=${'Paid on ' + fmtDate(paidOn)} placeholder="Paid on" onPick=${setPaidOn}/>
        <div class="small" style=${{ margin: '10px 0 4px' }}>Payment slip / receipt</div>
        ${slip ? html`<div class="okbox"><${Icon} n="Check" s=${18}/><span class="grow">${slip.label}</span><span class="lnk red" onClick=${() => setSlip(null)}>Remove</span></div>`
          : html`<div class="row" style=${{ gap: 8 }}><${OutBtn} onClick=${() => camRef.current.click()}>Take photo<//><${OutBtn} onClick=${() => fileRef.current.click()}>Choose file<//></div>
            <div class="small">Optional, but the office checks payments against the slip.</div>`}
        <input ref=${camRef} type="file" accept="image/*" capture="environment" hidden onChange=${(e) => e.target.files[0] && setSlip({ label: 'Photo attached', name: e.target.files[0].name })}/>
        <input ref=${fileRef} type="file" accept="image/*,application/pdf" hidden onChange=${(e) => e.target.files[0] && setSlip({ label: 'File attached', name: e.target.files[0].name })}/>`}
      <${Field} value=${notes} onInput=${setNotes} label="Notes (optional)"/>
      <${PickRow} value=${followUp} min=${nowLocal()} onPick=${setFollowUp} onClear=${() => setFollowUp(null)}
        text=${followUp ? fmtFull(followUp) : captures === 'promise' ? 'Promise date (required)' : sel?.needsFollowUp ? 'Follow-up date (required)' : 'Add follow-up (optional)'}/>`}
  <//>`;
}

/** Same rules as CallService::submitDisposition: one open callback per case, follow-up → "follow_up", else new → "in_progress". */
function saveFeedback(call, x) {
  const c = caseById(call.customerId);
  if (x.captures === 'payment' && c.payments.some((p) => p.status !== 'rejected' && p.amount === x.amount && p.paidOn === x.paidOn && (!x.ref || p.ref === x.ref)))
    return 'This payment (same amount, date and reference) is already recorded for this customer.';
  set({ busy: true });
  later(700, () => {
    const answered = call.state === 'completed' && call.talk > 0;
    updateCase(c.id, (k) => {
      k.calls += 1; if (answered) k.connected += 1;
      k.lastCallAt = Date.now(); k.lastOutcome = x.code;
      applyEntry(k, x, { type: 'call', at: Date.now(), by: D.EMPLOYEE.name, outcome: outcomeLabel(x.code), state: call.state, talk: call.talk, rec: call.recording, which: call.which, notes: x.notes || null,
        amount: x.amount, mode: x.mode, review: x.mode ? 'pending' : null, promiseDate: x.captures === 'promise' && x.followUp ? x.followUp.slice(0, 10) : null });
      return k;
    });
    set({ busy: false, call: null, error: null, today: { ...S.today, calls: S.today.calls + 1, connected: S.today.connected + (answered ? 1 : 0), talkSeconds: S.today.talkSeconds + call.talk },
      sync: { ...S.sync, last: Date.now() } });
    mark('feedback');
  });
  return null;
}
/** Promise / payment / follow-up effects shared by call feedback and "record without a call". */
function applyEntry(k, x, tl) {
  if (x.captures === 'promise') k.promises = [{ amount: x.amount, date: x.followUp.slice(0, 10), status: 'open', by: D.EMPLOYEE.name }, ...k.promises.map((p) => p.status === 'open' ? { ...p, status: 'replaced' } : p)];
  if (x.captures === 'payment') k.payments = [{ amount: x.amount, mode: x.mode, paidOn: x.paidOn, ref: x.ref || null, status: 'pending', by: D.EMPLOYEE.name, slip: !!x.slip, note: null }, ...k.payments];
  k.callback = null;
  const tlx = [tl];
  if (x.followUp) {
    const due = toD(x.followUp).getTime();
    k.callback = { at: due, notes: x.notes || null }; k.status = 'follow_up';
    tlx.push({ type: 'callback', at: Date.now() + 1, dueAt: due, status: 'open', by: D.EMPLOYEE.name, notes: x.notes || null });
  } else if (k.status === 'new') k.status = 'in_progress';
  k.timeline = [...tlx.reverse(), ...k.timeline.map((t) => t.type === 'callback' && t.status === 'open' ? { ...t, status: 'done' } : t)];
}

// ───────────────────────────── chat ─────────────────────────────
function openChat() { set({ chatOpen: S.chat.length === 1 ? S.chat[0].id : -1, unread: false, notif: null }); mark('chat'); }
function supervisorMessage(body, id = Date.now()) {
  const conv = S.chat[0];
  set({ chat: [{ ...conv, messages: [...conv.messages, { id, mine: false, body, at: Date.now(), read: false }] }] });
  if (S.chatOpen == null) set({ unread: true, notif: { title: 'Supervisor chat', body, at: Date.now() } }), later(5000, () => S.notif?.body === body && set({ notif: null }));
}
function ChatDialog() {
  const [text, setText] = useState('');
  const listRef = useRef();
  const conv = S.chat.find((c) => c.id === S.chatOpen);
  useEffect(() => { if (listRef.current) listRef.current.scrollTop = 1e6; }, [conv?.messages.length]);
  const close = () => set({ chatOpen: null });
  const send = () => {
    const body = text.trim(); if (!body) return;
    setText('');
    const msgs = [...conv.messages, { id: Date.now(), mine: true, body, at: Date.now(), read: false }];
    set({ chat: [{ ...conv, messages: msgs }] });
    later(1800, () => set({ chat: [{ ...S.chat[0], messages: S.chat[0].messages.map((m) => m.mine ? { ...m, read: true } : m) }] }));
    later(3600, () => supervisorMessage(/\?/.test(body) ? 'Let me check and get back to you in 5 minutes.' : 'Noted, thanks Priya. Keep me posted.'));
  };
  return html`<${Dialog} title=${S.chatOpen > 0 ? 'Messages' : 'Conversations'} onDismiss=${close} confirm=${html`<${TextBtn} onClick=${close}>Close<//>`}>
    ${S.chatOpen <= 0 ? (S.chat.length ? S.chat.map((c) => html`<${TextBtn} onClick=${() => set({ chatOpen: c.id })}>${c.subject || 'Supervisor chat #' + c.id}<//>`)
      : html`<p class="body-m">No messages yet. Your supervisor can start a conversation from the admin console.</p>`)
    : html`<div class="chat-list" ref=${listRef}>${conv.messages.map((m) => html`<div class=${'msg' + (m.mine ? ' mine' : '')}><div>${m.body}
        <small>${isoDay(m.at) + ' ' + p2(new Date(m.at).getHours()) + ':' + p2(new Date(m.at).getMinutes()) + (m.mine && m.read ? ' · read' : '')}</small></div></div>`)}</div>
      <div class="row-c"><div class="grow"><${Field} value=${text} onInput=${setText} label="Type a message" rounded=${false}/></div>
        <${TextBtn} disabled=${!text.trim()} onClick=${send}>Send<//></div>`}<//>`;
}

// ───────────────────────────── supervision ─────────────────────────────
function MediaRequestDialog({ r }) {
  const decline = () => set({ pendingMedia: null });
  const accept = () => set({ pendingMedia: null, sys: r.kind === 'voice' ? null : { kind: r.kind }, activeMedia: r.kind === 'voice' ? { kind: 'voice' } : null });
  if (r.kind === 'voice') return html`<${Dialog} icon=${html`<${IconTile} n="Call" tint=${Ds.Green} size=${48}/>`} title="Incoming call" onDismiss=${decline}
    confirm=${html`<${PrimaryButton} text="Answer" color=${Ds.Green} onClick=${accept}/>`} dismiss=${html`<${TextBtn} color=${Ds.Red} onClick=${decline}>Decline<//>`}>
    <p class="body-m">${r.by} is calling you over the internet (no SIM charges).</p><//>`;
  return html`<${Dialog} icon=${html`<${IconTile} n="Shield" tint=${Ds.Navy} size=${48}/>`} title=${r.kind === 'screen' ? 'Screen sharing request' : 'Camera session request'} onDismiss=${decline}
    confirm=${html`<${PrimaryButton} text="Accept" onClick=${accept}/>`} dismiss=${html`<${TextBtn} onClick=${decline}>Decline<//>`}>
    <p class="body-m pre">${`${r.by} asks to see your ${r.kind === 'screen' ? 'screen' : 'camera'}.\n\nPurpose: ${r.purpose}\n\nIf you accept, Android will also ask for permission. A notification and indicator stay visible while sharing, and you can stop at any time.`}</p><//>`;
}
function MonitoringBanner() {
  const voice = S.activeMedia.kind === 'voice';
  return html`<div class="mon"><span class="grow">${voice ? '● On a call with your supervisor' : '● Sharing with your supervisor'}</span>
    <button type="button" onClick=${() => set({ activeMedia: null })}>${voice ? 'HANG UP' : 'STOP'}</button></div>`;
}
/** Android's own dialogs (permission, screen capture, call settings) — simulated. */
function SystemDialog({ d }) {
  const close = () => set({ sys: null });
  if (d.kind === 'callsettings') {
    const on = S.recordingOn;
    return html`<div class="sysdlg-scrim"><div class="sysdlg wide"><div class="sys-src">Phone app settings (simulated)</div><b>Record calls</b>
      <label class="sw-row"><span class="grow">Auto record calls<small>Record all calls automatically</small></span><input type="checkbox" class="sw" checked=${on} onChange=${(e) => set({ recordingOn: e.target.checked })}/></label>
      <p class="small">Then make one short test call; ROVEXY unlocks calling when it finds that recording.</p>
      <div class="sys-a"><button type="button" onClick=${() => { set({ sys: null, recordingProven: S.recordingOn }); if (S.recordingOn) toast('Recording found — calls unlocked'); }}>${on ? 'Make test call & return' : 'Back'}</button></div></div></div>`;
  }
  const t = { perms: ['Allow ROVEXY to make and manage phone calls, read your call log and access music and audio?', 'Allow', () => set({ sys: null, perms: true, error: null })],
    screen: ['Start recording or casting with ROVEXY?', 'Start now', () => set({ sys: null, activeMedia: { kind: 'screen' } })],
    camera: ['Allow ROVEXY to take pictures and record video?', 'While using the app', () => set({ sys: null, activeMedia: { kind: 'camera' } })] }[d.kind];
  return html`<div class="sysdlg-scrim"><div class="sysdlg"><div class="sys-src">Android (simulated)</div><b>${t[0]}</b>
    <div class="sys-a col"><button type="button" onClick=${t[2]}>${t[1]}</button><button type="button" onClick=${close}>Don't allow</button></div></div></div>`;
}
function askPermissions() {
  if (!S.perms) return set({ sys: { kind: 'perms' } });
  toast('Phone app and call recording are set up');
}
function HeadsUp({ n }) {
  return html`<button type="button" class="headsup" onClick=${openChat}><span class="hu-ic">R</span><span class="grow"><b>${n.title}</b><small>${n.body}</small></span><em>now</em></button>`;
}

// ───────────────────────────── customer profile (Cases.kt) ─────────────────────────────
const TABS = ['Overview', 'Details', 'Loan & dues', 'Payments', 'Calls', 'Follow-ups', 'Address & map'];
function openProfile(id, dialog = null) { set({ profileId: id, profileTab: 0, profileDialog: dialog, profileLoaded: false, error: null }); later(450, () => set({ profileLoaded: true })); mark('profile'); }
function closeProfile() { set({ profileId: null, profileDialog: null, error: null }); }
const addressOf = (c) => [c.address, c.area && !c.address.includes(c.area) ? c.area : null, c.city, c.state, c.pincode].filter(Boolean).join(', ');
function openDirections(c) {
  const dest = c.lat != null ? `${c.lat},${c.lng}` : addressOf(c);
  window.open('https://www.google.com/maps/dir/?api=1&travelmode=two-wheeler&destination=' + encodeURIComponent(dest), '_blank', 'noopener');
}
function queue(id, fn) {   // offline queue (EventQueue): applied on the phone now, sent when back online
  updateCase(id, fn);
  if (!S.sync.online) set({ sync: { ...S.sync, pending: S.sync.pending + 1 } });
}

function CustomerProfile({ r }) {
  const c = r.c;
  const offline = !S.sync.online;
  const p = offline || !S.profileLoaded ? null : c;
  const [dialog, setDialog] = useState(S.profileDialog);
  useEffect(() => { if (S.profileDialog) { setDialog(S.profileDialog); S.profileDialog = null; } }, []);
  const tab = S.profileTab;
  const close = () => setDialog(null);
  const reload = () => { set({ profileLoaded: false }); later(500, () => set({ profileLoaded: true })); };
  return html`<div class="profile">
    <div class="prof-hero">
      <div class="row-c"><${TextBtn} color="#fff" onClick=${closeProfile}>‹ Back<//><span class="grow"></span>
        ${offline && html`<span style=${{ color: Ds.GoldSoft, fontSize: 12 }}>Offline · saved summary</span>`}<${TextBtn} color=${Ds.GoldSoft} onClick=${reload}>Refresh<//></div>
      <div class="row-c" style=${{ paddingLeft: 8 }}><${Avatar} name=${c.name} size=${52}/>
        <div class="grow ell" style=${{ marginLeft: 12 }}><div class="ph-name ell">${c.name}</div><div class="ph-sub">${[c.ref, c.portfolio].join(' · ')}</div>
          <div class="ph-sub" style=${{ fontSize: 12 }}>${p ? `Assigned to ${c.pool ? '—' : D.EMPLOYEE.name} · ${c.team}` : `Group: ${c.team}`}</div></div>
        <${StatusTag} status=${c.status}/></div>
      <div class="row" style=${{ padding: '14px 0 0 8px' }}>
        <${HeroMetric} label="Overdue now" value=${money(r.overdue)} alert=${(r.dpd || 0) > 0}/>
        <${HeroMetric} label="Days overdue" value=${r.dpd != null ? String(r.dpd) : '—'} alert=${(r.dpd || 0) > 30}/>
        <${HeroMetric} label="Outstanding" value=${money(r.remaining)}/></div>
    </div>
    <div class="row acts">
      <${ActionChip} n="Call" text="Call" tint=${Ds.Green} enabled=${!S.busy && !S.call} onClick=${() => c.alt ? setDialog('which') : placeCall(c, 'primary')}/>
      <${ActionChip} n="Event" text="Callback" tint=${Ds.Amber} onClick=${() => setDialog('callback')}/>
      <${ActionChip} n="Check" text="Payment" tint=${Ds.Teal} onClick=${() => setDialog('payment')}/>
      <${ActionChip} n="Place" text="Directions" tint=${Ds.Blue} onClick=${() => p ? openDirections(c) : reload()}/></div>
    <div class="row" style=${{ padding: '0 12px', gap: 8 }}>${[['promise', 'Promise'], ['note', 'Note'], ['status', 'Status']].map(([k, l]) => html`<${OutBtn} cls="grow1 sm" onClick=${() => setDialog(k)}>${l}<//>`)}</div>
    ${S.error && html`<div class="err-t" style=${{ padding: '4px 16px' }}>${S.error}</div>`}
    <div class="tabs" role="tablist">${TABS.map((t, i) => html`<button type="button" role="tab" aria-selected=${tab === i} class=${'tab' + (tab === i ? ' on' : '')} onClick=${() => set({ profileTab: i })}>${t}</button>`)}</div>
    <div class="scroll prof-body">
      ${!p ? html`${offline ? html`<${Notice} text="No connection — full details, history and the map need the server. Saved summary below." tint=${Ds.Amber}/>`
        : html`<div class="row-c small" style=${{ gap: 8 }}><${Spinner} s=${18}/>Loading case…</div>`}<div style=${{ height: 10 }}></div><${OfflineSummary} r=${r}/>`
      : [() => html`<${OverviewTab} r=${r}/>`, () => html`<${DetailsTab} c=${c}/>`, () => html`<${DuesTab} r=${r}/>`, () => html`<${PaymentsTab} r=${r}/>`,
        () => html`<${CallsTab} c=${c}/>`, () => html`<${FollowUpsTab} c=${c}/>`, () => html`<${AddressTab} r=${r}/>`][tab]()}
      <div style=${{ height: 40 }}></div></div>
    ${dialog === 'which' && html`<${Dialog} title="Which number?" onDismiss=${close} confirm=${html`<${TextBtn} onClick=${() => { close(); placeCall(c, 'primary'); }}>Primary<//>`}
      dismiss=${html`<${TextBtn} onClick=${() => { close(); placeCall(c, 'alternate'); }}>Alternate<//>`}/>`}
    ${dialog === 'note' && html`<${TextDialog} title="Add collection note" label="Note" onDismiss=${close} onSave=${(t) => { queue(c.id, (k) => ({ ...k, timeline: [{ type: 'note', at: Date.now(), by: D.EMPLOYEE.name, notes: t }, ...k.timeline] })); close(); }}/>`}
    ${dialog === 'status' && html`<${StatusDialog} current=${c.status} onDismiss=${close} onSave=${(st, n) => { queue(c.id, (k) => ({ ...k, status: st, timeline: [{ type: 'status', at: Date.now(), by: D.EMPLOYEE.name, from: k.status, to: st, notes: n || null }, ...k.timeline] })); close(); }}/>`}
    ${dialog === 'callback' && html`<${CallbackDialog} onDismiss=${close} onSave=${(at, n) => { queue(c.id, (k) => { const due = toD(at).getTime(); return { ...k, status: 'follow_up', callback: { at: due, notes: n || null },
      timeline: [{ type: 'callback', at: Date.now(), dueAt: due, status: 'open', by: D.EMPLOYEE.name, notes: n || null }, ...k.timeline.map((t) => t.type === 'callback' && t.status === 'open' ? { ...t, status: 'done' } : t)] }; }); close(); }}/>`}
    ${(dialog === 'payment' || dialog === 'promise') && html`<${EntryDialog} r=${r} kind=${dialog} onDismiss=${close}/>`}
  </div>`;
}
const HeroMetric = ({ label, value, alert }) => html`<div class="metric"><b style=${{ color: alert ? '#FFB4A8' : '#fff', fontSize: 18 }}>${value}</b><small style=${{ color: '#C8D3E0' }}>${label}</small></div>`;
const ActionChip = ({ n, text, tint, enabled = true, onClick }) => html`<button type="button" class="achip" disabled=${!enabled} onClick=${onClick}>
  <${Icon} n=${n} s=${20} c=${enabled ? tint : Ds.Muted}/><span style=${{ color: enabled ? Ds.Ink : Ds.Muted }}>${text}</span></button>`;
const Notice = ({ text, tint }) => html`<div class="notice" style=${{ color: tint, background: alpha(tint, 0.08) }}>${text}</div>`;
const Section = ({ title, children }) => html`<div class="lab-s sec">${title.toUpperCase()}</div><${SurfaceCard}><div style=${{ padding: 14 }}>${children}</div><//>`;
const Kv = ({ label, value, strong, color = Ds.Ink }) => html`<div class="kv"><span class="small">${label}</span>
  <b style=${{ color: value == null ? Ds.Muted : color, fontSize: strong ? 16 : 14, fontWeight: strong ? 600 : 400 }}>${value ?? 'Not available'}</b></div>`;

function OfflineSummary({ r }) {
  return html`<${Section} title="Saved summary">
    <${Kv} label="Overdue (as uploaded)" value=${money(r.overdue)} strong color=${Ds.Red}/><${Kv} label="Days overdue" value=${r.dpd != null ? String(r.dpd) : null}/>
    <${Kv} label="Total outstanding (as uploaded)" value=${money(r.outstanding)}/><${Kv} label="Next instalment" value=${money(r.c.emi) + ' on ' + fmtDate(r.c.dueDate)}/>
    <${Kv} label="Last call" value=${r.c.lastCallAt ? fmtWhen(r.c.lastCallAt) + (r.c.lastOutcome ? ' · ' + outcomeLabel(r.c.lastOutcome) : '') : 'Not called yet'}/>
    <${Kv} label="Next callback" value=${r.nextCallback ? fmtWhen(r.nextCallback) : 'None'}/>
    <${Kv} label="Promise" value=${r.promiseAmount != null ? `${money(r.promiseAmount)} on ${fmtDate(r.promiseDate)} · ${r.promiseStatus}` : 'None'}/><//>`;
}
function OverviewTab({ r }) {
  const c = r.c, pr = c.promises[0];
  return html`<${Section} title="Collection status">
      <${Kv} label="Overdue now" value=${money(r.overdue)} strong color=${Ds.Red}/><${Kv} label="Days overdue" value=${r.dpd != null ? String(r.dpd) : null}/>
      <${Kv} label="Next instalment" value=${money(c.emi) + ' on ' + fmtDate(c.dueDate)}/><${Kv} label="Total outstanding" value=${money(r.remaining)}/>
      <${Kv} label="Collected (verified)" value=${money(r.verified)}/>${r.pending > 0 && html`<${Kv} label="Waiting for office check" value=${money(r.pending)} color=${Ds.Amber}/>`}<//>
    <${Section} title="Next action">
      <${Kv} label="Callback" value=${c.callback ? fmtWhen(c.callback.at) : 'None scheduled'}/>${c.callback?.notes && html`<div class="small">${c.callback.notes}</div>`}
      <${Kv} label="Latest promise" value=${pr ? `${money(pr.amount)} on ${fmtDate(pr.date)} · ${pr.status}` : 'None'} color=${pr?.status === 'broken' ? Ds.Red : pr?.status === 'kept' ? Ds.Green : Ds.Ink}/>
      <${Kv} label="Last call" value=${c.lastCallAt ? fmtWhen(c.lastCallAt) + (c.lastOutcome ? ' · ' + outcomeLabel(c.lastOutcome) : '') : 'Not called yet'}/><//>
    <${Section} title="Activity"><${Timeline} items=${c.timeline}/><//>`;
}
function Timeline({ items }) {
  if (!items.length) return html`<div class="small">No activity yet.</div>`;
  return items.map((t) => {
    const [title, tint] = ({
      call: () => ['Call · ' + (t.outcome || (t.state || '').replace(/_/g, ' ')), Ds.Blue], entry: () => ['Recorded without call · ' + (t.outcome || ''), Ds.Teal],
      callback: () => [`Callback scheduled for ${fmtWhen(t.dueAt)}${t.status !== 'open' ? ` (${t.status})` : ''}`, Ds.Amber], note: () => ['Note', Ds.Navy],
      status: () => [`Status: ${(t.from || '').replace(/_/g, ' ')} → ${(t.to || '').replace(/_/g, ' ')}`, Ds.Gold], assignment: () => [t.notes || 'Assignment', Ds.Muted],
      location: () => ['Address / map location', Ds.Teal], visit: () => ['Field visit · reached the customer', Ds.Green] })[t.type]?.() || [t.type, Ds.Muted];
    return html`<div class="tl"><i style=${{ background: tint }}></i><div class="grow">
      <div class="tl-t">${title}</div>
      ${t.amount != null && html`<div style=${{ color: Ds.Teal, fontSize: 13 }}>${t.mode ? `Payment ${money(t.amount)} · ${t.mode} · ${t.review}` : `Promised ${money(t.amount)} by ${fmtDate(t.promiseDate)}`}</div>`}
      ${t.type === 'call' && t.talk > 0 && html`<div class="small">Talk ${Math.floor(t.talk / 60)}m ${t.talk % 60}s${t.rec && t.rec !== 'not_required' ? ' · recording ' + t.rec.replace(/_/g, ' ') : ''}</div>`}
      ${t.type !== 'assignment' && t.notes && html`<div style=${{ fontSize: 13 }}>${t.notes}</div>`}
      <div class="small">${[fmtWhen(t.at), t.by].filter(Boolean).join(' · ')}</div></div></div>`;
  });
}
function DetailsTab({ c }) {
  return html`<${Section} title="Customer"><${Kv} label="Name" value=${c.name}/><${Kv} label="Case ID" value=${c.ref}/><${Kv} label="Phone" value=${maskNo(c.phone)}/>
      <${Kv} label="Alternate" value=${maskNo(c.alt)}/><${Kv} label="Email" value=${c.email}/><${Kv} label="Preferred contact time" value=${c.preferred}/><//>
    <${Section} title="Assignment"><${Kv} label="Portfolio" value=${c.portfolio || 'Added by hand'}/><${Kv} label="Campaign" value=${c.campaign}/>
      <${Kv} label="Assigned employee" value=${c.pool ? 'Group pool' : `${D.EMPLOYEE.name} (${D.EMPLOYEE.code})`}/><${Kv} label="Group" value=${c.team}/>
      <${Kv} label="Assigned on" value=${fmtWhen(c.assignedAt)}/><${Kv} label="Case status" value=${c.status.replace(/_/g, ' ')}/><//>
    <${Section} title="Address"><${AddressBlock} c=${c}/><//>`;
}
function DuesTab({ r }) {
  const c = r.c;
  return html`<${Notice} text=${`Amounts are as uploaded on ${fmtDate(c.balanceAsOf)}. Only payments verified by the office are taken off; promises and unchecked payments are not.`} tint=${Ds.Muted}/>
    <${Section} title="Overdue"><${Kv} label="Overdue now" value=${money(r.overdue)} strong color=${Ds.Red}/><${Kv} label="Overdue (as uploaded)" value=${money(c.overdueUploaded)}/>
      <${Kv} label="Days overdue" value=${r.dpd != null ? String(r.dpd) : null}/><${Kv} label="Oldest unpaid instalment" value=${c.oldestDue ? fmtDate(c.oldestDue) : null}/>
      <${Kv} label="Overdue instalments" value=${String(c.overdueInstalments)}/><//>
    <${Section} title="Next instalment"><${Kv} label="Amount" value=${money(c.emi)} strong/><${Kv} label="Due date" value=${fmtDate(c.dueDate)}/><//>
    <${Section} title="Account"><${Kv} label="Loan / account amount" value=${money(c.loan)}/><${Kv} label="Total outstanding (as uploaded)" value=${money(c.outstanding)}/>
      <${Kv} label="Principal outstanding" value=${money(c.principal)}/><${Kv} label="Interest outstanding" value=${money(c.interest)}/><${Kv} label="Late fees / charges" value=${money(c.charges)}/>
      <${Kv} label="Verified payments since upload" value=${money(r.verified)}/><${Kv} label="Remaining balance" value=${money(r.remaining)} strong/>
      <${Kv} label="Last payment (from file)" value=${c.lastPaidFile ? `${money(c.lastPaidFile[0])} on ${fmtDate(c.lastPaidFile[1])}` : null}/><//>
    <div class="small" style=${{ paddingTop: 10 }}>Instalment-wise schedule: not in the uploaded file (only the summary above).</div>`;
}
const MODE_LABEL = Object.fromEntries(D.PAYMENT_MODES);
function PaymentsTab({ r }) {
  const c = r.c;
  return html`<${Section} title="Totals"><${Kv} label="Verified" value=${money(r.verified)} strong color=${Ds.Green}/><${Kv} label="Waiting for office check" value=${money(r.pending)} color=${Ds.Amber}/><//>
    <${Section} title="Payment history">${!c.payments.length && html`<div class="small">No payments recorded in the app yet.</div>`}
      ${c.payments.map((x) => html`<div class="row-c" style=${{ padding: '6px 0' }}><div class="grow"><div class="t-s">${money(x.amount)} · ${MODE_LABEL[x.mode] || x.mode}</div>
        <div class="small">${[`Paid ${fmtDate(x.paidOn)}`, x.ref && `Ref ${x.ref}`, x.by, x.slip && 'slip attached'].filter(Boolean).join(' · ')}</div>
        ${x.note && html`<div class="small">Office: ${x.note}</div>`}</div><${Tag} text=${x.status} c=${x.status === 'verified' ? Ds.Green : x.status === 'rejected' ? Ds.Red : Ds.Amber}/></div>`)}<//>
    <${Section} title="Promises">${!c.promises.length && html`<div class="small">No promises recorded.</div>`}
      ${c.promises.map((x) => html`<${Kv} label=${`${money(x.amount)} by ${fmtDate(x.date)}`} value=${x.status + (x.by ? ' · ' + x.by : '')} color=${x.status === 'broken' ? Ds.Red : x.status === 'kept' ? Ds.Green : Ds.Blue}/>`)}<//>`;
}
function CallsTab({ c }) {
  const calls = c.timeline.filter((t) => t.type === 'call');
  return html`<${Section} title="Summary"><${Kv} label="Calls" value=${String(c.calls)}/><${Kv} label="Connected" value=${String(c.connected)}/><//>
    <${Section} title="Calling history">${!calls.length && html`<div class="small">No calls yet.</div>`}
      ${calls.map((x) => html`<div style=${{ padding: '6px 0' }}><div class="t-s">${fmtWhen(x.at)} · ${x.outcome || x.state.replace(/_/g, ' ')}</div>
        <div class="small">${[x.by, x.which && x.which + ' number', `talk ${Math.floor(x.talk / 60)}m ${x.talk % 60}s`, x.rec && x.rec !== 'not_required' && 'recording ' + x.rec.replace(/_/g, ' ')].filter(Boolean).join(' · ')}</div>
        ${x.notes && html`<div style=${{ fontSize: 13 }}>${x.notes}</div>`}</div>`)}
      <div class="small" style=${{ paddingTop: 6 }}>Recordings are kept by your company; supervisors with permission play them from the admin console.</div><//>`;
}
function FollowUpsTab({ c }) {
  const f = c.timeline.filter((t) => t.type === 'callback');
  return html`<${Section} title="Callbacks">${!f.length && html`<div class="small">No callbacks scheduled.</div>`}
      ${f.map((x) => { const late = x.status === 'open' && x.dueAt < Date.now();
        return html`<${Kv} label=${fmtWhen(x.dueAt)} value=${(late ? 'overdue' : x.status) + (x.by ? ' · ' + x.by : '')} color=${late ? Ds.Red : Ds.Ink}/>${x.notes && html`<div class="small">${x.notes}</div>`}`; })}<//>
    <${Section} title="Commitments">${!c.promises.length && html`<div class="small">No commitments recorded.</div>`}
      ${c.promises.map((x) => html`<${Kv} label=${`${money(x.amount)} by ${fmtDate(x.date)}`} value=${x.status} color=${x.status === 'broken' ? Ds.Red : Ds.Ink}/>`)}<//>`;
}
function AddressBlock({ c }) {
  const a = addressOf(c);
  const copy = () => { navigator.clipboard?.writeText(a).catch(() => {}); toast('Address copied'); };
  return html`<div style=${{ fontSize: 14 }}>${a || 'Address not available.'}</div>
    <div class="flow" style=${{ paddingTop: 10, gap: 8 }}><button type="button" class="fbtn" onClick=${() => openDirections(c)}>Get directions</button>
      <${OutBtn} onClick=${copy}>Copy address<//>${c.lat != null && html`<${OutBtn} onClick=${() => window.open(`https://www.openstreetmap.org/?mlat=${c.lat}&mlon=${c.lng}#map=17/${c.lat}/${c.lng}`, '_blank', 'noopener')}>Open map<//>`}</div>`;
}
function AddressTab({ r }) {
  const c = r.c;
  const [update, setUpdate] = useState(false);
  return html`<${Section} title="Address"><${AddressBlock} c=${c}/><//>
    <${Section} title="Map">${c.lat != null ? html`<${MiniMap} c=${c}/><div class="small" style=${{ paddingTop: 6 }}>Pin saved on this case. Tap the pin or Distance & route for the road route.</div>`
      : html`<div class="small">Location unavailable — no map coordinates are saved for this customer. Directions use the address instead.</div>`}
      <div class="row" style=${{ paddingTop: 10, gap: 8 }}>${c.lat != null && html`<button type="button" class="fbtn" onClick=${() => openFieldMap(c.id)}>Distance & route</button>`}
        <${OutBtn} onClick=${() => setUpdate(true)}>${c.lat == null ? 'Add location' : 'Update location'}<//></div><//>
    <${Section} title="Address history"><${AddressHistory} items=${c.addressHistory}/><//>
    ${update && html`<${UpdateLocationDialog} c=${c} onDone=${() => setUpdate(false)}/>`}`;
}
function AddressHistory({ items }) {
  if (!items.length) return html`<div class="small">No changes since this case was uploaded.</div>`;
  return items.map((h, i) => {
    const moved = h.moved != null ? km(h.moved) : null;
    const pin = !h.oldPin && h.newPin ? 'Pin added' : h.oldPin && !h.newPin ? 'Pin removed' : moved ? `Pin moved ${moved}` : null;
    const title = h.what === 'address' ? 'Address changed' : h.what === 'both' ? 'Address changed · ' + (pin || '') : pin || 'Map location';
    return html`<div class="ah" style=${{ background: i === 0 ? alpha(Ds.Gold, 0.08) : 'transparent' }}>
      <div class="row-c"><span class="grow t-s">${title}</span>${i === 0 && html`<b style=${{ color: Ds.Gold, fontSize: 11 }}>CURRENT</b>`}</div>
      ${h.what !== 'pin' && html`<div style=${{ color: Ds.Muted, fontSize: 13 }}>Previous: ${h.oldAddress || 'no address'}</div><div style=${{ fontSize: 13 }}>Updated: ${h.newAddress || 'no address'}</div>`}
      <div class="small">${[fmtWhen(h.at), h.source, h.by].filter(Boolean).join(' · ')}</div></div>`;
  });
}

function TextDialog({ title, label, onDismiss, onSave }) {
  const [t, setT] = useState('');
  return html`<${Dialog} title=${title} onDismiss=${onDismiss} confirm=${html`<${TextBtn} disabled=${!t.trim()} onClick=${() => onSave(t.trim())}>Save<//>`}
    dismiss=${html`<${TextBtn} onClick=${onDismiss}>Cancel<//>`}><${Field} area value=${t} onInput=${(v) => setT(v.slice(0, 2000))} label=${label} rounded=${false}/><//>`;
}
function StatusDialog({ current, onDismiss, onSave }) {
  const [s, setS] = useState(current);
  const [note, setNote] = useState('');
  return html`<${Dialog} title="Update case status" onDismiss=${onDismiss} confirm=${html`<${TextBtn} disabled=${s === current} onClick=${() => onSave(s, note.trim())}>Save<//>`}
    dismiss=${html`<${TextBtn} onClick=${onDismiss}>Cancel<//>`}>
    ${[['new', 'New'], ['in_progress', 'In progress'], ['follow_up', 'Follow-up'], ['closed', 'Closed (resolved)'], ['do_not_call', 'Do not call']].map(([k, l]) => html`
      <button type="button" class=${'radio-row' + (s === k ? ' on' : '')} onClick=${() => setS(k)}><i class="radio"></i>${l}</button>`)}
    <${Field} value=${note} onInput=${(v) => setNote(v.slice(0, 2000))} label="Reason (optional)" rounded=${false}/><//>`;
}
function CallbackDialog({ onDismiss, onSave }) {
  const [at, setAt] = useState(null);
  const [notes, setNotes] = useState('');
  return html`<${Dialog} title="Schedule callback" onDismiss=${onDismiss} confirm=${html`<${TextBtn} disabled=${!at} onClick=${() => onSave(at, notes.trim())}>Save<//>`}
    dismiss=${html`<${TextBtn} onClick=${onDismiss}>Cancel<//>`}>
    <${PickRow} value=${at} min=${nowLocal()} text=${at ? fmtFull(at) : 'Pick date and time'} placeholder="Pick date and time" onPick=${setAt}/>
    <${Field} value=${notes} onInput=${(v) => setNotes(v.slice(0, 2000))} label="Notes (optional)" rounded=${false}/>
    <div class="small" style=${{ paddingTop: 6 }}>This replaces any earlier open callback for this customer.</div><//>`;
}
/** Promise (commitment) or payment recorded from the profile without a call. */
function EntryDialog({ r, kind, onDismiss }) {
  const c = r.c;
  const options = D.OUTCOMES.filter((o) => o.captures === kind);
  const [code, setCode] = useState(options[0].code);
  const [amount, setAmount] = useState('');
  const [mode, setMode] = useState('');
  const [ref, setRef] = useState('');
  const [notes, setNotes] = useState('');
  const [paidOn, setPaidOn] = useState(todayIso());
  const [promiseAt, setPromiseAt] = useState(null);
  const [slip, setSlip] = useState(null);
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(), camRef = useRef();
  const ok = !!code && Number(amount) > 0 && (kind === 'payment' ? !!mode : !!promiseAt);
  const save = () => {
    if (!S.sync.online) return setErr('Recording a payment or promise needs a connection (the office reviews it with the slip).');
    const amt = Number(amount);
    if (kind === 'payment' && c.payments.some((p) => p.status !== 'rejected' && p.amount === amt && p.paidOn === paidOn && (!ref.trim() || p.ref === ref.trim())))
      return setErr('This payment (same amount, date and reference) is already recorded for this customer.');
    setBusy(true);
    later(800, () => {
      const x = { code, captures: kind, amount: amt, mode: kind === 'payment' ? mode : null, ref: ref.trim(), paidOn, slip, followUp: kind === 'promise' ? promiseAt : null, notes: notes.trim() };
      updateCase(c.id, (k) => { applyEntry(k, x, { type: 'entry', at: Date.now(), by: D.EMPLOYEE.name, outcome: outcomeLabel(code), amount: amt, mode: x.mode, review: x.mode ? 'pending' : null,
        promiseDate: kind === 'promise' ? promiseAt.slice(0, 10) : null, notes: x.notes || null }); return k; });
      onDismiss();
      if (kind === 'payment') { toast('Waiting for office check'); mark('payment'); }
    });
  };
  return html`<${Dialog} title=${kind === 'payment' ? 'Record payment' : 'Record commitment'} onDismiss=${onDismiss}
    confirm=${html`<${TextBtn} disabled=${!ok || busy} onClick=${save}>${busy ? 'Saving…' : 'Save'}<//>`} dismiss=${html`<${TextBtn} onClick=${onDismiss}>Cancel<//>`}>
    <div class="small">${c.name} · ${c.ref}</div>
    <div class="flow" style=${{ padding: '8px 0', gap: 6 }}>${options.map((o) => html`<${Pill} text=${o.label} selected=${code === o.code} onClick=${() => setCode(o.code)}/>`)}</div>
    <${Field} value=${amount} onInput=${(v) => setAmount(v.replace(/[^\d.]/g, '').slice(0, 11))} label=${kind === 'payment' ? 'Amount received (₹)' : 'Promised amount (₹)'} inputMode="decimal" rounded=${false}/>
    ${kind === 'promise' ? html`<${PickRow} value=${promiseAt} min=${nowLocal()} text=${promiseAt ? fmtFull(promiseAt) : 'Promised payment date (required)'} placeholder="Promised payment date" onPick=${setPromiseAt}/>`
    : html`<div class="small" style=${{ margin: '8px 0 4px' }}>Payment mode</div>
      <div class="flow" style=${{ gap: 6 }}>${D.PAYMENT_MODES.map(([k, l]) => html`<${Pill} text=${l} selected=${mode === k} onClick=${() => setMode(k)}/>`)}</div>
      <${Field} value=${ref} onInput=${(v) => setRef(v.slice(0, 64))} label=${mode === 'CASH' ? 'Receipt no. (optional)' : 'Reference / UTR / cheque no.'} rounded=${false}/>
      <${PickRow} kind="date" icon=${false} value=${paidOn} max=${todayIso()} text=${'Paid on ' + fmtDate(paidOn)} placeholder="Paid on" onPick=${setPaidOn}/>
      <div class="row-c" style=${{ paddingTop: 8, gap: 8 }}>${slip ? html`<span class="grow" style=${{ color: Ds.Green }}>Slip attached</span><${TextBtn} color=${Ds.Red} onClick=${() => setSlip(null)}>Remove<//>`
        : html`<${OutBtn} onClick=${() => TOUR ? setSlip('IMG_20261014_1142.jpg') : camRef.current.click()}>Take photo<//><${OutBtn} onClick=${() => fileRef.current.click()}>Choose file<//>`}</div>
      <input ref=${camRef} type="file" accept="image/*" capture="environment" hidden onChange=${(e) => e.target.files[0] && setSlip(e.target.files[0].name)}/>
      <input ref=${fileRef} type="file" accept="image/*,application/pdf" hidden onChange=${(e) => e.target.files[0] && setSlip(e.target.files[0].name)}/>
      <div class="small">The office checks the payment before it reduces the balance.</div>`}
    <${Field} value=${notes} onInput=${(v) => setNotes(v.slice(0, 2000))} label="Notes (optional)" rounded=${false}/>
    ${err && html`<div class="err-t" style=${{ paddingTop: 6 }}>${err}</div>`}<//>`;
}

/** Set a case's map pin: GPS at the customer's door, or the match for the case address. */
const AREA_CENTRE = { Lingampally: [17.4943, 78.3192], Serilingampally: [17.4859, 78.3164] };
function UpdateLocationDialog({ c, onDone }) {
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  const [found, setFound] = useState(null);
  const run = (fn) => { setBusy(true); setMsg(null); later(900, () => { setBusy(false); fn(); }); };
  const gps = () => run(() => {
    const f = fixOrNull();
    if (!f) return setMsg('Your location could not be detected. Turn on GPS and try again.');
    setFound({ lat: f.lat, lng: f.lng, label: `Your position (±${Math.round(f.acc)} m)`, source: 'gps', acc: f.acc });
  });
  const geo = () => run(() => {
    const centre = c.lat != null ? [c.lat + 0.0006, c.lng - 0.0004] : AREA_CENTRE[c.area] || [17.45, 78.36];
    setFound({ lat: centre[0], lng: centre[1], label: addressOf(c), source: 'address' });
    if (c.lat == null) setMsg('Google matched only the area, not the building. Prefer your GPS at the door.');
  });
  const save = () => run(() => {
    updateCase(c.id, (k) => {
      const old = k.lat != null ? { lat: k.lat, lng: k.lng } : null;
      return { ...k, lat: found.lat, lng: found.lng, locIssue: null,
        addressHistory: [{ what: 'pin', at: Date.now(), by: D.EMPLOYEE.name, source: found.source === 'gps' ? 'GPS at the door' : 'Address lookup', oldPin: old, newPin: { lat: found.lat, lng: found.lng },
          moved: old ? Math.round(distM(old, found)) : null }, ...k.addressHistory],
        timeline: [{ type: 'location', at: Date.now(), by: D.EMPLOYEE.name, notes: found.source === 'gps' ? `Pin set at the customer's door (GPS ±${Math.round(found.acc)} m)` : 'Pin set from the case address' }, ...k.timeline] };
    });
    onDone();
  });
  return html`<${Dialog} title=${'Update location · ' + c.name} onDismiss=${onDone} confirm=${html`<${TextBtn} disabled=${!found || busy} onClick=${save}>Save pin<//>`}
    dismiss=${html`<${TextBtn} onClick=${onDone}>Cancel<//>`}>
    <div class="small">Use your GPS when you are at the customer's door, or look up the case address.</div>
    <div class="row" style=${{ paddingTop: 10, gap: 8 }}><${OutBtn} disabled=${busy} onClick=${gps}>My location<//><${OutBtn} disabled=${busy || !c.address} onClick=${geo}>From address<//></div>
    ${busy && html`<div style=${{ paddingTop: 10 }}><${Spinner} s=${18}/></div>`}
    ${found && html`<div style=${{ paddingTop: 10, fontSize: 13 }}>${found.label}<br/>${found.lat.toFixed(5)}, ${found.lng.toFixed(5)}</div>`}
    ${msg && html`<div style=${{ paddingTop: 8, fontSize: 13, color: Ds.Amber }}>${msg}</div>`}<//>`;
}

// ───────────────────────────── maps (Leaflet + OpenStreetMap in place of Google Maps) ─────────────────────────────
const THEME = { normal: { bg: '#FFFFFF', fg: '#15202E', dot: '#DC2626', bd: '#D3DAE3' }, nearest: { bg: '#C9A35E', fg: '#13243B', dot: '#13243B', bd: '#A9843F' },
  selected: { bg: '#1F3A5F', fg: '#FFFFFF', dot: '#C9A35E', bd: '#13243B' }, stop: { bg: '#5B3CC4', fg: '#FFFFFF', dot: '#FFFFFF', bd: '#46309A' } };
const Z = { normal: 1, stop: 20, nearest: 25, selected: 30 };
const measure = document.createElement('canvas').getContext('2d');
const esc = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
/** Customer marker = name chip with a pointer (same drawing as field-map.html). */
function chipIcon(name, k, num) {
  measure.font = '600 12px Roboto, Arial, sans-serif';
  const t = THEME[k] || THEME.normal, tw = Math.ceil(measure.measureText(name).width);
  const lead = num ? 30 : 22, w = lead + tw + 12, h = 26, W = w + 4, H = h + 12;
  const body = (dx, dy, fill, stroke) => `<path d="M${2 + dx + 13} ${1 + dy}h${w - 26}a13 13 0 0 1 0 ${h}h-${w / 2 - 19}l-6 7l-6 -7h-${w / 2 - 19}a13 13 0 0 1 0 -${h}z" fill="${fill}"${stroke ? ` stroke="${stroke}" stroke-width="1"` : ''}/>`;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}">${body(0, 2, 'rgba(15,23,42,0.18)')}${body(0, 0, t.bg, t.bd)}` +
    (num ? `<circle cx="17" cy="${1 + h / 2}" r="9" fill="${t.dot}"/><text x="17" y="${1 + h / 2 + 4}" font-family="Roboto,Arial,sans-serif" font-size="11" font-weight="700" text-anchor="middle" fill="${t.bg}">${esc(num)}</text>`
      : `<circle cx="14" cy="${1 + h / 2}" r="4" fill="${t.dot}"/>`) +
    `<text x="${2 + lead}" y="${1 + h / 2 + 4}" font-family="Roboto,Arial,sans-serif" font-size="12" font-weight="600" fill="${t.fg}">${esc(name)}</text></svg>`;
  return L.icon({ iconUrl: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg), iconSize: [W, H], iconAnchor: [W / 2, h + 8] });
}
const meIcon = () => L.divIcon({ className: 'me-dot', html: '<i></i><b></b>', iconSize: [22, 22], iconAnchor: [11, 11] });
const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const tiles = () => L.tileLayer(TILE_URL, { maxZoom: 19, attribution: '© OpenStreetMap', className: 'osm' });

function MiniMap({ c }) {
  const el = useRef();
  useEffect(() => {
    const m = L.map(el.current, { zoomControl: false, attributionControl: true, dragging: true }).setView([c.lat, c.lng], 16);
    tiles().addTo(m);
    L.marker([c.lat, c.lng], { icon: chipIcon(c.name.length > 22 ? c.name.slice(0, 21) + '…' : c.name, 'selected') }).addTo(m).on('click', () => openFieldMap(c.id));
    const f = fixOrNull(); if (f) L.marker([f.lat, f.lng], { icon: meIcon(), interactive: false }).addTo(m);
    return () => m.remove();
  }, [c.lat, c.lng]);
  return html`<div class="minimap" ref=${el}></div>`;
}

function openFieldMap(focus = null) { set({ fieldOpen: true, fieldFocus: focus, profileId: null }); mark('map'); }

/** Road distance/time stand-in: OSRM when reachable, else straight line × 1.3 at 25 km/h (the app asks Google Routes). */
const roadOf = (m) => ({ meters: Math.round(m * 1.32), seconds: Math.round((m * 1.32) / 6.9) });
async function osrm(points) {
  const coords = points.map((p) => `${p.lng.toFixed(6)},${p.lat.toFixed(6)}`).join(';');
  const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 6000);
  try {
    const r = await fetch(`https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson`, { signal: ctl.signal });
    const j = await r.json();
    if (j.code !== 'Ok') throw new Error(j.code);
    const rt = j.routes[0];
    return { points: rt.geometry.coordinates.map(([lng, lat]) => ({ lat, lng })), meters: Math.round(rt.distance), seconds: Math.round(rt.duration * 1.15),
      legs: rt.legs.map((l) => ({ meters: Math.round(l.distance), seconds: Math.round(l.duration * 1.15) })) };
  } finally { clearTimeout(t); }
}
function straightRoute(points) {
  const legs = points.slice(1).map((p, i) => roadOf(distM(points[i], p)));
  return { points, meters: legs.reduce((a, l) => a + l.meters, 0), seconds: legs.reduce((a, l) => a + l.seconds, 0), legs, simulated: true };
}

const RADII = [[0, 'All'], [1, '1 km'], [2, '2 km'], [5, '5 km'], [10, '10 km'], [25, '25 km']];
const FAR = 300000, MAX_STOPS = 10, ARRIVE = 100;

function FieldMapScreen({ rows }) {
  const fix = fixOrNull();
  const locState = S.locPerm ? 'ok' : 'denied';
  const online = S.sync.online;
  const [filter, setFilter] = useState({ ...S.filter, tile: '', sort: 'dpd' });
  const [radius, setRadius] = useState(0);
  const [selected, setSelected] = useState(null);
  const [route, setRoute] = useState(null);
  const [routeBusy, setRouteBusy] = useState(false);
  const [roadBusy, setRoadBusy] = useState(true);
  const [note, setNote] = useState(online ? null : 'No connection — straight-line distance is shown.');
  const [planning, setPlanning] = useState(false);
  const [picked, setPicked] = useState([]);
  const [fixCase, setFixCase] = useState(null);
  const [visits, setVisits] = useState({});
  const [checkBusy, setCheckBusy] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [mapReady, setMapReady] = useState(false);
  const mapEl = useRef(), mapRef = useRef(), layers = useRef({}), listRef = useRef(), drag = useRef(null);

  useEffect(() => { const t = later(700, () => setRoadBusy(false)); return () => clearTimeout(t); }, [fix?.lat, fix?.lng]);

  const shown = applyCaseFilter(rows, filter, null);
  const pinned = shown.filter((r) => r.lat != null);
  const unpinned = shown.filter((r) => r.lat == null);
  const withStraight = pinned.map((r) => [r, fix ? distM(fix, r) : null]);
  const far = withStraight.filter(([, m]) => m != null && m > FAR).map(([r]) => r);
  const inRadius = withStraight.filter(([, m]) => (m == null || m <= FAR) && (radius === 0 || (m != null && m <= radius * 1000)));
  const candidates = new Set([...inRadius].sort((a, b) => (a[1] ?? Infinity) - (b[1] ?? Infinity)).slice(0, 10).map(([r]) => r.c.id));
  const nearby = inRadius.map(([r, m]) => ({ r, straight: m, road: online && !roadBusy && m != null && candidates.has(r.c.id) ? roadOf(m) : null }));
  nearby.sort((a, b) => (a.road && b.road) ? a.road.seconds - b.road.seconds : a.road ? -1 : b.road ? 1 : (a.straight ?? Infinity) - (b.straight ?? Infinity));
  const nearest = fix ? nearby[0] : null;
  const sel = selected != null ? (nearby.find((n) => n.r.c.id === selected) || (() => { const r = pinned.find((x) => x.c.id === selected); return r && { r, straight: fix ? distM(fix, r) : null, road: null }; })()) : null;
  const hero = sel || nearest;
  const arrived = hero?.straight != null && hero.straight <= ARRIVE;

  const fit = (pts) => {
    const m = mapRef.current; if (!m || !pts.length) return;
    if (pts.length === 1) m.setView([pts[0].lat, pts[0].lng], 16, { animate: true });
    else m.fitBounds(L.latLngBounds(pts.map((p) => [p.lat, p.lng])), { paddingTopLeft: [30, 150], paddingBottomRight: [60, 320], maxZoom: 16 });
  };
  const loadRoute = async (ids, optimize) => {
    if (!fix) { setNote('Your location is needed for a route. Tap Refresh location.'); return; }
    let stops = ids.map((id) => pinned.find((r) => r.c.id === id)).filter(Boolean);
    if (optimize) {   // nearest-next from here; the farthest customer ends up last
      const left = [...stops], out = []; let at = fix;
      while (left.length) { left.sort((a, b) => distM(at, a) - distM(at, b)); const n = left.shift(); out.push(n); at = n; }
      stops = out;
    }
    setRouteBusy(true);
    const pts = [{ lat: fix.lat, lng: fix.lng }, ...stops.map((r) => ({ lat: r.lat, lng: r.lng }))];
    let rt;
    try { if (!online) throw new Error('offline'); rt = await osrm(pts); } catch { rt = straightRoute(pts); }
    setRouteBusy(false);
    setRoute({ ...rt, order: stops.map((r) => r.c.id) });
    setNote(online ? null : 'No connection — straight-line distance is shown.');
    fit([{ lat: fix.lat, lng: fix.lng }, ...rt.points]);
  };
  const selectAndRoute = (id) => {
    const willRoute = !!fix && !planning && !(route && route.order.length === 1 && route.order[0] === id);
    setSelected(id);
    const r = pinned.find((x) => x.c.id === id);
    if (!willRoute && r) fit([r]);
    if (listRef.current) listRef.current.scrollTop = 0;
    if (willRoute) loadRoute([id], false);
  };
  const findNearest = () => {
    if (!fix) return setNote(S.locPerm ? 'Location not found' : 'Allow location so the app can show where you are and which customers are nearest.');
    const ids = pinned.filter((r) => distM(fix, r) <= FAR && (radius === 0 || distM(fix, r) <= radius * 1000)).sort((a, b) => distM(fix, a) - distM(fix, b)).map((r) => r.c.id);
    if (!ids.length) return setNote(radius === 0 ? 'No customers with a map location.' : `No nearby customers found within ${radius} km.`);
    setSelected(ids[0]); loadRoute([ids[0]], false);
  };
  const refreshFix = () => { setRoadBusy(true); set({ fix: { ...S.fix, time: Date.now() } }); if (fix) fit([fix]); };
  const checkIn = (r) => {
    setCheckBusy(true);
    later(900, () => {
      setCheckBusy(false);
      if (!online) return setNote('No connection — straight-line distance is shown.');
      const d = Math.round(distM(fix, r));
      setVisits({ ...visits, [r.c.id]: hm(new Date()) });
      updateCase(r.c.id, (k) => ({ ...k, timeline: [{ type: 'visit', at: Date.now(), by: D.EMPLOYEE.name, notes: `Reached the customer (${d} m from the pin)` }, ...k.timeline] }));
      mark('checkin');
    });
  };

  // ── Leaflet map (created once; layers redrawn on change) ──
  useEffect(() => {
    if (!online) return;
    const m = L.map(mapEl.current, { zoomControl: false, attributionControl: true }).setView([D.HOME_FIX.lat, D.HOME_FIX.lng], 13);
    const tl = tiles().addTo(m);
    tl.once('load', () => setMapReady(true));
    later(4000, () => setMapReady(true));
    mapRef.current = m;
    layers.current = { pins: L.layerGroup().addTo(m), route: L.layerGroup().addTo(m), me: L.layerGroup().addTo(m), zone: L.layerGroup().addTo(m) };
    const focus = S.fieldFocus && pinned.find((r) => r.c.id === S.fieldFocus);
    if (focus) { setSelected(focus.c.id); fit([focus]); S.fieldFocus = null; }
    else fit([...(fix ? [fix] : []), ...inRadius.slice(0, 30).map(([r]) => r)]);
    return () => { m.remove(); mapRef.current = null; };
  }, [online]);
  const stops = route?.order || [];
  const pinKey = JSON.stringify([nearby.map((n) => n.r.c.id), sel?.r.c.id, nearest?.r.c.id, stops, filter.query]);
  useEffect(() => {
    const g = layers.current.pins; if (!g || !mapRef.current) return;
    g.clearLayers();
    const list = [...nearby.map((n) => n.r), ...(sel && !nearby.some((n) => n.r.c.id === sel.r.c.id) ? [sel.r] : [])];
    list.forEach((r) => {
      const id = r.c.id, k = id === sel?.r.c.id ? 'selected' : id === nearest?.r.c.id ? 'nearest' : stops.includes(id) ? 'stop' : 'normal';
      const name = r.c.name.length > 22 ? r.c.name.slice(0, 21) + '…' : r.c.name;
      L.marker([r.lat, r.lng], { icon: chipIcon(name, k, stops.includes(id) && stops.length > 1 ? String(stops.indexOf(id) + 1) : null), zIndexOffset: Z[k] * 100, title: r.c.name })
        .on('click', () => { selectAndRoute(id); setExpanded(false); }).addTo(g);
    });
  }, [pinKey, mapReady]);
  useEffect(() => {
    const g = layers.current; if (!g.me || !mapRef.current) return;
    g.me.clearLayers(); g.zone.clearLayers(); g.route.clearLayers();
    if (fix) { L.circle([fix.lat, fix.lng], { radius: fix.acc, color: '#2563EB', opacity: 0.25, weight: 1, fillColor: '#2563EB', fillOpacity: 0.10, interactive: false }).addTo(g.me);
      L.marker([fix.lat, fix.lng], { icon: meIcon(), interactive: false, zIndexOffset: 5000 }).addTo(g.me); }
    if (hero) { const c = arrived ? '#16A34A' : '#C9A35E';
      L.circle([hero.r.lat, hero.r.lng], { radius: ARRIVE, color: c, opacity: 0.9, weight: 1.5, fillColor: c, fillOpacity: arrived ? 0.16 : 0.08, interactive: false }).addTo(g.zone); }
    if (route) { const ll = route.points.map((p) => [p.lat, p.lng]);
      L.polyline(ll, { color: '#0F2A57', opacity: 0.9, weight: 10, interactive: false }).addTo(g.route);
      L.polyline(ll, { color: '#3B82F6', weight: 6, interactive: false }).addTo(g.route);
      if (hero) L.polyline([ll[ll.length - 1], [hero.r.lat, hero.r.lng]], { color: '#3B82F6', weight: 3, dashArray: '2 8', interactive: false }).addTo(g.route); }
  }, [fix?.lat, fix?.lng, hero?.r.c.id, arrived, route, mapReady]);
  useEffect(() => { mapRef.current?.invalidateSize(); }, [expanded]);

  const onHandleDown = (e) => { drag.current = e.clientY; };
  const onHandleUp = (e) => { if (drag.current == null) return; const dy = e.clientY - drag.current; drag.current = null; setExpanded(Math.abs(dy) < 6 ? !expanded : dy < 0); };
  const navigate = (list) => {
    const ll = (r) => `${r.lat},${r.lng}`, dest = list[list.length - 1];
    window.open('https://www.google.com/maps/dir/?api=1&travelmode=two-wheeler&dir_action=navigate&destination=' + encodeURIComponent(ll(dest)) +
      (list.length > 1 ? '&waypoints=' + encodeURIComponent(list.slice(0, -1).map(ll).join('|')) : ''), '_blank', 'noopener');
  };
  const close = () => { if (planning) { setPlanning(false); setPicked([]); } else set({ fieldOpen: false }); };

  return html`<div class="fmap">
    <div class="fmap-map">
      ${online ? html`<div class="leaf" ref=${mapEl}></div>${!mapReady && html`<${MapProblem} stage/>`}` : html`<${MapProblem} err="offline"/>`}
    </div>
    <div class="fmap-top">
      <div class="row-c" style=${{ padding: '0 12px', gap: 8 }}>
        <${RoundBtn} n="ArrowBack" label="Back to cases" onClick=${close}/>
        <label class="msearch"><${Icon} n="Search" s=${20} c=${Ds.Muted}/><input value=${filter.query} placeholder="Search customer" onInput=${(e) => setFilter({ ...filter, query: e.target.value })}/>
          ${filter.query && html`<button type="button" class="x" aria-label="Clear search" onClick=${() => setFilter({ ...filter, query: '' })}><${Icon} n="Close" s=${20} c=${Ds.Muted}/></button>`}
          ${(roadBusy || routeBusy) && html`<${Spinner} s=${16} c=${Ds.Gold}/>`}</label></div>
      <div class="hscroll" style=${{ padding: '10px 12px', gap: 8 }}>${RADII.map(([k, l]) => html`<button type="button" class=${'fchip2' + (radius === k ? ' on' : '')} onClick=${() => setRadius(k)}>${k === 0 ? 'All' : 'Within ' + l}</button>`)}</div>
    </div>
    <div class="fmap-ctl">
      <${RoundBtn} n="MyLocation" label="My location" onClick=${refreshFix}/>
      <${RoundBtn} n="FitAll" label="Show all customers" onClick=${() => { setSelected(null); setRoute(null); fit([...(fix ? [fix] : []), ...inRadius.map(([r]) => r)]); }}/>
      ${route && html`<${RoundBtn} n="Close" label="Clear route" onClick=${() => setRoute(null)}/>`}
    </div>
    <div class=${'bsheet' + (expanded ? ' open' : '')}>
      <div class="bs-handle" onPointerDown=${onHandleDown} onPointerUp=${onHandleUp} role="button" aria-label=${expanded ? 'Collapse list' : 'Expand list'}><i></i></div>
      <div class="bs-list" ref=${listRef}>
        ${hero ? html`<${CustomerSheetCard} n=${hero} label=${sel ? (hero.r.c.id === nearest?.r.c.id ? 'NEAREST · SELECTED' : 'SELECTED') : 'NEAREST CUSTOMER'}
            route=${route && route.order.length === 1 && route.order[0] === hero.r.c.id ? route : null} routeBusy=${routeBusy} callEnabled=${!S.busy && !S.call}
            onNavigate=${() => navigate([hero.r])} onRoute=${() => { setSelected(hero.r.c.id); loadRoute([hero.r.c.id], false); }}
            onCall=${() => placeCall(hero.r.c, 'primary')} onCollect=${() => { set({ fieldOpen: false }); openProfile(hero.r.c.id, 'payment'); }}
            onView=${() => { set({ fieldOpen: false }); openProfile(hero.r.c.id); }} onFix=${() => setFixCase(hero.r.c)}
            arrived=${arrived} checkedIn=${visits[hero.r.c.id]} checkBusy=${checkBusy} onCheckIn=${() => checkIn(hero.r)}
            onClose=${sel ? () => { setSelected(null); setRoute(null); } : null}/>`
          : html`<${LocationPrompt} state=${locState} noPins=${pinned.length === 0} noCases=${rows.length === 0}/>`}
        <div class="row-c me-row"><i style=${{ background: fix ? Ds.Blue : Ds.Muted }}></i>
          <div class="grow ell"><div class="t-s" style=${{ fontWeight: 500 }}>${D.EMPLOYEE.name}</div>
            <div class="small ell" style=${{ color: !fix ? Ds.Red : Ds.Muted }}>${fix ? `${fix.lat.toFixed(5)}, ${fix.lng.toFixed(5)} · ±${Math.round(fix.acc)} m · ${hm(new Date(fix.time))}` : 'Location permission is off'}</div></div>
          <${TextBtn} color=${Ds.Navy} onClick=${refreshFix}>Refresh<//>
          <button type="button" class="gold-btn" disabled=${roadBusy || routeBusy || !fix} onClick=${findNearest}>Find nearest</button></div>
        ${note && html`<div class="noteline"><${Icon} n="Warning" s=${16}/>${note}</div>`}
        <div class="hscroll" style=${{ padding: '6px 16px', gap: 6 }}>${[['', 'All cases'], ...STATUS].map(([k, l]) => html`<${Pill} text=${l} selected=${filter.status === k} onClick=${() => setFilter({ ...filter, status: k })}/>`)}</div>
        <div style=${{ padding: '4px 16px' }}>
          <div class="row-c"><span class="grow t-s">${planning ? `Tick up to ${MAX_STOPS} customers below` : `Customers ${radius === 0 ? 'by distance' : `within ${radius} km`} · ${nearby.length}`}</span>
            <${TextBtn} color=${Ds.Navy} onClick=${() => { setPlanning(!planning); if (planning) setPicked([]); }}>${planning ? 'Cancel' : 'Plan a route'}<//></div>
          ${planning && html`<div class="row" style=${{ gap: 8 }}><button type="button" class="fbtn" disabled=${!picked.length || routeBusy} onClick=${() => { setSelected(null); loadRoute(picked, true); }}>Show route (${picked.length})</button>
            ${route && route.order.length > 1 && html`<button type="button" class="fbtn" style=${{ background: Ds.Gold, color: Ds.NavyInk }} onClick=${() => navigate(route.order.map((id) => pinned.find((r) => r.c.id === id)).filter(Boolean))}>Navigate all</button>`}</div>`}
          ${route && route.order.length > 1 && html`<${RouteSummary} r=${route} pinned=${pinned}/>`}
        </div>
        ${nearby.length === 0 && html`<div class="small" style=${{ padding: '12px 16px' }}>${rows.length === 0 ? 'No customers are assigned to you yet.' : pinned.length === 0 ? 'No customers have valid location information.'
          : !fix && radius > 0 ? 'Your current location could not be detected. Please enable location permission.' : radius > 0 ? `No nearby customers found within ${radius} km.` : 'No customers match the search.'}</div>`}
        ${nearby.map((n, i) => html`<${NearRow} key=${n.r.c.id} no=${i + 1} n=${n} highlighted=${n.r.c.id === selected} nearest=${n.r.c.id === nearest?.r.c.id} planning=${planning}
          picked=${picked.includes(n.r.c.id)} onPick=${(on) => setPicked(on ? [...picked, n.r.c.id].slice(0, MAX_STOPS) : picked.filter((x) => x !== n.r.c.id))} onClick=${() => selectAndRoute(n.r.c.id)}/>`)}
        ${(unpinned.length > 0 || far.length > 0) && html`<div style=${{ padding: '10px 16px' }}><div class="t-s">Location unavailable · ${unpinned.length + far.length}</div>
          ${[...unpinned.map((r) => [r, r.c.locIssue === 'invalid' ? 'Wrong coordinates in the file' : 'No map location']), ...far.map((r) => [r, 'Pin is over 300 km away — check it'])].map(([r, why]) => html`
            <div class="row-c" style=${{ padding: '6px 0' }}><i class="dot" style=${{ background: Ds.Amber }}></i><div class="grow ell" style=${{ marginLeft: 10 }}>
              <div class="ell" style=${{ fontWeight: 500 }}>${r.c.name}</div><div class="small clamp2">${why} · ${addressOf(r.c)}</div></div>
              <${TextBtn} color=${Ds.Navy} onClick=${() => setFixCase(r.c)}>Set pin<//></div>`)}</div>`}
        <div style=${{ height: 40 }}></div>
      </div>
    </div>
    ${fixCase && html`<${UpdateLocationDialog} c=${caseById(fixCase.id)} onDone=${() => setFixCase(null)}/>`}
  </div>`;
}
const RoundBtn = ({ n, label, onClick }) => html`<button type="button" class="round" aria-label=${label} title=${label} onClick=${onClick}><${Icon} n=${n} s=${22} c=${Ds.Navy}/></button>`;
function MapProblem({ err, stage }) {
  const [t, b] = err === 'offline' ? ['Map could not load', 'Check the internet connection and try again.'] : ['Loading map…', 'Step: drawing the map'];
  return html`<div class="mprob">${err ? html`<${IconTile} n="Place" tint=${Ds.Amber} size=${44}/>` : html`<${Spinner} s=${28} w=${3}/>`}
    <div class="t-m" style=${{ paddingTop: 10 }}>${t}</div><div class="small">${b}</div>
    ${err && html`<div class="small" style=${{ color: Ds.Muted }}>Distances, list and navigation below still work.</div>`}</div>`;
}
function LocationPrompt({ state, noPins, noCases }) {
  const [t, b] = state === 'denied' ? ['Turn on location', 'Allow location so the app can show where you are and which customers are nearest.']
    : noCases ? ['No customers yet', 'Customers appear here once your supervisor assigns them.'] : noPins ? ['No customer has a map location', 'Open a customer below and use Set pin when you are at their address.']
    : ['Finding your location…', 'This takes a few seconds outdoors.'];
  return html`<div class="shcard"><div class="t-m">${t}</div><div class="small" style=${{ paddingTop: 4 }}>${b}</div>
    ${state === 'denied' && html`<button type="button" class="fbtn full" style=${{ marginTop: 12 }} onClick=${() => set({ locPerm: true })}>Allow location</button>`}</div>`;
}
function CustomerSheetCard({ n, label, route, routeBusy, callEnabled, onNavigate, onRoute, onCall, onCollect, onView, onFix, arrived, checkedIn, checkBusy, onCheckIn, onClose }) {
  const r = n.r, today = todayIso(), gold = label.startsWith('NEAREST');
  const rt = route ? { meters: route.meters, seconds: route.seconds } : n.road;
  return html`<div class="shcard" style=${{ borderColor: gold ? Ds.Gold : Ds.Blue }}>
    <div class="row" style=${{ alignItems: 'flex-start' }}><div class="grow ell"><div class="lab-s" style=${{ color: gold ? Ds.Gold : Ds.Blue }}>${label}</div>
      <div class="ell" style=${{ fontWeight: 600, fontSize: 18 }}>${r.c.name}</div><div class="small ell">${[r.c.ref, r.c.portfolio].join(' · ')}</div></div>
      <${StatusTag} status=${r.c.status}/>${onClose && html`<button type="button" class="x" style=${{ marginLeft: 8 }} aria-label="Close" onClick=${onClose}><${Icon} n="Close" s=${22} c=${Ds.Muted}/></button>`}</div>
    <div class="hscroll" style=${{ paddingTop: 10, gap: 6 }}>
      ${rt && html`<span class="dchip strong">🏍 ${km(rt.meters)} · ${eta(rt.seconds)}</span>`}${n.straight != null && html`<span class="dchip">${km(n.straight)} straight line</span>`}
      ${!rt && n.straight == null && html`<span class="dchip">Distance needs your location</span>`}${route?.simulated && html`<span class="dhint inline">estimated</span>`}</div>
    <div class="row" style=${{ paddingTop: 12 }}><${Metric} label="Overdue" value=${money(r.overdue)} color=${(r.dpd || 0) > 0 ? Ds.Red : Ds.Ink}/><${Metric} label="Outstanding" value=${money(r.outstanding)}/>
      <${Metric} label="Collected today" value=${r.lastPaymentOn === today ? money(r.lastPaymentAmount) : '—'}/></div>
    <div class="small ell" style=${{ paddingTop: 6 }}>${[r.c.lastCallAt ? 'Last call ' + fmtWhen(r.c.lastCallAt) : 'Not called yet', r.promiseStatus === 'open' && `Promise ${money(r.promiseAmount)} on ${r.promiseDate}`].filter(Boolean).join(' · ')}</div>
    ${checkedIn ? html`<div class="okbox big"><${Icon} n="Check" s=${20}/><div><b>Checked in at ${checkedIn}</b><div class="small">Collect payment or record the outcome below</div></div></div>`
      : arrived ? html`<button type="button" class="checkin" disabled=${checkBusy} onClick=${onCheckIn}><${Icon} n="Place" s=${20}/><span><b>${checkBusy ? 'Checking in…' : 'I\'ve reached · Check in'}</b>
          ${n.straight != null && html`<small>You are ${km(n.straight)} from the customer</small>`}</span></button>`
      : html`<button type="button" class="navbtn" onClick=${onNavigate}><${Icon} n="Navigate" s=${18}/>Start navigation</button>`}
    <div class="row" style=${{ paddingTop: 10, gap: 8 }}>
      <${SheetAction} n="Route" label=${routeBusy ? 'Routing…' : 'Route'} enabled=${!routeBusy} onClick=${onRoute}/><${SheetAction} n="Call" label="Call" enabled=${callEnabled} onClick=${onCall}/>
      <${SheetAction} n="Check" label="Collect" onClick=${onCollect}/><${SheetAction} n="People" label="Details" onClick=${onView}/></div>
    <button type="button" class="lnk" style=${{ paddingTop: 10, fontSize: 12 }} onClick=${onFix}>Pin in the wrong place? Set pin</button></div>`;
}
const SheetAction = ({ n, label, enabled = true, onClick }) => html`<button type="button" class="sact" disabled=${!enabled} onClick=${onClick}><${Icon} n=${n} s=${20} c=${enabled ? Ds.Navy : Ds.Muted}/><span>${label}</span></button>`;
function NearRow({ no, n, highlighted, nearest, planning, picked, onPick, onClick }) {
  return html`<div class="near" onClick=${onClick} style=${{ background: highlighted ? alpha(Ds.Blue, 0.08) : '#fff', borderColor: highlighted ? Ds.Blue : nearest ? Ds.Gold : Ds.Line }}>
    ${planning ? html`<input type="checkbox" class="cbx" checked=${picked} onClick=${(e) => e.stopPropagation()} onChange=${(e) => onPick(e.target.checked)}/>`
      : html`<span class="nno" style=${{ background: nearest ? Ds.Gold : alpha(Ds.Navy, 0.08), color: nearest ? '#fff' : Ds.Navy }}>${no}</span>`}
    <div class="grow ell" style=${{ marginLeft: 12 }}><div class="ell" style=${{ fontWeight: 600, fontSize: 15 }}>${n.r.c.name}</div><div class="small">Overdue ${money(n.r.overdue)}</div></div>
    <div style=${{ textAlign: 'right' }}><div style=${{ fontWeight: 600, fontSize: 14 }}>${n.road ? km(n.road.meters) : n.straight != null ? '≈ ' + km(n.straight) : '—'}</div>
      <div class="small">${n.road ? eta(n.road.seconds) : n.straight != null ? 'straight line' : ''}</div></div></div>`;
}
function RouteSummary({ r, pinned }) {
  return html`<div class="shcard" style=${{ borderColor: Ds.Blue, margin: '6px 0' }}><div class="lab-s" style=${{ color: Ds.Blue }}>ROUTE · ${r.order.length} STOPS</div>
    <div style=${{ fontWeight: 600, fontSize: 16 }}>${km(r.meters)} · ${eta(r.seconds)} by bike</div>
    ${r.order.map((id, i) => html`<div class="row-c" style=${{ paddingTop: 6 }}><span class="stopno">${i + 1}</span><span class="grow ell" style=${{ marginLeft: 8, fontSize: 14 }}>${pinned.find((x) => x.c.id === id)?.c.name || '#' + id}</span>
      ${r.legs[i] && html`<span class="small">+${km(r.legs[i].meters)} · ${eta(r.legs[i].seconds)}</span>`}</div>`)}
    <div class="small" style=${{ paddingTop: 8 }}>Order chosen for the shortest drive; the farthest customer is last.</div></div>`;
}

// ───────────────────────────── demo shell (outside the phone) ─────────────────────────────
function resetDemo() { timers.forEach(clearTimeout); timers.clear(); const keepPanel = S.panel; Object.assign(S, fresh(EMBED ? 'dashboard' : 'connect'), { panel: keepPanel }); redraw(); }
const atWork = () => S.screen === 'dashboard';
const ACTIONS = [
  ['Office', [
    ['Approve this phone', () => approvePhone(), () => S.screen === 'pending'],
    ['Reject the registration', () => set({ screen: 'ended', ended: 'rejected' }), () => S.screen === 'pending'],
    ['Send a supervisor message', () => supervisorMessage(['Can you visit Meena Reddy before 1 pm?', 'Good work on the Axis cases today!', 'Please call Lakshmi Kanth again, she asked for a callback.'][Math.floor(Math.random() * 3)]), atWork],
    ['Ask to see the screen', () => set({ pendingMedia: { kind: 'screen', by: 'Anjali (team lead)', purpose: 'Help you with the payment entry' } }), atWork],
    ['Start a camera session', () => set({ pendingMedia: { kind: 'camera', by: 'Anjali (team lead)', purpose: 'Verify the customer\'s address board' } }), atWork],
    ['Call the phone over the internet', () => set({ pendingMedia: { kind: 'voice', by: 'Anjali (team lead)' } }), atWork],
    ['Revoke this phone', () => set({ screen: 'ended', ended: 'revoked', call: null, dialer: null }), atWork],
  ]],
  ['Phone', [
    ['Device policy fails at next sign-in', () => { set({ failPolicy: true }); toast('Next sign-in will fail the policy check'); }, () => !S.failPolicy],
    ['Offline access period ends', () => set({ screen: 'locked', call: null, dialer: null }), atWork],
    ['Walk to the nearest customer', () => { const c = S.cases.find((x) => x.name === 'Meena Reddy'); set({ fix: { lat: c.lat - 0.0003, lng: c.lng + 0.0001, acc: 8, time: Date.now() }, locPerm: true }); toast('Moved next to Meena Reddy'); }, () => true],
    ['Back to Kondapur', () => set({ fix: { ...D.HOME_FIX, time: Date.now() } }), () => true],
  ]],
];
const TOGGLES = [['online', 'Internet connection', () => S.sync.online, (v) => set({ sync: { ...S.sync, online: v, pending: v ? 0 : S.sync.pending, last: v ? Date.now() : S.sync.last }, error: null })],
  ['perms', 'Call permissions granted', () => S.perms, (v) => set({ perms: v })],
  ['rec', 'Auto call recording on', () => S.recordingProven, (v) => set({ recordingProven: v, recordingOn: v })],
  ['loc', 'Location permission', () => S.locPerm, (v) => set({ locPerm: v })]];
const TRY = [['connect', 'Connect the phone and wait for approval'], ['signin', 'Sign in (password: demo)'], ['tile', 'Filter with a priority tile'], ['call', 'Call a customer from the list'],
  ['feedback', 'Save the call feedback'], ['profile', 'Open a case and look through the tabs'], ['payment', 'Record a payment with a slip'], ['map', 'Open Customers near me'],
  ['checkin', 'Check in at Suresh Babu (62 m away)'], ['chat', 'Read a message from your supervisor']];

function Panel() {
  return html`<div class="panel-in">
    <div class="p-sec"><h3>Demo controls</h3><p class="p-note">Everything runs in your browser on sample data. Nothing is saved or sent.</p>
      <button type="button" class="p-btn ghost" onClick=${resetDemo}>↺ Reset the demo</button>
      ${S.screen !== 'dashboard' && html`<button type="button" class="p-btn ghost" onClick=${() => { set({ company: D.COMPANY.name }); enterWork(); }}>Skip to the case list</button>`}</div>
    ${ACTIONS.map(([g, list]) => html`<div class="p-sec"><h4>Simulate · ${g}</h4>${list.map(([l, fn, ok]) => html`<button type="button" class="p-btn" disabled=${!ok()} onClick=${() => { fn(); S.panel && set({ panel: false }); }}>${l}</button>`)}</div>`)}
    <div class="p-sec"><h4>Phone conditions</h4>${TOGGLES.map(([k, l, get, put]) => html`<label class="p-tog"><span>${l}</span><input type="checkbox" class="sw" checked=${get()} onChange=${(e) => put(e.target.checked)}/></label>`)}</div>
  </div>`;
}
function Tips() {
  const n = TRY.filter(([k]) => S.done[k]).length;
  return html`<div class="tips"><h3>Things to try</h3><div class="p-note">${n} of ${TRY.length} done</div>
    <ol>${TRY.map(([k, t]) => html`<li class=${S.done[k] ? 'done' : ''}>${t}</li>`)}</ol>
    <div class="p-sec simlist"><h4>Real in the app, simulated here</h4><ul>
      <li>The ROVEXY server: sample cases, approvals and saves stay in this page</li><li>The phone's own Phone app, SIM call and call recording</li>
      <li>GPS: the phone is placed in Kondapur, Hyderabad</li><li>Android permission and screen-capture prompts</li>
      <li>Maps: OpenStreetMap here; the app uses Google Maps and Google road distances</li></ul></div></div>`;
}
function StatusBar() {
  const [, t] = useState(0);
  useEffect(() => { const i = setInterval(() => t((x) => x + 1), 20000); return () => clearInterval(i); }, []);
  const d = new Date();
  return html`<div class="sbar"><span>${d.getHours()}:${p2(d.getMinutes())}</span><i class="cam"></i><span class="sb-ic">
    <svg viewBox="0 0 24 12" width="17" height="10"><path d="M1 11h3V8H1zm5 0h3V5H6zm5 0h3V2h-3zm5 0h3V0h-3z" fill="currentColor"/></svg>
    <svg viewBox="0 0 26 12" width="22" height="11"><rect x=".5" y=".5" width="22" height="11" rx="3" fill="none" stroke="currentColor"/><rect x="2" y="2" width="15" height="8" rx="1.5" fill="currentColor"/><rect x="23.5" y="4" width="2" height="4" rx="1" fill="currentColor"/></svg></span></div>`;
}
/** The phone is always laid out at 390 × 844 and scaled as a whole to fit the window (never reflowed). */
function fitPhone() {
  const d = document.querySelector('.device'), bar = document.querySelector('.bar');
  if (!d) return;
  const availH = innerHeight - (EMBED ? 16 : (bar ? bar.offsetHeight : 0) + 32), availW = innerWidth - 16;
  const k = Math.min(1, availH / d.offsetHeight, availW / d.offsetWidth);
  const r = document.documentElement.style;
  r.setProperty('--k', k.toFixed(4)); r.setProperty('--dw', d.offsetWidth + 'px'); r.setProperty('--dh', d.offsetHeight + 'px');
}
function Page() {
  const [, f] = useState(0);
  redraw = () => f((x) => x + 1);
  useEffect(() => { fitPhone(); addEventListener('resize', fitPhone); return () => removeEventListener('resize', fitPhone); }, []);
  const phone = html`<div class="device-wrap"><div class="device"><div class="screen"><${StatusBar}/><${AppRoot} key=${S.sceneKey}/><div class="gnav"><i></i></div></div></div></div>`;
  if (EMBED) return html`<div class="embed">${phone}</div>`;
  return html`<div class="page">
    <header class="bar">
      <a class="back" href="/"><span aria-hidden="true">←</span><img src="/brand/rovexy-lockup-dark.svg" alt="ROVEXY" height="30"/></a>
      <div class="bar-t"><b>Live demo<span class="long"> of the field app</span></b><span class="badge">Sample data · nothing is saved</span></div>
      <div class="bar-a"><button type="button" class="p-open" onClick=${() => set({ panel: !S.panel })}>${S.panel ? 'Close' : 'Demo controls'}</button>
        <a class="cta ghost" href="/#contact">Book a live demo</a><a class="cta" href="/#trial">Start free trial</a></div>
    </header>
    <main class="stage">
      <aside class=${'panel' + (S.panel ? ' open' : '')}>${html`<${Panel}/>`}</aside>
      ${phone}
      <aside class="tips-col"><${Tips}/><a class="cta block" href="/#trial">Start your free trial →</a><a class="cta ghost block" href="/">← Back to rovexy.app</a></aside>
    </main></div>`;
}


// ───────────────────────────── home-page tour: each step played live in the real screens ─────────────────────────────
/** Finger tap: a dot lands on the element, then it is clicked (the same click a visitor would make). */
function reveal(el) {
  const box = el.closest('.dlg-b,.scroll,.bs-list,.prof-body,.sheet-scroll');
  if (box) { const r = el.getBoundingClientRect(), b = box.getBoundingClientRect(), k = b.height / box.clientHeight || 1;
    box.scrollTop += (r.top + r.height / 2 - (b.top + b.height / 2)) / k; }
}
function dot(el) {
  const scr = document.querySelector('.screen'); if (!scr || !el) return;
  const r = el.getBoundingClientRect(), s = scr.getBoundingClientRect(), k = s.width / scr.offsetWidth || 1;
  const d = document.createElement('i'); d.className = 'tapdot';
  d.style.left = (r.left + r.width / 2 - s.left) / k + 'px'; d.style.top = (r.top + r.height / 2 - s.top) / k + 'px';
  scr.appendChild(d); setTimeout(() => d.remove(), 700);
}
const q = (sel, text) => [...document.querySelectorAll('.screen ' + sel)].find((e) => !text || e.textContent.includes(text));
function tap(sel, text) { const el = q(sel, text); if (!el) return; reveal(el); dot(el); setTimeout(() => el.click(), 260); }
/** Types into the text field whose label contains `label`, one key at a time. */
function typeIn(label, text, every = 70) {
  const f = q('.tf', label); const i = f && f.querySelector('input,textarea'); if (!i) return;
  reveal(f); dot(f);
  const proto = i.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
  const put = Object.getOwnPropertyDescriptor(proto, 'value').set;
  [...text].forEach((_, n) => later(150 + n * every, () => { put.call(i, text.slice(0, n + 1)); i.dispatchEvent(new Event('input', { bubbles: true })); }));
}
/** Picks a date / time in the last picker row of the open dialog (the native picker cannot be shown by script). */
function pickIn(value) {
  const rows = [...document.querySelectorAll('.screen .pickrow')], row = rows[rows.length - 1]; if (!row) return;
  reveal(row); dot(row);
  const i = row.querySelector('input'); const put = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set;
  later(300, () => { put.call(i, value); i.dispatchEvent(new Event('change', { bubbles: true })); });
}
const tomorrowAt = (h) => `${plusDays(1)}T${p2(h)}:00`;
const SURESH = 1003;
const SCENES = [
  { len: 7500, set: () => ({ cases: [], sync: { online: true, last: Date.now() - 52 * 60000, pending: 0 } }), run: [   // Upload the portfolio
    [1200, () => set({ notif: { title: 'ROVEXY', body: '24 new cases assigned · Axis Q3 Oct · sorted by days past due' } })],
    [3000, () => tap('.hero-ic[aria-label="Sync"]')],
    [3950, () => set({ cases: D.sampleCases(), notif: null })]] },
  { len: 8500, set: () => ({ screen: 'connect', company: 'ROVEXY' }), run: [   // Approve the company phone
    [1300, () => tap('.pbtn', 'Connect')], [4600, () => approvePhone()], [6200, () => tap('.pbtn', 'Sign in')]] },
  { len: 9000, set: () => ({ recordingProven: false, recordingOn: false }), run: [   // Recording must be on
    [1200, () => tap('.call-fab')], [3300, () => tap('.pbtn', 'Open call settings')], [4900, () => tap('.sw-row input')], [6300, () => tap('.sys-a button')]] },
  { len: 11000, set: () => ({}), run: [   // Call the customer
    [1000, () => tap('.cc-name', 'Ravi Kumar')], [2500, () => tap('.achip', 'Call')], [3600, () => tap('.tbtn', 'Primary')]] },
  { len: 9500, set: () => ({ call: { id: 1, state: 'completed', talk: 154, recording: 'available', route: 'sim', customerId: 1001, name: 'Ravi Kumar', which: 'primary', number: '9848000000' } }), run: [   // Record the outcome
    [1300, () => tap('.opt', 'Promise to pay')], [2300, () => typeIn('Promised amount', '12400')], [3700, () => pickIn(tomorrowAt(11))],
    [4700, () => typeIn('Notes', 'Salary on 14th', 60)], [6600, () => tap('.pbtn', 'Save feedback')],
    [7700, () => { const c = q('.cc-name', 'Ravi Kumar'); if (c) { const card = c.closest('.scard'); reveal(card); } }]] },
  { len: 12000, set: () => ({ fieldOpen: true, fieldFocus: SURESH, fix: { lat: 17.45745, lng: 78.35480, acc: 9, time: Date.now() } }), run: [   // Visit and check in
    [1300, () => tap('.sact', 'Route')], [2000, () => { const l = document.querySelector('.screen .bs-list'); if (l) l.scrollTop = 0; }],
    ...Array.from({ length: 9 }, (_, n) => [2600 + n * 650, () => { const a = { lat: 17.45745, lng: 78.35480 }, b = { lat: 17.46020, lng: 78.35712 }, t = (n + 1) / 9;
      set({ fix: { lat: a.lat + (b.lat - a.lat) * t, lng: a.lng + (b.lng - a.lng) * t, acc: 8, time: Date.now() } }); }]),
    [9000, () => tap('.checkin')]] },
  { len: 9500, set: () => ({ profileId: SURESH, profileLoaded: true }), run: [   // Collect the payment
    [1100, () => tap('.achip', 'Payment')], [2200, () => tap('.pill', 'UPI')], [3000, () => typeIn('Amount received', '8000')],
    [4200, () => typeIn('Reference', '428207645190', 50)], [5600, () => tap('.obtn', 'Take photo')], [6900, () => tap('.tbtn', 'Save')]] },
  { len: 8000, set: () => ({ profileId: SURESH, profileLoaded: true, profileTab: 3,
      cases: D.sampleCases().map((c) => c.id === SURESH ? { ...c, payments: [{ amount: 8000, mode: 'UPI', paidOn: todayIso(), ref: '428207645190', status: 'pending', by: D.EMPLOYEE.name, slip: true, note: null }] } : c) }), run: [   // Check and confirm
    [3000, () => { updateCase(SURESH, (k) => ({ ...k, payments: k.payments.map((p) => ({ ...p, status: 'verified', note: 'Matched with bank statement' })) }));
      set({ notif: { title: 'Payment confirmed', body: 'Suresh Babu · ₹8,000 checked by the office' } }); }]] },
  { len: 8000, set: () => ({}), run: [   // See the team live
    [1300, () => tap('.hero-ic[aria-label="Menu"]')], [2600, () => tap('.menu-i', 'What my company can see')]] },
];
function playScene(n) {
  const sc = SCENES[n]; if (!sc) return;
  timers.forEach(clearTimeout); timers.clear();
  Object.assign(S, fresh('dashboard'), sc.set(), { sceneKey: (S.sceneKey || 0) + 1 });   // fresh screens every scene
  redraw();
  sc.run.forEach(([at, fn]) => later(at, fn));
  parent.postMessage({ rovexySceneLen: sc.len, scene: n }, location.origin);
}
if (TOUR) {
  addEventListener('message', (e) => { if (e.origin === location.origin && typeof e.data?.rovexyScene === 'number') playScene(e.data.rovexyScene); });
  setTimeout(() => parent.postMessage({ rovexyReady: true }, location.origin), 0);
}

render(html`<${Page}/>`, document.getElementById('root'));
