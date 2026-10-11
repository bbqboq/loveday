/* invite-blocks.js
   에디터(editor-prototype-v3-overlay.html)와 공개페이지(render-invite.js)가 "같은 파일 하나"를 불러다 쓰는
   공용 섹션 모음. 여기 있는 섹션은 에디터와 실제 화면이 절대 따로 놀 수 없다 - 새 섹션은 앞으로 여기에만 추가한다.
   (기존 섹션 - 히어로/인사말/오시는길/갤러리/계좌/디데이/타임라인/인터뷰 - 은 아직 두 파일에 각각 있고,
    나중에 하나씩 이쪽으로 옮겨올 예정)

   섹션 하나 = BLOCKS[id] = {
       label   : 에디터 목록에 보일 이름
       color   : 에디터 목록 색점
       defaults: 처음 추가될 때 fields 기본값
       editor  : 에디터 오른쪽 편집창에 그릴 입력칸 목록 (에디터가 이걸 보고 자동으로 폼을 만든다)
       render(fields) : 실제 HTML (에디터 미리보기와 공개페이지가 똑같이 이 함수를 쓴다)
   }
   입력칸 type: text / textarea / date / tel / url / image / color / range / choice / font / items(반복 항목) */
(function (global) {
    'use strict';

    const isEditor = () => !!global.INVITE_EDITOR; // 에디터에서만 빈 칸 안내 문구를 보여주기 위한 표시

    // 신랑·신부·혼주 명칭과 순서 (에디터 "명칭·순서" 설정 - design.labels). 섹션들이 그릴 때마다 여기 값을 쓴다.
    const LABEL_DEFAULTS = { groom: '신랑', bride: '신부', father: '아버지', mother: '어머니', groomColor: '#5F8B9B', brideColor: '#BB7273', order: 'groom-first', deceasedMark: 'flower' };
    let L = Object.assign({}, LABEL_DEFAULTS);
    function setLabels(labels) {
        L = Object.assign({}, LABEL_DEFAULTS);
        Object.keys(labels || {}).forEach(k => { if (labels[k] !== '' && labels[k] != null) L[k] = labels[k]; });
        return L;
    }
    const bothSides = (groomHtml, brideHtml) => L.order === 'bride-first' ? brideHtml + groomHtml : groomHtml + brideHtml;

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function nl2br(s) { return esc(s).replace(/\n/g, '<br>'); }
    function uid(prefix) { return (prefix || 'i') + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
    function emptyHint(text) { return isEditor() ? `<p class="ib-empty">${esc(text)}</p>` : ''; }
    // 업로드 주소 보정 - 짧은 공개주소(loveday.kr/코드)에서도 /invite/uploads/... 로 제대로 보이게 (render-invite.js의 normalizeUploadUrl과 동일)
    function imgUrl(url) {
        if (!url) return '';
        if (/^(https?:|data:|\/invite\/)/.test(url)) return url;
        if (url.startsWith('/uploads/')) return '/invite' + url;
        if (url.startsWith('/')) return url;
        return '/invite/' + url;
    }
    function youtubeId(input) {
        const raw = String(input || '').trim();
        if (!raw) return '';
        const m = raw.match(/(?:youtu\.be\/|v=|\/embed\/|\/shorts\/)([A-Za-z0-9_-]{6,})/);
        return m ? m[1] : (/^[A-Za-z0-9_-]{6,}$/.test(raw) ? raw : '');
    }
    function phoneDigits(p) { return String(p || '').replace(/[^0-9+]/g, ''); }
    // 링크 주소: "naver.com"처럼 http(s)://를 빼고 적어도 연결되게 (예전엔 https://로 시작하지 않으면 버튼이 아예 안 나왔음)
    function linkHref(u) {
        u = String(u || '').trim();
        if (!u) return '';
        if (/^https?:\/\//i.test(u)) return u;
        if (/^(tel|mailto|sms):/i.test(u)) return u;
        if (/^[\w-]+(\.[\w-]+)+([/?#].*)?$/i.test(u)) return 'https://' + u;
        return '';
    }
    // ---------- 섹션 제목 = 위치를 옮길 수 있는 문구 레이어 ----------
    // 제목을 높이가 정해진 칸(.ib-title-canvas) 안에 x·y(%)로 띄운다. 에디터에서는 끌어서 옮기고, 눌러서 글자 크기·폭·정렬·회전,
    // 두 번 눌러 바로 고치기 (에디터의 다른 문구 레이어와 똑같은 방식 - fields.layout[키]에 저장).
    // 위치·크기는 fields.layers[키] (프로필 섹션의 fields.layout은 이미 다른 뜻으로 쓰고 있어서 이름을 따로 씀),
    // 칸 높이는 fields[키 + 'H'] (기본 44px). 저장된 위치가 없으면 가운데 → 예전 모양과 거의 같다.
    const FONT_BASE = 390; // 에디터·공개페이지와 같은 글자 크기 기준 폭
    // 문구 테두리 (에디터 팝업 "테두리") - 에디터 INTRO_TEXT_SHADOW / INTRO_TEXT_OUTLINE과 같게
    const LAYER_SHADOW = '0 2px 6px rgba(0,0,0,.38), 0 0 1px rgba(0,0,0,.3)';
    const LAYER_OUTLINE = '-1px -1px 0 rgba(0,0,0,.55), 1px -1px 0 rgba(0,0,0,.55), -1px 1px 0 rgba(0,0,0,.55), 1px 1px 0 rgba(0,0,0,.55), 0 0 3px rgba(0,0,0,.35)';
    function titleLayout(f, key, fs, def) {
        const saved = f && f.layers && f.layers[key];
        const d = def || {};
        return Object.assign({ x: d.x != null ? +d.x : 50, y: d.y != null ? +d.y : 50, fontSize: fs || 18, scaleX: 100,
            widthAuto: !d.w, width: d.w ? +d.w : 80, align: d.align || '', rotation: 0 }, saved || {});
    }
    // 섹션 제목·자유 배치 문구의 글꼴 (에디터·render-invite.js의 fontOptions id와 같게)
    const PART_FONTS = { pretendard: "'Pretendard Variable', Pretendard, sans-serif", 'noto-serif-kr': "'Noto Serif KR', serif", 'gowun-batang': "'Gowun Batang', serif",
        'nanum-myeongjo': "'Nanum Myeongjo', serif", 'gothic-a1': "'Gothic A1', sans-serif", 'song-myung': "'Song Myung', serif", 'nanum-pen': "'Nanum Pen Script', cursive",
        'nanum-brush': "'Nanum Brush Script', cursive", gaegu: "'Gaegu', cursive", 'hi-melody': "'Hi Melody', cursive", 'gamja-flower': "'Gamja Flower', cursive" };
    function layerStyle(p) {
        return `${p.font && PART_FONTS[p.font] ? `font-family:${PART_FONTS[p.font]}; ` : ''}left:${p.x}%; top:${p.y}%; font-size:${(p.fontSize / FONT_BASE * 100).toFixed(3)}cqw; ${p.widthAuto ? '' : `width:${p.width}%; `}${p.align ? `text-align:${p.align}; ` : ''}${p.ls ? `letter-spacing:${Math.round(p.ls) / 1000}em; ` : ''}${/^#[0-9A-Fa-f]{6}$/.test(p.glow || '') ? `text-shadow:0 0 4px ${p.glow}, 0 0 10px ${p.glow}, 0 0 18px ${p.glow}; ` : p.outline ? `text-shadow:${LAYER_OUTLINE}; ` : p.shadow ? `text-shadow:${LAYER_SHADOW}; ` : ''}${p.color && /^#[0-9A-Fa-f]{6}$/.test(p.color) ? `color:${p.color}; ` : ''}transform:translate(-50%,-50%) scaleX(${(p.scaleX || 100) / 100})${p.rotation ? ` rotate(${p.rotation}deg)` : ''};`;
    }
    // ---------- 자유 배치 칸 (여러 문구를 한 칸 안에서 각자 끌어서 옮김) ----------
    // parts: [[키, 안쪽 HTML, { x, y, fs(글자 크기), w(폭 %, 긴 글은 꼭), align, cls }]] - 위치는 fields.layers[키], 칸 높이는 fields[칸키 + 'H']
    function freeCanvas(f, canvasKey, h, parts, extraCls) {
        const hh = Math.max(40, Math.min(1200, Number(f && f[canvasKey + 'H']) || h));
        return `<div class="ib-title-canvas ib-free-canvas free-canvas drag-canvas ${extraCls || ''}" style="height:${hh}px;">` + parts.filter(p => p && p[1]).map(([key, html, o]) => {
            o = o || {};
            const p = titleLayout(f, key, o.fs, o);
            return `<div class="${o.cls || ''} ib-title-layer drag-part on-light" data-part="${key}" data-drag-el data-fs="${o.fs || 16}" data-x="${o.x != null ? o.x : 50}" data-y="${o.y != null ? o.y : 50}"${o.w ? ` data-w="${o.w}"` : ''}${o.align ? ` data-align="${o.align}"` : ''} data-th="${h}" data-hkey="${canvasKey}H" style="${layerStyle(p)}">${html}</div>`;
        }).join('') + '</div>';
    }
    function titleLayer(text, f, key, cls, fs, h) {
        if (!text) return '';
        key = key || 'title';
        const p = titleLayout(f, key, fs);
        const hh = Math.max(24, Math.min(320, Number(f && f[key + 'H']) || h || 44));
        const style = layerStyle(p);
        return `<div class="ib-title-canvas free-canvas drag-canvas" data-title-key="${key}" style="height:${hh}px;"><h4 class="${cls || ''} ib-title-layer drag-part on-light" data-part="${key}" data-drag-el data-fs="${fs || 18}" data-th="${h || 44}" style="${style}">${esc(text)}</h4></div>`;
    }
    function sectionTitle(t, f) { return titleLayer(t, f, 'title', 'ib-title'); }
    // 계좌 한 줄 "국민 123-456 (김민준)" → 계좌번호 + 예금주는 괄호 없이 옅은 글씨로 (빈 괄호 "()"는 숨김)
    function accHtml(v) {
        const s = String(v || '').replace(/\s*\(\s*\)\s*$/, '').trim(), m = s.match(/^(.*?)\s*\(([^()]+)\)$/);
        return m ? `${esc(m[1])}<span class="ib-acc-hd">${esc(m[2].trim())}</span>` : esc(s);
    }

    // ---------- 사진 "보이는 부분" (잘릴 때 어느 쪽을 보여줄지) ----------
    // 청첩장 전체에 하나의 표 design.imgFocus = { "파일이름.webp": "50% 30%" } (사진 파일 이름은 올릴 때 무작위라 겹치지 않음).
    // 그린 뒤에 applyImgFocus(root, 표)로 <img>의 object-position, 배경 사진의 background-position을 맞춘다 (에디터·공개 페이지 공통).
    const FOCUS_RE = /^\d{1,3}(\.\d+)?% \d{1,3}(\.\d+)?%$/;
    function imgKey(src) { const m = String(src || '').split(/[?#]/)[0].match(/([^/]+)$/); return m ? m[1] : ''; }
    // zoom = design.imgZoom = { "파일이름.webp": 1.6 } - 사진 칸 안에서 확대 (1 ~ 4배). 칸 크기는 그대로, 보이는 부분 점을 기준으로 커짐
    function zoomOf(zoom, key) { const z = Number(zoom && zoom[key]); return z > 1.001 && z <= 4 ? Math.round(z * 100) / 100 : 1; }
    function applyImgZoom(im, z, pos) {
        if (z <= 1) {
            if (im.dataset.fz) { im.style.scale = ''; im.style.transformOrigin = ''; im.style.clipPath = ''; delete im.dataset.fz; }
            return;
        }
        const [px, py] = (pos && FOCUS_RE.test(pos) ? pos : '50% 50%').split(' ').map(parseFloat);
        const k = 1 - 1 / z, f = n => Math.round(n * k * 100) / 100 + '%';
        // 확대한 만큼 바깥을 잘라서 원래 칸 크기 그대로 보이게 (모서리 둥글기도 확대 전 크기로)
        // 모서리 4개를 각각(가로/세로 반지름) 그대로 - 아치(위만 둥근)·원·알약 모양이 확대해도 안 바뀌게
        //  (예전엔 왼쪽 위 모서리 값 하나를 네 모서리에 다 써서 아치가 타원으로 바뀌었음)
        let round = '';
        try {
            const cs = getComputedStyle(im);
            const sc = v => { const n = parseFloat(v) / z; return (Math.round(n * 100) / 100) + (/%$/.test(v) ? '%' : 'px'); };
            const cs4 = ['borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomRightRadius', 'borderBottomLeftRadius'].map(k => { const p = String(cs[k] || '0px').trim().split(/\s+/); return [p[0], p[1] || p[0]]; });
            if (cs4.some(([h, v]) => parseFloat(h) || parseFloat(v))) round = ' round ' + cs4.map(c => sc(c[0])).join(' ') + ' / ' + cs4.map(c => sc(c[1])).join(' ');
        } catch (e) {}
        im.style.transformOrigin = `${px}% ${py}%`;
        im.style.scale = String(z);
        im.style.clipPath = `inset(${f(py)} ${f(100 - px)} ${f(100 - py)} ${f(px)}${round})`;
        im.dataset.fz = '1';
    }
    function applyImgFocus(root, map, zoom) {
        if (!root) return;
        map = map || {};
        root.querySelectorAll('img').forEach(im => {
            if (im.closest('.tip-demo, .sticker-el') || im.classList.contains('sticker-el')) return;
            const key = imgKey(im.getAttribute('src'));
            const v = map[key];
            if (v && FOCUS_RE.test(v)) { im.style.objectPosition = v; im.dataset.fp = '1'; }
            else if (im.dataset.fp) { im.style.objectPosition = ''; delete im.dataset.fp; }
            applyImgZoom(im, zoomOf(zoom, key), v);
        });
        root.querySelectorAll('[style*="background-image"]').forEach(el => {
            const m = (el.getAttribute('style') || '').match(/background-image:\s*url\((['"]?)([^'")]+)\1\)/);
            const v = m && map[imgKey(m[2])];
            if (v && FOCUS_RE.test(v)) el.style.backgroundPosition = v;
        });
    }

    // ---------- 섹션 "안쪽 창 색" (연락처 카드·안내 칸·방명록 글·하객 안내·팝업 창 등) ----------
    // 섹션(block)에 boxBg / boxInk(#색)를 저장 → .col에 --box-bg / --box-ink와 ib-box-bg / ib-box-ink 클래스를 붙인다 (에디터·공개 페이지 공통)
    const BOX_COLOR_SECTIONS = ['contact', 'notice', 'account', 'guestbook', 'rsvp', 'dayinfo', 'trip', 'lottery', 'guestsnap', 'share'];
    const HEX_RE = /^#[0-9a-fA-F]{3,8}$/;
    // ---------- 섹션 테마 (섹션마다 따로: 빈티지 큐피드 · 밤하늘 골드 · 안개 유리 · 다른 디자인 색) ----------
    //  b.skin = 'vintage' | 'night' | 'mist' (디자인 폴더 blockFields로 줄 땐 fields.skin), b.skinPal = {bg, ink, accent, line, muted} (다른 디자인 색)
    //  연락하기·마음 전하실 곳·디데이는 원래 '빈티지 큐피드' 모양이 있어서 섹션 테마의 빈티지는 안 보여 줌 (SKIN_HAS_VINTAGE)
    const SECTION_SKINS = [['', '디자인 따라'], ['vintage', '빈티지 큐피드'], ['night', '밤하늘 골드'], ['mist', '안개 유리'], ['webtoon', '웹툰 컷']];
    const SKIN_HAS_VINTAGE = ['contact', 'account', 'dday'];
    const SKIN_DARK = ['night', 'mist'];
    // ---------- 디자인 테마 스킨 (섹션 하나만 다른 디자인 느낌으로: 색·글꼴·모서리·종이 + 디자인마다 다른 꾸밈) ----------
    //  b.skinPal = {bg, ink, accent, line, muted, hf(제목 글꼴), bf(본문 글꼴), hw(제목 굵기), r(모서리), paper, font(글꼴 id), deco, id(디자인 id)}
    //  deco = THEME_DECOS 중 하나 → .col.ib-th.ib-th-<deco> (invite-blocks.css '디자인 테마 스킨'). '@vintage' 같은 건 섹션 테마(SECTION_SKINS)를 그대로 씀
    //  디자인 폴더 preset.json에 "sectionLook"을 적으면 그것, 없으면 아래 THEME_LOOKS
    const THEME_DECOS = ['rule', 'bold', 'soft', 'mono', 'leaf', 'gold', 'kraft', 'lace', 'film', 'cinema', 'polaroid', 'note', 'typo', 'board', 'tape', 'deco', 'sky', 'cupid', 'stars', 'drops', 'angel'];
    const THEME_LOOKS = { classic: 'rule', modern: 'bold', pastel: 'soft', 'p-mono': 'mono', 'p-romantic': 'cupid', 'p-garden': 'leaf', 'p-navy': 'gold',
        'p-earth': 'kraft', 'p-lavender': 'lace', 'p-film': 'film', 'p-cinema': 'cinema', 'p-photos': 'polaroid', 'p-story': 'note', 'p-typo': 'typo',
        'p-notice': 'board', 'p-scrapbook': 'tape', 'p-cosmos': 'stars', 'p-rain': 'drops', 'p-midnight': 'deco', 'p-weather': 'sky', 'p-webtoon': '@webtoon', 'p-angel': 'angel' };
    const FONT_RE = /^[^<>{};]{1,120}$/;
    const THEME_FONTS = { tape: 'nanum-pen', note: 'nanum-pen', typo: 'playfair', film: 'cormorant', cinema: 'cormorant', deco: 'cormorant', rule: 'cormorant', angel: 'cormorant' }; // 테마 부품에 쓰는 글꼴 (숫자·영문)
    function themeFonts(k) { if (THEME_FONTS[k]) try { ensureFont(THEME_FONTS[k]); } catch (e) {} if (k === 'angel') try { ensureCalFonts(); } catch (e) {} } // 천사의 편지: 달 이름·숫자는 Cinzel (빈티지 큐피드 달력과 같은 글꼴)
    // 인터뷰(둘만의 사랑 이야기) 모양 - 에디터·공개 페이지 둘 다 .blk-interview에 iv-st-* (CSS는 invite-blocks.css '인터뷰 모양')
    const IV_STYLES = [['', '이름표'], ['chat', '채팅 말풍선'], ['card', '질문 카드'], ['mag', '매거진'], ['split', '마주 보기']];
    const ivCls = f => { const v = f && f.style; return IV_STYLES.some(x => x[0] === v && v) ? ' iv-st-' + v : ''; };
    function hexDark(h) { // 바탕색이 어두운지 (밝기 0.45 아래) - #RGB · #RRGGBB · rgb()
        const rg = String(h || '').trim().match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i);
        if (rg) return (0.299 * rg[1] + 0.587 * rg[2] + 0.114 * rg[3]) / 255 < 0.45;
        const m = String(h || '').trim().match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i); if (!m) return false;
        const x = m[1].length === 3 ? m[1].replace(/./g, c => c + c) : m[1], n = parseInt(x, 16);
        return (0.299 * (n >> 16 & 255) + 0.587 * (n >> 8 & 255) + 0.114 * (n & 255)) / 255 < 0.45;
    }
    // 포인트 색 위 글자색: 보통은 흰색, 포인트 색이 아주 밝으면(금색·하늘색 등 - 흰 글자 대비 2.6 아래) 진한 색 → CSS var(--p-on-accent)
    function onAccent(h) {
        const s = String(h || '').trim(), rg = s.match(/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i), m = s.match(/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i);
        let c; if (rg) c = [+rg[1], +rg[2], +rg[3]]; else if (m) { const x = m[1].length === 3 ? m[1].replace(/./g, d => d + d) : m[1], n = parseInt(x, 16); c = [n >> 16 & 255, n >> 8 & 255, n & 255]; } else return '#FFFFFF';
        const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }, L = .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]);
        return 1.05 / (L + .05) < 2.6 ? '#231E22' : '#FFFFFF'; // (밝은 디자인 포인트 색은 모두 3.1 넘음, 어두운 디자인 금색·하늘색은 2.3 아래)
    }
    function skinOf(b) {
        const k = b && (b.skin || (b.fields && b.fields.skin));
        return SECTION_SKINS.some(x => x[0] === k && k) ? k : '';
    }
    function boxColAttrs(b) {
        const bg = b && HEX_RE.test(b.boxBg || '') ? b.boxBg : '', ink = b && HEX_RE.test(b.boxInk || '') ? b.boxInk : '';
        let cls = (bg ? ' ib-box-bg' : '') + (ink ? ' ib-box-ink' : '');
        if (bg && !ink) cls += hexDark(bg) ? ' ib-box-dk' : ' ib-box-lt'; // 창 색만 골랐을 때: 디자인 바탕과 반대 밝기면 CSS가 글자색을 알아서 (어두운 디자인 + 흰 창 → 글자가 안 보이던 것)
        const st = [bg ? `--box-bg:${bg}` : '', ink ? `--box-ink:${ink}` : ''];
        let sk = skinOf(b); const pal = b && b.skinPal && typeof b.skinPal === 'object' ? b.skinPal : null;
        // 색 바꾸면 꾸밈도 같이: 디자인이 정해 둔 섹션 테마(fields.skin - 직접 고른 b.skin은 그대로)는 색을 바꾸면 디자인 꾸밈으로 (웹툰 컷은 그대로)
        if (sk && sk !== 'webtoon' && !b.skin && colorLinkOn() && colorChanged() && designLook()) sk = '';
        const palLink = !!(pal && pal.at && CUR_COLORS && colorLinkOn() && pal.at !== colKey(CUR_COLORS)); // 디자인 스킨을 고른 뒤 색을 바꿈 → 모양은 그대로, 색은 지금 색
        if (sk) cls += ` ib-sk ib-sk-${sk}${SKIN_DARK.includes(sk) ? ' ib-sk-dark' : ''}`;
        if (sk === 'webtoon') ensureFont('black-han-sans'); // 웹툰 컷 말풍선 제목 글꼴
        else if (pal && HEX_RE.test(pal.bg || '') && HEX_RE.test(pal.ink || '')) { // 다른 디자인 색으로
            cls += ' ib-sk ib-sk-pal' + (hexDark(palLink ? CUR_COLORS.bg : pal.bg) ? ' ib-sk-dark' : ' ib-sk-light') + (palLink ? ' ib-sk-link' : '');
            if (!palLink) {
                [['bg', 'p-bg'], ['ink', 'p-ink'], ['accent', 'p-accent'], ['line', 'p-line'], ['muted', 'p-muted']].forEach(([k, v]) => { if (HEX_RE.test(pal[k] || '')) st.push(`--${v}:${pal[k]}`); });
                if (HEX_RE.test(pal.accent || '')) st.push(`--p-on-accent:${onAccent(pal.accent)}`);
            }
            // 디자인 테마 스킨: 글꼴·모서리·종이 + 꾸밈
            if (THEME_DECOS.includes(pal.deco)) { cls += ' ib-th ib-th-' + pal.deco; themeFonts(pal.deco); }
            if (pal.font && FONT_CSS[pal.font]) ensureFont(pal.font);
            const fq = v => String(v).replace(/"/g, "'"); // style="" 안에 들어가서 큰따옴표는 작은따옴표로
            if (FONT_RE.test(pal.hf || '')) st.push(`--p-head-font:${fq(pal.hf)}`);
            if (FONT_RE.test(pal.bf || '')) st.push(`--p-body-font:${fq(pal.bf)}`);
            if (/^[1-9]00$/.test(String(pal.hw || ''))) st.push(`--p-head-weight:${pal.hw}`);
            if (/^\d{1,2}px$/.test(String(pal.r || ''))) st.push(`--p-radius:${pal.r}`);
            if (pal.paper && BG_PAPERS[pal.paper]) st.push(`--sk-paper:${BG_PAPERS[pal.paper].img}`);
        }
        // 디자인 전체 꾸밈: 디자인을 고르면 그 디자인의 꾸밈(design.sectionLook)이 모든 섹션에 기본으로 (섹션 테마·디자인 스킨을 따로 고른 섹션, 첫 화면, '꾸밈 없이'는 빼고 · 화면 설정 extras.look=false면 끔)
        else if (!sk && b && b.id !== 'hero' && b.id !== 'heroVideo' && b.skin !== 'plain') { const gl = designLook(); if (gl) { cls += ' ib-th ib-th-' + gl; themeFonts(gl); } }
        return { cls, style: st.filter(Boolean).join(';') };
    }

    // ---------- 스티커 반짝이며 등장 · 연기처럼 사라지기 (알갱이 뿌리기) ----------
    // 등장 효과가 재생되는 순간(.in-view가 붙을 때) 에디터·공개 페이지가 불러준다. 스티커 자체 움직임은 CSS(rvBoing·rvSpin·rvPuff)
    const SPARK_COLORS = ['#E0A72E', '#F2B8C6', '#F5D76E', '#FFFFFF', '#E8A0B4'];
    function burstAt(el, kind) {
        if (!el || !el.isConnected || !el.parentElement) return;
        const size = Math.max(16, el.offsetWidth || 24);
        const b = document.createElement('span');
        b.className = 'ib-burst ' + kind;
        // 스티커는 left/top이 가운데 자리 (translate(-50%,-50%))라 offsetLeft/Top이 곧 가운데
        b.style.left = el.offsetLeft + 'px'; b.style.top = el.offsetTop + 'px';
        b.style.zIndex = (parseInt(el.style.zIndex, 10) || 10) + 1;
        const n = kind === 'spark' ? 8 : 7;
        let html = '';
        for (let i = 0; i < n; i++) {
            const a = (i / n) * Math.PI * 2 + Math.random() * .5;
            const dist = size * (kind === 'spark' ? .75 + Math.random() * .45 : .45 + Math.random() * .4);
            const dx = Math.cos(a) * dist, dy = Math.sin(a) * dist - (kind === 'puff' ? size * .35 : 0);
            const fs = kind === 'spark' ? Math.round(size * (.22 + Math.random() * .18)) : Math.round(size * (.22 + Math.random() * .2));
            html += `<i style="--dx:${dx.toFixed(1)}px;--dy:${dy.toFixed(1)}px;--fs:${fs}px;--d:${(Math.random() * .12).toFixed(2)}s;--r:${Math.round(60 + Math.random() * 120)}deg;--c:${SPARK_COLORS[i % SPARK_COLORS.length]}">${kind === 'spark' ? (i % 2 ? '✦' : '✧') : ''}</i>`;
        }
        b.innerHTML = html;
        el.parentElement.appendChild(b);
        setTimeout(() => b.remove(), 1400);
    }
    function stickerFx(el) {
        if (!el || typeof document === 'undefined') return;
        if (global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const spark = el.dataset.fxSpark === '1', puff = el.dataset.hideFx === 'puff';
        if (!spark && !puff) return;
        const cs = getComputedStyle(el);
        const delay = parseFloat(cs.getPropertyValue('--rv-delay')) || 0;
        const hide = el.classList.contains('rv-vanish') ? (parseFloat(cs.getPropertyValue('--rv-hide')) || 2) : 0;
        const inDur = el.classList.contains('reveal-boing') ? .9 : el.classList.contains('reveal-spin') ? .8 : .7;
        clearTimeout(el._fxT1); clearTimeout(el._fxT2);
        const alive = () => el.isConnected && el.classList.contains('in-view');
        if (spark) el._fxT1 = setTimeout(() => { if (alive()) burstAt(el, 'spark'); }, (delay + .22) * 1000);
        if (puff && hide && !el.classList.contains('rv-novanish')) el._fxT2 = setTimeout(() => { if (alive()) burstAt(el, 'puff'); }, (delay + inDur + hide + .05) * 1000);
    }

    // ---------- 첫 화면 "다음 섹션" 버튼 (유튜브 영상 히어로·히어로 아래 가운데 동그란 ↓) ----------
    // nextBtn(켜기), nextStyle: glass(반투명 흰)·dark·light·accent·line(선만), nextSize: small·normal·large, nextOpacity 30~100
    const NEXT_STYLES = [['glass', '반투명 유리'], ['dark', '어두운'], ['light', '밝은'], ['accent', '포인트 색'], ['line', '선만'], ['wave', '음악 웨이브']];
    const NEXT_SIZES = [['small', '작게'], ['normal', '보통'], ['large', '크게']];
    // 직접 꾸미기 (nextStyle: 'custom') - nextIcon 아이콘 · nextShape 바탕 · nextColor 색(#RRGGBB) · nextAnim 움직임
    const NEXT_ICONS = [['chev', '꺾쇠'], ['arrow', '화살표'], ['dbl', '두 줄'], ['mouse', '마우스'], ['heart', '하트']];
    const NEXT_SHAPES = [['none', '없음'], ['circle', '원'], ['square', '둥근 네모'], ['ring', '테두리']];
    const NEXT_ANIMS = [['bob', '통통'], ['fade', '깜빡'], ['none', '멈춤']];
    const NEXT_ICON_PATHS = {
        chev: '<path d="M6 9l6 6 6-6"/>',
        arrow: '<path d="M12 4.5v15M6.5 14L12 19.5 17.5 14"/>',
        dbl: '<path d="M7 6.5l5 5 5-5M7 12.5l5 5 5-5"/>',
        mouse: '<rect x="7.5" y="3" width="9" height="15" rx="4.5"/><path d="M12 6.5v3M9.5 20.5l2.5 1.6 2.5-1.6"/>',
        heart: '<path d="M12 19.5s-7-4.3-7-9.2A3.8 3.8 0 0 1 12 8a3.8 3.8 0 0 1 7 2.3c0 4.9-7 9.2-7 9.2z"/>',
    };
    const nbPick = (list, v, d) => list.some(x => x[0] === v) ? v : d;
    function nextColorInk(hex) {
        const n = parseInt(String(hex).slice(1), 16); if (isNaN(n)) return '#fff';
        return ((n >> 16 & 255) * .299 + (n >> 8 & 255) * .587 + (n & 255) * .114) / 255 > .62 ? '#2B2320' : '#fff';
    }
    // 첫 화면이 전체화면인지 (히어로: 가로 100% + 전체화면 / 유튜브 히어로: 화면 높이 전체화면)
    function heroFull(f, kind) {
        if (!f) return false;
        return kind === 'video' ? f.heightMode === 'full' : (f.heroWidth === 'full' && f.heroRatio === 'screen');
    }
    // 이름·날짜 글자가 사진/영상 위에 있는지 (유튜브 히어로는 예전부터 영상 위라서 값이 없으면 켜짐)
    function heroTextOn(f, kind) {
        if (!f) return false;
        return kind === 'video' ? f.videoTextOver !== false : !!(f.heroTextOver && f.heroImage);
    }
    // 메인 영상 "영상 아래 글자 칸" (메인 사진과 같은 방식): 글자가 영상 위일 때, 영상 아래로 heroTextSpace(px, 390 기준) 만큼 칸을 더 두고
    //  글자 칸이 영상 + 아래 칸 전체를 덮어서 이름·날짜를 영상 위·아래 어디든 놓을 수 있다. 0(기본)이면 예전처럼 영상 위에만
    function videoBandSpace(f) {
        if (!f || !heroTextOn(f, 'video') || heroFull(f, 'video')) return 0;
        return Math.max(0, Math.min(500, Number(f.heroTextSpace) || 0));
    }
    // 스크롤 버튼(↓)은 전체화면 + 글자를 사진/영상 위로 켰을 때만 (글자가 아래 칸에 있으면 ↓ 자리가 겹쳐서)
    function nextBtnAllowed(f, kind) {
        return heroFull(f, kind) && heroTextOn(f, kind);
    }
    function nextBtnHtml(f, kind) {
        if (!f || !f.nextBtn) return '';
        if (kind && !nextBtnAllowed(f, kind)) return '';
        const custom = f.nextStyle === 'custom';
        const st = custom ? 'custom' : nbPick(NEXT_STYLES, f.nextStyle, 'glass');
        const sz = nbPick(NEXT_SIZES, f.nextSize, 'normal');
        const op = Math.max(30, Math.min(100, Number(f.nextOpacity) || 85)) / 100;
        let cls = `ib-next ib-next-${st} ib-next-${sz}`, css = `--next-op:${op};`, icon = 'chev';
        if (custom) {
            icon = nbPick(NEXT_ICONS, f.nextIcon, 'chev');
            const col = /^#[0-9a-f]{6}$/i.test(f.nextColor || '') ? f.nextColor : '#FFFFFF';
            cls += ` ib-next-sh-${nbPick(NEXT_SHAPES, f.nextShape, 'circle')}`;
            css += `--next-c:${col};--next-ink:${nextColorInk(col)};`;
        }
        cls += ` ib-next-an-${nbPick(NEXT_ANIMS, f.nextAnim, 'bob')}`; // 움직임(통통·깜빡·멈춤)은 모든 모양에 (예전엔 직접 꾸미기에서만)
        const fx = nextFxOf(f);
        const wave = st === 'wave' ? '<span class="ib-wv" aria-hidden="true"><i></i><i></i><i></i></span>' : ''; // 음악 웨이브: 배경음악 박자에 맞춰 물결이 퍼짐 (음악이 없으면 잔잔하게)
        return `<button type="button" class="${cls}" data-ib-next${fx !== 'none' ? ` data-fx="${fx}"` : ''} aria-label="다음 내용 보기" style="${css}">${wave}<svg viewBox="0 0 24 24" aria-hidden="true">${NEXT_ICON_PATHS[icon]}</svg></button>`;
    }
    // ---- 배경음악 박자 읽기 (스크롤 버튼 "음악 웨이브"용): --ib-beat(0~1)를 문서에 계속 넣어 줌 ----
    //  같은 사이트 음악만 (다른 사이트 음악을 분석기에 물리면 소리가 안 나서). 소리 장치(AudioContext)가 켜진 경우에만 연결
    let beatCtx = null, beatRaf = 0;
    const beatNodes = typeof WeakMap !== 'undefined' ? new WeakMap() : null;
    function beatWatch(audio) {
        try {
            if (!audio || !beatNodes || typeof document === 'undefined' || !document.querySelector('.ib-next-wave')) return;
            if (new URL(audio.currentSrc || audio.src, location.href).origin !== location.origin) return;
            const AC = global.AudioContext || global.webkitAudioContext; if (!AC) return;
            beatCtx = beatCtx || new AC();
            const de = document.documentElement;
            const start = () => {
                if (beatCtx.state !== 'running') return;
                let nd = beatNodes.get(audio);
                if (!nd) {
                    const src = beatCtx.createMediaElementSource(audio), an = beatCtx.createAnalyser();
                    an.fftSize = 256; an.smoothingTimeConstant = .72; src.connect(an); an.connect(beatCtx.destination);
                    nd = { an, buf: new Uint8Array(an.frequencyBinCount) }; beatNodes.set(audio, nd);
                }
                cancelAnimationFrame(beatRaf);
                let avg = 0;
                const tick = () => {
                    if (audio.paused) { de.style.setProperty('--ib-beat', '0'); de.classList.remove('ib-beat-on'); return; }
                    nd.an.getByteFrequencyData(nd.buf);
                    let sum = 0; for (let i = 1; i <= 24; i++) sum += nd.buf[i];
                    const lv = sum / 24 / 255; avg = avg * .93 + lv * .07;
                    de.style.setProperty('--ib-beat', Math.max(0, Math.min(1, (lv - avg * .8) * 3.2 + lv * .35)).toFixed(3));
                    de.classList.add('ib-beat-on');
                    beatRaf = requestAnimationFrame(tick);
                };
                tick();
            };
            if (beatCtx.state === 'suspended') beatCtx.resume().then(start).catch(() => {}); else start();
        } catch (e) {}
    }
    // ---- 스크롤 버튼 등장 효과 (nextFx) - 편집창에서 켤 때 · 청첩장에서 처음 보일 때 한 번만 ----
    const NEXT_FX = [['none', '없음'], ['pop', '퐁'], ['ripple', '물결'], ['drop', '위에서 툭'], ['shine', '빛 스윽'], ['spot', '스포트라이트'], ['tip', '말풍선'], ['hop', '콩콩'], ['spark', '별가루'], ['hand', '손가락 톡']];
    const NEXT_FX_MS = { pop: 950, ripple: 1950, drop: 1150, shine: 3000, spot: 2450, tip: 3050, hop: 1550, spark: 1300, hand: 2250 };
    function nextFxOf(f) { return f && NEXT_FX.some(x => x[0] === f.nextFx) ? f.nextFx : 'ripple'; }
    const FX_HAND = '<svg viewBox="0 0 34 40"><path d="M12 3.5c1.7 0 3 1.3 3 3v11.2l1-.2c1.2-.2 2.4.5 2.8 1.6l.2.7 1.2-.2c1.3-.2 2.5.6 2.8 1.9l.1.4.9-.1c1.6-.2 3 1 3.1 2.6l.4 6.2c.3 4.6-3.3 8.4-7.9 8.4h-3.6c-2.6 0-5-1.3-6.4-3.4L4 28.8c-.8-1.2-.5-2.8.7-3.6 1.1-.7 2.6-.5 3.4.5L9 26.9V6.5c0-1.7 1.3-3 3-3z" fill="#fff" stroke="#2B2320" stroke-width="1.6" stroke-linejoin="round"/></svg>';
    function playNextFx(btn, fx, ctx) {
        if (!btn || !fx || fx === 'none' || !NEXT_FX_MS[fx]) return;
        if (global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        clearTimeout(btn._fxT);
        btn.querySelectorAll('.ib-fx').forEach(n => n.remove());
        [...btn.classList].filter(c => c.indexOf('ib-fxon-') === 0).forEach(c => btn.classList.remove(c));
        void btn.offsetWidth;
        let h = '';
        if (fx === 'ripple' || fx === 'hand') h += '<i class="ib-fx ib-fx-ring"></i><i class="ib-fx ib-fx-ring"></i><i class="ib-fx ib-fx-ring"></i>';
        if (fx === 'shine') h += '<i class="ib-fx ib-fx-shine"><b></b></i>';
        if (fx === 'spot') h += '<i class="ib-fx ib-fx-spot"></i>';
        if (fx === 'tip') h += `<i class="ib-fx ib-fx-tip">${ctx === 'editor' ? '↓ 스크롤 버튼이 생겼어요' : '↓ 눌러서 아래로 넘겨 보세요'}</i>`;
        if (fx === 'hand') h += `<i class="ib-fx ib-fx-hand">${FX_HAND}</i>`;
        if (fx === 'spark') {
            const w = btn.offsetWidth || 46;
            h += '<i class="ib-fx ib-fx-spk">';
            for (let i = 0; i < 10; i++) {
                const a = i / 10 * Math.PI * 2, d = w * (.9 + Math.random() * .4);
                h += `<i style="--dx:${(Math.cos(a) * d).toFixed(1)}px;--dy:${(Math.sin(a) * d).toFixed(1)}px;--fs:${Math.round(w * (.24 + Math.random() * .14))}px;--d:${(Math.random() * .15).toFixed(2)}s">${i % 2 ? '✦' : '✧'}</i>`;
            }
            h += '</i>';
        }
        btn.insertAdjacentHTML('beforeend', h);
        btn.classList.add('ib-fxon-' + fx);
        btn._fxT = setTimeout(() => { btn.classList.remove('ib-fxon-' + fx); btn.querySelectorAll('.ib-fx').forEach(n => n.remove()); }, NEXT_FX_MS[fx] + 120);
    }
    // 청첩장: 스크롤 버튼이 화면에 처음 보이면 (인트로·팝업이 끝난 뒤) 한 번 재생
    function armNextFx(root) {
        if (typeof document === 'undefined' || typeof IntersectionObserver === 'undefined') return;
        (root || document).querySelectorAll('.ib-next[data-fx]').forEach(b => {
            if (b._fxArmed) return; b._fxArmed = true;
            const reduce = global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!reduce) b.classList.add('ib-fx-wait'); // 효과가 나올 때까지 숨겨 둠 (멈춰 있다가 갑자기 움직이지 않게)
            const io = new IntersectionObserver(ens => {
                if (!ens.some(en => en.isIntersecting)) return;
                io.disconnect();
                const t0 = Date.now();
                const busy = () => document.querySelector('.intro-overlay, .ib-modal') && Date.now() - t0 < 30000;
                (function wait() { // 인트로·첫 안내 팝업(참석 여부 등)이 다 끝나면 바로 재생 (예전엔 끝난 뒤 1.3초를 더 기다려서 너무 늦게 나왔음)
                    if (busy()) return setTimeout(wait, 150);
                    if (Date.now() - t0 >= 30000) b.classList.remove('ib-fx-wait');
                    setTimeout(() => { if (busy()) return wait(); b.classList.remove('ib-fx-wait'); if (b.isConnected) playNextFx(b, b.dataset.fx, 'invite'); }, 250);
                })();
            }, { threshold: .3 });
            io.observe(b);
        });
    }
    // ---- 사진·영상 위 글자 잘 보이게: 뒤쪽 그라데이션 (heroShade · heroShadeLv) ----
    //  유튜브 히어로는 예전부터 아래쪽이 어두웠으므로 값이 없으면 "아래 · 예전 진하기", 사진 히어로는 없음
    const HERO_SHADES = [['none', '없음'], ['bottom', '아래 어둡게'], ['top', '위 어둡게'], ['both', '위아래'], ['full', '전체 살짝'], ['light', '아래 밝게']];
    const HERO_SHADE_LV = [['1', '약하게'], ['2', '보통'], ['3', '진하게']];
    function heroShadeOf(f, kind) { return f && HERO_SHADES.some(x => x[0] === f.heroShade) ? f.heroShade : (kind === 'video' ? 'bottom' : 'none'); }
    function heroShadeHtml(f, kind) {
        const sh = heroShadeOf(f, kind);
        if (sh === 'none') return '';
        const lv = { 1: .45, 2: .7, 3: .9 }[f.heroShadeLv] || (kind === 'video' ? .78 : .7);
        return `<i class="ib-shade ib-shade-${sh}" style="--sh:${lv}" aria-hidden="true"></i>`;
    }
    // 히어로(사진/프레임) 이름·날짜 글자 칸
    //  heroTextOver: 켜면 글자 칸이 "사진 + 사진 아래 칸" 전체를 덮어서, 글자를 사진 위로도 끌어 올릴 수 있음 (사진이 있을 때만)
    //  heroTextSpace: 그때 사진 아래 글자 칸 높이(px, 390 기준 → 기기 폭 따라 같이 커지고 작아짐, 0이면 사진 위에만)
    //  heroTextInk: '' 테마색 · white 흰 글자(그림자) · dark 진한 글자
    function heroTextOpts(f) {
        f = f || {};
        const over = !!(f.heroTextOver && f.heroImage);
        // 전체화면이면 아래 글자 칸은 없음 (글자가 화면 밖 아래로 내려가지 않게 - 글자 자리 = 화면 한 장)
        const sp = heroFull(f, 'hero') ? 0 : Math.max(0, Math.min(500, f.heroTextSpace != null && f.heroTextSpace !== '' ? Number(f.heroTextSpace) || 0 : 220));
        // 글자색: 사진 위 칸에서는 직접 고른 색(white·dark)이 없으면 "자동" - 놓인 자리가 사진 위면 흰 글자, 아래 칸이면 테마색 (heroInkAuto)
        //  (예전에 "사진 위로"를 켜며 자동으로 흰색이 된 값(_inkAuto)도 자동으로 봄)
        let ink = f.heroTextInk === 'white' || f.heroTextInk === 'dark' ? f.heroTextInk : '';
        if (over && (!ink || (ink === 'white' && f._inkAuto))) ink = 'auto';
        return { over, space: sp,
            cls: (over ? ' hero-text-over' : '') + (over && !sp ? ' hero-text-nospace' : '') + (ink ? ' hero-ink-' + ink : ''),
            canvasCls: over ? ' hero-over-canvas' : '',
            canvasStyle: over ? '' : 'height:220px;',
            spacer: over ? `<div class="hero-text-space" style="height:${(sp / FONT_BASE * 100).toFixed(3)}cqw;"></div>` : '' };
    }
    // 첫 화면 글자색 자동: 글자(날짜·이름·하트)의 가운데가 사진·영상 위에 있으면 흰 글자(그림자), 아래 글자 칸이면 테마색
    //  화면 폭·사진 비율에 따라 사진 높이가 달라지므로 그릴 때마다(사진이 늦게 뜨면 다 뜬 뒤 한 번 더) 실제 자리로 판단한다
    function heroInkAuto(root) {
        if (!root || typeof document === 'undefined') return;
        root.querySelectorAll('.blk-hero.hero-ink-auto, .blk-hero-video.vt-overband').forEach(h => {
            const m = h.querySelector(':scope > .hero-photo-wrap, :scope > .video-cover-wrap'); if (!m) return;
            const img = m.querySelector('img');
            if (img && !img.complete && !img._inkWait) { img._inkWait = 1; img.addEventListener('load', () => heroInkAuto(root), { once: true }); }
            const media = (img && img.getBoundingClientRect().height ? img : m).getBoundingClientRect(); if (!media.height) return;
            h.querySelectorAll('.hero-over-canvas .drag-part').forEach(p => {
                const q = p.getBoundingClientRect(), cx = q.left + q.width / 2, cy = q.top + q.height / 2;
                const on = cy < media.bottom && cy > media.top && cx > media.left && cx < media.right;
                p.classList.toggle('ink-photo', on); p.classList.toggle('ink-band', !on);
            });
        });
    }
    // 히어로(사진/프레임) ↓ 버튼 자리: below(글자 칸 아래) · photo(사진 아래쪽 위) · free(자유) - 예전 저장값은 "휴대폰 화면 꽉" 사진이면 photo, 아니면 below
    function heroNextPos(f) {
        // 스크롤 버튼은 전체화면 히어로에서만 나오고 항상 가운데 아래 자동 - 사진이 있으면 사진(=화면) 아래쪽, 없으면 글자 칸 아래
        return f && f.heroImage ? 'photo' : 'below';
    }
    // ---- 화면 밖 섹션은 움직이는 장식(무한 반복 애니메이션)을 멈춤 + (에디터) 유튜브 영상은 잠깐 내림 ----
    // 신혼여행 지도 점선·핀, ↓ 버튼 흔들림 같은 무한 애니메이션이 화면 밖에서도 매 프레임 다시 그려져서
    // 휴대폰이 느려지고 배터리를 먹었다. 화면 근처(위아래 300px)에 들어오면 다시 움직임.
    let offIO = null;
    function watchOffscreen(root, opts) {
        if (typeof IntersectionObserver === 'undefined' || !root) return;
        opts = opts || {};
        if (!offIO) offIO = new IntersectionObserver(entries => entries.forEach(en => {
            const col = en.target, off = !en.isIntersecting;
            col.classList.toggle('ib-off', off);
            if (col._unloadVideo) col.querySelectorAll('iframe[data-cover]').forEach(f => {
                if (off && f.src && f.src !== 'about:blank') { f.dataset.offSrc = f.src; f.src = 'about:blank'; }
                else if (!off && f.dataset.offSrc) { f.src = f.dataset.offSrc; delete f.dataset.offSrc; }
            });
        }), { rootMargin: '300px 0px' });
        root.querySelectorAll('.col[data-block-id]').forEach(col => { col._unloadVideo = !!opts.unloadVideo; offIO.observe(col); });
    }
    // 누르면 다음 섹션의 맨 위가 화면 맨 위에 오도록 (에디터 미리보기·실제 청첩장 공통)
    function goNextSection(btn) {
        const col = btn.closest('.col[data-block-id]');
        if (!col) return;
        const cols = Array.from(document.querySelectorAll('.col[data-block-id]')).filter(c => c.offsetParent !== null && c.getBoundingClientRect().height > 2 && c.closest('#previewRoot, .invite-root, body') === col.closest('#previewRoot, .invite-root, body'));
        const next = cols[cols.indexOf(col) + 1];
        if (!next) return;
        // 다음 섹션 위쪽에 딱 맞게: 스크롤 등장 효과(아래에서 올라오기 등)로 섹션이 잠깐 아래로 밀려 있어도
        // 밀리기 전 원래 자리(offsetTop - transform 무시)로 계산 (예전 scrollIntoView는 밀린 자리에 맞춰서 살짝 더 내려갔음)
        const absTop = el => { let t = 0; for (let e = el; e; e = e.offsetParent) t += e.offsetTop + (e !== el ? e.clientTop : 0); return t; };
        let sc = next.parentElement;
        while (sc && sc !== document.body && sc !== document.documentElement) { const o = getComputedStyle(sc).overflowY; if ((o === 'auto' || o === 'scroll') && sc.scrollHeight > sc.clientHeight + 2) break; sc = sc.parentElement; }
        if (sc && sc !== document.body && sc !== document.documentElement) sc.scrollTo({ top: Math.max(0, absTop(next) - absTop(sc) - sc.clientTop), behavior: 'smooth' });
        else global.scrollTo({ top: Math.max(0, absTop(next)), behavior: 'smooth' });
    }
    // ---- ↓ 버튼을 "지금 보이는 화면" 아래쪽에 맞추기 ----
    // 휴대폰은 100vh·화면 꽉 사진이 주소창·아래 도구막대 뒤까지 내려가거나, 히어로가 첫 화면보다 길어서
    // 버튼(섹션 맨 아래)이 화면 밖으로 가려졌었다. 섹션 아래쪽이 화면 밖이면 버튼을 화면 아래쪽까지만 끌어올린다
    // (스크롤하면 섹션 끝에 닿을 때까지 화면 아래에 붙어 있음 = sticky). 자유 위치(ib-next-free)는 정한 자리 그대로.
    function dockNextButtons() {
        if (typeof document === 'undefined') return;
        const vv = global.visualViewport;
        const visBottom = vv ? vv.offsetTop + vv.height : global.innerHeight;
        document.querySelectorAll('.ib-next:not(.ib-next-free)').forEach(b => {
            let bottom = visBottom;
            const pane = b.closest('.preview-pane'); // 에디터 PC: 미리보기 칸이 따로 스크롤됨
            if (pane && pane.scrollHeight > pane.clientHeight + 2) bottom = Math.min(bottom, pane.getBoundingClientRect().bottom);
            if (b.closest('#previewRoot')) { // 에디터 휴대폰 화면: 아래 고정 도구막대(임시저장·발행) 위까지만
                const br0 = b.getBoundingClientRect();
                document.querySelectorAll('.action-bar, .width-fab-row').forEach(bar => {
                    const br = bar.getBoundingClientRect(); // 고정 요소는 offsetParent가 없어서 크기·자리로만 판단
                    // 버튼과 가로로 겹치는 막대만 피함 (왼쪽 구석의 "표준·좁게·테두리" 작은 막대 때문에 버튼이 위로 들려 있었음)
                    const overlapX = br.left < br0.right - 4 && br.right > br0.left + 4;
                    if (overlapX && br.height && br.width > 120 && br.top > bottom * .6 && br.top < bottom && getComputedStyle(bar).position === 'fixed') bottom = br.top;
                });
            }
            const box = b.closest('.hero-photo-wrap, .video-cover-wrap, .blk-hero') || b.parentElement;
            const r = b.getBoundingClientRect(), cr = box.getBoundingClientRect();
            if (!r.height) return;
            const cur = b._dock || 0;
            const natTop = r.top + cur, natBottom = r.bottom + cur; // 끌어올리기 전 원래 자리
            const need = natBottom + 14 - bottom;               // 화면 아래 14px 여유
            const topVis = pane && pane.scrollHeight > pane.clientHeight + 2 ? Math.max(0, pane.getBoundingClientRect().top) : (vv ? vv.offsetTop : 0);
            const showing = cr.top < topVis + (bottom - topVis) * 0.5; // 섹션이 화면 위쪽 절반부터 차지할 때만 (아래에 살짝 걸친 섹션은 그대로)
            let dock = need > 0 && showing ? Math.min(need, natTop - cr.top - 10) : 0; // 섹션 위로는 안 넘어가게
            // 에디터: 끌어올리지 않고 사진·영상 칸 맨 아래에 그대로 붙여 둠 (화면 크기를 재서 올리면 기기마다 위치가 달라 너무 위에 떠 보였음)
            if (b.closest('#previewRoot')) dock = 0;
            dock = Math.max(0, Math.round(dock));
            if (dock !== cur) { b._dock = dock; b.style.translate = dock ? `0 ${-dock}px` : ''; }
        });
    }
    let dockRaf = 0;
    function scheduleDock() { if (!dockRaf && typeof requestAnimationFrame !== 'undefined') dockRaf = requestAnimationFrame(() => { dockRaf = 0; dockNextButtons(); }); }
    let nextBound = false;
    function bindNextButtons() {
        scheduleDock();
        if (nextBound || typeof document === 'undefined') return; nextBound = true;
        // 스크롤(페이지·에디터 미리보기 칸)·화면 크기·주소창 접힘·사진 로딩·미리보기 다시 그리기 때마다 다시 맞춤
        document.addEventListener('scroll', scheduleDock, { passive: true, capture: true });
        global.addEventListener('resize', scheduleDock);
        if (global.visualViewport) { global.visualViewport.addEventListener('resize', scheduleDock); global.visualViewport.addEventListener('scroll', scheduleDock); }
        document.addEventListener('load', e => { if (e.target && e.target.tagName === 'IMG') scheduleDock(); }, true);
        if (typeof MutationObserver !== 'undefined' && document.body) new MutationObserver(muts => { if (muts.some(m => [...m.addedNodes].some(n => n.nodeType === 1 && (n.matches('.ib-next, .col, .row-flex') || n.querySelector && n.querySelector('.ib-next'))))) scheduleDock(); })
            .observe(document.body, { childList: true, subtree: true });
        setTimeout(scheduleDock, 600); setTimeout(scheduleDock, 1800);
        document.addEventListener('click', e => {
            const b = e.target.closest && e.target.closest('[data-ib-next]'); if (!b) return;
            e.preventDefault(); e.stopPropagation();
            if (b._dragged) { b._dragged = false; return; } // 에디터에서 끌어서 옮긴 직후의 클릭은 넘기기 아님
            goNextSection(b);
        }, true);
    }

    // ---------- 히어로(사진/프레임) 사진 ----------
    // heroWidth: 'frame' = 프레임 모양 안에 (예전 그대로) / 'full' = 가로 100% (좌우 여백 없이)
    // 가로 100%일 때 heroRatio: auto(원본 비율) · 1/1 · 4/5 · 3/4 · 16/9 · screen(휴대폰 화면 꽉) · px(직접 heroHeightPx)
    const HERO_RATIOS = [['auto', '원본 비율'], ['1/1', '정사각'], ['4/5', '세로 4:5'], ['3/4', '세로 3:4'], ['16/9', '가로 16:9'], ['screen', '전체화면'], ['px', '높이조정']];
    // 사진 칸 모드 (메인 레이아웃): 화면 한 장(바탕색) 위 원하는 자리에 사진을 놓음 - x·y·w·h는 화면 기준 %, frame 모양, fade 아래쪽 흐려짐 %
    const HERO_BOX_FRAMES = [['none', '사각형'], ['rounded', '둥근 모서리'], ['arch', '아치'], ['polaroid', '폴라로이드'], ['shadow', '그림자'], ['comic', '웹툰 컷'], ['baroque', '금테 액자']];
    function heroBoxGeom(b) { // 사진 칸(heroBox) 자리·모양 - 사진 히어로와 유튜브 히어로가 같이 씀
        const n = (v, d, mn, mx) => Math.max(mn, Math.min(mx, Number.isFinite(+v) ? +v : d));
        const fr = HERO_BOX_FRAMES.some(x => x[0] === b.frame) ? b.frame : 'none', fade = n(b.fade, 0, 0, 90);
        const mask = fade ? `-webkit-mask-image:linear-gradient(#000 ${100 - fade}%, transparent);mask-image:linear-gradient(#000 ${100 - fade}%, transparent);` : '';
        return { fr, bg: /^#[0-9A-Fa-f]{6}$/.test(b.bg || '') ? b.bg : '', style: `left:${n(b.x, 0, -50, 100)}%;top:${n(b.y, 0, -50, 100)}%;width:${n(b.w, 100, 5, 200)}%;height:${n(b.h, 60, 5, 200)}%;${mask}` };
    }
    // 유튜브 히어로 + 사진 칸 레이아웃: 영상을 칸 안에 (전체화면일 때만). 칸이 없으면 영상 그대로
    function heroVideoBox(f, iframeHtml) {
        if (!f || f.heightMode !== 'full' || !f.heroBox || typeof f.heroBox !== 'object') return { cls: '', style: '', html: iframeHtml };
        const g = heroBoxGeom(f.heroBox);
        // '글자 잘 보이게' 그라데이션은 영상 칸 안에만 (칸 밖 바탕까지 어두워지지 않게 - 바깥 것은 CSS로 숨김)
        return { cls: ' hero-boxed', style: g.bg ? `background:${g.bg};` : '', html: `<div class="hero-box hb-fr-${g.fr}" style="${g.style}"><div class="hero-vbox">${iframeHtml}${heroTextOn(f, 'video') ? heroShadeHtml(f, 'video') : ''}</div></div>` };
    }
    function heroBoxHtml(f, src, pri) {
        const g = heroBoxGeom(f.heroBox), bg = g.bg ? ` style="background:${g.bg}"` : '', fr = g.fr;
        // '글자 잘 보이게' 그라데이션은 사진 위에만 (예전엔 첫 화면 전체 = 칸 밖 흰 바탕까지 어두워졌음)
        return `<div class="hero-photo-wrap hero-full-wrap hero-boxed"${bg}><div class="hero-box hb-fr-${fr}" style="${g.style}"><span class="hb-ph"><img${pri} class="hero-photo" src="${src}" alt="">${heroTextOn(f, 'hero') && f.heroShade && f.heroShade !== 'none' ? heroShadeHtml(f, 'hero') : ''}</span></div></div>`;
    }
    function heroPhotoHtml(f, opts) {
        if (!f || !f.heroImage) return '';
        const o = opts || {};
        const src = esc(o.src ? o.src(f.heroImage) : f.heroImage);
        const pri = o.priority ? ' fetchpriority="high"' : '';
        if (f.heroWidth === 'full' && f.heroRatio === 'screen' && f.heroBox && typeof f.heroBox === 'object') return heroBoxHtml(f, src, pri);
        if (f.heroWidth === 'full') {
            const r = HERO_RATIOS.some(x => x[0] === f.heroRatio) ? f.heroRatio : 'auto';
            const px = Math.max(160, Math.min(1000, Number(f.heroHeightPx) || 480));
            const style = r === 'px' ? `height:${(px / FONT_BASE * 100).toFixed(3)}cqw;`
                : (r === 'auto' || r === 'screen') ? '' : `aspect-ratio:${r};`;
            return `<div class="hero-photo-wrap hero-full-wrap"><img${pri} class="hero-photo hero-full ratio-${r.replace('/', '-')}" src="${src}" alt="" style="${style}">${heroTextOn(f, 'hero') ? heroShadeHtml(f, 'hero') : ''}</div>`;
        }
        // 프레임 안 사진도 "글자 잘 보이게" 그라데이션 - 사진과 같은 자리·같은 모양(프레임)으로 겹침
        const fr = esc(f.heroFrame || 'rounded');
        const sh = heroTextOn(f, 'hero') ? heroShadeHtml(f, 'hero').replace('class="ib-shade ', `class="ib-shade ib-shade-fr ib-fr-${fr} `) : '';
        return `<div class="hero-photo-wrap"><img${pri} class="hero-photo hero-frame-${fr}" src="${src}" alt="">${sh}</div>`;
    }

    // 손편지 등에서 쓰는 손글씨 폰트 - 공개페이지는 invite_view.php가 청첩장 대표 폰트만 불러오므로 여기서 직접 불러온다
    const FONT_CSS = {
        'nanum-pen':    { family: '"Nanum Pen Script", cursive', url: 'https://fonts.googleapis.com/css2?family=Nanum+Pen+Script&display=swap', label: '나눔손글씨 펜' },
        'nanum-brush':  { family: '"Nanum Brush Script", cursive', url: 'https://fonts.googleapis.com/css2?family=Nanum+Brush+Script&display=swap', label: '나눔손글씨 붓' },
        'gaegu':        { family: '"Gaegu", cursive', url: 'https://fonts.googleapis.com/css2?family=Gaegu:wght@400;700&display=swap', label: '개구체' },
        'hi-melody':    { family: '"Hi Melody", cursive', url: 'https://fonts.googleapis.com/css2?family=Hi+Melody&display=swap', label: '하이멜로디' },
        'gamja-flower': { family: '"Gamja Flower", cursive', url: 'https://fonts.googleapis.com/css2?family=Gamja+Flower&display=swap', label: '감자꽃' },
        'gowun-batang': { family: '"Gowun Batang", serif', url: 'https://fonts.googleapis.com/css2?family=Gowun+Batang&display=swap', label: '고운바탕' },
        'noto-serif-kr':  { family: '"Noto Serif KR", serif', url: 'https://fonts.googleapis.com/css2?family=Noto+Serif+KR:wght@400;700&display=swap', label: '노토 명조' },
        'nanum-myeongjo': { family: '"Nanum Myeongjo", serif', url: 'https://fonts.googleapis.com/css2?family=Nanum+Myeongjo:wght@400;700&display=swap', label: '나눔명조' },
        'gothic-a1':      { family: '"Gothic A1", sans-serif', url: 'https://fonts.googleapis.com/css2?family=Gothic+A1:wght@400;700&display=swap', label: '고딕 A1' },
        'song-myung':     { family: '"Song Myung", serif', url: 'https://fonts.googleapis.com/css2?family=Song+Myung&display=swap', label: '송명체' },
        'bagel-fat-one':  { family: '"Bagel Fat One", sans-serif', url: 'https://fonts.googleapis.com/css2?family=Bagel+Fat+One&display=swap', label: '배글 팻 원' },
        'black-han-sans': { family: '"Black Han Sans", sans-serif', url: 'https://fonts.googleapis.com/css2?family=Black+Han+Sans&display=swap', label: '검은고딕' },
        'bangers': { family: '"Bangers", sans-serif', url: 'https://fonts.googleapis.com/css2?family=Bangers&display=swap', label: 'Bangers' },
        'great-vibes': { family: '"Great Vibes", cursive', url: 'https://fonts.googleapis.com/css2?family=Great+Vibes&display=swap', label: 'Great Vibes' },
        'pinyon': { family: '"Pinyon Script", cursive', url: 'https://fonts.googleapis.com/css2?family=Pinyon+Script&display=swap', label: 'Pinyon Script' },
        'parisienne': { family: '"Parisienne", cursive', url: 'https://fonts.googleapis.com/css2?family=Parisienne&display=swap', label: 'Parisienne' },
        'alex-brush': { family: '"Alex Brush", cursive', url: 'https://fonts.googleapis.com/css2?family=Alex+Brush&display=swap', label: 'Alex Brush' },
        'cormorant': { family: '"Cormorant Garamond", serif', url: 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&display=swap', label: 'Cormorant' },
        'playfair': { family: '"Playfair Display", serif', url: 'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&display=swap', label: 'Playfair' }
    };
    function ensureFont(id) {
        const f = FONT_CSS[id];
        if (!f || typeof document === 'undefined') return '';
        if (!document.querySelector(`link[data-ib-font="${id}"]`)) {
            const link = document.createElement('link');
            link.rel = 'stylesheet'; link.href = f.url; link.dataset.ibFont = id;
            document.head.appendChild(link);
        }
        return f.family;
    }

    // =====================================================================
    // 글꾸미기(리치 텍스트) - 방명록 작성칸, 인트로 문구 등에 쓰는 도구 모음
    //   RichText.toolbar(편집칸, {emojiOnly})  → 굵게/밑줄/기울임/취소선/글자색/형광펜/정렬/글꼴/크기/이모티콘/서식 지우기
    //   RichText.sanitize(html) → 허용한 태그·스타일만 남긴 안전한 HTML (화면에 넣기 전에 반드시 통과)
    //   RichText.plain(html)    → 글자만 (글자 수 세기·미리보기용)
    //   RichText.type(el, html, 초) → 꾸민 글씨 그대로 타자 효과 (인트로)
    // 서버(방명록 저장)도 같은 규칙으로 한 번 더 걸러낸다 → guest_functions.php rt_sanitize()
    // =====================================================================
    const RT_FONT_IDS = ['', 'gowun-batang', 'noto-serif-kr', 'nanum-myeongjo', 'song-myung', 'gothic-a1', 'nanum-pen', 'nanum-brush', 'gaegu', 'hi-melody', 'gamja-flower'];
    const RT_SIZES = [['2', '작게'], ['3', '보통'], ['4', '조금 크게'], ['5', '크게'], ['6', '아주 크게']];
    const RT_EM = { 'x-small': .7, small: .85, medium: 1, large: 1.2, 'x-large': 1.5, 'xx-large': 2, 'xxx-large': 2.5 }; // 브라우저 기본 크기 이름 → 주변 글씨 대비 배수
    const RT_COLORS = ['#2B2320', '#6B5F55', '#9A9086', '#7A3B41', '#C0392B', '#E67E22', '#C9A227', '#27AE60', '#16A085', '#2E86AB', '#5B7FDE', '#8E44AD', '#E28FA0', '#FFFFFF'];
    const RT_MARKS = ['#FFF3A3', '#FFD6E0', '#D6F5E3', '#D6ECFF', '#EBDCFF', '#FFE4C4', 'transparent'];
    const RT_EMOJI = {
        '하트': '❤️ 🧡 💛 💚 💙 💜 🤍 🤎 💕 💞 💓 💗 💖 💘 💝 💌 ♥️ 😍 🥰 😘'.split(' '),
        '웨딩': '💍 💒 👰 🤵 👰‍♀️ 🤵‍♂️ 💐 🌹 🌷 🌸 🎀 🎁 🥂 🍾 🎂 🕊️ ⛪ 👑 💎 ✨'.split(' '),
        '축하': '🎉 🎊 🥳 👏 🙌 🙏 👍 👍🏻 💯 🔥 ⭐ 🌟 💫 🎈 🎶 🍀 ☀️ 🌈 🍻 🤗'.split(' '),
        '얼굴': '😀 😁 😂 🤣 😊 🙂 😉 😇 🥹 😭 🥲 😆 😎 🤩 😳 🤭 😋 😴 🤔 🫶'.split(' ')
    };
    const rtFontById = id => FONT_CSS[id];
    function rtFontIdFromFamily(fam) {
        const f = String(fam || '').toLowerCase();
        return Object.keys(FONT_CSS).find(id => f.includes(FONT_CSS[id].family.split(',')[0].replace(/"/g, '').toLowerCase())) || '';
    }
    function rtColorOk(v) { v = String(v || '').trim(); return /^#[0-9a-f]{3,8}$/i.test(v) || /^rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+\s*)?\)$/i.test(v) || v === 'transparent' ? v : ''; }
    function rtSizeOk(v) {
        v = String(v || '').trim().toLowerCase();
        if (RT_EM[v]) return RT_EM[v] + 'em';
        const m = v.match(/^(\d*\.?\d+)em$/); if (m) { const n = Math.max(.6, Math.min(3, +m[1])); return n + 'em'; }
        return '';
    }
    /** 허용: b i u s br div span / 스타일: 글자색·형광펜·크기(em)·글꼴(목록에 있는 것만)·정렬·굵게·기울임·밑줄·취소선 */
    function rtSanitize(html) {
        if (typeof document === 'undefined') return '';
        const tpl = document.createElement('template');
        tpl.innerHTML = String(html || '');
        const TAG = { B: 'b', STRONG: 'b', I: 'i', EM: 'i', U: 'u', S: 's', STRIKE: 's', DEL: 's', BR: 'br', DIV: 'div', P: 'div', SPAN: 'span', FONT: 'span' };
        const DROP = /^(SCRIPT|STYLE|TEMPLATE|IFRAME|OBJECT|EMBED|SVG|MATH|NOSCRIPT|TEXTAREA|SELECT|BUTTON|INPUT|IMG|VIDEO|AUDIO|LINK|META|TITLE|HEAD)$/;
        const out = document.createElement('div');
        let count = 0;
        (function walk(src, dst, depth) {
            for (const n of Array.from(src.childNodes)) {
                if (++count > 4000) return;
                if (n.nodeType === 3) { dst.appendChild(document.createTextNode(n.nodeValue)); continue; }
                if (n.nodeType !== 1 || DROP.test(n.tagName)) continue;
                const tag = TAG[n.tagName];
                if (!tag || depth > 12) { walk(n, dst, depth); continue; } // 모르는 태그는 껍데기만 벗기고 글자는 살림
                const el = document.createElement(tag);
                if (tag !== 'br') {
                    const st = n.style || {}, css = [];
                    const color = rtColorOk(st.color || (n.tagName === 'FONT' && n.getAttribute('color')));
                    if (color && color !== 'transparent') css.push('color:' + color);
                    const bg = rtColorOk(st.backgroundColor);
                    if (bg) css.push('background-color:' + bg);
                    let size = rtSizeOk(st.fontSize);
                    if (!size && n.tagName === 'FONT' && n.getAttribute('size')) size = rtSizeOk(['', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large'][+n.getAttribute('size')] || '');
                    if (size) css.push('font-size:' + size);
                    const fid = rtFontIdFromFamily(st.fontFamily || (n.tagName === 'FONT' && n.getAttribute('face')) || '');
                    if (fid) { css.push('font-family:' + FONT_CSS[fid].family); el.setAttribute('data-f', fid); }
                    if (/^(left|center|right)$/.test(st.textAlign || '')) css.push('text-align:' + st.textAlign);
                    if (/^(bold|[6-9]00)$/.test(st.fontWeight || '')) css.push('font-weight:bold');
                    if (st.fontStyle === 'italic') css.push('font-style:italic');
                    const deco = ((st.textDecorationLine || st.textDecoration || '').match(/underline|line-through/g) || []);
                    if (deco.length) css.push('text-decoration:' + Array.from(new Set(deco)).join(' '));
                    if (css.length) el.setAttribute('style', css.join(';'));
                    walk(n, el, depth + 1);
                    if (tag === 'span' && !css.length) { while (el.firstChild) dst.appendChild(el.firstChild); continue; } // 스타일 없는 span은 풀어버림
                }
                dst.appendChild(el);
            }
        })(tpl.content, out, 0);
        return out.innerHTML.replace(/(<br>)+$/, '');
    }
    function rtPlain(html) {
        if (typeof document === 'undefined') return '';
        const d = document.createElement('div');
        d.innerHTML = rtSanitize(html).replace(/<br>/g, '\n').replace(/<div[^>]*>/g, '\n');
        return d.textContent.replace(/^\n/, '').replace(/\n{3,}/g, '\n\n');
    }
    /** 꾸민 글씨에 쓰인 글꼴 파일 불러오기 */
    function rtLoadFonts(root) { (root || document).querySelectorAll('[data-f]').forEach(el => ensureFont(el.getAttribute('data-f'))); }
    /** 꾸민 글씨 그대로 한 글자씩 타자 효과 (인트로 문구)
     *  깜빡이는 커서(|)는 지금 치고 있는 글자 바로 뒤를 따라다닌다 - 줄이 바뀌거나 글꼴·크기·색이 바뀌어도 그 글씨에 맞춰짐.
     *  (원래 커서는 문구 칸 바깥에 있어서 줄바꿈이 있으면 엉뚱한 줄에 떠 있었다 → 복제해서 글자 사이로 옮기고 원래 것은 숨김) */
    function rtType(el, html, duration) {
        if (el._typewriterTimer) clearInterval(el._typewriterTimer);
        el.innerHTML = rtSanitize(html);
        rtLoadFonts(el);
        const nodes = []; const w = document.createTreeWalker(el, 4 /* 글자 노드 */);
        while (w.nextNode()) nodes.push(w.currentNode);
        const full = nodes.map(n => Array.from(n.nodeValue)); // 한글·이모티콘이 안 깨지게 글자 단위로
        nodes.forEach(n => { n.nodeValue = ''; });
        const host = el.parentElement;
        const orig = host && Array.from(host.children).find(c => c.classList.contains('intro-cursor'));
        let cur = null;
        if (orig) {
            host.classList.add('rt-typing');
            cur = orig.cloneNode(true); cur.classList.add('rt-cursor');
            const first = nodes[0];
            if (first) first.parentNode.insertBefore(cur, first.nextSibling); else el.appendChild(cur);
        }
        const total = full.reduce((a, c) => a + c.length, 0);
        const perChar = Math.max(35, (Number(duration) || 2.5) * 1000 * 0.5 / Math.max(1, total));
        let ni = 0, ci = 0;
        const timer = setInterval(() => {
            while (ni < nodes.length && ci >= full[ni].length) { ni++; ci = 0; }
            if (ni >= nodes.length || !el.contains(nodes[ni])) { clearInterval(timer); el._typewriterTimer = null; return; } // 다 쳤거나 문구가 새로 바뀜
            nodes[ni].nodeValue += full[ni][ci++];
            if (cur) nodes[ni].parentNode.insertBefore(cur, nodes[ni].nextSibling); // 커서를 방금 친 글자 바로 뒤로
        }, perChar);
        el._typewriterTimer = timer;
        return timer;
    }

    /** 편집칸(contenteditable) 위에 붙는 도구 모음. opts.emojiOnly = 이모티콘만 */
    function rtToolbar(edit, opts) {
        opts = opts || {};
        const bar = document.createElement('div');
        bar.className = 'rt-bar';
        const btn = (cmd, html, title, extra) => `<button type="button" class="rt-b ${extra || ''}" data-rt="${cmd}" title="${esc(title)}" aria-label="${esc(title)}">${html}</button>`;
        const dd = (key, html, title) => `<span class="rt-dd"><button type="button" class="rt-b rt-has-dd" data-rt-dd="${key}" title="${esc(title)}" aria-label="${esc(title)}">${html}<i class="rt-caret">▾</i></button><div class="rt-pop" data-rt-pop="${key}" hidden></div></span>`;
        let html = '';
        if (!opts.emojiOnly) {
            html += `<span class="rt-grp">${btn('bold', '<b>B</b>', '굵게 (Ctrl+B)')}${btn('underline', '<u>U</u>', '밑줄 (Ctrl+U)')}${btn('italic', '<i style="font-family:serif">I</i>', '기울임 (Ctrl+I)')}${btn('strikeThrough', '<s>S</s>', '취소선')}</span>`;
            html += `<span class="rt-grp">${dd('color', '<b class="rt-a">A</b><span class="rt-swatch" data-rt-cur="color" style="background:#C0392B"></span>', '글자색')}${dd('mark', '<span class="rt-mk">🖍</span><span class="rt-swatch" data-rt-cur="mark" style="background:#FFF3A3"></span>', '형광펜')}</span>`;
            html += `<span class="rt-grp">${dd('align', '<span class="rt-al">≡</span>', '정렬')}${dd('font', '<span class="rt-txt">글꼴</span>', '글꼴')}${dd('size', '<span class="rt-txt">크기</span>', '글자 크기')}</span>`;
        }
        html += `<span class="rt-grp">${dd('emoji', '<span class="rt-emo">😊</span>', '이모티콘')}${opts.emojiOnly ? '' : btn('removeFormat', '<span class="rt-txt">✕꾸밈</span>', '서식 지우기 (글자만 남기기)')}</span>`;
        bar.innerHTML = html;

        // 선택 영역 기억 - 도구 버튼을 눌러도 편집칸에서 고른 글자가 풀리지 않게
        let saved = null;
        const save = () => { const sel = document.getSelection(); if (sel.rangeCount && edit.contains(sel.anchorNode)) saved = sel.getRangeAt(0).cloneRange(); };
        const restore = () => {
            edit.focus();
            const sel = document.getSelection();
            if (saved) { sel.removeAllRanges(); sel.addRange(saved); }
            else { const r = document.createRange(); r.selectNodeContents(edit); r.collapse(false); sel.removeAllRanges(); sel.addRange(r); }
        };
        ['keyup', 'mouseup', 'input', 'focus'].forEach(ev => edit.addEventListener(ev, () => { save(); syncState(); }));
        document.addEventListener('selectionchange', () => { if (document.activeElement === edit) { save(); syncState(); } });
        const exec = (cmd, val) => {
            restore();
            try { document.execCommand('styleWithCSS', false, true); } catch (e) {}
            document.execCommand(cmd, false, val);
            save(); syncState();
            edit.dispatchEvent(new Event('input', { bubbles: true }));
        };
        function syncState() {
            bar.querySelectorAll('[data-rt]').forEach(b => {
                let on = false; try { on = /^(bold|italic|underline|strikeThrough)$/.test(b.dataset.rt) && document.queryCommandState(b.dataset.rt); } catch (e) {}
                b.classList.toggle('on', !!on);
            });
        }
        // 붙여넣기는 글자만 (다른 사이트 서식·이미지가 딸려 오지 않게)
        edit.addEventListener('paste', e => { e.preventDefault(); const t = (e.clipboardData || global.clipboardData).getData('text/plain'); document.execCommand('insertText', false, t); });
        edit.addEventListener('drop', e => e.preventDefault());

        const pops = {
            color: () => `<div class="rt-sw-grid">${RT_COLORS.map(c => `<button type="button" class="rt-sw" data-rt-set="foreColor" data-v="${c}" style="background:${c}" title="${c}"></button>`).join('')}</div>`,
            mark: () => `<div class="rt-sw-grid">${RT_MARKS.map(c => `<button type="button" class="rt-sw ${c === 'transparent' ? 'rt-sw-none' : ''}" data-rt-set="hiliteColor" data-v="${c}" style="background:${c === 'transparent' ? '#fff' : c}" title="${c === 'transparent' ? '형광펜 없애기' : c}"></button>`).join('')}</div>`,
            align: () => [['justifyLeft', '왼쪽 정렬', '⇤'], ['justifyCenter', '가운데 정렬', '↔'], ['justifyRight', '오른쪽 정렬', '⇥']].map(([c, l, i]) => `<button type="button" class="rt-item" data-rt-set="${c}">${i} ${l}</button>`).join(''),
            font: () => RT_FONT_IDS.map(id => { const f = rtFontById(id); if (f) ensureFont(id);
                return `<button type="button" class="rt-item" data-rt-set="fontName" data-v="${id}" style="${f ? 'font-family:' + esc(f.family) : ''}">${esc(f ? f.label : '기본 글꼴')}</button>`; }).join(''),
            size: () => RT_SIZES.map(([v, l]) => `<button type="button" class="rt-item" data-rt-set="fontSize" data-v="${v}" style="font-size:${RT_EM[['', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large'][+v]] * 14}px">${l}</button>`).join(''),
            emoji: () => `<div class="rt-emo-tabs">${Object.keys(RT_EMOJI).map((k, i) => `<button type="button" class="rt-emo-tab ${i ? '' : 'on'}" data-rt-tab="${esc(k)}">${esc(k)}</button>`).join('')}</div>
                <div class="rt-emo-grid">${RT_EMOJI[Object.keys(RT_EMOJI)[0]].map(e => `<button type="button" class="rt-e" data-rt-emoji="${e}">${e}</button>`).join('')}</div>`
        };
        const closeAll = except => bar.querySelectorAll('.rt-pop').forEach(p => { if (p !== except) p.hidden = true; });
        // 버튼을 눌러도 편집칸의 커서/선택이 사라지지 않게 mousedown 기본동작을 막는다
        bar.addEventListener('mousedown', e => { if (e.target.closest('button')) e.preventDefault(); });
        bar.addEventListener('click', e => {
            const b = e.target.closest('button'); if (!b) return;
            e.stopPropagation();
            if (b.dataset.rt) { closeAll(); exec(b.dataset.rt); return; }
            if (b.dataset.rtDd) {
                const pop = bar.querySelector(`[data-rt-pop="${b.dataset.rtDd}"]`);
                const open = pop.hidden; closeAll(); if (!open) return;
                pop.innerHTML = pops[b.dataset.rtDd](); pop.hidden = false;
                // 화면 밖으로 넘치면 왼쪽으로 당김
                pop.style.left = '0'; const r = pop.getBoundingClientRect();
                if (r.right > window.innerWidth - 8) pop.style.left = (window.innerWidth - 8 - r.right) + 'px';
                return;
            }
            if (b.dataset.rtTab) {
                const pop = b.closest('.rt-pop');
                pop.querySelectorAll('.rt-emo-tab').forEach(t => t.classList.toggle('on', t === b));
                pop.querySelector('.rt-emo-grid').innerHTML = RT_EMOJI[b.dataset.rtTab].map(x => `<button type="button" class="rt-e" data-rt-emoji="${x}">${x}</button>`).join('');
                return;
            }
            if (b.dataset.rtEmoji) { exec('insertText', b.dataset.rtEmoji); return; } // 이모티콘은 여러 개 연달아 넣을 수 있게 창을 안 닫음
            if (b.dataset.rtSet) {
                let v = b.dataset.v;
                if (b.dataset.rtSet === 'fontName') { const f = rtFontById(v); if (f) ensureFont(v); v = f ? f.family : 'inherit'; }
                if (b.dataset.rtSet === 'foreColor' || b.dataset.rtSet === 'hiliteColor') {
                    const cur = bar.querySelector(`[data-rt-cur="${b.dataset.rtSet === 'foreColor' ? 'color' : 'mark'}"]`); if (cur && v !== 'transparent') cur.style.background = v;
                }
                closeAll(); exec(b.dataset.rtSet, v);
            }
        });
        document.addEventListener('click', e => { if (!bar.contains(e.target)) closeAll(); });
        return bar;
    }
    /** 편집칸 + 도구 모음 한 벌 만들기. 반환: { wrap, edit, getHtml(), getPlain(), setHtml(h) } */
    function rtEditor(opts) {
        opts = opts || {};
        const wrap = document.createElement('div');
        wrap.className = 'rt-wrap' + (opts.className ? ' ' + opts.className : '');
        const edit = document.createElement('div');
        edit.className = 'rt-edit';
        edit.contentEditable = 'true';
        edit.setAttribute('role', 'textbox'); edit.setAttribute('aria-multiline', 'true');
        if (opts.placeholder) edit.dataset.placeholder = opts.placeholder;
        if (opts.minHeight) edit.style.minHeight = opts.minHeight;
        const bar = rtToolbar(edit, opts);
        wrap.appendChild(bar); wrap.appendChild(edit);
        const syncEmpty = () => edit.classList.toggle('is-empty', !edit.textContent.trim());
        // 흰색처럼 아주 밝은 글자색은 하얀 편집칸에서 안 보이므로 편집칸 안에서만 테두리 그림자를 씌운다
        // (rt-lt 클래스는 저장할 때 rtSanitize가 지워서 실제 청첩장에는 안 나감)
        const markLight = () => {
            edit.querySelectorAll('[style]').forEach(el => {
                const m = String(el.style.color || '').match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
                let light = false;
                if (m) light = (0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3]) / 255 > 0.82;
                el.classList.toggle('rt-lt', light);
                if (!el.classList.length) el.removeAttribute('class');
            });
        };
        const api = {
            wrap, edit, bar,
            getHtml: () => rtSanitize(edit.innerHTML),
            getPlain: () => (edit.innerText || '').replace(/\n$/, ''),
            setHtml: h => { edit.innerHTML = rtSanitize(h); rtLoadFonts(edit); syncEmpty(); markLight(); },
            setPlain: t => { edit.textContent = t || ''; syncEmpty(); }
        };
        edit.addEventListener('input', () => { syncEmpty(); markLight(); }); syncEmpty();
        return api;
    }
    /** 꾸밈 없는 글자 타자 효과로 돌아갈 때 - 원래 커서를 다시 보이게 */
    function rtTypeReset(el) { const host = el && el.parentElement; if (host) host.classList.remove('rt-typing'); }
    // ---- 인트로 글자 효과 (타자 말고 7가지) - 에디터 인트로 미리보기와 공개 페이지가 같이 씀 ----
    //  글자마다 <span class="ibx-ch" style="--i:글자 번호;--l:줄 번호">로 나누고(낱말은 .ibx-w로 묶어 줄바꿈 유지) invite-blocks.css .ibx-a-* 애니메이션
    const INTRO_ANIMS = [['type', '타자 치듯'], ['fade', '천천히 나타나기'], ['rise', '한 글자씩 떠오르기'], ['blur', '흐릿하게 선명해지기'], ['zoom', '크게서 작게'], ['pop', '톡톡 튀기'], ['line', '한 줄씩 올라오기'], ['draw', '손글씨처럼 그려지기']];
    function introAnimReset(el) { const host = el && el.parentElement; if (!host) return; [...host.classList].filter(c => /^ibx-a-/.test(c)).forEach(c => host.classList.remove(c)); }
    function introAnim(el, html, anim, duration) {
        if (!el) return;
        if (el._typewriterTimer) { clearInterval(el._typewriterTimer); el._typewriterTimer = null; }
        rtTypeReset(el); introAnimReset(el);
        const host = el.parentElement;
        el.innerHTML = rtSanitize(html || '');
        rtLoadFonts(el);
        let i = 0, l = 0, started = false;
        const split = node => {
            [...node.childNodes].forEach(ch => {
                if (ch.nodeType === 3) {
                    const frag = document.createDocumentFragment(); let word = null;
                    Array.from(ch.nodeValue).forEach(c => {
                        if (c === '\n') { word = null; l++; frag.appendChild(document.createTextNode('\n')); return; }
                        if (/\s/.test(c)) { word = null; frag.appendChild(document.createTextNode(c)); return; }
                        if (!word) { word = document.createElement('span'); word.className = 'ibx-w'; frag.appendChild(word); }
                        const sp = document.createElement('span'); sp.className = 'ibx-ch'; sp.textContent = c; sp.style.setProperty('--i', i++); sp.style.setProperty('--l', l);
                        word.appendChild(sp); started = true;
                    });
                    ch.replaceWith(frag);
                } else if (ch.nodeType === 1) {
                    if (ch.tagName === 'BR') { l++; return; }
                    if (/^(DIV|P)$/.test(ch.tagName) && started) l++;
                    split(ch);
                }
            });
        };
        split(el);
        const total = (Number(duration) || 2.5) * 1000 * 0.6; // 재생 길이의 60% 동안 나타나고, 나머지는 다 나온 채로
        if (anim === 'draw' && document.createElementNS) { // 손글씨처럼: 글자마다 윤곽선을 한 획씩 따라 그린 뒤(SVG 글자 선) 속을 채움
            const NS = 'http://www.w3.org/2000/svg';
            // 영문 낱말은 통째로 한 칸 (필기체는 글자끼리 이어져야 해서 - 쪼개면 이음이 끊기고 모양이 달라짐) → 왼쪽부터 쓸듯이 드러남
            el.querySelectorAll('.ibx-w').forEach(w => {
                const chs = [...w.querySelectorAll(':scope > .ibx-ch')]; if (chs.length < 2) return;
                const txt = chs.map(c => c.textContent).join('');
                if (!/^[A-Za-z0-9'’&.,!?\-]+$/.test(txt)) return;
                const u = chs[0]; u.textContent = txt; u.classList.add('ibx-word'); chs.slice(1).forEach(c => c.remove());
            });
            const units = [...el.querySelectorAll('.ibx-ch')];
            units.forEach(sp => {
                const c = sp.textContent; sp.textContent = ''; sp.style.position = 'relative'; sp.style.display = 'inline-block';
                const fill = document.createElement('span'); fill.className = 'ibx-fill'; fill.textContent = c;
                const svg = document.createElementNS(NS, 'svg'); svg.setAttribute('class', 'ibx-stroke'); svg.setAttribute('aria-hidden', 'true');
                const t = document.createElementNS(NS, 'text'); t.setAttribute('x', '0'); t.textContent = c;
                svg.appendChild(t); sp.append(fill, svg);
            });
            // 글자 기준선 높이를 재서 SVG 글자를 HTML 글자에 딱 겹침 - 글꼴이 늦게 받아지면(필기체 등) 받아진 뒤 다시 잼
            const place = () => units.forEach(sp => {
                if (!sp.isConnected) return;
                const t = sp.querySelector('.ibx-stroke text'); if (!t) return;
                const bl = document.createElement('i'); bl.style.cssText = 'display:inline-block;width:0;height:0;vertical-align:baseline;';
                sp.insertBefore(bl, sp.firstChild); const y = bl.offsetTop; bl.remove();
                const fs = parseFloat(getComputedStyle(sp).fontSize) || 20, len = (sp.textContent || '').length || 1;
                t.setAttribute('y', String(y));
                sp.style.setProperty('--L', String(Math.round(fs * 14 * Math.max(1, len * 0.6)))); // 윤곽선 길이(넉넉히) - 이만큼을 점선 한 칸으로 두고 밀어서 그려지게
                sp.style.setProperty('--sw', Math.max(0.8, fs * 0.035).toFixed(2) + 'px');
            });
            place();
            if (document.fonts) {
                if (document.fonts.ready) document.fonts.ready.then(place);
                const onDone = () => place(); document.fonts.addEventListener && document.fonts.addEventListener('loadingdone', onDone);
                setTimeout(() => { document.fonts.removeEventListener && document.fonts.removeEventListener('loadingdone', onDone); }, 8000);
            }
        }
        if (anim === 'draw' && host) { // 재생 길이의 85% 동안: 글자마다 획이 천천히(0.6~1.8초) 그려지고, 앞 글자부터 차례로 이어짐
            const T = (Number(duration) || 2.5) * 1000 * 0.85, D = Math.max(600, Math.min(1800, T * 0.45));
            host.style.setProperty('--ibx-draw', Math.round(D) + 'ms');
            host.style.setProperty('--ibx-dstep', Math.round(Math.max(0, T - D - 450) / Math.max(1, i - 1)) + 'ms');
        }
        if (host) {
            host.style.setProperty('--ibx-total', Math.round(total) + 'ms');
            host.style.setProperty('--ibx-step', Math.round(anim === 'line' ? total * 0.7 / Math.max(1, l + 1) : total * 0.7 / Math.max(1, i)) + 'ms');
            void host.offsetWidth; // (같은 효과를 다시 틀 때 처음부터)
            host.classList.add('ibx-a-' + (INTRO_ANIMS.some(a => a[0] === anim) ? anim : 'fade'));
        }
    }
    // =====================================================================
    // 스크롤바 모양 (화면 설정 → 부가기능 "스크롤바 모양") - PC에서 청첩장 오른쪽에 보이는 스크롤바
    // 휴대폰은 스크롤할 때만 잠깐 뜨는 얇은 막대라 대부분 기기 기본 모양으로 보인다.
    // 모양은 invite-blocks.css의 .sb-이름 클래스, 색은 청첩장 색(포인트색·선색 등)을 --sb-* 변수로 복사해서 쓴다.
    // =====================================================================
    const SCROLLBARS = [
        ['default', '기본'], ['thin', '얇은 선'], ['slim', '먹색 슬림'], ['soft', '소프트'],
        ['accent', '포인트 캡슐'], ['gradient', '그라데이션'], ['outline', '라인 캡슐'], ['dot', '도트 트랙'], ['ghost', '투명 페이드'], ['mist', '그레이 페이드'], ['hidden', '숨김']
    ];
    const SB_VARS = [['--p-accent', '--sb-accent'], ['--p-line', '--sb-line'], ['--p-muted', '--sb-muted'], ['--p-bg', '--sb-bg'], ['--p-ink', '--sb-ink']];
    // opts.color: 스크롤바 색(#RRGGBB, 없으면 청첩장 포인트색·글자색) / opts.opacity: 진하기 10~100 (에디터 화면 설정 > 스크롤)
    function applyScrollbar(target, name, varsFrom, opts) {
        if (!target || typeof document === 'undefined') return;
        SCROLLBARS.forEach(([n]) => target.classList.remove('sb-' + n));
        ['--sb-mist'].forEach(v => target.style.removeProperty(v));
        target.style.removeProperty('scrollbar-color');
        if (!name || name === 'default' || !SCROLLBARS.some(([n]) => n === name)) {
            ghostBarOff(target);
            // 기본 모양이어도 색을 고르면 브라우저 기본 스크롤바에 색만 입힘 (scrollbar-color)
            const c0 = opts && /^#[0-9a-f]{6}$/i.test(opts.color || '') ? opts.color : '';
            const o0 = opts && opts.opacity != null && opts.opacity !== '' ? Math.max(10, Math.min(100, Number(opts.opacity) || 100)) : 100;
            if (c0) target.style.setProperty('scrollbar-color', `${o0 < 100 ? `color-mix(in srgb, ${c0} ${o0}%, transparent)` : c0} transparent`);
            return;
        }
        target.classList.add('sb-' + name);
        if (varsFrom) {
            const cs = getComputedStyle(varsFrom);
            SB_VARS.forEach(([from, to]) => {
                const v = cs.getPropertyValue(from).trim(); if (v) target.style.setProperty(to, v);
            });
        }
        opts = opts || {};
        const col = /^#[0-9a-f]{6}$/i.test(opts.color || '') ? opts.color : '';
        const op = opts.opacity != null && opts.opacity !== '' ? Math.max(10, Math.min(100, Number(opts.opacity) || 100)) : 100;
        if (col || op < 100) {
            const cs2 = getComputedStyle(target);
            ['--sb-accent', '--sb-muted', '--sb-ink'].forEach(v => {
                const base = col || cs2.getPropertyValue(v).trim(); if (!base) return;
                target.style.setProperty(v, op < 100 ? `color-mix(in srgb, ${base} ${op}%, transparent)` : base);
            });
            target.style.setProperty('--sb-mist', `color-mix(in srgb, ${col || '#808080'} ${Math.round(op * .32)}%, transparent)`);
        }
        if (name === 'ghost' || name === 'mist') ghostBarOn(target, name); else ghostBarOff(target);
    }

    // ---- "투명 페이드"(ghost, 속이 빈 라인) / "그레이 페이드"(mist, 연한 회색 반투명으로 꽉 찬 막대) 스크롤바 ----
    // 기본 스크롤바는 숨기고, 스크롤하는 동안에만 속이 빈 투명 박스(.ib-ghost-bar)가 오른쪽에 스르르 나타나
    // 지금 위치를 따라 움직이다가, 멈추면 1초 뒤 다시 사라진다. 마우스로 끌어서 옮길 수도 있다.
    // 브라우저 스크롤바 모양 설정(::-webkit-scrollbar)은 휴대폰에서 거의 무시되지만, 이건 직접 그리는 막대라 휴대폰에서도 똑같이 보인다.
    const ghostBars = new Map();
    function ghostBarOff(target) {
        const g = ghostBars.get(target);
        if (g) { g.destroy(); ghostBars.delete(target); }
    }
    function ghostBarOn(target, variant) {
        const old = ghostBars.get(target);
        if (old) { old.setVariant(variant); old.syncVars(); old.update(); return; }
        const isRoot = target === document.documentElement || target === document.body;
        const scroller = isRoot ? (document.scrollingElement || document.documentElement) : target;
        const evTarget = isRoot ? window : target;
        const bar = document.createElement('div');
        bar.className = 'ib-ghost-bar' + (variant === 'mist' ? ' mist' : '');
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
        const PAD = 6, MIN_H = 36;
        let hideT = 0, raf = 0, drag = null;
        const view = () => {
            if (isRoot) return { top: 0, right: document.documentElement.clientWidth || window.innerWidth, height: window.innerHeight };
            const r = target.getBoundingClientRect();
            return { top: r.top, right: r.right, height: r.height };
        };
        function geom() {
            const v = view(), ch = isRoot ? window.innerHeight : scroller.clientHeight, sh = scroller.scrollHeight;
            const track = Math.max(0, v.height - PAD * 2);
            const h = Math.max(MIN_H, Math.min(track, track * ch / Math.max(1, sh)));
            const max = Math.max(1, sh - ch);
            return { v, ch, sh, track, h, max, scrollable: sh > ch + 2 && v.height > 40 };
        }
        function update() {
            raf = 0;
            const g = geom();
            if (!g.scrollable || !document.body.contains(bar) || (!isRoot && !document.contains(target))) { bar.style.display = 'none'; return; }
            bar.style.display = '';
            const top = g.v.top + PAD + (g.track - g.h) * Math.min(1, Math.max(0, scroller.scrollTop / g.max));
            bar.style.height = g.h + 'px';
            bar.style.transform = `translate3d(${Math.round(g.v.right - 13)}px, ${top.toFixed(1)}px, 0)`;
        }
        const schedule = () => { if (!raf) raf = requestAnimationFrame(update); };
        function show() {
            schedule();
            bar.classList.add('on');
            clearTimeout(hideT);
            if (!drag) hideT = setTimeout(() => { if (!bar.matches(':hover')) bar.classList.remove('on'); }, 1000);
        }
        // 오른쪽 가장자리에 마우스를 가져가도 나타난다 (PC)
        const onMove = e => {
            if (e.pointerType && e.pointerType !== 'mouse') return;
            if (!isRoot && e.target !== bar && !target.contains(e.target)) return; // 에디터: 미리보기 칸 위에서만
            const v = view();
            if (e.clientX > v.right - 26 && e.clientX <= v.right && e.clientY >= v.top && e.clientY <= v.top + v.height) show();
        };
        const onDown = e => {
            const g = geom();
            drag = { y: e.clientY, top: scroller.scrollTop, ratio: g.max / Math.max(1, g.track - g.h) };
            bar.classList.add('drag', 'on'); clearTimeout(hideT);
            try { bar.setPointerCapture(e.pointerId); } catch (err) {}
            e.preventDefault();
        };
        const onDrag = e => { if (!drag) return; scroller.scrollTop = drag.top + (e.clientY - drag.y) * drag.ratio; };
        const onUp = () => { if (!drag) return; drag = null; bar.classList.remove('drag'); show(); };
        const onLeave = () => { if (!drag) { clearTimeout(hideT); hideT = setTimeout(() => bar.classList.remove('on'), 600); } };
        function syncVars() {
            const cs = getComputedStyle(target);
            SB_VARS.concat([[0, '--sb-mist']]).forEach(([, v]) => { const x = cs.getPropertyValue(v).trim(); if (x) bar.style.setProperty(v, x); else bar.style.removeProperty(v); });
        }
        evTarget.addEventListener('scroll', show, { passive: true });
        window.addEventListener('resize', schedule);
        document.addEventListener('pointermove', onMove, { passive: true });
        bar.addEventListener('pointerdown', onDown);
        bar.addEventListener('pointermove', onDrag);
        bar.addEventListener('pointerup', onUp);
        bar.addEventListener('pointercancel', onUp);
        bar.addEventListener('pointerleave', onLeave);
        syncVars(); update();
        ghostBars.set(target, {
            update, syncVars,
            setVariant(v) { bar.classList.toggle('mist', v === 'mist'); },
            destroy() {
                evTarget.removeEventListener('scroll', show);
                window.removeEventListener('resize', schedule);
                document.removeEventListener('pointermove', onMove);
                clearTimeout(hideT); if (raf) cancelAnimationFrame(raf);
                bar.remove();
            }
        });
    }

    const RichText = { animate: introAnim, animReset: introAnimReset, ANIMS: INTRO_ANIMS, resetCursor: rtTypeReset, sanitize: rtSanitize, plain: rtPlain, loadFonts: rtLoadFonts, type: rtType, toolbar: rtToolbar, editor: rtEditor, EMOJI: RT_EMOJI };

    const PAPERS = { cotton: '코튼', white: '화이트', ivory: '줄노트', kraft: '크라프트', grid: '모눈', linen: '린넨', dot: '도트' };

    // 연락하기 디자인 8가지 (에디터 편집창 "디자인" - 모양은 invite-blocks.css .ib-cs-*)
    // (카드·글자 버튼·타일·측 머리말·진한 상자는 뺐음 - 예전에 고른 청첩장은 '기본'으로 보임)
    const CONTACT_STYLES = [['basic', '기본'], ['line', '미니멀'], ['soft', '파스텔'], ['vintage', '빈티지 큐피드'], ['outline', '라인 상자'], ['center', '가운데 정렬'], ['capsule', '캡슐 버튼']];
    // 전화·문자 아이콘 (가는 선 그림 - 예전 ☎ ✉ 글자보다 깔끔하게, 모든 디자인 공통)
    const CT_IC = {
        tel: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5.5 3.5h3l1.6 4.4-2.1 1.5a11.5 11.5 0 0 0 6.6 6.6l1.5-2.1 4.4 1.6v3a2 2 0 0 1-2.2 2A17 17 0 0 1 3.5 5.7a2 2 0 0 1 2-2.2z"/></svg>',
        sms: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2.2"/><path d="M3.6 6.6L12 12.8l8.4-6.2"/></svg>'
    };

    // ---------- 엔딩 크레딧 (스탭롤): 화면 가득 사진 위, 정해진 칸 안에서만 글자가 아주 천천히 올라감 (끝나면 처음부터 다시) ----------
    //  줄 내용의 {신랑} {신부} {날짜} {시간} {예식장}은 지금 청첩장 값으로 바꿔서 보여줌 (setDesign으로 받은 디자인)
    const CREDIT_ROWS_DEFAULT = [
        { id: 'cr1', label: 'Date', text: '{날짜}' }, { id: 'cr2', label: 'Location', text: '{예식장}' }, { id: 'cr3', label: 'Time', text: '{시간}' },
        { id: 'cr4', label: 'Produced by', text: 'our family' }, { id: 'cr5', label: 'Story begins', text: 'since 2022' },
        { id: 'cr6', label: 'Directed by', text: '{신랑}, {신부}' }, { id: 'cr7', label: 'Special Thanks to', text: 'Everyone for Your Love, Blessings' },
        { id: 'cr8', label: 'Forever with', text: 'Love & Happiness' }
    ];
    // {예식장}: 간편 만들기에서 고객이 아직 예식장을 안 적었으면(디자인 예시 이름 그대로 = design.venueDemo) 비워 둠
    //  - "나 라움아트센터 아닌데?" 하지 않게. 예식장 질문을 하는 동안만 에디터가 옅은 '예식장 이름' 자리 글자를 보여줌(__ibVenueHint)
    function venueOf(loc) {
        const v = loc.venue || '', demo = CUR_DESIGN && CUR_DESIGN.venueDemo;
        if (demo && v === demo) return (typeof window !== 'undefined' && window.__ibVenueHint) ? '예식장 이름' : '';
        return v;
    }
    // 오시는 길: 예식장 이름·주소가 디자인 예시 그대로인지 (간편 만들기로 시작했을 때 design.venueDemo·venueDemoAddr)
    function isVenueDemo(f) { const d = CUR_DESIGN || {}; f = f || {}; return { v: !!(d.venueDemo && (f.venue || '') === d.venueDemo), a: !!(d.venueDemoAddr && (f.address || '') === d.venueDemoAddr) }; }
    function creditVars() {
        const bl = (CUR_DESIGN && CUR_DESIGN.blocks) || [], fd = id => ((bl.find(b => b.id === id && b.enabled !== false) || bl.find(b => b.id === id) || {}).fields) || {};
        const h = Object.assign({}, fd('heroVideo'), fd('hero')), d = fd('dday'), loc = fd('location');
        const hr = Number(d.hour), mi = Number(d.minute) || 0;
        const time = d.hour != null && d.hour !== '' ? `${((hr + 11) % 12) + 1}:${String(mi).padStart(2, '0')} ${hr < 12 ? 'AM' : 'PM'}` : '';
        return { '신랑': h.groomName || '', '신부': h.brideName || '', '날짜': d.year ? `${d.year}.${d.month}.${d.day}` : '', '시간': time, '예식장': venueOf(loc) };
    }
    // ---------- 메인 화면 문구 칸 (메인 레이아웃) ----------
    //  메인 사진·영상 위에 이름·날짜 말고도 문구 칸을 더 얹음. 칸 이름(키)은 f.heroLayers, 글은 f[키], 자리·글꼴·색은 f.layout[키]
    //  (이름·날짜 칸과 같은 방식이라 끌기·글꼴·색·크기 조절이 그대로 됨). 글에 {신랑} {신부} {날짜} {날짜:영문} {날짜:점} {요일:영문} {시간} {예식장}
    const EN_MON = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const EN_DAY = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    function heroVars(f) {
        const v = creditVars(); f = f || {};
        const bl = (CUR_DESIGN && CUR_DESIGN.blocks) || [], d = ((bl.find(b => b.id === 'dday') || {}).fields) || {};
        const dt = d.year && !(CUR_DESIGN && CUR_DESIGN.dateTbd) ? new Date(+d.year, +d.month - 1, +d.day) : null; // 예식 일정 미정이면 날짜 글자 칸은 비움
        const ord = n => n + (n % 10 === 1 && n !== 11 ? 'st' : n % 10 === 2 && n !== 12 ? 'nd' : n % 10 === 3 && n !== 13 ? 'rd' : 'th');
        return Object.assign(v, { '신랑': f.groomName || v['신랑'], '신부': f.brideName || v['신부'], '날짜': f.datetime || v['날짜'],
            '날짜:영문': dt ? `${EN_DAY[dt.getDay()].slice(0, 3)}, ${EN_MON[dt.getMonth()].slice(0, 3)} ${ord(dt.getDate())}, ${dt.getFullYear()}` : '',
            '날짜:점': dt ? `${dt.getFullYear()}.${String(dt.getMonth() + 1).padStart(2, '0')}.${String(dt.getDate()).padStart(2, '0')}` : '',
            '요일:영문': dt ? EN_DAY[dt.getDay()].toUpperCase() : '', '요일:영문짧게': dt ? EN_DAY[dt.getDay()].slice(0, 3).toUpperCase() : '',
            '년:2': dt ? String(dt.getFullYear()).slice(-2) : '', '월': dt ? String(dt.getMonth() + 1) : '', '일': dt ? String(dt.getDate()) : '' });
    }
    function heroFill(text, f) { const v = heroVars(f); return String(text || '').replace(/\{(신랑|신부|날짜:영문|날짜:점|날짜|요일:영문짧게|요일:영문|년:2|월|일|시간|예식장)\}/g, (m, k) => v[k] || ''); }
    // 문구 칸 모양: vertical(세로쓰기 'up'=글자 바로 / 'side'=눕혀서) · arc(곡선 -100~100, 폭 arcW%) · anim(등장 효과 write 써지듯 / fade / up / zoom, animDur·animDelay 초)
    const HL_ANIMS = [['', '없음'], ['write', '써지듯이'], ['fade', '천천히 나타나기'], ['up', '아래에서 위로'], ['zoom', '커지며 나타나기']];
    let hlArcN = 0;
    function heroArcSvg(text, L) { // 곡선 글자 (SVG 글자 길): 폭 arcW%를 1000칸으로 보고 글자 크기를 그만큼 키움
        const W = 1000, wpx = Math.max(10, Math.min(100, Number(L.arcW) || 70)) / 100 * FONT_BASE, k = W / wpx, fs = (Number(L.fontSize) || 16) * k;
        const bend = Math.max(-100, Math.min(100, Number(L.arc) || 0)), c = W - fs * 0.6, h = Math.max(1, Math.abs(bend) / 200 * c), r = (c * c / 4 + h * h) / (2 * h);
        const up = bend >= 0, H = h + fs * 1.5, y0 = up ? H - fs * 0.35 : fs * 1.1, x0 = (W - c) / 2, id = 'hlarc' + (++hlArcN);
        // 곡선 길이보다 글이 길면 양끝이 잘림 → 글자 크기를 곡선 길이에 맞게 줄임 (여기선 어림값, 화면에 그려진 뒤 fitHeroArcs가 실제 폭으로 다시 맞춤)
        const line = String(text).replace(/\n+/g, ' '), arcLen = 2 * r * Math.asin(Math.min(1, c / 2 / r)), fs0 = Math.min(fs, arcLen * 0.94 / Math.max(1, line.length * 0.5));
        return `<svg class="hl-arc" viewBox="0 0 ${W} ${H.toFixed(0)}" width="100%" role="img" aria-label="${esc(text)}"><path id="${id}" d="M${x0.toFixed(1)},${y0.toFixed(1)} A${r.toFixed(1)},${r.toFixed(1)} 0 0 ${up ? 1 : 0} ${(x0 + c).toFixed(1)},${y0.toFixed(1)}" fill="none"/><text font-size="${fs0.toFixed(1)}" data-fs="${fs.toFixed(1)}" text-anchor="middle" fill="currentColor"><textPath href="#${id}" startOffset="50%">${esc(line)}</textPath></text></svg>`;
    }
    // 곡선 글씨 크기 맞추기: 정한 크기로 재 보고, 곡선보다 길면 그만큼 줄임 (글꼴이 늦게 받아져도 다시 맞춤)
    function fitHeroArcs(root) {
        if (typeof document === 'undefined') return;
        (root || document).querySelectorAll('svg.hl-arc').forEach(svg => {
            const t = svg.querySelector('text'), p = svg.querySelector('path'); if (!t || !p || !svg.isConnected) return;
            const want = parseFloat(t.dataset.fs) || parseFloat(t.getAttribute('font-size')); if (!want) return;
            let len, pl; try { t.setAttribute('font-size', want); len = t.getComputedTextLength(); pl = p.getTotalLength(); } catch (e) { return; }
            if (!len || !pl) { t.setAttribute('font-size', t.dataset.fs0 || want); return; } // 숨겨진 칸은 못 잼
            const fit = len > pl * 0.96 ? want * pl * 0.96 / len : want;
            t.dataset.fs0 = fit.toFixed(1); t.setAttribute('font-size', fit.toFixed(1));
        });
    }
    // 자유 배치 글자 칸(메인 화면 이름·날짜·문구, 섹션 제목 등): 칸 폭이 정해져 있어서 "김민준 & 박새 / 로이"처럼
    //  낱말 가운데서 줄이 바뀌던 것 → 한 줄(또는 직접 줄을 바꾼 그대로)로 들어가면 칸을 글자만큼 넓혀서 한 줄로,
    //  화면 폭(92%)을 넘으면 글자를 조금(72%까지) 줄여서 한 줄로, 그래도 길면 원래대로 줄바꿈 (낱말 단위 - CSS keep-all)
    //  넓힐 때는 왼쪽·오른쪽 정렬 칸이면 그쪽 끝을 그대로 두고, 화면 밖으로 나가면 안쪽(3%)으로 들임
    const FIT_SEL = '.drag-part, .ib-title-layer';
    const fitSeen = typeof WeakSet !== 'undefined' ? new WeakSet() : null;
    let fitRO = null, fitRoRaf = 0; const fitQ = new Set();
    if (typeof ResizeObserver !== 'undefined' && fitSeen) fitRO = new ResizeObserver(es => {
        es.forEach(e => { if (e.contentRect.width) fitQ.add(e.target); });
        if (fitRoRaf || !fitQ.size) return;
        fitRoRaf = requestAnimationFrame(() => { fitRoRaf = 0; const q = [...fitQ]; fitQ.clear();
            q.forEach(t => { if (t.isConnected) t.querySelectorAll(':scope > .drag-part, :scope > .ib-title-layer').forEach(el => { try { fitOne(el); } catch (er) {} }); }); });
    });
    function fitOne(el) {
        if (!el.isConnected || el.matches('.acc-row, .hl-vert, .hl-curve, [contenteditable="true"]') || el.querySelector('svg, img, input, textarea, button')) return;
        if (fitRO && el.parentElement && !fitSeen.has(el.parentElement)) { fitSeen.add(el.parentElement); fitRO.observe(el.parentElement); } // 숨어 있다가 보이거나 폭이 바뀌면 다시 맞춤
        const cb = el.offsetParent || el.parentElement; if (!cb) return;
        const W = cb.clientWidth; if (!W) return;
        const st = el.style, d = el.dataset;
        if (d.fit) { st.whiteSpace = d.fitWs; st.width = d.fitW; st.minWidth = ''; st.marginLeft = ''; st.fontSize = d.fitFs; } // 다시 맞출 땐 원래 값부터
        else { d.fit = '1'; d.fitWs = st.whiteSpace; d.fitW = st.width; d.fitFs = st.fontSize; }
        const box0 = el.offsetWidth, h0 = el.offsetHeight;
        const lines = /\n/.test((el.innerText || el.textContent || '').trim()) || !!el.querySelector('br');
        st.whiteSpace = lines ? 'pre' : 'nowrap'; st.width = 'max-content';
        let nw = el.offsetWidth;
        const lim = W * 0.92;
        const restore = () => { st.whiteSpace = d.fitWs; st.width = d.fitW; st.fontSize = d.fitFs; };
        if (nw <= box0 + 1 && el.offsetHeight >= h0 - 1) { restore(); return; } // 원래도 줄이 안 바뀜
        if (nw > lim) {
            const r = lim / nw;
            if (r < 0.72) { restore(); return; } // 긴 문장은 원래대로 (낱말 단위 줄바꿈)
            st.fontSize = (parseFloat(getComputedStyle(el).fontSize) * r).toFixed(2) + 'px'; nw = el.offsetWidth;
        }
        if (/%$/.test(d.fitW || '')) st.minWidth = d.fitW;
        const g = el.offsetWidth - box0, ta = getComputedStyle(el).textAlign;
        let ml = /left|start/.test(ta) ? g / 2 : /right|end/.test(ta) ? -g / 2 : 0; // 정렬한 쪽 끝은 그대로
        st.marginLeft = ml ? ml.toFixed(1) + 'px' : '';
        const cr = cb.getBoundingClientRect(), er = el.getBoundingClientRect(), k = cr.width / W || 1; // (에디터 미리보기는 줄여서 보여 줌)
        const L = (er.left - cr.left) / k, R = (er.right - cr.left) / k;
        if (L < W * 0.03) ml += W * 0.03 - L; else if (R > W * 0.97) ml -= R - W * 0.97;
        st.marginLeft = ml ? ml.toFixed(1) + 'px' : '';
    }
    function fitTextLayers(root) {
        if (typeof document === 'undefined') return;
        (root || document).querySelectorAll(FIT_SEL).forEach(el => { try { fitOne(el); } catch (e) {} });
    }
    if (typeof document !== 'undefined' && typeof MutationObserver !== 'undefined') { // 에디터 미리보기·레이아웃 카드·공개 페이지 어디서 그려도 저절로
        let fitRaf = 0; const fitSoon = () => { if (!fitRaf) fitRaf = requestAnimationFrame(() => { fitRaf = 0; fitHeroArcs(document); fitTextLayers(document); }); };
        const hasFit = n => n.nodeType === 1 && (n.matches('svg.hl-arc, ' + FIT_SEL) || n.querySelector('svg.hl-arc, ' + FIT_SEL));
        const startFit = () => { new MutationObserver(ms => { if (ms.some(m => [...m.addedNodes].some(hasFit))) fitSoon(); }).observe(document.documentElement, { childList: true, subtree: true }); fitSoon(); };
        if (typeof window !== 'undefined') window.addEventListener('resize', fitSoon);
        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', startFit) : startFit();
        if (document.fonts) { document.fonts.addEventListener && document.fonts.addEventListener('loadingdone', fitSoon); document.fonts.ready && document.fonts.ready.then(fitSoon); }
    }
    function heroLayerInner(text, L) { return L && Number(L.arc) ? heroArcSvg(text, L) : nl2br(text); }
    // 문구 칸 글자 상자 (웹툰): 말풍선 · 나레이션 박스(노랑/흰색) · 효과음 글자(굵은 먹선) · 꼬리표(빨간 알약)
    const HL_DECOS = [['', '없음'], ['bubble', '말풍선'], ['caption', '나레이션 (노랑)'], ['caption-w', '나레이션 (흰색)'], ['sfx', '효과음 글자'], ['tag', '꼬리표']];
    function heroLayerCls(L) { return (L.deco && HL_DECOS.some(x => x[0] === L.deco && x[0]) ? ` hl-deco hl-d-${L.deco}` : '') + (L.vertical ? ' hl-vert' + (L.vertical === 'side' ? ' hl-vert-side' : '') : '') + (L.anim && HL_ANIMS.some(x => x[0] === L.anim) ? ` hl-anim hl-a-${L.anim}` : '') + (Number(L.arc) ? ' hl-curve' : ''); }
    function heroLayerCss(L) { return (Number(L.arc) ? `width:${Math.max(10, Math.min(100, Number(L.arcW) || 70))}%;` : '') + (L.anim ? `--hl-dur:${Math.max(.3, Math.min(8, Number(L.animDur) || 1.8))}s;--hl-delay:${Math.max(0, Math.min(8, Number(L.animDelay) || 0))}s;` : ''); }
    function heroLayersHtml(f, ink, styleOf, editor) {
        return ((f && f.heroLayers) || []).filter(k => f.layout && f.layout[k]).map(k => { const L = f.layout[k];
            return `<span class="drag-part ${ink || 'on-light'} op-layer${heroLayerCls(L)}" data-part="${esc(k)}"${editor ? ' data-drag-el' : ''} style="${styleOf(k)}${heroLayerCss(L)}">${heroLayerInner(heroFill(f[k], f), L)}</span>`; }).join('');
    }
    // 등장 효과: 공개 페이지는 화면에 보이면(인트로가 끝난 뒤) 한 번 / 에디터는 [효과 다시 보기]를 누를 때만 (평소엔 다 나온 모습)
    const hlReduced = () => typeof matchMedia !== 'undefined' && matchMedia('(prefers-reduced-motion: reduce)').matches;
    function armHeroAnims(root) {
        if (!root || typeof IntersectionObserver === 'undefined' || hlReduced()) return;
        const els = [...root.querySelectorAll('.op-layer.hl-anim:not(.hl-armed)')]; if (!els.length) return;
        els.forEach(e => e.classList.add('hl-arm', 'hl-armed')); // 재생 전까지 숨겨 둠 (인트로 뒤에서 미리 보이지 않게)
        // 인트로·첫 안내 팝업이 있는지는 화면에 보인 뒤에 확인 (예전엔 그리자마자 확인해서, 인트로가 아직 안 붙은 순간이라 인트로 뒤에서 재생돼 버렸음)
        const t0 = Date.now(), busy = () => document.querySelector('.intro-overlay, .ib-modal') && Date.now() - t0 < 30000;
        const io = new IntersectionObserver(es => es.forEach(en => {
            if (!en.isIntersecting) return; io.unobserve(en.target); const e = en.target;
            (function wait() { if (busy()) return setTimeout(wait, 150); setTimeout(() => { if (busy()) return wait(); requestAnimationFrame(() => e.classList.add('hl-run')); }, 250); })();
        }), { threshold: .15 });
        els.forEach(e => io.observe(e));
    }
    function playHeroAnims(root, only) { // only = 문구 칸 키 하나만 (에디터에서 그 줄 효과를 고를 때 - 기다림 없이 바로)
        if (!root) return; const els = [...root.querySelectorAll('.op-layer.hl-anim')].filter(e => !only || e.dataset.part === only); if (!els.length) return;
        let end = 0;
        els.forEach(e => { e.classList.remove('hl-run'); e.classList.add('hl-arm'); if (only) { if (e.dataset.hlD == null) e.dataset.hlD = e.style.getPropertyValue('--hl-delay'); e.style.setProperty('--hl-delay', '0s'); } const cs = getComputedStyle(e); end = Math.max(end, (parseFloat(cs.getPropertyValue('--hl-dur')) || 1.8) + (parseFloat(cs.getPropertyValue('--hl-delay')) || 0)); });
        void root.offsetWidth;
        requestAnimationFrame(() => requestAnimationFrame(() => els.forEach(e => e.classList.add('hl-run'))));
        clearTimeout(playHeroAnims._t); playHeroAnims._t = setTimeout(() => els.forEach(e => { e.classList.remove('hl-arm', 'hl-run'); if (e.dataset.hlD != null) { e.style.setProperty('--hl-delay', e.dataset.hlD || '0s'); delete e.dataset.hlD; } }), (end + .4) * 1000);
        return end;
    }
    // 에디터에서 효과·레이아웃을 고르면: 한 번 → 끝나고 1.3초 뒤 한 번 더 (스크롤 버튼 효과와 같은 방식)
    function playHeroAnimsTwice(root, only) {
        clearTimeout(playHeroAnimsTwice._t);
        const end = playHeroAnims(root, only); if (!end) return;
        playHeroAnimsTwice._t = setTimeout(() => playHeroAnims(root, only), (end + 1.3) * 1000);
    }
    // 메인 레이아웃 (기본 제공). 고르면 메인 사진·영상의 화면 크기·글자 자리·글꼴·색과 문구 칸을 이 모양으로 바꾸고, 사진·이름 같은 내용은 그대로 둠
    //  parts: 이름·날짜·하트 칸 / layers: 더 얹는 문구 칸 / hide: 안 보이게 할 칸 (layers로 대신 쓸 때) - 좌표는 화면(사진) 기준 %, 글자 크기는 폭 390 기준 px
    const HERO_LAYOUTS = [
        // 1. 위에 세 줄 세리프 + 아래 영문 날짜·글귀
        { id: 'our-wedding-day', label: 'our wedding day', desc: '위에 세 줄 세리프 · 아래 영문 날짜와 글귀',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'both', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'both', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'our\nwedding\nday', x: 50, y: 17, fontSize: 66, font: 'cormorant', color: '#FFF3DE', ls: -20, anim: 'fade', animDur: 2 },
                   { text: '~ {날짜:영문} ~', x: 50, y: 83, fontSize: 13, font: 'cormorant', color: '#FFFFFF', ls: 40, anim: 'fade', animDelay: .8 },
                   { text: 'Forever begins with a single step,\nAnd love guides us every step of the way.', x: 50, y: 89, fontSize: 12.5, font: 'cormorant', color: '#FFFFFF', anim: 'fade', animDelay: 1.2 }] },
        // 2. 가운데 아래 분홍 필기체
        { id: 'getting-married', label: "We're getting Married!", desc: '사진 가득 · 분홍 필기체 한 줄',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'none' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: "We're getting\nMarried!", x: 50, y: 70, fontSize: 50, font: 'alex-brush', color: '#F2A3B6', rotation: -8, anim: 'write', animDur: 2.4 }] },
        // 3. 위 양쪽 이름 + 세로 영문 이름 · 아래 양쪽 세로 예식장·날짜
        { id: 'names-vertical', label: '이름 양쪽 · 세로 글씨', desc: '위 양쪽에 이름, 가장자리에 세로 글씨', edge: true, // 가장자리까지 글자를 끌 수 있게 (에디터 dragRange)
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'both', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'both', heroShadeLv: '1' },
          parts: { groomName: { x: 15, y: 4.5, fontSize: 20, color: '#FFFFFF', ls: 20 }, brideName: { x: 85, y: 4.5, fontSize: 20, color: '#FFFFFF', ls: 20 } },
          hide: ['heart', 'datetime'],
          layers: [{ text: 'Groom', x: 15, y: 13, fontSize: 13, font: 'cormorant', color: '#FFFFFF', vertical: 'side', ls: 80 },
                   { text: 'Bride', x: 85, y: 13, fontSize: 13, font: 'cormorant', color: '#FFFFFF', vertical: 'side', ls: 80 },
                   { text: '{예식장}', x: 8.5, y: 80, fontSize: 13, color: '#FFFFFF', vertical: 'up', ls: 60 },
                   { text: '{날짜:점} {시간}', x: 91.5, y: 80, fontSize: 13, color: '#FFFFFF', vertical: 'side', ls: 60 }] },
        // 4. 폴라로이드 + 필기체 + 세로 이름 · 아래 날짜·예식장
        { id: 'polaroid', label: '폴라로이드', desc: '흰 카드 속 사진 · 필기체 · 아래 날짜와 예식장',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 9, y: 4, w: 82, h: 60, frame: 'polaroid' },
          parts: { datetime: { x: 50, y: 72, fontSize: 12.5, ls: 20 } },
          hide: ['groomName', 'brideName', 'heart'],
          layers: [{ text: 'Happy wedding day', x: 50, y: 56, fontSize: 30, font: 'great-vibes', color: '#3A3430', anim: 'write', animDur: 2.2 },
                   { text: '{신랑} {신부}', x: 85.5, y: 15, fontSize: 11.5, color: '#6F6A63', vertical: 'up', ls: 120 },
                   { text: '{예식장}', x: 50, y: 76.5, fontSize: 12.5, ls: 20 }] },
        // 5. 아래 왼쪽 금색 필기체 두 줄
        { id: 'happily-ever-after', label: 'happily ever after', desc: '아래 왼쪽에 금색 필기체 두 줄',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'bottom', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'bottom', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'happily\never after', x: 34, y: 74, fontSize: 36, font: 'great-vibes', color: '#E8C67C', rotation: -10, ls: 20, anim: 'write', animDur: 2.6 }] },
        // 6. 가운데 흰 필기체
        { id: 'happy-wedding-day', label: 'Happy wedding day', desc: '사진 가득 · 가운데 흰 필기체',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'full', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'full', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'Happy wedding day', x: 50, y: 66, fontSize: 40, font: 'alex-brush', color: '#FFFFFF', rotation: -4, anim: 'write', animDur: 2.2 }] },
        // 7. SAVE The DATE + 오른쪽 큰 숫자
        { id: 'save-the-date', label: 'SAVE The DATE', desc: '왼쪽 사진 · 오른쪽 큰 날짜 숫자 · 아래 이름',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 5, y: 13, w: 64, h: 42, frame: 'none' },
          parts: { datetime: { x: 50, y: 62, fontSize: 12.5, ls: 20 } },
          hide: ['groomName', 'brideName', 'heart'],
          layers: [{ text: 'SAVE', x: 22, y: 7, fontSize: 30, font: 'playfair', ls: 60 }, { text: 'The', x: 50, y: 7, fontSize: 36, font: 'great-vibes' }, { text: 'DATE', x: 78, y: 7, fontSize: 30, font: 'playfair', ls: 60 },
                   { text: '{년:2}\n{월}\n{일}', x: 84, y: 34, fontSize: 54, font: 'playfair', ls: 20 },
                   { text: '{신랑} & {신부}', x: 50, y: 69, fontSize: 21, ls: 20 },
                   { text: '{예식장}', x: 50, y: 74.5, fontSize: 12.5, ls: 20 }] },
        // 8. 아치 사진 + 곡선 글씨 + 양쪽 세로 이름
        { id: 'arch', label: '아치 · 곡선 글씨', desc: '아치 사진 위 곡선 글씨 · 양쪽 세로 이름',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 17, y: 9, w: 66, h: 47, frame: 'arch' },
          parts: { datetime: { x: 50, y: 64, fontSize: 12, ls: 20 } },
          hide: ['groomName', 'brideName', 'heart'],
          layers: [{ text: 'We are getting married!', x: 50, y: 6.2, fontSize: 13.5, ls: 40, arc: 35, arcW: 62 },
                   { text: '{신랑}', x: 8, y: 33, fontSize: 18, vertical: 'up', ls: 200 }, { text: '{신부}', x: 92, y: 33, fontSize: 18, vertical: 'up', ls: 200 },
                   { text: '{예식장}', x: 50, y: 68.5, fontSize: 12, ls: 20 }] },
        // 9. 아래로 흐려지는 사진 + 큰 날짜
        { id: 'fade-date', label: '흐려지는 사진 · 큰 날짜', desc: '사진이 아래로 흐려지고 큰 날짜 · 이름',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 0, y: 0, w: 100, h: 66, frame: 'none', fade: 34 },
          parts: { groomName: { x: 40, y: 82, fontSize: 15, ls: 20 }, heart: { x: 50, y: 82, fontSize: 11 }, brideName: { x: 60, y: 82, fontSize: 15, ls: 20 } },
          hide: ['datetime'],
          layers: [{ text: '{월}.{일}', x: 50, y: 66, fontSize: 32, font: 'playfair', ls: 40 },
                   { text: '{요일:영문짧게} {시간}', x: 50, y: 71.5, fontSize: 12, ls: 80 },
                   { text: '{예식장}', x: 50, y: 75.5, fontSize: 12, ls: 20 }] },
        // 10. 매거진 표지: 위 큰 제목 · 아래 양쪽에 이름과 날짜
        { id: 'magazine', label: '매거진 표지', desc: '위에 큰 WEDDING · 아래 양쪽에 이름과 날짜',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'both', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'both', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'WEDDING', x: 50, y: 9, fontSize: 54, font: 'playfair', color: '#FFFFFF', ls: 40, anim: 'fade', animDur: 1.6 },
                   { text: 'VOL. {년:2}  ·  {월}.{일}  ·  SPECIAL ISSUE', x: 50, y: 15.5, fontSize: 10, font: 'cormorant', color: '#FFFFFF', ls: 200, anim: 'fade', animDelay: .6 },
                   { text: '{신랑}\n& {신부}', x: 24, y: 85, fontSize: 21, color: '#FFFFFF', ls: 20, align: 'left', anim: 'up', animDelay: 1 },
                   { text: '{날짜:점}\n{예식장}', x: 76, y: 86, fontSize: 11.5, color: '#FFFFFF', ls: 40, align: 'right', anim: 'up', animDelay: 1.3 }] },
        // 11. 미니멀 한 줄: 아래에 작은 글씨 세 줄만
        { id: 'minimal-line', label: '미니멀 아래 세 줄', desc: '사진은 그대로 · 아래에 작은 글씨 세 줄',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'bottom', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'bottom', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'WEDDING INVITATION', x: 50, y: 80.5, fontSize: 10.5, font: 'cormorant', color: '#FFFFFF', ls: 400, anim: 'fade' },
                   { text: '{신랑}   ·   {신부}', x: 50, y: 85.5, fontSize: 20, color: '#FFFFFF', ls: 60, anim: 'fade', animDelay: .5 },
                   { text: '{날짜:점}  {요일:영문짧게}  {시간}', x: 50, y: 90.5, fontSize: 11, color: '#FFFFFF', ls: 120, anim: 'fade', animDelay: 1 }] },
        // 12. 위에 둥근 영문 · 아래 날짜
        { id: 'arc-top', label: '둥근 영문 제목', desc: '위에 둥글게 휜 영문 · 이름 · 아래 영문 날짜',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'both', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'both', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'WE ARE GETTING MARRIED', x: 50, y: 7.5, fontSize: 15, font: 'cormorant', color: '#FFFFFF', ls: 200, arc: 30, arcW: 80, anim: 'fade', animDur: 2 },
                   { text: '{신랑} & {신부}', x: 50, y: 16, fontSize: 18, color: '#FFFFFF', ls: 40, anim: 'fade', animDelay: .8 },
                   { text: '{날짜:영문}', x: 50, y: 90, fontSize: 12.5, font: 'cormorant', color: '#FFFFFF', ls: 80, anim: 'fade', animDelay: 1.2 }] },
        // 13. 위 사진 · 아래 글 (사진 칸)
        { id: 'split-top', label: '위 사진 · 아래 글', desc: '위쪽에 사진 · 아래 종이 위에 필기체와 이름',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 0, y: 0, w: 100, h: 58, frame: 'none' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'Wedding Invitation', x: 50, y: 65.5, fontSize: 31, font: 'pinyon', color: '#3A3430', anim: 'write', animDur: 2.2 },
                   { text: '{신랑}   그리고   {신부}', x: 50, y: 73.5, fontSize: 17, ls: 40 },
                   { text: '{날짜:점}  {시간}', x: 50, y: 79.5, fontSize: 12, ls: 40 },
                   { text: '{예식장}', x: 50, y: 83.5, fontSize: 12, ls: 20 }] },
        // 14. 왼쪽 위 큰 날짜 숫자 · 오른쪽 아래 이름
        { id: 'big-date', label: '큰 날짜 숫자', desc: '왼쪽 위에 큰 날짜 숫자 · 오른쪽 아래 이름',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'full', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'full', heroShadeLv: '1' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: '{년:2}\n{월}\n{일}', x: 21, y: 27, fontSize: 70, font: 'playfair', color: '#FFFFFF', ls: -10, anim: 'up', animDur: 1.6 },
                   { text: '{요일:영문}', x: 21, y: 47.5, fontSize: 11, font: 'cormorant', color: '#FFFFFF', ls: 300, anim: 'fade', animDelay: .8 },
                   { text: '{신랑} · {신부}', x: 72, y: 87.5, fontSize: 16, color: '#FFFFFF', ls: 40, anim: 'fade', animDelay: 1.1 },
                   { text: '{예식장}  {시간}', x: 72, y: 91.5, fontSize: 11, color: '#FFFFFF', ls: 20, anim: 'fade', animDelay: 1.3 }] },
        // 웹툰 1. 표지: 위 NEW 꼬리표 · 효과음 제목 · 기울어진 웹툰 컷 속 사진 · 아래 나레이션 박스와 '글·그림'
        { id: 'webtoon-cover', label: '웹툰 표지', desc: '효과음 제목 · 웹툰 컷 속 사진 · 나레이션 박스',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 6, y: 23, w: 88, h: 53, frame: 'comic' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'NEW', x: 19, y: 6, fontSize: 13, font: 'bangers', color: '#FFFFFF', ls: 60, deco: 'tag', anim: 'zoom', animDur: .7 },
                   { text: '{날짜:점} 첫 화 공개', x: 58, y: 6, fontSize: 12, color: '#1B1B1F', ls: 10 },
                   { text: '우리\n결혼해요!', x: 50, y: 14.5, fontSize: 42, font: 'bagel-fat-one', color: '#FF7A9C', rotation: -3, deco: 'sfx', anim: 'zoom', animDur: .9, animDelay: .2 },
                   { text: 'EP.01 · {날짜:점} {시간}\n{예식장}', x: 38, y: 78.5, fontSize: 12, color: '#1B1B1F', rotation: -2, deco: 'caption', anim: 'up', animDelay: .7 },
                   { text: '글·그림  {신랑} ♥ {신부}', x: 50, y: 87.5, fontSize: 12, color: '#6F6A73', ls: 20, anim: 'fade', animDelay: 1 }] },
        // 웹툰 2. 말풍선 컷: 사진 가득 · 위 나레이션 · 옆 효과음 '두근!' · 아래 큰 말풍선과 날짜 나레이션
        { id: 'webtoon-bubble', label: '웹툰 말풍선', desc: '사진 가득 · 위 나레이션 · 아래 큰 말풍선',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'none' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: 'EP.01 · 우리 결혼합니다', x: 36, y: 6, fontSize: 12.5, color: '#1B1B1F', rotation: -2, deco: 'caption', anim: 'up' },
                   { text: '두근!', x: 79, y: 15, fontSize: 42, font: 'black-han-sans', color: '#FF7A9C', rotation: 12, deco: 'sfx', anim: 'zoom', animDur: .7, animDelay: .5 },
                   { text: '{신랑} ♥ {신부}\n결혼합니다!', x: 50, y: 76, fontSize: 24, font: 'bagel-fat-one', color: '#1B1B1F', deco: 'bubble', anim: 'zoom', animDur: .8, animDelay: .9 },
                   { text: '{날짜:점} {요일:영문짧게} {시간}', x: 64, y: 89.5, fontSize: 12.5, color: '#1B1B1F', rotation: 1.5, deco: 'caption-w', anim: 'up', animDelay: 1.3 }] },
        // 천사의 편지: 양피지 바탕 · 금테 액자 속 사진 (모서리·꼭대기 바로크 장식) · 위 THE WEDDING DAY · 아래 필기체 이름과 날짜
        { id: 'angel-letter', label: '천사의 편지', desc: '양피지 · 금테 액자 속 사진 · 필기체 이름',
          photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'none' },
          box: { x: 14, y: 15, w: 72, h: 52, frame: 'baroque' },
          hide: ['groomName', 'brideName', 'heart', 'datetime'],
          layers: [{ text: '❦  THE WEDDING DAY  ❦', x: 50, y: 6.5, fontSize: 12, font: 'cormorant', color: '#9E3B3B', ls: 260, anim: 'fade', animDur: 1.4 },
                   { text: 'Lettre d\'amour', x: 50, y: 10.5, fontSize: 15, font: 'pinyon', color: '#8A6A45', anim: 'fade', animDelay: .4 },
                   { text: '{신랑}  &  {신부}', x: 50, y: 76, fontSize: 34, font: 'pinyon', color: '#4A3426', anim: 'write', animDur: 2.2, animDelay: .6 },
                   { text: '{날짜:점}  {요일:영문}  {시간}', x: 50, y: 84, fontSize: 12.5, color: '#6B5440', ls: 40, anim: 'up', animDelay: 1.2 },
                   { text: '{예식장}', x: 50, y: 88, fontSize: 12.5, color: '#6B5440', ls: 20, anim: 'up', animDelay: 1.4 }] }
    ];
    const HL_RESET = { deco: '', font: '', color: '', ls: 0, rotation: 0, align: '', outline: false, shadow: false, glow: '', scaleX: 100, widthAuto: true, width: 80, vertical: '', arc: 0, arcW: 70, anim: '', animDur: 1.8, animDelay: 0 };
    function applyHeroLayout(block, lay) {
        if (!block || !lay) return;
        const f = block.fields = block.fields || {}, isV = block.id === 'heroVideo';
        Object.assign(f, JSON.parse(JSON.stringify((isV ? lay.video : lay.photo) || {})));
        if (lay.box) f.heroBox = JSON.parse(JSON.stringify(lay.box)); else delete f.heroBox;
        if (isV && lay.box && !lay.video) Object.assign(f, { heightMode: 'full', videoTextOver: true, heroShade: 'none' }); // 사진 칸 레이아웃을 영상에: 영상이 칸 안으로
        f.layout = f.layout || {};
        (f.heroLayers || []).forEach(k => { delete f[k]; delete f.layout[k]; });
        f.heroLayers = [];
        Object.entries(lay.parts || {}).forEach(([k, v]) => { f.layout[k] = Object.assign({}, f.layout[k] || {}, HL_RESET, v); });
        f.hideParts = (lay.hide || []).filter(k => k !== 'heart');
        f.hideHeart = (lay.hide || []).includes('heart');
        const stamp = Date.now().toString(36).slice(-4);
        (lay.layers || []).forEach((L, i) => { const k = `hl_${stamp}${i}`, o = Object.assign({}, HL_RESET, L); f[k] = o.text || ''; delete o.text; f.layout[k] = o; f.heroLayers.push(k); });
        f.heroLayout = lay.id;
    }
    function creditsHtml(f) {
        const v = creditVars(), fill = t => String(t || '').replace(/\{(신랑|신부|날짜|시간|예식장)\}/g, (m, k) => v[k] || '');
        const rows = (f.creditRows || []).map(r => ({ l: fill(r.label), t: fill(r.text) })).filter(r => r.l || r.t);
        const list = rows.map(r => `<div class="ib-cr-row"><b>${esc(r.l)}</b><span>${esc(r.t)}</span></div>`).join('');
        const sec = Math.max(20, Math.min(120, Number(f.creditSpeed) || 45)), top = Math.max(5, Math.min(60, Number(f.creditTop) || 24)), hh = Math.max(20, Math.min(80, Number(f.creditH) || 46));
        const pos = (f.creditTextPos || (f.align === 'top' ? 'top' : 'bottom')) === 'top' ? 'flex-start' : 'flex-end'; // 글귀는 위 또는 아래 (가운데면 올라가는 크레딧과 겹쳐서) - creditTextPos가 없던 예전 저장분은 글 위치(align)대로
        // 같은 목록을 두 번 이어 붙여 -50%까지 올리면 끊김 없이 반복됨. 칸 위·아래는 흐려지게(mask)
        return `<div class="ib-block ib-ending ib-credits${f.image ? '' : ' ib-credits-noimg'}${f.creditAlign === 'center' ? ' ib-cr-center' : ''}" style="justify-content:${pos};">
            ${f.image ? `<img loading="lazy" decoding="async" src="${esc(imgUrl(f.image))}" alt=""><div class="ib-ending-shade" style="background:rgba(0,0,0,${(Number(f.overlay) || 0) / 100});"></div>` : ''}
            <div class="ib-cr-win" style="top:${top}%;height:${hh}%;"><div class="ib-cr-roll" style="animation-duration:${sec}s;">${rows.length ? `<div class="ib-cr-list">${list}</div><div class="ib-cr-list" aria-hidden="true">${list}</div>` : ''}</div></div>
            ${f.creditText !== false && String(f.text || '').trim() ? `<p class="ib-cr-text">${nl2br(f.text)}</p>` : ''}
        </div>`;
    }
    // 안내 말씀 모양 + 바로 넣는 예시 (간편 만들기 "눌러서 칸 추가" · 전문가 모드 예시 버튼)
    const NOTICE_STYLES = [['card', '카드형 (사진 포함)'], ['box', '박스형 (글만)'], ['slide', '슬라이드 (옆으로 넘기기)'], ['tabs', '탭 (눌러서 보기)']];
    const NOTICE_TPL = {
        '포토부스': '예식장 로비에 포토부스가 준비되어 있어요.\n두 사람과 함께한 오늘을 사진으로 남겨 주세요.',
        '주차 안내': '건물 지하 주차장을 이용해 주세요.\n예식 하객은 2시간 무료로 주차할 수 있어요.',
        '답례품': '와 주신 마음에 감사드리며\n작은 답례품을 준비했어요. 식사 후 안내 데스크에서 받아 주세요.',
        '식사 안내': '예식 후 2층 연회장에서\n식사가 준비되어 있습니다.',
        '화환 안내': '마음만 감사히 받겠습니다.\n화환은 정중히 사양합니다.',
        '셔틀버스': '예식장 앞에서 셔틀버스가 운행돼요.\n출발 시간은 아래 버튼에서 확인해 주세요.'
    };
    const BLOCKS = {
        // ---------- 연락하기 (신랑신부 + 혼주) ----------
        contact: {
            label: '연락하기', color: '#2E86AB',
            defaults: { title: '연락하기', groomPhone: '', bridePhone: '', groomFatherPhone: '', groomMotherPhone: '', brideFatherPhone: '', brideMotherPhone: '',
                contactLayout: 'side', contactStyle: 'basic', rowOrder: ['groomPhone', 'groomFatherPhone', 'groomMotherPhone', 'bridePhone', 'brideFatherPhone', 'brideMotherPhone'] },
            editor: [
                { key: 'title', label: '제목', type: 'text' },
                { key: 'contactStyle', label: '디자인', type: 'choice', options: CONTACT_STYLES },
                { key: 'contactLayout', label: '배치', type: 'choice', options: [['side', '신랑측·신부측 나란히'], ['list', '한 줄로 쭉'], ['sideB', '신부측 먼저']] },
                // 나란히 배치(신랑측·신부측 칸이 따로)일 땐 순서도 측별로 나눠서 그 측 안에서만 바꿈 (신부측 먼저면 신부측 목록이 위)
                { key: 'rowOrder', label: '보이는 순서 (↑↓로 바꿔요)', type: 'order', groups: [['groom', '신랑측'], ['bride', '신부측']], groupUnless: ['contactLayout', 'list'], groupFirst: ['contactLayout', 'sideB', 'bride'], options: [['groomPhone', '신랑'], ['groomFatherPhone', '신랑 아버지'], ['groomMotherPhone', '신랑 어머니'], ['bridePhone', '신부'], ['brideFatherPhone', '신부 아버지'], ['brideMotherPhone', '신부 어머니']] },
                { group: '신랑신부 연락처' },
                { key: 'groomPhone', label: '신랑 전화번호', type: 'tel' },
                { key: 'bridePhone', label: '신부 전화번호', type: 'tel' },
                { group: '혼주 연락처 (비워두면 안 보임)' },
                { key: 'groomFatherPhone', label: '신랑 아버지', type: 'tel' },
                { key: 'groomMotherPhone', label: '신랑 어머니', type: 'tel' },
                { key: 'brideFatherPhone', label: '신부 아버지', type: 'tel' },
                { key: 'brideMotherPhone', label: '신부 어머니', type: 'tel' }
            ],
            render(f) {
                // 공개 청첩장에서는 전화번호 대신 '__LD_SECURE__' 표시가 온다 (invite_view.php - 봇 수집 방지).
                // 그땐 번호 없는 버튼만 그리고, 하객이 화면을 만지면 render-invite.js의 bindGuestSecure가 번호를 채운다.
                // 버튼 안 <b>전화/문자</b> 글자는 '글자 버튼' 디자인에서만 보임 · 줄에 신랑측(g)/신부측(b) 표시는 '파스텔' 디자인 색칠용
                const row = (label, key) => {
                    const phone = f[key], side = key.startsWith('groom') ? 'g' : 'b';
                    const btns = (tel, sms) => `<a class="ib-icon-btn" ${tel} aria-label="${esc(label)}에게 전화"><i>${CT_IC.tel}</i><b>전화</b></a>
                        <a class="ib-icon-btn" ${sms} aria-label="${esc(label)}에게 문자"><i>${CT_IC.sms}</i><b>문자</b></a>`;
                    if (phone === '__LD_SECURE__') {
                        return `<div class="ib-contact-row ib-cr-${side}"><span class="ib-contact-who">${esc(label)}</span>
                        ${btns(`href="#" data-sec-phone="${key}" data-sec-kind="tel"`, `href="#" data-sec-phone="${key}" data-sec-kind="sms"`)}</div>`;
                    }
                    const d = phoneDigits(phone);
                    if (!d) return '';
                    return `<div class="ib-contact-row ib-cr-${side}"><span class="ib-contact-who">${esc(label)}</span>
                        ${btns(`href="tel:${d}"`, `href="sms:${d}"`)}</div>`;
                };
                // 보이는 순서: 에디터에서 ↑↓로 정한 순서 (빠진 칸은 원래 순서대로 뒤에)
                const LBL = { groomPhone: L.groom, groomFatherPhone: `${L.groom} ${L.father}`, groomMotherPhone: `${L.groom} ${L.mother}`,
                    bridePhone: L.bride, brideFatherPhone: `${L.bride} ${L.father}`, brideMotherPhone: `${L.bride} ${L.mother}` };
                const ALL = Object.keys(LBL);
                const ord = (Array.isArray(f.rowOrder) ? f.rowOrder.filter(k => LBL[k]) : []).concat(ALL).filter((k, i, a) => a.indexOf(k) === i);
                const rows = keys => keys.map(k => row(LBL[k], k)).join('');
                const groom = rows(ord.filter(k => k.startsWith('groom'))), bride = rows(ord.filter(k => k.startsWith('bride')));
                const cs = CONTACT_STYLES.some(([k]) => k === f.contactStyle) ? f.contactStyle : 'basic';
                const cls = `ib-block ib-contact ib-cs-${cs}`;
                if (!groom && !bride) return `<div class="${cls}">${sectionTitle(f.title, f)}${emptyHint('전화번호를 입력하면 전화·문자 버튼이 나타나요')}</div>`;
                // 한 줄로 쭉: 정한 순서 그대로
                if (f.contactLayout === 'list') return `<div class="${cls}">${sectionTitle(f.title, f)}<div class="ib-contact-side ib-contact-list">${rows(ord)}</div></div>`;
                const head = side => `<p class="ib-contact-h">${esc(side === 'g' ? L.groom : L.bride)}측</p>`; // 빈티지·라인 상자·가운데 정렬·캡슐 버튼에서만 보임
                return `<div class="${cls}">${sectionTitle(f.title, f)}
                    <div class="ib-contact-grid">
                        ${(() => { const gs = groom ? `<div class="ib-contact-side ib-groom">${head('g')}${groom}</div>` : '', bs = bride ? `<div class="ib-contact-side ib-bride">${head('b')}${bride}</div>` : '';
                            // 어느 측 칸이 왼쪽인지는 배치로만 정함: 신부측 먼저(sideB, 잠깐 있던 'listB'도) = 신부측 왼쪽 / 나란히 = 신랑측 왼쪽(공통 설정이 신부 먼저면 신부측)
                            const brideFirst = f.contactLayout === 'sideB' || f.contactLayout === 'listB';
                            return brideFirst ? bs + gs : bothSides(gs, bs); })()}
                    </div></div>`;
            }
        },

        // ---------- 프로필형 소개 ----------
        profile: {
            label: '프로필형 소개', color: '#E07A5F',
            defaults: { layout: 'row', groomLabel: '신랑', groomName: '민준', groomImage: '', groomText: '늘 곁에서 웃게 해주는 사람', brideLabel: '신부', brideName: '서연', brideImage: '', brideText: '함께라서 더 행복한 사람' },
            editor: [
                { key: 'layout', label: '배치', type: 'choice', options: [['row', '가로형'], ['col', '세로형']] },
                { group: '신랑' },
                { key: 'groomName', label: '이름', type: 'text' },
                { key: 'groomImage', label: '사진', type: 'image' },
                { key: 'groomText', label: '소개글', type: 'textarea' },
                { group: '신부' },
                { key: 'brideName', label: '이름', type: 'text' },
                { key: 'brideImage', label: '사진', type: 'image' },
                { key: 'brideText', label: '소개글', type: 'textarea' }
            ],
            render(f) {
                const card = (label, name, img, text, side) => `
                    <div class="ib-profile-card ib-${side}">
                        ${img ? `<div class="ib-profile-photo"><img loading="lazy" decoding="async" src="${esc(imgUrl(img))}" alt=""></div>` : (isEditor() ? '<div class="ib-profile-photo"><span>사진을 올려주세요</span></div>' : '')}
                        <div class="ib-profile-body"><span class="ib-profile-label">${esc(label)}</span><strong>${esc(name)}</strong><p>${nl2br(text)}</p></div>
                    </div>`;
                return `<div class="ib-block ib-profile ib-profile-${f.layout === 'col' ? 'col' : 'row'}">
                    ${bothSides(card(L.groom, f.groomName, f.groomImage, f.groomText, 'groom'), card(L.bride, f.brideName, f.brideImage, f.brideText, 'bride'))}
                </div>`;
            }
        },

        // ---------- 손편지 ----------
        letter: {
            label: '손편지', color: '#A47148',
            defaults: { image: '', text: '서로의 가장 좋은 친구가 되어\n평생 함께 걸어가겠습니다.\n\n저희의 첫걸음을\n따뜻하게 지켜봐 주세요.', paper: 'cotton', paperColor: '#FAFAF7', paperScale: 100, paperFull: false, font: 'nanum-pen', fontSize: 22 },
            editor: [
                { key: 'image', label: '편지 사진 (선택)', type: 'image' },
                { key: 'text', label: '편지 내용', type: 'textarea', rows: 7, hint: '편지 글은 미리보기에서 끌어서 옮기고, 눌러서 글자 크기·폭·정렬을 바꿔요. 종이 높이는 글을 누른 뒤 "칸 높이"로.' },
                { key: 'paper', label: '종이 재질 (무늬)', type: 'choice', options: Object.entries(PAPERS) },
                { key: 'paperScale', label: '무늬 크기', type: 'range', min: 50, max: 400, unit: '%' },
                { key: 'paperColor', label: '종이 색상', type: 'color' },
                { key: 'paperFull', label: '', type: 'check', checkLabel: '종이를 가로 100%로 꽉 채우기 (좌우 여백 없이)' },
                { key: 'font', label: '글씨체', type: 'choice', options: Object.entries(FONT_CSS).map(([k, v]) => [k, v.label]) }
            ],
            render(f) {
                const fam = ensureFont(f.font) || 'inherit';
                const ps = Math.max(50, Math.min(400, Number(f.paperScale) || 100)) / 100;
                // 종이 = 자유 배치 칸 (편지 글을 끌어서 옮기고 크기 조절), 무늬는 배경 패턴 (무늬 크기 조절)
                const paper = freeCanvas(f, 'paper', 300, [['text', nl2br(f.text), { x: 50, y: 50, fs: Number(f.fontSize) || 22, w: 86, align: 'left', cls: 'ib-letter-text' }]],
                    `ib-paper ib-paper-${esc(f.paper || 'cotton')}${f.paperFull ? ' ib-paper-full' : ''}${String(f.paperColor || '#FAFAF7').toUpperCase() === '#FAFAF7' ? ' ib-paper-def' : ''}`).replace('style="height:', `style="--paper:${esc(f.paperColor || '#FAFAF7')}; --ps:${ps}; font-family:${fam}; height:`); // (ib-paper-def = 종이 색을 따로 안 고름 → 어두운 디자인에선 어두운 종이)
                return `<div class="ib-block ib-letter">
                    ${f.image ? `<div class="ib-letter-photo"><img loading="lazy" decoding="async" src="${esc(imgUrl(f.image))}" alt=""></div>` : ''}
                    ${paper}
                </div>`;
            }
        },

        // ---------- 영상 ----------
        video: {
            label: '영상', color: '#C0392B',
            defaults: { title: '식전 영상', youtube: '', fullWidth: false, size: 100, radius: 12 },
            editor: [
                { key: 'title', label: '영상 제목', type: 'text' },
                { key: 'youtube', label: '유튜브 주소', type: 'url', placeholder: 'https://youtu.be/...' },
                { group: '영상 크기·모양' },
                { key: 'fullWidth', label: '', type: 'check', checkLabel: '영상 가로 100%로 꽉 채우기 (좌우 여백 없이)' },
                { key: 'size', label: '영상 폭', type: 'range', min: 50, max: 100, unit: '%', showIf: '!fullWidth' },
                { key: 'radius', label: '모서리 둥글기', type: 'range', min: 0, max: 40, unit: 'px' }
            ],
            render(f) {
                const id = youtubeId(f.youtube);
                // 유튜브는 어느 사이트에서 틀었는지(Referer)를 꼭 받아야 재생한다 - 청첩장 페이지는 개인정보 때문에
                // 기본으로 Referer를 안 보내서(no-referrer) "오류 153"이 났다. 이 영상 칸만 주소의 앞부분(도메인)만 보내게 한다.
                const origin = (typeof location !== 'undefined' && /^https?:/.test(location.protocol)) ? '&origin=' + encodeURIComponent(location.origin) : '';
                const full = !!f.fullWidth, size = Math.max(50, Math.min(100, +f.size || 100)), radius = Math.max(0, Math.min(40, f.radius == null ? 12 : +f.radius));
                const style = `border-radius:${radius}px;${full ? '' : `width:${size}%;margin-left:auto;margin-right:auto;`}`;
                const body = id
                    ? `<div class="ib-video${full ? ' full-width' : ''}" style="${style}"><iframe loading="lazy" src="https://www.youtube-nocookie.com/embed/${esc(id)}?rel=0&playsinline=1${origin}" title="${esc(f.title || '영상')}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>`
                    : (isEditor() // 에디터에서는 주소를 넣기 전에도 폭·둥글기·가로 100%가 바로 보이게 빈 영상 칸을 그림
                        ? `<div class="ib-video ib-video-empty${full ? ' full-width' : ''}" style="${style}"><span>▶ 유튜브 주소를 넣으면 여기에 영상이 나와요</span></div>`
                        : '');
                return `<div class="ib-block">${sectionTitle(f.title, f)}${body}</div>`;
            }
        },

        // ---------- 교통수단 ----------
        transport: {
            label: '교통수단', color: '#16A085',
            defaults: { title: '오시는 방법', style: 'theme', items: [
                { id: 'tr1', label: '지하철', text: '2호선 강남역 1번 출구에서 도보 5분' },
                { id: 'tr2', label: '버스', text: '강남역 정류장 하차 (간선 140, 144, 145)' },
                { id: 'tr3', label: '주차', text: '건물 지하 주차장 이용 (2시간 무료)' }
            ] },
            editor: [
                { key: 'title', label: '제목', type: 'text' },
                { key: 'style', label: '모양', type: 'choice', options: [['theme', '테마 모양'], ['basic', '기본 (아이콘)']], hint: '테마 모양은 디자인마다 달라요 (번호 · 오솔길 · 씬 번호 · 체크리스트 …).' },
                { key: 'items', label: '교통수단', type: 'items', addLabel: '교통수단 추가', item: { label: '', text: '', depart: '' }, fields: [
                    { key: 'label', label: '교통수단', type: 'text', placeholder: '지하철 / 버스 / 자가용 / 대절버스' },
                    { key: 'text', label: '내용', type: 'textarea' },
                    { key: 'depart', label: '출발 시각 (대절·셔틀버스일 때만)', type: 'text', placeholder: '예: 09:30',
                      hint: '적어 두면 하객이 "캘린더에 저장"을 눌렀을 때 버스 출발 30분 전에도 알림이 울려요. 예식 당일 기준이에요.' }
                ] }
            ],
            render(f) {
                const icon = l => /지하철|전철/.test(l) ? '🚇' : /버스|셔틀/.test(l) ? '🚌' : /자가용|자동차|차량|네비/.test(l) ? '🚗' : /주차/.test(l) ? '🅿️' : /기차|KTX|SRT/.test(l) ? '🚆' : /비행/.test(l) ? '✈️' : '📍';
                const items = (f.items || []).filter(it => it.label || it.text);
                return `<div class="ib-block ib-transport${f.style === 'basic' ? '' : ' ib-tr-theme'}">${sectionTitle(f.title, f)}
                    ${items.length ? items.map(it => `<div class="ib-transport-item"><span class="ib-transport-ico">${icon(it.label || '')}</span>
                        <div><strong>${esc(it.label)}</strong>${busTime(it.depart) ? `<span class="ib-depart">${esc(busTime(it.depart))} 출발</span>` : ''}<p>${nl2br(it.text)}</p></div></div>`).join('') : emptyHint('교통수단을 추가해주세요')}
                </div>`;
            }
        },

        // ---------- 안내문 (카드형 / 박스형) ----------
        notice: {
            label: '안내문', color: '#8E6C8A',
            defaults: { title: '안내 말씀', style: 'card', items: [
                { id: 'nt1', title: '식사 안내', image: '', text: '예식 후 2층 연회장에서\n식사가 준비되어 있습니다.', linkLabel: '', linkUrl: '' }
            ] },
            editor: [
                { key: 'title', label: '제목', type: 'text' },
                { key: 'style', label: '모양', type: 'choice', options: NOTICE_STYLES },
                { key: 'items', label: '안내문', type: 'items', addLabel: '안내문 추가', item: { title: '', image: '', text: '', linkLabel: '', linkUrl: '' }, fields: [
                    { key: 'title', label: '제목', type: 'text' },
                    { key: 'image', label: '사진 (카드형·슬라이드·탭에서 보임)', type: 'image' },
                    { key: 'text', label: '내용', type: 'textarea' },
                    { key: 'linkLabel', label: '외부링크 버튼 이름 (선택)', type: 'text', placeholder: '예: 셔틀버스 시간표 보기' },
                    { key: 'linkUrl', label: '외부링크 주소', type: 'url', placeholder: 'https://' }
                ] }
            ],
            render(f) {
                const box = f.style === 'box';
                const items = (f.items || []).filter(it => it.title || it.text || it.image);
                const link = it => { const u = linkHref(it.linkUrl); return u
                    ? `<a class="ib-link-btn" href="${esc(u)}" target="_blank" rel="noopener">${esc(it.linkLabel || '자세히 보기')}</a>` : ''; };
                const pic = it => it.image ? `<img loading="lazy" decoding="async" src="${esc(imgUrl(it.image))}" alt="">` : '';
                const body = it => `<div class="ib-notice-body">${it.title ? `<strong>${esc(it.title)}</strong>` : ''}<p>${nl2br(it.text)}</p>${link(it)}</div>`;
                if (items.length && f.style === 'slide') // 슬라이드: 옆으로 넘기는 카드 + 진행 막대 (갤러리 슬라이드와 같은 방식)
                    return `<div class="ib-block ib-notice ib-notice-slide">${sectionTitle(f.title, f)}<div class="ib-nt-track" data-ib-slide>${items.map(it => `<div class="ib-nt-card">${pic(it)}${body(it)}</div>`).join('')}</div>${items.length > 1 ? '<div class="ib-g-progress"><span></span></div>' : ''}</div>`;
                if (items.length && f.style === 'tabs') // 탭: 위에 제목 탭, 누르면 그 안내만
                    return `<div class="ib-block ib-notice ib-notice-tabs" data-ib-tabs>${sectionTitle(f.title, f)}<div class="ib-nt-tabs" role="tablist">${items.map((it, i) => `<button type="button" role="tab" class="${i ? '' : 'on'}" data-ib-tab="${i}" aria-selected="${!i}">${esc(it.title || `안내 ${i + 1}`)}</button>`).join('')}</div>${items.map((it, i) => `<div class="ib-nt-panel" data-ib-panel="${i}"${i ? ' hidden' : ''}>${pic(it)}<div class="ib-notice-body"><p>${nl2br(it.text)}</p>${link(it)}</div></div>`).join('')}</div>`;
                return `<div class="ib-block ib-notice ib-notice-${box ? 'box' : 'card'}">${sectionTitle(f.title, f)}
                    ${items.length ? items.map(it => `<div class="ib-notice-item">
                        ${!box && it.image ? `<img loading="lazy" decoding="async" src="${esc(imgUrl(it.image))}" alt="">` : ''}
                        <div class="ib-notice-body">${it.title ? `<strong>${esc(it.title)}</strong>` : ''}<p>${nl2br(it.text)}</p>${link(it)}</div>
                    </div>`).join('') : emptyHint('안내문을 추가해주세요')}
                </div>`;
            }
        },

        // ---------- 함께한 시간 ----------
        together: {
            label: '함께한 시간', color: '#F2A541',
            defaults: { title: '함께한 시간', firstMet: '2021-04-10', caption: '처음 만난 날부터 지금까지' },
            editor: [
                { key: 'title', label: '제목', type: 'text' },
                { key: 'firstMet', label: '첫 만남', type: 'date' },
                { key: 'caption', label: '설명 문구', type: 'text' }
            ],
            render(f) {
                const d = f.firstMet ? new Date(f.firstMet + 'T00:00:00') : null;
                if (!d || isNaN(d)) return `<div class="ib-block ib-together">${sectionTitle(f.title, f)}${emptyHint('첫 만남 날짜를 입력해주세요')}</div>`;
                const days = Math.max(1, Math.floor((Date.now() - d.getTime()) / 86400000) + 1);
                const dateLabel = `${d.getFullYear()}.${String(d.getMonth() + 1).padStart(2, '0')}.${String(d.getDate()).padStart(2, '0')}`;
                return `<div class="ib-block ib-together">${sectionTitle(f.title, f)}
                    ${freeCanvas(f, 'together', 150, [
                        ['caption', esc(f.caption), { x: 50, y: 16, fs: 13, cls: 'ib-together-cap' }],
                        ['days', `<span>${days.toLocaleString('ko-KR')}</span>일`, { x: 50, y: 50, fs: 16, cls: 'ib-together-days' }],
                        ['dateLine', `${dateLabel} 첫 만남`, { x: 50, y: 84, fs: 12, cls: 'ib-together-date' }]
                    ])}
                </div>`;
            }
        },

        // ---------- 엔딩 ----------
        ending: {
            label: '엔딩', color: '#34495E',
            defaults: { image: '', text: '저희의 새로운 시작을\n함께해 주셔서 감사합니다.', align: 'bottom', overlay: 35,
                credits: false, creditText: true, creditTextPos: 'bottom', creditRows: CREDIT_ROWS_DEFAULT.map(r => Object.assign({}, r)), creditSpeed: 45, creditAlign: 'left', creditTop: 24, creditH: 46 },
            editor: [
                { key: 'credits', label: '', type: 'check', checkLabel: '엔딩 크레딧(스탭롤)로 보여주기',
                  hint: '사진을 화면 가득 깔고, 그 위 정해진 칸 안에서만 글자가 영화 엔딩처럼 아주 천천히 올라가요.' },
                { key: 'creditText', label: '', type: 'check', checkLabel: '크레딧과 함께 글귀도 보여주기', showIf: 'credits',
                  hint: '켜면 글귀가 크레딧 칸 위나 아래에 그대로 보여요.' },
                { key: 'creditTextPos', label: '글귀 자리', type: 'choice', options: [['top', '위'], ['bottom', '아래']], showIf: 'credits&creditText' },
                { key: 'image', label: '사진', type: 'image' },
                { key: 'text', label: '글귀', type: 'textarea', showIf: '!credits|creditText' },
                { key: 'align', label: '글 위치 (사진 위)', type: 'choice', options: [['top', '상단'], ['center', '중간'], ['bottom', '하단']], showIf: 'image' },
                { key: 'overlay', label: '사진 어둡게 (글씨 강조)', type: 'range', min: 0, max: 80, unit: '%', showIf: 'image' },
                { key: 'creditRows', label: '크레딧 줄', type: 'items', addLabel: '줄 추가', item: { label: '', text: '' }, showIf: 'credits', compact: true, fields: [
                    { key: 'label', label: '왼쪽 (항목)', short: '항목 (왼쪽)', type: 'text', placeholder: 'Directed by' },
                    { key: 'text', label: '오른쪽 (내용)', short: '내용 (오른쪽)', type: 'text', placeholder: '{신랑}, {신부}',
                      hint: '{신랑} {신부} {날짜} {시간} {예식장}을 적으면 청첩장에 적은 값으로 바뀌어요.' }
                ] },
                { key: 'creditSpeed', label: '올라가는 속도 (한 바퀴 걸리는 시간)', type: 'range', min: 20, max: 120, unit: '초', showIf: 'credits' },
                { key: 'creditAlign', label: '글자 정렬', type: 'choice', options: [['left', '왼쪽'], ['center', '가운데']], showIf: 'credits' },
                { key: 'creditTop', label: '글자 칸 위치 (위에서)', type: 'range', min: 5, max: 60, unit: '%', showIf: 'credits' },
                { key: 'creditH', label: '글자 칸 높이', type: 'range', min: 20, max: 80, unit: '%', showIf: 'credits' }
            ],
            render(f) {
                if (f.credits) return creditsHtml(f);
                const pos = { top: 'flex-start', center: 'center', bottom: 'flex-end' }[f.align] || 'flex-end';
                if (!f.image) return `<div class="ib-block ib-ending ib-ending-noimg"><p>${nl2br(f.text)}</p></div>`;
                return `<div class="ib-block ib-ending" style="justify-content:${pos};">
                    <img loading="lazy" decoding="async" src="${esc(imgUrl(f.image))}" alt="">
                    <div class="ib-ending-shade" style="background:rgba(0,0,0,${(Number(f.overlay) || 0) / 100});"></div>
                    <p>${nl2br(f.text)}</p>
                </div>`;
            }
        }
    };

    // ---------- 게스트스냅 (하객 사진 업로드) ----------
    // 실제 업로드는 서버의 snap.php(하객용)·snap_upload.php가 처리한다. 여기서는 안내 문구 + "사진 올리기" 버튼만.
    // 버튼 주소에 쓰는 공개코드는 invite_view.php가 window.INVITE_SLUG로 넣어준다.
    BLOCKS.guestsnap = {
        label: '게스트스냅', color: '#1ABC9C',
        defaults: { title: '게스트스냅', desc: '예식날 찍은 사진을 저희에게 보내주세요!\n로그인 없이 바로 올릴 수 있어요.', buttonLabel: '📷 사진 올리기', earlyUpload: false },
        editor: [
            { key: 'title', label: '제목', type: 'text' },
            { key: 'desc', label: '안내 문구', type: 'textarea', rows: 4 },
            { key: 'buttonLabel', label: '버튼 문구', type: 'text' },
            { key: 'earlyUpload', label: '', type: 'check', checkLabel: '예식 전에도 사진을 올릴 수 있게 하기',
              hint: '켜면 저장(발행)한 때부터 바로 받아요 - 웨딩촬영·브라이덜샤워 사진도 모을 수 있어요. 마감일과 보관 기간은 그대로예요.' }
        ],
        render(f) {
            const slug = global.INVITE_SLUG;
            // 사진 올리기는 새 창이 아니라 청첩장 위 팝업으로 (bindStage4Clicks의 data-ib-snap). 대시보드는 예전처럼 새 창.
            const btn = slug
                ? `<a class="ib-snap-btn" href="/invite/snap.php?s=${encodeURIComponent(slug)}" data-ib-snap="${esc(slug)}" target="_blank" rel="noopener">${esc(f.buttonLabel || '사진 올리기')}</a>`
                : `<span class="ib-snap-btn is-disabled">${esc(f.buttonLabel || '사진 올리기')}</span>`;
            // 안내 문구·버튼도 자유 배치 (끌어서 위치, 눌러서 글자 크기)
            return `<div class="ib-block ib-snap">${sectionTitle(f.title, f)}${freeCanvas(f, 'snap', 150, [
                ['desc', nl2br(f.desc), { x: 50, y: 32, fs: 13.5, w: 90, cls: 'ib-snap-desc' }],
                ['snapBtn', btn, { x: 50, y: 80, fs: 14 }]
            ])}${slug ? '' : emptyHint('버튼은 발행된 청첩장에서 하객 업로드 팝업으로 열려요')}</div>`;
        }
    };
    // ---------- 신혼여행 라이브 (신랑신부가 여행지에서 올리는 사진 + 도시 체크인 → 여행 경로 지도) ----------
    // 소식은 서버(trip_post.php)에 쌓이고, 공개 청첩장은 trip_feed.php에서 받아 그린다 (initTrip).
    // 에디터 미리보기·아직 소식이 없을 때는 예시(파리·니스)로 모양만 보여준다.
    BLOCKS.trip = {
        label: '신혼여행 라이브', color: '#E67E22',
        defaults: { title: '우리 지금 여기', desc: '신혼여행 중에 올리는 사진과 들른 곳이\n여기에 실시간으로 올라와요.', showMap: true, delayHours: '0', startDate: '', order: 'new' },
        editor: [
            { key: 'title', label: '제목', type: 'text' },
            { key: 'desc', label: '안내 문구', type: 'textarea', rows: 3 },
            { key: 'startDate', label: '하객에게 보이기 시작하는 날 (보통 출발일)', type: 'date', hint: '비워두면 첫 소식을 올리는 순간부터 보여요. 그 전에는 청첩장에서 이 섹션이 숨겨져요.' },
            { key: 'showMap', label: '', type: 'check', checkLabel: '🗺 여행 경로 지도 보이기 (체크인한 도시를 선으로 이어요)' },
            { key: 'delayHours', label: '하객에게 공개 늦추기', type: 'choice', options: [['0', '바로'], ['3', '3시간 뒤'], ['12', '12시간 뒤'], ['24', '하루 뒤']],
              hint: '지금 있는 곳이 바로 알려지는 게 걱정되면 늦춰 두세요. 위치는 도시 수준으로만 표시돼요 (숙소 위치 같은 자세한 곳은 저장하지 않아요).' },
            { key: 'order', label: '소식 순서', type: 'choice', options: [['new', '최신 소식 먼저'], ['old', '여행 순서대로']] }
        ],
        render(f) {
            const map = f.showMap !== false ? '<div class="ib-trip-map" data-trip-map hidden></div>' : '';
            return `<div class="ib-block ib-trip" data-ib-trip>${sectionTitle(f.title, f)}${f.desc ? `<p class="ib-trip-desc">${nl2br(f.desc)}</p>` : ''}
                ${map}<div class="ib-trip-feed" data-trip-feed data-order="${f.order === 'old' ? 'old' : 'new'}"></div></div>`;
        }
    };
    // 에디터 미리보기용 예시 소식
    function tripSamplePosts() {
        const h = 3600000, now = Date.now();
        return [
            { place: '인천, 대한민국', lat: 37.5, lng: 126.4, caption: '드디어 출발해요 ✈️', at: now - 50 * h, photos: [] },
            { place: '파리, 프랑스', lat: 48.9, lng: 2.4, caption: '에펠탑 앞에서 첫 저녁 🗼', at: now - 30 * h, photos: [{ ph: 1 }, { ph: 2 }] },
            { place: '니스, 프랑스', lat: 43.7, lng: 7.3, caption: '바다 색이 너무 예뻐요', at: now - 2 * h, photos: [{ ph: 3 }] }
        ];
    }
    function tripAgo(ms) {
        const m = Math.max(0, Math.round((Date.now() - ms) / 60000));
        if (m < 1) return '방금';
        if (m < 60) return m + '분 전';
        if (m < 24 * 60) return Math.floor(m / 60) + '시간 전';
        const d = new Date(ms);
        return `${d.getMonth() + 1}월 ${d.getDate()}일`;
    }
    function tripFeedHtml(posts, order, sample) {
        if (!posts.length) return `<p class="ib-trip-empty">아직 올라온 소식이 없어요. 곧 여행 소식이 올라올 거예요 💌</p>`;
        const list = order === 'old' ? posts.slice() : posts.slice().reverse();
        const newest = posts[posts.length - 1];
        return list.map(p => {
            const ph = (p.photos || []).slice(0, 6);
            const photos = ph.length ? `<div class="ib-trip-photos n${Math.min(ph.length, 3)}">${ph.map(x => x.ph
                ? `<span class="ib-trip-ph ph${x.ph}"></span>`
                : `<img loading="lazy" decoding="async" src="${esc(x.src)}" alt=""${x.w && x.h ? ` width="${x.w}" height="${x.h}"` : ''}>`).join('')}</div>` : '';
            const isNew = p === newest && Date.now() - p.at < 24 * 3600000;
            return `<article class="ib-trip-post${isNew ? ' is-new' : ''}">
                <div class="ib-trip-meta">${p.place ? `<span class="ib-trip-place">📍 ${esc(p.place)}</span>` : '<span></span>'}<time>${isNew ? '<b>NEW</b> ' : ''}${tripAgo(p.at)}${p.pending ? ' · 공개 전' : ''}</time></div>
                ${photos}${p.caption ? `<p class="ib-trip-cap">${nl2br(p.caption)}</p>` : ''}</article>`;
        }).join('') + (sample ? '<p class="ib-trip-sample-note">예시 모양이에요. 실제 소식은 내 청첩장 → "신혼여행 라이브"에서 올려요.</p>' : '');
    }
    // ---- 여행 경로 지도 (지도 서비스 없이 직접 그림 - 비용·가입 필요 없음) ----
    // 육지 모양: Natural Earth(퍼블릭 도메인) 1:50m 을 간단하게 줄인 선 (assets/world-land.json, 처음 한 번만 받음)
    // 좌표 → 화면: 경도·위도를 그대로 가로·세로로 펼친 지도 (가로 1000 × 세로 500)
    let worldLand = null;
    function loadWorld() {
        if (!worldLand) worldLand = fetch('/invite/assets/world-land.json').then(r => r.ok ? r.json() : { d: '' }).then(j => j.d || '').catch(() => '');
        return worldLand;
    }
    async function drawTripMap(el, posts) {
        const pts = posts.filter(p => p.lat != null && p.lng != null);
        if (!el) return;
        if (!pts.length) { el.hidden = true; el.innerHTML = ''; return; }
        const land = await loadWorld();
        // 날짜변경선을 건너는 여행(예: 한국 → 하와이)은 서쪽 경도에 360을 더해 한 화면에 이어지게
        const lngs = pts.map(p => p.lng);
        const wrap = Math.max(...lngs) - Math.min(...lngs) > 180;
        const P = pts.map(p => [((wrap && p.lng < 0 ? p.lng + 360 : p.lng) + 180) / 360 * 1000, (90 - p.lat) / 180 * 500]);
        let x0 = Math.min(...P.map(p => p[0])), x1 = Math.max(...P.map(p => p[0]));
        let y0 = Math.min(...P.map(p => p[1])), y1 = Math.max(...P.map(p => p[1]));
        const RATIO = 0.62; // 세로/가로
        let w = Math.max(44, (x1 - x0) * 1.35), h = Math.max(w * RATIO, (y1 - y0) * 1.5);
        w = Math.max(w, h / RATIO); h = w * RATIO;
        const cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
        const vx = cx - w / 2, vy = Math.max(-20, Math.min(520 - h, cy - h / 2));
        const u = w / 100; // 화면 크기에 맞춘 단위 (점·글씨 크기)
        let route = '';
        for (let i = 1; i < P.length; i++) {
            const [ax, ay] = P[i - 1], [bx, by] = P[i];
            const mx = (ax + bx) / 2, my = (ay + by) / 2, dx = bx - ax, dy = by - ay, len = Math.hypot(dx, dy) || 1;
            const k = Math.min(len * 0.22, 14 * u); // 살짝 휘어진 비행 경로
            route += `M${ax.toFixed(2)},${ay.toFixed(2)} Q${(mx - dy / len * k).toFixed(2)},${(my + dx / len * k).toFixed(2)} ${bx.toFixed(2)},${by.toFixed(2)} `;
        }
        // 도시 이름: 최근 소식부터 붙이고, 이미 붙인 이름과 너무 가까우면 생략 (겹쳐 보이지 않게)
        const placed = [], labels = [];
        for (let i = P.length - 1; i >= 0; i--) {
            const [x, y] = P[i], name = String(pts[i].place || '').split(',')[0].trim();
            if (!name || placed.some(([px, py]) => Math.abs(px - x) < 14 * u && Math.abs(py - y) < 5 * u)) continue;
            placed.push([x, y]);
            const right = x < vx + w * 0.72; // 오른쪽 끝에 가까우면 이름을 점 왼쪽에
            labels.push(`<text x="${(x + (right ? 2.2 : -2.2) * u).toFixed(2)}" y="${(y - 1.6 * u).toFixed(2)}" font-size="${(3.4 * u).toFixed(2)}" text-anchor="${right ? 'start' : 'end'}">${esc(name)}</text>`);
        }
        const dots = P.map(([x, y], i) => {
            const last = i === P.length - 1;
            return (last ? `<circle class="pulse" cx="${x.toFixed(2)}" cy="${y.toFixed(2)}" r="${(2.4 * u).toFixed(2)}"/>` : '')
                + `<circle class="${last ? 'now' : 'dot'}" cx="${x.toFixed(2)}" cy="${y.toFixed(2)}" r="${((last ? 1.6 : 1.1) * u).toFixed(2)}"/>`;
        }).join('') + labels.join('');
        const landPath = land ? `<path class="land" d="${land}"/>` + (wrap ? `<path class="land" d="${land}" transform="translate(1000 0)"/>` : '') : '';
        el.innerHTML = `<svg viewBox="${vx.toFixed(2)} ${vy.toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)}" preserveAspectRatio="xMidYMid slice" role="img" aria-label="여행 경로 지도">
            <rect class="sea" x="${vx - w}" y="${vy - h}" width="${w * 3}" height="${h * 3}"/>${landPath}
            ${route ? `<path class="route" d="${route}" style="stroke-width:${(0.55 * u).toFixed(2)};stroke-dasharray:${(1.6 * u).toFixed(2)} ${(1.2 * u).toFixed(2)}"/>` : ''}${dots}</svg>
            <span class="ib-trip-now">📍 지금 ${esc(String(pts[pts.length - 1].place || '').split(',')[0] || '여기')}</span>`;
        el.hidden = false;
    }
    // 에디터 미리보기·예시: 샘플로 채움
    function fillTripSample(root) {
        root.querySelectorAll('[data-ib-trip]').forEach(box => {
            const feed = box.querySelector('[data-trip-feed]');
            if (!feed || feed.dataset.filled) return;
            const posts = tripSamplePosts();
            feed.dataset.filled = '1';
            feed.innerHTML = tripFeedHtml(posts, feed.dataset.order, true);
            drawTripMap(box.querySelector('[data-trip-map]'), posts);
        });
    }
    // 공개 청첩장: 서버에서 실제 소식을 받아 채우고, 1분마다 새 소식 확인 (화면이 보일 때만)
    function initTrip(root) {
        const boxes = root.querySelectorAll('[data-ib-trip]');
        if (!boxes.length) return;
        if (global.INVITE_OFFLINE) { boxes.forEach(box => { const col = box.closest('.col'); if (col) col.style.display = 'none'; }); return; } // 내려받은 파일에서는 숨김 (서버에서 받아와야 해서)
        if (!global.INVITE_SLUG) { fillTripSample(root); return; }
        const url = global.INVITE_PREVIEW_T ? '/invite/trip_feed.php?t=' + encodeURIComponent(global.INVITE_PREVIEW_T) : '/invite/trip_feed.php?s=' + encodeURIComponent(global.INVITE_SLUG);
        let last = '';
        const load = () => fetch(url, { cache: 'no-store' }).then(r => r.ok ? r.json() : null).then(j => {
            boxes.forEach(box => {
                const col = box.closest('.col') || box;
                if (!j || !j.ok || !j.started) { col.style.display = 'none'; return; } // 아직 보일 때가 아님
                col.style.display = '';
                const posts = (j.posts || []).map(p => Object.assign({}, p, { pending: p.visible_at && p.visible_at > Date.now() }));
                const sig = JSON.stringify(posts.map(p => [p.id, p.pending]));
                if (sig === last) return;
                const feed = box.querySelector('[data-trip-feed]');
                feed.innerHTML = tripFeedHtml(posts, feed.dataset.order, false);
                drawTripMap(box.querySelector('[data-trip-map]'), posts);
            });
            if (j && j.ok) last = JSON.stringify((j.posts || []).map(p => [p.id, p.visible_at && p.visible_at > Date.now()]));
        }).catch(() => {});
        boxes.forEach(box => { const col = box.closest('.col'); if (col) col.style.display = 'none'; }); // 받아오기 전엔 숨김
        load();
        setInterval(() => { if (!document.hidden) load(); }, 60000);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    }

    // ---------- 행운의 추첨 (식장에서 하객 대상 추첨 - lottery.php) ----------
    // 참여 방식 3가지: 현장 코드(사회자가 알려준 4자리) / 게스트스냅에 사진 올린 하객 / 누구나(예식 시간 동안)
    // 추첨 진행은 내 청첩장 → "🎁 추첨 진행"(lottery_manage.php). 당첨되면 청첩장을 열어 둔 하객 화면에 "당첨!"이 크게 뜬다.
    BLOCKS.lottery = {
        label: '행운의 추첨', color: '#D35400',
        defaults: { title: '행운의 추첨 🎁', desc: '예식 중에 추첨해서 작은 선물을 드려요!\n청첩장을 열어 두시면 당첨 소식이 바로 떠요.', method: 'code', timeLimit: true, prizes: '' },
        editor: [
            { key: 'title', label: '제목', type: 'text' },
            { key: 'desc', label: '안내 문구', type: 'textarea', rows: 3 },
            { key: 'method', label: '참여 방식', type: 'choice', options: [['code', '현장 코드'], ['snap', '게스트스냅 참여자'], ['open', '누구나']],
              hint: '현장 코드: 사회자가 알려주는 4자리 코드를 넣은 하객만 (식장에 온 분만 알아요) · 게스트스냅: 사진을 올린 하객이 자동 응모 · 누구나: 응모 버튼만 누르면 돼요.' },
            { key: 'timeLimit', label: '', type: 'check', checkLabel: '예식 시간에만 응모 받기 (예식 1시간 전 ~ 4시간 뒤)', hint: '현장 코드·누구나 방식에 적용돼요. 진행 화면에서 지금 열기/마감으로 바로 바꿀 수도 있어요.' },
            { key: 'prizes', label: '경품 안내 (한 줄에 하나)', type: 'textarea', rows: 3, placeholder: '1등 커피 쿠폰 (3명)\n2등 디저트 세트 (5명)' }
        ],
        render(f) {
            const prizes = String(f.prizes || '').split('\n').map(x => x.trim()).filter(Boolean);
            return `<div class="ib-block ib-lot" data-ib-lot data-method="${esc(f.method || 'code')}">${sectionTitle(f.title, f)}
                ${f.desc ? `<p class="ib-lot-desc">${nl2br(f.desc)}</p>` : ''}
                ${prizes.length ? `<ul class="ib-lot-prizes">${prizes.map(p => `<li>🎁 ${esc(p)}</li>`).join('')}</ul>` : ''}
                <div class="ib-lot-body" data-lot-body>${lotteryBodyHtml(f.method || 'code', { phase: 'open', entries: 0, rounds: [] }, true)}</div></div>`;
        }
    };
    function lotteryBodyHtml(method, st, sample) {
        const dis = sample ? ' disabled' : '';
        const fmt = ms => { const d = new Date(ms); return `${d.getMonth() + 1}월 ${d.getDate()}일 ${d.getHours() < 12 ? '오전' : '오후'} ${(d.getHours() % 12) || 12}시${d.getMinutes() ? ' ' + d.getMinutes() + '분' : ''}`; };
        let main;
        if (st.me) {
            main = st.me.won
                ? `<div class="ib-lot-me won">🎉 <b>당첨!</b> ${esc(st.me.prize || '')}<span>응모 번호 <b>${esc(st.me.no)}</b> · 이 화면을 보여주세요</span></div>`
                : `<div class="ib-lot-me">✅ 응모 완료 · 응모 번호 <b>${esc(st.me.no)}</b><span>${st.phase === 'closed' ? '추첨 결과를 기다려 주세요' : '추첨하면 이 화면에 바로 알려드려요'}</span></div>`;
        } else if (st.phase === 'before') {
            main = `<p class="ib-lot-note">${st.from ? fmt(st.from) + '부터 응모할 수 있어요' : '예식 날 응모할 수 있어요'}</p>`;
        } else if (st.phase === 'closed') {
            main = `<p class="ib-lot-note">응모가 마감됐어요</p>`;
        } else if (method === 'snap') {
            const slug = global.INVITE_SLUG;
            main = `<p class="ib-lot-note">게스트스냅에 식장 사진을 올리면 <b>자동으로 응모</b>돼요!</p>
                ${slug ? `<a class="ib-lot-btn" href="/invite/snap.php?s=${encodeURIComponent(slug)}" data-ib-snap="${esc(slug)}" target="_blank" rel="noopener">📷 사진 올리고 응모하기</a>` : `<span class="ib-lot-btn is-disabled">📷 사진 올리고 응모하기</span>`}`;
        } else {
            main = `<form class="ib-lot-form" data-lot-form>
                <input name="name" maxlength="20" placeholder="이름" autocomplete="name"${dis} required>
                ${method === 'code' ? `<input name="code" inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="현장 코드 4자리"${dis} required>` : ''}
                <button type="submit" class="ib-lot-btn"${dis}>응모하기</button></form>
                ${method === 'code' ? '<p class="ib-lot-hint">현장 코드는 예식 중에 사회자가 알려드려요</p>' : ''}`;
        }
        const rounds = (st.rounds || []).slice().reverse().map(r => `<div class="ib-lot-round"><b>🎁 ${esc(r.prize)}</b>
            <span>${r.winners.map(w => `${esc(w.name)}<small>#${esc(w.no)}</small>`).join(' · ')}</span></div>`).join('');
        return main + (st.entries ? `<p class="ib-lot-count">지금까지 <b>${st.entries}</b>명 응모</p>` : '') + (rounds ? `<div class="ib-lot-rounds"><p>당첨자</p>${rounds}</div>` : '')
            + (sample ? '<p class="ib-lot-sample">예시 모양이에요. 발행된 청첩장에서 응모할 수 있어요.</p>' : '');
    }
    function lotteryWinFx(st) {
        if (!st.me || !st.me.won) return;
        const key = 'ib_lot_win_' + global.INVITE_SLUG + '_' + st.me.won;
        if (storeGet(key)) return;
        storeSet(key, '1');
        const el = document.createElement('div');
        el.className = 'ib-lot-win';
        let bits = '';
        for (let i = 0; i < 40; i++) bits += `<i style="left:${Math.random() * 100}%;animation-delay:${(Math.random() * 1.2).toFixed(2)}s;background:${['#F2B8C6', '#F5C542', '#8FC1E3', '#B5D99C', '#E28FA0'][i % 5]}"></i>`;
        el.innerHTML = `<div class="ib-lot-confetti">${bits}</div><div class="ib-lot-win-box" role="alertdialog" aria-label="당첨">
            <div class="ib-lot-win-ic">🎉</div><h3>축하해요, 당첨이에요!</h3><p class="ib-lot-win-prize">${esc(st.me.prize || '선물')}</p>
            <p class="ib-lot-win-no">응모 번호 <b>${esc(st.me.no)}</b><br><small>${esc(st.me.name)}님 · 이 화면을 신랑신부측에 보여주세요</small></p>
            <button type="button">확인</button></div>`;
        el.querySelector('button').onclick = () => el.remove();
        document.body.appendChild(el);
        try { navigator.vibrate && navigator.vibrate([250, 120, 250, 120, 400]); } catch (e) {}
    }
    function initLottery(root) {
        const boxes = root.querySelectorAll('[data-ib-lot]');
        if (!boxes.length) return;
        if (global.INVITE_OFFLINE || !global.INVITE_SLUG) { if (global.INVITE_OFFLINE) boxes.forEach(b => { const c = b.closest('.col'); if (c) c.style.display = 'none'; }); return; }
        const slug = global.INVITE_SLUG, base = '/invite/lottery.php';
        let lastRound = null, timer = 0, st = null;
        const paint = () => boxes.forEach(box => { const body = box.querySelector('[data-lot-body]'); if (body) body.innerHTML = lotteryBodyHtml(st.method, st, false); });
        function schedule() {
            clearTimeout(timer);
            if (!st) return;
            const live = st.phase === 'open' || (st.to && Date.now() < st.to + 6 * 3600000) || (st.phase === 'before' && st.from && st.from - Date.now() < 3600000);
            timer = setTimeout(load, document.hidden ? 60000 : live ? 8000 : 60000);
        }
        function apply(j) {
            if (!j || !j.ok) return;
            const prevRound = lastRound;
            st = j; lastRound = j.round; paint();
            if (j.me && j.me.won) lotteryWinFx(j);
            else if (prevRound !== null && j.round > prevRound && j.rounds.length) {
                const r = j.rounds[j.rounds.length - 1];
                toast(`🎁 ${r.prize} 당첨자: ${r.winners.slice(0, 3).map(w => w.name).join(', ')}${r.winners.length > 3 ? ` 외 ${r.winners.length - 3}명` : ''}`);
            }
        }
        function load() { fetch(`${base}?s=${encodeURIComponent(slug)}&a=status`, { credentials: 'same-origin', cache: 'no-store' }).then(r => r.json()).then(apply).catch(() => {}).finally(schedule); }
        root.addEventListener('submit', e => {
            const form = e.target.closest && e.target.closest('[data-lot-form]');
            if (!form) return;
            e.preventDefault();
            const btn = form.querySelector('button'); btn.disabled = true;
            const body = new URLSearchParams({ s: slug, a: 'enter', csrf_token: global.INVITE_CSRF || '', name: form.name.value, code: form.code ? form.code.value : '' });
            fetch(base, { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json()).then(j => {
                btn.disabled = false;
                if (!j.ok) { toast(j.error || '응모하지 못했어요'); return; }
                apply(j); toast('응모 완료! 추첨하면 바로 알려드려요');
            }).catch(() => { btn.disabled = false; toast('응모하지 못했어요. 다시 눌러주세요'); });
        });
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        load();
    }

    // 기존 섹션 목록 뒤에 붙일 순서 (에디터 목록/기본 템플릿에서 이 순서로 나온다)
    const ORDER = ['profile', 'contact', 'letter', 'video', 'transport', 'notice', 'together', 'ending', 'guestsnap', 'trip', 'lottery'];


    // ---------- 혼주 소개 (가족 정보) ----------
    // "김OO · 박OO 의 아들 민준" 형식. 고인은 국화꽃(🌼) 또는 한자(故)로 표시.
    BLOCKS.family = {
        label: '혼주 소개', color: '#6D597A',
        defaults: { groomName: '민준', groomFather: '', groomFatherDeceased: false, groomMother: '', groomMotherDeceased: false, groomRelation: '아들',
                    brideName: '서연', brideFather: '', brideFatherDeceased: false, brideMother: '', brideMotherDeceased: false, brideRelation: '딸',
                    deceasedMark: '', order: '', align: 'center' },
        editor: [
            // 신랑·신부 이름은 에디터 맨 위 "예식 정보"에서 한 번만 입력 (여기로 자동으로 들어옴)
            { group: '신랑측' },
            { key: 'groomFather', label: '신랑 아버지', type: 'text' },
            { key: 'groomMother', label: '신랑 어머니', type: 'text' },
            { key: 'groomRelation', label: '관계명', type: 'text', placeholder: '아들 / 장남 / 차남' },
            { group: '신부측' },
            { key: 'brideFather', label: '신부 아버지', type: 'text' },
            { key: 'brideMother', label: '신부 어머니', type: 'text' },
            { key: 'brideRelation', label: '관계명', type: 'text', placeholder: '딸 / 장녀 / 차녀' },
            { group: '표시 방법' },
            { key: 'deceasedMark', label: '고인 표시', type: 'deceased' }, // 고인인 분 이름 칩 켜기 + 국화꽃/故 (한 칸)
            { key: 'order', label: '처음 놓일 순서', type: 'choice', options: [['', '공통 설정'], ['groom-first', '신랑 먼저'], ['bride-first', '신부 먼저']],
              hint: '두 줄은 미리보기에서 끌어서 자유롭게 옮기고, 눌러서 글자 크기·정렬·회전을 바꿔요.' }
        ],
        render(f) {
            const markType = f.deceasedMark || L.deceasedMark;
            const mark = markType === 'hanja' ? '<span class="ib-deceased">故</span>' : '<span class="ib-deceased ib-flower" aria-label="고인">🌼</span>';
            const person = (name, dead) => name ? `${dead ? mark : ''}${esc(name)}` : '';
            const line = (father, fd, mother, md, rel, child) => {
                const parents = [person(father, fd), person(mother, md)].filter(Boolean).join(' · ');
                if (!parents && !child) return '';
                return `<p class="ib-family-line">${parents ? `<span class="ib-family-parents">${parents}</span><span class="ib-family-rel">의 ${esc(rel || '')}</span>` : ''}<strong>${esc(child)}</strong></p>`;
            // (신랑/신부 이름 앞 호칭은 표시하지 않음 - "OOO의 아들 민준" 형식)
            };
            const g = line(f.groomFather, f.groomFatherDeceased, f.groomMother, f.groomMotherDeceased, f.groomRelation, f.groomName);
            const b = line(f.brideFather, f.brideFatherDeceased, f.brideMother, f.brideMotherDeceased, f.brideRelation, f.brideName);
            if (!g && !b) return `<div class="ib-block ib-family">${emptyHint('부모님 성함을 입력해주세요')}</div>`;
            const bf = (f.order || L.order) === 'bride-first';
            // 두 줄을 자유 배치 (끌어서 위치, 눌러서 크기·정렬) - 예전 "정렬" 옵션 대신
            return `<div class="ib-block ib-family">${freeCanvas(f, 'family', 110, [
                ['groomLine', g, { x: 50, y: bf ? 70 : 30, fs: 14.5 }],
                ['brideLine', b, { x: 50, y: bf ? 30 : 70, fs: 14.5 }]
            ])}</div>`;
        }
    };
    ORDER.unshift('family');

    // ---------- 공유하기 (섹션 자리의 공유 버튼 + 화면 구석에 떠 있는 공유 버튼) ----------
    // 섹션 자리에는 "카카오톡 공유 / 링크 복사" 버튼이 그려지고, 떠 있는 버튼 설정도 이 섹션이 갖고 있다.
    // 떠 있는 버튼은 페이지(body)에 붙으므로 render가 아니라 initExtras → initShareFab 이 만든다.
    // 이 섹션을 끄면 공유 버튼이 전부(떠 있는 버튼 포함) 사라진다.
    // 카카오톡 미리보기 모양(썸네일·제목)은 화면 설정의 "카카오톡·링크 미리보기"(design.share)에 따로 있다.
    const SHARE_DEFAULTS = { title: '청첩장 공유하기', bar: true, kakao: true, link: true, native: true,
        fab: true, fabPos: 'right', fabShow: 'smart', fabHero: true, fabStyle: 'dark', fabSize: 'normal', fabOpacity: 72, fabLabel: '' };
    BLOCKS.share = {
        label: '공유하기', color: '#E67E22', defaultEnabled: true,
        defaults: Object.assign({}, SHARE_DEFAULTS, { bar: false }), // 새로 넣는 공유하기: 섹션 자리 버튼은 꺼짐 (떠 있는 공유 버튼만)
        editor: [
            { group: '섹션 자리의 공유 버튼' },
            { key: 'bar', label: '', type: 'check', checkLabel: '이 섹션 자리에 공유 버튼 보이기' },
            { key: 'title', label: '제목', type: 'text', showIf: 'bar' },
            { group: '공유 방법 (섹션 버튼·떠 있는 버튼 공통)' },
            { key: 'kakao', label: '', type: 'check', checkLabel: '카카오톡 공유' },
            { key: 'link', label: '', type: 'check', checkLabel: '링크 복사' },
            { key: 'native', label: '', type: 'check', checkLabel: '다른 앱으로 공유 (휴대폰 기본 공유창 - 문자·인스타 등)',
              hint: '휴대폰 브라우저에서만 나타나요. PC에서는 자동으로 숨겨져요.' },
            { group: '떠 있는 공유 버튼' },
            { key: 'fab', label: '', type: 'check', checkLabel: '화면 구석에 떠 있는 공유 버튼 사용',
              hint: '미리보기 구석에 실제 모양대로 보여요(눌러보면 메뉴도 펼쳐져요). 실제 청첩장에서는 섹션 자리의 공유 버튼이 화면에 보이는 동안 겹치지 않게 자동으로 숨어요.' },
            { key: 'fabPos', label: '위치', type: 'choice', showIf: 'fab', options: [['right', '오른쪽 아래'], ['left', '왼쪽 아래']] },
            { key: 'fabShow', label: '보이는 방식', type: 'choice', showIf: 'fab', options: [['smart', '스크롤 멈추면 나타남'], ['always', '항상 보임']],
              hint: '"스크롤 멈추면 나타남"은 아래로 내리는 동안 숨었다가, 멈추거나 위로 올리면 다시 나타나요.' },
            { key: 'fabHero', label: '', type: 'check', showIf: 'fab', checkLabel: '첫 화면(대표사진)에서는 숨기기' },
            { key: 'fabStyle', label: '색', type: 'choice', showIf: 'fab', options: [['dark', '어두운 반투명'], ['light', '밝은 반투명'], ['accent', '포인트색']] },
            { key: 'fabSize', label: '크기', type: 'choice', showIf: 'fab', options: [['small', '작게'], ['normal', '보통'], ['large', '크게']] },
            { key: 'fabOpacity', label: '진하기', type: 'range', showIf: 'fab', min: 30, max: 100, unit: '%' },
            { key: 'fabLabel', label: '버튼 글자 (비우면 아이콘만)', type: 'text', showIf: 'fab', placeholder: '예: 공유' }
        ],
        render(f) {
            const o = Object.assign({}, SHARE_DEFAULTS, f);
            const items = shareItems(o, true);
            if (o.bar === false || !items.length) {
                return hideCol; // 섹션 자리 버튼을 끄면 섹션 칸 자체를 숨김 (에디터 미리보기도 동일, 떠 있는 버튼 설정은 섹션 목록의 옵션에서)
            }
            return `<div class="ib-share ib-share-block">${o.title ? `<p class="ib-share-title">${esc(o.title)}</p>` : ''}
                <div class="ib-share-row">${items.map(([type, icon, label]) =>
                    `<button type="button" class="ib-share-btn${type === 'kakao' ? ' ib-kakao' : ''}" data-ib-share="${type}"><span>${icon}</span>${esc(label)}</button>`).join('')}</div></div>`;
        }
    };
    ORDER.push('share');

    // =====================================================================
    // 4단계: 참석 의사 전달(RSVP) / 방명록 / D-DAY 하객 안내
    // 서버: rsvp.php, guestbook.php (신랑신부 관리 화면은 guest_manage.php)
    // 공개코드·CSRF·예식일은 invite_view.php가 window.INVITE_SLUG / INVITE_CSRF / INVITE_WEDDING_DATE 로 넣어준다.
    // =====================================================================
    const API = '/invite/';
    const hideCol = '<div data-ib-hidecol hidden></div>'; // 공개페이지에서 이 섹션 칸 전체를 숨기라는 표시 (initExtras가 처리)
    function badge(text) { return isEditor() ? `<span class="ib-ed-badge">${esc(text)}</span>` : ''; }
    function dataAttr(obj) { return esc(JSON.stringify(obj)); }

    // ---------- 참석 의사 전달 (RSVP) ----------
    BLOCKS.rsvp = {
        label: '참석 의사 전달', color: '#16A085',
        defaults: { title: '참석 의사 전달', desc: '축하의 마음으로 참석해주시는 분들을 위해\n정성껏 자리를 준비하고자 합니다.\n참석 여부를 알려주시면 감사하겠습니다.',
            buttonLabel: '참석 의사 전달하기', doneLabel: '전달한 내용 수정하기', deadline: '',
            askHeadcount: true, askMeal: true, askPhone: false, askMemo: true, popup: false, popupAt: 'scroll', btnStyle: 'theme' },
        editor: [
            { key: 'title', label: '제목', type: 'text' },
            { key: 'desc', label: '안내 문구', type: 'textarea', rows: 4 },
            { key: 'buttonLabel', label: '버튼 문구', type: 'text' },
            { key: 'btnStyle', label: '버튼 모양', type: 'choice', options: [['theme', '테마 모양'], ['basic', '기본']], hint: '테마 모양은 디자인마다 달라요 (봉투 · 클래퍼보드 · 도장 …).' },
            { key: 'doneLabel', label: '이미 보낸 하객에게 보일 버튼 문구', type: 'text' },
            { key: 'deadline', label: '마감일 (비우면 계속 받음)', type: 'date', hint: '마감일 밤 12시까지 받고, 그 뒤로는 버튼이 "마감되었습니다"로 바뀌어요.' },
            { group: '받을 항목' },
            { key: 'askHeadcount', label: '', type: 'check', checkLabel: '참석 인원 (본인 포함)' },
            { key: 'askMeal', label: '', type: 'check', checkLabel: '식사 여부' },
            { key: 'askPhone', label: '', type: 'check', checkLabel: '연락처 (선택 입력)', hint: '연락처는 암호화해서 저장하고 신랑·신부 관리 화면에서만 보여요. 예식일 뒤 일정 기간이 지나면 명단과 함께 자동 삭제돼요.' },
            { key: 'askMemo', label: '', type: 'check', checkLabel: '전하는 말 (선택 입력)' },
            { group: '팝업' },
            { key: 'popup', label: '', type: 'check', checkLabel: '참석 여부를 묻는 팝업 띄우기', hint: '이미 보낸 하객, 마감일이 지난 뒤, "오늘 하루 보지 않기"를 누른 하객에게는 안 떠요.' },
            { key: 'popupAt', label: '팝업이 뜨는 때', type: 'choice', options: [['scroll', '메인 화면을 지나 내려가면'], ['open', '청첩장을 열자마자']], showIf: 'popup' }
        ],
        render(f) {
            const o = Object.assign({}, BLOCKS.rsvp.defaults, f);
            const closed = /^\d{4}-\d{2}-\d{2}$/.test(o.deadline || '') && seoulToday() > o.deadline;
            const cfg = { askHeadcount: o.askHeadcount !== false, askMeal: o.askMeal !== false, askPhone: !!o.askPhone, askMemo: o.askMemo !== false, doneLabel: o.doneLabel };
            const dl = o.deadline ? `<p class="ib-rsvp-dl">${esc(fmtKDate(o.deadline))}까지 알려주세요</p>` : '';
            return `<div class="ib-block ib-rsvp${o.btnStyle === 'basic' ? '' : ' ib-rs-theme'}">${sectionTitle(o.title, o)}<p class="ib-rsvp-desc">${nl2br(o.desc)}</p>${dl}
                ${closed && !isEditor() ? '<span class="ib-rsvp-btn is-closed">참석 여부 전달이 마감되었습니다</span>'
                    : `<button type="button" class="ib-rsvp-btn" data-ib-rsvp="${dataAttr(cfg)}">${esc(o.buttonLabel || '참석 의사 전달하기')}</button>`}
                ${closed && isEditor() ? badge('마감일이 지나서 하객에게는 "마감" 으로 보여요') : ''}</div>`;
        }
    };

    // ---------- 방명록 ----------
    const GB_SAMPLE = [
        { name: '지은', message: '두 분 결혼 정말 축하해요! 💕 늘 지금처럼 행복하게 웃으며 살기를 🙌', date: '2026.10.02',
          html: '두 분 결혼 <span style="color:#C0392B;font-weight:bold">정말 축하해요!</span> 💕<br>늘 지금처럼 <span style="background-color:#FFF3A3">행복하게</span> 웃으며 살기를 🙌' },
        { name: '민호 삼촌', message: '예쁜 부부가 되어라. 결혼 축하한다!', date: '2026.10.01' },
        { name: '서진', message: '드디어!! 축하축하 💐 예식날 꼭 갈게요', date: '2026.09.30' }
    ];
    BLOCKS.guestbook = {
        label: '방명록', color: '#8E44AD',
        defaults: { title: '방명록', desc: '따뜻한 축하의 한마디를 남겨주세요.', style: 'card', pageSize: 5, allowWrite: true, richText: true, writeLabel: '방명록 작성하기' },
        editor: [
            { key: 'title', label: '제목', type: 'text' },
            { key: 'desc', label: '안내 문구', type: 'textarea', rows: 2 },
            { key: 'style', label: '모양', type: 'choice', options: [['theme', '테마 모양'], ['card', '카드'], ['line', '줄글']] },
            { key: 'pageSize', label: '한 번에 보일 글 수', type: 'choice', options: [[3, '3개'], [5, '5개'], [10, '10개']] },
            { key: 'writeLabel', label: '작성 버튼 문구', type: 'text' },
            { key: 'allowWrite', label: '', type: 'check', checkLabel: '하객이 새 글을 쓸 수 있게 하기', hint: '끄면 지금까지 받은 글만 보이고 작성 버튼이 사라져요. 글 삭제는 내 청첩장 관리 → 방명록 관리에서 할 수 있어요.' },
            { key: 'richText', label: '', type: 'check', checkLabel: '하객 글꾸미기 허용 (굵게·색·형광펜·글꼴·크기·정렬)', hint: '끄면 작성칸에 이모티콘만 남아요. 이미 꾸며서 남긴 글은 꾸민 모양 그대로 보여요.' }
        ],
        render(f) {
            const o = Object.assign({}, BLOCKS.guestbook.defaults, f);
            const cfg = { style: o.style, allowWrite: o.allowWrite !== false, richText: o.richText !== false };
            const list = isEditor() ? gbEntriesHtml(GB_SAMPLE.slice(0, Number(o.pageSize) || 5), o.style) : '<p class="ib-gb-empty">불러오는 중…</p>';
            return `<div class="ib-block ib-gb ib-gb-${o.style === 'line' ? 'line' : o.style === 'theme' ? 'card ib-gb-theme' : 'card'}">${sectionTitle(o.title, o)}${o.desc ? `<p class="ib-gb-desc">${nl2br(o.desc)}</p>` : ''}
                <div class="ib-gb-list" data-ib-gb="${dataAttr(cfg)}">${list}</div>
                <button type="button" class="ib-gb-more" hidden>더 보기</button>
                ${o.allowWrite !== false ? `<button type="button" class="ib-gb-write" data-ib-gb-write>${esc(o.writeLabel || '방명록 작성하기')}</button>` : ''}
                ${isEditor() ? badge('미리보기는 예시 글이에요 · 실제 글은 발행 후 하객이 남겨요') : ''}</div>`;
        }
    };
    function gbEntriesHtml(list, style) {
        if (!list.length) return '<p class="ib-gb-empty">아직 남겨진 글이 없어요. 첫 축하를 남겨주세요!</p>';
        return list.map(e => `<div class="ib-gb-item" data-id="${Number(e.id) || 0}">
                <div class="ib-gb-head"><b>${esc(e.name)}</b><span>${esc(e.date)}</span>
                <button type="button" class="ib-gb-del" data-ib-gb-del="${Number(e.id) || 0}" data-mine="${e.mine ? 1 : 0}" aria-label="삭제">✕</button></div>
                <div class="ib-gb-msg">${e.html ? rtSanitize(e.html) : nl2br(e.message)}</div></div>`).join('');
    }

    // ---------- D-DAY 하객 안내 (예식 당일 안내) ----------
    BLOCKS.dayinfo = {
        label: 'D-DAY 하객 안내', color: '#C0392B',
        defaults: { title: '오늘 오시는 하객분들께', desc: '귀한 걸음 해주셔서 감사합니다.\n아래 내용을 참고해 주세요.', show: 'dday', popup: true,
            items: [
                { id: 'd1', icon: '🚗', title: '주차', text: '건물 지하 주차장을 이용해 주세요. (2시간 무료)', url: '' },
                { id: 'd2', icon: '🍽️', title: '식사', text: '예식 후 3층 연회장에서 뷔페가 준비되어 있습니다.', url: '' },
                { id: 'd3', icon: '📷', title: '사진', text: '오늘 찍은 사진을 게스트스냅으로 보내주세요!', url: '' }
            ] },
        editor: [
            { key: 'title', label: '제목', type: 'text' },
            { key: 'desc', label: '안내 문구', type: 'textarea', rows: 2 },
            { key: 'show', label: '섹션이 보이는 때', type: 'choice', options: [['dday', '예식 당일에만'], ['always', '항상']],
              hint: '"예식 당일에만"이면 평소엔 섹션이 숨어 있다가 예식일(한국 시간)에만 나타나요. 미리 확인하려면 청첩장 주소 뒤에 ?ddaytest=1 을 붙여 열어보세요.' },
            { key: 'popup', label: '', type: 'check', checkLabel: '예식 당일 청첩장을 열면 안내 팝업 띄우기' },
            { key: 'items', label: '안내 항목', type: 'items', addLabel: '항목 추가', item: { icon: '📌', title: '', text: '', url: '' },
              fields: [
                  { key: 'icon', label: '아이콘 (이모지)', type: 'text' },
                  { key: 'title', label: '제목', type: 'text' },
                  { key: 'text', label: '내용', type: 'textarea', rows: 2 },
                  { key: 'url', label: '연결 주소 (선택 - 지도·주차 안내 등)', type: 'url' }
              ] }
        ],
        render(f) {
            const o = Object.assign({}, BLOCKS.dayinfo.defaults, f);
            if (!isEditor() && o.show !== 'always' && !isWeddingDay()) return hideCol;
            return `<div class="ib-block ib-day">${o.show !== 'always' ? badge('예식 당일에만 하객에게 보여요') : ''}${sectionTitle(o.title, o)}
                ${o.desc ? `<p class="ib-day-desc">${nl2br(o.desc)}</p>` : ''}${dayItemsHtml(o.items)}</div>`;
        }
    };
    function dayItemsHtml(items) {
        return `<div class="ib-day-list">${(items || []).filter(it => it.title || it.text).map(it => `<div class="ib-day-item">
                <span class="ib-day-ic">${esc(it.icon || '📌')}</span>
                <div><b>${esc(it.title)}</b><p>${nl2br(it.text)}</p>
                ${linkHref(it.url) ? `<a href="${esc(linkHref(it.url))}" target="_blank" rel="noopener">자세히 보기 →</a>` : ''}</div></div>`).join('')}</div>`;
    }

    ORDER.splice(ORDER.indexOf('share'), 0, 'rsvp', 'guestbook', 'dayinfo'); // 에디터 섹션 목록에서 공유하기 바로 앞

    // ---------- 예식 후 감사 인사 (예전엔 화면 설정 → "예식 후 감사 인사"였던 것을 섹션으로) ----------
    //  보이는 때: 예식 다음 날부터(기본) / 예식 당일부터 / 항상. 때가 되기 전엔 하객에게 섹션이 숨는다 (에디터에선 늘 보임)
    //  보일 때 대표 사진 바로 아래로 올리기 · 예식 후 참석 의사·D-DAY 안내·캘린더 버튼 숨기기 · 결혼기념일 띠 → initAfterWedding이 처리
    //  미리 보기: 청첩장 주소 뒤에 ?thankstest=1
    const TY_RATIOS = [['4/3', '가로'], ['1/1', '정사각'], ['3/4', '세로'], ['16/9', '넓게']];
    const TY_TEXT = '바쁘신 중에도 귀한 걸음 해주셔서 진심으로 감사합니다.\n보내주신 축하와 마음, 오래 간직하며 예쁘게 잘 살겠습니다.';
    BLOCKS.thanks = {
        label: '예식 후 감사 인사', color: '#C0727E',
        defaults: { show: 'after', toTop: true, hideRsvp: true, anniv: true, style: 'card', photo: '', phW: 90, phRatio: '4/3', phH: 380, eyebrow: 'THANK YOU',
            title: '함께해 주셔서 감사합니다', text: TY_TEXT, sign: 'auto', signText: '', btnLabel: '', btnUrl: '' },
        editor: [
            { key: 'show', label: '하객에게 보이는 때', type: 'choice', options: [['after', '예식 다음 날부터'], ['day', '예식 당일부터'], ['always', '항상']],
              hint: '정한 때가 되기 전엔 하객에게 이 섹션이 안 보여요. 답례 문자에 같은 청첩장 링크를 보내면 돼요. 미리 확인하려면 주소 뒤에 ?thankstest=1 을 붙여 열어보세요.' },
            { key: 'toTop', label: '', type: 'check', checkLabel: '보일 때 맨 위로', hint: '하객에게 보일 때 대표 사진 바로 아래로 올라가요.' },
            { key: 'hideRsvp', label: '', type: 'check', checkLabel: '예식 후 참석·안내 숨김', hint: '예식 다음 날부터 참석 의사·D-DAY 하객 안내·캘린더 버튼을 숨겨요.' },
            { key: 'anniv', label: '', type: 'check', checkLabel: '결혼기념일 띠', hint: '매년 결혼기념일에 "결혼 N주년" 띠가 맨 위에 떠요.' },
            { group: '모양' },
            { key: 'style', label: '모양', type: 'choice', options: [['card', '카드'], ['letter', '편지지'], ['photo', '사진 위 글자'], ['plain', '깔끔하게']] },
            { key: 'photo', label: '사진 (선택 - 예식 사진·감사 사진)', type: 'image', focus: true },
            { key: 'phW', label: '사진 크기 (칸 폭에서)', type: 'range', min: 40, max: 100, unit: '%', showIf: 'photo&style!=photo' },
            { key: 'phRatio', label: '사진 모양', type: 'choice', options: TY_RATIOS, showIf: 'photo&style!=photo' },
            { key: 'phH', label: '사진 높이', type: 'range', min: 280, max: 720, unit: 'px', showIf: 'photo&style=photo' },
            { group: '문구' },
            { key: 'eyebrow', label: '작은 머리글', type: 'text', placeholder: 'THANK YOU' },
            { key: 'title', label: '제목', type: 'text' },
            { key: 'text', label: '인사말', type: 'textarea', rows: 4 },
            { key: 'sign', label: '서명', type: 'choice', options: [['auto', '신랑·신부 이름'], ['custom', '직접 쓰기'], ['none', '없음']] },
            { key: 'signText', label: '서명 문구 (직접 쓰기일 때)', type: 'text', placeholder: '민준 · 서연 올림' },
            { group: '버튼 (선택)' },
            { key: 'btnLabel', label: '버튼 이름', type: 'text', placeholder: '예식 사진 보러 가기' },
            { key: 'btnUrl', label: '연결 주소', type: 'url', hint: '본식 사진 앨범·감사 영상 등. 이름과 주소를 둘 다 적으면 버튼이 나와요.' }
        ],
        render(f) {
            const o = Object.assign({}, BLOCKS.thanks.defaults, f);
            if (!isEditor() && !thanksVisible(o.show)) return hideCol;
            const [n1, n2] = coupleNames(CUR_DESIGN || {});
            const sign = o.sign === 'none' ? '' : o.sign === 'custom' ? (o.signText || '') : (n1 && n2 ? `${n1} · ${n2} 드림` : '');
            const st = o.style === 'photo' && !o.photo ? 'card' : (o.style || 'card');
            const href = linkHref(o.btnUrl);
            const when = { after: '예식 다음 날부터 하객에게 보여요', day: '예식 당일부터 하객에게 보여요' }[o.show];
            const ph = o.photo ? esc(imgUrl(o.photo)) : '';
            // 사진 크기·모양 (보일 부분·확대는 다른 사진과 같이 design.imgFocus/imgZoom - <img>라서 applyImgFocus가 맞춤, '사진 위 글자'도 <img>로 깔아서 같이 됨)
            const w = Math.max(40, Math.min(100, Number(o.phW) || 90)), ra = TY_RATIOS.some(x => x[0] === o.phRatio) ? o.phRatio : '4/3', hh = Math.max(280, Math.min(720, Number(o.phH) || 380));
            return `<div class="ib-block ib-ty ib-ty-${esc(st)}"${st === 'photo' ? ` style="min-height:${hh}px"` : ''}>${when ? badge(when) : ''}
                ${ph && st === 'photo' ? `<div class="ib-ty-bgph"><img src="${ph}" alt="" loading="lazy"></div>` : ''}
                ${ph && st !== 'photo' ? `<div class="ib-ty-ph" style="width:${w}%;max-width:none;aspect-ratio:${ra}"><img src="${ph}" alt="" loading="lazy"></div>` : ''}
                <div class="ib-ty-in">${o.eyebrow ? `<span class="ib-ty-eye">${esc(o.eyebrow)}</span>` : ''}
                <h3>${esc(o.title || '함께해 주셔서 감사합니다')}</h3>
                <p>${nl2br(o.text || TY_TEXT)}</p>${sign ? `<span class="ib-ty-sign">${esc(sign)}</span>` : ''}
                ${o.btnLabel && href ? `<a class="ib-ty-btn" href="${esc(href)}" target="_blank" rel="noopener">${esc(o.btnLabel)}</a>` : ''}</div></div>`;
        }
    };
    let CUR_DESIGN = null; // 서명(신랑·신부 이름)을 쓰려고 지금 그리는 청첩장 - 에디터·공개페이지가 setDesign으로 넘김
    function setDesign(d) { CUR_DESIGN = d || null; }
    function designLook() { const d = CUR_DESIGN, g = d && d.sectionLook; return g && THEME_DECOS.includes(g) && !(d.extras && d.extras.look === false) ? g : ''; }
    // ---------- 색 바꾸면 꾸밈도 같이 (화면 설정 extras.colorLink, 기본 켜짐) ----------
    // design.themePal = 디자인을 고를 때의 원래 색 · setColors = 지금 청첩장 색 (색 묶음·포인트 색·바탕색을 바꾼 뒤)
    //  켜짐: 디자인 꾸밈·테마 부품은 원래 --p-* 를 따라감 + 디자인이 정해 둔 섹션 테마(빈티지·밤하늘·안개)는 색을 바꾸면 그 디자인 꾸밈으로(지금 색)
    //        + 따로 고른 디자인 스킨(skinPal)은 고른 뒤 색을 바꾸면 모양은 그대로 지금 색으로 (skinPal.at = 고를 때 색)
    //  꺼짐: 디자인 꾸밈·테마 부품의 포인트 색·선 색은 디자인 원래 색 그대로 (--tp-a/l/o, 글자·바탕은 지금 색 - 안 보이는 글자가 생기지 않게)
    let CUR_COLORS = null;
    function setColors(c) { CUR_COLORS = c && HEX_RE.test(c.bg || '') ? c : null; }
    const colKey = p => p ? [p.bg, p.ink, p.accent].map(x => String(x || '').toUpperCase()).join('|') : '';
    function colorLinkOn() { const d = CUR_DESIGN; return !(d && d.extras && d.extras.colorLink === false); }
    function colorChanged() { const t = CUR_DESIGN && CUR_DESIGN.themePal; return !!(t && CUR_COLORS && HEX_RE.test(t.bg || '') && colKey(t) !== colKey(CUR_COLORS)); }
    function curColorKey() { return colKey(CUR_COLORS); }
    // 원래 포인트 색이 바꾼 바탕 위에서 잘 안 보이면(대비 2.4 아래) 지금 글자색 쪽으로 조금씩 섞어서 보이게 (색 느낌은 남김)
    const rgbOf = h => { let x = String(h || '').replace('#', ''); if (x.length === 3) x = x.replace(/./g, d => d + d); const n = parseInt(x, 16); return [n >> 16 & 255, n >> 8 & 255, n & 255]; };
    const lumOf = c => { const f = v => { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }; return .2126 * f(c[0]) + .7152 * f(c[1]) + .0722 * f(c[2]); };
    function readableOn(a, bg, ink) {
        if (!HEX_RE.test(bg || '') || !HEX_RE.test(ink || '')) return a;
        const A = rgbOf(a), K = rgbOf(ink), lb = lumOf(rgbOf(bg)), cr = c => { const l = lumOf(c); return (Math.max(l, lb) + .05) / (Math.min(l, lb) + .05); };
        if (cr(A) >= 2.4) return a;
        for (let w = .15; w <= .75; w += .1) { const m = A.map((v, i) => Math.round(v * (1 - w) + K[i] * w)); if (cr(m) >= 2.4 || w > .7) return '#' + m.map(v => v.toString(16).padStart(2, '0')).join('').toUpperCase(); }
        return a;
    }
    function applyColorLink(el) {
        if (!el || !el.style) return;
        ['--tp-a', '--tp-l', '--tp-o'].forEach(v => el.style.removeProperty(v));
        if (colorLinkOn() || !colorChanged()) return;
        const t = CUR_DESIGN.themePal;
        if (HEX_RE.test(t.accent || '')) { const a = readableOn(t.accent, CUR_COLORS.bg, CUR_COLORS.ink); el.style.setProperty('--tp-a', a); el.style.setProperty('--tp-o', onAccent(a)); }
        if (HEX_RE.test(t.line || '')) el.style.setProperty('--tp-l', t.line);
    }
    function thanksVisible(show) {
        if (show === 'always') return true;
        if (typeof location !== 'undefined' && /[?&]thankstest=1/.test(location.search)) return true;
        const w = global.INVITE_WEDDING_DATE;
        if (!w || !/^\d{4}-\d{2}-\d{2}$/.test(w)) return false;
        const t = seoulToday();
        return show === 'day' ? t >= w : t > w;
    }
    ORDER.push('thanks');

    // ---- 날짜 도우미 (한국 시간 기준) ----
    function seoulToday() {
        try { return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Seoul', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date()); }
        catch (e) { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
    }
    function fmtKDate(ymd) { const m = String(ymd).match(/^(\d{4})-(\d{2})-(\d{2})$/); return m ? `${+m[2]}월 ${+m[3]}일` : ymd; }
    function isWeddingDay() {
        if (typeof location !== 'undefined' && /[?&]ddaytest=1/.test(location.search)) return true; // 신랑신부 미리 확인용
        const w = global.INVITE_WEDDING_DATE;
        return !!w && w === seoulToday();
    }
    // 켜진 공유 방법 목록 [종류, 아이콘, 이름]. 휴대폰 기본 공유창은 지원하는 브라우저에서만 (에디터 미리보기는 항상 보여줌)
    function shareItems(o, forBar) {
        const out = [];
        if (o.kakao !== false) out.push(['kakao', forBar ? '💬' : 'K', forBar ? '카카오톡 공유' : '카카오톡']);
        if (o.link !== false) out.push(['link', '🔗', '링크 복사']);
        if (o.native !== false && (isEditor() || (typeof navigator !== 'undefined' && navigator.share))) out.push(['native', '⋯', '다른 앱']);
        if (forBar && out.length > 2) out[0][2] = out[0][0] === 'kakao' ? '카카오톡' : out[0][2]; // 버튼 3개면 한 줄에 들어가게 짧게
        return out;
    }

    // ---------- 장식 효과 (떠다니는 하트/벚꽃/눈 등) ----------
    //  type '3d'    : 그림(SVG)이 앞뒤로 뒤집히며 3D로 빙글빙글 돌면서 떨어지거나(fall) 떠오름(rise)
    //  type 'spark' : 반짝임 - 제자리에서 반짝이며 천천히 흘러감 (3D 회전 없음)
    //  type 'bokeh' : 빛망울 - 초점이 나간(f0.8) 연한 아이보리 빛 동그라미가 깊이감 있게 천천히 떠다님
    //  type 'flash' : 플래시 - 하객들이 사진 찍듯 여기저기서 아주 연하게 번쩍 (눈이 안 아프게 짧고 은은하게)
    //  mode = 기본 방향 (rise 위로 / fall 아래로). 에디터 화면 설정에서 effects.ambientDir(up|down)로 바꿀 수 있음
    //  glyphs = 디자인 고르기 카드 등에 쓰는 작은 글자 견본
    const AMBIENT = {
        hearts:   { label: '하트',   mode: 'rise', type: '3d', glyphs: ['♥', '♡'], color: '#E28FA0' },
        petals:   { label: '꽃잎',   mode: 'fall', type: '3d', glyphs: ['🌸', '❀'], color: '#F2A7B5' },
        sparkles: { label: '반짝임', mode: 'rise', type: 'spark', glyphs: ['✦', '✧', '⋆'], color: '#E0B84F' },
        cherry:   { label: '벚꽃',   mode: 'fall', type: '3d', glyphs: ['❀', '✿'], color: '#F4B6C2' },
        leaves:   { label: '나뭇잎', mode: 'fall', type: '3d', glyphs: ['🍃'], color: '#7BA05B' },
        autumn:   { label: '낙엽',   mode: 'fall', type: '3d', glyphs: ['🍁', '🍂'], color: '#C8702E' },
        snow:     { label: '눈',     mode: 'fall', type: '3d', glyphs: ['❄', '❅', '•'], color: '#FFFFFF' },
        snowflake:{ label: '눈꽃',   mode: 'fall', type: '3d', glyphs: ['❄'], color: '#DDEBF7' },
        rain:     { label: '빗방울', mode: 'fall', type: 'rain', glyphs: ['╱'], color: '#B9CCDD' },
        sunset:   { label: '선셋',   mode: '',     type: 'sunset', glyphs: ['☀'], color: '#F7B77A' },
        fireworks:{ label: '불꽃놀이', mode: '',   type: 'fireworks', glyphs: ['✺'], color: '#F6D27A' },
        popper:   { label: '폭죽',   mode: '',     type: 'popper', glyphs: ['🎉'], color: '#F28B82' },
        meteor:   { label: '별똥별', mode: '',     type: 'meteor', glyphs: ['☄'], color: '#FFF4D6' },
        weather:  { label: '날씨 따라', mode: '',  type: 'weather', glyphs: ['☀'], color: '#9BB7D4' },
        confetti: { label: '컨페티', mode: 'fall', type: '3d', glyphs: ['▮'], color: '' },
        sunshine: { label: '선샤인', mode: 'rise', type: 'spark', sprite: 'sun', glyphs: ['✺'], color: '#F5C542' },
        stars:    { label: '별',     mode: 'rise', type: '3d', glyphs: ['★', '☆'], color: '#F0C04A' },
        daisy:    { label: '데이지', mode: 'fall', type: '3d', glyphs: ['🌼'], color: '#F2D35B' },
        bokeh:    { label: '빛망울', mode: 'rise', type: 'bokeh', glyphs: ['●'], color: '#F3E7CC' },
        flash:    { label: '플래시', mode: '',     type: 'flash', glyphs: ['✦'], color: '#FFFDF5' }
    };
    const CONFETTI_COLORS = ['#F28B82', '#FBBC04', '#81C995', '#78D9EC', '#C58AF9', '#FF8BCB'];
    // (따옴표·괄호까지 전부 %로 바꿔서 따옴표 없는 url(...) - style="..." 속성 안에 넣어도 안 깨지게. SVG 안에서는 #을 그대로 씀)
    const svgUrl = svg => `url(data:image/svg+xml,${encodeURIComponent(svg).replace(/'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29')})`;
    const SVG = (body, vb) => svgUrl(`<svg xmlns='http://www.w3.org/2000/svg' viewBox='${vb || '0 0 40 40'}'>${body}</svg>`);
    const rg = (stops, cx, cy, r) => `<defs><radialGradient id='g' cx='${cx || '50%'}' cy='${cy || '50%'}' r='${r || '60%'}'>${stops.map(([o, c, a]) => `<stop offset='${o}' stop-color='${c}'${a != null ? ` stop-opacity='${a}'` : ''}/>`).join('')}</radialGradient></defs>`;
    const lg = (stops, x2, y2) => `<defs><linearGradient id='g' x1='0' y1='0' x2='${x2 == null ? 1 : x2}' y2='${y2 == null ? 1 : y2}'>${stops.map(([o, c]) => `<stop offset='${o}' stop-color='${c}'/>`).join('')}</linearGradient></defs>`;
    const HEART = 'M20 35C8 26 3 19 3 12.5 3 7.8 6.8 4 11.4 4c3.6 0 6.6 2.1 8.6 5.3C22 6.1 25 4 28.6 4 33.2 4 37 7.8 37 12.5 37 19 32 26 20 35z';
    const STAR = '20,3 24.7,14.6 37,15.3 27.5,23.2 30.6,35.3 20,28.5 9.4,35.3 12.5,23.2 3,15.3 15.3,14.6';
    const daisy = (petal, mid) => SVG(Array.from({ length: 12 }, (_, i) => `<ellipse cx='20' cy='10.5' rx='3.3' ry='8' fill='${petal}' stroke='#E9E1D2' stroke-width='.5' transform='rotate(${i * 30} 20 20)'/>`).join('') + `<circle cx='20' cy='20' r='5.2' fill='${mid}'/><circle cx='18.6' cy='18.6' r='1.8' fill='#FFF3C2' opacity='.7'/>`);
    const SPRITES3D = {
        hearts: [
            SVG(rg([[0, '#FFE6EC'], [.5, '#F5A8B9'], [1, '#D96F8A']], '35%', '30%', '80%') + `<path d='${HEART}' fill='url(#g)'/><ellipse cx='12.5' cy='11' rx='4.2' ry='2.4' fill='#fff' opacity='.6' transform='rotate(-32 12.5 11)'/>`),
            SVG(rg([[0, '#FFF1F4'], [.55, '#F9C6D2'], [1, '#EC9AAE']], '35%', '30%', '80%') + `<path d='${HEART}' fill='url(#g)'/><ellipse cx='12.5' cy='11' rx='4' ry='2.2' fill='#fff' opacity='.7' transform='rotate(-32 12.5 11)'/>`),
            SVG(rg([[0, '#FFDCE3'], [.5, '#EE8FA4'], [1, '#C85571']], '35%', '30%', '80%') + `<path d='${HEART}' fill='url(#g)'/><ellipse cx='12.5' cy='11' rx='4' ry='2.2' fill='#fff' opacity='.5' transform='rotate(-32 12.5 11)'/>`)
        ],
        petals: [
            SVG(rg([[0, '#FFF5F7'], [.55, '#F8C3CF'], [1, '#E992A8']], '50%', '25%', '85%') + `<path d='M20 3C30 9 35.5 19 31.5 28 28.5 34.5 23 37 20 37S11.5 34.5 8.5 28C4.5 19 10 9 20 3z' fill='url(#g)'/><path d='M20 34C19 25 19.5 15 20.5 7' stroke='#F2A4B7' stroke-width='.7' fill='none' opacity='.5'/>`),
            SVG(rg([[0, '#FFF8F2'], [.6, '#FAD4C8'], [1, '#EFA894']], '50%', '25%', '85%') + `<path d='M20 4C29 8 36 17 33 27 30.5 34 24.5 37 20.5 37 15 37 9 33.5 7.5 27 5 17 11 8 20 4z' fill='url(#g)'/>`),
            SVG(rg([[0, '#FFFFFF'], [.6, '#FBE3E8'], [1, '#F2BDC9']], '50%', '25%', '85%') + `<path d='M20 3C30 9 35.5 19 31.5 28 28.5 34.5 23 37 20 37S11.5 34.5 8.5 28C4.5 19 10 9 20 3z' fill='url(#g)'/>`)
        ],
        cherry: [
            SVG(rg([[0, '#FFF6F8'], [.55, '#FAC8D3'], [1, '#EE9DB1']], '50%', '85%', '85%') + `<path d='M20 38C9.5 31 3.5 21 5.5 12c1.2-5.2 6.3-8 10.4-5.6 1.9 1.1 3.2 3 4.1 5.2.9-2.2 2.2-4.1 4.1-5.2 4.1-2.4 9.2.4 10.4 5.6 2 9-4 19-14.5 26z' fill='url(#g)'/><path d='M20 36c-.6-7-.4-14 0-21' stroke='#F3A6B8' stroke-width='.7' fill='none' opacity='.6'/>`),
            SVG(rg([[0, '#FFFFFF'], [.6, '#FBD3DC'], [1, '#F2A9BB']], '45%', '80%', '90%') + `<path d='M21 37C11 33 5 24 6.5 14.5 7.6 8 13 4.5 17.5 6.8c1.3.7 2.2 1.8 2.8 3.2 1-1.6 2.4-2.7 4.1-3.1 4.6-1.1 9 3 8.9 8.6-.2 9.5-4.8 17.7-12.3 21.5z' fill='url(#g)'/>`)
        ],
        leaves: [SVG(lg([[0, '#A9CF7E'], [1, '#5E8F3E']]) + `<path d='M6 34C6 17 16 6 35 5c0 19-11 30-29 29z' fill='url(#g)'/><path d='M7 33C14 25 22 16 33 7' stroke='#E9F4D9' stroke-width='1' fill='none' opacity='.75'/>`)],
        autumn: [
            SVG(lg([[0, '#F2A541'], [1, '#C8502A']], 0, 1) + `<path d='M20 3l3.2 7.6 5.6-3.4-1.4 7.4 7.6-1.2-4.4 6 5.4 3.2-7.4 2.2 1.6 5.2-6.4-2.6L20 36l-3.8-8.6-6.4 2.6 1.6-5.2-7.4-2.2 5.4-3.2-4.4-6 7.6 1.2-1.4-7.4 5.6 3.4z' fill='url(#g)'/><path d='M20 36V12' stroke='#8E3A1C' stroke-width='.9' opacity='.6'/>`),
            SVG(lg([[0, '#E8B04A'], [1, '#A8562A']]) + `<path d='M7 33C7 18 17 7 34 6c0 17-10 28-27 27z' fill='url(#g)'/><path d='M8 32C15 24 23 15 32 8' stroke='#F6DDB0' stroke-width='1' fill='none' opacity='.6'/>`)
        ],
        snow: [SVG(rg([[0, '#FFFFFF'], [.55, '#FFFFFF', .9], [1, '#FFFFFF', 0]]) + `<circle cx='20' cy='20' r='18' fill='url(#g)'/>`)],
        confetti: CONFETTI_COLORS.map(c => SVG(`<rect x='1' y='1' width='22' height='38' rx='2.5' fill='${c}'/><rect x='1' y='1' width='22' height='13' rx='2.5' fill='#fff' opacity='.28'/>`, '0 0 24 40')),
        sunshine: [
            SVG(rg([[0, '#FFFBE6'], [.35, '#FFE7A0'], [.7, '#F5C542', .55], [1, '#F5C542', 0]]) + `<circle cx='20' cy='20' r='19' fill='url(#g)'/>` + Array.from({ length: 8 }, (_, i) => `<rect x='19.3' y='1.5' width='1.4' height='9' rx='.7' fill='#FFE08A' opacity='.75' transform='rotate(${i * 45} 20 20)'/>`).join('')),
            SVG(rg([[0, '#FFFFFF'], [.4, '#FFF0C2'], [1, '#F7D36B', 0]]) + `<circle cx='20' cy='20' r='17' fill='url(#g)'/>`)
        ],
        stars: [
            SVG(lg([[0, '#FFF4C8'], [.5, '#F2C14E'], [1, '#D3962A']]) + `<polygon points='${STAR}' fill='url(#g)'/><polygon points='${STAR}' fill='none' stroke='#FFF6D6' stroke-width='.8' opacity='.7'/>`),
            SVG(lg([[0, '#FFFFFF'], [1, '#F6DE92']]) + `<polygon points='${STAR}' fill='url(#g)'/>`)
        ],
        daisy: [daisy('#FFFFFF', '#F2C94C'), daisy('#FFF8EC', '#EDB93C')]
    };
    // 눈꽃: 여섯 갈래 결정 (가지마다 작은 곁가지)
    const FLAKE = (stroke, w) => SVG(`<g fill='none' stroke='${stroke}' stroke-width='${w}' stroke-linecap='round'>` + Array.from({ length: 6 }, (_, i) => `<g transform='rotate(${i * 60} 20 20)'><path d='M20 20V3.5'/><path d='M20 9.5l-4-3.6M20 9.5l4-3.6M20 14.5l-3-2.6M20 14.5l3-2.6'/></g>`).join('') + `</g><circle cx='20' cy='20' r='2' fill='${stroke}'/>`);
    SPRITES3D.snowflake = [FLAKE('#FFFFFF', 1.6), FLAKE('#E6F1FB', 1.4), FLAKE('#F4F9FF', 2)];
    const SPRITE_H = { confetti: 1.65 }; // 세로가 긴 그림 (높이 = 폭 x 이 값)
    const SPARKLE = SVG(rg([[0, '#FFFFFF'], [.3, '#FFF6D8', .9], [1, '#FFE9A8', 0]]) + `<circle cx='20' cy='20' r='9' fill='url(#g)'/><path d='M20 1C21.2 13 27 18.8 39 20 27 21.2 21.2 27 20 39 18.8 27 13 21.2 1 20 13 18.8 18.8 13 20 1z' fill='#FFF8E1'/><path d='M20 9C20.6 16 24 19.4 31 20 24 20.6 20.6 24 20 31 19.4 24 16 20.6 9 20 16 19.4 19.4 16 20 9z' fill='#FFFFFF'/>`);
    // 선샤인: 따뜻한 햇빛 알갱이 (가운데 밝고 살짝 빛살) - 3D로 돌지 않고 천천히 흘러가며 숨 쉬듯 밝아졌다 흐려짐
    const SUNGLOW = SVG(rg([[0, '#FFFDF0'], [.25, '#FFEFB0', .95], [.6, '#F8CF63', .45], [1, '#F5C542', 0]]) + `<circle cx='20' cy='20' r='19' fill='url(#g)'/>` + Array.from({ length: 8 }, (_, i) => `<rect x='19.4' y='2' width='1.2' height='8' rx='.6' fill='#FFF3C4' opacity='.55' transform='rotate(${i * 45 + 22.5} 20 20)'/>`).join(''));
    const rnd = (a, b) => a + Math.random() * (b - a);
    const f1 = v => v.toFixed(1);
    /**
     * 장식 효과 마크업. opts.dir = 'up'|'down' (없으면 효과 기본 방향), opts.local = 첫 화면 섹션 안에만 (개수를 줄임)
     */
    function ambientHtml(kind, opts) {
        const def = AMBIENT[kind];
        if (!def) return '';
        opts = opts || {};
        const up = (opts.dir === 'up' || opts.dir === 'down') ? opts.dir === 'up' : def.mode === 'rise';
        const k = opts.local ? .6 : 1, cls = `ambient-field ambient-${kind}${opts.local ? ' ib-amb-local' : ''}`;
        let spans = '';
        if (def.type === 'flash') { // 플래시: 정해진 자리에서 서로 다른 박자로 아주 짧게 번쩍
            const n = Math.round(9 * k);
            for (let i = 0; i < n; i++) {
                const z = rnd(110, 230), dur = rnd(7, 15);
                spans += `<span class="ib-fl" style="left:${f1(rnd(4, 96))}%;top:${f1(rnd(6, 90))}%;width:${f1(z)}px;height:${f1(z)}px;margin:${f1(-z / 2)}px 0 0 ${f1(-z / 2)}px;animation-duration:${f1(dur)}s;animation-delay:${f1(-rnd(0, dur))}s;--fo:${rnd(.38, .6).toFixed(2)}"><i></i></span>`;
            }
            return `<div class="${cls} ib-amb-flash">${spans}</div>`;
        }
        const still = opts.dir === 'still'; // 화면 고정: 흘러가지 않고 제자리에서 살랑살랑 (3D 회전·반짝임은 그대로)
        const dirCls = still ? ' ib-dir-still' : up ? ' ib-dir-up' : ' ib-dir-down';
        if (def.type === 'bokeh') { // 빛망울: 크기·흐림·깊이(translateZ)가 제각각인 연한 아이보리 동그라미
            const n = Math.round(16 * k);
            for (let i = 0; i < n; i++) {
                const z = rnd(22, 92), dur = rnd(22, 40), depth = Math.round(rnd(-260, 80));
                spans += `<span class="ib-bk" style="left:${f1(rnd(-4, 96))}%;--t:${f1(rnd(4, 90))}%;animation-duration:${f1(dur)}s;animation-delay:${f1(-rnd(0, dur))}s;--sway:${Math.round(rnd(-40, 40))}px;--z:${depth}px"><i style="width:${f1(z)}px;height:${f1(z)}px;filter:blur(${f1(rnd(1.5, z > 60 ? 7 : 4.5))}px);opacity:${rnd(.45, .9).toFixed(2)};animation-duration:${f1(rnd(5, 9))}s;animation-delay:${f1(-rnd(0, 6))}s"></i></span>`;
            }
            return `<div class="${cls} ib-amb-bokeh${dirCls}">${spans}</div>`;
        }
        if (def.type === 'spark') { // 반짝임·선샤인: 천천히 흘러가며 커졌다 작아졌다 반짝 (3D 회전 없음)
            const sun = def.sprite === 'sun', n = Math.round((sun ? 16 : 22) * k);
            for (let i = 0; i < n; i++) {
                const z = sun ? rnd(14, 34) : rnd(8, 20), dur = sun ? rnd(20, 34) : rnd(16, 30);
                spans += `<span class="ib-sp" style="left:${f1(rnd(0, 100))}%;--t:${f1(rnd(4, 90))}%;animation-duration:${f1(dur)}s;animation-delay:${f1(-rnd(0, dur))}s;--sway:${Math.round(rnd(-25, 25))}px"><i style="width:${f1(z)}px;height:${f1(z)}px;background-image:${sun ? SUNGLOW : SPARKLE};animation-duration:${f1(sun ? rnd(3.5, 6) : rnd(1.6, 3.4))}s;animation-delay:${f1(-rnd(0, 3))}s"></i></span>`;
            }
            return `<div class="${cls} ib-amb-spark${sun ? ' ib-amb-sun' : ''}${dirCls}">${spans}</div>`;
        }
        if (def.type === 'rain') { // 빗방울: 가늘고 투명한 빗줄기가 살짝 비스듬히 빠르게 (크기·흐림·속도가 달라 앞뒤 깊이감)
            const n = Math.round(46 * k);
            for (let i = 0; i < n; i++) {
                const near = Math.random(), len = 12 + near * 22, dur = 1.5 - near * .8;
                spans += `<span class="ib-rn" style="left:${f1(rnd(-5, 105))}%;animation-duration:${dur.toFixed(2)}s;animation-delay:${f1(-rnd(0, 3))}s"><i style="height:${f1(len)}px;width:${(.8 + near * .9).toFixed(2)}px;opacity:${(.35 + near * .5).toFixed(2)};${near < .35 ? 'filter:blur(.6px);' : ''}"></i></span>`;
            }
            return `<div class="${cls} ib-amb-rain">${spans}</div>`;
        }
        if (def.type === 'fireworks') { // 불꽃놀이: 아래에서 불꽃이 솟아올라 터지며 빛 알갱이가 퍼졌다 떨어짐 (자리·박자 제각각)
            const COLS = ['#FFE7A3', '#FFC2CF', '#BFE3FF', '#FFFFFF', '#F6D27A', '#D9C6FF'];
            const n = opts.local ? 3 : 5;
            for (let i = 0; i < n; i++) {
                const dur = rnd(4.2, 6.5), c = COLS[i % COLS.length], c2 = COLS[(i + 2) % COLS.length], R = rnd(46, 78), P = 18;
                let parts = '';
                for (let j = 0; j < P; j++) {
                    const a = (j / P) * Math.PI * 2 + rnd(-.12, .12), r = R * rnd(.75, 1.05);
                    parts += `<b style="--dx:${f1(Math.cos(a) * r)}px;--dy:${f1(Math.sin(a) * r)}px;background:${j % 3 ? c : c2}"></b>`;
                }
                spans += `<span class="ib-fw" style="left:${f1(rnd(14, 86))}%;top:${f1(rnd(16, 42))}%;--rise:${Math.round(rnd(160, 260))}px;--d:${f1(dur)}s;--dl:${f1(-rnd(0, dur) + i * .9)}s"><i style="background:linear-gradient(${c},transparent)"></i>${parts}</span>`;
            }
            return `<div class="${cls} ib-amb-fw">${spans}</div>`;
        }
        if (def.type === 'popper') { // 폭죽: 아래 양쪽 모서리에서 "펑" - 색종이가 비스듬히 솟았다가 뒤집히며(3D) 떨어짐
            const n = 22;
            const side = (left, delay) => {
                let p = '';
                for (let j = 0; j < n; j++) {
                    const ang = (left ? 1 : -1) * rnd(18, 62) * Math.PI / 180, sp = rnd(170, 330);
                    const dx = Math.sin(ang) * sp, dy = -Math.cos(ang) * sp;
                    p += `<b style="--dx:${f1(dx)}px;--dy:${f1(dy)}px;--fx:${f1(dx * 1.25)}px;--rx:${Math.round(rnd(360, 900))}deg;--ry:${Math.round(rnd(180, 720))}deg;width:${f1(rnd(5, 9))}px;height:${f1(rnd(9, 14))}px;background:${CONFETTI_COLORS[j % CONFETTI_COLORS.length]}"></b>`;
                }
                return `<span class="ib-pp ${left ? 'l' : 'r'}" style="--dl:${delay}s">${p}</span>`;
            };
            spans = side(true, -.2) + side(false, -3.4);
            return `<div class="${cls} ib-amb-pp">${spans}</div>`;
        }
        if (def.type === 'meteor') { // 별똥별: 가끔 오른쪽 위에서 왼쪽 아래로 빛꼬리를 그으며 휙 + 작은 별들이 은은하게 반짝
            for (let i = 0; i < 4; i++) {
                const dur = rnd(5, 9);
                spans += `<span class="ib-mt" style="left:${f1(rnd(35, 105))}%;top:${f1(rnd(-4, 30))}%;width:${Math.round(rnd(90, 150))}px;--d:${f1(dur)}s;--dl:${f1(-rnd(0, dur) + i * 1.7)}s"></span>`;
            }
            for (let i = 0; i < (opts.local ? 8 : 14); i++) spans += `<span class="ib-tw" style="left:${f1(rnd(2, 98))}%;top:${f1(rnd(2, 70))}%;--d:${f1(rnd(2.4, 4.6))}s;--dl:${f1(-rnd(0, 4))}s;--z:${f1(rnd(1.5, 3))}px"></span>`;
            return `<div class="${cls} ib-amb-mt">${spans}</div>`;
        }
        if (def.type === 'sunset') { // 선셋: 가끔 위쪽에서 따뜻한 빛이 은은하게 번지며 빛줄기가 비췄다가 사라짐
            const ray = (l, w, rot, d) => `<span class="ib-ss-ray" style="left:${l}%;width:${w}%;--rot:${rot}deg;animation-delay:${d}s"></span>`;
            spans = `<span class="ib-ss-glow"></span>${ray(8, 16, 22, .6)}${ray(30, 10, 16, 1.4)}${ray(52, 20, 12, .2)}${ray(76, 12, 6, 1.9)}`;
            return `<div class="${cls} ib-amb-sunset">${spans}</div>`;
        }
        // 3D: 바깥 span = 흔들리며 떨어짐/떠오름, 안쪽 i = 그림이 앞뒤로 뒤집히며 회전
        const sp = SPRITES3D[kind] || SPRITES3D.cherry, small = kind === 'snow' || kind === 'confetti';
        if (kind === 'snowflake') { // 눈꽃: 크기 차이를 크게 (멀리 작은 결정 · 가까이 큰 결정)
            const n2 = Math.round(22 * k);
            for (let i = 0; i < n2; i++) {
                const size = rnd(9, 26), dur = 14 + Math.random() * 12;
                spans += `<span class="ib-a3d" style="left:${f1(rnd(0, 100))}%;--t:${f1(rnd(4, 90))}%;animation-duration:${f1(dur)}s;animation-delay:${f1(-Math.random() * 22)}s;--sway:${Math.round(rnd(-50, 50))}px;"><i style="width:${f1(size)}px;height:${f1(size)}px;background-image:${sp[i % sp.length]};animation-name:ibTumble${1 + (i % 3)};animation-duration:${f1(rnd(5, 10))}s;animation-delay:${f1(-Math.random() * 6)}s;${size < 13 ? 'filter:blur(.5px);' : ''}"></i></span>`;
            }
            return `<div class="${cls} ib-amb-3d${dirCls}">${spans}</div>`;
        }
        const n = Math.round((kind === 'snow' ? 30 : kind === 'confetti' ? 26 : 20) * k);
        for (let i = 0; i < n; i++) {
            const size = kind === 'snow' ? rnd(5, 14) : kind === 'confetti' ? rnd(6, 10) : kind === 'sunshine' ? rnd(14, 30) : rnd(13, 26);
            const dur = (kind === 'snow' ? 13 : 11) + Math.random() * 12, delay = -Math.random() * 22;
            const spin = f1(rnd(small ? 2.2 : 3, small ? 4.5 : 8)), tum = 'ibTumble' + (1 + (i % 3));
            const blur = !small && size < 16 ? 'filter:blur(.6px);' : ''; // 작은 것(멀리 있는 것)은 살짝 흐리게 = 깊이감
            spans += `<span class="ib-a3d" style="left:${f1(rnd(0, 100))}%;--t:${f1(rnd(4, 90))}%;animation-duration:${f1(dur)}s;animation-delay:${f1(delay)}s;--sway:${Math.round(rnd(-45, 45))}px;"><i style="width:${f1(size)}px;height:${f1(size * (SPRITE_H[kind] || 1))}px;background-image:${sp[i % sp.length]};animation-name:${kind === 'snow' ? 'none' : tum};animation-duration:${spin}s;animation-delay:${f1(-Math.random() * 6)}s;${blur}"></i></span>`;
        }
        return `<div class="${cls} ib-amb-3d${dirCls}">${spans}</div>`;
    }
    /**
     * 장식 효과를 청첩장에 붙인다 (에디터 미리보기·공개 페이지 같은 규칙)
     *  effects.ambient       효과 이름
     *  effects.ambientDir    'up' 위로 / 'down' 아래로 / 없으면 효과 기본 방향
     *  effects.ambientScope  'hero' = 첫 화면(메인 영상·메인 사진) 섹션 안에만 / 없으면 화면 전체
     *  effects.ambientOpacity 10~100 진하기
     */
    // ---- 날씨 따라: 식장 지역의 지금 날씨(weather.php)를 보고 효과를 고른다 (맑음 낮 → 선샤인, 밤 → 별똥별, 흐림·안개 → 빛망울, 비 → 빗방울, 눈 → 눈꽃) ----
    const WEATHER_FX = { clear: 'sunshine', night: 'meteor', cloudy: 'bokeh', fog: 'bokeh', rain: 'rain', snow: 'snowflake', storm: 'rain' };
    const weatherCache = {};
    function weatherKind(url) {
        if (!url || typeof fetch === 'undefined') return Promise.resolve(null);
        if (!weatherCache[url]) weatherCache[url] = fetch(url, { cache: 'no-store' }).then(r => r.ok ? r.json() : null).then(j => j && j.ok ? j : null).catch(() => null);
        return weatherCache[url];
    }
    // 날씨에 따라 첫 화면 색감도 살짝 (비 = 푸른 회색, 눈 = 차가운 흰빛, 맑음 = 따뜻하게, 밤 = 짙은 남색)
    const WEATHER_TINT = { rain: 'rgba(32,46,68,.30)', storm: 'rgba(24,32,48,.38)', snow: 'rgba(214,228,244,.24)', clear: 'rgba(255,196,120,.14)', night: 'rgba(12,16,42,.32)', cloudy: 'rgba(120,128,140,.18)', fog: 'rgba(200,204,210,.22)' };
    // 미리보기(에디터·디자인 고르기)에서는 비 → 눈 → 맑음을 번갈아 보여 줌 (실제 청첩장은 그날 식장 날씨)
    const WEATHER_DEMO = [['rain', '☔', '비 오는 날'], ['snow', '❄', '눈 오는 날'], ['clear', '☀', '맑은 날']];
    function weatherTint(root, cond, label) {
        const col = root.querySelector('.col[data-block-id="heroVideo"], .col[data-block-id="hero"]');
        if (!col) return;
        const hero = col.querySelector('.hero-photo-wrap, .video-cover-wrap') || col; // 사진·영상 칸 위에만 (칸 밖으로 넓힌 사진도 꼭 맞게)
        if (getComputedStyle(hero).position === 'static') hero.style.position = 'relative';
        let t = hero.querySelector(':scope > .ib-wx-tint');
        if (!t) { t = document.createElement('div'); t.className = 'ib-wx-tint'; hero.appendChild(t); }
        t.style.background = WEATHER_TINT[cond] || 'transparent';
        let b = hero.querySelector(':scope > .ib-wx-badge');
        if (label) { if (!b) { b = document.createElement('div'); b.className = 'ib-wx-badge'; hero.appendChild(b); } b.textContent = label; b.classList.remove('ib-wx-in'); void b.offsetWidth; b.classList.add('ib-wx-in'); }
        else if (b) b.remove();
    }
    function ambientPut(root, kind, fx) {
        const dir = ['up', 'down', 'still'].includes(fx.ambientDir) ? fx.ambientDir : '';
        if (fx.ambientScope === 'hero') {
            root.querySelectorAll('.col[data-block-id="heroVideo"], .col[data-block-id="hero"]').forEach(col => {
                if (getComputedStyle(col).position === 'static') col.style.position = 'relative';
                col.insertAdjacentHTML('beforeend', ambientHtml(kind, { dir, local: true }));
            });
        } else root.insertAdjacentHTML('beforeend', ambientHtml(kind, { dir }));
        const op = fx.ambientOpacity != null ? Math.max(10, Math.min(100, Number(fx.ambientOpacity) || 100)) / 100 : 1;
        if (op < 1) root.querySelectorAll('.ambient-field').forEach(f => { f.style.opacity = op; });
    }
    function mountAmbient(root, fx, mopts) {
        if (!root) return;
        clearTimeout(root._wxDemoT);
        root.querySelectorAll('.ambient-field').forEach(n => n.remove());
        let kind = fx && fx.ambient;
        if (kind === 'weather') {
            const tok = (root._ambTok = (root._ambTok || 0) + 1);
            if (mopts && mopts.weatherDemo) { // 미리보기: 비 → 눈 → 맑음 되풀이 (4.5초씩, 바뀔 때 살짝 겹쳐 사라짐)
                let i = 0;
                const step = () => {
                    if (root._ambTok !== tok || !root.isConnected) return;
                    const [cond, ic, t] = WEATHER_DEMO[i++ % WEATHER_DEMO.length];
                    const old = [...root.querySelectorAll('.ambient-field')];
                    old.forEach(n => { n.classList.add('ib-wx-out'); setTimeout(() => n.remove(), 900); });
                    ambientPut(root, WEATHER_FX[cond], fx);
                    weatherTint(root, cond, `${ic} ${t} · 날씨 따라 바뀌어요`);
                    root._wxDemoT = setTimeout(step, 4500);
                };
                step();
                if (mopts.onWeather && mopts.weatherUrl) weatherKind(mopts.weatherUrl).then(w => { if (root._ambTok === tok) mopts.onWeather(w); });
                return;
            }
            weatherKind(mopts && mopts.weatherUrl).then(w => { // 실제 청첩장: 날씨를 받아온 뒤에 붙임 (못 받으면 빛망울)
                if (root._ambTok !== tok) return; // 그 사이 다시 그렸으면 버림
                root._weather = w;
                root.querySelectorAll('.ambient-field').forEach(n => n.remove());
                ambientPut(root, (w && WEATHER_FX[w.cond]) || 'bokeh', fx);
                if (w) weatherTint(root, w.cond, '');
                if (mopts && mopts.onWeather) mopts.onWeather(w);
            });
            return;
        }
        if (!kind || kind === 'none' || !AMBIENT[kind]) return;
        ambientPut(root, kind, fx);
    }

    // ---------- 종이 질감 배경 (에디터 화면 설정 > 색상 > 종이 질감) ----------
    //  bg = 종이 바탕색, img = 그 위에 얹는 결(노이즈) - 청첩장 카드 배경에 --p-paper로 깔림
    const noise = (freq, oct, r, g, b, a, extra) => svgUrl(`<svg xmlns='http://www.w3.org/2000/svg' width='260' height='260'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='${freq}' numOctaves='${oct}' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 ${r} 0 0 0 0 ${g} 0 0 0 0 ${b} 0 0 0 ${a} 0'/></filter><rect width='100%' height='100%' filter='url(#n)'/>${extra || ''}</svg>`);
    // 종이 질감: 빛을 비스듬히 비춘 요철(feDiffuseLighting) + 섬유·티 → 카드 바탕색 위에 곱하기(multiply)로 얹어서 실제 종이 결처럼
    //  (예전에는 아주 옅은 노이즈라 거의 바탕색만 보였다)
    const PAPER_S = 300;
    const pWrap = body => svgUrl(`<svg xmlns='http://www.w3.org/2000/svg' width='${PAPER_S}' height='${PAPER_S}'>${body}</svg>`);
    const pRelief = (id, freq, oct, scale, elev, seed, op) => `<filter id='${id}' x='0' y='0' width='100%' height='100%'><feTurbulence type='fractalNoise' baseFrequency='${freq}' numOctaves='${oct}' seed='${seed || 1}' stitchTiles='stitch' result='n'/><feDiffuseLighting in='n' surfaceScale='${scale}' lighting-color='#fff'><feDistantLight azimuth='225' elevation='${elev}'/></feDiffuseLighting></filter><rect width='100%' height='100%' filter='url(#${id})'${op ? ` opacity='${op}'` : ''}/>`;
    const pLines = (id, freq, oct, rgb, k, c, seed, t) => `<filter id='${id}' x='0' y='0' width='100%' height='100%'><feTurbulence type='${t || 'fractalNoise'}' baseFrequency='${freq}' numOctaves='${oct}' seed='${seed || 2}' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 ${rgb[0]} 0 0 0 0 ${rgb[1]} 0 0 0 0 ${rgb[2]} ${k} 0 0 0 ${c}'/></filter><rect width='100%' height='100%' filter='url(#${id})'/>`;
    const BG_PAPERS = {
        beige: { label: '코튼지', bg: '#F4ECDF', img: pWrap(pRelief('a', '.9', 3, 1.1, 62) + pRelief('b', '.035', 3, 1.4, 70, 5, '.55') + pLines('f', '.006 .3', 2, [.62, .54, .44], 2.6, -1.78, 7)) },
        white: { label: '수채화지', bg: '#FBFAF6', img: pWrap(pRelief('a', '.06', 4, 1.9, 60, 3) + pRelief('b', '.8', 2, .8, 70, 9, '.5')) },
        hanji: { label: '한지', bg: '#F6F1E6', img: pWrap(pRelief('a', '.5', 3, .9, 66) + pLines('f', '.018 .03', 3, [.55, .47, .36], -4.5, .5, 8, 'turbulence') + pLines('g', '.03 .018', 3, [.6, .52, .4], -5, .45, 13, 'turbulence')) },
        linen: { label: '린넨', bg: '#EFE9DF', img: pWrap(pLines('h', '.9 .012', 1, [.4, .35, .3], 1.8, -.95, 3) + pLines('v', '.012 .9', 1, [.4, .35, .3], 1.8, -.95, 6) + pRelief('a', '.9', 2, .7, 70, 1, '.6')) },
        kraft: { label: '크래프트', bg: '#D9C2A2', img: pWrap(pRelief('a', '.7', 3, 1.3, 60) + pLines('f', '.004 .35', 2, [.42, .32, .2], 2.8, -1.95, 4) + pLines('s', '.9', 1, [.25, .17, .1], 7, -5.6, 11)) }
    };
    // 관리자가 올린 종이 그림 (관리자 → 추천 디자인 → 종이 질감 그림 · paper_settings.php) - 없는 종이는 기본 무늬
    //  공개 페이지는 invite_view.php가 window.LD_PAPERS로 넣어 두고, 에디터는 받아 와서 setPapers (style 속성에도 들어가서 작은따옴표)
    function setPapers(m) {
        Object.keys(BG_PAPERS).forEach(k => { const p = BG_PAPERS[k]; if (p.img0 == null) p.img0 = p.img; const u = m && m[k];
            p.img = typeof u === 'string' && /^\/invite\/uploads\/site\/[a-f0-9]{32}\.webp$/.test(u) ? `url('${u}')` : p.img0; p.custom = p.img !== p.img0; });
    }
    if (global.LD_PAPERS) setPapers(global.LD_PAPERS);
    function paperCss(key) { return BG_PAPERS[key] ? BG_PAPERS[key].img : 'none'; }

    // ---------- 갤러리 모양 6종 ----------
    // grid(정사각 그리드)는 예전 마크업과 완전히 같게 둬서, 기존 청첩장은 모양이 하나도 바뀌지 않는다.
    const GALLERY_TYPES = [['grid', '정사각 그리드'], ['tall', '세로 그리드'], ['collage', '콜라주'], ['wide', '가로형 콜라주'], ['circle', '써클'], ['slide', '슬라이드'], ['pages', '넘기는 콜라주']];
    // 사진 나타나는 방식 (스크롤로 갤러리에 닿으면): '' 한 번에 · seq 차례로 · random 무작위로 차라락
    const GALLERY_REVEALS = [['', '한 번에'], ['seq', '차례로'], ['random', '무작위로 차라락']];
    function galleryHtml(f, width, opts) {
        const h = galleryHtml0(f, width, opts), rv = f && (f.reveal === 'seq' || f.reveal === 'random') ? f.reveal : '';
        return rv ? h.replace('<div class="blk-gallery', `<div data-ib-reveal="${rv}" class="blk-gallery`) : h;
    }
    // 갤러리 차라락: 사진마다 나타날 차례(--rv-d)·기울기(--rv-r)를 정함 → 재생 길이(초)
    function galleryRevealPrep(g) {
        const imgs = [...g.querySelectorAll('.grid img')], n = imgs.length; if (!n) return 0;
        const order = imgs.map((_, i) => i);
        if (g.dataset.ibReveal === 'random') for (let i = n - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [order[i], order[j]] = [order[j], order[i]]; }
        const step = Math.max(.045, Math.min(.1, 1.6 / n));
        order.forEach((ix, k) => { const im = imgs[ix]; im.style.setProperty('--rv-d', (k * step).toFixed(3) + 's'); im.style.setProperty('--rv-r', g.dataset.ibReveal === 'random' ? (Math.random() * 14 - 7).toFixed(1) + 'deg' : '0deg'); });
        return (n - 1) * step + .6;
    }
    function galleryRevealRun(g, total) {
        g.classList.add('rv-arm'); void g.offsetWidth;
        requestAnimationFrame(() => requestAnimationFrame(() => g.classList.add('rv-run')));
        clearTimeout(g._rvT); g._rvT = setTimeout(() => g.classList.remove('rv-arm', 'rv-run'), (total + .35) * 1000); // 끝나면 원래 모양으로 (넘기기·확대 등)
    }
    // 공개 페이지: 하객이 스크롤해서 갤러리가 보이면 한 번 (인트로가 떠 있으면 끝난 뒤)
    function armGalleryReveal(root) {
        if (!root || typeof IntersectionObserver === 'undefined' || hlReduced()) return;
        const gs = [...root.querySelectorAll('.blk-gallery[data-ib-reveal]:not(.rv-armed)')]; if (!gs.length) return;
        const tot = new Map(); gs.forEach(g => { tot.set(g, galleryRevealPrep(g)); g.classList.add('rv-armed', 'rv-arm'); });
        const io = new IntersectionObserver(es => es.forEach(en => {
            if (!en.isIntersecting) return; io.unobserve(en.target); const g = en.target;
            (function wait() { if (document.querySelector('.intro-overlay')) return setTimeout(wait, 200); galleryRevealRun(g, tot.get(g)); })();
        }), { threshold: .18 });
        gs.forEach(g => io.observe(g));
    }
    // 에디터: 고를 때 바로 한 번 보여 줌 (평소 미리보기는 다 나온 모습)
    function playGalleryReveal(root) { if (!root || hlReduced()) return; root.querySelectorAll('.blk-gallery[data-ib-reveal]').forEach(g => galleryRevealRun(g, galleryRevealPrep(g))); }
    function galleryHtml0(f, width, opts) {
        const o = opts || {};
        const images = (f.images && f.images.length) ? f.images : (o.defaultImages ? o.defaultImages() : []);
        const scale = f.resScale || 1;
        const src = im => esc(o.scaleImgUrl ? o.scaleImgUrl(im.src, scale) : im.src);
        const lazy = o.lazy ? ' loading="lazy" decoding="async"' : '';
        const lightboxCls = f.lightbox === false ? 'no-lightbox' : '';
        const edgeCls = f.edgeToEdge ? 'edge-to-edge' : '';
        const cardCls = f.cardStyle ? 'card-style' : '';
        const type = f.layoutType || 'grid';
        let title = titleLayer(f.galleryTitle, f, 'galleryTitle', 'ib-title gallery-title', 13.5, 34);
        // 제목과 사진 사이 간격 (에디터 갤러리 편집 "제목 ↕ 사진 간격", -20 ~ 80px)
        const tGap = Math.max(-20, Math.min(80, Number(f.galleryTitleGap) || 0));
        if (title && tGap) title = title.replace('style="height:', `style="margin-bottom:${tGap}px; height:`);
        if (type === 'grid') {
            const cols = (!f.colLayout || f.colLayout === 'auto') ? (width <= 33 ? 1 : (width <= 50 ? 2 : 3)) : parseInt(f.colLayout, 10);
            const cls = { 1: 'g1', 2: 'g2', 4: 'g4', 5: 'g5' }[cols] || '';
            const imgs = images.map(im => `<img${lazy} src="${src(im)}" alt="" class="${im.size === 'large' ? 'g-large' : ''}">`).join('');
            return `<div class="blk-gallery ${edgeCls}" style="position:relative;">${title}<div class="grid ${cls} ${lightboxCls} ${cardCls}">${imgs}</div></div>`;
        }
        const imgs = images.map(im => `<img${lazy} src="${src(im)}" alt="">`).join('');
        // 넘기는 콜라주: 사진 6장씩 두 줄 높낮이 콜라주 한 장 → 옆으로 넘김 (다음 장이 오른쪽에 살짝 보임)
        if (type === 'pages') {
            const W = { 1: [1], 2: [1, 1], 3: [4, 3, 5] }, WR = { 1: [1], 2: [1, 1], 3: [5, 4, 3] }; // 왼쪽·오른쪽 줄 사진 높이 비율 (엇갈리게)
            const col = (arr, w) => `<div class="ib-gp-col">${arr.map((im, i) => `<img${lazy} src="${src(im)}" alt="" style="flex-grow:${(w[arr.length] || [])[i] || 1}">`).join('')}</div>`;
            const pages = []; for (let i = 0; i < images.length; i += 6) pages.push(images.slice(i, i + 6));
            const page = p => { const h = Math.ceil(p.length / 2); return `<div class="ib-gp-page">${col(p.slice(0, h), W)}${p.length > 1 ? col(p.slice(h), WR) : ''}</div>`; };
            const bar = f.slideProgress !== false && pages.length > 1 ? `<div class="ib-g-progress"><span style="width:${100 / pages.length}%"></span></div>` : '';
            return `<div class="blk-gallery ${edgeCls}" style="position:relative;">${title}<div class="grid ib-g-pages ${lightboxCls}${pages.length > 1 ? '' : ' one'}" data-ib-slide>${pages.map(page).join('')}</div>${bar}</div>`;
        }
        if (type === 'slide') {
            const bar = f.slideProgress !== false ? `<div class="ib-g-progress"><span style="width:${images.length ? (100 / images.length) : 100}%"></span></div>` : '';
            return `<div class="blk-gallery ${edgeCls}" style="position:relative;">${title}<div class="grid ib-g-slide ${lightboxCls}" data-ib-slide>${imgs}</div>${bar}</div>`;
        }
        return `<div class="blk-gallery ${edgeCls}" style="position:relative;">${title}<div class="grid ib-g-${type} ${lightboxCls} ${cardCls}">${imgs}</div></div>`;
    }

    // ---------- 계좌번호 카드형 ----------
    // 공개페이지에서는 계좌값이 마스킹된 채로 그려지고(masked), render-invite.js의 bindAccountReveal이
    // 서버에서 실제 값을 받아와 data-reveal="value" 칸의 글자만 바꿔 넣는다 (계좌번호가 HTML에 평문으로 안 남게).
    const ACCOUNT_ROLES = [
        { key: 'groomBank', side: 'groom', role: 'self' }, { key: 'groomFatherBank', side: 'groom', role: 'father' }, { key: 'groomMotherBank', side: 'groom', role: 'mother' },
        { key: 'brideBank', side: 'bride', role: 'self' }, { key: 'brideFatherBank', side: 'bride', role: 'father' }, { key: 'brideMotherBank', side: 'bride', role: 'mother' }
    ];
    // 카드형 계좌 디자인 (에디터 "마음 전하실 곳" → 카드형 → 디자인). 모양은 invite-blocks.css .ib-as-*
    const ACC_STYLES = [['theme', '테마 모양'], ['basic', '기본 카드'], ['line', '미니멀'], ['outline', '라인 상자'], ['center', '가운데 정렬'], ['vintage', '빈티지 큐피드']];
    function accountCardsHtml(f, masked) {
        const MASK = '계좌번호 불러오는 중…';
        const side = (sideKey, sideLabel) => {
            // 카카오페이 송금은 신랑·신부 본인 것만 - 본인 계좌 줄 바로 아래에 "카카오페이로 신랑에게 송금"
            const kakao = f[sideKey + 'Kakao'] && /^https?:\/\//.test(f[sideKey + 'Kakao'])
                ? `<a class="ib-kakaopay" href="${esc(f[sideKey + 'Kakao'])}" target="_blank" rel="noopener">카카오페이로 ${esc(L[sideKey])}에게 송금</a>` : '';
            const rows = ACCOUNT_ROLES.filter(r => r.side === sideKey && f[r.key]).map(r => `
                <div class="ib-acc-row">
                    <span class="ib-acc-who">${esc(r.role === 'self' ? L[sideKey] : L[r.role])}</span>
                    <span class="ib-acc-val" ${masked ? `data-masked="1" data-part="${r.key}" data-reveal="value"` : ''}>${masked ? MASK : accHtml(f[r.key])}</span>
                    <button type="button" class="ib-copy-btn" data-ib-copy>복사</button>
                </div>${r.role === 'self' ? kakao : ''}`).join('');
            if (!rows) return '';
            const inner = rows;
            return f.accCollapse
                ? `<details class="ib-acc-side ib-${sideKey}"><summary>${esc(sideLabel)} 계좌번호</summary>${inner}</details>`
                : `<div class="ib-acc-side ib-${sideKey}"><div class="ib-acc-head">${esc(sideLabel)}</div>${inner}</div>`;
        };
        const g = side('groom', L.groom + '측'), b = side('bride', L.bride + '측');
        const as = ACC_STYLES.some(([k]) => k === f.accStyle) ? f.accStyle : 'basic';
        return `<div class="ib-block ib-accounts ib-as-${as}">${sectionTitle(f.accTitle || '마음 전하실 곳')}
            ${f.accNote ? `<p class="ib-acc-note">${nl2br(f.accNote)}</p>` : ''}
            ${g || b ? `<div class="ib-acc-wrap">${bothSides(g, b)}</div>` : emptyHint('계좌를 입력해주세요')}
        </div>`;
    }

    // ---------- 공개페이지 동작 (복사 버튼, 슬라이드 진행바) - 한 번만 연결 ----------
    let interactionsBound = false;
    function toast(msg) {
        let el = document.querySelector('.ib-toast');
        if (!el) { el = document.createElement('div'); el.className = 'ib-toast'; document.body.appendChild(el); }
        el.textContent = msg; el.classList.add('show');
        clearTimeout(el._t); el._t = setTimeout(() => el.classList.remove('show'), 1500);
    }
    function bindInteractions(root) {
        if (typeof document === 'undefined') return;
        bindNextButtons();
        if (root && !global.INVITE_SLUG && !global.INVITE_OFFLINE) fillTripSample(root); // 에디터 미리보기: 신혼여행 라이브 예시
        if (!interactionsBound) {
            interactionsBound = true;
            // 계좌 복사: 카드형 [복사] 버튼(data-ib-copy) · 공개 페이지 자유 배치 줄(data-ib-copyrow)을 누르면 계좌번호만 복사
            //  계좌번호는 하객이 화면을 만져야 받아오므로(봇 방지, render-invite.js bindGuestSecure) 아직이면 받아온 뒤 복사
            //  (Safari는 누른 순간에 복사를 시작해야 해서 ClipboardItem에 "나중에 채울 값"을 넘김). 막힌 브라우저면 길게 눌러 복사 안내
            const accVal = el => { const row = el.closest('[data-ib-copy]') ? el.closest('[data-ib-copy]').parentElement : el.closest('[data-ib-copyrow]'); return row && (row.querySelector('.ib-acc-val, .acc-v') || row); };
            const accNum = v => { let t = (v && v.textContent || '').trim(); if (t.includes('·')) t = t.split('·').slice(1).join('·').trim(); const m = t.match(/[0-9][0-9-]{5,}[0-9]/); return m ? m[0] : ''; };
            const masked = v => !!(v && (v.dataset.masked === '1' || v.querySelector('[data-masked="1"]')));
            const legacyCopy = txt => { const ta = document.createElement('textarea'); ta.value = txt; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;'; document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, txt.length); let ok = false; try { ok = document.execCommand('copy'); } catch (err) {} ta.remove(); return ok; };
            const okMsg = () => toast('계좌번호가 복사되었습니다'), failMsg = () => toast('이 브라우저는 복사가 막혀 있어요. 계좌번호를 길게 눌러 복사해 주세요.');
            const copyNow = num => { if (!num) { toast('계좌번호가 없어요.'); return; }
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(num).then(okMsg, () => (legacyCopy(num) ? okMsg() : failMsg()));
                else (legacyCopy(num) ? okMsg() : failMsg()); };
            document.addEventListener('click', e => { // 안내 말씀 탭
                const tab = e.target.closest && e.target.closest('[data-ib-tab]'); if (!tab) return;
                const box = tab.closest('[data-ib-tabs]'); if (!box) return;
                box.querySelectorAll('[data-ib-tab]').forEach(t => { const on = t === tab; t.classList.toggle('on', on); t.setAttribute('aria-selected', on); });
                box.querySelectorAll('[data-ib-panel]').forEach(p => { p.hidden = p.dataset.ibPanel !== tab.dataset.ibTab; });
            });
            document.addEventListener('click', e => {
                const hit = e.target.closest && e.target.closest('[data-ib-copy], [data-ib-copyrow]');
                if (!hit) return;
                const v = accVal(hit);
                if (!masked(v)) { copyNow(accNum(v)); return; }
                const load = global.__ldSecureLoad;
                if (!load) { toast('계좌번호를 불러오는 중이에요. 잠시 후 다시 눌러주세요.'); return; }
                const got = Promise.resolve(load()).then(() => (masked(v) ? '' : accNum(v)));
                if (global.ClipboardItem && navigator.clipboard && navigator.clipboard.write) { // 누른 순간에 시작 (Safari)
                    navigator.clipboard.write([new ClipboardItem({ 'text/plain': got.then(n => { if (!n) throw 0; return new Blob([n], { type: 'text/plain' }); }) })])
                        .then(okMsg, () => got.then(n => n ? copyNow(n) : toast('계좌번호를 불러오지 못했어요. 잠시 후 다시 눌러주세요.')));
                } else got.then(n => n ? copyNow(n) : toast('계좌번호를 불러오지 못했어요. 잠시 후 다시 눌러주세요.'));
            });
        }
        (root || document).querySelectorAll('[data-ib-slide]').forEach(track => {
            if (track._ibBound) return; track._ibBound = true;
            const bar = track.parentElement.querySelector('.ib-g-progress span');
            if (!bar) return;
            const update = () => {
                const max = track.scrollWidth - track.clientWidth;
                const n = track.children.length || 1;
                const ratio = max > 0 ? track.scrollLeft / max : 0;
                bar.style.width = (100 / n) + '%';
                bar.style.transform = `translateX(${ratio * (n - 1) * 100}%)`;
            };
            track.addEventListener('scroll', update, { passive: true });
            update();
        });
    }


    // =====================================================================
    // 청첩장 전체 기능 (배경음악 / 공유 / 상단 메뉴) - 공개페이지에서 initExtras로 한 번에 켠다
    // 설정값: design.bgm, design.share, design.extras (에디터 "화면 설정" 패널)
    // =====================================================================
    const MENU_LABELS = { greeting: '인사말', family: '혼주', profile: '소개', letter: '편지', dday: '예식일', timeline: '이야기', interview: '인터뷰',
        gallery: '갤러리', video: '영상', location: '오시는 길', transport: '교통', notice: '안내', account: '마음 전하실 곳', contact: '연락하기',
        together: '함께한 시간', guestsnap: '게스트스냅', ending: '엔딩', rsvp: '참석 여부', guestbook: '방명록', dayinfo: '당일 안내' };
    const KAKAO_SDK = 'https://t1.kakaocdn.net/kakao_js_sdk/2.7.2/kakao.min.js';
    function absUrl(u) { if (!u) return ''; try { return new URL(imgUrl(u), location.origin).href; } catch (e) { return ''; } }

    function shareBarHtml(share) {
        const s = share || {}; // 공유 설정이 없는 예전 청첩장도 기본으로 보여준다
        if (s.shareBar === false) return '';
        return `<div class="ib-share"><p class="ib-share-title">청첩장 공유하기</p>
            <div class="ib-share-row">
                <button type="button" class="ib-share-btn ib-kakao" data-ib-share="kakao"><span>💬</span>카카오톡 공유</button>
                <button type="button" class="ib-share-btn" data-ib-share="link"><span>🔗</span>링크 복사</button>
            </div></div>`;
    }
    function loadKakao(key) {
        return new Promise((resolve, reject) => {
            const ready = () => { try { if (!global.Kakao.isInitialized()) global.Kakao.init(key); resolve(global.Kakao); } catch (e) { reject(e); } };
            if (global.Kakao) return ready();
            const sc = document.createElement('script');
            sc.src = KAKAO_SDK; sc.async = true; sc.onload = ready; sc.onerror = () => reject(new Error('카카오 SDK 로드 실패'));
            document.head.appendChild(sc);
        });
    }
    function kakaoShare(design) {
        const s = design.share || {};
        const key = global.KAKAO_JS_KEY;
        const url = location.href.split('#')[0];
        const size = { portrait: [600, 800], square: [800, 800], landscape: [800, 400] }[s.kakaoRatio || 'portrait'];
        const loc = (design.blocks || []).find(b => b.id === 'location');
        const buttons = [{ title: s.kakaoButton || '청첩장 보기', link: { mobileWebUrl: url, webUrl: url } }];
        if (s.kakaoExtra === 'location' && loc && loc.fields && loc.fields.address) {
            const m = 'https://map.kakao.com/link/search/' + encodeURIComponent(loc.fields.addressBase || loc.fields.address);
            buttons.push({ title: '위치 보기', link: { mobileWebUrl: m, webUrl: m } }); // 위치보기는 이름 고정 (이름·주소는 "직접 지정"일 때만)
        } else if (s.kakaoExtra === 'url' && /^https?:\/\//.test(s.kakaoExtraUrl || '')) {
            buttons.push({ title: s.kakaoExtraLabel || '자세히 보기', link: { mobileWebUrl: s.kakaoExtraUrl, webUrl: s.kakaoExtraUrl } });
        }
        // 서버가 jpg로 바꿔 둔 썸네일(share_thumb.php) 우선 - 카카오톡은 webp 썸네일을 못 보여주는 경우가 있다
        const img = global.INVITE_SHARE_THUMB || absUrl(s.kakaoThumb) || absUrl(s.ogImage) || (document.querySelector('meta[property="og:image"]') || {}).content || '';
        return loadKakao(key).then(K => K.Share.sendDefault({
            objectType: 'feed',
            content: { title: s.kakaoTitle || document.title, description: s.kakaoDesc || '', imageUrl: img, imageWidth: size[0], imageHeight: size[1], link: { mobileWebUrl: url, webUrl: url } },
            buttons
        }));
    }

    // ---- 배경음악: 모바일 브라우저는 소리 있는 자동재생을 막기 때문에, "자동재생"이면 첫 터치/스크롤 때 바로 튼다 ----
    function initBgm(root, bgm) {
        if (!bgm || !bgm.enabled || !bgm.url || document.querySelector('.ib-bgm-btn')) return;
        const audio = new Audio(imgUrl(bgm.url));
        audio.loop = true; audio.preload = 'none';
        audio.volume = Math.max(0, Math.min(1, (Number(bgm.volume ?? 70)) / 100)); // iOS는 볼륨 조절이 안 됨(기기 볼륨 따름)
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'ib-bgm-btn'; btn.setAttribute('aria-label', '배경음악 켜기/끄기');
        btn.innerHTML = '<span class="ib-eq"><i></i><i></i><i></i></span><svg class="ib-bgm-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V6l10-2v11"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="16.5" cy="15" r="2.5"/><path d="M4 4l16 16"/></svg>';
        document.body.appendChild(btn);
        // 맨 위가 메인 영상이면 영상 오른쪽 위 "전체화면" 버튼과 겹치므로, 맨 위에 있을 땐 그 아래로 내려 두고 스크롤하면 제자리로
        const first = root && root.querySelector('.col[data-block-id]');
        if (first && first.dataset.blockId === 'heroVideo') {
            const low = () => btn.classList.toggle('ib-bgm-low', (window.scrollY || document.documentElement.scrollTop || 0) < 40);
            low(); window.addEventListener('scroll', low, { passive: true });
        }
        try { global.__ibBgmAudio = audio; } catch (e) {} // 스크롤 버튼 "음악 웨이브"가 이 음악의 박자를 읽음
        audio.addEventListener('play', () => { document.documentElement.classList.add('ib-music-on'); if (global.InviteBlocks && global.InviteBlocks.beatWatch) global.InviteBlocks.beatWatch(audio); });
        audio.addEventListener('pause', () => { document.documentElement.classList.remove('ib-music-on', 'ib-beat-on'); document.documentElement.style.setProperty('--ib-beat', '0'); }); // 음악을 끄면 스크롤 버튼 웨이브도 멈춤
        const sync = () => btn.classList.toggle('is-playing', !audio.paused);
        audio.addEventListener('play', sync); audio.addEventListener('pause', sync);
        audio.addEventListener('error', () => { btn.remove(); }); // 음악 파일을 못 불러오면 버튼 자체를 숨김
        let userPaused = false;
        btn.addEventListener('click', e => { e.stopPropagation(); if (audio.paused) { userPaused = false; audio.play().catch(() => {}); } else { userPaused = true; audio.pause(); } });
        if (bgm.autoplay) {
            const start = () => { if (!userPaused && audio.paused) audio.play().catch(() => {}); ['pointerdown', 'touchstart', 'scroll', 'keydown'].forEach(ev => window.removeEventListener(ev, start, true)); };
            audio.play().catch(() => ['pointerdown', 'touchstart', 'scroll', 'keydown'].forEach(ev => window.addEventListener(ev, start, { capture: true, passive: true })));
        }
        document.addEventListener('visibilitychange', () => { if (document.hidden && !audio.paused) { audio.pause(); audio._resume = true; } else if (!document.hidden && audio._resume) { audio._resume = false; audio.play().catch(() => {}); } });
    }

    // ---- 상단 메뉴바: 첫 화면(히어로)을 지나 스크롤하면 위에 섹션 바로가기가 나타난다 ----
    function initTopMenu(root) {
        if (document.querySelector('.ib-topmenu')) return;
        const cols = Array.from(root.querySelectorAll('.col[data-block-id]')).filter(c => MENU_LABELS[c.dataset.blockId]);
        if (cols.length < 2) return;
        const nav = document.createElement('nav');
        nav.className = 'ib-topmenu';
        const rs = getComputedStyle(root); // 색 변수는 카드(root)에만 있어서 body에 붙는 메뉴로 복사
        ['--p-bg', '--p-line', '--p-muted', '--p-accent', '--p-on-accent'].forEach(v => nav.style.setProperty(v, rs.getPropertyValue(v)));
        nav.innerHTML = cols.map(c => `<button type="button" data-ib-go="${esc(c.dataset.blockId)}">${esc(MENU_LABELS[c.dataset.blockId])}</button>`).join('');
        document.body.appendChild(nav);
        nav.addEventListener('click', e => {
            const b = e.target.closest('[data-ib-go]'); if (!b) return;
            const col = root.querySelector(`.col[data-block-id="${b.dataset.ibGo}"]`);
            if (col) window.scrollTo({ top: col.getBoundingClientRect().top + window.scrollY - 52, behavior: 'smooth' });
        });
        const first = root.querySelector('.col');
        const onScroll = () => {
            const limit = first ? first.getBoundingClientRect().bottom : 300;
            nav.classList.toggle('show', limit < 60);
            let cur = null;
            cols.forEach(c => { if (c.getBoundingClientRect().top < 120) cur = c.dataset.blockId; });
            nav.querySelectorAll('button').forEach(x => x.classList.toggle('active', x.dataset.ibGo === cur));
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    // 클립보드 복사가 막힌 브라우저 - 기본 prompt 창 대신 청첩장 모양의 팝업에 주소를 띄운다
    function copyFallback(url) {
        if (global.LD && global.LD.copy) { global.LD.copy(url, '청첩장 주소를 복사했어요'); return; }
        const m = openModal('아래 주소를 길게 눌러 복사해주세요', `<input type="text" readonly value="${esc(url)}" style="width:100%;box-sizing:border-box;padding:12px;border:1px solid rgba(0,0,0,.12);border-radius:10px;font-size:14px">`,
            null, { footer: '<div style="margin-top:14px"><button type="button" class="ib-f-submit" data-ib-close>닫기</button></div>' });
        const inp = m.el.querySelector('input'); setTimeout(() => { inp.focus(); inp.select(); }, 60);
    }
    function doShare(type, d) {
        const url = location.href.split('#')[0];
        if (type === 'link') {
            (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(() => toast('청첩장 주소를 복사했어요'), () => { copyFallback(url); });
        } else if (type === 'native') {
            navigator.share({ title: document.title, url }).catch(() => {}); // 사용자가 공유창을 닫아도 오류 아님
        } else {
            if (!global.KAKAO_JS_KEY) { toast('카카오톡 공유를 준비 중이에요'); return; }
            kakaoShare(d).catch(() => toast('카카오톡 공유를 열 수 없어요. 링크 복사를 이용해주세요.'));
        }
    }

    // ---- 떠 있는 공유 버튼 (설정은 "공유하기" 섹션의 fab* 값) ----
    // 기본은 최대한 안 거슬리게: 첫 화면(대표사진)에선 숨김 → 지나가면 나타남 → 아래로 스크롤하는 동안엔 사라졌다가
    // 멈추거나 위로 올리면 다시 나타남 → 섹션 자리의 공유 버튼이 화면에 보이면 겹치니까 숨김
    const SHARE_ICON = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>';
    function shareOpts(f) { return Object.assign({}, SHARE_DEFAULTS, f || {}); }
    /** 떠 있는 버튼 DOM만 만든다 (에디터 미리보기도 이걸 그대로 써서 모양이 똑같다). onPick(type) = 메뉴 항목을 눌렀을 때 */
    function createShareFab(f, accent, onPick) {
        const o = shareOpts(f);
        const items = shareItems(o, false);
        if (!items.length) return null;
        const wrap = document.createElement('div');
        wrap.className = `ib-fab ib-fab-${o.fabPos === 'left' ? 'left' : 'right'} ib-fab-${o.fabStyle || 'dark'} ib-fab-${o.fabSize || 'normal'}`;
        wrap.style.setProperty('--fab-op', String(Math.max(30, Math.min(100, Number(o.fabOpacity) || 72)) / 100));
        if (accent) { wrap.style.setProperty('--p-accent', accent); wrap.style.setProperty('--p-on-accent', onAccent(accent)); }
        const label = String(o.fabLabel || '').trim();
        wrap.innerHTML = `<div class="ib-fab-menu" role="menu">${items.map(([type, icon, name]) =>
                `<button type="button" data-fab="${type}"><span${type === 'kakao' ? ' class="ib-fab-k"' : ''}>${icon}</span>${esc(name)}</button>`).join('')}</div>
            <button type="button" class="ib-fab-btn${label ? ' has-label' : ''}" aria-label="청첩장 공유하기" aria-expanded="false">${SHARE_ICON}${label ? `<b>${esc(label)}</b>` : ''}</button>`;
        const btn = wrap.querySelector('.ib-fab-btn');
        const setOpen = on => { wrap.classList.toggle('open', on); btn.setAttribute('aria-expanded', on ? 'true' : 'false'); };
        wrap._setOpen = setOpen;
        btn.addEventListener('click', e => { e.stopPropagation(); setOpen(!wrap.classList.contains('open')); });
        wrap.querySelector('.ib-fab-menu').addEventListener('click', e => {
            const b = e.target.closest('[data-fab]'); if (!b) return;
            e.stopPropagation(); setOpen(false); if (onPick) onPick(b.dataset.fab);
        });
        return wrap;
    }
    function initShareFab(root, d, f) {
        if (document.querySelector('.ib-fab')) return;
        const o = shareOpts(f);
        const wrap = createShareFab(o, getComputedStyle(root).getPropertyValue('--p-accent').trim(), type => doShare(type, d));
        if (!wrap) return;
        if (root.classList.contains('ib-dark')) wrap.classList.add('ib-fab-ondark'); // 어두운 디자인: 펼친 공유 메뉴도 어둡게
        document.body.appendChild(wrap);
        document.addEventListener('click', () => wrap._setOpen(false));

        const first = root.querySelector('.col');
        const bar = root.querySelector('.ib-share');
        const smart = o.fabShow !== 'always';
        let barVisible = false, lastY = window.scrollY, idleT = 0, ticking = false;
        if (bar && 'IntersectionObserver' in global) {
            new IntersectionObserver(es => { barVisible = es[0].isIntersecting; update(false); }).observe(bar);
        }
        function update(scrollingDown) {
            const pastHero = !o.fabHero || (first ? first.getBoundingClientRect().bottom < window.innerHeight * 0.35 : window.scrollY > 300);
            const show = pastHero && !barVisible && !(smart && scrollingDown);
            wrap.classList.toggle('show', show || wrap.classList.contains('open'));
        }
        window.addEventListener('scroll', () => {
            if (ticking) return; ticking = true;
            requestAnimationFrame(() => {
                ticking = false;
                const y = window.scrollY, down = y > lastY + 2; lastY = y;
                if (down && wrap.classList.contains('open')) wrap._setOpen(false); // 메뉴 열어둔 채 내리면 닫기
                update(down);
                clearTimeout(idleT);
                idleT = setTimeout(() => update(false), 700); // 스크롤이 멈추면 다시 보이기
            });
        }, { passive: true });
        update(false);
    }

    // =====================================================================
    // 4단계 동작: 팝업 창, 참석 의사 전달 폼, 방명록 목록/작성/삭제, 예식 당일 안내 팝업
    // =====================================================================
    const slug = () => global.INVITE_SLUG || '';
    function copyVars(from, to) {
        if (!from) return;
        const cs = getComputedStyle(from);
        ['--p-bg', '--p-ink', '--p-accent', '--p-on-accent', '--p-line', '--p-muted', '--p-head-font', '--groom-color', '--bride-color', '--box-bg', '--box-ink'].forEach(v => {
            const val = cs.getPropertyValue(v); if (val) to.style.setProperty(v, val.trim());
        });
        to.style.fontFamily = cs.fontFamily;
        // 섹션 "안쪽 창 색"을 정했으면 팝업 창에도 (하객 안내·참석 의사·방명록 쓰기 등)
        if (cs.getPropertyValue('--box-bg').trim()) to.classList.add('ib-box-bg');
        if (cs.getPropertyValue('--box-ink').trim()) to.classList.add('ib-box-ink');
    }
    /** 가운데 팝업 창. 반환: { el(내용 영역), close() } */
    function openModal(title, bodyHtml, varsFrom, opts) {
        opts = opts || {};
        document.querySelectorAll('.ib-modal').forEach(m => m._close && m._close());
        const wrap = document.createElement('div');
        wrap.className = 'ib-modal';
        wrap.innerHTML = `<div class="ib-modal-box" role="dialog" aria-modal="true">
                <button type="button" class="ib-modal-x" aria-label="닫기">✕</button>
                ${title ? `<h4 class="ib-modal-title">${esc(title)}</h4>` : ''}
                <div class="ib-modal-body">${bodyHtml}</div>
                ${opts.footer || ''}</div>`;
        const vf = varsFrom || document.querySelector('[data-block-id]') || document.body;
        copyVars(vf, wrap);
        { // 어두운 디자인·어두운 섹션 테마면 팝업 안 입력 칸·버튼도 어둡게 (창은 body에 붙어서 청첩장의 .ib-dark를 못 물려받음). 섹션 '안쪽 창 색'을 정했으면 그 색 기준
            const boxBg = wrap.style.getPropertyValue('--box-bg').trim();
            const dark = boxBg ? hexDark(boxBg) : (hexDark(wrap.style.getPropertyValue('--p-bg')) || !!(vf.closest && vf.closest('.ib-dark, .ib-sk-dark')));
            wrap.classList.toggle('ib-dark', !!dark);
            const pInk = wrap.style.getPropertyValue('--p-ink').trim();
            if (boxBg && !wrap.style.getPropertyValue('--box-ink').trim() && pInk && hexDark(pInk) === !!dark) { // 창 색만 정했고 글자가 창과 같은 밝기면 (어두운 디자인 + 흰 창) 글자색을 반대로
                wrap.style.setProperty('--box-ink', dark ? '#F3EFE9' : '#2B2320'); wrap.classList.add('ib-box-ink');
            }
        }
        document.body.appendChild(wrap);
        const prevOverflow = document.documentElement.style.overflow;
        document.documentElement.style.overflow = 'hidden';
        requestAnimationFrame(() => wrap.classList.add('show'));
        const close = () => {
            if (wrap._closed) return; wrap._closed = true;
            wrap.classList.remove('show'); document.documentElement.style.overflow = prevOverflow;
            setTimeout(() => wrap.remove(), 220);
            if (opts.onClose) opts.onClose();
        };
        wrap._close = close;
        wrap.addEventListener('click', e => { if (e.target === wrap || e.target.closest('.ib-modal-x') || e.target.closest('[data-ib-close]')) close(); });
        document.addEventListener('keydown', function esc_(e) { if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc_); } });
        return { el: wrap.querySelector('.ib-modal-box'), close };
    }
    function postForm(file, data) {
        const fd = new FormData();
        Object.keys(data).forEach(k => fd.append(k, data[k] == null ? '' : data[k]));
        fd.append('s', slug()); fd.append('csrf_token', global.INVITE_CSRF || '');
        return fetch(API + file, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json().catch(() => ({ ok: false, error: '서버 응답을 읽을 수 없어요.' })));
    }
    const storeGet = (k, area) => { try { return (area || localStorage).getItem(k); } catch (e) { return null; } };
    const storeSet = (k, v, area) => { try { (area || localStorage).setItem(k, v); } catch (e) {} };

    // ---- 참석 의사 전달 폼 ----
    function openRsvpForm(cfg, from) {
        const g = L.groom, b = L.bride;
        const side = (v, t) => `<label class="ib-seg-opt"><input type="radio" name="side" value="${v}"><span>${esc(t)}측</span></label>`;
        const html = `<form class="ib-form" novalidate>
            <div class="ib-f-row"><span class="ib-f-lb">어느 측 하객이신가요?</span>
                <div class="ib-seg">${L.order === 'bride-first' ? side('bride', b) + side('groom', g) : side('groom', g) + side('bride', b)}</div></div>
            <div class="ib-f-row"><span class="ib-f-lb">참석 여부</span>
                <div class="ib-seg"><label class="ib-seg-opt"><input type="radio" name="attend" value="yes"><span>참석할게요</span></label>
                <label class="ib-seg-opt"><input type="radio" name="attend" value="no"><span>참석이 어려워요</span></label></div></div>
            <div class="ib-f-row"><label class="ib-f-lb" for="ibRsvpName">성함</label><input id="ibRsvpName" name="name" maxlength="20" autocomplete="name" placeholder="성함을 적어주세요"></div>
            <div class="ib-f-yes">
                ${cfg.askHeadcount ? `<div class="ib-f-row"><span class="ib-f-lb">참석 인원 <small>(본인 포함)</small></span>
                    <div class="ib-stepper"><button type="button" data-step="-1">−</button><input name="headcount" value="1" inputmode="numeric" readonly><button type="button" data-step="1">＋</button></div></div>` : ''}
                ${cfg.askMeal ? `<div class="ib-f-row"><span class="ib-f-lb">식사 여부</span>
                    <div class="ib-seg"><label class="ib-seg-opt"><input type="radio" name="meal" value="yes"><span>식사할게요</span></label>
                    <label class="ib-seg-opt"><input type="radio" name="meal" value="no"><span>안 해요</span></label>
                    <label class="ib-seg-opt"><input type="radio" name="meal" value="unknown"><span>미정</span></label></div></div>` : ''}
            </div>
            ${cfg.askPhone ? `<div class="ib-f-row"><label class="ib-f-lb" for="ibRsvpPhone">연락처 <small>(선택)</small></label><input id="ibRsvpPhone" name="phone" inputmode="tel" maxlength="13" placeholder="숫자만 입력"></div>` : ''}
            ${cfg.askMemo ? `<div class="ib-f-row"><label class="ib-f-lb" for="ibRsvpMemo">전하는 말 <small>(선택)</small></label><textarea id="ibRsvpMemo" name="memo" rows="2" maxlength="300"></textarea></div>` : ''}
            ${cfg.askPhone ? '<p class="ib-f-note">연락처는 신랑·신부에게만 전달되고 예식 후 자동으로 삭제돼요.</p>' : ''}
            <button type="submit" class="ib-f-submit">전달하기</button></form>`;
        const m = openModal('참석 의사 전달', html, from);
        const form = m.el.querySelector('form');
        const yesBox = form.querySelector('.ib-f-yes');
        const sync = () => { const a = form.querySelector('[name=attend]:checked'); yesBox.hidden = !(a && a.value === 'yes'); };
        form.addEventListener('change', sync); sync();
        form.querySelectorAll('[data-step]').forEach(bt => bt.addEventListener('click', () => {
            const inp = form.querySelector('[name=headcount]'); inp.value = Math.max(1, Math.min(20, (Number(inp.value) || 1) + Number(bt.dataset.step)));
        }));
        const setVal = (name, v) => { const r = form.querySelector(`[name=${name}][value="${v}"]`); if (r) r.checked = true; else { const i = form.querySelector(`[name=${name}]`); if (i) i.value = v; } };
        if (!isEditor() && slug()) {
            fetch(API + 'rsvp.php?mine=1&s=' + encodeURIComponent(slug()), { credentials: 'same-origin' }).then(r => r.json()).then(d => {
                if (d && d.mine) {
                    const keys = d.mine.attend === 'yes' ? ['side', 'attend', 'name', 'headcount', 'meal', 'memo'] : ['side', 'attend', 'name', 'memo']; // 불참이었으면 인원·식사는 새로 고르게
                    keys.forEach(k => d.mine[k] != null && setVal(k, d.mine[k])); sync();
                    const ph = form.querySelector('[name=phone]'); if (ph && d.mine.hasPhone) ph.placeholder = '전에 적은 연락처 그대로 (바꿀 때만 입력)';
                    form.querySelector('.ib-f-submit').textContent = '수정해서 전달하기';
                }
            }).catch(() => {});
        }
        form.addEventListener('submit', e => {
            e.preventDefault();
            const v = n => { const el = form.querySelector(`[name=${n}]:checked`) || form.querySelector(`[name=${n}]`); return el ? el.value : ''; };
            if (!form.querySelector('[name=side]:checked')) return toast('신랑측/신부측을 골라주세요');
            if (!form.querySelector('[name=attend]:checked')) return toast('참석 여부를 골라주세요');
            if (!v('name').trim()) return toast('성함을 적어주세요');
            if (isEditor()) { toast('미리보기에서는 전송되지 않아요'); m.close(); return; }
            const btn = form.querySelector('.ib-f-submit'); btn.disabled = true; btn.textContent = '전달하는 중…';
            postForm('rsvp.php', { side: v('side'), attend: v('attend'), name: v('name'), headcount: v('headcount') || 1,
                meal: form.querySelector('[name=meal]:checked') ? v('meal') : 'unknown', phone: v('phone'), memo: v('memo') })
                .then(d => {
                    if (!d.ok) throw new Error(d.error || '전달하지 못했어요');
                    storeSet('ib_rsvp_' + slug(), '1'); m.close();
                    toast(v('attend') === 'yes' ? '참석 의사를 전달했어요. 감사합니다!' : '전달했어요. 마음 써주셔서 감사합니다.');
                    document.querySelectorAll('[data-ib-rsvp]').forEach(x => { try { x.textContent = JSON.parse(x.dataset.ibRsvp).doneLabel || x.textContent; } catch (err) {} });
                })
                .catch(err => { toast(err.message); btn.disabled = false; btn.textContent = '전달하기'; });
        });
    }

    // ---- 방명록 ----
    function gbLoad(listEl, page) {
        let cfg = {}; try { cfg = JSON.parse(listEl.dataset.ibGb || '{}'); } catch (e) {}
        const more = listEl.parentElement.querySelector('.ib-gb-more');
        return fetch(API + 'guestbook.php?s=' + encodeURIComponent(slug()) + '&page=' + page, { credentials: 'same-origin' })
            .then(r => r.json()).then(d => {
                if (!d.ok) { listEl.innerHTML = `<p class="ib-gb-empty">${esc(d.error || '방명록을 불러올 수 없어요')}</p>`; return; }
                const html = gbEntriesHtml(d.entries, cfg.style);
                if (page === 1) listEl.innerHTML = html; else if (d.entries.length) listEl.insertAdjacentHTML('beforeend', html);
                rtLoadFonts(listEl); // 하객이 고른 글꼴 불러오기
                listEl._page = page;
                if (more) more.hidden = page * d.pageSize >= d.total;
            }).catch(() => { if (page === 1) listEl.innerHTML = '<p class="ib-gb-empty">방명록을 불러올 수 없어요</p>'; });
    }
    function openGbWrite(listEl, from) {
        const html = `<form class="ib-form" novalidate>
            <div class="ib-f-row"><label class="ib-f-lb" for="ibGbName">이름</label><input id="ibGbName" name="name" maxlength="12" placeholder="이름"></div>
            <div class="ib-f-row"><label class="ib-f-lb" for="ibGbPw">비밀번호 <small>(글 삭제할 때 필요)</small></label><input id="ibGbPw" name="pw" type="password" maxlength="20" placeholder="4자 이상" autocomplete="new-password"></div>
            <div class="ib-f-row"><span class="ib-f-lb">축하 메시지</span><div class="ib-gb-editor"></div><span class="ib-f-count">0 / 300</span></div>
            <button type="submit" class="ib-f-submit">남기기</button></form>`;
        let cfg = {}; try { cfg = JSON.parse((listEl && listEl.dataset.ibGb) || '{}'); } catch (e) {}
        const m = openModal('방명록 작성', html, from);
        const form = m.el.querySelector('form');
        // 글꾸미기 도구 + 편집칸 (신랑신부가 "글꾸미기 허용"을 끄면 이모티콘만)
        const rte = rtEditor({ emojiOnly: cfg.richText === false, placeholder: '따뜻한 축하의 한마디를 남겨주세요', minHeight: '110px' });
        form.querySelector('.ib-gb-editor').appendChild(rte.wrap);
        const cnt = form.querySelector('.ib-f-count');
        rte.edit.addEventListener('input', () => { const n = Array.from(rte.getPlain()).length; cnt.textContent = n + ' / 300'; cnt.classList.toggle('over', n > 300); });
        form.addEventListener('submit', e => {
            e.preventDefault();
            const name = form.querySelector('[name=name]').value.trim(), pw = form.querySelector('[name=pw]').value, message = rte.getPlain().trim(); // form.name은 폼 자체 속성이라 쓰면 안 됨
            const messageHtml = cfg.richText === false ? '' : rte.getHtml();
            if (Array.from(message).length > 300) return toast('축하 메시지는 300자까지 쓸 수 있어요');
            if (!name) return toast('이름을 적어주세요');
            if (pw.length < 4) return toast('비밀번호는 4자 이상으로 정해주세요');
            if (message.length < 2) return toast('축하 메시지를 적어주세요');
            if (isEditor()) { toast('미리보기에서는 저장되지 않아요'); m.close(); return; }
            const btn = form.querySelector('.ib-f-submit'); btn.disabled = true; btn.textContent = '남기는 중…';
            postForm('guestbook.php', { action: 'write', name, pw, message, message_html: messageHtml }).then(d => {
                if (!d.ok) throw new Error(d.error || '남기지 못했어요');
                m.close(); toast('축하 메시지를 남겼어요. 감사합니다!');
                if (listEl) gbLoad(listEl, 1);
            }).catch(err => { toast(err.message); btn.disabled = false; btn.textContent = '남기기'; });
        });
    }
    // 게스트스냅 사진 올리기 - 새 창 대신 청첩장 위 팝업 (안에 snap.php를 띄움)
    function openSnapPopup(s, from) {
        const m = openModal('', `<iframe class="ib-snap-frame" src="/invite/snap.php?s=${encodeURIComponent(s)}&embed=1" title="사진 올리기"></iframe>`, from);
        const wrap = m.el.closest('.ib-modal'); if (wrap) wrap.classList.add('ib-modal-snap'); // m.el은 내용 칸 - 창 크기는 바깥 .ib-modal 기준
        return m;
    }
    function gbDelete(btn) {
        const listEl = btn.closest('.ib-gb-list');
        const id = btn.dataset.ibGbDel;
        if (isEditor()) { toast('미리보기의 예시 글이에요'); return; }
        // 글 삭제는 언제나 작성할 때 정한 비밀번호가 있어야 함 (예전엔 같은 휴대폰이면 비밀번호 없이 남의 글도 지워졌음)
        const html = '<form class="ib-form" novalidate><div class="ib-f-row"><label class="ib-f-lb" for="ibGbDelPw">작성할 때 정한 비밀번호</label><input id="ibGbDelPw" name="pw" type="password" maxlength="20" autocomplete="off"></div><button type="submit" class="ib-f-submit" data-go>삭제</button></form>';
        const m = openModal('글 삭제', html, btn);
        const go = e => {
            if (e) e.preventDefault();
            const pwEl = m.el.querySelector('[name=pw]');
            postForm('guestbook.php', { action: 'delete', id, pw: pwEl ? pwEl.value : '' }).then(d => {
                if (!d.ok) throw new Error(d.error || '삭제하지 못했어요');
                m.close(); toast('삭제했어요'); gbLoad(listEl, 1);
            }).catch(err => toast(err.message));
        };
        const f = m.el.querySelector('form'); if (f) f.addEventListener('submit', go);
        else m.el.querySelector('[data-go]').addEventListener('click', go);
    }

    // 버튼 클릭 연결 (문서 전체에 한 번만 - 에디터 미리보기에서도 폼 모양을 볼 수 있게)
    let stage4Bound = false;
    function bindStage4Clicks() {
        if (stage4Bound || typeof document === 'undefined') return; stage4Bound = true;
        document.addEventListener('click', e => {
            const t = e.target;
            if (!t.closest) return;
            // 내려받은 보관용 파일(invite_export.php)은 서버가 없어서 참석 회신·방명록 쓰기가 안 된다
            if (global.INVITE_OFFLINE && t.closest('[data-ib-rsvp],[data-ib-gb-write],[data-ib-gb-del],.ib-gb-more')) {
                e.preventDefault(); e.stopPropagation(); toast('보관용 파일에서는 쓸 수 없는 기능이에요'); return;
            }
            const r = t.closest('[data-ib-rsvp]');
            if (r) { let cfg = {}; try { cfg = JSON.parse(r.dataset.ibRsvp); } catch (err) {} e.stopPropagation(); openRsvpForm(cfg, r); return; }
            const w = t.closest('[data-ib-gb-write]');
            if (w) { e.stopPropagation(); openGbWrite(w.parentElement.querySelector('.ib-gb-list'), w); return; }
            const d = t.closest('[data-ib-gb-del]');
            if (d) { e.stopPropagation(); gbDelete(d); return; }
            const sn = t.closest('[data-ib-snap]');
            if (sn && !isEditor()) { e.preventDefault(); e.stopPropagation(); openSnapPopup(sn.dataset.ibSnap, sn); return; }
            const more = t.closest('.ib-gb-more');
            if (more) { const l = more.parentElement.querySelector('.ib-gb-list'); gbLoad(l, (l._page || 1) + 1); }
        }, true);
    }

    // 청첩장을 연 뒤 인트로(오프닝)가 끝나면 한 번만 팝업: 예식 당일이면 하객 안내, 아니면 참석 여부
    function afterIntro(fn) {
        const t0 = Date.now();
        // 인트로는 이 함수가 불린 직후에 화면에 붙으므로, 조금 기다렸다가부터 인트로가 끝났는지 확인한다
        setTimeout(function wait() {
            const intro = document.querySelector('.intro-overlay');
            if (intro && Date.now() - t0 < 15000) return setTimeout(wait, 300);
            setTimeout(fn, 900);
        }, 150);
    }
    function initStage4(root, d) {
        bindStage4Clicks();
        const blocks = (d.blocks || []).filter(b => b.enabled);
        const find = id => blocks.find(b => b.id === id);
        const s = slug();
        // 이미 참석 여부를 보낸 하객이면 버튼 문구를 "수정하기"로
        if (s && storeGet('ib_rsvp_' + s)) root.querySelectorAll('[data-ib-rsvp]').forEach(x => { try { x.textContent = JSON.parse(x.dataset.ibRsvp).doneLabel || x.textContent; } catch (e) {} });
        // 방명록 목록 불러오기 - 보관용 파일이면 내려받을 때 담아 둔 글을 그대로 보여준다
        if (Array.isArray(global.INVITE_GB_SNAPSHOT)) {
            root.querySelectorAll('[data-ib-gb]').forEach(l => {
                let cfg = {}; try { cfg = JSON.parse(l.dataset.ibGb || '{}'); } catch (e) {}
                l.innerHTML = global.INVITE_GB_SNAPSHOT.length ? gbEntriesHtml(global.INVITE_GB_SNAPSHOT, cfg.style) : '<p class="ib-gb-empty">남겨진 글이 없어요</p>';
                rtLoadFonts(l);
            });
        } else if (s) root.querySelectorAll('[data-ib-gb]').forEach(l => gbLoad(l, 1));
        // 팝업
        const day = find('dayinfo'), rsvp = find('rsvp');
        const dayF = day && Object.assign({}, BLOCKS.dayinfo.defaults, day.fields);
        const rsvpF = rsvp && Object.assign({}, BLOCKS.rsvp.defaults, rsvp.fields);
        if (dayF && dayF.popup !== false && isWeddingDay() && !storeGet('ib_day_' + s, global.sessionStorage)) {
            afterIntro(() => {
                storeSet('ib_day_' + s, '1', global.sessionStorage);
                openModal(dayF.title, `${dayF.desc ? `<p class="ib-day-desc">${nl2br(dayF.desc)}</p>` : ''}${dayItemsHtml(dayF.items)}`, root.querySelector('[data-block-id="dayinfo"]') || root,
                    { footer: '<button type="button" class="ib-f-submit" data-ib-close>확인</button>' });
            });
        } else if (rsvpF && rsvpF.popup && s && !storeGet('ib_rsvp_' + s) && storeGet('ib_rsvp_skip_' + s) !== seoulToday()
                   && !(/^\d{4}-\d{2}-\d{2}$/.test(rsvpF.deadline || '') && seoulToday() > rsvpF.deadline)) {
            afterIntro(() => afterHeroScroll(root, rsvpF.popupAt === 'open', () => {
                if (storeGet('ib_rsvp_' + s) || document.querySelector('.ib-modal, .lbx')) return; // (그새 보냈거나 다른 창이 떠 있으면 안 띄움)
                const cfg = { askHeadcount: rsvpF.askHeadcount !== false, askMeal: rsvpF.askMeal !== false, askPhone: !!rsvpF.askPhone, askMemo: rsvpF.askMemo !== false, doneLabel: rsvpF.doneLabel };
                const m = openModal(rsvpF.title || '참석 의사 전달', `<p class="ib-rsvp-desc">${nl2br(rsvpF.desc)}</p>${rsvpF.deadline ? `<p class="ib-rsvp-dl">${esc(fmtKDate(rsvpF.deadline))}까지 알려주세요</p>` : ''}`, root.querySelector('[data-block-id="rsvp"]') || root,
                    { footer: `<button type="button" class="ib-f-submit" data-go>${esc(rsvpF.buttonLabel || '참석 의사 전달하기')}</button>
                               <button type="button" class="ib-f-skip" data-skip>오늘 하루 보지 않기</button>` });
                m.el.querySelector('[data-go]').addEventListener('click', () => { m.close(); setTimeout(() => openRsvpForm(cfg, root), 240); });
                m.el.querySelector('[data-skip]').addEventListener('click', () => { storeSet('ib_rsvp_skip_' + s, seoulToday()); m.close(); });
            }));
        }
    }
    // 참석 여부 팝업: 맨 위가 메인 사진·영상이면 하객이 메인 화면을 지나 내려갈 때 띄움 (now = 열자마자)
    function afterHeroScroll(root, now, fn) {
        const first = root && root.querySelector('.col[data-block-id]');
        if (now || !first || !/^hero/.test(first.dataset.blockId)) return fn();
        let done = false;
        const check = () => {
            if (done || first.getBoundingClientRect().bottom > global.innerHeight * 0.45) return; // 메인 화면이 절반 넘게 올라가면
            done = true; global.removeEventListener('scroll', check); setTimeout(fn, 350);
        };
        global.addEventListener('scroll', check, { passive: true }); check();
    }

    // ---- 캘린더에 저장 (예식 3시간 전 + 대절버스 출발 30분 전 알림) - 서버 calendar.php가 .ics를 만들어 줌 ----
    function busTime(v) { const m = String(v || '').match(/^\s*(\d{1,2})\s*[:시]\s*(\d{2})?/); if (!m || +m[1] > 23 || +(m[2] || 0) > 59) return ''; return String(+m[1]).padStart(2, '0') + ':' + (m[2] || '00'); }
    function initCalendar(root, d) {
        const s = slug();
        if (!s || (d.extras && d.extras.calendar === false) || root.querySelector('.ib-cal')) return;
        const blocks = (d.blocks || []).filter(b => b.enabled);
        const dd = blocks.find(b => b.id === 'dday');
        if (!(dd && dd.fields && dd.fields.year) && !global.INVITE_WEDDING_DATE) return; // 예식일을 모르면 버튼 없음
        const col = root.querySelector('.col[data-block-id="dday"]') || root.querySelector('.col[data-block-id="location"]');
        if (!col) return;
        const tr = blocks.find(b => b.id === 'transport');
        const hasBus = !!(tr && (tr.fields.items || []).some(it => busTime(it.depart)));
        const box = document.createElement('div');
        box.className = 'ib-cal';
        box.innerHTML = `<button type="button" class="ib-cal-btn" data-ib-cal><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17M8 3v4M16 3v4M12 13.5v4M10 15.5h4"/></svg>캘린더에 저장</button>
            <p class="ib-cal-note">예식 3시간 전에 알림이 울려요${hasBus ? ' · 대절버스 출발 30분 전에도' : ''}</p>`;
        (col.querySelector('.ib-block, .blk-dday, .blk-location') || col).appendChild(box);
        box.querySelector('[data-ib-cal]').addEventListener('click', e => { e.stopPropagation(); addToCalendar(s, box); });
    }
    function addToCalendar(s, from) {
        const ics = location.origin + API + 'calendar.php?s=' + encodeURIComponent(s);
        const ua = navigator.userAgent || '';
        const ios = /iPhone|iPad|iPod/i.test(ua) || (/Macintosh/.test(ua) && 'ontouchend' in document);
        const android = /Android/i.test(ua);
        // 아이폰: .ics를 열면 아이폰 캘린더 앱의 "캘린더에 추가" 화면이 바로 뜸 (카카오톡 안 브라우저는 못 열어서 기본 브라우저로 넘김)
        if (ios) { location.href = /KAKAOTALK/i.test(ua) ? 'kakaotalk://web/openExternal?url=' + encodeURIComponent(ics) : ics; return; }
        if (!android && /KAKAOTALK/i.test(ua)) { location.href = 'kakaotalk://web/openExternal?url=' + encodeURIComponent(ics); return; }
        fetch(API + 'calendar.php?json=1&s=' + encodeURIComponent(s)).then(r => r.json()).then(info => {
            if (!info.ok) throw new Error();
            // 안드로이드: 웹페이지에서 캘린더 앱의 '새 일정' 화면을 바로 여는 건 크롬·삼성 인터넷 보안 정책상 막혀 있음
            //  (intent는 앱이 '브라우저에서 열어도 됨(BROWSABLE)'으로 열어 둔 화면만 열리는데 캘린더 일정 추가 화면은 아님 → 늘 파일 받기로 넘어갔음)
            //  그래서 휴대폰 캘린더 = .ics 파일(받은 뒤 '열기' → 삼성 캘린더 등 추가 화면), 구글 = 웹에서 저장하면 같은 계정의 구글 캘린더 앱에도 바로 보임
            const bus = info.buses.length ? `<p class="ib-cal-bus">🚌 ${info.buses.map(b => esc(b.label) + ' ' + esc(b.time) + ' 출발').join(' · ')}<br><small>구글 캘린더를 고르면 버스 일정은 따로 저장되지 않아요.</small></p>` : '';
            const alarm = `예식 3시간 전${info.buses.length ? '·버스 출발 30분 전' : ''} 알림 포함`;
            const body = `<div class="ib-cal-pick">
                    <a class="ib-cal-opt" data-ics href="${esc(ics)}"><b>${android ? '삼성 캘린더 · 휴대폰 캘린더' : '캘린더 파일'}</b><span>${android ? `파일을 받은 뒤 [열기]를 누르면 캘린더에 추가돼요 · ${alarm}` : `아웃룩·윈도우 캘린더 등 · ${alarm}`}</span></a>
                    <a class="ib-cal-opt" href="${esc(info.google)}" target="_blank" rel="noopener"><b>구글 캘린더</b><span>${android ? '[저장]을 누르면 휴대폰 구글 캘린더 앱에도 바로 보여요' : '구글 캘린더의 기본 알림 설정을 따라요'}</span></a>${bus}</div>`;
            const m = openModal('캘린더에 저장', body, from);
            m.el.querySelectorAll('.ib-cal-opt').forEach(a => a.addEventListener('click', () => { setTimeout(m.close, 300); if (android && a.hasAttribute('data-ics')) setTimeout(() => toast('화면 아래 받은 파일의 [열기]를 누르면 캘린더에 추가돼요'), 900); }));
        }).catch(() => { location.href = ics; });
    }

    function initExtras(root, design) {
        if (typeof document === 'undefined' || !root) return;
        const d = design || {};
        const ex = d.extras || {};
        const sh = d.share || {};
        const shareBlock = (d.blocks || []).find(b => b.id === 'share');
        if (!shareBlock) {
            // "공유하기" 섹션이 생기기 전에 저장된 청첩장: 맨 아래 공유 칸 + 기본 모양의 떠 있는 버튼
            root.insertAdjacentHTML('beforeend', shareBarHtml(sh));
        }
        root.addEventListener('click', e => {
            const b = e.target.closest && e.target.closest('[data-ib-share]');
            if (b) doShare(b.dataset.ibShare, d);
        });
        if (shareBlock) { if (shareBlock.enabled && shareBlock.fields && shareBlock.fields.fab !== false) initShareFab(root, d, shareBlock.fields); }
        else if (sh.floatBtn !== false) initShareFab(root, d, {});
        initBgm(root, d.bgm);
        initCalendar(root, d);
        initTrip(root);
        initLottery(root);
        initAfterWedding(root, d);
        if (ex.topMenu) initTopMenu(root);
        // 공개페이지에서 숨겨야 하는 칸(예식 당일에만 보이는 안내, 꺼둔 공유 버튼 등)은 칸째로 숨김
        root.querySelectorAll('[data-ib-hidecol]').forEach(el => { const c = el.closest('.col'); if (c) c.style.display = 'none'; });
        initStage4(root, d);
    }

    // ---------- 예식 후 감사 인사 + 결혼기념일 ----------
    //  감사 인사는 신랑신부가 켰을 때만 (에디터 화면 설정 → "예식 후 감사 인사", 기본은 꺼짐):
    //   예식 다음 날(한국 시간)부터 대표 사진 아래에 감사 인사 카드가 추가된다. 다른 섹션은 그대로 둔다(숨기지 않음).
    //  매년 예식일과 같은 날: "오늘은 결혼 N주년이에요" 띠
    //  미리 보기: 청첩장 주소 뒤에 ?thankstest=1 (감사 모드) / ?annivtest=1 (1주년)
    const THANKS_DEFAULT = '바쁘신 중에도 귀한 걸음 해주셔서 진심으로 감사합니다.\n보내주신 축하와 마음, 오래 간직하며 예쁘게 잘 살겠습니다.';
    function ymdAdd(ymd, days) {
        const [y, m, d] = ymd.split('-').map(Number), t = new Date(Date.UTC(y, m - 1, d + days));
        return t.toISOString().slice(0, 10);
    }
    function coupleNames(d) {
        const first = s => String(s || '').replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, '').split('\n')[0].trim();
        for (const b of (d.blocks || [])) {
            if ((b.id === 'hero' || b.id === 'heroVideo') && b.fields && (b.fields.groomName || b.fields.brideName)) {
                const g = first(b.fields.groomName), br = first(b.fields.brideName);
                return L.order === 'bride-first' ? [br, g] : [g, br];
            }
        }
        return ['', ''];
    }
    function initAfterWedding(root, d) {
        const w = global.INVITE_WEDDING_DATE;
        if (!w || !/^\d{4}-\d{2}-\d{2}$/.test(w) || isEditor()) return;
        const qs = typeof location !== 'undefined' ? location.search : '';
        const test = /[?&]thankstest=1/.test(qs);
        const today = test ? ymdAdd(w, 1) : /[?&]annivtest=1/.test(qs) ? (+w.slice(0, 4) + 1) + w.slice(4) : seoulToday();
        const tb = (d.blocks || []).find(b => b.id === 'thanks'), tf = Object.assign({}, BLOCKS.thanks.defaults, tb && tb.fields);
        const tbOn = !!(tb && tb.enabled);
        // 대표 사진(히어로) 바로 아래 자리 - 히어로가 없으면 맨 위
        const heroCol = root.querySelector('.col[data-block-id="hero"], .col[data-block-id="heroVideo"]');
        let anchor = heroCol;
        while (anchor && anchor.parentElement && anchor.parentElement !== root) anchor = anchor.parentElement;
        const putTop = node => { if (anchor && anchor.parentElement === root) anchor.after(node); else root.prepend(node); };
        // 감사 인사 섹션: 보이는 때가 되면 대표 사진 바로 아래로 (켜 둔 경우)
        if (tbOn && tf.toTop !== false && (test || thanksVisible(tf.show))) {
            let row = root.querySelector('.col[data-block-id="thanks"]');
            while (row && row.parentElement && row.parentElement !== root) row = row.parentElement;
            if (row && row !== anchor && row.parentElement === root) putTop(row);
        }
        if (today <= w) return;
        // 예식이 끝났으면 참석 의사·D-DAY 안내·캘린더 버튼은 숨김 (감사 인사 섹션에서 끌 수 있음)
        if (tbOn && tf.hideRsvp !== false) {
            ['rsvp', 'dayinfo'].forEach(id => { const c = root.querySelector(`.col[data-block-id="${id}"]`); if (c) c.style.display = 'none'; });
            root.querySelectorAll('.ib-cal').forEach(el => { el.style.display = 'none'; });
        }
        const ex = d.extras || {};
        const [n1, n2] = coupleNames(d);
        let top = '';
        const years = +today.slice(0, 4) - +w.slice(0, 4);
        if (years >= 1 && today.slice(5) === w.slice(5) && (!tb || tf.anniv !== false)) {
            top += `<div class="ib-anniv">💍 오늘은 ${n1 && n2 ? `${esc(n1)} ♥ ${esc(n2)}의 ` : ''}<b>결혼 ${years}주년</b>이에요</div>`;
        }
        // 예전 방식(화면 설정의 감사 인사)으로 저장된 청첩장 - 섹션이 아직 없을 때만
        if (ex.thanks === true && !tb) {
            const sign = n1 && n2 ? `${esc(n1)} · ${esc(n2)} 드림` : '';
            top += `<section class="ib-thanks"><span class="ib-thanks-eye">THANK YOU</span>
                <h3>${esc(ex.thanksTitle || '함께해 주셔서 감사합니다')}</h3>
                <p>${nl2br(ex.thanksText || THANKS_DEFAULT.replace(/\\n/g, '\n'))}</p>${sign ? `<span class="ib-thanks-sign">${sign}</span>` : ''}</section>`;
        }
        if (!top) return;
        const box = document.createElement('div'); box.className = 'ib-after'; box.innerHTML = top;
        putTop(box);
    }



    // ---------- 달력 모양 프리셋 (디데이 섹션의 "달력 보여주기") ----------
    // vintage = 큐피드·비둘기·장미 액자 그림(assets/cal-vintage.webp) 위에 날짜를 그려 넣는 빈티지 달력 (결혼식이 있는 주 + 그 앞 주, 2줄)
    const CAL_STYLES = [['theme', '테마 모양'], ['classic', '기본'], ['vintage', '빈티지 큐피드'], ['minimal', '미니멀'], ['week', '한 주 띠'],
        ['desk', '탁상 달력'], ['night', '밤하늘 골드'], ['heart', '하트'], ['planner', '플래너']];
    const CAL_KEYS = CAL_STYLES.map(x => x[0]);
    const WD_KO = ['일', '월', '화', '수', '목', '금', '토'], WD_EN = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
    const WD_FULL = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'];
    const MON_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    function calWeeks(y, m) { // [[0,0,0,1,2,3,4], ...] (0 = 빈칸)
        const first = new Date(y, m - 1, 1).getDay(), n = new Date(y, m, 0).getDate(), out = [];
        let w = new Array(7).fill(0);
        for (let d = 1; d <= n; d++) { const i = (first + d - 1) % 7; w[i] = d; if (i === 6 || d === n) { out.push(w); w = new Array(7).fill(0); } }
        return out;
    }
    function ensureCalFonts() {
        if (typeof document === 'undefined' || document.getElementById('ldCalFonts')) return;
        const l = document.createElement('link');
        l.id = 'ldCalFonts'; l.rel = 'stylesheet';
        l.href = 'https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,600&family=Nanum+Myeongjo:wght@700;800&display=swap';
        document.head.appendChild(l);
    }
    function ddayCalendar(f) {
        const st = CAL_KEYS.includes(f && f.calendarStyle) ? f.calendarStyle : 'classic';
        const y = +f.year || 2026, m = +f.month || 1, d = +f.day || 1, hh = +f.hour || 0, mi = +f.minute || 0;
        const weeks = calWeeks(y, m), wd = new Date(y, m - 1, d).getDay();
        const row = weeks.findIndex(w => w.includes(d));
        const p2 = n => String(n).padStart(2, '0');
        const ampm = hh < 12 ? '오전' : '오후', h12 = hh % 12 === 0 ? 12 : hh % 12;
        const whenKo = `${ampm} ${h12}시${mi ? ` ${mi}분` : ''}`;
        const cells = (cls, on, extra) => weeks.map(w => w.map((x, i) => x ? `<span class="${cls}${x === d ? ' on' : ''}${i === 0 ? ' sun' : ''}${extra ? extra(x) : ''}">${x === d && on ? on(x) : x}</span>` : `<span class="${cls} e"></span>`).join('')).join('');
        let html;
        switch (st) {
            case 'vintage': {
                ensureCalFonts();
                const rows = row > 0 ? [row - 1, row] : [row, row + 1].filter(r => r < weeks.length);
                const X = i => ((418 + 116.67 * i) / 1536 * 100).toFixed(2), Y = [57.8, 67.4];
                const nums = rows.map((r, k) => weeks[r].map((x, i) => x ? `<span class="cv-n${x === d ? ' on' : ''}" style="left:${X(i)}%;top:${Y[k]}%">${x}</span>` : '').join('')).join('');
                html = `<div class="cv-art"><span class="cv-year">${y}</span><span class="cv-date">${p2(m)}.${p2(d)}</span><span class="cv-wd">${WD_FULL[wd]}</span>`
                    + WD_EN.map((w, i) => `<span class="cv-h${i === 0 ? ' sun' : ''}" style="left:${X(i)}%">${w}</span>`).join('') + nums
                    + `<span class="cv-when">${y}년 ${p2(m)}월 ${p2(d)}일 ${WD_KO[wd]}요일 ${whenKo}</span></div>`;
                break;
            }
            case 'minimal':
                html = `<div class="cm-head"><b>${MON_EN[m - 1]}</b><span>${y}</span></div><div class="cm-grid">${['S', 'M', 'T', 'W', 'T', 'F', 'S'].map((w, i) => `<span class="cm-w${i === 0 ? ' sun' : ''}">${w}</span>`).join('')}${cells('cm-d')}</div>`;
                break;
            case 'week':
                html = `<div class="cw-top">${y}. ${p2(m)}</div><div class="cw-row">${weeks[row].map((x, i) => `<div class="cw-d${x === d ? ' on' : ''}${x ? '' : ' e'}"><i>${WD_KO[i]}</i><b>${x || ''}</b>${x === d ? '<u>♥</u>' : ''}</div>`).join('')}</div><div class="cw-cap">${WD_KO[wd]}요일 ${whenKo}</div>`;
                break;
            case 'desk':
                html = `<div class="cd-rings"><i></i><i></i></div><div class="cd-page"><div class="cd-band">${MON_EN[m - 1].toUpperCase()} ${y}</div><div class="cd-day">${d}</div><div class="cd-wd">${WD_KO[wd]}요일 · ${WD_FULL[wd].charAt(0) + WD_FULL[wd].slice(1).toLowerCase()}</div><div class="cd-time">${whenKo}</div></div>`;
                break;
            case 'night':
                html = `<div class="cn-head"><i>✦</i>${y} · ${p2(m)}<i>✦</i></div><div class="cn-grid">${WD_EN.map((w, i) => `<span class="cn-w${i === 0 ? ' sun' : ''}">${w.charAt(0)}</span>`).join('')}${cells('cn-d')}</div><div class="cn-foot">${WD_FULL[wd].charAt(0) + WD_FULL[wd].slice(1).toLowerCase()}, ${MON_EN[m - 1]} ${d}</div>`;
                break;
            case 'heart':
                html = `<div class="ch-head">♥ ${m}월 ♥</div><div class="ch-grid">${WD_KO.map((w, i) => `<span class="ch-w${i === 0 ? ' sun' : ''}">${w}</span>`).join('')}${cells('ch-d', x => `<i></i><b>${x}</b>`)}</div>`;
                break;
            case 'theme': { // 테마 모양: 디자인 꾸밈(.ib-th-*)마다 CSS가 모양을 정함 (화관·필름 띠·찢은 종이·아르데코 액자 …) - 글자는 다 넣어 두고 테마가 골라 보여 줌
                const wk = weeks[row] || [];
                html = `<div class="ct-head"><span class="ct-big">${p2(m)}</span><span class="ct-mon">${MON_EN[m - 1]}</span><span class="ct-mko">${m}월</span><span class="ct-year">${y}</span><span class="ct-when">${WD_KO[wd]}요일 ${whenKo}</span></div>`
                    + `<div class="ct-feat"><span class="ct-fm">${MON_EN[m - 1].toUpperCase()}</span><b class="ct-fd">${d}</b><span class="ct-fw">${WD_KO[wd]}요일 ${whenKo}</span></div>`
                    + `<div class="ct-grid">${WD_KO.map((w, i) => `<span class="ct-w${i === 0 ? ' sun' : ''}${i === 6 ? ' sat' : ''}"><i>${WD_EN[i].charAt(0)}</i><em>${w}</em><u>${WD_EN[i].charAt(0) + WD_EN[i].slice(1).toLowerCase()}</u></span>`).join('')}`
                    + weeks.map(w => w.map((x, i) => `<span class="ct-d${x ? '' : ' e'}${x === d ? ' on' : ''}${i === 0 ? ' sun' : ''}${i === 6 ? ' sat' : ''}${w === wk ? ' wk' : ''}">${x ? `<b>${x}</b>` : ''}</span>`).join('')).join('') + `</div>`
                    + `<i class="ct-o ct-o1" aria-hidden="true"></i><i class="ct-o ct-o2" aria-hidden="true"></i>`;
                break;
            }
            case 'planner':
                html = `<div class="cp-head"><b>${p2(m)}</b><span>${MON_EN[m - 1].toUpperCase()}<br>${y}</span><em>${WD_KO[wd]}요일 ${whenKo}</em></div><div class="cp-grid">${WD_EN.map(w => `<span class="cp-w">${w}</span>`).join('')}${cells('cp-d', x => `<b>${x}</b><small>Wedding</small>`, x => x < d ? ' past' : '')}</div>`;
                break;
            default:
                html = `<div class="dday-cal-row dday-cal-head">${WD_KO.map(w => `<div>${w}</div>`).join('')}</div><div class="dday-cal-row dday-cal-grid">${weeks.flat().map(x => x ? `<div class="dday-cal-cell${x === d ? ' wedding-day' : ''}">${x === d ? '♥' : x}</div>` : '<div class="dday-cal-cell empty"></div>').join('').replace(/^((<div class="dday-cal-cell empty"><\/div>)*)/, '$1')}</div>`;
                return `<div class="dday-calendar dday-cal cal-classic">${html}</div>`;
        }
        return `<div class="dday-cal cal-${st}">${html}</div>`;
    }

    // ---------- 디데이 카운터 모양 프리셋 (에디터·실제 청첩장 공통) ----------
    // 숫자 칸은 모두 data-dday-part(days|hours|minutes|seconds)라서 카운트다운 갱신 코드는 하나로 같이 씀.
    // 갱신 코드가 --sec(0~59)를 넣어 주면 원형 링이 초에 맞춰 돌고, 숫자가 바뀔 때 .dd-tick으로 넘김 효과.
    const DDAY_STYLES = [['theme', '테마 모양'], ['classic', '클래식'], ['bigd', '큰 D-day'], ['flip', '플립 시계'], ['ring', '원형 링'],
        ['sentence', '문장형'], ['line', '미니멀 라인'], ['ticket', '티켓'], ['bubble', '버블']];
    const DDAY_KEYS = DDAY_STYLES.map(x => x[0]);
    const DD_UNITS = [['days', 'Days', '일'], ['hours', 'Hours', '시간'], ['minutes', 'Minutes', '분'], ['seconds', 'Seconds', '초']];
    const DD_SAMPLE = { days: '123', hours: '04', minutes: '12', seconds: '33' };
    /** opt.sample = 에디터 모양 고르기 칸용 (움직이지 않는 예시 숫자) */
    function ddayCounter(f, targetMs, opt) {
        const st = DDAY_KEYS.includes(f && f.counterStyle) ? f.counterStyle : 'classic';
        const sample = !!(opt && opt.sample);
        const P = k => `<span class="dd-n${st === 'classic' ? ' dday-num' : ''}" data-dday-part="${k}">${sample ? DD_SAMPLE[k] : '00'}</span>`;
        // CSS 변수 안의 url()은 그 변수를 쓰는 CSS 파일(assets/) 기준으로 풀리므로 페이지 기준 절대 주소로 바꿔서 넣는다
        let bg = f && f.bgImage ? String(f.bgImage) : '';
        if (bg && typeof document !== 'undefined') { try { bg = new URL(bg, document.baseURI).href; } catch (e) {} }
        bg = esc(bg);
        let inner;
        switch (st) {
            case 'bigd': inner = `<div class="dd-big"><span class="dd-d">D</span><span class="dd-minus">-</span>${P('days')}</div><div class="dd-sub">${P('hours')}<i>:</i>${P('minutes')}<i>:</i>${P('seconds')}</div>`; break;
            case 'flip': inner = DD_UNITS.map(u => `<div class="dd-u"><div class="dd-card">${P(u[0])}</div><span class="dd-l">${u[1]}</span></div>`).join(''); break;
            case 'ring': inner = `<div class="dd-ring"><div class="dd-ring-in"><span class="dd-l">D-DAY</span>${P('days')}<span class="dd-l">days left</span></div></div><div class="dd-sub">${P('hours')}<i>h</i> ${P('minutes')}<i>m</i> ${P('seconds')}<i>s</i></div>`; break;
            case 'sentence': inner = `<p class="dd-sen">우리의 결혼식까지<br><b>${P('days')}</b>일 <b>${P('hours')}</b>시간 <b>${P('minutes')}</b>분 <b>${P('seconds')}</b>초<br>남았어요</p>`; break;
            case 'line': inner = DD_UNITS.map(u => `<div class="dd-u">${P(u[0])}<span class="dd-l">${u[2]}</span></div>`).join('<i class="dd-sep"></i>'); break;
            case 'ticket': inner = `<div class="dd-tk-l"><span class="dd-l">WEDDING</span><div class="dd-tk-d">D-${P('days')}</div></div><div class="dd-tk-r">${DD_UNITS.slice(1).map(u => `<div class="dd-u">${P(u[0])}<span class="dd-l">${u[2]}</span></div>`).join('')}</div>`; break;
            case 'bubble': inner = DD_UNITS.map(u => `<div class="dd-u">${P(u[0])}<span class="dd-l">${u[2]}</span></div>`).join(''); break;
            case 'theme': { // 테마 모양: 칸 4개 + 진행 막대 + 링 + 한 줄 설명을 다 넣어 두고 디자인 꾸밈(.ib-th-*) CSS가 골라 보여 줌
                const left = sample ? 123 : Math.max(0, Math.ceil(((+targetMs || 0) - Date.now()) / 86400000)), pct = Math.max(6, Math.min(96, Math.round(100 - left / 200 * 100)));
                inner = `<span class="dd-cap">우리의 결혼식까지</span>` + DD_UNITS.map(u => `<div class="dd-u dd-u-${u[0]}">${P(u[0])}<span class="dd-l"><i>${u[1].toUpperCase()}</i><em>${u[2]}</em></span></div>`).join('')
                    + `<span class="dd-bar" style="--dd-pct:${pct}%"><i></i></span><svg class="dd-orb" viewBox="0 0 100 100" aria-hidden="true"><circle cx="50" cy="50" r="44" pathLength="100"></circle><circle class="dd-orb-on" cx="50" cy="50" r="44" pathLength="100" style="stroke-dasharray:${pct} 100"></circle></svg><span class="dd-end">남았어요</span>`;
                break;
            }
            default: inner = DD_UNITS.map(u => `<div class="dday-unit">${P(u[0])}<span class="dday-unit-label">${u[1]}</span></div>`).join('');
        }
        const style = bg ? (st === 'classic' ? ` style="background-image:url('${bg}'); background-size:cover; background-position:center;"` : ` style="--dd-bg:url('${bg}')"`) : '';
        return `<div class="dday-countdown dd-${st}${bg ? ' dd-has-bg' : ''}"${sample ? '' : ` data-dday-target="${+targetMs || 0}"`}${style}>${inner}</div>`;
    }
    /** 카운트다운 숫자 갱신 (에디터·실제 청첩장 공통). 돌려준 함수를 1초마다 부름 */
    function ddayTick(el) {
        const target = parseInt(el.dataset.ddayTarget, 10);
        return () => {
            const diff = Math.max(0, target - Date.now());
            const v = { days: Math.floor(diff / 86400000), hours: Math.floor((diff % 86400000) / 3600000), minutes: Math.floor((diff % 3600000) / 60000), seconds: Math.floor((diff % 60000) / 1000) };
            Object.keys(v).forEach(k => el.querySelectorAll(`[data-dday-part="${k}"]`).forEach(t => {
                const s = String(v[k]).padStart(2, '0');
                if (t.textContent === s) return;
                const first = t.textContent === '00' && !t.dataset.ddInit;
                t.textContent = s; t.dataset.ddInit = '1';
                if (!first) { t.classList.remove('dd-tick'); void t.offsetWidth; t.classList.add('dd-tick'); }
            }));
            el.style.setProperty('--sec', v.seconds);
            el.classList.toggle('dd-done', diff === 0);
        };
    }

    function defaultBlock(id) {
        const def = BLOCKS[id];
        const fields = JSON.parse(JSON.stringify(def.defaults));
        return { id, enabled: !!def.defaultEnabled, width: 100, scrollEffect: 'up', fields };
    }

    /**
     * 에디터에 있는 모든 섹션 [{id, label, color}] - 에디터 기본 순서 그대로.
     * 관리자 → 섹션 순서(admin_sections.php)가 이걸 읽어서 목록을 만든다.
     * 새 섹션은 위 BLOCKS에 넣고 ORDER에 id를 추가하면 관리자 화면에도 자동으로 나타난다.
     * (CORE는 에디터 본문에 처음부터 있던 섹션 - 에디터의 blockLabels와 같은 이름·색)
     */
    const CORE_SECTIONS = [
        ['heroVideo', '메인 영상', '#D6556F'], ['hero', '메인 사진', '#5B7FDE'], ['greeting', '인사말', '#D9A441'],
        ['location', '오시는 길', '#3FA66C'], ['gallery', '갤러리', '#9B59B6'], ['account', '마음 전하실 곳', '#B4862A'],
        ['dday', '디데이 카운트다운', '#1C9099'], ['timeline', '타임라인', '#6C63C9'], ['interview', '인터뷰', '#D6668A'],
    ];
    function sectionCatalog() {
        return CORE_SECTIONS.map(([id, label, color]) => ({ id, label, color, core: true }))
            .concat(ORDER.filter(id => BLOCKS[id]).map(id => ({ id, label: BLOCKS[id].label, color: BLOCKS[id].color || '#999', core: false })));
    }

    global.InviteBlocks = { SECTION_SKINS, THEME_DECOS, THEME_LOOKS, designLook, setColors, applyColorLink, colorChanged, colorLinkOn, curColorKey, IV_STYLES, ivCls, onAccent, HL_DECOS, SKIN_HAS_VINTAGE, SKIN_DARK, hexDark, skinOf, NOTICE_STYLES, NOTICE_TPL, heroFill, heroLayersHtml, heroLayerInner, heroLayerCls, heroLayerCss, fitHeroArcs, fitTextLayers, heroVideoBox, HL_ANIMS, armHeroAnims, playHeroAnims, playHeroAnimsTwice, HERO_BOX_FRAMES, HERO_LAYOUTS, applyHeroLayout, ACC_STYLES, CONTACT_STYLES, CAL_STYLES, ddayCalendar, DDAY_STYLES, ddayCounter, ddayTick, watchOffscreen, stickerFx, heroTextOpts, dockNextButtons, heroNextPos, nextBtnAllowed, heroFull, heroTextOn, heroInkAuto, videoBandSpace, NEXT_FX, NEXT_FX_MS, nextFxOf, playNextFx, armNextFx, HERO_SHADES, HERO_SHADE_LV, heroShadeOf, heroShadeHtml, NEXT_ICONS, NEXT_SHAPES, NEXT_ANIMS, NEXT_ICON_PATHS, BOX_COLOR_SECTIONS, boxColAttrs, freeCanvas, linkHref, nextBtnHtml, bindNextButtons, NEXT_STYLES, NEXT_SIZES, titleLayer, titleLayout, imgKey, applyImgFocus, zoomOf, accHtml, isVenueDemo, heroPhotoHtml, HERO_RATIOS, sectionCatalog, CORE_SECTIONS, BLOCKS, ORDER, setDesign, defaultBlock, esc, uid, imgUrl, ensureFont, FONT_CSS, beatWatch, AMBIENT, WEATHER_FX, ambientHtml, mountAmbient, SPARKLE, SUNGLOW, SPRITES3D, SPRITE_H, BG_PAPERS, paperCss, setPapers, GALLERY_TYPES, GALLERY_REVEALS, galleryHtml, armGalleryReveal, playGalleryReveal, ACCOUNT_ROLES, accountCardsHtml, bindInteractions, toast, setLabels, LABEL_DEFAULTS, initExtras, shareBarHtml, MENU_LABELS, createShareFab, SHARE_DEFAULTS, bindStage4Clicks, RichText, SCROLLBARS, applyScrollbar, tripFeedHtml, drawTripMap, tripSample: fillTripSample };
})(typeof window !== 'undefined' ? window : this);
