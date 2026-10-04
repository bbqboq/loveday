<?php
/**
 * lottery_manage.php?t=편집토큰 - 식장 추첨 진행 화면 (신랑신부·사회자용, 휴대폰으로)
 *
 *  - 현장 코드 크게 보기 / 새로 만들기 (사회자가 "오늘의 코드는 ○○○○!" 하고 읽어 줌)
 *  - 응모 받기: 자동(예식 1시간 전 ~ 4시간 뒤) / 지금 열기 / 마감
 *  - 응모자 수·명단 (5초마다 새로고침)
 *  - 추첨: 경품 이름 + 인원 → 이름이 돌아가다 멈추는 연출 → 당첨자 발표. 당첨된 하객 화면에는 "당첨!"이 크게 뜬다
 *  - 여러 번(라운드) 추첨할 수 있고, 이미 당첨된 사람은 다음 추첨에서 빠진다
 * 내 청첩장 → "🎁 추첨 진행" 버튼, 에디터의 "행운의 추첨" 섹션 편집창 버튼으로 들어온다.
 * 사회자에게 이 주소를 보내면 편집 권한까지 넘어가니, 신랑신부 폰으로 진행하거나 PIN을 걸어두세요.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

$pdo = get_pdo();
$token = (string) ($_GET['t'] ?? '');
$invite = snap_owner_invite($pdo, $token);
if (!$invite) {
    http_response_code(403);
    exit('편집 링크가 올바르지 않거나 PIN 확인이 필요합니다. 내 청첩장에서 다시 들어와주세요.');
}
$tableOk = true;
try { $pdo->query('SELECT 1 FROM lottery_entries LIMIT 1'); } catch (Throwable $e) { $tableOk = false; }
$blk = lottery_block($invite);
$names = trim($invite['groom_name'] . ' ♥ ' . $invite['bride_name'], ' ♥');
$csrf = csrf_token();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = fn($f) => @filemtime(__DIR__ . '/assets/' . $f) ?: 1;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>추첨 진행 · LOVE DAY</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<style>
:root { --bg:var(--ui-page, #F6F4F1); --ink:#1B1A18; --sub:#6F6A63; --faint:#A39D95; --line:var(--ui-line, #EAE6E0); --soft:#F1EEEA; --accent:#C0566B; --ok:#2F7D55; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", sans-serif; -webkit-font-smoothing: antialiased; word-break: keep-all; }
.top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 12px; height: 56px; padding: 0 16px; background: rgba(246,244,241,.92); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
.top a { color: var(--sub); text-decoration: none; font-size: 14px; }
.wrap { max-width: 560px; margin: 0 auto; padding: 18px 16px 70px; }
h1 { font-size: 22px; margin: 6px 0 4px; letter-spacing: -.02em; }
.sub { color: var(--sub); font-size: 13.5px; margin: 0 0 14px; }
.state { padding: 11px 14px; border-radius: 12px; font-size: 13px; line-height: 1.6; margin-bottom: 14px; background: #FFF6E5; color: #8A5A00; }
.state a { color: inherit; font-weight: 700; }
.card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 16px; margin-bottom: 14px; }
.card h2 { font-size: 15px; margin: 0 0 12px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.code { font-size: 64px; font-weight: 800; letter-spacing: .18em; text-align: center; font-variant-numeric: tabular-nums; margin: 4px 0 6px; }
.small { font-size: 12.5px; color: var(--faint); text-align: center; margin: 0; line-height: 1.6; }
.seg { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.seg button { height: 44px; border: 1px solid var(--line); background: #fff; border-radius: 12px; font: inherit; font-size: 14px; cursor: pointer; }
.seg button.on { background: var(--ink); color: #fff; border-color: var(--ink); font-weight: 700; }
.phase { margin: 10px 0 0; font-size: 13px; text-align: center; font-weight: 600; }
.phase.open { color: var(--ok); } .phase.closed, .phase.before { color: var(--sub); }
.big { font-size: 44px; font-weight: 800; text-align: center; margin: 0; }
.big small { font-size: 16px; color: var(--sub); font-weight: 500; margin-left: 4px; }
.row { display: flex; gap: 8px; }
input[type=text], input[type=number] { width: 100%; height: 48px; border: 1px solid var(--line); border-radius: 12px; padding: 0 13px; font: inherit; font-size: 15px; background: #FCFBF9; }
input[type=number] { width: 96px; flex: none; text-align: center; }
.lb { display: block; font-size: 12.5px; color: var(--sub); margin: 0 0 6px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }
.chips button { border: 1px solid var(--line); background: #FCFBF9; border-radius: 999px; padding: 6px 11px; font: inherit; font-size: 12.5px; cursor: pointer; }
.draw { width: 100%; height: 58px; margin-top: 12px; border: 0; border-radius: 14px; background: var(--accent); color: #fff; font: inherit; font-size: 18px; font-weight: 800; cursor: pointer; }
.draw:disabled { opacity: .5; }
.btn-line { border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: 7px 11px; font: inherit; font-size: 12.5px; cursor: pointer; color: var(--sub); }
.btn-line.danger { color: #B24A4A; border-color: #EFCACA; }
.list { max-height: 280px; overflow: auto; margin: 0; padding: 0; list-style: none; font-size: 13.5px; }
.list li { display: flex; justify-content: space-between; gap: 8px; padding: 8px 2px; border-bottom: 1px solid var(--soft); }
.list li span { color: var(--faint); font-variant-numeric: tabular-nums; }
.list li.won { color: var(--accent); font-weight: 700; }
.round { padding: 10px 0; border-bottom: 1px solid var(--soft); font-size: 14px; }
.round:last-child { border-bottom: 0; }
.round b { display: block; margin-bottom: 4px; }
.round span { color: var(--sub); }
/* 추첨 연출 (전체 화면) */
.show { position: fixed; inset: 0; z-index: 50; background: #1B1A18; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 30px; text-align: center; }
.show[hidden] { display: none; }
.show .pz { font-size: 18px; opacity: .8; margin-bottom: 18px; }
.show .spin { font-size: 44px; font-weight: 800; min-height: 60px; }
.show .spin small { display: block; font-size: 18px; opacity: .7; letter-spacing: .15em; margin-top: 6px; }
.show .res { display: flex; flex-direction: column; gap: 10px; margin-top: 10px; max-height: 60vh; overflow: auto; }
.show .res div { font-size: 30px; font-weight: 800; animation: pop .4s cubic-bezier(.2,1.4,.4,1) both; }
.show .res div small { font-size: 16px; opacity: .7; margin-left: 8px; letter-spacing: .1em; }
.show button { margin-top: 26px; padding: 14px 30px; border: 0; border-radius: 12px; background: #fff; color: #1B1A18; font: inherit; font-weight: 700; font-size: 15px; cursor: pointer; }
@keyframes pop { from { transform: scale(.5); opacity: 0; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
<div class="top"><a href="dashboard.php?t=<?= $h($token) ?>">← 내 청첩장</a><b>추첨 진행</b></div>
<div class="wrap">
  <h1>🎁 행운의 추첨</h1>
  <p class="sub"><?= $h($names ?: '우리 청첩장') ?> · 예식 중에 이 화면으로 추첨을 진행해요.</p>
  <?php if (!$tableOk): ?><div class="state">서버 준비가 아직 안 됐어요 (관리자: stage5_setup.sql 실행 필요).</div><?php endif; ?>
  <?php if (!$blk || !$blk['enabled']): ?><div class="state">청첩장에 "행운의 추첨" 섹션이 꺼져 있어요. 에디터에서 켜고 저장해야 하객이 응모할 수 있어요. <a href="editor-prototype-v3-overlay.html?t=<?= $h($token) ?>">에디터 열기 →</a></div><?php endif; ?>

  <div class="card" id="codeCard" hidden>
    <h2>현장 코드 <button type="button" class="btn-line" id="newCode">새 코드</button></h2>
    <div class="code" id="code">----</div>
    <p class="small">사회자가 "오늘의 코드는 ○○○○!" 하고 알려주세요.<br>하객은 청첩장의 추첨 칸에 이름과 코드를 넣어 응모해요.</p>
  </div>
  <div class="card" id="snapCard" hidden>
    <h2>게스트스냅 참여자 추첨</h2>
    <p class="small" style="text-align:left">게스트스냅에 사진을 올린 하객이 자동으로 응모돼요. 추첨 직전에 "지금 식장 사진을 올려주세요!"라고 안내하면 좋아요.</p>
  </div>

  <div class="card">
    <h2>응모 받기</h2>
    <div class="seg" id="seg">
      <button type="button" data-v="auto">자동</button><button type="button" data-v="open">지금 열기</button><button type="button" data-v="closed">마감</button>
    </div>
    <p class="phase" id="phase"></p>
  </div>

  <div class="card">
    <h2>응모자 <button type="button" class="btn-line" id="toggleList">명단 보기</button></h2>
    <p class="big" id="count">0<small>명</small></p>
    <ul class="list" id="list" hidden></ul>
  </div>

  <div class="card">
    <h2>추첨하기</h2>
    <span class="lb">경품</span>
    <input type="text" id="prize" maxlength="60" placeholder="예: 커피 쿠폰">
    <div class="chips" id="prizeChips"></div>
    <span class="lb" style="margin-top:12px">당첨 인원</span>
    <div class="row"><input type="number" id="count2" min="1" max="30" value="1"><span class="small" style="text-align:left;align-self:center">이미 당첨된 분은 빼고 뽑아요</span></div>
    <button type="button" class="draw" id="drawBtn">🎲 추첨하기</button>
  </div>

  <div class="card">
    <h2>당첨자 <button type="button" class="btn-line danger" id="reset">기록 모두 지우기</button></h2>
    <div id="rounds"><p class="small">아직 추첨하지 않았어요</p></div>
  </div>
</div>

<div class="show" id="show" hidden>
  <div class="pz" id="showPrize"></div>
  <div class="spin" id="spin"></div>
  <div class="res" id="res"></div>
  <button type="button" id="closeShow" hidden>닫기</button>
</div>

<script src="assets/ld-dialog.js?v=<?= $v('ld-dialog.js') ?>"></script>
<script>
const T = <?= json_encode($token) ?>, CSRF = <?= json_encode($csrf) ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
let D = null, listOpen = false, busy = false;
const fmt = ms => { const d = new Date(ms); return `${d.getMonth() + 1}/${d.getDate()} ${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`; };

async function api(a, extra) {
    const body = new URLSearchParams(Object.assign({ t: T, a, csrf_token: CSRF }, extra || {}));
    const r = await fetch('lottery.php', { method: 'POST', body, credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    if (!j || !j.ok) throw new Error((j && j.error) || '처리하지 못했어요');
    return j;
}
function paint() {
    if (!D) return;
    $('codeCard').hidden = D.method !== 'code';
    $('snapCard').hidden = D.method !== 'snap';
    $('code').textContent = D.code;
    document.querySelectorAll('#seg button').forEach(b => b.classList.toggle('on', b.dataset.v === D.manual));
    const ph = $('phase');
    ph.className = 'phase ' + D.phase;
    ph.textContent = D.phase === 'open' ? '● 지금 응모 받는 중' + (D.manual === 'auto' && D.to ? ` (${fmt(D.to)}까지)` : '')
        : D.phase === 'before' ? `응모 전 · ${D.from ? fmt(D.from) + '부터 자동으로 열려요' : ''}` : '응모 마감';
    $('count').innerHTML = `${D.entries.length}<small>명</small>`;
    $('list').innerHTML = D.entries.map(e => `<li class="${e.won ? 'won' : ''}">${esc(e.name)}${e.won ? ' 🎉' : ''}<span>#${esc(e.no)}${e.source === 'snap' ? ' · 📷' : ''}</span></li>`).join('') || '<li>아직 응모한 하객이 없어요</li>';
    $('rounds').innerHTML = D.rounds.length ? D.rounds.slice().reverse().map(r => `<div class="round"><b>${r.round}회 · ${esc(r.prize)}</b><span>${r.winners.map(w => `${esc(w.name)} #${esc(w.no)}`).join(' · ')}</span></div>`).join('') : '<p class="small">아직 추첨하지 않았어요</p>';
    const chips = String(D.prizes || '').split('\n').map(x => x.trim()).filter(Boolean);
    $('prizeChips').innerHTML = chips.map(c => `<button type="button" data-p="${esc(c)}">${esc(c)}</button>`).join('');
    const left = D.entries.filter(e => !e.won).length;
    $('drawBtn').disabled = !left;
    $('drawBtn').textContent = left ? `🎲 추첨하기 (${left}명 중)` : '추첨할 응모자가 없어요';
}
async function load() {
    if (busy) return;
    const r = await fetch('lottery.php?t=' + encodeURIComponent(T) + '&a=admin', { cache: 'no-store', credentials: 'same-origin' }).then(r => r.json()).catch(() => null);
    if (r && r.ok) { D = r; paint(); }
}
$('seg').addEventListener('click', e => { const b = e.target.closest('[data-v]'); if (!b) return; api('manual', { value: b.dataset.v }).then(j => { D = j; paint(); }).catch(err => LD.alert(err.message)); });
$('newCode').onclick = () => LD.confirm('현장 코드를 새로 만들까요?', { message: '이미 알려준 코드는 더 이상 안 먹혀요. (이미 응모한 분은 그대로예요)', ok: '새로 만들기' }).then(ok => ok && api('newcode').then(j => { D = j; paint(); }));
$('toggleList').onclick = () => { listOpen = !listOpen; $('list').hidden = !listOpen; $('toggleList').textContent = listOpen ? '명단 접기' : '명단 보기'; };
$('prizeChips').addEventListener('click', e => { const b = e.target.closest('[data-p]'); if (!b) return; const m = b.dataset.p.match(/^(.*?)\s*\((\d+)\s*명\)\s*$/); $('prize').value = m ? m[1] : b.dataset.p; if (m) $('count2').value = m[2]; });
$('reset').onclick = () => LD.confirm('응모·당첨 기록을 모두 지울까요?', { message: '리허설 뒤에 쓰세요. 되돌릴 수 없어요.', danger: true, ok: '모두 지우기' }).then(ok => ok && api('reset').then(j => { D = j; paint(); LD.toast('지웠어요'); }));

// ---------- 추첨 연출 ----------
$('drawBtn').onclick = async () => {
    const prize = $('prize').value.trim() || '선물', count = Math.max(1, Math.min(30, +$('count2').value || 1));
    const pool = D.entries.filter(e => !e.won);
    busy = true;
    $('show').hidden = false; $('res').innerHTML = ''; $('closeShow').hidden = true;
    $('showPrize').textContent = `🎁 ${prize} · ${Math.min(count, pool.length)}명`;
    let t = 0, i = 0;
    const spin = setInterval(() => { const e = pool[Math.floor(Math.random() * pool.length)]; $('spin').innerHTML = `${esc(e.name)}<small>#${esc(e.no)}</small>`; }, 70);
    let result;
    try { result = await api('draw', { count, prize }); } catch (err) { clearInterval(spin); $('show').hidden = true; busy = false; LD.alert(err.message); return; }
    await new Promise(r => setTimeout(r, 2600)); // 두근두근
    clearInterval(spin);
    const r = result.rounds[result.rounds.length - 1];
    $('spin').innerHTML = '🎉 당첨을 축하해요!';
    r.winners.forEach((w, k) => setTimeout(() => $('res').insertAdjacentHTML('beforeend', `<div>${esc(w.name)}<small>#${esc(w.no)}</small></div>`), 350 * k));
    setTimeout(() => { $('closeShow').hidden = false; }, 350 * r.winners.length + 300);
    try { navigator.vibrate && navigator.vibrate(200); } catch (e) {}
    D = result; paint(); busy = false;
};
$('closeShow').onclick = () => { $('show').hidden = true; };

load();
setInterval(() => { if (!document.hidden) load(); }, 5000);
</script>
</body>
</html>
