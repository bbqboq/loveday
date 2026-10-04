<?php
// admin_logout.php - 관리자 로그아웃 (POST + CSRF로만. 링크 한 번 눌러서 남이 로그아웃시키지 못하게)
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: admin_create.php'); exit; }
csrf_verify($_POST['csrf_token'] ?? null);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => 1, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax']);
}
session_destroy();
header('Location: admin_login.php?bye=1');
exit;
