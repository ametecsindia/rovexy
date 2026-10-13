<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} — ROVEXY Accounts</title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 12px/1.45 "Segoe UI", system-ui, sans-serif; color: #0f2238; background: #fff; }
  .bar { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; padding-bottom: 10px; border-bottom: 3px solid #072654; margin-bottom: 4px; }
  .bar img { height: 42px; }
  .bar .who { text-align: right; font-size: 11px; color: #5d6b79; }
  .gold { height: 2px; background: #C89B2D; margin-bottom: 14px; }
  h1 { font-size: 18px; margin: 0 0 6px; color: #072654; }
  .meta { display: flex; flex-wrap: wrap; gap: 4px 18px; margin: 0 0 12px; color: #34465a; }
  .meta b { color: #072654; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #072654; color: #fff; text-align: left; font-weight: 600; padding: 6px 8px; font-size: 11px; }
  td { padding: 5px 8px; border-bottom: 1px solid #e3e8ee; vertical-align: top; }
  tbody tr:nth-child(even) td { background: #f6f8fb; }
  td.n, th.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  tfoot td { font-weight: 700; border-top: 2px solid #072654; border-bottom: 0; background: #fbf6ea; }
  .foot { margin-top: 16px; font-size: 10px; color: #8a96a3; display: flex; justify-content: space-between; }
  .tools { position: fixed; top: 10px; right: 10px; }
  .tools button { font: 600 13px system-ui; padding: 8px 14px; border-radius: 8px; border: 0; background: #0A2F6B; color: #fff; cursor: pointer; }
  @media print { .tools { display: none; } }
</style>
</head>
<body>
<div class="tools"><button onclick="window.print()">Save as PDF / Print</button></div>
<div class="bar"><img src="/brand/rovexy-logo.svg" alt="ROVEXY"><div class="who"><b>{{ $seller }}</b><br>Printed {{ $printed }}</div></div>
<div class="gold"></div>
<h1>{{ $title }}</h1>
@if ($meta)
<p class="meta">@foreach ($meta as $k => $v)<span>{{ $k }}: <b>{{ $v }}</b></span>@endforeach</p>
@endif
<table>
  <thead><tr>@foreach ($headers as $i => $h)<th class="{{ is_numeric($rows[0][$i] ?? null) || ($totals[$i] ?? null) !== null && is_numeric($totals[$i] ?? null) ? 'n' : '' }}">{{ $h }}</th>@endforeach</tr></thead>
  <tbody>
  @forelse ($rows as $r)
    <tr>@foreach (array_values($r) as $v)<td class="{{ is_int($v) || is_float($v) ? 'n' : '' }}">{{ is_float($v) ? number_format($v, 2) : $v }}</td>@endforeach</tr>
  @empty
    <tr><td colspan="{{ count($headers) }}">Nothing in this period.</td></tr>
  @endforelse
  </tbody>
  @if ($totals)
  <tfoot><tr>@foreach ($totals as $v)<td class="{{ is_int($v) || is_float($v) ? 'n' : '' }}">{{ is_float($v) ? number_format($v, 2) : $v }}</td>@endforeach</tr></tfoot>
  @endif
</table>
<div class="foot"><span>ROVEXY Accounts · {{ $seller }}</span><span>Amounts in ₹</span></div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
