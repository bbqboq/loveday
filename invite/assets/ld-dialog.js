/**
 * ld-dialog.js - 크롬 기본 알림창(alert / confirm / prompt) 대신 쓰는 LOVE DAY 팝업
 *
 * 쓰는 법 (모두 Promise를 돌려준다):
 *   LD.alert('저장했어요')                                   → 확인을 누르면 끝
 *   LD.confirm('삭제할까요?', { ok: '삭제', danger: true })   → true / false
 *   LD.prompt('별칭', '기본값', { placeholder: '예: 본식용' }) → 입력한 글 / 취소하면 null
 *   LD.copy('https://loveday.kr/abc')                        → 클립보드 복사, 안 되면 복사용 칸을 띄움
 *   LD.toast('복사했어요')                                   → 화면 아래에 잠깐 떴다 사라지는 안내
 *   LD.confirm('…', { check: '동의합니다' })                  → 체크해야 확인 버튼이 눌림 (동의 받기)
 *   LD.confirm('…', { note: '작은 글씨 안내' })               → 버튼 위 작은 안내문
 *
 * 예전 방식 자동 변환:
 *   onsubmit="return confirm('…')"  /  onclick="return confirm('…')"  가 붙은 폼·버튼은
 *   이 파일만 불러오면 자동으로 팝업으로 바뀐다. (관리자 페이지 등 코드를 따로 안 고쳐도 됨)
 *   새로 만들 때는 data-confirm="메시지" (+ data-confirm-ok="삭제" data-confirm-danger) 를 쓰면 된다.
 *
 * CSS는 이 파일이 직접 넣는다 - <script src="assets/ld-dialog.js"></script> 한 줄이면 끝.
 */
(function (global) {
    'use strict';
    if (global.LD && global.LD.confirm) return;

    var CSS = '' +
        '.ld-dlg{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(27,26,24,.38);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px);opacity:0;transition:opacity .18s ease}' +
        '.ld-dlg.show{opacity:1}' +
        '.ld-dlg-box{width:min(360px,100%);background:#fff;border-radius:20px;box-shadow:0 24px 60px rgba(30,20,15,.22);padding:26px 22px 18px;transform:translateY(10px) scale(.97);transition:transform .2s cubic-bezier(.2,.9,.3,1.2);font-family:"Pretendard Variable",Pretendard,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;color:#1B1A18;text-align:center;word-break:keep-all;-webkit-font-smoothing:antialiased}' +
        '.ld-dlg.show .ld-dlg-box{transform:none}' +
        '.ld-dlg-ic{width:46px;height:46px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;background:#F4ECEC;color:#8A4B55}' +
        '.ld-dlg-ic.danger{background:#FBEAEA;color:#B24A4A}' +
        '.ld-dlg-ic svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}' +
        '.ld-dlg-t{margin:0 0 6px;font-size:17px;font-weight:700;letter-spacing:-.02em;line-height:1.4}' +
        '.ld-dlg-m{margin:0;font-size:14px;line-height:1.65;color:#6F6A63;white-space:pre-line;word-break:keep-all}' +
        '.ld-dlg-in{display:block;width:100%;box-sizing:border-box;margin:16px 0 0;padding:12px 14px;border:1px solid #EAE6E0;border-radius:12px;font:inherit;font-size:15px;color:#1B1A18;background:#FAF9F7;outline:none;text-align:left}' +
        '.ld-dlg-in:focus{border-color:#1B1A18;background:#fff}' +
        '.ld-dlg-chk{display:flex;gap:10px;align-items:flex-start;text-align:left;margin:16px 0 0;padding:12px 14px;border-radius:12px;background:#FAF9F7;border:1px solid #EAE6E0;font-size:13.5px;line-height:1.55;color:#1B1A18;cursor:pointer}' +
        '.ld-dlg-chk input{flex:none;width:18px;height:18px;margin:1px 0 0;accent-color:#1B1A18;cursor:pointer}' +
        '.ld-dlg-chk.on{border-color:#1B1A18;background:#fff}' +
        '.ld-dlg-sub{margin:10px 0 0;font-size:12px;line-height:1.6;color:#A39D95;text-align:left;white-space:pre-line}' +
        '.ld-dlg-ok:disabled{opacity:.35;cursor:not-allowed;filter:none}' +
        '.ld-dlg-btns{display:flex;gap:8px;margin-top:20px}' +
        '.ld-dlg-btns button{flex:1;border:0;border-radius:12px;padding:13px 10px;font:inherit;font-size:14.5px;font-weight:600;cursor:pointer;transition:filter .15s}' +
        '.ld-dlg-btns button:hover{filter:brightness(.96)}' +
        '.ld-dlg-no{background:#F1EEEA;color:#6F6A63}' +
        '.ld-dlg-ok{background:#1B1A18;color:#fff}' +
        '.ld-dlg-ok.danger{background:#B24A4A}' +
        '.ld-toast{position:fixed;left:50%;bottom:28px;z-index:2147483001;transform:translate(-50%,16px);opacity:0;background:rgba(27,26,24,.92);color:#fff;font:500 13.5px/1.4 "Pretendard Variable",Pretendard,-apple-system,sans-serif;padding:11px 18px;border-radius:999px;transition:.25s;pointer-events:none;max-width:90vw;text-align:center}' +
        '.ld-toast.show{opacity:1;transform:translate(-50%,0)}';

    var ICONS = {
        info: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
        ask: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5v.2M12 17h.01"/></svg>',
        danger: '<svg viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>',
        edit: '<svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>',
        link: '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>',
        clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></svg>'
    };

    function injectCss() {
        if (document.getElementById('ld-dialog-css')) return;
        var s = document.createElement('style'); s.id = 'ld-dialog-css'; s.textContent = CSS;
        (document.head || document.documentElement).appendChild(s);
    }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    var queue = Promise.resolve();
    // 팝업 하나를 띄운다. 여러 개가 동시에 요청되면 차례대로 보여준다.
    function open(o) {
        var run = function () {
            return new Promise(function (resolve) {
                injectCss();
                var wrap = document.createElement('div');
                wrap.className = 'ld-dlg';
                var danger = !!o.danger;
                var icon = o.icon || (danger ? 'danger' : o.kind === 'confirm' ? 'ask' : o.kind === 'prompt' ? 'edit' : 'info');
                wrap.innerHTML = '<div class="ld-dlg-box" role="' + (o.kind === 'alert' ? 'alertdialog' : 'dialog') + '" aria-modal="true">' +
                    (icon !== 'none' ? '<div class="ld-dlg-ic' + (danger ? ' danger' : '') + '">' + (ICONS[icon] || ICONS.info) + '</div>' : '') +
                    (o.title ? '<p class="ld-dlg-t">' + esc(o.title) + '</p>' : '') +
                    (o.message ? '<p class="ld-dlg-m">' + esc(o.message) + '</p>' : '') +
                    (o.kind === 'prompt' || o.kind === 'copy' ? '<input class="ld-dlg-in" type="text"' + (o.readonly ? ' readonly' : '') +
                        ' value="' + esc(o.value || '') + '" placeholder="' + esc(o.placeholder || '') + '"' + (o.maxLength ? ' maxlength="' + (+o.maxLength) + '"' : '') + '>' : '') +
                    (o.check ? '<label class="ld-dlg-chk"><input type="checkbox"><span>' + esc(o.check) + '</span></label>' : '') +
                    (o.note ? '<p class="ld-dlg-sub">' + esc(o.note) + '</p>' : '') +
                    '<div class="ld-dlg-btns">' +
                    (o.kind !== 'alert' && o.kind !== 'copy' ? '<button type="button" class="ld-dlg-no">' + esc(o.cancel || '취소') + '</button>' : '') +
                    '<button type="button" class="ld-dlg-ok' + (danger ? ' danger' : '') + '">' + esc(o.ok || '확인') + '</button>' +
                    '</div></div>';
                document.body.appendChild(wrap);
                var input = wrap.querySelector('.ld-dlg-in');
                var okBtn = wrap.querySelector('.ld-dlg-ok');
                var chk = wrap.querySelector('.ld-dlg-chk input');
                if (chk) { // 체크해야 확인 버튼이 눌린다 (동의 받기용)
                    okBtn.disabled = true;
                    chk.addEventListener('change', function () { okBtn.disabled = !chk.checked; chk.parentNode.classList.toggle('on', chk.checked); });
                }
                var prevFocus = document.activeElement;
                var done = false;
                function finish(val) {
                    if (done) return; done = true;
                    document.removeEventListener('keydown', onKey, true);
                    wrap.classList.remove('show');
                    setTimeout(function () { wrap.remove(); }, 200);
                    try { prevFocus && prevFocus.focus && prevFocus.focus({ preventScroll: true }); } catch (e) {}
                    resolve(val);
                }
                var cancelVal = o.kind === 'prompt' ? null : o.kind === 'confirm' ? false : undefined;
                function okVal() { return o.kind === 'prompt' ? input.value : o.kind === 'confirm' ? true : undefined; }
                function onKey(e) {
                    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(cancelVal); }
                    else if (e.key === 'Enter' && !e.isComposing && (document.activeElement === input || !input) && !okBtn.disabled) { e.preventDefault(); e.stopPropagation(); finish(okVal()); }
                    else if (e.key === 'Tab') { // 팝업 밖으로 포커스가 나가지 않게
                        var f = wrap.querySelectorAll('input,button'); if (!f.length) return;
                        var first = f[0], last = f[f.length - 1];
                        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
                    }
                }
                document.addEventListener('keydown', onKey, true);
                okBtn.addEventListener('click', function () { if (!okBtn.disabled) finish(okVal()); });
                var no = wrap.querySelector('.ld-dlg-no'); if (no) no.addEventListener('click', function () { finish(cancelVal); });
                wrap.addEventListener('click', function (e) { if (e.target === wrap) finish(o.kind === 'alert' || o.kind === 'copy' ? undefined : cancelVal); });
                requestAnimationFrame(function () {
                    wrap.classList.add('show');
                    if (chk) chk.focus({ preventScroll: true });
                    else if (input) { input.focus(); if (o.kind === 'copy') input.select(); else input.setSelectionRange(input.value.length, input.value.length); }
                    else okBtn.focus({ preventScroll: true });
                });
            });
        };
        var p = queue.then(run, run);
        queue = p.then(function () {}, function () {});
        return p;
    }

    // 첫 줄은 굵은 제목, 나머지는 설명으로 나눠서 보여준다 (예전 confirm 문구를 그대로 넣어도 보기 좋게)
    function split(msg, opts) {
        opts = opts || {};
        if (opts.title !== undefined) return { title: opts.title, message: msg };
        if (opts.message !== undefined) return { title: msg, message: opts.message };
        var s = String(msg == null ? '' : msg).trim();
        var i = s.indexOf('\n');
        if (i < 0) {
            var m = s.match(/^(.+?[?.!])\s+(.+)$/); // "정말 삭제하시겠습니까? 되돌릴 수 없습니다." → 제목 + 설명
            return m ? { title: m[1], message: m[2] } : { title: s, message: '' };
        }
        return { title: s.slice(0, i).trim(), message: s.slice(i + 1).trim() };
    }
    function assign(a, b) { for (var k in b) if (b[k] !== undefined) a[k] = b[k]; return a; }

    var LD = global.LD || {};
    LD.alert = function (msg, opts) { var t = split(msg, opts); return open(assign({ kind: 'alert' }, assign(assign({}, opts || {}), t))); };
    LD.confirm = function (msg, opts) { var t = split(msg, opts); return open(assign({ kind: 'confirm' }, assign(assign({}, opts || {}), t))); };
    LD.prompt = function (msg, value, opts) { var t = split(msg, opts); return open(assign({ kind: 'prompt', value: value == null ? '' : value }, assign(assign({}, opts || {}), t))); };
    LD.toast = function (msg, ms) {
        injectCss();
        var t = document.querySelector('.ld-toast');
        if (!t) { t = document.createElement('div'); t.className = 'ld-toast'; document.body.appendChild(t); }
        t.textContent = msg; void t.offsetWidth; t.classList.add('show');
        clearTimeout(t._t); t._t = setTimeout(function () { t.classList.remove('show'); }, ms || 1800);
    };
    LD.copy = function (text, doneMsg) {
        var fallback = function () { return open({ kind: 'copy', icon: 'link', title: '아래 주소를 길게 눌러 복사해주세요', value: text, readonly: true, ok: '닫기' }); };
        if (navigator.clipboard && global.isSecureContext !== false) {
            return navigator.clipboard.writeText(text).then(function () { LD.toast(doneMsg || '복사했어요'); return true; }, fallback);
        }
        return fallback();
    };

    // ---------- 예전 confirm('…') 자동 변환 ----------
    var RE = /^\s*return\s+confirm\(\s*(['"`])([\s\S]*)\1\s*\)\s*;?\s*$/;
    function unq(s) { return s.replace(/\\n/g, '\n').replace(/\\(['"`\\])/g, '$1'); }
    function upgrade(root) {
        (root || document).querySelectorAll('[onsubmit*="confirm("],[onclick*="confirm("]').forEach(function (el) {
            ['onsubmit', 'onclick'].forEach(function (attr) {
                var v = el.getAttribute(attr); if (!v) return;
                var m = v.match(RE); if (!m) return;
                el.removeAttribute(attr);
                el.setAttribute('data-confirm', unq(m[2]));
                if (/삭제|비우|지울|되돌릴 수 없/.test(m[2]) && !el.hasAttribute('data-confirm-danger')) el.setAttribute('data-confirm-danger', '');
            });
        });
    }
    var bypass = false;
    function ask(el) {
        var msg = el.getAttribute('data-confirm');
        var danger = el.hasAttribute('data-confirm-danger');
        return LD.confirm(msg, { danger: danger, ok: el.getAttribute('data-confirm-ok') || (danger ? '삭제' : '확인') });
    }
    // 버튼(또는 링크)에 붙은 확인
    document.addEventListener('click', function (e) {
        if (bypass) return;
        var el = e.target.closest && e.target.closest('[data-confirm]');
        if (!el || el.tagName === 'FORM') return;
        e.preventDefault(); e.stopImmediatePropagation();
        ask(el).then(function (ok) {
            if (!ok) return;
            var form = el.form || el.closest('form');
            if (el.tagName === 'A' && el.href) { location.href = el.href; return; }
            if (form && (el.type === 'submit' || el.tagName === 'BUTTON' && !el.getAttribute('type'))) {
                bypass = true;
                try { form.requestSubmit ? form.requestSubmit(el) : (function () { var h = document.createElement('input'); h.type = 'hidden'; h.name = el.name; h.value = el.value; if (el.name) form.appendChild(h); form.submit(); })(); }
                finally { bypass = false; }
            } else { bypass = true; try { el.click(); } finally { bypass = false; } }
        });
    }, true);
    // 폼 전체에 붙은 확인
    document.addEventListener('submit', function (e) {
        if (bypass) return;
        var form = e.target;
        if (!form.hasAttribute || !form.hasAttribute('data-confirm')) return;
        e.preventDefault(); e.stopImmediatePropagation();
        var submitter = e.submitter;
        ask(form).then(function (ok) {
            if (!ok) return;
            bypass = true;
            try {
                if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                else form.submit();
            } finally { bypass = false; }
        });
    }, true);

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { upgrade(); });
    else upgrade();
    LD.upgrade = upgrade;

    /*
     * 저장 안 한 변경이 있을 때 "나가기" 확인 - 크롬 기본 "사이트에서 나가시겠습니까?" 대신 LOVE DAY 팝업
     *   var guard = LD.guardLeave(function () { return 바뀐게있으면true; }, { except: '#저장폼' });
     *   guard.release();  // 저장 직후 새로고침할 때처럼 확인 없이 나가야 할 때
     * 사이트 안의 링크·버튼·폼 제출·뒤로 가기는 이 팝업으로 묻는다.
     * 새로고침·탭 닫기·주소창에 직접 입력은 브라우저가 보안 규칙상 자기 기본 창만 허용해서, 그때만 크롬 창이 뜬다.
     */
    LD.guardLeave = function (isDirty, opts) {
        opts = opts || {};
        var leaving = false, armed = false, skipPop = false;
        var dirty = function () { try { return !leaving && !!isDirty(); } catch (e) { return false; } };
        function askLeave() {
            return LD.confirm('저장하지 않고 나갈까요?', {
                message: opts.message || '바꾼 내용이 아직 저장되지 않았어요.\n지금 나가면 바꾼 내용이 사라져요.',
                ok: '저장 안 하고 나가기', cancel: '계속 편집', danger: true, icon: 'ask',
            });
        }
        // 새로고침·탭 닫기 (브라우저 기본 창만 가능)
        global.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
        // 링크 (새 탭·다운로드·같은 화면 안 이동은 제외)
        document.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
            var href = a.getAttribute('href') || '';
            if (href === '' || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
            if (!dirty()) return;
            e.preventDefault();
            askLeave().then(function (ok) { if (ok) { leaving = true; location.href = a.href; } });
        });
        // 다른 폼 제출 (저장 폼은 except로 빼고, 새로고침 없이 처리하는 폼은 스스로 막으니 그대로 둠)
        document.addEventListener('submit', function (e) {
            var f = e.target;
            if (e.defaultPrevented || !dirty() || (opts.except && f.matches && f.matches(opts.except))) return;
            e.preventDefault();
            var sub = e.submitter;
            askLeave().then(function (ok) {
                if (!ok) return;
                leaving = true;
                if (f.requestSubmit) f.requestSubmit(sub && sub.form === f ? sub : undefined); else f.submit();
            });
        });
        // 뒤로 가기: 바뀐 게 생기면 기록을 한 칸 끼워 두고, 뒤로 가면 팝업으로 물음
        function arm() { if (armed) return; armed = true; try { history.pushState({ ldGuard: 1 }, ''); } catch (err) { armed = false; } }
        global.addEventListener('popstate', function () {
            if (skipPop) { skipPop = false; return; }
            if (!armed) return;
            armed = false;
            if (!dirty()) { history.back(); return; }
            askLeave().then(function (ok) { if (ok) { leaving = true; history.back(); } else arm(); });
        });
        setInterval(function () {
            if (dirty()) arm();
            else if (armed && !leaving) { armed = false; skipPop = true; history.back(); } // 저장해서 깨끗해지면 끼워 둔 기록을 치움
        }, 600);
        return { release: function () { leaving = true; } };
    };
    global.LD = LD;
})(window);
