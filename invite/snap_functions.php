<?php
/**
 * snap_functions.php - 게스트스냅 공통 함수 + 관리자 설정(app_settings) 헬퍼
 * functions.php를 고치지 않으려고 따로 뺐다. 게스트스냅 관련 파일들은 전부 이 파일 하나만 require 하면 된다.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
date_default_timezone_set('Asia/Seoul'); // 업로드 기간·삭제일을 한국 날짜 기준으로 계산 (서버가 UTC여도)

/* =========================================================
 * 관리자 설정 (app_settings 테이블, 키-값)
 * ======================================================= */
const APP_SETTING_DEFAULTS = [
    'snap_quota_invite_mb' => '50',
    'snap_quota_guest_mb'  => '50',
    'snap_max_file_mb'     => '10',
    'snap_days_before'     => '0',
    'snap_days_after'      => '5',
    'snap_retention_days'  => '15',
    'snap_download_limit'  => '2',
    'bgm_max_mb'           => '10',   // 배경음악 직접 올리기 최대 용량
    'rsvp_retention_days'  => '90',   // 예식일 며칠 후에 참석여부 명단(이름·연락처) 자동 삭제
    'demo_hours'           => '24',   // 회원가입 없이 만든 체험 청첩장 보관 시간
    'demo_max_active'      => '300',  // 동시에 존재할 수 있는 체험 청첩장 최대 수 (서버 보호)
    'editor_countdown_min' => '60',   // 편집 화면 가운데에 삭제 카운트다운이 뜨기 시작하는 남은 시간(분) - 무료체험·둘러보기만
    'trial_grace_hours'    => '24',   // 무료체험이 끝난 뒤 휴지통으로 옮기기까지 유예 시간 (네이버 로그인 회원만 - 비회원 둘러보기는 바로 완전 삭제)
    'trash_keep_days'      => '30',   // 휴지통 보관 기간 (지나면 사진 폴더까지 완전 삭제)
    // 방명록 금지어 (관리자 설정 → 한 줄에 하나 또는 쉼표로 구분). 띄어쓰기·특수문자·숫자를 끼워 넣어도 걸러진다(예: "시 1 발")
    'gb_banned_words'      => "시발\n씨발\n씨바\n씨팔\n시팔\nㅅㅂ\nㅆㅂ\n개새끼\n개새\n병신\nㅂㅅ\nㅄ\n좆\n존나\n졸라\n지랄\n미친놈\n미친년\n염병\n썅\n느금마\n니미\n섹스\n야동\n카지노\n바카라\n토토사이트\nfuck\nshit\nbitch",
    'gb_filter_mode'       => 'block', // block = 금지어가 있으면 글을 안 받음 / mask = 금지어만 ***로 가리고 받음
    // ---- 사이트 정보 (관리자 → 사이트 정보: admin_site_settings.php) - 홈페이지 가격표·하단 사업자 정보 ----
    'price_one_year'       => '',     // 1년 보관 가격 (숫자, 원). 비우면 "문의"로 표시
    'price_permanent'      => '',     // 영구 보관 가격
    'store_url'            => '',     // 구매 버튼이 열 스토어 주소 (네이버 스마트스토어 등)
    'biz_name'             => '',     // 상호
    'biz_owner'            => '',     // 대표자
    'biz_number'           => '',     // 사업자등록번호
    'biz_mailorder'        => '',     // 통신판매업 신고번호 (선택)
    'biz_address'          => '',     // 사업장 주소 (선택)
    'biz_contact'          => '',     // 고객 문의 안내 (예: 스토어 톡톡 / 전화 / 이메일)
    'terms_url'            => '',     // 이용약관 주소 (선택)
    'home_sample_ids'      => '',     // 홈페이지 샘플 카드에 보일 프리셋 id (쉼표, 최대 6개). 비우면 앞에서부터 6개
    'home_sample_url'      => '/testbed', // "샘플 청첩장 직접 보기" 버튼 주소
    'privacy_officer'      => '',     // 개인정보 보호책임자 (비우면 대표자)
    'privacy_email'        => '',     // 보호책임자 연락처 (비우면 고객 문의)
    'privacy_effective'    => '',     // 개인정보처리방침 시행일 (YYYY-MM-DD)
];

function app_setting(string $key): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = APP_SETTING_DEFAULTS;
        try {
            foreach (get_pdo()->query('SELECT setting_key, setting_value FROM app_settings') as $row) {
                $cache[$row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (Throwable $e) {
            // 테이블이 아직 없으면(snap_setup.sql 실행 전) 기본값으로 동작
        }
    }
    return $cache[$key] ?? '';
}
function app_setting_int(string $key): int { return (int) app_setting($key); }

function save_app_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')->execute([$key, $value]);
}

/* =========================================================
 * 게스트스냅
 * ======================================================= */
function snap_dir(int $invitationId): string
{
    return UPLOAD_DIR . 'snap/' . $invitationId . '/';
}

function snap_design(array $invite): ?array
{
    if (empty($invite['design_json'])) return null;
    $d = json_decode($invite['design_json'], true);
    return is_array($d) ? $d : null;
}

/** 에디터에서 "게스트스냅" 섹션을 켜 둔 청첩장인지 */
function snap_enabled(array $invite): bool
{
    $d = snap_design($invite);
    foreach (($d['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') === 'guestsnap') return !empty($b['enabled']);
    }
    return false;
}

/** 예식일 (날짜만) - invitation_orders.wedding_datetime 우선, 없으면 에디터의 디데이 섹션 날짜 */
function snap_wedding_date(array $invite): ?DateTimeImmutable
{
    if (!empty($invite['wedding_datetime'])) {
        $t = strtotime((string) $invite['wedding_datetime']);
        if ($t) return (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    }
    $d = snap_design($invite);
    foreach (($d['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') === 'dday' && !empty($b['fields']['year'])) {
            $f = $b['fields'];
            return (new DateTimeImmutable())->setDate((int) $f['year'], (int) $f['month'], (int) $f['day'])->setTime(0, 0);
        }
    }
    return null;
}

/**
 * 업로드 가능 기간 / 삭제 예정일 계산
 * 반환: ['wedding'=>, 'open'=>, 'close'=>, 'delete'=>, 'state'=>'before|open|closed|expired|nodate']
 */
function snap_schedule(array $invite): array
{
    $w = snap_wedding_date($invite);
    if (!$w) return ['state' => 'nodate'];
    $open   = $w->modify('-' . app_setting_int('snap_days_before') . ' days');
    $close  = $w->modify('+' . (app_setting_int('snap_days_after') + 1) . ' days');    // 그날 자정까지
    $delete = $w->modify('+' . (app_setting_int('snap_retention_days') + 1) . ' days');
    $now = new DateTimeImmutable();
    // 에디터 게스트스냅 옵션 "예식 전에도 올릴 수 있게" - 켜면 업로드 시작일을 기다리지 않고 지금부터 받는다 (마감·보관 기간은 그대로)
    $early = false;
    foreach ((snap_design($invite)['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') === 'guestsnap') { $early = !empty($b['fields']['earlyUpload']); break; }
    }
    if ($early && $now < $open) $open = $now;
    $state = $now < $open ? 'before' : ($now < $close ? 'open' : ($now < $delete ? 'closed' : 'expired'));
    return ['wedding' => $w, 'open' => $open, 'close' => $close, 'delete' => $delete, 'state' => $state, 'early' => $early];
}

function snap_usage(PDO $pdo, int $invitationId, ?string $guestKey = null): array
{
    $total = (int) $pdo->query('SELECT COALESCE(SUM(bytes),0) FROM guest_snaps WHERE invitation_id = ' . $invitationId)->fetchColumn();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM guest_snaps WHERE invitation_id = ' . $invitationId)->fetchColumn();
    $guest = 0;
    if ($guestKey !== null) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(bytes),0) FROM guest_snaps WHERE invitation_id = ? AND guest_key = ?');
        $st->execute([$invitationId, $guestKey]);
        $guest = (int) $st->fetchColumn();
    }
    return ['total' => $total, 'count' => $count, 'guest' => $guest,
            'quota_invite' => app_setting_int('snap_quota_invite_mb') * 1048576,
            'quota_guest'  => app_setting_int('snap_quota_guest_mb') * 1048576];
}

function snap_download_count(PDO $pdo, int $invitationId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM guest_snap_downloads WHERE invitation_id = ?');
    $st->execute([$invitationId]);
    return (int) $st->fetchColumn();
}

/** 하객 브라우저 구분 쿠키 (1인당 한도용) */
function snap_guest_key(): string
{
    $k = (string) ($_COOKIE['snap_gk'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $k)) {
        $k = bin2hex(random_bytes(16));
        setcookie('snap_gk', $k, ['expires' => time() + 60 * 86400, 'path' => '/invite/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    }
    return $k;
}

/** 사진 검증 + 재인코딩(악성 데이터 제거, 위치정보 등 EXIF도 지워짐) + 1920px로 축소 + webp 저장. 워터마크 없음. [파일명, 바이트, 가로, 세로]
 *  $dir: 저장할 폴더 (비우면 게스트스냅 폴더) - 신혼여행 라이브(trip_post.php)도 같이 쓴다 */
function snap_store_image(array $file, int $invitationId, ?string $dir = null): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('업로드 중 오류가 발생했습니다.');
    if ($file['size'] > app_setting_int('snap_max_file_mb') * 1048576) throw new RuntimeException('사진 1장은 ' . app_setting_int('snap_max_file_mb') . 'MB 이하만 올릴 수 있어요.');
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('사진(jpg, png, webp)만 올릴 수 있어요. 영상은 받지 않습니다.');
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) throw new RuntimeException('사진 파일을 읽을 수 없어요.');
    [$w, $h] = $info;
    $ratio = min(1, 1920 / max($w, $h));
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) throw new RuntimeException('사진을 처리할 수 없어요.');
    // 휴대폰 사진의 회전 정보(EXIF) 반영 - 안 하면 세로 사진이 눕혀져 저장된다
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) { $r = imagerotate($src, $rot, 0); imagedestroy($src); $src = $r; [$w, $h] = [imagesx($src), imagesy($src)];
            $ratio = min(1, 1920 / max($w, $h)); $nw = max(1, (int) round($w * $ratio)); $nh = max(1, (int) round($h * $ratio)); }
    }
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $mode = $dir === null ? 0750 : 0755; // 게스트스냅 폴더는 예전처럼
    $dir = $dir ?? snap_dir($invitationId);
    if (!is_dir($dir) && !mkdir($dir, $mode, true) && !is_dir($dir)) throw new RuntimeException('저장 폴더를 만들 수 없어요.');
    $name = bin2hex(random_bytes(16)) . '.webp';
    imagewebp($dst, $dir . $name, 80);
    imagedestroy($dst);
    clearstatcache(true, $dir . $name);
    return [$name, (int) filesize($dir . $name), $nw, $nh];
}

/** 한 청첩장의 게스트스냅 사진·기록 전부 삭제 */
function snap_delete_all(PDO $pdo, int $invitationId): int
{
    $dir = snap_dir($invitationId);
    $n = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '*.webp') ?: [] as $f) { @unlink($f); $n++; }
        @rmdir($dir);
    }
    $pdo->prepare('DELETE FROM guest_snaps WHERE invitation_id = ?')->execute([$invitationId]);
    return $n;
}

/** 편집 토큰으로 신랑신부 본인 확인 (invite_load.php와 같은 기준: 토큰 + PIN 세션) */
function snap_owner_invite(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $invite = find_invitation_by_token($pdo, $token);
    if (!$invite || !empty($invite['deleted_at'])) return null;
    if (!empty($invite['edit_pin_hash']) && empty($_SESSION['pin_ok_' . $invite['id']])) return null;
    // 기간이 끝난 청첩장(회원 무료체험 삭제 유예 중 등)은 결제 확인·남은 시간 조회 말고는 전부 잠금
    if (invite_owner_locked($pdo, $invite) && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), OWNER_LOCK_ALLOW, true)) invite_owner_lock_fail($invite);
    return $invite;
}

/** 기간이 끝나도 쓸 수 있는 주인 통로 (결제하면 바로 풀려야 하니까) */
const OWNER_LOCK_ALLOW = ['pay_check.php', 'invite_expiry.php', 'dashboard.php'];

/**
 * 주인 기능이 잠긴 청첩장인지 - 휴지통에는 아직 없지만 보관 기간이 끝난 것
 * (네이버 회원 무료체험의 삭제 유예 24시간, 기간이 끝난 1년 보관). 결제·기간 연장하면 바로 풀린다.
 */
function invite_owner_locked(PDO $pdo, array $inv): bool
{
    return empty($inv['deleted_at']) && !invite_is_live($pdo, $inv);
}

/** 잠긴 청첩장에 주인 기능을 쓰려 할 때 - AJAX/POST면 JSON, 아니면 안내 화면 (결제 버튼) */
function invite_owner_lock_fail(array $inv): void
{
    $trial = ($inv['storage_plan'] ?? '') === 'trial';
    $msg = $trial ? '무료체험 기간이 끝나 잠겼어요. 결제하면 바로 다시 쓸 수 있어요.' : '보관 기간이 끝나 잠겼어요. 계속 쓰려면 고객센터로 문의해주세요.';
    $payUrl = 'dashboard.php?t=' . rawurlencode((string) $inv['edit_token']) . ($trial ? '&pay=1' : '');
    http_response_code(403);
    header('Cache-Control: no-store');
    $json = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || isset($_GET['ajax']) || isset($_GET['a'])
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'locked' => true, 'error' => $msg, 'pay_url' => $payUrl], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>잠긴 청첩장</title>'
       . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F6F4F1;font-family:"Pretendard Variable",Pretendard,-apple-system,sans-serif;color:#1B1A18;padding:24px;box-sizing:border-box;word-break:keep-all}'
       . '.b{background:#fff;border:1px solid #EAE6E0;border-radius:22px;padding:36px 28px;max-width:360px;text-align:center;line-height:1.7;box-shadow:0 18px 40px rgba(40,25,20,.08)}.i{font-size:34px}h1{margin:8px 0;font-size:19px}p{margin:0 0 22px;color:#6F6A63;font-size:14px}'
       . 'a{display:block;padding:13px 20px;border-radius:12px;text-decoration:none;font-weight:700;font-size:14.5px}a.pay{background:#03C75A;color:#fff}a.back{margin-top:8px;color:#6F6A63;font-weight:500}</style></head>'
       . '<body><div class="b"><div class="i">🔒</div><h1>' . ($trial ? '무료체험 기간이 끝났어요' : '보관 기간이 끝났어요') . '</h1><p>' . $h($msg) . '</p>'
       . ($trial ? '<a class="pay" href="' . $h($payUrl) . '">💳 결제하고 계속 쓰기</a>' : '') . '<a class="back" href="dashboard.php?t=' . $h(rawurlencode((string) $inv['edit_token'])) . '">내 청첩장으로</a></div></body></html>';
    exit;
}

/**
 * 하객에게 열어 줘도 되는 청첩장인지 - 삭제 안 됨 + 보관 기간이 안 지남.
 * 기간 비교는 DB 시각으로 한다 (PHP와 DB 시간대가 어긋나 있어도 정확).
 * 짧은 주소(loveday.kr/코드)·게스트스냅·계좌/연락처·썸네일·신혼여행 소식 등 공개 통로가 모두 이걸 거친다.
 */
function invite_is_live(PDO $pdo, array $inv): bool
{
    if (!empty($inv['deleted_at'])) return false;
    if (empty($inv['expires_at'])) return true;
    $st = $pdo->prepare('SELECT expires_at > NOW() FROM invitation_orders WHERE id = ?');
    $st->execute([(int) $inv['id']]);
    return (bool) $st->fetchColumn();
}

function snap_fmt_mb(int $bytes): string { return number_format($bytes / 1048576, 1) . 'MB'; }
function snap_h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
