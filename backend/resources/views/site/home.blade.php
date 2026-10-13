@extends('site.layout', ['announcement' => $site['announcement'], 'meta' => $site['meta']])
@section('style')
/* ════ ROVEXY landing — two-block layout (12 Oct 2026, after the SmartEPT site) ════ */
.band{padding:112px 0}.band.paper{background:var(--paper)}.band.dark{background:var(--ink);color:#C3CFDD}.band.dark h2,.band.dark h3{color:#fff}
@media (max-width:760px){.band{padding:76px 0}}
.two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:64px;align-items:center}
.two.head{align-items:end;margin-bottom:40px}.two.head h2{margin:0}.two.head p{margin:0}
@media (max-width:960px){.two{grid-template-columns:1fr;gap:40px}}
.copy h2{max-width:16ch}.copy p{color:var(--mute)}.band.dark .copy p{color:#A9B7C8}
.mute{color:var(--mute)}

/* grid children must be allowed to shrink (horizontal tab strips inside) */
.tour > *,.two > *,.hero-grid > *{min-width:0}
/* hero: text block + live dashboard block */
.hero{--hp:clamp(28px,6vh,72px);--hw:1200px;background:var(--ink);color:#fff;padding:var(--hp) 0;position:relative;overflow:hidden}
.hero:before{content:"";position:absolute;inset:0;background:radial-gradient(900px 600px at 85% 30%,rgba(42,76,120,.75),transparent 70%);pointer-events:none}
.hero-grid{position:relative;display:grid;grid-template-columns:minmax(0,.82fr) minmax(0,1.18fr);gap:clamp(28px,3.5vw,56px);align-items:center}
/* desktop: the whole hero fits one screen; the dashboard is sized by the screen height so it is never cut off */
@media (min-width:1061px){.hero{min-height:calc(100svh - 69px);display:flex;align-items:center}.hero > .wrap{width:100%}.hero-grid{grid-template-columns:minmax(0,1fr) auto}.hero .cx{width:min(calc(min(100vw,var(--hw)) * .56),calc((100svh - 69px - 2 * var(--hp)) * 1180 / 900))}@media (min-width:1600px){.hero{--hw:1440px}.hero > .wrap{max-width:1440px}}.hero .cx.full{width:min(96vw,calc(92vh * 1180 / 900))}}
@media (min-width:1061px) and (max-height:760px){.hero .links{display:none}.hero .lead{font-size:1rem;margin-bottom:20px}.hero .kicker{margin-bottom:10px}.hero .proof{margin-top:20px}}
@media (max-width:700px){.hero .cx-hint{display:none}}
@media (max-width:1060px){.hero-grid{grid-template-columns:1fr}}
.kicker{color:var(--brass-2);font-weight:600;font-size:.95rem;margin:0 0 18px}
.hero h1{color:#fff;font-size:clamp(2.1rem,min(3.6vw,6.4vh),3.6rem);line-height:1.04;letter-spacing:-.03em;margin:0 0 .5em;max-width:15ch}
.hero .lead{font-size:1.12rem;color:#C3CFDD;max-width:48ch;margin:0 0 28px}
.cta{display:flex;gap:12px;flex-wrap:wrap}.hero .cta .btn{white-space:nowrap}
.links{display:flex;gap:22px;margin-top:18px}.links a{color:#C3CFDD;font-weight:500;font-size:.95rem}.links a:hover{color:#fff}
.proof{list-style:none;margin:30px 0 0;padding:0;display:flex;flex-direction:column;gap:6px;color:#93A4B9;font-size:.9rem}
.proof li{display:flex;gap:8px;align-items:center}.proof li:before{content:"";width:6px;height:6px;border-radius:50%;background:var(--ok)}


/* hero: faithful console dashboard, drawn at 1180×900 and scaled to its column */
.cx{position:relative;width:100%;aspect-ratio:1180/900;border-radius:14px;overflow:hidden;box-shadow:0 50px 100px -30px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.1);background:#F5F7FA;animation:rise 1s .1s cubic-bezier(.2,.7,.2,1) both}
@keyframes rise{from{opacity:0;transform:translateY(40px)}}
.cx-canvas{position:absolute;left:0;top:0;width:1180px;height:900px;transform-origin:0 0;transform:scale(var(--cxs,.5));display:grid;grid-template-columns:228px 1fr;
  font:14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1B2733;background:#F5F7FA;text-align:left}
.cx svg{fill:currentColor}
.cx-side{background:linear-gradient(180deg,#1F3A5F,#16304F);color:#DFE7F1;display:flex;flex-direction:column;padding:12px 10px 10px}
.cx-brand{display:flex;align-items:center;gap:10px;padding:0 4px 10px;border-bottom:1px solid rgba(255,255,255,.1);margin-bottom:8px}
.cx-brand b{width:40px;height:40px;border-radius:10px;border:1.5px dashed rgba(255,255,255,.35);display:grid;place-items:center;color:#fff;font-size:15px}
.cx-brand span{flex:1;color:#fff;font-weight:600;font-size:15px;white-space:nowrap}.cx-brand i{width:26px;height:26px;border-radius:7px;border:1px solid rgba(255,255,255,.2);display:grid;place-items:center;font-style:normal}
.cx-side nav{flex:1;display:flex;flex-direction:column;gap:1px}
.cx-side a,.cx-grp{display:flex;align-items:center;gap:10px;padding:5px 10px;border-radius:8px;font-size:13.5px;color:#DFE7F1;transition:background .2s}
.cx-side a svg,.cx-grp svg{width:19px;height:19px;flex:none;opacity:.9}
.cx-side a.on{background:rgba(255,255,255,.14);color:#fff;font-weight:600;box-shadow:inset 0 0 0 1px rgba(255,255,255,.12)}
.cx-grp{margin-top:2px;color:#fff;font-weight:500}.cx-grp span{flex:1}.cx-grp em{font-style:normal;font-size:11px;background:rgba(255,255,255,.12);border-radius:999px;padding:1px 7px}.cx-grp i{font-style:normal;opacity:.6}
.cx-side a.sub{padding:2.5px 10px 2.5px 39px;font-size:12px;color:#B9C8DA;position:relative}
.cx-side a.sub:before{content:"";position:absolute;left:21px;top:0;bottom:0;width:1px;background:rgba(255,255,255,.14)}
.cx-side a.sub[data-badge]:not([data-badge=""]):after{content:attr(data-badge);margin-left:auto;background:#1E7B4F;color:#fff;font-size:10.5px;border-radius:999px;padding:0 6px}
.cx-side a.hot{background:rgba(255,255,255,.1);color:#fff}
.cx-me{display:flex;gap:10px;align-items:center;border-top:1px solid rgba(255,255,255,.1);padding:12px 6px 0}
.cx-me b{width:36px;height:36px;border-radius:50%;background:#D4AE6A;color:#1B2733;display:grid;place-items:center;font-size:13px}.cx-me span{font-size:13.5px;color:#fff;font-weight:600}.cx-me small{display:block;color:#9FB0C6;font-weight:400;font-size:11.5px}
.cx-main{position:relative;display:flex;flex-direction:column;min-width:0}
.cx-top{display:flex;align-items:center;gap:22px;padding:10px 20px;border-bottom:1px solid #DDE3EA;background:rgba(255,255,255,.7)}
.cx-top .crumb{flex:1;color:#5D6B79;font-size:15px}.cx-top .crumb i{font-style:normal;margin:0 6px;opacity:.6}.cx-top .crumb b{color:#1B2733}
.cx-clock{color:#5D6B79;font-size:14px;font-variant-numeric:tabular-nums}
 .cx .bell{position:relative;color:#C99A2E} .cx .bell svg{width:20px;height:20px} .cx .bell i{position:absolute;right:-3px;top:-2px;width:8px;height:8px;border-radius:50%;background:#B42318;display:none} .cx .bell i.on{display:block}
 .cx .who{display:flex;align-items:center;gap:8px;font-weight:600;font-size:14.5px} .cx .who b{width:30px;height:30px;border-radius:50%;background:#D4AE6A;display:grid;place-items:center;font-size:12px}
 .cx .out{border:1px solid #DDE3EA;background:#fff;border-radius:8px;padding:6px 12px;font-weight:600;color:#1F3A5F;font-size:13.5px}
.cx-page{padding:12px 20px 14px;display:flex;flex-direction:column;gap:9px;flex:1;min-height:0}
.cx-head{display:flex;justify-content:space-between;align-items:flex-end}.cx-head small{color:#5D6B79;font-size:12.5px}
.cx-head h3{margin:1px 0;font-size:23px;letter-spacing:-.01em;color:#1B2733}.cx-head p{margin:0;color:#5D6B79;font-size:14.5px;max-width:none}
.cx-ctl{display:flex;gap:10px;align-items:center} .cx .seg{display:flex;border:1px solid #DDE3EA;border-radius:8px;overflow:hidden;background:#fff}
 .cx .seg i{font-style:normal;padding:7px 13px;font-size:14px;color:#5D6B79} .cx .seg i.on{background:#1F3A5F;color:#fff}
 .cx .sel{border:1px solid #DDE3EA;background:#fff;border-radius:8px;padding:7px 40px 7px 12px;font-size:14px}
 .cx .tog{display:flex;gap:7px;align-items:center;font-weight:600;font-size:14px} .cx .tog i{width:34px;height:19px;border-radius:999px;background:#1E7B4F;position:relative} .cx .tog i:after{content:"";position:absolute;right:2px;top:2px;width:15px;height:15px;border-radius:50%;background:#fff}
 .cx .ref{border:1px solid #DDE3EA;background:#fff;border-radius:8px;width:32px;height:32px;display:grid;place-items:center;color:#5D6B79}
.cx-alert{background:#FFF4DC;border:1px solid #F2DDAA;border-left:4px solid #B8893B;border-radius:8px;padding:10px 16px;color:#8A5A00;font-size:14.5px}.cx-alert u{color:#1D5FA8}
.cx-row{display:grid;gap:12px}.cx-row.five{grid-template-columns:repeat(5,1fr)}.cx-row.four{grid-template-columns:repeat(4,1fr)}
 .cx .k{background:#fff;border:1px solid #DDE3EA;border-left-width:4px;border-radius:12px;padding:9px 11px;position:relative;box-shadow:0 1px 2px rgba(16,32,52,.05)}
 .cx .k small{display:block;color:#5D6B79;font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;padding-right:36px}
 .cx .k b{display:block;font-size:23px;font-weight:700;color:#1F3A5F;margin:3px 0 1px;font-variant-numeric:tabular-nums;transition:color .4s} .cx .k b i{font-style:normal;font-size:15px;color:#5D6B79;font-weight:500}
 .cx .k b.bump{color:#1E7B4F} .cx .k p{margin:0;color:#5D6B79;font-size:13px;max-width:none} .cx .k p.up{color:#1E7B4F;font-weight:600} .cx .k p.bad{color:#B42318;font-weight:600}
 .cx .ki{position:absolute;right:12px;top:12px;width:30px;height:30px;padding:6px;border-radius:8px}
 .cx .ki.n{background:#E8EEF6;color:#1F3A5F} .cx .ki.g{background:#E6F4EC;color:#1E7B4F} .cx .ki.bl{background:#E7F0FB;color:#1D5FA8} .cx .ki.a{background:#FFF4DC;color:#B8893B} .cx .ki.gr{background:#EEF1F4;color:#5D6B79} .cx .ki.t{background:#E3F4F3;color:#0F8A84} .cx .ki.v{background:#F0ECFB;color:#6B4FC8}
 .cx .b-navy{border-left-color:#1F3A5F} .cx .b-green{border-left-color:#1E7B4F} .cx .b-blue{border-left-color:#1D5FA8} .cx .b-amber{border-left-color:#B8893B} .cx .b-grey{border-left-color:#9AA6B5} .cx .b-teal{border-left-color:#0F8A84} .cx .b-violet{border-left-color:#6B4FC8}
.cx-sub{font-size:15px;color:#5D6B79}.cx-sub b{color:#1B2733}
.cx-bottom{display:grid;grid-template-columns:1.75fr 1fr;gap:12px;flex:1;min-height:0}
.cx-bottom .card{background:#fff;border:1px solid #DDE3EA;border-radius:12px;padding:12px 16px;display:flex;flex-direction:column;min-height:0}
 .cx .ch{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #EEF1F4;padding-bottom:8px} .cx .ch b{font-size:16px;border-left:3px solid #B8893B;padding-left:8px} .cx .ch u{color:#1D5FA8;font-size:14px}
 .cx .lg{display:flex;gap:8px;align-items:center;font-size:13px;color:#5D6B79;margin:8px 0} .cx .lg i{width:11px;height:11px;border-radius:2px;display:inline-block} .cx .lg .c1{background:#2563A8} .cx .lg .c2{background:#B8893B;margin-left:10px}
 .cx .chart{display:flex;gap:8px;flex:1;min-height:0} .cx .axis{display:flex;flex-direction:column;justify-content:space-between;font-size:11.5px;color:#5D6B79;padding-bottom:22px}
 .cx .cols{flex:1;display:flex;align-items:flex-end;gap:22px;padding:0 10px;background:repeating-linear-gradient(0deg,transparent 0 calc(25% - 1px),#EEF1F4 calc(25% - 1px) 25%)}
 .cx .col{flex:1;display:flex;flex-direction:column;align-items:stretch;height:100%;justify-content:flex-end} .cx .col small{text-align:center;color:#5D6B79;font-size:11.5px;margin-top:6px;height:16px}
 .cx .stack{display:flex;flex-direction:column;justify-content:flex-end;height:calc(100% - 22px)}
 .cx .stack i{display:block;height:calc(var(--v) / 244 * 100%);transition:height 1.2s cubic-bezier(.2,.7,.2,1)} .cx .stack .n{background:#B8893B;border-radius:4px 4px 0 0} .cx .stack .c{background:#2563A8}

 .cx .donut{position:relative;width:150px;height:150px;margin:8px auto 4px} .cx .donut svg{width:100%;height:100%;transform:rotate(-90deg)}
 .cx .donut circle{transition:stroke-dasharray 1s,stroke-dashoffset 1s} .cx .mid{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center} .cx .mid b{font-size:32px;color:#1B2733} .cx .mid small{color:#5D6B79;font-size:13px}
 .cx .leg{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:13px;color:#5D6B79} .cx .leg li{display:flex;align-items:center;gap:6px} .cx .leg i{width:10px;height:10px;border-radius:50%} .cx .leg b{margin-left:auto;color:#1B2733}
.cx-toast{position:absolute;right:24px;bottom:24px;width:360px;background:#fff;border:1px solid #DDE3EA;border-left:4px solid #1E7B4F;border-radius:10px;padding:12px 14px;box-shadow:0 18px 40px -16px rgba(16,32,52,.45);font-size:13.5px;transform:translateX(120%);transition:transform .45s cubic-bezier(.2,.7,.2,1)}
.cx-toast.on{transform:none}.cx-toast b{display:block;margin-bottom:2px}.cx-toast span{color:#5D6B79}
.cx-zoom{position:absolute;right:12px;bottom:12px;z-index:3;border:0;border-radius:8px;background:rgba(14,26,43,.82);color:#fff;font:600 12px var(--body);padding:7px 11px;cursor:pointer;opacity:0;transition:opacity .2s}
.cx:hover .cx-zoom,.cx-zoom:focus-visible{opacity:1}
.cx.full{position:fixed;z-index:100;inset:0;margin:auto;width:min(96vw,calc(92vh * 1180 / 900));height:auto;border-radius:12px}
.cx-backdrop{position:fixed;inset:0;z-index:99;background:rgba(8,15,26,.78)}
@media (max-width:760px){.cx-zoom{opacity:1}}


/* hero console is clickable: menu items open the real screens; the dashboard itself opens full screen */
.cx .cx-side a{cursor:pointer;text-decoration:none}.cx .cx-side a:hover{background:rgba(255,255,255,.1);color:#fff}
.cx .cx-main{cursor:zoom-in}.cx.full .cx-main{cursor:default}.cx.shot .cx-main{cursor:default}
.cx .cx-shotview{position:absolute;inset:0;z-index:4;background:#F5F6FA;overflow:hidden;animation:shotin .35s ease-out}
.cx .cx-shotview[hidden]{display:none}
.cx .cx-shotview img{display:block;height:auto;max-width:none}
@keyframes shotin{from{opacity:0;transform:translateY(10px)}}
.cx .cx-back{position:absolute;left:20px;bottom:18px;border:0;border-radius:999px;background:#0E1A2B;color:#fff;font:600 14px system-ui,sans-serif;padding:10px 16px;cursor:pointer;box-shadow:0 10px 24px -8px rgba(0,0,0,.5)}
.cx .cx-back:hover{background:#1F3A5F}
.cx .cx-note{position:absolute;left:50%;top:50%;z-index:6;transform:translate(-50%,-40%);width:420px;background:#fff;border:1px solid #DDE3EA;border-radius:14px;padding:22px 24px;box-shadow:0 30px 60px -20px rgba(16,32,52,.45);text-align:center;opacity:0;pointer-events:none;transition:opacity .25s,transform .25s}
.cx .cx-note.on{opacity:1;transform:translate(-50%,-50%);pointer-events:auto}.cx .cx-note b{display:block;font-size:20px;margin-bottom:6px}.cx .cx-note span{display:block;color:#5D6B79;font-size:14.5px;margin-bottom:14px}
.cx .cx-note a{display:inline-block;background:#B8893B;color:#0E1A2B;border-radius:10px;padding:9px 16px;font-weight:600;font-size:14px;text-decoration:none}
.cx-hint{position:absolute;left:12px;bottom:12px;z-index:3;background:rgba(14,26,43,.82);color:#E9D3A6;font:600 12px var(--body);padding:6px 11px;border-radius:999px;pointer-events:none;animation:hintpulse 2.4s ease-in-out 3}
@keyframes hintpulse{50%{transform:translateY(-3px)}}
.cx.full .cx-hint{display:none}
/* before / after block */
.ba{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media (max-width:620px){.ba{grid-template-columns:1fr}}
.ba > div{border-radius:18px;padding:26px}.ba .old{background:var(--paper);border:1px solid var(--line)}.ba .new{background:var(--navy);color:#fff}.ba .new h3{color:#fff}
.ba h3{font-size:1.02rem;margin-bottom:12px}.ba ul{list-style:none;margin:0;padding:0}.ba li{padding:9px 0 9px 26px;position:relative;font-size:.94rem;border-top:1px solid rgba(127,140,160,.25)}
.ba .old li{color:var(--mute)}.ba .old li:before{content:"×";position:absolute;left:3px;top:6px;color:var(--late);font-weight:700}
.ba .new li:before{content:"";position:absolute;left:4px;top:1.05em;width:10px;height:5px;border-left:2px solid var(--brass-2);border-bottom:2px solid var(--brass-2);transform:rotate(-45deg)}

/* workflow tour: steps block + phone block */
/* how it works: dark stage, gold progress rail */
.how{background:radial-gradient(900px 600px at 78% 40%,rgba(184,137,59,.16),transparent 60%),radial-gradient(700px 500px at 10% 100%,rgba(42,76,120,.45),transparent 60%),var(--ink);color:#B9C6D6;overflow:hidden}
.how h2{color:#fff;max-width:13ch}.how .sub{color:#9FB0C4;max-width:46ch}
.tour{display:grid;grid-template-columns:minmax(0,.95fr) minmax(0,1.05fr);gap:64px;align-items:center}
@media (max-width:960px){.tour{grid-template-columns:1fr;gap:30px}}
.lanes{display:flex;gap:18px;font-size:.8rem;margin:22px 0 4px;color:#8FA0B5}.lanes span:before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;background:#5E86B8}.lanes .f:before{background:var(--brass-2)}
.tabs{display:flex;flex-direction:column;gap:2px;margin-top:10px;position:relative}
.tabs .rail{position:absolute;left:25px;top:22px;bottom:22px;width:2px;background:rgba(255,255,255,.1);border-radius:2px}
.tabs .rail i{position:absolute;left:0;top:0;width:100%;height:calc(var(--p,0) * 100%);background:linear-gradient(var(--brass-2),var(--brass));border-radius:2px;transition:height .6s cubic-bezier(.2,.7,.2,1);box-shadow:0 0 12px rgba(212,174,106,.6)}
.tabs button{all:unset;cursor:pointer;display:flex;gap:16px;align-items:center;padding:8px 12px;border-radius:12px;position:relative;transition:background .25s;color:#8FA0B5}
.tabs button:hover{background:rgba(255,255,255,.04);color:#C9D4E1}.tabs button:focus-visible{outline:2px solid var(--brass-2)}
.tabs .n{flex:none;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--ink);border:1.5px solid #34475F;font-weight:700;font-size:.78rem;position:relative;z-index:1;transition:all .3s}
.tabs button.done .n{border-color:var(--brass);color:var(--brass-2)}
.tabs b{display:block;font-weight:600;font-size:.97rem;line-height:1.3}.tabs small{font-size:.74rem;opacity:.75}
.tabs button.on{background:linear-gradient(90deg,rgba(184,137,59,.16),rgba(184,137,59,0));color:#fff}
.tabs button.on .n{background:var(--brass);border-color:var(--brass);color:var(--ink);box-shadow:0 0 0 6px rgba(184,137,59,.18)}
.tabs .bar{position:absolute;left:56px;right:12px;bottom:3px;height:2px;width:0;background:var(--brass-2);opacity:.7;border-radius:2px}.tabs button.on.run .bar{animation:fill var(--dwell,7s) linear forwards}@keyframes fill{to{width:calc(100% - 68px)}}
@media (max-width:960px){.tabs{flex-direction:row;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:6px;gap:6px}.tabs .rail{display:none}.tabs button{flex:none;scroll-snap-align:start;border:1px solid #2A3B52;padding:7px 12px 7px 8px}.tabs small{display:none}.tabs b{font-size:.84rem;white-space:nowrap}.tabs .bar{left:8px}.lanes{display:none}}
.stage{position:relative;display:flex;flex-direction:column;align-items:center}
.stage .rings{position:absolute;left:50%;top:44%;width:620px;aspect-ratio:1;transform:translate(-50%,-50%);pointer-events:none}
.stage .rings i{position:absolute;inset:0;border-radius:50%;border:1px solid rgba(212,174,106,.16)}.stage .rings i:nth-child(2){inset:14%;border-color:rgba(212,174,106,.22)}.stage .rings i:nth-child(3){inset:28%;border:0;background:radial-gradient(circle,rgba(184,137,59,.28),transparent 68%)}
.stage .rings:after{content:"";position:absolute;inset:0;border-radius:50%;background:conic-gradient(from 0deg,transparent 0 80%,rgba(212,174,106,.35) 92%,transparent);mask:radial-gradient(circle,transparent 69.6%,#000 70%,#000 71%,transparent 71.4%);-webkit-mask:radial-gradient(circle,transparent 69.6%,#000 70%,#000 71%,transparent 71.4%);animation:sweep 9s linear infinite}
@keyframes sweep{to{transform:rotate(1turn)}}
.panes{position:relative;min-height:760px;width:100%;overflow:hidden}
.pane{position:absolute;inset:0;opacity:0;visibility:hidden;transform:translateY(16px) scale(.98);transition:opacity .45s,transform .45s,visibility .45s;display:flex;flex-direction:column;align-items:center}
.pane.on{opacity:1;visibility:visible;transform:none;position:relative}
.how .droid{color:var(--ink)}
.pane .droid{box-shadow:0 40px 80px -20px rgba(0,0,0,.7),0 0 0 1px rgba(255,255,255,.06)}
.cap{margin-top:22px;max-width:440px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);backdrop-filter:blur(10px);border-radius:16px;padding:14px 18px;text-align:left}
.cap small{display:block;color:var(--brass-2);font-weight:600;font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;margin-bottom:4px}
.cap p{margin:0;color:#B9C6D6;font-size:.94rem;line-height:1.55}.cap p b{color:#fff}
.tour-nav{position:absolute;z-index:2;top:40%;left:0;right:0;display:flex;justify-content:space-between;pointer-events:none}
.tour-nav button{all:unset;pointer-events:auto;cursor:pointer;width:44px;height:44px;border-radius:50%;display:grid;place-items:center;font-size:1.5rem;color:#fff;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);transition:background .2s}
.tour-nav button:hover{background:var(--brass);color:var(--ink)}.tour-nav button:focus-visible{outline:2px solid var(--brass-2)}
@media (max-width:960px){.panes{min-height:0}.stage .rings{width:440px}.tour-nav{top:34%}.tour-nav button{width:38px;height:38px}}

/* realistic Android phone */
.droid{width:300px;height:628px;border-radius:48px;padding:11px;background:linear-gradient(145deg,#2B313A,#0D1015);box-shadow:inset 0 0 0 2px #3C434E,0 40px 80px -30px rgba(14,26,43,.6),0 0 0 1px #0D1015;position:relative;flex:none}
.droid:before{content:"";position:absolute;right:-3px;top:130px;width:3px;height:70px;border-radius:0 3px 3px 0;background:#2B313A}
.droid:after{content:"";position:absolute;right:-3px;top:220px;width:3px;height:42px;border-radius:0 3px 3px 0;background:#2B313A}
.d-screen{position:relative;height:100%;border-radius:38px;overflow:hidden;background:#F4F6F9;font-size:12px;line-height:1.35;display:flex;flex-direction:column}
.d-status{position:absolute;z-index:6;left:0;right:0;top:0;height:30px;display:flex;justify-content:space-between;align-items:center;padding:0 22px;color:#fff;font-size:.78rem;font-weight:600}
.d-status .cam{position:absolute;left:50%;top:9px;width:12px;height:12px;margin-left:-6px;border-radius:50%;background:#0B0E12;box-shadow:inset 0 0 0 2px #1C222B}
.d-ic{display:flex;gap:5px}.d-ic svg{height:10px;width:auto}
.d-nav{position:absolute;z-index:6;left:0;right:0;bottom:0;height:22px;display:grid;place-items:center}.d-nav i{width:96px;height:4px;border-radius:4px;background:rgba(14,26,43,.55)}
.a-hero{background:linear-gradient(160deg,#1F3A5F,#2A4C78);color:#fff;padding:40px 16px 16px;border-radius:0 0 26px 26px}
.a-row{display:flex;justify-content:space-between;align-items:center;gap:8px}.a-row b{font-size:1rem}
.a-pill{font-size:.66rem;background:rgba(255,255,255,.14);padding:3px 8px;border-radius:999px;color:#D4E1F0}
.a-today{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:14px;background:rgba(255,255,255,.08);border-radius:14px;padding:10px}
.a-today b{display:block;font-size:1rem;font-variant-numeric:tabular-nums}.a-today small{color:#C8D3E0;font-size:.62rem}
.a-tiles{display:flex;gap:6px;overflow:hidden;padding:12px 12px 0}.a-tiles span{flex:none;background:#fff;border:1px solid #E1E6EC;border-radius:10px;padding:6px 8px;font-size:.66rem;color:var(--mute)}
.a-tiles span b{display:block;color:var(--ink);font-size:.9rem}.a-tiles span.w{border-color:var(--brass)}
.a-search{margin:10px 12px 0;background:#fff;border:1px solid #E1E6EC;border-radius:12px;padding:9px 12px;color:#98A3B3;font-size:.72rem}
.a-list{padding:8px 12px;display:flex;flex-direction:column;gap:7px}
.a-case{display:grid;grid-template-columns:32px 1fr auto;gap:10px;align-items:center;background:#fff;border:1px solid #E1E6EC;border-radius:14px;padding:9px 10px}
.a-case > i{width:32px;height:32px;border-radius:50%;background:#E6ECF4;color:var(--navy);font-style:normal;font-weight:700;display:grid;place-items:center}
.a-case b{display:block;font-size:.8rem}.a-case small{color:var(--mute);font-size:.66rem}.a-case .r{text-align:right}
.a-case em{display:block;font-style:normal;font-size:.62rem;font-weight:600;margin-top:2px}.a-case em.r{color:var(--late)}.a-case em.a{color:#B7791F}.a-case em.g{color:var(--ok)}
.a-hero.case{padding-top:36px}.a-hero.case .a-row{font-size:.78rem}.a-hero.case .gs{color:#E5C98F}
.a-who{display:flex;gap:10px;align-items:center;margin-top:8px}.a-who > i{width:44px;height:44px;border-radius:50%;background:#E6ECF4;color:var(--navy);font-style:normal;font-weight:700;font-size:1.1rem;display:grid;place-items:center;flex:none}
.a-who b{display:block;font-size:1rem}.a-who small{display:block;color:#C8D3E0;font-size:.64rem}.a-who div{flex:1;min-width:0}
.a-st{font-size:.6rem;background:#FBF1E0;color:#94600E;border-radius:999px;padding:2px 7px;font-weight:600}
.a-met{display:grid;grid-template-columns:repeat(3,1fr);margin-top:12px}.a-met b{display:block;font-size:.95rem}.a-met b.al{color:#FFB4A8}.a-met small{color:#C8D3E0;font-size:.6rem}
.a-acts{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;padding:10px 10px 0}
.a-acts span{background:#fff;border:1px solid #E1E6EC;border-radius:12px;padding:7px 0;text-align:center;font-size:.64rem;font-weight:600;display:flex;flex-direction:column;align-items:center;gap:3px}
.a-acts svg{width:18px;height:18px;fill:currentColor}.a-acts .g{color:#1E8E5A}.a-acts .a{color:#B7791F}.a-acts .t{color:#0F8A84}.a-acts .b{color:#2563EB}
.a-outl{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:8px 10px 0}.a-outl span{border:1px solid #C9D2DC;border-radius:999px;text-align:center;padding:5px 0;font-size:.66rem;color:var(--navy);font-weight:600}
.a-tabs{display:flex;gap:12px;padding:10px 12px 0;font-size:.68rem;color:var(--mute);border-bottom:1px solid #E1E6EC}.a-tabs > *{padding-bottom:7px}.a-tabs b{color:var(--navy);border-bottom:2px solid var(--navy)}
.a-card{margin:10px 12px;background:#fff;border:1px solid #E1E6EC;border-radius:12px;padding:10px}.a-card small{display:block;color:var(--mute);font-size:.64rem}.a-card b{font-size:.76rem}
.a-scrim{position:absolute;inset:0;background:rgba(14,26,43,.45);z-index:3;opacity:0}.pane.on .a-scrim{animation:fadein .3s .5s forwards}
@keyframes fadein{to{opacity:1}}
.a-sheet{position:absolute;z-index:4;left:0;right:0;bottom:0;background:#fff;border-radius:22px 22px 0 0;padding:10px 16px 34px;display:flex;flex-direction:column;gap:8px;transform:translateY(100%)}
.pane.on .a-sheet{animation:sheet .5s .6s cubic-bezier(.2,.7,.2,1) forwards}@keyframes sheet{to{transform:none}}
.a-sheet .grab{width:36px;height:4px;border-radius:4px;background:#D3DAE3;align-self:center}.a-sheet .st{font-size:.95rem;margin:2px 0 2px}
.a-fld{border:1px solid #C9D2DC;border-radius:10px;padding:6px 10px}.a-fld small{display:block;color:var(--mute);font-size:.6rem}.a-fld b{font-size:.82rem}
.a-btn{background:var(--navy);color:#fff;text-align:center;border-radius:999px;padding:9px;font-weight:600;font-size:.76rem}
.a-slip{display:flex;justify-content:space-between;align-items:center;border:1.5px dashed #C9D2DC;border-radius:10px;padding:9px 10px;font-size:.72rem}.a-slip em{font-style:normal;color:var(--ok);font-weight:600;opacity:0}.pane.on .a-slip em{animation:fadein .3s 1.6s forwards}
.a-fine{color:var(--mute);font-size:.6rem}
.a-dlg{position:absolute;z-index:4;left:18px;right:18px;top:200px;background:#fff;border-radius:22px;padding:18px;box-shadow:0 20px 40px -10px rgba(0,0,0,.4);opacity:0;transform:scale(.92)}
.pane.on .a-dlg{animation:pop2 .35s .6s forwards}@keyframes pop2{to{opacity:1;transform:none}}
.a-dlg b{font-size:.95rem}.a-dlg p{color:var(--mute);font-size:.74rem;margin:6px 0 12px}.a-dlg span{color:var(--navy);font-weight:700;font-size:.76rem;float:right}
.a-push{position:absolute;z-index:5;left:10px;right:10px;top:34px;display:flex;gap:10px;align-items:center;background:rgba(255,255,255,.97);border-radius:18px;padding:10px 12px;box-shadow:0 14px 30px -10px rgba(0,0,0,.45);transform:translateY(-140%)}
.pane.on .a-push{animation:push .5s .8s cubic-bezier(.2,.7,.2,1) forwards}@keyframes push{to{transform:none}}
.a-push > i{width:28px;height:28px;border-radius:8px;background:var(--navy);color:var(--brass-2);font-style:normal;font-weight:700;font-size:.64rem;display:grid;place-items:center;flex:none}
.a-push b{display:block;font-size:.76rem}.a-push small{color:var(--mute);font-size:.64rem}.a-push em{margin-left:auto;font-style:normal;font-size:.6rem;color:var(--mute);align-self:flex-start}
.a-toast{position:absolute;z-index:5;left:16px;right:16px;bottom:36px;background:#1E2A3A;color:#fff;border-radius:12px;padding:10px 12px;font-size:.72rem;font-weight:600;opacity:0;transform:translateY(12px)}
.pane.on .a-toast{animation:toast .4s 2.4s forwards}.pane.on .a-toast.late{animation-delay:3.4s}@keyframes toast{to{opacity:1;transform:none}}
.a-toast.amber{background:#7A5418}.a-toast.info{background:var(--navy)}
.a-sign{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;background:linear-gradient(170deg,#1F3A5F,#14263F);color:#fff;text-align:center;padding:0 24px}
.a-sign .lg{width:54px;height:54px;border-radius:16px;background:#24395A;color:var(--brass-2);font-style:normal;font-weight:800;display:grid;place-items:center;margin-bottom:10px}
.a-sign small{color:#B9C6D6}.a-sign .code{margin:26px 0 18px;background:rgba(255,255,255,.08);border-radius:18px;padding:16px 26px}.a-sign .code b{display:block;font-size:2.4rem;letter-spacing:.14em}
.a-sign .spin{width:22px;height:22px;border-radius:50%;border:3px solid rgba(255,255,255,.2);border-top-color:var(--brass-2);animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
.a-call{position:absolute;z-index:4;left:10px;right:10px;top:250px;display:flex;gap:10px;align-items:center;background:#0F8A5F;color:#fff;border-radius:16px;padding:10px 12px;opacity:0;transform:translateY(10px)}
.pane.on .a-call{animation:pop2 .35s .7s forwards}.a-call > i{width:9px;height:9px;border-radius:50%;background:#FF6B6B;animation:blink 1.1s infinite}.a-call b{display:block;font-size:.78rem}.a-call small{font-size:.66rem;color:#D5F2E5}
.a-call .end{margin-left:auto;background:#D93636;border-radius:999px;padding:4px 10px;font-size:.66rem;font-weight:700}
.a-bar{background:var(--navy);color:#fff;padding:38px 14px 12px;font-weight:600}
.a-map{height:230px;position:relative;background:#E8ECF1;background-image:linear-gradient(90deg,rgba(255,255,255,.95) 3px,transparent 3px),linear-gradient(rgba(255,255,255,.95) 3px,transparent 3px);background-size:46px 46px}
.a-map svg{position:absolute;inset:0;width:100%;height:100%}.trail2{stroke-dasharray:300;stroke-dashoffset:300}.pane.on .trail2{animation:dash 2.2s .4s ease-out forwards}@keyframes dash{to{stroke-dashoffset:0}}
.a-map .me{position:absolute;left:24px;bottom:12px;width:14px;height:14px;border-radius:50%;background:#2563EB;border:3px solid #fff;box-shadow:0 0 0 8px rgba(37,99,235,.18)}
.a-map .pin{position:absolute;background:#fff;border-radius:999px;padding:2px 8px;font-size:.62rem;font-weight:600;box-shadow:0 4px 10px -4px rgba(0,0,0,.4)}.a-map .p1{right:16px;top:30px;background:var(--brass);color:var(--ink)}.a-map .p2{left:40px;top:48px}
.a-map .ring{position:absolute;right:22px;top:6px;width:44px;height:44px;border-radius:50%;border:2px solid var(--ok);background:rgba(30,142,90,.12)}
.a-near{padding:10px 12px;display:flex;flex-direction:column;gap:8px}
.a-pay{margin:10px 12px;background:#fff;border:1px solid #E1E6EC;border-radius:12px;padding:10px}.a-pay > small{color:var(--mute);font-size:.64rem}
.a-prow{display:flex;flex-direction:column;gap:6px;margin-top:6px}.a-prow b{font-size:.8rem;display:block}.a-prow small{font-size:.6rem;color:var(--mute)}
.a-prow em{font-style:normal;font-size:.64rem;font-weight:700;border-radius:999px;padding:3px 9px;align-self:flex-start}.st1{background:#FBF1E0;color:#94600E}.st2{background:#E7F4EC;color:#1E7A4D;opacity:0}.stx{position:relative;height:20px;display:block}.stx em{position:absolute;left:0;top:0}
.pane.on .st1{animation:fadeout .3s 2.2s forwards}.pane.on .st2{animation:fadein .3s 2.2s forwards}@keyframes fadeout{to{opacity:0}}
.a-loc{margin:10px 12px 0;background:#E7F4EC;color:#14532D;border-radius:10px;padding:8px 10px;font-size:.66rem;font-weight:600;order:-1}
.typed{overflow:hidden}

/* feature blocks with real console screens */
.feat{padding:112px 0}.feat-head{margin-bottom:24px}.feat-head h2{margin-bottom:6px}
.blk{padding:56px 0;border-top:1px solid var(--line)}.blk:first-of-type{border-top:0}
.blk.flip .copy{order:2}
.blk h3{font-size:clamp(1.5rem,2.4vw,2rem);letter-spacing:-.02em;margin-bottom:10px}
.ticks{list-style:none;margin:18px 0 0;padding:0}.ticks li{padding:8px 0 8px 28px;position:relative;border-top:1px solid var(--line);font-size:.97rem}
.ticks li:before{content:"";position:absolute;left:4px;top:1.05em;width:11px;height:5px;border-left:2px solid var(--ok);border-bottom:2px solid var(--ok);transform:rotate(-45deg)}
.scr{margin:0;position:relative;background:#fff;border-radius:14px;overflow:visible;box-shadow:0 40px 80px -40px rgba(14,26,43,.55),0 0 0 1px var(--line)}
.scr-bar{display:flex;gap:6px;align-items:center;background:#E9EDF2;padding:9px 12px;border-radius:14px 14px 0 0}.scr-bar i{width:10px;height:10px;border-radius:50%;background:#C9D1DB}
.scr-bar span{margin-left:10px;font-size:.72rem;color:var(--mute);background:#fff;border-radius:6px;padding:2px 10px}
.scr-img{overflow:hidden;border-radius:0 0 14px 14px;aspect-ratio:16/10}.scr-img img{width:100%;height:100%;object-fit:cover;object-position:top left;display:block;animation:pan 16s ease-in-out infinite alternate}
@keyframes pan{from{transform:scale(1)}to{transform:scale(1.07)}}
.scr-chip{position:absolute;left:-18px;bottom:28px;display:flex;align-items:center;gap:8px;background:#fff;border-radius:12px;padding:9px 13px;font-size:.84rem;font-weight:600;box-shadow:0 18px 40px -16px rgba(14,26,43,.5);animation:bob 5s ease-in-out infinite}
.scr-chip:before{content:"";width:8px;height:8px;border-radius:50%;background:var(--ok);animation:blink 1.4s infinite}
.blk.flip .scr-chip{left:auto;right:-18px}
@keyframes bob{50%{transform:translateY(-8px)}}
@media (max-width:960px){.blk.flip .copy{order:0}.scr-chip{left:12px!important;right:auto!important}}
.alsos{margin-top:40px;color:var(--mute);font-size:.95rem;max-width:none}.alsos b{color:var(--ink);font-weight:600}


/* console showcase: tabs + text block + annotated screen */
@keyframes vin{from{opacity:0;transform:translateY(8px)}}@keyframes blink{50%{opacity:.25}}@keyframes ring2{from{transform:scale(.6);opacity:1}to{transform:scale(1.5);opacity:0}}
.showcase{background:#fff}
.sc-tabs{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--line);margin-bottom:44px}
.sc-tabs button{all:unset;cursor:pointer;position:relative;padding:12px 16px 14px;font-weight:600;font-size:.95rem;color:var(--mute);transition:color .2s}
.sc-tabs button:hover{color:var(--ink)}.sc-tabs button.on{color:var(--ink)}.sc-tabs button:focus-visible{outline:3px solid var(--brass);border-radius:6px}
.sc-tabs i{position:absolute;left:0;right:0;bottom:-1px;height:3px;background:var(--line);transform-origin:left;transform:scaleX(0)}
.sc-tabs button.on i{background:var(--ink);transform:scaleX(1)}.showcase.run .sc-tabs button.on i{background:linear-gradient(var(--brass),var(--brass)) left/0 100% no-repeat var(--ink);animation:tabfill var(--scdw,8s) linear forwards}
@keyframes tabfill{to{background-size:100% 100%}}
@media (max-width:760px){.sc-tabs{flex-wrap:nowrap;overflow-x:auto}.sc-tabs button{white-space:nowrap}}
.sc-stage{position:relative}
.sc-pane{display:none}.sc-pane.on{display:grid;animation:vin .5s ease-out}
.sc-pane{grid-template-columns:minmax(0,.75fr) minmax(0,1.25fr);gap:56px}
@media (max-width:960px){.sc-pane{grid-template-columns:1fr;gap:28px}}
.sc-pane h3{font-size:clamp(1.5rem,2.4vw,2rem);letter-spacing:-.02em;margin-bottom:10px}
.sc-points{list-style:none;margin:22px 0 0;padding:0;counter-reset:none}
.sc-points li{display:flex;gap:12px;align-items:center;padding:11px 0;border-top:1px solid var(--line);font-size:.97rem}
.sc-points span{flex:none;width:26px;height:26px;border-radius:50%;background:var(--brass);color:var(--ink);font-weight:700;font-size:.8rem;display:grid;place-items:center}
.sc-where{margin-top:18px;font-size:.88rem;color:var(--mute)}.sc-where b{color:var(--ink);font-weight:600}
.sc-frame{margin:0;background:#fff;border-radius:14px;box-shadow:0 50px 90px -45px rgba(14,26,43,.55),0 0 0 1px var(--line)}
.sc-img{position:relative;border-radius:0 0 14px 14px;overflow:hidden;aspect-ratio:16/10}.sc-img img{width:100%;height:100%;object-fit:cover;object-position:top left;display:block}
.spot{position:absolute;transform:translate(-50%,-50%);opacity:0}.sc-pane.on .spot{animation:spotin .45s var(--dl) cubic-bezier(.2,.7,.2,1) forwards}
@keyframes spotin{from{opacity:0;transform:translate(-50%,-50%) scale(.5)}to{opacity:1;transform:translate(-50%,-50%)}}
.spot b{position:relative;display:grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--brass);color:var(--ink);font-size:.8rem;font-weight:800;box-shadow:0 0 0 4px rgba(184,137,59,.3),0 6px 14px -4px rgba(0,0,0,.4)}
.spot b:after{content:"";position:absolute;inset:-4px;border-radius:50%;border:2px solid var(--brass);animation:ring2 2.2s infinite}
.spot em{position:absolute;left:50%;top:calc(100% + 8px);transform:translateX(-50%);white-space:nowrap;background:var(--ink);color:#fff;font-style:normal;font-size:.72rem;font-weight:600;padding:5px 9px;border-radius:7px;opacity:0;pointer-events:none;transition:opacity .2s}
.spot:hover em,.spot.show em{opacity:1}.spot.r em{left:auto;right:-6px;transform:none}.spot.l em{left:-6px;transform:none}
@media (max-width:640px){.spot em{display:none}.spot b{width:22px;height:22px;font-size:.7rem}}
/* security */
.trust{display:grid;grid-template-columns:1fr 1fr;gap:28px 40px}@media (max-width:560px){.trust{grid-template-columns:1fr}}
.trust h3{font-size:1.02rem;margin-bottom:4px}.trust p{margin:0;font-size:.93rem;color:#A9B7C8}

/* pricing */
/* pricing */
.pricing{background:linear-gradient(#fff,var(--paper))}
.p-head{text-align:center;max-width:640px;margin:0 auto 34px}.p-head h2{margin:0 auto 12px}.p-head p{margin:0 auto}
.finder{max-width:680px;margin:0 auto 46px;background:#fff;border:1px solid var(--line);border-radius:18px;padding:18px 24px;box-shadow:0 10px 30px -18px rgba(14,26,43,.25)}
.finder label{display:flex;justify-content:space-between;align-items:baseline;margin:0 0 6px;font-weight:600}.finder label b{font-size:1.5rem;color:var(--navy);font-variant-numeric:tabular-nums}
.finder input[type=range]{width:100%;margin:6px 0 10px;accent-color:var(--brass);padding:0;border:0;background:none;box-shadow:none}
.finder output{display:block;color:var(--mute);font-size:.92rem}.finder output b{color:var(--ink)}
.plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:22px;align-items:stretch}
.plan{padding:34px 30px 30px;display:flex;flex-direction:column;position:relative;background:#fff;border:1px solid var(--line);border-radius:22px;transition:transform .3s,box-shadow .3s}
.plan:hover{transform:translateY(-4px);box-shadow:0 26px 50px -30px rgba(14,26,43,.35)}
.plan.pick{background:radial-gradient(420px 260px at 80% 0,rgba(184,137,59,.28),transparent 70%),linear-gradient(160deg,var(--navy),var(--ink));color:#fff;border:1px solid rgba(212,174,106,.55);box-shadow:0 34px 70px -30px rgba(14,26,43,.7)}
@media (min-width:900px){.plan.pick{transform:translateY(-14px)}.plan.pick:hover{transform:translateY(-18px)}}
.plan.fit{outline:3px solid var(--brass);outline-offset:4px}
.plan .badge{position:absolute;top:-13px;left:30px;font-size:.74rem;font-weight:700;letter-spacing:.04em;color:var(--ink);background:linear-gradient(90deg,var(--brass-2),var(--brass));padding:5px 12px;border-radius:99px}
.plan h3{font-size:1.3rem;margin:0}.plan .tag{color:var(--mute);font-size:.92rem;min-height:2.9em}.plan.pick .tag,.plan.pick .per{color:#B8C6D8}
.plan .price{font-size:2.9rem;font-weight:760;letter-spacing:-.035em;margin:18px 0 2px;font-variant-numeric:tabular-nums;line-height:1.1}
.plan .price sup{font-size:.5em;vertical-align:.7em;margin-right:2px;font-weight:600}.plan .price span{font-size:.95rem;font-weight:500;letter-spacing:0;color:var(--mute);margin-left:6px}.plan.pick .price span{color:#B8C6D8}
.plan .price.talk,.plan .price .talk{font-size:2.3rem;color:inherit;margin:0;letter-spacing:-.03em}
.seg-dep{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}.seg-dep label{border:1.5px solid var(--line);border-radius:99px;padding:8px 16px;cursor:pointer;font-weight:600;font-size:.92rem;background:#fff}
.seg-dep input{width:auto;margin:0 6px 0 0}.seg-dep label:has(input:checked){border-color:var(--navy);background:var(--navy);color:#fff}
.plan .per{color:var(--mute);font-size:.88rem;margin-bottom:22px}.plan .per b{color:inherit}.plan.pick .per b{color:#fff}
.plan .btn{justify-content:center}.plan.pick .btn{background:var(--brass);color:var(--ink)}.plan.pick .btn:hover{background:var(--brass-2)}
.plan .inc{margin:24px 0 4px;padding-top:20px;border-top:1px solid var(--line);font-size:.78rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--mute)}.plan.pick .inc{border-color:rgba(255,255,255,.14);color:var(--brass-2)}
.plan ul{list-style:none;padding:0;margin:0}.plan li{padding:6px 0 6px 26px;position:relative;font-size:.93rem}
.plan li:before{content:"";position:absolute;left:0;top:.62em;width:16px;height:16px;border-radius:50%;background:rgba(30,142,90,.12)}
.plan li:after{content:"";position:absolute;left:5px;top:.98em;width:6px;height:3px;border-left:1.6px solid var(--ok);border-bottom:1.6px solid var(--ok);transform:rotate(-45deg)}
.plan.pick li:before{background:rgba(212,174,106,.18)}.plan.pick li:after{border-color:var(--brass-2)}
.assure{list-style:none;padding:0;margin:44px 0 0;display:grid;grid-template-columns:repeat(4,1fr);gap:18px}
.assure li{font-size:.86rem;color:var(--mute);padding-left:16px;border-left:2px solid var(--brass)}.assure b{display:block;color:var(--ink);font-size:.95rem}
@media (max-width:820px){.assure{grid-template-columns:1fr 1fr}}
/* free trial: one split card */
.deal{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);border-radius:28px;overflow:hidden;background:#fff;border:1px solid var(--line);box-shadow:0 50px 100px -50px rgba(14,26,43,.45)}
@media (max-width:940px){.deal{grid-template-columns:1fr}}
.deal-l{position:relative;overflow:hidden;padding:48px 44px;background:radial-gradient(500px 360px at 100% 100%,rgba(184,137,59,.3),transparent 65%),linear-gradient(160deg,var(--navy),var(--ink));color:#B9C6D6}
.deal-l > *{position:relative}.deal-l h2{color:#fff;font-size:clamp(1.9rem,3.2vw,2.6rem);max-width:12ch;margin:0 0 12px}
.deal-l .rings{position:absolute;right:-160px;bottom:-160px;width:420px;aspect-ratio:1}.deal-l .rings i{position:absolute;inset:0;border-radius:50%;border:1px solid rgba(212,174,106,.2)}.deal-l .rings i:nth-child(2){inset:15%}.deal-l .rings i:nth-child(3){inset:30%;border:0;background:radial-gradient(circle,rgba(212,174,106,.35),transparent 70%)}
.steps{list-style:none;counter-reset:s;padding:0;margin:28px 0}
.steps li{counter-increment:s;position:relative;padding:0 0 22px 46px}.steps li:last-child{padding-bottom:0}
.steps li:before{content:counter(s);position:absolute;left:0;top:0;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;border:1.5px solid var(--brass);color:var(--brass-2);font-weight:700;font-size:.85rem}
.steps li:not(:last-child):after{content:"";position:absolute;left:15px;top:34px;bottom:4px;width:1.5px;background:rgba(212,174,106,.3)}
.steps b{display:block;color:#fff}.steps span{font-size:.9rem}
.reach{font-size:.9rem;margin:0}.reach a{color:var(--brass-2)}
.deal-r{padding:40px 44px}@media (max-width:640px){.deal-l,.deal-r{padding:32px 22px}}
.seg{display:flex;background:var(--mist);border-radius:12px;padding:4px;margin-bottom:24px}
.seg button{all:unset;flex:1;text-align:center;cursor:pointer;padding:10px;border-radius:9px;font-weight:600;font-size:.94rem;color:var(--mute);transition:all .2s}
.seg button.on{background:#fff;color:var(--ink);box-shadow:0 2px 8px rgba(14,26,43,.12)}.seg button:focus-visible{outline:2px solid var(--brass)}
.deal-r form + form{margin-top:36px;padding-top:30px;border-top:1px solid var(--line)}.deal-r.js form + form{margin:0;padding:0;border:0}
.deal-r .lead{color:var(--mute);font-size:.95rem}.btn.wide{width:100%;justify-content:center;margin-top:6px}
.deal-r form{animation:vin .35s}
/* faq */
.faq{background:var(--paper)}
.faq .wrap{display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr);gap:64px;align-items:start}@media (max-width:900px){.faq .wrap{grid-template-columns:1fr;gap:30px}}
@media (min-width:901px){.faq-l{position:sticky;top:110px}}
.ask{margin-top:30px;background:linear-gradient(160deg,var(--navy),var(--ink));color:#B9C6D6;border-radius:20px;padding:24px 24px 22px;position:relative;overflow:hidden}
.ask:after{content:"";position:absolute;right:-50px;top:-50px;width:150px;height:150px;border-radius:50%;border:1px solid rgba(212,174,106,.3);box-shadow:0 0 0 22px rgba(212,174,106,.06)}
.ask b{color:#fff;font-size:1.1rem}.ask p{font-size:.92rem;margin:4px 0 16px}.ask div{display:flex;gap:10px;flex-wrap:wrap}
.ask .btn.ghost{color:#fff;border-color:rgba(255,255,255,.3);background:transparent}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.chips button{all:unset;cursor:pointer;padding:7px 15px;border-radius:99px;border:1px solid var(--line);background:#fff;font-size:.88rem;font-weight:600;color:var(--mute);transition:all .2s}
.chips button.on{background:var(--ink);border-color:var(--ink);color:#fff}.chips button:focus-visible{outline:2px solid var(--brass)}
.faq details{background:#fff;border:1px solid var(--line);border-radius:16px;padding:0;margin-bottom:10px;transition:border-color .25s,box-shadow .25s}
.faq details[open]{border-color:rgba(184,137,59,.55);box-shadow:0 18px 40px -26px rgba(14,26,43,.35)}
.faq summary{font-weight:600;font-size:1.02rem;cursor:pointer;list-style:none;display:flex;align-items:center;gap:16px;padding:18px 22px}.faq summary::-webkit-details-marker{display:none}
.faq summary .q{flex:none;font-size:.78rem;font-weight:700;color:var(--brass);font-variant-numeric:tabular-nums}
.faq summary:after{content:"+";margin-left:auto;flex:none;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--mist);font-weight:500;color:var(--ink);font-size:1.1rem;transition:transform .25s,background .25s}
.faq details[open] summary:after{transform:rotate(45deg);background:var(--brass);color:#fff}
.faq details p{margin:0;padding:0 22px 20px 56px;color:var(--mute)}
.faq details[open] p{animation:vin .35s}
.closing{background:var(--paper);padding:20px 0 110px}@media (max-width:760px){.closing{padding:10px 0 70px}}
.close-card{position:relative;overflow:hidden;border-radius:28px;padding:clamp(36px,5vw,64px);display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,.75fr);gap:48px;align-items:center;color:#B9C6D6;
  background:radial-gradient(620px 420px at 88% 110%,rgba(184,137,59,.38),transparent 65%),radial-gradient(500px 360px at 0 0,rgba(42,76,120,.6),transparent 70%),linear-gradient(160deg,var(--navy),var(--ink));box-shadow:0 50px 100px -50px rgba(14,26,43,.7)}
@media (max-width:900px){.close-card{grid-template-columns:1fr;gap:34px}}
.close-card > *:not(.rings){position:relative}
.close-card .rings{position:absolute;right:-190px;bottom:-230px;width:560px;aspect-ratio:1;pointer-events:none}
.close-card .rings i{position:absolute;inset:0;border-radius:50%;border:1px solid rgba(212,174,106,.2)}.close-card .rings i:nth-child(2){inset:15%;border-color:rgba(212,174,106,.28)}.close-card .rings i:nth-child(3){inset:31%;border:0;background:radial-gradient(circle,rgba(212,174,106,.4),transparent 70%)}
.close-card .rings:after{content:"";position:absolute;inset:0;border-radius:50%;background:conic-gradient(transparent 0 80%,rgba(212,174,106,.5) 92%,transparent);-webkit-mask:radial-gradient(circle,transparent 69.6%,#000 70%,#000 71%,transparent 71.4%);mask:radial-gradient(circle,transparent 69.6%,#000 70%,#000 71%,transparent 71.4%);animation:sweep 9s linear infinite}
.close-card h2{color:#fff;font-size:clamp(2.2rem,4.2vw,3.5rem);line-height:1.04;letter-spacing:-.03em;max-width:14ch;margin:0 0 16px}.close-card h2 em{font-style:normal;color:var(--brass-2)}
.close-copy > p:not(.kicker){color:#B9C6D6;max-width:44ch;margin:0 0 28px}.close-copy .kicker{margin-bottom:12px}
.close-card .btn.ghost{color:#fff;border-color:rgba(255,255,255,.3);background:transparent}.close-card .btn.ghost:hover{background:rgba(255,255,255,.08)}
.close-stats{list-style:none;margin:0;padding:0;display:grid;gap:14px}
.close-stats li{display:flex;align-items:center;gap:16px;padding:16px 20px;border-radius:16px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);backdrop-filter:blur(8px)}
.close-stats b{flex:none;width:112px;color:#fff;font-size:1.6rem;font-weight:760;letter-spacing:-.02em;font-variant-numeric:tabular-nums}.close-stats span{font-size:.9rem;line-height:1.4}
@media (max-width:520px){.close-stats li{flex-direction:column;align-items:flex-start;gap:2px}.close-stats b{width:auto}}
@media (prefers-reduced-motion:reduce){.scr-img img,.scr-chip{animation:none}}
@endsection

@section('head')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@graph' => [
  ['@type' => 'SoftwareApplication', 'name' => 'ROVEXY', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Android, Web',
   'description' => 'Recorded calls, verified field visits and payment slips for collection teams.', 'publisher' => ['@type' => 'Organization', 'name' => 'Ametecs India Private Limited'],
   'offers' => $plans->filter(fn ($p) => $p->from_rate)->map(fn ($p) => ['@type' => 'Offer', 'name' => $p->name . ' (per user per month, annual)', 'price' => (string) $p->from_rate, 'priceCurrency' => 'INR'])->values()],
]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endsection

@section('main')
@php
$faqs = [
  ['Do we have to root the phones or buy special phones?', 'No. ROVEXY is an Android app on ordinary company phones of mixed brands (Xiaomi, Samsung, OPPO, Realme, Vivo, OnePlus, Pixel). You approve each phone from the console before it can be used for work.', 'setup'],
  ['Do calls go out on our own SIM numbers?', 'Yes. Calls are placed through the phone\'s SIM and its own Phone app, so customers see your company number. Calls can also go through Exotel cloud telephony if you use it.', 'setup'],
  ['Are both sides of every call recorded?', 'Yes. Calls are recorded from ringing to hang-up. The app won\'t start work until recording is on, and recordings upload to your console automatically.', 'setup'],
  ['Is employee location tracked all day?', 'Only during a work session, between signing in and signing out on the app. Employees see a notice while it is on.', 'privacy'],
  ['Can a manager see other teams\' customers or calls?', 'No. Managers see only the people and cases of their own group. Only the company admin sees everything, and every sensitive action is written to the audit log.', 'privacy'],
  ['Where is our data kept and for how long?', 'On ROVEXY\'s cloud servers run by Ametecs India, backed up daily. Each company\'s data is kept separate, and you decide how long recordings are kept from Storage & retention.', 'privacy'],
  ['How do we pay, and do we get a GST invoice?', 'Pay online by UPI, card or net banking through Razorpay, or ask us for a bank-transfer invoice. A GST tax invoice is issued for every payment and stays downloadable from your console.', 'billing'],
  ['What happens when our package ends?', 'You get a few days\' grace to renew from the console. After that, phones can\'t start work until you renew. Your data stays exactly as it was.', 'billing'],
  ['Can we add users during the year?', 'Yes. Move to a bigger package from Administration → Plan & licence; the new user limit applies as soon as it\'s paid. Renewing early never loses days.', 'billing'],
  ['Does it connect to our HR and payroll?', 'Yes, with SmartPRS (attendance and payroll) and SmartEPT (workplace compliance) from Ametecs. Field check-ins and work sessions flow across by employee code.', 'setup'],
];
@endphp

    @php
      // Realistic Android frame + the app's own screens (navy hero header, white cards, same labels as the Kotlin app).
      $droid = fn (string $screen, string $over = '') => '<div class="droid"><div class="d-screen"><div class="d-status"><span>9:41</span><i class="cam"></i><span class="d-ic"><svg viewBox="0 0 24 12"><path d="M1 11h3V8H1zm5 0h3V5H6zm5 0h3V2h-3zm5 0h3V0h-3z" fill="currentColor"/></svg><svg viewBox="0 0 26 12"><rect x=".5" y=".5" width="22" height="11" rx="3" fill="none" stroke="currentColor"/><rect x="2" y="2" width="15" height="8" rx="1.5" fill="currentColor"/><rect x="23.5" y="4" width="2" height="4" rx="1" fill="currentColor"/></svg></span></div>' . $screen . $over . '<div class="d-nav"><i></i></div></div></div>';
      $home = fn (string $tag = '') => '<div class="a-hero"><div class="a-row"><b>Good morning, Priya</b><span class="a-pill">' . ($tag ?: 'Work session active') . '</span></div>'
          . '<div class="a-today"><div><b>18</b><small>Calls</small></div><div><b>11</b><small>Connected</small></div><div><b>42:10</b><small>Talk time</small></div><div><b>6</b><small>Follow-ups</small></div></div></div>'
          . '<div class="a-tiles"><span>Overdue <b>212</b></span><span>Due today <b>9</b></span><span class="w">Promises today <b>4</b></span><span>Callbacks due <b>3</b></span></div>'
          . '<div class="a-search">Search name, case ID or last 4+ digits</div>'
          . '<div class="a-list">' . collect([['Ravi Kumar', 'Axis Q3 Oct', '₹12,400', '34 DPD', 'r'], ['Meena Reddy', 'Axis Q3 Oct', '₹7,850', 'Callback 4:30 pm', 'a'], ['Suresh Babu', 'HDFC Cards Sep', '₹8,000', 'Promise today', 'g'], ['Lakshmi K.', 'Axis Q3 Oct', '₹21,300', '61 DPD', 'r']])
              ->map(fn ($c) => '<div class="a-case"><i>' . e(mb_substr($c[0], 0, 1)) . '</i><div><b>' . e($c[0]) . '</b><small>' . e($c[1]) . '</small></div><div class="r"><b>' . e($c[2]) . '</b><em class="' . $c[4] . '">' . e($c[3]) . '</em></div></div>')->implode('') . '</div>';
      $case = fn () => '<div class="a-hero case"><div class="a-row"><span>‹ Back</span><span class="gs">Refresh</span></div><div class="a-who"><i>R</i><div><b>Ravi Kumar</b><small>AX-220781 · Axis Q3 Oct</small><small>Assigned to Priya S. · Hyderabad callers</small></div><span class="a-st">Follow up</span></div>'
          . '<div class="a-met"><div><b class="al">₹12,400</b><small>Overdue now</small></div><div><b class="al">34</b><small>Days overdue</small></div><div><b>₹48,900</b><small>Outstanding</small></div></div></div>'
          . '<div class="a-acts"><span class="g"><svg viewBox="0 0 24 24"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg>Call</span><span class="a"><svg viewBox="0 0 24 24"><path d="M7 2v3M17 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z" fill="none" stroke="currentColor" stroke-width="2"/></svg>Callback</span><span class="t"><svg viewBox="0 0 24 24"><path d="M5 12l5 5L20 7" fill="none" stroke="currentColor" stroke-width="2.4"/></svg>Payment</span><span class="b"><svg viewBox="0 0 24 24"><path d="M12 22s7-7.4 7-13a7 7 0 0 0-14 0c0 5.6 7 13 7 13z"/></svg>Directions</span></div>'
          . '<div class="a-outl"><span>Promise</span><span>Note</span><span>Status</span></div><div class="a-tabs"><b>Overview</b><span>Details</span><span>Dues</span><span>Payments</span><span>Calls</span></div>'
          . '<div class="a-card"><small>Latest promise</small><b>No promises recorded.</b></div>';
      $sheet = fn (string $title, string $body) => '<div class="a-scrim"></div><div class="a-sheet"><i class="grab"></i><b class="st">' . $title . '</b>' . $body . '</div>';
      $field = fn (string $l, string $v, string $cls = '') => '<div class="a-fld ' . $cls . '"><small>' . $l . '</small><b>' . $v . '</b></div>';
      $note = fn (string $t, string $s, string $cls = '') => '<div class="a-push ' . $cls . '"><i>SC</i><div><b>' . $t . '</b><small>' . $s . '</small></div><em>now</em></div>';
      $toast = fn (string $t, string $cls = '') => '<div class="a-toast ' . $cls . '">' . $t . '</div>';

      $tour = [
        ['Office', 'Upload the portfolio', 'The office drops in the bank\'s Excel or CSV and assigns it to a group. Each phone gets its cases straight away, with a push alert.',
          $droid($home(), $note('24 new cases assigned', 'Axis Q3 Oct · sorted by days past due'))],
        ['Office + phone', 'Approve the company phone', 'A new phone shows a code. The admin matches it in Devices & approvals and approves it. Unapproved phones cannot sign in.',
          $droid('<div class="a-sign"><i class="lg">SC</i><b>ROVEXY</b><small>This phone is waiting for your admin</small><div class="code"><small>Code on phone</small><b>4821</b></div><span class="spin"></span></div>', $toast('✓ Phone approved — you can sign in'))],
        ['Phone', 'Recording must be on', 'Work cannot start until a test call proves auto call recording is on. An answered call without a recording blocks calling again.',
          $droid($home('Calls blocked'), '<div class="a-scrim"></div><div class="a-dlg"><b>Recording not saved</b><p>Auto call recording is OFF. Calls are blocked until it is switched on.</p><span>Open call settings</span></div>' . $toast('✓ Recording found — calls unlocked', 'late'))],
        ['Phone', 'Call the customer', 'Cases are sorted by days past due and amount. The call goes out on the company SIM and the recording uploads by itself.',
          $droid($case(), '<div class="a-call"><i></i><div><b>On call · Ravi Kumar</b><small>Recording · <span data-timer="0">00:00</span></small></div><span class="end">End</span></div>' . $toast('Uploading the call recording…', 'late info'))],
        ['Phone', 'Record the outcome', 'After every call: a promise with amount and date, a payment, or a callback. Promises and callbacks land on the follow-up list.',
          $droid($case(), $sheet('Record commitment', $field('Promised amount (₹)', '12,400', 'typed') . $field('Promised payment date (required)', '15 Oct 2026') . $field('Notes (optional)', 'Salary on 14th') . '<span class="a-btn">Save</span>') . $toast('✓ Promise saved', 'late'))],
        ['Phone', 'Visit and check in', 'Field agents see customers near them with road distance. "I\'ve reached" only counts within 100 m of the customer\'s pin.',
          $droid('<div class="a-bar">‹ &nbsp;Customers near me</div><div class="a-map"><svg viewBox="0 0 260 190" preserveAspectRatio="none"><path class="trail2" d="M30 170 C 80 150, 100 90, 150 80 S 220 40, 226 28" stroke="#1F3A5F" stroke-width="5" fill="none" stroke-linecap="round"/></svg><span class="me"></span><span class="pin p1">Suresh B.</span><span class="pin p2">Lakshmi K.</span><span class="ring"></span></div>'
            . '<div class="a-near"><div class="a-case"><i>S</i><div><b>Suresh Babu</b><small>Distance &amp; route · 2.4 km · 9 min</small></div></div><span class="a-btn">I\'ve reached · Check in</span></div>', $toast('✓ Field visit · reached the customer (38 m)'))],
        ['Phone', 'Collect the payment', 'Mode, amount and a photo of the slip, on the spot. The office checks the payment before it reduces the balance.',
          $droid($case(), $sheet('Record payment', $field('Payment mode', 'UPI') . $field('Amount (₹)', '8,000') . '<div class="a-slip"><span>Take photo</span><em>Slip attached</em></div><small class="a-fine">The office checks the payment before it reduces the balance.</small><span class="a-btn">Save</span>') . $toast('Waiting for office check', 'late amber'))],
        ['Office', 'Check and confirm', 'Managers see each slip next to the payment and confirm it or send it back with a note. The agent sees the result on the case.',
          $droid($case(), '<div class="a-pay"><small>Payments</small><div class="a-prow"><div><b>₹8,000 · UPI</b><small>Paid 12 Oct · Ref 428207645190 · slip attached</small></div><span class="stx"><em class="st1">Waiting for office check</em><em class="st2">Confirmed</em></span></div></div>' . $note('Payment confirmed', 'Suresh Babu · ₹8,000 checked by the office', 'late'))],
        ['Office', 'See the team live', 'The office sees who is on a call, who is working and who has gone quiet. Location is shared only during the work session.',
          $droid($home(), '<div class="a-loc">Location is shared with your company while you are signed in.</div>' . $toast('Work session active · 6 h 12 m'))],
      ];
    @endphp
<section class="hero"><div class="wrap hero-grid">
  <div class="hero-copy">
    <p class="kicker">Calling, field visits and payments for collection teams</p>
    <h1>{{ $site['hero_title'] ?? 'Every call, visit and rupee your team collects — on record.' }}</h1>
    <p class="lead">{{ $site['hero_text'] ?? 'ROVEXY turns company Android phones into managed work phones: recorded calls, verified field visits and payment slips, with your office watching it live from one console.' }}</p>
    <div class="cta"><a class="btn gold" href="#trial">Start the {{ $trialDays }}-day free trial</a><a class="btn ghost" href="#contact">Book a live demo</a></div>
    <div class="links"><a href="#pricing">View plans</a><a href="#how">See how it works</a></div>
    <ul class="proof"><li>Recorded from ring to hang-up</li><li>Check-in within 100 m of the customer</li><li>Approved company phones only</li></ul>
  </div>
  @include('site.console')
</div></section>

<section id="why" class="band"><div class="wrap two">
  <div class="copy">
    <h2>Calls get made. Proof usually doesn't.</h2>
    <p>When a bank asks what was said to a customer, who visited, or where a payment went, the answer has to be on record — not in an agent's personal phone or a WhatsApp group.</p>
    <p>ROVEXY keeps calls, visits, promises and payments on one record that stays with the company.</p>
  </div>
  <div class="ba">
    <div class="old"><h3>What most floors have today</h3><ul><li>Calls from personal phones; no recording</li><li>Visit photos on WhatsApp, no proof of presence</li><li>Allocation in Excel, out of date by noon</li><li>Payments reported by phone; slips go missing</li></ul></div>
    <div class="new"><h3>With ROVEXY</h3><ul><li>Approved company phones, every call recorded</li><li>Check-in only within 100 m of the customer</li><li>Portfolio assigned to phones in a minute</li><li>Slip photo on the case, checked by the office</li></ul></div>
  </div>
</div></section>

<section id="how" class="band how"><div class="wrap">
  <div class="tour" id="tour" style="--n:{{ count($tour) }}">
    <div class="tour-left">
      <p class="kicker">How it works</p>
      <h2>One portfolio, start to finish</h2>
      <p class="sub">Follow one real day in the ROVEXY app, from the bank's file to the money in. Pick a step to see the phone.</p>
      <p class="lanes"><span class="o">Office</span><span class="f">Phone</span></p>
      <div class="tabs" role="tablist" aria-label="ROVEXY workflow">
        <i class="rail" aria-hidden="true"><i></i></i>
        @foreach($tour as $i => [$who, $t])
          <button type="button" role="tab" id="tt{{ $i }}" aria-controls="tp{{ $i }}" aria-selected="{{ $i ? 'false' : 'true' }}" class="{{ $i ? '' : 'on' }} {{ $who === 'Phone' ? 'f' : 'o' }}"><span class="n">{{ $i + 1 }}</span><span><b>{{ $t }}</b><small>{{ $who }}</small></span><i class="bar"></i></button>
        @endforeach
      </div>
    </div>
    <div class="stage">
      <i class="rings" aria-hidden="true"><i></i><i></i><i></i></i>
      <div class="panes">
        @foreach($tour as $i => [$who, $t, $text, $mock])
          <div class="pane {{ $i ? '' : 'on' }}" role="tabpanel" id="tp{{ $i }}" aria-labelledby="tt{{ $i }}">
            <div class="pane-mock" aria-hidden="true">{!! $mock !!}</div>
            <div class="cap"><small>Step {{ sprintf('%02d', $i + 1) }} / {{ sprintf('%02d', count($tour)) }} · {{ $who }}</small><p><b>{{ $t }}.</b> {{ $text }}</p></div>
          </div>
        @endforeach
      </div>
      <div class="tour-nav"><button type="button" data-step="-1" aria-label="Previous step">‹</button><button type="button" data-step="1" aria-label="Next step">›</button></div>
    </div>
  </div>
</div></section>

@php
  $shot = function (string $name) use ($shots) { foreach ($shots['web'] ?? [] as $s) { if (stripos($s['src'], $name) !== false) return $s; } return null; };
  // [file, tab label, heading, text, where in the console, [[x%, y%, callout], ...]]
  $views = [
    ['Dashboard', 'Dashboard', 'The whole floor on one screen', 'Who is online, who is on a call, how many calls connected and what is due today — compared with last week.', 'Dashboard',
      [[30, 30, 'Online, on a call, idle and offline'], [46, 53, 'Calls, connects and talk time vs last week'], [36, 86, 'Calls per day, connected and not']]],
    ['Live-status', 'Live status', 'Know where the team is, right now', 'A live map of the field team and a status for every agent, during work sessions only.', 'Supervision → Status & location',
      [[45, 56, 'Live pin for every agent'], [38, 28, 'Live, seen earlier, on a call'], [86, 46, 'Calls, visits and battery per agent']]],
    ['Calls-and-recordings', 'Calls & recordings', 'Every call, with the recording', 'Calls from the app are recorded from ring to hang-up and uploaded on their own. Play any call next to its outcome.', 'Calling → Calls & recordings',
      [[40, 36, 'Calls, connects and talk time'], [70, 52, 'Play the recording in the row'], [90, 52, 'Outcome: promise, paid, callback']]],
    ['Payments-and-slips', 'Payments & slips', 'Payments you can check, not just trust', 'Agents record the mode, amount and slip photo on the spot. The office confirms each one or sends it back with a note.', 'Calling → Payments & slips',
      [[33, 25, 'To check, verified, rejected'], [81, 62, 'Slip photo on every payment'], [93, 80, 'Confirm or reject with a note']]],
    ['Customers-and-assignment', 'Customers', 'Assign work in a minute', 'Search every customer across portfolios, select and assign them to a caller, or withdraw them when the bank recalls the file.', 'Customers',
      [[72, 34, 'Assign or withdraw in one step'], [55, 26, 'Filter by status, group, caller, portfolio'], [96, 47, 'Overdue amount and days past due']]],
    ['Portfolio-reports', 'Portfolio reports', 'Know where each portfolio stands', 'Calls, connects, collections and callbacks for every bank portfolio, by group and by agent — ready for the client review.', 'Portfolio reports',
      [[40, 15, 'Calls, collections and callbacks'], [36, 68, 'Daily calls, connected and not'], [86, 68, 'Collections by day']]],
    ['Devices-and-approvals', 'Devices', 'Company phones, under your control', 'A new phone shows a code; your admin checks it and approves. Policy and compliance for every phone in one list.', 'Devices → Devices & approvals',
      [[28, 27, 'Code shown on the new phone'], [93, 27, 'Review and approve'], [68, 47, 'Policy applied and compliant']]],
  ];
  $views = array_values(array_filter(array_map(fn ($v) => ($s = $shot($v[0])) ? array_merge($v, [$s]) : null, $views)));
@endphp
@if($views)
<section id="product" class="band showcase"><div class="wrap">
  <div class="two head"><h2>Your office sees it as it happens</h2><p class="mute">The real ROVEXY console, shown with sample data. Pick a screen, or let it play.</p></div>
  <div class="sc-tabs" role="tablist" aria-label="Console screens">
    @foreach($views as $i => $v)<button type="button" role="tab" id="sct{{ $i }}" aria-controls="scp{{ $i }}" aria-selected="{{ $i ? 'false' : 'true' }}" class="{{ $i ? '' : 'on' }}">{{ $v[1] }}<i></i></button>@endforeach
  </div>
  <div class="sc-stage">
    @foreach($views as $i => [$file, $tab, $h, $p, $where, $spots, $s])
    <div class="sc-pane two {{ $i ? '' : 'on' }}" role="tabpanel" id="scp{{ $i }}" aria-labelledby="sct{{ $i }}">
      <div class="copy">
        <h3>{{ $h }}</h3><p>{{ $p }}</p>
        <ol class="sc-points">@foreach($spots as $k => $sp)<li><span>{{ $k + 1 }}</span>{{ $sp[2] }}</li>@endforeach</ol>
        <p class="sc-where">In the console: <b>{{ $where }}</b></p>
      </div>
      <figure class="sc-frame">
        <div class="scr-bar"><i></i><i></i><i></i><span>{{ $s['caption'] }}</span></div>
        <div class="sc-img"><img src="{{ $s['src'] }}" alt="{{ $s['caption'] }} in the ROVEXY console (sample data)" loading="{{ $i ? 'lazy' : 'eager' }}" width="1600" height="1000">
          @foreach($spots as $k => $sp)<span class="spot {{ $sp[0] > 75 ? 'r' : ($sp[0] < 25 ? 'l' : '') }}" style="left:{{ $sp[0] }}%;top:{{ $sp[1] }}%;--dl:{{ 0.5 + $k * 0.45 }}s"><b>{{ $k + 1 }}</b><em>{{ $sp[2] }}</em></span>@endforeach
        </div>
      </figure>
    </div>
    @endforeach
  </div>
  <p class="alsos"><b>Also included:</b> messages and internet voice calls between office and phone, live screen and camera sessions on request, push alerts, audit log and your company branding. <b>Works with</b> SmartPRS payroll, SmartEPT, Exotel, Google Maps and Excel/CSV.</p>
</div></section>
@endif

<section id="security" class="band dark"><div class="wrap two">
  <div class="copy"><h2>Records you can stand behind</h2><p>Customer data, call recordings and employee locations are sensitive. ROVEXY is designed around that, so you can show a bank exactly who did what.</p></div>
  <div class="trust">
    <div><h3>Your workspace, your data</h3><p>One company never sees another's customers or calls.</p></div>
    <div><h3>Approved phones only</h3><p>Your admin approves a phone before anyone signs in on it.</p></div>
    <div><h3>Access by role and group</h3><p>Managers see only their group; permissions decide who plays, exports or changes.</p></div>
    <div><h3>Audit log</h3><p>Sign-ins, exports and setting changes, with who and when.</p></div>
    <div><h3>Location only at work</h3><p>Between sign-in and sign-out, with a notice on the phone.</p></div>
    <div><h3>Retention you control</h3><p>Choose how long recordings are kept. Backed up daily.</p></div>
  </div>
</div></section>

<section id="pricing" class="band pricing"><div class="wrap">
  <div class="p-head"><p class="kicker">Pricing</p><h2>On our cloud, or on your own server</h2><p class="mute">Priced per user. Cloud is a subscription — annual is the best price. On-premises is a one-time licence with yearly updates &amp; support. Prices exclude 18% GST.</p></div>
  <div class="finder" id="finder">
    <div class="seg-dep" role="radiogroup" aria-label="Where ROVEXY runs">
      <label><input type="radio" name="dep" value="cloud" checked> Cloud (we host it)</label>
      <label><input type="radio" name="dep" value="onprem"> On-premises (your server)</label>
    </div>
    <label for="seats">How many people will use it? <b id="seatn">25</b></label>
    <input type="range" id="seats" min="5" max="600" value="25" step="1">
    <output for="seats" id="fitout" aria-live="polite"></output>
  </div>
  <div class="plans">
    @php $pick = $plans->count() > 1 ? 1 : 0; $prev = null; @endphp
    @forelse($plans as $i => $p)
      @php
        $has = $p->features ?? [];
        $plus = $prev && ! array_diff($prev->features ?? [], $has);   // ponytail: "Everything in X, plus" only when this plan really contains X
        $list = $plus ? array_diff($has, $prev->features ?? []) : $has;
      @endphp
      <div class="plan {{ $i === $pick ? 'pick' : '' }}" data-code="{{ $p->code }}" data-min="{{ $p->min_users }}" data-name="{{ $p->name }}"
           data-rate="{{ $p->from_rate }}" data-perp="{{ $p->from_perpetual }}">
        @if($i === $pick)<span class="badge">Most teams choose this</span>@endif
        <h3>{{ $p->name }}</h3>
        <span class="tag">{{ $p->tagline }}</span>
        <div class="price" data-p>@if($p->from_rate)<sup>₹</sup>{{ number_format($p->from_rate) }}<span>/ user / month</span>@else<span class="talk">Let's talk</span>@endif</div>
        <div class="per" data-per>@if($p->from_rate)From {{ $p->min_users }} users, billed annually @else Priced to your size @endif</div>
        <a class="btn {{ $i === $pick ? 'gold' : '' }}" data-buy href="/buy?plan={{ $p->code }}">Buy {{ $p->name }}</a>
        <p class="inc">{{ $plus ? 'Everything in ' . $prev->name . ', plus' : 'What you get' }}</p>
        <ul>@foreach($list as $k)@if(isset($features[$k]))<li>{{ $features[$k] }}</li>@endif @endforeach</ul>
      </div>
      @php $prev = $p; @endphp
    @empty
      <p>Packages are being updated. <a href="#contact">Ask us for a quotation.</a></p>
    @endforelse
  </div>
  <ul class="assure">
    <li><b>GST invoice</b>on every payment</li>
    <li><b>UPI, card or net banking</b>or a bank-transfer invoice</li>
    <li><b>Add users any time</b>you pay only for the rest of the term</li>
    <li><b>On-premises</b>one-time licence, first year of updates included</li>
    <li><b>Discount code?</b>enter it when you buy</li>
  </ul>
</div></section>


<section id="trial" class="start band"><div class="wrap">
  <div class="deal">
    <div class="deal-l">
      <i class="rings" aria-hidden="true"><i></i><i></i><i></i></i>
      <p class="kicker">Free for {{ $trialDays }} days</p>
      <h2>Try it with your own team</h2>
      <p>Every feature switched on, for up to {{ $trialUsers }} users. No card needed.</p>
      <ol class="steps">
        <li><b>Create your workspace</b><span>{{ $verify ? 'Confirm your e-mail with a code; takes a minute.' : 'Ready as soon as you submit.' }}</span></li>
        <li><b>Approve a company phone</b><span>Install the app, match the code, switch recording on.</span></li>
        <li><b>Upload a portfolio and call</b><span>Drop in the bank's Excel and assign it to your callers.</span></li>
      </ol>
      @if($contact['phone'] || $contact['email'])<p class="reach">Prefer a person? {!! collect([$contact['phone'] ? '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $contact['phone'])) . '">' . e($contact['phone']) . '</a>' : null, $contact['email'] ? '<a href="mailto:' . e($contact['email']) . '">' . e($contact['email']) . '</a>' : null])->filter()->implode(' · ') !!}</p>@endif
    </div>
    <div class="deal-r">
      <div class="seg" role="tablist" aria-label="Choose a form" hidden>
        <button type="button" role="tab" aria-selected="true" aria-controls="f-trial" class="on">Start free trial</button>
        <button type="button" role="tab" aria-selected="false" aria-controls="contact">Talk to sales</button>
      </div>
      <form method="post" action="/trial" id="f-trial">
        @csrf<input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        @if($errors->has('trial'))<div class="err" role="alert">{{ $errors->first('trial') }}</div>@elseif($errors->any() && old('workspace') !== null)<div class="err" role="alert">{{ $errors->first() }}</div>@endif
        <div class="grid2">
          <label>Company name<input name="company" value="{{ old('company') }}" required maxlength="150" autocomplete="organization"></label>
          <label>Workspace name<input name="workspace" value="{{ old('workspace') }}" required pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" placeholder="acme-collections" title="3–40 lower-case letters, numbers or dashes"></label>
          <label>Your name<input name="name" value="{{ old('name') }}" required maxlength="150" autocomplete="name"></label>
          <label>Mobile<input name="phone" value="{{ old('phone') }}" required maxlength="20" autocomplete="tel" inputmode="tel"></label>
        </div>
        <label>Work e-mail<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label>
        <div class="grid2">
          <label>Password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
          <label>Confirm password<input type="password" name="password_confirmation" required minlength="10" autocomplete="new-password"></label>
        </div>
        <label class="check"><input type="checkbox" name="terms" value="1" required> <span>I agree to the <a href="/terms">terms</a> and <a href="/privacy">privacy policy</a></span></label>
        <button class="btn gold wide" type="submit">Create my workspace →</button>
      </form>
      <form method="post" action="/enquiry" id="contact">
        @csrf<input class="hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <p class="lead">Large team, a custom package or the SmartPRS / SmartEPT link? We'll call you back within a working day.</p>
        <div class="grid2"><label>Name<input name="name" required maxlength="150" autocomplete="name"></label><label>Company<input name="company" maxlength="150" autocomplete="organization"></label></div>
        <div class="grid2"><label>Mobile<input name="phone" maxlength="20" inputmode="tel" autocomplete="tel"></label><label>E-mail<input type="email" name="email" autocomplete="email"></label></div>
        <label>Number of users<input type="number" name="users" min="1"></label>
        <label>Message<textarea name="message" rows="3" maxlength="2000"></textarea></label>
        <button class="btn wide" type="submit">Send enquiry</button>
      </form>
    </div>
  </div>
</div></section>


<section id="faq" class="band faq"><div class="wrap">
  <div class="faq-l">
    <p class="kicker">FAQ</p>
    <h2>Questions collection heads ask us</h2>
    <p class="mute">Straight answers on phones, recording, privacy and billing.</p>
    <div class="ask">
      <b>Still unsure?</b><p>Talk to a person who has set this up for collection floors before.</p>
      <div><?php $tel = preg_replace("/[^\d+]/", "", (string) $contact["phone"]); ?>@if($tel)<a class="btn gold" href="tel:{{ $tel }}">Call us</a>@endif<a class="btn ghost" href="#contact">Book a live demo</a></div>
    </div>
  </div>
  <div class="faq-r">
    <div class="chips" role="group" aria-label="Filter questions" hidden>@foreach(['' => 'All', 'setup' => 'Phones & calls', 'privacy' => 'Privacy & data', 'billing' => 'Billing'] as $k => $l)<button type="button" data-cat="{{ $k }}" class="{{ $k ? '' : 'on' }}" aria-pressed="{{ $k ? 'false' : 'true' }}">{{ $l }}</button>@endforeach</div>
    @foreach($faqs as $i => [$q, $a, $cat])<details name="faq" data-cat="{{ $cat }}" @if(! $i) open @endif><summary><span class="q">{{ sprintf('%02d', $i + 1) }}</span>{{ $q }}</summary><p>{{ $a }}</p></details>@endforeach
  </div>
</div></section>

<section class="closing"><div class="wrap"><div class="close-card">
  <i class="rings" aria-hidden="true"><i></i><i></i><i></i></i>
  <div class="close-copy">
    <p class="kicker">Ready when you are</p>
    <h2>Put your next portfolio on <em>record.</em></h2>
    <p>Start a free trial today, or let us walk your team through ROVEXY on a live call.</p>
    <div class="cta"><a class="btn gold" href="#trial">Start the {{ $trialDays }}-day free trial →</a><a class="btn ghost" href="#contact">Book a live demo</a></div>
  </div>
  <ul class="close-stats">
    <li><b>3,000+</b><span>collection and recovery agencies worked with</span></li>
    <li><b>2019</b><span>building collection software in Hyderabad</span></li>
    <li><b>{{ $trialDays }} days</b><span>free, every feature switched on</span></li>
  </ul>
</div></div></section>
@endsection

@section('script')
<script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => collect($faqs)->map(fn ($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]])],
  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
<script>
(function () {
  var plans = [].slice.call(document.querySelectorAll('.plan[data-code]')), box = document.getElementById('finder');
  if (!plans.length || !box) return;
  var seats = document.getElementById('seats'), out = document.getElementById('fitout'), t = null, seq = 0;
  var tok = (document.querySelector('meta[name=csrf-token]') || {}).content;
  var inr = function (n) { return '₹' + Math.round(n).toLocaleString('en-IN'); };
  var dep = function () { return (box.querySelector('input[name=dep]:checked') || {}).value || 'cloud'; };
  function price(p, n, d) {   // the server's own price — the same function every order uses
    return fetch('/price-check', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': tok },
      body: JSON.stringify({ plan: p.dataset.code, users: n, billing: 'annual', deployment: d }) }).then(function (r) { return r.json(); });
  }
  function fit() {
    var n = Math.max(1, parseInt(seats.value, 10) || 1), d = dep(), my = ++seq;
    document.getElementById('seatn').textContent = n + (n == seats.max ? '+' : '');
    Promise.all(plans.map(function (p) { return n < +p.dataset.min ? Promise.resolve({ ok: false, error: 'below_minimum' }) : price(p, n, d).catch(function () { return null; }); }))
      .then(function (res) {
        if (my !== seq) return;
        res.forEach(function (j, i) {
          var p = plans[i], pe = p.querySelector('[data-p]'), per = p.querySelector('[data-per]'), buy = p.querySelector('[data-buy]');
          if (j && j.ok) {
            pe.innerHTML = d === 'cloud' ? '<sup>₹</sup>' + Math.round(j.per_user_month).toLocaleString('en-IN') + '<span>/ user / month</span>'
              : '<sup>₹</sup>' + Math.round(j.list_price).toLocaleString('en-IN') + '<span>one-time</span>';
            per.innerHTML = d === 'cloud' ? 'For <b>' + n + ' users</b>: ' + inr(j.list_price) + ' a year + GST' : 'For <b>' + n + ' users</b>, plus setup; updates &amp; support yearly';
          } else {
            pe.innerHTML = '<span class="talk">' + (j && j.error === 'below_minimum' ? 'From ' + p.dataset.min + ' users' : "Let's talk") + '</span>';
            per.textContent = j && j.error === 'below_minimum' ? 'This plan starts at ' + p.dataset.min + ' users' : 'For ' + n + ' users we price it to your size';
          }
          var ok = j && j.ok;
          buy.textContent = ok ? 'Buy ' + p.dataset.name : 'Ask for a quotation';
          buy.className = 'btn ' + (ok ? (p.classList.contains('pick') ? 'gold' : '') : 'ghost');
          buy.href = ok ? '/buy?plan=' + p.dataset.code + '&users=' + n + (d === 'onprem' ? '&kind=perpetual' : '') : '#contact';
        });
        out.innerHTML = d === 'cloud' ? 'Cloud prices for ' + n + ' users, billed annually. Half-yearly and quarterly billing are also available.'
          : 'One-time on-premises licence for ' + n + ' users. The first 12 months of updates &amp; support are included; after that a yearly AMC.';
      });
  }
  var later = function () { clearTimeout(t); t = setTimeout(fit, 200); };
  seats.addEventListener('input', later); box.addEventListener('change', later); fit();
})();
</script>
<script>
(function () {
  var root = document.getElementById('tour'); if (!root) return;
  var tabs = [].slice.call(root.querySelectorAll('[role=tab]')), panes = [].slice.call(root.querySelectorAll('[role=tabpanel]'));
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches, cur = 0, timer = null, auto = !still, seen = false, DWELL = 7000;
  function show(i, focus) {
    cur = (i + tabs.length) % tabs.length;
    root.style.setProperty('--p', cur / Math.max(1, tabs.length - 1));
    tabs.forEach(function (t, k) { var on = k === cur; t.classList.toggle('on', on); t.classList.toggle('done', k < cur); t.classList.remove('run'); t.setAttribute('aria-selected', on); t.tabIndex = on ? 0 : -1; });
    panes.forEach(function (p, k) { p.classList.toggle('on', k === cur); });
    var t = tabs[cur]; void t.offsetWidth; if (auto) t.classList.add('run');
    if (focus) t.focus();
    if (window.innerWidth <= 900) t.scrollIntoView({ block: 'nearest', inline: 'center', behavior: still ? 'auto' : 'smooth' });
    [].forEach.call(panes[cur].querySelectorAll('[data-timer]'), function (el) { el.dataset.timer = 0; el.textContent = '00:00'; });
    clearTimeout(timer); if (auto) timer = setTimeout(function () { show(cur + 1); }, DWELL);
  }
  tabs.forEach(function (t, k) {
    t.addEventListener('click', function () { auto = false; show(k); });
    t.addEventListener('keydown', function (e) { var d = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[e.key]; if (d) { e.preventDefault(); auto = false; show(cur + d, true); } });
  });
  [].forEach.call(root.querySelectorAll('[data-step]'), function (b) { b.addEventListener('click', function () { auto = false; show(cur + +b.dataset.step); }); });
  root.style.setProperty('--dwell', DWELL / 1000 + 's');
  // start the tour only when it scrolls into view; pause while the pointer is over the screen
  if ('IntersectionObserver' in window) new IntersectionObserver(function (es) { if (es[0].isIntersecting && !seen) { seen = true; if (auto) show(0); } }, { threshold: .3 }).observe(root);
  root.querySelector('.stage').addEventListener('mouseenter', function () { clearTimeout(timer); tabs[cur].classList.remove('run'); });
  root.querySelector('.stage').addEventListener('mouseleave', function () { if (auto) show(cur); });
})();
</script>

<script>
[].forEach.call(document.querySelectorAll('[data-timer]'), function (el) {
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  setInterval(function () { var s = (+el.dataset.timer || 0) + 1; el.dataset.timer = s; el.textContent = String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); }, 1000);
});
</script>
<script>
(function () {   // console showcase: tabs auto-advance; each callout lights up in turn
  var root = document.querySelector('.showcase'); if (!root) return;
  var tabs = [].slice.call(root.querySelectorAll('[role=tab]')), panes = [].slice.call(root.querySelectorAll('.sc-pane'));
  var still = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches, cur = 0, t = null, st = [], auto = !still, seen = false, DW = 8000;
  root.style.setProperty('--scdw', DW / 1000 + 's');
  function spots() {
    st.forEach(clearTimeout); st = [];
    var ss = [].slice.call(panes[cur].querySelectorAll('.spot')); ss.forEach(function (x) { x.classList.remove('show'); });
    ss.forEach(function (x, k) { st.push(setTimeout(function () { ss.forEach(function (y) { y.classList.toggle('show', y === x); }); }, 1400 + k * 2000)); });
  }
  function show(i, user, focus) {
    cur = (i + tabs.length) % tabs.length;
    tabs.forEach(function (b, k) { b.classList.toggle('on', k === cur); b.setAttribute('aria-selected', k === cur); b.tabIndex = k === cur ? 0 : -1; });
    panes.forEach(function (p, k) { p.classList.toggle('on', k === cur); });
    if (focus) tabs[cur].focus();
    if (user) auto = false;
    root.classList.remove('run'); void root.offsetWidth; if (auto) root.classList.add('run');
    spots(); clearTimeout(t); if (auto) t = setTimeout(function () { show(cur + 1); }, DW);
  }
  tabs.forEach(function (b, k) {
    b.addEventListener('click', function () { show(k, true); });
    b.addEventListener('keydown', function (e) { var d = { ArrowRight: 1, ArrowLeft: -1 }[e.key]; if (d) { e.preventDefault(); show(cur + d, true, true); } });
  });
  root.querySelector('.sc-stage').addEventListener('mouseenter', function () { clearTimeout(t); root.classList.remove('run'); });
  root.querySelector('.sc-stage').addEventListener('mouseleave', function () { if (auto) show(cur); });
  if ('IntersectionObserver' in window) new IntersectionObserver(function (es) { if (es[0].isIntersecting && !seen) { seen = true; if (auto) show(0); } }, { threshold: .25 }).observe(root);
  else show(0);
})();
</script>
<script>
(function () {   // hero console: scale the 1180×900 drawing to its box, keep the numbers moving, full-screen on demand
  var cx = document.querySelector('.cx'); if (!cx) return;
  var canvas = cx.querySelector('.cx-canvas'), btn = cx.querySelector('.cx-zoom'), back = null;
  function fit() { cx.style.setProperty('--cxs', cx.clientWidth / 1180); }
  ('ResizeObserver' in window) ? new ResizeObserver(fit).observe(cx) : window.addEventListener('resize', fit); fit();
  function full(on) {
    cx.classList.toggle('full', on); btn.textContent = on ? '✕ Close' : '⤢ Full screen';
    if (on) { back = document.createElement('div'); back.className = 'cx-backdrop'; back.onclick = function () { full(false); }; document.body.appendChild(back); }
    else if (back) { back.remove(); back = null; }
    fit();
  }
  btn.addEventListener('click', function () { full(!cx.classList.contains('full')); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && cx.classList.contains('full')) full(false); });
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  var K = function (k) { return cx.querySelector('[data-k=' + k + ']'); };
  function set(k, v) { var el = K(k); if (el && el.textContent != v) { el.textContent = v; var b = el.closest('b') || el; b.classList.add('bump'); setTimeout(function () { b.classList.remove('bump'); }, 900); } }
  var s = { online: 9, active: 4, oncall: 3, idle: 1, calls: 1401, conn: 919, talk: 178620, reached: 1088, fu: 38, rec: 871, recp: 6, tc: 120, tn: 62, min: 40 };
  var nav = [].slice.call(cx.querySelectorAll('.cx-side a.sub')), toast = cx.querySelector('[data-toast]'), bell = cx.querySelector('[data-bell]');
  var msgs = [['New message from Priya S.', 'Suresh Babu paid ₹8,000 by UPI. Slip uploaded.'], ['Payment to check', 'Anil Verma · ₹27,300 · NEFT · slip attached'],
              ['New message from Ravi Teja K.', 'Reached Imran S. — checked in 21 m from the pin.'], ['Phone waiting for approval', 'OPPO CPH2359 · Priya S. · code 4821']];
  function donut() {
    var off = 10 - s.online, work = s.active, call = s.oncall, idle = s.idle;
    var seg = [['work', work], ['call', call], ['idle', idle], ['off', off]], at = 25;
    seg.forEach(function (x) { var c = cx.querySelector('[data-seg=' + x[0] + ']'), len = x[1] * 10; c.setAttribute('stroke-dasharray', len + ' ' + (100 - len)); c.setAttribute('stroke-dashoffset', at); at -= len; });
    set('online2', s.online); set('l-work', work); set('l-call', call); set('l-idle', idle);
  }
  var tick = 0;
  setInterval(function () {
    if (document.hidden || cx.getBoundingClientRect().bottom < 0) return;
    tick++;
    // a call starts or ends somewhere on the floor
    if (Math.random() < .5 && s.active > 1) { s.active--; s.oncall++; } else if (s.oncall > 1) { s.oncall--; s.active++; s.calls++; s.reached += Math.random() < .7 ? 1 : 0; if (Math.random() < .66) { s.conn++; s.tc += 1; } else s.tn += 1; s.talk += 60 + Math.floor(Math.random() * 180); if (Math.random() < .8) { s.rec++; } }
    if (tick % 5 === 0) { var d = Math.random() < .5 ? 1 : -1; if (s.online + d <= 10 && s.online + d >= 8) { s.online += d; if (d > 0 && s.idle > 0) s.idle--; else s.idle++; } }
    if (tick % 4 === 0 && s.fu > 30) s.fu--;
    s.recp = 3 + (tick % 5);
    set('online', s.online); set('onlinep', s.online * 10 + '% of employees'); set('active', s.active); set('oncall', s.oncall); set('idle', s.idle); set('offline', 10 - s.online - s.idle >= 0 ? 10 - s.online : 0);
    set('calls', s.calls); set('conn', s.conn); set('pct', Math.round(s.conn / s.calls * 100)); set('reached', s.reached); set('fu', s.fu); set('rec', s.rec); set('recp', s.recp);
    set('talk', Math.floor(s.talk / 3600) + 'h ' + String(Math.floor(s.talk % 3600 / 60)).padStart(2, '0') + 'm');
    K('todayc').style.setProperty('--v', Math.min(s.tc, 170)); K('todayn').style.setProperty('--v', Math.min(s.tn, 70));
    donut();
    if (tick % 3 === 0) { s.min = (s.min + 1) % 60; K('clock') ; cx.querySelector('[data-clock]').textContent = 'Mon, 12 Oct · 0' + (s.min < 40 ? 4 : 3) + ':' + String(s.min).padStart(2, '0') + ' pm'; }
    nav.forEach(function (a) { a.classList.remove('hot'); }); if (tick % 2 && !cx.classList.contains('shot')) nav[Math.floor(Math.random() * nav.length)].classList.add('hot');
    if (tick % 6 === 2) { var m = msgs[(tick / 6 | 0) % msgs.length]; toast.querySelector('b').textContent = m[0]; toast.querySelector('span').textContent = m[1]; toast.classList.add('on'); bell.classList.add('on'); setTimeout(function () { toast.classList.remove('on'); }, 3800); }
  }, 1700);
})();
</script>
<script>
(function () {   // hero console clicks: menu → real screen, dashboard → full screen
  var cx = document.querySelector('.cx'); if (!cx) return;
  var view = cx.querySelector('.cx-shotview'), img = cx.querySelector('[data-shotimg]'), note = cx.querySelector('[data-note]'), crumb = cx.querySelector('.crumb b'), hint = cx.querySelector('.cx-hint');
  var links = [].slice.call(cx.querySelectorAll('.cx-side a')), nt = null;
  function mark(a) { links.forEach(function (x) { x.classList.toggle('on', x === a); x.classList.remove('hot'); }); if (crumb) crumb.textContent = a.dataset.label; if (hint) hint.remove(), hint = null; }
  function live() { view.hidden = true; cx.classList.remove('shot'); note.classList.remove('on'); mark(links[0]); }
  links.forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault(); e.stopPropagation();
      if (a.hasAttribute('data-live')) return live();
      mark(a); note.classList.remove('on');
      if (a.dataset.shot) {
        var cut = parseFloat(a.dataset.cut) || 0, w = 952 / (1 - cut);   // hide the screenshot's own sidebar behind ours; fit width so no column is cut
        img.src = a.dataset.shot; img.style.width = w + 'px'; img.style.marginLeft = (-w * cut) + 'px';
        view.hidden = false; view.style.animation = 'none'; void view.offsetWidth; view.style.animation = ''; cx.classList.add('shot');
      } else {
        view.hidden = true; cx.classList.remove('shot');
        note.querySelector('b').textContent = a.dataset.label; note.classList.add('on'); clearTimeout(nt); nt = setTimeout(function () { note.classList.remove('on'); }, 4500);
      }
    });
  });
  cx.querySelector('.cx-back').addEventListener('click', function (e) { e.stopPropagation(); live(); });
  note.addEventListener('click', function (e) { e.stopPropagation(); });
  cx.querySelector('.cx-main').addEventListener('click', function () { if (!cx.classList.contains('full') && !cx.classList.contains('shot')) cx.querySelector('.cx-zoom').click(); });
})();
</script>
<script>
(function () {   // trial / sales switch; any #contact link opens the sales form
  var box = document.querySelector('.deal-r'); if (!box) return;
  var seg = box.querySelector('.seg'), tabs = [].slice.call(seg.querySelectorAll('button')), forms = tabs.map(function (t) { return document.getElementById(t.getAttribute('aria-controls')); });
  function pick(k) { tabs.forEach(function (t, i) { t.classList.toggle('on', i === k); t.setAttribute('aria-selected', i === k); forms[i].hidden = i !== k; }); }
  seg.hidden = false; box.classList.add('js'); pick(0);
  tabs.forEach(function (t, i) { t.addEventListener('click', function () { pick(i); }); });
  function hash() { if (location.hash === '#contact') { pick(1); document.getElementById('trial').scrollIntoView(); } }
  document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('a[href$="#contact"]'); if (a) { e.preventDefault(); history.replaceState(null, '', '#contact'); hash(); } });
  hash();
})();
(function () {   // faq topic filter
  var chips = document.querySelector('.faq .chips'); if (!chips) return;
  var items = [].slice.call(document.querySelectorAll('.faq details')); chips.hidden = false;
  chips.addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    [].forEach.call(chips.children, function (c) { c.classList.toggle('on', c === b); c.setAttribute('aria-pressed', c === b); });
    var first = true; items.forEach(function (d) { var show = !b.dataset.cat || d.dataset.cat === b.dataset.cat; d.hidden = !show; d.open = show && first; if (show) first = false; });
  });
})();
</script>
@endsection
