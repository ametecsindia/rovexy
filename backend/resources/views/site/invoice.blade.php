@php
  // Indian numbering in words (crore / lakh / thousand), for "Amount in words".
  $words = function (float $n): string {
      $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
      $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
      $two = fn (int $x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
      $three = fn (int $x) => trim(($x >= 100 ? $ones[intdiv($x, 100)] . ' Hundred ' : '') . $two($x % 100));
      $say = function (int $x) use ($two, $three): string {
          if ($x === 0) return 'Zero';
          $out = [];
          foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$unit, $name]) {
              if ($x >= $unit) { $out[] = ($unit === 10000000 && intdiv($x, $unit) >= 100 ? $three(intdiv($x, $unit) % 1000) : $two(intdiv($x, $unit) % 100)) . ' ' . $name; $x %= $unit; }
          }
          if ($x > 0) $out[] = $three($x);
          return implode(' ', $out);
      };
      $rupees = (int) floor($n + 0.0001); $paise = (int) round(($n - $rupees) * 100);
      return 'Rupees ' . $say($rupees) . ($paise > 0 ? ' and ' . $two($paise) . ' Paise' : '') . ' Only';
  };
  $kind = ['new' => 'New', 'upgrade' => 'Upgrade from trial', 'renewal' => 'Renewal', 'seats' => 'More users', 'amc' => 'AMC'][$o->kind] ?? 'Renewal / upgrade';
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tax invoice {{ $o->invoice_number }}</title>
<style>
@page{size:A4;margin:12mm}*{-webkit-print-color-adjust:exact;print-color-adjust:exact;box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;color:#13243B;font-size:12.5px;margin:0;background:#EEF1F5}
.sheet{max-width:820px;margin:18px auto;background:#fff;box-shadow:0 1px 4px rgba(16,32,52,.12)}
.head{display:flex;justify-content:space-between;align-items:flex-start;padding:22px 26px 16px;border-bottom:3px solid #1F3A5F}
.head img{height:46px;display:block}.head .co{font-size:11px;color:#5A6678;margin-top:8px;line-height:1.5}
.title{text-align:right}.title h1{margin:0;font-size:22px;letter-spacing:.06em;color:#1F3A5F}.title .orig{font-size:10px;color:#B8893B;font-weight:bold;letter-spacing:.12em;margin-top:2px}
.meta{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid #D9DEE6}.meta div{padding:10px 14px;border-right:1px solid #D9DEE6}.meta div:last-child{border-right:0}
.meta small{display:block;color:#5A6678;font-size:10px;text-transform:uppercase;letter-spacing:.06em}.meta b{font-size:12.5px}
.pad{padding:18px 26px}.cards{display:grid;grid-template-columns:1fr 1fr;gap:14px}.card{border:1px solid #D9DEE6;border-radius:6px;padding:12px 14px;line-height:1.55}
.card h3{margin:0 0 6px;color:#B8893B;font-size:11px;text-transform:uppercase;letter-spacing:.08em}.card .nm{font-weight:bold;font-size:13.5px}.mute{color:#5A6678}
table{width:100%;border-collapse:collapse;margin-top:16px}th{background:#1F3A5F;color:#fff;text-align:left;padding:8px 10px;font-size:11.5px}
td{padding:8px 10px;border-bottom:1px solid #D9DEE6;vertical-align:top}tbody tr:nth-child(even) td{background:#F5F7FA}.r{text-align:right;white-space:nowrap}
tr.t td{font-weight:bold;font-size:14px;background:#F3E7D1;border-top:2px solid #1F3A5F}
.words{margin-top:10px;padding:9px 12px;background:#F5F7FA;border-left:3px solid #B8893B}
.bottom{display:grid;grid-template-columns:1.4fr 1fr;gap:18px;margin-top:18px}.terms{font-size:11px;color:#5A6678;line-height:1.6}.terms b{color:#13243B}
.sign{border:1px solid #D9DEE6;border-radius:6px;padding:12px 14px;text-align:center;font-size:11.5px}.sign .sp{height:40px}.sign b{display:block}
.foot{display:flex;justify-content:space-between;color:#5A6678;font-size:10.5px;border-top:1px solid #D9DEE6;padding:10px 26px}
.no-print{max-width:820px;margin:0 auto 24px;text-align:right}.no-print button{background:#1F3A5F;color:#fff;border:0;border-radius:6px;padding:10px 18px;font-weight:bold;cursor:pointer}
@media (max-width:640px){.meta{grid-template-columns:1fr 1fr}.cards,.bottom{grid-template-columns:1fr}.head{flex-direction:column;gap:12px}.title{text-align:left}}
@media print{body{background:#fff}.sheet{box-shadow:none;margin:0;max-width:none}.no-print{display:none}}
</style></head>
<body>
<div class="sheet">
  <div class="head">
    <div>
      <img src="{{ url('/brand/rovexy-logo.svg') }}" alt="ROVEXY">
      <div class="co">A product of {{ $seller['seller_name'] }}@if($seller['seller_email']) · {{ $seller['seller_email'] }}@endif @if($seller['seller_phone']) · {{ $seller['seller_phone'] }}@endif</div>
    </div>
    <div class="title"><h1>TAX INVOICE</h1><div class="orig">ORIGINAL FOR RECIPIENT</div></div>
  </div>
  <div class="meta">
    <div><small>Invoice no.</small><b>{{ $o->invoice_number }}</b></div>
    <div><small>Invoice date</small><b>{{ $o->paid_at->copy()->setTimezone('Asia/Kolkata')->format('d M Y') }}</b></div>
    <div><small>Order no.</small><b>{{ $o->number }}</b></div>
    <div><small>Place of supply</small><b>{{ $states[$o->state_code] ?? '—' }} ({{ $o->state_code }})</b></div>
  </div>
  <div class="pad">
    <div class="cards">
      <div class="card"><h3>Seller</h3>
        <div class="nm">{{ $seller['seller_name'] }}</div>
        {{ $seller['seller_address'] }}<br>
        State: {{ $states[$seller['seller_state']] ?? $seller['seller_state'] }} ({{ $seller['seller_state'] }})<br>
        GSTIN: <b>{{ $seller['seller_gstin'] ?: 'not registered' }}</b>
        @if($seller['seller_email'] || $seller['seller_phone'])<br><span class="mute">{{ collect([$seller['seller_email'], $seller['seller_phone']])->filter()->implode(' · ') }}</span>@endif
      </div>
      <div class="card"><h3>Billed to</h3>
        <div class="nm">{{ $o->buyer_company }}</div>
        @if($company?->address){{ $company->address }}<br>@endif
        State: {{ $states[$o->state_code] ?? '—' }} ({{ $o->state_code }})<br>
        GSTIN: <b>{{ $o->buyer_gstin ?: 'unregistered (B2C)' }}</b><br>
        <span class="mute">{{ collect([$o->buyer_name, $o->buyer_email, $o->buyer_phone ?: $company?->billing_phone])->filter()->implode(' · ') }}</span>
        @if($company)<br><span class="mute">Workspace: {{ $company->slug }}</span>@endif
      </div>
    </div>

    <table>
      <thead><tr><th style="width:40px">#</th><th>Description</th><th>SAC</th><th class="r">Amount (₹)</th></tr></thead>
      <tbody>
      @if($o->lines)
        @foreach($o->lines as $i => $line)
          <tr><td>{{ $line['amount'] >= 0 ? $i + 1 : '' }}</td><td>@if($i === 0)<b>ROVEXY {{ $o->plan->name }}</b> — {{ $o->deployment === 'onprem' ? 'on-premises' : 'cloud' }}<br>@endif{{ $line['label'] }}
            @if($i === 0 && $o->licence)<br><span class="mute">Licence {{ $o->licence->key }}@if($o->licence->expires_at), valid until {{ $o->licence->expires_at->format('d M Y') }}@elseif($o->licence->kind === 'perpetual') · perpetual @if($o->licence->amc_expires_at), updates &amp; support until {{ $o->licence->amc_expires_at->format('d M Y') }}@endif @endif</span>@endif</td>
            <td>{{ $line['amount'] >= 0 ? '998315' : '' }}</td><td class="r">{{ $line['amount'] < 0 ? '−' : '' }}{{ number_format(abs($line['amount']), 2) }}</td></tr>
        @endforeach
      @else
        <tr><td>1</td><td><b>ROVEXY {{ $o->plan->name }} subscription</b> (cloud)<br><span class="mute">{{ $kind }} · up to {{ $o->plan->max_users }} users · {{ $o->plan->months }} months @if($o->licence) · licence {{ $o->licence->key }}@if($o->licence->expires_at), valid until {{ $o->licence->expires_at->format('d M Y') }}@endif @endif</span>
          @if($o->pricing === 'custom' && $o->discount <= 0 && $o->list_price)<br><span class="mute">Negotiated rate (standard price ₹{{ number_format($o->list_price, 2) }})</span>@endif</td>
          <td>998315</td><td class="r">{{ number_format($o->amount + $o->discount, 2) }}</td></tr>
        @if($o->discount > 0)<tr><td></td><td>{{ $o->coupon_code ? 'Discount (' . $o->coupon_code . ')' : 'Negotiated discount' }}</td><td></td><td class="r">−{{ number_format($o->discount, 2) }}</td></tr>@endif
      @endif
      <tr><td></td><td><b>Taxable value</b></td><td></td><td class="r"><b>{{ number_format($o->amount, 2) }}</b></td></tr>
      @if($o->cgst > 0)<tr><td></td><td>CGST @ {{ $gst / 2 }}%</td><td></td><td class="r">{{ number_format($o->cgst, 2) }}</td></tr><tr><td></td><td>SGST @ {{ $gst / 2 }}%</td><td></td><td class="r">{{ number_format($o->sgst, 2) }}</td></tr>@endif
      @if($o->igst > 0)<tr><td></td><td>IGST @ {{ $gst }}%</td><td></td><td class="r">{{ number_format($o->igst, 2) }}</td></tr>@endif
      <tr class="t"><td></td><td>Total</td><td></td><td class="r">₹ {{ number_format($o->total, 2) }}</td></tr>
      </tbody>
    </table>
    <div class="words"><b>Amount in words:</b> {{ $words((float) $o->total) }}</div>

    <div class="bottom">
      <div class="terms">
        <b>Payment received</b> on {{ $o->paid_at->copy()->setTimezone('Asia/Kolkata')->format('d M Y') }} by {{ $o->payment_method === 'razorpay' ? 'Razorpay (online)' : strtoupper($o->payment_method) }}{{ $o->payment_ref ? ', reference ' . $o->payment_ref : '' }}.<br>
        Tax is not payable on reverse charge. Subscription fees are not refundable once the licence is issued, except as set out in our refund policy.<br>
        Questions about this invoice: {{ $seller['seller_email'] ?: $seller['seller_name'] }}.
      </div>
      <div class="sign">For <b>{{ $seller['seller_name'] }}</b><div class="sp"></div>Authorised signatory<br><span class="mute">Computer-generated invoice; no signature required.</span></div>
    </div>
  </div>
  <div class="foot"><span>{{ $seller['seller_name'] }} · Confidential</span><span>{{ $o->invoice_number }}</span></div>
</div>
<p class="no-print"><button onclick="window.print()">Print / save as PDF</button></p>
</body></html>
