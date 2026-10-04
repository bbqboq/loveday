<?php
/**
 * gdrive.php - 구글 드라이브 연동 (게스트스냅 사진 · 청첩장 파일을 신랑신부 본인 구글 드라이브로)
 *
 *  왜: 하객 사진을 우리 서버에서 zip으로 묶어 내려받게 하면 서버 용량·트래픽 부담이 크다.
 *      신랑신부가 자기 구글 드라이브를 한 번 연결하면, 하객이 올린 사진이 그 드라이브 폴더로 바로 들어가고
 *      다운로드도 구글 드라이브에서 한다. 청첩장 HTML 파일 내보내기도 같은 폴더로 보낼 수 있다.
 *
 *  관리자 → 게스트스냅 (admin_snap_settings.php) 맨 아래 "구글 드라이브 연동"에서:
 *    gdrive_enabled        1 = 켜짐 (고객 화면에 "구글 드라이브 연결" 버튼이 보임) / 0 = 꺼짐 (기본, 승인 전에는 꺼 두기)
 *    gdrive_client_id      구글 클라우드 콘솔 → OAuth 클라이언트 ID (웹 애플리케이션)
 *    gdrive_client_secret  클라이언트 보안 비밀번호 (DB에 암호화해서 저장)
 *    snap_server_download  1 = 우리 서버에서 zip 전체 다운로드 허용 (기본) / 0 = 끔 (드라이브로만)
 *    export_server_download 1 = 청첩장 HTML 파일을 기기로 바로 받기 허용 (기본) / 0 = 드라이브로만
 *  config.php에 GDRIVE_CLIENT_ID / GDRIVE_CLIENT_SECRET 상수가 있으면 그게 우선 (관리자 화면 값보다)
 *
 *  권한(scope): drive.file (이 앱이 만든 파일·폴더만 보고 쓸 수 있음 - 고객의 다른 드라이브 파일은 못 봄) + 이메일 주소(연결 계정 표시용)
 *  저장: gdrive_links 표 (청첩장마다 연결 1개: 갱신 토큰은 encrypt_data로 암호화), gdrive_files 표 (드라이브로 보낸 사진 기록)
 *        표는 처음 쓸 때 자동으로 만들어진다.
 *  구글 콘솔에 등록할 리디렉션 URI: https://(도메인)/invite/gdrive_callback.php  (관리자 화면에 그대로 표시됨)
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

const GDRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file openid email';
function gdrive_base(string $k): string
{
    // 테스트 서버에서는 config.php에 GDRIVE_*_BASE를 넣어 가짜 구글 서버로 돌릴 수 있다
    $def = ['auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
            'api' => 'https://www.googleapis.com', 'revoke' => 'https://oauth2.googleapis.com/revoke'];
    $c = 'GDRIVE_' . strtoupper($k) . '_BASE';
    return defined($c) ? (string) constant($c) : $def[$k];
}
function gdrive_client_id(): string { return defined('GDRIVE_CLIENT_ID') ? (string) GDRIVE_CLIENT_ID : app_setting('gdrive_client_id'); }
function gdrive_client_secret(): string
{
    if (defined('GDRIVE_CLIENT_SECRET')) return (string) GDRIVE_CLIENT_SECRET;
    $enc = app_setting('gdrive_client_secret');
    if ($enc === '') return '';
    try { return decrypt_data($enc); } catch (Throwable $e) { return ''; }
}
/** 고객 화면에 드라이브 기능을 보여도 되는지 (관리자가 켰고 + 키가 들어 있음) */
function gdrive_on(): bool { return app_setting('gdrive_enabled') === '1' && gdrive_client_id() !== '' && gdrive_client_secret() !== ''; }
function snap_server_download_on(): bool { return app_setting('snap_server_download') !== '0'; }
function export_server_download_on(): bool { return app_setting('export_server_download') !== '0'; }
function gdrive_redirect_uri(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'loveday.kr');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || !preg_match('/^(localhost|127\.)/', $host);
    return ($https ? 'https' : 'http') . '://' . $host . '/invite/gdrive_callback.php';
}

function gdrive_tables(PDO $pdo): void
{
    static $done = false; if ($done) return; $done = true;
    $pdo->exec('CREATE TABLE IF NOT EXISTS gdrive_links (
        invitation_id INT PRIMARY KEY, refresh_enc TEXT NOT NULL, email VARCHAR(190) NULL,
        folder_id VARCHAR(120) NULL, folder_url VARCHAR(300) NULL, last_error VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL) DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS gdrive_files (
        snap_id INT PRIMARY KEY, invitation_id INT NOT NULL, drive_id VARCHAR(120) NOT NULL, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY inv (invitation_id)) DEFAULT CHARSET=utf8mb4');
}
function gdrive_link(PDO $pdo, int $invId): ?array
{
    try { gdrive_tables($pdo); $st = $pdo->prepare('SELECT * FROM gdrive_links WHERE invitation_id = ?'); $st->execute([$invId]); return $st->fetch() ?: null; }
    catch (Throwable $e) { return null; }
}

/** HTTP 요청 (curl) → [상태코드, 본문 배열|null, 원문] */
function gdrive_http(string $method, string $url, array $headers = [], $body = null, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $j = json_decode($raw, true);
    return [$code, is_array($j) ? $j : null, $raw];
}

function gdrive_auth_url(string $state): string
{
    return gdrive_base('auth') . '?' . http_build_query([
        'client_id' => gdrive_client_id(), 'redirect_uri' => gdrive_redirect_uri(), 'response_type' => 'code',
        'scope' => GDRIVE_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
    ]);
}
function gdrive_exchange_code(string $code): array
{
    [$st, $j] = gdrive_http('POST', gdrive_base('token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => gdrive_client_id(), 'client_secret' => gdrive_client_secret(), 'redirect_uri' => gdrive_redirect_uri(), 'grant_type' => 'authorization_code']));
    if ($st !== 200 || empty($j['access_token'])) throw new RuntimeException('구글 로그인을 마치지 못했어요. (' . ($j['error'] ?? $st) . ')');
    return $j;
}
/** id_token(JWT)에서 이메일만 읽기 (서명 확인은 생략 - 화면 표시용) */
function gdrive_email_from_idtoken(?string $idt): string
{
    if (!$idt || substr_count($idt, '.') < 2) return '';
    $p = json_decode((string) base64_decode(strtr(explode('.', $idt)[1], '-_', '+/')), true);
    return is_array($p) ? mb_substr((string) ($p['email'] ?? ''), 0, 190) : '';
}
/** 저장된 갱신 토큰으로 접근 토큰 받기 (1시간짜리, 요청마다 새로) */
function gdrive_access_token(PDO $pdo, array $link): string
{
    $refresh = decrypt_data((string) $link['refresh_enc']);
    [$st, $j] = gdrive_http('POST', gdrive_base('token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => gdrive_client_id(), 'client_secret' => gdrive_client_secret(), 'refresh_token' => $refresh, 'grant_type' => 'refresh_token']));
    if ($st !== 200 || empty($j['access_token'])) {
        $err = ($j['error'] ?? '') === 'invalid_grant' ? '구글 드라이브 연결이 끊겼어요 (권한 취소 또는 만료). 다시 연결해주세요.' : '구글 드라이브에 접속하지 못했어요.';
        $pdo->prepare('UPDATE gdrive_links SET last_error = ?, updated_at = NOW() WHERE invitation_id = ?')->execute([$err, $link['invitation_id']]);
        throw new RuntimeException($err);
    }
    return (string) $j['access_token'];
}
function gdrive_create_folder(string $at, string $name): array
{
    [$st, $j] = gdrive_http('POST', gdrive_base('api') . '/drive/v3/files?fields=id,webViewLink', ['Authorization: Bearer ' . $at, 'Content-Type: application/json'],
        json_encode(['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'], JSON_UNESCAPED_UNICODE));
    if ($st !== 200 || empty($j['id'])) throw new RuntimeException('드라이브에 폴더를 만들지 못했어요.');
    return ['id' => (string) $j['id'], 'url' => (string) ($j['webViewLink'] ?? ('https://drive.google.com/drive/folders/' . $j['id']))];
}
/** 파일 하나 올리기 (multipart, 수십 MB까지) → 드라이브 파일 id */
function gdrive_upload(string $at, string $folderId, string $name, string $mime, string $data): string
{
    $b = 'ld' . bin2hex(random_bytes(8));
    $body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode(['name' => $name, 'parents' => [$folderId]], JSON_UNESCAPED_UNICODE)
          . "\r\n--$b\r\nContent-Type: $mime\r\n\r\n" . $data . "\r\n--$b--";
    [$st, $j] = gdrive_http('POST', gdrive_base('api') . '/upload/drive/v3/files?uploadType=multipart&fields=id', ['Authorization: Bearer ' . $at, 'Content-Type: multipart/related; boundary=' . $b], $body, 120);
    if ($st !== 200 || empty($j['id'])) throw new RuntimeException('드라이브에 파일을 올리지 못했어요. (' . $st . ')');
    return (string) $j['id'];
}
/** 연결한 드라이브 폴더가 아직 있는지 확인하고, 지워졌으면 새로 만든다 */
function gdrive_folder(PDO $pdo, array &$link, string $at, array $invite): string
{
    if (!empty($link['folder_id'])) {
        [$st, $j] = gdrive_http('GET', gdrive_base('api') . '/drive/v3/files/' . rawurlencode($link['folder_id']) . '?fields=id,trashed', ['Authorization: Bearer ' . $at]);
        if ($st === 200 && empty($j['trashed'])) return (string) $link['folder_id'];
    }
    $f = gdrive_create_folder($at, gdrive_folder_name($invite));
    $pdo->prepare('UPDATE gdrive_links SET folder_id = ?, folder_url = ?, updated_at = NOW() WHERE invitation_id = ?')->execute([$f['id'], $f['url'], $link['invitation_id']]);
    $link['folder_id'] = $f['id']; $link['folder_url'] = $f['url'];
    return $f['id'];
}
function gdrive_folder_name(array $invite): string
{
    $n = trim(((string) $invite['groom_name']) . ' ♥ ' . ((string) $invite['bride_name']), ' ♥');
    return 'LOVE DAY ' . ($n !== '' ? $n . ' ' : '') . '하객 사진';
}

/** 하객 사진 한 장(또는 아직 안 보낸 사진 전부)을 드라이브로 → 보낸 장수 */
function gdrive_push_snaps(PDO $pdo, array $invite, ?int $onlySnapId = null, int $max = 200): int
{
    if (!gdrive_on()) return 0;
    $invId = (int) $invite['id'];
    $link = gdrive_link($pdo, $invId);
    if (!$link) return 0;
    $sql = 'SELECT s.id, s.file_name, s.guest_name, s.created_at FROM guest_snaps s LEFT JOIN gdrive_files g ON g.snap_id = s.id WHERE s.invitation_id = ? AND g.snap_id IS NULL';
    $args = [$invId];
    if ($onlySnapId) { $sql .= ' AND s.id = ?'; $args[] = $onlySnapId; }
    $st = $pdo->prepare($sql . ' ORDER BY s.id LIMIT ' . max(1, $max)); $st->execute($args);
    $rows = $st->fetchAll();
    if (!$rows) return 0;
    $at = gdrive_access_token($pdo, $link);
    $ins = $pdo->prepare('INSERT IGNORE INTO gdrive_files (snap_id, invitation_id, drive_id) VALUES (?, ?, ?)');
    $n = 0;
    try {
    $folder = gdrive_folder($pdo, $link, $at, $invite);
    foreach ($rows as $r) {
        $path = snap_dir($invId) . $r['file_name'];
        if (!is_file($path)) continue;
        $who = preg_replace('/[^\p{L}\p{N}_-]+/u', '', (string) ($r['guest_name'] ?? '')) ?: '하객';
        $name = sprintf('%s_%s_%d.webp', date('md-His', strtotime((string) $r['created_at'])), $who, (int) $r['id']);
        $id = gdrive_upload($at, $folder, $name, 'image/webp', (string) file_get_contents($path));
        $ins->execute([(int) $r['id'], $invId, $id]);
        $n++;
    }
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE gdrive_links SET last_error = ?, updated_at = NOW() WHERE invitation_id = ?')
            ->execute([mb_substr($e instanceof RuntimeException ? $e->getMessage() : '드라이브로 보내는 중 문제가 생겼어요.', 0, 250), $invId]);
        throw $e;
    }
    $pdo->prepare('UPDATE gdrive_links SET last_error = NULL, updated_at = NOW() WHERE invitation_id = ?')->execute([$invId]);
    return $n;
}
function gdrive_sent_count(PDO $pdo, int $invId): int
{
    try { gdrive_tables($pdo); $st = $pdo->prepare('SELECT COUNT(*) FROM gdrive_files WHERE invitation_id = ?'); $st->execute([$invId]); return (int) $st->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}
function gdrive_disconnect(PDO $pdo, int $invId): void
{
    $link = gdrive_link($pdo, $invId);
    if ($link) { try { gdrive_http('POST', gdrive_base('revoke') . '?token=' . rawurlencode(decrypt_data((string) $link['refresh_enc'])), ['Content-Type: application/x-www-form-urlencoded'], ''); } catch (Throwable $e) {} }
    $pdo->prepare('DELETE FROM gdrive_links WHERE invitation_id = ?')->execute([$invId]);
}
