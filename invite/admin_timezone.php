<?php
/**
 * admin_timezone.php - 관리자: 서버 시간 점검 · 예전 기록 시각 한 번 옮기기
 *
 * 문제: PHP는 한국 시간인데 DB는 서버 기본 시간(UTC 등)이면, DB가 NOW()로 저장한 시각을 PHP가 9시간 틀리게 읽는다.
 *       (관리자 "남은 시간"이 바로 "지남", 대시보드 삭제 시계·신혼여행 소식 시각이 9시간 어긋남)
 * 해결: config.php가 이제 DB 연결마다 한국 시간(+09:00)을 쓰게 한다. 다만 그 전에 저장된 시각은 예전 기준(UTC)이라
 *       이 화면에서 "한 번만" 한국 시간으로 옮긴다. 두 번 누르지 못하게 기록해 둔다.
 * 옮기는 칸: DB의 NOW()로 저장되던 시각들만 (예식일시·결제일처럼 사람이 적거나 PHP가 한국 시간으로 적던 칸은 그대로)
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// 옮길 칸 [표, 칸, 조건]
const TZ_COLUMNS = [
    ['invitation_orders', 'created_at', ''], ['invitation_orders', 'deleted_at', ''], ['invitation_orders', 'expires_at', "storage_plan = 'trial'"],
    ['customers', 'over_limit_since', ''], ['customer_stickers', 'created_at', ''],
    ['guest_snaps', 'created_at', ''], ['guest_snap_downloads', 'downloaded_at', ''],
    ['rsvp_responses', 'created_at', ''], ['rsvp_responses', 'updated_at', ''], ['guestbook_entries', 'created_at', ''],
    ['music_uploads', 'created_at', ''], ['music_uploads', 'agreed_at', ''], ['music_uploads', 'removed_at', ''],
    ['naver_orders', 'created_at', ''], ['naver_orders', 'updated_at', ''],
    ['trip_posts', 'created_at', ''], ['trip_posts', 'visible_at', ''],
    ['lottery_entries', 'created_at', ''], ['lottery_entries', 'won_at', ''], ['lottery_state', 'updated_at', ''],
    ['rate_limits', 'window_start', ''], ['app_settings', 'updated_at', ''],
];

// DB 서버 기본 시간대가 UTC보다 몇 분 앞서는지 (이 연결은 +09:00으로 바꿨으니 서버 설정값으로 따로 계산)
$serverTz = (string) $pdo->query('SELECT @@global.time_zone')->fetchColumn();
$sysTz = (string) $pdo->query('SELECT @@system_time_zone')->fetchColumn();
$offset = $pdo->query("SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', @@global.time_zone))")->fetchColumn();
$offset = $offset === null ? null : (int) $offset;
$diff = $offset === null ? null : 540 - $offset; // 한국 시간(+540분)으로 맞추려면 더할 분
$done = app_setting('tz_migrated');
$sessionNow = (string) $pdo->query('SELECT NOW()')->fetchColumn();
$phpNow = date('Y-m-d H:i:s');
$ok = abs(strtotime($sessionNow) - time()) < 5; // 지금 PHP와 DB가 같은 시각을 가리키는지

// 칸이 실제로 있는지 + 값이 든 행 수
$cols = [];
foreach (TZ_COLUMNS as [$t, $c, $where]) {
    try {
        $exists = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t) . " AND COLUMN_NAME = " . $pdo->quote($c))->fetchColumn();
        if ($exists !== 'datetime') continue; // TIMESTAMP는 DB가 알아서 바꿔 주므로 옮기지 않음
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$c` IS NOT NULL" . ($where ? " AND $where" : ''))->fetchColumn();
        $cols[] = [$t, $c, $where, $n];
    } catch (Throwable $e) {}
}

$notice = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'migrate') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($done !== '') $error = '이미 한 번 옮겼어요 (' . $done . '). 두 번 옮기면 다시 어긋나요.';
    elseif (!$diff) $error = 'DB가 이미 한국 시간이라 옮길 필요가 없어요.';
    else {
        $pdo->beginTransaction();
        try {
            $total = 0;
            foreach ($cols as [$t, $c, $where]) {
                $total += $pdo->exec("UPDATE `$t` SET `$c` = DATE_ADD(`$c`, INTERVAL $diff MINUTE) WHERE `$c` IS NOT NULL" . ($where ? " AND $where" : ''));
            }
            save_app_setting($pdo, 'tz_migrated', date('Y-m-d H:i') . ' · ' . ($diff / 60) . '시간');
            $pdo->commit();
            header('Location: admin_timezone.php?moved=' . $total);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = '옮기지 못했어요: ' . $e->getMessage();
        }
    }
}
if (isset($_GET['moved'])) $notice = (int) $_GET['moved'] . '개 기록의 시각을 한국 시간으로 옮겼어요.';
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>서버 시간 점검 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.tz-grid { display: grid; grid-template-columns: 180px 1fr; gap: 8px 14px; font-size: 14px; }
.tz-grid b { font-weight: 600; }
.ok { color: #2F7D55; font-weight: 700; } .bad { color: #B24A4A; font-weight: 700; }
.cols { font-size: 12.5px; color: #666; line-height: 1.9; columns: 2; margin: 10px 0 0; padding-left: 18px; }
@media (max-width: 600px) { .tz-grid { grid-template-columns: 1fr; } .cols { columns: 1; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
<?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('extra', '서버 시간 점검'); ?>
<div class="wrap">
    <h2 class="page-title">서버 시간 점검</h2>
    <?php if ($notice): ?><div class="notice success"><?= $h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notice error"><?= $h($error) ?></div><?php endif; ?>

    <div class="panel">
        <div class="tz-grid">
            <span>지금 (PHP)</span><b><?= $h($phpNow) ?> · <?= $h(date_default_timezone_get()) ?></b>
            <span>지금 (DB 연결)</span><b><?= $h($sessionNow) ?></b>
            <span>둘이 같은가요?</span><b class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓ 같아요 - 새로 저장되는 시각은 정확해요' : '✕ 달라요 - config.php를 새 파일로 바꿔주세요' ?></b>
            <span>DB 서버 기본 시간대</span><b><?= $h($serverTz) ?><?= $serverTz === 'SYSTEM' ? ' (' . $h($sysTz) . ')' : '' ?> · UTC<?= $offset === null ? '?' : sprintf('%+d시간', intdiv($offset, 60)) ?></b>
        </div>
    </div>

    <h2 class="page-title" style="margin-top:26px;">예전 기록 시각 옮기기 (한 번만)</h2>
    <div class="panel">
        <?php if ($done !== ''): ?>
            <p class="ok">이미 옮겼어요 · <?= $h($done) ?></p>
        <?php elseif ($diff === 0): ?>
            <p class="ok">DB 서버가 원래 한국 시간이라 예전 기록도 맞아요. 옮길 필요 없어요.</p>
        <?php elseif ($diff === null): ?>
            <p class="bad">DB 시간대를 확인하지 못했어요 (시간대 표가 없는 서버). 고객센터 개발 담당에게 확인해 주세요.</p>
        <?php else: ?>
            <p style="font-size:14px;line-height:1.8;margin:0 0 10px;">
                DB 서버 기본 시간이 한국 시간보다 <b><?= abs($diff / 60) ?>시간 <?= $diff > 0 ? '느려서' : '빨라서' ?></b>, 그동안 저장된 시각이 어긋나 있어요.<br>
                아래 칸의 시각에 <b><?= sprintf('%+g', $diff / 60) ?>시간</b>을 한 번 더하면 대시보드 삭제 시계·관리자 남은 시간·신혼여행 소식 시각이 맞게 보여요.<br>
                <small style="color:#888;">config.php를 새 파일로 바꾼 <b>직후</b>에 한 번만 눌러주세요. 예식일시·결제일처럼 사람이 적은 시각은 그대로 둬요.</small>
            </p>
            <ul class="cols"><?php foreach ($cols as [$t, $c, $w, $n]): ?><li><?= $h("$t.$c") ?><?= $w ? ' (무료체험만)' : '' ?> · <?= $n ?>개</li><?php endforeach; ?></ul>
            <form method="post" style="margin-top:14px;" onsubmit="return confirm('예전 기록 시각을 <?= sprintf('%+g', $diff / 60) ?>시간 옮길까요? 한 번만 할 수 있어요.');">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <button class="btn" type="submit" name="action" value="migrate" <?= $ok ? '' : 'disabled' ?>>예전 기록 시각 옮기기</button>
                <?php if (!$ok): ?><span style="font-size:12.5px;color:#B24A4A;margin-left:8px;">먼저 config.php를 바꿔서 위가 "같아요"가 돼야 해요</span><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
