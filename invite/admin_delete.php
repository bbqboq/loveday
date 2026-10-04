<?php
/**
 * admin_delete.php - 관리자 목록(admin_create.php)의 "삭제"
 *  - 고객·VIP 청첩장: 휴지통으로 (admin_trash.php에서 복원 가능, 보관 기간이 지나면 정리 작업이 완전 삭제)
 *  - 비회원 둘러보기(체험) 청첩장: 휴지통 없이 바로 완전 삭제 (사진·게스트스냅·방명록 등 전부)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/guest_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('허용되지 않는 요청입니다.');
}

csrf_verify($_POST['csrf_token'] ?? null);

$pdo = get_pdo();
$id  = (int) ($_POST['id'] ?? 0);
$tab = in_array($_POST['tab'] ?? '', ['cust', 'vip', 'test'], true) ? $_POST['tab'] : 'cust';

if ($id <= 0) {
    http_response_code(400);
    exit('잘못된 요청입니다.');
}

$st = $pdo->prepare('SELECT o.customer_id, o.order_memo, o.deleted_at, c.customer_code
                     FROM invitation_orders o LEFT JOIN customers c ON c.id = o.customer_id WHERE o.id = ?');
$st->execute([$id]);
$inv = $st->fetch();
if (!$inv) {
    header('Location: admin_create.php#tab-' . $tab);
    exit;
}

$isDemo = empty($inv['customer_id']) && str_starts_with((string) $inv['order_memo'], DEMO_MEMO);
if ($isDemo) {
    invitation_purge($pdo, $id);
    $q = 'purged=1';
} else {
    if (empty($inv['deleted_at'])) soft_delete_invitation($pdo, $id);
    $q = 'trashed=1';
}
// 같은 고객 묶음은 펼친 채로 돌아가기
if (!empty($inv['customer_code'])) $q .= '&open=' . rawurlencode((string) $inv['customer_code']);

header('Location: admin_create.php?' . $q . '#tab-' . $tab);
exit;
