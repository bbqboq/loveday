<?php
/**
 * guest_secure.php - 청첩장 페이지의 계좌번호·연락처를 "사람에게만" 건네주는 곳 (봇 수집 방지)
 *
 * 청첩장 페이지(invite_view.php)는 HTML에 계좌번호·전화번호를 넣지 않고, 하객이 화면을 실제로 만지면
 * (스크롤·터치·클릭) 이 주소로 POST해서 받아 채운다 (render-invite.js의 bindGuestSecure).
 *
 * 요청:  POST  s=청첩장코드 & k=페이지 입장권   (소유자 미리보기는 t=편집토큰)
 *        헤더 X-LD-Guest: 1
 * 응답:  { ok, accounts: {groomBank: "…", …}, phones: {groomPhone: "01012345678", …} }
 *
 * 통과 조건(guest_functions.php의 guest_* 함수): 페이지 입장권(쿠키와 짝, 12시간) / POST·같은 사이트 /
 *  봇 이름 아님 / IP당 10분 60번·하루 서로 다른 청첩장 30개까지. 하나라도 어긋나면 번호 없이 거절한다.
 * 예전 account_reveal.php(주소만 알면 누구나 GET으로 받아가던 곳)는 이 파일로 대체되어 닫았다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function gs_deny(int $code, string $why): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $why], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') gs_deny(405, 'method');
if (!guest_same_origin()) gs_deny(403, 'origin');
if (guest_is_bot()) gs_deny(403, 'bot');

$pdo = get_pdo();
$editToken = (string) ($_POST['t'] ?? '');
if ($editToken !== '') {
    // 소유자 미리보기 (편집 토큰 자체가 64자 비밀값)
    if (!preg_match('/^[a-f0-9]{64}$/', $editToken)) gs_deny(404, 'not found');
    $inv = find_invitation_by_token($pdo, $editToken);
    if (!$inv || !empty($inv['deleted_at'])) gs_deny(404, 'not found');
    if (invite_owner_locked($pdo, $inv)) gs_deny(403, 'locked'); // 기간이 끝난 청첩장은 주인 미리보기에서도 계좌·연락처 안 줌
} else {
    $slug = (string) ($_POST['s'] ?? '');
    if (!preg_match('/^[a-z0-9]{4,20}$/', $slug)) gs_deny(404, 'not found');
    $inv = guest_invite_by_slug($pdo, $slug); // 발행 + 삭제·기간 만료 아닌 청첩장만
    if (!$inv) gs_deny(404, 'not found');
    if (!guest_token_ok((string) ($_POST['k'] ?? ''), (int) $inv['id'])) gs_deny(403, 'token');
    if (!guest_secure_rate_ok($pdo, (int) $inv['id'])) gs_deny(429, 'rate');
}

// 계좌: DB에는 암호화된 채로만 있고(account_info_enc), 여기서만 풀어서 건넨다. 글자 값만 그대로 (새 방식·예전 방식 키 모두)
$accounts = [];
if (!empty($inv['account_info_enc'])) {
    $dec = json_decode(decrypt_data((string) $inv['account_info_enc']), true);
    if (is_array($dec)) foreach ($dec as $k => $v) if (is_string($k) && is_string($v) && $v !== '') $accounts[$k] = $v;
}
// 연락처: 연락하기 섹션의 전화번호
$design = json_decode((string) ($inv['design_json'] ?? ''), true);
$phones = is_array($design) ? guest_secure_phones($design) : [];

echo json_encode(['ok' => true, 'accounts' => (object) $accounts, 'phones' => (object) $phones], JSON_UNESCAPED_UNICODE);
