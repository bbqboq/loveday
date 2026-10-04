<?php
/**
 * gdrive_connect.php?t=편집토큰 - 신랑신부가 "구글 드라이브 연결"을 누르면 구글 로그인 화면으로 보낸다.
 *   ?t=..&off=1 (POST) 로 오면 연결 해제.
 * 관리자가 구글 드라이브 연동을 꺼 두었으면(gdrive_enabled=0) 아무것도 하지 않고 돌려보낸다.
 */
declare(strict_types=1);
require_once __DIR__ . '/gdrive.php';

$pdo = get_pdo();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$invite = snap_owner_invite($pdo, $token);
if (!$invite) { http_response_code(403); exit('편집 링크가 올바르지 않거나 PIN 확인이 필요합니다.'); }
$back = 'snap_manage.php?t=' . rawurlencode($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['off'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    gdrive_disconnect($pdo, (int) $invite['id']);
    header('Location: ' . $back . '&gd=off'); exit;
}
if (!gdrive_on()) { header('Location: ' . $back . '&gd=closed'); exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['gdrive_state'] = ['s' => $state, 't' => $token, 'at' => time(), 'r' => in_array($_GET['r'] ?? '', ['dash'], true) ? 'dash' : 'snap'];
header('Location: ' . gdrive_auth_url($state));
