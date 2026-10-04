<?php
/**
 * rsvp.php - 하객 참석여부 전달 (청첩장의 "참석 의사 전달" 섹션이 부른다)
 *
 *   GET  rsvp.php?s=공개코드&mine=1   → 이 브라우저가 전에 보낸 응답 (다시 열었을 때 채워 넣기용)
 *   POST rsvp.php  s, csrf_token, side(groom|bride), attend(yes|no), name, headcount, meal(yes|no|unknown), phone, memo
 *
 * 하객 한 명(브라우저 1개)당 청첩장마다 한 줄 - 다시 보내면 덮어써서 수정된다.
 * 연락처는 암호화해서 저장하고, 신랑신부 관리 화면(guest_manage.php)에서만 풀어서 보여준다.
 * 예식일 + rsvp_retention_days(관리자 설정, 기본 90일)가 지나면 snap_cleanup.php 크론이 명단을 지운다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

$pdo  = get_pdo();
$slug = (string) ($_REQUEST['s'] ?? '');
$inv  = guest_invite_by_slug($pdo, $slug);
if (!$inv) guest_fail(404, '발행된 청첩장에서만 참석 여부를 받을 수 있어요.');
$fields = guest_block($inv, 'rsvp');
if ($fields === null) guest_fail(403, '참석 여부를 받지 않는 청첩장이에요.');
$id  = (int) $inv['id'];
$key = snap_guest_key();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 연락처는 돌려주지 않는다(다른 사람이 같은 기기를 쓸 수도 있으니) - 대신 적어 둔 적이 있는지만 알려준다
        $st = $pdo->prepare('SELECT side, attend, guest_name, headcount, meal, memo, phone_enc IS NOT NULL AS has_phone FROM rsvp_responses WHERE invitation_id = ? AND guest_key = ?');
        $st->execute([$id, $key]);
        $r = $st->fetch();
        guest_json(['ok' => true, 'closed' => rsvp_closed($fields), 'mine' => $r ? [
            'side' => $r['side'], 'attend' => $r['attend'], 'name' => $r['guest_name'],
            'headcount' => (int) $r['headcount'], 'meal' => $r['meal'], 'memo' => (string) $r['memo'], 'hasPhone' => (bool) $r['has_phone'],
        ] : null]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') guest_fail(405, '허용되지 않는 요청입니다.');
    guest_csrf_check();
    if (rsvp_closed($fields)) guest_fail(403, '참석 여부 전달 기간이 끝났어요.');
    if (!check_rate_limit($pdo, 'rsvp_' . client_ip(), 30, 600)) guest_fail(429, '잠시 후 다시 시도해주세요.');

    $side   = in_array($_POST['side'] ?? '', ['groom', 'bride'], true) ? $_POST['side'] : null;
    $attend = in_array($_POST['attend'] ?? '', ['yes', 'no'], true) ? $_POST['attend'] : null;
    $name   = guest_clean($_POST['name'] ?? '', 20);
    if (!$side)   guest_fail(400, '신랑측/신부측을 골라주세요.');
    if (!$attend) guest_fail(400, '참석 여부를 골라주세요.');
    if ($name === '') guest_fail(400, '성함을 적어주세요.');
    $headcount = $attend === 'yes' ? max(1, min(20, (int) ($_POST['headcount'] ?? 1))) : 0;
    $meal = $attend === 'yes' && in_array($_POST['meal'] ?? '', ['yes', 'no', 'unknown'], true) ? $_POST['meal'] : ($attend === 'yes' ? 'unknown' : 'no');
    $phone = preg_replace('/[^0-9]/', '', (string) ($_POST['phone'] ?? ''));
    if ($phone !== '' && !preg_match('/^0\d{8,10}$/', $phone)) guest_fail(400, '연락처는 숫자만 정확히 적어주세요.');
    $phoneEnc = $phone !== '' && !empty($fields['askPhone']) ? encrypt_data($phone) : null;
    $memo = ($fields['askMemo'] ?? true) ? guest_clean($_POST['memo'] ?? '', 300) : '';

    $pdo->prepare('INSERT INTO rsvp_responses (invitation_id, guest_key, side, attend, guest_name, headcount, meal, phone_enc, memo)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE side = VALUES(side), attend = VALUES(attend), guest_name = VALUES(guest_name),
                       headcount = VALUES(headcount), meal = VALUES(meal), phone_enc = COALESCE(VALUES(phone_enc), phone_enc), memo = VALUES(memo), updated_at = NOW()')
        ->execute([$id, $key, $side, $attend, $name, $headcount, $meal, $phoneEnc, $memo !== '' ? $memo : null]);
    guest_json(['ok' => true]);
} catch (Throwable $e) {
    error_log('[rsvp] ' . $e->getMessage());
    guest_fail(500, '지금은 전달할 수 없어요. 잠시 후 다시 시도해주세요.');
}
