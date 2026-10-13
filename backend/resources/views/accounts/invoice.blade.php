<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $inv->type === 'bill' ? 'Bill' : 'Tax invoice' }} {{ $inv->number }}</title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 12.5px/1.5 "Segoe UI", system-ui, sans-serif; color: #0f2238; background: #fff; }
  .wrap { max-width: 800px; margin: 0 auto; padding: 8px; }
  .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 3px solid #072654; padding-bottom: 12px; }
  .top img { height: 48px; }
  .doc { text-align: right; } .doc h1 { margin: 0; font-size: 20px; color: #072654; letter-spacing: .02em; } .doc .no { font-weight: 700; color: #C89B2D; }
  .gold { height: 2px; background: #C89B2D; margin-bottom: 16px; }
  .cards { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px; }
  .card { border: 1px solid #dde3ea; border-radius: 8px; padding: 10px 12px; } .card h3 { margin: 0 0 4px; font-size: 10px; letter-spacing: .14em; text-transform: uppercase; color: #C89B2D; }
  .card b { color: #072654; font-size: 13.5px; }
  .dates { display: flex; gap: 24px; margin-bottom: 12px; } .dates span { color: #5d6b79; } .dates b { color: #072654; }
  table { width: 100%; border-collapse: collapse; }
  th { background: #072654; color: #fff; text-align: left; padding: 7px 9px; font-weight: 600; font-size: 11px; }
  td { padding: 7px 9px; border-bottom: 1px solid #e3e8ee; } tbody tr:nth-child(even) td { background: #f6f8fb; }
  .n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .sum { width: 300px; margin: 12px 0 0 auto; } .sum td { border: 0; padding: 4px 9px; } .sum tr.total td { border-top: 2px solid #072654; font-weight: 800; font-size: 14px; color: #072654; background: #fbf6ea; }
  .notes { margin-top: 16px; color: #34465a; white-space: pre-line; }
  .stamp { display: inline-block; margin-top: 10px; padding: 4px 12px; border: 2px solid; border-radius: 6px; font-weight: 800; letter-spacing: .1em; }
  .stamp.paid { color: #1e7b4f; } .stamp.cancelled { color: #b42318; } .stamp.overdue { color: #b42318; }
  .foot { margin-top: 24px; font-size: 10.5px; color: #8a96a3; border-top: 1px solid #e3e8ee; padding-top: 8px; }
  .tools { position: fixed; top: 10px; right: 10px; } .tools button { font: 600 13px system-ui; padding: 8px 14px; border-radius: 8px; border: 0; background: #0A2F6B; color: #fff; cursor: pointer; }
  @media print { .tools { display: none; } }
</style>
</head>
<body>
<div class="tools"><button onclick="window.print()">Save as PDF / Print</button></div>
<div class="wrap">
  <div class="top">
    <img src="/brand/rovexy-logo.svg" alt="ROVEXY">
    <div class="doc"><h1>{{ $inv->type === 'bill' ? 'PURCHASE BILL' : 'TAX INVOICE' }}</h1><div class="no">{{ $inv->number }}</div></div>
  </div>
  <div class="gold"></div>
  <div class="cards">
    <div class="card"><h3>{{ $inv->type === 'bill' ? 'Billed to' : 'From' }}</h3><b>{{ $seller['name'] }}</b><br>{{ $seller['address'] }}@if ($seller['gstin'])<br>GSTIN: {{ $seller['gstin'] }}@endif<br>{{ $seller['email'] }} · {{ $seller['phone'] }}</div>
    <div class="card"><h3>{{ $inv->type === 'bill' ? 'Vendor' : 'Bill to' }}</h3><b>{{ $inv->party_name }}</b>@if ($inv->party_address)<br>{{ $inv->party_address }}@endif @if ($inv->party_gstin)<br>GSTIN: {{ $inv->party_gstin }}@endif @if ($inv->party_email || $inv->party_phone)<br>{{ trim($inv->party_email . ' · ' . $inv->party_phone, ' ·') }}@endif</div>
  </div>
  <div class="dates"><span>Date: <b>{{ $inv->issue_date->format('d M Y') }}</b></span>@if ($inv->due_date)<span>Due: <b>{{ $inv->due_date->format('d M Y') }}</b></span>@endif</div>
  <table>
    <thead><tr><th>#</th><th>Description</th><th class="n">Qty</th><th class="n">Rate (₹)</th><th class="n">Amount (₹)</th></tr></thead>
    <tbody>
    @foreach ($inv->items as $i => $l)
      <tr><td>{{ $i + 1 }}</td><td>{{ $l['description'] }}</td><td class="n">{{ rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.') }}</td><td class="n">{{ number_format((float) $l['rate'], 2) }}</td><td class="n">{{ number_format((float) $l['amount'], 2) }}</td></tr>
    @endforeach
    </tbody>
  </table>
  <table class="sum">
    <tr><td>Taxable value</td><td class="n">₹{{ number_format($inv->subtotal, 2) }}</td></tr>
    <tr><td>GST @ {{ rtrim(rtrim(number_format($inv->tax_rate, 2), '0'), '.') }}%</td><td class="n">₹{{ number_format($inv->tax_amount, 2) }}</td></tr>
    <tr class="total"><td>Total</td><td class="n">₹{{ number_format($inv->total, 2) }}</td></tr>
    @if ($inv->amount_paid > 0)
    <tr><td>Paid</td><td class="n">₹{{ number_format($inv->amount_paid, 2) }}</td></tr>
    <tr><td><b>Balance due</b></td><td class="n"><b>₹{{ number_format($inv->balance, 2) }}</b></td></tr>
    @endif
  </table>
  @if (in_array($inv->display_status, ['paid', 'cancelled', 'overdue'], true))<span class="stamp {{ $inv->display_status }}">{{ strtoupper($inv->display_status) }}</span>@endif
  @if ($inv->notes)<div class="notes">{{ $inv->notes }}</div>@endif
  <div class="foot">{{ $seller['name'] }} · This is a computer-generated document.</div>
</div>
</body>
</html>
