// split-deco.js - 1019z16 하객 화면 CSS 가볍게: invite-blocks.min.css에서 '꾸밈 하나에만 쓰이는 규칙'을 떼어 꾸밈마다 파일로
//
//  꾸밈(디자인 테마 스킨·테마 부품·페이지 배경) 규칙은 선택자에 .ib-th-<k> · .ib-tp-<k> · .ib-bd-<k> 가 꼭 들어 있음
//  → 그 클래스가 화면에 없으면 쓰일 일이 없음. 한 청첩장은 꾸밈 1~2개만 씀(전체의 절반 넘는 약 200KB가 나머지 꾸밈).
//  모든 선택자가 같은 꾸밈 하나(k)를 '반드시' 요구하는 규칙만 뗌 - :not(.ib-th-k) 안에 든 것·꾸밈이 둘 섞인 것·꾸밈 없는 것은 공용(core)에 남김.
//  @media·@supports 안 규칙은 같은 @규칙으로 감싸서 옮김. @keyframes·@font-face는 공용.
//
//  만드는 것: assets/invite-blocks.core.min.css (공용) + assets/deco/<k>.css + assets/deco/manifest.json {v, keys}
//  쓰는 곳: 하객 화면(invite_view.php)만 - core를 불러오고 render-invite가 화면에 쓰인 꾸밈 클래스를 찾아 그 파일만 받음(LD_DECO).
//           에디터·관리자·내보내기는 예전처럼 전체(invite-blocks.min.css).
const postcss = require('postcss');

const KEY_RE = /\.ib-(?:th|tp|bd)-(o?[a-z0-9]+)(?![\w-])/g;

/** 선택자 하나가 반드시 요구하는 꾸밈 키 집합 (:not( ) 안은 '없어야' 하는 것이라 빼고, 거기 꾸밈이 있으면 공용) */
function selKeys(sel) {
    let out = '', notTxt = '';
    for (let i = 0; i < sel.length; i++) {
        if (sel.startsWith(':not(', i)) {   // 짝이 맞는 ) 까지
            let d = 0, j = i + 4;
            for (; j < sel.length; j++) { if (sel[j] === '(') d++; else if (sel[j] === ')') { d--; if (d === 0) break; } }
            notTxt += sel.slice(i, j + 1); i = j; continue;
        }
        out += sel[i];
    }
    KEY_RE.lastIndex = 0;
    if (KEY_RE.test(notTxt)) return null;   // '이 꾸밈이 아니면' 규칙 → 공용
    const ks = new Set(); let m; KEY_RE.lastIndex = 0;
    while ((m = KEY_RE.exec(out))) ks.add(m[1]);
    // :is( a, b ) 처럼 둘 중 하나만 있어도 되는 묶음이면, 키가 하나뿐일 때만 '반드시'로 침 (모든 갈래가 그 키를 갖는지 확인)
    const isRe = /:(?:is|where|matches)\(/g;
    if (isRe.test(out)) {
        if (ks.size !== 1) return null;
        const k = [...ks][0];
        // 쉼표로 나뉜 갈래 중 그 키가 없는 갈래가 있고, 그 묶음 밖에도 키가 없으면 '반드시'가 아님
        const outside = out.replace(/:(?:is|where|matches)\((?:[^()]|\([^()]*\))*\)/g, '');
        if (!new RegExp('\\.ib-(?:th|tp|bd)-' + k + '(?![\\w-])').test(outside)) {
            const groups = out.match(/:(?:is|where|matches)\(((?:[^()]|\([^()]*\))*)\)/g) || [];
            const kr = new RegExp('\\.ib-(?:th|tp|bd)-' + k + '(?![\\w-])');
            const ok = groups.some(g => g.slice(g.indexOf('(') + 1, -1).split(',').every(part => kr.test(part)));
            if (!ok) return null;
        }
    }
    return ks;
}

/** 선택자 우선순위 [id, class, 태그] (대충이지만 :is/:not/:has = 안에서 가장 큰 것, :where = 0) */
function spec(sel) {
    let a = 0, b = 0, c = 0, i = 0;
    const max3 = list => list.map(spec).reduce((m, x) => (cmp(x, m) > 0 ? x : m), [0, 0, 0]);
    while (i < sel.length) {
        const ch = sel[i];
        if (ch === '#') { a++; i++; while (i < sel.length && /[\w-]/.test(sel[i])) i++; continue; }
        if (ch === '.') { b++; i++; while (i < sel.length && /[\w-]/.test(sel[i])) i++; continue; }
        if (ch === '[') { b++; while (i < sel.length && sel[i] !== ']') i++; i++; continue; }
        if (ch === ':') {
            const pe = sel[i + 1] === ':'; i += pe ? 2 : 1;
            let name = ''; while (i < sel.length && /[\w-]/.test(sel[i])) name += sel[i++];
            let args = null;
            if (sel[i] === '(') { let d = 0, j = i; for (; j < sel.length; j++) { if (sel[j] === '(') d++; else if (sel[j] === ')') { d--; if (d === 0) break; } } args = sel.slice(i + 1, j); i = j + 1; }
            if (pe || /^(before|after|first-line|first-letter|placeholder|selection|marker)$/.test(name)) { c++; continue; }
            if (name === 'where') continue;
            if (/^(is|not|has|matches)$/.test(name) && args != null) { const m = max3(splitTop(args)); a += m[0]; b += m[1]; c += m[2]; continue; }
            b++; continue;
        }
        if (/[a-zA-Z]/.test(ch)) { c++; while (i < sel.length && /[\w-]/.test(sel[i])) i++; continue; }
        i++;
    }
    return [a, b, c];
}
function cmp(x, y) { return x[0] - y[0] || x[1] - y[1] || x[2] - y[2]; }
function splitTop(s) { const out = []; let d = 0, cur = ''; for (const ch of s) { if (ch === '(') d++; if (ch === ')') d--; if (ch === ',' && d === 0) { out.push(cur); cur = ''; } else cur += ch; } out.push(cur); return out; }

/** 선택자의 마지막 칸(실제로 꾸미는 요소): 클래스 · 태그 · 가상 요소(::before 등) - 두 규칙이 같은 요소를 꾸밀 수 있는지 어림 */
function subjectOf(sel) {
    let d = 0, last = 0;
    for (let i = 0; i < sel.length; i++) { const ch = sel[i]; if (ch === '(') d++; else if (ch === ')') d--; else if (d === 0 && (ch === ' ' || ch === '>' || ch === '+' || ch === '~')) last = i + 1; }
    let sub = sel.slice(last).replace(/:(?:not|is|where|has|matches)\((?:[^()]|\([^()]*\))*\)/g, '');
    const pe = (sub.match(/::?(before|after|first-line|first-letter|placeholder|marker|selection)\b/) || [])[1] || '';
    const cls = new Set((sub.match(/\.[\w-]+/g) || []).map(x => x.slice(1)));
    const tag = (sub.match(/^[a-zA-Z][\w-]*/) || [''])[0].toLowerCase();
    return { pe, cls, tag };
}
/** 공용 규칙 선택자가 '섹션 테마(ib-sk-*)나 부품 모양(ib-tp-*)'을 꼭 요구하는지 - 그런 섹션·부품엔 디자인 꾸밈(ib-th-k)·다른 부품 모양이 같이 붙지 않음
 *  (boxColAttrs: 섹션 테마를 고르면 ib-th를 안 붙임 · 부품 모양을 고르면 ib-tpx라 꾸밈 규칙이 :not(.ib-tpx)로 빠짐) */
function needsSkin(sel) {
    const s2 = sel.replace(/:not\((?:[^()]|\([^()]*\))*\)/g, '');
    const SK = /\.ib-(?:sk|tp)-[a-z0-9]/;
    const groups = s2.match(/:is\(((?:[^()]|\([^()]*\))*)\)/g);
    if (groups) for (const g of groups) { const alts = splitTop(g.slice(4, -1)); if (alts.length > 1 && alts.every(a => SK.test(a))) return true; }
    const outside = s2.replace(/:is\((?:[^()]|\([^()]*\))*\)/g, '');
    return SK.test(outside);
}
function mayMeet(x, y) {
    if (x.pe !== y.pe) return false;
    if (x.tag && y.tag && x.tag !== y.tag) return false;
    if (!x.cls.size || !y.cls.size) return true;
    for (const c of x.cls) if (y.cls.has(c)) return true;
    return false;
}

function split(css) {
    const root = postcss.parse(css);
    const files = {};               // k → postcss Root
    const getTarget = (k, chain) => {
        let r = files[k] || (files[k] = postcss.root());
        let cur = r;
        for (const a of chain) {
            let found = cur.nodes && cur.nodes.find(n => n.type === 'atrule' && n.name === a.name && n.params === a.params && n._dk);
            if (!found) { found = postcss.atRule({ name: a.name, params: a.params }); found._dk = true; cur.append(found); }
            cur = found;
        }
        return cur;
    };
    // 1) 규칙마다 순서 · 꾸밈 키 · 속성별 우선순위
    const all = []; let order = 0;
    root.walkRules(rule => {
        for (let p = rule.parent; p && p.type !== 'root'; p = p.parent) if (p.type === 'atrule' && /keyframes$/i.test(p.name)) return;
        let key = null, ok = true;
        for (const sel of rule.selectors) {
            const ks = selKeys(sel);
            if (!ks || ks.size !== 1) { ok = false; break; }
            const k = [...ks][0];
            if (key && key !== k) { ok = false; break; }
            key = k;
        }
        const chain = [];
        for (let p = rule.parent; p && p.type !== 'root'; p = p.parent) {
            if (p.type !== 'atrule' || !/^(media|supports|container|layer)$/i.test(p.name)) { ok = false; break; }
            chain.unshift({ name: p.name, params: p.params });
        }
        const sels = rule.selectors.map(x => ({ sp: spec(x).join(','), sub: subjectOf(x), skin: needsSkin(x), bd: /\.ib-bd-/.test(x) }));
        const props = new Set(); rule.each(d => { if (d.type === 'decl') props.add(d.prop + (d.important ? '!' : '')); });
        all.push({ rule, i: order++, key: ok ? key : null, chain, sels, props });
    });
    // 2) 옮기면 순서가 바뀌어 결과가 달라질 수 있는 규칙은 남김:
    //    같은 속성 · 같은 우선순위(동점이면 뒤에 오는 규칙이 이김)인 규칙이 '원래 뒤에 있는데 공용에 남는 것'이거나 '다른 꾸밈 파일'이면
    //    (공용은 먼저 · 꾸밈 파일은 나중에 받음 → 동점 순서가 뒤집힘). 남기는 규칙이 생기면 다시 봄.
    const byProp = new Map();
    for (const r of all) for (const pr of r.props) { if (!byProp.has(pr)) byProp.set(pr, []); byProp.get(pr).push(r); }
    const clashWith = (r, o) => {
        for (const x of r.sels) for (const y of o.sels) {
            if (x.sp !== y.sp || !mayMeet(x.sub, y.sub)) continue;
            if (y.skin && !x.bd) continue;   // 섹션 테마·다른 부품 모양 전용 규칙 ↔ 디자인 꾸밈 규칙은 같은 요소에 같이 안 걸림
            return true;
        }
        return false;
    };
    let changed = true, kept = 0;
    while (changed) {
        changed = false;
        for (const r of all) {
            if (!r.key) continue;
            let clash = false;
            for (const pr of r.props) {
                for (const o of byProp.get(pr)) {
                    if (o === r || o.key === r.key) continue;
                    // 원래 뒤에 있던 공용 규칙과 동점(같은 우선순위 · 같은 요소일 수 있음) → 옮기면 순서가 뒤집혀 결과가 달라짐 → 남김
                    if (o.key || o.i < r.i) continue; // 다른 꾸밈끼리는 한 요소에 같이 걸리지 않게 만들어져 있음(섹션마다 꾸밈 하나 · 부품 :not(.ib-tpx)) → 하객 화면 32개 디자인으로 확인
                    if (clashWith(r, o)) { clash = true; break; }
                }
                if (clash) break;
            }
            if (clash && !process.env.DECO_NOCLASH) { r.key = null; kept++; changed = true; }
        }
    }
    let moved = 0;
    for (const r of all) {
        if (!r.key) continue;
        getTarget(r.key, r.chain).append(r.rule.clone());
        r.rule.remove(); moved++;
    }
    // 비게 된 @media 등 지우기
    let again = true;
    while (again) { again = false; root.walkAtRules(a => { if (a.nodes && a.nodes.length === 0) { a.remove(); again = true; } }); }
    const out = {};
    for (const [k, r] of Object.entries(files)) out[k] = r.toString();
    return { core: root.toString(), files: out, moved, kept };
}

module.exports = { split, selKeys, _spec: spec, _sub: subjectOf, _meet: mayMeet };
