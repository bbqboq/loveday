<?php
/**
 * qr.php?t=편집토큰 - QR코드 만들기 (내 청첩장 → "QR 만들기")
 *
 *  - 결제한 청첩장(1년·영구 보관)만 쓸 수 있다 - 무료체험·둘러보기는 잠김 안내 (내 청첩장 메뉴 버튼도 잠김)
 *  - 무엇으로 연결할지: 청첩장 / 게스트스냅 사진 올리기 (이 청첩장 주소만 - 아무 주소나 넣는 QR 생성기로 쓰지 못하게)
 *  - 모양: 기본(네모) · 둥근 점 · 하트 점 · ♥ 하트 모양(QR 전체가 하트 안에 들어감)
 *  - 색·배경·가운데 하트 로고를 고르고 PNG(1200px)로 저장
 *  - 만들 때마다 실제로 읽히는지 스스로 스캔해서(jsQR) "휴대폰으로 인식돼요"를 확인해 준다
 * 모두 휴대폰·PC 브라우저 안에서 그린다 (서버로 보내는 것 없음).
 * 라이브러리: assets/qrcode.min.js (MIT), assets/jsQR.min.js (Apache-2.0)
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

$pdo = get_pdo();
$token = (string) ($_GET['t'] ?? '');
$invite = snap_owner_invite($pdo, $token);
if (!$invite) {
    http_response_code(403);
    exit('편집 링크가 올바르지 않거나 PIN 확인이 필요합니다. 내 청첩장에서 다시 들어와주세요.');
}
$slug = (string) $invite['view_slug'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// 결제한 청첩장만 (무분별한 생성 방지)
if (!in_array((string) $invite['storage_plan'], ['one_year', 'permanent'], true)) {
    http_response_code(403);
    ?><!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>QR코드 만들기</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F6F4F1;font-family:"Pretendard Variable",Pretendard,-apple-system,sans-serif;color:#1B1A18;padding:20px;box-sizing:border-box}
    .b{background:#fff;border:1px solid #EAE6E0;border-radius:20px;padding:32px 26px;max-width:380px;text-align:center;line-height:1.7;word-break:keep-all}.i{font-size:34px}h3{margin:6px 0 8px;font-size:18px}p{margin:0 0 20px;color:#6F6A63;font-size:14px}
    a{display:inline-block;padding:13px 22px;border-radius:12px;background:#1B1A18;color:#fff;text-decoration:none;font-weight:600;font-size:14.5px}</style></head>
    <body><div class="b"><div class="i">🔒</div><h3>결제한 청첩장에서 열려요</h3><p>하트 QR 만들기는 1년·영구 보관으로 결제한 청첩장에서 쓸 수 있어요.<br>결제하면 바로 이 메뉴가 열려요.</p><a href="dashboard.php?t=<?= $h($token) ?>">내 청첩장으로</a></div></body></html><?php
    exit;
}
$v = fn($f) => @filemtime(__DIR__ . '/assets/' . $f) ?: 1;
$targets = [
    'invite' => ['청첩장', 'https://loveday.kr/' . $slug],
    'snap'   => ['게스트스냅 사진 올리기', 'https://loveday.kr/invite/snap.php?s=' . rawurlencode($slug)],
];
$published = $invite['status'] === 'published';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>QR코드 만들기 · LOVE DAY</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<style>
:root { --bg:#F6F4F1; --ink:#1B1A18; --sub:#6F6A63; --faint:#A39D95; --line:#EAE6E0; --soft:#F1EEEA; --ok:#2F7D55; --warn:#B24A4A; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", sans-serif; -webkit-font-smoothing: antialiased; word-break: keep-all; }
.top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 14px; height: 56px; padding: 0 16px; background: rgba(246,244,241,.92); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
.top a { color: var(--sub); text-decoration: none; font-size: 14px; }
.wrap { max-width: 980px; margin: 0 auto; padding: 22px 18px 60px; display: grid; grid-template-columns: 1fr 380px; gap: 22px; align-items: start; }
h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -.02em; grid-column: 1 / -1; }
.lead { grid-column: 1 / -1; margin: -14px 0 0; color: var(--sub); font-size: 13.5px; }
.stage { position: sticky; top: 78px; background: #fff; border: 1px solid var(--line); border-radius: 22px; padding: 22px; text-align: center; }
.stage canvas { width: 100%; max-width: 420px; height: auto; display: block; margin: 0 auto; border-radius: 12px;
  background: repeating-conic-gradient(#F3F1EE 0 25%, #fff 0 50%) 0 0 / 16px 16px; }
.check { margin: 14px 0 0; font-size: 13px; font-weight: 600; padding: 9px 12px; border-radius: 10px; }
.check.ok { background: #EAF6EE; color: #2F6B45; } .check.bad { background: #FBEAEA; color: var(--warn); } .check.wait { background: var(--soft); color: var(--sub); }
.dl { margin-top: 12px; width: 100%; height: 50px; border: 0; border-radius: 12px; background: var(--ink); color: #fff; font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; }
.hint { font-size: 12px; color: var(--faint); line-height: 1.6; margin: 10px 0 0; }
.panel { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px; }
.grp { margin-bottom: 20px; } .grp:last-child { margin-bottom: 0; }
.grp > b { display: block; font-size: 13px; margin-bottom: 8px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { border: 1px solid var(--line); background: #fff; border-radius: 999px; padding: 8px 13px; font: inherit; font-size: 13px; cursor: pointer; color: var(--ink); }
.chip.on { background: var(--ink); color: #fff; border-color: var(--ink); }
.shapes { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.shape { border: 1px solid var(--line); background: #fff; border-radius: 14px; padding: 10px 4px 8px; cursor: pointer; font: inherit; font-size: 12px; color: var(--sub); display: flex; flex-direction: column; align-items: center; gap: 6px; }
.shape.on { border-color: var(--ink); box-shadow: 0 0 0 1px var(--ink); color: var(--ink); font-weight: 600; }
.shape svg { width: 40px; height: 40px; }
.colors { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.sw { width: 32px; height: 32px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px var(--line); cursor: pointer; padding: 0; }
.sw.on { box-shadow: 0 0 0 2px var(--ink); }
input[type=color] { width: 36px; height: 36px; border: 0; padding: 0; background: none; cursor: pointer; }
input[type=url] { width: 100%; border: 1px solid var(--line); border-radius: 10px; padding: 11px 12px; font: inherit; font-size: 14px; margin-top: 8px; }
.url { font-size: 12px; color: var(--faint); margin-top: 8px; word-break: break-all; }
.tog { display: flex; align-items: center; gap: 8px; font-size: 14px; cursor: pointer; }
.tog input { width: 18px; height: 18px; accent-color: var(--ink); }
.warnbox { grid-column: 1 / -1; background: #FFF6E5; color: #8A5A00; border-radius: 12px; padding: 10px 14px; font-size: 13px; }
@media (max-width: 820px) {
  .wrap { grid-template-columns: 1fr; }
  .stage { position: static; order: -1; }
}
</style>
</head>
<body>
<div class="top"><a href="dashboard.php?t=<?= $h($token) ?>">← 내 청첩장</a><b>QR코드 만들기</b></div>
<div class="wrap">
  <h1>♥ QR코드 만들기</h1>
  <p class="lead">종이 청첩장·식장 안내판·답례품 카드에 넣을 QR을 만들어요. 하트 모양도 휴대폰 카메라로 바로 읽혀요.</p>
  <?php if (!$published): ?><div class="warnbox">청첩장이 아직 발행 전이에요. QR은 지금 만들어 둘 수 있지만, 발행해야 하객이 열 수 있어요.</div><?php endif; ?>

  <div class="panel">
    <div class="grp"><b>무엇으로 연결할까요?</b>
      <div class="chips" id="targets">
        <?php foreach ($targets as $k => [$label, $url]): ?><button type="button" class="chip<?= $k === 'invite' ? ' on' : '' ?>" data-target="<?= $k ?>" data-url="<?= $h($url) ?>"><?= $h($label) ?></button><?php endforeach; ?>
      </div>
      <div class="url" id="urlShow"></div>
    </div>
    <div class="grp"><b>모양</b>
      <div class="shapes" id="shapes">
        <button type="button" class="shape" data-shape="square"><svg viewBox="0 0 40 40"><g fill="currentColor"><rect x="4" y="4" width="12" height="12"/><rect x="24" y="4" width="12" height="12"/><rect x="4" y="24" width="12" height="12"/><rect x="24" y="24" width="5" height="5"/><rect x="31" y="31" width="5" height="5"/></g></svg>기본</button>
        <button type="button" class="shape" data-shape="dot"><svg viewBox="0 0 40 40"><g fill="currentColor"><rect x="4" y="4" width="12" height="12" rx="4"/><rect x="24" y="4" width="12" height="12" rx="4"/><rect x="4" y="24" width="12" height="12" rx="4"/><circle cx="26.5" cy="26.5" r="2.6"/><circle cx="33.5" cy="33.5" r="2.6"/><circle cx="33.5" cy="26.5" r="2.6"/></g></svg>둥근 점</button>
        <button type="button" class="shape" data-shape="hearts"><svg viewBox="0 0 40 40"><g fill="currentColor"><rect x="4" y="4" width="12" height="12" rx="4"/><rect x="24" y="4" width="12" height="12" rx="4"/><rect x="4" y="24" width="12" height="12" rx="4"/><path d="M27 25.5c-1.2-1.6-4-1-4 1.2 0 1.8 2.2 3.2 4 4.6 1.8-1.4 4-2.8 4-4.6 0-2.2-2.8-2.8-4-1.2z"/><path d="M33 31.5c-1.2-1.6-4-1-4 1.2 0 1.8 2.2 3.2 4 4.6 1.8-1.4 4-2.8 4-4.6 0-2.2-2.8-2.8-4-1.2z"/></g></svg>하트 점</button>
        <button type="button" class="shape on" data-shape="heart"><svg viewBox="0 0 40 40"><path fill="none" stroke="currentColor" stroke-width="2.2" d="M20 35C8 26 3 20 3 13.5 3 8 7 4 12 4c3.4 0 6.2 2 8 5 1.8-3 4.6-5 8-5 5 0 9 4 9 9.5C37 20 32 26 20 35z"/><g fill="currentColor"><rect x="13" y="12" width="4" height="4"/><rect x="23" y="12" width="4" height="4"/><rect x="13" y="21" width="4" height="4"/><rect x="19" y="17" width="3" height="3"/></g></svg>하트 모양</button>
      </div>
    </div>
    <div class="grp"><b>색</b>
      <div class="colors" id="inks">
        <?php foreach (['#1B1A18' => '먹색', '#7A3B41' => '와인', '#C0566B' => '로즈', '#2F4A6D' => '네이비', '#5E7A63' => '세이지'] as $c => $n): ?>
          <button type="button" class="sw<?= $c === '#7A3B41' ? ' on' : '' ?>" style="background:<?= $c ?>" data-ink="<?= $c ?>" title="<?= $n ?>" aria-label="<?= $n ?>"></button>
        <?php endforeach; ?>
        <input type="color" id="inkCustom" value="#7A3B41" title="직접 고르기">
      </div>
      <p class="hint">너무 연한 색은 휴대폰이 못 읽을 수 있어요. 아래 인식 확인을 봐주세요.</p>
    </div>
    <div class="grp"><b>배경</b>
      <div class="chips" id="bgs">
        <button type="button" class="chip on" data-bg="#ffffff">흰색</button>
        <button type="button" class="chip" data-bg="#FBF7F0">크림</button>
        <button type="button" class="chip" data-bg="transparent">투명 (인쇄물 위에 올릴 때)</button>
      </div>
    </div>
    <div class="grp">
      <label class="tog"><input type="checkbox" id="logo" checked> 가운데에 하트 넣기</label>
    </div>
  </div>

  <div class="stage">
    <canvas id="cv" width="840" height="840" aria-label="QR코드 미리보기"></canvas>
    <p class="check wait" id="check">확인 중…</p>
    <button type="button" class="dl" id="dl">PNG로 저장</button>
    <p class="hint">인쇄할 땐 가로 2.5cm 이상으로 넣어주세요. 저장한 뒤 휴대폰 카메라로 한 번 찍어보면 가장 확실해요.</p>
  </div>
</div>
<script src="assets/qrcode.min.js?v=<?= $v('qrcode.min.js') ?>"></script>
<script src="assets/jsQR.min.js?v=<?= $v('jsQR.min.js') ?>"></script>
<script>
const SLUG = <?= json_encode($slug) ?>;
const S = { target: 'invite', url: document.querySelector('[data-target="invite"]').dataset.url, shape: 'heart', ink: '#7A3B41', bg: '#ffffff', logo: true };
const $ = id => document.getElementById(id);

// ---------- 그리기 ----------
// 하트 모양 판별 (가로 x: 왼쪽 -1.2 ~ 오른쪽 1.2, 세로 y: 위 1.25 ~ 아래 -1.05)
const inHeart = (x, y) => { const a = x * x + y * y - 1; return a * a * a - x * x * y * y * y <= 0; };
function heartPath(ctx, cx, cy, s) { // 크기 s 칸 안에 들어가는 작은 하트
    const w = s * .5;
    ctx.moveTo(cx, cy + w * .95);
    ctx.bezierCurveTo(cx - w * 1.9, cy - w * .1, cx - w * .95, cy - w * 1.35, cx, cy - w * .45);
    ctx.bezierCurveTo(cx + w * .95, cy - w * 1.35, cx + w * 1.9, cy - w * .1, cx, cy + w * .95);
}
function roundRect(ctx, x, y, w, h, r) { ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }
// 작은 난수 (같은 주소면 같은 무늬 - 저장할 때마다 모양이 바뀌지 않게)
function rng(seed) { let h = 2166136261; for (const c of seed) { h ^= c.charCodeAt(0); h = Math.imul(h, 16777619); } return () => { h ^= h << 13; h ^= h >>> 17; h ^= h << 5; return ((h >>> 0) % 10000) / 10000; }; }

function render(canvas, size) {
    const qr = qrcode(0, 'H'); // 오류 복구 최고 등급(약 30%) - 모양을 꾸며도 읽히게
    qr.addData(S.url || ' '); qr.make();
    const n = qr.getModuleCount();
    const ctx = canvas.getContext('2d');
    canvas.width = canvas.height = size;
    ctx.clearRect(0, 0, size, size);
    const heart = S.shape === 'heart';
    const quiet = heart ? 2 : 3;
    // 칸 크기와 QR 위치
    let m, ox, oy, H = null;
    if (heart) {
        // 하트 좌표 → 화면: 가로·세로 같은 비율, 하트 한가운데가 그림 가운데
        const W = 2.75, cyH = 0.13, sc = size / W; // 테두리까지 그림 안에 들어오게
        const toPx = (x, y) => [size / 2 + x * sc, size / 2 - (y - cyH) * sc];
        // 하트 안에 들어가는 가장 큰 정사각형을 찾고, 그 안에 QR(+여백)을 넣는다
        let best = { a: 0, c: 0 };
        for (let c = -0.35; c <= 0.36; c += 0.01) {
            let lo = 0, hi = 1;
            for (let k = 0; k < 22; k++) {
                const a = (lo + hi) / 2; let ok = true;
                for (let t = -1; t <= 1.0001 && ok; t += 0.05) ok = inHeart(t * a, c + a) && inHeart(t * a, c - a) && inHeart(a, c + t * a) && inHeart(-a, c + t * a);
                ok ? lo = a : hi = a;
            }
            if (lo > best.a) best = { a: lo, c };
        }
        m = best.a * 2 * sc / (n + quiet * 2);
        const [qx, qy] = toPx(-best.a, best.c + best.a);
        ox = qx + quiet * m; oy = qy + quiet * m;
        // 하트 윤곽선 (가운데에서 바깥으로 뻗어 경계를 찾음)
        const outline = grow => { const pts = []; for (let i = 0; i < 240; i++) { const th = i / 240 * Math.PI * 2, dx = Math.cos(th), dy = Math.sin(th); let lo = 0, hi = 2;
            for (let k = 0; k < 20; k++) { const r = (lo + hi) / 2; inHeart(dx * r, dy * r) ? lo = r : hi = r; } pts.push(toPx(dx * lo * grow, dy * lo * grow)); } return pts; };
        H = { sc, toPx, cyH, outline: outline(1.1) };
        if (S.bg !== 'transparent') { ctx.fillStyle = S.bg; ctx.beginPath(); H.outline.forEach(([x, y], i) => i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)); ctx.closePath(); ctx.fill(); }
    } else {
        m = size / (n + quiet * 2);
        ox = oy = quiet * m;
        if (S.bg !== 'transparent') { ctx.fillStyle = S.bg; ctx.beginPath(); roundRect(ctx, 0, 0, size, size, S.shape === 'square' ? 0 : size * .04); ctx.fill(); }
    }
    ctx.fillStyle = S.ink;
    const isFinder = (r, c) => (r < 7 && c < 7) || (r < 7 && c >= n - 7) || (r >= n - 7 && c < 7);
    // 작은 위치 표시(정렬 패턴 5×5) - 점 모양으로 그리면 휴대폰이 잘 못 읽어서 네모로 따로 그린다
    const ver = (n - 17) / 4;
    const APOS = [[], [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54], [6, 32, 58], [6, 34, 62], [6, 26, 46, 66], [6, 26, 48, 70]][ver] || [];
    const aligns = [];
    APOS.forEach(r => APOS.forEach(c => { if (!((r === 6 && c === 6) || (r === 6 && c === APOS[APOS.length - 1]) || (r === APOS[APOS.length - 1] && c === 6))) aligns.push([r, c]); }));
    const isAlign = (r, c) => aligns.some(([ar, ac]) => Math.abs(r - ar) <= 2 && Math.abs(c - ac) <= 2);
    // 가운데 로고 자리 (QR 칸 기준)
    const logoCells = S.logo ? Math.max(5, Math.round(n * .22) | 1) : 0;
    const l0 = (n - logoCells) / 2;
    const inLogo = (r, c) => S.logo && r >= l0 - .5 && r < l0 + logoCells - .5 && c >= l0 - .5 && c < l0 + logoCells - .5;
    const style = S.shape === 'heart' ? 'dot' : S.shape;
    const drawCell = (x, y, s, kind) => {
        if (kind === 'square') ctx.rect(x, y, s + .5, s + .5);
        else if (kind === 'hearts') heartPath(ctx, x + s / 2, y + s / 2 + s * .04, s * 1.02);
        else { ctx.moveTo(x + s * .97, y + s / 2); ctx.arc(x + s / 2, y + s / 2, s * .47, 0, Math.PI * 2); }
    };
    ctx.beginPath();
    for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) {
        if (!qr.isDark(r, c) || isFinder(r, c) || isAlign(r, c) || inLogo(r, c)) continue;
        drawCell(ox + c * m, oy + r * m, m, style);
    }
    ctx.fill();
    // 위치 표시(세 모서리 큰 네모) - 읽히는 데 가장 중요해서 모양은 단순하게
    [[0, 0], [0, n - 7], [n - 7, 0]].forEach(([r, c]) => {
        const x = ox + c * m, y = oy + r * m, round = style === 'square' ? 0 : m * 1.6;
        ctx.beginPath(); roundRect(ctx, x, y, 7 * m, 7 * m, round || .001); roundRect(ctx, x + m, y + m, 5 * m, 5 * m, round ? round * .6 : .001); ctx.fill('evenodd');
        ctx.beginPath(); roundRect(ctx, x + 2 * m, y + 2 * m, 3 * m, 3 * m, round ? round * .5 : .001); ctx.fill();
    });
    aligns.forEach(([r, c]) => {
        const x = ox + (c - 2) * m, y = oy + (r - 2) * m, round = style === 'square' ? .001 : m * 1.1;
        ctx.beginPath(); roundRect(ctx, x, y, 5 * m, 5 * m, round); roundRect(ctx, x + m, y + m, 3 * m, 3 * m, round * .5 || .001); ctx.fill('evenodd');
        ctx.beginPath(); ctx.rect(x + 2 * m, y + 2 * m, m, m); ctx.fill();
    });
    // 하트 모양: QR 바깥쪽 하트 안을 같은 색 점으로 채워 하트 윤곽이 보이게 (QR 둘레 여백 칸은 비움 - 읽히는 데 필요)
    if (heart) {
        const R = rng(S.url + S.ink);
        const kx0 = -Math.ceil(ox / m), kx1 = Math.ceil((size - ox) / m), ky0 = -Math.ceil(oy / m), ky1 = Math.ceil((size - oy) / m);
        ctx.beginPath();
        for (let ky = ky0; ky < ky1; ky++) for (let kx = kx0; kx < kx1; kx++) {
            if (kx >= -quiet && kx < n + quiet && ky >= -quiet && ky < n + quiet) continue;
            const px = ox + (kx + .5) * m, py = oy + (ky + .5) * m;
            const hx = (px - size / 2) / H.sc, hy = H.cyH - (py - size / 2) / H.sc;
            if (!inHeart(hx * 1.02, hy * 1.02) || R() < .45) continue;
            drawCell(ox + kx * m, oy + ky * m, m, style === 'hearts' ? 'hearts' : 'dot');
        }
        ctx.fill();
        // 가는 하트 테두리
        ctx.save(); ctx.strokeStyle = S.ink; ctx.lineWidth = Math.max(1.5, m * .35); ctx.globalAlpha = .9;
        ctx.beginPath(); H.outline.forEach(([x, y], i) => i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)); ctx.closePath();
        ctx.stroke(); ctx.restore();
    }
    // 가운데 하트 로고
    if (S.logo) {
        const s = logoCells * m, x = ox + l0 * m, y = oy + l0 * m;
        ctx.save(); ctx.fillStyle = S.bg === 'transparent' ? '#fff' : S.bg;
        ctx.beginPath(); roundRect(ctx, x + m * .2, y + m * .2, s - m * .4, s - m * .4, m * 1.2); ctx.fill();
        ctx.fillStyle = S.ink; ctx.beginPath(); heartPath(ctx, x + s / 2, y + s / 2 + s * .05, s * .62); ctx.fill();
        ctx.restore();
    }
}

// ---------- 인식 확인 (직접 스캔해 봄) ----------
function verify(canvas) {
    const size = 600, c = document.createElement('canvas'); c.width = c.height = size;
    const x = c.getContext('2d', { willReadFrequently: true });
    x.fillStyle = '#fff'; x.fillRect(0, 0, size, size); x.drawImage(canvas, 0, 0, size, size);
    const img = x.getImageData(0, 0, size, size);
    const r = jsQR(img.data, size, size, { inversionAttempts: 'dontInvert' });
    return !!(r && r.data === S.url);
}
let t = 0;
function update() {
    clearTimeout(t);
    t = setTimeout(() => {
        const cv = $('cv');
        render(cv, 840);
        const ok = S.url && verify(cv);
        const el = $('check');
        el.className = 'check ' + (ok ? 'ok' : 'bad');
        el.textContent = !S.url ? '연결할 주소를 넣어주세요' : ok ? '✓ 휴대폰으로 인식돼요' : '⚠ 인식이 어려울 수 있어요 — 더 진한 색이나 다른 모양을 골라보세요';
        $('dl').disabled = !S.url;
    }, 60);
}

// ---------- 선택 ----------
function showUrl() { $('urlShow').textContent = S.url ? '→ ' + S.url : ''; }
$('targets').addEventListener('click', e => {
    const b = e.target.closest('[data-target]'); if (!b) return;
    document.querySelectorAll('[data-target]').forEach(x => x.classList.toggle('on', x === b));
    S.target = b.dataset.target;
    S.url = b.dataset.url;
    showUrl(); update();
});
$('shapes').addEventListener('click', e => { const b = e.target.closest('[data-shape]'); if (!b) return; document.querySelectorAll('[data-shape]').forEach(x => x.classList.toggle('on', x === b)); S.shape = b.dataset.shape; update(); });
$('inks').addEventListener('click', e => { const b = e.target.closest('[data-ink]'); if (!b) return; document.querySelectorAll('[data-ink]').forEach(x => x.classList.toggle('on', x === b)); S.ink = b.dataset.ink; $('inkCustom').value = S.ink; update(); });
$('inkCustom').addEventListener('input', e => { document.querySelectorAll('[data-ink]').forEach(x => x.classList.remove('on')); S.ink = e.target.value; update(); });
$('bgs').addEventListener('click', e => { const b = e.target.closest('[data-bg]'); if (!b) return; document.querySelectorAll('[data-bg]').forEach(x => x.classList.toggle('on', x === b)); S.bg = b.dataset.bg; update(); });
$('logo').addEventListener('change', e => { S.logo = e.target.checked; update(); });
$('dl').addEventListener('click', () => {
    const c = document.createElement('canvas');
    render(c, 1200);
    c.toBlob(b => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = `loveday-qr-${SLUG}-${S.target}-${S.shape}.png`;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }, 'image/png');
});
showUrl(); update();
</script>
</body>
</html>
