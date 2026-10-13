{{-- A faithful, live-animated copy of the ROVEXY console Dashboard (sample data) for the website hero.
     Drawn at 1440×1080 and scaled to fit; every menu of the real console is listed. --}}
@php
  $ic = [
    'home' => 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z',
    'bar' => 'M5 9.2h3V19H5zM10.6 5h2.8v14h-2.8zm5.6 8H19v6h-2.8z',
    'people' => 'M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z',
    'phone' => 'M6.62 10.79a15.1 15.1 0 0 0 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1C10.61 21 3 13.39 3 4c0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z',
    'contacts' => 'M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm-8 2.75a2.25 2.25 0 1 1 0 4.5 2.25 2.25 0 0 1 0-4.5zM17 17H7v-1.5c0-1.67 3.33-2.5 5-2.5s5 .83 5 2.5V17zM4 1h16v2H4zm0 20h16v2H4z',
    'device' => 'M17 1.01 7 1a2 2 0 0 0-2 2v18a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3a2 2 0 0 0-2-1.99zM17 19H7V5h10v14z',
    'eye' => 'M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
    'gear' => 'M19.14 12.94a7.07 7.07 0 0 0 0-1.88l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7.03 7.03 0 0 0-1.62-.94l-.36-2.54A.48.48 0 0 0 13.93 2h-3.84a.47.47 0 0 0-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.48.48 0 0 0-.59.22L2.74 8.47a.47.47 0 0 0 .12.61l2.03 1.58a7.2 7.2 0 0 0 0 1.88l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.49.49 0 0 0-.12-.61l-2.01-1.58zM12 15.6a3.6 3.6 0 1 1 0-7.2 3.6 3.6 0 0 1 0 7.2z',
    'check' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z',
    'clock' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z',
    'warn' => 'M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z',
    'cal' => 'M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11zM7 11h5v5H7z',
    'mic' => 'M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z',
    'shield' => 'M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z',
    'bell' => 'M12 22a2 2 0 0 0 2-2h-4a2 2 0 0 0 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4a1.5 1.5 0 0 0-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z',
  ];
  $svg = fn (string $k, string $cls = '') => '<svg class="' . $cls . '" viewBox="0 0 24 24" aria-hidden="true"><path d="' . $ic[$k] . '"/></svg>';
  $menu = [
    [null, 'home', 'Dashboard', []], [null, 'bar', 'Portfolio reports', []],
    ['Team', 'people', null, ['Groups & people']],
    ['Calling', 'phone', null, ['Calls & recordings', 'Follow-ups', 'Payments & slips', 'Configuration']],
    ['Customers', 'contacts', null, ['Portfolios', 'Assignment', 'Customers', 'Address & reachability']],
    ['Devices', 'device', null, ['Devices & approvals', 'Policy profiles']],
    ['Supervision', 'eye', null, ['Status & location', 'Messages', 'Live screen', 'Camera sessions']],
    ['Administration', 'gear', null, ['Plan & licence', 'Reports & exports', 'Company branding', 'Storage & retention', 'Audit logs', 'Integrations', 'System health']],
  ];
  $shotFor = function (string $label) use ($shots) {
      $map = ['Calls & recordings' => ['Calls-and-recordings', .183], 'Payments & slips' => ['Payments-and-slips', .183], 'Portfolio reports' => ['Portfolio-reports', .183],
              'Status & location' => ['Live-status', .183], 'Devices & approvals' => ['Devices-and-approvals', .05], 'Customers' => ['Customers-and-assignment', .05], 'Assignment' => ['Customers-and-assignment', .05]];
      if (!isset($map[$label])) return null;
      foreach ($shots['web'] ?? [] as $sh) { if (stripos($sh['src'], $map[$label][0]) !== false) return [$sh['src'], $map[$label][1]]; }
      return null;
  };
@endphp
<div class="cx" aria-label="The ROVEXY console dashboard, live, with sample data">
  <div class="cx-canvas">
    <aside class="cx-side">
      <div class="cx-brand"><b>DC</b><span>Demo Collections</span><i>‹</i></div>
      <nav>
        @foreach($menu as [$group, $icon, $label, $items])
          @if(!$group)
            <?php $sh = $shotFor($label); ?>
            <a href="#" class="{{ $label === 'Dashboard' ? 'on' : '' }}" data-label="{{ $label }}" @if($label === 'Dashboard') data-live @elseif($sh) data-shot="{{ $sh[0] }}" data-cut="{{ $sh[1] }}" @endif>{!! $svg($icon) !!}{{ $label }}</a>
          @else
            <div class="cx-grp">{!! $svg($icon) !!}<span>{{ $group }}</span><em>{{ count($items) }}</em><i>⌄</i></div>
            @foreach($items as $it)<?php $sh = $shotFor($it); ?><a href="#" class="sub" data-label="{{ $it }}" data-badge="{{ $it === 'Messages' ? '2' : '' }}" @if($sh) data-shot="{{ $sh[0] }}" data-cut="{{ $sh[1] }}" @endif>{{ $it }}</a>@endforeach
          @endif
        @endforeach
      </nav>
      <div class="cx-me"><b>SK</b><span>Sameer Khan<small>sameer@democollections.in</small></span></div>
    </aside>
    <div class="cx-main">
      <header class="cx-top"><span class="crumb">ROVEXY <i>/</i> <b>Dashboard</b></span>
        <span class="cx-clock" data-clock>Mon, 12 Oct · 03:40 pm</span><span class="bell">{!! $svg('bell') !!}<i data-bell></i></span>
        <span class="who"><b>SK</b>Sameer Khan</span><span class="out">Sign out</span></header>
      <div class="cx-page">
        <div class="cx-head"><div><small>Home / Dashboard</small><h3>Dashboard</h3><p>Good afternoon, Sameer · Monday 12 October, 2026</p></div>
          <div class="cx-ctl"><span class="seg"><i>Today</i><i class="on">7 days</i><i>30 days</i></span><span class="sel">All teams</span><span class="tog"><i></i>Live</span><span class="ref">↻</span></div></div>
        <div class="cx-alert">2 phone(s) waiting for approval. <u>Review requests</u></div>
        <div class="cx-row five">
          <div class="k b-navy"><small>Online now</small>{!! $svg('people', 'ki n') !!}<b><span data-k="online">9</span><i> / 10</i></b><p data-k="onlinep">90% of employees</p></div>
          <div class="k b-green"><small>Active</small>{!! $svg('check', 'ki g') !!}<b data-k="active">4</b><p>working in the app</p></div>
          <div class="k b-blue"><small>On call</small>{!! $svg('phone', 'ki bl') !!}<b data-k="oncall">3</b><p>customer calls in progress</p></div>
          <div class="k b-amber"><small>Idle · signed out</small>{!! $svg('clock', 'ki a') !!}<b><span data-k="idle">1</span><i> · 1</i></b><p>online, not working</p></div>
          <div class="k b-grey"><small>Offline</small>{!! $svg('warn', 'ki gr') !!}<b data-k="offline">1</b><p>not seen in 10 min</p></div>
        </div>
        <div class="cx-sub"><b>Calling performance</b> · last 7 days, compared with the previous 7 days</div>
        <div class="cx-row five">
          <div class="k b-navy"><small>Total calls</small>{!! $svg('phone', 'ki bl') !!}<b data-k="calls">1401</b><p class="up">▲ 11% vs previous period</p></div>
          <div class="k b-green"><small>Connected</small>{!! $svg('check', 'ki g') !!}<b><span data-k="conn">919</span><i> · <span data-k="pct">66</span>%</i></b><p class="up">▲ 15% vs previous period</p></div>
          <div class="k b-teal"><small>Talk time</small>{!! $svg('clock', 'ki t') !!}<b data-k="talk" data-s="178620">49h 37m</b><p class="up">▲ 18% vs previous period</p></div>
          <div class="k b-violet"><small>Avg talk / call</small>{!! $svg('clock', 'ki v') !!}<b>3m 14s</b><p>avg ring 14s</p></div>
          <div class="k b-amber"><small>Customers reached</small>{!! $svg('contacts', 'ki a') !!}<b data-k="reached">1088</b><p>10 employee(s) calling</p></div>
        </div>
        <div class="cx-row four">
          <div class="k b-amber"><small>Follow-ups due today</small>{!! $svg('cal', 'ki a') !!}<b data-k="fu">38</b><p>nothing overdue</p></div>
          <div class="k b-violet"><small>Recordings</small>{!! $svg('mic', 'ki v') !!}<b data-k="rec">871</b><p><span data-k="recp">6</span> pending · 0 failed</p></div>
          <div class="k b-navy"><small>Approved phones</small>{!! $svg('device', 'ki n') !!}<b>10</b><p class="bad">2 pending approval</p></div>
          <div class="k b-green"><small>Policy compliance</small>{!! $svg('shield', 'ki g') !!}<b>100%</b><p>0 not compliant · 0 silent 24 h+</p></div>
        </div>
        <div class="cx-bottom">
          <div class="card"><div class="ch"><b>Calls per day — last 7 days</b><u>View calls →</u></div>
            <div class="lg"><i class="c1"></i>Connected <i class="c2"></i>Not connected</div>
            <div class="chart"><div class="axis"><span>244</span><span>183</span><span>122</span><span>61</span><span>0</span></div>
              <div class="cols">
                @foreach([['6 Oct', 64, 104], ['7 Oct', 96, 98], ['8 Oct', 130, 92], ['9 Oct', 118, 70], ['10 Oct', 150, 84], ['11 Oct', 70, 10], ['12 Oct', 120, 62]] as $k => [$d, $c, $n])
                  <div class="col{{ $k === 6 ? ' today' : '' }}"><div class="stack"><i class="n" style="--v:{{ $n }}" @if($k === 6) data-k="todayn" @endif></i><i class="c" style="--v:{{ $c }}" @if($k === 6) data-k="todayc" @endif></i></div><small>{{ $d }}</small></div>
                @endforeach
              </div></div></div>
          <div class="card"><div class="ch"><b>Team status</b></div>
            <div class="donut"><svg viewBox="0 0 42 42"><circle r="15.9" cx="21" cy="21" fill="none" stroke="#E8ECF1" stroke-width="5"/>
              <circle data-seg="work" r="15.9" cx="21" cy="21" fill="none" stroke="#1E7B4F" stroke-width="5" stroke-dasharray="40 60" stroke-dashoffset="25"/>
              <circle data-seg="call" r="15.9" cx="21" cy="21" fill="none" stroke="#1D5FA8" stroke-width="5" stroke-dasharray="30 70" stroke-dashoffset="-15"/>
              <circle data-seg="idle" r="15.9" cx="21" cy="21" fill="none" stroke="#B8893B" stroke-width="5" stroke-dasharray="10 90" stroke-dashoffset="-45"/>
              <circle data-seg="off" r="15.9" cx="21" cy="21" fill="none" stroke="#9AA6B5" stroke-width="5" stroke-dasharray="20 80" stroke-dashoffset="-55"/></svg>
              <div class="mid"><b data-k="online2">9</b><small>of 10 online</small></div></div>
            <ul class="leg"><li><i style="background:#1E7B4F"></i>Working <b data-k="l-work">4</b></li><li><i style="background:#1D5FA8"></i>On call <b data-k="l-call">3</b></li><li><i style="background:#B8893B"></i>Idle <b data-k="l-idle">1</b></li><li><i style="background:#9AA6B5"></i>Offline / signed out <b>2</b></li></ul></div>
        </div>
      </div>
      <div class="cx-shotview" hidden><img alt="" data-shotimg><button type="button" class="cx-back" data-live>● Back to the live dashboard</button></div>
      <div class="cx-note" data-note><b></b><span>This screen is part of the full ROVEXY console.</span><a href="#trial">Start the free trial →</a></div>
      <div class="cx-toast" data-toast><b>New message from Priya S.</b><span>Suresh Babu paid ₹8,000 by UPI. Slip uploaded.</span></div>
    </div>
  </div>
  <button type="button" class="cx-zoom" aria-label="Open the dashboard full screen">⤢ Full screen</button>
  <span class="cx-hint" aria-hidden="true">Click the menu to explore</span>
</div>
