<?php
/**
 * invite_expiry.php - 편집 화면이 30초마다 물어보는 "자동 삭제까지 남은 시간" (?t=편집토큰)
 *
 * 관리자가 남은 시간을 바꾸거나, 편집 도중 결제·가입으로 기간이 늘어나면 에디터의 카운트다운이 바로 따라간다.
 * 보관 시간이 이미 지났어도 답한다 (invite_load.php는 만료되면 403이라 에디터가 이걸로 확인).
 * 청첩장 내용은 주지 않고 시간 정보만 준다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$token = (string) ($_GET['t'] ?? '');
$inv = preg_match('/^[a-f0-9]{64}$/', $token) ? find_invitation_by_token(get_pdo(), $token) : null;
if (!$inv) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'gone' => true], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['ok' => true, 'expiry' => invite_expiry_info($inv)], JSON_UNESCAPED_UNICODE);
