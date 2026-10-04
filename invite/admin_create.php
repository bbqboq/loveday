<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
@include_once __DIR__ . '/guest_functions.php'; // DEMO_MEMO(체험 청첩장 표시), demo_cleanup() - 없어도 목록은 동작
require_once __DIR__ . '/admin_search.php';

$pdo = get_pdo();
$error  = '';

// 검색: 코드 · 고객코드 · 신랑신부 이름 · 전화번호 · 구매자 이름 (admin_search.php)
//  코드(loveday.kr/코드)와 정확히 같으면 예전 "코드로 찾기"처럼 바로 수정 화면으로 간다
$q = trim((string) ($_GET['q'] ?? $_GET['find_slug'] ?? ''));
$search = null; $searchTrash = null; $searchRows = [];
if ($q !== '') {
    if (preg_match('/^[A-Za-z0-9]{4,20}$/', $q) && ($found = find_invitation_by_slug_any_status($pdo, strtolower($q)))) {
        header('Location: admin_edit.php?id=' . (int) $found['id']);
        exit;
    }
    $search = admin_search_invitations($pdo, $q, false);
    $searchTrash = admin_search_invitations($pdo, $q, true);
    if ($search['ids']) {
        $ids = array_slice(array_keys($search['ids']), 0, 200);
        $st = $pdo->prepare('SELECT o.id, o.groom_name, o.bride_name, o.status, o.created_at, o.view_slug, o.order_platform, o.order_memo, o.expires_at, o.storage_plan,
                                    o.customer_phone_enc, o.customer_id, c.customer_code
                             FROM invitation_orders o LEFT JOIN customers c ON c.id = o.customer_id
                             WHERE o.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY o.id DESC');
        $st->execute($ids);
        $searchRows = $st->fetchAll();
    }
}

// 체험·테스트 탭의 "만료된 체험 지금 정리" 버튼
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'demo_cleanup') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $n = function_exists('demo_cleanup') ? demo_cleanup($pdo, 1000) : 0;
    header('Location: admin_create.php?cleaned=' . $n . '#tab-test');
    exit;
}

// VIP 전용 코드 생성은 상단 메뉴 "VIP 코드 생성"(admin_vip.php)으로 옮겼다

// 최근 생성 목록 (관리자 확인용) - 고객코드/연락처도 같이 보여줌.
// 같은 고객이 여러 개를 만들면(디자인 이것저것 시도해보는 경우 등) 고객 단위로 통째로 묶어서
// 목록이 무한정 길어지지 않게 하고, 클릭하면 펼쳐서 개별 항목을 보여준다.
// 목록을 세 칸(탭)으로 나눈다: 고객(네이버 로그인) / VIP(관리자가 지정 코드로 발급) / 체험·테스트(가입 없이 체험, 공용 테스트베드 등)
$recent = $pdo->query('
    SELECT o.id, o.groom_name, o.bride_name, o.status, o.created_at, o.view_slug, o.order_platform, o.order_memo, o.expires_at, o.storage_plan,
           o.customer_phone_enc, o.customer_id, c.customer_code
    FROM invitation_orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.deleted_at IS NULL
    ORDER BY o.id DESC LIMIT 500
')->fetchAll();

$demoPrefix = defined('DEMO_MEMO') ? DEMO_MEMO : '[체험]';
// 공용 테스트베드(testbed_setup.sql - 고객코드 TESTBED)와 가입 없이 만든 체험은 "체험·테스트"로
$isTest = fn(array $r) => $r['view_slug'] === 'testbed' || ($r['customer_code'] ?? '') === 'TESTBED'
    || (!$r['customer_id'] && (str_starts_with((string) $r['order_memo'], $demoPrefix) || $r['order_platform'] !== 'vip_admin'));
$tabs = ['cust' => [], 'vip' => [], 'test' => []];
foreach ($recent as $row) {
    if ($isTest($row)) { $tabs['test']['t' . $row['id']][] = $row; continue; }
    if ($row['customer_id']) $tabs['cust']['c' . $row['customer_id']][] = $row;   // 같은 고객 건은 하나로 묶음
    else $tabs['vip']['v' . $row['id']][] = $row;
}
// 공용 테스트베드는 체험·테스트 탭 맨 위에 고정
uasort($tabs['test'], fn($a, $b) => (($b[0]['view_slug'] === 'testbed') <=> ($a[0]['view_slug'] === 'testbed')) ?: ($b[0]['id'] <=> $a[0]['id']));
$tabCount = array_map(fn($g) => array_sum(array_map('count', $g)), $tabs);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>청첩장 관리 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .list-tabs { display: flex; gap: 6px; margin: 0 0 14px; flex-wrap: wrap; }
    .list-tabs button { border: 1px solid #ddd; background: #fff; border-radius: 999px; padding: 8px 16px; font: inherit; font-size: 13.5px; cursor: pointer; color: #555; }
    .list-tabs button b { margin-left: 4px; }
    .list-tabs button.on { background: #2B2B2B; border-color: #2B2B2B; color: #fff; }
    .tab-pane { display: none; } .tab-pane.on { display: block; }
    .tab-note { font-size: 12.5px; color: var(--muted); margin: 0 0 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    .tag-test { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background: #EEF1F6; color: #4A5A73; margin-left: 6px; }
    .tag-demo { background: #FFF3E0; color: #9A6414; }
    .empty-tab { padding: 30px; text-align: center; color: #999; border: 1px dashed #ddd; border-radius: 10px; }
    /* ---- 목록 (PC: 표 + ⋯ 메뉴 / 모바일: 표 + 왼쪽으로 밀면 수정·삭제) ---- */
    .ivt, .ivt * { box-sizing: border-box; }
    .ivt { background: #fff; border: 1px solid #ECE8E2; border-radius: 14px; overflow: hidden; }
    .iv-cols { display: grid; grid-template-columns: 46px minmax(110px, 1.6fr) 74px 70px minmax(84px, 1fr) minmax(96px, 1fr) 96px 40px; align-items: center; column-gap: 10px; }
    .iv-mc { display: flex; justify-content: flex-end; }
    .iv-head { padding: 9px 14px; background: #FCFBF9; border-bottom: 1px solid #ECE8E2; font-size: 11.5px; font-weight: 600; color: #A29C94; }
    .iv-head span:last-child { text-align: right; }
    .ivw { position: relative; overflow: hidden; border-bottom: 1px solid #F1EEE9; }
    .ivt > .ivw:last-child, .iv-gbody > .ivw:last-child { border-bottom: 0; }
    .ivr { position: relative; z-index: 1; background: #fff; padding: 8px 14px; min-height: 40px; font-size: 13px; cursor: pointer; transition: transform .22s ease, background .15s; touch-action: pan-y; }
    .ivr:hover { background: #FBF9F6; }
    .ivw.dragging .ivr { transition: none; }
    .iv-no { font-size: 11.5px; color: #A29C94; font-weight: 600; }
    .iv-nm { min-width: 0; display: flex; align-items: center; gap: 6px; }
    .iv-nm a { color: inherit; text-decoration: none; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .iv-nm a.noname { color: #B3ADA5; font-weight: 500; }
    .iv-nm .tag-test { margin-left: 0; flex: none; }
    .iv-st { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; white-space: nowrap; color: #555; }
    .iv-st::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #3E8E5E; flex: none; }
    .iv-st.editing::before { background: #B08A3E; } .iv-st.expired::before { background: #B55A4A; }
    .iv-plan { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 5px; white-space: nowrap; justify-self: start; }
    .iv-plan.trial { background: #FFF3E0; color: #9A6414; } .iv-plan.one_year { background: #EEF1F6; color: #4A5A73; } .iv-plan.permanent { background: #E9F5EE; color: #2F7A4E; }
    .iv-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: #6F6A63; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .iv-ph, .iv-dt { font-size: 12px; color: #8F8980; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .iv-dt .s { display: none; }
    .iv-more { justify-self: end; width: 32px; height: 30px; border: 0; border-radius: 8px; background: transparent; font-size: 18px; line-height: 1; color: #6F6A63; cursor: pointer; }
    .iv-more:hover, .iv-more.on { background: #F1EDE7; }
    .iv-act { position: absolute; inset: 0 0 0 auto; display: none; }
    .iv-act a, .iv-act button { display: grid; place-items: center; width: 64px; border: 0; color: #fff; font: inherit; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
    .iv-act .e { background: #5E6B7E; } .iv-act .d { background: #A8434B; }
    /* 같은 고객 묶음 */
    .iv-grp { border-bottom: 1px solid #ECE8E2; }
    .ivt > .iv-grp:last-child { border-bottom: 0; }
    .iv-ghead { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 14px; border: 0; background: #F6F2EB; font: inherit; font-size: 13px; text-align: left; cursor: pointer; flex-wrap: wrap; }
    .iv-ghead:hover { background: #F1ECE3; }
    .iv-ghead .chev { transition: transform .2s; color: #8F8980; font-size: 11px; }
    .iv-grp.open .iv-ghead .chev { transform: rotate(90deg); }
    .iv-ghead b { font-size: 13.5px; }
    .iv-ghead .sum { color: #6F6A63; font-size: 12px; }
    .iv-ghead .lat { margin-left: auto; color: #A29C94; font-size: 11.5px; }
    .iv-gbody { display: none; border-left: 3px solid #E8DFD0; }
    .iv-grp.open .iv-gbody { display: block; }
    /* ⋯ 메뉴 (화면에 하나만 떠서 줄 위치로 옮겨 다님) */
    .iv-menu { position: fixed; z-index: 300; min-width: 170px; background: #fff; border: 1px solid #ECE8E2; border-radius: 12px; box-shadow: 0 14px 34px rgba(30, 20, 10, .14); padding: 5px; display: none; }
    .iv-menu.on { display: block; }
    .iv-menu a, .iv-menu button { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 11px; border: 0; border-radius: 8px; background: none; font: inherit; font-size: 13px; color: #2B2B2B; text-decoration: none; text-align: left; cursor: pointer; }
    .iv-menu a:hover, .iv-menu button:hover { background: #F6F3EE; }
    .iv-menu .del { color: #A8434B; }
    .iv-menu hr { border: 0; border-top: 1px solid #F1EEE9; margin: 4px 2px; }
    .tab-note .mo { display: none; }
    /* 검색 */
    .ls-search { display: flex; gap: 8px; margin: 4px 0 6px; }
    .ls-search .box { position: relative; flex: 1; min-width: 0; }
    .ls-search input { width: 100%; box-sizing: border-box; padding: 12px 36px 12px 40px; border: 1px solid #E3DED6; border-radius: 12px; font: inherit; font-size: 14.5px; background: #fff; }
    .ls-search input:focus { outline: none; border-color: #2B2B2B; box-shadow: 0 0 0 3px rgba(43, 43, 43, .08); }
    .ls-search .ic { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; fill: none; stroke: #A29C94; stroke-width: 2; pointer-events: none; }
    .ls-search .x { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 50%; background: #EEEAE4; color: #6F6A63; display: grid; place-items: center; text-decoration: none; font-size: 12px; }
    .ls-search button { flex: none; border: 0; border-radius: 12px; background: #2B2B2B; color: #fff; font: inherit; font-size: 14.5px; font-weight: 600; padding: 0 20px; cursor: pointer; }
    .ls-hint { font-size: 12px; color: var(--muted); margin: 0 0 16px; line-height: 1.6; }
    .ls-found { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0 0 10px; font-size: 13px; color: #6F6A63; }
    .ls-found b { color: #2B2B2B; } .ls-found a { color: #4A5A73; }
    .ls-found .warn { width: 100%; font-size: 12px; color: #8A5A00; }
    .iv-why { flex: none; font-size: 10.5px; font-weight: 600; padding: 1px 6px; border-radius: 5px; background: #EAF3FF; color: #3A64A8; white-space: nowrap; }
    @media (max-width: 720px) { .iv-why { display: none; } }

    @media (max-width: 720px) {
        .iv-cols { grid-template-columns: 30px minmax(0, 1fr) 54px 86px 40px; column-gap: 8px; }
        .iv-cols .iv-pc { display: none; }
        .iv-head, .ivr { padding-left: 12px; padding-right: 12px; }
        .ivr { min-height: 42px; }
        .iv-dt .l { display: none; } .iv-dt .s { display: inline; }
        .iv-dt, .iv-head .hd-dt { text-align: right; }
        .iv-nm .tag-test .l { display: none; }
        .iv-act { display: flex; }
        .iv-ghead .lat { margin-left: 0; width: 100%; }
        .tab-note .pc-note { display: none; } .tab-note .mo { display: inline; }
    }
</style>
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('list', '청첩장 관리'); ?>

    <div class="wrap">
        <form class="ls-search" method="get" role="search">
            <div class="box">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="코드 · 고객코드 · 신랑신부 이름 · 전화번호" autocomplete="off" enterkeyhint="search">
                <?php if ($q !== ''): ?><a class="x" href="admin_create.php" aria-label="검색 지우기">✕</a><?php endif; ?>
            </div>
            <button type="submit">검색</button>
        </form>
        <p class="ls-hint">코드와 정확히 같으면 바로 수정 화면으로 가요. 고객이 코드를 모르면 신랑·신부 이름(부모님 이름도)이나 전화번호 4자리 이상으로 찾으세요.</p>

        <?php if ($search):
            $sStatus = ['editing' => '편집중', 'published' => '발행됨', 'expired' => '만료'];
            $sPlan = ['trial' => '무료체험', 'one_year' => '1년', 'permanent' => '영구'];
            $sDemo = defined('DEMO_MEMO') ? DEMO_MEMO : '[체험]';
            $sE = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $trashN = count($searchTrash['ids']);
        ?>
        <div class="ls-found">
            <span>"<b><?= $sE($q) ?></b>" 검색 결과 <b><?= count($search['ids']) ?>건</b></span>
            <?php if ($trashN): ?><a href="admin_trash.php?q=<?= rawurlencode($q) ?>">휴지통에도 <?= $trashN ?>건 있어요 →</a><?php endif; ?>
            <a href="admin_create.php" style="margin-left:auto">검색 닫기</a>
            <?php if ($search['phone_blocked']): ?><span class="warn">🔒 전화번호로 찾기는 개인정보 권한이 필요해요. 코드나 이름으로 찾아주세요.</span><?php endif; ?>
        </div>
        <?php if ($searchRows): ?>
        <div class="ivt" style="margin-bottom:26px;">
            <div class="iv-head iv-cols"><span>#</span><span>이름</span><span>상태</span><span class="iv-pc">보관</span><span>코드</span><span class="iv-pc">연락처</span><span class="hd-dt">만든 날</span><span class="iv-pc"></span></div>
            <?php foreach ($searchRows as $sr): ?>
                <?= render_invite_row($sr, $sStatus, $sPlan, $sDemo, $sE, $search['ids'][(int) $sr['id']] ?? []) ?>
            <?php endforeach; ?>
        </div>
        <?php elseif (!$trashN): ?>
        <div class="panel" style="text-align:center;color:var(--muted);font-size:13.5px;">찾는 청첩장이 없어요. 이름 한 글자나 전화번호 뒷자리 4개로도 찾아보세요.</div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($error): ?><p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

        <h2 class="page-title">최근 생성 목록</h2>
        <p style="font-size:12.5px;color:var(--muted);margin:-14px 0 20px;">
            같은 고객이 만든 건 하나로 묶여 표시됩니다. 묶음 줄을 누르면 펼쳐지고, 다시 누르면 접힙니다.
        </p>

        <?php
        $statusLabel = ['editing' => '편집중', 'published' => '발행됨', 'expired' => '만료'];
        $planLabel   = ['trial' => '무료체험', 'one_year' => '1년', 'permanent' => '영구'];
        $demoMemo    = defined('DEMO_MEMO') ? DEMO_MEMO : '[체험]';
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        /** 청첩장 한 줄 - PC는 표 + ⋯ 메뉴, 모바일은 같은 줄을 왼쪽으로 밀면 수정·삭제 */
        function render_invite_row(array $row, array $statusLabel, array $planLabel, string $demoMemo, callable $e, array $why = []): string
        {
            $phone  = $row['customer_phone_enc'] ? decrypt_data($row['customer_phone_enc']) : '';
            if (function_exists('admin_can') && !admin_can('customer_private')) $phone = admin_mask_phone($phone); // 부관리자: 개인정보 권한 없으면 "비공개"
            $isDemo = !$row['customer_id'] && str_starts_with((string) $row['order_memo'], $demoMemo);
            $g = trim((string) $row['groom_name']); $b = trim((string) $row['bride_name']);
            $hasName = $g !== '' || $b !== '';
            $edit = 'admin_edit.php?id=' . (int) $row['id'];
            $ts = strtotime((string) $row['created_at']);
            ob_start();
            ?>
            <div class="ivw" data-id="<?= (int) $row['id'] ?>" data-slug="<?= $e($row['view_slug']) ?>" data-edit="<?= $e($edit) ?>" data-demo="<?= $isDemo ? '1' : '0' ?>" data-name="<?= $e($hasName ? ($g ?: '-') . ' ♥ ' . ($b ?: '-') : $row['view_slug']) ?>">
                <div class="iv-act"><a class="e" href="<?= $e($edit) ?>">수정</a><?php if (admin_can('invite_delete')): ?><button type="button" class="d" data-del>삭제</button><?php endif; ?></div>
                <div class="ivr iv-cols">
                    <span class="iv-no">#<?= (int) $row['id'] ?></span>
                    <span class="iv-nm">
                        <a href="<?= $e($edit) ?>"<?= $hasName ? '' : ' class="noname"' ?>><?= $hasName ? $e(($g ?: '-') . ' ♥ ' . ($b ?: '-')) : '이름 미입력' ?></a>
                        <?php if ($row['view_slug'] === 'testbed'): ?><span class="tag-test">테스트베드</span>
                        <?php elseif ($isDemo): ?><span class="tag-test tag-demo">체험<span class="l"> · <?= $e(date('m-d H:i', strtotime((string) $row['expires_at']))) ?> 삭제</span></span><?php endif; ?>
                        <?php if ($why): ?><span class="iv-why">찾음: <?= $e(implode('·', $why)) ?></span><?php endif; ?>
                    </span>
                    <span class="iv-st <?= $e($row['status']) ?>"><?= $e($statusLabel[$row['status']] ?? $row['status']) ?></span>
                    <span class="iv-pc"><span class="iv-plan <?= $e($row['storage_plan']) ?>"><?= $e($planLabel[$row['storage_plan']] ?? $row['storage_plan']) ?></span></span>
                    <span class="iv-code"><?= $e($row['view_slug']) ?></span>
                    <span class="iv-ph iv-pc"><?= $phone !== '' ? $e($phone) : '<span style="color:#CFC9C1">-</span>' ?></span>
                    <span class="iv-dt" title="<?= $e($row['created_at']) ?>"><span class="l"><?= $e(date('y-m-d H:i', $ts)) ?></span><span class="s"><?= $e(date('m-d', $ts)) ?></span></span>
                    <span class="iv-pc iv-mc"><button type="button" class="iv-more" aria-label="더보기">⋯</button></span>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
        ?>

        <?php if (isset($_GET['cleaned'])): ?><p class="notice success">만료된 체험 청첩장 <?= (int) $_GET['cleaned'] ?>건을 정리했습니다.</p><?php endif; ?>
        <?php if (isset($_GET['trashed'])): ?><p class="notice success">휴지통으로 옮겼습니다. <a href="admin_trash.php">휴지통</a>에서 <?= function_exists('trash_keep_days') ? trash_keep_days() : 30 ?>일 안에 되살릴 수 있어요.</p><?php endif; ?>
        <?php if (isset($_GET['purged'])): ?><p class="notice success">체험 청첩장을 삭제했습니다.</p><?php endif; ?>
        <div class="list-tabs" id="listTabs">
            <button type="button" data-tab="cust">고객 <b><?= $tabCount['cust'] ?></b></button>
            <button type="button" data-tab="vip">VIP <b><?= $tabCount['vip'] ?></b></button>
            <button type="button" data-tab="test">체험·테스트 <b><?= $tabCount['test'] ?></b></button>
        </div>
        <?php
        $tabNotes = [
            'cust' => '네이버 로그인으로 고객이 직접 만든 청첩장. 같은 고객 건은 하나로 묶여 있어요.',
            'vip'  => '상단 메뉴 <a href="admin_vip.php">VIP 코드 생성</a>에서 관리자가 발급한 청첩장.',
            'test' => '회원가입 없이 체험한 청첩장(정해진 시간 뒤 자동 삭제)과 공용 테스트베드. 실제 고객 건과 섞이지 않게 따로 모았습니다.',
        ];
        foreach ($tabs as $tabKey => $groups): ?>
        <div class="tab-pane" id="tab-<?= $tabKey ?>">
            <div class="tab-note"><span><?= $tabNotes[$tabKey] ?>
                <span class="pc-note">줄을 누르면 수정 화면, <b>⋯</b>에서 공개 주소·코드 복사·삭제.</span>
                <span class="mo">줄을 누르면 수정 화면, <b>왼쪽으로 밀면</b> 수정·삭제.</span></span>
                <?php if ($tabKey === 'test' && function_exists('demo_cleanup')): ?>
                    <form method="post" style="margin-left:auto;"><input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>"><input type="hidden" name="action" value="demo_cleanup">
                        <button class="btn btn-sm" type="submit">만료된 체험 지금 정리</button></form>
                <?php endif; ?>
            </div>
            <?php if (!$groups): ?><div class="empty-tab">아직 없습니다.</div><?php else: ?>
            <div class="ivt">
                <div class="iv-head iv-cols"><span>#</span><span>이름</span><span>상태</span><span class="iv-pc">보관</span><span>코드</span><span class="iv-pc">연락처</span><span class="hd-dt">만든 날</span><span class="iv-pc"></span></div>
                <?php foreach ($groups as $groupRows): ?>
                    <?php if (count($groupRows) === 1): ?>
                        <?= render_invite_row($groupRows[0], $statusLabel, $planLabel, $demoMemo, $e) ?>
                    <?php else:
                        $counts = ['editing' => 0, 'published' => 0, 'expired' => 0];
                        foreach ($groupRows as $r) { $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1; }
                        $summaryBits = [];
                        foreach ($counts as $st => $cnt) { if ($cnt > 0) $summaryBits[] = ($statusLabel[$st] ?? $st) . " {$cnt}"; }
                    ?>
                        <div class="iv-grp">
                            <button type="button" class="iv-ghead">
                                <span class="chev">▶</span>
                                <b>고객코드 <?= $e($groupRows[0]['customer_code'] ?? '-') ?></b>
                                <span class="sum"><?= count($groupRows) ?>건 · <?= $e(implode(' · ', $summaryBits)) ?></span>
                                <span class="lat">최근 <?= $e(date('y-m-d H:i', strtotime((string) $groupRows[0]['created_at']))) ?></span>
                            </button>
                            <div class="iv-gbody">
                                <?php foreach ($groupRows as $row): ?>
                                    <?= render_invite_row($row, $statusLabel, $planLabel, $demoMemo, $e) ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- ⋯ 메뉴 (하나를 줄마다 옮겨 씀) + 삭제용 폼 -->
        <div class="iv-menu" id="ivMenu" role="menu">
            <a data-m="edit" href="#">✏️ 수정</a>
            <a data-m="view" href="#" target="_blank" rel="noopener">🔗 공개 주소 열기</a>
            <button type="button" data-m="copy">📋 코드 복사</button>
            <?php if (admin_can('invite_delete')): ?>
            <hr>
            <button type="button" data-m="del" class="del">🗑 삭제</button>
            <?php endif; ?>
        </div>
        <form method="post" action="admin_delete.php" id="ivDelForm" hidden>
            <input type="hidden" name="csrf_token" value="<?= $e(csrf_token()) ?>">
            <input type="hidden" name="id" value="">
            <input type="hidden" name="tab" value="">
        </form>

        <script>
        (function () {
            const tabs = document.getElementById('listTabs');
            let curTab = 'cust';
            const show = key => {
                curTab = key;
                tabs.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.tab === key));
                document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('on', p.id === 'tab-' + key));
                closeAll();
            };
            tabs.addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (!b) return; show(b.dataset.tab); history.replaceState(null, '', '#tab-' + b.dataset.tab); });

            const mobile = window.matchMedia('(max-width: 720px)');
            const menu = document.getElementById('ivMenu');
            const TRASH_DAYS = <?= function_exists('trash_keep_days') ? trash_keep_days() : 30 ?>;
            let menuRow = null, openRow = null;
            const SLIDE = <?= admin_can('invite_delete') ? 128 : 64 ?>; // 밀었을 때 나오는 버튼 폭 (삭제 권한 없으면 수정만)

            // ---- ⋯ 메뉴 (PC) ----
            function closeMenu() { menu.classList.remove('on'); document.querySelectorAll('.iv-more.on').forEach(b => b.classList.remove('on')); menuRow = null; }
            function openMenu(btn, row) {
                closeMenu(); menuRow = row; btn.classList.add('on');
                menu.querySelector('[data-m=edit]').href = row.dataset.edit;
                menu.querySelector('[data-m=view]').href = '/' + row.dataset.slug;
                menu.classList.add('on');
                const r = btn.getBoundingClientRect(), mh = menu.offsetHeight, mw = menu.offsetWidth;
                const top = r.bottom + 4 + mh > window.innerHeight ? r.top - mh - 4 : r.bottom + 4;
                menu.style.top = Math.max(8, top) + 'px';
                menu.style.left = Math.max(8, Math.min(window.innerWidth - mw - 8, r.right - mw)) + 'px';
            }
            // ---- 밀어서 수정·삭제 (모바일) ----
            function setX(row, x) { row.querySelector('.ivr').style.transform = x ? `translateX(${x}px)` : ''; }
            function closeRow() { if (openRow) { setX(openRow, 0); openRow = null; } }
            function closeAll() { closeMenu(); closeRow(); }

            async function del(row) {
                closeAll();
                const demo = row.dataset.demo === '1';
                const msg = demo
                    ? `"${row.dataset.name}" 체험 청첩장을 삭제할까요?\n체험 청첩장은 휴지통 없이 바로 지워져요.`
                    : `"${row.dataset.name}" 청첩장을 휴지통으로 옮길까요?\n${TRASH_DAYS}일 안에는 휴지통에서 되살릴 수 있어요.`;
                const ok = window.LD ? await LD.confirm(msg, { ok: demo ? '삭제' : '휴지통으로', danger: true }) : confirm(msg);
                if (!ok) return;
                const f = document.getElementById('ivDelForm');
                f.id.value = row.dataset.id; f.tab.value = curTab; f.submit();
            }

            document.addEventListener('click', e => {
                const t = e.target;
                // 메뉴 항목
                const m = t.closest('#ivMenu [data-m]');
                if (m) {
                    const row = menuRow;
                    if (m.dataset.m === 'copy') { e.preventDefault(); closeMenu(); window.LD ? LD.copy(row.dataset.slug, '코드를 복사했어요') : navigator.clipboard.writeText(row.dataset.slug); }
                    else if (m.dataset.m === 'del') { e.preventDefault(); del(row); }
                    else closeMenu();
                    return;
                }
                const more = t.closest('.iv-more');
                if (more) { e.stopPropagation(); const row = more.closest('.ivw'); menuRow === row ? closeMenu() : openMenu(more, row); return; }
                if (!t.closest('#ivMenu')) closeMenu();
                // 묶음 펼치기
                const gh = t.closest('.iv-ghead');
                if (gh) { closeRow(); gh.closest('.iv-grp').classList.toggle('open'); return; }
                // 밀었을 때 나오는 버튼
                const d = t.closest('.iv-act [data-del]');
                if (d) { del(d.closest('.ivw')); return; }
                if (t.closest('.iv-act')) return;
                // 줄 누르기 → 수정 화면 (밀려 있는 줄이 있으면 먼저 닫기만)
                const r = t.closest('.ivr');
                if (!r) { closeRow(); return; }
                if (openRow) { e.preventDefault(); closeRow(); return; }
                if (t.closest('a')) return; // 이름 링크는 그대로 (새 탭 열기 등)
                location.href = r.closest('.ivw').dataset.edit;
            });
            window.addEventListener('scroll', closeMenu, { passive: true });
            window.addEventListener('resize', closeAll);

            // 손가락으로 밀기
            let drag = null;
            document.addEventListener('touchstart', e => {
                if (!mobile.matches) return;
                const r = e.target.closest('.ivr'); if (!r) return;
                const row = r.closest('.ivw');
                if (openRow && openRow !== row) closeRow();
                const t = e.touches[0];
                drag = { row, x0: t.clientX, y0: t.clientY, base: openRow === row ? -SLIDE : 0, dir: null, x: 0 };
            }, { passive: true });
            document.addEventListener('touchmove', e => {
                if (!drag) return;
                const t = e.touches[0], dx = t.clientX - drag.x0, dy = t.clientY - drag.y0;
                if (!drag.dir && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) {
                    drag.dir = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
                    if (drag.dir === 'x') drag.row.classList.add('dragging');
                }
                if (drag.dir !== 'x') return;
                e.preventDefault();
                drag.x = Math.max(-SLIDE - 24, Math.min(0, drag.base + dx));
                setX(drag.row, drag.x);
            }, { passive: false });
            document.addEventListener('touchend', () => {
                if (!drag) return;
                const d = drag; drag = null;
                d.row.classList.remove('dragging');
                if (d.dir !== 'x') return;
                if (d.x < -SLIDE / 2) { setX(d.row, -SLIDE); openRow = d.row; }
                else { setX(d.row, 0); if (openRow === d.row) openRow = null; }
                // 밀기 직후 따라오는 click(수정 화면 이동)은 막기
                const stop = ev => { ev.stopPropagation(); ev.preventDefault(); };
                document.addEventListener('click', stop, { capture: true, once: true });
                setTimeout(() => document.removeEventListener('click', stop, { capture: true }), 350);
            });

            const m = location.hash.match(/^#tab-(cust|vip|test)$/);
            show(m ? m[1] : 'cust');
            // 방금 삭제한 줄이 있던 묶음은 펼쳐 두기 (?open=고객코드)
            const op = new URLSearchParams(location.search).get('open');
            if (op) document.querySelectorAll('.iv-grp').forEach(g => { if (g.querySelector('.iv-ghead b').textContent.trim() === '고객코드 ' + op) g.classList.add('open'); });
        })();
        </script>
    </div>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
