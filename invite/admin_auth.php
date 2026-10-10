<?php
/**
 * admin_auth.php - 대표 관리자 / 부관리자 권한
 *
 *  대표 관리자: config.php의 ADMIN_PASSWORD_HASH로 로그인 (아이디 칸은 비워 둠). 모든 권한.
 *  부관리자   : 대표 관리자가 "부관리자" 메뉴(admin_subadmins.php)에서 아이디·비밀번호를 만들어 줌.
 *               권한은 항목별로 켜고 끔 (ADMIN_PERMS). 바꾸면 부관리자의 다음 클릭부터 바로 적용.
 *
 *  admin_guard.php가 이 파일을 불러서
 *   - 부관리자 계정이 꺼졌거나 지워졌거나 비밀번호가 바뀌었으면 로그아웃
 *   - 화면별 권한 확인 (ADMIN_PAGE_RULES) - 권한 없는 저장·삭제 요청은 서버에서 막음
 *
 *  표(admin_users)는 처음 쓸 때 자동으로 만들어진다 (따로 SQL 실행 필요 없음).
 *   CREATE TABLE admin_users (id, username, display_name, password_hash, perms(JSON), active, created_at, last_login_at, pw_changed_at)
 */
declare(strict_types=1);

/** 부관리자 권한 [키 => [묶음, 이름, 설명, 새 부관리자 기본값]] - true면 "할 수 있음" */
const ADMIN_PERMS = [
    'invite_delete'    => ['청첩장', '청첩장 삭제', '목록에서 삭제 (휴지통으로 보내기)', true],
    'plan_edit'        => ['청첩장', '결제·보관 기간 처리', '결제 확인 → 1년/영구 지정, 체험으로 되돌리기 (워터마크 해제 포함)', true],
    'expiry_edit'      => ['청첩장', '무료 시간 수정', '자동 삭제까지 남은 시간을 직접 바꾸기', false],
    'vip_create'       => ['청첩장', 'VIP 코드 생성', '지정 코드로 VIP 청첩장 만들기', true],
    'customer_private' => ['개인정보', '회원정보·전화번호·계좌 보기', '연락처, 고객코드 변경, 편집 링크(계좌정보가 보이는 고객 편집 화면)', false],
    'customer_photos'  => ['개인정보', '고객이 올린 사진 보기', '청첩장 수정 화면의 사진 목록 보기·삭제', false],
    'trash_restore'    => ['휴지통', '휴지통 복원', '휴지통에서 청첩장 되살리기', true],
    'trash_purge'      => ['휴지통', '휴지통 완전 삭제', '영구삭제·휴지통 비우기 (되돌릴 수 없음)', false],
    'watermark'        => ['설정', '워터마크 수정', '워터마크 문구·크기·각도 저장', false],
    'music_delete'     => ['설정', '배경음악 삭제', '고객이 올린 음악 삭제', false],
    'snap_settings'    => ['설정', '게스트스냅 설정 수정', '용량·업로드 기간·보관 기간 저장', false],
    'extra_settings'   => ['설정', '부가기능 설정 수정', '체험 시간·유예·휴지통 기간·방명록 금지어 저장', false],
    'site_settings'    => ['설정', '사업자·사이트 정보 수정', '가격·스토어 주소·사업자 정보, 홈페이지·로그인 화면 사진, 섹션 순서, 에디터 도움말 저장', false],
    'naver_orders'     => ['설정', '스마트스토어 주문 보기·처리', '주문 목록(구매자 이름 포함) 보기, 동기화, 수동 적용 (API 키 변경은 대표만)', false],
];

/**
 * 화면별 규칙 [파일 => [권한, 방식]]
 *  방식 'post' = 보기는 되고 저장(POST)만 막음 → 화면 위에 "보기 전용" 안내 + 저장 버튼 잠금
 *       'all'  = 화면 자체를 막음 (메뉴에서도 숨김)
 *       권한이 'owner'면 대표 관리자만
 * admin_edit.php / admin_trash.php는 버튼마다 달라서 admin_guard.php가 따로 본다.
 */
const ADMIN_PAGE_RULES = [
    'admin_subadmins.php'         => ['owner', 'all'],
    'admin_timezone.php'          => ['owner', 'all'],
    'debug_account_crypto.php'    => ['owner', 'all'],
    'admin_watermark.php'         => ['watermark', 'post'],
    'admin_watermark_preview.php' => ['watermark', 'post'],
    'admin_site_settings.php'     => ['site_settings', 'post'],
    'admin_home_images.php'       => ['site_settings', 'post'],   // 홈페이지·로그인 화면 사진
    'admin_sections.php'          => ['site_settings', 'post'],   // 에디터 섹션 기본 순서
    'admin_stickers.php'          => ['site_settings', 'post'],   // 에디터 스티커 창 설정
    'admin_tips.php'              => ['site_settings', 'post'],   // 에디터 도움말
    'admin_tip_tours.php'         => ['site_settings', 'post'],   // 커스텀 편집팁 (손가락·여기 눌러주세요 안내)
    'admin_designs.php'           => ['site_settings', 'post'],   // 추천 디자인 (간편 템플릿)
    'sample_api.php'              => ['site_settings', 'post'],   // 디자인 샘플 저장 (에디터 ?sample=)
    'admin_extra_settings.php'    => ['extra_settings', 'post'],
    'admin_snap_settings.php'     => ['snap_settings', 'post'],
    'admin_music.php'             => ['music_delete', 'post'],
    'admin_naver.php'             => ['naver_orders', 'all'],
    'admin_vip.php'               => ['vip_create', 'post'],
    'admin_delete.php'            => ['invite_delete', 'all'],
];

/** admin_users 표가 없으면 만든다 */
function admin_users_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(40)  NOT NULL,
        display_name  VARCHAR(40)  NOT NULL DEFAULT '',
        password_hash VARCHAR(255) NOT NULL,
        perms         TEXT         NULL,
        active        TINYINT(1)   NOT NULL DEFAULT 1,
        created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME     NULL,
        pw_changed_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** 새 부관리자 기본 권한 */
function admin_perm_defaults(): array
{
    $d = [];
    foreach (ADMIN_PERMS as $k => $p) $d[$k] = (bool) $p[3];
    return $d;
}

/** DB에 저장된 권한(JSON) → [키 => bool] (없는 키는 기본값) */
function admin_perms_decode(?string $json): array
{
    $saved = json_decode((string) $json, true);
    $out = admin_perm_defaults();
    if (is_array($saved)) foreach ($out as $k => $v) if (array_key_exists($k, $saved)) $out[$k] = (bool) $saved[$k];
    return $out;
}

/** 지금 로그인한 사람이 대표 관리자인지 (예전 세션처럼 역할 기록이 없으면 대표) */
function admin_is_owner(): bool
{
    return ($_SESSION['admin_role'] ?? 'owner') === 'owner';
}

/** 지금 로그인한 부관리자 정보 (admin_guard.php가 채움). 대표면 null */
function admin_sub(): ?array
{
    return $GLOBALS['LD_ADMIN_SUB'] ?? null;
}

/** 이 권한이 있는지 */
function admin_can(string $perm): bool
{
    if (admin_is_owner()) return true;
    if ($perm === 'owner') return false;
    $sub = admin_sub();
    return $sub !== null && !empty($sub['perms'][$perm]);
}

/** 화면 이름으로 규칙 찾기 */
function admin_page_rule(?string $file = null): ?array
{
    $file = $file ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return ADMIN_PAGE_RULES[$file] ?? null;
}

/** 이 화면을 아예 열 수 있는지 (메뉴 숨김에도 씀) */
function admin_page_open(string $file): bool
{
    $r = admin_page_rule($file);
    return !$r || $r[1] !== 'all' || admin_can($r[0]);
}

/** 지금 화면이 부관리자에게 "보기 전용"인지 */
function admin_page_readonly(): bool
{
    $r = admin_page_rule();
    return $r && $r[1] === 'post' && !admin_can($r[0]);
}

/** 권한이 없을 때 - AJAX면 JSON, 아니면 안내 화면 */
function admin_deny(string $what = ''): void
{
    http_response_code(403);
    $msg = ($what !== '' ? $what . ' - ' : '') . '부관리자 권한이 없어요. 대표 관리자에게 권한을 요청해주세요.';
    $wantsJson = isset($_POST['ajax']) || isset($_GET['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $back = !empty($_SERVER['HTTP_REFERER']) && str_contains((string) $_SERVER['HTTP_REFERER'], (string) ($_SERVER['HTTP_HOST'] ?? '')) ? (string) $_SERVER['HTTP_REFERER'] : 'admin_create.php';
    echo '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>권한 없음</title><meta name="referrer" content="no-referrer">'
       . '<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#F4F2EE;font-family:"Pretendard Variable",Pretendard,-apple-system,sans-serif;color:#2B2B2B;padding:20px;box-sizing:border-box}'
       . '.b{max-width:380px;background:#fff;border:1px solid #ECE8E2;border-radius:18px;padding:28px 24px;text-align:center;box-shadow:0 14px 40px rgba(30,20,10,.08)}'
       . '.i{font-size:34px;margin:0 0 8px}h1{font-size:18px;margin:0 0 8px}p{font-size:13.5px;color:#6F6A63;line-height:1.7;margin:0 0 18px}a{display:inline-block;padding:10px 18px;border-radius:10px;background:#2B2B2B;color:#fff;text-decoration:none;font-size:14px;font-weight:600}</style></head>'
       . '<body><div class="b"><div class="i">🔒</div><h1>권한이 없어요</h1><p>' . $h($msg) . '</p><a href="' . $h($back) . '">돌아가기</a></div></body></html>';
    exit;
}

/** 권한이 없으면 막기 */
function admin_require(string $perm, string $what = ''): void
{
    if (!admin_can($perm)) admin_deny($what !== '' ? $what : (ADMIN_PERMS[$perm][1] ?? ''));
}

/** 상단바에 보여줄 이름 */
function admin_display_name(): string
{
    if (admin_is_owner()) return '대표 관리자';
    $s = admin_sub();
    return $s ? (($s['display_name'] ?: $s['username']) . ' (부관리자)') : '부관리자';
}

/** 전화번호 가리기 (부관리자에게 개인정보 권한이 없을 때) */
function admin_mask_phone(string $p): string
{
    return trim($p) === '' ? '' : '비공개';
}
