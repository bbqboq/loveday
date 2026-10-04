<?php
/**
 * pay_check.php?t=편집토큰 - 결제 창(대시보드 "💳 결제하기")이 결제가 반영됐는지 확인
 *
 *  - 이미 결제 반영(1년·영구)이면 바로 paid
 *  - 아니면 스마트스토어 주문을 "지금" 한 번 확인한다 (2분마다 도는 크론을 기다리지 않게)
 *    크론과 같은 잠금 파일을 써서 겹치지 않고, 청첩장당 20초에 한 번만 네이버에 물어본다
 * 응답: { ok, paid, plan, expires }
 */
declare(strict_types=1);
require_once __DIR__ . '/naver_commerce.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$pdo = get_pdo();
$inv = snap_owner_invite($pdo, (string) ($_GET['t'] ?? ''));
if (!$inv) { http_response_code(403); echo '{"ok":false}'; exit; }
$id = (int) $inv['id'];

$read = function () use ($pdo, $id): array {
    $st = $pdo->prepare('SELECT storage_plan, expires_at FROM invitation_orders WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: ['storage_plan' => 'trial', 'expires_at' => null];
};
$row = $read();
$synced = false;
if ($row['storage_plan'] === 'trial' && app_setting('naver_enabled') === '1' && nc_configured() && check_rate_limit($pdo, 'paychk_' . $id, 1, 20)) {
    $lock = fopen(sys_get_temp_dir() . '/loveday_naver_sync.lock', 'c'); // naver_order_sync.php(크론)와 같은 잠금
    if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
        try { nc_sync($pdo); $synced = true; } catch (Throwable $e) { /* 다음 확인 때 다시 */ }
        flock($lock, LOCK_UN);
    }
    $row = $read();
}
echo json_encode(['ok' => true, 'paid' => $row['storage_plan'] !== 'trial', 'plan' => $row['storage_plan'],
    'expires' => $row['expires_at'], 'synced' => $synced], JSON_UNESCAPED_UNICODE);
