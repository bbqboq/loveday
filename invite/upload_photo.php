<?php
declare(strict_types=1);
/**
 * upload_photo.php - 에디터 사진 업로드 (게스트스냅 제외 모든 섹션 사진)
 *
 *  에디터가 브라우저에서 미리 WebP로 바꿔서(긴 변 1920px 이하, 약 1.2MB 이하) 보낸다 → 서버는 검사만 하고 그대로 저장.
 *   (예전엔 서버가 매번 GD로 사진을 열어 줄이고 WebP로 두 번 다시 만들어서 CPU·메모리 부담이 컸음)
 *   - 워터마크가 필요 없는 청첩장: 받은 파일을 그대로 master/와 공개용에 저장 (사진을 열지 않음)
 *   - 무료체험(워터마크): 공개용만 열어서 워터마크를 입혀 저장 (master는 받은 그대로)
 *   - 예전 에디터·다른 화면처럼 WebP로 안 바꿔서 오면: 예전 방식(validate_and_store_image)으로 처리
 *  사진 개수 제한: 청첩장당 MAX_PHOTOS장. 꽉 차면 디자인에서 더 이상 안 쓰는 옛 사진(6시간 지난 것)을 먼저 정리하고 다시 센다.
 *   (예전엔 사진을 바꿔 올릴 때마다 쌓이기만 해서 30장을 넘으면 "일부 사진이 안 올라가는" 문제가 있었음)
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/snap_functions.php';

const MAX_PHOTOS        = 60;               // 청첩장 1건당 사진 수
const CLIENT_WEBP_BYTES = 1536 * 1024;      // 브라우저에서 바꿔 온 WebP 최대 용량
const CLIENT_WEBP_DIM   = 1920;             // 긴 변 최대 (예전 서버 처리와 같은 기준)

$pdo = get_pdo();

// 에디터(fetch)에서 올릴 때는 ajax=1을 같이 보내서 JSON으로 응답받는다.
// invite_edit.php의 옛날 방식 <form> 업로드는 ajax 없이 그대로 리다이렉트.
$isAjax = !empty($_POST['ajax']);

function upload_fail(bool $isAjax, int $code, string $msg): void
{
    http_response_code($code);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        exit($msg);
    }
    exit;
}

/**
 * 브라우저에서 WebP로 바꿔 온 사진이면 다시 만들지 않고 검사만 해서 저장. 조건에 안 맞으면 null (→ 예전 방식).
 * 검사: 실제 내용이 WebP(RIFF…WEBP 머리 + MIME + getimagesize) · 용량 · 가로세로 크기
 */
function store_client_webp(array $file, int $invitationId, bool $watermark): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) return null;
    $tmp = (string) $file['tmp_name'];
    if ((int) $file['size'] <= 0 || (int) $file['size'] > CLIENT_WEBP_BYTES) return null;
    $head = (string) @file_get_contents($tmp, false, null, 0, 12);
    if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') return null;
    if ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'image/webp') return null;
    $info = @getimagesize($tmp);
    if (!$info || ($info[2] ?? 0) !== IMAGETYPE_WEBP || $info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) > CLIENT_WEBP_DIM) return null;

    $invDir    = invitation_upload_dir($invitationId);
    $masterDir = $invDir . 'master/';
    foreach ([$invDir, $masterDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return null;
    }
    $filename = bin2hex(random_bytes(16)) . '.webp';

    // 1) 마스터(워터마크 없음) - 받은 그대로
    if (!@copy($tmp, $masterDir . $filename)) return null;
    // 2) 공개용 - 무료체험이면 워터마크를 입혀서, 아니면 그대로
    if ($watermark) {
        $im = @imagecreatefromwebp($tmp);
        if (!$im) { @unlink($masterDir . $filename); return null; } // 움직이는 WebP 등 → 예전 방식이 판단
        apply_watermark($im);
        $ok = imagewebp($im, $invDir . $filename, 82);
        imagedestroy($im);
        if (!$ok) { @unlink($masterDir . $filename); return null; }
    } elseif (!@copy($tmp, $invDir . $filename)) {
        @unlink($masterDir . $filename);
        return null;
    }
    return $filename;
}

/** 사진 수가 꽉 찼을 때: 디자인(design_json)에서 더 이상 안 쓰는 6시간 지난 사진을 지운다 (에디터로 만든 청첩장만) */
function purge_unused_photos(PDO $pdo, array $invite): void
{
    $design = (string) ($invite['design_json'] ?? '');
    if ($design === '') return; // 예전 방식 청첩장은 사진 목록(invitation_photos) 자체를 화면에 쓰므로 건드리지 않음
    $dir = invitation_upload_dir((int) $invite['id']);
    $st = $pdo->prepare('SELECT id, file_path FROM invitation_photos WHERE invitation_id = ?');
    $st->execute([$invite['id']]);
    $del = $pdo->prepare('DELETE FROM invitation_photos WHERE id = ?');
    foreach ($st->fetchAll() as $row) {
        $name = basename((string) $row['file_path']);
        if ($name === '' || str_contains($design, $name)) continue;      // 아직 쓰는 사진
        $path = $dir . $name;
        if (is_file($path) && filemtime($path) > time() - 6 * 3600) continue; // 방금 올리고 아직 저장 전일 수 있음
        @unlink($path);
        @unlink($dir . 'master/' . $name);
        $del->execute([$row['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    upload_fail($isAjax, 405, '허용되지 않는 요청입니다.');
}

csrf_verify($_POST['csrf_token'] ?? null);

$token = (string) ($_POST['edit_token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    upload_fail($isAjax, 404, '잘못된 접근입니다.');
}

$invite = find_invitation_by_token($pdo, $token);
if (!$invite || !empty($invite['deleted_at'])) {
    upload_fail($isAjax, 404, '유효하지 않은 링크입니다.');
}
if (invite_owner_locked($pdo, $invite)) {
    invite_owner_lock_fail($invite); // 무료체험이 끝난 유예 시간 등 - 결제 안내 (다른 편집 기능과 같은 잠금)
}
if ($invite['status'] === 'expired' || ($invite['expires_at'] && strtotime($invite['expires_at']) < time())) {
    upload_fail($isAjax, 403, '편집 기간이 만료된 청첩장입니다.');
}
if (empty($_SESSION['pin_ok_' . $invite['id']]) && $invite['edit_pin_hash']) {
    upload_fail($isAjax, 403, 'PIN 인증이 필요합니다.');
}
if (empty($_FILES['photo'])) {
    upload_fail($isAjax, 400, '사진 파일을 받지 못했어요. (서버 업로드 용량 한도를 확인해주세요)');
}

// 초대장 1건당 사진 개수 제한 (스토리지/남용 방지) - 꽉 차면 안 쓰는 옛 사진부터 정리하고 다시 센다
$countStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM invitation_photos WHERE invitation_id = ?');
$countStmt->execute([$invite['id']]);
if ((int) $countStmt->fetch()['cnt'] >= MAX_PHOTOS) {
    purge_unused_photos($pdo, $invite);
    $countStmt->execute([$invite['id']]);
    if ((int) $countStmt->fetch()['cnt'] >= MAX_PHOTOS) {
        upload_fail($isAjax, 400, '사진은 최대 ' . MAX_PHOTOS . '장까지 업로드 가능합니다. 안 쓰는 사진을 지우고 저장한 뒤 다시 올려주세요.');
    }
}

try {
    $watermark = needs_watermark($invite);
    $filename  = store_client_webp($_FILES['photo'], (int) $invite['id'], $watermark)
              ?? validate_and_store_image($_FILES['photo'], (int) $invite['id'], $watermark);
    $stmt = $pdo->prepare('INSERT INTO invitation_photos (invitation_id, file_path) VALUES (?, ?)');
    $stmt->execute([$invite['id'], $filename]);
} catch (RuntimeException $e) {
    upload_fail($isAjax, 400, $e->getMessage());
}

if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'        => true,
        'url'       => 'uploads/' . $invite['id'] . '/' . $filename,
        'watermark' => $watermark,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Location: invite_edit.php?t=' . urlencode($token));
exit;
