<?php
/**
 * admin_stickers.php - 관리자: 스티커 (고객 에디터 😀 스티커 창)
 *
 *  - 테마 순서 · 켜고 끄기 (▲▼, 스위치)
 *  - 테마 안 그림을 하나씩 숨기기 (그림을 누르면 숨김 ↔ 보임)
 *  - 디자인마다 [추천] 탭에 먼저 보일 테마 2개
 *  바꾸면 바로 저장 (sticker_settings.php → uploads/site/sticker_settings.json). 에디터는 새로 열 때 읽음.
 *  그림 목록은 assets/stickers/<테마>/*.svg · vintage/*.webp 폴더에서 저절로 읽는다.
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/sticker_settings.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'save') {
        $cfg = json_decode((string) ($_POST['cfg'] ?? ''), true);
        if (!is_array($cfg)) { echo json_encode(['ok' => false, 'error' => '값이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
        $ok = sticker_settings_save($cfg);
    } elseif ($act === 'reset') {
        $ok = sticker_settings_save([]);
    } else {
        echo json_encode(['ok' => false, 'error' => '알 수 없는 요청이에요.'], JSON_UNESCAPED_UNICODE); exit;
    }
    echo json_encode($ok ? ['ok' => true, 'cfg' => sticker_settings_get()] : ['ok' => false, 'error' => '저장하지 못했어요. 잠시 뒤 다시 해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// 폴더에 있는 그림 (파일 이름 = 스티커 이름)
$files = [];
foreach (array_keys(STICKER_THEMES) as $id) {
    $ext = $id === 'vintage' ? 'webp' : 'svg';
    $list = [];
    foreach (glob(__DIR__ . "/assets/stickers/$id/*.$ext") ?: [] as $p) {
        $k = basename($p, ".$ext");
        if (preg_match('/^[a-z0-9-]{1,40}$/', $k)) $list[] = ($id === 'vintage' ? 'v:' : "a:$id/") . $k;
    }
    $files[$id] = $list;
}
// 디자인 목록 (presets/폴더/preset.json)
$designs = [];
foreach (glob(__DIR__ . '/presets/*/preset.json') ?: [] as $p) {
    if (filesize($p) > 65536) continue;
    $j = json_decode((string) file_get_contents($p), true);
    if (!is_array($j) || !preg_match('/^[a-z0-9_-]{1,40}$/', (string) ($j['id'] ?? ''))) continue;
    $own = array_values(array_intersect((array) ($j['stickerThemes'] ?? []), array_keys(STICKER_THEMES)));
    $designs[] = ['id' => $j['id'], 'no' => (int) ($j['no'] ?? 99), 'name' => (string) ($j['label'] ?? $j['name'] ?? $j['id']), 'bg' => (string) ($j['palette']['bg'] ?? ''), 'def' => $own ?: (STICKER_REC_DEFAULT[$j['id']] ?? [])];
}
usort($designs, fn($a, $b) => $a['no'] <=> $b['no']);
$cfg = sticker_settings_get();
$readonly = function_exists('admin_page_readonly') && admin_page_readonly();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>스티커 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.stw { max-width: 920px; margin: 0 auto; padding: 24px 20px 80px; font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; color: #2B2320; box-sizing: border-box; }
.stw * { box-sizing: border-box; }
.stw h2 { font-size: 20px; margin: 0 0 6px; }
.stw .lead { font-size: 13px; color: #8A847B; margin: 0 0 22px; line-height: 1.7; }
.stw .card { background: #fff; border: 1px solid #ECE6DD; border-radius: 16px; padding: 18px 18px 8px; margin-bottom: 18px; }
.stw .card h3 { font-size: 15px; margin: 0 0 4px; display: flex; align-items: center; gap: 8px; }
.stw .card h3 small { font-size: 12px; color: #8A847B; font-weight: 500; }
.stw .card > p { font-size: 12.5px; color: #8A847B; margin: 0 0 12px; line-height: 1.6; }
.st-row { border-top: 1px solid #F1ECE5; padding: 10px 0; }
.st-row:first-of-type { border-top: 0; }
.st-line { display: flex; align-items: center; gap: 10px; }
.st-mv { display: flex; flex-direction: column; gap: 2px; flex: none; }
.st-mv button { width: 26px; height: 18px; border: 1px solid #E6E0D8; background: #fff; border-radius: 6px; font-size: 9px; color: #8A847B; cursor: pointer; padding: 0; line-height: 1; }
.st-mv button:disabled { opacity: .3; cursor: default; }
.st-ic { width: 40px; height: 40px; border-radius: 10px; background: #F6F3EE; display: grid; place-items: center; flex: none; }
.st-ic img { width: 30px; height: 30px; object-fit: contain; }
.st-nm { flex: 1; min-width: 0; }
.st-nm b { display: block; font-size: 14px; }
.st-nm small { font-size: 12px; color: #8A847B; }
.st-nm small em { font-style: normal; color: #B0563F; font-weight: 600; }
.st-open { border: 1px solid #E6E0D8; background: #fff; border-radius: 999px; height: 30px; padding: 0 12px; font: inherit; font-size: 12px; font-weight: 600; color: #5A524A; cursor: pointer; white-space: nowrap; }
.st-open.on { background: #2B2320; color: #fff; border-color: #2B2320; }
.st-sw { position: relative; width: 44px; height: 26px; flex: none; }
.st-sw input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; z-index: 1; }
.st-sw i { position: absolute; inset: 0; border-radius: 13px; background: #D9D3CB; transition: background .2s; }
.st-sw i::after { content: ''; position: absolute; left: 3px; top: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.st-sw input:checked + i { background: var(--ui-point, #B08A5A); }
.st-sw input:checked + i::after { transform: translateX(18px); }
.st-row.off .st-ic, .st-row.off .st-nm b { opacity: .45; }
.st-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(64px, 1fr)); gap: 8px; margin: 12px 0 4px 36px; }
.st-grid[hidden] { display: none; }
.st-it { position: relative; aspect-ratio: 1; border: 1px solid #ECE6DD; border-radius: 12px; background: #fff; cursor: pointer; padding: 0; display: grid; place-items: center; }
.st-it img { width: 78%; height: 78%; object-fit: contain; transition: opacity .15s, filter .15s; }
.st-it.v { background: #F7F1E6; }
.st-it.hid img { opacity: .25; filter: grayscale(1); }
.st-it.hid::after { content: '숨김'; position: absolute; left: 50%; bottom: 4px; transform: translateX(-50%); font-size: 10px; font-weight: 700; color: #fff; background: #8A7F72; border-radius: 5px; padding: 1px 5px; white-space: nowrap; }
.st-it:hover { border-color: #CDBFAE; }
.st-hint { grid-column: 1 / -1; font-size: 12px; color: #8A847B; margin: 0 0 2px; }
.rc-row { display: flex; align-items: center; gap: 10px; border-top: 1px solid #F1ECE5; padding: 9px 0; flex-wrap: wrap; }
.rc-row:first-of-type { border-top: 0; }
.rc-sw { width: 18px; height: 18px; border-radius: 50%; border: 1px solid rgba(0,0,0,.12); flex: none; }
.rc-nm { flex: 1; min-width: 120px; font-size: 13.5px; font-weight: 600; }
.rc-nm small { display: block; font-size: 11.5px; color: #8A847B; font-weight: 500; }
.rc-row select { height: 34px; border: 1px solid #E6E0D8; border-radius: 10px; background: #fff; font: inherit; font-size: 13px; padding: 0 8px; color: #2B2320; }
.rc-row.own .rc-nm::after { content: '정함'; margin-left: 6px; font-size: 10px; font-weight: 700; color: #fff; background: var(--ui-point, #B08A5A); border-radius: 5px; padding: 1px 5px; vertical-align: 2px; }
.st-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }
.st-foot button { border: 1px solid #E6E0D8; background: #fff; border-radius: 10px; height: 36px; padding: 0 14px; font: inherit; font-size: 13px; color: #8A847B; cursor: pointer; }
.st-toast { position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%) translateY(10px); background: rgba(43,35,32,.94); color: #fff; font-size: 13px; font-weight: 600; padding: 10px 16px; border-radius: 12px; opacity: 0; transition: opacity .25s, transform .25s; pointer-events: none; z-index: 90; }
.st-toast.on { opacity: 1; transform: translateX(-50%); }
.st-toast.err { background: #A5493A; }
.stw.ro .st-mv button, .stw.ro .st-sw input, .stw.ro .st-it, .stw.ro select, .stw.ro .st-foot button { pointer-events: none; }
@media (max-width: 640px) {
    .stw { padding: 18px 14px 70px; }
    .st-grid { margin-left: 0; grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .st-open { padding: 0 9px; }
    .rc-row select { flex: 1; min-width: 0; }
}
</style>
<?= function_exists('site_colors_link') ? site_colors_link() : '' ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
<?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('stickers', '스티커'); ?>
<div class="stw<?= $readonly ? ' ro' : '' ?>" id="stw">
    <h2>스티커</h2>
    <p class="lead">고객 에디터의 😀 스티커 창에 보일 테마와 그림을 정해요. 바꾸면 <b>바로 저장</b>되고, 고객이 에디터를 새로 열 때부터 보여요.<br>그림 파일은 <code>assets/stickers/테마 이름/</code> 폴더에 있어요.</p>

    <div class="card">
        <h3>테마 순서 · 켜고 끄기 <small>[테마] 탭 칩 순서</small></h3>
        <p>스위치를 끄면 그 테마가 고객 스티커 창에서 빠져요. [그림 보기]를 눌러 그림을 누르면 그 그림만 숨겨요 (한 번 더 누르면 다시 보여요).</p>
        <div id="themes"></div>
    </div>

    <div class="card">
        <h3>디자인마다 추천 테마 <small>[추천] 탭에 먼저 보일 2개</small></h3>
        <p>정하지 않은 디자인은 기본 추천을 써요. 꺼 둔 테마는 추천에서도 빠져요.</p>
        <div id="recs"></div>
    </div>

    <div class="st-foot"><button type="button" id="stReset">처음 설정으로 되돌리기</button></div>
</div>
<div class="st-toast" id="stToast"></div>
<script>
const NAMES = <?= json_encode(STICKER_THEMES, JSON_UNESCAPED_UNICODE) ?>;
const ICON = { romantic: 'a:romantic/heart-bow', cosmos: 'a:cosmos/moon', rain: 'a:rain/umbrella', garden: 'a:garden/daisy', classic: 'a:classic/diamond', party: 'a:party/balloons3', webtoon: 'a:webtoon/sfx-dugeun', season: 'a:season/cherry', vintage: 'v:cupid-heart' }; // 테마 칸 앞 그림
const FILES = <?= json_encode($files, JSON_UNESCAPED_UNICODE) ?>;
const DESIGNS = <?= json_encode($designs, JSON_UNESCAPED_UNICODE) ?>;
const CSRF = <?= json_encode($csrf) ?>;
const RO = <?= $readonly ? 'true' : 'false' ?>;
let C = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE) ?>;
if (Array.isArray(C.rec)) C.rec = {};
const open = new Set();
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const url = k => k[0] === 'v' ? 'assets/stickers/vintage/' + k.slice(2) + '.webp' : 'assets/stickers/' + k.slice(2) + '.svg';
const toast = (t, err) => { const el = document.getElementById('stToast'); el.textContent = t; el.classList.toggle('err', !!err); el.classList.add('on'); clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove('on'), 1800); };
let saveT = 0;
function save(msg) {
    if (RO) return;
    clearTimeout(saveT);
    saveT = setTimeout(() => {
        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'save'); fd.append('cfg', JSON.stringify(C));
        fetch('admin_stickers.php', { method: 'POST', body: fd }).then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error || '저장하지 못했어요.'); toast(msg || '저장했어요'); })
            .catch(e => toast(e.message || '저장하지 못했어요.', true));
    }, 400);
}
function drawThemes() {
    const box = document.getElementById('themes');
    box.innerHTML = C.order.map((id, i) => {
        const fs = FILES[id] || [], on = !C.off.includes(id), hid = fs.filter(k => C.hide.includes(k)).length;
        return `<div class="st-row${on ? '' : ' off'}" data-id="${id}">
            <div class="st-line">
                <div class="st-mv"><button type="button" data-mv="-1" ${i ? '' : 'disabled'} aria-label="위로">▲</button><button type="button" data-mv="1" ${i < C.order.length - 1 ? '' : 'disabled'} aria-label="아래로">▼</button></div>
                <span class="st-ic">${fs.length ? `<img src="${url(fs.includes(ICON[id]) ? ICON[id] : fs[0])}" alt="">` : ''}</span>
                <span class="st-nm"><b>${esc(NAMES[id] || id)}</b><small>그림 ${fs.length}개${hid ? ` · <em>숨김 ${hid}</em>` : ''}${id === 'vintage' ? ' · 다른 테마 안의 빈티지 그림도 같이 숨겨져요' : ''}</small></span>
                <button type="button" class="st-open${open.has(id) ? ' on' : ''}" data-open>그림 보기 ${open.has(id) ? '▴' : '▾'}</button>
                <label class="st-sw" title="${on ? '켜짐 - 고객에게 보여요' : '꺼짐 - 고객에게 안 보여요'}"><input type="checkbox" ${on ? 'checked' : ''} data-on aria-label="${esc(NAMES[id])} 보이기"><i></i></label>
            </div>
            <div class="st-grid" ${open.has(id) ? '' : 'hidden'}><p class="st-hint">누르면 숨김 ↔ 보임</p>${fs.map(k => `<button type="button" class="st-it${k[0] === 'v' ? ' v' : ''}${C.hide.includes(k) ? ' hid' : ''}" data-k="${k}" title="${esc(k.replace(/^[av]:/, ''))}"><img src="${url(k)}" alt="" loading="lazy"></button>`).join('')}</div>
        </div>`;
    }).join('');
}
function drawRecs() {
    const ids = Object.keys(NAMES).filter(id => id !== 'vintage');
    const opt = (cur, def) => `<option value="">기본 (${esc(NAMES[def] || '없음')})</option>` + ids.map(id => `<option value="${id}" ${cur === id ? 'selected' : ''}>${esc(NAMES[id])}${C.off.includes(id) ? ' (꺼짐)' : ''}</option>`).join('');
    document.getElementById('recs').innerHTML = DESIGNS.map(d => {
        const own = C.rec[d.id] || [], def = d.def || [];
        return `<div class="rc-row${own.length ? ' own' : ''}" data-d="${esc(d.id)}"><span class="rc-sw" style="background:${/^#[0-9a-fA-F]{3,8}$/.test(d.bg) ? d.bg : '#F6F3EE'}"></span><span class="rc-nm">${esc(d.name)}<small>기본: ${def.map(x => esc(NAMES[x] || x)).join(' · ') || '바탕 밝기에 따라'}</small></span>
            <select data-n="0" aria-label="첫째 추천">${opt(own[0] || '', def[0])}</select><select data-n="1" aria-label="둘째 추천">${opt(own[1] || '', def[1])}</select></div>`;
    }).join('');
}
document.getElementById('themes').addEventListener('click', e => {
    const row = e.target.closest('.st-row'); if (!row) return; const id = row.dataset.id;
    const mv = e.target.closest('[data-mv]');
    if (mv) { const i = C.order.indexOf(id), j = i + +mv.dataset.mv; if (j < 0 || j >= C.order.length) return; [C.order[i], C.order[j]] = [C.order[j], C.order[i]]; drawThemes(); save('순서를 저장했어요'); return; }
    if (e.target.closest('[data-open]')) { open.has(id) ? open.delete(id) : open.add(id); drawThemes(); return; }
    const it = e.target.closest('[data-k]');
    if (it) { const k = it.dataset.k; C.hide = C.hide.includes(k) ? C.hide.filter(x => x !== k) : C.hide.concat(k); it.classList.toggle('hid', C.hide.includes(k)); const sm = row.querySelector('.st-nm small'); const hid = (FILES[id] || []).filter(x => C.hide.includes(x)).length; sm.innerHTML = sm.innerHTML.replace(/ · <em>숨김 \d+<\/em>/, '').replace(/^(그림 \d+개)/, `$1${hid ? ` · <em>숨김 ${hid}</em>` : ''}`); save(C.hide.includes(k) ? '그림을 숨겼어요' : '그림을 다시 보이게 했어요'); }
});
document.getElementById('themes').addEventListener('change', e => {
    const cb = e.target.closest('[data-on]'); if (!cb) return; const id = cb.closest('.st-row').dataset.id;
    const artOn = Object.keys(NAMES).filter(x => x !== 'vintage' && !C.off.includes(x) && x !== id);
    if (!cb.checked && id !== 'vintage' && !artOn.length) { cb.checked = true; toast('그림 테마는 하나 이상 켜 두어야 해요', true); return; }
    C.off = cb.checked ? C.off.filter(x => x !== id) : C.off.concat(id);
    drawThemes(); drawRecs(); save(cb.checked ? '테마를 켰어요' : '테마를 껐어요');
});
document.getElementById('recs').addEventListener('change', e => {
    const s = e.target.closest('select'); if (!s) return; const row = s.closest('.rc-row'), d = row.dataset.d;
    const v = [...row.querySelectorAll('select')].map(x => x.value).filter(Boolean);
    const l = [...new Set(v)];
    if (l.length) C.rec[d] = l; else delete C.rec[d];
    row.classList.toggle('own', !!l.length);
    save('추천 테마를 저장했어요');
});
document.getElementById('stReset').addEventListener('click', () => {
    if (RO || !confirm('테마 순서·켜기, 숨긴 그림, 디자인별 추천을 모두 처음대로 되돌릴까요?')) return;
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'reset');
    fetch('admin_stickers.php', { method: 'POST', body: fd }).then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error); C = j.cfg; if (Array.isArray(C.rec)) C.rec = {}; drawThemes(); drawRecs(); toast('처음 설정으로 되돌렸어요'); }).catch(e => toast(e.message || '되돌리지 못했어요.', true));
});
drawThemes(); drawRecs();
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
