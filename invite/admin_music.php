<?php
/**
 * admin_music.php - 관리자: 배경음악 관리
 *  - 신랑신부가 직접 올린 배경음악 목록 (미리 듣기, 원래 파일 이름, 크기, 권리 동의 시각)
 *  - 저작권 신고 등이 들어오면 청첩장은 그대로 두고 "음악만" 삭제 (사유를 남기면 고객이 에디터를 열 때 알림이 뜸)
 *  - 삭제 기록 탭에서 언제 어떤 이유로 지웠는지 확인
 *  - 기본 제공 음악 탭: 에디터 "배경음악" 목록에 나오는 곡(/invite/music/ 폴더)을 여기서 올리고·이름 바꾸고·빼기
 *      올릴 때 자동으로 용량을 줄인다: 브라우저에서 96kbps mp3로 다시 저장해서 보냄(4분 곡 약 3MB),
 *      브라우저가 못 줄였으면 서버에 ffmpeg가 있을 때 서버에서 한 번 더 줄임. 곡 이름·태그는 music/tracks.json
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$notice = ''; $error = '';
$logOk = true;
try { $pdo->query('SELECT 1 FROM music_uploads LIMIT 1'); } catch (Throwable $e) { $logOk = false; }
const MUSIC_REASONS = ['저작권 신고', '권리자 요청', '부적절한 음원', '고객 요청', '기타'];

/** 이 청첩장이 지금 쓰는 "직접 올린 음악" 파일 이름 (없으면 null) */
function music_custom_file(array $inv): ?string
{
    $d = json_decode((string) $inv['design_json'], true);
    $url = (string) ($d['bgm']['url'] ?? '');
    if (preg_match('#^/?(?:invite/)?uploads/' . (int) $inv['id'] . '/(bgm_[a-f0-9]+\.(?:mp3|m4a))$#', $url, $m)) return $m[1];
    return null;
}

/** 음악만 삭제 - 파일을 지우고, 디자인의 배경음악을 끄고, 사유를 남긴다 */
function music_remove(PDO $pdo, array $inv, string $reason): void
{
    $id = (int) $inv['id'];
    foreach (glob(invitation_upload_dir($id) . 'bgm_*') ?: [] as $f) @unlink($f);
    $d = json_decode((string) $inv['design_json'], true);
    if (is_array($d)) {
        $title = (string) ($d['bgm']['title'] ?? '');
        $d['bgm'] = array_merge(is_array($d['bgm'] ?? null) ? $d['bgm'] : [], ['enabled' => false, 'url' => '', 'title' => '', 'removed' => ['at' => date('Y-m-d H:i'), 'reason' => $reason]]);
        $pdo->prepare('UPDATE invitation_orders SET design_json = ? WHERE id = ?')->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $id]);
    }
    try {
        $st = $pdo->prepare("UPDATE music_uploads SET status = 'removed', removed_at = NOW(), removed_reason = ? WHERE invitation_id = ? AND status = 'active'");
        $st->execute([$reason, $id]);
        if ($st->rowCount() === 0) { // 이 기능 전에 올린 곡 - 기록이 없으니 삭제 기록만 새로 남김
            $pdo->prepare("INSERT INTO music_uploads (invitation_id, file_name, orig_name, status, removed_at, removed_reason) VALUES (?, '-', ?, 'removed', NOW(), ?)")
                ->execute([$id, mb_substr($title ?? '', 0, 150), $reason]);
        }
    } catch (Throwable $e) {}
}

// ---------------------------------------------------------------- 기본 제공 음악 (/invite/music/)
const LIB_DIR = __DIR__ . '/music/';
const LIB_MAX_IN_MB = 60;   // 올릴 수 있는 원본 최대 (브라우저가 줄이기 전)
const LIB_MAX_OUT_MB = 15;  // 줄인 뒤 최대
function lib_meta(): array { $f = LIB_DIR . 'tracks.json'; $m = is_file($f) ? json_decode((string) file_get_contents($f), true) : []; return is_array($m) ? $m : []; }
function lib_meta_save(array $m): void
{
    unset($m['example-file-name.mp3']);
    if (@file_put_contents(LIB_DIR . 'tracks.json', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) throw new RuntimeException('music/tracks.json을 쓸 수 없어요 (폴더 권한 확인).');
}
function lib_clean_tags($v): array { return array_values(array_slice(array_filter(array_map(fn($t) => mb_substr(trim(strip_tags($t)), 0, 10), preg_split('/[,#\s]+/u', (string) $v) ?: [])), 0, 4)); }
function lib_tracks(): array
{
    $meta = lib_meta(); $out = [];
    foreach (glob(LIB_DIR . '*.{mp3,m4a,MP3,M4A}', GLOB_BRACE) ?: [] as $path) {
        $file = basename($path);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) continue;
        $i = is_array($meta[$file] ?? null) ? $meta[$file] : [];
        $out[] = ['file' => $file, 'title' => (string) ($i['title'] ?? pathinfo($file, PATHINFO_FILENAME)), 'tags' => (array) ($i['tags'] ?? []), 'size' => filesize($path), 'mtime' => filemtime($path)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}
/** 서버에 ffmpeg가 있으면 96kbps mp3로 (upload_music.php와 같은 방식) */
function lib_transcode(string $src, string $dst): bool
{
    if (!function_exists('exec') || !function_exists('shell_exec')) return false;
    $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    if (in_array('exec', $disabled, true) || in_array('shell_exec', $disabled, true)) return false;
    $ff = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
    if ($ff === '') return false;
    @exec(escapeshellcmd($ff) . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($src) . ' -vn -map_metadata -1 -ac 2 -ar 44100 -codec:a libmp3lame -b:a 96k ' . escapeshellarg($dst) . ' 2>&1', $o, $code);
    return $code === 0 && is_file($dst) && filesize($dst) > 1000;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lib'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    $act = (string) $_POST['lib'];
    $json = function (array $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    try {
        if (!is_dir(LIB_DIR) && !mkdir(LIB_DIR, 0755, true)) throw new RuntimeException('music 폴더를 만들 수 없어요.');
        if ($act === 'upload') {
            $f = $_FILES['music'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) throw new RuntimeException('파일을 받지 못했어요. (너무 크면 서버 업로드 한도에 걸릴 수 있어요)');
            if ((int) $f['size'] > LIB_MAX_IN_MB * 1048576) throw new RuntimeException(LIB_MAX_IN_MB . 'MB 이하 파일만 올릴 수 있어요.');
            $mime = (string) mime_content_type((string) $f['tmp_name']);
            $ext = match ($mime) { 'audio/mpeg', 'audio/mp3' => 'mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac' => 'm4a', default => '' };
            if ($ext === '') throw new RuntimeException('mp3 또는 m4a 파일만 올릴 수 있어요.');
            $tmp = (string) $f['tmp_name']; $server = false;
            if (($_POST['compressed'] ?? '') !== '1' && $f['size'] > 2.5 * 1048576) { // 브라우저가 못 줄였으면 서버에서
                $tc = LIB_DIR . '.tmp_' . bin2hex(random_bytes(6)) . '.mp3';
                if (lib_transcode($tmp, $tc) && filesize($tc) < $f['size']) { $tmp = $tc; $ext = 'mp3'; $server = true; } else @unlink($tc);
            }
            $size = (int) filesize($tmp);
            if ($size > LIB_MAX_OUT_MB * 1048576) { if ($server) @unlink($tmp); throw new RuntimeException('줄인 뒤에도 ' . LIB_MAX_OUT_MB . 'MB가 넘어요. 더 짧은 곡을 올려주세요.'); }
            $name = 'bgm-' . date('ymd') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $ok = $server ? rename($tmp, LIB_DIR . $name) : move_uploaded_file($tmp, LIB_DIR . $name);
            if (!$ok) throw new RuntimeException('저장하지 못했어요 (music 폴더 권한 확인).');
            @chmod(LIB_DIR . $name, 0644);
            $title = mb_substr(trim(strip_tags((string) ($_POST['title'] ?? ''))), 0, 60);
            if ($title === '') $title = mb_substr(pathinfo((string) ($_POST['orig_name'] ?? $f['name']), PATHINFO_FILENAME), 0, 60);
            $m = lib_meta(); $m[$name] = ['title' => $title, 'tags' => lib_clean_tags($_POST['tags'] ?? '')]; lib_meta_save($m);
            $json(['ok' => true, 'file' => $name, 'size' => $size, 'server' => $server]);
        }
        $file = basename((string) ($_POST['file'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]+\.(mp3|m4a)$/i', $file) || !is_file(LIB_DIR . $file)) throw new RuntimeException('곡을 찾지 못했어요.');
        if ($act === 'edit') {
            $m = lib_meta(); $m[$file] = ['title' => mb_substr(trim(strip_tags((string) ($_POST['title'] ?? ''))), 0, 60) ?: pathinfo($file, PATHINFO_FILENAME), 'tags' => lib_clean_tags($_POST['tags'] ?? '')]; lib_meta_save($m);
            header('Location: admin_music.php?tab=library&msg=lib_edit'); exit;
        }
        if ($act === 'delete') {
            if (!@unlink(LIB_DIR . $file)) throw new RuntimeException('파일을 지우지 못했어요.');
            $m = lib_meta(); unset($m[$file]); lib_meta_save($m);
            header('Location: admin_music.php?tab=library&msg=lib_del'); exit;
        }
        throw new RuntimeException('잘못된 요청이에요.');
    } catch (Throwable $e) {
        if ($act === 'upload') $json(['ok' => false, 'error' => $e->getMessage()], 400);
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['lib'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    try {
        $st = $pdo->prepare('SELECT * FROM invitation_orders WHERE id = ?');
        $st->execute([(int) ($_POST['id'] ?? 0)]);
        $inv = $st->fetch();
        if (!$inv) throw new RuntimeException('청첩장을 찾지 못했어요.');
        $reason = in_array($_POST['reason'] ?? '', MUSIC_REASONS, true) ? (string) $_POST['reason'] : '기타';
        $memo = mb_substr(trim(strip_tags((string) ($_POST['memo'] ?? ''))), 0, 120);
        music_remove($pdo, $inv, $reason . ($memo !== '' ? ' - ' . $memo : ''));
        header('Location: admin_music.php?tab=active&msg=removed&code=' . rawurlencode($inv['view_slug'])); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
if (($_GET['msg'] ?? '') === 'lib_edit') $notice = '곡 이름·태그를 바꿨어요.';
if (($_GET['msg'] ?? '') === 'lib_del') $notice = '기본 제공 음악에서 뺐어요.';
if (($_GET['msg'] ?? '') === 'lib_up') $notice = '곡을 올렸어요. 에디터 배경음악 목록에 바로 나와요 (5분 정도 캐시가 남을 수 있어요).';
if (($_GET['msg'] ?? '') === 'removed') $notice = '"' . ($_GET['code'] ?? '') . '" 청첩장의 음악을 삭제했어요. 고객이 에디터를 열면 삭제 사유가 안내돼요.';

$tab = in_array($_GET['tab'] ?? '', ['active', 'removed'], true) ? $_GET['tab'] : 'library'; // 처음 열면 기본 제공 음악(관리자 업로드)부터
$libTracks = lib_tracks();
$q = trim((string) ($_GET['q'] ?? ''));
$rows = [];
$removedCount = 0;
if ($logOk) { try { $removedCount = (int) $pdo->query("SELECT COUNT(*) FROM music_uploads WHERE status = 'removed'")->fetchColumn(); } catch (Throwable $e) {} }

if ($tab === 'active') {
    $sql = "SELECT o.id, o.view_slug, o.groom_name, o.bride_name, o.status, o.storage_plan, o.design_json, c.customer_code
            FROM invitation_orders o LEFT JOIN customers c ON c.id = o.customer_id
            WHERE o.deleted_at IS NULL AND o.design_json LIKE '%bgm\\_%'";
    $args = [];
    if ($q !== '') { $sql .= ' AND (o.view_slug = ? OR o.groom_name LIKE ? OR o.bride_name LIKE ? OR c.customer_code = ?)'; $args = [strtolower($q), "%$q%", "%$q%", $q]; }
    $sql .= ' ORDER BY o.id DESC LIMIT 300';
    $st = $pdo->prepare($sql); $st->execute($args);
    foreach ($st->fetchAll() as $inv) {
        $file = music_custom_file($inv);
        if (!$file) continue;
        $path = invitation_upload_dir((int) $inv['id']) . $file;
        $d = json_decode((string) $inv['design_json'], true);
        $log = null;
        if ($logOk) { $ls = $pdo->prepare("SELECT * FROM music_uploads WHERE invitation_id = ? AND file_name = ? ORDER BY id DESC LIMIT 1"); $ls->execute([$inv['id'], $file]); $log = $ls->fetch() ?: null; }
        $rows[] = ['inv' => $inv, 'file' => $file, 'exists' => is_file($path), 'size' => is_file($path) ? filesize($path) : 0,
                   'title' => (string) ($d['bgm']['title'] ?? ''), 'enabled' => !empty($d['bgm']['enabled']), 'log' => $log];
    }
} elseif ($tab === 'removed' && $logOk) {
    $rows = $pdo->query("SELECT m.*, o.view_slug, o.groom_name, o.bride_name FROM music_uploads m LEFT JOIN invitation_orders o ON o.id = m.invitation_id
                         WHERE m.status = 'removed' ORDER BY m.removed_at DESC LIMIT 300")->fetchAll();
}
$csrf = csrf_token();
$mb = fn($b) => $b ? number_format($b / 1048576, 1) . 'MB' : '-';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>배경음악 관리 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 0 0 12px; align-items: center; }
    .tabs a { padding: 7px 13px; border-radius: 999px; border: 1px solid #ddd; font-size: 13px; text-decoration: none; color: #555; background: #fff; }
    .tabs a.on { background: #2b2320; color: #fff; border-color: #2b2320; }
    .tabs form { margin-left: auto; display: flex; gap: 6px; }
    .tabs input { padding: 7px 10px; width: 200px; }
    .mlist { display: grid; gap: 10px; }
    .mrow { background: #fff; border: 1px solid #e6e2da; border-radius: 12px; padding: 14px 16px; display: grid; grid-template-columns: 1fr 300px; gap: 16px; align-items: center; }
    .mrow h4 { margin: 0 0 4px; font-size: 14.5px; }
    .mrow .meta { font-size: 12.5px; color: #777; line-height: 1.7; }
    .mrow audio { width: 100%; height: 34px; margin-top: 8px; }
    .tag { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 1px 7px; border-radius: 999px; margin-left: 4px; vertical-align: 1px; }
    .tag.ok { background: #E7F3EC; color: #2F6B4F; } .tag.old { background: #F4EFE6; color: #8A6B3A; } .tag.off { background: #eee; color: #888; }
    .rm { display: grid; gap: 6px; }
    .rm select, .rm input { padding: 7px 8px; width: 100%; box-sizing: border-box; }
    .rm button { background: #B24A4A; color: #fff; border: 0; border-radius: 8px; padding: 9px; font-weight: 600; cursor: pointer; }
    table.log { width: 100%; border-collapse: collapse; background: #fff; font-size: 13px; }
    table.log th, table.log td { border-bottom: 1px solid #eee; padding: 9px 8px; text-align: left; }
    table.log th { font-size: 12px; color: #888; background: #faf9f7; }
    .empty { text-align: center; color: #999; padding: 40px 0; background: #fff; border-radius: 12px; border: 1px solid #eee; }
    .lib-up { background: #fff; border: 1px solid #e6e2da; border-radius: 12px; padding: 16px; margin: 0 0 14px; }
    .lib-up b { font-size: 14.5px; } .lib-up p { font-size: 12.5px; color: #777; margin: 4px 0 12px; line-height: 1.6; }
    .lib-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr auto; gap: 8px; align-items: center; }
    .lib-row input { padding: 8px 10px; min-width: 0; }
    .lib-btn { background: #2b2320; color: #fff; border: 0; border-radius: 8px; padding: 9px 16px; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .lib-btn.ghost { background: #fff; color: #2b2320; border: 1px solid #2b2320; }
    .lib-btn:disabled { opacity: .5; cursor: default; }
    .lib-prog { margin-top: 10px; display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: #555; }
    .lib-prog i { flex: 0 0 220px; height: 6px; border-radius: 99px; background: #eee; position: relative; overflow: hidden; }
    .lib-prog i::after { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: var(--p, 0%); background: #2b2320; transition: width .2s; }
    .lib-edit { display: grid; gap: 6px; margin-bottom: 6px; }
    @media (max-width: 760px) { .lib-row { grid-template-columns: 1fr; } .lib-prog i { flex-basis: 140px; } }
    @media (max-width: 760px) { .mrow { grid-template-columns: 1fr; } .tabs form { margin-left: 0; width: 100%; } .tabs input { flex: 1; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('music', '배경음악 관리'); ?>
    <div class="wrap">
        <h2 class="page-title">배경음악 관리</h2>
        <p style="font-size:13px;color:#888;margin:-14px 0 18px;"><b>기본 제공 음악</b>: 에디터 배경음악 목록에 나오는 곡을 올리고 관리해요 (자동 압축). <b>고객이 올린 음악</b>: 저작권 신고가 들어오면 청첩장은 그대로 두고 음악만 삭제할 수 있어요.</p>
        <?php if ($notice): ?><div class="notice success"><?= $h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= $h($error) ?></div><?php endif; ?>
        <?php if (!$logOk): ?><div class="notice error">업로드 기록 표가 아직 없어요. 서버에서 <b>stage4_setup.sql</b>을 한 번 더 실행해주세요. (목록 보기·삭제는 기록 없이도 돼요)</div><?php endif; ?>

        <div class="tabs">
            <a href="?tab=library" class="<?= $tab === 'library' ? 'on' : '' ?>">기본 제공 음악 (업로드) <?= count($libTracks) ?></a>
            <a href="?tab=active" class="<?= $tab === 'active' ? 'on' : '' ?>">고객이 올린 음악<?= $tab === 'active' ? ' ' . count($rows) : '' ?></a>
            <a href="?tab=removed" class="<?= $tab === 'removed' ? 'on' : '' ?>">삭제 기록 <?= $removedCount ?></a>
            <?php if ($tab === 'active'): ?>
            <form method="get"><input type="hidden" name="tab" value="active"><input name="q" value="<?= $h($q) ?>" placeholder="청첩장 코드·이름·고객코드"><button class="btn" type="submit">찾기</button><?php if ($q !== ''): ?><a href="?tab=active" style="border:0;padding:7px 4px">전체</a><?php endif; ?></form>
            <?php endif; ?>
        </div>

        <?php if ($tab === 'library'): ?>
            <div class="lib-up">
                <b>새 곡 올리기</b>
                <p>mp3·m4a를 고르면 <b>자동으로 용량을 줄여서</b>(96kbps mp3, 4분 곡 약 3MB) 올려요. 직접 만들었거나 상업적으로 써도 되는(로열티 프리) 음원만 올려주세요.</p>
                <div class="lib-row"><input type="file" id="libFile" accept="audio/mpeg,audio/mp4,.mp3,.m4a"><input id="libTitle" maxlength="60" placeholder="곡 이름 (비우면 파일 이름)"><input id="libTags" maxlength="60" placeholder="태그 (쉼표로, 최대 4개) 예: 피아노, 잔잔한"><button type="button" id="libGo" class="lib-btn">올리기</button></div>
                <div class="lib-prog" id="libProg" hidden><i></i><span></span></div>
            </div>
            <?php if (!$libTracks): ?><div class="empty">아직 기본 제공 음악이 없어요.</div><?php endif; ?>
            <div class="mlist">
            <?php foreach ($libTracks as $t): ?>
                <div class="mrow">
                    <div>
                        <h4>🎵 <?= $h($t['title']) ?><?php foreach ($t['tags'] as $tg): ?> <span class="tag old">#<?= $h($tg) ?></span><?php endforeach; ?></h4>
                        <div class="meta">파일 <?= $h($t['file']) ?> · <?= $mb($t['size']) ?> · <?= $h(date('Y-m-d H:i', $t['mtime'])) ?></div>
                        <audio controls preload="none" src="/invite/music/<?= $h(rawurlencode($t['file'])) ?>?v=<?= (int) $t['mtime'] ?>"></audio>
                    </div>
                    <div class="rm">
                        <form method="post" class="lib-edit"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="lib" value="edit"><input type="hidden" name="file" value="<?= $h($t['file']) ?>">
                            <input name="title" maxlength="60" value="<?= $h($t['title']) ?>" placeholder="곡 이름"><input name="tags" maxlength="60" value="<?= $h(implode(', ', $t['tags'])) ?>" placeholder="태그 (쉼표로)"><button type="submit" class="lib-btn ghost">이름·태그 저장</button></form>
                        <form method="post" data-confirm="이 곡을 기본 제공 음악에서 뺄까요?&#10;이미 이 곡을 고른 청첩장은 배경음악이 재생되지 않아요." data-confirm-danger data-confirm-ok="빼기"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="lib" value="delete"><input type="hidden" name="file" value="<?= $h($t['file']) ?>"><button type="submit">목록에서 빼기</button></form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php elseif ($tab === 'active'): ?>
            <?php if (!$rows): ?><div class="empty"><?= $q !== '' ? '찾는 청첩장에 직접 올린 음악이 없어요.' : '직접 올린 음악이 없어요.' ?></div><?php endif; ?>
            <div class="mlist">
            <?php foreach ($rows as $r): $inv = $r['inv']; $log = $r['log']; $names = trim($inv['groom_name'] . ' ♥ ' . $inv['bride_name'], ' ♥'); ?>
                <div class="mrow">
                    <div>
                        <h4>🎵 <?= $h($r['title'] ?: ($log['orig_name'] ?? $r['file'])) ?>
                            <?php if ($log && $log['agreed_at']): ?><span class="tag ok">권리 동의 <?= $h(date('Y-m-d H:i', strtotime($log['agreed_at']))) ?></span><?php else: ?><span class="tag old">동의 기능 전 업로드</span><?php endif; ?>
                            <?php if (!$r['enabled']): ?><span class="tag off">꺼 둠</span><?php endif; ?></h4>
                        <div class="meta">
                            청첩장 <a href="admin_edit.php?id=<?= (int) $inv['id'] ?>"><b><?= $h($inv['view_slug']) ?></b></a> · <?= $h($names ?: '이름 없음') ?><?= $inv['customer_code'] ? ' · 고객 ' . $h($inv['customer_code']) : '' ?> · <?= $h($inv['status']) ?>
                            <br>파일 <?= $h($r['file']) ?> · <?= $mb($r['size']) ?><?php if ($log && $log['orig_size'] && $log['orig_size'] > $r['size'] * 1.1): ?> (원본 <?= $mb((int) $log['orig_size']) ?>에서 줄임)<?php endif; ?>
                            <?php if ($log && $log['orig_name']): ?><br>원래 파일 이름: <?= $h($log['orig_name']) ?><?php endif; ?>
                            <?php if (!$r['exists']): ?><br><b style="color:#B24A4A">서버에 파일이 없어요 (이미 지워졌을 수 있음)</b><?php endif; ?>
                        </div>
                        <?php if ($r['exists']): ?><audio controls preload="none" src="/invite/uploads/<?= (int) $inv['id'] ?>/<?= $h($r['file']) ?>"></audio><?php endif; ?>
                    </div>
                    <form method="post" class="rm" data-confirm="이 청첩장의 배경음악을 삭제할까요?&#10;음악 파일이 지워지고 배경음악이 꺼져요. 청첩장은 그대로예요." data-confirm-danger data-confirm-ok="음악 삭제">
                        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                        <select name="reason"><?php foreach (MUSIC_REASONS as $rs): ?><option><?= $h($rs) ?></option><?php endforeach; ?></select>
                        <input name="memo" maxlength="120" placeholder="메모 (선택) 예: 신고 접수번호, 권리자 이름">
                        <button type="submit">음악만 삭제</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <?php if (!$rows): ?><div class="empty">삭제한 음악이 없어요.</div><?php else: ?>
            <table class="log">
                <thead><tr><th>삭제일</th><th>청첩장</th><th>음악</th><th>동의 시각</th><th>사유</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= $r['removed_at'] ? $h(date('Y-m-d H:i', strtotime($r['removed_at']))) : '-' ?></td>
                        <td><?php if ($r['view_slug']): ?><a href="admin_edit.php?id=<?= (int) $r['invitation_id'] ?>"><?= $h($r['view_slug']) ?></a> <?= $h(trim($r['groom_name'] . ' ♥ ' . $r['bride_name'], ' ♥')) ?><?php else: ?>(삭제된 청첩장)<?php endif; ?></td>
                        <td><?= $h($r['orig_name'] ?: $r['file_name']) ?></td>
                        <td><?= $r['agreed_at'] ? $h(date('Y-m-d H:i', strtotime($r['agreed_at']))) : '기록 없음' ?></td>
                        <td><?= $h($r['removed_reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        <?php endif; ?>
        <p style="font-size:12px;color:#999;margin-top:18px;line-height:1.7">기본 제공 음악은 <b>기본 제공 음악</b> 탭에서 올리고 뺄 수 있어요 (서버의 /invite/music/ 폴더). 빼면 에디터 목록에서 사라지고, 이미 그 곡을 고른 청첩장은 재생이 멈춰요.</p>
    </div>
<script src="assets/ld-dialog.js"></script>
<script>
// 기본 제공 음악 올리기: 브라우저에서 96kbps mp3로 줄여서 보냄 (에디터 "직접 올리기"와 같은 방식, assets/lame.min.js)
(() => {
const go = document.getElementById('libGo'); if (!go) return;
const CSRF = <?= json_encode($csrf) ?>, KBPS = 96;
const prog = document.getElementById('libProg'), bar = prog.querySelector('i'), txt = prog.querySelector('span');
const show = (p, t) => { prog.hidden = false; bar.style.setProperty('--p', Math.round(p * 100) + '%'); txt.textContent = t; };
const loadLame = () => window.lamejs && lamejs.Mp3Encoder ? Promise.resolve() : new Promise((res, rej) => { const sc = document.createElement('script'); sc.src = 'assets/lame.min.js'; sc.onload = res; sc.onerror = () => rej(new Error('인코더를 불러오지 못했어요')); document.head.appendChild(sc); });
async function compress(file) {
    const AC = window.AudioContext || window.webkitAudioContext; if (!AC) return null;
    const ctx = new AC(); let audio;
    try { audio = await new Promise(async (res, rej) => { const buf = await file.arrayBuffer(); const p = ctx.decodeAudioData(buf, res, rej); if (p && p.then) p.then(res, rej); }); } finally { try { ctx.close(); } catch (e) {} }
    const dur = audio.duration || 1;
    if (/mpeg|mp3/i.test(file.type || file.name) && file.size * 8 / 1000 / dur <= KBPS * 1.25) return null; // 이미 충분히 작음
    await loadLame();
    const ok = [32000, 44100, 48000];
    if (!ok.includes(audio.sampleRate) && window.OfflineAudioContext) { const off = new OfflineAudioContext(Math.min(2, audio.numberOfChannels), Math.ceil(dur * 44100), 44100); const s = off.createBufferSource(); s.buffer = audio; s.connect(off.destination); s.start(); audio = await off.startRendering(); }
    if (!ok.includes(audio.sampleRate)) return null;
    const st = audio.numberOfChannels >= 2, enc = new lamejs.Mp3Encoder(st ? 2 : 1, audio.sampleRate, st ? KBPS : 64);
    const L = audio.getChannelData(0), R = st ? audio.getChannelData(1) : null;
    const i16 = (f, a, z) => { const o = new Int16Array(z - a); for (let i = a; i < z; i++) { const v = f[i] < -1 ? -1 : f[i] > 1 ? 1 : f[i]; o[i - a] = v < 0 ? v * 0x8000 : v * 0x7FFF; } return o; };
    const parts = [], FR = 1152, STEP = FR * 64; let t0 = performance.now();
    for (let i = 0; i < L.length; i += STEP) {
        const z = Math.min(L.length, i + STEP), l = i16(L, i, z), r = R ? i16(R, i, z) : null;
        for (let j = 0; j < l.length; j += FR) { const out = r ? enc.encodeBuffer(l.subarray(j, j + FR), r.subarray(j, j + FR)) : enc.encodeBuffer(l.subarray(j, j + FR)); if (out.length) parts.push(new Uint8Array(out.buffer, out.byteOffset, out.length).slice()); }
        if (performance.now() - t0 > 50) { show(z / L.length * .8, `용량 줄이는 중 ${Math.round(z / L.length * 100)}%`); await new Promise(r2 => setTimeout(r2, 0)); t0 = performance.now(); }
    }
    const last = enc.flush(); if (last.length) parts.push(new Uint8Array(last.buffer, last.byteOffset, last.length).slice());
    const blob = new Blob(parts, { type: 'audio/mpeg' });
    if (blob.size < 1000 || blob.size >= file.size) return null;
    return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.mp3', { type: 'audio/mpeg' });
}
const mb = n => (n / 1048576).toFixed(1) + 'MB';
go.addEventListener('click', async () => {
    const f = document.getElementById('libFile').files[0];
    if (!f) { LD.alert('파일을 골라주세요'); return; }
    if (f.size > 60 * 1048576) { LD.alert('파일이 너무 커요', { message: '60MB 이하 mp3·m4a를 골라주세요.' }); return; }
    go.disabled = true;
    let up = f, compressed = false;
    try { show(.02, '음악 확인 중…'); const r = await compress(f); if (r) { up = r; compressed = true; } } catch (e) { console.warn('브라우저에서 줄이지 못함 - 원본으로 올림(서버에서 줄여 봄)', e); }
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('lib', 'upload'); fd.append('music', up); fd.append('compressed', compressed ? '1' : '0');
    fd.append('title', document.getElementById('libTitle').value); fd.append('tags', document.getElementById('libTags').value); fd.append('orig_name', f.name);
    const xhr = new XMLHttpRequest(); xhr.open('POST', 'admin_music.php');
    xhr.upload.onprogress = e => { if (e.lengthComputable) show(.8 + e.loaded / e.total * .2, `올리는 중 ${Math.round(e.loaded / e.total * 100)}%` + (compressed ? ` (${mb(f.size)} → ${mb(up.size)})` : '')); };
    xhr.onload = () => {
        let d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
        if (!d || !d.ok) { go.disabled = false; show(0, ''); prog.hidden = true; LD.alert('올리지 못했어요', { message: (d && d.error) || ('서버 응답 ' + xhr.status) }); return; }
        show(1, '완료!'); location.href = 'admin_music.php?tab=library&msg=lib_up';
    };
    xhr.onerror = () => { go.disabled = false; LD.alert('올리지 못했어요', { message: '네트워크를 확인해주세요.' }); };
    xhr.send(fd);
});
})();
</script>
</body>
</html>
