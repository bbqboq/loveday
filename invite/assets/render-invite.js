/* render-invite.js
   editor-prototype-v3-overlay.html의 templates/skins 렌더링 로직을 "보기 전용"으로
   추출한 공용 엔진. 드래그/선택/편집 관련 코드는 전부 뺐습니다.
   에디터에서 templates/skins 구조를 바꾸면 여기도 같이 맞춰야 합니다. */

// 뷰포트 메타태그를 스크립트 파싱 즉시 보정 - invite_view.php 쪽에 없거나 다른 값이어도 여기서
// device-width 기준으로 맞춰준다. (스티커/텍스트 위치 문제는 이제 섹션의 실제 자식으로 렌더링해서
// 퍼센트로 위치를 잡는 방식으로 해결했으므로 - renderStickersHtml/renderCustomTextsHtml 참고 - 발행
// 페이지는 다시 방문자 기기 폭 그대로 쓰는 완전 반응형으로 되돌렸다.)
(function ensureViewportMeta() {
    if (typeof document === 'undefined') return;
    let meta = document.querySelector('meta[name="viewport"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'viewport');
        (document.head || document.documentElement).appendChild(meta);
    }
    const content = meta.getAttribute('content') || '';
    if (!/width\s*=\s*device-width/.test(content)) {
        meta.setAttribute('content', 'width=device-width, initial-scale=1');
    }
})();

// design.effects.pinchZoom === false 이면 두 손가락 확대/축소를 막는다 (기본값은 허용 = true)
function applyPinchZoomSetting(design) {
    if (typeof document === 'undefined') return;
    const meta = document.querySelector('meta[name="viewport"]');
    if (!meta) return;
    const allow = !(design && design.effects && design.effects.pinchZoom === false);
    meta.setAttribute('content', allow
        ? 'width=device-width, initial-scale=1'
        : 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no');
}

// 섹션 자석 스크롤: design.effects.snap = soft(가까울 때만 붙음) | hard(항상 섹션 시작에 붙음) | off
function applySnapSetting(design) {
    if (typeof document === 'undefined') return;
    const v = design && design.effects && design.effects.snap;
    document.documentElement.classList.toggle('ld-snap-soft', v === 'soft');
    document.documentElement.classList.toggle('ld-snap-hard', v === 'hard');
}

// PC로 볼 때 카드(.invite-frame, 최대 460px) 옆에 보이는 배경색(desktopSideBg).
// 창폭이 505px 이하(모바일 크기)면 끄고(render-invite.css의 505px 규칙과 같은 기준), 다시 넓히면 켠다.
// 505px 경계를 넘는 순간에는 카드에 늘어나기/줄어들기 애니메이션 클래스도 잠깐 붙인다.
const MOBILE_FULL_MQ = '(max-width: 505px)';
let desktopSideBgMql = null;
let desktopSideBgColor = null;
let desktopSideBgRoot = null;
function paintDesktopSideBg() {
    document.body.style.background = (desktopSideBgColor && !desktopSideBgMql.matches) ? desktopSideBgColor : '';
}
function applyDesktopSideBg(design, rootEl) {
    if (typeof document === 'undefined' || typeof window === 'undefined' || !window.matchMedia) return;
    desktopSideBgColor = (design && design.desktopSideBg) || null;
    if (rootEl) desktopSideBgRoot = rootEl;
    if (!desktopSideBgMql) {
        desktopSideBgMql = window.matchMedia(MOBILE_FULL_MQ);
        let animTimer = null;
        const onChange = () => {
            paintDesktopSideBg();
            const el = desktopSideBgRoot;
            if (!el) return;
            el.classList.remove('bp-expand', 'bp-shrink');
            void el.offsetWidth; // 연속으로 넘나들어도 매번 처음부터 재생
            el.classList.add(desktopSideBgMql.matches ? 'bp-expand' : 'bp-shrink');
            clearTimeout(animTimer);
            animTimer = setTimeout(() => el.classList.remove('bp-expand', 'bp-shrink'), 400);
        };
        if (desktopSideBgMql.addEventListener) desktopSideBgMql.addEventListener('change', onChange);
        else desktopSideBgMql.addListener(onChange);
    }
    paintDesktopSideBg();
}

// 에디터의 fontOptions와 반드시 동기화 - family/weight 조회용 (여긴 CSS 로딩은 안 하고,
// 실제 폰트 CSS는 invite_view.php의 <head>에서 design.customFont 값을 보고 직접 넣어준다)
// 에디터의 STICKER_ICONS와 반드시 동기화
const STICKER_ICONS = {
    'heart-line': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20.5S3.5 15.4 3.5 9.4C3.5 6.4 5.8 4.5 8.3 4.5c1.8 0 3.2 1 3.7 2.5.5-1.5 1.9-2.5 3.7-2.5 2.5 0 4.8 1.9 4.8 4.9 0 6-8.5 11.1-8.5 11.1z"/></svg>',
    'rings': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><circle cx="9" cy="14.5" r="5"/><circle cx="15.5" cy="14.5" r="5"/><path d="M8 9.2 6.5 6l1.7-2M16.3 9.2l1.7-3-1.5-2" stroke-linecap="round"/></svg>',
    'laurel-left': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M21 13c-7 0-15.5 2-18 8"/><path d="M6.5 6.5c2.3 2 3.4 5.5 2.2 8.8M9 4.3c2.2 2.3 3.2 6.6 2 11.2M12 3c1.6 2.8 2 7.7.8 12.8"/></svg>',
    'laurel-right': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M3 13c7 0 15.5 2 18 8"/><path d="M17.5 6.5c-2.3 2-3.4 5.5-2.2 8.8M15 4.3c-2.2 2.3-3.2 6.6-2 11.2M12 3c-1.6 2.8-2 7.7-.8 12.8"/></svg>',
    'flourish': '<svg viewBox="0 0 60 20" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"><path d="M2 10c8-9 16 9 24 0s16-9 24 0"/><circle cx="30" cy="10" r="1.7" fill="currentColor" stroke="none"/></svg>',
    'star-line': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><path d="M12 2.5l2.9 6.6 7.1.8-5.4 4.8 1.6 7-6.2-3.7-6.2 3.7 1.6-7-5.4-4.8 7.1-.8z"/></svg>',
    'sprig': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M12 21V5"/><path d="M12 10.5c-3-1-5.2-3.8-4.3-6.8 3 .2 5 2.3 4.3 5M12 15.5c3-1 5.2-3.8 4.3-6.8-3 .2-5 2.3-4.3 5"/></svg>',
    'dove': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12.5c3-3.8 7-4.6 9-2.6 1.2-2.8 4.4-4.6 8-3.6-2 .8-3 2-2.8 3.8 3-.2 5 .6 6 2.4-3 .2-5 .1-6.8-.8-1.4 2.8-4.4 5.3-8.4 5.1-2.8-.1-5-2-5-4.3z"/><circle cx="7.3" cy="10.8" r=".5" fill="currentColor" stroke="none"/></svg>',
    'bow': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><path d="M12 12L4.5 6.5v11z"/><path d="M12 12l7.5-5.5v11z"/><circle cx="12" cy="12" r="1.7" fill="currentColor" stroke="none"/></svg>',
    'diamond-line': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><path d="M6.5 9L12 3.2 17.5 9 12 20.8z"/><path d="M6.5 9h11M9.3 9l2.7 11.8M14.7 9 12 20.8" stroke-width="1"/></svg>',
    'glasses': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"><path d="M8 3l1.5 8c.3 1.8 1.9 3 3.5 2.8M8 3l-3 .5M8 3l3-.5"/><path d="M13 13.8v6.7M9.5 20.5h7"/><path d="M17.5 3.5l-2 5.5c-.4 1.2.4 2.4 1.7 2.4h.2c1.3 0 2.1-1.2 1.7-2.4l-2-5.5z"/></svg>',
    'envelope': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="1.5"/><path d="M3.5 7l8.5 6.5L20.5 7"/></svg>',
};

const fontOptions = [
    { id:'',               family:'', weight:'' },
    { id:'pretendard',     family:'"Pretendard Variable", Pretendard, sans-serif', weight:'700' },
    { id:'noto-serif-kr',  family:'"Noto Serif KR", serif', weight:'600' },
    { id:'gowun-batang',   family:'"Gowun Batang", serif', weight:'700' },
    { id:'nanum-myeongjo', family:'"Nanum Myeongjo", serif', weight:'800' },
    { id:'gothic-a1',      family:'"Gothic A1", sans-serif', weight:'700' },
    { id:'song-myung',     family:'"Song Myung", serif', weight:'400' },
    { id:'nanum-pen',      family:'"Nanum Pen Script", cursive', weight:'400' },
    { id:'nanum-brush',    family:'"Nanum Brush Script", cursive', weight:'400' },
    { id:'gaegu',          family:'"Gaegu", cursive', weight:'700' },
    { id:'hi-melody',      family:'"Hi Melody", cursive', weight:'400' },
    { id:'gamja-flower',   family:'"Gamja Flower", cursive', weight:'400' },
    { id:'bagel-fat-one',  family:'"Bagel Fat One", sans-serif', weight:'400' },
    { id:'black-han-sans', family:'"Black Han Sans", sans-serif', weight:'400' },
    { id:'great-vibes', family:'"Great Vibes", cursive', weight:'400' },
    { id:'pinyon', family:'"Pinyon Script", cursive', weight:'400' },
    { id:'parisienne', family:'"Parisienne", cursive', weight:'400' },
    { id:'alex-brush', family:'"Alex Brush", cursive', weight:'400' },
    { id:'cormorant', family:'"Cormorant Garamond", serif', weight:'400' },
    { id:'playfair', family:'"Playfair Display", serif', weight:'400' },
    { id:'bangers', family:'"Bangers", sans-serif', weight:'400' },
];

// 오시는 길 지도(카카오맵) - config.php와 동일한 JavaScript 키를 써야 한다
const KAKAO_MAP_APP_KEY = '9d5208f041541d3a79f70ffc2f4f7c58';
// 카카오톡 공유도 같은 앱의 JavaScript 키를 쓴다 (카카오 개발자센터 → 앱 → 플랫폼 → Web에 loveday.kr 등록 필요)
if (typeof window !== 'undefined') window.KAKAO_JS_KEY = window.KAKAO_JS_KEY || KAKAO_MAP_APP_KEY;
let kakaoMapSdkLoading = null;
function loadKakaoMapSdk() {
    if (window.kakao && window.kakao.maps) return Promise.resolve();
    if (kakaoMapSdkLoading) return kakaoMapSdkLoading;
    kakaoMapSdkLoading = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `//dapi.kakao.com/v2/maps/sdk.js?appkey=${KAKAO_MAP_APP_KEY}&autoload=false&libraries=services`;
        script.onload = () => window.kakao.maps.load(resolve);
        script.onerror = reject;
        document.head.appendChild(script);
    });
    return kakaoMapSdkLoading;
}
// [모바일 로딩 속도] 카카오맵 SDK(스크립트+지도 타일)는 무거운데, 오시는 길은 보통 페이지 중간 아래에 있다.
// 예전엔 페이지를 열자마자 받아서 첫 화면이 뜨는 데 쓸 네트워크를 같이 잡아먹었다 - 지도가 화면 근처
// (600px 앞)까지 스크롤돼 왔을 때 처음 불러오도록 미룬다.
function initLocationMaps(root) {
    (root || document).querySelectorAll('.location-map[data-address]').forEach(mapEl => {
        if (!mapEl.dataset.address || mapEl.dataset.mapInited || mapEl.dataset.mapWaiting) return;
        if (typeof IntersectionObserver === 'undefined') { initOneLocationMap(mapEl); return; }
        mapEl.dataset.mapWaiting = '1';
        const io = new IntersectionObserver(entries => {
            if (!entries.some(e => e.isIntersecting)) return;
            io.disconnect();
            initOneLocationMap(mapEl);
        }, { rootMargin: '600px 0px' });
        io.observe(mapEl);
    });
}
function initOneLocationMap(mapEl) {
    {
        const address = mapEl.dataset.address;
        if (!address || mapEl.dataset.mapInited) return;
        mapEl.dataset.mapInited = '1';
        loadKakaoMapSdk().then(() => {
            const geocoder = new kakao.maps.services.Geocoder();
            geocoder.addressSearch(address, (result, status) => {
                if (status !== kakao.maps.services.Status.OK || !result[0]) {
                    console.warn('[카카오맵] 주소 검색 실패 - status:', status, '/ 주소:', address);
                    mapEl.innerHTML = `<p style="font-size:12px;color:#999;text-align:center;padding:20px 0;">지도를 표시할 수 없습니다<br><span style="font-size:10px;">status: ${status}</span></p>`;
                    return;
                }
                try {
                    const coords = new kakao.maps.LatLng(result[0].y, result[0].x);
                    const map = new kakao.maps.Map(mapEl, { center: coords, level: 3 });
                    new kakao.maps.Marker({ map, position: coords });
                    map.addControl(new kakao.maps.ZoomControl(), kakao.maps.ControlPosition.RIGHT);
                    // 카카오맵은 생성 시점 컨테이너 크기를 기준으로 내부 캔버스를 그리고, 그 뒤로
                    // 컨테이너 폭이 바뀌어도(PC 창을 모바일 폭으로 줄이는 경우 등) 스스로 다시 맞추지
                    // 않는다 - relayout()을 직접 호출해줘야 한다. 안 해주면 지도가 원래 그렸던
                    // (더 넓은) 크기 그대로 남아서 카드 폭보다 훨씬 넓게 삐져나가 있었다.
                    if (typeof ResizeObserver !== 'undefined') {
                        let relayoutRaf = null;
                        new ResizeObserver(() => {
                            if (relayoutRaf) return;
                            relayoutRaf = requestAnimationFrame(() => {
                                relayoutRaf = null;
                                map.relayout();
                                map.setCenter(coords); // relayout()이 중심을 컨테이너 좌상단 기준으로 흐트러뜨리므로 다시 잡아준다
                            });
                        }).observe(mapEl);
                    }
                } catch (renderErr) {
                    console.error('[카카오맵] 지도 렌더링 중 오류:', renderErr);
                    mapEl.innerHTML = `<p style="font-size:12px;color:#999;text-align:center;padding:20px 0;">지도 렌더링 중 오류가 발생했습니다<br><span style="font-size:10px;">${esc(String(renderErr.message || renderErr))}</span></p>`;
                }
            });
        }).catch(err => {
            console.error('[카카오맵] SDK 로드 실패:', err, '/ 현재 도메인:', location.hostname);
            mapEl.innerHTML = `<p style="font-size:12px;color:#999;text-align:center;padding:20px 0;">지도를 불러오지 못했습니다<br><span style="font-size:10px;">현재 도메인(${location.hostname})이 카카오 개발자센터에 등록됐는지 확인해주세요</span></p>`;
        });
    }
}

const skins = {
    classic: { bg:'#FAF7F0', ink:'#2B2320', accent:'#7A3B41', line:'#E1D6C6', muted:'#8A7F72',
        headFont:'"Noto Serif KR", serif', bodyFont:'"Pretendard Variable", Pretendard, sans-serif', headWeight:'500', radius:'2px' },
    modern: { bg:'#FFFFFF', ink:'#15151A', accent:'#24339B', line:'#E7E7E9', muted:'#7A7A82',
        headFont:'"Pretendard Variable", Pretendard, sans-serif', bodyFont:'"Pretendard Variable", Pretendard, sans-serif', headWeight:'800', radius:'0px' },
    pastel: { bg:'#FBF8F2', ink:'#4A443C', accent:'#C97A82', line:'#EEF1E7', muted:'#93897A',
        headFont:'"Gowun Batang", serif', bodyFont:'"Pretendard Variable", Pretendard, sans-serif', headWeight:'700', radius:'16px' }
};

// 디자인 프리셋(16종)이 생기면서 스킨 색상이 에디터 쪽에만 수십 개가 될 수 있다 - 여기에 매번 똑같이
// 적어 두면 두 파일이 어긋나기 쉬워서, 에디터가 저장할 때 그 청첩장이 쓰는 색 묶음(design.palette)을
// 같이 넣어준다. palette가 있으면 그걸 쓰고, 없으면(프리셋 이전에 저장된 청첩장) 예전처럼 skins에서 찾는다.
function resolveSkin(design) {
    const base = skins[design && design.skin] || skins.classic;
    const pal = design && design.palette;
    if (!pal || typeof pal !== 'object') return base;
    return Object.assign({}, base, pal);
}

function esc(s) { return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function extractYoutubeId(input) {
    const raw = (input || '').trim();
    if (!raw) return '';
    if (!/^https?:\/\//i.test(raw) && !/youtu\.be|youtube\.com/i.test(raw)) return raw;
    try {
        const url = new URL(raw.startsWith('http') ? raw : 'https://' + raw);
        if (url.hostname.includes('youtu.be')) return url.pathname.split('/').filter(Boolean)[0] || raw;
        if (url.searchParams.get('v')) return url.searchParams.get('v');
        const m = url.pathname.match(/\/(?:shorts|embed)\/([a-zA-Z0-9_-]+)/);
        if (m) return m[1];
    } catch (e) {}
    return raw;
}

function ytEmbedSrc(rawId) {
    const id = extractYoutubeId(rawId);
    if (!id) return '';
    const q = new URLSearchParams({
        autoplay: '1', mute: '1', loop: '1', playlist: id, controls: '0', playsinline: '1', rel: '0',
        enablejsapi: '1', origin: location.origin
    });
    return `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?${q.toString()}`;
}

// 화면 폭이 다르면(PC vs 모바일) 글자크기가 고정 px라서 줄바꿈 지점이 달라지고 좌표가 어긋나 보였다.
// cqw(컨테이너 폭의 1%)로 바꾸면 화면 폭과 무관하게 "글자크기 대 박스폭" 비율이 항상 같아서 레이아웃이 일관된다.
const FONT_SCALE_BASE = 390; // 에디터의 "표준화면" 폭과 맞춘 기준값
function fontToCqw(px) {
    return (px / FONT_SCALE_BASE * 100).toFixed(3) + 'cqw';
}
// 스티커 뿅 작아지기 방향 [키 → [x, y]] - 에디터 STICKER_HIDE_DIRS와 같게
const STICKER_HIDE_DIRS = { nw: [-1, -1], n: [0, -1], ne: [1, -1], w: [-1, 0], c: [0, 0], e: [1, 0], sw: [-1, 1], s: [0, 1], se: [1, 1] };
// 문구 그림자 (인트로·자유 텍스트) - 에디터의 INTRO_TEXT_SHADOW와 반드시 같게
const INTRO_TEXT_SHADOW = '0 2px 6px rgba(0,0,0,.38), 0 0 1px rgba(0,0,0,.3)';
const INTRO_TEXT_OUTLINE = '-1px -1px 0 rgba(0,0,0,.55), 1px -1px 0 rgba(0,0,0,.55), -1px 1px 0 rgba(0,0,0,.55), 1px 1px 0 rgba(0,0,0,.55), 0 0 3px rgba(0,0,0,.35)';
// 글자 테두리 (에디터 팝업 "테두리": 그림자 shadow / 외곽선 outline)
function textEdge(o) {
    if (o && /^#[0-9A-Fa-f]{6}$/.test(o.glow || '')) { const g = o.glow; return `0 0 4px ${g}, 0 0 10px ${g}, 0 0 18px ${g}`; } // 글로우 (글 뒤에 은은한 빛)
    return o && o.outline ? INTRO_TEXT_OUTLINE : o && o.shadow ? INTRO_TEXT_SHADOW : '';
}
// 스티커 테두리 (에디터 ✨ 팝업 "테두리") - 에디터 STICKER_EDGE와 같게
const STICKER_EDGE = {
    shadow: 'drop-shadow(0 2px 3px rgba(0,0,0,.35))',
    outline: 'drop-shadow(1.5px 0 0 #fff) drop-shadow(-1.5px 0 0 #fff) drop-shadow(0 1.5px 0 #fff) drop-shadow(0 -1.5px 0 #fff) drop-shadow(0 1px 2px rgba(0,0,0,.28))',
};
// 자간: 1/1000 글자 단위 정수 (에디터 lsEm과 같게)
function lsEm(v) { return v ? (Math.round(v) / 1000) + 'em' : ''; }
function partStyle(layout, key) {
    const p = layout[key];
    if (!p) return '';
    let extra = '';
    if (p.isCustom) {
        const fontOpt = fontOptions.find(f => f.id === p.font);
        extra = `color:${p.color || 'inherit'}; font-family:${(fontOpt && fontOpt.family) || 'inherit'}; `;
    } else if (p.font) { // 섹션 문구마다 고른 글꼴 (에디터 문구 팝업·여러 개 고르기 팝업)
        const fontOpt = fontOptions.find(f => f.id === p.font);
        if (fontOpt && fontOpt.family) extra = `font-family:${fontOpt.family.replace(/"/g, "'")}; `;
    }
    const alignCss = p.align ? `text-align:${p.align}; ` : ''; // 에디터 팝업의 정렬/회전 값 (없으면 기존 모양 그대로)
    const rotCss = p.rotation ? ` rotate(${p.rotation}deg)` : '';
    const widthCss = p.widthAuto ? '' : `width:${p.width}%; `; // 에디터와 동일: 자동폭이면 폭을 강제하지 않는다 (강제하면 좁은 화면에서 글자가 줄바꿈되어 깨짐)
    const lsCss = (p.ls ? `letter-spacing:${lsEm(p.ls)}; ` : '') + (textEdge(p) ? `text-shadow:${textEdge(p)}; ` : '') + (!p.isCustom && p.color && /^#[0-9A-Fa-f]{6}$/.test(p.color) ? `color:${p.color}; ` : ''); // 글자색 (에디터 팝업) // 자간 (에디터 팝업, 1/1000 글자 단위) · 테두리
    return `left:${p.x}%; top:${p.y}%; font-size:${fontToCqw(p.fontSize)}; ${widthCss}${extra}${alignCss}${lsCss}transform:translate(-50%,-50%) scaleX(${p.scaleX / 100})${rotCss};`;
}

function scaleImgUrl(src, scale) {
    if (!scale || scale === 1) return src;
    return src.replace(/\/(\d+)\/(\d+)$/, (m, w, h) => `/${parseInt(w, 10) * scale}/${parseInt(h, 10) * scale}`);
}

function defaultGalleryImages() {
    return ['a', 'b', 'c'].map(s => ({ id: 'g' + s, src: `https://picsum.photos/seed/gallery${s}/400/400` }));
}

// 계좌 파츠는 마스킹 상태면 실제 값 대신 이 표시를 보여주고, data-masked/data-part를 남겨서
// 나중에 스크립트로 진짜 값을 채워 넣을 수 있게 함 (bindGuestSecure / guest_secure.php 참고)
const MASK_TEXT = '계좌번호 보기';

// 예전엔 스티커 업로드가 상대경로(uploads/stickers/...)로 저장됐었다. 에디터(/invite/ 아래)에서 볼 땐
// 문제없이 보였지만, 짧은 공개주소(loveday.kr/코드)로 보면 /invite/ 없이 도메인 루트 기준으로 깨졌다.
// 이미 저장된 청첩장들을 재업로드 없이 고치기 위해, 렌더링 시점에 상대경로면 앞에 /invite/를 붙여준다.
function normalizeUploadUrl(url) {
    if (!url) return url;
    if (url.startsWith('/uploads/')) return '/invite' + url; // 실제 파일은 /invite/uploads/ 아래에 있다
    if (url.startsWith('/') || url.startsWith('http://') || url.startsWith('https://') || url.startsWith('data:')) return url;
    return '/invite/' + url;
}

// 갤러리/대표사진/인트로 배경/D-day 배경 등 모든 이미지 주소를 한 번에 보정한다.
// (저장된 주소가 uploads/... 또는 /uploads/... 로 되어 있으면 짧은 공개주소에서 404가 난다)
function fixDesignUploadUrls(node) {
    if (Array.isArray(node)) { node.forEach((v, i) => { if (typeof v === 'string') { if (/^\/?uploads\//.test(v)) node[i] = normalizeUploadUrl(v); } else fixDesignUploadUrls(v); }); return node; }
    if (node && typeof node === 'object') {
        Object.keys(node).forEach(k => {
            const v = node[k];
            if (typeof v === 'string') { if (/^\/?uploads\//.test(v)) node[k] = normalizeUploadUrl(v); }
            else fixDesignUploadUrls(v);
        });
    }
    return node;
}

// 스티커(장식 이모지) - 공개페이지에서는 드래그 없이 저장된 위치/크기 그대로만 보여준다.
// 이제 페이지 전체 위에 뜨는 전역 레이어가 아니라, 각자 앵커된 섹션(.col)의 실제 자식으로
// 그려서(renderInviteReadOnly 참고) 위치가 그 섹션 기준 순수 CSS %가 되므로, 방문자 기기 폭이
// 얼마든 브라우저가 항상 알아서 다시 계산해준다.
// 스티커 반전 (에디터의 ⇋ 좌우 / ⇅ 상하) - 회전 뒤에 붙여서 스티커 자기 축 기준으로 뒤집힌다
function stickerFlipCss(st) { return (st && st.flipX ? ' scaleX(-1)' : '') + (st && st.flipY ? ' scaleY(-1)' : ''); }
function renderStickersHtml(stickers) {
    if (!stickers || !stickers.length) return '';
    return stickers.map(st => {
        const rot = st.rotation || 0;
        // reveal-* 클래스가 넘겨주는 --rv-tx/ty/rot/scale과 합성 - 안 그러면 이 인라인 transform이
        // 클래스의 transform을 덮어써서 등장 효과가 하나도 안 보인다.
        // left/top은 그려질 부모(섹션 또는 root) 기준 %로 직접 적는다.
        let style = `left:${st.relX * 100}%; ${anchorTopCss(st)} transform:translate(calc(-50% + var(--rv-tx,0px)),calc(-50% + var(--rv-ty,0px))) rotate(calc(${rot}deg + var(--rv-rot,0deg))) scale(var(--rv-scale,1))${stickerFlipCss(st)}; z-index:${st.zIndex ?? 10};`;
        const sizeCqw = fontToCqw(st.size);
        // 등장 딜레이(에디터 스티커 조작 틀 ⏱): 화면에 들어온 뒤 이만큼 기다렸다가 등장. 효과가 "없음"이면 '팝'으로
        const delay = Math.max(0, Math.min(5, Number(st.delay) || 0));
        const hide = Math.max(0, Math.min(10, Number(st.hideAfter) || 0)); // 나타난 뒤 몇 초 뒤 다시 사라지기
        const eff = (st.effect && st.effect !== 'none') ? st.effect : (delay || hide || st.sparkle ? 'pop' : '');
        const puff = hide && st.hideFx === 'puff'; // 사라지는 모양: 뿅(작아지기) / 연기처럼
        const fxClass = (eff ? ` reveal-${eff}` : '') + (hide ? ' rv-vanish' : '') + (puff ? ' rv-puff' : '');
        const fxData = (st.sparkle ? ' data-fx-spark="1"' : '') + (puff ? ' data-hide-fx="puff"' : ''); // 반짝이·연기 알갱이 (InviteBlocks.stickerFx)
        if (delay) style += ` --rv-delay:${delay}s;`;
        if (hide) style += ` --rv-hide:${hide}s;`;
        if (hide && !puff && STICKER_HIDE_DIRS[st.hideTo]) { const [vx, vy] = STICKER_HIDE_DIRS[st.hideTo]; style += ` --vx:${vx}; --vy:${vy};`; } // 뿅 작아지는 방향
        if (STICKER_EDGE[st.edge]) style += ` filter:${STICKER_EDGE[st.edge]};`; // 테두리
        if (st.image) return `<img class="sticker-el sticker-image${fxClass}" data-sticker-id="${st.id}"${fxData} src="${esc(normalizeUploadUrl(st.image))}" alt="" draggable="false" style="${style} width:${sizeCqw}; height:${sizeCqw};">`;
        return st.icon
            ? `<span class="sticker-el sticker-icon${fxClass}" data-sticker-id="${st.id}"${fxData} style="${style} width:${sizeCqw}; height:${sizeCqw};">${STICKER_ICONS[st.icon] || ''}</span>`
            : `<span class="sticker-el${fxClass}" data-sticker-id="${st.id}"${fxData} style="${style} font-size:${sizeCqw};">${st.emoji}</span>`;
    }).join('');
}

// ===== 스티커/자유텍스트 "섹션 기준" 위치 앵커 =====
// 에디터(editor-prototype-v3-overlay.html)와 동일한 로직 - 자세한 이유는 그쪽 주석 참고.
// 요약: 카드 전체 높이 대비 %(x,y)로 저장하면, 화면 모드/기기 폭에 따라 카드 총 높이가 달라져서
// 같은 %가 서로 다른 자리를 가리키게 된다. 그래서 스티커가 놓인 섹션 자신의 폭/높이를 기준으로
// 한 상대좌표(sectionId+relX/relY)를 쓰고, 렌더링 시엔 그 섹션의 실제 DOM 자식으로 순수 CSS %에
// 꽂아서 JS가 픽셀을 재계산할 필요 자체를 없앤다.
// 스티커·자유 텍스트 세로 위치: 섹션 높이 대비 %(relY)만 쓰면, 그 섹션 높이가 에디터와 실제 화면에서 다를 때
// (계좌 칸 접힘·실제 데이터 길이·사진 비율 등) 크게 어긋났다. 그래서 가까운 가장자리(위/아래)에서
// "섹션 폭 대비" 거리(offY)로도 저장한다 → 섹션 높이가 달라도 그 가장자리 근처에 정확히 붙어 있다.
// (세로 margin % 는 폭 기준이라 순수 CSS로 그려짐. offY가 없는 예전 저장분은 relY 그대로)
function anchorTopCss(item) {
    if (item && item.offY != null && (item.edgeY === 't' || item.edgeY === 'b')) return `top:${item.edgeY === 'b' ? '100%' : '0'}; margin-top:${(item.offY * 100).toFixed(4)}%;`;
    return `top:${(item.relY ?? 0.5) * 100}%;`;
}
function edgeAnchor(box, yPx) { // box: 섹션의 root 기준 px 상자 → { edgeY, offY }
    if (!box.width) return {};
    const relY = box.height ? (yPx - box.top) / box.height : 0.5;
    return relY > 0.5 ? { edgeY: 'b', offY: (yPx - (box.top + box.height)) / box.width } : { edgeY: 't', offY: (yPx - box.top) / box.width };
}
function anchorYPx(box, item) {
    if (item.offY != null && (item.edgeY === 't' || item.edgeY === 'b')) return (item.edgeY === 'b' ? box.top + box.height : box.top) + item.offY * box.width;
    return box.top + (item.relY ?? 0.5) * box.height;
}
function findSectionEl(root, sectionId) {
    return root.querySelector(`.col[data-block-id="${sectionId}"]`);
}
function sectionBoxRelativeToRoot(root, sectionEl) {
    const rootRect = root.getBoundingClientRect();
    const elRect = sectionEl.getBoundingClientRect();
    return {
        left: elRect.left - rootRect.left,
        top: elRect.top - rootRect.top,
        width: elRect.width,
        height: elRect.height,
    };
}
function findSectionForPoint(root, xPx, yPx) {
    const cols = Array.from(root.querySelectorAll('.col[data-block-id]'));
    if (!cols.length) return null;
    const rootRect = root.getBoundingClientRect();
    let best = null, bestDist = Infinity;
    cols.forEach(col => {
        const r = col.getBoundingClientRect();
        const top = r.top - rootRect.top, bottom = r.bottom - rootRect.top;
        const left = r.left - rootRect.left, right = r.right - rootRect.left;
        const inside = yPx >= top && yPx <= bottom && xPx >= left && xPx <= right;
        const dist = inside ? 0 : Math.max(0, top - yPx, yPx - bottom, left - xPx, xPx - right);
        if (dist < bestDist) { bestDist = dist; best = col; }
    });
    return best;
}
function anchorFromPixels(root, xPx, yPx) {
    const sectionEl = findSectionForPoint(root, xPx, yPx);
    if (!sectionEl) return null;
    const box = sectionBoxRelativeToRoot(root, sectionEl);
    const relX = box.width ? (xPx - box.left) / box.width : 0.5;
    const relY = box.height ? (yPx - box.top) / box.height : 0.5;
    return Object.assign({ sectionId: sectionEl.dataset.blockId, relX, relY }, edgeAnchor(box, yPx));
}
// 저장된 앵커(또는 예전 형식의 x/y %)를 root 기준 절대 픽셀 좌표로 되돌린다. sectionId가 없거나
// (예전 저장분) 그 섹션이 지금은 없으면(삭제/비활성화됨) 예전 방식인 "카드 전체 대비 %"로 대체한다.
function pixelsFromAnchor(root, item) {
    if (item.sectionId) {
        const sectionEl = findSectionEl(root, item.sectionId);
        if (sectionEl) {
            const box = sectionBoxRelativeToRoot(root, sectionEl);
            return { x: box.left + (item.relX ?? 0.5) * box.width, y: anchorYPx(box, item) };
        }
    }
    const rootRect = root.getBoundingClientRect();
    return { x: ((item.x ?? 50) / 100) * rootRect.width, y: ((item.y ?? 50) / 100) * rootRect.height };
}
// 스티커/커스텀텍스트 하나가 지금 어느 부모(섹션 .col 또는 root) 밑에 %로 그려져야 하는지 정한다.
// sectionId가 있고 그 섹션이 실제로 지금 화면에 있으면 그 섹션의 자식으로(그 섹션 기준 %),
// 없으면 예전 방식인 "카드 전체 기준 %"로 root의 자식으로 - 크래시 없이 어딘가에는 보이게 하기
// 위한 안전망. 에디터의 resolveAnchorContainer/groupAnchoredItems와 동일한 로직.
function resolveAnchorContainer(root, item) {
    if (item.sectionId) {
        // 메인 사진 ↔ 메인 영상을 바꿨으면 지금 켜진 쪽에 (같은 첫 화면 자리)
        const sectionEl = findSectionEl(root, item.sectionId) || ((item.sectionId === 'hero' || item.sectionId === 'heroVideo') ? findSectionEl(root, item.sectionId === 'hero' ? 'heroVideo' : 'hero') : null);
        if (sectionEl) return { container: sectionEl, relX: item.relX ?? 0.5, relY: item.relY ?? 0.5, edgeY: item.edgeY, offY: item.offY };
        return null; // 붙은 섹션이 꺼져 있으면 안 그림 (예전엔 청첩장 한가운데로 몰려 첫 화면 글자를 가렸음 - 섹션을 켜면 다시 나옴)
    }
    return { container: root, relX: (item.x ?? 50) / 100, relY: (item.y ?? 50) / 100, edgeY: undefined, offY: undefined };
}
function groupAnchoredItems(root, stickers, customTexts) {
    const groups = new Map();
    const pushInto = (key, kind, resolved) => {
        if (!groups.has(key)) groups.set(key, { stickers: [], customTexts: [] });
        groups.get(key)[kind].push(resolved);
    };
    (stickers || []).forEach(st => {
        const r = resolveAnchorContainer(root, st); if (!r) return;
        const { container, relX, relY, edgeY, offY } = r;
        pushInto(container, 'stickers', { ...st, relX, relY, edgeY, offY });
    });
    (customTexts || []).forEach(ct => {
        const r = resolveAnchorContainer(root, ct); if (!r) return;
        const { container, relX, relY, edgeY, offY } = r;
        pushInto(container, 'customTexts', { ...ct, relX, relY, edgeY, offY });
    });
    return groups;
}
// 스크롤로 화면에 들어올 때 등장 효과(.reveal-*)를 트리거하는 공용 옵저버 - 에디터의 initScrollReveal과 동일 로직
// (공개페이지는 #previewRoot 같은 별도 스크롤 컨테이너가 없으므로 root:null = 뷰포트 기준)
function initScrollReveal(root) {
    if (!root) return;
    const targets = root.querySelectorAll('.reveal-up,.reveal-zoom,.reveal-pop,.reveal-left,.reveal-right,.reveal-fade,.reveal-bounce,.reveal-boing,.reveal-spin');
    if (!targets.length) return;
    // 스크롤해 들어오면 재생, 다시 올려서 화면 "아래쪽"으로 완전히 나가면 숨김으로 되돌려 다음에 또 재생.
    // 예전엔 들어오고/나갈 때마다 단순 토글했는데, 숨김 상태의 효과(아래로 40px 내림·축소 등)가 섹션 위치를 바꾸다 보니
    // 화면 맨 위 경계에 걸친 섹션은 "나감→숨김(위치 이동)→다시 들어옴→재생→나감…"이 끝없이 반복됐다.
    // 그래서 위로 지나간 섹션은 그대로 보이게 두고, 아래로 완전히 빠졌을 때만 되돌린다.
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const el = entry.target;
            const bigEnough = entry.rootBounds && entry.intersectionRect.height >= entry.rootBounds.height * 0.3; // 화면보다 훨씬 긴 섹션은 20%를 못 채우므로
            if (entry.isIntersecting && (entry.intersectionRatio >= 0.2 || bigEnough)) { if (!el.classList.contains('in-view')) { el.classList.add('in-view'); if (window.InviteBlocks && InviteBlocks.stickerFx) InviteBlocks.stickerFx(el); } return; }
            const viewBottom = entry.rootBounds ? entry.rootBounds.bottom : window.innerHeight;
            if (!entry.isIntersecting && entry.boundingClientRect.top >= viewBottom) el.classList.remove('in-view');
        });
    }, { root: null, threshold: [0, 0.05, 0.1, 0.2], rootMargin: '0px 0px -12% 0px' });
    targets.forEach(el => observer.observe(el));
}
// 장식 효과(하트/꽃잎/반짝임) - 스크롤과 무관하게 카드 안에서 계속 떠다니며 순환 재생되는 오버레이 마크업 - 에디터와 동일
function renderAmbientEffectHtml(kind) {
    if (!kind || kind === 'none') return '';
    if (window.InviteBlocks) return InviteBlocks.ambientHtml(kind); // 공용 파일(11종) - 에디터와 동일
    const glyphs = { hearts: ['♥', '♡'], petals: ['🌸', '❀'], sparkles: ['✦', '✧', '⋆'] }[kind] || [];
    if (!glyphs.length) return '';
    let spans = '';
    for (let i = 0; i < 22; i++) {
        const left = Math.random() * 100;
        const size = 10 + Math.random() * 14;
        const dur = 18 + Math.random() * 18; // 기존의 2배 속도로 천천히 떠오르게
        const delay = Math.random() * 24;
        const glyph = glyphs[Math.floor(Math.random() * glyphs.length)];
        spans += `<span style="left:${left}%;font-size:${size}px;animation-duration:${dur}s;animation-delay:${delay}s;">${glyph}</span>`;
    }
    return `<div class="ambient-field ambient-${kind}">${spans}</div>`;
}

// 스티커와 동일하게 페이지 전체 위에 뜨는 전역 텍스트 레이어 (에디터의 renderCustomTextsHtml과 동일)
function renderCustomTextsHtml(customTexts) {
    if (!customTexts || !customTexts.length) return '';
    return customTexts.map(ct => {
        const anchorX = { left: '0%', center: '-50%', right: '-100%' }[ct.align || 'center'];
        // left/top은 그려질 부모(섹션 또는 root) 기준 %로 직접 적는다.
        const style = `left:${ct.relX * 100}%; ${anchorTopCss(ct)} transform:translate(${anchorX},-50%) scaleX(${(ct.scaleX ?? 100) / 100}) rotate(${ct.rotation || 0}deg); font-size:${fontToCqw(ct.fontSize)}; color:${ct.color || 'inherit'}; z-index:${ct.zIndex ?? 10};${ct.ls ? ` letter-spacing:${lsEm(ct.ls)};` : ''}${textEdge(ct) ? ` text-shadow:${textEdge(ct)};` : ''}`;
        return `<span class="custom-text-el" data-ct-id="${ct.id}" style="${style}">${esc(ct.text)}</span>`;
    }).join('');
}

// 첫 화면 인트로 애니메이션 - 방문자가 페이지를 열 때 한 번 자동 재생되고, 재생 중엔 스크롤을 막는다
// 문구를 타자기처럼 한 글자씩 나타나게 타이핑한다 (에디터의 typewriterInto와 동일 로직)
function typewriterInto(textEl, text, duration) {
    if (textEl._typewriterTimer) clearInterval(textEl._typewriterTimer);
    const chars = [...text]; // 스프레드로 분리해야 한글 음절이 안 깨짐
    const typingBudget = duration * 1000 * 0.5; // 재생길이의 절반은 타이핑, 나머지 절반은 완성된 문구를 그대로 멈춰서 보여줌
    const perChar = Math.max(35, typingBudget / Math.max(1, chars.length));
    let i = 0;
    textEl.textContent = '';
    const timer = setInterval(() => {
        if (i >= chars.length) { clearInterval(timer); textEl._typewriterTimer = null; return; }
        textEl.textContent += chars[i];
        i++;
    }, perChar);
    textEl._typewriterTimer = timer;
}
// 인트로 배경 이미지의 스타일 문자열 - 에디터 미리보기와 같은 결과를 내도록 에디터의 introBgImgCss와 반드시 동기화
// fit: cover(꽉 채우기) / contain(전체 보이기) / custom(가로·세로 크기와 위치를 직접). 크기는 화면 폭 기준(cqw).
function introBgImgCss(intro) {
    const fit = intro.bgFit || 'cover';
    const x = intro.bgX ?? 50, y = intro.bgY ?? 50;
    const rot = intro.bgRot || 0, op = (intro.bgOpacity ?? 100) / 100;
    const base = 'display:block; max-width:none; pointer-events:none; user-select:none; ';
    if (fit === 'custom') {
        const w = intro.bgW ?? 100;
        const h = intro.bgH ?? Math.round(w * (intro.bgAspect || 1) * 10) / 10;
        return `${base}position:absolute; left:${x}%; top:${y}%; width:${w}cqw; height:${h}cqw; object-fit:fill; transform:translate(-50%,-50%) rotate(${rot}deg); opacity:${op};`;
    }
    return `${base}position:absolute; left:0; top:0; width:100%; height:100%; object-fit:${fit}; object-position:${x}% ${y}%; transform:rotate(${rot}deg); opacity:${op};`;
}

// onReveal: 인트로가 걷히는 도중(사라지는 애니메이션이 반쯤 진행된 순간) 한 번 불린다.
//  첫 화면의 등장 효과(스티커 팝·바운스 등)를 인트로가 반쯤 걷혔을 때 시작해야 효과가 온전히 보인다.
//  예전엔 본문 등장 효과가 페이지를 여는 즉시 인트로 뒤에서 재생돼 버려서, 인트로가 걷혔을 땐 이미 끝나 있었다.
//  인트로가 없으면 바로 부른다.
const escHtml = v => String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
function playIntroAnimation(design, onReveal) {
    fixDesignUploadUrls(design);
    let revealed = false;
    const reveal = () => { if (revealed) return; revealed = true; if (typeof onReveal === 'function') onReveal(); };
    if (!design.intro || !design.intro.enabled) { reveal(); return; }
    window.scrollTo(0, 0);
    const intro = design.intro;
    const s = resolveSkin(design);
    const overlay = document.createElement('div');
    overlay.className = 'intro-overlay';
    const duration = intro.duration || 2.5;
    overlay.style.setProperty('--intro-duration', duration + 's');
    const bgType = intro.bgType || 'color';
    if (bgType === 'image' && intro.bgImage) {
        overlay.style.background = intro.bg || s.bg; // 이미지가 덮지 못하는 빈 곳에 보이는 색
        const bgImg = document.createElement('img');
        bgImg.alt = '';
        bgImg.src = intro.bgImage;
        bgImg.style.cssText = introBgImgCss(intro);
        overlay.appendChild(bgImg);
    } else if (bgType === 'video' && intro.bgYoutubeId) {
        overlay.style.background = '#000';
        const ytId = extractYoutubeId(intro.bgYoutubeId);
        const videoWrap = document.createElement('div');
        videoWrap.className = 'intro-bg-video-wrap';
        videoWrap.innerHTML = `<iframe src="${ytEmbedSrc(ytId)}" allow="autoplay" referrerpolicy="strict-origin-when-cross-origin" title="인트로 배경 영상"></iframe>`;
        overlay.appendChild(videoWrap);
    } else if (bgType === 'clear') { // 메인 화면 위 (투명): 청첩장 메인 사진·영상이 비치고 글자만 움직임
        overlay.classList.add('intro-clear');
        overlay.style.setProperty('--intro-dim', Math.max(0, Math.min(80, intro.clearDim ?? 30)) / 100);
    } else {
        overlay.style.background = intro.bg || s.bg;
    }
    const fontOpt = fontOptions.find(f => f.id === intro.font);
    // innerHTML 문자열 조립 대신 DOM으로 직접 만든다 - 폰트명에 큰따옴표가 들어있으면(예: "Song Myung")
    // style="..." 문자열 조립 방식은 그 따옴표가 속성을 중간에 끊어버려서 스타일이 통째로 깨졌었다.
    const textEl = document.createElement('div');
    textEl.className = 'intro-text';
    // "기본값"(폰트 미지정) 상태면 CSS inherit 대신 청첩장이 실제로 쓰는 폰트를 명시적으로 넣는다.
    // 인트로 오버레이가 청첩장 본문(#inviteRoot) 바깥(document.body)에 붙는 구조라 CSS 상속만으로는
    // 청첩장이 쓰는 폰트 값을 못 받아서, 재생 중 순간적으로 브라우저 기본폰트로 보였다가 바뀌는 것처럼 보였다.
    const defaultFontOpt = fontOptions.find(f => f.id === design.customFont);
    const defaultFontFamily = (defaultFontOpt && defaultFontOpt.family) || s.headFont;
    textEl.style.fontFamily = (fontOpt && fontOpt.family) || defaultFontFamily;
    textEl.style.fontSize = fontToCqw(intro.fontSize || 26);
    textEl.style.color = intro.color || (bgType === 'clear' ? '#fff' : s.ink);
    if (textEdge(intro)) textEl.style.textShadow = textEdge(intro); // 에디터 인트로 문구 팝업의 '테두리' (그림자·외곽선)
    textEl.style.left = (intro.x ?? 50) + '%';
    textEl.style.top = (intro.y ?? 50) + '%';
    if (intro.textWidthPct) textEl.style.width = intro.textWidthPct + '%'; // 에디터에서 측정해둔 고정폭 그대로 사용 (기기별 재측정으로 인한 줄바꿈 불일치 방지)
    textEl.style.transform = `translate(-50%,-50%) scaleX(${(intro.sx || 100) / 100}) rotate(${intro.rotation || 0}deg)`;
    if (intro.ls) textEl.style.letterSpacing = lsEm(intro.ls);
    textEl.style.textAlign = intro.align || 'center';
    const typedSpan = document.createElement('span');
    typedSpan.className = 'intro-typed';
    const cursorSpan = document.createElement('span');
    cursorSpan.className = 'intro-cursor';
    cursorSpan.textContent = '|';
    textEl.appendChild(typedSpan);
    textEl.appendChild(cursorSpan);
    overlay.appendChild(textEl);
    (intro.stickers || []).forEach(st => {
        const sk = document.createElement(st.image ? 'img' : 'span');
        sk.style.left = st.x + '%'; sk.style.top = st.y + '%';
        sk.style.transform = `translate(-50%,-50%) rotate(${st.rotation || 0}deg)${stickerFlipCss(st)}`;
        sk.style.zIndex = st.zIndex ?? 10;
        if (STICKER_EDGE[st.edge]) sk.style.filter = STICKER_EDGE[st.edge];
        const sizeCqw = fontToCqw(st.size);
        if (st.image) {
            sk.className = 'sticker-el sticker-image';
            sk.style.width = sizeCqw; sk.style.height = sizeCqw;
            sk.src = normalizeUploadUrl(st.image);
        } else if (st.icon) {
            sk.className = 'sticker-el sticker-icon';
            sk.style.width = sizeCqw; sk.style.height = sizeCqw;
            sk.innerHTML = STICKER_ICONS[st.icon] || '';
        } else {
            sk.className = 'sticker-el';
            sk.style.fontSize = sizeCqw;
            sk.textContent = st.emoji;
        }
        overlay.appendChild(sk);
    });
    (intro.customTexts || []).forEach(ct => {
        const el = document.createElement('span');
        el.className = 'custom-text-el';
        const anchorX = { left: '0%', center: '-50%', right: '-100%' }[ct.align || 'center'];
        el.style.left = ct.x + '%'; el.style.top = ct.y + '%';
        el.style.transform = `translate(${anchorX},-50%) scaleX(${(ct.scaleX ?? 100) / 100}) rotate(${ct.rotation || 0}deg)`;
        el.style.fontSize = fontToCqw(ct.fontSize);
        el.style.color = ct.color || 'inherit';
        el.style.zIndex = ct.zIndex ?? 10;
        if (textEdge(ct)) el.style.textShadow = textEdge(ct);
        if (ct.ls) el.style.letterSpacing = lsEm(ct.ls);
        el.textContent = ct.text;
        overlay.appendChild(el);
    });
    // 구글폰트는 display=swap이라 브라우저가 일단 기본폰트로 보여주고 나중에 교체하는데,
    // 인트로가 바로 재생을 시작해버리면 그 교체 순간이 눈에 띄는 "깜빡임"으로 보인다.
    // 그래서 (1) 인트로 화면(배경)은 바로 덮어서 뒤의 청첩장 본문이 비치지 않게 하고,
    // (2) 문구는 폰트가 실제로 준비될 때까지 숨겨뒀다가 준비되면 그때부터 타이핑/재생을 시작한다.
    // 주의: document.fonts.load(폰트)만 부르면 기본 글자(공백)용 조각(라틴)만 받아와서, 한글 조각은
    // 처음 그려질 때 뒤늦게 받아지며 "다른 폰트 -> 설정 폰트" 깜빡임이 남는다. 실제로 쓸 문구를 함께 넘겨서
    // 필요한 한글 조각까지 미리 받게 한다. (네트워크가 너무 느려도 무한정 기다리지 않도록 최대 2초)
    const waitEls = [textEl, ...overlay.querySelectorAll('.custom-text-el')];
    waitEls.forEach(el => { el.style.visibility = 'hidden'; });
    overlay.style.animationPlayState = 'paused'; // 사라지는 애니메이션 타이머도 준비된 뒤부터 시작
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden'; // 재생 중 스크롤 제한
    let started = false;
    const startIntro = () => {
        if (started) return;
        started = true;
        waitEls.forEach(el => { el.style.visibility = ''; });
        overlay.style.animationPlayState = '';
        const typedEl = overlay.querySelector('.intro-typed');
        // 에디터에서 글꾸미기(굵게·색·글꼴 등)를 했으면 꾸민 모양 그대로 타자 효과, 아니면 예전처럼 글자만
        const RT = window.InviteBlocks && InviteBlocks.RichText, anim = intro.anim || 'type';
        if (anim !== 'type' && RT && RT.animate) RT.animate(typedEl, intro.html || escHtml(intro.text || ''), anim, duration); // 글자 효과 (천천히·한 글자씩·흐림·크게서 작게·톡톡·한 줄씩·손글씨)
        else if (intro.html && RT) RT.type(typedEl, intro.html, duration);
        else { if (RT) RT.resetCursor(typedEl); typewriterInto(typedEl, intro.text || '', duration); }
        setTimeout(reveal, (duration + 0.3) * 1000); // 인트로가 반쯤 걷힌 순간 - 이보다 빠르면 효과의 앞부분이 인트로에 가려진다
        setTimeout(() => {
            overlay.remove();
            document.body.style.overflow = '';
            reveal(); // 혹시 위 타이머가 밀렸을 때 대비
        }, (duration + 0.6) * 1000);
    };
    const waitFontFamily = (fontOpt && fontOpt.family) || defaultFontFamily;
    const introSampleText = [intro.text || '', ...(intro.customTexts || []).map(c => c.text || '')].join('');
    if (waitFontFamily && document.fonts && document.fonts.load) {
        Promise.race([
            document.fonts.load(`16px ${waitFontFamily}`, introSampleText || undefined).catch(() => {}),
            new Promise(resolve => setTimeout(resolve, 1200)), // 모바일 느린 망에서 첫 화면이 너무 오래 비어 보여서 2초→1.2초
        ]).then(startIntro);
    } else {
        startIntro();
    }
}

const templates = {
    heroVideo: f => {
        const L = f.layout, s = k => partStyle(L, k);
        const wrapStyle = f.heightMode === 'full' ? 'height:100vh; height:100svh; aspect-ratio:auto;' : `aspect-ratio:${f.heightMode};`; // svh = 휴대폰 주소창·도구막대를 뺀 실제 보이는 높이
        const over = InviteBlocks.heroTextOn(f, 'video'); // 끄면 이름·날짜가 영상 아래 글자 칸으로 (켜면 아래 칸 없음)
        const ink = over && !(f.heightMode === 'full' && f.heroBox) ? 'on-dark' : 'on-light'; // 사진 칸 레이아웃이면 글자가 밝은 바탕 위
        const parts = `${(f.hideParts || []).includes('datetime') ? '' : `<span class="drag-part ${ink} op-datetime" style="${s('datetime')}">${esc(f.datetime)}</span>`}
        ${(f.hideParts || []).includes('groomName') ? '' : `<span class="drag-part ${ink} op-name" style="${s('groomName')}">${esc(f.groomName)}</span>`}
        ${f.hideHeart ? '' : `<span class="drag-part ${ink} op-heart" style="${s('heart')}">♥</span>`}
        ${(f.hideParts || []).includes('brideName') ? '' : `<span class="drag-part ${ink} op-name" style="${s('brideName')}">${esc(f.brideName)}</span>`}${InviteBlocks.heroLayersHtml(f, ink, s)}`;
        const band = InviteBlocks.videoBandSpace ? InviteBlocks.videoBandSpace(f) : 0; // 영상 아래 글자 칸 (글자는 영상+칸 어디든)
        const vb = InviteBlocks.heroVideoBox ? InviteBlocks.heroVideoBox(f, `<iframe src="${ytEmbedSrc(f.youtubeId)}" data-cover data-video-position="${f.videoPosition}" data-video-focus="${esc(f.videoFocus || '')}"${f.videoVertical ? ' data-vertical="1"' : ''}${+f.videoLb > 100 ? ` data-video-zoom="${+f.videoLb}"` : ''} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen playsinline title="배경 영상"></iframe>`) : { cls: '', style: '', html: `<iframe src="${ytEmbedSrc(f.youtubeId)}" data-cover data-video-position="${f.videoPosition}" data-video-focus="${esc(f.videoFocus || '')}"${f.videoVertical ? ' data-vertical="1"' : ''}${+f.videoLb > 100 ? ` data-video-zoom="${+f.videoLb}"` : ''} allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen playsinline title="배경 영상"></iframe>` }; // 사진 칸 레이아웃이면 영상을 칸 안에
        return `<div class="blk-hero-video${over ? '' : ' vt-under'}${band ? ' vt-overband' : ''}"><div class="video-cover-wrap${over && !band ? ' drag-canvas' : ''}${vb.cls}" style="${wrapStyle}${vb.style}">
        ${vb.html}
        <button type="button" class="video-fullscreen-btn" title="전체화면으로 보기">⤢</button>
        ${over ? `${InviteBlocks.heroShadeHtml(f, 'video')}${band ? '' : parts}${InviteBlocks.nextBtnHtml(f, 'video')}` : ''}
        </div>${band ? `<div class="hero-text-space" style="height:${(band / 390 * 100).toFixed(3)}cqw;"></div><div class="free-canvas drag-canvas hero-over-canvas">${parts}</div>` : ''}${over ? '' : `<div class="free-canvas drag-canvas vt-band" style="height:220px;">${parts}</div>`}</div>`;
    },
    hero: f => {
        const L = f.layout, s = k => partStyle(L, k);
        const heroImgHtml = InviteBlocks.heroPhotoHtml(f, { priority: true }); // 프레임 안 / 가로 100% (높이 옵션) - 에디터와 같은 함수
        const to = InviteBlocks.heroTextOpts(f); // 글자를 사진 위로도 옮길 수 있게 (켜면 글자 칸이 사진+아래 칸 전체를 덮음)
        const nb = InviteBlocks.nextBtnHtml(f, 'hero'); // 스크롤 버튼(↓) - 자리: 글자 칸 아래 / 사진 아래쪽 위 / 자유 위치
        let nbPos = nb ? InviteBlocks.heroNextPos(f) : '';
        if (nbPos === 'below' && to.over && !to.space) nbPos = 'photo'; // 사진 아래 칸이 없으면 "글자 칸 아래" = 사진 아래쪽
        const photoHtml = nbPos === 'photo' ? heroImgHtml.replace(/<\/div>$/, nb + '</div>') : heroImgHtml;
        return `<div class="blk-hero${nb ? ' has-next' : ''}${to.cls}">${photoHtml}${to.spacer}<div class="free-canvas drag-canvas${to.canvasCls}" style="${to.canvasStyle}">
        ${(f.hideParts || []).includes('datetime') ? '' : `<span class="drag-part on-light op-datetime" style="${s('datetime')}">${esc(f.datetime)}</span>`}
        ${(f.hideParts || []).includes('groomName') ? '' : `<span class="drag-part on-light op-name" style="${s('groomName')}">${esc(f.groomName)}</span>`}
        ${f.hideHeart ? '' : `<span class="drag-part on-light op-heart" style="${s('heart')}">♥</span>`}
        ${(f.hideParts || []).includes('brideName') ? '' : `<span class="drag-part on-light op-name" style="${s('brideName')}">${esc(f.brideName)}</span>`}${InviteBlocks.heroLayersHtml(f, 'on-light', s)}
        </div>${nbPos === 'below' ? `<div class="ib-next-row">${nb}</div>` : nbPos === 'free' ? nb : ''}</div>`;
    },
    greeting: f => {
        const L = f.layout, s = k => partStyle(L, k);
        return `<div class="blk-greeting"><div class="free-canvas drag-canvas" style="height:200px;">
        <span class="drag-part on-light" style="${s('text')}">${esc(f.text)}</span>
        </div></div>`;
    },
    location: f => {
        const L = f.layout, s = k => partStyle(L, k);
        // 예전엔 주소 뒤에 예식장 이름을 또 붙였는데(주소 검색 결과에 건물명이 이미 포함돼 있는
        // 경우가 많아서) 네이버맵/카카오맵/티맵에 이름이 두 번 뜨는 문제가 있었다 - 주소만 그대로 쓴다.
        const vd = InviteBlocks.isVenueDemo ? InviteBlocks.isVenueDemo(f) : {}; // 디자인 예시 예식장·주소 그대로면 하객에게 안 보이게 (다른 예식장으로 오해)
        if (vd.v || vd.a) f = Object.assign({}, f, vd.v ? { venue: '' } : {}, vd.a ? { address: '', addressBase: '' } : {});
        const q = encodeURIComponent((f.address || '').trim());
        // 안내전화/지도/지도밑 버튼3개 사이 간격과 지도 높이 - 에디터에서 조절한 값을 그대로 반영.
        // 예전에 저장된 디자인엔 이 필드들이 없을 수 있어서 기본값(14px/200px)으로 대체한다.
        const gapCallMap = f.gapCallMap ?? 14;
        const gapMapLinks = f.gapMapLinks ?? 14;
        const mapHeight = f.mapHeight ?? 200;
        const phoneHtml = f.phone ? `<div class="loc-phone-wrap" style="padding-bottom:${gapCallMap}px;"><a href="tel:${esc(f.phone)}" class="loc-phone-btn">안내 전화</a></div>` : '';
        const mapCls = f.mapFullWidth ? 'full-width' : '';
        const mapHtml = f.showMap ? `<div class="location-map ${mapCls}" style="height:${mapHeight}px;" data-address="${esc(f.addressBase || f.address)}"></div>` : '';
        const appLinksHtml = f.showMap ? `<div class="loc-applinks" style="padding-top:${gapMapLinks}px;">
            <a href="https://map.naver.com/p/search/${q}" target="_blank" rel="noopener" class="loc-applink"><span class="loc-app-badge loc-app-naver">N</span>네이버맵</a>
            <a href="https://map.kakao.com/link/search/${q}" target="_blank" rel="noopener" class="loc-applink"><span class="loc-app-badge loc-app-kakao">K</span>카카오맵</a>
            <a href="tmap://search?name=${q}" class="loc-applink"><span class="loc-app-badge loc-app-tmap">T</span>티맵</a>
        </div>` : '';
        return `<div class="blk-location">
        <p class="loc-eyebrow">LOCATION</p>
        <div class="free-canvas drag-canvas" style="height:200px;">
        <span class="drag-part on-light op-title" style="${s('title')}">오시는 길</span>
        <span class="drag-part on-light" style="${s('venue')}">${esc(f.venue)}</span>
        <span class="drag-part on-light" style="${s('address')}">${esc(f.address)}</span>
        </div>
        ${phoneHtml}
        ${mapHtml}
        ${appLinksHtml}
        </div>`;
    },
    gallery: (f, width) => window.InviteBlocks
        ? InviteBlocks.galleryHtml(f, width, { scaleImgUrl, defaultImages: defaultGalleryImages, lazy: true }) // 모양 6종 - 에디터와 같은 공용 파일
        : (function legacyGallery() {
                const images = (f.images && f.images.length) ? f.images : defaultGalleryImages();
                const cols = (!f.colLayout || f.colLayout === 'auto') ? (width <= 33 ? 1 : (width <= 50 ? 2 : 3)) : parseInt(f.colLayout, 10);
                const cls = { 1:'g1', 2:'g2', 4:'g4', 5:'g5' }[cols] || '';
                const lightboxCls = f.lightbox === false ? 'no-lightbox' : '';
                const scale = f.resScale || 1;
                const imgs = images.map(im => `<img loading="lazy" decoding="async" src="${esc(scaleImgUrl(im.src, scale))}" alt="" class="${im.size === 'large' ? 'g-large' : ''}">`).join('');
                const edgeCls = f.edgeToEdge ? 'edge-to-edge' : '';
                const cardCls = f.cardStyle ? 'card-style' : '';
                return `<div class="blk-gallery ${edgeCls}" style="position:relative;"><div class="grid ${cls} ${lightboxCls} ${cardCls}">${imgs}</div></div>`;
        
        })(),
    account: f => {
        if (f.display === 'cards' && window.InviteBlocks) return InviteBlocks.accountCardsHtml(f, !!f._masked); // 카드형 - 마스킹된 채로 그리고 아래 bindAccountReveal이 값을 채움
        const L = f.layout, s = k => partStyle(L, k);
        const extraKeys = ['groomFatherBank', 'groomMotherBank', 'brideFatherBank', 'brideMotherBank'];
        const extraLabels = { groomFatherBank:'신랑측 아버지', groomMotherBank:'신랑측 어머니', brideFatherBank:'신부측 아버지', brideMotherBank:'신부측 어머니' };
        const activeExtras = extraKeys.filter(k => f[k]);
        const canvasHeight = 220 + activeExtras.length * 40;
        const maskAttr = k => f._masked ? `data-masked="1" data-part="${k}" data-nowrap="1"` : '';
        const val = k => f._masked ? MASK_TEXT : InviteBlocks.accHtml(f[k]); // 예금주는 괄호 없이 옅은 글씨 (빈 "()"는 숨김)
        // 자동 정렬(accCols): 이름 | 계좌 두 열 (에디터와 같음). 마스킹이면 계좌 칸만 나중에 채움(data-reveal="value")
        const cols = !!f.accCols, vw = Math.max(6, Math.min(30, Number(f.accVw) || 14));
        // 이름 칸 폭(accLw em)도 자동 정렬이 실제 이름 글자에 맞춰 잼 - 신랑측·신부측만 있으면 좁게 (없으면 예전처럼 6.4em)
        const lw = Number(f.accLw) > 0 ? Math.max(2, Math.min(10, Number(f.accLw))) : (activeExtras.length ? 0 : 2.9), colsCss = lw ? `grid-template-columns:${lw}em ${vw}em;` : '';
        const line = (k, label) => cols
            ? `<span class="drag-part on-light acc-row" data-ib-copyrow title="누르면 계좌번호 복사" style="${s(k)}--acc-vw:${vw}em;${colsCss}"><b class="acc-l">${label}</b><span class="acc-v"${f._masked ? ` data-masked="1" data-reveal="value" data-part="${k}"` : ''}>${val(k)}</span></span>`
            : `<span class="drag-part on-light" data-ib-copyrow title="누르면 계좌번호 복사" ${maskAttr(k)} style="${s(k)}">${label} · ${val(k)}</span>`;
        const extraSpans = activeExtras.map(k => line(k, extraLabels[k])).join('');
        const main = (k, label) => f[k] ? line(k, label) : ''; // 비워 둔 신랑·신부 줄은 "신부측" 글자만 남지 않게 뺌
        return `<div class="blk-account"><div class="free-canvas drag-canvas" style="height:${canvasHeight}px;">
        <span class="drag-part on-light op-title" style="${s('title')}">마음 전하실 곳</span>
        ${main('groomBank', '신랑측')}
        ${main('brideBank', '신부측')}
        ${extraSpans}
        </div></div>`;
    },
    dday: f => {
        const dateObj = new Date(f.year, f.month - 1, f.day, f.hour, f.minute);
        const weekdays = ['일', '월', '화', '수', '목', '금', '토'];
        const ampm = f.hour < 12 ? '오전' : '오후';
        const h12 = f.hour % 12 === 0 ? 12 : f.hour % 12;
        const dateLabel = `${f.year}년 ${f.month}월 ${f.day}일 ${weekdays[dateObj.getDay()]}요일 ${ampm} ${h12}시${f.minute ? ` ${f.minute}분` : ''}`;
        const calHtml = f.showCalendar ? InviteBlocks.ddayCalendar(f) : ''; // 달력 모양 8가지 (assets/invite-blocks.js)
        return `<div class="blk-dday" style="position:relative;">
            <div class="dday-label">${esc(f.label)}</div>
            <div class="dday-date">${esc(dateLabel)}</div>
            ${calHtml}
            ${f.showCounter === false ? '' : InviteBlocks.ddayCounter(f, dateObj.getTime())}
            
        </div>`;
    },
    timeline: f => {
        // 에디터에서 날짜/제목/설명 각각에 포토샵처럼 끌어서 정한 줄바꿈 폭이 있으면 발행 페이지에도
        // 그대로 반영한다 (편집 UI는 없고 보기만 하므로 손잡이/데이터 속성 없이 폭만 그대로 적용).
        const tlField = (it, field) => {
            const width = it[field + 'Width'];
            const fixedClass = width ? ` tl-${field}-fixedwidth` : '';
            const styleAttr = width ? ` style="width:${width}px;"` : '';
            const prefix = field === 'title' ? `${esc(it.emoji)} ` : '';
            const inner = field === 'title' ? `<b>${esc(it.title)}</b>` : esc(it[field]);
            return `<p class="tl-${field}${fixedClass}"${styleAttr}>${prefix}${inner}</p>`;
        };
        const items = f.items.map((it, i) => `
            <div class="tl-item ${i % 2 === 0 ? 'tl-left' : 'tl-right'}">
                <div class="tl-photo">${it.image ? `<img loading="lazy" decoding="async" src="${esc(it.image)}" alt="">` : ''}</div>
                <div class="tl-dot"></div>
                <div class="tl-text">
                    ${tlField(it, 'date')}
                    ${tlField(it, 'title')}
                    ${tlField(it, 'desc')}
                </div>
            </div>
        `).join('');
        return `<div class="blk-timeline" style="position:relative;">${InviteBlocks.titleLayer(f.title, f, 'title', '', 18, 58)}<div class="tl-line">${items}</div></div>`;
    },
    interview: f => {
        const qas = f.qas.map(qa => `
            <div class="iv-qa">
                <p class="iv-q">Q. ${esc(qa.question)}</p>
                <div class="iv-a"><span class="iv-tag iv-groom">🤵 신랑</span><p>${esc(qa.groomAnswer)}</p></div>
                <div class="iv-a"><span class="iv-tag iv-bride">👰 신부</span><p>${esc(qa.brideAnswer)}</p></div>
            </div>
        `).join('');
        const imgHtml = f.image ? `<div class="iv-photo"><img loading="lazy" decoding="async" src="${esc(f.image)}" alt=""></div>` : '';
        return `<div class="blk-interview" style="position:relative;">${InviteBlocks.titleLayer(f.title, f, 'title', '', 18, 48)}${imgHtml}${qas}</div>`;
    }
};

function renderDdayCalendar(year, month, day) {
    const weekdayLabels = ['일', '월', '화', '수', '목', '금', '토'];
    const firstWeekday = new Date(year, month - 1, 1).getDay();
    const daysInMonth = new Date(year, month, 0).getDate();
    let cells = '';
    for (let i = 0; i < firstWeekday; i++) cells += '<div class="dday-cal-cell empty"></div>';
    for (let d = 1; d <= daysInMonth; d++) {
        cells += `<div class="dday-cal-cell${d === day ? ' wedding-day' : ''}">${d === day ? '♥' : d}</div>`;
    }
    return `<div class="dday-calendar">
        <div class="dday-cal-row dday-cal-head">${weekdayLabels.map(w => `<div>${w}</div>`).join('')}</div>
        <div class="dday-cal-row dday-cal-grid">${cells}</div>
    </div>`;
}
let ddayIntervals = [];
function initDdayCountdowns(root) {
    ddayIntervals.forEach(id => clearInterval(id));
    ddayIntervals = [];
    (root || document).querySelectorAll('[data-dday-target]').forEach(el => {
        const update = InviteBlocks.ddayTick(el); // 숫자 갱신 + 원형 링(--sec) + 넘김 효과 (assets/invite-blocks.js)
        update();
        ddayIntervals.push(setInterval(update, 1000));
    });
}

function groupIntoRows(blocks) {
    const rows = []; let current = []; let sum = 0;
    blocks.forEach(b => {
        if (sum + b.width > 100) { rows.push(current); current = []; sum = 0; }
        current.push(b); sum += b.width;
        if (sum >= 100) { rows.push(current); current = []; sum = 0; }
    });
    if (current.length) rows.push(current);
    return rows;
}

function fitVideoCover(iframe) {
    const wrap = iframe.closest('.hero-vbox') || iframe.closest('.video-cover-wrap'); // 사진 칸 레이아웃이면 칸 크기로
    if (!wrap) return;
    const cw = wrap.clientWidth, ch = wrap.clientHeight;
    if (!cw || !ch) return;
    const vert = iframe.dataset.vertical === '1'; // 세로 영상(쇼츠 등): 영상 틀도 세로(9:16)로 → 좌우는 항상 가운데
    const videoRatio = vert ? 9 / 16 : 16 / 9;
    let w, h;
    if (cw / ch > videoRatio) { w = cw; h = cw / videoRatio; }
    else { h = ch; w = ch * videoRatio; }
    // 영상에서 보일 부분 (videoFocus "x% y%" - 에디터에서 끌어서 정함). 없으면 예전 상단·가운데·하단 값으로
    let [fx, fy] = videoFocusXY(iframe.dataset.videoFocus, iframe.dataset.videoPosition);
    if (vert) fx = 0.5;
    // 레터박스 맞춤: 영상 자체에 검은 띠(시네마 비율)가 들어 있으면 그만큼 더 키워서 띠를 화면 밖으로 (data-video-zoom = 100~160%)
    //  (크기는 그대로 두고 보일 부분을 중심으로 확대 - 서버(invite_view.php)가 같은 값을 CSS로도 넣어 두어서 예전 스크립트가 캐시에 남아 있어도 적용됨)
    const z = Math.max(1, Math.min(1.6, (+iframe.dataset.videoZoom || 100) / 100));
    iframe.style.transformOrigin = `${fx * 100}% ${fy * 100}%`;
    iframe.style.transform = z > 1 ? `scale(${z})` : '';
    iframe.style.width = w + 'px';
    iframe.style.height = h + 'px';
    iframe.style.left = ((cw - w) * fx) + 'px';
    iframe.style.top = ((ch - h) * fy) + 'px';
}
// 유튜브 히어로 영상 위치: "x% y%" → [0~1, 0~1]. 예전 저장값(top·center·bottom)도 그대로 받음
function videoFocusXY(focus, pos) {
    const m = String(focus || '').match(/^(-?[\d.]+)%\s+(-?[\d.]+)%$/);
    if (m) return [Math.max(0, Math.min(100, +m[1])) / 100, Math.max(0, Math.min(100, +m[2])) / 100];
    return [0.5, pos === 'top' ? 0 : pos === 'bottom' ? 1 : 0.5];
}
function fitAllVideoCovers(root) {
    (root || document).querySelectorAll('.video-cover-wrap iframe[data-cover]').forEach(iframe => {
        fitVideoCover(iframe);
        if (!iframe._coverObserverAttached) {
            iframe._coverObserverAttached = true;
            const wrap = iframe.closest('.hero-vbox') || iframe.closest('.video-cover-wrap');
            if (wrap && typeof ResizeObserver !== 'undefined') {
                new ResizeObserver(() => fitVideoCover(iframe)).observe(wrap);
            }
            iframe.addEventListener('load', () => fitVideoCover(iframe));
            [50, 150, 300, 600, 1000, 2000].forEach(ms => setTimeout(() => fitVideoCover(iframe), ms));
        }
    });
}

// ===== 갤러리 사진 크게 보기 (fancybox 스타일) =====
// - 좌우로 밀어서(스와이프) 다음·이전 사진, PC는 화살표 버튼·키보드(←/→/Esc)
// - 두 번 탭(더블클릭)하면 2.5배 확대, 확대한 상태에서는 끌어서 이리저리 보기
// - 아래로 쓸어내리면 닫힘, 휴대폰 "뒤로 가기"를 눌러도 청첩장이 아니라 사진 창만 닫힘
// - 사진을 보는 동안 뒤의 청첩장이 같이 스크롤되던 문제: body를 그 자리에 고정해 두었다가 닫을 때 원래 위치로 되돌린다
let lbScrollY = 0;
function lockPageScroll() {
    lbScrollY = window.scrollY || document.documentElement.scrollTop || 0;
    const b = document.body.style;
    b.position = 'fixed'; b.top = -lbScrollY + 'px'; b.left = '0'; b.right = '0'; b.width = '100%';
    document.documentElement.classList.add('lbx-lock');
}
function unlockPageScroll() {
    const b = document.body.style;
    b.position = ''; b.top = ''; b.left = ''; b.right = ''; b.width = '';
    document.documentElement.classList.remove('lbx-lock');
    const html = document.documentElement, prev = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto'; // 부드러운 스크롤 설정이 있어도 제자리로 "순간" 복귀
    window.scrollTo(0, lbScrollY);
    html.style.scrollBehavior = prev;
}
function openLightbox(imgEl) {
    if (document.querySelector('.lbx')) return;
    const gallery = imgEl.closest('.blk-gallery, [data-lbx-group]'); // 갤러리 섹션 / 신혼여행 라이브 소식 사진
    // 같은 사진이 여러 번 들어간 경우(슬라이드형 무한 반복 등)는 한 번만
    const seen = new Set(); const list = [];
    Array.from(gallery.querySelectorAll('img')).forEach(im => { const src = im.currentSrc || im.src; if (src && !seen.has(src)) { seen.add(src); list.push(src); } });
    let idx = Math.max(0, list.indexOf(imgEl.currentSrc || imgEl.src));
    const n = list.length;
    const ov = document.createElement('div');
    ov.className = 'lbx';
    ov.setAttribute('role', 'dialog'); ov.setAttribute('aria-modal', 'true'); ov.setAttribute('aria-label', '사진 크게 보기');
    ov.innerHTML = `
        <div class="lbx-bg"></div>
        <div class="lbx-top"><span class="lbx-count">${n > 1 ? `<b>${idx + 1}</b> / ${n}` : ''}</span><button type="button" class="lbx-close" aria-label="닫기"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>
        <div class="lbx-stage"><div class="lbx-track">
            <div class="lbx-slide"><img alt="" draggable="false"></div><div class="lbx-slide"><img alt="" draggable="false"></div><div class="lbx-slide"><img alt="" draggable="false"></div>
        </div></div>
        ${n > 1 ? `<button type="button" class="lbx-nav lbx-prev" aria-label="이전 사진"><svg viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg></button>
        <button type="button" class="lbx-nav lbx-next" aria-label="다음 사진"><svg viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></svg></button>
        <div class="lbx-thumbs">${n <= 40 ? list.map((src, i) => `<button type="button" data-i="${i}" aria-label="${i + 1}번째 사진"><img src="${src}" alt="" loading="lazy"></button>`).join('') : ''}</div>` : ''}`;
    document.body.appendChild(ov);
    lockPageScroll();
    const track = ov.querySelector('.lbx-track');
    const slides = Array.from(ov.querySelectorAll('.lbx-slide'));
    const imgs = slides.map(sl => sl.querySelector('img'));
    const count = ov.querySelector('.lbx-count');
    const thumbs = Array.from(ov.querySelectorAll('.lbx-thumbs button'));
    const W = () => ov.querySelector('.lbx-stage').clientWidth;
    const wrap = i => (i + n) % n;
    let zoom = 1, panX = 0, panY = 0;

    function paint() {
        // 가운데 칸 = 지금 사진, 양옆 칸 = 이전/다음 사진 (미리 불러와 둠)
        imgs[1].src = list[idx];
        if (n > 1) { imgs[0].src = list[wrap(idx - 1)]; imgs[2].src = list[wrap(idx + 1)]; }
        else { imgs[0].removeAttribute('src'); imgs[2].removeAttribute('src'); }
        slides[0].style.visibility = slides[2].style.visibility = n > 1 ? '' : 'hidden';
        if (count && n > 1) count.innerHTML = `<b>${idx + 1}</b> / ${n}`;
        thumbs.forEach((t, i) => t.classList.toggle('on', i === idx));
        const on = thumbs[idx]; if (on) on.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        resetZoom(false);
        setX(0, false);
    }
    function setX(px, animate) {
        track.style.transition = animate ? 'transform .32s cubic-bezier(.22,.9,.32,1)' : 'none';
        track.style.transform = `translate3d(${-W() + px}px,0,0)`;
    }
    function applyZoom(animate) {
        imgs[1].style.transition = animate ? 'transform .25s ease' : 'none';
        imgs[1].style.transform = zoom > 1 ? `translate3d(${panX}px,${panY}px,0) scale(${zoom})` : '';
        ov.classList.toggle('zoomed', zoom > 1);
    }
    function resetZoom(animate) { zoom = 1; panX = panY = 0; applyZoom(animate); }
    function go(dir) {
        if (n < 2) return;
        resetZoom(false);
        setX(-dir * W(), true);
        setTimeout(() => { idx = wrap(idx + dir); paint(); }, 320);
    }
    let closed = false;
    function close(fromHistory) {
        if (closed) return; closed = true;
        ov.classList.remove('show');
        document.removeEventListener('keydown', onKey);
        window.removeEventListener('popstate', onPop);
        window.removeEventListener('resize', onResize);
        setTimeout(() => { ov.remove(); unlockPageScroll(); }, 220);
        if (!fromHistory && history.state && history.state.lbx) history.back();
    }
    const onKey = e => { if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft') go(-1); else if (e.key === 'ArrowRight') go(1); };
    const onPop = () => close(true);
    const onResize = () => setX(0, false);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onResize);
    try { history.pushState({ lbx: 1 }, ''); window.addEventListener('popstate', onPop); } catch (e) {}

    ov.querySelector('.lbx-close').addEventListener('click', () => close());
    ov.querySelector('.lbx-prev')?.addEventListener('click', () => go(-1));
    ov.querySelector('.lbx-next')?.addEventListener('click', () => go(1));
    thumbs.forEach(t => t.addEventListener('click', () => { idx = +t.dataset.i; paint(); }));

    // ---- 손가락/마우스 끌기: 좌우 = 넘기기, 아래로 = 닫기, 확대 중 = 이동 ----
    const stage = ov.querySelector('.lbx-stage');
    let sx = 0, sy = 0, dx = 0, dy = 0, axis = '', down = false, lastTap = 0, startPan = [0, 0], t0 = 0;
    stage.addEventListener('pointerdown', e => {
        if (e.button > 0) return;
        down = true; axis = ''; sx = e.clientX; sy = e.clientY; dx = dy = 0; t0 = Date.now(); startPan = [panX, panY];
        try { stage.setPointerCapture(e.pointerId); } catch (err) {}
    });
    stage.addEventListener('pointermove', e => {
        if (!down) return;
        dx = e.clientX - sx; dy = e.clientY - sy;
        if (zoom > 1) { panX = startPan[0] + dx; panY = startPan[1] + dy; applyZoom(false); return; }
        if (!axis && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
        if (axis === 'x' && n > 1) setX(dx, false);
        if (axis === 'y' && dy > 0) {
            imgs[1].style.transition = 'none';
            imgs[1].style.transform = `translate3d(0,${dy}px,0) scale(${Math.max(.8, 1 - dy / 1200)})`;
            ov.style.setProperty('--lbx-dim', String(Math.max(.25, 1 - dy / 500)));
        }
    });
    const end = e => {
        if (!down) return; down = false;
        const fast = Date.now() - t0 < 250;
        const moved = Math.abs(dx) > 8 || Math.abs(dy) > 8;
        // 확대 중: 끌었으면 사진 이동만, 제자리 탭이면 아래 "두 번 탭" 판정으로 (예전엔 확대 중이면 여기서 끝나서 다시 두 번 눌러도 원래 크기로 안 돌아왔음)
        if (zoom > 1 && moved) return;
        if (axis === 'x' && n > 1) {
            if (Math.abs(dx) > W() * .18 || (fast && Math.abs(dx) > 30)) go(dx < 0 ? 1 : -1); else setX(0, true);
        } else if (axis === 'y') {
            if (dy > 110 || (fast && dy > 50)) close();
            else { imgs[1].style.transition = 'transform .25s ease'; imgs[1].style.transform = ''; ov.style.removeProperty('--lbx-dim'); }
        } else if (!axis && !moved && e && e.type === 'pointerup') {
            // 탭: 두 번 연속이면 확대/축소, 사진 바깥 빈 곳 한 번이면 닫기
            const now = Date.now();
            if (now - lastTap < 300) {
                lastTap = 0;
                if (zoom > 1) resetZoom(true);
                else {
                    const r = imgs[1].getBoundingClientRect(); zoom = 2.5;
                    panX = (r.left + r.width / 2 - e.clientX) * (zoom - 1); panY = (r.top + r.height / 2 - e.clientY) * (zoom - 1);
                    applyZoom(true);
                }
            } else {
                lastTap = now;
                const r = imgs[1].getBoundingClientRect();
                const outside = e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
                if (outside && zoom === 1) setTimeout(() => { if (lastTap === now) close(); }, 300);
            }
        }
    };
    stage.addEventListener('pointerup', end);
    stage.addEventListener('pointercancel', end);
    // 뒤 화면으로 스크롤·확대가 새지 않게
    ov.addEventListener('touchmove', e => e.preventDefault(), { passive: false });
    let wheelAt = 0; // 트랙패드 가로 쓸기 - 한 번 쓸 때 한 장만 넘어가게
    ov.addEventListener('wheel', e => {
        e.preventDefault();
        if (zoom === 1 && Math.abs(e.deltaX) > 20 && Math.abs(e.deltaX) > Math.abs(e.deltaY) && Date.now() - wheelAt > 450) { wheelAt = Date.now(); go(e.deltaX > 0 ? 1 : -1); }
    }, { passive: false });

    paint();
    requestAnimationFrame(() => ov.classList.add('show'));
}

/**
 * design: { skin, blocks: [{id, enabled, width, fields}, ...] }
 * masked: true면 계좌 관련 파츠를 마스킹 처리(공개 페이지용). 에디터로 다시 불러올 땐 false로.
 * 반환값: 렌더된 rootEl (이벤트 바인딩까지 마친 상태)
 */
function renderInviteReadOnly(rootEl, design, masked) {
    fixDesignUploadUrls(design);
    applyPinchZoomSetting(design);
    applySnapSetting(design);
    const s = resolveSkin(design);
    applyDesktopSideBg(design, rootEl);
    // 신랑·신부·혼주 명칭/색 (에디터 "명칭·순서" 설정) - 공용 섹션들이 그릴 때 이 값을 쓴다
    if (window.InviteBlocks) {
        const lab = InviteBlocks.setLabels(design.labels);
        if (InviteBlocks.setDesign) InviteBlocks.setDesign(design); // 감사 인사 섹션 서명(신랑·신부 이름)용
        rootEl.style.setProperty('--groom-color', lab.groomColor);
        rootEl.style.setProperty('--bride-color', lab.brideColor);
    } // PC로 볼 때 좌우 여백 색 - 505px 이하에서는 끄고 가로 100%
    if (design.desktopShadow) rootEl.classList.add('has-desktop-shadow');
    rootEl.style.setProperty('--p-bg', design.customBg || s.bg);
    if (window.InviteBlocks && InviteBlocks.hexDark) rootEl.classList.toggle('ib-dark', InviteBlocks.hexDark(design.customBg || s.bg)); // 어두운 디자인: 섹션 안 카드·창도 어둡게
    rootEl.style.setProperty('--p-ink', s.ink);
    rootEl.style.setProperty('--p-accent', design.customAccent || s.accent); // 화면 설정 > 강조색
    rootEl.style.setProperty('--p-paper', window.InviteBlocks && InviteBlocks.paperCss ? InviteBlocks.paperCss(design.paper) : 'none'); // 종이 질감
    rootEl.style.setProperty('--p-line', s.line);
    rootEl.style.setProperty('--p-muted', s.muted);
    const customFont = fontOptions.find(f => f.id === design.customFont);
    rootEl.style.setProperty('--p-head-font', (customFont && customFont.family) || s.headFont);
    rootEl.style.setProperty('--p-body-font', (customFont && customFont.family) || s.bodyFont);
    rootEl.style.setProperty('--p-head-weight', (customFont && customFont.weight) || s.headWeight);
    rootEl.style.setProperty('--p-radius', s.radius);
    rootEl.classList.toggle('dividers-on', !!(design.extras && design.extras.dividers)); // 섹션 사이 구분선 (기본 꺼짐)

    const enabled0 = (design.blocks || []).filter(b => b.enabled);
    const enabled = enabled0.filter(b => b.id !== 'share').concat(enabled0.filter(b => b.id === 'share')); // 공유하기는 늘 맨 아래
    const rows = groupIntoRows(enabled);
    rootEl.innerHTML = rows.map(row => {
        const cols = row.map(b => b.width + '%').join(' ');
        const cells = row.map(b => {
            const fields = b.id === 'account' ? Object.assign({}, b.fields, { _masked: masked }) : b.fields;
            // 기존 섹션은 이 파일의 templates, 새 섹션(연락하기/프로필/손편지 등)은 공용 파일 invite-blocks.js
            // (에디터와 같은 파일을 쓰므로 둘이 절대 다르게 그려지지 않는다). 공용 파일을 못 불러왔으면 그 섹션만 건너뛴다.
            const shared = window.InviteBlocks && InviteBlocks.BLOCKS[b.id];
            const html = b.id === 'gallery' ? templates.gallery(fields, b.width)
                : templates[b.id] ? templates[b.id](fields)
                : shared ? shared.render(fields || {}) : '';
            const boxA = window.InviteBlocks && InviteBlocks.boxColAttrs ? InviteBlocks.boxColAttrs(b) : { cls: '', style: '' }; // 안쪽 창 색
            const colStyle = [
                b.padTop != null ? `padding-top:${b.padTop}px` : '',
                b.padBottom != null ? `padding-bottom:${b.padBottom}px` : '',
                b.sectionColor
                    ? (b.width >= 100
                        ? `background:${b.sectionColor}; margin-left:-24px; margin-right:-24px; padding-left:24px; padding-right:24px; width:calc(100% + 48px);`
                        : `background:${b.sectionColor}`)
                    : '',
                boxA.style,
            ].filter(Boolean).join(';');
            const fxOn = !(design.extras && design.extras.scrollFx === false); // 부가기능 "스크롤 효과" 끄면 전부 없음
            const topHero = (b.id === 'hero' || b.id === 'heroVideo') && enabled[0] === b; // 맨 위 히어로는 스크롤 효과 없이 바로 보이게
            const fxClass = (fxOn && !topHero && b.scrollEffect && b.scrollEffect !== 'none') ? ` reveal-${b.scrollEffect}` : '';
            return `<div class="col${fxClass}${boxA.cls}" data-block-id="${b.id}"${colStyle ? ` style="${colStyle}"` : ''}>${html}</div>`;
        }).join('');
        return `<div class="row-flex" style="grid-template-columns:${cols}">${cells}</div>`;
    }).join('');
    // 장식 효과 (방향·첫 화면에만·진하기까지 - 에디터와 같은 공용 함수)
    if (window.InviteBlocks && InviteBlocks.mountAmbient) InviteBlocks.mountAmbient(rootEl, design.effects || {}, { weatherUrl: window.INVITE_SLUG && !window.INVITE_OFFLINE ? '/invite/weather.php?s=' + encodeURIComponent(window.INVITE_SLUG) : '' }); // 날씨 따라: 식장 지역 지금 날씨
    else rootEl.insertAdjacentHTML('beforeend', renderAmbientEffectHtml(design.effects && design.effects.ambient));

    // 스티커/텍스트 - 예전엔 섹션(block.fields)에 저장됐었으므로 합쳐서 그린다. 이제 페이지 전체
    // 위에 뜨는 전역 레이어가 아니라, 각자 앵커된 섹션(.col)의 실제 자식으로 순수 CSS %에 그린다 -
    // 방문자 기기 폭이 얼마든 브라우저가 항상 알아서 다시 계산해주므로 JS 재배치가 필요 없다.
    const allStickers = (design.stickers || []).slice();
    const allCustomTexts = (design.customTexts || []).slice();
    (design.blocks || []).forEach(b => {
        if (Array.isArray(b.fields?.stickers)) allStickers.push(...b.fields.stickers);
        if (Array.isArray(b.fields?.customTexts)) allCustomTexts.push(...b.fields.customTexts);
    });
    const anchoredGroups = groupAnchoredItems(rootEl, allStickers, allCustomTexts);
    anchoredGroups.forEach(({ stickers, customTexts }, containerEl) => {
        containerEl.insertAdjacentHTML('beforeend', renderStickersHtml(stickers) + renderCustomTextsHtml(customTexts));
    });

    rootEl.querySelectorAll('.blk-gallery .grid:not(.no-lightbox) img').forEach(img => {
        img.addEventListener('click', () => openLightbox(img));
    });
    rootEl.querySelectorAll('.video-fullscreen-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                btn.closest('.video-cover-wrap')?.requestFullscreen?.();
            }
        });
    });
    requestAnimationFrame(() => fitAllVideoCovers(rootEl));
    // 첫 화면 글자색 자동 (사진·영상 위 = 흰 글자, 아래 글자 칸 = 테마색) - 화면 폭이 바뀌면 다시
    if (window.InviteBlocks && InviteBlocks.heroInkAuto) {
        requestAnimationFrame(() => InviteBlocks.heroInkAuto(rootEl));
        if (!rootEl._inkRs) { rootEl._inkRs = 1; let t; window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(() => InviteBlocks.heroInkAuto(rootEl), 150); }); }
    }
    if (InviteBlocks.watchOffscreen) InviteBlocks.watchOffscreen(rootEl); // 화면 밖 섹션의 반복 애니메이션 멈춤
    if (InviteBlocks.armNextFx) InviteBlocks.armNextFx(rootEl); // 스크롤 버튼 등장 효과: 처음 보일 때 한 번
    initLocationMaps(rootEl);
    initDdayCountdowns(rootEl);
    // 신혼여행 라이브 소식 사진도 눌러서 크게 보기 (소식 하나의 사진끼리 넘겨 봄)
    rootEl.addEventListener('click', e => {
        const img = e.target.closest && e.target.closest('.ib-trip-photos img');
        if (!img) return;
        img.closest('.ib-trip-photos').setAttribute('data-lbx-group', '');
        openLightbox(img);
    });
    if (window.InviteBlocks && InviteBlocks.applyImgFocus) InviteBlocks.applyImgFocus(rootEl, design.imgFocus, design.imgZoom); // 사진 "보이는 부분" (에디터에서 정함)
    if (window.InviteBlocks) InviteBlocks.bindInteractions(rootEl); // 계좌 복사 버튼, 슬라이드 갤러리 진행바
    if (window.InviteBlocks && InviteBlocks.armHeroAnims) InviteBlocks.armHeroAnims(rootEl); // 메인 문구 칸 등장 효과 (인트로가 끝난 뒤 화면에 보이면)
    if (window.InviteBlocks && InviteBlocks.armGalleryReveal) InviteBlocks.armGalleryReveal(rootEl); // 갤러리 차라락 (스크롤로 갤러리에 닿으면)
    if (window.InviteBlocks) InviteBlocks.initExtras(rootEl, design); // 공유 버튼, 배경음악, 상단 메뉴
    // 스크롤바 모양 (에디터 화면 설정 → 부가기능). 페이지 전체 스크롤바라 html에 붙이고, 색은 청첩장 색을 복사
    if (window.InviteBlocks && InviteBlocks.applyScrollbar) InviteBlocks.applyScrollbar(document.documentElement, design.extras && design.extras.scrollbar, rootEl, { color: design.extras && design.extras.scrollbarColor, opacity: design.extras && design.extras.scrollbarOpacity }); // 스크롤바 모양·색·진하기
    // 등장 효과는 인트로가 걷히는 순간부터 감시·재생 (인트로가 없으면 바로)
    playIntroAnimation(design, () => initScrollReveal(rootEl));
}

// 계좌번호·연락처 채우기 (봇 수집 방지) - invite_view.php / guest_secure.php 참고
//  HTML에는 계좌(data-masked)·연락처(data-sec-phone) 자리만 있고 실제 번호는 없다.
//  하객이 화면을 실제로 만지는 순간(터치·휠·마우스·클릭·키보드 - 사람이 한 동작만) 한 번 받아와서 채운다.
//  계좌 칸은 어차피 스크롤해야 보이는 곳이라 하객 눈에는 예전처럼 처음부터 채워져 있는 것처럼 보인다.
//  연락처 버튼을 먼저 누르면 받아온 뒤 바로 전화·문자로 넘어간다.
function bindGuestSecure(rootEl, opts) {
    const maskedEls = () => rootEl.querySelectorAll('[data-masked="1"]');
    const phoneEls = () => rootEl.querySelectorAll('[data-sec-phone]');
    if (!opts || (!maskedEls().length && !phoneEls().length)) return;
    let pending = null, done = false, failed = 0;
    function fill(data) {
        const acc = data.accounts || {}, ph = data.phones || {};
        maskedEls().forEach(target => {
            const key = target.dataset.part;
            if (target.dataset.reveal === 'value') { // 카드형: 값만 들어가는 칸
                target.innerHTML = InviteBlocks.accHtml(acc[key]); // (예금주는 괄호 없이 옅은 글씨)
            } else {
                const label = target.textContent.split('·')[0].trim();
                if (acc[key]) target.innerHTML = `${InviteBlocks.esc(label)} · ${InviteBlocks.accHtml(acc[key])}`;
            }
            target.removeAttribute('data-masked');
            target.removeAttribute('data-nowrap'); // 실제 계좌번호는 길이가 달라서 다시 줄바꿈 가능하게 둠
            target.style.cursor = '';
        });
        phoneEls().forEach(a => {
            const d = ph[a.dataset.secPhone];
            if (!d) return;
            a.href = (a.dataset.secKind === 'sms' ? 'sms:' : 'tel:') + d;
            a.removeAttribute('data-sec-phone');
        });
    }
    function load() {
        if (done) return Promise.resolve(true);
        if (pending) return pending;
        const body = new URLSearchParams({ s: opts.s || '', t: opts.t || '', k: opts.k || '' });
        pending = fetch(opts.url || '/invite/guest_secure.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-LD-Guest': '1' } })
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => { if (!data || !data.ok) throw 0; fill(data); done = true; disarm(); return true; })
            .catch(st => { failed++; pending = null; if (st === 429 || st === 403) disarm(); return st; });
        return pending;
    }
    // 사람이 한 동작(isTrusted)일 때만 - 스크립트로 흉내 낸 이벤트는 무시.
    // 'scroll'은 스크립트가 scrollTo()만 해도 진짜 이벤트로 발생해서 넣지 않았다. 휴대폰 스크롤은 touchstart,
    // PC는 휠·마우스 움직임·키보드로 잡힌다.
    const EVTS = ['touchstart', 'wheel', 'mousemove', 'pointerdown', 'keydown'];
    const onHuman = e => { if (e.isTrusted) load(); };
    function disarm() { EVTS.forEach(t => window.removeEventListener(t, onHuman, { capture: true })); }
    window.__ldSecureLoad = load; // 계좌 복사(invite-blocks.js)가 아직 못 받았으면 받아온 뒤 복사
    EVTS.forEach(t => window.addEventListener(t, onHuman, { capture: true, passive: true }));
    // 연락처 버튼을 번호가 오기 전에 누른 경우: 받아온 뒤 바로 연결
    rootEl.addEventListener('click', e => {
        const a = e.target.closest('[data-sec-phone]');
        if (!a || !e.isTrusted) return;
        e.preventDefault();
        load().then(r => {
            if (r === true && /^(tel|sms):/.test(a.getAttribute('href') || '')) { location.href = a.getAttribute('href'); return; }
            const msg = r === 429 ? '잠시 후 다시 눌러주세요.' : '연락처를 불러오지 못했어요. 새로고침 후 다시 눌러주세요.';
            if (window.InviteBlocks && InviteBlocks.toast) InviteBlocks.toast(msg);
        });
    });
}
// 예전 이름 - 이제 계좌는 bindGuestSecure가 채운다 (예전 account_reveal.php는 닫힘)
function bindAccountReveal() {}
