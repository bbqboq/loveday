<?php
/**
 * snap_image.php?t=편집토큰&id=사진번호 - 게스트스냅 사진 1장 보기 (신랑신부 본인만)
 * 사진 파일 주소를 직접 노출하지 않고, 편집 토큰을 확인한 뒤에만 내려준다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

$pdo = get_pdo();
$invite = snap_owner_invite($pdo, (string) ($_GET['t'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);
if (!$invite || $id <= 0) { http_response_code(403); exit; }

$st = $pdo->prepare('SELECT file_name FROM guest_snaps WHERE id = ? AND invitation_id = ?');
$st->execute([$id, (int) $invite['id']]);
$row = $st->fetch();
$path = $row ? snap_dir((int) $invite['id']) . $row['file_name'] : '';
if (!$row || !preg_match('/^[a-f0-9]{32}\.webp$/', $row['file_name']) || !is_file($path)) { http_response_code(404); exit; }

header('Content-Type: image/webp');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
