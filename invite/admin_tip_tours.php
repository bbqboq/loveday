<?php
/**
 * admin_tip_tours.php - 관리자: 커스텀 편집팁 만들기
 *
 *  고객 에디터에서 화면이 어두워지고 눌러야 할 버튼·자리만 밝게 보이면서, 옆에서 손가락이 콕콕 + "여기를 눌러주세요"
 *  + 설명 창이 뜨는 안내를 직접 만든다. [다음]을 누르면 다음 자리·설명으로.
 *   - 편집팁 여러 개 (이름 · 켜기 · 기기: 공통/PC/모바일 · 뜨는 때: 처음 들어온 고객에게 한 번 / ? 도움말에서만)
 *   - 단계마다: 기기(공통/PC/모바일), 가리킬 곳, 먼저 누를 곳(창을 열어 둬야 보이는 버튼일 때), 제목, 설명(**굵게**), 넘어가는 방법(다음 버튼 / 그곳을 누르면)
 *   - 가리킬 곳은 오른쪽 에디터 화면에서 🎯 고르기를 켜고 직접 눌러서 정한다 (PC 화면 / 모바일 화면 따로)
 *  저장: editor_tips.json 의 custom (editor_tips.php editor_tips_set_custom)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/editor_tips.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string) ($_POST['custom'] ?? ''), true);
    if (!is_array($in)) { echo json_encode(['ok' => false, 'error' => '저장할 내용이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
    if (!editor_tips_set_custom($in)) { echo json_encode(['ok' => false, 'error' => '저장하지 못했어요. uploads/site 폴더 쓰기 권한을 확인해주세요.'], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['ok' => true, 'custom' => editor_tips_get()['custom']], JSON_UNESCAPED_UNICODE);
    exit;
}
$custom = editor_tips_get()['custom'];
$csrf = csrf_token();
$readonly = function_exists('admin_page_readonly') && admin_page_readonly();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>커스텀 편집팁 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
:root { --ink: #2B2320; --muted: #8A8278; --line: #ECE8E2; --acc: #E0A72E; }
.tt-intro { font-size: 13px; color: var(--muted); margin: -14px 0 16px; line-height: 1.75; }
.tt-grid { display: grid; grid-template-columns: minmax(0, 1fr) 470px; gap: 20px; align-items: start; }
@media (max-width: 1180px) { .tt-grid { grid-template-columns: 1fr; } }
.tt-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 14px 16px; margin-bottom: 14px; }
.tt-h { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.tt-h b { font-size: 14.5px; }
.tt-h .sp { flex: 1; }
.tt-btn { height: 34px; padding: 0 14px; border: 0; border-radius: 10px; background: var(--ui-soft, #F3EEE6); color: #4A3F37; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; white-space: nowrap; }
.tt-btn:hover { background: #EAE2D6; }
.tt-btn.dark { background: var(--ink); color: #fff; }
.tt-btn.pick { background: #FFF4D6; color: #8A5A00; }
.tt-btn.pick.on { background: var(--acc); color: #fff; animation: ttBlink 1s ease-in-out infinite; }
@keyframes ttBlink { 50% { opacity: .7; } }
.tt-btn.del { background: #FBEAEA; color: #B03A3A; }
.tt-btn.sm { height: 28px; padding: 0 10px; font-size: 12px; border-radius: 8px; }
.tt-tours { display: flex; flex-wrap: wrap; gap: 8px; }
.tt-tour { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1.5px solid var(--line); border-radius: 12px; background: #FCFAF7; cursor: pointer; font-size: 13.5px; font-weight: 700; }
.tt-tour.sel { border-color: var(--ink); background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,.06); }
.tt-tour .dot { width: 8px; height: 8px; border-radius: 50%; background: #D5CFC6; } .tt-tour .dot.on { background: #34A060; }
.tt-tour small { color: var(--muted); font-weight: 600; }
.tt-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px solid #F3F0EB; }
.tt-row:last-child { border-bottom: 0; }
.tt-row > label { width: 92px; font-size: 12.5px; font-weight: 800; color: #6F6A63; }
.tt-row input[type=text], .tt-row textarea { flex: 1; min-width: 180px; padding: 8px 10px; border: 1px solid #E3DED6; border-radius: 9px; font: inherit; font-size: 13.5px; }
.tt-row textarea { min-height: 64px; resize: vertical; line-height: 1.55; }
.seg { display: inline-flex; gap: 2px; padding: 3px; border-radius: 10px; background: #F3EFE8; }
.seg button { height: 28px; padding: 0 11px; border: 0; border-radius: 8px; background: transparent; font: inherit; font-size: 12.5px; font-weight: 700; color: #7A746B; cursor: pointer; }
.seg button.on { background: #fff; color: var(--ink); box-shadow: 0 1px 3px rgba(0,0,0,.1); }
.sw { position: relative; width: 42px; height: 24px; flex: none; }
.sw input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; z-index: 1; }
.sw i { position: absolute; inset: 0; border-radius: 999px; background: #E3E0DB; transition: background .2s; }
.sw i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.2); transition: transform .2s; }
.sw input:checked + i { background: #34C759; } .sw input:checked + i::after { transform: translateX(18px); }
.tt-step { border: 1px solid var(--line); border-radius: 14px; padding: 10px 12px 6px; margin: 10px 0; background: #FFFDF9; }
.tt-step.cur { border-color: var(--acc); box-shadow: 0 0 0 3px rgba(224,167,46,.15); }
.tt-step-h { display: flex; align-items: center; gap: 8px; }
.tt-step-h .no { width: 24px; height: 24px; border-radius: 50%; background: var(--ink); color: #fff; font-size: 12px; font-weight: 800; display: grid; place-items: center; flex: none; }
.tt-step-h .nm { flex: 1; font-size: 13.5px; font-weight: 800; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tt-sel { display: flex; gap: 6px; flex: 1; min-width: 240px; align-items: center; }
.tt-sel input { font-family: ui-monospace, Menlo, monospace !important; font-size: 12px !important; color: #4A4540; }
.tt-sel .lb { font-size: 11.5px; color: var(--muted); white-space: nowrap; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
.tt-empty { padding: 24px; text-align: center; color: var(--muted); font-size: 13px; }
.tt-pv { position: sticky; top: 76px; }
.tt-pv.big { position: fixed; z-index: 60; top: 70px; right: 16px; bottom: 16px; width: min(1180px, calc(100vw - 32px)); background: #fff; border-radius: 18px; padding: 12px; box-shadow: 0 20px 60px rgba(0,0,0,.25); overflow: auto; }
.tt-pv-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.tt-frame { position: relative; background: var(--ui-soft, #EDE9E3); border-radius: 16px; overflow: hidden; display: flex; justify-content: center; }
.tt-frame iframe { border: 0; background: #fff; transform-origin: 0 0; display: block; }
.tt-tip { font-size: 12px; color: var(--muted); line-height: 1.6; margin: 8px 2px 0; }
.tt-tip b { color: #8A5A00; }
.tt-foot { position: sticky; bottom: 12px; display: flex; align-items: center; gap: 10px; justify-content: flex-end; padding: 10px 12px; background: rgba(255,255,255,.95); border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 8px 24px rgba(0,0,0,.08); }
.tt-foot span { flex: 1; font-size: 12.5px; font-weight: 700; color: var(--muted); }
.tt-foot span.dirty { color: #C2405E; }
.tt-toast { position: fixed; left: 50%; bottom: 80px; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 10px 16px; border-radius: 12px; font-size: 13px; z-index: 99; opacity: 0; transition: opacity .2s; pointer-events: none; }
.tt-toast.on { opacity: 1; }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
<?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('tiptours', '커스텀 편집팁'); ?>
<div class="wrap">
    <h2 class="page-title">커스텀 편집팁</h2>
    <p class="tt-intro">고객 에디터에서 <b>화면이 어두워지고 눌러야 할 곳만 밝게</b> 보이면서 손가락 아이콘과 "여기를 눌러주세요", 설명 창이 뜨는 안내를 만들어요. [다음]을 누르면 다음 자리로 넘어가요.<br>
    가리킬 곳은 오른쪽 에디터 화면에서 <b>🎯 고르기</b>를 누른 뒤 직접 눌러서 정해요. PC·모바일은 화면 모양이 달라서 <b>단계마다 기기를 정하고, 그 기기 화면에서 골라</b> 주세요.</p>

    <div class="tt-grid">
        <div>
            <div class="tt-card">
                <div class="tt-h"><b>편집팁 목록</b><span class="sp"></span><button type="button" class="tt-btn" id="addTour"<?= $readonly ? ' disabled' : '' ?>>＋ 새 편집팁</button></div>
                <div class="tt-tours" id="tours"></div>
            </div>
            <div id="editor"></div>
            <div class="tt-foot"><span id="state">바꾼 내용이 없어요</span><button type="button" class="tt-btn" id="undo" disabled>되돌리기</button><button type="button" class="tt-btn dark" id="save" disabled>저장</button></div>
        </div>
        <aside class="tt-pv">
            <div class="tt-pv-bar">
                <span class="seg" id="devSeg"><button type="button" data-dev="pc" class="on">PC 화면</button><button type="button" data-dev="m">모바일 화면</button></span>
                <span style="flex:1"></span>
                <button type="button" class="tt-btn sm" id="big">⤢ 크게</button>
                <button type="button" class="tt-btn sm" id="reload">↻ 새로</button>
                <button type="button" class="tt-btn sm dark" id="play">▶ 재생</button>
            </div>
            <div class="tt-frame" id="frameBox"><iframe id="pf" title="에디터 화면"></iframe></div>
            <p class="tt-tip" id="pickTip">에디터 화면은 평소처럼 눌러서 창을 열어 둘 수 있어요. 가리킬 곳이 창 안에 있으면 <b>먼저 창을 연 다음 🎯 고르기</b>를 누르세요. (그 창을 여는 버튼은 "먼저 누를 곳"으로 정해 두면 고객 화면에서도 자동으로 열려요)</p>
        </aside>
    </div>
</div>
<div class="tt-toast" id="toast"></div>
<script>
(() => {
    const CSRF = <?= json_encode($csrf) ?>, READONLY = <?= json_encode($readonly) ?>;
    let data = <?= json_encode($custom, JSON_UNESCAPED_UNICODE) ?>;
    if (!data || !Array.isArray(data.tours)) data = { tours: [] };
    let saved = JSON.stringify(data), cur = data.tours[0] ? data.tours[0].id : null, curStep = null, dev = 'pc', picking = null;
    const $ = id => document.getElementById(id);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const uid = p => p + Math.random().toString(36).slice(2, 10);
    const tour = () => data.tours.find(t => t.id === cur);
    const DEV = [['all', '공통'], ['pc', 'PC'], ['m', '모바일']];
    const seg = (key, list, v) => `<span class="seg" data-seg="${key}">${list.map(([k, l]) => `<button type="button" data-v="${k}" class="${v === k ? 'on' : ''}">${l}</button>`).join('')}</span>`;
    function toast(m) { const t = $('toast'); t.textContent = m; t.classList.add('on'); clearTimeout(toast.t); toast.t = setTimeout(() => t.classList.remove('on'), 2200); }
    function dirty() { const d = JSON.stringify(data) !== saved; $('save').disabled = !d || READONLY; $('undo').disabled = !d; $('state').textContent = d ? '바꾼 내용이 아직 저장되지 않았어요' : '바꾼 내용이 없어요'; $('state').classList.toggle('dirty', d); }
    function renderTours() {
        $('tours').innerHTML = data.tours.length ? data.tours.map(t => `<div class="tt-tour${t.id === cur ? ' sel' : ''}" data-tour="${t.id}"><i class="dot${t.on ? ' on' : ''}"></i>${esc(t.name)} <small>${t.steps.length}단계 · ${({ all: '공통', pc: 'PC', m: '모바일' })[t.device]} · ${t.trigger === 'first' ? '처음 한 번' : '? 도움말'}</small></div>`).join('')
            : '<div class="tt-empty" style="padding:8px">아직 없어요. ＋ 새 편집팁을 눌러 만들어 보세요.</div>';
    }
    function renderEditor() {
        const t = tour(), box = $('editor');
        if (!t) { box.innerHTML = ''; return; }
        box.innerHTML = `<div class="tt-card">
            <div class="tt-h"><b>편집팁 설정</b><span class="sp"></span><button type="button" class="tt-btn sm del" data-act="delTour">편집팁 지우기</button></div>
            <div class="tt-row"><label>이름</label><input type="text" data-f="name" maxlength="30" value="${esc(t.name)}" placeholder="예: 사진 바꾸는 법"></div>
            <div class="tt-row"><label>켜기</label><span class="sw"><input type="checkbox" data-f="on" ${t.on ? 'checked' : ''}><i></i></span><small style="color:var(--muted)">끄면 고객에게 안 보여요</small></div>
            <div class="tt-row"><label>보여줄 기기</label>${seg('device', DEV, t.device)}</div>
            <div class="tt-row"><label>뜨는 때</label>${seg('trigger', [['first', '처음 들어온 고객에게 한 번'], ['manual', '? 도움말에서 고를 때만']], t.trigger)}
                ${t.trigger === 'first' ? `<button type="button" class="tt-btn sm" data-act="bump" title="이미 본 고객에게도 한 번 더 보여줘요">이미 본 고객에게 다시 (v${t.ver})</button>` : ''}</div>
        </div>
        <div class="tt-card">
            <div class="tt-h"><b>단계</b><small style="color:var(--muted)">위에서부터 차례로 보여요</small><span class="sp"></span><button type="button" class="tt-btn" data-act="addStep">＋ 단계 추가</button></div>
            ${t.steps.length ? t.steps.map((s, i) => stepHtml(s, i, t.steps.length)).join('') : '<div class="tt-empty">단계를 추가하고, 오른쪽 화면에서 가리킬 곳을 골라 주세요.</div>'}
        </div>`;
    }
    function stepHtml(s, i, n) {
        const pk = f => `<button type="button" class="tt-btn sm pick${picking && picking.step === s.id && picking.field === f ? ' on' : ''}" data-pick="${f}">🎯 고르기</button>`;
        return `<div class="tt-step${curStep === s.id ? ' cur' : ''}" data-step="${s.id}">
            <div class="tt-step-h"><span class="no">${i + 1}</span><span class="nm">${esc(s.title || '(제목 없음)')}</span>
                ${seg('dev', DEV, s.dev)}
                <button type="button" class="tt-btn sm" data-act="up" ${i ? '' : 'disabled'}>▲</button><button type="button" class="tt-btn sm" data-act="down" ${i < n - 1 ? '' : 'disabled'}>▼</button><button type="button" class="tt-btn sm del" data-act="delStep">✕</button></div>
            <div class="tt-row"><label>가리킬 곳</label><span class="tt-sel"><input type="text" data-f="sel" value="${esc(s.sel)}" placeholder="오른쪽 화면에서 🎯 고르기로 정해요">${pk('sel')}<button type="button" class="tt-btn sm" data-act="test" data-tf="sel">확인</button></span></div>
            <div class="tt-row"><label>먼저 누를 곳<br><small style="font-weight:600">(선택)</small></label><span class="tt-sel"><input type="text" data-f="pre" value="${esc(s.pre)}" placeholder="가리킬 곳이 창 안에 있으면 그 창을 여는 버튼">${pk('pre')}<button type="button" class="tt-btn sm" data-act="test" data-tf="pre">확인</button></span></div>
            <div class="tt-row"><label>제목</label><input type="text" data-f="title" maxlength="40" value="${esc(s.title)}" placeholder="예: 사진 바꾸기"></div>
            <div class="tt-row"><label>설명</label><textarea data-f="text" maxlength="400" placeholder="예: 여기를 눌러 **대표 사진**을 바꿔요.">${esc(s.text)}</textarea></div>
            <div class="tt-row"><label>넘어가기</label>${seg('next', [['btn', '[다음] 버튼'], ['click', '그곳을 누르면 다음']], s.next)}</div>
        </div>`;
    }
    function renderAll() { renderTours(); renderEditor(); dirty(); }
    // ---- 편집 ----
    $('addTour').addEventListener('click', () => {
        const t = { id: uid('c'), name: '새 편집팁', on: false, device: 'all', trigger: 'manual', ver: 1, steps: [] };
        data.tours.push(t); cur = t.id; renderAll();
    });
    $('tours').addEventListener('click', e => { const b = e.target.closest('[data-tour]'); if (b) { cur = b.dataset.tour; curStep = null; renderAll(); } });
    $('editor').addEventListener('input', e => {
        const f = e.target.dataset.f; if (!f) return;
        const t = tour(), st = e.target.closest('[data-step]');
        const obj = st ? t.steps.find(s => s.id === st.dataset.step) : t;
        obj[f] = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        if (f === 'title' && st) st.querySelector('.nm').textContent = obj.title || '(제목 없음)';
        if (!st) renderTours();
        dirty();
    });
    $('editor').addEventListener('change', e => { if (e.target.type === 'checkbox') renderTours(); });
    $('editor').addEventListener('focusin', e => { const st = e.target.closest('[data-step]'); if (st && curStep !== st.dataset.step) { curStep = st.dataset.step; document.querySelectorAll('.tt-step').forEach(x => x.classList.toggle('cur', x.dataset.step === curStep)); } });
    $('editor').addEventListener('click', e => {
        const t = tour(); if (!t) return;
        const st = e.target.closest('[data-step]'), s = st ? t.steps.find(x => x.id === st.dataset.step) : null;
        const sg = e.target.closest('[data-seg] button');
        if (sg) { const key = sg.closest('[data-seg]').dataset.seg; (s || t)[key] = sg.dataset.v; renderAll(); return; }
        const pb = e.target.closest('[data-pick]');
        if (pb && s) { // 🎯 고르기 켜기/끄기
            const on = !(picking && picking.step === s.id && picking.field === pb.dataset.pick);
            picking = on ? { step: s.id, field: pb.dataset.pick } : null; curStep = s.id;
            if (on && s.dev !== 'all' && s.dev !== dev) setDev(s.dev); // 단계 기기에 맞는 화면으로
            post({ type: 'ld-tippick', on, field: pb.dataset.pick });
            if (on) toast('오른쪽 화면에서 ' + (pb.dataset.pick === 'pre' ? '먼저 누를 곳' : '가리킬 곳') + '을 눌러 주세요');
            renderEditor(); return;
        }
        const a = e.target.closest('[data-act]'); if (!a) return;
        const act = a.dataset.act;
        if (act === 'addStep') { const ns = { id: uid('s'), dev: dev === 'm' ? 'm' : 'all', sel: '', pre: '', title: '', text: '', next: 'btn' }; t.steps.push(ns); curStep = ns.id; renderAll(); }
        else if (act === 'delStep' && s) { if (confirm('이 단계를 지울까요?')) { t.steps.splice(t.steps.indexOf(s), 1); renderAll(); } }
        else if ((act === 'up' || act === 'down') && s) { const i = t.steps.indexOf(s), j = i + (act === 'up' ? -1 : 1); t.steps.splice(i, 1); t.steps.splice(j, 0, s); renderAll(); }
        else if (act === 'delTour') { if (confirm(`"${t.name}" 편집팁을 지울까요?`)) { data.tours.splice(data.tours.indexOf(t), 1); cur = data.tours[0] ? data.tours[0].id : null; renderAll(); } }
        else if (act === 'bump') { t.ver = (t.ver || 1) + 1; renderAll(); toast('저장하면 이미 본 고객에게도 한 번 더 보여요'); }
        else if (act === 'test' && s) { const v = s[a.dataset.tf]; if (!v) { toast('먼저 🎯 고르기로 정해 주세요'); return; } if (s.dev !== 'all' && s.dev !== dev) setDev(s.dev, () => post({ type: 'ld-tippick-test', sel: v })); else post({ type: 'ld-tippick-test', sel: v }); }
    });
    $('undo').addEventListener('click', () => { data = JSON.parse(saved); if (!tour()) cur = data.tours[0] ? data.tours[0].id : null; renderAll(); });
    $('save').addEventListener('click', () => {
        const fd = new FormData(); fd.set('csrf_token', CSRF); fd.set('custom', JSON.stringify(data));
        $('save').disabled = true;
        fetch('admin_tip_tours.php', { method: 'POST', body: fd }).then(r => r.json()).then(j => {
            if (!j.ok) throw new Error(j.error || '저장하지 못했어요.');
            data = j.custom; saved = JSON.stringify(data); if (!tour()) cur = data.tours[0] ? data.tours[0].id : null;
            renderAll(); toast('저장했어요. 고객 에디터에 바로 반영돼요.');
        }).catch(er => { dirty(); alert(er.message); });
    });
    // ---- 오른쪽 에디터 화면 (?tippick=1) ----
    const pf = $('pf'), fb = $('frameBox');
    let readyCb = null;
    function sizeFrame() {
        const W = (fb.clientWidth || 470), big = document.querySelector('.tt-pv').classList.contains('big');
        const [fw, fh] = dev === 'pc' ? [1280, 820] : [390, 780];
        const k = Math.min(1, W / fw, big ? (window.innerHeight - 160) / fh : 9);
        pf.style.width = fw + 'px'; pf.style.height = fh + 'px'; pf.style.transform = `scale(${k})`;
        fb.style.height = Math.round(fh * k) + 'px';
        pf.style.marginRight = (fw * k - fw) + 'px'; // 줄어든 만큼 자리도 줄임
    }
    function loadFrame(cb) { readyCb = cb || null; sizeFrame(); pf.src = 'editor-prototype-v3-overlay.html?tippick=1&_=' + Date.now(); }
    function setDev(d, cb) { dev = d; document.querySelectorAll('#devSeg button').forEach(b => b.classList.toggle('on', b.dataset.dev === d)); loadFrame(cb); }
    const post = m => { try { pf.contentWindow.postMessage(m, location.origin); } catch (e) {} };
    window.addEventListener('message', e => {
        if (e.origin !== location.origin || !e.data) return;
        const d = e.data;
        if (d.type === 'ld-tippick-ready') { if (picking) post({ type: 'ld-tippick', on: true, field: picking.field }); if (readyCb) { const c = readyCb; readyCb = null; setTimeout(c, 300); } }
        else if (d.type === 'ld-tippick-done' && picking) {
            const t = tour(), s = t && t.steps.find(x => x.id === picking.step);
            if (s) { s[picking.field] = d.sel; toast('골랐어요: ' + (d.label || d.sel)); if (picking.field === 'sel' && !s.title && d.label) s.title = d.label.slice(0, 40); }
            picking = null; renderAll();
        }
        else if (d.type === 'ld-tippick-test-r') toast(d.ok ? '찾았어요 - 오른쪽 화면에 노란 칸으로 표시했어요' : '지금 화면에서 못 찾았어요. 창을 연 상태인지, 기기(PC/모바일)가 맞는지 확인해 주세요');
    });
    document.querySelectorAll('#devSeg button').forEach(b => b.addEventListener('click', () => { if (b.dataset.dev !== dev) setDev(b.dataset.dev); }));
    $('reload').addEventListener('click', () => loadFrame());
    $('big').addEventListener('click', () => { const pv = document.querySelector('.tt-pv'); const on = pv.classList.toggle('big'); $('big').textContent = on ? '✕ 작게' : '⤢ 크게'; sizeFrame(); }); // PC 화면은 작게 보여서 크게 펼쳐 고르기
    $('play').addEventListener('click', () => { const t = tour(); if (!t || !t.steps.length) { toast('재생할 단계가 없어요'); return; } picking = null; renderEditor(); post({ type: 'ld-ctour-play', tour: t }); });
    window.addEventListener('resize', sizeFrame);
    window.addEventListener('beforeunload', e => { if (JSON.stringify(data) !== saved) { e.preventDefault(); e.returnValue = ''; } });
    renderAll(); loadFrame();
})();
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
