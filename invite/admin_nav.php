<?php
/**
 * admin_nav.php - 관리자 화면 공통 상단바
 *
 * 쓰는 법 (각 관리자 페이지의 <body> 바로 아래):
 *   <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('snap', '게스트스냅 설정'); ?>
 *
 *  - 왼쪽 LOVE DAY는 고정, 메뉴들만 가로로 밀어서(슬라이드) 넘긴다
 *  - 지금 보고 있는 메뉴는 까맣게 표시되고, 처음 열 때 화면 안으로 자동으로 스크롤된다
 *  - 휴대폰(760px 이하)에서는 "게스트스냅 설정" 같은 페이지 이름은 숨긴다 (메뉴에 이미 표시되므로)
 *  - 스마트스토어 연동 옆에는 확인이 필요한 주문 수가 숫자로 붙는다
 *  - 부관리자: 권한이 없어 열 수 없는 메뉴는 숨기고, 보기만 되는 화면은 위에 "보기 전용" 띠 + 저장 버튼 잠금
 *  - 오른쪽 끝 동그라미 = 지금 로그인한 사람 (누르면 이름·로그아웃)
 * 스타일은 이 파일 안에 있다 (assets/admin.css를 고치지 않아도 됨).
 */
declare(strict_types=1);

const ADMIN_MENU = [
    'list'      => ['admin_create.php', '청첩장 목록'],
    'vip'       => ['admin_vip.php', 'VIP 코드 생성'],
    'watermark' => ['admin_watermark.php', '워터마크'],
    'snap'      => ['admin_snap_settings.php', '게스트스냅'],
    'extra'     => ['admin_extra_settings.php', '부가기능'],
    'music'     => ['admin_music.php', '배경음악'],
    'naver'     => ['admin_naver.php', '스마트스토어 연동'],
    'site'      => ['admin_site_settings.php', '사이트 정보'],
    'homeimg'   => ['admin_home_images.php', '홈 이미지'],
    'designs'   => ['admin_designs.php', '추천 디자인'], // 새 청첩장 디자인 고르기 화면에 크게 보일 간편 템플릿
    'sections'  => ['admin_sections.php', '섹션 설정'], // 섹션 순서 · 편집창 모양 · 휴대폰 에디터 메뉴
    'stickers'  => ['admin_stickers.php', '스티커'],   // 에디터 스티커 창: 테마 순서·켜기, 그림 숨기기, 디자인별 추천
    'tips'      => ['admin_tips.php', '에디터 도움말'],
    'tiptours'  => ['admin_tip_tours.php', '커스텀 편집팁'],
    'trash'     => ['admin_trash.php', '휴지통'],
    'subadmin'  => ['admin_subadmins.php', '부관리자'],   // 대표 관리자에게만 보임 (admin_auth.php ADMIN_PAGE_RULES)
];

function admin_topbar(string $active, string $pageTitle = ''): void
{
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $badge = 0; // 스마트스토어: 확인 필요 + 교환 확인 중
    if (function_exists('admin_can') && admin_can('naver_orders')) {
        try { $badge = (int) get_pdo()->query("SELECT COUNT(*) FROM naver_orders WHERE result IN ('review','canceled','exchange_hold')")->fetchColumn(); } catch (Throwable $e) {}
    }
    $isOwner = !function_exists('admin_is_owner') || admin_is_owner();
    $meName = function_exists('admin_display_name') ? admin_display_name() : '관리자';
    $meInitial = $isOwner ? '대표' : mb_substr((string) ((admin_sub()['display_name'] ?? '') ?: (admin_sub()['username'] ?? '부')), 0, 1);
    $readonly = function_exists('admin_page_readonly') && admin_page_readonly();
    $sessLeft = function_exists('admin_session_deadline') ? max(0, admin_session_deadline() - time()) : 1800; // 자동 로그아웃까지 남은 초
    ?>
<style>
.adm-top { position: sticky; top: 0; z-index: 60; display: flex; align-items: center; gap: 14px; height: 56px; padding: 0 20px; background: #fff; border-bottom: 1px solid #E8E4DC;
    font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; box-sizing: border-box; }
.adm-top * { box-sizing: border-box; }
.adm-brand { flex: none; font-weight: 800; font-size: 14px; letter-spacing: .14em; color: #2B2320; text-decoration: none; white-space: nowrap; }
.adm-page { flex: none; font-size: 13.5px; color: #8A847B; white-space: nowrap; }
.adm-page::before { content: '·'; margin-right: 10px; color: #C9C3BA; }
.adm-nav { flex: 1; min-width: 0; display: flex; gap: 4px; overflow-x: auto; scroll-behavior: smooth; scrollbar-width: none; -webkit-overflow-scrolling: touch; padding: 4px 0; }
.adm-nav::-webkit-scrollbar { display: none; }
.adm-nav > a:first-child { margin-left: auto; } /* 넓은 화면에서는 오른쪽 정렬, 좁으면 왼쪽부터 밀어서 봄 */
.adm-nav a { flex: none; display: inline-flex; align-items: center; gap: 5px; padding: 7px 12px; border-radius: 999px; font-size: 13px; color: #555; text-decoration: none; white-space: nowrap; transition: background .15s, color .15s; }
.adm-nav a:hover { background: var(--ui-tint, #F4F1EC); color: #2B2320; }
.adm-nav a.on { background: #2B2320; color: #fff; }
.adm-nav .adm-badge { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #C7823A; color: #fff; font-size: 11px; font-weight: 700; line-height: 18px; text-align: center; }
/* PC: 메뉴 아래 회색 반투명 가로 스크롤바 (메뉴가 넘칠 때만, 끌거나 눌러서 이동) */
.adm-sbar { position: absolute; bottom: 4px; height: 5px; border-radius: 3px; background: rgba(60, 50, 40, .06); display: none; transition: height .15s, bottom .15s; }
.adm-sbar i { position: absolute; top: 0; bottom: 0; left: 0; border-radius: 3px; background: rgba(60, 50, 40, .26); cursor: grab; transition: background .15s; }
.adm-sbar:hover, .adm-sbar.drag { height: 7px; bottom: 3px; }
.adm-sbar:hover i, .adm-sbar.drag i { background: rgba(60, 50, 40, .45); }
.adm-sbar.drag i { cursor: grabbing; }
@media (hover: hover) and (pointer: fine) { .adm-sbar.on { display: block; } }
/* 양쪽 끝이 잘려 있으면 흐리게 - 더 밀 수 있다는 표시 */
.adm-nav.fade-l { -webkit-mask-image: linear-gradient(to right, transparent 0, #000 22px); mask-image: linear-gradient(to right, transparent 0, #000 22px); }
.adm-nav.fade-r { -webkit-mask-image: linear-gradient(to left, transparent 0, #000 22px); mask-image: linear-gradient(to left, transparent 0, #000 22px); }
.adm-nav.fade-l.fade-r { -webkit-mask-image: linear-gradient(to right, transparent 0, #000 22px, #000 calc(100% - 22px), transparent); mask-image: linear-gradient(to right, transparent 0, #000 22px, #000 calc(100% - 22px), transparent); }
/* 로그인한 사람 */
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
@media (max-width: 760px) {
    .adm-top { gap: 10px; padding: 0 0 0 16px; height: 52px; }
    .adm-me { margin-right: 12px; }
    .adm-ro { padding: 9px 16px; font-size: 12.5px; }
    .adm-page { display: none; }
    .adm-nav { padding-right: 12px; }
    .adm-nav a { padding: 7px 11px; font-size: 13px; }
}
</style>
<header class="adm-top">
    <a class="adm-brand" href="admin_create.php">LOVE DAY</a>
    <?php if ($pageTitle !== ''): ?><span class="adm-page"><?= $h($pageTitle) ?></span><?php endif; ?>
    <nav class="adm-nav" id="admNav" aria-label="관리자 메뉴">
        <?php foreach (ADMIN_MENU as $key => [$url, $label]):
            if (function_exists('admin_page_open') && !admin_page_open($url)) continue; // 부관리자가 열 수 없는 메뉴는 숨김 ?>
            <a href="<?= $h($url) ?>" class="<?= $key === $active ? 'on' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= $h($label) ?><?php if ($key === 'naver' && $badge): ?><span class="adm-badge"><?= $badge ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="adm-sbar" id="admSbar" aria-hidden="true"><i></i></div>
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
    var nav = document.getElementById('admNav'); if (!nav) return;
    // 메뉴가 넘치면 왼쪽 "· 화면 이름"은 숨김 (같은 이름이 메뉴에 진하게 표시돼 있음) → 메뉴를 더 많이 보여줌
    var pg = document.querySelector('.adm-page');
    if (pg && nav.scrollWidth > nav.clientWidth) pg.style.display = 'none';
    var on = nav.querySelector('a.on');
    // 지금 메뉴가 화면 밖에 있으면 가운데쯤으로 (처음 한 번은 애니메이션 없이)
    if (on && nav.scrollWidth > nav.clientWidth) {
        var nr = nav.getBoundingClientRect(), or = on.getBoundingClientRect();
        if (or.left < nr.left + 24 || or.right > nr.right - 24) { // 가려져 있거나 흐린 끝에 걸쳐 있으면
            nav.style.scrollBehavior = 'auto';
            nav.scrollLeft += (or.left - nr.left) - (nav.clientWidth - or.width) / 2;
            nav.style.scrollBehavior = '';
        }
    }
    function fade() {
        var max = nav.scrollWidth - nav.clientWidth;
        nav.classList.toggle('fade-l', nav.scrollLeft > 2);
        nav.classList.toggle('fade-r', max - nav.scrollLeft > 2);
    }
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
    // ---- PC 가로 스크롤바 ----
    var sbar = document.getElementById('admSbar'), thumb = sbar && sbar.querySelector('i'), header = nav.closest('.adm-top');
    function syncBar() {
        if (!sbar) return;
        var sw = nav.scrollWidth, cw = nav.clientWidth;
        var on = sw > cw + 1;
        sbar.classList.toggle('on', on);
        if (!on) return;
        var hr = header.getBoundingClientRect(), nr = nav.getBoundingClientRect();
        sbar.style.left = (nr.left - hr.left) + 'px';
        sbar.style.width = cw + 'px';
        var tw = Math.max(36, cw * cw / sw);
        thumb.style.width = tw + 'px';
        thumb.style.transform = 'translateX(' + (nav.scrollLeft / (sw - cw) * (cw - tw)) + 'px)';
    }
    if (sbar) {
        var dragX = null, dragScroll = 0;
        thumb.addEventListener('pointerdown', function (e) {
            e.preventDefault(); e.stopPropagation();
            dragX = e.clientX; dragScroll = nav.scrollLeft;
            thumb.setPointerCapture(e.pointerId); sbar.classList.add('drag'); nav.style.scrollBehavior = 'auto';
        });
        thumb.addEventListener('pointermove', function (e) {
            if (dragX === null) return;
            var sw = nav.scrollWidth, cw = nav.clientWidth, tw = thumb.offsetWidth;
            nav.scrollLeft = dragScroll + (e.clientX - dragX) * (sw - cw) / Math.max(1, cw - tw);
        });
        var endDrag = function () { if (dragX === null) return; dragX = null; sbar.classList.remove('drag'); nav.style.scrollBehavior = ''; };
        thumb.addEventListener('pointerup', endDrag); thumb.addEventListener('pointercancel', endDrag);
        // 빈 곳을 누르면 그쪽으로 한 화면만큼
        sbar.addEventListener('pointerdown', function (e) {
            if (e.target === thumb) return;
            var tr = thumb.getBoundingClientRect();
            nav.scrollLeft += (e.clientX < tr.left ? -1 : 1) * nav.clientWidth * .8;
        });
    }
    nav.addEventListener('scroll', function () { fade(); syncBar(); }, { passive: true });
    window.addEventListener('resize', function () { fade(); syncBar(); });
    if (document.readyState !== 'complete') window.addEventListener('load', syncBar);
    syncBar();
    // PC: 마우스 휠(세로)로도 가로로 넘기기
    nav.addEventListener('wheel', function (e) {
        if (nav.scrollWidth <= nav.clientWidth || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return;
        nav.scrollLeft += e.deltaY; e.preventDefault();
    }, { passive: false });
    fade();
})();
</script>
<?php
}
