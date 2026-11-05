// 1019z6 간편 만들기 조각 떼어 내기 (build-editor.js가 editor.js를 압축하기 전에 부름 - 원본 파일은 그대로)
//  떼는 것: 맨 바깥 function 중 이름이 sp대문자·spi대문자로 시작하는 것 (간편 만들기 전용)
//           + 맨 바깥 const 중 이름이 SP_·SPI_로 시작하고 남는 코드 어디에서도 안 쓰는 것 (조각에선 var - 두 번 실행돼도 안전)
//  남기는 것: tools/easy-keep.json 의 함수 (전문가 모드·둘러보기에서도 불리는 것 - 테스트로 모은 목록, 아래 쓰는 법)
//  남는 코드엔 떼어 낸 함수 자리에 대리 함수 `function spX(){return ldEz('spX',this,arguments)}` (editor.js ldEz - 조각을 아직 못 받았으면 그 자리에서 받음)
//  조각 끝에 window.__ldEzDone=1 · 남는 코드 끝에 ldEzBoot() (휴대폰은 이어서 · PC는 비동기로 받음)
//  끄기: node build-editor.js --no-split  (예전처럼 한 파일 - 조각 파일은 그대로 남지만 안 씀)
//  남길 목록 다시 모으기: 스크래치 shots/cov.py 같은 방식(전문가 모드에서 섹션 편집창·화면 설정·스티커·발행 점검을 열어 보며 Profiler 정밀 커버리지로 불린 sp* 이름)
const acorn = require('acorn');
const fs = require('fs'), path = require('path');
const FN_RE = /^spi?[A-Z]/, CONST_RE = /^SPI?_/;

function split(src) {
    const keep = new Set(JSON.parse(fs.readFileSync(path.join(__dirname, 'easy-keep.json'), 'utf8')).keep);
    // 1019z10 전문가 모드 조각: tools/exp-move.json 에 적힌 맨 바깥 함수 (ldEx 대리 함수 · editor-exp.min.js)
    const expList = fs.existsSync(path.join(__dirname, 'exp-move.json')) ? JSON.parse(fs.readFileSync(path.join(__dirname, 'exp-move.json'), 'utf8')).move : [];
    const expSet = new Set(expList);
    const ast = acorn.parse(src, { ecmaVersion: 'latest', sourceType: 'script' });
    const nodes = ast.body;
    const fnMove = new Set();
    for (const n of nodes) if (n.type === 'FunctionDeclaration' && FN_RE.test(n.id.name) && !keep.has(n.id.name) && !n.generator) fnMove.add(n.id.name);
    // const 후보: 이름이 SP_/SPI_ 이고 선언 하나짜리
    const cands = new Map(); // name → node
    for (const n of nodes) if (n.type === 'VariableDeclaration' && n.kind === 'const' && n.declarations.length === 1 && n.declarations[0].id.type === 'Identifier' && CONST_RE.test(n.declarations[0].id.name)) cands.set(n.declarations[0].id.name, n);
    // 남는 코드(떼지 않는 문장)에서 쓰이는 const는 남김 - 줄어들 때까지 되풀이
    const fnExp = new Set();
    for (const n of nodes) if (n.type === 'FunctionDeclaration' && expSet.has(n.id.name) && !fnMove.has(n.id.name) && !n.generator) fnExp.add(n.id.name);
    const moved = n => (n.type === 'FunctionDeclaration' && (fnMove.has(n.id.name) || fnExp.has(n.id.name))) || (n.type === 'VariableDeclaration' && cands.has(n.declarations[0] && n.declarations[0].id.name) && cands.get(n.declarations[0].id.name) === n);
    let changed = true;
    while (changed) {
        changed = false;
        const rest = nodes.filter(n => !moved(n)).map(n => src.slice(n.start, n.end)).join('\n');
        for (const name of [...cands.keys()]) if (new RegExp('\\b' + name + '\\b').test(rest)) { cands.delete(name); changed = true; }
    }
    let main = '', chunk = '', expChunk = '', pos = 0, nFn = 0, nConst = 0, nExp = 0;
    for (const n of nodes) {
        if (!moved(n)) continue;
        main += src.slice(pos, n.start);
        const code = src.slice(n.start, n.end);
        if (n.type === 'FunctionDeclaration' && fnExp.has(n.id.name)) { const nm = n.id.name; main += `function ${nm}(){return ldEx('${nm}',this,arguments)}`; expChunk += code + '\n'; nExp++; }
        else if (n.type === 'FunctionDeclaration') { const nm = n.id.name; main += `function ${nm}(){return ldEz('${nm}',this,arguments)}`; chunk += code + '\n'; nFn++; }
        else { chunk += code.replace(/^const\b/, 'var') + '\n'; nConst++; }
        pos = n.end;
    }
    main += src.slice(pos) + '\n;ldEzBoot();' + (nExp ? 'ldExBoot();' : '') + '\n';
    expChunk = '// 전문가 모드 조각 (tools/split-easy.js가 editor.js에서 떼어 냄 - 손으로 고치지 말 것, 목록 tools/exp-move.json)\n' + expChunk + 'window.__ldExDone = 1;\n';
    chunk = '// 간편 만들기 조각 (tools/split-easy.js가 editor.js에서 떼어 냄 - 손으로 고치지 말 것)\n' + chunk + 'window.__ldEzDone = 1;\n';
    return { main, chunk, nFn, nConst, expChunk, nExp };
}
module.exports = { split };
