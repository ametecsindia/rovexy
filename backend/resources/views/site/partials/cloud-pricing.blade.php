{{--
  ROVEXY Cloud pricing (10 Nov 2026): Google Maps model × optional Click2Call / Smart31, quarterly in advance.
  Rates come from the cloud plans' tiers (App\Services\Platform\CloudPlans); every total comes from /price-check,
  the same function that prices the order at checkout.
--}}
@php
  $am = $cloud['cloud-ametecs'] ?? null; $ow = $cloud['cloud-own'] ?? null;
  $tiers = fn ($p) => $p ? $p->cloudTiers->filter(fn ($t) => $t->max_users !== null && $t->rate_inr > 0)->values() : collect();
  $first = fn ($p) => $tiers($p)->first()?->rate_inr;
  $addon = fn (string $sfx) => ($cloud['cloud-ametecs' . $sfx] ?? null) && $am ? $first($cloud['cloud-ametecs' . $sfx]) - $first($am) : null;
  $c2c = $addon('-c2c'); $s31 = $addon('-c2c-smart31');
  $inr = fn ($v) => '₹' . number_format((float) $v);
  $ps = fn ($k) => \App\Services\Platform\PlatformSettings::get($k);
  $setupNote = (float) $ps('cloud_setup_base') > 0 ? 'One-time setup & onboarding on the first order: ' . $inr($ps('cloud_setup_base')) . ' for up to ' . (int) $ps('cloud_setup_included_users') . ' users'
      . ((float) $ps('cloud_setup_per_extra') > 0 ? ', plus ' . $inr($ps('cloud_setup_per_extra')) . ' for each user above that' : '') . '; GST extra.' : null;
@endphp
@if($am && $ow)
<style>
.cloudp{max-width:1080px;margin:0 auto}
.cp-h{font-size:1.05rem;margin:0 0 14px;text-align:center;color:var(--ink)}
.cp-maps{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px;margin-bottom:26px}
.cp-card{position:relative;display:block;cursor:pointer;background:#fff;border:1.5px solid var(--line);border-radius:22px;padding:26px 26px 22px;transition:border-color .2s,box-shadow .2s}
.cp-card:has(input:checked){border-color:var(--navy);box-shadow:0 26px 50px -30px rgba(14,26,43,.45)}
.cp-card input{position:absolute;top:24px;right:24px;width:20px;height:20px;margin:0;accent-color:var(--navy)}
.cp-card h3{font-size:1.15rem;margin:0 34px 6px 0}.cp-card p{color:var(--mute);font-size:.92rem;margin:0 0 14px}
.cp-card table{width:100%;border-collapse:collapse;font-size:.92rem;font-variant-numeric:tabular-nums}
.cp-card th{text-align:left;font-size:.74rem;letter-spacing:.06em;text-transform:uppercase;color:var(--mute);font-weight:700;padding:6px 0;border-bottom:1px solid var(--line)}
.cp-card td{padding:7px 0;border-bottom:1px solid var(--line)}.cp-card td+td,.cp-card th+th{text-align:right}
.cp-card .big{font-size:2.2rem;font-weight:760;letter-spacing:-.03em;margin:4px 0 2px}.cp-card .big span{font-size:.95rem;font-weight:500;color:var(--mute);margin-left:6px;letter-spacing:0}
.cp-card ul{margin:10px 0 0;padding-left:18px;font-size:.88rem;color:var(--mute)}.cp-card .fine{font-size:.82rem;color:var(--mute);margin:10px 0 0}
.cp-calc{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:22px;background:#fff;border:1px solid var(--line);border-radius:22px;padding:26px}
@media (max-width:820px){.cp-calc{grid-template-columns:1fr;padding:20px}}
.cp-calc .lab{display:block;font-weight:600;margin:0 0 8px}.cp-calc .lab+*{margin-bottom:18px}
.cp-users{display:flex;align-items:center;gap:8px}.cp-users input{width:110px;margin:0;text-align:center;font-weight:700;font-size:1.1rem}
.cp-users button{width:40px;height:40px;border-radius:10px;border:1px solid var(--line);background:#fff;font-size:1.2rem;cursor:pointer}
.cp-tel{display:grid;gap:8px}.cp-tel label{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1.5px solid var(--line);border-radius:12px;padding:10px 14px;cursor:pointer;font-weight:600;font-size:.93rem}
.cp-tel label:has(input:checked){border-color:var(--navy);background:#F4F7FB}.cp-tel input{display:inline-block;vertical-align:-2px;width:auto;margin:0 8px 0 0}.cp-tel small{color:var(--mute);font-weight:500}
.cp-sum table{width:100%;border-collapse:collapse;font-size:.95rem;font-variant-numeric:tabular-nums}
.cp-sum td{padding:8px 0;border-bottom:1px solid var(--line)}.cp-sum td+td{text-align:right;white-space:nowrap}
.cp-sum tr.tot td{border-bottom:0;border-top:2px solid var(--navy);font-weight:760;font-size:1.15rem;padding-top:12px}
.cp-sum tr.sub td{font-weight:600}.cp-sum .muted{color:var(--mute);font-size:.86rem}
.cp-sum .cta{display:flex;justify-content:center;width:100%;margin-top:16px}
.cp-note{font-size:.84rem;color:var(--mute);margin:14px 0 0}
</style>
<div class="cloudp" id="cloud-pane" hidden>
  <h3 class="cp-h">Choose your Google Maps service</h3>
  <div class="cp-maps" role="radiogroup" aria-label="Google Maps service">
    <label class="cp-card">
      <input type="radio" name="cmaps" value="ametecs" checked>
      <h3>Cloud Server — Powered by Ametecs Google Maps API</h3>
      <p>We run ROVEXY on our managed cloud, with Google Maps through Ametecs' managed API configuration. Suits teams that prefer not to manage Google Maps billing and API set-up.</p>
      <table>
        <thead><tr><th>Minimum users</th><th>Per user / month</th><th>Quarterly</th></tr></thead>
        <tbody>
        @foreach($tiers($am) as $t)<tr><td>{{ $t->min_users }} users</td><td>{{ $inr($t->rate_inr) }}</td><td>{{ $inr($t->min_users * $t->rate_inr * 3) }}</td></tr>@endforeach
        <tr><td>Above {{ $tiers($am)->last()->max_users }} users</td><td colspan="2">Contact sales · custom quote</td></tr>
        </tbody>
      </table>
      <p class="fine">Minimum {{ $am->min_users }} users. Monthly rates, paid quarterly in advance; GST extra; no automatic annual discount. Google Maps use is under the approved service terms and fair-use limits — not unlimited.</p>
      @if($setupNote)<p class="fine">{{ $setupNote }}</p>@endif
    </label>
    <label class="cp-card">
      <input type="radio" name="cmaps" value="own">
      <h3>Cloud Server — Use Your Own Google Maps API</h3>
      <p>Use ROVEXY with your organisation's own Google Maps API project and billing account.</p>
      <div class="big">{{ $inr($first($ow)) }}<span>/ user / month</span></div>
      <p style="margin:0">Flat rate from {{ $ow->min_users }} users, paid quarterly in advance; GST extra. Add more users at the same rate.</p>
      <ul>
        <li>You create and maintain the Google Maps API project</li>
        <li>You enable the required Google Maps services</li>
        <li>You set billing, API restrictions and usage quotas</li>
        <li>You pay Google Maps consumption charges directly to Google</li>
      </ul>
      <p class="fine"><b>Google Maps API consumption charges are not included in the ROVEXY subscription and are billed separately by Google.</b></p>
      @if($setupNote)<p class="fine">{{ $setupNote }}</p>@endif
    </label>
  </div>

  <div class="cp-calc" id="cp-calc">
    <div>
      <span class="lab" id="cp-ulab">Number of users</span>
      <div class="cp-users">
        <button type="button" data-step="-1" aria-label="Fewer users">−</button>
        <input type="number" id="cp-users" value="10" min="{{ $am->min_users }}" max="100000" inputmode="numeric" aria-labelledby="cp-ulab">
        <button type="button" data-step="1" aria-label="More users">+</button>
      </div>
      <span class="lab">Optional calling add-on</span>
      <div class="cp-tel" role="radiogroup" aria-label="Calling add-on">
        <label><span><input type="radio" name="ctel" value="none" checked>No add-on <small>· SIM calling from the app</small></span><small>₹0</small></label>
        <label><span><input type="radio" name="ctel" value="c2c">Click2Call (C2C)</span><small>+{{ $inr($c2c) }} / user / month</small></label>
        <label><span><input type="radio" name="ctel" value="c2c_smart31">C2C + Smart31 number rotation</span><small>{{ $inr($s31) }} / user / month in all</small></label>
      </div>
      <p class="cp-note">Smart31 is added on Click2Call (+{{ $inr($s31 - $c2c) }}), not sold alone. Click2Call: up to 50 connected calls per user per day, no per-call duration cap; the provider's and operators' fair-use terms apply.</p>
    </div>
    <div class="cp-sum" aria-live="polite">
      <table>
        <tr><td>Users</td><td id="cs-users">—</td></tr>
        <tr><td>Monthly rate per user</td><td id="cs-rate">—</td></tr>
        <tr><td>Monthly subscription</td><td id="cs-month">—</td></tr>
        <tr class="sub"><td>Quarterly subscription</td><td id="cs-quarter">—</td></tr>
        <tr><td id="cs-addl">Calling add-on (quarterly)</td><td id="cs-addon">—</td></tr>
        <tr id="cs-setup-row" hidden><td>Setup &amp; onboarding (first order)</td><td id="cs-setup">—</td></tr>
        <tr><td>GST <span id="cs-gstp">{{ rtrim(rtrim(number_format($gstRate, 2), '0'), '.') }}%</span></td><td id="cs-gst">—</td></tr>
        <tr class="tot"><td id="cs-totl">Total payable each quarter</td><td id="cs-total">—</td></tr>
      </table>
      <p class="muted" id="cs-msg" style="margin:10px 0 0"></p>
      <a class="btn gold cta" id="cs-go" href="/buy">Get started</a>
    </div>
  </div>
</div>
<script>
(function () {
  var pane = document.getElementById('cloud-pane'); if (!pane) return;
  var tok = (document.querySelector('meta[name=csrf-token]') || {}).content, users = document.getElementById('cp-users'), seq = 0, t = null;
  var $ = function (id) { return document.getElementById(id); };
  var inr = function (n) { var d = Math.round(n * 100) % 100 ? 2 : 0; return '₹' + (+n).toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var val = function (n) { return (pane.querySelector('input[name=' + n + ']:checked') || {}).value; };
  var min = +users.min;
  function blank(msg, contact) {
    ['cs-rate', 'cs-month', 'cs-quarter', 'cs-addon', 'cs-gst', 'cs-total'].forEach(function (id) { $(id).textContent = '—'; });
    $('cs-setup-row').hidden = true; $('cs-totl').textContent = 'Total payable each quarter'; $('cs-msg').textContent = msg;
    $('cs-go').textContent = contact ? 'Contact sales' : 'Get started'; $('cs-go').href = contact ? '#contact' : '/buy';
  }
  function calc() {
    var n = Math.floor(+users.value || 0), maps = val('cmaps'), tel = val('ctel'), my = ++seq;
    var code = 'cloud-' + maps + ({ none: '', c2c: '-c2c', c2c_smart31: '-c2c-smart31' })[tel];
    $('cs-users').textContent = n || '—';
    $('cs-addl').textContent = tel === 'none' ? 'Calling add-on (quarterly)' : (tel === 'c2c' ? 'Click2Call' : 'Click2Call + Smart31') + ' (quarterly)';
    if (n < min) return blank('ROVEXY Cloud starts at ' + min + ' users.', false);
    fetch('/price-check', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': tok },
      body: JSON.stringify({ plan: code, users: n, deployment: 'cloud', billing: 'quarterly' }) })
      .then(function (r) { return r.json(); }).then(function (j) {
        if (my !== seq) return;
        if (!j.ok) return blank(j.error === 'custom_quote' ? 'Above this size we price ROVEXY Cloud to your team. Contact sales for a quote.' : (j.message || 'Not available for this size.'), j.error === 'custom_quote');
        var base = j.base_per_user_month != null ? j.base_per_user_month : j.per_user_month, add = j.addon_per_user_month || 0;
        var setup = (j.lines.filter(function (l) { return l.type === 'setup'; })[0] || {}).amount || 0;
        $('cs-rate').textContent = inr(base) + (add ? ' + ' + inr(add) + ' add-on' : '');
        $('cs-month').textContent = inr(base * n);
        $('cs-quarter').textContent = inr(j.base_quarter != null ? j.base_quarter : base * n * 3);
        $('cs-addon').textContent = add ? inr(j.addon_quarter) : '—';
        $('cs-setup-row').hidden = !setup; $('cs-setup').textContent = inr(setup);
        $('cs-gst').textContent = inr(j.cgst + j.sgst + j.igst); $('cs-total').textContent = inr(j.total);
        $('cs-totl').textContent = setup ? 'Total for the first quarter' : 'Total payable each quarter';
        $('cs-msg').textContent = (setup ? 'Includes the one-time setup & onboarding. From the second quarter: ' + inr(j.amount - setup) + ' + GST. ' : 'Before GST: ' + inr(j.amount) + ' a quarter. ') + (maps === 'own' ? 'Google Maps API charges are billed separately by Google.' : 'Google Maps via Ametecs under the service terms.');
        $('cs-go').textContent = 'Get started'; $('cs-go').href = '/buy?plan=' + code + '&users=' + n;
      }).catch(function () { if (my === seq) blank('Could not work out the price right now. Please try again.', false); });
  }
  var later = function () { clearTimeout(t); t = setTimeout(calc, 200); };
  pane.addEventListener('change', later); users.addEventListener('input', later);
  var shown = false;   // price only once Cloud is opened: on-premises is the default and checks share a per-visitor limit
  document.addEventListener('change', function (e) { if (e.target.name === 'dep' && e.target.value === 'cloud' && !shown) { shown = true; calc(); } });
  [].forEach.call(pane.querySelectorAll('[data-step]'), function (b) { b.addEventListener('click', function () { users.value = Math.max(min, (+users.value || 0) + +b.dataset.step); later(); }); });
  if (!pane.hidden) { shown = true; calc(); }
})();
</script>
@endif
