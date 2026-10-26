<?php
$page_title = "Support & Help Center - Contact Waamini CRM";
$meta_desc = "Get expert support for your WhatsApp Business API. Chat on WhatsApp, call, email or send us a message. FAQs and technical help for Waamini WhatsApp CRM users.";
$meta_keywords = "WhatsApp CRM Support, WhatsApp API Help, Contact Waamini, WhatsApp Automation FAQ";
$asyncStylesheets = [
  'assets/css/landing-neo.css?v=16',
];
$bodyClass = 'lp-neo';
$waNumber = '919150802906';
$faqs = [
    ['What is Waamini CRM?', 'Waamini is a WhatsApp Business API platform that helps businesses automate their customer communication, sales and support on WhatsApp: broadcasts, a shared team inbox, chatbots, flows and an AI assistant.'],
    ['How long does API approval take?', 'Most businesses are connected within a few minutes using our signup process. Full business verification by Meta can take 1-2 working days.'],
    ['Can I use my existing number?', 'Yes. You can use your current phone number with the WhatsApp API, as long as it is not active on the regular WhatsApp or WhatsApp Business app at the same time.'],
    ['Is technical support included?', 'Yes. Every plan includes setup help and technical support by WhatsApp, phone and email, Monday to Saturday.'],
    ['Do you charge extra on Meta message rates?', 'No. You pay Meta’s official conversation rates with zero markup; our plan covers the platform and support.'],
    ['Can my team use one WhatsApp number together?', 'Yes. The shared inbox lets several team members reply from the same number, with chat assignment, notes and labels.'],
];
// Support desk hours: Monday to Saturday, 9 AM to 7 PM IST. Shown live in the hero (no fake "all systems" badge).
$__ist = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$__dow = (int)$__ist->format('N');               // 1 = Monday ... 7 = Sunday
$__hr  = (int)$__ist->format('G') + (int)$__ist->format('i') / 60;
$deskOpen = $__dow <= 6 && $__hr >= 9 && $__hr < 19;
if ($deskOpen) { $deskBack = ''; }
elseif ($__dow <= 6 && $__hr < 9) { $deskBack = 'today'; }
elseif ($__dow <= 5) { $deskBack = 'tomorrow'; }
else { $deskBack = 'on Monday'; }
$pageCriticalCss = <<<'CSS'
.sp{--g:#128C7E;--g2:#25D366;--gd:#0B6B5E;--ink:#0F2622;--tx:#3B4B47;--mu:#6B7C76;--line:#E3ECE8;--soft:#F3FAF7;font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);background:#fff}
.sp *{box-sizing:border-box}
.sp-wrap{max-width:1160px;margin:0 auto;padding:0 20px}
.sp-hero{padding:120px 0 84px;text-align:center;background:radial-gradient(900px 380px at 50% -40px,#DDF7EC 0,transparent 70%),linear-gradient(180deg,#F5FCF9 0,#fff 100%)}
.sp-badge{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;background:#E3F7EE;color:var(--gd);font-size:13px;font-weight:700}
.sp-hero h1{margin:18px 0 12px;font-size:clamp(32px,5vw,52px);line-height:1.08;font-weight:800;letter-spacing:-.03em;color:var(--ink)}
.sp-hero h1 span{color:var(--g)}
.sp-tw{display:block}
body.lp-neo .sp .title-wrap>*{transform:none}
@media (min-width:1001px) and (prefers-reduced-motion:no-preference){body.lp-neo.lp-anim-ready .sp .title-wrap>*{transform:translateY(105%)}}
.sp-hero p{max-width:620px;margin:0 auto;font-size:clamp(15.5px,1.6vw,18px);line-height:1.6;color:var(--tx)}
.sp-hero-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.1fr);gap:40px;align-items:center;text-align:left}
.sp-hero-grid p{margin:0}
.sp-hero-art{position:relative;min-width:0}
.spi{display:block;width:100%;height:auto;overflow:visible}
@media (max-width:900px){
  .sp-hero-grid{grid-template-columns:1fr;gap:28px;text-align:center}
  .sp-hero-grid p{margin:0 auto}
  .sp-hero-art{max-width:480px;width:100%;margin:0 auto}
}
CSS;
include 'includes/header.php';
?>

<!-- FAQ SCHEMA (JSON-LD) -->
<script type="application/ld+json">
<?php echo json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(static fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faqs)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>
</script>

<style>
/* ---- support illustration (includes/support_art.php): entrance, then gentle ambient motion ---- */
.spi .spi-pop,.spi .spi-gear,.spi .spi-pie,.spi .spi-plane,.spi .spi-bars rect,.spi .spi-line{transform-box:fill-box;transform-origin:center}
.spi .spi-bars rect{transform-origin:50% 100%}
.spi .spi-line{transform-origin:0 50%}
.spi .spi-frame{filter:drop-shadow(0 18px 26px rgba(15,38,34,.16))}
@media (prefers-reduced-motion:no-preference){
  .spi{animation:spiIn .7s cubic-bezier(.34,1.56,.64,1) both}
  .spi .spi-pop{animation:spiPop .6s cubic-bezier(.34,1.56,.64,1) both;animation-delay:var(--d,.5s)}
  .spi .spi-gear1-wrap{--d:.45s}.spi .spi-gear2-wrap{--d:.55s}.spi .spi-paper1{--d:.6s}.spi .spi-paper2{--d:.65s}
  .spi .spi-plane-wrap{--d:.7s}.spi .spi-dot1{--d:.75s}.spi .spi-dot2{--d:.8s}.spi .spi-dot3{--d:.85s}
  .spi .spi-gear1{animation:spiGear 20s linear infinite alternate}
  .spi .spi-gear2{animation:spiGear 20s linear infinite alternate-reverse}
  .spi .spi-pie{animation:spiSpin 10s linear infinite}
  .spi .spi-bars rect{animation:spiBar 2.4s ease-in-out infinite alternate;animation-delay:calc(var(--n)*.18s)}
  .spi .spi-line{animation:spiLine 3.2s ease-in-out infinite;animation-delay:calc(1s + var(--n)*.35s)}
  .spi .spi-paper1{animation:spiPop .6s cubic-bezier(.34,1.56,.64,1) both,spiFloat 4.5s ease-in-out 1.3s infinite alternate;animation-delay:var(--d),1.3s}
  .spi .spi-paper2{animation:spiPop .6s cubic-bezier(.34,1.56,.64,1) both,spiFloat 5.2s ease-in-out 1.5s infinite alternate-reverse;animation-delay:var(--d),1.5s}
  .spi .spi-dot{animation:spiPop .6s cubic-bezier(.34,1.56,.64,1) both,spiBob 3.6s ease-in-out infinite alternate;animation-delay:var(--d),1.4s}
  .spi .spi-dot2{animation-duration:.6s,4.4s}.spi .spi-dot3{animation-duration:.6s,3s}
  .spi .spi-plane{animation:spiFly 6s ease-in-out 1.4s infinite}
}
@keyframes spiIn{from{opacity:0;transform:scale(.7)}to{opacity:1;transform:scale(1)}}
@keyframes spiPop{from{opacity:0;transform:scale(.5)}to{opacity:1;transform:scale(1)}}
@keyframes spiGear{to{transform:rotate(180deg)}}
@keyframes spiSpin{to{transform:rotate(360deg)}}
@keyframes spiBar{from{transform:scaleY(1)}to{transform:scaleY(.62)}}
@keyframes spiLine{0%,100%{transform:scaleX(1);opacity:1}50%{transform:scaleX(.55);opacity:.6}}
@keyframes spiFloat{from{transform:translate(0,0) rotate(0)}to{transform:translate(0,-9px) rotate(-3deg)}}
@keyframes spiBob{from{transform:translateY(0)}to{transform:translateY(-8px)}}
@keyframes spiFly{0%,100%{transform:translate(0,0) rotate(0)}30%{transform:translate(-14px,7px) rotate(-6deg)}60%{transform:translate(6px,-6px) rotate(4deg)}}
/* ---- hero actions + live support-desk status (Webflow-style status widget) ---- */
.sp-cta{display:flex;flex-wrap:wrap;gap:10px;margin-top:26px}
.sp-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:12px;border:1.5px solid var(--line);background:#fff;color:var(--ink);font-size:15px;font-weight:700;text-decoration:none;transition:transform .2s,box-shadow .2s,border-color .2s}
.sp-btn:hover{transform:translateY(-2px);border-color:#9ED9BF;box-shadow:0 12px 26px -16px rgba(18,140,126,.6)}
.sp-btn--wa{background:linear-gradient(135deg,#25D366,#128C7E);border-color:transparent;color:#fff;box-shadow:0 12px 26px -14px rgba(18,140,126,.7)}
.sp-btn--wa:hover{border-color:transparent}
.sp-status{display:flex;align-items:center;gap:12px;max-width:520px;margin-top:22px;padding:12px 14px;border:1.5px solid var(--line);border-radius:14px;background:rgba(255,255,255,.8);text-align:left}
.sp-status__dot{position:relative;flex:none;width:10px;height:10px;border-radius:50%;background:#94A3B8}
.sp-status.is-on .sp-status__dot{background:#25D366}
.sp-status.is-on .sp-status__dot::after{content:"";position:absolute;inset:-4px;border-radius:50%;border:2px solid #25D366;opacity:0;animation:spPing 2s ease-out infinite}
@keyframes spPing{0%{transform:scale(.6);opacity:.7}100%{transform:scale(1.8);opacity:0}}
.sp-status__txt{display:flex;flex-direction:column;line-height:1.35;min-width:0}
.sp-status__txt b{font-size:13px;color:var(--ink)}
.sp-status__txt span{font-size:13px;color:var(--mu)}
.sp-status__txt strong{color:var(--gd)}
.sp-status:not(.is-on) .sp-status__txt strong{color:#475569}
.sp-status__link{margin-left:auto;flex:none;display:inline-flex;align-items:center;gap:4px;font-size:12.5px;font-weight:700;color:var(--g);text-decoration:none;white-space:nowrap}
.sp-status__link:hover{text-decoration:underline}
@media (max-width:900px){.sp-cta{justify-content:center}.sp-status{margin-left:auto;margin-right:auto}}
@media (max-width:480px){.sp-status{flex-wrap:wrap}.sp-status__link{margin-left:22px}}

/* ---- additional support requests ---- */
.sp-req{display:flex;flex-wrap:wrap;align-items:center;gap:12px 28px;margin-top:40px;padding:20px 24px;border:1.5px solid var(--line);border-radius:18px;background:var(--soft)}
.sp-req__title{margin:0;font-size:15px;font-weight:800;color:var(--ink)}
.sp-req__links{display:flex;flex-wrap:wrap;gap:10px 26px}
.sp-req__links a{display:inline-flex;align-items:center;gap:6px;font-size:14.5px;font-weight:700;color:var(--g);text-decoration:none}
.sp-req__links a svg{transition:transform .2s}
.sp-req__links a:hover svg{transform:translateX(3px)}

/* ---- learning and support resources ---- */
.sp-res{padding-top:84px}
.sp-res__head{max-width:640px}
.sp-res__grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.sp-res__card{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:24px;border:1.5px solid var(--line);border-radius:20px;background:#fff;color:inherit;text-decoration:none;transition:transform .25s,box-shadow .25s,border-color .25s}
.sp-res__card:hover{transform:translateY(-4px);border-color:#9ED9BF;box-shadow:0 22px 44px -26px rgba(15,38,34,.45)}
.sp-res__card b{font-size:18px;font-weight:800;color:var(--ink)}
.sp-res__card>span:not(.sp-ic){font-size:14.5px;line-height:1.55;color:var(--mu)}
.sp-res__card em{margin-top:auto;padding-top:12px;display:inline-flex;align-items:center;gap:6px;font-style:normal;font-size:14.5px;font-weight:700;color:var(--g)}
.sp-res__card em svg{transition:transform .2s}
.sp-res__card:hover em svg{transform:translate(2px,-2px)}

/* ---- get started band ---- */
.sp-go{display:flex;align-items:center;justify-content:space-between;gap:24px;margin:0 0 90px;padding:40px 44px;border-radius:24px;background:radial-gradient(600px 240px at 90% 0,rgba(255,255,255,.18),transparent 70%),linear-gradient(135deg,#128C7E,#0B6B5E);color:#fff}
.sp-go h2{margin:0 0 8px;font-size:clamp(24px,3vw,32px);font-weight:800;letter-spacing:-.02em;color:#fff}
.sp-go p{margin:0;max-width:520px;font-size:15.5px;line-height:1.6;color:rgba(255,255,255,.88)}
.sp-go__btns{display:flex;flex-wrap:wrap;gap:10px;flex:none}
.sp-btn--light{background:#fff;border-color:#fff;color:var(--gd)}
.sp-btn--ghost{background:transparent;border-color:rgba(255,255,255,.55);color:#fff}
.sp-btn--ghost:hover{border-color:#fff}
@media (max-width:900px){
  .sp-res__grid{grid-template-columns:1fr}
  .sp-go{flex-direction:column;align-items:flex-start;padding:32px 24px}
}
.sp-ways{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-top:-44px;position:relative;z-index:2}
.sp-way{display:flex;flex-direction:column;align-items:flex-start;gap:6px;padding:22px;border-radius:20px;background:#fff;text-decoration:none;color:inherit;box-shadow:0 1px 2px rgba(15,38,34,.05),0 16px 40px -24px rgba(15,38,34,.35);transition:transform .2s,box-shadow .2s}
.sp-way:hover{transform:translateY(-3px);box-shadow:0 1px 2px rgba(15,38,34,.05),0 22px 48px -22px rgba(15,38,34,.4)}
.sp-ic{flex:none;width:46px!important;height:46px!important;max-width:46px;border-radius:14px;display:grid;place-items:center;background:var(--soft);color:var(--g);margin-bottom:8px}
.sp-way b{font-size:16.5px;font-weight:800;color:var(--ink)}
.sp-way span{font-size:14px;line-height:1.5;color:var(--mu)}
.sp-way em{margin-top:auto;padding-top:10px;font-style:normal;font-size:14px;font-weight:700;color:var(--g)}
.sp-way--wa{background:linear-gradient(140deg,#25D366 0,#128C7E 100%);color:#fff}
.sp-way--wa .sp-ic{background:rgba(255,255,255,.18);color:#fff}
.sp-way--wa b,.sp-way--wa span,.sp-way--wa em{color:#fff}
.sp-way--wa span{opacity:.9}

.sp-main{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:40px;align-items:start;padding:84px 0 40px}
.sp-h2{margin:0 0 6px;font-size:clamp(24px,3vw,32px);font-weight:800;letter-spacing:-.02em;color:var(--ink)}
.sp-sub{margin:0 0 24px;font-size:15px;color:var(--mu)}
.sp-faq{display:flex;flex-direction:column;gap:10px}
.sp-q{border-radius:16px;background:var(--soft);transition:background .2s}
.sp-q[open]{background:#fff;box-shadow:0 0 0 1px var(--line),0 12px 30px -22px rgba(15,38,34,.4)}
.sp-q summary{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;cursor:pointer;list-style:none;font-size:16px;font-weight:700;color:var(--ink)}
.sp-q summary::-webkit-details-marker{display:none}
.sp-q summary i{flex:none;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;background:#fff;color:var(--g);transition:transform .25s,background .2s}
.sp-q[open] summary i{transform:rotate(45deg);background:var(--g);color:#fff}
.sp-q p{margin:0;padding:0 20px 20px;font-size:15px;line-height:1.65;color:var(--tx)}

.sp-form{position:sticky;top:100px;padding:28px;border-radius:24px;background:#fff;box-shadow:0 0 0 1px var(--line),0 24px 60px -30px rgba(15,38,34,.45)}
.sp-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.sp-f{display:flex;flex-direction:column;gap:7px;margin-bottom:16px}
.sp-f label{font-size:13.5px;font-weight:700;color:var(--tx)}
.sp-f label small{font-weight:500;color:var(--mu)}
.sp-f input,.sp-f select,.sp-f textarea{width:100%;padding:13px 14px;border:1.5px solid var(--line);border-radius:12px;background:#FBFDFC;font:500 15px Inter,system-ui,sans-serif;color:var(--ink);outline:0;transition:border-color .15s,box-shadow .15s,background .15s;-webkit-appearance:none;appearance:none}
.sp-f select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236B7C76' stroke-width='2.4'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center;padding-right:40px}
.sp-f textarea{min-height:130px;resize:vertical;line-height:1.55}
.sp-f input:focus,.sp-f select:focus,.sp-f textarea:focus{border-color:var(--g);background:#fff;box-shadow:0 0 0 4px rgba(18,140,126,.12)}
.sp-f.is-bad input,.sp-f.is-bad textarea{border-color:#E5484D}
.sp-f .sp-err{font-size:12.5px;color:#C8323A}
.sp-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.sp-send{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;height:52px;border:0;border-radius:14px;background:linear-gradient(135deg,#25D366,#128C7E);color:#fff;font:800 15.5px Inter,system-ui,sans-serif;cursor:pointer;box-shadow:0 14px 28px -14px rgba(18,140,126,.9);transition:transform .15s}
.sp-send:hover{transform:translateY(-1px)}.sp-send:disabled{opacity:.7;cursor:wait;transform:none}
.sp-note{margin:12px 0 0;font-size:12.5px;color:var(--mu);text-align:center}
.sp-msg{display:none;margin-bottom:16px;padding:12px 14px;border-radius:12px;font-size:14px;line-height:1.5}
.sp-msg.is-err{display:block;background:#FDECEC;color:#B42318}
.sp-done{display:none;text-align:center;padding:24px 6px}
.sp-done i{width:64px;height:64px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#E3F7EE;color:var(--g)}
.sp-done h3{margin:0 0 6px;font-size:21px;font-weight:800}
.sp-done p{margin:0 0 18px;color:var(--tx);line-height:1.6}
.sp-done a{color:var(--g);font-weight:700}
.sp-form.is-sent form{display:none}.sp-form.is-sent .sp-done{display:block}

.sp-visit{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.5fr);gap:0;margin:60px auto 56px;border-radius:24px;overflow:hidden;background:#fff;box-shadow:0 0 0 1px var(--line),0 24px 60px -30px rgba(15,38,34,.45)}
.sp-visit__info{padding:32px;display:flex;flex-direction:column;gap:18px;background:var(--soft)}
.sp-visit__info h2{margin:0;font-size:24px;font-weight:800}
.sp-li{display:flex;gap:12px;align-items:flex-start;font-size:15px;line-height:1.55;color:var(--tx)}
.sp-li .sp-ic{width:38px;height:38px;border-radius:11px;margin:0;flex:none;background:#fff}
.sp-li b{display:block;color:var(--ink);font-size:14px}
.sp-dir{display:inline-flex;align-items:center;gap:8px;align-self:flex-start;margin-top:auto;padding:12px 20px;border-radius:12px;background:var(--ink);color:#fff;font-weight:700;font-size:14px;text-decoration:none}
.sp-visit iframe{display:block;width:100%;height:100%;min-height:380px;border:0}

@media (max-width:980px){.sp-ways{grid-template-columns:repeat(2,minmax(0,1fr))}.sp-main{grid-template-columns:1fr;gap:48px}.sp-form{position:static}.sp-visit{grid-template-columns:1fr}.sp-visit iframe{min-height:300px}}
@media (max-width:560px){.sp-hero{padding:96px 0 72px}.sp-ways{grid-template-columns:1fr;gap:12px;margin-top:-36px}.sp-way{display:grid!important;grid-template-columns:46px minmax(0,1fr);column-gap:14px;row-gap:3px;align-items:start;padding:16px 18px}.sp-way .sp-ic{grid-row:1 / span 3;margin:0}.sp-way>b,.sp-way>span:not(.sp-ic),.sp-way>em{grid-column:2}.sp-way>.sp-ic{grid-column:1}.sp-way em{padding-top:4px}.sp-row{grid-template-columns:1fr;gap:0}.sp-form{padding:22px 18px}.sp-main{padding-top:56px}.sp-visit{margin:40px auto 72px}.sp-visit__info{padding:24px}}
</style>

<?php
$I = [
  'wa'    => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
  'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
  'mail'  => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
  'pin'   => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
  'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
  'head'  => '<path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/>',
  'plus'  => '<path d="M12 5v14"/><path d="M5 12h14"/>',
  'send'  => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
  'check' => '<path d="M20 6 9 17l-5-5"/>',
  'go'    => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
  'ext'   => '<path d="M7 17 17 7"/><path d="M8 7h9v9"/>',
  'book'  => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><path d="M8 7h8M8 11h6"/>',
  'learn' => '<path d="M22 10 12 5 2 10l10 5 10-5Z"/><path d="M6 12v5c3 2.5 9 2.5 12 0v-5"/>',
  'tag'   => '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
];
$svg = static fn(string $k, int $s = 22) => '<svg width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $I[$k] . '</svg>';
?>
<main class="sp">

  <section class="sp-hero">
    <div class="sp-wrap sp-hero-grid">
      <div class="sp-hero-txt">
        <span class="sp-badge badge-label"><?php echo $svg('head', 16); ?> Support Center</span>
        <div class="title-wrap sp-tw"><h1>Get <span>support</span></h1></div>
        <p>Find answers and get help from our WhatsApp experts. Questions about your WhatsApp Business API, campaigns or billing? Our Karaikudi team replies fast on WhatsApp, phone and email.</p>
        <div class="sp-cta">
          <a class="sp-btn sp-btn--wa" href="https://wa.me/<?php echo $waNumber; ?>?text=<?php echo rawurlencode('Hi Waamini team, I need help with '); ?>" target="_blank" rel="noopener"><?php echo $svg('wa', 18); ?> Chat on WhatsApp</a>
          <a class="sp-btn" href="#spForm"><?php echo $svg('mail', 18); ?> Send a message</a>
        </div>
        <div class="sp-status<?php echo $deskOpen ? ' is-on' : ''; ?>" aria-live="polite">
          <span class="sp-status__dot" aria-hidden="true"></span>
          <div class="sp-status__txt">
            <b>Support desk</b>
            <span><?php echo $deskOpen ? '<strong>Online now</strong> &middot; usually replies within minutes' : '<strong>Offline</strong> &middot; back ' . htmlspecialchars($deskBack) . ' at 9 AM IST'; ?></span>
          </div>
          <a class="sp-status__link" href="https://metastatus.com/" target="_blank" rel="noopener">WhatsApp API status <?php echo $svg('go', 14); ?></a>
        </div>
      </div>
      <div class="sp-hero-art"><?php include __DIR__ . '/includes/support_art.php'; ?></div>
    </div>
  </section>

  <div class="sp-wrap">
    <div class="sp-ways">
      <a class="sp-way sp-way--wa" href="https://wa.me/<?php echo $waNumber; ?>?text=<?php echo rawurlencode('Hi Waamini team, I need help with '); ?>" target="_blank" rel="noopener">
        <span class="sp-ic"><?php echo $svg('wa'); ?></span>
        <b>Chat on WhatsApp</b>
        <span>Fastest way to reach us. Usually a reply within minutes.</span>
        <em>Start chat &rarr;</em>
      </a>
      <a class="sp-way" href="tel:+919150802906">
        <span class="sp-ic"><?php echo $svg('phone'); ?></span>
        <b>Call us</b>
        <span>+91 91508 02906<br>+91 90038 76516</span>
        <em>Mon–Sat, 9 AM–7 PM</em>
      </a>
      <a class="sp-way" href="mailto:info@7digit.in">
        <span class="sp-ic"><?php echo $svg('mail'); ?></span>
        <b>Email us</b>
        <span>info@7digit.in</span>
        <em>Reply within 24 hours</em>
      </a>
      <a class="sp-way" href="#visit">
        <span class="sp-ic"><?php echo $svg('pin'); ?></span>
        <b>Visit our office</b>
        <span>Dolphin Super Market, 2nd Floor, Kalanivasal, Karaikudi</span>
        <em>Get directions</em>
      </a>
    </div>

    <!-- Additional support requests -->
    <section class="sp-req" aria-labelledby="reqTitle">
      <h2 class="sp-req__title" id="reqTitle">Additional support requests</h2>
      <div class="sp-req__links">
        <a href="https://wa.me/<?php echo $waNumber; ?>?text=<?php echo rawurlencode('Hi Waamini team, I want to report a billing or payment issue: '); ?>" target="_blank" rel="noopener">Report a billing or payment issue <?php echo $svg('go', 16); ?></a>
        <a href="data-deletion.php">Request data deletion <?php echo $svg('go', 16); ?></a>
        <a href="mailto:info@7digit.in?subject=<?php echo rawurlencode('Report spam or abuse'); ?>">Report spam or abuse <?php echo $svg('go', 16); ?></a>
      </div>
    </section>

    <!-- Learning and support resources -->
    <section class="sp-res" aria-labelledby="resTitle">
      <div class="sp-res__head">
        <div class="title-wrap sp-tw"><h2 class="sp-h2" id="resTitle">Explore our learning and support resources</h2></div>
        <p class="sp-sub">Guides, tutorials and plan details to get the most out of Waamini, at your own pace.</p>
      </div>
      <div class="sp-res__grid">
        <a class="sp-res__card" href="features.php">
          <span class="sp-ic"><?php echo $svg('book'); ?></span>
          <b>Feature guides</b>
          <span>See how broadcasts, the shared inbox, chatbots, flows and the AI assistant work, feature by feature.</span>
          <em>Explore all features <?php echo $svg('ext', 15); ?></em>
        </a>
        <a class="sp-res__card" href="blogs.php">
          <span class="sp-ic"><?php echo $svg('learn'); ?></span>
          <b>Blog &amp; tutorials</b>
          <span>Step-by-step tips on WhatsApp marketing, API setup and growing sales on WhatsApp.</span>
          <em>Start learning <?php echo $svg('ext', 15); ?></em>
        </a>
        <a class="sp-res__card" href="pricing.php">
          <span class="sp-ic"><?php echo $svg('tag'); ?></span>
          <b>Pricing &amp; plans</b>
          <span>Compare plans, see what each one includes and how Meta conversation charges work.</span>
          <em>Compare plans <?php echo $svg('ext', 15); ?></em>
        </a>
      </div>
    </section>

    <div class="sp-main">
      <section aria-labelledby="faqTitle">
        <div class="title-wrap sp-tw"><h2 class="sp-h2" id="faqTitle">Frequently asked questions</h2></div>
        <p class="sp-sub">Quick answers to what customers ask us most.</p>
        <div class="sp-faq">
          <?php foreach ($faqs as $i => [$q, $a]): ?>
          <details class="sp-q"<?php echo $i === 0 ? ' open' : ''; ?>>
            <summary><?php echo htmlspecialchars($q); ?><i><?php echo $svg('plus', 16); ?></i></summary>
            <p><?php echo htmlspecialchars($a); ?></p>
          </details>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="sp-form" id="spForm" aria-labelledby="formTitle">
        <div class="title-wrap sp-tw"><h2 class="sp-h2" id="formTitle">Send us a message</h2></div>
        <p class="sp-sub">Tell us what you need and we will get back to you.</p>
        <form id="spContact" novalidate>
          <div class="sp-msg" id="spMsg" role="alert"></div>
          <div class="sp-row">
            <div class="sp-f" data-f="name"><label for="spName">Full name</label><input id="spName" name="name" autocomplete="name" placeholder="Your name" required></div>
            <div class="sp-f"><label for="spPhone">Phone <small>(optional)</small></label><input id="spPhone" name="phone" type="tel" autocomplete="tel" placeholder="+91 98765 43210"></div>
          </div>
          <div class="sp-f" data-f="email"><label for="spEmail">Email address</label><input id="spEmail" name="email" type="email" autocomplete="email" placeholder="name@company.com" required></div>
          <div class="sp-f"><label for="spTopic">What is it about?</label>
            <select id="spTopic" name="subject">
              <option value="technical">Technical help</option>
              <option value="api">WhatsApp API setup</option>
              <option value="sales">Sales / pricing</option>
              <option value="billing">Billing</option>
              <option value="general">General question</option>
            </select>
          </div>
          <div class="sp-f" data-f="message"><label for="spText">Message</label><textarea id="spText" name="message" placeholder="How can we help you today?" required></textarea></div>
          <div class="sp-hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
          <button type="submit" class="sp-send" id="spSend"><?php echo $svg('send', 18); ?> Send message</button>
          <p class="sp-note">We reply by email within one working day.</p>
        </form>
        <div class="sp-done" role="status">
          <i><?php echo $svg('check', 30); ?></i>
          <h3>Message sent</h3>
          <p>Thanks! Our team will reply to your email soon. Need it faster? <a href="https://wa.me/<?php echo $waNumber; ?>" target="_blank" rel="noopener">Chat on WhatsApp</a>.</p>
        </div>
      </section>
    </div>

    <section class="sp-visit" id="visit" aria-labelledby="visitTitle">
      <div class="sp-visit__info">
        <div class="title-wrap sp-tw"><h2 id="visitTitle">Visit our office</h2></div>
        <div class="sp-li"><span class="sp-ic"><?php echo $svg('pin', 18); ?></span><div><b>Address</b>2nd Floor, Dolphin Super Market,<br>Kalanivasal, Karaikudi - 630001</div></div>
        <div class="sp-li"><span class="sp-ic"><?php echo $svg('clock', 18); ?></span><div><b>Working hours</b>Monday to Saturday, 9 AM – 7 PM IST</div></div>
        <div class="sp-li"><span class="sp-ic"><?php echo $svg('phone', 18); ?></span><div><b>Phone</b>+91 91508 02906</div></div>
        <a class="sp-dir" href="https://maps.app.goo.gl/sagyQzVW6EcuqQW27" target="_blank" rel="noopener">Get directions <?php echo $svg('go', 16); ?></a>
      </div>
      <iframe title="Waamini office on Google Maps" src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3928.173874312!2d78.774!3d10.085!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3b00678de0011ce1%3A0xbab48a457c1c8bd5!2s7digit!5e0!3m2!1sen!2sin!4v1774790137756!5m2!1sen!2sin" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
    </section>

    <!-- Get started -->
    <section class="sp-go" aria-labelledby="goTitle">
      <div>
        <h2 id="goTitle">Get started for free</h2>
        <p>Try Waamini free for 14 days. No credit card needed, and our team helps you set up your WhatsApp number.</p>
      </div>
      <div class="sp-go__btns">
        <a class="sp-btn sp-btn--light" href="register.php">Start free trial <?php echo $svg('go', 16); ?></a>
        <a class="sp-btn sp-btn--ghost" href="https://wa.me/<?php echo $waNumber; ?>?text=<?php echo rawurlencode('Hi Waamini team, I would like to talk to sales.'); ?>" target="_blank" rel="noopener"><?php echo $svg('wa', 16); ?> Talk to sales</a>
      </div>
    </section>
  </div>
</main>

<script nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>">
(function () {
  var form = document.getElementById('spContact'); if (!form) return;
  var box = document.getElementById('spForm'), msg = document.getElementById('spMsg'), btn = document.getElementById('spSend');
  function err(t, fields) {
    msg.textContent = t; msg.className = 'sp-msg is-err';
    form.querySelectorAll('.sp-f[data-f]').forEach(function (f) {
      f.classList.toggle('is-bad', !!(fields && fields[f.dataset.f]));
      var e = f.querySelector('.sp-err'); if (e) e.remove();
      if (fields && fields[f.dataset.f]) { var s = document.createElement('span'); s.className = 'sp-err'; s.textContent = fields[f.dataset.f]; f.appendChild(s); }
    });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(form), f = {};
    if ((fd.get('name') || '').trim().length < 2) f.name = 'Please enter your name.';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((fd.get('email') || '').trim())) f.email = 'Please enter a valid email address.';
    if ((fd.get('message') || '').trim().length < 10) f.message = 'Please write a little more (at least 10 characters).';
    if (Object.keys(f).length) { err('Please check the highlighted fields.', f); return; }
    btn.disabled = true; btn.lastChild.textContent = ' Sending…';
    fetch('api/support_contact.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) { msg.className = 'sp-msg'; msg.textContent = ''; box.classList.add('is-sent'); box.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        else err((d && d.error) || 'Could not send. Please try again.', d && d.fields);
      })
      .catch(function () { err('Network problem. Please try again, or chat with us on WhatsApp.'); })
      .then(function () { btn.disabled = false; btn.lastChild.textContent = ' Send message'; });
  });
})();
</script>

<script src="https://cdn.jsdelivr.net/npm/lenis@1.1.16/dist/lenis.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>
<script src="assets/js/lp-theme.js?v=6" nonce="<?php echo htmlspecialchars($_SESSION['_csp_nonce'] ?? ''); ?>"></script>

<?php include 'includes/footer.php'; ?>
