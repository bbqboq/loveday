<?php
/**
 * admin_nav.php - 관리자 화면 공통 상단바 + 왼쪽 메뉴판 + 전체 메뉴(사이트맵)
 *
 * 쓰는 법 (각 관리자 페이지의 <body> 바로 아래):
 *   <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('snap', '게스트스냅 설정'); ?>
 *
 *  - 메뉴는 하는 일끼리 묶음 5개 (ADMIN_MENU_GROUPS): 청첩장 · 디자인 · 에디터 · 하객 화면 · 사이트 (2026-10-20 정리, A안)
 *  - PC(1100px 넘게) = 왼쪽에 메뉴판이 늘 보임 (본문은 그만큼 오른쪽으로) / 좁은 화면 = 왼쪽 위 ☰ 를 누르면 서랍처럼 나옴
 *  - 위 [전체 메뉴] = 사이트맵 (C안): 오늘 할 일 + 묶음별 카드(메뉴마다 한 줄 설명). 키보드 / 를 눌러도 열림
 *  - 메뉴 찾기 칸: 메뉴 이름 · 설명 · 찾기 말(kw)로 찾음 (예: 금지어 → 부가기능, 썸네일 → 디자인)
 *  - 한 화면 안 탭으로 나뉜 메뉴(섹션 설정의 간편 만들기·기기별 설정)는 주소 #탭 으로 열고, 그 화면이 admNavSync()로 표시를 맞춤
 *  - 스마트스토어 옆에는 확인이 필요한 주문 수가 숫자로 붙는다
 *  - 부관리자: 권한이 없어 열 수 없는 메뉴는 숨기고, 보기만 되는 화면은 위에 "보기 전용" 띠 + 저장 버튼 잠금
 *  - 오른쪽 끝 동그라미 = 지금 로그인한 사람 (누르면 이름·로그아웃)
 * 스타일은 이 파일 안에 있다 (assets/admin.css를 고치지 않아도 됨).
 * 새 관리자 화면을 만들면 ADMIN_MENU_GROUPS 알맞은 묶음에 한 줄 (키, 주소, 이름, 한 줄 설명, 찾기 말).
 */
declare(strict_types=1);

// [키 => [주소, 이름, 한 줄 설명(사이트맵), 찾기 말]]
const ADMIN_MENU_GROUPS = [
    ['청첩장', '매일 보는 것', [
        'list'      => ['admin_create.php', '청첩장 목록', '고객·VIP·테스트 청첩장 찾기, 결제·보관 기간 처리', '검색 코드 이름 전화번호 고객코드 결제 보관 수정'],
        'vip'       => ['admin_vip.php', 'VIP 만들기', '원하는 코드로 VIP 청첩장 만들어 주기', 'VIP 코드 생성 지정 코드'],
        'naver'     => ['admin_naver.php', '스토어 주문', '스마트스토어 주문 확인 · 코드 틀린 주문 처리 · 취소·반품 기록', '주문 네이버 스마트스토어 교환 취소 반품 결제'],
        'trash'     => ['admin_trash.php', '휴지통', '지운 청첩장 · 지운 디자인 되살리기 · 완전 삭제', '삭제 복원 되살리기 영구 지운 디자인 템플릿'],
    ]],
    ['디자인', '고객이 고르는 재료', [
        'designs'   => ['admin_designs.php', '디자인', '추천 순서 · 보이기·숨기기 · 모던/예전 · 썸네일 · 종이 질감', '추천 디자인 샘플 꾸미기 썸네일 종이 질감 모던 예전 숨기기 삭제 지우기 휴지통 템플릿'], // (디자인 휴지통도 여기),
        'stickers'  => ['admin_stickers.php', '스티커', '스티커 테마 순서 · 그림 숨기기 · 디자인별 추천', '스티커 이모티콘 테마'],
        'music'     => ['admin_music.php?tab=library', '기본 배경음악', '에디터 음악 목록에 나오는 곡 올리기·이름 바꾸기·빼기', '음악 노래 곡 mp3 배경음악 기본'],
    ]],
    ['에디터', '고객이 만드는 화면', [
        'easy'      => ['admin_sections.php#easy', '간편 만들기', '질문 순서 · 목록 화면 · 휴대폰 보기 방식 · 예시 사진·영상', '손쉬운 제작 이지모드 질문 목록 스타터 휴대폰 보기 반반 작은 창 PC 디자인 고르기 발행 준비 스크롤 버튼 메인 사진 예시 영상 예시'],
        'introsets' => ['admin_intro_sets.php', '인트로 분위기', '간편 만들기 인트로 분위기 16가지 - 글자 · 바탕 · 스티커 자리와 움직임', '인트로 분위기 프리셋 스티커 레이아웃 애니메이션 움직임 글자 효과'],
        'sections'  => ['admin_sections.php#order', '전문가 모드', '섹션 순서 · 섹션 묶음 · 편집창·메뉴 모양', '섹션 순서 묶음 편집창 메뉴 모양 이름'],
        'device'    => ['admin_sections.php#device', '기기별 설정', '간편·전문가 × 휴대폰·PC마다 칸·옵션 숨기기', '휴대폰 PC 기기 숨기기 옵션'],
        'tiptours'  => ['admin_tip_tours.php', '편집팁 만들기', '손가락으로 콕콕 짚어 주는 안내 만들기', '커스텀 편집팁 코치 투어 안내 손가락'],
        'tips'      => ['admin_tips.php', '예전 ⓘ 도움말', '편집창 회색 안내 · 첫 방문 둘러보기 (예전 방식)', '에디터 도움말 팁 둘러보기 첫 방문 안내 팝업'],
    ]],
    ['하객 화면', '하객이 쓰는 기능', [
        'snap'      => ['admin_snap_settings.php', '게스트스냅', '하객 사진 용량·기간 · 구글 드라이브 · 사용 현황', '하객 사진 게스트스냅 구글 드라이브 용량'],
        'extra'     => ['admin_extra_settings.php?part=guest', '방명록·참석', '방명록 금지어 (막기·가리기) · 참석 회신 명단 보관 기간', '방명록 금지어 욕설 참석 회신 RSVP 부가기능'],
        'music_cust'=> ['admin_music.php?tab=active', '고객이 올린 음악', '저작권 신고 → 음악만 삭제 · 삭제 기록 · 최대 용량', '음악 노래 mp3 저작권 신고 삭제 용량 MB'],
        'watermark' => ['admin_watermark.php', '워터마크', '무료체험 청첩장에 들어가는 글자', '워터마크 무료체험'],
    ]],
    ['사이트', '가끔 바꾸는 것', [
        'site'      => ['admin_site_settings.php', '사이트 정보', '가격표 · 구매 주소 · 사업자 정보 · 사이트 색상', '가격 사업자 구매 버튼 색상 샘플 카드 홈페이지'],
        'homeimg'   => ['admin_home_images.php', '홈 이미지', '홈 첫 화면 사진 · 로그인 화면 사진', '홈 이미지 사진 로그인'],
        'keep'      => ['admin_extra_settings.php?part=keep', '체험·보관 시간', '체험 청첩장·무료체험·휴지통을 얼마나 두었다 지울지 · 삭제 카운트다운', '체험 보관 시간 무료체험 유예 휴지통 기간 카운트다운 자동 삭제 부가기능'],
        'naver_setup' => ['admin_naver.php?part=setup', '스토어 연결', '스마트스토어 애플리케이션 ID·시크릿 · 자동 확인 · 연결 테스트', '네이버 커머스 API 키 시크릿 연동 cron 발주확인 발송처리'],
        'subadmin'  => ['admin_subadmins.php', '부관리자', '부관리자 계정 · 권한 켜고 끄기', '부관리자 권한 계정 비밀번호'],
        'timezone'  => ['admin_timezone.php', '서버 시간 점검', '한 번만 쓰는 점검 도구', '서버 시간 시각 점검 UTC'],
    ]],
];
// 예전 키 → 새 키 (예전 화면이 부르는 이름)
const ADMIN_MENU_ALIAS = [];

function admin_topbar(string $active, string $pageTitle = ''): void
{
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $active = ADMIN_MENU_ALIAS[$active] ?? $active;
    $badge = 0; // 스마트스토어: 확인 필요 + 교환 확인 중
    if (function_exists('admin_can') && admin_can('naver_orders')) {
        try { $badge = (int) get_pdo()->query("SELECT COUNT(*) FROM naver_orders WHERE result IN ('review','canceled','exchange_hold')")->fetchColumn(); } catch (Throwable $e) {}
    }
    $isOwner = !function_exists('admin_is_owner') || admin_is_owner();
    $meName = function_exists('admin_display_name') ? admin_display_name() : '관리자';
    $meInitial = $isOwner ? '대표' : mb_substr((string) ((admin_sub()['display_name'] ?? '') ?: (admin_sub()['username'] ?? '부')), 0, 1);
    $readonly = function_exists('admin_page_readonly') && admin_page_readonly();
    $sessLeft = function_exists('admin_session_deadline') ? max(0, admin_session_deadline() - time()) : 1800; // 자동 로그아웃까지 남은 초
    // 이 사람이 열 수 있는 메뉴만
    $groups = [];
    foreach (ADMIN_MENU_GROUPS as [$gName, $gDesc, $items]) {
        $vis = [];
        foreach ($items as $key => $it) {
            if (function_exists('admin_page_open') && !admin_page_open(strtok($it[0], '?#'))) continue;
            $vis[$key] = $it;
        }
        if ($vis) $groups[] = [$gName, $gDesc, $vis];
    }
    $link = function (string $key, array $it, string $cls) use ($h, $active, $badge): string {
        $on = $key === $active || ($active === 'sections' && in_array($key, ['sections', 'easy', 'device'], true) && $key === 'sections');
        return '<a href="' . $h($it[0]) . '" class="' . $cls . ($on ? ' on' : '') . '" data-nav="' . $h($key) . '"' . ($on ? ' aria-current="page"' : '') . '>'
            . '<span>' . $h($it[1]) . '</span>' . ($key === 'naver' && $badge ? '<em class="adm-badge">' . $badge . '</em>' : '') . '</a>';
    };
    ?>
<style>
.adm-top { position: sticky; top: 0; z-index: 60; display: flex; align-items: center; gap: 12px; height: 56px; padding: 0 20px; background: #fff; border-bottom: 1px solid #E8E4DC;
    font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; box-sizing: border-box; }
.adm-top *, .adm-side *, .adm-map * { box-sizing: border-box; }
.adm-brand { flex: none; font-weight: 800; font-size: 14px; letter-spacing: .14em; color: #2B2320; text-decoration: none; white-space: nowrap; }
.adm-page { flex: 0 1 auto; min-width: 0; font-size: 13.5px; color: #8A847B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.adm-page::before { content: '·'; margin-right: 10px; color: #C9C3BA; }
.adm-burger { display: none; flex: none; width: 36px; height: 36px; margin-left: -6px; border: 0; border-radius: 10px; background: none; cursor: pointer; padding: 0; color: #2B2320; }
.adm-burger:hover { background: var(--ui-tint, #F4F1EC); }
.adm-burger i, .adm-burger i::before, .adm-burger i::after { display: block; width: 18px; height: 2px; margin: 0 auto; border-radius: 2px; background: currentColor; position: relative; content: ''; }
.adm-burger i::before { position: absolute; top: -6px; left: 0; } .adm-burger i::after { position: absolute; top: 6px; left: 0; }
/* 메뉴 찾기 (위 가운데) */
.adm-find { position: relative; flex: 1 1 280px; max-width: 340px; min-width: 0; margin-left: auto; }
.adm-find input { width: 100%; height: 36px; border: 1px solid #E3DED6; border-radius: 999px; background: #FAF8F5; padding: 0 14px 0 34px; font: inherit; font-size: 13px; color: #2B2320; outline: none; }
.adm-find input:focus { border-color: #B8AE9F; background: #fff; }
.adm-find::before { content: ''; position: absolute; left: 13px; top: 11px; width: 10px; height: 10px; border: 1.7px solid #9A938A; border-radius: 50%; pointer-events: none; }
.adm-find::after { content: ''; position: absolute; left: 23px; top: 22px; width: 5px; height: 1.7px; background: #9A938A; transform: rotate(45deg); pointer-events: none; }
.adm-hits { position: absolute; left: 0; right: 0; top: 42px; z-index: 75; background: #fff; border: 1px solid #ECE8E2; border-radius: 12px; box-shadow: 0 14px 34px rgba(30, 20, 10, .14); padding: 6px; display: none; max-height: 60vh; overflow: auto; }
.adm-hits.on { display: block; }
.adm-hits a, .adm-hits p { display: block; padding: 8px 10px; border-radius: 8px; color: #2B2320; text-decoration: none; font-size: 13px; margin: 0; }
.adm-hits a small { display: block; font-size: 11.5px; color: #8A847B; margin-top: 1px; }
.adm-hits a b { font-weight: 700; } .adm-hits a i { font-style: normal; color: #A39B90; font-size: 11.5px; margin-left: 6px; }
.adm-hits a:hover, .adm-hits a.cur { background: var(--ui-tint, #F4F1EC); }
.adm-hits p { color: #8A847B; }
.adm-mapbtn { flex: none; display: inline-flex; align-items: center; gap: 7px; height: 36px; padding: 0 13px; border: 1px solid #E3DED6; border-radius: 999px; background: #fff; color: #2B2320; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.adm-mapbtn:hover { background: var(--ui-tint, #F4F1EC); }
.adm-mapbtn svg { width: 14px; height: 14px; }
.adm-mapbtn .adm-badge { margin-left: -2px; }
.adm-badge { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #C7823A; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; font-style: normal; }
/* 왼쪽 메뉴판 */
.adm-side { box-sizing: border-box; position: fixed; z-index: 58; left: 0; top: 56px; bottom: 0; width: 220px; overflow-y: auto; padding: 12px 10px 28px; background: #FAF8F5; border-right: 1px solid #ECE8E2; scrollbar-width: thin;
    font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; }
.adm-side-hd { display: none; }
.adm-side h4 { margin: 16px 10px 4px; font-size: 11.5px; font-weight: 700; letter-spacing: .04em; color: #9A938A; }
.adm-side h4:first-of-type { margin-top: 4px; }
.adm-side a { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 7px 10px; border-radius: 9px; color: #3F3833; text-decoration: none; font-size: 13.5px; line-height: 1.35; }
.adm-side a:hover { background: var(--ui-tint, #F1EDE6); color: #2B2320; }
.adm-side a.on { background: #2B2320; color: #fff; font-weight: 700; }
.adm-side a:focus-visible, .adm-map a:focus-visible, .adm-mapbtn:focus-visible { outline: 2px solid #B08D57; outline-offset: 2px; }
.adm-side .adm-side-map { width: 100%; margin: 18px 0 0; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px; border: 1px dashed #D9D2C7; border-radius: 10px; background: none; color: #6F6A63; font: inherit; font-size: 12.5px; cursor: pointer; }
.adm-side .adm-side-map:hover { background: #fff; color: #2B2320; }
.adm-scrim { position: fixed; inset: 0; z-index: 88; background: rgba(25, 20, 15, .36); opacity: 0; pointer-events: none; transition: opacity .2s; }
@media (min-width: 1100px) {
    body { padding-left: 220px !important; }
    .adm-top { margin-left: -220px; padding-left: 20px; }
}
@media (max-width: 1099.98px) {
    .adm-burger { display: block; }
    .adm-side { top: 0; z-index: 90; width: min(280px, 86vw); transform: translateX(-102%); transition: transform .25s cubic-bezier(.2, .8, .3, 1); box-shadow: 8px 0 30px rgba(0, 0, 0, .14); padding-top: 0; }
    html.adm-drawer .adm-side { transform: none; }
    html.adm-drawer .adm-scrim { opacity: 1; pointer-events: auto; }
    .adm-side-hd { display: flex; position: sticky; top: 0; z-index: 2; align-items: center; justify-content: space-between; height: 56px; margin: 0 -10px 4px; padding: 0 12px 0 20px; background: #FAF8F5; border-bottom: 1px solid #ECE8E2; }
    .adm-side-hd b { font-size: 13px; letter-spacing: .14em; font-weight: 800; color: #2B2320; }
    .adm-side-hd button { width: 34px; height: 34px; border: 0; border-radius: 9px; background: none; font-size: 18px; color: #6F6A63; cursor: pointer; }
    .adm-side .adm-find { display: block; max-width: none; margin: 10px 0 14px; }
}
/* 전체 메뉴 (사이트맵) */
.adm-map { position: fixed; inset: 0; z-index: 95; display: block; padding: 56px 20px 30px; overflow-y: auto; background: rgba(25, 20, 15, .4); opacity: 0; transition: opacity .18s;
    font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; }
.adm-map[hidden] { display: none; }
.adm-map.show { opacity: 1; }
.adm-map-box { width: min(1080px, 100%); margin: 0 auto; background: #fff; border-radius: 18px; box-shadow: 0 24px 60px rgba(0, 0, 0, .22); padding: 22px 24px 26px; transform: translateY(8px); transition: transform .22s cubic-bezier(.2, .8, .3, 1); }
.adm-map.show .adm-map-box { transform: none; }
.adm-map-hd { display: flex; align-items: center; gap: 12px; margin: 0 0 16px; flex-wrap: wrap; }
.adm-map-hd h2 { margin: 0; font-size: 18px; color: #2B2320; }
.adm-map-hd small { color: #8A847B; font-size: 12.5px; }
.adm-map-hd .adm-find { margin-left: auto; flex: 1 1 240px; max-width: 320px; }
.adm-map-x { width: 36px; height: 36px; border: 0; border-radius: 10px; background: var(--ui-tint, #F4F1EC); color: #4A433D; font-size: 17px; cursor: pointer; flex: none; }
.adm-todo { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 18px; }
.adm-todo a, .adm-todo span { display: inline-flex; align-items: center; gap: 8px; padding: 9px 13px; border-radius: 11px; font-size: 13px; text-decoration: none; }
.adm-todo a { background: #FBEFE4; color: #6B3F14; }
.adm-todo a:hover { background: #F6E3D1; }
.adm-todo span { background: #F4F1EC; color: #7A746C; }
.adm-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
.adm-card { border: 1px solid #ECE8E2; border-radius: 14px; padding: 14px 14px 10px; min-width: 0; }
.adm-card h3 { margin: 0; font-size: 14.5px; color: #2B2320; }
.adm-card > small { display: block; margin: 1px 0 8px; font-size: 12px; color: #9A938A; }
.adm-card a { display: block; padding: 8px 10px; margin: 0 -4px; border-radius: 9px; color: #2B2320; text-decoration: none; }
.adm-card a:hover { background: var(--ui-tint, #F4F1EC); }
.adm-card a.on { background: #F1EDE6; }
.adm-card a b { display: flex; align-items: center; gap: 6px; font-size: 13.5px; }
.adm-card a span { display: block; margin-top: 1px; font-size: 12px; line-height: 1.45; color: #8A847B; }
@media (max-width: 760px) {
    .adm-top { gap: 8px; padding: 0 12px 0 16px; height: 52px; }
    .adm-top > .adm-find, .adm-page { display: none; }
    .adm-mapbtn { margin-left: auto; padding: 0 11px; }
    .adm-mapbtn .lb { display: none; }
    .adm-side { padding-bottom: 40px; }
    .adm-map { padding: 0; background: #fff; overscroll-behavior: contain; }
    .adm-map-box { border-radius: 0; min-height: 100%; padding: 14px 16px 30px; box-shadow: none; }
    .adm-map-hd .adm-find { order: 3; max-width: none; flex-basis: 100%; }
    .adm-map-x { margin-left: auto; }
    .adm-cards { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) { .adm-side, .adm-map, .adm-map-box, .adm-scrim { transition: none; } }
.adm-me { position: relative; flex: none; }
.adm-me > button { width: 34px; height: 34px; border-radius: 50%; border: 1px solid #E3DED6; background: var(--ui-tint, #F4F1EC); color: #2B2320; font: inherit; font-size: 11px; font-weight: 800; cursor: pointer; padding: 0; }
.adm-me > button.sub { background: #EEF2F7; color: #4A5A73; border-color: #D8E0EA; font-size: 13px; }
.adm-me-pop { position: absolute; right: 0; top: 42px; z-index: 70; min-width: 190px; background: #fff; border: 1px solid #ECE8E2; border-radius: 12px; box-shadow: 0 14px 34px rgba(30, 20, 10, .14); padding: 12px; display: none; }
.adm-me.open .adm-me-pop { display: block; }
.adm-me-pop b { display: block; font-size: 13.5px; margin: 0 0 2px; }
.adm-me-pop small { display: block; font-size: 11.5px; color: #8A847B; margin: 0 0 10px; }
.adm-me-pop button { width: 100%; padding: 9px; border: 1px solid #E3DED6; border-radius: 9px; background: #fff; font: inherit; font-size: 13px; cursor: pointer; }
.adm-me-pop button:hover { background: #F6F3EE; }
/* 자동 로그아웃 남은 시간 / 연장 */
.adm-sess { margin: 0 0 10px; padding: 10px; border-radius: 10px; background: #F6F3EE; font-size: 12px; color: #6F6A63; }
.adm-sess b { color: #2B2320; font-variant-numeric: tabular-nums; }
.adm-sess-btns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; margin-top: 8px; }
.adm-me-pop .adm-sess-btns button { width: auto; padding: 6px 0; font-size: 12px; font-weight: 700; background: #fff; }
.adm-sess-pop { position: fixed; inset: 0; z-index: 3000; display: grid; place-items: center; padding: 20px; background: rgba(25, 20, 15, .45); opacity: 0; transition: opacity .2s;
    font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", sans-serif; }
.adm-sess-pop.show { opacity: 1; }
.adm-sess-pop[hidden] { display: none; }
.adm-sess-pop .box { box-sizing: border-box; width: min(360px, 100%); background: #fff; border-radius: 20px; padding: 26px 22px 18px; text-align: center; box-shadow: 0 24px 60px rgba(0, 0, 0, .25); transform: translateY(12px) scale(.97); transition: transform .25s cubic-bezier(.2, .8, .3, 1.2); }
.adm-sess-pop.show .box { transform: none; }
.adm-sess-pop .ic { width: 52px; height: 52px; margin: 0 auto 12px; border-radius: 50%; background: #FFF4E0; display: grid; place-items: center; font-size: 24px; }
.adm-sess-pop h3 { margin: 0 0 6px; font-size: 17px; color: #2B2320; }
.adm-sess-pop p { margin: 0 0 18px; font-size: 13.5px; line-height: 1.65; color: #6F6A63; }
.adm-sess-pop p b { color: #C2410C; font-size: 15px; font-variant-numeric: tabular-nums; }
.adm-sess-pop .btns { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; }
.adm-sess-pop .btns button { border: 0; border-radius: 12px; padding: 12px 0; font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; background: var(--ui-soft, #F1EEE9); color: #2B2320; }
.adm-sess-pop .btns button.main { background: #2B2320; color: #fff; }
.adm-sess-pop .btns button:disabled { opacity: .5; }
.adm-sess-pop .sub { display: flex; justify-content: center; gap: 16px; margin-top: 14px; }
.adm-sess-pop .sub button { border: 0; background: none; font: inherit; font-size: 12.5px; color: #8F8980; cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }
/* 부관리자 보기 전용 띠 */
.adm-ro { display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #FFF6E5; color: #8A5A00; font-size: 13px; border-bottom: 1px solid #F1E2C4;
    font-family: "Pretendard Variable", Pretendard, -apple-system, sans-serif; }
.adm-locked { opacity: .45 !important; cursor: not-allowed !important; }
@media (max-width: 760px) { .adm-ro { padding: 9px 16px; font-size: 12.5px; } }
</style>
<header class="adm-top">
    <button type="button" class="adm-burger" id="admBurger" aria-label="메뉴 열기" aria-controls="admSide" aria-expanded="false"><i></i></button>
    <a class="adm-brand" href="admin_create.php">LOVE DAY</a>
    <?php if ($pageTitle !== ''): ?><span class="adm-page"><?= $h($pageTitle) ?></span><?php endif; ?>
    <div class="adm-find"><input type="search" data-adm-find placeholder="메뉴 찾기 (예: 금지어, 썸네일)" aria-label="메뉴 찾기" autocomplete="off"><div class="adm-hits" role="listbox"></div></div>
    <button type="button" class="adm-mapbtn" id="admMapBtn" aria-haspopup="dialog" title="모든 메뉴를 한눈에 보기 (키보드 /)"><svg viewBox="0 0 14 14" aria-hidden="true"><rect x="0" y="0" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="8" y="0" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="0" y="8" width="6" height="6" rx="1.5" fill="currentColor"/><rect x="8" y="8" width="6" height="6" rx="1.5" fill="currentColor"/></svg><span class="lb">전체 메뉴</span><?php if ($badge): ?><em class="adm-badge"><?= $badge ?></em><?php endif; ?></button>
    <div class="adm-me" id="admMe">
        <button type="button" class="<?= $isOwner ? '' : 'sub' ?>" aria-label="내 계정" title="<?= $h($meName) ?>"><?= $h($meInitial) ?></button>
        <div class="adm-me-pop">
            <b><?= $h($meName) ?></b>
            <small><?= $isOwner ? '모든 권한' : '대표 관리자가 정한 권한만 쓸 수 있어요' ?></small>
            <div class="adm-sess">자동 로그아웃까지 <b id="admSessLeft">-</b>
                <div class="adm-sess-btns"><button type="button" data-ext="1800">+30분</button><button type="button" data-ext="3600">+1시간</button><button type="button" data-ext="21600">+6시간</button></div>
            </div>
            <form method="post" action="admin_logout.php" id="admLogoutForm"><input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>"><button type="submit">로그아웃</button></form>
        </div>
    </div>
</header>
<nav class="adm-side" id="admSide" aria-label="관리자 메뉴">
    <div class="adm-side-hd"><b>LOVE DAY</b><button type="button" id="admSideX" aria-label="메뉴 닫기">✕</button></div>
    <div class="adm-find" hidden data-side-find><input type="search" data-adm-find placeholder="메뉴 찾기" aria-label="메뉴 찾기" autocomplete="off"><div class="adm-hits" role="listbox"></div></div>
    <?php foreach ($groups as [$gName, $gDesc, $items]): ?>
    <h4><?= $h($gName) ?></h4>
    <?php foreach ($items as $key => $it) echo $link($key, $it, ''); ?>
    <?php endforeach; ?>
    <button type="button" class="adm-side-map" data-open-map>전체 메뉴 한눈에 보기</button>
</nav>
<div class="adm-scrim" id="admScrim"></div>
<div class="adm-map" id="admMap" hidden role="dialog" aria-modal="true" aria-labelledby="admMapTitle">
    <div class="adm-map-box">
        <div class="adm-map-hd">
            <h2 id="admMapTitle">전체 메뉴</h2><small>하는 일끼리 묶었어요</small>
            <div class="adm-find"><input type="search" data-adm-find placeholder="메뉴 찾기 (예: 금지어, VIP)" aria-label="메뉴 찾기" autocomplete="off"><div class="adm-hits" role="listbox"></div></div>
            <button type="button" class="adm-map-x" id="admMapX" aria-label="닫기">✕</button>
        </div>
        <div class="adm-todo">
            <?php if ($badge): ?><a href="admin_naver.php"><em class="adm-badge"><?= $badge ?></em>확인할 스마트스토어 주문이 있어요</a>
            <?php else: ?><span>지금 확인할 일이 없어요</span><?php endif; ?>
        </div>
        <div class="adm-cards">
            <?php foreach ($groups as [$gName, $gDesc, $items]): ?>
            <section class="adm-card"><h3><?= $h($gName) ?></h3><small><?= $h($gDesc) ?></small>
                <?php foreach ($items as $key => $it): ?>
                <a href="<?= $h($it[0]) ?>" data-nav="<?= $h($key) ?>"><b><?= $h($it[1]) ?><?php if ($key === 'naver' && $badge): ?><em class="adm-badge"><?= $badge ?></em><?php endif; ?></b><span><?= $h($it[2]) ?></span></a>
                <?php endforeach; ?>
            </section>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<div class="adm-sess-pop" id="admSessPop" hidden role="alertdialog" aria-modal="true" aria-labelledby="admSessTitle">
    <div class="box">
        <div class="ic">⏳</div>
        <h3 id="admSessTitle">곧 자동 로그아웃돼요</h3>
        <p><b id="admSessCount">5:00</b> 뒤에 로그아웃돼요.<br>계속 작업하시려면 로그인을 연장해주세요.</p>
        <div class="btns"><button type="button" class="main" data-ext="1800">30분 연장</button><button type="button" data-ext="3600">1시간 연장</button><button type="button" data-ext="21600">6시간 연장</button></div>
        <div class="sub"><button type="button" id="admSessLater">닫기</button><button type="button" id="admSessOut">지금 로그아웃</button></div>
    </div>
</div>
<?php if ($readonly): ?>
<div class="adm-ro" id="admRo">🔒 <span>보기 전용이에요. 이 화면을 저장·삭제할 권한이 없어요 (대표 관리자에게 요청).</span></div>
<?php endif; ?>
<script>
(function () {
    var ACTIVE = <?= json_encode($active) ?>;
    var MENU = <?= json_encode(array_merge(...array_map(fn($g) => array_map(fn($k, $it) => ['k' => $k, 'u' => $it[0], 'n' => $it[1], 'd' => $it[2], 'w' => $it[3], 'g' => $g[0]], array_keys($g[2]), $g[2]), $groups ?: [['', '', []]])), JSON_UNESCAPED_UNICODE) ?>;
    var root = document.documentElement, side = document.getElementById('admSide');
    // ---- 지금 메뉴 표시 (섹션 설정은 주소 #탭 따라 간편 만들기 · 전문가 모드 · 기기별 설정) ----
    function curKey() {
        if (ACTIVE !== 'sections') return ACTIVE;
        var hs = location.hash;
        return hs === '#easy' ? 'easy' : hs === '#device' ? 'device' : 'sections';
    }
    window.admNavSync = function () {
        var k = curKey();
        document.querySelectorAll('[data-nav]').forEach(function (a) {
            var on = a.dataset.nav === k; a.classList.toggle('on', on);
            if (on) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
    };
    admNavSync();
    window.addEventListener('hashchange', admNavSync);
    var onLink = side && side.querySelector('a.on');
    if (onLink && onLink.offsetTop > side.clientHeight - 60) side.scrollTop = onLink.offsetTop - side.clientHeight / 2;
    // ---- 좁은 화면: ☰ 서랍 ----
    var burger = document.getElementById('admBurger');
    function drawer(open) {
        root.classList.toggle('adm-drawer', open);
        if (burger) burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        var sf = side.querySelector('[data-side-find]'); if (sf) sf.hidden = !open;
    }
    if (burger) burger.addEventListener('click', function () { drawer(!root.classList.contains('adm-drawer')); });
    document.getElementById('admScrim').addEventListener('click', function () { drawer(false); });
    document.getElementById('admSideX').addEventListener('click', function () { drawer(false); });
    side.addEventListener('click', function (e) { if (e.target.closest('a')) drawer(false); });
    // ---- 전체 메뉴 (사이트맵) ----
    var map = document.getElementById('admMap'), mapBtn = document.getElementById('admMapBtn');
    function openMap(on) {
        if (on === !map.hidden) return;
        if (on) {
            drawer(false); map.hidden = false; requestAnimationFrame(function () { map.classList.add('show'); });
            var k = curKey(); map.querySelectorAll('[data-nav]').forEach(function (a) { a.classList.toggle('on', a.dataset.nav === k); });
            var inp = map.querySelector('[data-adm-find]'); if (inp && matchMedia('(hover: hover)').matches) inp.focus();
        } else { map.classList.remove('show'); setTimeout(function () { if (!map.classList.contains('show')) map.hidden = true; }, 180); if (mapBtn) mapBtn.focus(); }
    }
    if (mapBtn) mapBtn.addEventListener('click', function () { openMap(true); });
    document.querySelectorAll('[data-open-map]').forEach(function (b) { b.addEventListener('click', function () { openMap(true); }); });
    document.getElementById('admMapX').addEventListener('click', function () { openMap(false); });
    map.addEventListener('click', function (e) { if (e.target === map) openMap(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { if (!map.hidden) openMap(false); else if (root.classList.contains('adm-drawer')) drawer(false); return; }
        var t = e.target, typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
        if (e.key === '/' && !typing && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); openMap(true); }
    });
    // ---- 메뉴 찾기 (위 · 서랍 · 사이트맵 칸 셋 다 같은 방식) ----
    var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    function find(q) {
        q = q.trim().toLowerCase().replace(/\s+/g, ' '); if (!q) return [];
        var words = q.split(' ');
        return MENU.map(function (m) {
            var name = m.n.toLowerCase(), all = (m.n + ' ' + m.d + ' ' + m.w + ' ' + m.g).toLowerCase();
            if (!words.every(function (w) { return all.indexOf(w) >= 0; })) return null;
            return { m: m, s: name.indexOf(words[0]) === 0 ? 0 : name.indexOf(words[0]) > 0 ? 1 : 2 };
        }).filter(Boolean).sort(function (a, b) { return a.s - b.s; }).slice(0, 8).map(function (x) { return x.m; });
    }
    document.querySelectorAll('[data-adm-find]').forEach(function (inp) {
        var box = inp.parentNode.querySelector('.adm-hits'), cur = -1;
        function draw() {
            var r = find(inp.value); cur = r.length ? 0 : -1;
            if (!inp.value.trim()) { box.classList.remove('on'); return; }
            box.innerHTML = r.length ? r.map(function (m, i) { return '<a href="' + esc(m.u) + '" role="option" class="' + (i === 0 ? 'cur' : '') + '"><b>' + esc(m.n) + '</b><i>' + esc(m.g) + '</i><small>' + esc(m.d) + '</small></a>'; }).join('')
                : '<p>찾는 메뉴가 없어요. 다른 말로 찾아보세요.</p>';
            box.classList.add('on');
        }
        inp.addEventListener('input', draw);
        inp.addEventListener('focus', function () { if (inp.value.trim()) draw(); });
        inp.addEventListener('keydown', function (e) {
            var links = box.querySelectorAll('a'); if (!links.length) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); cur = (cur + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length; links.forEach(function (a, i) { a.classList.toggle('cur', i === cur); }); }
            else if (e.key === 'Enter' && cur >= 0) { e.preventDefault(); links[cur].click(); }
        });
        box.addEventListener('click', function (e) { var a = e.target.closest('a'); if (!a) return; box.classList.remove('on'); inp.value = ''; openMap(false); drawer(false); });
        document.addEventListener('click', function (e) { if (!inp.parentNode.contains(e.target)) box.classList.remove('on'); });
    });
    // 같은 화면 안 탭 메뉴(섹션 설정 #easy 등)를 눌렀을 때도 서랍·사이트맵을 닫음
    document.querySelectorAll('.adm-card a, .adm-side a').forEach(function (a) { a.addEventListener('click', function () { openMap(false); }); });
    // 내 계정 동그라미
    var me = document.getElementById('admMe');
    if (me) {
        me.querySelector('button').addEventListener('click', function (e) { e.stopPropagation(); me.classList.toggle('open'); });
        document.addEventListener('click', function (e) { if (!me.contains(e.target)) me.classList.remove('open'); });
    }
    // 보기 전용 화면: 저장(POST) 폼의 버튼·입력칸을 잠금 (서버에서도 막혀 있음)
    if (document.getElementById('admRo')) {
        var lock = function () {
            document.querySelectorAll('form').forEach(function (f) {
                if ((f.getAttribute('method') || '').toLowerCase() !== 'post' || f.closest('#admMe')) return;
                f.querySelectorAll('button, input, select, textarea').forEach(function (el) {
                    if (el.type === 'hidden') return;
                    el.disabled = true; el.classList.add('adm-locked');
                });
                f.addEventListener('submit', function (e) { e.preventDefault(); }, true);
            });
        };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', lock); else lock();
    }
    // ---- 자동 로그아웃 (30분 동안 아무것도 안 하면) - 5분 남으면 연장 팝업 ----
    (function sessionTimer() {
        var CSRF = <?= json_encode(csrf_token()) ?>;
        var deadline = Date.now() + <?= (int) $sessLeft ?> * 1000;
        var pop = document.getElementById('admSessPop'), leftEl = document.getElementById('admSessLeft'), cntEl = document.getElementById('admSessCount');
        var laterUntil = 0, checking = false, lastPoll = Date.now(), gone = false;
        function fmtLong(sec) {
            var h = Math.floor(sec / 3600), m = Math.ceil((sec % 3600) / 60);
            if (m === 60) { h++; m = 0; }
            return sec < 60 ? sec + '초' : (h ? h + '시간' + (m ? ' ' + m + '분' : '') : m + '분');
        }
        function fmtClock(sec) { return Math.floor(sec / 60) + ':' + ('0' + sec % 60).slice(-2); }
        function showPop(on) {
            if (on === !pop.hidden) return;
            if (on) { pop.hidden = false; requestAnimationFrame(function () { pop.classList.add('show'); }); var b = pop.querySelector('.main'); if (b) b.focus(); }
            else { pop.classList.remove('show'); setTimeout(function () { if (!pop.classList.contains('show')) pop.hidden = true; }, 200); }
        }
        function expire() {
            if (gone) return; gone = true;
            location.href = 'admin_login.php?expired=1';
        }
        function poll() {
            if (checking) return; checking = true; lastPoll = Date.now();
            return fetch('admin_session.php', { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (!j.ok) { if (j.expired) expire(); return; } deadline = Date.now() + j.left * 1000; })
                .catch(function () {}).then(function () { checking = false; tick(); });
        }
        function tick() {
            var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
            if (leftEl) leftEl.textContent = fmtLong(left);
            if (cntEl) cntEl.textContent = fmtClock(left);
            if (left <= 0) { poll(); if (left <= 0 && Date.now() - deadline > 3000) expire(); return; }
            // 5분 남으면 (다른 탭에서 작업했을 수도 있으니 서버에 한 번 확인한 뒤) 팝업
            var want = left <= 300 && (Date.now() > laterUntil || left <= 60);
            if (want && pop.hidden && Date.now() - lastPoll > 10000) { poll(); return; }
            showPop(want);
            if (!pop.hidden && Date.now() - lastPoll > 15000) poll(); // 팝업이 떠 있는 동안 다른 탭에서 연장했는지 확인
        }
        function extend(sec, btn) {
            var all = document.querySelectorAll('[data-ext]');
            all.forEach(function (b) { b.disabled = true; });
            var fd = new FormData(); fd.set('csrf_token', CSRF); fd.set('act', 'extend'); fd.set('sec', sec);
            fetch('admin_session.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (!j.ok) { if (j.expired) expire(); else throw new Error(j.error || ''); return; }
                    deadline = Date.now() + j.left * 1000; laterUntil = 0; showPop(false); tick();
                    var msg = '로그인을 ' + fmtLong(+sec) + ' 연장했어요';
                    if (window.LD && LD.toast) LD.toast(msg);
                })
                .catch(function () { if (window.LD) LD.alert('연장하지 못했어요. 새로고침 후 다시 해주세요.'); })
                .then(function () { all.forEach(function (b) { b.disabled = false; }); });
        }
        document.querySelectorAll('[data-ext]').forEach(function (b) {
            b.addEventListener('click', function (e) { e.stopPropagation(); extend(b.dataset.ext, b); });
        });
        document.getElementById('admSessLater').addEventListener('click', function () { laterUntil = Date.now() + 10 * 60000; showPop(false); }); // 1분 남으면 다시 뜸
        document.getElementById('admSessOut').addEventListener('click', function () { var f = document.getElementById('admLogoutForm'); if (f) f.submit(); else expire(); });
        setInterval(tick, 1000);
        setInterval(function () { if (Date.now() - lastPoll > 60000) poll(); }, 5000); // 1분마다 서버와 맞춤 (다른 탭·저장 작업 반영)
        document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
        tick();
    })();
})();
</script>
<?php
}
