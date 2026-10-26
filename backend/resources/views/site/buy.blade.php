@php
  $kind = old('kind', $kind);
  $cyc = ['quarterly' => ['Quarterly', '3 months'], 'half_yearly' => ['6 months', (int) $halfPct . '% off'], 'annual' => ['12 months', (int) $annualPct . '% off']];
  $cyc['quarterly'][1] = '0% off';
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex"><title>Buy ROVEXY — instant activation</title>
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--ink:#0E1A2B;--navy:#0A2F6B;--deep:#061B3D;--panel:#0C2A56;--seg:#071F44;--gold:#B8893B;--gold-2:#D4AE6A;--line:#D9DFE7;--mute:#5B6878;--soft:#AFC0D8;--ok:#1E8E5A;--bad:#B42318}
*{box-sizing:border-box}
html{background:#04122A}html,body{margin:0;min-height:100%}
body{font-family:"Schibsted Grotesk",system-ui,sans-serif;color:var(--ink);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased;
  background:radial-gradient(900px 500px at 15% 0%,rgba(184,137,59,.16),transparent 60%),linear-gradient(160deg,#0A2F6B 0%,#061B3D 55%,#04122A 100%);background-attachment:fixed;padding:32px 16px}
a{color:inherit}:focus-visible{outline:3px solid var(--gold-2);outline-offset:2px}
.card{max-width:1000px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);border-radius:22px;overflow:hidden;box-shadow:0 40px 90px -30px rgba(0,0,0,.6)}
@media (max-width:860px){.card{grid-template-columns:minmax(0,1fr)}}
@media (max-width:520px){body{padding:16px 10px}.cfg,.acc{padding:24px 18px}.seg label{font-size:.8rem;padding:8px 3px}}
/* left: configurator */
.cfg{background:linear-gradient(180deg,#0E2F5E,#0A2650);color:#fff;padding:34px 32px 30px}
.cfg .logo img{height:42px;display:block}
.cfg h1{font-size:1.65rem;margin:22px 0 6px;letter-spacing:-.02em;line-height:1.15}
.cfg .lead{color:var(--soft);margin:0 0 22px;font-size:.93rem}
.lab{display:block;font-size:.72rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:#C9D6E8;margin:18px 0 7px}
.seg{display:grid;grid-auto-flow:column;grid-auto-columns:1fr;gap:3px;background:var(--seg);border-radius:10px;padding:3px}
.seg label{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:8px 6px;border-radius:8px;cursor:pointer;font-weight:600;font-size:.86rem;color:#D7E1EE;min-height:38px}
.seg label small{font-weight:500;font-size:.7rem;color:var(--gold-2)}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg label:has(input:checked){background:var(--gold);color:var(--ink)}.seg label:has(input:checked) small{color:#3A2A0C}
.seg label:has(input:focus-visible){outline:2px solid var(--gold-2)}
.step{display:flex;align-items:center;gap:8px}
.step button{width:34px;height:34px;border-radius:8px;border:1px solid #3B5784;background:#123566;color:#fff;font-size:1.1rem;cursor:pointer}
.step button:hover{background:#1A447E}
.step input{width:84px;height:38px;border-radius:9px;border:1px solid #3B5784;background:#061B3D;color:#fff;font-family:inherit;font-weight:700;font-size:1.05rem;text-align:center}
.step .hint{color:var(--soft);font-size:.82rem;margin-left:6px}
.coupon{display:flex;gap:8px}.coupon input{min-width:0;flex:1;height:40px;border-radius:9px;border:1px solid #3B5784;background:#061B3D;color:#fff;padding:0 12px;font:inherit;text-transform:uppercase}
.coupon input::placeholder{color:#6F84A3}.coupon button{font-family:inherit;border:1px solid #3B5784;background:#123566;color:#fff;border-radius:9px;padding:0 16px;font-weight:600;cursor:pointer}
#cmsg{font-size:.84rem;min-height:1.2em;margin-top:6px}
.sum{margin-top:22px;border:1px solid #2D4B7A;background:rgba(255,255,255,.05);border-radius:14px;padding:6px 16px 12px}
.sum table{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.sum td{padding:10px 0;border-bottom:1px solid #2D4B7A;font-size:.92rem;vertical-align:top}.sum td:last-child{text-align:right;font-weight:700;white-space:nowrap;padding-left:12px}
.sum tr.neg td{color:#9BE3BC}.sum tr.t td{border-bottom:0;font-size:1.3rem;font-weight:800;padding-top:12px}
.sum .note{color:var(--soft);font-size:.8rem;text-align:right;margin:0}
.sum .err{color:#FFB4A8;font-size:.92rem;padding:12px 0}
.fine{color:#9FB2CC;font-size:.8rem;margin:16px 0 0}
/* right: account form */
.acc{background:#fff;padding:34px 32px 30px}
.acc h2{font-size:1.45rem;margin:0 0 6px;letter-spacing:-.02em}
.acc .lead{color:var(--mute);margin:0 0 18px;font-size:.93rem}.acc .lead a{font-weight:700;color:var(--navy)}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px}.grid .full{grid-column:1/-1}
@media (max-width:520px){.grid{grid-template-columns:1fr}}
label.f{display:block;font-size:.84rem;font-weight:600;color:#26354A}
label.f input,label.f select{display:block;width:100%;margin-top:5px;height:44px;border:1px solid var(--line);border-radius:10px;padding:0 12px;font:inherit;background:#fff}
label.f input:focus,label.f select:focus{border-color:var(--navy);outline:2px solid rgba(10,47,107,.15)}
label.f .opt{font-weight:400;color:var(--mute)}label.f .req{color:var(--bad)}
.help{font-size:.78rem;color:var(--mute);margin:4px 0 0}
.sec{font-size:.72rem;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--gold);margin:16px 0 0}
.check{display:flex;gap:9px;align-items:flex-start;font-size:.88rem;margin:16px 0 0}.check input{margin-top:3px}
.cta{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:50px;margin-top:16px;border:0;border-radius:11px;background:var(--navy);color:#fff;font-family:inherit;font-weight:700;font-size:1rem;cursor:pointer}
.cta:hover{background:#082553}.cta[disabled]{opacity:.6;cursor:default}
.alt{font-size:.86rem;color:var(--mute);margin:12px 0 0;text-align:center}.alt a{color:var(--navy);font-weight:600}
.alert{border-radius:10px;padding:10px 12px;font-size:.88rem;margin:0 0 14px}.alert.bad{background:#FDECEA;color:var(--bad)}.alert.warn{background:#FFF6E5;color:#8A5300}
.after{margin-top:20px;border-top:1px solid var(--line);padding-top:14px;font-size:.84rem;color:var(--mute)}.after b{color:var(--ink)}
.after ol{margin:6px 0 0;padding-left:18px}.after li{margin:2px 0}
.hp{position:absolute;left:-9999px}
[hidden]{display:none!important}
</style>
</head>
<body>
<form method="post" action="/buy" id="buy" class="card" novalidate>
  @csrf<input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">

  <section class="cfg" aria-label="Choose your plan">
    <a class="logo" href="/" aria-label="ROVEXY home"><img src="/brand/rovexy-logo-dark.svg" alt="ROVEXY"></a>
    <h1 id="title">ROVEXY {{ $plan->name }}</h1>
    <p class="lead" id="lead"></p>

    <span class="lab">Plan</span>
    <div class="seg" role="radiogroup" aria-label="Plan">
      @foreach($plans as $p)<label><input type="radio" name="plan" value="{{ $p->code }}" data-name="{{ $p->name }}" data-min="{{ $p->min_users }}" data-tag="{{ $p->tagline }}" {{ old('plan', $plan->code) === $p->code ? 'checked' : '' }}>{{ $p->name }}</label>@endforeach
    </div>

    <span class="lab">How you buy</span>
    <div class="seg" role="radiogroup" aria-label="How you buy">
      <label><input type="radio" name="kind" value="cloud" {{ $kind === 'cloud' ? 'checked' : '' }}>Rent · Cloud<small>we host it</small></label>
      <label><input type="radio" name="kind" value="perpetual" {{ $kind === 'perpetual' ? 'checked' : '' }}>Own · Perpetual<small>your own server</small></label>
    </div>

    <div id="cycles">
      <span class="lab">Pay in advance</span>
      <div class="seg" role="radiogroup" aria-label="Billing period">
        @foreach($cyc as $k => [$label, $off])<label><input type="radio" name="billing" value="{{ $k }}" {{ old('billing', $billing) === $k ? 'checked' : '' }}>{{ $label }}<small>{{ $off }}</small></label>@endforeach
      </div>
    </div>

    <span class="lab" id="ulab">Users</span>
    <div class="step">
      <button type="button" id="minus" aria-label="Fewer users">−</button>
      <input type="number" name="users" id="users" value="{{ old('users', $users) }}" min="1" max="100000" inputmode="numeric" aria-labelledby="ulab">
      <button type="button" id="plus" aria-label="More users">+</button>
      <span class="hint" id="uhint">everyone who signs in</span>
    </div>

    <span class="lab">Coupon code</span>
    <div class="coupon"><input name="coupon" id="coupon" value="{{ old('coupon') }}" maxlength="30" placeholder="e.g. DIWALI25" autocomplete="off" aria-label="Coupon code"><button type="button" id="apply">Apply</button></div>
    <div id="cmsg" aria-live="polite"></div>

    <div class="sum" aria-live="polite">
      <table><tbody id="lines"></tbody><tr><td id="taxl">GST {{ $gst + 0 }}%</td><td id="tax">—</td></tr><tr class="t"><td id="totl">Total</td><td id="total">—</td></tr></table>
      <p class="note" id="note"></p>
      <div class="err" id="err" hidden></div>
    </div>
    <p class="fine">Pay by UPI, card or net banking (Razorpay). Payment activates your licence straight away and the GST tax invoice is e-mailed automatically. {{ $seller['name'] }}@if($seller['gstin']) · GST {{ $seller['gstin'] }}@endif.</p>
  </section>

  <section class="acc" aria-label="Your details">
    <h2>Buy ROVEXY — instant activation</h2>
    <p class="lead" id="acclead"></p>
    @if(!$payments)<div class="alert warn">Online payment is being set up. You can still place the order; we'll send bank details to pay.</div>@endif
    @if($errors->any())<div class="alert bad" role="alert">{{ $errors->first() }}</div>@endif
    <div class="grid">
      <label class="f full">Company name<input name="buyer_company" value="{{ old('buyer_company') }}" required maxlength="150" autocomplete="organization"></label>
      <label class="f">Your name<input name="buyer_name" value="{{ old('buyer_name') }}" required maxlength="150" autocomplete="name"></label>
      <label class="f">Mobile<input name="buyer_phone" value="{{ old('buyer_phone') }}" required maxlength="20" inputmode="tel" autocomplete="tel" placeholder="98480 12345"></label>
      <label class="f full"><span id="maillab">Work e-mail (this becomes your sign-in)</span><input type="email" name="buyer_email" value="{{ old('buyer_email') }}" required autocomplete="email"></label>
      <div class="full cloud-only">
        <p class="sec">Your cloud workspace</p>
      </div>
      <label class="f full cloud-only">Workspace name<input name="workspace" value="{{ old('workspace') }}" pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]" title="3–40 lower-case letters, numbers or dashes" placeholder="acme-collections" autocapitalize="none">
        <span class="help">Your console address: …/w/<b id="wsprev">acme-collections</b>/admin</span></label>
      <label class="f cloud-only">Choose a password <span class="opt">(at least 8 characters)</span><input type="password" name="password" minlength="8" autocomplete="new-password"></label>
      <label class="f cloud-only">Confirm password<input type="password" name="password_confirmation" minlength="8" autocomplete="new-password"></label>
      <label class="f">State <span class="req">*</span><select name="state_code" required><option value="">Select…</option>@foreach($states as $code => $name)<option value="{{ $code }}" {{ old('state_code') === $code ? 'selected' : '' }}>{{ $name }}</option>@endforeach</select></label>
      <label class="f">GSTIN <span class="opt">(optional)</span><input name="buyer_gstin" value="{{ old('buyer_gstin') }}" maxlength="15" style="text-transform:uppercase" placeholder="36ABCDE1234F1Z5"></label>
      <p class="help full">Your state decides how GST appears on your invoice — CGST + SGST for Telangana, IGST for other states. A GSTIN lets you claim input credit.</p>
    </div>
    <label class="check"><input type="checkbox" name="terms" value="1" required> <span>I agree to the <a href="/terms" target="_blank">Terms</a> and <a href="/refund" target="_blank">Refund policy</a></span></label>
    <button class="cta" type="submit" id="go">Pay securely &amp; activate →</button>
    <p class="alt">Need a formal quotation or a bigger team? <a href="/#contact">Ask us</a>@if($seller['phone']) · or WhatsApp {{ $seller['phone'] }}@endif</p>
    <div class="after">
      <b>What happens after payment</b>
      <ol id="after"></ol>
      <p style="margin:8px 0 0">Already a customer? <a href="/admin">Sign in</a></p>
    </div>
  </section>
</form>
<script>
(function(){
  var f=document.getElementById('buy'), tok=document.querySelector('meta[name=csrf-token]').content, seller='{{ $sellerState }}', gst={{ $gst + 0 }}, amc={{ $amcPct + 0 }}, trial={{ $trialDays }};
  var $=function(id){return document.getElementById(id);}, t=null, seq=0;
  var inr=function(n){var d=Math.round(n*100)%100?2:0;return '₹'+(+n).toLocaleString('en-IN',{minimumFractionDigits:d,maximumFractionDigits:d});};
  var val=function(n){var x=f.querySelector('input[name='+n+']:checked');return x?x.value:null;};
  var plan=function(){return f.querySelector('input[name=plan]:checked');};
  function mode(){
    var own=val('kind')==='perpetual', p=plan();
    $('cycles').hidden=own;
    [].forEach.call(document.querySelectorAll('.cloud-only'),function(e){e.hidden=own; e.querySelectorAll('input').forEach(function(i){i.required=!own;});});
    $('title').textContent='ROVEXY '+p.dataset.name+(own?' · On-premises':' · Cloud');
    $('lead').textContent=own?'One-time licence for your own server — never expires. First 12 months of updates & support included; optional AMC from year 2 ('+amc+'% a year).'
      :(p.dataset.tag?p.dataset.tag.replace(/\.?$/,'. '):'')+'Hosted by us — nothing to install. Pay in advance; add users any time.';
    $('acclead').innerHTML=own?'Pay securely and your licence key and GST invoice are e-mailed the same minute. Not sure yet? <a href="/#trial">Start a '+trial+'-day free trial →</a>'
      :'Pay securely and your workspace, licence and GST invoice are created the same minute — then you sign straight in. Just exploring? <a href="/#trial">Start a '+trial+'-day free trial →</a>';
    $('maillab').textContent=own?'Work e-mail (your licence key is sent here)':'Work e-mail (this becomes your sign-in)';
    $('after').innerHTML=own?'<li>Your licence key is issued and e-mailed.</li><li>Install ROVEXY on your server and enter the key under Administration → Plan &amp; licence.</li><li>The GST invoice and receipt are e-mailed to you.</li>'
      :'<li>Your workspace goes live and your licence is issued.</li><li>The GST invoice and receipt are e-mailed to you.</li><li>You sign in to your console with the e-mail and password above.</li>';
    $('uhint').textContent='from '+p.dataset.min+' · everyone who signs in';
  }
  function show(j){
    var body=$('lines'); body.innerHTML=''; $('err').hidden=true;
    if(!j.ok){ $('err').hidden=false; $('err').innerHTML=(j.message||'This size is priced on quotation.')+(j.error==='custom_quote'?' <a href="/#contact" style="color:#fff;font-weight:700">Ask for a quote →</a>':'');
      $('tax').textContent='—'; $('total').textContent='—'; $('note').textContent=''; $('go').disabled=j.error==='custom_quote'||j.error==='below_minimum'; return; }
    $('go').disabled=false;
    j.lines.forEach(function(l){var tr=document.createElement('tr'); if(l.amount<0) tr.className='neg';
      var a=document.createElement('td'), b=document.createElement('td'); a.textContent=l.label; b.textContent=(l.amount<0?'− ':'')+inr(Math.abs(l.amount)); tr.appendChild(a); tr.appendChild(b); body.appendChild(tr);});
    var st=f.elements.state_code.value, own=val('kind')==='perpetual';
    $('taxl').textContent=st?(st===seller?'CGST + SGST '+gst+'%':'IGST '+gst+'%'):'GST '+gst+'%';
    $('tax').textContent=inr(j.cgst+j.sgst+j.igst); $('total').textContent=inr(j.total);
    $('totl').textContent=own?'One-time total':'Payable now';
    $('note').textContent=own?'One-time · you own it · optional AMC '+amc+'%/yr from year 2':'≈ '+inr(j.per_user_month)+' per user a month, before GST';
  }
  function quote(){
    mode();
    var p=plan(), users=+f.elements.users.value||0, own=val('kind')==='perpetual', my=++seq, code=f.elements.coupon.value.trim();
    fetch('/price-check',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':tok},
      body:JSON.stringify({plan:p.value,users:users,deployment:own?'onprem':'cloud',billing:own?null:val('billing'),state_code:f.elements.state_code.value||null,code:code||null,email:f.elements.buyer_email.value||null})})
      .then(function(r){return r.json();}).then(function(j){ if(my!==seq) return;
        var cm=$('cmsg');
        if(!j.ok&&j.error==='bad_coupon'){ cm.textContent=j.message; cm.style.color='#FFB4A8'; return; }
        cm.textContent=code&&j.ok?'Code applied.':''; cm.style.color='#9BE3BC'; show(j); })
      .catch(function(){ $('note').textContent='Could not work out the price right now; it is checked again when you pay.'; });
  }
  var later=function(){clearTimeout(t);t=setTimeout(quote,220);};
  $('minus').addEventListener('click',function(){f.elements.users.value=Math.max(+plan().dataset.min,(+f.elements.users.value||0)-1);later();});
  $('plus').addEventListener('click',function(){f.elements.users.value=(+f.elements.users.value||0)+1;later();});
  $('apply').addEventListener('click',quote);
  f.elements.users.addEventListener('input',later);
  f.addEventListener('change',function(e){ if(e.target.name==='plan'&&(+f.elements.users.value)<+plan().dataset.min) f.elements.users.value=plan().dataset.min; later(); });
  f.elements.workspace.addEventListener('input',function(){$('wsprev').textContent=this.value||'acme-collections';});
  f.addEventListener('submit',function(e){ if(!f.reportValidity()){e.preventDefault();return;} $('go').disabled=true; $('go').textContent='Preparing payment…'; });
  quote();
})();
</script>
</body>
</html>
