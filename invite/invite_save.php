<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

// 계좌정보(은행/계좌번호/예금주) 관련 필드 키 - design_json에는 평문으로 남기지 않을 필드들

header('Content-Type: application/json; charset=utf-8');
$pdo = get_pdo();

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, '허용되지 않는 요청입니다.');
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    fail(400, '잘못된 요청 본문입니다.');
}

$token = (string) ($body['edit_token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail(404, '잘못된 접근입니다.');
}

$invite = find_invitation_by_token($pdo, $token);
if (!$invite) {
    fail(404, '유효하지 않은 링크입니다.');
}
if ($invite['status'] === 'expired' || ($invite['expires_at'] && strtotime($invite['expires_at']) < time())) {
    fail(403, '편집 기간이 만료된 청첩장입니다.');
}
if ($invite['edit_pin_hash'] && empty($_SESSION['pin_ok_' . $invite['id']])) {
    fail(403, 'PIN 인증이 필요합니다.');
}

$design = $body['design'] ?? null;
if (!is_array($design) || !isset($design['blocks']) || !is_array($design['blocks'])) {
    fail(400, '디자인 데이터가 없습니다.');
}

/*
 * 계좌정보(은행/계좌번호/예금주)는 design_json에 평문으로 절대 남기지 않는다.
 * account 블록을 찾아서 값은 따로 뽑아 암호화 저장하고, design_json에는
 * "값이 있었는지 여부"만 남겨서(마스킹 값) 레이아웃/파츠 표시 여부는 그대로 유지한다.
 */
$accountInfo = null;

foreach ($design['blocks'] as &$block) {
    if (($block['id'] ?? '') !== 'account' || !isset($block['fields']) || !is_array($block['fields'])) {
        continue;
    }
    $accountInfo = [];
    foreach (ACCOUNT_KEYS as $key) {
        $val = trim((string) ($block['fields'][$key] ?? ''));
        $accountInfo[$key] = $val;
        // design_json 쪽에는 "값이 있었다"는 사실만 남김 (빈 문자열이면 그 파츠는 원래도 안 보였던 것)
        $block['fields'][$key] = $val !== '' ? '•' : '';
    }
}
unset($block);

$designJson = json_encode($design, JSON_UNESCAPED_UNICODE);
if ($designJson === false) {
    fail(400, '디자인 데이터를 저장할 수 없는 형식입니다.');
}
if (strlen($designJson) > 2_000_000) { // 2MB 제한 - 악의적으로 거대한 payload 방지
    fail(413, '디자인 데이터가 너무 큽니다.');
}

// 관리자 목록/페이지 타이틀 등에 쓰이는 요약 필드는 있으면 같이 갱신 (없으면 기존 값 유지)
$groom = trim((string) ($body['groom_name'] ?? ''));
$bride = trim((string) ($body['bride_name'] ?? ''));

$publish = !empty($body['publish']);
$status  = $publish ? 'published' : $invite['status'];
if ($status === 'expired') {
    $status = $invite['status']; // 방어적으로: 여기까지 왔다는 건 이미 위에서 만료 체크를 통과했다는 뜻
}

$pdo->beginTransaction();
try {
    if ($accountInfo !== null) {
        $accountEnc = encrypt_data(json_encode($accountInfo, JSON_UNESCAPED_UNICODE));
        $pdo->prepare('UPDATE invitation_orders SET account_info_enc = ? WHERE id = ?')
            ->execute([$accountEnc, $invite['id']]);
    }

    $stmt = $pdo->prepare('
        UPDATE invitation_orders
        SET design_json = ?,
            groom_name = COALESCE(NULLIF(?, \'\'), groom_name),
            bride_name = COALESCE(NULLIF(?, \'\'), bride_name),
            status = ?
        WHERE id = ?
    ');
    $stmt->execute([$designJson, $groom, $bride, $status, $invite['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fail(500, '저장 중 오류가 발생했습니다.');
}

echo json_encode([
    'ok'       => true,
    'status'   => $status,
    'view_url' => 'https://loveday.kr/' . $invite['view_slug'],
], JSON_UNESCAPED_UNICODE);
