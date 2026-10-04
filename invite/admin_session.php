<?php
/**
 * admin_session.php - 관리자 자동 로그아웃까지 남은 시간 확인 / 연장
 *
 *  GET              → {"ok":true,"left":초,"extended":연장 중이면 true}
 *                     (확인만으로는 시간이 늘어나지 않음 - ADMIN_GUARD_PASSIVE)
 *  POST act=extend  → sec=1800|3600|21600 (30분·1시간·6시간) 지금부터 그만큼 유지
 *  관리자 화면 상단(admin_nav.php)이 1분마다 확인하고, 5분 남으면 연장 팝업을 띄운다.
 */
declare(strict_types=1);
define('ADMIN_GUARD_PASSIVE', true);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ADMIN_EXTEND_CHOICES = [1800, 3600, 21600];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $sec = (int) ($_POST['sec'] ?? 0);
    if (($_POST['act'] ?? '') !== 'extend' || !in_array($sec, ADMIN_EXTEND_CHOICES, true)) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청이에요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['last_activity'] = time();
    $_SESSION['admin_extend_until'] = time() + $sec;
}

$left = max(0, admin_session_deadline() - time());
echo json_encode(['ok' => true, 'left' => $left, 'extended' => (int) ($_SESSION['admin_extend_until'] ?? 0) > time() + 5], JSON_UNESCAPED_UNICODE);
