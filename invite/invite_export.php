<?php
/**
 * invite_export.php - 영구 보관 청첩장을 "HTML 파일 하나"로 내려받기 (총 3회)
 *
 * 파일 하나에 전부 들어간다 - 인터넷 없이 브라우저로 바로 열림
 *   - 사진(대표·갤러리·타임라인 등 디자인에 쓰인 모든 사진), 스티커, 인트로 배경
 *   - 배경음악 (직접 올린 곡 / 기본 제공 곡) → 파일 안에 넣어서 오른쪽 위 작은 원형 버튼으로 재생
 *   - 방명록 글 (내려받는 시점의 글을 그대로 담음)
 *   - 계좌정보는 가리지 않은 실제 값 (본인 보관용이라서)
 * 서버가 있어야 하는 기능(참석 회신 보내기, 하객 사진 올리기, 카카오톡 공유)은 파일에서 뺀다.
 * 글꼴은 구글 글꼴 주소를 그대로 둔다 - 인터넷이 없으면 기본 글꼴로 보인다.
 * 받을 수 있는 때: 스마트스토어 주문이 구매확정된 뒤, 예식 전날부터 (naver_commerce.php nc_export_gate)
 */
declare(strict_types=1);
require_once __DIR__ . '/naver_commerce.php'; // guest_functions(functions·snap_functions·방명록) + 다운로드 조건(nc_export_gate)

const MAX_EXPORTS      = 3;
const EXPORT_MAX_FILE  = 25 * 1048576;  // 한 파일(사진·음악) 최대 크기 - 이보다 크면 넣지 않음
const EXPORT_MAX_TOTAL = 180 * 1048576; // 파일 하나에 넣는 전체 크기 한도

$pdo = get_pdo();

function export_fail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $back = htmlspecialchars((string) ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'), ENT_QUOTES, 'UTF-8');
    exit('<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>다운로드</title>'
        . '<div style="font-family:-apple-system,sans-serif;max-width:420px;margin:80px auto;padding:0 24px;text-align:center;color:#333">'
        . '<p style="font-size:15px;line-height:1.7">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a href="' . $back . '">돌아가기</a></p></div>');
}

$token = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) export_fail(404, '잘못된 접근입니다.');
$invite = find_invitation_by_token($pdo, $token);
if (!$invite || !empty($invite['deleted_at'])) export_fail(404, '유효하지 않은 링크입니다.');
if ($invite['edit_pin_hash'] && empty($_SESSION['pin_ok_' . $invite['id']])) export_fail(403, 'PIN 인증이 필요합니다. 내 청첩장 화면에서 다시 시도해주세요.');
if (invite_owner_locked($pdo, $invite)) export_fail(403, '보관 기간이 끝나 잠긴 청첩장이에요.');
if ($invite['storage_plan'] !== 'permanent') export_fail(403, '영구 보관 결제 후 이용하실 수 있는 기능입니다.');
$gate = nc_export_gate($pdo, $invite); // 구매확정 뒤, 예식 전날부터
if (!$gate['ok']) export_fail(403, $gate['reason']);
if ((int) $invite['export_count'] >= MAX_EXPORTS) export_fail(403, '다운로드 가능 횟수(' . MAX_EXPORTS . '회)를 모두 사용하셨습니다. 추가로 필요하시면 고객센터로 문의해주세요.');
// 구글 드라이브로 보내기 (?to=drive) - 연결한 드라이브 폴더에 HTML 파일로 저장. 관리자가 서버 직접 받기를 꺼 두면 드라이브로만.
require_once __DIR__ . '/gdrive.php';
$toDrive = ($_GET['to'] ?? '') === 'drive';
if ($toDrive && (!gdrive_on() || !gdrive_link($pdo, (int) $invite['id']))) export_fail(403, '구글 드라이브가 연결되어 있지 않아요. 게스트스냅 관리 화면에서 먼저 연결해주세요.');
if (!$toDrive && !export_server_download_on()) export_fail(403, gdrive_on() ? '파일 받기는 구글 드라이브로만 할 수 있어요. 내 청첩장 화면에서 "구글 드라이브로 보내기"를 눌러주세요.' : '지금은 파일 받기를 쓸 수 없어요. 고객센터로 문의해주세요.');
if (!$invite['design_json']) export_fail(400, '아직 저장된 디자인이 없습니다. 에디터에서 먼저 저장해주세요.');
$design = json_decode($invite['design_json'], true);
if (!is_array($design)) export_fail(500, '디자인 데이터를 읽을 수 없습니다.');
@set_time_limit(120);
@ini_set('memory_limit', '768M');

$id = (int) $invite['id'];

// ---------------------------------------------------------------- 계좌정보: 가린 값(•) 대신 실제 값
if ($invite['account_info_enc']) {
    $accountInfo = json_decode(decrypt_data($invite['account_info_enc']), true);
    if (is_array($accountInfo)) {
        foreach ($design['blocks'] as &$block) {
            if (($block['id'] ?? '') === 'account' && isset($block['fields'])) {
                foreach (ACCOUNT_KEYS as $key) if (isset($accountInfo[$key])) $block['fields'][$key] = $accountInfo[$key];
            }
        }
        unset($block);
    }
}

// ---------------------------------------------------------------- 서버가 있어야 하는 기능은 파일에서 뺀다
foreach ($design['blocks'] as &$block) {
    if (in_array($block['id'] ?? '', ['rsvp', 'guestsnap', 'share'], true)) $block['enabled'] = false;
}
unset($block);
if (isset($design['share']) && is_array($design['share'])) $design['share']['fab'] = false;

// ---------------------------------------------------------------- 사진·스티커·음악을 파일 안에 넣기
// 디자인 전체를 훑어서 "우리 서버의 파일 주소"인 글자를 찾아 data: 주소로 바꾼다.
// 이 청첩장 폴더, 이 고객의 스티커 폴더, 기본 제공 음악·프리셋·스티커 폴더의 파일만 넣는다 (다른 사람 파일은 절대 안 넣음).
$MIME = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml',
         'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4'];
$embedded = []; $totalBytes = 0; $skipped = [];
$resolve = function (string $url) use ($id, $invite): ?string {
    $u = preg_replace('#^https?://(www\.)?loveday\.kr#i', '', $url);
    $u = preg_replace('/[?#].*$/', '', $u);
    $u = rawurldecode($u);
    $u = preg_replace('#^/?(invite/)?#', '', $u); // → uploads/… , music/… , presets/…
    if (preg_match('#^uploads/' . $id . '/([A-Za-z0-9_\-]+\.(webp|png|jpe?g|gif|mp3|m4a))$#i', $u, $m)) return invitation_upload_dir($id) . $m[1];
    if (preg_match('#^uploads/stickers/(\d+)/([A-Za-z0-9_\-]+\.(webp|png))$#i', $u, $m) && $invite['customer_id'] && (int) $m[1] === (int) $invite['customer_id']) return customer_sticker_dir((int) $m[1]) . $m[2];
    if (preg_match('#^(music|presets|assets/stickers)/([^\x00]+\.(mp3|m4a|webp|png|jpe?g|gif|svg))$#i', $u, $m)) { // 기본 스티커(빈티지·테마 그림)도
        $base = realpath(__DIR__ . '/' . $m[1]);
        $real = realpath(__DIR__ . '/' . $m[1] . '/' . $m[2]);
        if ($base && $real && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) return $real;
    }
    return null;
};
$embed = function (string $url) use (&$embedded, &$totalBytes, &$skipped, $resolve, $MIME): string {
    if ($url === '' || str_starts_with($url, 'data:')) return $url;
    if (isset($embedded[$url])) return $embedded[$url];
    $path = $resolve($url);
    if (!$path || !is_file($path)) return $url;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $size = (int) filesize($path);
    if (!isset($MIME[$ext]) || $size > EXPORT_MAX_FILE || $totalBytes + $size > EXPORT_MAX_TOTAL) { $skipped[] = basename($path); return $url; }
    $totalBytes += $size;
    return $embedded[$url] = 'data:' . $MIME[$ext] . ';base64,' . base64_encode((string) file_get_contents($path));
};
$walk = function (&$node) use (&$walk, $embed): void {
    if (is_array($node)) { foreach ($node as &$v) $walk($v); unset($v); return; }
    if (is_string($node) && preg_match('#^(https?://(www\.)?loveday\.kr)?/?(invite/)?(uploads|music|presets|assets/stickers)/#i', $node)) $node = $embed($node);
};
$walk($design);

// ---------------------------------------------------------------- 방명록 글 (내려받는 시점 그대로)
$gbSnapshot = null;
$gbOn = false;
foreach ($design['blocks'] as $b) if (($b['id'] ?? '') === 'guestbook' && !empty($b['enabled'])) $gbOn = true;
if ($gbOn) {
    try {
        $htmlCol = gb_has_html($pdo) ? ', message_html' : '';
        $st = $pdo->prepare('SELECT id, author, message, created_at' . $htmlCol . ' FROM guestbook_entries WHERE invitation_id = ? ORDER BY id DESC LIMIT 1000');
        $st->execute([$id]);
        $gbSnapshot = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['author'], 'message' => $r['message'], 'html' => (string) ($r['message_html'] ?? ''),
            'date' => date('Y.m.d', strtotime((string) $r['created_at'])), 'mine' => false], $st->fetchAll());
    } catch (Throwable $e) { $gbSnapshot = []; }
}

// ---------------------------------------------------------------- 글꼴 (인터넷이 있으면 원래 글꼴로)
$fontCssUrls = [
    'noto-serif-kr'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+KR:wght@400;500;700&display=swap',
    'gowun-batang'   => 'https://fonts.googleapis.com/css2?family=Gowun+Batang&display=swap',
    'nanum-myeongjo' => 'https://fonts.googleapis.com/css2?family=Nanum+Myeongjo:wght@400;700;800&display=swap',
    'gothic-a1'      => 'https://fonts.googleapis.com/css2?family=Gothic+A1:wght@400;500;700;900&display=swap',
    'song-myung'     => 'https://fonts.googleapis.com/css2?family=Song+Myung&display=swap',
    'nanum-pen'      => 'https://fonts.googleapis.com/css2?family=Nanum+Pen+Script&display=swap',
    'nanum-brush'    => 'https://fonts.googleapis.com/css2?family=Nanum+Brush+Script&display=swap',
    'gaegu'          => 'https://fonts.googleapis.com/css2?family=Gaegu:wght@400;700&display=swap',
    'hi-melody'      => 'https://fonts.googleapis.com/css2?family=Hi+Melody&display=swap',
    'gamja-flower'   => 'https://fonts.googleapis.com/css2?family=Gamja+Flower&display=swap',
];
$neededFontIds = array_unique(array_filter([$design['customFont'] ?? '', ($design['intro'] ?? [])['font'] ?? '']));
$customFontUrls = array_values(array_filter(array_map(fn($f) => $fontCssUrls[$f] ?? null, $neededFontIds)));
$fontLinkTag = implode('', array_map(fn($url) => '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">', $customFontUrls));

// ---------------------------------------------------------------- 화면 코드 (공개 청첩장과 같은 파일들을 그대로 넣음)
$read = fn(string $f) => (string) (@file_get_contents(__DIR__ . '/assets/' . $f) ?: '');
$css = $read('render-invite.css') . "\n" . $read('invite-blocks.css');
$safeJs = fn(string $js) => str_ireplace('</script', '<\/script', $js);
$jsBlocks = $safeJs($read('invite-blocks.js'));
$jsRender = $safeJs($read('render-invite.js'));
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES;
$designJson = json_encode($design, $jsonFlags);
$gbJson = json_encode($gbSnapshot, $jsonFlags);
$wd = snap_wedding_date($invite);
$weddingJson = json_encode($wd ? $wd->format('Y-m-d') : '');

// 이름: 주문 정보 → 대표 화면(히어로)에 적은 이름
$g = trim((string) $invite['groom_name']); $b = trim((string) $invite['bride_name']);
foreach ($design['blocks'] as $blk) {
    if (in_array($blk['id'] ?? '', ['hero', 'heroVideo'], true) && ($g === '' || $b === '')) {
        $first = fn($s) => trim(explode("\n", trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $s))))[0]);
        $g = $g ?: $first($blk['fields']['groomName'] ?? ''); $b = $b ?: $first($blk['fields']['brideName'] ?? '');
    }
}
$names = ($g !== '' && $b !== '') ? "{$g} ♥ {$b}" : '우리의 청첩장';
$title = htmlspecialchars($names, ENT_QUOTES, 'UTF-8');
$savedAt = date('Y. n. j');
$musicNote = !empty($design['bgm']['enabled']) && !empty($design['bgm']['url']) && str_starts_with((string) $design['bgm']['url'], 'data:')
    ? ' 오른쪽 위 ♪ 버튼으로 배경음악을 켜고 끌 수 있어요.' : '';

$html = <<<HTML
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$title}</title>
<meta name="robots" content="noindex">
{$fontLinkTag}
<style>
{$css}
.ld-export-note { max-width: 460px; margin: 24px auto 60px; padding: 0 24px; font-size: 11.5px; line-height: 1.7; color: #8A7F72; text-align: center; }
</style>
</head>
<body class="ib-offline">
<div class="invite-frame" id="inviteRoot"></div>
<p class="ld-export-note">
    이 파일은 loveday.kr에서 {$savedAt}에 내려받은 개인 보관용 사본이에요. 인터넷 없이도 브라우저에서 그대로 열려요.{$musicNote}<br>
    계좌정보가 들어 있으니 다른 사람과 공유하지 마세요. 방명록은 내려받은 날까지의 글이 담겨 있어요.
</p>
<script>
window.INVITE_OFFLINE = true;
window.INVITE_SLUG = '';
window.INVITE_CSRF = '';
window.INVITE_WEDDING_DATE = {$weddingJson};
window.INVITE_GB_SNAPSHOT = {$gbJson};
</script>
<script>
{$jsBlocks}
</script>
<script>
{$jsRender}
</script>
<script>
    const design = {$designJson};
    renderInviteReadOnly(document.getElementById('inviteRoot'), design, false);
</script>
</body>
</html>
HTML;

$filename = '청첩장_' . preg_replace('/[\\\\\/:*?"<>|♥\s]+/u', '_', $names) . '.html';
if ($toDrive) {
    try {
        $link = gdrive_link($pdo, $id);
        $at = gdrive_access_token($pdo, $link);
        $folder = gdrive_folder($pdo, $link, $at, $invite);
        $fileId = gdrive_upload($at, $folder, $filename, 'text/html', $html);
    } catch (Throwable $e) {
        export_fail(502, $e instanceof RuntimeException ? $e->getMessage() : '구글 드라이브로 보내지 못했어요.');
    }
    $pdo->prepare('UPDATE invitation_orders SET export_count = export_count + 1 WHERE id = ?')->execute([$id]);
    $fu = htmlspecialchars('https://drive.google.com/file/d/' . rawurlencode($fileId) . '/view', ENT_QUOTES, 'UTF-8');
    $folderUrl = htmlspecialchars((string) ($link['folder_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    exit('<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>드라이브로 보냈어요</title>'
        . '<div style="font-family:-apple-system,sans-serif;max-width:420px;margin:80px auto;padding:0 24px;text-align:center;color:#333">'
        . '<p style="font-size:16px;font-weight:600">구글 드라이브에 저장했어요</p><p style="font-size:14px;line-height:1.7;color:#666">' . htmlspecialchars($filename, ENT_QUOTES, 'UTF-8') . '<br>(남은 횟수 ' . max(0, MAX_EXPORTS - (int) $invite['export_count'] - 1) . '회)</p>'
        . '<p><a href="' . $fu . '" target="_blank" rel="noopener">파일 열기</a>' . ($folderUrl ? ' · <a href="' . $folderUrl . '" target="_blank" rel="noopener">폴더 열기</a>' : '') . ' · <a href="dashboard.php">내 청첩장으로</a></p></div>');
}

$pdo->prepare('UPDATE invitation_orders SET export_count = export_count + 1 WHERE id = ?')->execute([$id]);

header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: attachment; filename="invitation.html"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($html));
header('Cache-Control: no-store');
echo $html;
