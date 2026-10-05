<?php
/**
 * admin_trash.php - 휴지통
 *  들어오는 것
 *   - 고객이 대시보드에서 직접 지운 청첩장 / 관리자가 목록에서 지운 고객·VIP 청첩장
 *   - 네이버 회원 무료체험: 기간이 끝나고 유예 시간(기본 24시간)까지 지나 정리 작업이 옮긴 것
 *   (비회원 둘러보기 청첩장은 휴지통 없이 바로 완전 삭제)
 *  나가는 것
 *   - 복원 / 영구삭제 (이 화면)
 *   - 들어온 지 보관 기간(기본 30일, 관리자 → 부가기능)이 지나면 정리 작업(expire_cleanup.php)이 사진까지 완전 삭제
 *
 *  화면: 청첩장 목록(admin_create.php)과 같은 모양
 *   - PC: 표 + 체크박스(선택하면 위에 검은 막대) + 줄마다 ⋯ 메뉴
 *   - 모바일: 선택 모드 (줄을 누르면 체크 → 화면 아래 막대에서 복원·영구삭제)
 *   - 부관리자: 복원(trash_restore) / 영구삭제·비우기(trash_purge) 권한이 없으면 버튼이 안 보임 (서버에서도 막음)
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/admin_search.php';

$pdo = get_pdo();

/** 복원 - 기간이 이미 끝난 무료체험은 그대로 두면 다음 정리 때 다시 휴지통으로 가니까 지금부터 24시간 연장. 반환: 연장했으면 true */
function trash_restore(PDO $pdo, int $id): bool
{
    restore_invitation($pdo, $id);
    $st = $pdo->prepare("UPDATE invitation_orders SET expires_at = DATE_ADD(NOW(), INTERVAL 1 DAY)
                         WHERE id = ? AND storage_plan = 'trial' AND expires_at IS NOT NULL AND expires_at < DATE_ADD(NOW(), INTERVAL 1 HOUR)");
    $st->execute([$id]);
    return $st->rowCount() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    // 휴지통에 있는 것만 대상으로 (다른 화면의 청첩장을 잘못 지우지 않게)
    $inTrash = function (array $ids) use ($pdo): array {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        $st = $pdo->prepare('SELECT id FROM invitation_orders WHERE deleted_at IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    };
    $act = (string) ($_POST['act'] ?? '');
    $ids = $act === 'empty'
        ? array_map('intval', $pdo->query('SELECT id FROM invitation_orders WHERE deleted_at IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN))
        : $inTrash((array) ($_POST['ids'] ?? []));
    $n = 0; $ext = 0;
    if ($act === 'restore') {
        foreach ($ids as $id) { $ext += trash_restore($pdo, $id) ? 1 : 0; $n++; }
    } elseif ($act === 'delete' || $act === 'empty') {
        foreach ($ids as $id) { invitation_purge($pdo, $id); $n++; }
    }
    $qBack = trim((string) ($_POST['q'] ?? ''));
    header('Location: admin_trash.php?done=' . rawurlencode($act) . '&n=' . $n . '&ext=' . $ext . ($qBack !== '' && $act !== 'empty' ? '&q=' . rawurlencode($qBack) : '') . (isset($_POST['f']) ? '#f-' . preg_replace('/[^a-z]/', '', (string) $_POST['f']) : ''));
    exit;
}

$notice = '';
if (isset($_GET['done'])) {
    $n = (int) ($_GET['n'] ?? 0); $ext = (int) ($_GET['ext'] ?? 0);
    $notice = match ((string) $_GET['done']) {
        'restore' => $n . '건 복원했어요.' . ($ext ? ' 무료체험 기간이 끝난 ' . $ext . '건은 다시 휴지통으로 가지 않도록 지금부터 24시간 늘렸어요. 필요하면 청첩장 수정에서 기간을 바꿔주세요.' : ''),
        'delete'  => $n . '건 영구 삭제했어요.',
        'empty'   => '휴지통을 비웠어요 (' . $n . '건 영구 삭제).',
        default   => '',
    };
}

$keepDays = trash_keep_days();
$trash = $pdo->query("
    SELECT o.*, c.customer_code,
           DATE_ADD(o.deleted_at, INTERVAL {$keepDays} DAY) AS purge_at,
           GREATEST(0, CEIL(TIMESTAMPDIFF(MINUTE, NOW(), DATE_ADD(o.deleted_at, INTERVAL {$keepDays} DAY)) / 1440)) AS days_left
    FROM invitation_orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    WHERE o.deleted_at IS NOT NULL
    ORDER BY o.deleted_at DESC
")->fetchAll();

// 검색 (코드 · 고객코드 · 신랑신부 이름 · 전화번호 · 구매자 이름) - admin_search.php
$q = trim((string) ($_GET['q'] ?? ''));
$search = null;
$trashTotal = count($trash);
if ($q !== '') {
    $search = admin_search_invitations($pdo, $q, true);
    $trash = array_values(array_filter($trash, fn($r) => isset($search['ids'][(int) $r['id']])));
    foreach ($trash as &$r) $r['_why'] = $search['ids'][(int) $r['id']];
    unset($r);
}

$reasonLabel = ['customer' => '고객 삭제', 'expired' => '기간 만료'];
$planLabel = ['trial' => '무료체험', 'one_year' => '1년', 'permanent' => '영구'];
$cnt = ['customer' => 0, 'expired' => 0];
foreach ($trash as &$row) { $row['_reason'] = trash_reason($row); $cnt[$row['_reason']]++; }
unset($row);
$csrf = csrf_token();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>휴지통 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .tr-head { display: flex; align-items: center; gap: 10px; margin: 0 0 6px; }
    .tr-head .page-title { margin: 0; }
    .tr-head .cnt { font-size: 13px; color: var(--muted); }
    .tr-note { font-size: 12.5px; color: var(--muted); margin: 0 0 14px; line-height: 1.7; }
    .tr-search { display: flex; gap: 8px; margin: 0 0 12px; }
    .tr-search .box { position: relative; flex: 1; min-width: 0; }
    .tr-search input { width: 100%; box-sizing: border-box; padding: 11px 36px 11px 38px; border: 1px solid var(--ui-line, #E3DED6); border-radius: 12px; font: inherit; font-size: 14px; background: #fff; }
    .tr-search input:focus { outline: none; border-color: #2B2B2B; box-shadow: 0 0 0 3px rgba(43, 43, 43, .08); }
    .tr-search .ic { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; fill: none; stroke: #A29C94; stroke-width: 2; pointer-events: none; }
    .tr-search .x { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 50%; background: var(--ui-line, #EEEAE4); color: #6F6A63; display: grid; place-items: center; text-decoration: none; font-size: 12px; }
    .tr-search button { flex: none; border: 0; border-radius: 12px; background: #2B2B2B; color: #fff; font: inherit; font-size: 14px; font-weight: 600; padding: 0 18px; cursor: pointer; }
    .tr-found { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: -2px 0 12px; font-size: 13px; color: #6F6A63; }
    .tr-found b { color: #2B2B2B; }
    .tr-found a { color: #4A5A73; }
    .tr-found .warn { width: 100%; font-size: 12px; color: #8A5A00; }
    .tv-why { display: inline-block; margin-left: 6px; font-size: 10.5px; font-weight: 600; padding: 1px 6px; border-radius: 5px; background: #EAF3FF; color: #3A64A8; vertical-align: 1px; }
    .tr-filters { display: flex; gap: 6px; margin: 0 0 12px; flex-wrap: wrap; align-items: center; }
    .tr-filters button { border: 1px solid #ddd; background: #fff; border-radius: 999px; padding: 7px 14px; font: inherit; font-size: 13px; cursor: pointer; color: #555; }
    .tr-filters button b { margin-left: 3px; }
    .tr-filters button.on { background: #2B2B2B; border-color: #2B2B2B; color: #fff; }
    .tr-filters .empty { margin-left: auto; color: #A8434B; border-color: #E7C9CC; font-size: 12.5px; }
    .tr-filters .empty:hover { background: #FBF1F2; }

    .tvt, .tvt * { box-sizing: border-box; }
    .tvt { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 14px; overflow: hidden; }
    /* 칸이 줄어들 수 있게 (관리자 화면 폭이 좁아도 ⋯가 잘리지 않게) */
    .tv-cols { display: grid; grid-template-columns: 22px 44px minmax(100px, 1.5fr) 78px 66px minmax(80px, 1fr) 68px 82px minmax(104px, .9fr) 40px; align-items: center; column-gap: 10px; }
    .tv-head { padding: 9px 14px; background: #FCFBF9; border-bottom: 1px solid var(--ui-line, #ECE8E2); font-size: 11.5px; font-weight: 600; color: #A29C94; }
    .tv-w { border-bottom: 1px solid var(--ui-soft, #F1EEE9); }
    .tv-w:last-of-type { border-bottom: 0; }
    .tv-w[hidden] { display: none; }
    .tv-r { background: #fff; padding: 8px 14px; min-height: 42px; font-size: 13px; cursor: pointer; transition: background .15s; }
    .tv-r:hover { background: var(--ui-tint, #FBF9F6); }
    .tv-w.sel .tv-r { background: var(--ui-soft, #F7F4EE); }
    .tvt input[type=checkbox] { width: 16px; height: 16px; margin: 0; accent-color: #2B2B2B; cursor: pointer; }
    .tv-no { font-size: 11.5px; color: #A29C94; font-weight: 600; }
    .tv-nm { min-width: 0; }
    .tv-nm b { display: block; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tv-nm b.noname { color: #B3ADA5; font-weight: 500; }
    .tv-nm small { display: none; }
    .tv-rs { display: inline-block; font-size: 11.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
    .tv-rs.customer { background: #EEF2F7; color: #5B6B82; } .tv-rs.expired { background: var(--ui-soft, #FBF0E6); color: #A2561C; }
    .tv-plan { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 5px; white-space: nowrap; }
    .tv-plan.trial { background: #FFF3E0; color: #9A6414; } .tv-plan.one_year { background: #EEF1F6; color: #4A5A73; } .tv-plan.permanent { background: #E9F5EE; color: #2F7A4E; }
    .tv-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: #6F6A63; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tv-dt { font-size: 12px; color: #8F8980; white-space: nowrap; }
    .tv-dd { font-size: 12px; color: #6F6A63; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tv-dd b { font-weight: 700; }
    .tv-dd.soon, .tv-dd.soon b { color: #C0392B; }
    .tv-dd .s, .hd-dd .s { display: none; }
    .tv-mc { display: flex; justify-content: flex-end; }
    .tv-more { width: 32px; height: 30px; border: 0; border-radius: 8px; background: transparent; font-size: 18px; line-height: 1; color: #6F6A63; cursor: pointer; }
    .tv-more:hover, .tv-more.on { background: var(--ui-soft, #F1EDE7); }
    .tv-none { padding: 26px; text-align: center; color: #A29C94; font-size: 13px; }

    /* 선택했을 때 막대 (PC: 표 위 / 모바일: 화면 아래에 떠 있음) */
    .tv-bulk { display: none; align-items: center; gap: 8px; padding: 10px 14px; background: #2B2B2B; color: #fff; font-size: 13px; }
    .tv-bulk.on { display: flex; }
    .tv-bulk b { margin-right: auto; }
    .tv-bulk button { border: 0; border-radius: 9px; padding: 7px 12px; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
    .tv-bulk .r { background: #fff; color: #2B2B2B; } .tv-bulk .x { background: #C0525A; color: #fff; } .tv-bulk .c { background: transparent; color: #CFC9C1; }

    .tv-menu { position: fixed; z-index: 300; min-width: 170px; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 12px; box-shadow: 0 14px 34px rgba(30, 20, 10, .14); padding: 5px; display: none; }
    .tv-menu.on { display: block; }
    .tv-menu a, .tv-menu button { display: flex; align-items: center; gap: 9px; width: 100%; padding: 9px 11px; border: 0; border-radius: 8px; background: none; font: inherit; font-size: 13px; color: #2B2B2B; text-decoration: none; text-align: left; cursor: pointer; }
    .tv-menu a:hover, .tv-menu button:hover { background: var(--ui-soft, #F6F3EE); }
    .tv-menu .del { color: #A8434B; }
    .tv-menu hr { border: 0; border-top: 1px solid var(--ui-soft, #F1EEE9); margin: 4px 2px; }
    .tr-note .mo { display: none; }

    /* 모바일: 늘 선택 모드 (체크 칸 + 아래 막대) */
    @media (max-width: 720px) {
        .tv-cols { grid-template-columns: 20px minmax(0, 1fr) 64px 46px; column-gap: 10px; }
        .tv-cols .tv-pc { display: none; }
        .tv-head, .tv-r { padding-left: 12px; padding-right: 12px; }
        .tv-r { min-height: 50px; }
        .tv-nm small { display: flex; gap: 6px; margin-top: 1px; font-size: 11px; color: #A29C94; white-space: nowrap; overflow: hidden; }
        .tv-nm small .tv-mono { font-size: 11px; }
        .tv-dd, .tv-head .hd-dd { text-align: right; }
        .tv-dd .l, .hd-dd .l { display: none; } .tv-dd .s, .hd-dd .s { display: inline; white-space: nowrap; }
        .tr-note .pc-note { display: none; } .tr-note .mo { display: inline; }
        .tr-filters .empty { padding: 7px 12px; }
        .tv-bulk.top { display: none !important; }
        .tv-bulk.bottom { position: fixed; left: 12px; right: 12px; bottom: max(12px, env(safe-area-inset-bottom)); z-index: 80; border-radius: 16px; box-shadow: 0 10px 24px rgba(0, 0, 0, .22); }
        body.has-bulk { padding-bottom: 84px; }
    }
    @media (min-width: 721px) { .tv-bulk.bottom { display: none !important; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('trash', '휴지통'); ?>
    <?php $canRestore = admin_can('trash_restore'); $canPurge = admin_can('trash_purge'); ?>

    <div class="wrap">
        <div class="tr-head">
            <h2 class="page-title">휴지통</h2><span class="cnt"><?= count($trash) ?>건</span>
        </div>
        <p class="tr-note">
            고객이 지운 청첩장과 기간이 끝나고 <?= trial_grace_hours() ?>시간 유예까지 지난 회원 무료체험이 여기 보관됩니다.
            들어온 지 <b><?= $keepDays ?>일</b>이 지나면 사진까지 자동으로 완전히 삭제됩니다 (<a href="admin_extra_settings.php">부가기능</a>에서 변경).
            <span class="pc-note">줄을 누르면 체크, <b>⋯</b>에서 복원·영구삭제.</span>
            <span class="mo">줄을 눌러 체크한 뒤 아래 막대에서 <b>복원·영구삭제</b>.</span>
            <?php if (!$canRestore || !$canPurge): ?><br>🔒 부관리자 권한: <?= $canRestore ? '복원 가능' : '복원 불가' ?> · <?= $canPurge ? '영구삭제 가능' : '영구삭제 불가' ?><?php endif; ?>
        </p>

        <?php if ($notice): ?><p class="notice success"><?= $h($notice) ?></p><?php endif; ?>

        <?php if ($trashTotal): ?>
        <form class="tr-search" method="get" role="search">
            <div class="box">
                <svg class="ic" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input type="search" name="q" value="<?= $h($q) ?>" placeholder="코드 · 고객코드 · 신랑신부 이름 · 전화번호" autocomplete="off" enterkeyhint="search">
                <?php if ($q !== ''): ?><a class="x" href="admin_trash.php" aria-label="검색 지우기">✕</a><?php endif; ?>
            </div>
            <button type="submit">검색</button>
        </form>
        <?php if ($search): ?>
        <div class="tr-found">
            <span>"<b><?= $h($q) ?></b>" 검색 결과 <b><?= count($trash) ?>건</b> (휴지통 전체 <?= $trashTotal ?>건)</span>
            <a href="admin_trash.php">전체 보기</a>
            <?php if (!$trash): ?><a href="admin_create.php?q=<?= rawurlencode($q) ?>">휴지통 밖에서 찾아보기 →</a><?php endif; ?>
            <?php if ($search['phone_blocked']): ?><span class="warn">🔒 전화번호로 찾기는 개인정보 권한이 필요해요. 코드나 이름으로 찾아주세요.</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($trash): ?>
        <div class="tr-filters" id="trFilters">
            <button type="button" data-f="all" class="on">전체 <b><?= count($trash) ?></b></button>
            <button type="button" data-f="customer">고객 삭제 <b><?= $cnt['customer'] ?></b></button>
            <button type="button" data-f="expired">기간 만료 <b><?= $cnt['expired'] ?></b></button>
            <?php if ($canPurge): ?><button type="button" class="empty" id="trEmpty">휴지통 비우기</button><?php endif; ?>
        </div>

        <?php $bulkBtns = ($canRestore ? '<button type="button" class="r" data-bulk="restore">복원</button>' : '') . ($canPurge ? '<button type="button" class="x" data-bulk="delete">영구삭제</button>' : ''); ?>
        <div class="tvt" id="tvt">
            <div class="tv-bulk top"><b>0건 선택</b><?= $bulkBtns ?><button type="button" class="c" data-bulk="clear">선택 해제</button></div>
            <div class="tv-head tv-cols"><span><input type="checkbox" id="tvAll" aria-label="전체 선택"></span><span class="tv-pc">#</span><span>이름</span><span>사유</span><span class="tv-pc">보관</span><span class="tv-pc">코드</span><span class="tv-pc">고객코드</span><span class="tv-pc">삭제일</span><span class="hd-dd"><span class="l">완전 삭제</span><span class="s">남은 날</span></span><span class="tv-pc"></span></div>
            <?php foreach ($trash as $row):
                $g = trim((string) $row['groom_name']); $b = trim((string) $row['bride_name']);
                $hasName = $g !== '' || $b !== '';
                $name = $hasName ? ($g ?: '-') . ' ♥ ' . ($b ?: '-') : '';
                $left = (int) $row['days_left'];
                $soon = $left <= 3;
            ?>
            <div class="tv-w" data-id="<?= (int) $row['id'] ?>" data-slug="<?= $h($row['view_slug']) ?>" data-reason="<?= $row['_reason'] ?>" data-name="<?= $h($name ?: $row['view_slug']) ?>">
                <div class="tv-r tv-cols">
                    <span><input type="checkbox" class="tv-cb" aria-label="선택"></span>
                    <span class="tv-no tv-pc">#<?= (int) $row['id'] ?></span>
                    <span class="tv-nm"><b<?= $hasName ? '' : ' class="noname"' ?>><?= $hasName ? $h($name) : '이름 미입력' ?><?php if (!empty($row['_why'])): ?><span class="tv-why">찾음: <?= $h(implode('·', $row['_why'])) ?></span><?php endif; ?></b>
                        <small><span class="tv-mono"><?= $h($row['view_slug']) ?></span><span><?= $h($row['customer_code'] ?? 'VIP') ?></span></small></span>
                    <span><span class="tv-rs <?= $row['_reason'] ?>"><?= $reasonLabel[$row['_reason']] ?></span></span>
                    <span class="tv-pc"><span class="tv-plan <?= $h($row['storage_plan']) ?>"><?= $h($planLabel[$row['storage_plan']] ?? $row['storage_plan']) ?></span></span>
                    <span class="tv-mono tv-pc"><?= $h($row['view_slug']) ?></span>
                    <span class="tv-mono tv-pc"><?= $h($row['customer_code'] ?? '-') ?></span>
                    <span class="tv-dt tv-pc"><?= $h(date('m-d H:i', strtotime((string) $row['deleted_at']))) ?></span>
                    <span class="tv-dd<?= $soon ? ' soon' : '' ?>"><span class="l"><b><?= $h(date('m-d', strtotime((string) $row['purge_at']))) ?></b> · <?= $left > 0 ? $left . '일 남음' : '곧 삭제' ?></span><span class="s"><b><?= $left > 0 ? $left . '일' : '곧' ?></b></span></span>
                    <span class="tv-pc tv-mc"><button type="button" class="tv-more" aria-label="더보기">⋯</button></span>
                </div>
            </div>
            <?php endforeach; ?>
            <div class="tv-none" id="tvNone" hidden>이 조건에 맞는 청첩장이 없어요.</div>
        </div>
        <div class="tv-bulk bottom"><b>0건 선택</b><?= $bulkBtns ?><button type="button" class="c" data-bulk="clear">해제</button></div>

        <div class="tv-menu" id="tvMenu" role="menu">
            <?php if ($canRestore): ?><button type="button" data-m="restore">↩︎ 복원</button><?php endif; ?>
            <a data-m="edit" href="#">✏️ 수정 화면에서 보기</a>
            <button type="button" data-m="copy">📋 코드 복사</button>
            <?php if ($canPurge): ?><hr><button type="button" data-m="delete" class="del">🗑 영구삭제</button><?php endif; ?>
        </div>
        <form method="post" id="tvForm" hidden>
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="act" value="">
            <input type="hidden" name="f" value="">
            <input type="hidden" name="q" value="<?= $h($q) ?>">
        </form>
        <?php elseif ($search): ?>
            <div class="panel" style="text-align:center; color:var(--muted);">찾는 청첩장이 휴지통에 없어요.</div>
        <?php else: ?>
            <div class="panel" style="text-align:center; color:var(--muted);">휴지통이 비어있습니다.</div>
        <?php endif; ?>
    </div>

<script src="assets/ld-dialog.js"></script>
<?php if ($trash): ?>
<script>
(function () {
    const tvt = document.getElementById('tvt'), menu = document.getElementById('tvMenu'), form = document.getElementById('tvForm');
    const rows = [...tvt.querySelectorAll('.tv-w')];
    const mobile = window.matchMedia('(max-width: 720px)');
    let filter = 'all', menuRow = null;
    const ask = (msg, opts) => window.LD ? LD.confirm(msg, opts) : Promise.resolve(confirm(msg));

    // ---- 보내기 ----
    function send(act, ids) {
        form.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
        ids.forEach(id => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; form.appendChild(i); });
        form.act.value = act; form.f.value = filter; form.submit();
    }
    async function doAct(act, list) {
        closeMenu();
        if (!list.length) return;
        const one = list.length === 1 ? `"${list[0].dataset.name}"` : `${list.length}건`;
        if (act === 'delete') {
            if (!await ask(`${one}을(를) 영구 삭제할까요?\n사진·게스트스냅·방명록까지 모두 지워지고 되돌릴 수 없어요.`, { ok: '영구삭제', danger: true })) return;
        } else if (list.length > 1) {
            if (!await ask(`${one}을(를) 복원할까요?`, { ok: '복원' })) return;
        }
        send(act, list.map(r => r.dataset.id));
    }

    // ---- 선택 (PC: 줄·체크박스 / 모바일: 줄을 누르면 체크) ----
    const checked = () => rows.filter(r => !r.hidden && r.querySelector('.tv-cb').checked);
    function sync() {
        const n = checked().length;
        rows.forEach(r => r.classList.toggle('sel', r.querySelector('.tv-cb').checked));
        document.querySelectorAll('.tv-bulk').forEach(b => { b.querySelector('b').textContent = n + '건 선택'; b.classList.toggle('on', n > 0); });
        document.body.classList.toggle('has-bulk', n > 0 && mobile.matches);
        const vis = rows.filter(r => !r.hidden);
        const all = document.getElementById('tvAll'); all.checked = vis.length > 0 && n === vis.length; all.indeterminate = n > 0 && n < vis.length;
    }
    function clearSel() { rows.forEach(r => r.querySelector('.tv-cb').checked = false); sync(); }
    document.getElementById('tvAll').addEventListener('change', e => { rows.forEach(r => { if (!r.hidden) r.querySelector('.tv-cb').checked = e.target.checked; }); sync(); });
    tvt.addEventListener('change', e => { if (e.target.classList.contains('tv-cb')) sync(); });
    document.querySelectorAll('[data-bulk]').forEach(b => b.addEventListener('click', () => b.dataset.bulk === 'clear' ? clearSel() : doAct(b.dataset.bulk, checked())));

    // ---- 사유 필터 ----
    function setFilter(f) {
        filter = f;
        document.querySelectorAll('#trFilters [data-f]').forEach(b => b.classList.toggle('on', b.dataset.f === f));
        rows.forEach(r => r.hidden = f !== 'all' && r.dataset.reason !== f);
        document.getElementById('tvNone').hidden = rows.some(r => !r.hidden);
        clearSel(); closeMenu();
        history.replaceState(null, '', f === 'all' ? location.pathname + location.search : '#f-' + f);
    }
    document.querySelectorAll('#trFilters [data-f]').forEach(b => b.addEventListener('click', () => setFilter(b.dataset.f)));
    const emptyBtn = document.getElementById('trEmpty');
    if (emptyBtn) emptyBtn.addEventListener('click', async () => {
        if (!await ask(`휴지통의 <?= $trashTotal ?>건을 모두 영구 삭제할까요?${<?= $search ? "true" : "false" ?> ? "\n(검색 결과만이 아니라 휴지통 전체예요)" : ""}`, { ok: '다음', danger: true })) return;
        if (!await ask('정말 비울까요? 사진까지 모두 지워지고 되돌릴 수 없어요.', { ok: '휴지통 비우기', danger: true })) return;
        send('empty', []);
    });

    // ---- ⋯ 메뉴 (PC) ----
    function closeMenu() { menu.classList.remove('on'); tvt.querySelectorAll('.tv-more.on').forEach(b => b.classList.remove('on')); menuRow = null; }
    function openMenu(btn, row) {
        closeMenu(); menuRow = row; btn.classList.add('on');
        menu.querySelector('[data-m=edit]').href = 'admin_edit.php?id=' + row.dataset.id;
        menu.classList.add('on');
        const r = btn.getBoundingClientRect(), mh = menu.offsetHeight, mw = menu.offsetWidth;
        menu.style.top = Math.max(8, r.bottom + 4 + mh > innerHeight ? r.top - mh - 4 : r.bottom + 4) + 'px';
        menu.style.left = Math.max(8, Math.min(innerWidth - mw - 8, r.right - mw)) + 'px';
    }

    document.addEventListener('click', e => {
        const t = e.target;
        const m = t.closest('#tvMenu [data-m]');
        if (m) {
            const row = menuRow;
            if (m.dataset.m === 'copy') { e.preventDefault(); closeMenu(); window.LD ? LD.copy(row.dataset.slug, '코드를 복사했어요') : navigator.clipboard.writeText(row.dataset.slug); }
            else if (m.dataset.m === 'restore' || m.dataset.m === 'delete') { e.preventDefault(); doAct(m.dataset.m, [row]); }
            else closeMenu();
            return;
        }
        const more = t.closest('.tv-more');
        if (more) { const row = more.closest('.tv-w'); menuRow === row ? closeMenu() : openMenu(more, row); return; }
        if (!t.closest('#tvMenu')) closeMenu();
        const r = t.closest('.tv-r');
        if (!r || t.closest('input')) return; // 체크박스는 그대로
        const cb = r.querySelector('.tv-cb'); cb.checked = !cb.checked; sync(); // 줄 누르면 체크
    });
    window.addEventListener('scroll', closeMenu, { passive: true });
    window.addEventListener('resize', () => { closeMenu(); sync(); });

    const fm = location.hash.match(/^#f-(customer|expired)$/);
    setFilter(fm ? fm[1] : 'all');
})();
</script>
<?php endif; ?>
</body>
</html>
