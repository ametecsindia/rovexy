// ROVEXY live demo — sample data. Everything here is invented; nothing is sent to a server.
// Dates are relative to today so the priority tiles always have something in them.

export const DAY = 86400000;
const t0 = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d; };
export const day = (n) => { const d = t0(); d.setDate(d.getDate() + n); return d; };
const at = (n, h, m = 0) => { const d = day(n); d.setHours(h, m, 0, 0); return d.getTime(); };
const iso = (n) => { const d = day(n); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

export const COMPANY = { name: 'Sunrise Recoveries', server: 'https://demo.rovexy.app', workspace: 'sunrise' };
export const EMPLOYEE = { name: 'Priya Sharma', code: 'SR-1042', team: 'Hyderabad field team' };
/** Where the demo "phone" is: Kondapur, Hyderabad. */
export const HOME_FIX = { lat: 17.46005, lng: 78.35695, acc: 12 };

/** Same list and order the server sends (Outcomes::CODES, ordered by label). [code, label, needsFollowUp, captures] */
export const OUTCOMES = [
  ['CONNECTED', 'Connected (spoke to customer)', false, 'none'],
  ['CONNECTED_PTP', 'Promise to pay', true, 'promise'],
  ['PAID', 'Already paid', false, 'payment'],
  ['PART_PAID', 'Part payment received', false, 'payment'],
  ['VISIT_BRANCH', 'Will pay at branch / online', true, 'promise'],
  ['SETTLEMENT', 'Asked for settlement', true, 'none'],
  ['CALLBACK', 'Call back later', true, 'none'],
  ['THIRD_PARTY', 'Spoke to family / third party', false, 'none'],
  ['DISPUTE', 'Dispute raised', false, 'none'],
  ['REFUSED', 'Refused to pay', false, 'none'],
  ['RINGING_NO_ANSWER', 'Ringing, no answer', false, 'none'],
  ['SWITCHED_OFF', 'Switched off / out of coverage', false, 'none'],
  ['NOT_REACHABLE', 'Not reachable', false, 'none'],
  ['WRONG_NUMBER', 'Wrong number', false, 'none'],
  ['BUSY', 'Busy', false, 'none'],
  ['OTHER', 'Other', false, 'none'],
].map(([code, label, needsFollowUp, captures]) => ({ code, label, needsFollowUp, captures }))
  .sort((a, b) => a.label.localeCompare(b.label));

export const PAYMENT_MODES = [['CASH', 'Cash'], ['UPI', 'UPI'], ['NEFT_IMPS', 'NEFT / IMPS / RTGS'], ['CHEQUE', 'Cheque'], ['DD', 'Demand draft'],
  ['CARD', 'Debit / credit card'], ['ONLINE_LINK', 'Payment link'], ['BRANCH', 'Paid at branch']];

export const DISCLOSURE = { recorded: true, notice: 'Recordings are kept for 180 days for quality and compliance checks.', location: 'Every 60 s while signed in', liveView: true, camera: true };

// [name, ref, portfolio, area, lat, lng, overdue, outstanding, emi, oldestDueDaysAgo, dueInDays, status, extras]
const RAW = [
  ['Ravi Kumar', 'AX-220781', 'Axis Q3 Oct', 'Madhapur', 17.4483, 78.3915, 12400, 48900, 6200, 34, 5, 'follow_up', { calls: 4, connected: 2, last: [-1, 16, 'CALLBACK'], cb: [0, 16, 30, 'Asked to call after 4 pm'], alt: '9849012345' }],
  ['Meena Reddy', 'AX-220812', 'Axis Q3 Oct', 'Kothaguda', 17.46470, 78.36590, 7850, 31200, 3925, 21, 0, 'in_progress', { calls: 2, connected: 1, last: [-2, 11, 'CONNECTED'] }],
  ['Suresh Babu', 'HD-551203', 'HDFC Cards Sep', 'Kondapur', 17.46040, 78.35740, 8000, 26500, 4000, 18, 3, 'follow_up', { calls: 3, connected: 3, last: [-1, 12, 'CONNECTED_PTP'], promise: [8000, 0, 'open'] }],
  ['Lakshmi Kanth', 'AX-220655', 'Axis Q3 Oct', 'Gachibowli', 17.4401, 78.3489, 21300, 92400, 7100, 61, 2, 'in_progress', { calls: 6, connected: 2, last: [-3, 15, 'REFUSED'] }],
  ['Anil Verma', 'BJ-90311', 'Bajaj PL Aug', 'Hitech City', 17.4435, 78.3772, 15600, 64000, 5200, 92, 9, 'follow_up', { calls: 7, connected: 3, last: [-4, 10, 'CONNECTED_PTP'], promise: [15600, -2, 'broken'] }],
  ['Farhana Begum', 'HD-551344', 'HDFC Cards Sep', 'Miyapur', 17.4969, 78.3548, 4300, 18800, 2150, 9, 1, 'new', { calls: 0, connected: 0, alt: '9700011223' }],
  ['Venkatesh Rao', 'AX-220903', 'Axis Q3 Oct', 'Kukatpally', 17.4948, 78.3996, 0, 54200, 5400, null, 6, 'in_progress', { calls: 2, connected: 2, last: [0, 10, 'PAID'], paid: [5400, 0, 'UPI', '428207645190', 'pending'] }],
  ['Swathi Naidu', 'BJ-90455', 'Bajaj PL Aug', 'Manikonda', 17.4045, 78.3866, 9800, 41000, 4900, 45, 4, 'new', { calls: 1, connected: 0, last: [-1, 18, 'RINGING_NO_ANSWER'] }],
  ['Mohammed Irfan', 'HD-551398', 'HDFC Cards Sep', 'Jubilee Hills', 17.4325, 78.4071, 26750, 118000, 8900, 75, 0, 'in_progress', { calls: 5, connected: 2, last: [-2, 17, 'SETTLEMENT'], cb: [-1, 11, 0, 'Wants settlement figure'] }],
  ['Kavitha Rani', 'AX-221010', 'Axis Q3 Oct', 'Nallagandla', 17.4710, 78.3110, 3100, 12600, 3100, 6, 7, 'new', { calls: 0, connected: 0 }],
  ['Rajesh Gupta', 'BJ-90502', 'Bajaj PL Aug', 'Chandanagar', 17.4930, 78.3270, 11200, 47300, 5600, 38, 0, 'in_progress', { calls: 3, connected: 1, last: [-5, 13, 'DISPUTE'], pool: true }],
  ['Deepika Joshi', 'HD-551477', 'HDFC Cards Sep', 'Banjara Hills', 17.4156, 78.4347, 0, 22000, 2750, null, 12, 'closed', { calls: 4, connected: 4, last: [-6, 12, 'PAID'], paid: [2750, -6, 'CASH', 'R-00871', 'verified'] }],
  ['Srinivas Goud', 'AX-221077', 'Axis Q3 Oct', 'Ameerpet', 17.4375, 78.4482, 18900, 76500, 6300, 120, 3, 'in_progress', { calls: 8, connected: 1, last: [-1, 9, 'SWITCHED_OFF'], pool: true }],
  ['Naveen Chandra', 'BJ-90588', 'Bajaj PL Aug', 'Lingampally', null, null, 6700, 29900, 3350, 27, 5, 'new', { calls: 1, connected: 1, last: [-3, 14, 'THIRD_PARTY'], noPin: 'missing' }],
  ['Padma Priya', 'HD-551530', 'HDFC Cards Sep', 'Serilingampally', null, null, 5200, 20800, 2600, 14, 2, 'new', { calls: 0, connected: 0, noPin: 'invalid' }],
  ['Arjun Mehta', 'AX-221142', 'Axis Q3 Oct', 'T. Nagar, Chennai', 13.0418, 80.2341, 9400, 38000, 4700, 52, 8, 'in_progress', { calls: 2, connected: 0, last: [-2, 12, 'NOT_REACHABLE'] }],
];

const STREETS = ['Plot 14, Road No. 3', 'H.No. 2-48/7', 'Flat 302, Sai Residency', 'H.No. 1-98/A', 'Door 5-9-22', 'Flat 104, Lake View Apts', 'Plot 61, Phase 2'];
const CITY = (area) => area.includes('Chennai') ? ['Chennai', 'Tamil Nadu', '600017'] : ['Hyderabad', 'Telangana', '5000' + (32 + (area.length % 60))];

function makeCase([name, ref, portfolio, area, lat, lng, overdue, outstanding, emi, oldest, dueIn, status, x], i) {
  const [city, state, pincode] = CITY(area);
  const phone = '98' + String(48000000 + i * 1234567).slice(0, 8);
  const c = {
    id: 1001 + i, name, ref, phone, alt: x.alt || null, email: name.toLowerCase().replace(/[^a-z]+/g, '.') + '@example.in',
    preferred: i % 3 === 0 ? 'After 6 pm' : i % 3 === 1 ? 'Morning, 9–11 am' : null,
    status, portfolio, campaign: portfolio.replace(/ .*/, '') + ' collections', team: EMPLOYEE.team, pool: !!x.pool,
    address: STREETS[i % STREETS.length] + ', ' + area, area, city, state, pincode,
    lat: x.noPin ? null : lat, lng: x.noPin ? null : lng, locIssue: x.noPin || null,
    overdueUploaded: overdue, outstanding, emi, loan: Math.round(outstanding * 1.6 / 1000) * 1000,
    principal: Math.round(outstanding * 0.82), interest: Math.round(outstanding * 0.15), charges: Math.round(outstanding * 0.03),
    oldestDue: oldest == null ? null : iso(-oldest), dueDate: iso(dueIn), balanceAsOf: iso(-9),
    overdueInstalments: oldest == null ? 0 : Math.max(1, Math.round(oldest / 30)),
    lastPaidFile: oldest == null ? null : [emi, iso(-(oldest + 30))],
    calls: x.calls, connected: x.connected, lastCallAt: x.last ? at(x.last[0], x.last[1], 12) : null, lastOutcome: x.last ? x.last[2] : null,
    callback: x.cb ? { at: at(x.cb[0], x.cb[1], x.cb[2]), notes: x.cb[3] } : null,
    promises: x.promise ? [{ amount: x.promise[0], date: iso(x.promise[1]), status: x.promise[2], by: EMPLOYEE.name }] : [],
    payments: x.paid ? [{ amount: x.paid[0], mode: x.paid[2], paidOn: iso(x.paid[1]), ref: x.paid[3], status: x.paid[4], by: EMPLOYEE.name, slip: true,
      note: x.paid[4] === 'verified' ? 'Matched with bank statement' : null }] : [],
    assignedAt: at(-12, 10), timeline: [], addressHistory: [],
  };
  // History the server would send for the profile tabs.
  const tl = [{ type: 'assignment', at: at(-12, 10), notes: `Assigned to ${EMPLOYEE.name} from ${portfolio}`, by: 'Supervisor' }];
  if (x.last) {
    for (let k = x.calls; k >= 1; k--) {
      const answered = k <= x.connected;
      const when = k === x.calls ? at(x.last[0], x.last[1], 12) : at(x.last[0] - (x.calls - k) * 2 - 1, 11 + (k % 5), 5);
      tl.push({ type: 'call', at: when, by: EMPLOYEE.name, outcome: k === x.calls ? label(x.last[2]) : answered ? 'Connected (spoke to customer)' : 'Ringing, no answer',
        state: answered ? 'completed' : 'no_answer', talk: answered ? 40 + (k * 37) % 220 : 0, rec: answered ? 'available' : 'not_required', which: 'primary',
        notes: k === x.calls && x.cb ? x.cb[3] : null });
    }
  }
  if (x.promise) tl.push({ type: 'entry', at: at(x.last[0], x.last[1], 14), by: EMPLOYEE.name, outcome: 'Promise to pay', amount: x.promise[0], promiseDate: iso(x.promise[1]) });
  if (x.paid) tl.push({ type: 'entry', at: at(x.paid[1], 10, 20), by: EMPLOYEE.name, outcome: 'Already paid', amount: x.paid[0], mode: x.paid[2], review: x.paid[4] });
  if (x.cb) tl.push({ type: 'callback', at: at(x.last[0], x.last[1], 15), dueAt: at(x.cb[0], x.cb[1], x.cb[2]), status: 'open', by: EMPLOYEE.name, notes: x.cb[3] });
  if (i === 0) {
    tl.push({ type: 'location', at: at(-8, 12), by: EMPLOYEE.name, notes: 'Pin set at the customer\'s door (GPS ±9 m)' });
    c.addressHistory.push({ what: 'pin', at: at(-8, 12), by: EMPLOYEE.name, source: 'GPS at the door', oldPin: { lat: 17.4471, lng: 78.3893 }, newPin: { lat, lng }, moved: 270 });
    c.addressHistory.push({ what: 'address', at: at(-11, 15), by: 'Supervisor', source: 'Edited in the console', oldAddress: 'H.No. 3-11, Madhapur, Hyderabad', newAddress: c.address });
  }
  c.timeline = tl.sort((a, b) => b.at - a.at);
  return c;
}

const label = (code) => OUTCOMES.find((o) => o.code === code)?.label || code;

export function sampleCases() { return RAW.map(makeCase); }

export const TODAY = { calls: 18, connected: 11, talkSeconds: 2530 };

export function sampleChat() {
  return [{ id: 7, subject: 'Supervisor chat', messages: [
    { id: 1, mine: false, body: 'Good morning Priya. Please visit Suresh Babu today, he promised ₹8,000.', at: at(0, 9, 5), read: true },
    { id: 2, mine: true, body: 'Sure, I am in Kondapur. Will check in there first.', at: at(0, 9, 12), read: true },
  ] }];
}
