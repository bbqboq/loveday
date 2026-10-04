<?php
/**
 * guest_functions.php - 4단계(참석여부·방명록·D-DAY 안내) 공통 함수
 * snap_functions.php(관리자 설정·하객 쿠키·예식일 계산)를 그대로 이어서 쓴다.
 * rsvp.php / guestbook.php / guest_manage.php / guest_card.php 가 이 파일 하나만 require 한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

function guest_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function guest_fail(int $code, string $msg): void { guest_json(['ok' => false, 'error' => $msg], $code); }

/** 공개 페이지에서 오는 요청의 CSRF 확인 (invite_view.php가 window.INVITE_CSRF로 넣어준 값). 실패하면 JSON으로 응답 */
function guest_csrf_check(): void
{
    $t = (string) ($_POST['csrf_token'] ?? '');
    if ($t === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $t)) {
        guest_fail(403, '페이지를 새로고침한 뒤 다시 시도해주세요.');
    }
}

/** 공개코드로 발행된 청첩장 찾기 (발행 전·삭제·만료면 null) */
function guest_invite_by_slug(PDO $pdo, string $slug): ?array
{
    if (!preg_match('/^[a-z0-9]{4,20}$/', $slug)) return null;
    $inv = find_invitation_by_slug($pdo, $slug);
    return $inv && invite_is_live($pdo, $inv) ? $inv : null; // 삭제·기간 만료면 없는 것처럼
}

/** 디자인에서 섹션 하나의 설정값 (켜져 있을 때만, 없거나 꺼져 있으면 null) */
function guest_block(array $invite, string $id): ?array
{
    $d = snap_design($invite);
    foreach (($d['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') === $id) return !empty($b['enabled']) ? (array) ($b['fields'] ?? []) : null;
    }
    return null;
}

/** 입력 문자열 정리 - 제어문자 제거, 앞뒤 공백 제거, 글자 수 제한 (화면에 그릴 때는 항상 escape 하므로 태그는 그대로 둬도 안전) */
function guest_clean($s, int $max): string
{
    $s = is_string($s) ? $s : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim(str_replace("\r\n", "\n", $s));
    return mb_substr($s, 0, $max);
}

/** 참석여부 마감일이 지났는지 (마감일 당일 자정까지 받음, 한국 시간) */
function rsvp_closed(array $fields): bool
{
    $dl = (string) ($fields['deadline'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dl)) return false;
    return date('Y-m-d') > $dl;
}

/** 참석여부 집계 */
function rsvp_summary(PDO $pdo, int $invitationId): array
{
    $s = ['total' => 0, 'yes' => 0, 'no' => 0, 'people' => 0, 'groom' => 0, 'bride' => 0, 'groomPeople' => 0, 'bridePeople' => 0, 'mealYes' => 0, 'mealUnknown' => 0];
    try {
        $st = $pdo->prepare('SELECT side, attend, headcount, meal FROM rsvp_responses WHERE invitation_id = ?');
        $st->execute([$invitationId]);
        foreach ($st->fetchAll() as $r) {
            $s['total']++;
            if ($r['attend'] !== 'yes') { $s['no']++; continue; }
            $n = (int) $r['headcount'];
            $s['yes']++; $s['people'] += $n;
            $s[$r['side']]++; $s[$r['side'] . 'People'] += $n;
            if ($r['meal'] === 'yes') $s['mealYes'] += $n;
            elseif ($r['meal'] === 'unknown') $s['mealUnknown'] += $n;
        }
    } catch (Throwable $e) { /* 테이블이 아직 없으면(stage4_setup.sql 실행 전) 0으로 */ }
    return $s;
}

function guestbook_count(PDO $pdo, int $invitationId): int
{
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM guestbook_entries WHERE invitation_id = ?');
        $st->execute([$invitationId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* =========================================================
 * 회원가입 없이 체험하기 (try_demo.php)
 * 체험 청첩장 = customer_id 없음 + order_memo가 DEMO_MEMO로 시작 + 무료체험(trial, 워터마크).
 * 만료(demo_hours 관리자 설정, 기본 24시간)되면 사진·게스트스냅·참석여부·방명록까지 통째로 영구 삭제한다.
 * ======================================================= */
const DEMO_MEMO = '[체험] 회원가입 없이 테스트';

/** 청첩장 하나를 흔적 없이 지움 - 게스트스냅·참석여부·방명록·신혼여행·추첨 기록 + 사진 폴더 + 청첩장 행 */
function invitation_purge(PDO $pdo, int $id): void
{
    $cst = $pdo->prepare('SELECT customer_id FROM invitation_orders WHERE id = ?');
    $cst->execute([$id]);
    $customerId = (int) $cst->fetchColumn();
    try { snap_delete_all($pdo, $id); } catch (Throwable $e) {}
    foreach (['rsvp_responses', 'guestbook_entries', 'guest_snap_downloads', 'trip_posts', 'lottery_entries', 'lottery_state'] as $t) {
        try { $pdo->prepare("DELETE FROM {$t} WHERE invitation_id = ?")->execute([$id]); } catch (Throwable $e) {}
    }
    permanently_delete_invitation($pdo, $id); // 사진 폴더(신혼여행 사진 포함) + 청첩장 행
    if ($customerId) { try { refresh_over_limit_state($pdo, $customerId); } catch (Throwable $e) {} }
}

/** 만료된 체험(둘러보기, 비회원) 청첩장 영구 삭제 - 유예 없이 바로. 반환: 지운 건수 */
function demo_cleanup(PDO $pdo, int $limit = 200): int
{
    $st = $pdo->prepare("SELECT id FROM invitation_orders
                         WHERE customer_id IS NULL AND order_memo LIKE ? AND expires_at IS NOT NULL AND expires_at < NOW()
                         ORDER BY id LIMIT " . max(1, $limit));
    $st->execute([DEMO_MEMO . '%']);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { invitation_purge($pdo, (int) $id); $n++; }
    return $n;
}

/** 무료체험이 끝난 뒤 휴지통으로 옮기기까지 유예 시간 (관리자 → 부가기능, 기본 24시간) - 네이버 로그인 회원 청첩장만 */
function trial_grace_hours(): int { return max(0, min(168, app_setting_int('trial_grace_hours'))); }

/**
 * 회원 무료체험 청첩장: 기간이 끝나고 유예 시간까지 지나면 휴지통으로 옮긴다 (관리자가 복원 가능).
 * 휴지통에서 trash_keep_days(기본 30일)가 지나면 trash_cleanup()이 사진까지 완전 삭제한다.
 * 유예 중에는 대시보드에 "삭제까지" 시계가 뜨고, 결제하면(스마트스토어 연동·관리자) 기간이 늘어나 삭제 대상에서 빠진다.
 * 크론(expire_cleanup.php)이 돌고, 회원이 대시보드를 열 때도 그 회원 것만 한 번 정리한다.
 */
function member_trial_cleanup(PDO $pdo, int $limit = 200, ?int $customerId = null): int
{
    $g = trial_grace_hours();
    $st = $pdo->prepare("SELECT id FROM invitation_orders
                         WHERE customer_id IS NOT NULL" . ($customerId ? ' AND customer_id = ?' : '') . "
                           AND storage_plan = 'trial' AND deleted_at IS NULL
                           AND expires_at IS NOT NULL AND expires_at < DATE_SUB(NOW(), INTERVAL {$g} HOUR)
                         ORDER BY id LIMIT " . max(1, $limit));
    $st->execute($customerId ? [$customerId] : []);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { soft_delete_invitation($pdo, (int) $id); $n++; }
    return $n;
}

/** 휴지통 보관 기간 (관리자 → 부가기능, 기본 30일) */
function trash_keep_days(): int { return max(1, min(365, app_setting_int('trash_keep_days'))); }

/** 휴지통에 들어간 지 trash_keep_days가 지난 청첩장을 사진·하객 기록까지 완전 삭제. 반환: 지운 건수 */
function trash_cleanup(PDO $pdo, int $limit = 200): int
{
    $d = trash_keep_days();
    $st = $pdo->query("SELECT id FROM invitation_orders
                       WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL {$d} DAY)
                       ORDER BY id LIMIT " . max(1, $limit));
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { invitation_purge($pdo, (int) $id); $n++; }
    return $n;
}

/** 휴지통에 들어온 이유 - 무료체험 기간이 끝난 뒤에 지워졌으면 자동 정리, 아니면 고객이 직접 삭제 */
function trash_reason(array $row): string
{
    $exp = (string) ($row['expires_at'] ?? '');
    $del = (string) ($row['deleted_at'] ?? '');
    if (($row['storage_plan'] ?? '') === 'trial' && $exp !== '' && $del !== '' && $exp <= $del) return 'expired';
    return 'customer';
}

function demo_active_count(PDO $pdo): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM invitation_orders WHERE customer_id IS NULL AND order_memo LIKE ? AND expires_at > NOW()');
    $st->execute([DEMO_MEMO . '%']);
    return (int) $st->fetchColumn();
}

/* ---------------------------------------------------------
 * 둘러보기로 만든 청첩장을 네이버 로그인한 계정으로 옮기기
 *  - 이 브라우저가 만든 체험 청첩장($_SESSION['demo_token'])만 옮긴다 → 링크(토큰)만 아는 남이 가져갈 수 없다
 *  - 옮기면 일반 무료체험이 된다: 회원 번호가 붙어서 자동 삭제(demo_cleanup) 대상에서 빠지고,
 *    보관 기간은 "지금부터 무료체험 4일"(원래 남은 시간이 더 길면 그대로)
 *  - 사진·스티커·문구 등 만들던 내용은 전부 그대로
 *  - 계정의 청첩장이 이미 MAX_SLOTS(5)개면 옮기지 않는다 (하나 지운 뒤 다시 누르면 옮겨짐)
 * 쓰는 곳: naver_callback.php(로그인 직후), start_free.php(이미 로그인한 채 "무료 시작"), claim_demo.php(에디터의 저장 버튼)
 * --------------------------------------------------------- */
const DEMO_CLAIM_TRIAL_DAYS = 4;
const DEMO_CLAIMED_MEMO = '[둘러보기→가입]';

/** 반환 ['result' => none(옮길 것 없음) | claimed | full(5개 꽉 참) | gone(이미 만료), 'token' => 옮긴 청첩장 편집 토큰] */
function demo_claim_from_session(PDO $pdo, int $customerId): array
{
    $token = (string) ($_SESSION['demo_token'] ?? '');
    if ($customerId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token)) return ['result' => 'none'];
    $inv = find_invitation_by_token($pdo, $token);
    $isDemo = $inv && empty($inv['customer_id']) && empty($inv['deleted_at']) && str_starts_with((string) $inv['order_memo'], DEMO_MEMO);
    if (!$isDemo) { unset($_SESSION['demo_token']); return ['result' => 'none']; } // 이미 옮겼거나 지워짐
    // 보관 시간이 지났어도 아직 지워지기 전이면 옮길 수 있다 (편집 화면의 "시간이 끝났어요" 팝업에서 가입하는 경우)
    if (count_active_invitations($pdo, $customerId) >= MAX_SLOTS) return ['result' => 'full']; // demo_token은 남겨서 나중에 다시 시도 가능
    // customer_id IS NULL 조건을 같이 걸어 두 창에서 동시에 눌러도 한 번만 옮겨지게
    $st = $pdo->prepare("UPDATE invitation_orders
                            SET customer_id = ?, order_memo = ?, storage_plan = 'trial',
                                expires_at = GREATEST(expires_at, DATE_ADD(NOW(), INTERVAL " . DEMO_CLAIM_TRIAL_DAYS . " DAY))
                          WHERE id = ? AND customer_id IS NULL AND deleted_at IS NULL AND order_memo LIKE ?");
    $st->execute([$customerId, DEMO_CLAIMED_MEMO . ' ' . date('Y-m-d H:i'), (int) $inv['id'], DEMO_MEMO . '%']);
    unset($_SESSION['demo_token']);
    if ($st->rowCount() !== 1) return ['result' => 'none'];
    $_SESSION['pin_ok_' . $inv['id']] = true;
    return ['result' => 'claimed', 'token' => $token];
}

/** 둘러보기 청첩장이 아직 남아 있는지 (claim_demo.php가 로그인 보내기 전에 확인) */
function demo_exists(PDO $pdo, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
    $inv = find_invitation_by_token($pdo, $token);
    return $inv && empty($inv['customer_id']) && empty($inv['deleted_at']) && str_starts_with((string) $inv['order_memo'], DEMO_MEMO);
}

/* ---------------------------------------------------------
 * 편집 화면 삭제 카운트다운용 정보 (invite_load.php / invite_expiry.php가 내려줌)
 *  - 무료체험(trial)·둘러보기만 카운트다운 대상. 결제한 청첩장(1년·영구)은 active=false
 *  - 시각은 서버 기준 밀리초로 보내고, 에디터는 서버 시각과의 차이를 보정해서 센다 (휴대폰 시계가 틀려도 정확)
 * --------------------------------------------------------- */
function invite_expiry_info(array $inv): array
{
    $isDemo = empty($inv['customer_id']) && str_starts_with((string) ($inv['order_memo'] ?? ''), DEMO_MEMO);
    $exp = !empty($inv['expires_at']) ? strtotime((string) $inv['expires_at']) : false;
    $store = app_setting('store_url');
    return [
        'active'        => ($inv['storage_plan'] ?? '') === 'trial' && $exp !== false && empty($inv['deleted_at']),
        'expires_ms'    => $exp !== false ? $exp * 1000 : null,
        'now_ms'        => (int) round(microtime(true) * 1000),
        'demo'          => $isDemo,
        'member'        => !empty($inv['customer_id']),
        'slug'          => (string) ($inv['view_slug'] ?? ''),
        'store_url'     => preg_match('#^https?://#i', $store) ? $store : '',
        'countdown_min' => max(1, app_setting_int('editor_countdown_min') ?: 60),
        'trial_days'    => DEMO_CLAIM_TRIAL_DAYS,
        'grace_hours'   => (!empty($inv['customer_id']) && ($inv['storage_plan'] ?? '') === 'trial') ? trial_grace_hours() : 0, // 회원: 끝나도 이만큼 뒤에 휴지통으로
    ];
}

/** 옮긴 결과에 맞는 대시보드 주소. $explicit=false(로그인하면서 자동으로)일 땐 만료 안내는 띄우지 않는다 */
function demo_claim_redirect(array $r, bool $explicit = false): string
{
    switch ($r['result']) {
        case 'claimed': return 'dashboard.php?t=' . $r['token'] . '&claimed=1';
        case 'full':    return 'dashboard.php?demo_full=1';
        case 'gone':    return $explicit ? 'dashboard.php?demo_gone=1' : 'dashboard.php';
        default:        return 'dashboard.php';
    }
}

/* =========================================================
 * 글꾸미기(방명록) HTML 서버 필터 - 브라우저의 RichText.sanitize와 같은 규칙
 * 허용 태그: b i u s br div span / 허용 스타일: 글자색·형광펜·크기(em)·글꼴(목록)·정렬·굵게·기울임·밑줄·취소선
 * 그 밖의 태그는 껍데기만 벗기고 글자만 남기고, script 같은 위험한 태그는 내용째 버린다.
 * ======================================================= */
const RT_FONTS = [
    'gowun-batang' => '"Gowun Batang", serif', 'noto-serif-kr' => '"Noto Serif KR", serif', 'nanum-myeongjo' => '"Nanum Myeongjo", serif',
    'song-myung' => '"Song Myung", serif', 'gothic-a1' => '"Gothic A1", sans-serif', 'nanum-pen' => '"Nanum Pen Script", cursive',
    'nanum-brush' => '"Nanum Brush Script", cursive', 'gaegu' => '"Gaegu", cursive', 'hi-melody' => '"Hi Melody", cursive', 'gamja-flower' => '"Gamja Flower", cursive',
];

function rt_sanitize(string $html, int $maxLen = 6000): string
{
    $html = trim($html);
    if ($html === '' || strlen($html) > $maxLen * 3) return '';
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="rt-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $doc->getElementById('rt-root');
    if (!$root) return '';
    $out = rt_walk($root, 0);
    $out = preg_replace('/(<br>)+$/', '', $out);
    return strlen($out) > $maxLen ? '' : $out;
}

function rt_walk(DOMNode $node, int $depth): string
{
    static $tags = ['b' => 'b', 'strong' => 'b', 'i' => 'i', 'em' => 'i', 'u' => 'u', 's' => 's', 'strike' => 's', 'del' => 's', 'br' => 'br', 'div' => 'div', 'p' => 'div', 'span' => 'span', 'font' => 'span'];
    static $drop = ['script', 'style', 'template', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'textarea', 'select', 'button', 'input', 'img', 'video', 'audio', 'link', 'meta', 'title', 'head'];
    $out = '';
    foreach ($node->childNodes as $n) {
        if ($n instanceof DOMText) { $out .= htmlspecialchars($n->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); continue; }
        if (!($n instanceof DOMElement)) continue;
        $name = strtolower($n->nodeName);
        if (in_array($name, $drop, true)) continue;
        if (!isset($tags[$name]) || $depth > 12) { $out .= rt_walk($n, $depth); continue; }
        $tag = $tags[$name];
        if ($tag === 'br') { $out .= '<br>'; continue; }
        [$css, $font] = rt_style($n);
        $inner = rt_walk($n, $depth + 1);
        if ($tag === 'span' && !$css) { $out .= $inner; continue; }
        $out .= '<' . $tag . ($font ? ' data-f="' . $font . '"' : '') . ($css ? ' style="' . htmlspecialchars(implode(';', $css), ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . $inner . '</' . $tag . '>';
    }
    return $out;
}

/** 스타일 속성에서 허용한 것만 골라낸다. 반환: [css 목록, 글꼴 id] */
function rt_style(DOMElement $el): array
{
    $st = [];
    foreach (explode(';', (string) $el->getAttribute('style')) as $decl) {
        if (strpos($decl, ':') === false) continue;
        [$k, $v] = array_map('trim', explode(':', $decl, 2));
        $st[strtolower($k)] = $v;
    }
    if (strtolower($el->nodeName) === 'font') {
        if ($el->getAttribute('color')) $st['color'] = $el->getAttribute('color');
        if ($el->getAttribute('face')) $st['font-family'] = $el->getAttribute('face');
        $sizes = ['', 'x-small', 'small', 'medium', 'large', 'x-large', 'xx-large', 'xxx-large'];
        if ($el->getAttribute('size') !== '') $st['font-size'] = $sizes[(int) $el->getAttribute('size')] ?? '';
    }
    $color = fn($v) => preg_match('/^(#[0-9a-f]{3,8}|rgba?\(\s*[\d.]+%?\s*,\s*[\d.]+%?\s*,\s*[\d.]+%?\s*(,\s*[\d.]+\s*)?\)|transparent)$/i', trim($v)) ? trim($v) : '';
    $css = []; $font = '';
    if (!empty($st['color']) && ($c = $color($st['color'])) && $c !== 'transparent') $css[] = 'color:' . $c;
    if (!empty($st['background-color']) && ($c = $color($st['background-color']))) $css[] = 'background-color:' . $c;
    if (!empty($st['font-size'])) {
        $em = ['x-small' => .7, 'small' => .85, 'medium' => 1, 'large' => 1.2, 'x-large' => 1.5, 'xx-large' => 2, 'xxx-large' => 2.5];
        $v = strtolower($st['font-size']);
        if (isset($em[$v])) $css[] = 'font-size:' . $em[$v] . 'em';
        elseif (preg_match('/^(\d*\.?\d+)em$/', $v, $m)) $css[] = 'font-size:' . max(.6, min(3, (float) $m[1])) . 'em';
    }
    if (!empty($st['font-family'])) {
        $f = strtolower(str_replace(['"', "'"], '', $st['font-family']));
        foreach (RT_FONTS as $id => $fam) {
            if (strpos($f, strtolower(str_replace('"', '', explode(',', $fam)[0]))) !== false) { $css[] = 'font-family:' . $fam; $font = $id; break; }
        }
    }
    if (in_array($st['text-align'] ?? '', ['left', 'center', 'right'], true)) $css[] = 'text-align:' . $st['text-align'];
    if (preg_match('/^(bold|[6-9]00)$/', $st['font-weight'] ?? '')) $css[] = 'font-weight:bold';
    if (($st['font-style'] ?? '') === 'italic') $css[] = 'font-style:italic';
    preg_match_all('/underline|line-through/', ($st['text-decoration-line'] ?? '') . ' ' . ($st['text-decoration'] ?? ''), $dm);
    if ($dm[0]) $css[] = 'text-decoration:' . implode(' ', array_unique($dm[0]));
    return [$css, $font];
}

/** 방명록 테이블에 글꾸미기 칸(message_html)이 있는지 - stage4_setup.sql을 다시 실행하기 전에도 글쓰기가 멈추지 않게 */
function gb_has_html(PDO $pdo): bool
{
    static $has = null;
    if ($has === null) {
        try { $has = (bool) $pdo->query("SHOW COLUMNS FROM guestbook_entries LIKE 'message_html'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** 걸러낸 HTML → 글자만 (줄바꿈 유지) */
function rt_plain(string $safeHtml): string
{
    $t = preg_replace(['/<br>/', '/<div[^>]*>/'], ["\n", "\n"], $safeHtml);
    $t = html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

/* =========================================================
 * 방명록 금지어 필터 (관리자: admin_extra_settings.php → "방명록 금지어")
 * 글자 사이에 띄어쓰기·특수문자·숫자를 끼워 넣어도 걸린다 ("시 발", "시1발", "시.발")
 * ======================================================= */
function gb_banned_words(): array
{
    static $words = null;
    if ($words === null) {
        $words = [];
        foreach (preg_split('/[\r\n,]+/u', app_setting('gb_banned_words')) as $w) {
            $w = trim(preg_replace('/\s+/u', '', $w));
            if ($w !== '' && mb_strlen($w) <= 30) $words[mb_strtolower($w)] = true;
        }
        $words = array_slice(array_keys($words), 0, 1000);
    }
    return $words;
}
/** 금지어 하나 → 글자 사이 "끼워넣기"를 허용하는 정규식 */
function gb_word_regex(string $w): string
{
    $chars = array_map(fn($c) => preg_quote($c, '/'), preg_split('//u', $w, -1, PREG_SPLIT_NO_EMPTY));
    return '/' . implode('[\s\p{P}\p{S}\d_]{0,3}', $chars) . '/iu';
}
/** 글에 들어 있는 첫 번째 금지어 (없으면 '') */
function gb_find_banned(string $text): string
{
    foreach (gb_banned_words() as $w) if (preg_match(gb_word_regex($w), $text)) return $w;
    return '';
}
/** 금지어를 같은 길이의 *로 가림 (글자만) */
function gb_mask(string $text): string
{
    foreach (gb_banned_words() as $w) {
        $text = preg_replace_callback(gb_word_regex($w), fn($m) => str_repeat('*', max(2, mb_strlen(preg_replace('/[\s\p{P}\p{S}\d_]/u', '', $m[0])))), $text) ?? $text;
    }
    return $text;
}
/** 글꾸미기 HTML 안의 글자 부분만 가림 (태그는 건드리지 않음) */
function gb_mask_html(string $html): string
{
    if ($html === '') return '';
    $out = preg_replace_callback('/>([^<]+)</u', function ($m) {
        $plain = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '>' . htmlspecialchars(gb_mask($plain), ENT_QUOTES, 'UTF-8') . '<';
    }, '>' . $html . '<');
    return $out === null ? $html : substr($out, 1, -1);
}

/* =========================================================
 * 계좌번호·전화번호 보호 (봇 수집 방지) - guest_secure.php / invite_view.php
 *
 *  페이지 HTML에는 계좌번호·연락처(신랑신부·혼주 전화번호)를 아예 넣지 않는다.
 *  하객이 화면을 실제로 만지거나(스크롤·터치·클릭) 연락처 버튼을 누르면 그때 guest_secure.php에서 받아 채운다.
 *  받아가려면 아래를 모두 통과해야 한다:
 *   1) 이 청첩장 페이지를 연 브라우저가 받은 "페이지 입장권"(12시간, 쿠키와 짝) - 주소만 알고 직접 요청하면 거절
 *   2) POST + 같은 사이트에서 보낸 요청 (다른 사이트·스크립트에서 몰래 부르기 차단)
 *   3) 검색엔진·수집 프로그램(봇) 이름이면 거절
 *   4) IP당 10분에 60번, 하루에 서로 다른 청첩장 GS_MAX_INV_PER_IP_DAY개까지 - 여러 청첩장을 훑어가는 수집 차단
 * ======================================================= */
const GUEST_SECURE_MARK = '__LD_SECURE__';   // 페이지에 내려보낼 때 전화번호 자리에 대신 넣는 표시
const GUEST_TOKEN_HOURS = 12;                // 페이지 입장권 유효 시간
const GS_MAX_INV_PER_IP_DAY = 30;            // 한 IP가 하루에 번호를 받아갈 수 있는 서로 다른 청첩장 수
const GUEST_COOKIE = 'ld_g';

/** 연락하기 섹션의 전화번호 칸(…Phone)을 표시로 바꾼 디자인. 빈 칸은 그대로 빈 칸 (버튼 안 보임) */
function guest_secure_strip(array $design): array
{
    foreach (($design['blocks'] ?? []) as $i => $b) {
        if (!is_array($b) || !str_starts_with((string) ($b['id'] ?? ''), 'contact') || !is_array($b['fields'] ?? null)) continue;
        foreach ($b['fields'] as $k => $v) {
            if (str_ends_with((string) $k, 'Phone') && is_string($v) && preg_replace('/[^0-9]/', '', $v) !== '') {
                $design['blocks'][$i]['fields'][$k] = GUEST_SECURE_MARK;
            }
        }
    }
    return $design;
}

/** 원래 디자인에서 연락처 번호만 뽑기 (키: 칸 이름, 값: 숫자와 +만) */
function guest_secure_phones(array $design): array
{
    $out = [];
    foreach (($design['blocks'] ?? []) as $b) {
        if (!is_array($b) || !str_starts_with((string) ($b['id'] ?? ''), 'contact') || !is_array($b['fields'] ?? null)) continue;
        foreach ($b['fields'] as $k => $v) {
            if (str_ends_with((string) $k, 'Phone') && is_string($v)) {
                $d = preg_replace('/[^0-9+]/', '', $v);
                if ($d !== '') $out[$k] = $d;
            }
        }
    }
    return $out;
}

/** 이 브라우저용 페이지 입장권. 쿠키가 없으면 만든다 (그래서 HTML을 내보내기 전에 불러야 함) */
function guest_page_token(int $invitationId): string
{
    $c = (string) ($_COOKIE[GUEST_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $c)) {
        $c = bin2hex(random_bytes(16));
        if (!headers_sent()) setcookie(GUEST_COOKIE, $c, ['expires' => time() + 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE[GUEST_COOKIE] = $c;
    }
    $ts = time();
    return $invitationId . '.' . $ts . '.' . substr(hash_hmac('sha256', "{$invitationId}|{$ts}|{$c}", ENCRYPTION_KEY), 0, 32);
}

function guest_token_ok(string $token, int $invitationId): bool
{
    if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{32})$/', $token, $m)) return false;
    $c = (string) ($_COOKIE[GUEST_COOKIE] ?? '');
    if ((int) $m[1] !== $invitationId || $c === '') return false;
    $age = time() - (int) $m[2];
    if ($age < 0 || $age > GUEST_TOKEN_HOURS * 3600) return false;
    return hash_equals(substr(hash_hmac('sha256', "{$m[1]}|{$m[2]}|{$c}", ENCRYPTION_KEY), 0, 32), $m[3]);
}

/** 검색엔진·수집 프로그램·자동화 도구로 보이는 접속 */
function guest_is_bot(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || strlen($ua) < 20) return true;
    return (bool) preg_match('/bot\b|crawl|spider|slurp|Yeti|Daumoa|facebookexternalhit|kakaotalk-scrap|Bingpreview|python|curl|wget|scrapy|httpclient|okhttp|Go-http|java\/|node-fetch|axios|HeadlessChrome|PhantomJS|Puppeteer|Playwright|Selenium|libwww|Postman/i', $ua);
}

/** 같은 사이트에서 보낸 요청인지 (브라우저가 붙여 보내는 Sec-Fetch-Site / Origin으로 확인) */
function guest_same_origin(): bool
{
    $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($site !== '' && $site !== 'same-origin') return false;
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (strtolower((string) parse_url($origin, PHP_URL_HOST)) !== preg_replace('/:\d+$/', '', $host)) return false;
    }
    return ($_SERVER['HTTP_X_LD_GUEST'] ?? '') === '1'; // 우리 스크립트가 붙이는 표시 (다른 사이트의 폼 전송으로는 못 붙임)
}

/** 한 IP가 너무 많이, 너무 여러 청첩장에서 받아가면 false */
function guest_secure_rate_ok(PDO $pdo, int $invitationId): bool
{
    $ip = client_ip();
    if (!check_rate_limit($pdo, 'gs_req_' . $ip, 60, 600)) return false;
    $seen = 'gs_seen_' . substr(hash('sha256', $ip . '|' . $invitationId), 0, 40);
    $st = $pdo->prepare('SELECT window_start FROM rate_limits WHERE bucket = ?');
    $st->execute([$seen]);
    $ws = $st->fetchColumn();
    if ($ws === false || strtotime((string) $ws) < time() - 86400) { // 오늘 처음 보는 청첩장이면 "서로 다른 청첩장 수"에 더함
        if (!check_rate_limit($pdo, 'gs_many_' . $ip, GS_MAX_INV_PER_IP_DAY, 86400)) return false;
        $pdo->prepare('INSERT INTO rate_limits (bucket, attempts, window_start) VALUES (?, 1, NOW())
                       ON DUPLICATE KEY UPDATE attempts = 1, window_start = NOW()')->execute([$seen]);
    }
    return true;
}

/* =========================================================
 * 신혼여행 라이브 (에디터 섹션 "신혼여행 라이브" - 섹션 id: trip)
 *  신랑신부: trip_manage.php(사진·체크인 올리기) → trip_post.php / trip_geo.php(장소 찾기)
 *  하객:     청첩장의 섹션이 trip_feed.php에서 소식을 받아 지도 + 타임라인으로 보여줌
 *  위치는 도시 수준(소수점 한 자리)으로만 저장하고, 섹션 설정에 따라 몇 시간 늦게 공개할 수 있다.
 * ======================================================= */
const TRIP_MAX_PHOTOS = 6;      // 소식 1건에 붙일 수 있는 사진 수
const TRIP_MAX_POSTS  = 300;    // 청첩장 1개에 올릴 수 있는 소식 수

function trip_dir(int $invitationId): string { return UPLOAD_DIR . $invitationId . '/trip/'; }
function trip_url(int $invitationId, string $file): string { return '/invite/uploads/' . $invitationId . '/trip/' . $file; }

/** 섹션 설정 (없으면 null). enabled = 에디터에서 섹션을 켰는지 */
function trip_block(array $invite): ?array
{
    $d = snap_design($invite);
    foreach (($d['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') === 'trip') {
            $f = is_array($b['fields'] ?? null) ? $b['fields'] : [];
            return ['enabled' => !empty($b['enabled']), 'delay' => max(0, min(72, (int) ($f['delayHours'] ?? 0))), 'startDate' => (string) ($f['startDate'] ?? '')];
        }
    }
    return null;
}

/** DB 행 → 화면에 보낼 모양 */
function trip_post_out(array $r, int $invitationId, bool $owner = false): array
{
    $photos = [];
    foreach ((array) json_decode((string) ($r['photos'] ?? ''), true) as $p) {
        if (is_array($p) && preg_match('/^[a-f0-9]{32}\.webp$/', (string) ($p['f'] ?? ''))) {
            $photos[] = ['src' => trip_url($invitationId, $p['f']), 'w' => (int) ($p['w'] ?? 0), 'h' => (int) ($p['h'] ?? 0)];
        }
    }
    $out = [
        'id'      => (int) $r['id'],
        'photos'  => $photos,
        'caption' => (string) ($r['caption'] ?? ''),
        'place'   => (string) ($r['place'] ?? ''),
        'lat'     => $r['lat'] !== null ? (float) $r['lat'] : null,
        'lng'     => $r['lng'] !== null ? (float) $r['lng'] : null,
        'at'      => strtotime((string) $r['created_at']) * 1000,
    ];
    if ($owner) $out['visible_at'] = strtotime((string) $r['visible_at']) * 1000;
    return $out;
}

/** 장소 이름 ↔ 좌표 (OpenStreetMap Nominatim). 결과는 도시 수준으로 줄여서 돌려준다. 실패하면 null */
function trip_geocode(array $params): ?array
{
    $root = defined('TRIP_GEOCODE_BASE') ? rtrim((string) TRIP_GEOCODE_BASE, '/') : 'https://nominatim.openstreetmap.org'; // 테스트 서버에서만 config.php로 바꿈
    $base = $root . (isset($params['q']) ? '/search' : '/reverse');
    $query = $params + ['format' => 'jsonv2', 'accept-language' => 'ko,en', 'zoom' => 10, 'limit' => 1, 'addressdetails' => 1];
    $ch = curl_init($base . '?' . http_build_query($query));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['User-Agent: LOVEDAY-invitation/1.0 (https://loveday.kr)']]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) return null;
    $j = json_decode((string) $body, true);
    if (isset($params['q'])) $j = is_array($j) ? ($j[0] ?? null) : null;
    if (!is_array($j) || !isset($j['lat'], $j['lon'])) return null;
    $a = is_array($j['address'] ?? null) ? $j['address'] : [];
    $city = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? $a['state'] ?? ($j['name'] ?? '');
    $country = $a['country'] ?? '';
    $place = trim($city . ($country !== '' && $country !== $city ? ', ' . $country : ''), ', ');
    return ['place' => mb_substr($place, 0, 80), 'lat' => round((float) $j['lat'], 1), 'lng' => round((float) $j['lon'], 1)];
}

/* =========================================================
 * 식장 추첨 (에디터 섹션 "행운의 추첨" - 섹션 id: lottery) - lottery.php / lottery_manage.php
 *  참여 방식 (섹션 설정 method):
 *   code : 사회자가 알려주는 4자리 현장 코드를 넣은 하객만 (코드는 lottery_state에만 있고 청첩장 HTML에는 없음)
 *   snap : 게스트스냅에 사진을 올린 하객이 자동 응모
 *   open : 응모 버튼을 누른 하객 누구나
 *  응모 받는 시간: 자동이면 예식 1시간 전 ~ 4시간 뒤 (섹션의 "예식 시간에만" 옵션), 진행 화면에서 지금 열기/마감으로 바꿀 수 있음
 *  당첨 알림: 청첩장을 열어 둔 하객 화면이 몇 초마다 확인해서, 당첨되면 전체 화면으로 "당첨!"을 띄운다
 * ======================================================= */
const LOTTERY_BEFORE_MIN = 60;   // 예식 몇 분 전부터 응모 받기
const LOTTERY_AFTER_MIN  = 240;  // 예식 시작 몇 분 뒤까지

/** 섹션 설정 (섹션이 없으면 null). enabled 포함 */
function lottery_block(array $invite): ?array
{
    $d = snap_design($invite);
    foreach (($d['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') !== 'lottery') continue;
        $f = (array) ($b['fields'] ?? []);
        $m = in_array($f['method'] ?? '', ['code', 'snap', 'open'], true) ? $f['method'] : 'code';
        return ['enabled' => !empty($b['enabled']), 'method' => $m, 'timeLimit' => ($f['timeLimit'] ?? true) !== false, 'prizes' => (string) ($f['prizes'] ?? '')];
    }
    return null;
}

/** 예식 시작 시각(유닉스 초). 디데이 섹션의 날짜·시각 → 주문 정보의 예식 일시 순서. 모르면 null */
function lottery_wedding_ts(array $invite): ?int
{
    $tz = new DateTimeZone('Asia/Seoul');
    foreach ((snap_design($invite)['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') === 'dday' && !empty($b['fields']['year'])) {
            $f = $b['fields'];
            return (new DateTimeImmutable('now', $tz))->setDate((int) $f['year'], (int) $f['month'], (int) $f['day'])->setTime((int) ($f['hour'] ?? 12), (int) ($f['minute'] ?? 0))->getTimestamp();
        }
    }
    return !empty($invite['wedding_datetime']) ? (new DateTimeImmutable((string) $invite['wedding_datetime'], $tz))->getTimestamp() : null;
}

/** 진행 상태 (없으면 만든다 - 현장 코드는 이때 한 번 정해짐) */
function lottery_state(PDO $pdo, int $invitationId): array
{
    $st = $pdo->prepare('SELECT * FROM lottery_state WHERE invitation_id = ?');
    $st->execute([$invitationId]);
    $row = $st->fetch();
    if ($row) return $row;
    $pdo->prepare('INSERT IGNORE INTO lottery_state (invitation_id, code, updated_at) VALUES (?, ?, NOW())')
        ->execute([$invitationId, str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)]);
    $st->execute([$invitationId]);
    return $st->fetch();
}

/** 지금 응모를 받는지: [상태(before/open/closed), 여는 시각, 닫는 시각] */
function lottery_phase(array $invite, array $blk, array $state): array
{
    if ($state['manual'] === 'open') return ['open', null, null];
    if ($state['manual'] === 'closed') return ['closed', null, null];
    if ($blk['method'] === 'snap' || !$blk['timeLimit']) return ['open', null, null];
    $ts = lottery_wedding_ts($invite);
    if (!$ts) return ['open', null, null]; // 예식 시각을 모르면 막지 않음
    $from = $ts - LOTTERY_BEFORE_MIN * 60; $to = $ts + LOTTERY_AFTER_MIN * 60;
    return [time() < $from ? 'before' : (time() > $to ? 'closed' : 'open'), $from, $to];
}

/** 겹치지 않는 4자리 응모 번호 */
function lottery_new_no(PDO $pdo, int $invitationId): string
{
    $st = $pdo->prepare('SELECT 1 FROM lottery_entries WHERE invitation_id = ? AND entry_no = ?');
    for ($i = 0; $i < 40; $i++) {
        $no = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $st->execute([$invitationId, $no]);
        if (!$st->fetchColumn()) return $no;
    }
    return str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/** 게스트스냅 방식: 사진을 올린 하객을 응모자로 옮겨 담기 ($guestKey를 주면 그 하객만) */
function lottery_sync_snap(PDO $pdo, int $invitationId, ?string $guestKey = null): void
{
    $sql = 'SELECT guest_key, MAX(COALESCE(guest_name, \'\')) AS name FROM guest_snaps WHERE invitation_id = ?' . ($guestKey ? ' AND guest_key = ?' : '') . ' GROUP BY guest_key';
    $st = $pdo->prepare($sql);
    $st->execute($guestKey ? [$invitationId, $guestKey] : [$invitationId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO lottery_entries (invitation_id, guest_key, name, entry_no, source) VALUES (?, ?, ?, ?, 'snap')");
    foreach ($st->fetchAll() as $r) {
        $name = trim((string) $r['name']) !== '' ? mb_substr(trim((string) $r['name']), 0, 20) : '사진 올린 하객';
        $ins->execute([$invitationId, $r['guest_key'], $name, lottery_new_no($pdo, $invitationId)]);
    }
}

/** 화면에 보여줄 이름 (가운데 가림: 김민수 → 김*수, 민수 → 민*) */
function lottery_mask(string $name): string
{
    $n = mb_strlen($name);
    if ($n <= 1) return $name;
    if ($n === 2) return mb_substr($name, 0, 1) . '*';
    return mb_substr($name, 0, 1) . str_repeat('*', $n - 2) . mb_substr($name, -1);
}

/** 라운드별 당첨자 [{round, prize, winners:[{name, no}]}] */
function lottery_rounds(PDO $pdo, int $invitationId, bool $fullNames = false): array
{
    $st = $pdo->prepare('SELECT won_round, prize, name, entry_no FROM lottery_entries WHERE invitation_id = ? AND won_round IS NOT NULL ORDER BY won_round, won_at, id');
    $st->execute([$invitationId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $k = (int) $r['won_round'];
        $out[$k] ??= ['round' => $k, 'prize' => (string) $r['prize'], 'winners' => []];
        $out[$k]['winners'][] = ['name' => $fullNames ? $r['name'] : lottery_mask((string) $r['name']), 'no' => $r['entry_no']];
    }
    return array_values($out);
}
