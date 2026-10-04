<?php
/**
 * invite_load.php - 에디터가 처음 열릴 때 청첩장 내용을 불러온다 (?t=편집토큰)
 * expiry: 편집 화면 삭제 카운트다운용 정보 (guest_functions.php의 invite_expiry_info)
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php'; // functions.php + 카운트다운 정보

header('Content-Type: application/json; charset=utf-8');
$pdo = get_pdo();

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fail(404, '잘못된 접근입니다.');
}

$invite = find_invitation_by_token($pdo, $token);
if (!$invite) {
    fail(404, '유효하지 않은 링크입니다.');
}

// 기간이 끝났으면 편집 잠금 - 에디터는 locked를 보고 "결제하고 계속 쓰기" 안내를 띄운다
if ($invite['status'] === 'expired' || invite_owner_locked($pdo, $invite)) {
    http_response_code(403);
    $trial = $invite['storage_plan'] === 'trial';
    echo json_encode(['ok' => false, 'locked' => true, 'trial' => $trial,
        'error' => $trial ? '무료체험 기간이 끝나 편집이 잠겼어요. 결제하면 바로 다시 편집할 수 있어요.' : '보관 기간이 끝나 편집이 잠겼어요.',
        'pay_url' => 'dashboard.php?t=' . rawurlencode($token) . ($trial ? '&pay=1' : '')], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($invite['edit_pin_hash'] && empty($_SESSION['pin_ok_' . $invite['id']])) {
    fail(403, 'PIN 인증이 필요합니다.');
}

$design = null;
if ($invite['design_json']) {
    $decoded = json_decode($invite['design_json'], true);
    if (is_array($decoded)) {
        $design = $decoded;
    }
}

// design_json에는 계좌정보가 마스킹(•)되어 있으므로, 편집 화면에서는
// account_info_enc를 복호화해서 실제 값으로 다시 채워 넣어준다 (편집자 본인이므로 안전).
if ($design && $invite['account_info_enc'] && isset($design['blocks']) && is_array($design['blocks'])) {
    $decrypted = decrypt_data($invite['account_info_enc']);
    $accountInfo = json_decode($decrypted, true);

    if (is_array($accountInfo)) {
        foreach ($design['blocks'] as &$block) {
            if (($block['id'] ?? '') === 'account' && isset($block['fields'])) {
                foreach (ACCOUNT_KEYS as $key) {
                    if (isset($accountInfo[$key])) {
                        $block['fields'][$key] = $accountInfo[$key];
                    }
                }
            }
        }
        unset($block);
    }
}

// 이 고객이 이전에 올려둔 개인 스티커들도 같이 내려줘서, 다른 청첩장을 만들 때도 재사용할 수 있게 한다
$personalStickers = [];
if (!empty($invite['customer_id'])) {
    $stmt = $pdo->prepare('SELECT id, file_path FROM customer_stickers WHERE customer_id = ? ORDER BY created_at DESC');
    $stmt->execute([(int) $invite['customer_id']]);
    foreach ($stmt->fetchAll() as $row) {
        $personalStickers[] = ['id' => (int) $row['id'], 'url' => 'uploads/stickers/' . (int) $invite['customer_id'] . '/' . $row['file_path']];
    }
}

echo json_encode([
    'ok'          => true,
    'design'      => $design, // 처음 만드는 경우 null - 에디터는 기본값으로 시작
    'invitation_id' => (int) $invite['id'],
    'csrf_token'  => csrf_token(), // 에디터에서 사진 업로드(upload_photo.php) 시 필요
    'personal_stickers' => $personalStickers,
    'groom_name'  => $invite['groom_name'],
    'bride_name'  => $invite['bride_name'],
    'venue_name'  => $invite['venue_name'],
    'venue_address' => $invite['venue_address'],
    'wedding_datetime' => $invite['wedding_datetime'],
    'status'      => $invite['status'],
    'view_slug'   => $invite['view_slug'],
    'expiry'      => invite_expiry_info($invite),
], JSON_UNESCAPED_UNICODE);