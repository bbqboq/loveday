// 휴대폰에서 버튼을 누른 뒤 :hover 색이 그대로 남는 것(sticky hover) 막기 - 압축본을 만들 때만 (원본 CSS는 그대로) · 1019d
//  모든 selector가 :hover인 규칙 중 모양만 바꾸는 것(바탕·글자색·테두리·그림자·transform·filter·밑줄)을 @media (hover: hover) 안으로 옮김
//  display·visibility·opacity·pointer-events·width 등 '보이게/숨기게'를 바꾸는 규칙은 그대로 둠 (휴대폰에서 누르면 나타나는 것들이 안 깨지게)
//  이미 (hover: hover)/(hover: none) 안에 있으면 그대로. 끄기: node build-editor.js --no-hover-gate
const postcss = require('postcss');
const COSMETIC = /^(background(-color|-image)?|color|border(-[a-z]+)*|outline(-color)?|box-shadow|text-shadow|text-decoration(-[a-z]+)*|transform|scale|translate|rotate|filter|fill|stroke|--[\w-]+)$/;
module.exports = function hoverGate(css) {
    const root = postcss.parse(css); let n = 0;
    root.walkRules(rule => {
        if (!rule.selectors || !rule.selectors.every(s => s.includes(':hover'))) return;
        for (let p = rule.parent; p && p.type !== 'root'; p = p.parent) if (p.type === 'atrule' && /hover|pointer/.test(p.params)) return;
        let ok = true; rule.walkDecls(d => { if (!COSMETIC.test(d.prop)) ok = false; });
        if (!ok || !rule.nodes || !rule.nodes.length) return;
        const at = postcss.atRule({ name: 'media', params: '(hover: hover)' });
        rule.replaceWith(at); at.append(rule); n++;
    });
    return { css: root.toString(), n };
};
