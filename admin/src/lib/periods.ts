/** Date-range presets shared by every report: [label, () => [from, to]] as local YYYY-MM-DD ('' = open end). */
const day = (d: Date) => d.toLocaleDateString('en-CA');
const monday = (n: Date) => new Date(n.getFullYear(), n.getMonth(), n.getDate() - ((n.getDay() + 6) % 7));
export const RANGES: [string, () => [string, string]][] = [
  ['Today', () => [day(new Date()), day(new Date())]],
  ['This week', () => [day(monday(new Date())), day(new Date())]],
  ['Last 7 days', () => [day(new Date(Date.now() - 6 * 864e5)), day(new Date())]],
  ['This month', () => { const n = new Date(); return [day(new Date(n.getFullYear(), n.getMonth(), 1)), day(n)]; }],
  ['Last month', () => { const n = new Date(); return [day(new Date(n.getFullYear(), n.getMonth() - 1, 1)), day(new Date(n.getFullYear(), n.getMonth(), 0))]; }],
  ['This year', () => { const n = new Date(); return [day(new Date(n.getFullYear(), 0, 1)), day(n)]; }],
  ['All time', () => ['', '']],
];
/** Which preset (if any) a from/to pair is — so the button shows as selected; otherwise it is a custom range. */
export const rangeName = (from: string, to: string) => RANGES.find(([, fn]) => { const [a, b] = fn(); return a === from && b === to; })?.[0] ?? 'Custom';
