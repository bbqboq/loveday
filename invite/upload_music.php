<?php
/**
 * upload_music.php - 신랑신부가 직접 고른 배경음악 1곡 업로드 (에디터 "배경음악 → 직접 올리기")
 *
 *  - 청첩장 1건당 1곡만 보관 (새로 올리면 이전 곡은 지운다). mp3/m4a
 *  - 권리 동의(agree=1)가 있어야 받는다. 동의 기록은 music_uploads 표에 남긴다 (신고가 들어오면 확인용)
 *  - 용량 줄이기: 에디터가 브라우저에서 먼저 96kbps mp3로 다시 저장해서 보낸다 (4분 곡 약 3MB).
 *    브라우저가 못 줄인 채로 온 큰 파일은, 서버에 ffmpeg가 있으면 여기서 한 번 더 줄인다.
 *  - 최대 용량: 관리자 설정 bgm_max_mb (기본 10MB, 줄인 뒤 크기 기준)
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php'; // app_setting() 사용

header('Content-Type: application/json; charset=utf-8');
function music_fail(int $code, string $msg): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

/** 서버에 ffmpeg가 있으면 96kbps mp3로 다시 저장 (앨범 사진·태그도 뺌) */
function music_transcode(string $src, string $dst): bool
{
    if (!function_exists('exec') || !function_exists('shell_exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true) || in_array('shell_exec', $disabled, true)) return false;
    $ff = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($ff === '') return false;
    $cmd = escapeshellcmd($ff) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($src)
        . ' -vn -map_metadata -1 -ac 2 -ar 44100 -codec:a libmp3lame -b:a 96k ' . escapeshellarg($dst) . ' 2>&1';
    @exec($cmd, $out, $code);
    return $code === 0 && is_file($dst) && filesize($dst) > 1000;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') music_fail(405, '허용되지 않는 요청입니다.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) music_fail(403, '페이지를 새로고침 후 다시 시도해주세요.');

$pdo = get_pdo();
$token = (string) ($_POST['edit_token'] ?? '');
$invite = preg_match('/^[a-f0-9]{64}$/', $token) ? find_invitation_by_token($pdo, $token) : null;
if (!$invite) music_fail(404, '유효하지 않은 링크입니다.');
if ($invite['status'] === 'expired' || ($invite['expires_at'] && strtotime($invite['expires_at']) < time())) music_fail(403, '편집 기간이 만료된 청첩장입니다.');
if (!empty($invite['edit_pin_hash']) && empty($_SESSION['pin_ok_' . $invite['id']])) music_fail(403, 'PIN 인증이 필요합니다.');
if (($_POST['agree'] ?? '') !== '1') music_fail(400, '음원 사용 권리 확인에 동의해야 올릴 수 있어요.');

$f = $_FILES['music'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) music_fail(400, '업로드 중 오류가 발생했습니다. (파일이 너무 크면 서버 업로드 한도에 걸릴 수 있어요)');
$maxMb = (int) (app_setting('bgm_max_mb') ?: 10);
$mime = mime_content_type($f['tmp_name']);
$ext = match ($mime) { 'audio/mpeg', 'audio/mp3' => 'mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac' => 'm4a', default => '' };
if ($ext === '') music_fail(400, 'mp3 또는 m4a 파일만 올릴 수 있어요.');

$dir = invitation_upload_dir((int) $invite['id']);
if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) music_fail(500, '저장 폴더를 만들 수 없어요.');
$tmpName = $f['tmp_name'];
$serverCompressed = false;
// 브라우저가 줄이지 못한 큰 파일(2.5MB 넘음)은 서버에서 한 번 더 줄여 본다
if (($_POST['compressed'] ?? '') !== '1' && $f['size'] > 2.5 * 1048576) {
    $tc = $dir . 'tmp_' . bin2hex(random_bytes(6)) . '.mp3';
    if (music_transcode($tmpName, $tc) && filesize($tc) < $f['size']) { $tmpName = $tc; $ext = 'mp3'; $serverCompressed = true; }
    else @unlink($tc);
}
$finalSize = (int) filesize($tmpName);
if ($finalSize > $maxMb * 1048576) { if ($serverCompressed) @unlink($tmpName); music_fail(400, "음악 파일은 {$maxMb}MB 이하만 올릴 수 있어요. (줄인 뒤 크기 기준)"); }

foreach (glob($dir . 'bgm_*') ?: [] as $old) @unlink($old); // 1곡만 보관
$name = 'bgm_' . bin2hex(random_bytes(12)) . '.' . $ext;
$ok = $serverCompressed ? rename($tmpName, $dir . $name) : move_uploaded_file($tmpName, $dir . $name);
if (!$ok) music_fail(500, '저장에 실패했어요.');

// 동의 기록 (표가 아직 없으면 건너뜀 - stage4_setup.sql 실행 전)
$origName = mb_substr(trim(strip_tags((string) ($_POST['orig_name'] ?? $f['name'] ?? ''))), 0, 150);
$origSize = max($finalSize, (int) ($_POST['orig_size'] ?? $f['size']));
try {
    $pdo->prepare("UPDATE music_uploads SET status = 'replaced' WHERE invitation_id = ? AND status = 'active'")->execute([$invite['id']]);
    $pdo->prepare('INSERT INTO music_uploads (invitation_id, file_name, orig_name, orig_size, size_bytes, agreed_at, ip_hash) VALUES (?, ?, ?, ?, ?, NOW(), ?)')
        ->execute([$invite['id'], $name, $origName, $origSize, $finalSize, hash('sha256', (function_exists('client_ip') ? client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '')) . '|' . (defined('NAVER_ID_PEPPER') ? NAVER_ID_PEPPER : 'loveday'))]);
} catch (Throwable $e) {}

echo json_encode(['ok' => true, 'url' => 'uploads/' . (int) $invite['id'] . '/' . $name, 'size' => $finalSize, 'origSize' => $origSize, 'serverCompressed' => $serverCompressed], JSON_UNESCAPED_UNICODE);
