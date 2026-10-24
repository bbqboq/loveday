<?php
/**
 * admin_intro_sets.php - 관리자: 인트로 분위기 (간편 만들기 인트로 칸의 분위기 16가지)
 *
 *  - 분위기마다 레이아웃 정하기: 미리보기 휴대폰에서 글자 · 스티커를 끌어서 옮기고,
 *    오른쪽 칸에서 이름 · 설명 · 글자 효과 · 글꼴 · 크기 · 색 · 정렬 · 바탕 · 스티커(넣기 · 크기 · 기울기 · 뒤집기 · 나타날 때 · 계속 움직임)
 *  - 왼쪽 목록: 순서(▲▼) · 고객에게 보이기 스위치 (바로 저장)
 *  - 분위기 칸은 [저장]을 눌러야 저장 · [원래대로]는 기본값(assets/intro-sets.js)으로
 *  저장: intro_sets.php → uploads/site/intro_sets.json. 에디터는 새로 열 때 읽음 (editor.js SPI_SETS를 덮어씀).
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/intro_sets.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    if (function_exists('admin_page_readonly') && admin_page_readonly()) { echo json_encode(['ok' => false, 'error' => '보기 전용이에요.'], JSON_UNESCAPED_UNICODE); exit; }
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'save') {
        $cfg = json_decode((string) ($_POST['cfg'] ?? ''), true);
        if (!is_array($cfg)) { echo json_encode(['ok' => false, 'error' => '값이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
        $ok = intro_sets_save($cfg);
    } elseif ($act === 'reset') {
        $ok = intro_sets_save([]);
    } else {
        echo json_encode(['ok' => false, 'error' => '알 수 없는 요청이에요.'], JSON_UNESCAPED_UNICODE); exit;
    }
    $c = intro_sets_get();
    echo json_encode($ok ? ['ok' => true, 'cfg' => ['sets' => $c['sets'] ?: new stdClass(), 'order' => $c['order'], 'off' => $c['off']]] : ['ok' => false, 'error' => '저장하지 못했어요. 잠시 뒤 다시 해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 스티커 그림 (assets/stickers/<테마>/*.svg)
$themeNames = ['romantic' => '로맨틱', 'garden' => '가든·꽃', 'classic' => '클래식', 'cosmos' => '별·우주', 'party' => '축하·파티', 'season' => '계절', 'rain' => '비 오는 날', 'webtoon' => '웹툰'];
$files = [];
foreach (array_keys($themeNames) as $t) {
    $list = [];
    foreach (glob(__DIR__ . "/assets/stickers/$t/*.svg") ?: [] as $p) { $k = basename($p, '.svg'); if (preg_match('/^[a-z0-9-]{1,40}$/', $k)) $list[] = $k; }
    $files[$t] = $list;
}
// 미리보기 바탕 사진 = 간편 만들기 메인 사진 예시 (없으면 그라데이션)
$heroPic = '';
try { require_once __DIR__ . '/section_defaults.php'; if (function_exists('section_defaults_easy_hero')) $heroPic = section_defaults_easy_hero(); } catch (Throwable $e) {}
$c = intro_sets_get();
$readonly = function_exists('admin_page_readonly') && admin_page_readonly();
$csrf = csrf_token();
$v = @filemtime(__DIR__ . '/assets/intro-sets.js') ?: time();
$vb = @filemtime(__DIR__ . '/assets/invite-blocks.js') ?: time();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>인트로 분위기 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<link rel="stylesheet" href="assets/invite-blocks.css?v=<?= $vb ?>">
<style>
.iw { max-width: 1180px; margin: 0 auto; padding: 24px 20px 90px; font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; color: #2B2320; box-sizing: border-box; }
.iw * { box-sizing: border-box; }
.iw h2 { font-size: 20px; margin: 0 0 6px; }
.iw .lead { font-size: 13px; color: #8A847B; margin: 0 0 20px; line-height: 1.7; }
.iw-grid { display: grid; grid-template-columns: 290px minmax(0, 1fr); gap: 18px; align-items: start; }
.card { background: #fff; border: 1px solid #ECE6DD; border-radius: 16px; padding: 14px; }
/* 왼쪽 목록 */
.il { display: flex; flex-direction: column; gap: 6px; max-height: calc(100vh - 140px); overflow-y: auto; position: sticky; top: 70px; }
.il-row { display: flex; align-items: center; gap: 8px; padding: 6px; border: 1.5px solid transparent; border-radius: 12px; cursor: pointer; }
.il-row:hover { background: #FAF7F3; }
.il-row.on { border-color: #2B2320; background: #FAF7F3; }
.il-row.off .il-th, .il-row.off .il-nm b { opacity: .45; }
.il-mv { display: flex; flex-direction: column; gap: 2px; flex: none; }
.il-mv button { width: 22px; height: 17px; border: 1px solid #E6E0D8; background: #fff; border-radius: 6px; font-size: 8px; color: #8A847B; cursor: pointer; padding: 0; line-height: 1; }
.il-mv button:disabled { opacity: .3; cursor: default; }
.il-th { flex: none; width: 44px; }
.il-nm { flex: 1; min-width: 0; }
.il-nm b { display: block; font-size: 13.5px; }
.il-nm small { display: block; font-size: 11.5px; color: #8A847B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.il-nm em { font-style: normal; font-size: 10px; font-weight: 700; color: #fff; background: var(--ui-point, #B08A5A); border-radius: 5px; padding: 1px 5px; margin-left: 4px; vertical-align: 1px; }
.sw { position: relative; width: 40px; height: 24px; flex: none; }
.sw input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; z-index: 1; }
.sw i { position: absolute; inset: 0; border-radius: 12px; background: #D9D3CB; transition: background .2s; }
.sw i::after { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.sw input:checked + i { background: var(--ui-point, #B08A5A); }
.sw input:checked + i::after { transform: translateX(16px); }
/* 휴대폰 미리보기 */
.st { position: relative; width: 100%; aspect-ratio: 9 / 16; border-radius: 16px; overflow: hidden; container-type: inline-size; background: linear-gradient(160deg, #C9B8A6, #4E4038) center / cover no-repeat; user-select: none; -webkit-user-select: none; }
.st.mini { border-radius: 7px; }
.st-ov { position: absolute; inset: 0; }
.st-tx { position: absolute; transform: translate(-50%, -50%); max-width: 92%; line-height: 1.45; white-space: pre-wrap; word-break: keep-all; font-size: 6.667cqw; text-align: center; width: max-content; }
.st-stk { position: absolute; height: auto; transform-origin: 50% 50%; pointer-events: none; }
.st:not(.mini) .st-stk, .st:not(.mini) .st-tx { pointer-events: auto; cursor: grab; touch-action: none; }
.st .sel { outline: 1.5px dashed rgba(255,255,255,.95); outline-offset: 3px; filter: drop-shadow(0 0 1px rgba(0,0,0,.6)); }
.st.dragging .st-stk, .st.dragging .st-tx { cursor: grabbing; }
.st-gd { position: absolute; background: rgba(255,90,120,.9); pointer-events: none; display: none; z-index: 50; }
.st-gd.v { left: 50%; top: 0; bottom: 0; width: 1px; } .st-gd.h { top: 50%; left: 0; right: 0; height: 1px; }
.st-gd.on { display: block; }
/* 오른쪽 편집 */
.ie-top { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.ie-top h3 { font-size: 17px; margin: 0; flex: 1; min-width: 140px; }
.ie-top h3 small { font-size: 12px; color: #B0563F; font-weight: 600; margin-left: 6px; }
.btn2 { border: 1px solid #E6E0D8; background: #fff; border-radius: 10px; height: 36px; padding: 0 14px; font: inherit; font-size: 13px; font-weight: 600; color: #5A524A; cursor: pointer; }
.btn2.pri { background: #2B2320; border-color: #2B2320; color: #fff; }
.btn2:disabled { opacity: .4; cursor: default; }
.ie { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 20px; align-items: start; }
.ie-stw { position: sticky; top: 70px; display: flex; flex-direction: column; gap: 8px; }
.ie-bar { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; font-size: 12px; color: #8A847B; }
.ie-bar input[type=text] { flex: 1; min-width: 0; height: 32px; border: 1px solid #E6E0D8; border-radius: 9px; padding: 0 9px; font: inherit; font-size: 13px; }
.seg { display: inline-flex; background: #F4EFE9; border-radius: 10px; padding: 3px; gap: 3px; flex-wrap: wrap; }
.seg button { border: 0; background: transparent; border-radius: 8px; height: 28px; padding: 0 10px; font: inherit; font-size: 12.5px; font-weight: 600; color: #8A847B; cursor: pointer; }
.seg button.on { background: #fff; color: #2B2320; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.ie-hint { font-size: 12px; color: #8A847B; line-height: 1.5; margin: 0; }
.sec { border-top: 1px solid #F1ECE5; padding: 12px 0; display: flex; flex-direction: column; gap: 9px; }
.sec:first-child { border-top: 0; padding-top: 0; }
.sec > h4 { margin: 0; font-size: 14px; }
.fr { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px; }
.fr > label { width: 92px; flex: none; color: #5A524A; font-weight: 600; font-size: 12.5px; }
.fr input[type=text], .fr select { flex: 1; min-width: 140px; height: 34px; border: 1px solid #E6E0D8; border-radius: 9px; padding: 0 9px; font: inherit; font-size: 13px; background: #fff; color: #2B2320; }
.fr input[type=range] { flex: 1; min-width: 120px; accent-color: #2B2320; }
.fr output { width: 46px; text-align: right; font-variant-numeric: tabular-nums; color: #5A524A; font-size: 12.5px; }
.fr input[type=color] { width: 40px; height: 32px; border: 1px solid #E6E0D8; border-radius: 8px; padding: 2px; background: #fff; }
.ck { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.sks { display: flex; gap: 6px; flex-wrap: wrap; }
.sk { position: relative; width: 52px; height: 52px; border: 1.5px solid #ECE6DD; border-radius: 11px; background: #FAF7F3; padding: 5px; cursor: pointer; }
.sk img { width: 100%; height: 100%; object-fit: contain; }
.sk.on { border-color: #2B2320; background: #fff; }
.sk-add { width: 52px; height: 52px; border: 1.5px dashed #D9D0C3; border-radius: 11px; background: #fff; font-size: 22px; color: #8A847B; cursor: pointer; }
.skp { border: 1px solid #ECE6DD; border-radius: 12px; padding: 10px; background: #FCFAF7; display: flex; flex-direction: column; gap: 9px; }
.skp h5 { margin: 0; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.skp h5 img { width: 28px; height: 28px; object-fit: contain; }
.lib { border: 1px solid #ECE6DD; border-radius: 12px; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
.lib-g { display: grid; grid-template-columns: repeat(auto-fill, minmax(54px, 1fr)); gap: 6px; max-height: 260px; overflow-y: auto; }
.lib-g button { aspect-ratio: 1; border: 1px solid #ECE6DD; border-radius: 10px; background: #fff; padding: 6px; cursor: pointer; }
.lib-g button img { width: 100%; height: 100%; object-fit: contain; }
.lib-g button:hover { border-color: #CDBFAE; }
.foot { display: flex; justify-content: flex-end; margin-top: 14px; }
.toast { position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%) translateY(10px); background: rgba(43,35,32,.94); color: #fff; font-size: 13px; font-weight: 600; padding: 10px 16px; border-radius: 12px; opacity: 0; transition: opacity .25s, transform .25s; pointer-events: none; z-index: 90; }
.toast.on { opacity: 1; transform: translateX(-50%); }
.toast.err { background: #A5493A; }
.iw.ro .btn2, .iw.ro .sw input, .iw.ro .il-mv button { pointer-events: none; opacity: .5; }
@media (max-width: 980px) {
    .iw-grid { grid-template-columns: 1fr; }
    .il { position: static; max-height: none; flex-direction: row; overflow-x: auto; overflow-y: hidden; padding-bottom: 4px; }
    .il-row { flex: 0 0 200px; }
}
@media (max-width: 720px) {
    .iw { padding: 16px 12px 80px; }
    .ie { grid-template-columns: 1fr; }
    .ie-stw { position: static; max-width: 300px; margin: 0 auto; width: 100%; }
    .fr > label { width: 100%; }
}
</style>
<?= function_exists('site_colors_link') ? site_colors_link() : '' ?>
</head>
<body>
<?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('introsets', '인트로 분위기'); ?>
<div class="iw<?= $readonly ? ' ro' : '' ?>" id="iw">
    <h2 class="page-title">인트로 분위기</h2>
    <p class="lead">간편 만들기 인트로 칸에서 고객이 고르는 분위기 16가지를 정해요. 미리보기에서 <b>글자 · 스티커를 끌어서</b> 자리를 옮기고, 오른쪽 칸에서 글자 · 바탕 · 스티커 움직임을 바꾼 뒤 <b>[저장]</b>을 눌러요.<br>왼쪽 목록의 순서(▲▼)와 보이기 스위치는 바로 저장돼요. 고객이 에디터를 새로 열 때부터 보여요.</p>
    <div class="iw-grid">
        <div class="card il" id="list"></div>
        <div class="card" id="edit"></div>
    </div>
    <div class="foot"><button type="button" class="btn2" id="resetAll">모든 분위기를 처음 설정으로</button></div>
</div>
<div class="toast" id="toast"></div>
<script src="assets/invite-blocks.js?v=<?= $vb ?>"></script>
<script src="assets/intro-sets.js?v=<?= $v ?>"></script>
<script>
const FILES = <?= json_encode($files, JSON_UNESCAPED_SLASHES) ?>;
const THEMES = <?= json_encode($themeNames, JSON_UNESCAPED_UNICODE) ?>;
const HERO = <?= json_encode($heroPic, JSON_UNESCAPED_SLASHES) ?>;
const CSRF = <?= json_encode($csrf) ?>;
const RO = <?= $readonly ? 'true' : 'false' ?>;
let C = <?= json_encode(['sets' => $c['sets'] ?: new stdClass(), 'order' => $c['order'], 'off' => $c['off']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const DEF = (window.LD_INTRO_SETS || []).map(x => JSON.parse(JSON.stringify(x)));
const IB = window.InviteBlocks || {};
const ANIMS = (IB.RichText && IB.RichText.ANIMS) || [['type', '타자 치듯']];
const FX = IB.INTRO_STK_FX || [], LOOP = IB.INTRO_STK_LOOP || [], FXN = IB.INTRO_STK_NAMES || {};
const FONTS = IB.FONT_CSS || {};
const PV = { light: { bg: '#FBF8F3', ink: '#3B322D' }, dark: { bg: '#1E1A17', ink: '#EFE6D8' } };
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const clone = o => JSON.parse(JSON.stringify(o));
const src = (t, k) => `assets/stickers/${t}/${k}.svg`;
const toast = (t, err) => { const el = document.getElementById('toast'); el.textContent = t; el.classList.toggle('err', !!err); el.classList.add('on'); clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove('on'), 1900); };
if (Array.isArray(C.sets)) C.sets = {};

let cur = '', W = null, dirty = false, sel = -1, libOn = false, libT = Object.keys(THEMES)[0], pvMode = 'light', sample = '우리 결혼합니다';
const ids = () => { const all = DEF.map(x => x.id); const o = (C.order || []).filter(id => all.includes(id)); return o.concat(all.filter(id => !o.includes(id))); };
const setOf = id => { const d = DEF.find(x => x.id === id) || {}; return Object.assign({ id }, clone(C.sets[id] || d), { id }); };
const font = id => (FONTS[id] && FONTS[id].family) || '';
const rgba = (hex, a) => { const h = String(hex || '').replace('#', ''); if (!/^[0-9a-f]{6}$/i.test(h)) return hex; const n = parseInt(h, 16); return `rgba(${n >> 16 & 255},${n >> 8 & 255},${n & 255},${a})`; };
function bgCss(s) { if (s.bg === 'dim') return 'rgba(0,0,0,.35)'; const c = s.bg || PV[pvMode].bg; return s.op != null && s.op < 100 ? rgba(c, s.op / 100) : c; }
function inkOf(s) { return s.color || (s.bg === 'dim' ? '#FFFFFF' : PV[pvMode].ink); }

// 휴대폰 화면 하나 (mini = 목록 작은 그림 - 끌기 없음)
function stageHtml(s, mini) {
    if (s.font && IB.ensureFont) IB.ensureFont(s.font);
    const under = HERO ? `background-image:url("${HERO.replace(/"/g, '%22')}")` : '';
    const tx = `<div class="st-tx${!mini && sel === -2 ? ' sel' : ''}" data-tx style="left:${s.tx ?? 50}%;top:${s.ty ?? 50}%;font-size:${((s.fs || 26) / 390 * 100).toFixed(2)}cqw;color:${inkOf(s)};text-align:${s.align || 'center'};${s.font ? `font-family:${esc(font(s.font).replace(/"/g, "'"))};` : 'font-family:\'Nanum Myeongjo\',\'Noto Serif KR\',serif;'}"><span class="tt">${esc(mini ? '' : sample)}</span></div>`;
    const stk = (s.stk || []).map((r, n) => `<img class="st-stk${!mini && sel === n ? ' sel' : ''}" data-n="${n}" src="${src(r[0], r[1])}" alt="" draggable="false" style="left:${r[2]}%;top:${r[3]}%;width:${((r[5] || 48) / 390 * 100).toFixed(2)}cqw;transform:translate(-50%,-50%) rotate(${r[7] || 0}deg)${r[8] ? ' scaleX(-1)' : ''};z-index:${10 + n}">`).join('');
    return `<div class="st${mini ? ' mini' : ''}" style="${under}"><div class="st-ov" style="background:${bgCss(s)}"></div>${tx}${stk}${mini ? '' : '<i class="st-gd v"></i><i class="st-gd h"></i>'}</div>`;
}

function drawList() {
    const L = ids();
    document.getElementById('list').innerHTML = L.map((id, i) => { const s = setOf(id), off = (C.off || []).includes(id);
        return `<div class="il-row${id === cur ? ' on' : ''}${off ? ' off' : ''}" data-id="${id}">
            <div class="il-mv"><button type="button" data-mv="-1" ${i ? '' : 'disabled'} aria-label="위로">▲</button><button type="button" data-mv="1" ${i < L.length - 1 ? '' : 'disabled'} aria-label="아래로">▼</button></div>
            <div class="il-th">${stageHtml(s, true)}</div>
            <div class="il-nm"><b>${esc(s.n)}${C.sets[id] ? '<em>고침</em>' : ''}</b><small>${esc(s.d || '')}</small></div>
            <label class="sw" title="${off ? '고객에게 안 보여요' : '고객에게 보여요'}"><input type="checkbox" data-on ${off ? '' : 'checked'} aria-label="${esc(s.n)} 보이기"><i></i></label></div>`; }).join('');
}

const opt = (list, v) => list.map(([k, l]) => `<option value="${esc(k)}" ${String(v ?? '') === String(k) ? 'selected' : ''}>${esc(l)}</option>`).join('');
function drawEdit() {
    const s = W, box = document.getElementById('edit');
    const bgMode = s.bg === 'dim' ? 'dim' : s.bg ? 'color' : 'design';
    const r = sel >= 0 ? s.stk[sel] : null;
    box.innerHTML = `<div class="ie-top"><h3>${esc(s.n)}${dirty ? '<small>· 저장 안 한 고침이 있어요</small>' : ''}</h3>
            <button type="button" class="btn2" id="bReset" ${C.sets[cur] || dirty ? '' : 'disabled'}>원래대로</button><button type="button" class="btn2 pri" id="bSave" ${dirty ? '' : 'disabled'}>저장</button></div>
        <div class="ie">
            <div class="ie-stw"><div id="stage">${stageHtml(s)}</div>
                <div class="ie-bar"><button type="button" class="btn2" id="bPlay">▶ 재생</button><span class="seg" id="pvSeg"><button type="button" data-pv="light" class="${pvMode === 'light' ? 'on' : ''}">밝은 디자인</button><button type="button" data-pv="dark" class="${pvMode === 'dark' ? 'on' : ''}">어두운 디자인</button></span></div>
                <div class="ie-bar"><span>미리보기 문구</span><input type="text" id="sample" value="${esc(sample)}" maxlength="40"></div>
                <p class="ie-hint">글자 · 스티커를 끌어서 옮겨요. 가운데에 가까우면 분홍 선에 딱 붙어요. 미리보기 문구는 저장되지 않아요 (고객이 적은 문구가 들어가요).</p></div>
            <div>
                <div class="sec"><h4>이름</h4>
                    <div class="fr"><label for="fN">분위기 이름</label><input type="text" id="fN" value="${esc(s.n)}" maxlength="16"></div>
                    <div class="fr"><label for="fD">한 줄 설명</label><input type="text" id="fD" value="${esc(s.d || '')}" maxlength="30"></div></div>
                <div class="sec"><h4>글자</h4>
                    <div class="fr"><label for="fA">나타나는 효과</label><select id="fA">${opt(ANIMS, s.anim)}</select></div>
                    <div class="fr"><label for="fF">글꼴</label><select id="fF"><option value="">디자인 글꼴 그대로</option>${opt(Object.entries(FONTS).map(([k, f]) => [k, f.label || k]), s.font)}</select></div>
                    <div class="fr"><label for="fS">글자 크기</label><input type="range" id="fS" min="12" max="80" step="1" value="${s.fs || 26}"><output id="fSv">${s.fs || 26}</output></div>
                    <div class="fr"><label>글자 색</label><label class="ck"><input type="checkbox" id="fCk" ${s.color ? '' : 'checked'}> 디자인 글자 색</label><input type="color" id="fC" value="${s.color || '#3B322D'}" ${s.color ? '' : 'disabled'}></div>
                    <div class="fr"><label>정렬</label><span class="seg" id="fAl">${[['left', '왼쪽'], ['center', '가운데'], ['right', '오른쪽']].map(([k, l]) => `<button type="button" data-al="${k}" class="${(s.align || 'center') === k ? 'on' : ''}">${l}</button>`).join('')}</span></div>
                    <div class="fr"><label>자리</label><span class="ie-hint">가로 ${Math.round(s.tx ?? 50)}% · 세로 ${Math.round(s.ty ?? 50)}% (미리보기에서 끌기)</span><button type="button" class="btn2" id="fMid">가운데로</button></div></div>
                <div class="sec"><h4>바탕</h4>
                    <div class="fr"><label>바탕</label><span class="seg" id="fBg"><button type="button" data-bg="design" class="${bgMode === 'design' ? 'on' : ''}">디자인 바탕색</button><button type="button" data-bg="dim" class="${bgMode === 'dim' ? 'on' : ''}">첫 화면 사진 위 (어둡게)</button><button type="button" data-bg="color" class="${bgMode === 'color' ? 'on' : ''}">색 직접</button></span></div>
                    ${bgMode === 'color' ? `<div class="fr"><label for="fBc">색</label><input type="color" id="fBc" value="${s.bg}"></div>` : ''}
                    ${bgMode !== 'dim' ? `<div class="fr"><label for="fO">진하기</label><input type="range" id="fO" min="0" max="100" step="5" value="${s.op ?? 100}"><output id="fOv">${s.op ?? 100}%</output></div><p class="ie-hint">낮출수록 뒤 첫 화면 사진이 비쳐요.</p>` : ''}</div>
                <div class="sec"><h4>스티커 <small style="font-weight:500;color:#8A847B;font-size:12px">${(s.stk || []).length} / 12</small></h4>
                    <div class="sks">${(s.stk || []).map((r, n) => `<button type="button" class="sk${sel === n ? ' on' : ''}" data-sk="${n}" title="${esc(r[1])}"><img src="${src(r[0], r[1])}" alt=""></button>`).join('')}${(s.stk || []).length < 12 ? `<button type="button" class="sk-add" id="bAdd" aria-label="스티커 넣기">+</button>` : ''}</div>
                    ${libOn ? `<div class="lib"><span class="seg" id="libT">${Object.entries(THEMES).map(([k, l]) => `<button type="button" data-lt="${k}" class="${libT === k ? 'on' : ''}">${esc(l)}</button>`).join('')}</span>
                        <div class="lib-g">${(FILES[libT] || []).map(k => `<button type="button" data-lk="${esc(k)}" title="${esc(k)}"><img src="${src(libT, k)}" alt="" loading="lazy"></button>`).join('')}</div><p class="ie-hint">누르면 미리보기 가운데에 들어가요.</p></div>` : ''}
                    ${r ? `<div class="skp"><h5><img src="${src(r[0], r[1])}" alt=""> 고른 스티커 <span class="ie-hint">(${esc(r[1])})</span></h5>
                        <div class="fr"><label for="kS">크기</label><input type="range" id="kS" min="14" max="240" step="1" value="${r[5] || 48}"><output id="kSv">${r[5] || 48}</output></div>
                        <div class="fr"><label for="kR">기울기</label><input type="range" id="kR" min="-180" max="180" step="1" value="${r[7] || 0}"><output id="kRv">${r[7] || 0}°</output></div>
                        <div class="fr"><label>뒤집기</label><label class="ck"><input type="checkbox" id="kF" ${r[8] ? 'checked' : ''}> 좌우로 뒤집기</label></div>
                        <div class="fr"><label for="kX">나타날 때</label><select id="kX"><option value="">없음 (처음부터 보임)</option>${opt(FX.map(k => [k, FXN[k] || k]), r[4])}</select></div>
                        <div class="fr"><label for="kL">계속 움직임</label><select id="kL"><option value="">없음</option>${opt(LOOP.map(k => [k, FXN[k] || k]), r[6])}</select></div>
                        <div class="fr"><button type="button" class="btn2" id="kPlay">▶ 이 스티커만 보기</button><button type="button" class="btn2" id="kTop">맨 앞으로</button><button type="button" class="btn2" id="kDel">지우기</button></div></div>` : (s.stk || []).length ? '<p class="ie-hint">스티커를 누르면 크기 · 기울기 · 움직임을 바꿀 수 있어요.</p>' : ''}</div>
            </div></div>`;
    bindEdit();
}
function changed(redrawEdit) { dirty = true; if (redrawEdit) drawEdit(); else { document.getElementById('stage').innerHTML = stageHtml(W); bindDrag(); const h = document.querySelector('.ie-top h3'); if (h && !h.querySelector('small')) h.insertAdjacentHTML('beforeend', '<small>· 저장 안 한 고침이 있어요</small>'); document.getElementById('bSave').disabled = false; document.getElementById('bReset').disabled = false; } }
function bindEdit() {
    const $ = id => document.getElementById(id);
    const inp = (id, fn, redraw) => { const el = $(id); if (el) el.addEventListener(el.type === 'range' || el.type === 'text' || el.type === 'color' ? 'input' : 'change', () => { fn(el); changed(redraw); }); };
    inp('fN', el => { W.n = el.value; });
    inp('fD', el => { W.d = el.value; });
    inp('fA', el => { W.anim = el.value; play(); });
    inp('fF', el => { if (el.value) W.font = el.value; else delete W.font; });
    inp('fS', el => { W.fs = +el.value; $('fSv').textContent = el.value; });
    inp('fCk', el => { if (el.checked) delete W.color; else W.color = $('fC').value; $('fC').disabled = el.checked; });
    inp('fC', el => { W.color = el.value.toUpperCase(); });
    inp('fBc', el => { W.bg = el.value.toUpperCase(); });
    inp('fO', el => { const v = +el.value; if (v >= 100) delete W.op; else W.op = v; $('fOv').textContent = v + '%'; });
    document.querySelectorAll('#fAl [data-al]').forEach(b => b.addEventListener('click', () => { if (b.dataset.al === 'center') delete W.align; else W.align = b.dataset.al; changed(true); }));
    document.querySelectorAll('#fBg [data-bg]').forEach(b => b.addEventListener('click', () => { const m = b.dataset.bg; if (m === 'dim') { W.bg = 'dim'; delete W.op; W.color = W.color || '#FFFFFF'; } else if (m === 'design') W.bg = ''; else W.bg = /^#/.test(W.bg) ? W.bg : '#F6EDE2'; changed(true); }));
    $('fMid')?.addEventListener('click', () => { delete W.tx; delete W.ty; changed(true); });
    document.querySelectorAll('#pvSeg [data-pv]').forEach(b => b.addEventListener('click', () => { pvMode = b.dataset.pv; drawEdit(); drawList(); }));
    $('sample').addEventListener('input', e => { sample = e.target.value || ' '; const t = document.querySelector('#stage .tt'); if (t) t.textContent = sample; });
    $('bPlay').addEventListener('click', () => play());
    $('bSave').addEventListener('click', saveCur);
    $('bReset').addEventListener('click', resetCur);
    $('bAdd')?.addEventListener('click', () => { libOn = !libOn; drawEdit(); });
    document.querySelectorAll('#libT [data-lt]').forEach(b => b.addEventListener('click', () => { libT = b.dataset.lt; drawEdit(); }));
    document.querySelectorAll('[data-lk]').forEach(b => b.addEventListener('click', () => { W.stk = W.stk || []; if (W.stk.length >= 12) return toast('스티커는 12개까지 넣을 수 있어요', true); W.stk.push([libT, b.dataset.lk, 50, 50, 'pop', 80, '', 0, 0]); sel = W.stk.length - 1; libOn = false; changed(true); }));
    document.querySelectorAll('[data-sk]').forEach(b => b.addEventListener('click', () => { sel = +b.dataset.sk === sel ? -1 : +b.dataset.sk; drawEdit(); }));
    const r = sel >= 0 ? W.stk[sel] : null;
    if (r) {
        inp('kS', el => { r[5] = +el.value; $('kSv').textContent = el.value; });
        inp('kR', el => { r[7] = +el.value; $('kRv').textContent = el.value + '°'; });
        inp('kF', el => { r[8] = el.checked ? 1 : 0; });
        inp('kX', el => { r[4] = el.value; play(sel); });
        inp('kL', el => { r[6] = el.value; play(sel); });
        $('kPlay').addEventListener('click', () => play(sel));
        $('kTop').addEventListener('click', () => { W.stk.push(W.stk.splice(sel, 1)[0]); sel = W.stk.length - 1; changed(true); });
        $('kDel').addEventListener('click', () => { W.stk.splice(sel, 1); sel = -1; changed(true); });
    }
    bindDrag();
}
// 미리보기에서 끌어 옮기기 (가운데 ±2%면 딱 붙음)
function bindDrag() {
    const st = document.querySelector('#stage .st'); if (!st || st._bound) return; st._bound = 1;
    st.addEventListener('pointerdown', e => {
        const el = e.target.closest('.st-stk, .st-tx'); if (!el || RO) return;
        e.preventDefault();
        const isTx = el.classList.contains('st-tx'), n = isTx ? -2 : +el.dataset.n;
        if (sel !== n) { sel = n; drawEdit(); }
        const st2 = document.querySelector('#stage .st'), el2 = isTx ? st2.querySelector('.st-tx') : st2.querySelector(`.st-stk[data-n="${n}"]`);
        const rc = st2.getBoundingClientRect(), gv = st2.querySelector('.st-gd.v'), gh = st2.querySelector('.st-gd.h');
        const o = isTx ? [W.tx ?? 50, W.ty ?? 50] : [W.stk[n][2], W.stk[n][3]];
        const sx = e.clientX, sy = e.clientY; let moved = false;
        st2.classList.add('dragging'); try { st2.setPointerCapture(e.pointerId); } catch (er) {}
        const mv = ev => {
            const dx = (ev.clientX - sx) / rc.width * 100, dy = (ev.clientY - sy) / rc.height * 100;
            if (!moved && Math.hypot(ev.clientX - sx, ev.clientY - sy) < 3) return; moved = true;
            let x = Math.max(-5, Math.min(105, o[0] + dx)), y = Math.max(-5, Math.min(105, o[1] + dy));
            const snx = Math.abs(x - 50) < 2, sny = Math.abs(y - 50) < 2; if (snx) x = 50; if (sny) y = 50;
            gv.classList.toggle('on', snx); gh.classList.toggle('on', sny);
            x = Math.round(x * 10) / 10; y = Math.round(y * 10) / 10;
            if (isTx) { W.tx = x; W.ty = y; } else { W.stk[n][2] = x; W.stk[n][3] = y; }
            el2.style.left = x + '%'; el2.style.top = y + '%';
        };
        const up = () => { st2.removeEventListener('pointermove', mv); st2.removeEventListener('pointerup', up); st2.removeEventListener('pointercancel', up); st2.classList.remove('dragging'); gv.classList.remove('on'); gh.classList.remove('on'); if (moved) changed(true); };
        st2.addEventListener('pointermove', mv); st2.addEventListener('pointerup', up); st2.addEventListener('pointercancel', up);
    });
}
// 재생: 글자 효과 + 스티커 나타날 때 · 계속 움직임 (only = 그 스티커만)
function play(only) {
    const st = document.querySelector('#stage .st'); if (!st) return;
    st.innerHTML = document.createRange().createContextualFragment(stageHtml(W)).firstElementChild.innerHTML;
    if (only == null) { const t = st.querySelector('.tt'); try { const RT = IB.RichText; if (W.anim && W.anim !== 'type' && RT.animate) RT.animate(t, esc(sample), W.anim, 2.5); else if (RT.type) RT.type(t, esc(sample), 2.5); } catch (e) {} }
    st.querySelectorAll('.st-stk').forEach(el => { const n = +el.dataset.n; if (only != null && n !== only) return; const r = W.stk[n]; if (IB.introStkFx) IB.introStkFx(el, { effect: r[4], loop: r[6] }, only != null ? 0 : n, true); });
    bindDrag();
}
function post(act, cfg) {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', act); if (cfg) fd.append('cfg', JSON.stringify(cfg));
    return fetch('admin_intro_sets.php', { method: 'POST', body: fd }).then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error || '저장하지 못했어요.'); C = j.cfg; if (Array.isArray(C.sets)) C.sets = {}; return j; });
}
function saveCur() {
    if (RO) return;
    if (!String(W.n || '').trim()) return toast('분위기 이름을 적어 주세요', true);
    const s = clone(W); delete s.id; const cfg = clone(C); cfg.sets[cur] = s;
    post('save', cfg).then(() => { dirty = false; W = setOf(cur); drawList(); drawEdit(); toast('저장했어요 · 고객이 에디터를 새로 열면 보여요'); }).catch(e => toast(e.message, true));
}
function resetCur() {
    if (RO) return;
    if (!confirm(`'${W.n}' 분위기를 처음 기본값으로 되돌릴까요?`)) return;
    if (!C.sets[cur]) { dirty = false; W = setOf(cur); sel = -1; drawEdit(); return; }
    const cfg = clone(C); delete cfg.sets[cur];
    post('save', cfg).then(() => { dirty = false; W = setOf(cur); sel = -1; drawList(); drawEdit(); toast('기본값으로 되돌렸어요'); }).catch(e => toast(e.message, true));
}
function pick(id) {
    if (id === cur) return;
    if (dirty && !confirm('저장하지 않은 고침이 있어요. 버리고 다른 분위기로 갈까요?')) return;
    cur = id; W = setOf(id); dirty = false; sel = -1; libOn = false; drawList(); drawEdit(); play();
}
document.getElementById('list').addEventListener('click', e => {
    const row = e.target.closest('.il-row'); if (!row) return; const id = row.dataset.id;
    const mv = e.target.closest('[data-mv]');
    if (mv) { const L = ids(), i = L.indexOf(id), j = i + +mv.dataset.mv; if (j < 0 || j >= L.length) return; [L[i], L[j]] = [L[j], L[i]]; const cfg = clone(C); cfg.order = L; post('save', cfg).then(() => { drawList(); toast('순서를 저장했어요'); }).catch(er => toast(er.message, true)); return; }
    if (e.target.closest('.sw')) return;
    pick(id);
});
document.getElementById('list').addEventListener('change', e => {
    const cb = e.target.closest('[data-on]'); if (!cb) return; const id = cb.closest('.il-row').dataset.id;
    const off = (C.off || []).filter(x => x !== id); if (!cb.checked) off.push(id);
    if (off.length >= DEF.length) { cb.checked = true; return toast('분위기는 하나 이상 보여야 해요', true); }
    const cfg = clone(C); cfg.off = off;
    post('save', cfg).then(() => { drawList(); toast(cb.checked ? '고객에게 보여요' : '고객에게 안 보여요'); }).catch(er => { cb.checked = !cb.checked; toast(er.message, true); });
});
document.getElementById('resetAll').addEventListener('click', () => {
    if (RO || !confirm('16가지 분위기의 레이아웃 · 순서 · 보이기를 모두 처음 설정으로 되돌릴까요?')) return;
    post('reset').then(() => { dirty = false; W = setOf(cur); sel = -1; drawList(); drawEdit(); toast('처음 설정으로 되돌렸어요'); }).catch(e => toast(e.message, true));
});
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
if (!DEF.length) document.getElementById('edit').innerHTML = '<p class="ie-hint">assets/intro-sets.js 파일을 찾지 못했어요. 그 파일을 같이 올려 주세요.</p>';
else { const first = (location.hash || '').slice(1); pick(ids().includes(first) ? first : ids()[0]); }
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
