<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

// 계좌정보(은행/계좌번호/예금주) 관련 필드 키 - design_json에는 평문으로 남기지 않는 필드들.
// invite_save.php / invite_load.php / invite_export.php가 공통으로 참조한다.
const ACCOUNT_KEYS = ['groomBank', 'brideBank', 'groomFatherBank', 'groomMotherBank', 'brideFatherBank', 'brideMotherBank'];

/* =========================================================
 * CSRF 토큰
 * ======================================================= */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): void
{
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF 검증에 실패했습니다. 새로고침 후 다시 시도해주세요.');
    }
}

/* =========================================================
 * 편집 토큰 / 공개 슬러그
 * ======================================================= */
function generate_edit_token(): string
{
    return bin2hex(random_bytes(32)); // 64자 - 브루트포스 사실상 불가능
}

function generate_slug(PDO $pdo, string $groom, string $bride): string
{
    // 한글 이름은 그대로 슬러그로 쓰면 URL 인코딩이 지저분해지고
    // 신랑신부 이름이 공개 URL에 그대로 노출되는 것도 바람직하지 않으므로
    // 항상 짧은 랜덤 슬러그를 사용한다.
    $stmt = $pdo->prepare('SELECT id FROM invitation_orders WHERE view_slug = ? LIMIT 1');
    do {
        $slug = 'w' . bin2hex(random_bytes(4)); // 예: w3f9a2c1
        $stmt->execute([$slug]);
    } while ($stmt->fetch());
    return $slug;
}

/* =========================================================
 * 계좌정보 등 민감정보 암호화 (AES-256-GCM)
 * ======================================================= */
function encrypt_data(string $plain): string
{
    $iv  = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('암호화 실패');
    }
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_data(string $encoded): string
{
    $raw    = base64_decode($encoded);
    $iv     = substr($raw, 0, 12);
    $tag    = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain  = openssl_decrypt($cipher, 'aes-256-gcm', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
}

/* =========================================================
 * 관리자 로그인 시도 제한 (브루트포스 방어)
 * ======================================================= */
function check_login_lockout(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare('SELECT locked_until FROM admin_login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
        $waitMin = (int) ceil((strtotime($row['locked_until']) - time()) / 60);
        http_response_code(429);
        exit("로그인 시도가 너무 많습니다. {$waitMin}분 후 다시 시도해주세요.");
    }
}

function record_login_failure(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare('SELECT attempts FROM admin_login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
    $row = $stmt->fetch();
    $attempts = ($row['attempts'] ?? 0) + 1;
    $locked_until = $attempts >= MAX_LOGIN_ATTEMPTS
        ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS)
        : null;

    $stmt = $pdo->prepare('
        INSERT INTO admin_login_attempts (ip, attempts, locked_until, updated_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE attempts = VALUES(attempts),
                                 locked_until = VALUES(locked_until),
                                 updated_at = NOW()
    ');
    $stmt->execute([$ip, $attempts, $locked_until]);
}

function clear_login_failures(PDO $pdo, string $ip): void
{
    $stmt = $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip = ?');
    $stmt->execute([$ip]);
}

/* =========================================================
 * 이미지 업로드 검증 + 재인코딩(악성 페이로드 제거) + 리사이즈 + webp 변환
 * ---------------------------------------------------------
 * 저장 구조: uploads/{invitation_id}/master/{filename}.webp  (원본, 워터마크 없음, 웹에서 직접 서빙 안 함)
 *            uploads/{invitation_id}/{filename}.webp         (공개용, 무료체험이면 워터마크 적용)
 * 나중에 결제 확인되면 master에서 다시 뽑아 워터마크 없이 공개용을 재생성한다 (reprocess_invitation_photos 참고).
 * ======================================================= */
function validate_and_store_image(array $file, int $invitationId, bool $watermark): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('업로드 중 오류가 발생했습니다.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('파일 용량은 5MB 이하만 가능합니다.');
    }
    // 확장자가 아니라 실제 파일 내용으로 MIME 판별 (위조 방지)
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ALLOWED_MIME, true)) {
        throw new RuntimeException('jpg, png, webp 형식만 업로드 가능합니다.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('유효한 이미지 파일이 아닙니다.');
    }

    [$width, $height] = $info;
    $maxDim = 1920;
    $ratio  = min(1, $maxDim / max($width, $height));
    $newW   = max(1, (int) round($width * $ratio));
    $newH   = max(1, (int) round($height * $ratio));

    $src = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => imagecreatefrompng($file['tmp_name']),
        'image/webp' => imagecreatefromwebp($file['tmp_name']),
        default      => null,
    };
    if ($src === null || $src === false) {
        throw new RuntimeException('이미지를 처리할 수 없습니다.');
    }

    $dst = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($src);

    $invDir    = invitation_upload_dir($invitationId);
    $masterDir = $invDir . 'master/';
    foreach ([$invDir, $masterDir] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('업로드 폴더를 생성할 수 없습니다.');
        }
    }

    // 원본 파일명은 절대 사용하지 않고 랜덤 이름으로 저장 (경로 조작/충돌 방지). 전부 webp로 통일 저장.
    $filename = bin2hex(random_bytes(16)) . '.webp';

    // 1) 마스터(원본, 워터마크 없음) 저장 - 나중에 결제 확인 시 여기서 다시 뽑아온다
    imagewebp($dst, $masterDir . $filename, 82);

    // 2) 공개용 저장 - 무료체험이면 워터마크를 강제로 입힌다
    if ($watermark) {
        apply_watermark($dst);
    }
    imagewebp($dst, $invDir . $filename, 82);

    imagedestroy($dst);

    return $filename;
}

function invitation_upload_dir(int $invitationId): string
{
    return UPLOAD_DIR . $invitationId . '/';
}

function customer_sticker_dir(int $customerId): string
{
    return UPLOAD_DIR . 'stickers/' . $customerId . '/';
}

/**
 * 고객이 올린 PNG(또는 WebP) 스티커를 투명 배경을 살린 채로 WebP로 변환해서 저장한다.
 * 사진 업로드(validate_and_store_image)와 다르게 워터마크가 없고, 알파 채널을 보존해야 한다.
 */
function validate_and_store_sticker(array $file, int $customerId): string
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('업로드 중 오류가 발생했습니다.');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('파일 용량은 5MB 이하만 가능합니다.');
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, ['image/png', 'image/webp'], true)) {
        throw new RuntimeException('PNG 또는 WebP 파일만 업로드 가능합니다 (투명 배경 지원 형식).');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('유효한 이미지 파일이 아닙니다.');
    }

    [$width, $height] = $info;
    $maxDim = 600; // 스티커는 화면에서 작게 쓰이므로 사진만큼 큰 해상도가 필요 없음
    $ratio  = min(1, $maxDim / max($width, $height));
    $newW   = max(1, (int) round($width * $ratio));
    $newH   = max(1, (int) round($height * $ratio));

    $src = $mime === 'image/png' ? imagecreatefrompng($file['tmp_name']) : imagecreatefromwebp($file['tmp_name']);
    if ($src === false) {
        throw new RuntimeException('이미지를 처리할 수 없습니다.');
    }

    $dst = imagecreatetruecolor($newW, $newH);
    // 알파 채널(투명 배경)을 유지해야 스티커답게 보인다 - 사진 리사이즈와 가장 다른 부분
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagedestroy($src);

    $dir = customer_sticker_dir($customerId);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('업로드 폴더를 생성할 수 없습니다.');
    }
    $filename = bin2hex(random_bytes(16)) . '.webp';
    imagewebp($dst, $dir . $filename, 90);
    imagedestroy($dst);

    return $filename;
}

/**
 * 관리자 설정 페이지(admin_watermark.php)에서 저장한 워터마크 설정을 불러온다.
 * 테이블/행이 아직 없으면(마이그레이션 전) 기존 기본값으로 동작한다.
 */
function get_watermark_settings(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $row = null;
    try {
        $row = get_pdo()->query('SELECT * FROM watermark_settings WHERE id = 1')->fetch();
    } catch (\Throwable $e) {
        $row = null;
    }
    $cached = [
        'text'              => $row['text'] ?? 'LOVEDAY.KR 무료 체험 사용중입니다.',
        'width_ratio'       => (float) ($row['width_ratio'] ?? 0.5),
        'step_x_ratio'      => (float) ($row['step_x_ratio'] ?? 0.55),
        'step_y_multiplier' => (float) ($row['step_y_multiplier'] ?? 9.0),
        'angle'             => (int) ($row['angle'] ?? 30),
        'opacity'           => (int) ($row['opacity'] ?? 70),
    ];
    return $cached;
}

function save_watermark_settings(PDO $pdo, array $s): void
{
    $pdo->prepare('
        UPDATE watermark_settings
        SET text = ?, width_ratio = ?, step_x_ratio = ?, step_y_multiplier = ?, angle = ?, opacity = ?
        WHERE id = 1
    ')->execute([
        $s['text'], $s['width_ratio'], $s['step_x_ratio'], $s['step_y_multiplier'], $s['angle'], $s['opacity'],
    ]);
}

/**
 * 사진 위에 워터마크 문구를 사선 반복 패턴으로 덮어씌운다.
 * 결제 전 무료체험 사진에 강제로 들어가는 워터마크.
 * Pretendard Bold(SIL OFL, 상업적 사용 무료)를 사용. GD에 FreeType(TTF) 지원이
 * 없는 극히 예외적인 환경이면 회전 없는 내장 폰트 타일 패턴으로 자동 대체된다.
 *
 * $settings를 안 넘기면 DB에 저장된(관리자 설정 페이지에서 조정한) 값을 쓴다.
 * admin_watermark_preview.php는 저장 전 미리보기 값을 바로 넘겨서 쓴다.
 */
function apply_watermark(\GdImage $img, ?array $settings = null): void
{
    $s = $settings ?? get_watermark_settings();
    $w = imagesx($img);
    $h = imagesy($img);
    imagealphablending($img, true);

    // opacity(0~100, 100이 가장 진하게 보임)를 GD의 alpha(0=불투명~127=투명)로 변환
    $whiteAlpha = max(0, min(127, (int) round((100 - $s['opacity']) * 1.27)));
    $blackAlpha = max(0, min(127, $whiteAlpha + 25));
    $white = imagecolorallocatealpha($img, 255, 255, 255, $whiteAlpha);
    $black = imagecolorallocatealpha($img, 0, 0, 0, $blackAlpha);
    $text  = $s['text'];

    if (function_exists('imagettftext') && is_file(WATERMARK_FONT)) {
        $angle = $s['angle'];

        // 문구 길이(한글 포함)에 따라 실제 렌더링 폭이 크게 달라지므로, 임의 배수 대신
        // 기준 크기(60px)로 한 번 측정해서 "사진 폭의 N%"가 되도록 최종 폰트 크기를 역산한다.
        $refSize = 60;
        $refBox  = imagettfbbox($refSize, $angle, WATERMARK_FONT, $text);
        $refW    = max(1, abs($refBox[4] - $refBox[0]));
        $targetW = $w * $s['width_ratio'];
        $fontSize = max(12, min(140, (int) round($refSize * ($targetW / $refW))));

        $box   = imagettfbbox($fontSize, $angle, WATERMARK_FONT, $text);
        $textW = abs($box[4] - $box[0]);
        $stepX = max(10, (int) round($textW * $s['step_x_ratio']));
        $stepY = max(10, (int) round($fontSize * $s['step_y_multiplier']));
        $row   = 0;

        for ($y = -$stepY; $y < $h + $stepY; $y += $stepY, $row++) {
            // 대각선 바둑판 패턴 - 한 줄씩 반 칸(step의 절반)만큼 엇갈리게 찍는다.
            // (엇갈림 없이 같은 x에서 시작하면 줄끼리 정확히 겹쳐서 오히려 더 지저분해짐)
            $offset = ($row % 2 === 0) ? 0 : $stepX / 2;
            for ($x = -$stepX + $offset; $x < $w + $stepX; $x += $stepX) {
                imagettftext($img, $fontSize, $angle, (int) $x + 2, $y + 2, $black, WATERMARK_FONT, $text);
                imagettftext($img, $fontSize, $angle, (int) $x, $y, $white, WATERMARK_FONT, $text);
            }
        }
        return;
    }

    // 폴백: FreeType 미지원 환경 - 회전은 안 되지만 내장 폰트라 항상 동작함
    $font  = 5;
    $stepX = 150;
    $stepY = 70;
    $row   = 0;
    for ($y = -$stepY; $y < $h + $stepY; $y += $stepY, $row++) {
        $offset = ($row % 2 === 0) ? 0 : $stepX / 2;
        for ($x = -$stepX + $offset; $x < $w + $stepX; $x += $stepX) {
            imagestring($img, $font, (int) $x + 1, $y + 1, $text, $black);
            imagestring($img, $font, (int) $x, $y, $text, $white);
        }
    }
}

/**
 * 관리자가 결제 확인 처리를 하면 호출 - 마스터 이미지에서 다시 뽑아
 * 워터마크 없는 공개용 사진으로 전부 재생성한다. (반대로 워터마크를 다시
 * 씌워야 할 일은 거의 없지만 $watermark=true로 넘기면 그것도 가능)
 */
function reprocess_invitation_photos(PDO $pdo, int $invitationId, bool $watermark): void
{
    $invDir    = invitation_upload_dir($invitationId);
    $masterDir = $invDir . 'master/';

    $stmt = $pdo->prepare('SELECT file_path FROM invitation_photos WHERE invitation_id = ?');
    $stmt->execute([$invitationId]);

    foreach ($stmt->fetchAll() as $row) {
        $masterPath = $masterDir . $row['file_path'];
        if (!is_file($masterPath)) {
            continue; // 마스터가 없으면(마이그레이션 이전 사진 등) 건드리지 않음
        }
        $img = imagecreatefromwebp($masterPath);
        if (!$img) {
            continue;
        }
        if ($watermark) {
            apply_watermark($img);
        }
        imagewebp($img, $invDir . $row['file_path'], 82);
        imagedestroy($img);
    }
}

/* =========================================================
 * 초대장 조회 헬퍼
 * ======================================================= */
function find_invitation_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM invitation_orders WHERE edit_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_invitation_by_slug(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM invitation_orders WHERE view_slug = ? AND status = 'published' LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// 관리자가 코드(view_slug)로 찾을 때는 아직 발행 전(editing)이어도 찾을 수 있어야 함
function find_invitation_by_slug_any_status(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM invitation_orders WHERE view_slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// 무료체험(trial) 상태면 업로드 사진에 워터마크를 강제로 넣는다
function needs_watermark(array $invite): bool
{
    return ($invite['storage_plan'] ?? 'trial') === 'trial';
}

/**
 * IP 등을 기준으로 짧은 시간에 너무 많이 요청하면 막는다 (계좌정보 조회 무작위 대입 방지 등).
 * 예: check_rate_limit($pdo, 'reveal_' . client_ip(), 20, 600) → 10분(600초)에 20회까지만 허용.
 * true면 계속 진행해도 됨(카운트 증가됨), false면 이번 요청은 막아야 함.
 */
function check_rate_limit(PDO $pdo, string $bucket, int $maxAttempts, int $windowSeconds): bool
{
    $stmt = $pdo->prepare('SELECT attempts, window_start FROM rate_limits WHERE bucket = ?');
    $stmt->execute([$bucket]);
    $row = $stmt->fetch();

    if (!$row || strtotime($row['window_start']) < time() - $windowSeconds) {
        // 처음이거나 시간창이 지났으면 새로 시작
        $pdo->prepare('
            INSERT INTO rate_limits (bucket, attempts, window_start) VALUES (?, 1, NOW())
            ON DUPLICATE KEY UPDATE attempts = 1, window_start = NOW()
        ')->execute([$bucket]);
        return true;
    }

    if ((int) $row['attempts'] >= $maxAttempts) {
        return false;
    }

    $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE bucket = ?')->execute([$bucket]);
    return true;
}

/* =========================================================
 * 네이버 로그인 (최소 연동)
 * ---------------------------------------------------------
 * 이름/이메일/휴대폰 등은 전혀 요청하지 않는다. 오직 "이 서비스에서
 * 이 사람을 구분하기 위한 고유 식별자"(response.id) 하나만 받아서,
 * 그것도 원본이 아니라 해시(NAVER_ID_PEPPER로 솔팅)해서만 저장한다.
 * → DB가 털려도 나가는 건 "익명 해시값이 몇 개 만들었다" 수준.
 * ======================================================= */

function naver_authorize_url(string $state): string
{
    return 'https://nid.naver.com/oauth2.0/authorize?' . http_build_query([
        'response_type' => 'code',
        'client_id'     => NAVER_CLIENT_ID,
        'redirect_uri'  => NAVER_REDIRECT_URI,
        'state'         => $state,
    ]);
}

function curl_get_json(string $url, array $headers = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $ok  = $res !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if (!$ok) {
        return null;
    }
    $data = json_decode($res, true);
    return is_array($data) ? $data : null;
}

function naver_exchange_token(string $code, string $state): ?string
{
    $url = 'https://nid.naver.com/oauth2.0/token?' . http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => NAVER_CLIENT_ID,
        'client_secret' => NAVER_CLIENT_SECRET,
        'code'          => $code,
        'state'         => $state,
    ]);
    $data = curl_get_json($url);
    return $data['access_token'] ?? null;
}

// 네이버가 주는 여러 값 중 response.id (서비스별 고유 식별자) 하나만 꺼내 쓴다
function naver_fetch_uid(string $accessToken): ?string
{
    $data = curl_get_json('https://openapi.naver.com/v1/nid/me', [
        'Authorization: Bearer ' . $accessToken,
    ]);
    return $data['response']['id'] ?? null;
}

function hash_naver_uid(string $uid): string
{
    return hash('sha256', $uid . NAVER_ID_PEPPER);
}

/**
 * 네이버 인증이 끝난 뒤 호출됨. 이미 이 사람 명의로 살아있는(만료 안 된) 체험
 * 청첩장이 있으면 그걸 그대로 돌려주고(중복 생성 방지), 없으면 새로 만든다.
 * 이 경로로 들어오는 이상 시간당 개수 제한은 없다 - 네이버 로그인 자체가 진입장벽 역할을 한다.
 */
const MAX_SLOTS = 5; // 고객 1명(네이버 계정 1개)당 최대 청첩장 샘플 개수

// C0001, C0002 ... 형태의 순번 코드를 자동 배정한다 (관리자가 나중에 직접 수정 가능)
function generate_customer_code(PDO $pdo): string
{
    $stmt = $pdo->prepare('SELECT customer_code FROM customers WHERE customer_code = ? LIMIT 1');
    $n = (int) $pdo->query('SELECT COUNT(*) AS c FROM customers')->fetch()['c'] + 1;
    do {
        $code = 'C' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $stmt->execute([$code]);
        $n++;
    } while ($stmt->fetch());
    return $code;
}

// 네이버 로그인 고유식별자 해시 1개 = 고객 1명. 이미 있으면 그대로, 없으면 새로 만든다.
function find_or_create_customer(PDO $pdo, string $naverUidHash): array
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE naver_uid_hash = ? LIMIT 1');
    $stmt->execute([$naverUidHash]);
    $customer = $stmt->fetch();
    if ($customer) {
        return $customer;
    }

    $code = generate_customer_code($pdo);
    $pdo->prepare('INSERT INTO customers (customer_code, naver_uid_hash) VALUES (?, ?)')
        ->execute([$code, $naverUidHash]);

    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = LAST_INSERT_ID()');
    $stmt->execute();
    return $stmt->fetch();
}

// 휴지통에 있는 것은 세지 않는다 (deleted_at IS NULL인 것만 "슬롯을 차지한" 것으로 간주)
function count_active_invitations(PDO $pdo, int $customerId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM invitation_orders WHERE customer_id = ? AND deleted_at IS NULL');
    $stmt->execute([$customerId]);
    return (int) $stmt->fetch()['cnt'];
}

// 새 무료체험 샘플 하나를 이 고객 명의로 만든다. 슬롯 제한 체크는 호출하는 쪽(대시보드)에서 먼저 한다.
function create_new_invitation_for_customer(PDO $pdo, int $customerId): string
{
    $editToken = generate_edit_token();
    $slug      = generate_slug($pdo, '', '');

    $stmt = $pdo->prepare('
        INSERT INTO invitation_orders
            (order_platform, customer_id, edit_token, view_slug, groom_name, bride_name,
             status, storage_plan, expires_at)
        VALUES
            (\'self_signup\', ?, ?, ?, \'\', \'\', \'editing\', \'trial\', DATE_ADD(NOW(), INTERVAL 4 DAY))
    ');
    $stmt->execute([$customerId, $editToken, $slug]);

    return $editToken;
}

// 고객이 자기 샘플을 지우면 완전 삭제가 아니라 휴지통행 - 관리자만 보고 복원/영구삭제할 수 있다
function soft_delete_invitation(PDO $pdo, int $invitationId): void
{
    $stmt = $pdo->prepare('SELECT customer_id FROM invitation_orders WHERE id = ?');
    $stmt->execute([$invitationId]);
    $row = $stmt->fetch();

    $pdo->prepare('UPDATE invitation_orders SET deleted_at = NOW() WHERE id = ?')->execute([$invitationId]);

    if ($row && $row['customer_id']) {
        refresh_over_limit_state($pdo, (int) $row['customer_id']);
    }
}

// 휴지통에서 복원 - 복원 결과 슬롯이 5개를 넘으면 "초과 상태 시작 시각"을 지금으로 기록한다
function restore_invitation(PDO $pdo, int $invitationId): void
{
    $stmt = $pdo->prepare('SELECT customer_id FROM invitation_orders WHERE id = ?');
    $stmt->execute([$invitationId]);
    $row = $stmt->fetch();

    $pdo->prepare('UPDATE invitation_orders SET deleted_at = NULL WHERE id = ?')->execute([$invitationId]);

    if ($row && $row['customer_id']) {
        $count = count_active_invitations($pdo, (int) $row['customer_id']);
        if ($count > MAX_SLOTS) {
            $pdo->prepare('UPDATE customers SET over_limit_since = NOW() WHERE id = ?')->execute([$row['customer_id']]);
        }
    }
}

// 활성 개수가 5개 이하로 돌아왔으면 초과 타이머를 지운다 (고객이 직접 삭제해서 여유가 생긴 경우 등)
function refresh_over_limit_state(PDO $pdo, int $customerId): void
{
    if (count_active_invitations($pdo, $customerId) <= MAX_SLOTS) {
        $pdo->prepare('UPDATE customers SET over_limit_since = NULL WHERE id = ?')->execute([$customerId]);
    }
}

// 파일(사진 폴더 전체)까지 포함해서 정말로 완전히 지운다 - 휴지통에서 "영구삭제"할 때, 그리고 cron 정리에 쓴다
function permanently_delete_invitation(PDO $pdo, int $invitationId): void
{
    $dir = invitation_upload_dir($invitationId);
    if (is_dir($dir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
    $pdo->prepare('DELETE FROM invitation_orders WHERE id = ?')->execute([$invitationId]);
}

/* =========================================================
 * 사이트 화면 색 (관리자 → 사이트 정보 → 사이트 색상)
 *  에디터·내 청첩장·관리자 화면 CSS가 var(--ui-page, #원래색)처럼 써 둔 4가지 색을 바꾼다.
 *  page 바탕 / tint 옅은 면 / soft 칸·버튼·칩 / line 선. 값은 app_settings 'ui_colors' (JSON)
 * ======================================================= */
// point = 켜짐 스위치·강조 / pointSoft = 포인트 옅은 면 (간편 만들기 아래 [미리보기] 버튼 등)
const SITE_COLOR_KEYS = ['page' => '바탕', 'tint' => '옅은 면', 'soft' => '칸·버튼', 'line' => '선', 'point' => '포인트 (켜짐·강조)', 'pointSoft' => '포인트 옅은 면'];
const SITE_COLOR_PRESETS = [
    'warm'  => ['label' => '따뜻한 베이지 (처음 색)', 'page' => '#F6F4F1', 'tint' => '#FBF9F6', 'soft' => '#F3EEE6', 'line' => '#E5DED3', 'point' => '#C9A86A', 'pointSoft' => '#F6EEEC'],
    'white' => ['label' => '깨끗한 흰색',            'page' => '#FFFFFF', 'tint' => '#FAFAFA', 'soft' => '#F3F3F4', 'line' => '#E6E6E8', 'point' => '#4A4A4F', 'pointSoft' => '#F1F1F3'],
    'gray'  => ['label' => '차분한 회색',            'page' => '#F5F6F7', 'tint' => '#FAFAFB', 'soft' => '#ECEEF0', 'line' => '#DEE1E5', 'point' => '#6B7480', 'pointSoft' => '#ECEEF1'],
    'blue'  => ['label' => '블루 그레이',            'page' => '#F3F6F9', 'tint' => '#F8FAFC', 'soft' => '#E7EDF3', 'line' => '#D7E0E9', 'point' => '#5E7C9A', 'pointSoft' => '#E8EEF4'],
    'rose'  => ['label' => '연한 로즈',              'page' => '#FAF6F6', 'tint' => '#FDFAFA', 'soft' => '#F3E9EA', 'line' => '#E8DADC', 'point' => '#B98590', 'pointSoft' => '#F6ECEE'],
];
function site_colors_get(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $c = ['preset' => 'warm'];
    try {
        $st = get_pdo()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $st->execute(['ui_colors']);
        $j = json_decode((string) $st->fetchColumn(), true);
        if (is_array($j)) $c = $j;
    } catch (Throwable $e) { /* 테이블이 없으면 처음 색 */ }
    return $c;
}
/** 실제로 쓸 4가지 색 (프리셋이면 프리셋 값, 직접이면 고른 값 - 잘못된 값은 처음 색) */
function site_colors_values(?array $c = null): array
{
    $c = $c ?? site_colors_get();
    $base = SITE_COLOR_PRESETS['warm'];
    $p = SITE_COLOR_PRESETS[$c['preset'] ?? ''] ?? null;
    $out = [];
    foreach (array_keys(SITE_COLOR_KEYS) as $k) {
        $v = $p ? $p[$k] : (string) ($c[$k] ?? '');
        $out[$k] = preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : $base[$k];
    }
    return $out;
}
function site_colors_css(): string
{
    $c = site_colors_get();
    if (($c['preset'] ?? 'warm') === 'warm') return "/* 사이트 색상: 처음 색 그대로 */\n";
    $v = site_colors_values($c);
    // 포인트 옅은 면 위 글자색 = 포인트 색을 진하게 (간편 만들기 [미리보기] 버튼 글자 등)
    [$r, $g, $b] = sscanf($v['point'], '#%02x%02x%02x');
    $v['pointInk'] = sprintf('#%02X%02X%02X', (int) round($r * .5), (int) round($g * .5), (int) round($b * .5));
    return ':root{' . implode('', array_map(fn($k) => '--ui-' . strtolower($k) . ":{$v[$k]};", array_keys($v))) . "}\n";
}
/** 각 화면 <head>에 넣는 링크 (색이 바뀌면 주소가 바뀌어 바로 반영) */
function site_colors_link(): string
{
    return '<link rel="stylesheet" href="/invite/site_colors.php?v=' . substr(md5(json_encode(site_colors_get())), 0, 8) . '">';
}
