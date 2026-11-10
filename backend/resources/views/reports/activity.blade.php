<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} — ROVEXY</title>
<style>
  @page { size: A4 landscape; margin: 10mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 11px/1.45 "Segoe UI", system-ui, sans-serif; color: #0f2238; background: #fff; }
  .bar { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; padding-bottom: 10px; border-bottom: 3px solid #072654; margin-bottom: 4px; }
  .bar img { height: 40px; }
  .bar .who { text-align: right; font-size: 11px; color: #5d6b79; }
  .gold { height: 2px; background: #C89B2D; margin-bottom: 14px; }
  h1 { font-size: 18px; margin: 0 0 2px; color: #072654; }
  .period { margin: 0 0 14px; color: #34465a; }
  h2 { font-size: 13px; color: #072654; margin: 18px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #072654; }
  h2 span { color: #C89B2D; margin-right: 6px; }
  table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
  tr { page-break-inside: avoid; }
  th { background: #072654; color: #fff; text-align: left; font-weight: 600; padding: 5px 6px; font-size: 10px; }
  td { padding: 4px 6px; border-bottom: 1px solid #e3e8ee; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #f6f8fb; }
  td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .summary { width: auto; min-width: 50%; }
  .foot { margin-top: 16px; font-size: 10px; color: #8a96a3; display: flex; justify-content: space-between; }
  .tools { position: fixed; bottom: 16px; right: 16px; }
  @media screen { body { padding: 20px 24px; } }
  .tools button { font: 600 13px system-ui; padding: 8px 14px; border-radius: 8px; border: 0; background: #0A2F6B; color: #fff; cursor: pointer; }
  @media print { .tools { display: none; } }
</style>
</head>
<body>
<div class="tools"><button onclick="window.print()">Save as PDF / Print</button></div>
<div class="bar"><img src="/brand/rovexy-logo.svg" alt="ROVEXY"><div class="who"><b>{{ $company }}</b><br>Printed {{ $printed }}</div></div>
<div class="gold"></div>
<h1>{{ $title }}</h1>
<p class="period">{{ $period }}</p>
@foreach ($sheets as $name => $lines)
  <h2><span>{{ $loop->iteration }}</span>{{ $name }}</h2>
  <table class="{{ $name === 'Summary' ? 'summary' : '' }}">
    <thead><tr>@foreach ($lines[0] as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse (array_slice($lines, 1) as $row)
      <tr>@foreach ($row as $v)<td class="{{ is_int($v) || is_float($v) || (is_string($v) && preg_match('/^\d+:\d\d$/', $v)) ? 'n' : '' }}">{{ $v }}</td>@endforeach</tr>
    @empty
      <tr><td colspan="{{ count($lines[0]) }}">Nothing in this period.</td></tr>
    @endforelse
    </tbody>
  </table>
@endforeach
<div class="foot"><span>ROVEXY · Employee activity report · Confidential</span><span>Times in hours:minutes, company time zone</span></div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
