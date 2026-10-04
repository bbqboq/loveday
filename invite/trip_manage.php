<?php
/**
 * trip_manage.php?t=편집토큰 - 신혼여행 라이브: 신랑신부가 여행지에서 휴대폰으로 소식을 올리는 화면
 *
 *  - 사진(최대 6장) + 한마디 + 장소(체크인)를 한 번에 올린다. 사진 없이 체크인만 해도 된다.
 *  - 📍 지금 위치: 휴대폰 위치로 도시 이름을 찾아 넣는다 (도시 수준으로만 저장 - trip_geo.php)
 *  - 사진은 올리기 전에 휴대폰에서 줄여서 보낸다 (데이터·시간 절약). 서버에서 한 번 더 다시 저장하면서 사진 속 위치정보도 지워진다.
 *  - 아래에 하객에게 보이는 모양(지도 + 소식)을 그대로 미리 보여주고, 올린 소식을 지울 수 있다.
 * 대시보드(내 청첩장)의 "신혼여행 라이브" 버튼, 에디터의 섹션 편집창 버튼으로 들어온다.
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
$id = (int) $invite['id'];
$blk = trip_block($invite);
$d = snap_design($invite) ?? [];
$tripFields = [];
foreach (($d['blocks'] ?? []) as $b) if (($b['id'] ?? '') === 'trip') $tripFields = (array) ($b['fields'] ?? []);
$names = trim($invite['groom_name'] . ' ♥ ' . $invite['bride_name'], ' ♥');
$published = $invite['status'] === 'published';
$tableOk = true;
try { $pdo->query('SELECT 1 FROM trip_posts LIMIT 1'); } catch (Throwable $e) { $tableOk = false; }
$csrf = csrf_token();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$v = fn($f) => @filemtime(__DIR__ . '/assets/' . $f) ?: 1;
// 섹션 상태 안내
if (!$blk) $state = ['warn', '청첩장에 "신혼여행 라이브" 섹션이 아직 없어요. 에디터 섹션 목록에서 켜고 저장해야 하객에게 보여요.'];
elseif (!$blk['enabled']) $state = ['warn', '에디터에서 "신혼여행 라이브" 섹션이 꺼져 있어요. 켜고 저장해야 하객에게 보여요. 소식은 미리 올려 둘 수 있어요.'];
elseif (!$published) $state = ['warn', '청첩장이 아직 발행 전이라 하객에게 안 보여요.'];
else $state = ['ok', '하객에게 보이는 중' . ($blk['delay'] ? " · 새 소식은 {$blk['delay']}시간 뒤에 공개돼요" : ' · 올리면 바로 공개돼요') . ($blk['startDate'] !== '' ? ' · ' . date('n월 j일', strtotime($blk['startDate'])) . '부터 보여요' : '')];
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="referrer" content="no-referrer">
<title>신혼여행 라이브 · LOVE DAY</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<link rel="stylesheet" href="assets/invite-blocks.css?v=<?= $v('invite-blocks.css') ?>">
<style>
:root { --bg:#F6F4F1; --ink:#1B1A18; --sub:#6F6A63; --faint:#A39D95; --line:#EAE6E0; --soft:#F1EEEA; --accent:#C7823A; --ok:#2F7D55;
  --p-bg:#FAF7F0; --p-ink:#2B2320; --p-accent:#7A3B41; --p-line:#E1D6C6; --p-muted:#8A7F72; }
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", sans-serif; -webkit-font-smoothing: antialiased; word-break: keep-all; }
.top { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 10px; height: 56px; padding: 0 16px; background: rgba(246,244,241,.92); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
.top a { color: var(--sub); text-decoration: none; font-size: 14px; }
.top b { font-size: 15px; }
.wrap { max-width: 560px; margin: 0 auto; padding: 18px 16px 60px; }
h1 { font-size: 22px; letter-spacing: -.02em; margin: 6px 0 4px; }
.sub { color: var(--sub); font-size: 13.5px; margin: 0 0 14px; }
.state { display: flex; gap: 8px; align-items: flex-start; padding: 11px 14px; border-radius: 12px; font-size: 13px; line-height: 1.6; margin-bottom: 16px; }
.state.ok { background: #EAF6EE; color: #2F6B45; } .state.warn { background: #FFF6E5; color: #8A5A00; }
.state a { color: inherit; font-weight: 700; }
.card { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 18px 16px; margin-bottom: 18px; }
.card h2 { font-size: 16px; margin: 0 0 12px; }
.pick { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 12px; }
.pick .th { position: relative; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: var(--soft); }
.pick .th img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pick .th button { position: absolute; top: 4px; right: 4px; width: 24px; height: 24px; border-radius: 50%; border: 0; background: rgba(0,0,0,.55); color: #fff; font-size: 13px; line-height: 24px; cursor: pointer; }
.pick label.add { aspect-ratio: 1; border-radius: 10px; border: 1.5px dashed #D6D0C8; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; color: var(--sub); font-size: 12.5px; cursor: pointer; background: #FCFBF9; }
.pick label.add span { font-size: 24px; line-height: 1; }
textarea, input[type=text] { width: 100%; border: 1px solid var(--line); border-radius: 12px; padding: 12px 13px; font: inherit; font-size: 15px; background: #FCFBF9; outline: none; }
textarea:focus, input[type=text]:focus { border-color: var(--ink); background: #fff; }
textarea { min-height: 78px; resize: vertical; }
.lb { display: block; font-size: 12.5px; color: var(--sub); margin: 12px 0 6px; }
.place { display: flex; gap: 6px; }
.place input { flex: 1; min-width: 0; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 12px; padding: 0 14px; height: 46px; font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.btn:disabled { opacity: .5; cursor: default; }
.btn.dark { background: var(--ink); color: #fff; border-color: var(--ink); width: 100%; height: 52px; font-size: 15.5px; margin-top: 16px; }
.geo { font-size: 12.5px; color: var(--sub); margin: 8px 2px 0; min-height: 18px; }
.geo.ok { color: var(--ok); } .geo.err { color: #B24A4A; }
.geo button { border: 0; background: none; color: var(--faint); text-decoration: underline; font: inherit; font-size: 12px; cursor: pointer; padding: 0 0 0 6px; }
.note { font-size: 12px; color: var(--faint); line-height: 1.6; margin: 10px 0 0; }
.prev { background: var(--p-bg); border-radius: 18px; padding: 18px 14px; border: 1px solid var(--line); }
.prev .ib-title { margin: 0 0 12px; text-align: center; font-size: 17px; font-weight: 600; }
.del { margin-top: 8px; border: 0; background: none; color: #B24A4A; font: inherit; font-size: 12.5px; cursor: pointer; padding: 4px 2px; }
.pend { display: inline-block; margin-top: 8px; margin-right: 8px; font-size: 12px; color: #8A5A00; background: #FFF6E5; border-radius: 999px; padding: 3px 9px; }
.bar { position: fixed; left: 0; right: 0; bottom: 0; height: 3px; background: transparent; z-index: 9; }
.bar i { display: block; height: 100%; width: 0; background: var(--accent); transition: width .2s; }
</style>
</head>
<body>
<div class="top"><a href="dashboard.php?t=<?= $h($token) ?>">← 내 청첩장</a><b style="margin-left:auto;margin-right:auto;transform:translateX(-36px)">신혼여행 라이브</b></div>
<div class="bar"><i id="bar"></i></div>
<div class="wrap">
  <h1>🧳 우리 지금 여기</h1>
  <p class="sub"><?= $h($names ?: '우리 청첩장') ?> · 여행지에서 사진과 들른 곳을 올리면 청첩장에 바로 쌓여요.</p>
  <div class="state <?= $state[0] ?>"><span><?= $state[0] === 'ok' ? '✅' : '⚠️' ?></span><span><?= $h($state[1]) ?><?php if ($state[0] === 'warn'): ?> <a href="editor-prototype-v3-overlay.html?t=<?= $h($token) ?>">에디터 열기 →</a><?php endif; ?></span></div>
  <?php if (!$tableOk): ?><div class="state warn">서버 준비가 아직 안 됐어요 (관리자: stage5_setup.sql 실행 필요).</div><?php endif; ?>

  <form class="card" id="form" autocomplete="off">
    <h2>새 소식 올리기</h2>
    <div class="pick" id="pick">
      <label class="add" id="addBtn"><span>＋</span>사진 추가<input type="file" id="files" accept="image/*" multiple hidden></label>
    </div>
    <textarea id="caption" maxlength="300" placeholder="한마디 (예: 에펠탑 앞에서 첫 저녁 🗼)"></textarea>
    <span class="lb">장소 (체크인) · 지도에 핀이 찍혀요</span>
    <div class="place">
      <input type="text" id="place" maxlength="80" placeholder="예: 파리">
      <button type="button" class="btn" id="findBtn">찾기</button>
      <button type="button" class="btn" id="hereBtn">📍 지금 위치</button>
    </div>
    <p class="geo" id="geo"></p>
    <button type="submit" class="btn dark" id="sendBtn">소식 올리기</button>
    <p class="note">사진은 한 번에 6장까지, 체크인만 올려도 돼요. 위치는 <b>도시 수준</b>으로만 저장되고, 사진 속 위치정보는 자동으로 지워져요.</p>
  </form>

  <h2 style="font-size:16px;margin:26px 2px 10px;">하객에게는 이렇게 보여요</h2>
  <div class="prev">
    <h4 class="ib-title"><?= $h($tripFields['title'] ?? '우리 지금 여기') ?></h4>
    <div class="ib-trip-map" id="map" hidden></div>
    <div class="ib-trip-feed" id="feed"><p class="ib-trip-empty">불러오는 중…</p></div>
  </div>
</div>
<script src="assets/ld-dialog.js?v=<?= $v('ld-dialog.js') ?>"></script>
<script src="assets/invite-blocks.js?v=<?= $v('invite-blocks.js') ?>"></script>
<script>
const T = <?= json_encode($token) ?>, CSRF = <?= json_encode($csrf) ?>, MAX = <?= TRIP_MAX_PHOTOS ?>;
const ORDER = <?= json_encode(($tripFields['order'] ?? 'new') === 'old' ? 'old' : 'new') ?>;
const $ = id => document.getElementById(id);
let files = [];          // { blob, url }
let geo = null;          // { place, lat, lng }

// ---------- 사진 고르기 (휴대폰에서 1920px·jpg로 줄여서 올림) ----------
async function shrink(file) {
    try {
        const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const r = Math.min(1, 1920 / Math.max(bmp.width, bmp.height));
        const c = document.createElement('canvas'); c.width = Math.round(bmp.width * r); c.height = Math.round(bmp.height * r);
        c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
        const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', .86));
        return blob || file;
    } catch (e) { return file; } // 못 줄이면 원본 그대로 (서버가 줄임)
}
function drawPick() {
    const pick = $('pick');
    pick.querySelectorAll('.th').forEach(x => x.remove());
    files.forEach((f, i) => {
        const d = document.createElement('div'); d.className = 'th';
        d.innerHTML = `<img src="${f.url}" alt=""><button type="button" aria-label="빼기">✕</button>`;
        d.querySelector('button').onclick = () => { URL.revokeObjectURL(f.url); files.splice(i, 1); drawPick(); };
        pick.insertBefore(d, $('addBtn'));
    });
    $('addBtn').style.display = files.length >= MAX ? 'none' : '';
}
$('files').addEventListener('change', async e => {
    const list = Array.from(e.target.files || []);
    e.target.value = '';
    if (files.length + list.length > MAX) LD.toast(`사진은 한 번에 ${MAX}장까지예요`);
    for (const f of list.slice(0, MAX - files.length)) {
        const blob = await shrink(f);
        files.push({ blob, url: URL.createObjectURL(blob) });
        drawPick();
    }
});

// ---------- 장소 (체크인) ----------
function setGeo(g, msg, cls) {
    geo = g;
    const el = $('geo');
    el.className = 'geo ' + (cls || '');
    el.innerHTML = msg + (g ? ' <button type="button" id="clrGeo">지우기</button>' : '');
    const c = document.getElementById('clrGeo'); if (c) c.onclick = () => { setGeo(null, ''); };
}
async function geoCall(qs) {
    const r = await fetch('trip_geo.php?t=' + encodeURIComponent(T) + '&' + qs, { cache: 'no-store' });
    return r.json();
}
$('findBtn').onclick = async () => {
    const q = $('place').value.trim();
    if (!q) { $('place').focus(); return; }
    setGeo(null, '찾는 중…');
    const j = await geoCall('q=' + encodeURIComponent(q)).catch(() => null);
    if (j && j.ok) { $('place').value = j.place || q; setGeo(j, `📍 지도에 표시돼요 · ${j.place || q}`, 'ok'); }
    else setGeo(null, (j && j.error) || '장소를 찾지 못했어요. 이름만 적어도 올릴 수 있어요 (지도 핀 없이).', 'err');
};
$('hereBtn').onclick = () => {
    if (!navigator.geolocation) { setGeo(null, '이 브라우저는 위치를 쓸 수 없어요. 도시 이름으로 찾아주세요.', 'err'); return; }
    setGeo(null, '지금 위치 확인 중… (위치 권한을 허용해주세요)');
    navigator.geolocation.getCurrentPosition(async p => {
        const j = await geoCall(`lat=${p.coords.latitude}&lng=${p.coords.longitude}`).catch(() => null);
        if (j && j.ok) { if (j.place) $('place').value = j.place; setGeo(j, `📍 ${j.place || '지금 위치'} · 도시 수준으로만 저장돼요`, 'ok'); }
        else setGeo(null, '위치 이름을 찾지 못했어요. 도시 이름으로 찾아주세요.', 'err');
    }, err => setGeo(null, err.code === 1 ? '위치 권한이 꺼져 있어요. 도시 이름으로 찾아주세요.' : '위치를 확인하지 못했어요. 도시 이름으로 찾아주세요.', 'err'),
    { enableHighAccuracy: false, timeout: 12000, maximumAge: 300000 });
};
$('place').addEventListener('input', () => { if (geo && $('place').value.trim() !== geo.place) setGeo(geo, `📍 지도 핀은 "${geo.place}" 위치 그대로예요 · 이름만 바뀌어요`, 'ok'); });

// ---------- 올리기 ----------
$('form').addEventListener('submit', e => {
    e.preventDefault();
    const caption = $('caption').value.trim(), place = $('place').value.trim();
    if (!files.length && !caption && !place) { LD.toast('사진, 한마디, 장소 중 하나는 넣어주세요'); return; }
    const fd = new FormData();
    fd.append('t', T); fd.append('csrf_token', CSRF); fd.append('action', 'add');
    fd.append('caption', caption); fd.append('place', place);
    if (geo) { fd.append('lat', geo.lat); fd.append('lng', geo.lng); }
    files.forEach((f, i) => fd.append('photos[]', f.blob, 'photo' + i + '.jpg'));
    const btn = $('sendBtn'); btn.disabled = true; btn.textContent = '올리는 중…';
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'trip_post.php');
    xhr.upload.onprogress = ev => { if (ev.lengthComputable) $('bar').style.width = (ev.loaded / ev.total * 100) + '%'; };
    xhr.onload = () => {
        btn.disabled = false; btn.textContent = '소식 올리기'; $('bar').style.width = '0';
        let j = null; try { j = JSON.parse(xhr.responseText); } catch (err) {}
        if (!j || !j.ok) { LD.alert('올리지 못했어요', { message: (j && j.error) || '잠시 후 다시 시도해주세요.' }); return; }
        files.forEach(f => URL.revokeObjectURL(f.url)); files = []; drawPick();
        $('caption').value = ''; $('place').value = ''; setGeo(null, '');
        LD.toast(j.delay ? `올렸어요 · ${j.delay}시간 뒤 하객에게 공개돼요` : '올렸어요 · 청첩장에 바로 보여요');
        load();
    };
    xhr.onerror = () => { btn.disabled = false; btn.textContent = '소식 올리기'; LD.alert('올리지 못했어요', { message: '인터넷 연결을 확인하고 다시 시도해주세요. 적은 내용은 그대로 남아 있어요.' }); };
    xhr.send(fd);
});

// ---------- 미리보기 + 지우기 ----------
async function load() {
    const j = await fetch('trip_feed.php?t=' + encodeURIComponent(T), { cache: 'no-store' }).then(r => r.ok ? r.json() : null).catch(() => null);
    const posts = (j && j.posts || []).map(p => Object.assign({}, p, { pending: p.visible_at > Date.now() }));
    $('feed').innerHTML = InviteBlocks.tripFeedHtml(posts, ORDER, false);
    InviteBlocks.drawTripMap($('map'), posts);
    // 소식마다 지우기 버튼 (화면에 그려진 순서 = ORDER)
    const list = ORDER === 'old' ? posts : posts.slice().reverse();
    $('feed').querySelectorAll('.ib-trip-post').forEach((el, i) => {
        const p = list[i]; if (!p) return;
        if (p.pending) el.insertAdjacentHTML('beforeend', `<span class="pend">🕒 ${new Date(p.visible_at).toLocaleString('ko-KR', { month: 'numeric', day: 'numeric', hour: 'numeric', minute: '2-digit' })} 공개</span>`);
        const b = document.createElement('button'); b.type = 'button'; b.className = 'del'; b.textContent = '이 소식 지우기';
        b.onclick = () => LD.confirm('이 소식을 지울까요?', { message: '사진도 함께 지워지고 되돌릴 수 없어요.', danger: true, ok: '지우기' }).then(ok => {
            if (!ok) return;
            const fd = new FormData(); fd.append('t', T); fd.append('csrf_token', CSRF); fd.append('action', 'delete'); fd.append('id', p.id);
            fetch('trip_post.php', { method: 'POST', body: fd }).then(r => r.json()).then(r => { if (r.ok) { LD.toast('지웠어요'); load(); } else LD.alert(r.error || '지우지 못했어요'); });
        });
        el.appendChild(b);
    });
}
load();
</script>
</body>
</html>
