/**
 * tip-demos.js - 에디터 도움말 "움직이는 시범" 프리셋 모음 (손가락 동작·안내 그림 애니메이션)
 *
 *  에디터(editor-prototype-v3-overlay.html)와 관리자 → 에디터 도움말(admin_tips.php)이 같이 쓴다.
 *  모양은 assets/tip-demos.css. 프리셋을 늘리려면 여기 LIST에 [키, 이름, 묶음, 안쪽 HTML]을 넣고 CSS에 .g-키 애니메이션을 추가.
 *
 *  TipDemos.LIST        : [[키, 이름, 묶음], …]  (관리자 화면 고르기 목록 순서)
 *  TipDemos.html(키)    : 시범 그림 HTML (없는 키면 '')
 *  TipDemos.label(키)   : 이름
 *  TipDemos.auto(문구)  : 도움말 문구를 보고 알맞은 프리셋 키를 고름 (못 고르면 '')
 */
(function (g) {
    'use strict';
    const F = '<span class="tg-fin"></span>';
    const CARD = '<span class="tg-card"><i></i><i></i></span>';
    const IMG = n => `<span class="tg-img i${n}"></span>`;
    const ROW = n => `<span class="tg-row r${n}"><b>⠿</b><em></em><i></i></span>`;

    // [키, 이름, 묶음, 안쪽 HTML]
    const P = [
        // ---- 미리보기에서 직접 만지기 ----
        ['drag', '끌어서 옮기기', '미리보기 조작', CARD + F],
        ['tap', '눌러서 고르기', '미리보기 조작', CARD + F],
        ['dbltap', '두 번 눌러 바로 입력', '미리보기 조작', CARD + F],
        ['resize', '가장자리 끌어 폭 조절', '미리보기 조작', CARD + F],
        ['fontsize', '위 손잡이로 글자 크기', '미리보기 조작', '<span class="tg-card big"><b>Aa</b></span><span class="tg-hg"><span class="tg-hd">A</span><span class="tg-stem"></span></span>' + F],
        ['rotate', '아래 손잡이로 회전', '미리보기 조작', '<span class="tg-rw"><span class="tg-card big"><b>Aa</b></span><span class="tg-stem"></span><span class="tg-hd">↻</span></span>' + F],
        ['multiselect', '여러 개 같이 옮기기', '미리보기 조작', '<span class="tg-card a"><i></i><i></i></span><span class="tg-card b"><i></i><i></i></span>' + F],
        ['marquee', '빈 곳 끌어 범위 선택', '미리보기 조작', '<span class="tg-card a"><i></i></span><span class="tg-card b"><i></i></span><span class="tg-mq"></span>' + F],
        ['margin', '섹션 가장자리 끌어 여백', '미리보기 조작', '<span class="tg-blk a"></span><span class="tg-blk b"></span><span class="tg-ln"></span>' + F],
        ['keys', '방향키로 미세 이동 (PC)', '미리보기 조작', CARD + '<span class="tg-key">▶</span>'],
        ['sticker', '스티커 붙이기', '미리보기 조작', '<span class="tg-box tgt"></span><span class="tg-pal"><s>💐</s><s>🎀</s><s>⭐</s></span><span class="tg-em">💐</span>' + F],
        ['pinch', '두 손가락으로 확대', '미리보기 조작', IMG(1) + '<span class="tg-fin f1"></span><span class="tg-fin f2"></span>'],
        ['scroll', '위아래로 넘기기', '미리보기 조작', CARD + F],
        ['slide', '옆으로 밀어 넘기기', '미리보기 조작', '<span class="tg-vp"><span class="tg-strip">' + IMG(1) + IMG(2) + IMG(3) + '</span></span>' + F],
        ['undo', '↺ 되돌리기', '미리보기 조작', CARD + '<span class="tg-btn lt rd">↺</span>' + F],
        ['delete', '✕ 눌러 지우기', '미리보기 조작', '<span class="tg-card big"><b>♥</b></span><span class="tg-xx">✕</span>' + F],
        ['reset', '위치 초기화', '미리보기 조작', '<span class="tg-card s1"><i></i></span><span class="tg-card s2"><i></i></span><span class="tg-card s3"><i></i></span><span class="tg-btn lt">↺ 위치 초기화</span>' + F],
        // ---- 스티커 (😀 버튼으로 붙인 꾸미기 스티커) ----
        ['stkall', '스티커 한눈에 보기', '스티커', '<span class="tg-steps"><s class="s1">등장</s><s class="s2">크기</s><s class="s3">회전</s><s class="s4">사라짐</s></span>'
            + '<span class="tg-btn lt b1">⏱ 1초 뒤 등장</span><span class="tg-btn lt b2">👋 사라지기</span><span class="tg-bar up"><i></i></span><span class="tg-bar dn"><i></i></span>'
            + '<span class="tg-sk"><span class="bx"></span><span class="e">🌸</span><span class="ln"></span><span class="h rt"></span><span class="h br"></span></span>'
            + '<span class="tg-spk s1">✦</span><span class="tg-spk s2">✧</span><span class="tg-puff"><s></s><s></s><s></s></span>'
            + '<span class="tg-fin fa"></span><span class="tg-fin fb"></span><span class="tg-fin fc"></span>'],
        ['stksize', '스티커 크기 키우고 줄이기', '스티커', '<span class="tg-sk"><span class="bx"></span><span class="e">🌸</span><span class="h br"></span></span><span class="tg-val"><s class="a">36</s><s class="b">50</s></span>' + F],
        ['stkrot', '스티커 돌리기', '스티커', '<span class="tg-sk"><span class="bx"></span><span class="e">🌸</span><span class="ln"></span><span class="h rt"></span></span><span class="tg-val"><s class="a">0°</s><s class="b">35°</s></span>' + F],
        ['stkfx', '스티커 등장 효과', '스티커', '<span class="tg-btn lt fx">✨ 효과</span><span class="tg-chips sk"><s>팝</s><s class="on">통통</s><s>빙글</s></span><span class="tg-sk"><span class="e">🌸</span></span><span class="tg-spk s1">✦</span><span class="tg-spk s2">✧</span>' + F],
        ['stkdelay', '스티커 등장 딜레이', '스티커', '<span class="tg-btn lt dl"><span class="a">⏱ 0초</span><span class="b">⏱ 1.5초</span></span><span class="tg-bar"><i></i></span><span class="tg-sk"><span class="e">🌸</span></span>' + F],
        ['stkhide', '스티커 사라지게', '스티커', '<span class="tg-btn lt hd">👋 3초 뒤 사라짐</span><span class="tg-bar dn"><i></i></span><span class="tg-sk"><span class="e">🌸</span></span><span class="tg-puff"><s></s><s></s><s></s></span>'],
        // ---- 편집창·목록 ----
        ['reorder', '⠿ 끌어 순서 바꾸기', '편집창·목록', ROW(1) + ROW(2) + ROW(3) + F],
        ['toggle', '스위치로 켜고 끄기', '편집창·목록', '<span class="tg-sw"></span>' + '<span class="tg-card big"><i></i><i></i></span>' + F],
        ['expand', '⤢ 눌러 크게 편집', '편집창·목록', '<span class="tg-pn"><span class="tg-pnh"></span><span class="tg-x">⤢</span><i></i><i></i></span>' + F],
        ['cards', '▲▼ 다음 섹션 카드', '편집창·목록', '<span class="tg-c c2"></span><span class="tg-c c1"></span><span class="tg-btn lt nx">▼</span>' + F],
        ['width', '반 칸·⅓ 칸 나란히', '편집창·목록', '<span class="tg-blk a"></span><span class="tg-blk b"></span><span class="tg-pills"><s>전체</s><s class="on">1/2</s><s>1/3</s></span>' + F],
        ['ratio', '사진 비율·높이 고르기', '편집창·목록', '<span class="tg-pic"></span><span class="tg-pills r"><s class="p1">원본 비율</s><s class="p2">꽉차게</s><s class="p3">높이조정</s></span>' + F],
        ['ytbg', '유튜브 주소 → 배경 영상', '편집창·목록', '<span class="tg-in yt"><span class="tg-yt"></span><span class="tg-tx">youtu.be/wD8x…</span></span><span class="tg-arw r">→</span>'
            + '<span class="tg-ph yv"><i></i><i></i><i></i><span class="tg-yv"></span><span class="tg-ytt"><b></b><b></b></span><span class="tg-yt pl"></span><span class="tg-ypg"></span></span>'
            + '<span class="tg-btn lt ya">▶ 자동재생 · 소리 끔 · 반복</span>' + F],
        ['layout', '배치 디자인 바꾸기', '편집창·목록', '<span class="tg-lp"><s class="p1">목록</s><s class="p2">카드</s><s class="p3">나란히</s></span>'
            + '<span class="tg-lw"><i class="b1"></i><i class="b2"></i><i class="b3"></i></span>' + F],
        ['align', '정렬 바꾸기', '편집창·목록', '<span class="tg-box bx"><span class="tg-l l1"></span><span class="tg-l l2"></span><span class="tg-l l3"></span></span><span class="tg-ab"><s>◀</s><s>▬</s><s>▶</s></span>' + F],
        ['color', '색 고르기', '편집창·목록', '<span class="tg-box cl"></span><span class="tg-dot d1"></span><span class="tg-dot d2"></span><span class="tg-dot d3"></span>' + F],
        ['type', '글자 입력·줄바꿈', '편집창·목록', '<span class="tg-in"><span class="tg-tx">우리 결혼합니다 ♥</span></span>'],
        ['search', '검색해서 고르기', '편집창·목록', '<span class="tg-in s"><span class="tg-mg">⌕</span><span class="tg-tx">테헤란로 123</span></span><span class="tg-dd"><s></s><s></s></span>' + F],
        ['dropdown', '목록에서 고르기', '편집창·목록', '<span class="tg-sel"><span class="v1">은행 선택</span><span class="v2">국민은행</span><b>▾</b></span><span class="tg-dd d"><s></s><s></s><s></s></span>' + F],
        ['upload', '사진 올리기', '편집창·목록', '<span class="tg-btn">⬆ 사진</span>' + IMG(2) + F],
        ['photoorder', '사진 끌어 순서 바꾸기', '편집창·목록', IMG(1) + IMG(2) + IMG(3) + F],
        ['focus', '사진 보이는 부분 정하기', '편집창·목록', '<span class="tg-fsrc"><i></i></span><span class="tg-arw r">→</span><span class="tg-fwin"></span>' + F],
        ['star', '★ 눌러 크게 보기', '편집창·목록', IMG(1) + IMG(2) + IMG(3) + '<span class="tg-star">★</span>' + F],
        ['copy', '눌러서 복사', '편집창·목록', '<span class="tg-box cp"><i></i><i></i></span><span class="tg-btn">복사</span><span class="tg-toast">복사됐어요 ✓</span>' + F],
        ['code', '코드 입력', '편집창·목록', '<span class="tg-cd"><s>4</s><s>8</s><s>2</s><s>7</s></span>'],
        ['view', '화면 크기 바꿔 보기', '편집창·목록', '<span class="tg-ph"><i></i><i></i><i></i></span><span class="tg-pills v"><s class="p1">표준</s><s class="p2">좁게</s></span>'],
        ['bezel', '테두리 켜고 끄기', '편집창·목록', '<span class="tg-out"></span><span class="tg-ph"><i></i><i></i><i></i></span><span class="tg-btn lt bz">테두리</span>' + F],
        ['effect', '스크롤 등장 효과', '편집창·목록', '<span class="tg-card big"><i></i><i></i></span><span class="tg-spk">✦</span><span class="tg-arw">↓</span>'],
        // ---- 안내 그림 (기능 설명용) ----
        ['alarm', '알림이 울려요', '안내 그림', '<span class="tg-ic bell">🔔</span><span class="tg-wave"></span><span class="tg-noti"><b>D-1</b><i></i><i></i></span>'],
        ['lock', '안전하게 보호', '안내 그림', '<span class="tg-ic lk"><span class="o">🔓</span><span class="c">🔒</span></span><span class="tg-box dt"><span class="r">010-1234-5678</span><span class="m">010-••••-••••</span></span>'],
        ['qr', 'QR로 사진 올리기', '안내 그림', '<span class="tg-qr"><span class="sc"></span></span><span class="tg-arw r">→</span>' + IMG(4)],
        ['map', '지도에 위치 표시', '안내 그림', '<span class="tg-map"></span><span class="tg-pin">📍</span><span class="tg-rip"></span>'],
        ['clock', '기간·마감', '안내 그림', '<span class="tg-clk"><b></b><i></i></span><span class="tg-tag"><span class="a">받는 중</span><span class="b">마감</span></span>'],
        ['calendar', '그날에만 보여요', '안내 그림', '<span class="tg-cal"><b></b><span class="a">13</span><span class="b">D-DAY</span></span><span class="tg-card big"><i></i><i></i></span>'],
        ['lottery', '추첨·당첨', '안내 그림', '<span class="tg-ic gift">🎁</span><span class="tg-cf"><s></s><s></s><s></s><s></s><s></s><s></s></span><span class="tg-win">당첨!</span>'],
        ['mobile', '휴대폰에서만 보여요', '안내 그림', '<span class="tg-ph sm"><i></i><span class="tg-ok">✓</span></span><span class="tg-mon"><span class="tg-no">✕</span></span>'],
        ['hide', '스크롤할 때 숨기기', '안내 그림', '<span class="tg-vp v"><span class="tg-pg"><i></i><i></i><i></i><i></i><i></i><i></i></span><span class="tg-fab"></span></span>'],
        ['fab', '구석 버튼 펼치기', '안내 그림', '<span class="tg-vp v"><span class="tg-pg"><i></i><i></i><i></i></span><span class="tg-fab"></span><span class="tg-fm m1"></span><span class="tg-fm m2"></span><span class="tg-fm m3"></span></span>' + F],
        ['popup', '팝업·오늘 하루 안 보기', '안내 그림', '<span class="tg-vp v"><span class="tg-pg"><i></i><i></i><i></i></span><span class="tg-pop"><i></i><i></i><span class="ck"><s></s>오늘 안 보기</span></span></span>' + F],
        ['emoji', '이모티콘만 남기기', '안내 그림', '<span class="tg-sw"></span><span class="tg-chips"><s>Aa</s><s>🎨</s><s>🖼</s><s class="keep">😊</s></span>' + F],
    ];
    const MAP = {}; P.forEach(p => { MAP[p[0]] = p; });

    function html(key) {
        const p = MAP[key];
        if (!p) return '';
        return `<div class="tip-demo g-${key}" aria-hidden="true">${p[3]}<span class="tg-cap">${p[1]}</span></div>`;
    }

    // 도움말 문구 → 프리셋 (위에서부터 먼저 맞는 것)
    const RULES = [
        [/사진 (폭|높이)|원본 비율|가로 100% ?꽉|높이(를)? 직접/, 'ratio'],
        [/유튜브.*배경|배경 ?영상|영상.*배경(화면)?/, 'ytbg'],
        [/배치 ?디자인|배치를 (바꾸|변경|골라)|레이아웃|(가로|세로|나란히|한 줄).{0,6}배치/, 'layout'],
        // 스티커 조작 (일반 회전·크기 규칙보다 먼저)
        [/스티커.*(한눈|한 번에|모두|전부)/, 'stkall'],
        [/딜레이|기다렸다(가)? ?등장/, 'stkdelay'],
        [/스티커.*사라|사라지기|뒤 사라/, 'stkhide'],
        [/스티커.*(크기|키우|줄이)/, 'stksize'],
        [/스티커.*(회전|돌리|돌려)/, 'stkrot'],
        [/스티커.*효과/, 'stkfx'],
        [/섹션 위\/아래|위\/아래 가장자리/, 'margin'],
        [/주소 검색|검색/, 'search'],
        [/드롭다운|목록에서 고르/, 'dropdown'],
        [/★|크게 보기/, 'star'],
        [/(사진|갤러리).*(순서)|순서.*(사진)/, 'photoorder'],
        [/보이는 부분|초점/, 'focus'],
        [/다중 ?선택|여러 개|한꺼번에/, 'multiselect'],
        [/범위/, 'marquee'],
        [/방향키/, 'keys'],
        [/되돌리|↺/, 'undo'],
        [/위치 초기화/, 'reset'],
        [/복사/, 'copy'],
        [/회전|돌려/, 'rotate'],
        [/글자 ?크기/, 'fontsize'],
        [/추첨|당첨/, 'lottery'],
        [/알림|울려/, 'alarm'],
        [/암호화|걱정|개인정보|보호/, 'lock'],
        [/QR/, 'qr'],
        [/보지 않기|안 떠요|팝업/, 'popup'],
        [/마감|기간|늦춰/, 'clock'],
        [/코드/, 'code'],
        [/지도|체크인|핀/, 'map'],
        [/당일|예식일에만|순간부터/, 'calendar'],
        [/휴대폰 브라우저|PC에서는|휴대폰에서만/, 'mobile'],
        [/멈추면|숨었다/, 'hide'],
        [/구석|메뉴도 펼쳐/, 'fab'],
        [/이모티콘/, 'emoji'],
        [/스티커/, 'sticker'],
        [/1\/2|1\/3|나란히/, 'width'],
        [/테두리/, 'bezel'],
        [/정렬/, 'align'],
        [/색을|색상|배경색/, 'color'],
        [/등장|효과/, 'effect'],
        [/드래그/, 'drag'],
        [/Enter|줄이 바뀝/, 'type'],
        [/⤢|크게 띄워|크게 편집|전체화면/, 'expand'],
        [/▲▼|다음 섹션/, 'cards'],
        [/⠿|순서/, 'reorder'],
        [/가장자리|폭/, 'resize'],
        [/끌어|끌면/, 'drag'],
        [/더블클릭|더블 ?탭|두 ?번 ?(누르|눌러|클릭|탭)/, 'dbltap'],
        [/업로드|올리|올려/, 'upload'],
        [/꺼두면|끄면|켜면|켜고|스위치/, 'toggle'],
        [/여백/, 'margin'],
        [/확대|두 손가락/, 'pinch'],
        [/스크롤|넘겨|넘기/, 'scroll'],
        [/슬라이드|옆으로/, 'slide'],
        [/입력|타이핑/, 'type'],
        [/삭제|지우/, 'delete'],
        [/클릭|누르면|눌러|탭하/, 'tap'],
    ];
    function auto(text) {
        const t = String(text || '');
        for (const [re, k] of RULES) if (re.test(t)) return k;
        return '';
    }

    // ---- 섹션별 사용법 (섹션 편집창 위 "▶ 사용법" 버튼 → 카드가 차례로 넘어가며 움직이는 그림 + 설명) ----
    // [프리셋 키, 설명]. 관리자 → 에디터 도움말 → 섹션별 사용법에서 섹션마다 바꿀 수 있다 (여기는 기본값).
    // 목록에 없는 새 섹션은 기본 3장(제목 옮기기·내용 고치기·켜고 끄기)이 나온다.
    const TITLE = ['drag', '섹션 제목도 끌어서 원하는 자리로 옮겨요'];
    const GUIDES = {
        heroVideo: [['ytbg', '유튜브 주소를 입력하면 배경화면이 돼요'], ['drag', '이름·날짜 문구는 끌어서 옮겨요'], ['fontsize', '문구를 누르고 위 손잡이로 글자 크기를 바꿔요'], ['rotate', '아래 손잡이로 비스듬히 돌려요'], ['focus', '영상 위치 창을 끌어서 화면에 보일 부분을 정해요'], ['view', '화면 높이(표준·가로·세로·전체화면)를 골라요'], ['scroll', '전체화면일 때 ↓ 버튼을 켜면 눌러서 다음 섹션으로 가요']],
        hero: [['upload', '대표 사진을 올려요'], ['ratio', '사진을 가로 100%로 꽉 채우고 높이(원본 비율·전체화면·직접)를 골라요'], ['focus', '사진을 누르면 잘릴 때 보일 부분을 정해요'], ['drag', '이름·날짜 문구를 끌어서 옮겨요'], ['dbltap', '문구를 두 번 눌러 바로 고쳐 써요'], ['fontsize', '위 손잡이로 글자 크기를 바꿔요'], ['keys', 'PC에서는 방향키로 1px씩 맞춰요'], ['scroll', '전체화면일 때 ↓ 버튼을 켜면 눌러서 다음 섹션으로 가요']],
        greeting: [['dbltap', '인사말을 두 번 눌러 바로 고쳐 써요'], ['type', 'Enter로 원하는 곳에서 줄을 바꿔요'], ['resize', '가장자리를 끌어 줄바꿈 폭을 정해요'], ['align', '왼쪽·가운데·오른쪽 정렬을 골라요'], ['margin', '섹션 위·아래 가장자리를 끌어 여백을 조절해요']],
        location: [['search', '주소 검색으로 정확한 주소를 넣어요'], ['map', '주소대로 지도가 자동으로 나와요'], ['toggle', '지도를 가로 100%로 꽉 채울 수 있어요'], ['drag', '예식장 이름·주소 문구를 옮겨요'], ['tap', '하객은 네이버·카카오·티맵으로 바로 길찾기']],
        gallery: [['upload', '사진을 올려요'], ['photoorder', '사진을 끌어 순서를 바꿔요'], ['focus', '사진의 ◎를 눌러 잘릴 때 보일 부분을 정해요'], ['star', '★로 크게 보일 사진을 골라요'], ['slide', '슬라이드형은 옆으로 넘겨 봐요'], ['pinch', '하객은 사진을 눌러 크게 볼 수 있어요'], TITLE],
        account: [['dropdown', '은행을 목록에서 골라요'], ['type', '계좌번호만 입력하면 자동으로 조합돼요'], ['toggle', '부모님 계좌를 켜서 추가해요'], ['copy', '하객은 눌러서 계좌번호를 복사해요'], ['reset', '문구가 겹치면 위치 초기화']],
        dday: [['calendar', '예식일까지 남은 날을 보여줘요'], ['clock', '시·분·초가 실시간으로 줄어요'], ['upload', '배경 사진을 넣을 수 있어요']],
        timeline: [['type', '날짜·제목·설명을 입력해요'], ['dbltap', '미리보기에서 두 번 눌러 바로 고쳐요'], ['resize', '글자 옆 손잡이를 끌어 줄바꿈 폭을 정해요'], ['upload', '항목마다 사진을 넣어요'], TITLE],
        interview: [['type', '질문과 답을 입력해요'], ['upload', '사진을 넣어요'], ['reorder', '질문 순서를 바꿔요'], TITLE],
        family: [['type', '부모님 성함을 입력해요'], ['toggle', '고인이시면 표시를 켜요 (국화·故)'], ['drag', '신랑측·신부측 줄을 끌어서 자유롭게 옮겨요'], ['fontsize', '줄을 누르고 글자 크기·정렬·회전을 바꿔요']],
        profile: [['upload', '신랑·신부 사진을 올려요'], ['focus', '사진을 눌러 얼굴이 잘 보이게 맞춰요'], ['type', '소개 글을 써요'], ['layout', '배치 디자인(가로·세로)을 바꿔 보세요']],
        contact: [['type', '전화번호를 입력해요'], ['reorder', '↑↓로 가족 순서를 바꿔요'], ['layout', '배치 디자인(나란히·한 줄)을 바꿔 보세요'], ['color', '안쪽 창 색을 바꿀 수 있어요'], ['tap', '하객은 눌러서 바로 전화·문자해요'], ['lock', '번호는 봇이 못 가져가게 숨겨져 있어요'], TITLE],
        letter: [['type', '편지 내용을 써요'], ['color', '종이 무늬·색과 무늬 크기를 골라요'], ['width', '종이를 가로 100%로 꽉 채울 수 있어요'], ['drag', '편지 글을 끌어서 옮겨요'], ['fontsize', '글을 누르고 글자 크기·폭을 바꿔요'], ['upload', '편지 사진을 넣을 수 있어요']],
        video: [['type', '유튜브 주소를 붙여넣어요'], ['toggle', '가로 100%로 꽉 채워요'], ['resize', '영상 폭·모서리 둥글기를 조절해요'], TITLE],
        transport: [['type', '지하철·버스·자가용 안내를 써요'], ['alarm', '대절버스 출발 시간을 적으면 알림이 울려요'], ['reorder', '안내 순서를 바꿔요'], TITLE],
        notice: [['type', '안내문을 써요'], ['upload', '카드형은 사진을 넣어요'], ['tap', '외부 링크 버튼을 달 수 있어요'], ['reorder', '안내문 순서를 바꿔요'], TITLE],
        together: [['calendar', '첫 만남 날짜를 넣어요'], ['effect', '함께한 날 수가 자동으로 계산돼요'], ['drag', '설명·날 수·날짜 문구를 각각 끌어서 옮겨요'], ['fontsize', '문구를 누르고 글자 크기를 바꿔요'], TITLE],
        ending: [['upload', '마지막 사진을 올려요'], ['align', '글 위치(상단·중간·하단)를 골라요'], ['color', '사진을 어둡게 해서 글씨를 또렷하게']],
        guestsnap: [['qr', '하객은 QR·버튼으로 사진을 올려요'], ['popup', '버튼을 누르면 청첩장 위 팝업으로 열려요'], ['drag', '안내 문구·버튼을 끌어서 옮기고 크기를 바꿔요'], ['clock', '업로드 기간이 정해져 있어요 (예식 전에도 받게 켤 수 있어요)'], ['toggle', '켜고 저장해야 업로드가 열려요'], TITLE],
        trip: [['map', '체크인하면 지도에 핀이 찍혀요'], ['upload', '여행 사진을 올려요'], ['lock', '위치는 도시 수준으로만 보여요'], ['clock', '공개 시작을 늦출 수 있어요'], TITLE],
        lottery: [['code', '현장 코드로 응모를 받아요'], ['clock', '응모를 열고 마감해요'], ['lottery', '추첨하면 당첨 화면이 떠요'], TITLE],
        rsvp: [['popup', '하객에게 참석 여부 창이 떠요'], ['clock', '마감일까지 받아요'], ['lock', '연락처는 암호화해서 보관해요'], TITLE],
        guestbook: [['type', '하객이 축하 글을 남겨요'], ['emoji', '꾸미기를 끄면 이모티콘만 남아요'], ['toggle', '작성을 잠시 막을 수 있어요'], TITLE],
        dayinfo: [['calendar', '예식 당일에만 보이게 할 수 있어요'], ['popup', '당일 안내 팝업을 띄워요'], ['color', '안내 칸·팝업 창 색을 바꿀 수 있어요'], TITLE],
        // 스티커는 섹션이 아니라 😀 스티커 창 맨 위에 "▶ 스티커 사용법"으로 나옴
        sticker: [['stkall', '스티커로 할 수 있는 걸 한눈에 봐요'], ['sticker', '골라서 누르면 미리보기에 붙어요'], ['drag', '끌어서 원하는 자리로 옮겨요'], ['stksize', '오른쪽 아래 손잡이로 크기를 키우고 줄여요'],
            ['stkrot', '위쪽 동그라미를 끌어 비스듬히 돌려요'], ['stkfx', '✨ 버튼으로 등장 효과를 골라요'], ['stkdelay', '⏱ 버튼으로 몇 초 뒤에 나타날지 정해요'],
            ['stkhide', '⏱ 버튼에서 몇 초 뒤 사라지게도 할 수 있어요'], ['delete', '✕로 지워요']],
        share: [['tap', '카카오톡·링크로 공유해요'], ['fab', '구석 공유 버튼을 펼쳐요'], ['hide', '스크롤하면 숨었다가 다시 나타나요'], ['mobile', '휴대폰에서만 보여요']],
    };
    const GUIDE_DEFAULT = [TITLE, ['dbltap', '내용을 두 번 눌러 바로 고쳐요'], ['toggle', '목록의 스위치로 켜고 꺼요']];
    const guide = id => (GUIDES[id] || GUIDE_DEFAULT).map(([d, t]) => ({ d, t, on: true }));

    // 사용법 카드 바탕 무늬 (CSS .tdx-키) - 관리자가 섹션마다 고름
    const TEXTURES = [['plain', '없음'], ['paper', '종이'], ['linen', '린넨'], ['grid', '모눈'], ['dot', '도트'], ['kraft', '크라프트'], ['blush', '파스텔']];

    // 한 바퀴가 긴 시범 (ms) - 사용법 카드가 다음 장으로 넘어가기 전에 끝까지 보이게
    const DUR = { stkall: 10500 };

    g.TipDemos = {
        TEXTURES,
        dur: k => DUR[k] || 0,
        LIST: P.map(p => [p[0], p[1], p[2]]),
        GUIDES, guide,
        html, auto,
        label: k => (MAP[k] ? MAP[k][1] : ''),
        has: k => !!MAP[k],
    };
})(typeof window !== 'undefined' ? window : this);
