<?php
/**
 * admin_designs.php - 관리자: 추천 디자인 (간편 템플릿)
 *
 *  새 청첩장 → 디자인 고르기 화면에 크게 보여 줄 디자인을 고르고 순서를 정한다 (최대 6개, 추천 3~4개).
 *  고르지 않은 디자인은 그 화면의 "다른 디자인 더 보기"를 눌러야 보인다.
 *  하나도 안 고르고 저장하면 예전처럼 모든 디자인이 한꺼번에 보인다.
 *  디자인 목록은 preset_list.php(presets 폴더)에서 읽는다. 저장: uploads/site/featured_presets.json (featured_presets.php)
 *  디자인 샘플: 디자인마다 [에디터로 꾸미기] → 실제 에디터(?sample=아이디)에서 사진·글·스티커·글자 위치까지 꾸며 저장하면
 *              고객이 그 디자인을 고를 때 꾸민 모습 그대로 시작한다. [원래대로]로 지울 수 있다. (sample_api.php)
 *              [+ 새 디자인 만들기] → 바탕 디자인을 골라 새 디자인을 하나 더 만든다 (한 번 저장해야 고객에게 보임)
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장할 수 있다 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/featured_presets.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    $ids = json_decode((string) ($_POST['ids'] ?? ''), true);
    if (!is_array($ids)) { echo json_encode(['ok' => false, 'error' => '저장할 내용이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
    try { $saved = featured_presets_save($ids); }
    catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['ok' => true, 'ids' => $saved], JSON_UNESCAPED_UNICODE);
    exit;
}
$csrf = csrf_token();
$cur = featured_presets_get();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>추천 디자인 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
body { background: var(--ui-page, #F6F4F1); }
.fd-intro { font-size: 13px; color: #8A847B; margin: -14px 0 20px; line-height: 1.75; }
.fd-card { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; padding: 18px; margin: 0 0 18px; }
.fd-h { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin: 0 0 12px; }
.fd-h b { font-size: 15px; }
.fd-h small { font-size: 12.5px; color: #8A847B; }
.fd-sel { display: flex; gap: 10px; flex-wrap: wrap; min-height: 64px; }
.fd-sel .empty { font-size: 13px; color: #A29C94; padding: 18px 4px; }
.fd-chip { display: flex; align-items: center; gap: 10px; padding: 8px 10px 8px 8px; border: 1px solid #2B2320; border-radius: 14px; background: #fff; }
.fd-chip .no { width: 22px; height: 22px; border-radius: 50%; background: #2B2320; color: #fff; font-size: 11.5px; font-weight: 700; display: grid; place-items: center; flex: none; }
.fd-chip .sw { width: 34px; height: 44px; border-radius: 8px; flex: none; position: relative; overflow: hidden; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.fd-chip .sw i { position: absolute; left: 5px; right: 5px; top: 5px; height: 16px; border-radius: 3px; }
.fd-chip .sw u { position: absolute; left: 50%; bottom: 7px; transform: translateX(-50%); font-size: 9px; text-decoration: none; }
.fd-chip b { font-size: 13.5px; }
.fd-chip .ord { display: flex; gap: 2px; margin-left: 4px; }
.fd-chip .ord button { width: 26px; height: 26px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; border-radius: 8px; cursor: pointer; font-size: 12px; color: #6F6A63; }
.fd-chip .ord button:disabled { opacity: .3; cursor: default; }
.fd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
.fd-p { position: relative; border: 1.5px solid var(--ui-line, #ECE8E2); border-radius: 14px; background: #fff; overflow: hidden; cursor: pointer; text-align: left; padding: 0; font: inherit; color: inherit; transition: border-color .15s, box-shadow .15s; }
.fd-p:hover { border-color: var(--ui-line, #C9C1B6); }
.fd-p.on { border-color: #2B2320; box-shadow: 0 0 0 1px #2B2320; }
.fd-p .pv { height: 120px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; position: relative; }
.fd-p .pv i { width: 64%; height: 48px; border-radius: 8px; }
.fd-p .pv span { font-size: 14px; }
.fd-p .pv .hrt { font-size: .7em; margin: 0 .35em; }
.fd-p .tx { display: block; padding: 10px 12px 12px; border-top: 1px solid var(--ui-soft, #F1EEE9); }
.fd-p .tx b { display: block; font-size: 13.5px; margin-bottom: 2px; }
.fd-p .tx small { font-size: 11.5px; color: #8A847B; line-height: 1.5; display: block; }
.fd-p .ck { position: absolute; top: 8px; right: 8px; min-width: 24px; height: 24px; padding: 0 6px; border-radius: 99px; background: rgba(255,255,255,.92); border: 1px solid var(--ui-line, #DED8CF); font-size: 11.5px; font-weight: 700; display: grid; place-items: center; color: #A29C94; }
.fd-p.on .ck { background: #2B2320; border-color: #2B2320; color: #fff; }
.fd-bar { position: sticky; bottom: 0; display: flex; align-items: center; gap: 12px; justify-content: flex-end; padding: 14px 0; background: linear-gradient(rgba(246,244,241,0), var(--ui-page, #F6F4F1) 40%); }
.fd-bar span { font-size: 13px; color: #8A847B; margin-right: auto; }
.fd-bar button { height: 44px; padding: 0 22px; border-radius: 12px; border: 0; background: #2B2320; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.fd-bar button.ghost { background: #fff; color: #6F6A63; border: 1px solid var(--ui-line, #E5DED3); }
.fd-bar button:disabled { opacity: .45; cursor: default; }
.fd-toast { position: fixed; left: 50%; bottom: 80px; transform: translateX(-50%); background: #2B2320; color: #fff; padding: 10px 18px; border-radius: 99px; font-size: 13px; opacity: 0; transition: opacity .2s; pointer-events: none; }
.fd-toast.on { opacity: 1; }
.fd-p { cursor: default; display: flex; flex-direction: column; }
.fd-p .top { all: unset; display: block; cursor: pointer; position: relative; }
.fd-p .acts { display: flex; gap: 6px; padding: 0 12px 12px; flex-wrap: wrap; margin-top: auto; }
.fd-p .acts a, .fd-p .acts button { height: 30px; padding: 0 10px; border-radius: 9px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; font: inherit; font-size: 12px; font-weight: 600; color: #4A453F; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
.fd-p .acts a.main { background: #2B2320; border-color: #2B2320; color: #fff; }
.fd-p .acts button.del { color: #B24A4A; }
.fd-p .tag { position: absolute; left: 8px; top: 8px; font-size: 10.5px; font-weight: 700; padding: 3px 8px; border-radius: 99px; background: #E7F4EC; color: #2F7A4E; }
.fd-p .tag.draft { background: #FFF3DC; color: #9A6B12; }
.fd-new { display: grid; grid-template-columns: 1fr 1fr 1.4fr auto; gap: 8px; align-items: end; margin: 0 0 14px; padding: 14px; border: 1px dashed var(--ui-line, #DED8CF); border-radius: 14px; background: var(--ui-tint, #FBF9F6); }
.fd-new[hidden] { display: none; }
.fd-new label { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 700; color: #6F6A63; }
.fd-new select, .fd-new input { height: 40px; border: 1px solid var(--ui-line, #DED8CF); border-radius: 10px; padding: 0 10px; font: inherit; font-size: 13.5px; background: #fff; }
.fd-new button, .fd-addbtn { height: 40px; padding: 0 16px; border-radius: 10px; border: 0; background: #2B2320; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.fd-addbtn { background: #fff; color: #2B2320; border: 1px solid #2B2320; }
@media (max-width: 760px) { .fd-new { grid-template-columns: 1fr; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('designs', '추천 디자인'); ?>
    <div class="wrap">
        <h2 class="page-title">디자인 샘플 · 추천 디자인</h2>
        <p class="fd-intro">
            <b>에디터로 꾸미기</b>를 누르면 실제 에디터가 열려요. 사진·글·스티커·글자 위치까지 꾸며서 <b>샘플 저장</b>하면, 고객이 그 디자인을 고를 때 꾸민 모습 그대로 시작해요.<br>
            카드 윗부분을 누르면 <b>추천 디자인</b>에 넣고 빼요. 추천은 새 청첩장 화면에 크게 보이고, 나머지는 “다른 디자인 더 보기”에 들어가요 (3~4개 추천, 최대 <?= FEATURED_MAX ?>개).
        </p>
        <div class="fd-card">
            <div class="fd-h"><b>추천 디자인 · 고객에게 보이는 순서</b><small id="fdCount"></small></div>
            <div class="fd-sel" id="fdSel"></div>
        </div>
        <div class="fd-card">
            <div class="fd-h"><b>전체 디자인</b><button type="button" class="fd-addbtn" id="fdAdd">+ 새 디자인 만들기</button></div>
            <form class="fd-new" id="fdNew" hidden>
                <label>바탕 디자인<select id="fdBase"></select></label>
                <label>이름<input id="fdLabel" maxlength="30" placeholder="예: 봄날 가든"></label>
                <label>한 줄 설명<input id="fdDesc" maxlength="60" placeholder="예: 연둣빛 정원에 꽃잎이 흩날리는"></label>
                <button type="submit">만들고 꾸미기</button>
            </form>
            <div class="fd-grid" id="fdGrid"><p style="color:#A29C94;font-size:13px">불러오는 중…</p></div>
        </div>
        <div class="fd-bar"><span id="fdState"></span><button type="button" class="ghost" id="fdReset">추천 처음 기본값으로</button><button type="button" id="fdSave" disabled>추천 저장</button></div>
    </div>
    <div class="fd-toast" id="fdToast"></div>
<script>
(() => {
const CSRF = <?= json_encode($csrf) ?>;
const DEFAULT = <?= json_encode(FEATURED_DEFAULT) ?>, MAX = <?= (int) FEATURED_MAX ?>;
let sel = <?= json_encode($cur) ?>, saved = JSON.stringify(sel), presets = [], samples = {};
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const toast = m => { const t = $('fdToast'); t.textContent = m; t.classList.add('on'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('on'), 2200); };
// 전체 디자인 = 폴더 프리셋 + 새로 만든 샘플 (바탕 디자인의 색을 빌려 보여줌)
function designs() {
    const extra = Object.values(samples).filter(x => x.custom).map(x => { const b = presets.find(p => p.id === x.base) || {}; return Object.assign({}, b, { id: x.id, label: x.label, desc: x.desc, no: '', __custom: true, __base: x.base }); });
    return presets.concat(extra);
}
const byId = id => designs().find(p => p.id === id);
const canFeature = p => !p.__custom || (samples[p.id] && samples[p.id].saved);
function sw(p) { const pl = p.palette || {}; return `<span class="sw" style="background:${esc(pl.bg)}"><i style="background:linear-gradient(150deg, ${esc(pl.line)}, ${esc(pl.accent)})"></i><u style="color:${esc(pl.accent)}">♥</u></span>`; }
function post(a, data) {
    const fd = new FormData(); fd.append('csrf_token', CSRF); Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch('sample_api.php?a=' + a, { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => { if (!d.ok) throw new Error(d.error || '실패했어요'); return d; });
}
function loadSamples() {
    return fetch('sample_api.php?a=admin_list', { cache: 'no-store', headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => { samples = {}; (d.items || []).forEach(x => { samples[x.id] = x; }); });
}
function draw() {
    sel = sel.filter(id => byId(id));
    $('fdSel').innerHTML = sel.length ? sel.map((id, i) => { const p = byId(id); return `<div class="fd-chip"><span class="no">${i + 1}</span>${sw(p)}<b>${esc(p.label)}</b>
        <span class="ord"><button type="button" data-mv="${i}" data-d="-1" ${i === 0 ? 'disabled' : ''} aria-label="앞으로">←</button><button type="button" data-mv="${i}" data-d="1" ${i === sel.length - 1 ? 'disabled' : ''} aria-label="뒤로">→</button><button type="button" data-rm="${esc(id)}" aria-label="빼기">✕</button></span></div>`; }).join('')
        : '<p class="empty">고른 디자인이 없어요 → 고객 화면에 모든 디자인이 한꺼번에 보여요.</p>';
    $('fdCount').textContent = `${sel.length} / ${MAX}개`;
    $('fdGrid').innerHTML = designs().map(p => { const i = sel.indexOf(p.id), pl = p.palette || {}, sm = samples[p.id];
        const tag = p.__custom ? (sm && sm.saved ? `<span class="tag">새 디자인</span>` : `<span class="tag draft">아직 저장 전</span>`) : (sm && sm.saved ? `<span class="tag">꾸민 샘플 · ${esc(sm.updated)}</span>` : '');
        return `<div class="fd-p ${i >= 0 ? 'on' : ''}" data-id="${esc(p.id)}">
            <button type="button" class="top" data-fav="${esc(p.id)}" title="추천에 넣기/빼기"><span class="pv" style="background:${esc(pl.bg)};color:${esc(pl.ink)};font-family:${esc(pl.headFont || 'serif')}"><i style="background:linear-gradient(150deg, ${esc(pl.line)}, ${esc(pl.accent)})"></i><span>민준<span class="hrt" style="color:${esc(pl.accent)}">♥</span>서연</span></span>
            ${tag}<span class="ck">${i >= 0 ? i + 1 : '★'}</span>
            <span class="tx"><b>${p.no ? String(p.no).padStart(2, '0') + ' ' : ''}${esc(p.label)}</b><small>${esc(p.desc || '')}${p.__custom ? ` · 바탕: ${esc((presets.find(x => x.id === p.__base) || {}).label || p.__base)}` : ''}</small></span></button>
            <div class="acts"><a class="main" href="editor-prototype-v3-overlay.html?sample=${encodeURIComponent(p.id)}" target="_blank" rel="noopener">에디터로 꾸미기</a>
            ${p.__custom ? `<button type="button" data-rename="${esc(p.id)}">이름</button><button type="button" class="del" data-reset="${esc(p.id)}">삭제</button>` : (sm && sm.saved ? `<button type="button" class="del" data-reset="${esc(p.id)}">원래대로</button>` : '')}</div></div>`; }).join('');
    $('fdSel').querySelectorAll('[data-mv]').forEach(b => b.onclick = () => { const i = +b.dataset.mv, j = i + +b.dataset.d; [sel[i], sel[j]] = [sel[j], sel[i]]; draw(); });
    $('fdSel').querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { sel = sel.filter(x => x !== b.dataset.rm); draw(); });
    $('fdGrid').querySelectorAll('[data-fav]').forEach(b => b.onclick = () => {
        const id = b.dataset.fav, p = byId(id);
        if (sel.includes(id)) sel = sel.filter(x => x !== id);
        else if (!canFeature(p)) { toast('새 디자인은 에디터에서 한 번 저장해야 추천에 넣을 수 있어요'); return; }
        else if (sel.length >= MAX) { toast(`최대 ${MAX}개까지 고를 수 있어요`); return; }
        else sel.push(id);
        draw();
    });
    $('fdGrid').querySelectorAll('[data-reset]').forEach(b => b.onclick = () => {
        const id = b.dataset.reset, custom = samples[id] && samples[id].custom;
        if (!confirm(custom ? '이 새 디자인을 지울까요? (이미 이 디자인으로 만든 청첩장은 그대로예요)' : '꾸민 샘플을 지우고 원래 디자인으로 되돌릴까요?')) return;
        post('reset', { id }).then(() => { if (custom) { sel = sel.filter(x => x !== id); } return loadSamples(); }).then(() => { draw(); toast(custom ? '지웠어요' : '원래대로 되돌렸어요'); }).catch(e => toast(e.message));
    });
    $('fdGrid').querySelectorAll('[data-rename]').forEach(b => b.onclick = () => {
        const id = b.dataset.rename, cur = samples[id];
        const label = prompt('디자인 이름', cur.label); if (label === null) return;
        const desc = prompt('한 줄 설명', cur.desc || ''); if (desc === null) return;
        post('meta', { id, label, desc }).then(loadSamples).then(() => { draw(); toast('바꿨어요'); }).catch(e => toast(e.message));
    });
    const dirty = JSON.stringify(sel) !== saved;
    $('fdSave').disabled = !dirty;
    $('fdState').textContent = dirty ? '추천 순서가 바뀌었어요 - 저장을 눌러주세요' : '';
}
$('fdAdd').onclick = () => { const f = $('fdNew'); f.hidden = !f.hidden; if (!f.hidden) $('fdLabel').focus(); };
$('fdNew').onsubmit = e => {
    e.preventDefault();
    post('create', { base: $('fdBase').value, label: $('fdLabel').value, desc: $('fdDesc').value }).then(d => {
        $('fdNew').hidden = true; $('fdLabel').value = ''; $('fdDesc').value = '';
        window.open('editor-prototype-v3-overlay.html?sample=' + encodeURIComponent(d.id), '_blank', 'noopener');
        return loadSamples();
    }).then(() => { draw(); toast('새 디자인을 만들었어요. 열린 에디터에서 꾸미고 저장해 주세요'); }).catch(err => toast(err.message));
};
$('fdReset').onclick = () => { sel = DEFAULT.slice(); draw(); };
$('fdSave').onclick = () => {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('ids', JSON.stringify(sel));
    $('fdSave').disabled = true;
    fetch('admin_designs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (!d.ok) throw new Error(d.error || '저장하지 못했어요');
        sel = d.ids; saved = JSON.stringify(sel); draw(); toast('저장했어요. 새 청첩장 화면에 바로 반영돼요');
    }).catch(e => { toast(e.message); draw(); });
};
// 다른 탭(에디터)에서 샘플을 저장하고 돌아오면 표시를 새로
window.addEventListener('focus', () => { loadSamples().then(draw).catch(() => {}); });
Promise.all([
    fetch('preset_list.php', { cache: 'no-store' }).then(r => r.json()).then(d => { presets = (d.presets || []).slice().sort((a, b) => (a.no || 99) - (b.no || 99)); }),
    loadSamples().catch(() => {})
]).then(() => { $('fdBase').innerHTML = presets.map(p => `<option value="${esc(p.id)}">${esc(p.label)}</option>`).join(''); draw(); })
  .catch(() => { $('fdGrid').innerHTML = '<p style="color:#B24A4A;font-size:13px">디자인 목록(preset_list.php)을 불러오지 못했어요.</p>'; });
window.addEventListener('beforeunload', e => { if (JSON.stringify(sel) !== saved) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
</body>
</html>
