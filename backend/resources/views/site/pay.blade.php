@extends('site.layout', ['title' => 'Order ' . $o->number . ' — ROVEXY', 'noindex' => true])
@section('style')
.pay{max-width:640px;margin:0 auto;padding:56px 20px 90px}
.box{border:1.5px solid var(--line);border-radius:20px;padding:26px;margin-top:20px}
table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}td{padding:7px 0;vertical-align:top}td:last-child{text-align:right}tr.t td{border-top:1px solid var(--line);font-weight:700;font-size:1.2rem;padding-top:12px}
.ok{background:#E7F4EC;border-left:4px solid var(--ok);border-radius:10px;padding:16px 18px;margin-top:20px}
@endsection
@section('main')
<div class="pay">
  <h1 style="font-size:2rem">Order {{ $o->number }}</h1>
  <p class="mute">{{ $o->buyer_company }}, {{ $o->buyer_email }}</p>
  <div class="box"><table>
    @if($o->lines)
      @foreach($o->lines as $line)<tr><td>{{ $line['label'] }}</td><td>{{ $line['amount'] < 0 ? '− ' : '' }}₹{{ number_format(abs($line['amount']), 2) }}</td></tr>@endforeach
      <tr><td class="mute">{{ $o->deployment === 'onprem' ? 'On-premises' : 'Cloud' }} · {{ ['new' => $o->deployment === 'onprem' ? 'New licence' : 'New workspace: ' . $o->workspace, 'upgrade' => 'Upgrade from trial', 'renewal' => 'Renewal', 'seats' => 'More users', 'amc' => 'AMC'][$o->kind] ?? '' }}</td><td></td></tr>
    @else
      <tr><td>ROVEXY {{ $o->plan->name }}, up to {{ $o->plan->max_users }} users, {{ $o->plan->months }} months<br><span class="mute">{{ $o->kind === 'new' ? 'New workspace: ' . $o->workspace : ($o->kind === 'upgrade' ? 'Upgrade from trial' : 'Renewal') }}</span></td><td>₹{{ number_format($o->amount + $o->discount, 2) }}</td></tr>
      @if($o->pricing === 'custom' && $o->discount <= 0 && $o->list_price)<tr><td class="mute">Negotiated rate for your account (standard ₹{{ number_format($o->list_price, 2) }})</td><td></td></tr>@endif
      @if($o->discount > 0)<tr><td>{{ $o->coupon_code ? 'Discount (' . $o->coupon_code . ')' : 'Negotiated discount for your account' }}</td><td>− ₹{{ number_format($o->discount, 2) }}</td></tr>@endif
    @endif
    @if($o->lines)<tr><td><b>Taxable value</b></td><td><b>₹{{ number_format($o->amount, 2) }}</b></td></tr>@endif
    @if($o->igst > 0)<tr><td>IGST</td><td>₹{{ number_format($o->igst, 2) }}</td></tr>@endif
    @if($o->cgst > 0)<tr><td>CGST</td><td>₹{{ number_format($o->cgst, 2) }}</td></tr><tr><td>SGST</td><td>₹{{ number_format($o->sgst, 2) }}</td></tr>@endif
    <tr class="t"><td>Total</td><td>₹{{ number_format($o->total, 2) }}</td></tr>
  </table></div>

  @if($o->status === 'paid')
    <div class="ok">
      <b>Paid on {{ $o->paid_at->format('d M Y') }}. Thank you.</b>
      @if($console)
        <p style="margin:8px 0 0">@if($o->kind === 'new')Your workspace is ready. Sign in with {{ $o->buyer_email }} and the password you chose.@else Your licence is renewed until {{ $o->licence?->expires_at?->format('d M Y') }}.@endif</p>
        <p style="margin:12px 0 0"><a class="btn" href="{{ $console }}">Open my console</a></p>
      @endif
      <p style="margin:12px 0 0"><a href="/pay/{{ $o->number }}/{{ $token }}/invoice">Tax invoice {{ $o->invoice_number }}</a></p>
    </div>
  @elseif($o->status === 'cancelled')
    <div class="err">This order was cancelled{{ $o->cancel_reason ? ' (' . $o->cancel_reason . ')' : '' }}. Place a new order or contact us.</div>
  @elseif($o->expiredQuote())
    <div class="err">This quotation was valid until {{ $o->valid_until->format('d M Y') }}. Contact {{ $seller['name'] }}{{ $seller['email'] ? ' at ' . $seller['email'] : '' }} for a fresh one.</div>
  @elseif($payments)
    <div id="msg">@if($o->failed_at)<div class="err">The last payment attempt failed{{ $o->failure_reason ? ': ' . $o->failure_reason : '' }}. No money was taken for it — you can try again.</div>@endif</div>
    <p style="margin-top:22px"><button class="btn gold" id="paybtn">Pay ₹{{ number_format($o->total, 2) }}</button></p>
    <p class="mute" style="font-size:.9rem">@if($o->valid_until)Valid until {{ $o->valid_until->format('d M Y') }}. @endif UPI, cards and net banking through Razorpay. Keep this page open until it confirms.</p>
  @else
    <div class="box"><b>Pay by bank transfer</b><p class="mute" style="margin:6px 0 0">Online payment isn't switched on yet. {{ $seller['name'] }} will send bank details for this order{{ $seller['email'] ? ' — or write to ' . $seller['email'] : '' }}. Your licence is issued as soon as the payment is recorded.</p></div>
  @endif
</div>
@endsection
@section('script')
@if($o->status === 'created' && $payments && !$o->expiredQuote())
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function(){
  var btn=document.getElementById('paybtn'), msg=document.getElementById('msg'), base='/pay/{{ $o->number }}/{{ $token }}';
  var tok=document.querySelector('meta[name=csrf-token]').content;
  var post=function(u,b){return fetch(u,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':tok},body:JSON.stringify(b||{})}).then(function(r){return r.json().then(function(j){if(!r.ok) throw new Error(j.message||'Something went wrong. Try again.'); return j;});});};
  var show=function(t){msg.innerHTML='';var d=document.createElement('div');d.className='err';d.textContent=t;msg.appendChild(d);};
  btn.addEventListener('click',function(){
    btn.disabled=true; btn.textContent='Opening payment…';
    post(base+'/razorpay').then(function(o){
      var rz=new Razorpay({key:o.key,order_id:o.order_id,amount:o.amount,currency:o.currency,name:o.name,description:o.description,prefill:o.prefill,theme:{color:'#1F3A5F'},
        handler:function(r){btn.textContent='Confirming…'; post(base+'/verify',r).then(function(j){location.href=j.redirect;}).catch(function(e){show(e.message);btn.disabled=false;btn.textContent='Pay again';});},
        modal:{ondismiss:function(){btn.disabled=false;btn.textContent='Pay ₹{{ number_format($o->total, 2) }}';}}});
      rz.on('payment.failed',function(r){var t=(r.error&&r.error.description)||''; show(t||'The payment failed. No money was taken; try again.'); post(base+'/failed',{reason:t.slice(0,200)}).catch(function(){});});
      rz.open();
    }).catch(function(e){show(e.message);btn.disabled=false;btn.textContent='Pay ₹{{ number_format($o->total, 2) }}';});
  });
})();
</script>
@endif
@endsection
