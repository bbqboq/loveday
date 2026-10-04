<?php
/**
 * lottery.php - 식장 추첨 (섹션 "행운의 추첨")
 *
 * 하객 (청첩장 화면 - invite-blocks.js initLottery)
 *   GET  ?s=코드&a=status                       지금 상태 + 내 응모·당첨 여부 + 당첨자 목록 (몇 초마다 확인)
 *   POST s=코드&a=enter&name=&code=&csrf_token  응모 (현장 코드 방식이면 code 필요)
 *
 * 신랑신부·사회자 (진행 화면 - lottery_manage.php, 편집 토큰 t + CSRF)
 *   GET  ?t=토큰&a=admin                        응모자 전체·현장 코드·상태
 *   POST a=draw&count=&prize=                   추첨 (이미 당첨된 사람은 빼고 무작위)
 *   POST a=manual&value=auto|open|closed        응모 열기/마감
 *   POST a=newcode                              현장 코드 새로 만들기
 *   POST a=reset                                응모·당첨 기록 모두 지우기
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

$pdo = get_pdo();
$a = (string) ($_GET['a'] ?? $_POST['a'] ?? '');

// ---------------------------------------------------------------- 진행 화면 (신랑신부·사회자)
$t = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
if ($t !== '') {
    $invite = snap_owner_invite($pdo, $t);
    if (!$invite) guest_fail(403, '편집 링크가 올바르지 않아요.');
    $id = (int) $invite['id'];
    $blk = lottery_block($invite) ?? ['enabled' => false, 'method' => 'code', 'timeLimit' => true, 'prizes' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        guest_csrf_check();
        if ($a === 'manual') {
            $v = in_array($_POST['value'] ?? '', ['auto', 'open', 'closed'], true) ? $_POST['value'] : 'auto';
            lottery_state($pdo, $id);
            $pdo->prepare('UPDATE lottery_state SET manual = ?, updated_at = NOW() WHERE invitation_id = ?')->execute([$v, $id]);
        } elseif ($a === 'newcode') {
            lottery_state($pdo, $id);
            $pdo->prepare('UPDATE lottery_state SET code = ?, updated_at = NOW() WHERE invitation_id = ?')->execute([str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT), $id]);
        } elseif ($a === 'reset') {
            $pdo->prepare('DELETE FROM lottery_entries WHERE invitation_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE lottery_state SET round_no = 0, updated_at = NOW() WHERE invitation_id = ?')->execute([$id]);
        } elseif ($a === 'draw') {
            $count = max(1, min(30, (int) ($_POST['count'] ?? 1)));
            $prize = guest_clean($_POST['prize'] ?? '', 60) ?: '선물';
            if ($blk['method'] === 'snap') lottery_sync_snap($pdo, $id);
            $pdo->beginTransaction();
            try {
                $state = lottery_state($pdo, $id);
                $st = $pdo->prepare('SELECT id FROM lottery_entries WHERE invitation_id = ? AND won_round IS NULL FOR UPDATE');
                $st->execute([$id]);
                $pool = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                if (!$pool) { $pdo->rollBack(); guest_fail(400, '추첨할 응모자가 없어요.'); }
                // 암호학적 난수로 섞기 (Fisher-Yates + random_int)
                for ($i = count($pool) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]]; }
                $picked = array_slice($pool, 0, $count);
                $round = (int) $state['round_no'] + 1;
                $up = $pdo->prepare('UPDATE lottery_entries SET won_round = ?, prize = ?, won_at = NOW() WHERE id = ?');
                foreach ($picked as $eid) $up->execute([$round, $prize, $eid]);
                $pdo->prepare('UPDATE lottery_state SET round_no = ?, updated_at = NOW() WHERE invitation_id = ?')->execute([$round, $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } else {
            guest_fail(400, '잘못된 요청입니다.');
        }
    }
    // 진행 화면용 전체 정보
    if ($blk['method'] === 'snap') lottery_sync_snap($pdo, $id);
    $state = lottery_state($pdo, $id);
    [$phase, $from, $to] = lottery_phase($invite, $blk, $state);
    $st = $pdo->prepare('SELECT name, entry_no, source, created_at, won_round, prize FROM lottery_entries WHERE invitation_id = ? ORDER BY id DESC');
    $st->execute([$id]);
    guest_json(['ok' => true, 'enabled' => $blk['enabled'], 'method' => $blk['method'], 'timeLimit' => $blk['timeLimit'], 'prizes' => $blk['prizes'],
        'code' => $state['code'], 'manual' => $state['manual'], 'phase' => $phase, 'from' => $from ? $from * 1000 : null, 'to' => $to ? $to * 1000 : null,
        'entries' => array_map(fn($r) => ['name' => $r['name'], 'no' => $r['entry_no'], 'source' => $r['source'], 'won' => $r['won_round'] !== null ? (int) $r['won_round'] : null, 'prize' => $r['prize']], $st->fetchAll()),
        'rounds' => lottery_rounds($pdo, $id, true), 'round' => (int) $state['round_no']]);
}

// ---------------------------------------------------------------- 하객
$invite = guest_invite_by_slug($pdo, (string) ($_GET['s'] ?? $_POST['s'] ?? ''));
$blk = $invite ? lottery_block($invite) : null;
if (!$invite || !$blk || !$blk['enabled']) guest_fail(404, '추첨을 하지 않는 청첩장이에요.');
$id = (int) $invite['id'];
$gk = snap_guest_key();

if ($a === 'enter') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') guest_fail(405, '잘못된 요청입니다.');
    guest_csrf_check();
    if (!check_rate_limit($pdo, 'lot_' . client_ip(), 40, 600)) guest_fail(429, '잠시 후 다시 시도해주세요.');
    if ($blk['method'] === 'snap') guest_fail(400, '게스트스냅에 사진을 올리면 자동으로 응모돼요.');
    $state = lottery_state($pdo, $id);
    [$phase] = lottery_phase($invite, $blk, $state);
    if ($phase === 'before') guest_fail(403, '아직 응모 시간이 아니에요.');
    if ($phase === 'closed') guest_fail(403, '응모가 마감됐어요.');
    if ($blk['method'] === 'code') {
        // 코드를 마구 넣어보는 것 방지: 이 브라우저·IP당 10분에 8번까지
        if (!check_rate_limit($pdo, 'lotc_' . $id . '_' . client_ip(), 8, 600)) guest_fail(429, '코드를 너무 여러 번 틀렸어요. 잠시 후 다시 해주세요.');
        if (!hash_equals((string) $state['code'], preg_replace('/\D/', '', (string) ($_POST['code'] ?? '')))) guest_fail(400, '현장 코드가 달라요. 사회자가 알려준 4자리를 넣어주세요.');
    }
    $name = guest_clean($_POST['name'] ?? '', 20);
    if (mb_strlen($name) < 1) guest_fail(400, '이름을 넣어주세요.');
    if (function_exists('gb_find_banned') && gb_find_banned($name) !== '') guest_fail(400, '이름에 쓸 수 없는 말이 있어요.');
    $pdo->prepare('INSERT IGNORE INTO lottery_entries (invitation_id, guest_key, name, entry_no, source) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $gk, $name, lottery_new_no($pdo, $id), $blk['method']]);
    // 아래 status로 이어서 응답 (이미 응모했으면 예전 응모 그대로)
}

// 상태 (하객 화면이 몇 초마다 부름)
if ($blk['method'] === 'snap') lottery_sync_snap($pdo, $id, $gk);
$state = lottery_state($pdo, $id);
[$phase, $from, $to] = lottery_phase($invite, $blk, $state);
$st = $pdo->prepare('SELECT name, entry_no, won_round, prize FROM lottery_entries WHERE invitation_id = ? AND guest_key = ?');
$st->execute([$id, $gk]);
$me = $st->fetch();
$cnt = $pdo->prepare('SELECT COUNT(*) FROM lottery_entries WHERE invitation_id = ?'); $cnt->execute([$id]);
guest_json(['ok' => true, 'method' => $blk['method'], 'phase' => $phase, 'from' => $from ? $from * 1000 : null, 'to' => $to ? $to * 1000 : null,
    'entries' => (int) $cnt->fetchColumn(), 'round' => (int) $state['round_no'],
    'me' => $me ? ['name' => $me['name'], 'no' => $me['entry_no'], 'won' => $me['won_round'] !== null ? (int) $me['won_round'] : null, 'prize' => $me['prize']] : null,
    'rounds' => lottery_rounds($pdo, $id)]);
