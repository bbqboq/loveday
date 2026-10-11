// 섹션 스킨 · 디데이 모양 고르기 썸네일을 미리 그려 두는 도구 (에디터 → invite/assets/ssk/<칸>-<lt|dk>.webp + manifest.json)
//  에디터는 이 이미지 한 장(섹션마다, 밝은·어두운 바탕 두 벌)으로 썸네일을 바로 보여 주고,
//  지금 디자인을 따라 달라지는 카드('디자인 따라' · '테마 모양')만 그 자리에서 그림 (editor: sskSprite / SSK_SPR)
//
//  사용: node tools/ssk-sprites.js "<에디터 주소 ?t=편집토큰>" [출력 폴더 = invite/assets/ssk]
//    - playwright, ImageMagick(convert, WebP 지원) 필요
//    - 한글 글꼴(나눔명조 · Pretendard …)과 구글 글꼴이 받아지는 환경에서 돌릴 것 (없으면 썸네일 글씨가 다른 글꼴로 찍힘)
//    - 바깥 주소가 막힌 테스트 환경이면 require('./tools/ssk-sprites').generate(page, outDir)를
//      글꼴·예시 사진을 로컬로 이어 둔 page로 부르면 됨
//  섹션 모양 CSS(테마 부품 · 디자인 테마 스킨 · 섹션 테마)나 디자인을 바꾸면 다시 돌릴 것 (manifest의 v가 바뀌어 새로 받음)
const fs = require('fs'), path = require('path'), { execFileSync } = require('child_process');
const COLS = 10, DPR = 2;
const VARIANTS = [['lt', 'classic'], ['dk', 'p-cosmos']]; // 밝은 바탕 · 어두운 바탕 (청첩장 root가 .ib-dark면 dk)

async function buildPage(page, preset) {
    return page.evaluate(async preset => {
        const wait = ms => new Promise(r => setTimeout(r, ms));
        startWithPreset(preset); await wait(900); try { finishOnboarding(); } catch (e) {} showScreen('editor');
        ensureExtraState(); state.extras.look = false; // 모양 카드는 디자인 꾸밈 없이 (디자인 스킨 카드는 제 꾸밈을 가져감)
        state.blocks.forEach(b => { delete b.skin; delete b.skinPal; delete b.skinDesign; delete b.skinPrev; delete b.tint; delete b.tintLine; if (b.fields) delete b.fields.skin; });
        state.intro.enabled = false; renderPreviewNow(); await wait(400);
        SSK.cache.clear(); document.getElementById('sskGen')?.remove();
        const host = document.createElement('div'); host.id = 'sskGen';
        host.innerHTML = `<style>#sskGen{position:absolute;left:0;top:0;z-index:999999;background:#fff;width:${84 * 10}px}#sskGen .g{display:flex;flex-wrap:wrap;width:${84 * 10}px}#sskGen .ssk-c{gap:0;width:84px}#sskGen .ssk-c b,#sskGen .ssk-c small{display:none}#sskGen .ssk-th{border-radius:0!important;box-shadow:none!important}#sskGen .ssk-th::after{display:none!important}</style>`;
        document.body.appendChild(host);
        const jobs = [], SSK_PER_LOOK = typeof SSK_TILES !== 'undefined' ? SSK_TILES : ['gallery'];
        const group = (key, cls, cards) => { const g = document.createElement('div'); g.className = 'g ' + cls; g.dataset.key = key; cards.forEach(c => { c.classList.remove('on'); g.appendChild(c); }); host.appendChild(g); return g; };
        for (const b of state.blocks) {
            if (SKIN_NO.includes(b.id) || b.id === 'dday') continue;
            const d = document.createElement('div'); d.innerHTML = sskHtml(b);
            const cards = [...d.querySelectorAll('.ssk-c')].filter(c => !sskDynamic(c.dataset.ssk));
            if (!cards.some(c => c.dataset.ssk === 's:plain')) { const t = document.createElement('div'); t.innerHTML = sskCard('s:plain', '꾸밈 없이'); cards.unshift(t.firstElementChild); } // (꾸밈을 끄고 찍어서 목록에 안 나옴 - 디자인 꾸밈이 있는 디자인에선 보임)
            if (!cards.length) continue;
            group(b.id, '', cards); cards.forEach(c => sskFill(c, b));
            const lk0 = (SSK_LOOKS[b.id] || [])[0], l0 = lk0 && ((lk0[2]() || []).filter(x => x && x[0] != null)[0] || [''])[0], lv = lk0 ? (b.fields[lk0[0]] != null && b.fields[lk0[0]] !== '' ? b.fields[lk0[0]] : l0) : '';
            jobs.push({ key: b.id, w: 84, h: 60, s: cards.map(c => c.dataset.ssk), lv: String(lv) }); // lv = 이 그림을 찍을 때의 섹션 모양 (지금 모양이 같으면 스킨 카드도 이 그림을 씀)
            // 스킨 카드는 섹션 모양마다 따로 - 편집 칸 타일로 고르는 섹션만 (SSK_TILES, 모양마다 다 그리면 너무 오래 걸림) (스킨 그림이 고객이 고른 모양 그대로 보이게 - 에디터 sskSprKey '<섹션>~<키>=<값>')
            const lk = SSK_PER_LOOK.includes(b.id) && (SSK_LOOKS[b.id] || [])[0];
            if (lk) {
                const keep = JSON.stringify(b.fields);
                for (const [v] of (lk[2]() || []).filter(x => x && x[0] != null && !String(x[0]).startsWith('t:'))) {
                    b.fields[lk[0]] = v; if (lk[0] === 'accStyle') b.fields.display = 'cards';
                    const S = sskSkins(b), t = document.createElement('div');
                    t.innerHTML = [sskCard('s:plain', ''), ...S.base.filter(([k]) => k && k !== 'plain').map(([k]) => sskCard('s:' + k, '')), ...S.designs.map(p => sskCard('d:' + p.id, ''))].join('');
                    const sc = [...t.children], key = `${b.id}~${lk[0]}=${v}`;
                    group(key, '', sc); sc.forEach(c => sskFill(c, b));
                    jobs.push({ key, w: 84, h: 60, s: sc.map(c => c.dataset.ssk) });
                }
                b.fields = JSON.parse(keep);
            }
        }
        const dd = state.blocks.find(b => b.id === 'dday');
        if (dd) for (const kind of ['cal', 'dd']) {
            const d = document.createElement('div'); d.innerHTML = ddkRow(kind, dd.fields);
            const cards = [...d.querySelectorAll('.ddk-c')].filter(c => c.dataset.ddkV !== 'theme');
            group('dday-' + kind, 'ddk-' + kind, cards); cards.forEach(c => ddkFill(c, dd));
            jobs.push({ key: 'dday-' + kind, w: 84, h: kind === 'cal' ? 70 : 48, s: cards.map(c => c.dataset.ddkV) });
        }
        try { await document.fonts.ready; } catch (e) {}
        await Promise.all([...host.querySelectorAll('img')].map(im => im.complete ? 0 : new Promise(r => { im.onload = im.onerror = r; setTimeout(r, 4000); })));
        await wait(800);
        return jobs;
    }, preset);
}

async function generate(page, outDir) {
    outDir = outDir || path.join(__dirname, '..', 'invite', 'assets', 'ssk');
    fs.mkdirSync(outDir, { recursive: true });
    const man = { v: Date.now().toString(36), cols: COLS, k: {} };
    for (const [vr, preset] of VARIANTS) {
        const jobs = await buildPage(page, preset);
        for (const j of jobs) {
            const g = await page.$(`#sskGen .g[data-key="${j.key}"]`);
            const png = path.join(outDir, `${j.key}-${vr}.png`), webp = png.replace(/\.png$/, '.webp');
            await g.screenshot({ path: png });
            execFileSync('convert', [png, '-quality', '52', '-define', 'webp:method=6', webp]);
            fs.unlinkSync(png);
            man.k[j.key] = Object.assign({ w: j.w, h: j.h, s: j.s }, j.lv != null ? { lv: j.lv } : {});
            console.log(vr, j.key, j.s.length, 'cards', Math.round(fs.statSync(webp).size / 1024) + 'KB');
        }
    }
    fs.writeFileSync(path.join(outDir, 'manifest.json'), JSON.stringify(man));
    return man;
}

module.exports = { generate, DPR };

if (require.main === module) (async () => {
    const url = process.argv[2]; if (!url) { console.log('node tools/ssk-sprites.js "<에디터 주소 ?t=편집토큰>" [출력 폴더]'); process.exit(1); }
    const { chromium } = require('playwright');
    const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1200, height: 900 }, deviceScaleFactor: DPR });
    await p.goto(url); await p.waitForTimeout(2500);
    await p.click('#startChoice [data-track="custom"]').catch(() => {}); await p.waitForTimeout(1200);
    await generate(p, process.argv[3]); await b.close();
})();
