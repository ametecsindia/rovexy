<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'ROVEXY — recorded calls, field visits and payments for collection teams' }}</title>
<meta name="description" content="{{ $meta ?? 'ROVEXY runs telecallers and field agents from one console: calls from company phones recorded from ring to hang-up, lead upload and allocation, verified visit check-ins, payment slips and live team status.' }}">
@if(!empty($noindex))<meta name="robots" content="noindex">@else<link rel="canonical" href="{{ url()->current() }}">@endif
<meta property="og:type" content="website"><meta property="og:site_name" content="ROVEXY">
<meta property="og:title" content="{{ $title ?? 'ROVEXY — recorded calls, field visits and payments for collection teams' }}">
<meta property="og:description" content="{{ $meta ?? 'Recorded calls, verified field visits and payment slips for collection teams, on company Android phones.' }}">
<meta property="og:url" content="{{ url()->current() }}"><meta name="twitter:card" content="summary">
<meta name="theme-color" content="#0A0F1A">
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml"><link rel="icon" href="/favicon.ico" sizes="any"><link rel="apple-touch-icon" href="/brand/rovexy-app-icon-180.png">
@yield('head')
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--ink:#0E1A2B;--navy:#1F3A5F;--navy-2:#2A4C78;--brass:#B8893B;--brass-2:#D4AE6A;--gold:var(--brass);--gold-2:var(--brass-2);--paper:#F5F7FA;--mist:#EEF2F6;--line:#E1E6EC;--mute:#5B6878;--ok:#1E8E5A;--late:#C2410C;
  --display:"Schibsted Grotesk",system-ui,sans-serif;--body:"Schibsted Grotesk",system-ui,sans-serif;--wrap:1200px}
*{box-sizing:border-box}
body{margin:0;font-family:var(--body);font-size:17px;line-height:1.62;color:var(--ink);background:#fff;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility}
a{color:var(--navy)}
:focus-visible{outline:3px solid var(--gold);outline-offset:2px}
.wrap{max-width:var(--wrap);margin:0 auto;padding:0 24px}
h1,h2,h3{font-family:var(--display);line-height:1.05;letter-spacing:-.025em;margin:0 0 .45em;font-weight:700}
h1{font-size:clamp(2.5rem,5.6vw,4.4rem)}h2{font-size:clamp(2rem,3.8vw,3.1rem)}h3{font-size:1.2rem;font-weight:650;letter-spacing:-.01em}
p{margin:0 0 1em;max-width:64ch}.mute{color:var(--mute)}
.btn{display:inline-flex;align-items:center;gap:8px;border:0;border-radius:10px;padding:.82em 1.35em;font:600 .98rem var(--body);text-decoration:none;cursor:pointer;background:var(--navy);color:#fff;transition:background .15s}
.btn:hover{background:var(--ink)}.btn.gold{background:var(--gold);color:var(--ink)}.btn.gold:hover{background:var(--gold-2)}
.btn.ghost{background:transparent;color:inherit;box-shadow:inset 0 0 0 1px rgba(127,140,160,.6)}.btn.ghost:hover{background:rgba(127,140,160,.12)}
.btn[disabled]{opacity:.6;cursor:default}
.notice{background:var(--ink);color:#E9D3A6;border-bottom:1px solid #24364F;text-align:center;font-weight:600;font-size:.92rem;padding:8px 16px}
header.top{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.88);backdrop-filter:saturate(1.4) blur(12px);border-bottom:1px solid var(--line)}
header.top .wrap{display:flex;align-items:center;gap:28px;height:68px}
.logo{display:flex;align-items:center;gap:10px;font:700 1.15rem var(--display);letter-spacing:-.02em;text-decoration:none;color:var(--ink)}
.logo svg{flex:none}.logo img{display:block;height:44px;width:auto}footer .logo img{height:60px}
header.top nav{display:flex;gap:24px;margin-left:auto;align-items:center}
header.top nav a{text-decoration:none;color:#3A4757;font-weight:500;font-size:.94rem;white-space:nowrap}header.top nav a:hover{color:var(--ink)}
.logo{white-space:nowrap}
@media (max-width:520px){header.top .wrap{gap:12px}header.top nav{gap:10px}header.top nav a.hide-xs{display:none}}
header.top nav a.btn{color:var(--ink);padding:.6em 1.15em}
@media (max-width:820px){header.top nav a.hide-sm{display:none}}
.menu{display:none;position:relative}.menu summary{list-style:none;cursor:pointer;padding:8px;border-radius:8px;display:grid;place-items:center}
.menu summary::-webkit-details-marker{display:none}.menu summary:after{content:none}
.menu div{position:absolute;right:0;top:46px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:8px;min-width:200px;box-shadow:0 20px 40px -20px rgba(15,30,51,.4)}
.menu div a{display:block;padding:10px 12px;border-radius:8px}.menu div a:hover{background:var(--mist)}
@media (max-width:820px){.menu{display:block}}
.wa{position:fixed;right:18px;bottom:18px;z-index:20;display:flex;align-items:center;gap:8px;background:#1E8E5A;color:#fff;text-decoration:none;font-weight:600;font-size:.88rem;border-radius:999px;padding:10px 15px 10px 12px;box-shadow:0 14px 30px -12px rgba(15,30,51,.6)}
.wa:hover{background:#17744A}@media print{.wa,header.top,.notice{display:none}}
section{padding:96px 0}
label{display:block;font-weight:600;font-size:.9rem;margin:0 0 14px}
input,select,textarea{display:block;width:100%;margin-top:6px;padding:.72em .85em;border:1px solid #C9D2DC;border-radius:10px;font:inherit;font-size:1rem;background:#fff;color:var(--ink)}
input:focus,select:focus,textarea:focus{border-color:var(--navy);outline:2px solid rgba(31,58,95,.18);outline-offset:0}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media (max-width:640px){.grid2{grid-template-columns:1fr}}
.err{background:#FDEDE7;color:#9A2F0A;border-radius:10px;padding:12px 14px;margin-bottom:16px}
.okbox{background:#E7F4EC;color:#14532D;border-radius:10px;padding:12px 14px;margin-bottom:16px}
.hp{position:absolute;left:-9999px}
.check{display:flex;gap:10px;align-items:flex-start;font-weight:400}.check input{width:auto;margin:.3em 0 0}
footer{background:#0A1422;color:#93A4B9;padding:72px 0 36px;font-size:.92rem;border-top:1px solid #1C2B3F}
footer .cols{display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr;gap:32px}@media (max-width:820px){footer .cols{grid-template-columns:1fr 1fr}}
footer h4{color:#fff;font:700 .95rem var(--display);margin:0 0 12px}footer ul{list-style:none;margin:0;padding:0}footer li{padding:4px 0}
footer a{color:#DCE4EE;text-decoration:none}footer a:hover{color:#fff;text-decoration:underline}footer .logo{color:#fff;margin-bottom:12px}
.play-badge{display:inline-flex;align-items:center;gap:10px;padding:8px 18px 8px 14px;border-radius:12px;background:#0B1422;border:1px solid rgba(255,255,255,.22);color:#fff;text-decoration:none;line-height:1.1;white-space:nowrap}.play-badge:hover{background:#000;color:#fff;text-decoration:none}.play-badge svg{color:var(--brass-2)}.play-badge small{display:block;font-size:.66rem;letter-spacing:.06em;opacity:.85}.play-badge b{display:block;font-size:1.15rem;font-weight:600}footer .play-badge{margin-top:18px}
footer address{font-style:normal;max-width:34ch}footer .base{border-top:1px solid #24364F;margin-top:40px;padding-top:20px;display:flex;flex-wrap:wrap;gap:8px 24px;justify-content:space-between}
@media (prefers-reduced-motion:reduce){*,*:before,*:after{animation:none!important;transition:none!important}}
@yield('style')
</style>
</head>
<body>
@if(!empty($announcement))<div class="notice" role="note">{{ $announcement }}</div>@endif
<header class="top"><div class="wrap">
  <a class="logo" href="/" aria-label="ROVEXY home"><img src="/brand/rovexy-lockup.svg" alt="ROVEXY" width="151" height="44"></a>
  <nav aria-label="Main">
    <a class="hide-sm" href="/#how">How it works</a><a class="hide-sm" href="/demo">Live demo</a><a class="hide-sm" href="/#product">Product</a><a class="hide-sm" href="/#security">Security</a><a class="hide-sm" href="/#pricing">Pricing</a><a class="hide-sm" href="/#faq">FAQ</a>
    <a class="hide-xs" href="/admin">Sign in</a><a class="btn gold" href="/#trial">Free trial</a>
    <details class="menu" onclick="if(event.target.tagName==='A')this.open=false"><summary aria-label="Menu"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="#0F1E33" stroke-width="2" stroke-linecap="round"/></svg></summary>
      <div><a href="/#how">How it works</a><a href="/demo">Live demo</a><a href="/#screens">Screens</a><a href="/#product">Product</a><a href="/#security">Security</a><a href="/#pricing">Pricing</a><a href="/#faq">FAQ</a><a href="/contact">Contact</a><a href="/admin">Sign in</a></div></details>
  </nav>
</div></header>
@yield('main')
@php($co = \App\Services\Platform\PlatformSettings::class)
@php($phone = $co::get('seller_phone'))
@php($wa = strlen($d = preg_replace('/\D/', '', (string) $phone)) === 10 ? '91' . $d : $d)
<footer><div class="wrap">
  <div class="cols">
    <div><span class="logo"><img src="/brand/rovexy-logo-dark.svg" alt="ROVEXY — Your Workforce. In Motion." width="206" height="60"></span>
      <p>Recorded calls, verified visits and payment slips for collection teams. A product of {{ $co::get('seller_name') }}.</p>
      <address>{{ $co::get('seller_address') }}</address>
      <a class="play-badge" href="https://play.google.com/store/apps/details?id=in.ametecs.managedmobile&amp;hl=en_IN" target="_blank" rel="noopener" aria-label="Get it on Google Play"><svg width="26" height="26" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 3.5v17a.5.5 0 0 0 .76.43l14.5-8.5a.5.5 0 0 0 0-.86L5.76 3.07A.5.5 0 0 0 5 3.5z"/></svg><span><small>GET IT ON</small><b>Google Play</b></span></a></div>
    <div><h4>Product</h4><ul><li><a href="/#how">How it works</a></li><li><a href="/demo">Live demo</a></li><li><a href="/#screens">Screens</a></li><li><a href="/#product">Product</a></li><li><a href="/#security">Security</a></li><li><a href="/#pricing">Pricing</a></li></ul></div>
    <div><h4>Get started</h4><ul><li><a href="/#trial">Free trial</a></li><li><a href="/buy">Buy a package</a></li><li><a href="/admin">Sign in</a></li><li><a href="/#faq">FAQ</a></li></ul></div>
    <div><h4>Company</h4><ul><li><a href="/contact">Contact us</a></li><li><a href="/privacy">Privacy policy</a></li><li><a href="/terms">Terms of service</a></li><li><a href="/refund">Refund &amp; cancellation</a></li><li><a href="/delivery">Service delivery</a></li></ul></div>
  </div>
  <div class="base"><span>© {{ date('Y') }} {{ $co::get('seller_name') }}{{ $co::get('seller_gstin') ? ' · GSTIN ' . $co::get('seller_gstin') : '' }}</span>
    <span>@if($phone)<a href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">{{ $phone }}</a> · @endif @if($co::get('seller_email'))<a href="mailto:{{ $co::get('seller_email') }}">{{ $co::get('seller_email') }}</a>@endif</span></div>
</div></footer>
@if($wa && empty($noindex))<a class="wa" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Hi, I would like to know more about ROVEXY.') }}" target="_blank" rel="noopener"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#fff" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.8 2c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.1-.3.3-.1.6.2.3.8 1.3 1.6 2.1 1.1 1 2 1.3 2.3 1.4.3.2.5.1.6 0l.9-1c.2-.3.4-.2.6-.1l1.9.9c.3.1.5.2.5.3.1.1.1.7-.1 1.3z"/></svg>WhatsApp us</a>@endif
@yield('script')
</body>
</html>
