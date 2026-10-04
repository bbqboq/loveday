<?php
/**
 * snap_upload.php - 게스트스냅 사진 1장 업로드 (snap.php에서 호출)
 * 확인 순서: CSRF → 청첩장/게스트스냅 켜짐 → 업로드 기간 → 요청 횟수 제한 → 용량 한도(청첩장 전체 / 하객 1인) → 저장
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

header('Content-Type: application/json; charset=utf-8');
function snap_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') snap_fail(405, '잘못된 요청입니다.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) snap_fail(403, '페이지를 새로고침 후 다시 시도해주세요.');

$pdo = get_pdo();
$slug = (string) ($_POST['s'] ?? '');
$invite = preg_match('/^[a-z0-9]{4,20}$/', $slug) ? find_invitation_by_slug($pdo, $slug) : null;
if ($invite && !invite_is_live($pdo, $invite)) $invite = null; // 기간 만료된 청첩장
if (!$invite || !snap_enabled($invite)) snap_fail(404, '게스트스냅을 사용하지 않는 청첩장입니다.');
if (snap_schedule($invite)['state'] !== 'open') snap_fail(403, '업로드 기간이 아닙니다.');
if (!check_rate_limit($pdo, 'snap_' . client_ip(), 150, 600)) snap_fail(429, '잠시 후 다시 시도해주세요.');
if (empty($_FILES['photo'])) snap_fail(400, '사진이 없습니다.');

$invitationId = (int) $invite['id'];
$guestKey = snap_guest_key();
$guestName = mb_substr(trim(strip_tags((string) ($_POST['guest_name'] ?? ''))), 0, 20);
$incoming = (int) ($_FILES['photo']['size'] ?? 0);

// 같은 청첩장에 동시에 여러 장이 들어와도 한도를 넘지 않도록 청첩장 단위로 잠근다
$lockName = 'snap_' . $invitationId;
$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockName) . ", 10)");
try {
    $u = snap_usage($pdo, $invitationId, $guestKey);
    // 저장 전에는 서버에서 줄인 뒤 크기를 모르므로, 올라온 크기로 먼저 막고 저장 후 실제 크기로 기록한다
    if ($u['total'] + min($incoming, 1048576) > $u['quota_invite']) snap_fail(413, '이 청첩장의 사진 업로드 한도(' . snap_fmt_mb($u['quota_invite']) . ')가 가득 찼어요.');
    if ($u['guest'] + min($incoming, 1048576) > $u['quota_guest']) snap_fail(413, '한 분이 올릴 수 있는 용량(' . snap_fmt_mb($u['quota_guest']) . ')을 다 쓰셨어요.');

    try {
        [$name, $bytes, $w, $h] = snap_store_image($_FILES['photo'], $invitationId);
    } catch (RuntimeException $e) {
        snap_fail(400, $e->getMessage());
    }
    if ($u['total'] + $bytes > $u['quota_invite'] || $u['guest'] + $bytes > $u['quota_guest']) {
        @unlink(snap_dir($invitationId) . $name);
        snap_fail(413, '업로드 한도를 넘어서 이 사진은 올릴 수 없어요.');
    }
    $pdo->prepare('INSERT INTO guest_snaps (invitation_id, guest_key, guest_name, file_name, bytes, width, height) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$invitationId, $guestKey, $guestName !== '' ? $guestName : null, $name, $bytes, $w, $h]);
    $snapId = (int) $pdo->lastInsertId();
} finally {
    $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockName) . ")");
}

echo json_encode(['ok' => true, 'guest_used' => $u['guest'] + $bytes, 'guest_quota' => $u['quota_guest']], JSON_UNESCAPED_UNICODE);

// 신랑신부가 구글 드라이브를 연결해 두었으면 방금 올라온 사진을 드라이브 폴더로도 보낸다.
// 하객은 기다리지 않게 응답을 먼저 보내고 나서 처리한다. 실패해도 서버 사본은 그대로 남고, 관리 화면에서 "안 간 사진 보내기"로 다시 보낼 수 있다.
require_once __DIR__ . '/gdrive.php';
if (gdrive_on() && gdrive_link($pdo, $invitationId)) {
    session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush(); flush(); }
    ignore_user_abort(true);
    @set_time_limit(120);
    try { gdrive_push_snaps($pdo, $invite, $snapId, 1); }
    catch (Throwable $e) { error_log('gdrive push failed inv=' . $invitationId . ': ' . $e->getMessage()); }
}
