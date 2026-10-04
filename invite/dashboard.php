<?php
/**
 * dashboard.php - 내 청첩장 (예전 "내 청첩장 목록" + "청첩장 관리(invite_edit.php)"를 하나로 합친 화면)
 *
 *  - 네이버로 로그인한 고객: 내 청첩장 전부. PC는 왼쪽 목록 + 오른쪽 상세, 휴대폰은 옆으로 넘기는 카드
 *  - 편집 링크(?t=토큰)로 들어온 사람(VIP 등): 그 청첩장 하나만 (PIN이 걸려 있으면 PIN 확인)
 *  - invite_edit.php?t=… 로 들어와도 이 화면으로 넘어온다
 *
 * 한 청첩장에서 하는 일: 디자인 편집 / 링크 복사·카카오톡 공유·미리보기 / 하객 사진·참석 회신·방명록 숫자와 관리 화면 /
 *                        별칭 / 파일로 다운로드(영구보관) / 삭제(고객 본인 것만, 휴지통으로)
 */
declare(strict_types=1);
require_once __DIR__ . '/gdrive.php';
require_once __DIR__ . '/naver_commerce.php'; // guest_functions(snap_* / rsvp_summary / guestbook_count) + 스마트스토어 주문·다운로드 조건

$pdo = get_pdo();
$customerId = (int) ($_SESSION['customer_id'] ?? 0);
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
if ($token !== '' && !preg_match('/^[a-f0-9]{64}$/', $token)) $token = '';
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

function dash_json(array $d, int $code = 200): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function dash_has_col(PDO $pdo, string $col): bool {
    static $c = [];
    if (!isset($c[$col])) { try { $c[$col] = (bool) $pdo->query("SHOW COLUMNS FROM invitation_orders LIKE " . $pdo->quote($col))->fetch(); } catch (Throwable $e) { $c[$col] = false; } }
    return $c[$col];
}

// ---------------------------------------------------------------- 로그아웃
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']); }
    session_destroy();
    header('Location: /');
    exit;
}

// ---------------------------------------------------------------- 어떤 청첩장들을 보여줄지
$mode = $customerId ? 'customer' : 'token';
$list = [];
if ($mode === 'customer') {
    // 유예 시간까지 지난 무료체험은 여기서도 한 번 정리 (크론이 아직 안 돌았어도 화면에 남지 않게)
    try { member_trial_cleanup($pdo, 20, $customerId); } catch (Throwable $e) {}
    $st = $pdo->prepare('SELECT * FROM invitation_orders WHERE customer_id = ? AND deleted_at IS NULL ORDER BY id DESC');
    $st->execute([$customerId]);
    $list = $st->fetchAll();
    foreach ($list as $o) $_SESSION['pin_ok_' . $o['id']] = true; // 로그인한 본인 청첩장은 PIN 없이 관리 화면(사진·명단)까지 열림
    $cst = $pdo->prepare('SELECT customer_code FROM customers WHERE id = ?'); $cst->execute([$customerId]);
    $customerCode = (string) ($cst->fetchColumn() ?: '');
    // 로그인했는데 남의 편집 링크(?t=)로 들어온 경우: 그 청첩장은 토큰 화면으로 보여준다
    if ($token !== '' && !array_filter($list, fn($o) => hash_equals($o['edit_token'], $token))) { $mode = 'token'; $list = []; }
}
if ($mode === 'token') {
    if ($token === '') { header('Location: /'); exit; }
    $inv = find_invitation_by_token($pdo, $token);
    if (!$inv || !empty($inv['deleted_at'])) { http_response_code(404); exit('유효하지 않은 링크입니다.'); }
    // PIN 보호 (예전 invite_edit.php와 같은 방식)
    $pinKey = 'pin_ok_' . $inv['id'];
    if (!empty($inv['edit_pin_hash']) && empty($_SESSION[$pinKey])) {
        $pinError = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
            csrf_verify($_POST['csrf_token'] ?? null);
            if (password_verify((string) $_POST['pin'], $inv['edit_pin_hash'])) { $_SESSION[$pinKey] = true; header('Location: dashboard.php?t=' . $token); exit; }
            $pinError = 'PIN 번호가 올바르지 않습니다.';
        }
        ?><!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex">
        <title>PIN 확인</title><style>body{margin:0;background:var(--ui-page, #F6F4F1);font-family:"Pretendard Variable",Pretendard,-apple-system,sans-serif;color:#1B1A18;display:flex;min-height:100vh;align-items:center;justify-content:center}
        .b{background:#fff;border:1px solid var(--ui-line, #EAE6E0);border-radius:18px;padding:30px 26px;width:min(340px,90vw);text-align:center}h3{margin:0 0 16px;font-size:17px}
        input{width:100%;box-sizing:border-box;padding:13px;border:1px solid var(--ui-line, #EAE6E0);border-radius:10px;font-size:18px;text-align:center;letter-spacing:.4em}
        button{margin-top:12px;width:100%;padding:13px;border:0;border-radius:10px;background:#1B1A18;color:#fff;font-size:15px;font-weight:600}.e{color:#B24A4A;font-size:13px}</style></head>
        <body><form class="b" method="post"><h3>PIN 번호를 입력해주세요</h3><?php if ($pinError): ?><p class="e"><?= $h($pinError) ?></p><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>"><input type="hidden" name="t" value="<?= $h($token) ?>">
        <input name="pin" maxlength="4" inputmode="numeric" autocomplete="off" required autofocus><button type="submit">확인</button></form></body></html><?php
        exit;
    }
    $list = [$inv];
}

// ---------------------------------------------------------------- 저장 동작 (새로 만들기 / 별칭 / 삭제)
$ownedById = [];
foreach ($list as $o) $ownedById[(int) $o['id']] = $o;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $isAjax = ($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json';
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        $isAjax ? dash_json(['ok' => false, 'error' => '페이지를 새로고침한 뒤 다시 시도해주세요.'], 403) : exit('CSRF 검증에 실패했습니다.');
    }
    if ($action === 'create' && $mode === 'customer') {
        if (count_active_invitations($pdo, $customerId) >= MAX_SLOTS) { header('Location: dashboard.php?full=1'); exit; }
        $newToken = create_new_invitation_for_customer($pdo, $customerId);
        header('Location: editor-prototype-v3-overlay.html?t=' . $newToken);
        exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if (!isset($ownedById[$id])) dash_json(['ok' => false, 'error' => '내 청첩장이 아니에요.'], 403);
    if ($action === 'nickname' && invite_owner_locked($pdo, $ownedById[$id])) dash_json(['ok' => false, 'locked' => true, 'error' => '기간이 끝나 잠긴 청첩장이에요. 결제하면 다시 쓸 수 있어요.'], 403);
    if ($action === 'nickname') {
        if (!dash_has_col($pdo, 'nickname')) dash_json(['ok' => false, 'error' => '별칭 기능을 쓰려면 서버에서 stage4_setup.sql을 다시 실행해야 해요.'], 500);
        $nick = mb_substr(trim(strip_tags((string) ($_POST['nickname'] ?? ''))), 0, 30);
        $pdo->prepare('UPDATE invitation_orders SET nickname = ? WHERE id = ?')->execute([$nick !== '' ? $nick : null, $id]);
        dash_json(['ok' => true, 'nickname' => $nick]);
    }
    if ($action === 'delete' && $mode === 'customer') {
        soft_delete_invitation($pdo, $id); // 휴지통으로 (관리자가 복원 가능)
        header('Location: dashboard.php?deleted=1');
        exit;
    }
    // 디자인 복사 - 같은 디자인·사진·글로 새 청첩장을 하나 더 만든다 (하객 사진·회신·방명록·결제는 옮기지 않음)
    if ($action === 'duplicate' && $mode === 'customer') {
        if (invite_owner_locked($pdo, $ownedById[$id])) dash_json(['ok' => false, 'locked' => true, 'error' => '기간이 끝나 잠긴 청첩장은 복사할 수 없어요. 결제하면 다시 쓸 수 있어요.'], 403);
        if (count_active_invitations($pdo, $customerId) >= MAX_SLOTS) dash_json(['ok' => false, 'full' => true, 'error' => '청첩장은 최대 ' . MAX_SLOTS . '개까지 만들 수 있어요. 안 쓰는 청첩장을 지운 뒤 다시 복사해주세요.'], 409);
        if (!check_rate_limit($pdo, 'dup_' . $customerId, 10, 3600)) dash_json(['ok' => false, 'error' => '복사를 너무 자주 했어요. 잠시 뒤에 다시 시도해주세요.'], 429);
        try { $newId = dash_duplicate($pdo, $ownedById[$id], $customerId); }
        catch (Throwable $e) { error_log('dashboard duplicate: ' . $e->getMessage()); dash_json(['ok' => false, 'error' => '복사하지 못했어요. 잠시 뒤 다시 시도해주세요.'], 500); }
        dash_json(['ok' => true, 'id' => $newId]);
    }
    dash_json(['ok' => false, 'error' => '잘못된 요청입니다.'], 400);
}

/**
 * 청첩장 복사: 새 무료체험 청첩장을 만들고 디자인(design_json)·계좌·이름/예식 정보·사진·배경음악을 옮긴다.
 *  - 사진은 새 청첩장 폴더로 복사하고, 디자인 안의 주소(uploads/원래번호/...)를 새 번호로 바꾼다
 *  - 복사본은 무료체험이라 공개용 사진은 원본(master)에서 다시 뽑아 워터마크를 넣는다
 *  - 무료체험을 복사하면 남은 기간을 그대로 이어받는다 (복사로 체험 기간이 늘어나지 않게). 결제한 청첩장의 복사본은 새 무료체험
 *  - 하객 사진·참석 회신·방명록·신혼여행·추첨·결제·PIN·다운로드 횟수는 옮기지 않는다
 */
function dash_duplicate(PDO $pdo, array $src, int $customerId): int
{
    $srcId = (int) $src['id'];
    $newToken = create_new_invitation_for_customer($pdo, $customerId);
    $new = find_invitation_by_token($pdo, $newToken);
    if (!$new) throw new RuntimeException('new invitation not found');
    $newId = (int) $new['id'];
    try {
        // 1) 디자인 안의 업로드 주소를 새 번호로 바꾸면서, 쓰고 있는 파일 이름을 모은다
        $files = [];
        $design = null;
        if (trim((string) ($src['design_json'] ?? '')) !== '') {
            $design = json_decode((string) $src['design_json'], true);
            if (!is_array($design)) throw new RuntimeException('design_json decode failed');
            $re = '#(^|/)((?:invite/)?uploads/)' . $srcId . '/([A-Za-z0-9_]{1,80}\.(?:webp|jpe?g|png|gif|mp3|m4a))#';
            array_walk_recursive($design, function (&$v) use ($re, $newId, &$files) {
                if (!is_string($v) || !str_contains($v, 'uploads/')) return;
                $v = preg_replace_callback($re, function ($m) use ($newId, &$files) { $files[$m[3]] = true; return $m[1] . $m[2] . $newId . '/' . $m[3]; }, $v);
            });
        }
        // 2) 파일 복사 (사진: 원본은 그대로, 공개용은 워터마크) + 사진 목록
        $sd = invitation_upload_dir($srcId); $nd = invitation_upload_dir($newId);
        foreach ([$nd, $nd . 'master/'] as $dir) if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('mkdir failed');
        $sortQ = $pdo->prepare('SELECT sort_order FROM invitation_photos WHERE invitation_id = ? AND file_path = ? LIMIT 1');
        $insP = $pdo->prepare('INSERT INTO invitation_photos (invitation_id, file_path, sort_order) VALUES (?, ?, ?)');
        $srcWm = ($src['storage_plan'] ?? 'trial') === 'trial'; // 원래 공개용 사진에 이미 워터마크가 있는지
        $bgm = null;
        foreach (array_keys($files) as $name) {
            $name = basename($name);
            if (!is_file($sd . $name) && !is_file($sd . 'master/' . $name)) continue; // 지워진 사진은 건너뜀
            if (str_starts_with($name, 'bgm_')) { if (@copy($sd . $name, $nd . $name)) $bgm = $name; continue; }
            $master = is_file($sd . 'master/' . $name) ? $sd . 'master/' . $name : $sd . $name;
            if (!@copy($master, $nd . 'master/' . $name)) throw new RuntimeException('copy failed');
            $done = false;
            if (str_ends_with($name, '.webp') && !($srcWm && $master === $sd . $name)) {
                $im = @imagecreatefromwebp($master);
                if ($im) { apply_watermark($im); $done = imagewebp($im, $nd . $name, 82); imagedestroy($im); }
            }
            if (!$done && !@copy(is_file($sd . $name) ? $sd . $name : $master, $nd . $name)) throw new RuntimeException('copy failed');
            $sortQ->execute([$srcId, $name]);
            $insP->execute([$newId, $name, (int) ($sortQ->fetchColumn() ?: 0)]);
        }
        // 배경음악 동의 기록도 같이 (표가 없으면 건너뜀)
        if ($bgm !== null) {
            try {
                $pdo->prepare("INSERT INTO music_uploads (invitation_id, file_name, orig_name, orig_size, size_bytes, agreed_at, ip_hash, status)
                    SELECT ?, ?, orig_name, orig_size, size_bytes, agreed_at, ip_hash, 'active' FROM music_uploads WHERE invitation_id = ? AND file_name = ? ORDER BY id DESC LIMIT 1")
                    ->execute([$newId, $bgm, $srcId, $bgm]);
            } catch (Throwable $e) {}
        }
        // 3) 청첩장 정보 옮기기
        $nick = trim((string) ($src['nickname'] ?? ''));
        $nick = mb_substr(($nick !== '' ? $nick . ' ' : '') . '복사본', 0, 30);
        $set = ['design_json = ?', 'groom_name = ?', 'bride_name = ?', 'venue_name = ?', 'venue_address = ?', 'wedding_datetime = ?', 'account_info_enc = ?'];
        $val = [$design !== null ? json_encode($design, JSON_UNESCAPED_UNICODE) : ($src['design_json'] ?? null), (string) ($src['groom_name'] ?? ''), (string) ($src['bride_name'] ?? ''),
                $src['venue_name'] ?? null, $src['venue_address'] ?? null, $src['wedding_datetime'] ?? null, $src['account_info_enc'] ?? null];
        if (dash_has_col($pdo, 'nickname')) { $set[] = 'nickname = ?'; $val[] = $nick; }
        // 무료체험의 복사본은 원래 남은 기간까지만
        if (($src['storage_plan'] ?? '') === 'trial' && !empty($src['expires_at'])) { $set[] = 'expires_at = LEAST(expires_at, ?)'; $val[] = $src['expires_at']; }
        $val[] = $newId;
        $pdo->prepare('UPDATE invitation_orders SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($val);
    } catch (Throwable $e) {
        try { $pdo->prepare('DELETE FROM invitation_photos WHERE invitation_id = ?')->execute([$newId]); permanently_delete_invitation($pdo, $newId); } catch (Throwable $e2) {}
        throw $e;
    }
    return $newId;
}

// ---------------------------------------------------------------- 화면에 쓸 정보 만들기
function dash_first_line($s): string { $s = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $s))); return trim(explode("\n", $s)[0]); }
function dash_img_ok($u, int $id): string {
    $u = (string) $u;
    if (preg_match('#^/?(?:invite/)?uploads/' . $id . '/[a-f0-9]{32}\.webp$#', $u)) return '/invite/' . preg_replace('#^/?(?:invite/)?#', '', $u);
    if (preg_match('#^https://[^\s"\'<>]+$#', $u)) return $u; // 프리셋 예시 사진 등
    return '';
}
/** 스마트스토어 주문 상태 - 결제 처리됐는지, 취소로 되돌렸는지 (고객 안내용) */
function dash_naver_info(PDO $pdo, int $id): array
{
    try {
        $st = $pdo->prepare('SELECT product_order_id, result, grace_given, reverted_at, claim, message FROM naver_orders WHERE invitation_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 5');
        $st->execute([$id]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) { return ['paid' => false, 'revert' => null]; }
    $paid = (bool) array_filter($rows, fn($r) => in_array($r['result'], ['applied', 'exchange_hold'], true));
    $rev = null;
    foreach ($rows as $r) if ($r['result'] === 'reverted') { $rev = ['id' => $r['product_order_id'], 'at' => $r['reverted_at'], 'grace' => str_contains((string) $r['message'], '시간 추가'), 'exchange' => str_contains((string) $r['claim'], 'EXCHANGE')]; break; }
    return ['paid' => $paid, 'revert' => $paid ? null : $rev];
}
function dash_card(PDO $pdo, array $o): array
{
    $id = (int) $o['id'];
    $d = snap_design($o) ?? [];
    $blocks = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
    $blk = function (string $bid) use ($blocks) { foreach ($blocks as $b) if (($b['id'] ?? '') === $bid) return $b; return null; };
    // 이름: 에디터 "예식 정보"에 적은 이름 → 대표 화면(메인 사진/영상)에 적은 이름 → 주문 정보 (주문 때 적은 이름은 나중에 고쳐도 안 바뀌므로 맨 마지막)
    $info = is_array($d['info'] ?? null) ? $d['info'] : [];
    $g = dash_first_line($info['groom'] ?? ''); $b = dash_first_line($info['bride'] ?? '');
    if ($g === '' || $b === '') {
        $hb = null; foreach (['heroVideo', 'hero'] as $hid) { $x = $blk($hid); if ($x && !empty($x['enabled'])) { $hb = $x; break; } }
        $hb = $hb ?: ($blk('hero') ?: $blk('heroVideo'));
        if ($hb) { $g = $g ?: dash_first_line($hb['fields']['groomName'] ?? ''); $b = $b ?: dash_first_line($hb['fields']['brideName'] ?? ''); }
    }
    $g = $g ?: trim((string) $o['groom_name']); $b = $b ?: trim((string) $o['bride_name']);
    $named = ($g !== '' && $b !== '' && !($g === '신랑' && $b === '신부'));
    // 예식일: 에디터 "예식 정보" → 주문 정보 → 켜 둔 디데이 섹션 (꺼진 섹션의 예시 날짜는 쓰지 않음)
    $w = null;
    if (!empty($info['date']) && preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', (string) $info['date'], $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1])) {
        $tm = preg_match('/^(\d{1,2}):(\d{2})/', (string) ($info['time'] ?? ''), $tt) ? [(int) $tt[1], (int) $tt[2]] : [12, 0];
        $w = (new DateTimeImmutable())->setDate((int) $dm[1], (int) $dm[2], (int) $dm[3])->setTime(min(23, $tm[0]), min(59, $tm[1]));
    }
    elseif (!empty($o['wedding_datetime'])) $w = new DateTimeImmutable((string) $o['wedding_datetime']);
    else { $dd = $blk('dday'); if ($dd && !empty($dd['enabled']) && !empty($dd['fields']['year'])) { $f = $dd['fields']; $w = (new DateTimeImmutable())->setDate((int) $f['year'], (int) $f['month'], (int) $f['day'])->setTime((int) ($f['hour'] ?? 12), (int) ($f['minute'] ?? 0)); } }
    $dday = null; $dateText = '';
    if ($w) {
        $dday = (int) round(((new DateTimeImmutable($w->format('Y-m-d')))->getTimestamp() - (new DateTimeImmutable('today'))->getTimestamp()) / 86400);
        $yo = ['일', '월', '화', '수', '목', '금', '토'][(int) $w->format('w')];
        $hr = (int) $w->format('G'); $mi = (int) $w->format('i');
        $dateText = $w->format('Y. n. j') . ' ' . $yo . ' ' . ($hr < 12 ? '오전 ' : '오후 ') . (($hr % 12) ?: 12) . '시' . ($mi ? ' ' . $mi . '분' : '');
    }
    // 예식장: 켜 둔 오시는 길 섹션에 적은 이름 → 주문 정보
    $venue = ''; $lb = $blk('location'); if ($lb && !empty($lb['enabled'])) $venue = dash_first_line($lb['fields']['venue'] ?? '');
    if ($venue === '') $venue = trim((string) ($o['venue_name'] ?? ''));
    // 표지 사진: 대표사진 → 갤러리 첫 사진 → 없음(디자인 색)
    $cover = '';
    foreach (['hero'] as $hid) { $hb = $blk($hid); if ($hb) $cover = dash_img_ok($hb['fields']['heroImage'] ?? '', $id); }
    if ($cover === '') { $gb = $blk('gallery'); foreach ((array) ($gb['fields']['images'] ?? []) as $im) { $cover = dash_img_ok(is_array($im) ? ($im['src'] ?? '') : $im, $id); if ($cover !== '') break; } }
    $pal = is_array($d['palette'] ?? null) ? $d['palette'] : [];
    $tint = preg_match('/^#[0-9a-f]{6}$/i', (string) ($pal['accent'] ?? '')) ? $pal['accent'] : '#8A4B55';
    // 상태·보관 - 시각은 DB가 직접 초로 바꿔 준 값으로 비교 (PHP와 DB 시간대가 어긋나도 정확)
    $tsq = $pdo->prepare('SELECT UNIX_TIMESTAMP(expires_at) AS e, UNIX_TIMESTAMP(created_at) AS c, UNIX_TIMESTAMP() AS n FROM invitation_orders WHERE id = ?');
    $tsq->execute([$id]);
    $ts = $tsq->fetch() ?: ['e' => null, 'c' => null, 'n' => time()];
    $now = (int) $ts['n'];
    $expTs = $ts['e'] !== null ? (int) $ts['e'] : null;
    $expired = $o['status'] === 'expired' || ($expTs !== null && $expTs < $now);
    $plan = (string) $o['storage_plan'];
    $daysLeft = $expTs !== null ? max(0, (int) ceil(($expTs - $now) / 86400)) : null;
    // 네이버 로그인 회원의 무료체험은 기간이 끝나도 유예 시간(기본 24시간) 뒤에 휴지통으로 - 그때까지 "삭제까지" 시계
    $purgeTs = ($plan === 'trial' && !empty($o['customer_id']) && $expTs !== null) ? $expTs + trial_grace_hours() * 3600 : null;
    $isDemo = defined('DEMO_MEMO') && str_starts_with((string) ($o['order_memo'] ?? ''), DEMO_MEMO);
    $planText = ['trial' => '무료체험' . ($expired ? ' · 기간 끝남' : ($daysLeft !== null ? ' · ' . $daysLeft . '일 남음' : '')), 'one_year' => '1년 보관', 'permanent' => '영구 보관'][$plan] ?? $plan;
    // 숫자 (테이블이 없으면 0)
    $snap = 0; try { $snap = snap_usage($pdo, $id)['count']; } catch (Throwable $e) {}
    $rs = rsvp_summary($pdo, $id);
    $tk = rawurlencode($o['edit_token']);
    return [
        'id' => $id, 'nick' => (string) ($o['nickname'] ?? ''), 'named' => $named,
        'title' => $named ? "{$g} ♥ {$b}" : '이름 없는 청첩장',
        'created' => date('n월 j일', strtotime((string) $o['created_at'])) . ' 만듦',
        'dday' => $dday, 'date' => $dateText, 'venue' => $venue,
        'cover' => $cover, 'tint' => $tint,
        'expTs' => $expTs ? $expTs * 1000 : null, 'startTs' => ((int) ($ts['c'] ?? $now)) * 1000, 'demo' => $isDemo,
        'purgeTs' => $purgeTs ? $purgeTs * 1000 : null, 'graceH' => trial_grace_hours(),
        'status' => $expired ? 'expired' : $o['status'], 'plan' => $plan, 'planText' => $planText, 'warn' => $plan === 'trial',
        'published' => $o['status'] === 'published' && !$expired,
        'slug' => $o['view_slug'], 'publicUrl' => 'https://loveday.kr/' . $o['view_slug'], 'token' => $o['edit_token'],
        'snap' => $snap, 'snapOn' => snap_enabled($o),
        'rsvp' => $rs['people'], 'rsvpText' => $rs['total'] ? "신랑측 {$rs['groomPeople']} · 신부측 {$rs['bridePeople']} · 식사 {$rs['mealYes']} · 불참 {$rs['no']}" : '아직 받은 회신이 없어요',
        'gb' => guestbook_count($pdo, $id),
        'exportLeft' => $plan === 'permanent' ? max(0, 3 - (int) ($o['export_count'] ?? 0)) : null,
        'exportGate' => $plan === 'permanent' ? nc_export_gate($pdo, $o) : null, // 구매확정 뒤, 예식 전날부터
        // 구글 드라이브 (관리자가 켰을 때만): linked = 이 청첩장에 드라이브 연결됨, dl = 기기로 바로 받기 허용
        'gd' => ['on' => gdrive_on(), 'linked' => gdrive_on() && (bool) gdrive_link($pdo, $id), 'dl' => export_server_download_on()],
        'naver' => dash_naver_info($pdo, $id),
        'url' => [
            'edit' => 'editor-prototype-v3-overlay.html?t=' . $tk, 'preview' => 'invite_view.php?preview_t=' . $tk,
            'snap' => 'snap_manage.php?t=' . $tk, 'rsvp' => 'guest_manage.php?t=' . $tk . '&tab=rsvp', 'gb' => 'guest_manage.php?t=' . $tk . '&tab=guestbook',
            'export' => 'invite_export.php?t=' . $tk,
            'trip' => 'trip_manage.php?t=' . $tk, 'lottery' => 'lottery_manage.php?t=' . $tk, 'qr' => 'qr.php?t=' . $tk, 'anniv' => 'anniversary.php?t=' . $tk,
        ],
    ];
}
$cards = array_map(fn($o) => dash_card($pdo, $o), $list);
$selected = 0;
if ($token !== '') foreach ($list as $i => $o) if (hash_equals($o['edit_token'], $token)) $selected = $i;
if (isset($_GET['id'])) foreach ($cards as $i => $c) if ($c['id'] === (int) $_GET['id']) $selected = $i;
$slots = $mode === 'customer' ? count_active_invitations($pdo, $customerId) : 0;
$withdrawUrl = '';
foreach (['withdraw.php', 'member_withdraw.php', 'account_delete.php', 'leave.php'] as $f) if (is_file(__DIR__ . '/' . $f)) { $withdrawUrl = $f; break; }
$csrf = csrf_token();
$flash = ''; $flashLink = null; $flashOk = false; // 위쪽 안내 띠 (문구, [주소, 버튼 글자], 초록색 여부)
if (isset($_GET['deleted'])) $flash = '청첩장을 삭제했어요. 실수였다면 고객센터로 알려주세요 (휴지통에서 되살릴 수 있어요).';
elseif (isset($_GET['copied']) && $mode === 'customer') { $flashOk = true; $flash = '디자인을 복사해 새 청첩장을 만들었어요. 이름 옆에 "복사본" 별칭이 붙어 있어요.'; }
elseif (isset($_GET['full'])) $flash = '청첩장은 최대 ' . MAX_SLOTS . '개까지 만들 수 있어요. 안 쓰는 청첩장을 지운 뒤 다시 만들어주세요.';
elseif (isset($_GET['claimed']) && $mode === 'customer') { $flashOk = true; $flash = '둘러보기로 만들던 청첩장을 내 계정에 저장했어요. 무료체험으로 바뀌어서 ' . DEMO_CLAIM_TRIAL_DAYS . '일 동안 이어서 만들 수 있어요.'; }
elseif (isset($_GET['demo_full'])) { $flash = '둘러보기로 만든 청첩장을 옮기지 못했어요. 청첩장은 최대 ' . MAX_SLOTS . '개까지라, 안 쓰는 청첩장을 하나 지운 뒤 다시 눌러주세요.'; if (!empty($_SESSION['demo_token'])) $flashLink = ['claim_demo.php', '다시 옮기기']; }
elseif (isset($_GET['demo_gone'])) $flash = '둘러보기 청첩장은 보관 시간이 지나 삭제되어 옮길 수 없어요.';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>내 청첩장 · LOVE DAY</title>
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<style>
:root{--bg:var(--ui-page, #F6F4F1);--surface:#fff;--ink:#1B1A18;--sub:#6F6A63;--faint:#A39D95;--line:var(--ui-line, #EAE6E0);--soft:#F1EEEA;--accent:#8A4B55;--accent-soft:#F4ECEC;--ok:#3E8A68;--warn:#C7823A;--idle:#B9B2A8;--danger:#B24A4A}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--ink);font-family:"Pretendard Variable",Pretendard,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button{font-family:inherit}
svg.i{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round;flex:none}
.top{height:60px;display:flex;align-items:center;gap:14px;padding:0 24px;background:var(--surface);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:20}
.logo{font-weight:800;letter-spacing:.2em;font-size:13px}.logo span{color:var(--accent)}
.sp{flex:1}
.who{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--sub)}
.nv{width:18px;height:18px;border-radius:5px;background:#03C75A;color:#fff;font:800 11px/18px sans-serif;text-align:center}
.ghost{border:1px solid var(--line);background:var(--surface);color:var(--sub);border-radius:8px;padding:7px 12px;font-size:12.5px;cursor:pointer}
.linklike{background:none;border:0;color:var(--faint);font-size:12px;cursor:pointer;padding:0}
.flash{max-width:900px;margin:14px auto 0;padding:11px 16px;border-radius:12px;background:#FFF6E5;color:#8A5A00;font-size:13.5px}
.flash.ok{background:#EAF6EE;color:#2F6B45}.flash a{margin-left:6px;font-weight:700;color:inherit;text-decoration:underline;text-underline-offset:3px}
/* status */
.st{display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--sub);font-weight:500;white-space:nowrap}
.st::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--idle)}
.st.ok::before{background:var(--ok)}.st.warn::before{background:var(--warn)}.st.bad::before{background:var(--danger)}
.dday{font-weight:700;font-size:12px;line-height:1;color:var(--accent);background:var(--accent-soft);padding:5px 8px;border-radius:6px;letter-spacing:.02em;white-space:nowrap}
.dday.mute{color:var(--sub);background:var(--soft)}
.dot-sep{color:var(--faint)}
.cv{border-radius:12px;position:relative;overflow:hidden;flex:none;background-size:cover;background-position:center}
.cv.noimg{display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.9)}
.cv .gl{position:absolute;inset:0;background:radial-gradient(circle at 72% 22%,rgba(255,255,255,.3),transparent 45%)}
.b1{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;background:var(--ink);color:#fff;border-radius:10px;padding:11px 18px;font-weight:600;font-size:13.5px;cursor:pointer}
.b1[aria-disabled=true]{opacity:.4;pointer-events:none}
.b2{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:10px;padding:10px 14px;font-weight:500;font-size:13px;cursor:pointer}
.kk{background:#FEE500;border-color:#FEE500;color:#191600}
.toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,20px);opacity:0;background:rgba(27,26,24,.92);color:#fff;font-size:13.5px;padding:11px 18px;border-radius:999px;transition:.25s;z-index:99;pointer-events:none;max-width:90vw;text-align:center}
.toast.show{opacity:1;transform:translate(-50%,0)}
/* ================= PC (900px 이상) ================= */
.pc{display:flex;min-height:calc(100vh - 60px)}
.side{width:300px;background:var(--surface);border-right:1px solid var(--line);padding:22px 16px;display:flex;flex-direction:column;position:sticky;top:60px;height:calc(100vh - 60px);overflow-y:auto}
.side-h{display:flex;align-items:center;gap:8px;padding:0 6px 14px}.side-h b{font-size:15px}.side-h small{color:var(--faint);font-size:12px}
.newbtn{margin-left:auto;display:inline-flex;align-items:center;gap:4px;border:0;background:var(--ink);color:#fff;border-radius:8px;padding:7px 11px;font-weight:600;font-size:12.5px;cursor:pointer}
.newbtn[disabled]{opacity:.35;cursor:default}
.item{display:flex;gap:12px;align-items:center;padding:10px;border-radius:12px;margin-bottom:4px;position:relative;cursor:pointer;width:100%;border:0;background:none;text-align:left;color:inherit}
.item:hover{background:#FAF9F7}.item.on{background:var(--soft)}
.item.on::before{content:'';position:absolute;left:-16px;top:14px;bottom:14px;width:3px;border-radius:0 3px 3px 0;background:var(--accent)}
.item h4{margin:0 0 5px;font-size:14px;font-weight:600;letter-spacing:-.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px}
.item h4.none{color:var(--faint);font-weight:500}
.item .m{display:flex;gap:8px;align-items:center;font-size:12px;color:var(--sub)}
.slots{margin-top:auto;padding:14px 8px 0;border-top:1px solid var(--line);font-size:12px;color:var(--faint);display:flex;align-items:center;gap:10px}
.bar{flex:1;height:4px;border-radius:2px;background:var(--soft);overflow:hidden}.bar i{display:block;height:100%;background:var(--ink)}
.main{flex:1;padding:30px 36px 60px;max-width:1040px}
.hero{display:flex;gap:24px;align-items:flex-end;padding-bottom:24px;border-bottom:1px solid var(--line);margin-bottom:22px}
.hero h1{margin:8px 0 10px;font-size:28px;letter-spacing:-.02em;font-weight:700;line-height:1.25}
.hero h1.none{color:var(--faint);font-weight:600}
.hero .nick{font-size:13px;color:var(--faint);font-weight:500;margin-left:8px}
.hero .meta{display:flex;align-items:center;gap:10px;font-size:13px;color:var(--sub);flex-wrap:wrap}
.acts{display:flex;gap:8px;margin-top:18px;flex-wrap:wrap}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.box{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:18px 18px 16px;display:flex;flex-direction:column}
.box .h{display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--sub);font-weight:600}
.box .n{font-size:30px;font-weight:700;letter-spacing:-.02em;margin:12px 0 4px}.box .n small{font-size:14px;color:var(--faint);font-weight:500;margin-left:3px}
.box .d{font-size:12px;color:var(--faint);line-height:1.6;flex:1}
.box .f{display:flex;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--soft);font-size:12.5px}
.box .f a{margin-left:auto;color:var(--ink);font-weight:600;display:inline-flex;align-items:center;gap:3px}
.url{display:flex;align-items:center;gap:10px;margin-top:14px;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px 16px;font-size:12.5px;color:var(--sub);flex-wrap:wrap}
.url code{font:500 13px ui-monospace,SFMono-Regular,Menlo,monospace;color:var(--ink)}
.url .links{margin-left:auto;display:flex;gap:6px;align-items:center}
.url .cp{border:0;background:var(--soft);color:var(--sub);border-radius:6px;padding:4px 8px;font-size:11.5px;cursor:pointer}.url .cp:hover{color:var(--ink)}
/* 작은 버튼 - 별칭 / 다운로드 / 삭제 */
.sbtn{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--line);background:var(--surface);color:var(--sub);border-radius:9px;padding:7px 11px;font-size:12.5px;font-weight:500;cursor:pointer;white-space:nowrap;transition:background .15s,color .15s,border-color .15s}
.sbtn svg.i{width:14px;height:14px}
.sbtn:hover{border-color:#D6D0C8;color:var(--ink)}
.sbtn.del{color:var(--danger);border-color:#EFCACA;background:#FFF7F6;font-weight:600}
.sbtn.del:hover{background:var(--danger);border-color:var(--danger);color:#fff}
.sbtn.off{color:var(--faint);cursor:default;background:var(--soft)}
/* 자동 삭제까지 남은 시간 */
.timer{display:flex;align-items:center;gap:16px;margin:0 0 18px;padding:16px 20px;border-radius:16px;background:#FFF6E5;border:1px solid #F2DDB2;color:#7A4F00}
.timer.urgent{background:#FDEDEC;border-color:#F2C7C4;color:#9A3434}
.timer.done{background:var(--soft);border-color:var(--line);color:var(--sub)}
.timer .tic{width:44px;height:44px;border-radius:13px;background:#fff;display:flex;align-items:center;justify-content:center;flex:none;box-shadow:0 1px 0 rgba(0,0,0,.04)}
.timer .tic svg.i{width:22px;height:22px}
.timer.urgent .tic svg{animation:tick 1s steps(2) infinite}
@keyframes tick{50%{transform:rotate(8deg)}}
.timer .tm{min-width:0}
.timer .tm small{display:block;font-size:12px;font-weight:600;opacity:.85;margin-bottom:2px}
.timer .clock{font-size:26px;font-weight:800;letter-spacing:-.02em;line-height:1.15;font-variant-numeric:tabular-nums;white-space:nowrap}
.timer .clock u{text-decoration:none;font-size:14px;font-weight:600;margin:0 7px 0 2px;opacity:.85}
.timer .prog{height:4px;border-radius:2px;background:rgba(0,0,0,.08);margin-top:8px;overflow:hidden;width:220px;max-width:100%}
.timer .prog i{display:block;height:100%;background:currentColor;border-radius:2px;transition:width 1s linear}
.timer .tr{margin-left:auto;text-align:right;font-size:12.5px;line-height:1.65;max-width:460px;word-break:keep-all;text-wrap:balance}
.timer .tr p{margin:0 0 8px}
.timer .tr p.two span{display:block}
.timer.grace .tr{max-width:none}
@media (min-width:1180px){.timer .tr p.two span:first-child{white-space:nowrap}}
.timer .code{white-space:nowrap;display:inline-flex;align-items:center;gap:6px;border:1px dashed currentColor;background:#fff;color:inherit;border-radius:9px;padding:6px 11px;font:700 13px ui-monospace,SFMono-Regular,Menlo,monospace;cursor:pointer;letter-spacing:.02em}
.timer .code span{font:600 11.5px "Pretendard Variable",Pretendard,sans-serif;opacity:.8}
.timer .paybtn{white-space:nowrap;border:0;border-radius:10px;background:#03C75A;color:#fff;font:700 13.5px "Pretendard Variable",Pretendard,sans-serif;padding:9px 16px;cursor:pointer;box-shadow:0 4px 12px rgba(3,199,90,.25)}
.timer .paybtn:hover{filter:brightness(1.05)}
/* 결제 창 */
.pay{position:fixed;inset:0;z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(27,26,24,.45);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}
.pay[hidden]{display:none}
.pay-box{position:relative;width:min(420px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:22px;padding:26px 22px 20px;box-shadow:0 30px 80px rgba(30,20,15,.28);animation:payIn .25s cubic-bezier(.2,.9,.3,1.2)}
@keyframes payIn{from{transform:translateY(12px) scale(.97);opacity:0}}
.pay-x{position:absolute;top:12px;right:12px;width:34px;height:34px;border:0;border-radius:50%;background:var(--soft);cursor:pointer;font-size:14px}
.pay h3{margin:0 0 4px;font-size:19px;letter-spacing:-.02em}
.pay .sub{word-break:keep-all;margin:0 0 18px;font-size:13px;color:var(--sub)}
.pay .pstep{display:flex;gap:10px;align-items:flex-start;margin:0 0 14px}
.pay .pstep i{flex:none;width:22px;height:22px;border-radius:50%;background:var(--ink);color:#fff;font:700 12px/22px sans-serif;text-align:center;font-style:normal}
.pay .pstep > div{flex:1;min-width:0}.pay .pstep > div > b{display:block;font-size:14px;margin-bottom:6px}
.pay .plans{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.pay .plans button{border:1.5px solid var(--line);background:#fff;border-radius:14px;padding:12px 10px;text-align:left;cursor:pointer;font:inherit}
.pay .plans button.on{border-color:var(--ink);box-shadow:0 0 0 1px var(--ink)}
.pay .plans small{display:block;font-size:11.5px;color:var(--sub)}.pay .plans strong{font-size:16px}
.pay .codebox{display:flex;align-items:center;gap:8px;border:1.5px dashed #03C75A;background:#F2FBF6;border-radius:12px;padding:10px 12px}
.pay .codebox code{flex:1;font:800 20px ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.06em;color:#0A7A3E}
.pay .codebox button{border:0;background:#03C75A;color:#fff;border-radius:8px;padding:7px 10px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer}
.pay .hint{word-break:keep-all;margin:6px 0 0;font-size:12px;color:var(--sub);line-height:1.6}
.pay .go{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin:6px 0 0;padding:15px;border:0;border-radius:14px;background:#03C75A;color:#fff;font:inherit;font-size:16px;font-weight:800;cursor:pointer}
.pay .go .n{width:22px;height:22px;border-radius:6px;background:#fff;color:#03C75A;font:900 13px/22px sans-serif;text-align:center}
.pay .foot{word-break:keep-all;margin:12px 0 0;font-size:11.5px;color:var(--faint);text-align:center;line-height:1.6}
.pay .wait{text-align:center;padding:10px 0 4px}
.pay .spin{width:42px;height:42px;margin:4px auto 14px;border-radius:50%;border:4px solid #DDF3E6;border-top-color:#03C75A;animation:paySpin 1s linear infinite}
@keyframes paySpin{to{transform:rotate(360deg)}}
.pay .wait p{word-break:keep-all;margin:0 0 8px;font-size:14px;line-height:1.7}
.pay .wait .btns{display:flex;gap:8px;justify-content:center;margin-top:14px}
.pay .wait .btns button{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 14px;font:inherit;font-size:13px;cursor:pointer}
.pay .done{font-size:44px;margin:0 0 6px}
.paynote{display:flex;gap:8px;align-items:flex-start;margin-top:10px;padding:10px 14px;border-radius:12px;background:var(--soft);color:var(--sub);font-size:12.5px;line-height:1.6}
.paynote svg.i{margin-top:2px;flex:none}.paynote b{color:var(--ink);font-weight:600}
.sbtn.off[onclick]{cursor:pointer}
.tools{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0 0} /* 위 숫자 카드(.grid)와 같은 간격 */
.tool{display:flex;flex-direction:column;align-items:flex-start;gap:2px;padding:14px 14px 12px;border:1px solid var(--line);border-radius:16px;background:var(--surface);text-decoration:none;color:var(--ink);transition:border-color .15s,transform .15s;cursor:pointer}
.tool:hover{border-color:#D6D0C8;transform:translateY(-1px)}
.tool .ti{width:34px;height:34px;border-radius:10px;background:var(--soft);display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:8px;color:#C0566B}
.tool b{font-size:13.5px;letter-spacing:-.01em}.tool small{font-size:11.5px;color:var(--faint)}
.tool.off{opacity:.55}
@media (max-width:520px){.tools{gap:7px;grid-template-columns:repeat(2,1fr)}.tool{padding:11px 9px 10px}.tool b{font-size:12.5px}.tool small{font-size:10.5px}}
.lockz{position:relative;cursor:pointer}
.lockz>*{pointer-events:none;opacity:.4;filter:grayscale(.9)}
.lockz::after{content:attr(data-lock);position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);background:rgba(27,26,24,.88);color:#fff;font-size:12.5px;font-weight:600;padding:7px 14px;border-radius:999px;white-space:nowrap;pointer-events:none;box-shadow:0 8px 20px rgba(0,0,0,.18)}
.lockz.quiet::after{display:none}
.warnbox{margin-top:14px;padding:12px 16px;border-radius:12px;background:#FFF6E5;color:#8A5A00;font-size:13px;line-height:1.6}
/* ================= 휴대폰 (900px 미만) - 옆으로 넘기는 카드 ================= */
.mo{display:none}
@media (max-width:899px){
  .pc{display:none}.mo{display:block}
  .top{padding:0 18px}.top .who .code{display:none}
  .mttl{display:flex;align-items:baseline;gap:6px;padding:16px 20px 12px}.mttl b{font-size:20px;letter-spacing:-.02em}.mttl small{color:var(--faint);font-size:12px}
  .mttl .cnt{margin-left:auto;font-size:12px;color:var(--sub)}
  .track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding:4px 20px 8px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
  .track::-webkit-scrollbar{display:none}
  .slide{flex:0 0 calc(100% - 64px);max-width:340px;aspect-ratio:3/3.9;border-radius:22px;position:relative;overflow:hidden;scroll-snap-align:center;box-shadow:0 14px 30px rgba(40,25,20,.14);background-size:cover;background-position:center;transition:transform .25s,opacity .25s;border:0;padding:0;text-align:left;color:#fff}
  .slide:not(.on){transform:scale(.95);opacity:.8}
  .slide .shade{position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,0) 40%,rgba(20,14,12,.66))}
  .slide .gl{position:absolute;inset:0;background:radial-gradient(circle at 72% 22%,rgba(255,255,255,.35),transparent 45%)}
  .slide .tp{position:absolute;top:14px;left:14px;right:14px;display:flex;gap:6px;align-items:center}
  .pill{font-size:11.5px;font-weight:600;padding:5px 9px;border-radius:999px;background:rgba(255,255,255,.9);color:var(--ink);display:inline-flex;gap:5px;align-items:center}
  .pill i{width:6px;height:6px;border-radius:50%;background:var(--idle);display:inline-block}.pill.ok i{background:var(--ok)}.pill.bad i{background:var(--danger)}
  .slide .dday{background:rgba(27,26,24,.7);color:#fff}
  .slide .bt{position:absolute;left:18px;right:18px;bottom:18px}
  .slide h2{margin:0 0 6px;font-size:24px;letter-spacing:-.02em;line-height:1.25}
  .slide .bt p{margin:0;font-size:12.5px;opacity:.9}
  .slide.noimg .empty{position:absolute;left:0;right:0;top:38%;display:flex;flex-direction:column;align-items:center;gap:10px;color:rgba(255,255,255,.92);font-size:12.5px}
  .slide.noimg .empty .ic{width:52px;height:52px;border-radius:16px;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;color:#fff}
  .slide.newslide{background:transparent;border:1.5px dashed #CFC8BE;box-shadow:none;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:var(--sub);cursor:pointer}
  .slide.newslide .ic{width:60px;height:60px;border-radius:50%;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center}
  .slide.newslide b{font-size:17px;color:var(--ink)}.slide.newslide span{font-size:12.5px;color:var(--faint);text-align:center;line-height:1.6;padding:0 20px}
  .dots{display:flex;justify-content:center;gap:6px;margin:10px 0 14px}
  .dots i{width:6px;height:6px;border-radius:3px;background:#D5CEC5;transition:.2s}.dots i.on{width:18px;background:var(--ink)}
  .dots i.plus{background:none;border:1px solid #CFC8BE}.dots i.plus.on{background:var(--ink);border-color:var(--ink)}
  .mact{padding:0 20px 40px}
  .medit{display:flex;align-items:center;justify-content:center;gap:8px;background:var(--ink);color:#fff;border-radius:14px;padding:15px;font-weight:600;font-size:15px;width:100%;border:0}
  .row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin:8px 0 12px}
  .row3 button{display:flex;flex-direction:column;align-items:center;gap:5px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 4px;font-size:11.5px;color:var(--sub);cursor:pointer}
  .row3 .kk{background:#FEE500;border-color:#FEE500;color:#191600}
  .stats{display:grid;grid-template-columns:1fr 1fr 1fr;background:var(--surface);border:1px solid var(--line);border-radius:14px}
  .stats a{padding:12px 6px;text-align:center;border-right:1px solid var(--soft)}.stats a:last-child{border-right:0}
  .stats b{display:block;font-size:19px;letter-spacing:-.02em}.stats b small{font-size:11px;color:var(--faint);font-weight:500;margin-left:1px}
  .stats span{font-size:11.5px;color:var(--sub);display:inline-flex;align-items:center;gap:4px}
  .mmore{display:flex;justify-content:center;flex-wrap:wrap;gap:8px;padding:16px 0 0}
  .mmore .sbtn{padding:9px 13px;font-size:13px}
  .mact .timer{margin:0 0 12px;padding:14px 16px;gap:12px;flex-wrap:wrap}
  .mact .timer .tic{width:38px;height:38px;border-radius:11px}
  .mact .timer .clock{font-size:22px}
  .mact .timer .prog{width:100%}
  .mact .timer .tm{flex:1}
  .mact .timer .tr{margin:0;text-align:left;max-width:none;flex-basis:100%;display:flex;flex-direction:column;align-items:stretch;gap:10px;font-size:12.5px;text-wrap:pretty}
  .mact .timer .tr p{margin:0}
  .mact .timer .tr br{display:none}
  .mact .timer .code{justify-content:center;padding:10px 12px}
  .mact .timer .paybtn{padding:12px;font-size:14.5px}
  .mnote{text-align:center;font-size:12.5px;color:var(--faint);line-height:1.7;margin:12px 0 0}
  .hint{position:absolute;left:50%;transform:translateX(-50%);background:rgba(27,26,24,.82);color:#fff;font-size:11.5px;padding:6px 12px;border-radius:999px;display:flex;gap:6px;align-items:center;white-space:nowrap;z-index:3;pointer-events:none;transition:opacity .4s}
  .mfoot{padding:0 20px 30px;text-align:center;font-size:12px;color:var(--faint)}
}
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
<svg style="display:none" aria-hidden="true">
 <symbol id="pen" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></symbol>
 <symbol id="link" viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></symbol>
 <symbol id="eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></symbol>
 <symbol id="cam" viewBox="0 0 24 24"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13.5" r="3.5"/></symbol>
 <symbol id="mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
 <symbol id="note" viewBox="0 0 24 24"><path d="M4 4h16v12H8l-4 4Z"/><path d="M8 9h8M8 12h5"/></symbol>
 <symbol id="plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
 <symbol id="chev" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></symbol>
 <symbol id="copy" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></symbol>
 <symbol id="trash" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></symbol>
 <symbol id="clock" viewBox="0 0 24 24"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></symbol>
 <symbol id="down" viewBox="0 0 24 24"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></symbol>
 <symbol id="img" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></symbol>
 <symbol id="swipe" viewBox="0 0 24 24"><path d="M5 12h14M15 8l4 4-4 4M9 8l-4 4 4 4"/></symbol>
 <symbol id="kakao" viewBox="0 0 24 24"><path d="M12 4C7 4 3 7.1 3 11c0 2.4 1.6 4.6 4 5.9L6 21l4.3-2.8c.6.1 1.1.1 1.7.1 5 0 9-3.1 9-7s-4-7-9-7Z" fill="currentColor" stroke="none"/></symbol>
</svg>

<div class="top">
    <a class="logo" href="/">LOVE<span>·</span>DAY</a><span class="sp"></span>
    <?php if ($mode === 'customer'): ?>
        <span class="who"><span class="nv">N</span><span class="code">네이버 로그인 · 고객코드 </span><?= $h($customerCode) ?></span>
        <form method="post" style="margin:0"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="ghost" type="submit">로그아웃</button></form>
    <?php else: ?>
        <span class="who">편집 링크로 들어왔어요</span>
    <?php endif; ?>
</div>
<?php if ($flash): ?><div class="flash<?= $flashOk ? ' ok' : '' ?>"><?= $h($flash) ?><?php if ($flashLink): ?> <a href="<?= $h($flashLink[0]) ?>"><?= $h($flashLink[1]) ?></a><?php endif; ?></div><?php endif; ?>

<!-- PC: 왼쪽 목록 + 오른쪽 상세 -->
<div class="pc">
    <?php if ($mode === 'customer'): ?>
    <aside class="side">
        <div class="side-h"><b>내 청첩장</b><small><?= $slots ?> / <?= MAX_SLOTS ?></small>
            <form method="post" style="margin:0 0 0 auto"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="create">
                <button class="newbtn" type="submit" <?= $slots >= MAX_SLOTS ? 'disabled title="최대 개수에 도달했어요"' : '' ?>><svg class="i" style="width:14px;height:14px"><use href="#plus"/></svg>새로 만들기</button></form></div>
        <div id="pcList"></div>
        <div class="slots"><span><?= $slots ?> / <?= MAX_SLOTS ?> 사용</span><span class="bar"><i style="width:<?= min(100, round($slots / MAX_SLOTS * 100)) ?>%"></i></span></div>
        <?php if ($withdrawUrl): ?><div style="padding:10px 8px 0"><a class="linklike" href="<?= $h($withdrawUrl) ?>">회원 탈퇴</a></div><?php endif; ?>
    </aside>
    <?php endif; ?>
    <main class="main" id="pcDetail"></main>
</div>

<!-- 휴대폰: 옆으로 넘기는 카드 -->
<div class="mo">
    <div class="mttl"><b>내 청첩장</b><?php if ($mode === 'customer'): ?><small><?= $slots ?> / <?= MAX_SLOTS ?></small><?php endif; ?><span class="cnt" id="moCnt"></span></div>
    <div style="position:relative">
        <div class="track" id="moTrack"></div>
        <div class="hint" id="moHint" style="top:62px" hidden><svg class="i" style="width:14px"><use href="#swipe"/></svg>옆으로 넘겨서 다른 청첩장</div>
    </div>
    <div class="dots" id="moDots"></div>
    <div class="mact" id="moAct"></div>
    <?php if ($mode === 'customer' && $withdrawUrl): ?><div class="mfoot"><a class="linklike" href="<?= $h($withdrawUrl) ?>">회원 탈퇴</a></div><?php endif; ?>
</div>

<form method="post" id="delForm" style="display:none"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value=""></form>
<form method="post" id="newForm" style="display:none"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="create"></form>
<div class="toast" id="toast"></div>
<!-- 결제 창 (시계 카드의 "💳 결제하기") - 스마트스토어 상품을 새 창으로 열고, 결제가 반영되면 여기서 바로 알려준다 -->
<div class="pay" id="pay" hidden><div class="pay-box" role="dialog" aria-modal="true" aria-labelledby="payTitle">
  <button type="button" class="pay-x" onclick="closePay()" aria-label="닫기">✕</button>
  <div id="payBody"></div>
</div></div>

<script src="assets/ld-dialog.js"></script>
<script>
const CARDS = <?= json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MODE = <?= json_encode($mode) ?>;
const CSRF = <?= json_encode($csrf) ?>;
const SLOTS = { used: <?= (int) $slots ?>, max: <?= (int) MAX_SLOTS ?> };
// 결제 창: 스마트스토어 상품 주소·가격 (관리자 → 사이트 정보), 자동 연동이 켜져 있는지
// 남은 시간은 서버 시각 기준 (이 컴퓨터·휴대폰 시계가 틀려도 관리자 화면·에디터와 같은 시계)
const SRV_OFF = <?= (int) round(microtime(true) * 1000) ?> - Date.now();
const srvNow = () => Date.now() + SRV_OFF;
const PAY = <?= json_encode([
    'url' => preg_match('#^https?://#i', app_setting('store_url')) ? app_setting('store_url') : '',
    'one' => app_setting('price_one_year'), 'perm' => app_setting('price_permanent'),
    'auto' => app_setting('naver_enabled') === '1' && nc_configured(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const KAKAO_KEY = '9d5208f041541d3a79f70ffc2f4f7c58'; // 공개페이지(render-invite.js)와 같은 카카오 JavaScript 키
let cur = <?= (int) $selected ?>;

const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const ic = (id, st) => `<svg class="i"${st ? ` style="${st}"` : ''}><use href="#${id}"/></svg>`;
function toast(msg) { const t = document.getElementById('toast'); t.textContent = msg; t.classList.add('show'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('show'), 1800); }
const ddayText = d => d == null ? '' : (d > 0 ? 'D-' + d : d === 0 ? 'D-DAY' : 'D+' + (-d));
const statusHtml = c => c.status === 'expired' ? '<span class="st bad">기간 만료</span>' : c.published ? '<span class="st ok">발행됨</span>' : '<span class="st">편집중</span>';
const coverStyle = c => c.cover ? `background-image:url('${esc(c.cover)}')` : `background:linear-gradient(155deg, color-mix(in srgb, ${c.tint} 25%, #fff), ${c.tint})`;

// ---------- 공통 동작 ----------
function copyLink(c) {
    if (!c.published) toast('아직 발행 전이라 하객에게는 열리지 않아요. 에디터에서 발행해주세요.');
    const done = () => { if (c.published) toast('청첩장 주소를 복사했어요'); };
    (navigator.clipboard ? navigator.clipboard.writeText(c.publicUrl) : Promise.reject()).then(done, () => LD.copy(c.publicUrl));
}
function kakaoShare(c) {
    if (!c.published) { toast('발행한 뒤에 카카오톡으로 보낼 수 있어요'); return; }
    const go = () => { try { if (!Kakao.isInitialized()) Kakao.init(KAKAO_KEY); Kakao.Share.sendScrap({ requestUrl: c.publicUrl }); } catch (e) { toast('카카오톡 공유를 열 수 없어요. 링크 복사를 이용해주세요.'); } };
    if (window.Kakao) return go();
    const s = document.createElement('script'); s.src = 'https://t1.kakaocdn.net/kakao_js_sdk/2.7.2/kakao.min.js'; s.onload = go; s.onerror = () => toast('카카오톡 공유를 불러오지 못했어요'); document.head.appendChild(s);
}
function setNick(c) {
    LD.prompt('별칭 붙이기', c.nick || '', { message: '여러 청첩장을 구별하기 쉽게 이름을 붙여요.\n비우고 저장하면 별칭을 지워요.', placeholder: '예: 본식용, 테스트', maxLength: 30, ok: '저장' }).then(v => {
    if (v === null) return;
    const fd = new FormData(); fd.append('action', 'nickname'); fd.append('id', c.id); fd.append('nickname', v.trim()); fd.append('csrf_token', CSRF);
    fetch('dashboard.php' + location.search, { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => {
        if (!d.ok) throw new Error(d.error); c.nick = d.nickname; renderAll(); toast(c.nick ? '별칭을 저장했어요' : '별칭을 지웠어요');
    }).catch(e => toast(e.message || '저장하지 못했어요'));
    });
}
function delInv(c) {
    LD.confirm(`"${c.nick || c.title}" 청첩장을 삭제할까요?\n삭제하면 공개 링크도 더 이상 열리지 않아요.`, { danger: true, ok: '삭제' }).then(ok => {
        if (!ok) return;
        const f = document.getElementById('delForm'); f.querySelector('[name=id]').value = c.id; f.submit();
    });
}
// 파일로 다운로드 - 횟수가 정해져 있어서 한 번 더 물어본다
function exportInv(c) {
    if (!(c.exportLeft > 0)) return;
    if (c.exportGate && !c.exportGate.ok) { LD.alert('아직 받을 수 없어요', { message: c.exportGate.reason + '\n\n받을 수 있는 횟수는 총 3회예요.' }); return; }
    const after = c.exportLeft - 1;
    const gd = c.gd || {};
    const left = `남은 횟수 ${c.exportLeft}회 → 받으면 ${after}회 (총 3회)` + (after === 0 ? '\n이번이 마지막이에요.' : '');
    const toDrive = () => { location.href = c.url.export + '&to=drive'; toast('구글 드라이브로 보내고 있어요. 사진이 많으면 조금 걸려요'); setTimeout(() => { c.exportLeft = after; renderAll(); }, 1500); };
    if (!gd.dl) { // 관리자가 서버에서 바로 받기를 꺼 둠 → 드라이브로만
        if (!gd.linked) { LD.alert('구글 드라이브를 먼저 연결해주세요', { message: '청첩장 파일은 내 구글 드라이브로 보내드려요.\n게스트스냅 관리 화면에서 "구글 드라이브 연결하기"를 눌러주세요.' + (gd.on ? '' : '\n(지금은 준비 중이에요)') }); return; }
        LD.confirm('청첩장 파일을 구글 드라이브로 보낼까요?', { message: '사진·글·배경음악·방명록까지 담긴 HTML 파일 하나를 연결한 드라이브 폴더에 저장해요.\n\n' + left, ok: '드라이브로 보내기', icon: 'info', danger: after === 0 }).then(ok => ok && toDrive());
        return;
    }
    if (gd.linked) {
        LD.confirm('어디로 받을까요?', { message: '구글 드라이브로 보내면 폴더에 안전하게 보관되고, 휴대폰·PC 어디서든 열 수 있어요.\n\n' + left, ok: '구글 드라이브로', cancel: '이 기기로 받기', icon: 'info' })
            .then(ok => ok ? toDrive() : askDevice());
        return;
    }
    askDevice();
    function askDevice() {
    LD.confirm('청첩장을 파일로 받을까요?', {
        message: `사진·글·배경음악·방명록까지 담긴 HTML 파일 하나로 내려받아요. 인터넷 없이도 브라우저에서 바로 열려요.\n\n남은 횟수 ${c.exportLeft}회 → 받으면 ${after}회 (총 3회)` + (after === 0 ? '\n이번이 마지막 다운로드예요.' : ''),
        ok: '다운로드', icon: 'info', danger: after === 0
    }).then(ok => {
        if (!ok) return;
        location.href = c.url.export;
        toast('파일을 만들고 있어요. 사진이 많으면 조금 걸려요');
        setTimeout(() => { c.exportLeft = after; renderAll(); }, 1500);
    });
    }
}
// 기간이 끝난 청첩장(회원 무료체험 삭제 유예 등)은 결제·삭제 말고 전부 잠금 - 서버(snap_owner_invite 등)에서도 막는다
const isLocked = c => c.status === 'expired';
const lockAttr = (c, i, quiet) => isLocked(c) ? ` lockz${quiet ? ' quiet' : ''}" data-lock="${c.plan === 'trial' ? '🔒 결제하면 다시 열려요' : '🔒 보관 기간이 끝나 잠겼어요'}" onclick="lockedTap(${i})` : '';
function lockedTap(i) {
    const c = CARDS[i];
    if (c.plan === 'trial' && PAY.url) { openPay(i); return; }
    const m = '계속 쓰려면 고객센터로 청첩장 코드(' + c.slug + ')를 알려주세요.';
    window.LD ? LD.alert(c.plan === 'trial' ? '무료체험 기간이 끝났어요' : '보관 기간이 끝났어요', { icon: 'clock', message: m }) : alert(m);
}
// 디자인 복사 - 같은 디자인·사진·글로 새 청첩장을 하나 더 (하객 사진·회신·방명록은 빼고)
let dupBusy = false;
function dupInv(c) {
    if (dupBusy) return;
    if (SLOTS.used >= SLOTS.max) { LD.alert('자리가 가득 찼어요', { message: `청첩장은 최대 ${SLOTS.max}개까지 만들 수 있어요.\n안 쓰는 청첩장을 지운 뒤 다시 복사해주세요.` }); return; }
    LD.confirm(`"${c.nick || c.title}" 디자인을 복사할까요?`, {
        message: '디자인·사진·글·계좌·배경음악을 그대로 담은 새 청첩장을 하나 더 만들어요.\n하객 사진·참석 회신·방명록은 옮기지 않아요.\n\n'
            + (c.plan === 'trial' ? '복사본도 무료체험이라, 지금 청첩장의 남은 기간까지만 쓸 수 있어요.' : '복사본은 무료체험으로 시작해요. 사진에 워터마크가 들어가고, 계속 쓰려면 따로 결제해야 해요.')
            + `\n(청첩장 ${SLOTS.used + 1} / ${SLOTS.max}개)`,
        ok: '복사하기', icon: 'info'
    }).then(ok => {
        if (!ok) return;
        dupBusy = true; toast('복사하고 있어요. 사진이 많으면 조금 걸려요');
        const fd = new FormData(); fd.append('action', 'duplicate'); fd.append('id', c.id); fd.append('csrf_token', CSRF);
        fetch('dashboard.php' + location.search, { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => {
            if (!d.ok) throw new Error(d.error);
            location.href = 'dashboard.php?copied=1&id=' + d.id;
        }).catch(e => { dupBusy = false; LD.alert('복사하지 못했어요', { message: e.message || '잠시 뒤 다시 시도해주세요.' }); });
    });
}
function act(name, i) { const c = CARDS[i]; if (isLocked(c) && name !== 'del') { lockedTap(i); return; } ({ copy: copyLink, kakao: kakaoShare, nick: setNick, del: delInv, export: exportInv, dup: dupInv })[name](c); }
const moreLinks = (c, i) => [
    isLocked(c) ? '' : `<button type="button" class="sbtn" onclick="act('nick',${i})">${ic('pen')}별칭${c.nick ? ' 바꾸기' : ' 붙이기'}</button>`,
    c.exportLeft != null ? (c.exportLeft > 0
        ? (c.exportGate && !c.exportGate.ok
            ? `<button type="button" class="sbtn off" onclick="act('export',${i})" title="${esc(c.exportGate.reason)}">${ic('down')}파일 다운로드 ${c.exportGate.from ? '· ' + esc(c.exportGate.from.slice(5).replace('-', '/')) + '부터' : '· 준비 중'}</button>`
            : `<button type="button" class="sbtn" onclick="act('export',${i})">${ic('down')}파일로 다운로드 (${c.exportLeft}회)</button>`)
        : `<span class="sbtn off">${ic('down')}다운로드 소진</span>`) : '',
    MODE === 'customer' && !isLocked(c) ? `<button type="button" class="sbtn" onclick="act('dup',${i})">${ic('copy')}디자인 복사</button>` : '',
    MODE === 'customer' ? `<button type="button" class="sbtn del" onclick="act('del',${i})">${ic('trash')}청첩장 삭제</button>` : ''
].filter(Boolean);

// ---------- 더 하기: 신혼여행 라이브 / 추첨 진행 / QR 만들기(결제한 청첩장만) / 기념일 캘린더 ----------
function toolsHtml(c) {
    const anniv = c.date
        ? `<a class="tool" href="${esc(c.url.anniv)}"><span class="ti">💍</span><b>기념일 캘린더</b><small>매년 결혼기념일 알림</small></a>`
        : `<span class="tool off" onclick="toast('예식일을 넣으면 쓸 수 있어요')"><span class="ti">💍</span><b>기념일 캘린더</b><small>예식일을 넣으면 사용</small></span>`;
    return `<div class="tools">
        <a class="tool" href="${esc(c.url.trip)}"><span class="ti">🧳</span><b>신혼여행 라이브</b><small>여행지 사진·체크인</small></a>
        <a class="tool" href="${esc(c.url.lottery)}"><span class="ti">🎁</span><b>추첨 진행</b><small>식장 하객 추첨</small></a>
        ${c.plan === 'trial'
            ? `<span class="tool off" onclick="toast('결제한 청첩장에서 쓸 수 있어요')"><span class="ti">♥</span><b>QR 만들기</b><small>🔒 결제 후 사용</small></span>`
            : `<a class="tool" href="${esc(c.url.qr)}"><span class="ti">♥</span><b>QR 만들기</b><small>하트 모양 QR</small></a>`}
        ${anniv}</div>`;
}

// ---------- 결제 창 ----------
// 네이버 스마트스토어는 다른 사이트 안에 넣어 띄울 수 없어서(보안 정책), 상품 페이지를 새 창으로 열고
// 청첩장 코드를 미리 복사해 둔다 → 주문서의 "청첩장 코드" 칸에 붙여넣고 결제 → 이 창이 몇 초마다 확인하다가
// 결제가 반영되면(pay_check.php가 스마트스토어 주문을 바로 조회) 축하 화면으로 바뀐다.
let payCard = null, payPlan = 'one_year', payTimer = 0, payUntil = 0;
const won = v => v ? Number(v).toLocaleString('ko-KR') + '원' : '가격 문의';
function copyCode(code, quiet) {
    const ok = () => { if (!quiet) toast('청첩장 코드를 복사했어요'); };
    if (navigator.clipboard && navigator.clipboard.writeText) return navigator.clipboard.writeText(code).then(ok, () => { if (!quiet) LD.copy(code); });
    if (!quiet) LD.copy(code);
    return Promise.resolve();
}
function openPay(i) {
    payCard = CARDS[i]; if (!payCard) return;
    document.getElementById('pay').hidden = false;
    document.documentElement.style.overflow = 'hidden';
    payStep1();
}
function closePay() {
    document.getElementById('pay').hidden = true;
    document.documentElement.style.overflow = '';
    clearTimeout(payTimer);
}
function payStep1() {
    const c = payCard;
    document.getElementById('payBody').innerHTML = !PAY.url
        ? `<h3 id="payTitle">💳 결제하기</h3><p class="sub">아직 결제 페이지가 준비되지 않았어요. 고객센터로 청첩장 코드(<b>${esc(c.slug)}</b>)를 알려주세요.</p>`
        : `<h3 id="payTitle">💳 결제하고 계속 쓰기</h3>
        <p class="sub">"${esc(c.nick || c.title)}" 청첩장 · 네이버 스마트스토어에서 안전하게 결제해요</p>
        <div class="pstep"><i>1</i><div><b>보관 기간</b>
          <div class="plans"><button type="button" data-plan="one_year" class="${payPlan === 'one_year' ? 'on' : ''}"><small>1년 보관</small><strong>${won(PAY.one)}</strong></button>
          <button type="button" data-plan="permanent" class="${payPlan === 'permanent' ? 'on' : ''}"><small>영구 보관</small><strong>${won(PAY.perm)}</strong></button></div>
          <p class="hint">주문서에서도 같은 보관 기간을 골라주세요.</p></div></div>
        <div class="pstep"><i>2</i><div><b>청첩장 코드</b>
          <div class="codebox"><code>${esc(c.slug)}</code><button type="button" onclick="copyCode('${esc(c.slug)}')">복사</button></div>
          <p class="hint">주문서의 <b>"청첩장 코드"</b> 칸에 붙여넣어 주세요. 아래 버튼을 누르면 자동으로 복사돼요.</p></div></div>
        <button type="button" class="go" onclick="payGo()"><span class="n">N</span>스마트스토어에서 결제하기</button>
        <p class="foot">${PAY.auto ? '결제하면 보통 1~2분 안에 자동으로 워터마크가 사라지고 보관 기간이 늘어나요.' : '결제 후 확인되면 워터마크가 사라지고 보관 기간이 늘어나요.'}</p>`;
    document.querySelectorAll('#payBody [data-plan]').forEach(b => b.onclick = () => { payPlan = b.dataset.plan; payStep1(); });
}
function payGo() {
    const c = payCard;
    copyCode(c.slug, true);
    window.open(PAY.url, '_blank', 'noopener');
    payUntil = Date.now() + 30 * 60000; // 30분 동안 확인
    payWait('');
    clearTimeout(payTimer); payTimer = setTimeout(payPoll, 8000);
}
function payWait(msg) {
    const c = payCard;
    document.getElementById('payBody').innerHTML = `<div class="wait"><div class="spin"></div>
        <h3 id="payTitle" style="margin-bottom:10px">결제를 기다리고 있어요</h3>
        <p>새 창에서 결제를 마치면 <b>이 화면이 자동으로</b> 바뀌어요.<br>주문서 <b>"청첩장 코드"</b> 칸에 <b style="color:#0A7A3E">${esc(c.slug)}</b> 를 붙여넣었는지 확인해 주세요 (복사돼 있어요).</p>
        ${msg ? `<p style="color:var(--sub);font-size:12.5px">${msg}</p>` : ''}
        <div class="btns"><button type="button" onclick="payPoll(true)">지금 확인</button><button type="button" onclick="payStep1()">결제 창 다시 열기</button></div></div>`;
}
function payPoll(manual) {
    clearTimeout(payTimer);
    if (document.getElementById('pay').hidden) return;
    fetch('pay_check.php?t=' + encodeURIComponent(payCard.token), { cache: 'no-store', credentials: 'same-origin' }).then(r => r.json()).then(j => {
        if (j && j.paid) { payDone(j.plan); return; }
        if (manual) payWait('아직 결제가 확인되지 않았어요. 결제 직후라면 1~2분만 기다려 주세요.');
        if (Date.now() < payUntil) payTimer = setTimeout(payPoll, document.hidden ? 20000 : 8000);
        else payWait('확인 시간이 지났어요. 결제를 마치셨다면 "지금 확인"을 눌러주세요. 코드를 빠뜨렸다면 고객센터로 알려주세요.');
    }).catch(() => { if (Date.now() < payUntil) payTimer = setTimeout(payPoll, 15000); });
}
function payDone(plan) {
    document.getElementById('payBody').innerHTML = `<div class="wait"><div class="done">🎉</div>
        <h3 id="payTitle" style="margin-bottom:8px">결제가 확인됐어요!</h3>
        <p>워터마크가 사라지고 <b>${plan === 'permanent' ? '영구 보관' : '1년 보관'}</b>으로 바뀌었어요.<br>이제 마음껏 쓰세요 💌</p>
        <div class="btns"><button type="button" onclick="location.reload()" style="background:var(--ink);color:#fff;border-color:var(--ink)">확인</button></div></div>`;
}
document.addEventListener('visibilitychange', () => { if (!document.hidden && payCard && !document.getElementById('pay').hidden && Date.now() < payUntil) payPoll(); }); // 결제 창에서 돌아오면 바로 확인
document.addEventListener('keydown', e => { if (e.key === 'Escape' && !document.getElementById('pay').hidden) closePay(); });

// ---------- 자동 삭제까지 남은 시간 ----------
// 무료체험·가입 없이 체험한 청첩장은 expires_at이 지나면 서버가 자동으로 지운다. 그 시각까지 1초마다 줄어드는 시계를 보여준다.
const pad2 = n => String(n).padStart(2, '0');
const GRACE_H = <?= (int) NC_GRACE_HOURS ?>;
// 스마트스토어 결제 청첩장에 보이는 취소 안내 (결제 전에 알려주기)
const cancelNote = c => c.naver && c.naver.paid && c.plan !== 'trial'
    ? `<div class="paynote">${ic('note', 'width:14px;height:14px')}<span>스마트스토어에서 <b>취소·반품을 요청하는 즉시</b> 결제 전 상태(무료체험)로 돌아가요. 이때 1회에 한해 ${GRACE_H}시간 더 쓸 수 있어요.${c.exportLeft != null ? ' 파일 다운로드는 구매확정 뒤, 예식 전날부터 받을 수 있어요.' : ''}</span></div>` : '';
// 취소로 되돌린 청첩장은 한 번 팝업으로 알려준다
function revertPopup() {
    const c = CARDS.find(x => x.naver && x.naver.revert);
    if (!c) return;
    const key = 'ld_rv_' + c.naver.revert.id + '_' + (c.naver.revert.at || '');
    try { if (localStorage.getItem(key)) return; localStorage.setItem(key, '1'); } catch (e) {}
    const rv = c.naver.revert;
    LD.alert('무료체험으로 돌아갔어요', { message: `"${c.nick || c.title}" 청첩장의 스마트스토어 주문이 ${rv.exchange ? '교환 요청' : '취소 요청'}되어, 결제 전 상태(무료체험)로 돌아갔어요.` + (rv.grace ? `\n1회에 한해 ${GRACE_H}시간을 더 드렸어요. 그 뒤에는 자동으로 삭제돼요.` : '') + '\n계속 쓰시려면 다시 결제해주세요.' });
}
function clockHtml(ms) {
    if (ms <= 0) return '곧 삭제돼요';
    const t = Math.floor(ms / 1000), d = Math.floor(t / 86400), h = Math.floor(t % 86400 / 3600), m = Math.floor(t % 3600 / 60), sec = t % 60;
    return (d ? `${d}<u>일</u>` : '') + `${pad2(h)}:${pad2(m)}:${pad2(sec)}`;
}
function purgeDate(ms) { const d = new Date(ms + 9 * 3600000); return `${d.getUTCMonth() + 1}월 ${d.getUTCDate()}일 ${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())}`; } // 한국 시간
function timerHtml(c) {
    // 기간이 끝난 회원 무료체험: 삭제(휴지통 이동)까지 남은 유예 시간
    if (c.status === 'expired' && c.purgeTs && c.plan === 'trial') {
        const left = c.purgeTs - srvNow();
        return `<div class="timer urgent grace" data-exp="${c.purgeTs}" data-start="${c.expTs}">
            <span class="tic">${ic('clock')}</span>
            <div class="tm"><small>삭제까지</small><div class="clock" data-clock>${clockHtml(left)}</div><div class="prog"><i style="width:${Math.max(0, Math.min(100, left / Math.max(1, c.purgeTs - c.expTs) * 100))}%"></i></div></div>
            <div class="tr"><p class="two"><span>무료체험 기간이 끝나 하객에게는 더 이상 안 보여요. <b>${purgeDate(c.purgeTs)}</b>에 청첩장이 <b>삭제</b>돼요.</span><span>그 전에 결제하시면 그대로 이어서 쓸 수 있어요.</span></p>
            <button type="button" class="paybtn" onclick="openPay(${CARDS.indexOf(c)})">💳 결제하고 계속 쓰기</button></div></div>`;
    }
    if (!c.expTs || c.plan !== 'trial' || c.status === 'expired') return '';
    const left = c.expTs - srvNow();
    const tone = left <= 0 ? 'done' : left < 86400000 ? 'urgent' : '';
    const pct = Math.max(0, Math.min(100, left / Math.max(1, c.expTs - c.startTs) * 100));
    const rv = c.naver && c.naver.revert;
    const right = c.demo
        ? `<p>가입 없이 만든 체험용이라 시간이 지나면 <b>통째로 삭제</b>되고, 결제·보관이 안 돼요.</p><span>계속 쓰려면 네이버로 시작해서 새로 만들어주세요.</span>`
        : rv ? `<p>스마트스토어 주문이 ${rv.exchange ? '교환 요청' : '취소'}되어 <b>무료체험으로 돌아갔어요.</b>${rv.grace ? ` 1회에 한해 ${GRACE_H}시간을 더 드렸어요.` : ''}<br>다시 결제하면 바로 워터마크가 사라져요.</p>
           <button type="button" class="paybtn" onclick="openPay(${CARDS.indexOf(c)})">💳 다시 결제하기</button>`
        : `<p>무료체험이라 사진에 워터마크가 들어가고, 시간이 지나면 <b>자동으로 삭제</b>돼요. <br>마음에 들면 결제해 주세요. 결제하면 바로 워터마크가 사라져요.</p>
           <button type="button" class="paybtn" onclick="openPay(${CARDS.indexOf(c)})">💳 결제하기</button>`;
    return `<div class="timer ${tone}" data-exp="${c.expTs}" data-start="${c.startTs}">
        <span class="tic">${ic('clock')}</span>
        <div class="tm"><small>자동 삭제까지</small><div class="clock" data-clock>${clockHtml(left)}</div><div class="prog"><i style="width:${pct}%"></i></div></div>
        <div class="tr">${right}</div></div>`;
}
setInterval(() => {
    document.querySelectorAll('.timer[data-exp]').forEach(t => {
        const exp = +t.dataset.exp, left = exp - srvNow();
        const c = t.querySelector('[data-clock]'); if (c) c.innerHTML = clockHtml(left);
        const bar = t.querySelector('.prog i'); if (bar) bar.style.width = Math.max(0, Math.min(100, left / Math.max(1, exp - +t.dataset.start) * 100)) + '%';
        t.classList.toggle('urgent', left > 0 && left < 86400000);
        t.classList.toggle('done', left <= 0);
    });
}, 1000);

// ---------- PC ----------
function renderPcList() {
    const el = document.getElementById('pcList'); if (!el) return;
    el.innerHTML = CARDS.map((c, i) => `<button type="button" class="item ${i === cur ? 'on' : ''}" onclick="select(${i})">
        <span class="cv ${c.cover ? '' : 'noimg'}" style="width:44px;height:58px;${coverStyle(c)}">${c.cover ? '<i class="gl"></i>' : ic('img')}</span>
        <span style="min-width:0"><h4 class="${c.named || c.nick ? '' : 'none'}">${esc(c.nick || c.title)}</h4>
        <span class="m">${c.dday != null ? `<span class="dday ${c.dday > 60 || c.dday < 0 ? 'mute' : ''}">${ddayText(c.dday)}</span>` : ''}${statusHtml(c)}${c.dday == null ? `<span class="dot-sep">·</span>${esc(c.created)}` : ''}</span></span></button>`).join('')
        || '<p style="padding:20px 8px;color:var(--faint);font-size:13px">아직 만든 청첩장이 없어요.</p>';
}
function renderPcDetail() {
    const el = document.getElementById('pcDetail'); const c = CARDS[cur];
    if (!c) { el.innerHTML = `<div style="padding:80px 20px;text-align:center;color:var(--sub)"><p style="font-size:18px;color:var(--ink);font-weight:600">첫 청첩장을 만들어 보세요</p><p>디자인을 고르고 바로 시작해요. 먼저 무료로 만들어 보고 마음에 들 때 결정하면 돼요.</p><button class="b1" onclick="document.getElementById('newForm').submit()">${ic('plus')}새 청첩장 만들기</button></div>`; return; }
    const exp = c.status === 'expired';
    el.innerHTML = `
      <div class="hero">
        <div class="cv ${c.cover ? '' : 'noimg'}" style="width:124px;height:164px;border-radius:16px;${coverStyle(c)}">${c.cover ? '<i class="gl"></i>' : ic('img', 'width:28px;height:28px')}</div>
        <div style="flex:1;min-width:0">
          ${c.dday != null ? `<span class="dday">${ddayText(c.dday)}</span>` : ''}
          <h1 class="${c.named ? '' : 'none'}">${esc(c.title)}${c.nick ? `<span class="nick">${esc(c.nick)}</span>` : ''}</h1>
          <div class="meta">${c.date ? esc(c.date) : '예식일을 아직 안 정했어요'}${c.venue ? `<span class="dot-sep">·</span>${esc(c.venue)}` : ''}<span class="dot-sep">·</span>${statusHtml(c)}<span class="st ${c.warn ? 'warn' : ''}">${esc(c.planText)}</span></div>
          <div class="acts${lockAttr(c, cur, true)}">
            <a class="b1" href="${esc(c.url.edit)}">${ic('pen')}${c.named ? '디자인 편집하기' : '이어서 편집하기'}</a>
            <button class="b2" type="button" onclick="act('copy',${cur})">${ic('link')}링크 복사</button>
            <button class="b2 kk" type="button" onclick="act('kakao',${cur})">${ic('kakao')}카카오톡 공유</button>
            <a class="b2" href="${esc(c.url.preview)}" target="_blank" rel="noopener">${ic('eye')}미리보기</a>
          </div>
        </div>
      </div>
      ${timerHtml(c)}
      ${exp && !c.purgeTs ? '<div class="warnbox" style="margin:-8px 0 18px">보관 기간이 끝나 더 이상 편집할 수 없어요. 계속 쓰려면 고객센터로 청첩장 코드를 알려주세요.</div>' : ''}
      <div class="grid${lockAttr(c, cur)}">
        <div class="box"><div class="h">${ic('cam')}하객 사진</div><div class="n">${c.snap}<small>장</small></div>
          <div class="d">${c.snapOn ? '하객이 로그인 없이 사진을 올릴 수 있어요' : '에디터에서 "게스트스냅" 섹션을 켜면 시작돼요'}</div>
          <div class="f"><span></span><a href="${esc(c.url.snap)}">사진·QR ${ic('chev', 'width:14px')}</a></div></div>
        <div class="box"><div class="h">${ic('mail')}참석 회신</div><div class="n">${c.rsvp}<small>명</small></div>
          <div class="d">${esc(c.rsvpText)}</div><div class="f"><span></span><a href="${esc(c.url.rsvp)}">명단 ${ic('chev', 'width:14px')}</a></div></div>
        <div class="box"><div class="h">${ic('note')}방명록</div><div class="n">${c.gb}<small>개</small></div>
          <div class="d">${c.gb ? '하객이 남긴 축하 메시지' : '아직 남긴 글이 없어요'}</div><div class="f"><span></span><a href="${esc(c.url.gb)}">관리 ${ic('chev', 'width:14px')}</a></div></div>
      </div>
      <div class="${lockAttr(c, cur, true).replace(/^ /, '')}">${toolsHtml(c)}</div>
      <div class="url">공개 주소 <code>loveday.kr/${esc(c.slug)}</code>${exp ? '<span class="st bad">기간이 끝나 하객에게 안 열려요</span>' : `<button type="button" class="cp" onclick="act('copy',${cur})">복사</button>${c.published ? '' : '<span class="st">발행 전 - 하객에게 아직 안 열려요</span>'}`}<span class="links">${moreLinks(c, cur).join('')}</span></div>
      ${cancelNote(c)}`;
}
function select(i) { cur = i; renderPcList(); renderPcDetail(); scrollMoTo(i, false); history.replaceState(null, '', CARDS[i] ? '?id=' + CARDS[i].id + (MODE === 'token' ? '&t=' + new URLSearchParams(location.search).get('t') : '') : location.pathname); }

// ---------- 휴대폰 ----------
const canCreate = MODE === 'customer';
function renderMo() {
    const tr = document.getElementById('moTrack');
    tr.innerHTML = CARDS.map((c, i) => `<div class="slide ${c.cover ? '' : 'noimg'}" data-i="${i}" style="${coverStyle(c)}">
        <i class="gl"></i>${c.cover ? '<i class="shade"></i>' : `<div class="empty"><span class="ic">${ic('img', 'width:22px;height:22px')}</span>대표 사진을 넣으면 여기에 보여요</div><i class="shade" style="opacity:.5"></i>`}
        <div class="tp">${c.dday != null ? `<span class="dday">${ddayText(c.dday)}</span>` : ''}<span class="sp"></span><span class="pill ${c.status === 'expired' ? 'bad' : c.published ? 'ok' : ''}"><i></i>${c.status === 'expired' ? '만료' : c.published ? '발행됨' : '편집중'}</span></div>
        <div class="bt"><h2>${esc(c.nick || c.title)}</h2><p>${esc([c.date, c.venue].filter(Boolean).join(' · ') || c.created)}</p></div></div>`).join('')
      + (canCreate ? `<div class="slide newslide" data-i="new" onclick="${SLOTS.used < SLOTS.max ? "document.getElementById('newForm').submit()" : ''}"><span class="ic">${ic('plus', 'width:26px;height:26px')}</span><b>${SLOTS.used < SLOTS.max ? '새 청첩장 만들기' : '자리가 가득 찼어요'}</b><span>${SLOTS.used < SLOTS.max ? '디자인을 고르고 바로 시작해요' : '청첩장은 최대 ' + SLOTS.max + '개까지예요.<br>안 쓰는 청첩장을 지우면 새로 만들 수 있어요'}</span></div>` : '');
    document.getElementById('moDots').innerHTML = CARDS.map((c, i) => `<i data-i="${i}"></i>`).join('') + (canCreate ? '<i class="plus" data-i="new"></i>' : '');
    // 가운데 온 카드를 "지금 카드"로
    if (window._moIo) window._moIo.disconnect();
    const io = window._moIo = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting && e.intersectionRatio > .6) moSet(e.target.dataset.i); }), { root: tr, threshold: [.6] });
    tr.querySelectorAll('.slide').forEach(s => io.observe(s));
    tr.querySelectorAll('.slide:not(.newslide)').forEach(s => s.addEventListener('click', () => { const i = +s.dataset.i; if (i !== cur) scrollMoTo(i, true); }));
    moCur = null; moSet(String(cur));
    // 넘길 수 있다는 안내 - 처음 한 번만
    if (!hintShown) try { if (CARDS.length + (canCreate ? 1 : 0) > 1 && !localStorage.getItem('ld_swipe_hint')) { const h = document.getElementById('moHint'); h.hidden = false; setTimeout(() => { h.style.opacity = 0; }, 2600); localStorage.setItem('ld_swipe_hint', '1'); } } catch (e) {}
}
let moCur = null;
function moSet(key) {
    if (moCur === key) return; moCur = key;
    document.querySelectorAll('#moTrack .slide').forEach(s => s.classList.toggle('on', s.dataset.i === key));
    document.querySelectorAll('#moDots i').forEach(d => d.classList.toggle('on', d.dataset.i === key));
    const total = CARDS.length;
    document.getElementById('moCnt').textContent = key === 'new' ? '새로 만들기' : total ? `${+key + 1} / ${total}` : '';
    const el = document.getElementById('moAct');
    if (key === 'new' || !total) {
        el.innerHTML = canCreate ? (SLOTS.used < SLOTS.max
            ? `<button class="medit" onclick="document.getElementById('newForm').submit()">${ic('plus')}새 청첩장 만들기</button><p class="mnote">${SLOTS.max - SLOTS.used}자리 남았어요 · 만들면 무료체험으로 시작해요<br>마음에 들 때 결제하면 워터마크가 사라져요</p>`
            : `<p class="mnote">청첩장은 최대 ${SLOTS.max}개까지 만들 수 있어요.</p>`) : '';
        return;
    }
    const i = +key; cur = i; const c = CARDS[i]; const exp = c.status === 'expired';
    el.innerHTML = `
      <div class="${lockAttr(c, i, true).replace(/^ /, '')}"><a class="medit" href="${esc(c.url.edit)}">${ic('pen')}${c.named ? '디자인 편집하기' : '이어서 편집하기'}</a>
      <div class="row3"><button type="button" onclick="act('copy',${i})">${ic('link')}링크 복사</button><button type="button" class="kk" onclick="act('kakao',${i})">${ic('kakao')}카카오 공유</button><button type="button" onclick="window.open('${esc(c.url.preview)}','_blank')">${ic('eye')}미리보기</button></div></div>
      ${timerHtml(c)}
      <div class="${lockAttr(c, i).replace(/^ /, '')}"><div class="stats"><a href="${esc(c.url.snap)}"><b>${c.snap}<small>장</small></b><span>${ic('cam', 'width:13px')}하객 사진</span></a><a href="${esc(c.url.rsvp)}"><b>${c.rsvp}<small>명</small></b><span>${ic('mail', 'width:13px')}참석</span></a><a href="${esc(c.url.gb)}"><b>${c.gb}<small>개</small></b><span>${ic('note', 'width:13px')}방명록</span></a></div>
      ${toolsHtml(c)}</div>
      <p class="mnote">${statusHtml(c)}<span class="dot-sep" style="margin:0 6px">·</span><span class="st ${c.warn ? 'warn' : ''}">${esc(c.planText)}</span>${exp ? '<br>기간이 끝나 하객에게 안 열려요' : c.published ? '' : '<br>발행 전 - 하객에게는 아직 열리지 않아요'}</p>
      <div class="mmore">${moreLinks(c, i).join('')}</div>
      ${cancelNote(c)}`;
    if (document.getElementById('pcDetail')) { renderPcList(); renderPcDetail(); }
}
function scrollMoTo(i, smooth) {
    const s = document.querySelector(`#moTrack .slide[data-i="${i}"]`); if (!s) return;
    const tr = document.getElementById('moTrack');
    tr.scrollTo({ left: s.offsetLeft - (tr.clientWidth - s.clientWidth) / 2, behavior: smooth ? 'smooth' : 'auto' });
}
document.getElementById('moDots').addEventListener('click', e => { const d = e.target.closest('[data-i]'); if (!d) return; d.dataset.i === 'new' ? document.querySelector('#moTrack .newslide').scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' }) : scrollMoTo(+d.dataset.i, true); });

let hintShown = false;
function renderAll() { renderPcList(); renderPcDetail(); hintShown = true; renderMo(); requestAnimationFrame(() => scrollMoTo(cur, false)); }
renderPcList(); renderPcDetail(); renderMo();
requestAnimationFrame(() => scrollMoTo(cur, false));
setTimeout(revertPopup, 400);
if (new URLSearchParams(location.search).get('pay') === '1' && CARDS[cur] && CARDS[cur].plan === 'trial') setTimeout(() => openPay(cur), 300); // 에디터의 "결제하러 가기"에서
</script>
</body>
</html>
