<?php
/**
 * snap_cleanup.php - LOVE DAY 매일 정리 작업 (크론)
 *   1) 보관 기간(기본: 예식일 + 15일)이 지난 게스트스냅 사진 삭제
 *   2) 예식일 + rsvp_retention_days(기본 90일)가 지난 참석여부 명단(이름·연락처) 삭제
 *   3) 삭제된 청첩장에 남은 참석여부·방명록 기록 삭제
 *   4) 만료된 "회원가입 없이 체험" 청첩장 통째로 영구 삭제 (체험하기 버튼을 누를 때도 조금씩 지워진다)
 *
 * 서버 크론에 하루 한 번 등록 (터미널에서 `crontab -e` 후 아래 한 줄 추가):
 *   10 4 * * * php /var/www/loveday/www/invite/snap_cleanup.php >> /var/log/loveday_snap_cleanup.log 2>&1
 *
 * 브라우저로는 실행되지 않는다(명령줄 전용). 크론을 안 걸어도 신랑신부 관리 페이지를 열면
 * 그 청첩장은 기간 지난 사진이 바로 지워지지만, 서버 용량을 위해 크론 등록을 권장.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('명령줄 전용'); }

// config.php가 세션을 시작하는데, 명령줄에서는 필요 없으니 경고만 막는다
$_SERVER['HTTPS'] = 'on';
require_once __DIR__ . '/guest_functions.php';

$pdo = get_pdo();
$ids = $pdo->query('SELECT DISTINCT invitation_id FROM guest_snaps')->fetchAll(PDO::FETCH_COLUMN);
$removedInv = 0; $removedFiles = 0;
foreach ($ids as $id) {
    $st = $pdo->prepare('SELECT * FROM invitation_orders WHERE id = ?');
    $st->execute([(int) $id]);
    $invite = $st->fetch();
    // 청첩장이 삭제됐거나(영구삭제 포함), 보관 기간이 지났으면 전부 삭제
    if (!$invite || !empty($invite['deleted_at']) || snap_schedule($invite)['state'] === 'expired') {
        $removedFiles += snap_delete_all($pdo, (int) $id);
        $removedInv++;
    }
}
echo date('Y-m-d H:i:s') . " 게스트스냅 정리: 청첩장 {$removedInv}건, 사진 파일 {$removedFiles}개 삭제\n";

// ---- 참석여부 명단 / 방명록 ----
try {
    $rsvpDel = 0; $orphan = 0;
    $ids = $pdo->query('SELECT DISTINCT invitation_id FROM rsvp_responses UNION SELECT DISTINCT invitation_id FROM guestbook_entries')->fetchAll(PDO::FETCH_COLUMN);
    $keep = app_setting_int('rsvp_retention_days');
    foreach ($ids as $id) {
        $st = $pdo->prepare('SELECT * FROM invitation_orders WHERE id = ?');
        $st->execute([(int) $id]);
        $invite = $st->fetch();
        if (!$invite) { // 청첩장이 영구삭제됨 → 남은 기록 전부
            foreach (['rsvp_responses', 'guestbook_entries'] as $t) { $d = $pdo->prepare("DELETE FROM {$t} WHERE invitation_id = ?"); $d->execute([(int) $id]); $orphan += $d->rowCount(); }
            continue;
        }
        $w = snap_wedding_date($invite);
        if ($w && $w->modify('+' . $keep . ' days') < new DateTimeImmutable()) {
            $d = $pdo->prepare('DELETE FROM rsvp_responses WHERE invitation_id = ?'); $d->execute([(int) $id]); $rsvpDel += $d->rowCount();
        }
    }
    echo date('Y-m-d H:i:s') . " 참석여부 명단 {$rsvpDel}건(보관기간 지남), 삭제된 청첩장의 기록 {$orphan}건 삭제\n";
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " 참석여부·방명록 정리 건너뜀 (stage4_setup.sql 실행 전?): " . $e->getMessage() . "\n";
}

// ---- 만료된 체험 청첩장 ----
try {
    echo date('Y-m-d H:i:s') . ' 만료된 체험 청첩장 ' . demo_cleanup($pdo, 5000) . "건 영구 삭제\n";
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' 체험 청첩장 정리 오류: ' . $e->getMessage() . "\n";
}
