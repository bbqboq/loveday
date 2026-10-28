// 에디터(invite/editor-prototype-v3-overlay.html)와 하객 화면(invite_view.php)의 CSS·JS 압축본을 만드는 도구
//  (1019부터 공용 invite-blocks.*·하객 화면 render-invite.*도 - 원본을 고치면 꼭 다시 돌릴 것)
//  고치는 파일(원본): invite/assets/editor.css · invite/assets/editor.js  ← 여기를 고칠 것
//  서버가 쓰는 파일(압축본): invite/assets/editor.min.css · invite/assets/editor.min.js  ← 손으로 고치지 말 것 (이 도구가 만듦)
//  만들면 에디터 HTML의 <link>/<script> 주소 ?v= 값을 압축본 내용의 지문으로 바꿈 (내용이 바뀔 때만 휴대폰이 새로 받음)
//
//  사용: cd tools && npm i (처음 한 번) → node build-editor.js        (원본을 고친 뒤 커밋하기 전에 꼭)
//        node build-editor.js --check   (압축본·?v=가 원본과 맞는지만 확인 - 안 맞으면 1로 끝남)
//  압축: esbuild - 공백·주석을 빼고 안쪽 이름만 짧게 (맨 바깥 함수·변수 이름은 그대로 - HTML onclick·관리자 화면·테스트가 이름으로 부름)
//        JS는 es2020 문법까지만 씀 (예전 휴대폰 브라우저) · 한글은 그대로(utf8)
const fs = require('fs'), path = require('path'), crypto = require('crypto');
const esbuild = require('esbuild');
const INV = path.join(__dirname, '..', 'invite');
const HTML = path.join(INV, 'editor-prototype-v3-overlay.html');
const CSS = { loader: 'css', minify: true, charset: 'utf8', legalComments: 'none' };
const JS = { loader: 'js', minify: true, target: 'es2020', charset: 'utf8', legalComments: 'none' };
// html: true = 에디터 HTML이 이 압축본을 ?v=지문으로 불러옴 · false = 하객 화면(invite_view.php)이 파일이 있으면 압축본을 씀 (?v=filemtime)
const FILES = [
    { src: 'assets/editor.css', out: 'assets/editor.min.css', opt: CSS, html: true },
    { src: 'assets/editor.js', out: 'assets/editor.min.js', opt: JS, html: true },
    { src: 'assets/invite-blocks.css', out: 'assets/invite-blocks.min.css', opt: CSS, html: true }, // 공용 섹션 (에디터 + 하객 화면) - 1019부터 압축본
    { src: 'assets/invite-blocks.js', out: 'assets/invite-blocks.min.js', opt: JS, html: true },
    { src: 'assets/render-invite.css', out: 'assets/render-invite.min.css', opt: CSS, html: false }, // 하객 화면 전용
    { src: 'assets/render-invite.js', out: 'assets/render-invite.min.js', opt: JS, html: false },
];
const check = process.argv.includes('--check');
let html = fs.readFileSync(HTML, 'utf8'), bad = 0;
for (const f of FILES) {
    const src = fs.readFileSync(path.join(INV, f.src), 'utf8');
    const { code, warnings } = esbuild.transformSync(src, { ...f.opt, sourcefile: f.src });
    warnings.forEach(w => console.warn(`  주의 ${f.src}: ${w.text}`));
    const v = crypto.createHash('sha1').update(code).digest('hex').slice(0, 10);
    const outPath = path.join(INV, f.out), cur = fs.existsSync(outPath) ? fs.readFileSync(outPath, 'utf8') : null;
    const re = new RegExp(f.out.replace(/[.]/g, '\\.') + '\\?v=[\\w-]+', 'g');
    if (f.html && !re.test(html)) { console.error(`  에디터 HTML에 ${f.out}?v=… 주소가 없어요`); bad++; continue; }
    const html2 = f.html ? html.replace(re, `${f.out}?v=${v}`) : html;
    if (check) {
        if (cur !== code) { console.error(`  ${f.out}이(가) ${f.src}과(와) 달라요 → node tools/build-editor.js`); bad++; }
        else if (html2 !== html) { console.error(`  에디터 HTML의 ${f.out}?v= 값이 옛날 거예요 → node tools/build-editor.js`); bad++; }
        else console.log(`  ✓ ${f.out} (${(Buffer.byteLength(code) / 1024).toFixed(0)}KB, v=${v})`);
        continue;
    }
    if (cur !== code) fs.writeFileSync(outPath, code);
    html = html2;
    console.log(`  ${f.out}  ${(Buffer.byteLength(src) / 1024).toFixed(0)}KB → ${(Buffer.byteLength(code) / 1024).toFixed(0)}KB  v=${v}${cur === code ? ' (그대로)' : ''}`);
}
if (!check) fs.writeFileSync(HTML, html);
if (bad) process.exit(1);
