<?php
/**
 * guestbook.php - 방명록 (청첩장의 "방명록" 섹션이 부른다)
 *
 *   GET  guestbook.php?s=공개코드&page=1              → 글 목록 (최신순)
 *   POST guestbook.php  action=write  s, csrf_token, name, pw, message, message_html(글꾸미기 - 서버에서 다시 걸러서 저장)
 *   POST guestbook.php  action=delete s, csrf_token, id, pw   (언제나 글을 쓸 때 정한 비밀번호가 맞아야 삭제)
 *
 * 신랑신부는 관리 화면(guest_manage.php?tab=guestbook)에서 아무 글이나 지울 수 있다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

$pdo  = get_pdo();
$slug = (string) ($_REQUEST['s'] ?? '');
$inv  = guest_invite_by_slug($pdo, $slug);
if (!$inv) guest_fail(404, '발행된 청첩장에서만 방명록을 쓸 수 있어요.');
$fields = guest_block($inv, 'guestbook');
if ($fields === null) guest_fail(403, '방명록을 쓰지 않는 청첩장이에요.');
$id  = (int) $inv['id'];
$key = snap_guest_key();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $size = max(1, min(20, (int) ($fields['pageSize'] ?? 5)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $total = guestbook_count($pdo, $id);
        $htmlCol = gb_has_html($pdo) ? ', message_html' : '';
        $st = $pdo->prepare('SELECT id, author, message, created_at, guest_key' . $htmlCol . ' FROM guestbook_entries
                             WHERE invitation_id = ? ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size));
        $st->execute([$id]);
        $list = array_map(fn($r) => [
            'id' => (int) $r['id'], 'name' => $r['author'], 'message' => $r['message'], 'html' => (string) ($r['message_html'] ?? ''),
            'date' => date('Y.m.d', strtotime((string) $r['created_at'])), 'mine' => hash_equals($r['guest_key'], $key),
        ], $st->fetchAll());
        guest_json(['ok' => true, 'entries' => $list, 'total' => $total, 'page' => $page, 'pageSize' => $size]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') guest_fail(405, '허용되지 않는 요청입니다.');
    guest_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'write') {
        if (($fields['allowWrite'] ?? true) === false) guest_fail(403, '지금은 방명록을 쓸 수 없어요.');
        if (!check_rate_limit($pdo, 'gb_' . client_ip(), 8, 600)) guest_fail(429, '잠시 후 다시 써주세요.');
        $name = guest_clean($_POST['name'] ?? '', 12);
        // 글꾸미기: 신랑신부가 허용했고 테이블에 칸이 있으면 HTML을 서버에서 다시 걸러서 저장, 글자 수는 꾸밈을 뺀 글자로 센다
        $html = (($fields['richText'] ?? true) !== false && gb_has_html($pdo)) ? rt_sanitize((string) ($_POST['message_html'] ?? '')) : '';
        $msg  = $html !== '' ? rt_plain($html) : guest_clean($_POST['message'] ?? '', 400);
        if (mb_strlen($msg) > 300) guest_fail(400, '축하 메시지는 300자까지 쓸 수 있어요.');
        $pw   = (string) ($_POST['pw'] ?? '');
        if ($name === '') guest_fail(400, '이름을 적어주세요.');
        if (mb_strlen($msg) < 2) guest_fail(400, '축하 메시지를 적어주세요.');
        if (!preg_match('/^.{4,20}$/u', $pw)) guest_fail(400, '비밀번호는 4~20자로 정해주세요. (글을 지울 때 필요해요)');
        if (guestbook_count($pdo, $id) >= 2000) guest_fail(403, '방명록이 가득 찼어요.');
        // 금지어 필터 (관리자 설정: 막기 또는 ***로 가리기)
        if (app_setting('gb_filter_mode') === 'mask') {
            $name = gb_mask($name); $msg = gb_mask($msg); $html = gb_mask_html($html);
            if ($html !== '' && gb_find_banned(rt_plain($html)) !== '') $html = ''; // 꾸밈 태그 사이에 쪼개 넣은 금지어 - 꾸밈을 버리고 가린 글자만 저장
        } elseif (gb_find_banned($name . "\n" . $msg) !== '') {
            guest_fail(400, '사용할 수 없는 단어가 들어 있어요. 고운 말로 축하해 주세요 🙏');
        }
        if (gb_has_html($pdo)) {
            $pdo->prepare('INSERT INTO guestbook_entries (invitation_id, guest_key, author, message, message_html, pw_hash) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$id, $key, $name, $msg, $html !== '' ? $html : null, password_hash($pw, PASSWORD_DEFAULT)]);
        } else {
            $pdo->prepare('INSERT INTO guestbook_entries (invitation_id, guest_key, author, message, pw_hash) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $key, $name, $msg, password_hash($pw, PASSWORD_DEFAULT)]);
        }
        guest_json(['ok' => true]);
    }
    if ($action === 'delete') {
        if (!check_rate_limit($pdo, 'gbdel_' . client_ip(), 20, 600)) guest_fail(429, '잠시 후 다시 시도해주세요.');
        $st = $pdo->prepare('SELECT id, guest_key, pw_hash FROM guestbook_entries WHERE id = ? AND invitation_id = ?');
        $st->execute([(int) ($_POST['id'] ?? 0), $id]);
        $row = $st->fetch();
        if (!$row) guest_fail(404, '이미 지워진 글이에요.');
        // 예전엔 "같은 브라우저 표시(guest_key)"가 맞으면 비밀번호 없이 지워졌다 - 표시가 비거나 겹치면 남의 글도 지워질 수 있어서 없앰.
        $pw = (string) ($_POST['pw'] ?? '');
        $ok = $pw !== '' && !empty($row['pw_hash']) && password_verify($pw, (string) $row['pw_hash']);
        if (!$ok) guest_fail(403, '비밀번호가 맞지 않아요.');
        $pdo->prepare('DELETE FROM guestbook_entries WHERE id = ?')->execute([(int) $row['id']]);
        guest_json(['ok' => true]);
    }
    guest_fail(400, '잘못된 요청입니다.');
} catch (Throwable $e) {
    error_log('[guestbook] ' . $e->getMessage());
    guest_fail(500, '지금은 방명록을 불러올 수 없어요. 잠시 후 다시 시도해주세요.');
}
