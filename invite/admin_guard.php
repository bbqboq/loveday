<?php
declare(strict_types=1);
// 모든 관리자 전용 페이지 최상단에서 require 할 것 (functions.php 다음에)
//  - 로그인 확인 + 30분 동안 아무것도 안 하면 자동 로그아웃
//  - 부관리자: 계정이 꺼졌거나 지워졌거나 비밀번호가 바뀌었으면 로그아웃, 화면·버튼별 권한 확인 (admin_auth.php)
require_once __DIR__ . '/admin_auth.php';

if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    if (defined('ADMIN_GUARD_PASSIVE')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'expired' => true]);
        exit;
    }
    header('Location: admin_login.php');
    exit;
}

// 세션 유휴시간 30분 초과 시 자동 로그아웃
//  - 화면 오른쪽 위 계정 동그라미 / 끝나기 5분 전 팝업에서 "30분·1시간·6시간 연장"을 누르면
//    admin_session.php가 $_SESSION['admin_extend_until']을 늘려서 그때까지는 아무것도 안 해도 유지된다.
//  - admin_session.php(남은 시간 확인)는 ADMIN_GUARD_PASSIVE를 켜고 불러서, 확인만으로는 시간이 늘어나지 않게 한다.
const ADMIN_IDLE_LIMIT = 1800;
function admin_session_deadline(): int
{
    return max((int) ($_SESSION['last_activity'] ?? time()) + ADMIN_IDLE_LIMIT, (int) ($_SESSION['admin_extend_until'] ?? 0));
}
if (isset($_SESSION['last_activity']) && time() > admin_session_deadline()) {
    $_SESSION = [];
    session_destroy();
    if (defined('ADMIN_GUARD_PASSIVE')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'expired' => true]);
        exit;
    }
    header('Location: admin_login.php?expired=1');
    exit;
}
if (!defined('ADMIN_GUARD_PASSIVE') || !isset($_SESSION['last_activity'])) $_SESSION['last_activity'] = time();

// ---- 부관리자: 매번 DB에서 계정·권한을 다시 읽는다 (대표가 바꾸면 바로 적용) ----
if (!admin_is_owner()) {
    $sub = null;
    try {
        $gpdo = get_pdo();
        admin_users_ensure($gpdo);
        $st = $gpdo->prepare('SELECT id, username, display_name, perms, active, UNIX_TIMESTAMP(pw_changed_at) AS pw_ts FROM admin_users WHERE id = ?');
        $st->execute([(int) ($_SESSION['admin_uid'] ?? 0)]);
        $sub = $st->fetch() ?: null;
    } catch (Throwable $e) { $sub = null; }
    // 없어졌거나, 꺼졌거나, 로그인한 뒤에 비밀번호가 바뀌었으면 로그아웃
    if (!$sub || !(int) $sub['active'] || (int) $sub['pw_ts'] > (int) ($_SESSION['admin_login_at'] ?? 0)) {
        $_SESSION = [];
        session_destroy();
        header('Location: admin_login.php?off=1');
        exit;
    }
    $sub['perms'] = admin_perms_decode($sub['perms']);
    $GLOBALS['LD_ADMIN_SUB'] = $sub;

    // ---- 화면별 권한 ----
    $gPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $gPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $gRule = admin_page_rule($gPage);
    if ($gRule) {
        [$gPerm, $gMode] = $gRule;
        if (($gMode === 'all' || $gPost) && !admin_can($gPerm)) admin_deny(ADMIN_PERMS[$gPerm][1] ?? '대표 관리자 전용 화면');
    }
    if ($gPost) {
        // 스마트스토어: API 키 저장·연결 시험은 대표만
        if ($gPage === 'admin_naver.php' && in_array($_POST['action'] ?? '', ['save', 'test'], true)) admin_deny('스마트스토어 API 설정');
        // 휴지통: 복원 / 영구삭제·비우기
        if ($gPage === 'admin_trash.php') {
            $gAct = (string) ($_POST['act'] ?? '');
            if ($gAct === 'restore') admin_require('trash_restore');
            elseif ($gAct === 'delete' || $gAct === 'empty') admin_require('trash_purge');
        }
        // 청첩장 수정: 버튼마다
        if ($gPage === 'admin_edit.php') {
            if (isset($_POST['set_expiry'])) admin_require('expiry_edit');
            if (isset($_POST['set_plan'])) admin_require('plan_edit');
            if (isset($_POST['delete_photo_id'])) admin_require('customer_photos');
            // 연락처·고객코드는 admin_edit.php가 권한이 없으면 무시하고 원래 값을 지킨다
        }
    }
}
