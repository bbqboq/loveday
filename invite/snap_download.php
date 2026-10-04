<?php
/**
 * snap_download.php (POST) - 게스트스냅 전체 사진 zip 다운로드 (신랑신부 본인, 횟수 제한)
 * 횟수는 zip을 다 만든 뒤, 내보내기 직전에 1회 차감한다 (만드는 도중 실패하면 차감 안 됨).
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('잘못된 요청입니다.'); }
csrf_verify($_POST['csrf_token'] ?? null);

$pdo = get_pdo();
$token = (string) ($_POST['t'] ?? '');
$invite = snap_owner_invite($pdo, $token);
if (!$invite) { http_response_code(403); exit('권한이 없습니다.'); }
$invitationId = (int) $invite['id'];
$back = 'snap_manage.php?t=' . urlencode($token);

require_once __DIR__ . '/gdrive.php';
if (!snap_server_download_on()) exit('서버 전체 다운로드는 지금 쓸 수 없어요. 구글 드라이브를 연결해 받아주세요. <a href="' . snap_h($back) . '">돌아가기</a>');
if (snap_schedule($invite)['state'] === 'expired') exit('보관 기간이 지나 사진이 삭제되었습니다. <a href="' . snap_h($back) . '">돌아가기</a>');
if (snap_download_count($pdo, $invitationId) >= app_setting_int('snap_download_limit')) exit('다운로드 가능 횟수를 모두 사용했어요. <a href="' . snap_h($back) . '">돌아가기</a>');
if (!class_exists('ZipArchive')) exit('서버에 zip 기능(php-zip)이 설치되어 있지 않습니다. 관리자에게 문의해주세요.');

$st = $pdo->prepare('SELECT file_name, guest_name, created_at FROM guest_snaps WHERE invitation_id = ? ORDER BY created_at');
$st->execute([$invitationId]);
$rows = $st->fetchAll();
if (!$rows) exit('받을 사진이 없어요. <a href="' . snap_h($back) . '">돌아가기</a>');

@set_time_limit(300);
$tmp = tempnam(sys_get_temp_dir(), 'snapzip');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) exit('압축 파일을 만들 수 없습니다.');
$n = 0;
foreach ($rows as $i => $r) {
    $path = snap_dir($invitationId) . $r['file_name'];
    if (!is_file($path)) continue;
    // 파일명: 순번_올린사람_날짜시간.webp (이름에서 파일명에 못 쓰는 글자는 제거)
    $who = preg_replace('/[^\p{L}\p{N}_-]+/u', '', (string) ($r['guest_name'] ?? '')) ?: 'guest';
    $entry = sprintf('%03d_%s_%s.webp', $i + 1, $who, date('md-His', strtotime($r['created_at'])));
    $zip->addFile($path, $entry);
    $zip->setCompressionName($entry, ZipArchive::CM_STORE); // 사진은 이미 압축돼 있어서 다시 압축하지 않음(빠르게)
    $n++;
}
$zip->close();
if ($n === 0) { @unlink($tmp); exit('받을 사진 파일이 없어요.'); }

$pdo->prepare('INSERT INTO guest_snap_downloads (invitation_id, photo_count) VALUES (?, ?)')->execute([$invitationId, $n]);

$fname = 'guestsnap_' . preg_replace('/[^a-z0-9]/', '', $invite['view_slug']) . '_' . date('Ymd') . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
