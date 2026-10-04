<?php
/**
 * trip_feed.php - 신혼여행 라이브 소식 목록 (청첩장의 "신혼여행 라이브" 섹션이 불러감)
 *   ?s=청첩장코드   하객용: 발행된 청첩장 + 섹션이 켜져 있을 때, 공개 시각이 지난 소식만
 *   ?t=편집토큰     신랑신부 미리보기: 공개 전 소식까지 전부
 * 응답: { ok, started(보여줄 때가 됐는지), posts: [오래된 순] }
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$pdo = get_pdo();
$owner = false;
if (($t = (string) ($_GET['t'] ?? '')) !== '') {
    $invite = snap_owner_invite($pdo, $t);
    $owner = (bool) $invite;
} else {
    $s = (string) ($_GET['s'] ?? '');
    $invite = guest_invite_by_slug($pdo, $s); // 발행 + 기간 안 지난 청첩장만
}
$blk = $invite ? trip_block($invite) : null;
if (!$invite || !$blk || (!$blk['enabled'] && !$owner)) { http_response_code(404); echo '{"ok":false}'; exit; }

$id = (int) $invite['id'];
try {
    $st = $pdo->prepare('SELECT * FROM trip_posts WHERE invitation_id = ?' . ($owner ? '' : ' AND visible_at <= NOW()') . ' ORDER BY created_at ASC, id ASC LIMIT ' . TRIP_MAX_POSTS);
    $st->execute([$id]);
    $posts = array_map(fn($r) => trip_post_out($r, $id, $owner), $st->fetchAll());
} catch (Throwable $e) {
    $posts = []; // stage5_setup.sql 실행 전
}
// 시작 날짜를 정했으면 그날(한국 시간)부터, 안 정했으면 첫 소식이 보이는 순간부터 하객에게 섹션이 보인다
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')))->format('Y-m-d');
$started = $blk['startDate'] !== '' ? $today >= $blk['startDate'] : (bool) $posts;
echo json_encode(['ok' => true, 'started' => $started || $owner, 'posts' => $posts], JSON_UNESCAPED_UNICODE);
