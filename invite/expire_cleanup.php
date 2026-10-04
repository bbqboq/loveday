<?php
/**
 * expire_cleanup.php - 기간이 끝난 청첩장 정리 (크론 전용, 1시간마다)
 *
 *   crontab -e 에 한 줄:
 *   17 * * * * php /var/www/loveday/www/invite/expire_cleanup.php >> /var/log/loveday_cleanup.log 2>&1
 *
 *  - 둘러보기(비회원) 체험 청첩장: 보관 시간이 끝나면 바로 완전 삭제 (휴지통 안 거침)
 *  - 네이버 로그인 회원의 무료체험: 기간이 끝나고 유예 시간(기본 24시간)까지 지나면 휴지통으로 이동 (관리자가 복원 가능)
 *    (유예 중에 결제하면 기간이 늘어나 옮겨지지 않음)
 *  - 휴지통: 들어간 지 보관 기간(기본 30일)이 지나면 사진·하객 기록까지 완전 삭제
 *    (고객이 직접 지운 것, 기간이 끝나 옮겨진 것 모두)
 *  - 결제한 청첩장(1년·영구)과 관리자 샘플은 건드리지 않는다
 *  유예 시간·휴지통 보관 기간은 관리자 → 부가기능에서 바꾼다.
 * 기간이 끝났거나 휴지통에 있는 청첩장은 짧은 주소(loveday.kr/코드)로 열리지 않는다 (invite_view.php).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/guest_functions.php';

$lock = fopen(sys_get_temp_dir() . '/loveday_expire_cleanup.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { echo date('Y-m-d H:i:s') . " 이미 실행 중\n"; exit; }

$pdo = get_pdo();
$demo   = demo_cleanup($pdo, 1000);
$member = member_trial_cleanup($pdo, 1000);
$trash  = trash_cleanup($pdo, 1000);
if ($demo || $member || $trash) {
    echo date('Y-m-d H:i:s') . " 정리: 둘러보기 완전 삭제 {$demo}건, 회원 무료체험 휴지통 이동 {$member}건 (유예 " . trial_grace_hours() . "시간), "
       . "휴지통 완전 삭제 {$trash}건 (" . trash_keep_days() . "일 지난 것)\n";
}
