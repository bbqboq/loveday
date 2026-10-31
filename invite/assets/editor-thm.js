// 디자인 썸네일 만들기 (관리자 → 디자인 → [썸네일 만들기] = 에디터 ?thumbs=make) - 에디터 editor.js에서 따로 뺌 (1019s)
//  editor.js가 THUMB_MAKE일 때만 ldChunk('thm')로 불러옴. editor.js의 전역(state · dpkShot · esc …)을 그대로 씀.
// ===== 디자인 썸네일 만들기 (관리자 → 추천 디자인 → [썸네일 만들기] = 에디터 ?thumbs=make) =====
//  디자인마다 dpkShot(위쪽 섹션까지)으로 그린 청첩장을 화면 왼쪽 위(390px 칸 .thm-cap)에 띄우고, '이 탭 공유'(getDisplayMedia)로
//  화면을 그대로 찍어 첫 화면(390×844)·긴 화면(최대 2600px, 칸보다 길면 몇 번에 나눠 찍어 이어 붙임)을 WebP로 dpk_thumbs.php에 올림.
//  (DOM을 그림으로 바꾸는 라이브러리는 마스크·color-mix·글꼴 등을 제대로 못 그려서 화면을 그대로 찍음 · PC 크롬·엣지만)
//  찍기 전에 초록 칸을 띄워 동영상 속 그 칸의 자리를 찾아 맞춤(thmCalib) - 화면 배율·창 크기가 달라도 맞게
const THM = { csrf: '', items: {}, st: {}, run: false, stop: false };
const THM_MAX_H = 2600, THM_COVER_H = 844, THM_OUT = 1.5; // 그림 배율 (390px → 585px)
const thm$ = id => document.getElementById(id);
function thmMsg(t, bad) { const m = thm$('thmMsg'); if (m) { m.textContent = t || ''; m.classList.toggle('bad', !!bad); } }
function thmIds() { return allDesigns().map(p => p.id); }
function thmStat(id) { const t = THM.items[id]; return THM.st[id] || (!t ? 'no' : t.sig === dpkSig(id) ? 'ok' : 'old'); }
const THM_LBL = { no: '그림 없음', old: '예전 그림', ok: '✓ 최신', run: '찍는 중…', up: '올리는 중…', done: '✓ 저장함', bad: '못 찍음' };
function thmDraw(now) {
    const ids = thmIds(), l = thm$('thmL'); if (!l) return;
    l.innerHTML = ids.map(id => { const p = allDesigns().find(x => x.id === id) || {}, t = THM.items[id], st = thmStat(id);
        return `<div class="thm-r${id === now ? ' now' : ''}${id === THM_ONLY ? ' pick' : ''}" data-thm-id="${esc(id)}">${t ? `<img src="${esc(t.c)}" alt="" loading="lazy">` : '<i></i>'}<b>${esc(p.label || id)}</b><small class="${st === 'done' ? 'ok' : st}">${THM_LBL[st] || ''}</small>`
            + `<span class="thm-ra"><button type="button" data-thm-shot="${esc(id)}"${THM.run || !THM.csrf ? ' disabled' : ''} title="이 디자인만 다시 찍기">${t ? '다시 찍기' : '찍기'}</button>${t ? `<button type="button" class="del" data-thm-del="${esc(id)}"${THM.run ? ' disabled' : ''} title="이 디자인 그림만 지우기">지우기</button>` : ''}</span></div>`; }).join('');
    const todo = ids.filter(id => ['no', 'old', 'bad'].includes(thmStat(id))).length;
    const a = thm$('thmGoNew'), b = thm$('thmGoAll');
    if (a) { a.textContent = todo ? `바뀐 것만 찍기 (${todo}개)` : '모두 최신이에요'; a.disabled = THM.run || !todo; }
    if (b) { b.textContent = THM.run ? '멈추기' : `모두 다시 찍기 (${ids.length}개)`; b.disabled = false; }
    if (now) { const r = l.querySelector('.thm-r.now'); if (r) r.scrollIntoView({ block: 'nearest' }); }
    else if (THM_ONLY && !thmDraw.seen) { const r = l.querySelector('.thm-r.pick'); if (r) { thmDraw.seen = true; r.scrollIntoView({ block: 'center' }); } }
}
// 디자인 하나만: [다시 찍기] = 그 디자인만 찍기 · [지우기] = 그 디자인 그림만 지움 (고객 화면은 다시 찍을 때까지 진짜로 그려서 보여 줌)
const THM_ONLY = (new URLSearchParams(location.search).get('only') || '').replace(/[^a-z0-9-]/gi, ''); // 관리자 → 추천 디자인 썸네일 칸 [다시 찍기]로 열면 그 디자인을 짚어 둠
async function thmDel(id) {
    if (THM.run || !THM.items[id]) return;
    const p = allDesigns().find(x => x.id === id) || {};
    if (!(await LD.confirm(`'${p.label || id}' 썸네일을 지울까요?`, { message: '고객 디자인 고르기 화면은 다시 찍을 때까지 진짜로 그려서 보여 줘요.', ok: '지우기', cancel: '그대로 두기' }))) return;
    const fd = new FormData(); fd.append('csrf_token', THM.csrf); fd.append('act', 'del'); fd.append('id', id);
    try { const j = await fetch('dpk_thumbs.php', { method: 'POST', body: fd }).then(x => x.json()); if (!j.ok) throw new Error(j.error || ''); delete THM.items[id]; delete THM.st[id]; thmMsg(`'${p.label || id}' 썸네일을 지웠어요.`); }
    catch (e) { thmMsg('지우지 못했어요. 새로고침 후 다시 해 주세요.', true); }
    thmDraw();
}
async function thmOpen() {
    document.documentElement.classList.add('thm-on'); DPK.off = true; // (뒤 디자인 고르기 화면은 그리지 않음)
    const el = document.createElement('div'); el.className = 'thm'; el.id = 'thm';
    el.innerHTML = `<div class="thm-h"><b>디자인 썸네일 만들기</b><p>${THM_DOM ? '디자인마다 첫 화면·긴 화면 그림을 만들어 두면 고객 디자인 고르기 화면이 빨라져요. 휴대폰에서는 청첩장을 그림으로 바꿔서 찍어요 (디자인 하나에 몇 초). 다 찍을 때까지 화면을 끄거나 다른 앱으로 가지 마세요.' : `디자인마다 첫 화면·긴 화면 그림을 찍어 두면 고객 디자인 고르기 화면이 빨라져요. [찍기]를 누르면 크롬·엣지가 <b>'이 탭 공유'</b>를 물어요 → <b>[공유]</b>를 눌러 주세요. 다 찍을 때까지 이 창을 그대로 두세요 (다른 탭으로 가면 멈춰요).`}</p></div>
        <p class="thm-msg" id="thmMsg">디자인 목록을 불러오는 중…</p><div class="thm-l" id="thmL"></div>
        <div class="thm-f"><button type="button" class="main" id="thmGoNew" disabled>바뀐 것만 찍기</button><button type="button" id="thmGoAll" disabled>모두 다시 찍기</button><a href="admin_designs.php#thumbs">추천 디자인 관리로</a></div>`;
    document.body.appendChild(el);
    thm$('thmGoNew').addEventListener('click', () => thmRun(thmIds().filter(id => ['no', 'old', 'bad'].includes(thmStat(id)))));
    thm$('thmGoAll').addEventListener('click', () => { if (THM.run) { THM.stop = true; thmMsg('지금 찍는 디자인까지만 하고 멈춰요…'); } else thmRun(thmIds()); });
    thm$('thmL').addEventListener('click', e => { const a = e.target.closest('[data-thm-shot]'), d = e.target.closest('[data-thm-del]'); if (a && !a.disabled) thmRun([a.dataset.thmShot]); else if (d && !d.disabled) thmDel(d.dataset.thmDel); });
    const t0 = Date.now(); while (!(PRESETS_OK && SAMPLE_LIST_OK && DPK_SECDEF) && Date.now() - t0 < 8000) await new Promise(r => setTimeout(r, 150)); // (디자인·샘플·메인 사진 예시를 다 받은 뒤 - 지문이 고객 화면과 같게)
    try { const j = await fetch('dpk_thumbs.php?csrf=1', { cache: 'no-store' }).then(r => r.json()); if (!j.ok || !j.csrf) throw 0; THM.csrf = j.csrf; }
    catch (e) { thmMsg('관리자로 로그인한 뒤 다시 열어 주세요. (관리자 → 추천 디자인 → [썸네일 만들기])', true); return; }
    try { const j = await fetch('dpk_thumbs.php', { cache: 'no-store' }).then(r => r.json()); THM.items = (j && j.items) || {}; } catch (e) {}
    const okEnv = THM_DOM || (navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia && window.innerWidth >= 820);
    thmMsg(okEnv ? (THM_ONLY && thmIds().includes(THM_ONLY) ? `짚어 둔 디자인 줄의 [${THM.items[THM_ONLY] ? '다시 찍기' : '찍기'}]를 누르면 그 디자인만 찍어요.` : '') : (window.innerWidth < 820 ? '창을 더 넓게(820px 이상) 해 주세요.' : '이 브라우저는 화면을 찍을 수 없어요. PC 크롬이나 엣지에서 열어 주세요.'), !okEnv);
    thmDraw();
}
// 화면을 찍을 칸 (왼쪽 위 390px) - 높이는 화면 높이까지
function thmCapEl() { let c = thm$('thmCap'); if (!c) { c = document.createElement('div'); c.className = 'thm-cap'; c.id = 'thmCap'; document.body.appendChild(c); } c.style.height = Math.min(window.innerHeight, 1000) + 'px'; return c; }
const thmWait = ms => new Promise(r => setTimeout(r, ms));
// 바꾼 화면이 동영상에 들어올 때까지 기다림 (새 장면 두 번 또는 0.5초)
async function thmFrame(v) {
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    await thmWait(90);
    if (v.requestVideoFrameCallback) for (let i = 0; i < 2; i++) await Promise.race([new Promise(r => v.requestVideoFrameCallback(() => r())), thmWait(260)]);
    else await thmWait(300);
}
// 초록 칸을 띄워 동영상 속 자리 찾기 → {x, y, w, h} (칸 390 × 높이 = 동영상의 이 네모)
async function thmCalib(v, cap) {
    cap.innerHTML = '<div style="position:absolute;inset:0;background:#00FF00"></div>';
    await thmFrame(v);
    const W = v.videoWidth, H = v.videoHeight; if (!W || !H) return null;
    const c = document.createElement('canvas'); c.width = W; c.height = H; const x = c.getContext('2d', { willReadFrequently: true }); x.drawImage(v, 0, 0);
    const d = x.getImageData(0, 0, W, H).data; let x0 = W, y0 = H, x1 = -1, y1 = -1;
    for (let y = 0; y < H; y += 1) for (let i = (y * W) * 4, e = i + W * 4; i < e; i += 4) if (d[i] < 70 && d[i + 1] > 190 && d[i + 2] < 70) { const px = (i / 4) % W; if (px < x0) x0 = px; if (px > x1) x1 = px; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    cap.innerHTML = '';
    if (x1 < 0 || x1 - x0 < 100) return null;
    return { x: x0, y: y0, w: x1 - x0 + 1, h: y1 - y0 + 1, cw: 390, ch: cap.clientHeight, iw: window.innerWidth, ih: window.innerHeight };
}
async function thmImgs(root) {
    const ims = [...root.querySelectorAll('img')];
    await Promise.race([Promise.all(ims.map(im => im.complete ? 0 : new Promise(r => { im.onload = im.onerror = r; }))), thmWait(6000)]);
    try { await Promise.race([document.fonts.ready, thmWait(3000)]); } catch (e) {}
}
const thmBlob = (cv, q) => new Promise(r => cv.toBlob(b => { if (b && b.type === 'image/webp') r(b); else cv.toBlob(r, 'image/jpeg', .84); }, 'image/webp', q));
// 디자인 하나 찍기 → {c: Blob, l: Blob, h}
async function thmShoot(id, v, cal) {
    delete DPK.shots[id];
    const s = await dpkShot(id, 's'); if (!s) throw new Error('그리지 못했어요');
    const cap = thmCapEl(); cap.innerHTML = '';
    const el = dpkIvEl({ cls: s.cls, style: s.style, html: dpkSlim(s) }); el.style.animation = 'none'; cap.appendChild(el);
    await thmImgs(el); await thmWait(700);
    cap.querySelectorAll('*').forEach(n => { n.style.animationPlayState = 'paused'; }); // (장식 효과는 0.7초 흐른 모습 그대로)
    const H = Math.min(THM_MAX_H, Math.max(THM_COVER_H, el.offsetHeight)), VH = cal.ch, kx = cal.w / cal.cw, ky = cal.h / cal.ch;
    const OW = Math.round(390 * THM_OUT), full = document.createElement('canvas'); full.width = OW; full.height = Math.round(H * THM_OUT);
    const fx = full.getContext('2d'), bg = getComputedStyle(el).backgroundColor; fx.fillStyle = !bg || /rgba\(0, 0, 0, 0\)|transparent/.test(bg) ? '#fff' : bg; fx.fillRect(0, 0, full.width, full.height);
    for (let y = 0; y < H; y += VH) {
        el.style.transform = `translateY(${-y}px)`;
        await thmFrame(v);
        const seg = Math.min(VH, H - y);
        fx.drawImage(v, cal.x, cal.y, cal.w, seg * ky, 0, Math.round(y * THM_OUT), OW, Math.round(seg * THM_OUT));
    }
    const cov = document.createElement('canvas'); cov.width = OW; cov.height = Math.round(THM_COVER_H * THM_OUT);
    cov.getContext('2d').drawImage(full, 0, 0, OW, cov.height, 0, 0, OW, cov.height);
    cap.innerHTML = ''; delete DPK.shots[id];
    return { c: await thmBlob(cov, .78), l: await thmBlob(full, .7), h: Math.round(H) };
}
// 찍은 그림 올리기 (두 방식 같이)
async function thmPut(id, r) {
    const fd = new FormData(); fd.append('csrf_token', THM.csrf); fd.append('act', 'put'); fd.append('id', id); fd.append('sig', dpkSig(id)); fd.append('h', String(r.h));
    const ext = r.c.type === 'image/webp' ? 'webp' : 'jpg'; fd.append('c', r.c, `${id}-c.${ext}`); fd.append('l', r.l, `${id}-l.${ext}`);
    const j = await fetch('dpk_thumbs.php', { method: 'POST', body: fd }).then(x => x.json());
    if (!j.ok) throw new Error(j.error || '올리지 못했어요');
    THM.items[id] = j.item;
}
// ---- 휴대폰 방식: 화면 녹화(getDisplayMedia)가 안 되는 기기(휴대폰·태블릿 브라우저)는 청첩장을 그림으로 바꿔서 찍음 ----
//  assets/modern-screenshot.js (SVG foreignObject - 브라우저가 직접 그려서 마스크·color-mix도 그대로) · 다른 사이트 글꼴은 직접 받아 넣음(thmFontCss)
//  PC에서도 주소 끝에 &how=dom 이면 이 방식 (확인용)
const THM_DOM = (() => { try { return urlParams.get('how') === 'dom' || !(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) || matchMedia('(pointer: coarse)').matches; } catch (e) { return true; } })();
let THM_LIB = null;
function thmLib() {
    return THM_LIB || (THM_LIB = new Promise((ok, bad) => {
        if (window.modernScreenshot) return ok(window.modernScreenshot);
        const sc = document.createElement('script'); sc.src = 'assets/modern-screenshot.js?v=4.6.8';
        sc.onload = () => window.modernScreenshot ? ok(window.modernScreenshot) : bad(new Error('그림 도구를 불러오지 못했어요'));
        sc.onerror = () => { THM_LIB = null; bad(new Error('그림 도구를 불러오지 못했어요')); };
        document.head.appendChild(sc);
    }));
}
// 다른 사이트(구글 글꼴 등) 글꼴은 그림 도구가 못 읽어서, 이 디자인에 쓰인 글꼴 · 들어 있는 글자 칸(unicode-range)만 받아 data: 로 넣음 (한 번 받은 건 기억)
const THM_FONT = { css: {}, data: {} };
function thmB64(buf) { let s = ''; const b = new Uint8Array(buf); for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode.apply(null, b.subarray(i, i + 0x8000)); return btoa(s); }
function thmInRange(range, cps) {
    if (!range) return true;
    return range.split(',').some(part => {
        const m = part.trim().replace(/^U\+/i, ''); let a, b;
        if (m.includes('?')) { a = parseInt(m.replace(/\?/g, '0'), 16); b = parseInt(m.replace(/\?/g, 'F'), 16); }
        else { const [x, y] = m.split('-'); a = parseInt(x, 16); b = y ? parseInt(y, 16) : a; }
        for (const c of cps) if (c >= a && c <= b) return true; return false;
    });
}
async function thmFontCss(el) {
    const fams = new Set(), cps = new Set();
    [el, ...el.querySelectorAll('*')].forEach(n => {
        [getComputedStyle(n), getComputedStyle(n, '::before'), getComputedStyle(n, '::after')].forEach(cs => (cs.fontFamily || '').split(',').forEach(f => { f = f.trim().replace(/^["']|["']$/g, ''); if (f) fams.add(f.toLowerCase()); }));
    });
    for (const ch of (el.textContent || '') + '0123456789:.·-&♥ ') cps.add(ch.codePointAt(0));
    const blocks = [];
    for (const sh of document.styleSheets) {
        let rules = null; try { rules = sh.cssRules; } catch (e) {}
        if (rules) { for (const r of rules) if (r.type === 5) blocks.push([r.cssText, sh.href || location.href]); continue; } // (같은 사이트 글꼴)
        if (!sh.href) continue;
        if (!THM_FONT.css[sh.href]) THM_FONT.css[sh.href] = fetch(sh.href).then(x => x.ok ? x.text() : '').catch(() => '');
        const css = await THM_FONT.css[sh.href];
        (css.match(/@font-face\s*{[^}]*}/g) || []).forEach(t => blocks.push([t, sh.href]));
    }
    const out = [];
    for (const [txt, base] of blocks) {
        const fam = ((txt.match(/font-family:\s*([^;]+)/i) || [])[1] || '').trim().replace(/^["']|["']$/g, '').toLowerCase();
        if (!fams.has(fam) || !thmInRange((txt.match(/unicode-range:\s*([^;}]+)/i) || [])[1], cps)) continue;
        const um = txt.match(/url\(\s*["']?([^"')]+)["']?\s*\)/); if (!um || /^data:/.test(um[1])) { out.push(txt); continue; }
        const url = new URL(um[1], base).href;
        try {
            if (!THM_FONT.data[url]) THM_FONT.data[url] = fetch(url).then(x => { if (!x.ok) throw 0; return x.arrayBuffer(); }).then(b => `data:font/${/\.woff2(\?|$)/.test(url) ? 'woff2' : /\.woff(\?|$)/.test(url) ? 'woff' : 'ttf'};base64,${thmB64(b)}`);
            const d = await THM_FONT.data[url];
            out.push(txt.replace(/src:[^;}]+/, `src: url(${d})`));
        } catch (e) {}
    }
    return out.join('\n');
}
// 꾸밈 층(::before/::after)에 깐 그림(금테 액자 장식 등)은 그림 도구가 빠뜨려서, 찍기 전에 data: 그림으로 바꿔 그 칸에만 덧씌움
const THM_IMG = {};
async function thmPseudoInline(el) {
    const rules = []; let n = 0;
    const toData = url => THM_IMG[url] || (THM_IMG[url] = fetch(url).then(r => { if (!r.ok) throw 0; return r.blob(); }).then(b => new Promise(ok => { const fr = new FileReader(); fr.onload = () => ok(fr.result); fr.onerror = () => ok(url); fr.readAsDataURL(b); })).catch(() => url));
    for (const node of [el, ...el.querySelectorAll('*')]) for (const ps of ['::before', '::after']) {
        const cs = getComputedStyle(node, ps); if (!cs || cs.content === 'none' || cs.display === 'none') continue;
        for (const prop of ['background-image', 'mask-image', '-webkit-mask-image']) {
            const v = cs.getPropertyValue(prop); if (!v || !/url\(["']?(?!data:)/.test(v)) continue;
            const urls = [...v.matchAll(/url\(["']?([^"')]+)["']?\)/g)].map(m => m[1]).filter(u => !/^data:/.test(u));
            let out = v; for (const u of urls) out = out.split(u).join(await toData(u));
            if (!node.dataset.thmPs) node.dataset.thmPs = String(++n);
            rules.push(`[data-thm-ps="${node.dataset.thmPs}"]${ps} { ${prop}: ${out} !important; }`);
        }
    }
    if (rules.length) { const st = document.createElement('style'); st.textContent = rules.join('\n'); el.prepend(st); }
}
async function thmShootDom(id) {
    const ms = await thmLib();
    delete DPK.shots[id];
    const s = await dpkShot(id, 's'); if (!s) throw new Error('그리지 못했어요');
    const cap = thmCapEl(); cap.classList.add('dom'); cap.innerHTML = '';
    const el = dpkIvEl({ cls: s.cls, style: s.style, html: dpkSlim(s) }); el.style.animation = 'none'; cap.appendChild(el);
    el.querySelectorAll('img[loading]').forEach(im => { im.loading = 'eager'; });
    await thmImgs(el); await thmWait(700);
    cap.querySelectorAll('*').forEach(n => { n.style.animationPlayState = 'paused'; });
    await thmPseudoInline(el);
    const H = Math.min(THM_MAX_H, Math.max(THM_COVER_H, el.offsetHeight));
    const bg = getComputedStyle(el).backgroundColor;
    const full = await ms.domToCanvas(el, { width: 390, height: H, scale: THM_OUT, backgroundColor: !bg || /rgba\(0, 0, 0, 0\)|transparent/.test(bg) ? '#fff' : bg, font: { cssText: await thmFontCss(el) }, timeout: 20000 });
    const OW = full.width, cov = document.createElement('canvas'); cov.width = OW; cov.height = Math.round(THM_COVER_H * THM_OUT);
    cov.getContext('2d').drawImage(full, 0, 0, OW, cov.height, 0, 0, OW, cov.height);
    cap.innerHTML = ''; delete DPK.shots[id];
    return { c: await thmBlob(cov, .78), l: await thmBlob(full, .7), h: Math.round(H) };
}
async function thmRunDom(ids) {
    THM.run = true; THM.stop = false; thmDraw();
    let done = 0, fail = 0;
    try {
        await thmLib();
        for (const id of ids) {
            if (THM.stop) break;
            THM.st[id] = 'run'; thmDraw(id); thmMsg(`찍는 중… ${done + fail + 1} / ${ids.length} (화면을 끄지 말고 그대로 두세요)`);
            try { const r = await thmShootDom(id); THM.st[id] = 'up'; thmDraw(id); await thmPut(id, r); THM.st[id] = 'done'; done++; }
            catch (e) { console.warn('[thm]', id, e); THM.st[id] = 'bad'; fail++; }
            thmDraw(id);
        }
    } catch (e) { thmMsg(e.message || '찍지 못했어요', true); }
    finally {
        const cap = thm$('thmCap'); if (cap) cap.remove();
        THM.run = false; thmDraw();
        if (done || fail) thmMsg(`${done}개 저장했어요${fail ? ` · ${fail}개는 못 찍었어요 (다시 눌러 보세요)` : ''}${THM.stop ? ' · 멈췄어요' : ''}. 고객 디자인 고르기 화면에 바로 반영돼요.`, !!fail);
    }
}
async function thmRun(ids) {
    if (THM.run || !ids.length) return;
    if (THM_DOM) return thmRunDom(ids);
    let stream = null;
    try { stream = await navigator.mediaDevices.getDisplayMedia({ video: { displaySurface: 'browser', width: { ideal: 4096 }, height: { ideal: 4096 }, frameRate: 30 }, audio: false, preferCurrentTab: true, selfBrowserSurface: 'include', surfaceSwitching: 'exclude' }); }
    catch (e) { thmMsg('화면 찍기를 허락하지 않았어요. 다시 누르고 \'이 탭 공유\'에서 [공유]를 눌러 주세요.', true); return; }
    THM.run = true; THM.stop = false; thmDraw();
    const track = stream.getVideoTracks()[0], v = document.createElement('video'); v.muted = true; v.playsInline = true; v.srcObject = stream;
    let done = 0, fail = 0, cal = null;
    try {
        await v.play();
        for (const id of ids) {
            if (THM.stop || track.readyState === 'ended') break;
            if (!cal || cal.iw !== window.innerWidth || cal.ih !== window.innerHeight) { cal = await thmCalib(v, thmCapEl()); if (!cal) { thmMsg('찍은 화면에서 이 탭을 찾지 못했어요. 다시 누르고 \'이 탭\'을 골라 주세요.', true); break; } } // (창 크기가 바뀌면 다시)
            THM.st[id] = 'run'; thmDraw(id); thmMsg(`찍는 중… ${done + fail + 1} / ${ids.length}`);
            try {
                const r = await thmShoot(id, v, cal);
                THM.st[id] = 'up'; thmDraw(id);
                await thmPut(id, r); THM.st[id] = 'done'; done++;
            } catch (e) { console.warn('[thm]', id, e); THM.st[id] = 'bad'; fail++; }
            thmDraw(id);
        }
    } finally {
        try { track.stop(); } catch (e) {}
        const cap = thm$('thmCap'); if (cap) cap.remove();
        THM.run = false; thmDraw();
        if (!thm$('thmMsg').classList.contains('bad') || done) thmMsg(`${done}개 저장했어요${fail ? ` · ${fail}개는 못 찍었어요 (다시 눌러 보세요)` : ''}${THM.stop ? ' · 멈췄어요' : ''}. 고객 디자인 고르기 화면에 바로 반영돼요.`, !!fail);
    }
}
