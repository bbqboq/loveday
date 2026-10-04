<?php
/**
 * gdrive_callback.php - 구글 로그인 후 돌아오는 주소 (구글 클라우드 콘솔에 "승인된 리디렉션 URI"로 등록)
 *  code → 토큰 교환 → 갱신 토큰을 암호화해서 저장 → 드라이브에 "LOVE DAY ... 하객 사진" 폴더 만들기
 *  → 이미 올라와 있던 하객 사진도 바로 드라이브로 보낸다.
 */
declare(strict_types=1);
require_once __DIR__ . '/gdrive.php';

$pdo = get_pdo();
$sess = $_SESSION['gdrive_state'] ?? null;
unset($_SESSION['gdrive_state']);
$fail = function (string $msg, ?string $token = null): void {
    if ($token) { header('Location: snap_manage.php?t=' . rawurlencode($token) . '&gd=err&m=' . rawurlencode($msg)); exit; }
    http_response_code(400); exit(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'));
};
if (!is_array($sess) || !hash_equals((string) $sess['s'], (string) ($_GET['state'] ?? '')) || time() - (int) $sess['at'] > 900) {
    $fail('연결 요청이 만료되었어요. 게스트스냅 관리 화면에서 다시 눌러주세요.');
}
$token = (string) $sess['t'];
$invite = snap_owner_invite($pdo, $token);
if (!$invite) $fail('편집 링크가 올바르지 않습니다.');
if (!gdrive_on()) $fail('지금은 구글 드라이브 연동을 쓸 수 없어요.', $token);
if (isset($_GET['error'])) $fail($_GET['error'] === 'access_denied' ? '구글 드라이브 연결을 취소했어요.' : '구글 로그인 중 문제가 생겼어요.', $token);

try {
    $tok = gdrive_exchange_code((string) ($_GET['code'] ?? ''));
    if (empty($tok['refresh_token'])) throw new RuntimeException('구글이 장기 연결 권한을 주지 않았어요. 다시 시도해주세요.');
    $invId = (int) $invite['id'];
    gdrive_tables($pdo);
    $f = gdrive_create_folder((string) $tok['access_token'], gdrive_folder_name($invite));
    $pdo->prepare('REPLACE INTO gdrive_links (invitation_id, refresh_enc, email, folder_id, folder_url, last_error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL, NOW(), NOW())')
        ->execute([$invId, encrypt_data((string) $tok['refresh_token']), gdrive_email_from_idtoken($tok['id_token'] ?? null), $f['id'], $f['url']]);
    // 새 폴더이니 예전에 보낸 기록은 지우고, 지금까지 올라온 사진을 새 폴더로 보낸다
    $pdo->prepare('DELETE FROM gdrive_files WHERE invitation_id = ?')->execute([$invId]);
    $sent = 0;
    try { @set_time_limit(300); $sent = gdrive_push_snaps($pdo, $invite, null, 300); } catch (Throwable $e) {}
} catch (Throwable $e) {
    $fail($e instanceof RuntimeException ? $e->getMessage() : '구글 드라이브 연결에 실패했어요.', $token);
}
$dest = ($sess['r'] ?? '') === 'dash' ? 'dashboard.php?t=' . rawurlencode($token) . '&gd=ok' : 'snap_manage.php?t=' . rawurlencode($token) . '&gd=ok&n=' . $sent;
header('Location: ' . $dest);
