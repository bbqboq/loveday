<?php
/**
 * start_free.php - 홈페이지의 "네이버로 무료 시작" / "로그인" / "무료로 만들기" 버튼 (POST)
 *
 *  - 이미 로그인했으면 → 내 청첩장(대시보드). 이 브라우저에서 둘러보기로 만든 청첩장이 있으면 내 계정으로 옮긴 뒤 이동
 *  - 로그인 전이면 → 네이버 로그인 (naver_login.php → naver_callback.php → 대시보드)
 *
 * 관리자 샘플(네이버 로그인 없이 만드는 무제한 샘플)은 admin_sample=1 을 함께 보낼 때만 만든다.
 *  예전엔 관리자로 로그인된 브라우저에서 홈페이지 버튼만 눌러도 샘플이 새로 생기고
 *  "편집 링크로 들어왔어요" 화면으로 가서, 네이버 로그인이 안 되는 것처럼 보였다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php'; // functions.php + 둘러보기 청첩장 옮기기(demo_claim_from_session)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('허용되지 않는 요청입니다.');
}

$isAdmin = !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

if ($isAdmin && ($_POST['admin_sample'] ?? '') === '1') {
    // 관리자는 네이버 로그인 없이 무제한 생성 (테스트/데모용, 슬롯 제한 없는 별도 샘플)
    $pdo       = get_pdo();
    $editToken = generate_edit_token();
    $slug      = generate_slug($pdo, '', '');

    $stmt = $pdo->prepare('
        INSERT INTO invitation_orders
            (order_platform, edit_token, view_slug, groom_name, bride_name, status, storage_plan, expires_at)
        VALUES
            (\'admin_sample\', ?, ?, \'\', \'\', \'editing\', \'trial\', DATE_ADD(NOW(), INTERVAL 4 DAY))
    ');
    $stmt->execute([$editToken, $slug]);

    header('Location: invite_edit.php?t=' . urlencode($editToken));
    exit;
}

// 이미 이 브라우저에서 네이버 로그인이 되어있으면 재로그인 없이 바로 대시보드로
// (둘러보기로 만든 청첩장이 있으면 내 계정으로 옮겨서, 그 청첩장을 골라 둔 채로)
if (!empty($_SESSION['customer_id'])) {
    $r = demo_claim_from_session(get_pdo(), (int) $_SESSION['customer_id']);
    header('Location: ' . demo_claim_redirect($r));
    exit;
}

// 처음 방문이면 네이버 로그인을 거쳐야 한다. 로그인 이후 대시보드로 이동한다.
header('Location: naver_login.php');
exit;
