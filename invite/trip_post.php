<?php
/**
 * trip_post.php - 신혼여행 라이브: 소식 올리기 / 지우기 (신랑신부만 - trip_manage.php에서 호출)
 *
 * POST t=편집토큰 & csrf_token & action=add   : photos[](0~6장) + caption + place + lat + lng
 *                                  action=delete : id
 * 사진은 게스트스냅과 같은 방식으로 다시 저장한다 (webp, 1920px, 사진 속 위치정보(EXIF)는 지워짐).
 * 좌표는 도시 수준(소수점 한 자리)으로 줄여서 저장. 섹션의 "공개 늦추기" 설정만큼 visible_at을 뒤로 둔다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

header('Content-Type: application/json; charset=utf-8');
function trip_fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') trip_fail(405, '잘못된 요청입니다.');
if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) trip_fail(403, '페이지를 새로고침한 뒤 다시 시도해주세요.');
$pdo = get_pdo();
$invite = snap_owner_invite($pdo, (string) ($_POST['t'] ?? ''));
if (!$invite) trip_fail(403, '편집 링크가 올바르지 않아요. 내 청첩장에서 다시 들어와주세요.');
$id = (int) $invite['id'];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'delete') {
    $st = $pdo->prepare('SELECT photos FROM trip_posts WHERE id = ? AND invitation_id = ?');
    $st->execute([(int) ($_POST['id'] ?? 0), $id]);
    $row = $st->fetch();
    if (!$row) trip_fail(404, '이미 지워진 소식이에요.');
    foreach ((array) json_decode((string) $row['photos'], true) as $p) {
        if (is_array($p) && preg_match('/^[a-f0-9]{32}\.webp$/', (string) ($p['f'] ?? ''))) @unlink(trip_dir($id) . $p['f']);
    }
    $pdo->prepare('DELETE FROM trip_posts WHERE id = ? AND invitation_id = ?')->execute([(int) $_POST['id'], $id]);
    echo json_encode(['ok' => true]);
    exit;
}
if ($action !== 'add') trip_fail(400, '잘못된 요청입니다.');

if (!check_rate_limit($pdo, 'trip_' . $id, 60, 3600)) trip_fail(429, '짧은 시간에 너무 많이 올렸어요. 잠시 후 다시 시도해주세요.');
$cnt = $pdo->prepare('SELECT COUNT(*) FROM trip_posts WHERE invitation_id = ?'); $cnt->execute([$id]);
if ((int) $cnt->fetchColumn() >= TRIP_MAX_POSTS) trip_fail(413, '소식은 ' . TRIP_MAX_POSTS . '개까지 올릴 수 있어요. 예전 소식을 지운 뒤 올려주세요.');

$caption = mb_substr(trim(strip_tags((string) ($_POST['caption'] ?? ''))), 0, 300);
$place = mb_substr(trim(strip_tags((string) ($_POST['place'] ?? ''))), 0, 80);
$lat = is_numeric($_POST['lat'] ?? null) ? round((float) $_POST['lat'], 1) : null;
$lng = is_numeric($_POST['lng'] ?? null) ? round((float) $_POST['lng'], 1) : null;
if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) { $lat = $lng = null; }

// 사진 (여러 장 - photos[])
$files = [];
if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
    foreach ($_FILES['photos']['name'] as $i => $_) {
        if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $files[] = ['name' => $_FILES['photos']['name'][$i], 'type' => $_FILES['photos']['type'][$i], 'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error' => $_FILES['photos']['error'][$i], 'size' => $_FILES['photos']['size'][$i]];
    }
}
if (count($files) > TRIP_MAX_PHOTOS) trip_fail(400, '사진은 한 번에 ' . TRIP_MAX_PHOTOS . '장까지 올릴 수 있어요.');
if (!$files && $caption === '' && $place === '') trip_fail(400, '사진, 한마디, 장소 중 하나는 넣어주세요.');

$saved = [];
try {
    foreach ($files as $f) {
        [$name, , $w, $h] = snap_store_image($f, $id, trip_dir($id));
        $saved[] = ['f' => $name, 'w' => $w, 'h' => $h];
    }
} catch (RuntimeException $e) {
    foreach ($saved as $p) @unlink(trip_dir($id) . $p['f']);
    trip_fail(400, $e->getMessage());
}

$blk = trip_block($invite);
$delay = $blk ? $blk['delay'] : 0;
$pdo->prepare('INSERT INTO trip_posts (invitation_id, photos, caption, place, lat, lng, created_at, visible_at)
               VALUES (?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? HOUR))')
    ->execute([$id, $saved ? json_encode($saved) : null, $caption !== '' ? $caption : null, $place !== '' ? $place : null, $lat, $lng, $delay]);
$st = $pdo->prepare('SELECT * FROM trip_posts WHERE id = ?'); $st->execute([(int) $pdo->lastInsertId()]);
echo json_encode(['ok' => true, 'post' => trip_post_out($st->fetch(), $id, true), 'delay' => $delay], JSON_UNESCAPED_UNICODE);
