<?php
declare(strict_types=1);
/**
 * photo_thumbs.php - 청첩장 사진의 작은 사본 (1019z11, 서버 전송량 줄이기)
 *
 *  uploads/{청첩장 id}/{파일}.webp  (공개용 원본, 긴 변 최대 1920px)
 *  uploads/{청첩장 id}/s/{파일}.webp  긴 변 800px  - 갤러리 여러 칸(정사각·세로 그리드·써클)
 *  uploads/{청첩장 id}/m/{파일}.webp  긴 변 1280px - 갤러리 한 칸·슬라이드·콜라주
 *  원본보다 작지 않으면 사본을 만들지 않음 (하객 화면은 사본이 없으면 원본으로 - InviteBlocks.thumbFail)
 *  공개용 원본이 바뀌면(워터마크 넣기·빼기) 사본도 다시 만듦 (reprocess_invitation_photos)
 *
 *  만드는 곳:
 *   1. 에디터가 올릴 때 브라우저에서 같이 만들어 보냄(thumb_s·thumb_m) → 서버는 검사만 하고 저장 (CPU 거의 안 씀)
 *   2. 무료체험(워터마크)·예전 브라우저면 서버가 GD로 만듦
 *   3. 예전에 올린 사진은 expire_cleanup.php(1시간 크론)가 photo_thumbs_backfill()로 조금씩 채움
 *      당장 다 만들려면: php photo_thumbs.php all
 */
require_once __DIR__ . '/functions.php';

const PHOTO_THUMBS = ['s' => 800, 'm' => 1280];
const PHOTO_THUMB_BYTES = 600 * 1024; // 브라우저에서 만들어 온 사본 최대 용량
const PHOTO_THUMB_RE = '/^[0-9a-f]{32}\.webp$/';

/** 사본을 둘 수 있는 파일 이름인지 (에디터가 올린 사진 = 32자.webp) */
function photo_thumb_ok_name(string $name): bool
{
    return (bool) preg_match(PHOTO_THUMB_RE, $name);
}

function photo_thumbs_drop(string $dir, string $name): void
{
    if (!photo_thumb_ok_name($name)) return;
    foreach (array_keys(PHOTO_THUMBS) as $k) @unlink($dir . $k . '/' . $name);
}

/** GD 그림에서 사본을 만듦 (원본보다 작을 때만) - 만든 개수 */
function photo_thumbs_from_gd($im, string $dir, string $name): int
{
    if (!photo_thumb_ok_name($name) || !function_exists('imagewebp')) return 0;
    $w = imagesx($im); $h = imagesy($im); $n = 0;
    foreach (PHOTO_THUMBS as $k => $side) {
        $path = $dir . $k . '/' . $name;
        if (max($w, $h) <= $side * 1.1) { @unlink($path); continue; } // 원본이 이미 작음 → 원본을 씀
        if (!is_dir($dir . $k) && !@mkdir($dir . $k, 0750, true) && !is_dir($dir . $k)) continue;
        $r = $side / max($w, $h);
        $tw = max(1, (int) round($w * $r)); $th = max(1, (int) round($h * $r));
        $t = imagecreatetruecolor($tw, $th);
        imagealphablending($t, false); imagesavealpha($t, true);
        imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
        if (@imagewebp($t, $path . '.tmp', $k === 's' ? 76 : 80) && @rename($path . '.tmp', $path)) $n++;
        else @unlink($path . '.tmp');
        imagedestroy($t);
    }
    return $n;
}

/** 공개용 원본 파일을 열어 사본을 만듦 */
function photo_thumbs_make(string $dir, string $name): int
{
    if (!photo_thumb_ok_name($name) || !is_file($dir . $name) || !function_exists('imagecreatefromwebp')) return 0;
    $im = @imagecreatefromwebp($dir . $name);
    if (!$im) return 0;
    $n = photo_thumbs_from_gd($im, $dir, $name);
    imagedestroy($im);
    return $n;
}

/** 에디터가 같이 보낸 사본(thumb_s·thumb_m)을 검사해서 저장 - 둘 다 제대로 받았으면 true */
function photo_thumbs_store_client(array $files, string $dir, string $name): bool
{
    if (!photo_thumb_ok_name($name)) return false;
    $info0 = @getimagesize($dir . $name);
    if (!$info0) return false;
    $long0 = max($info0[0], $info0[1]);
    $ok = 0; $need = 0;
    foreach (PHOTO_THUMBS as $k => $side) {
        if ($long0 <= $side * 1.1) continue; // 이 크기는 사본이 필요 없음
        $need++;
        $f = $files['thumb_' . $k] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) continue;
        $tmp = (string) $f['tmp_name'];
        if ((int) $f['size'] <= 0 || (int) $f['size'] > PHOTO_THUMB_BYTES) continue;
        $head = (string) @file_get_contents($tmp, false, null, 0, 12);
        if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') continue;
        if ((new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'image/webp') continue;
        $info = @getimagesize($tmp);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_WEBP || max($info[0], $info[1]) > $side + 2 || max($info[0], $info[1]) < $side * 0.5) continue;
        if (!is_dir($dir . $k) && !@mkdir($dir . $k, 0750, true) && !is_dir($dir . $k)) continue;
        if (@copy($tmp, $dir . $k . '/' . $name)) $ok++;
    }
    return $need === 0 || $ok === $need;
}

/**
 * 예전 사진 사본 채우기 (크론) - invitation_photos를 id 순서로 조금씩 훑음, 어디까지 봤는지는 uploads/site/thumbs_cursor.txt
 * 한 바퀴 다 돌면 처음부터 다시 (새로 생긴 빈칸·바뀐 원본 찾기). 시간·개수 한도 안에서만.
 */
function photo_thumbs_backfill(PDO $pdo, float $budgetSec = 20.0, int $maxMake = 300): array
{
    $cf = UPLOAD_DIR . 'site/thumbs_cursor.txt';
    $last = is_file($cf) ? (int) @file_get_contents($cf) : 0;
    $t0 = microtime(true); $made = 0; $seen = 0;
    $st = $pdo->prepare('SELECT id, invitation_id, file_path FROM invitation_photos WHERE id > ? ORDER BY id LIMIT 500');
    while (microtime(true) - $t0 < $budgetSec && $made < $maxMake) {
        $st->execute([$last]);
        $rows = $st->fetchAll();
        if (!$rows) { $last = 0; break; } // 한 바퀴 끝
        foreach ($rows as $r) {
            $last = (int) $r['id']; $seen++;
            $name = basename((string) $r['file_path']);
            if (!photo_thumb_ok_name($name)) continue;
            $dir = invitation_upload_dir((int) $r['invitation_id']);
            $src = $dir . $name;
            if (!is_file($src)) continue;
            $mt = (int) @filemtime($src); $want = false;
            foreach (array_keys(PHOTO_THUMBS) as $k) { $p = $dir . $k . '/' . $name; if (!is_file($p) || (int) @filemtime($p) < $mt) { $want = true; break; } }
            if (!$want) continue;
            // 원본이 이미 작아서 사본이 없는 경우는 크기를 보고 건너뜀
            $info = @getimagesize($src);
            if ($info && max($info[0], $info[1]) <= min(PHOTO_THUMBS) * 1.1) continue;
            $made += photo_thumbs_make($dir, $name) > 0 ? 1 : 0;
            if (microtime(true) - $t0 >= $budgetSec || $made >= $maxMake) break;
        }
    }
    if (is_dir(UPLOAD_DIR . 'site')) @file_put_contents($cf, (string) $last);
    return ['made' => $made, 'seen' => $seen, 'cursor' => $last];
}

// php photo_thumbs.php all  → 시간 제한 없이 끝까지 한 번에 (서버가 한가할 때)
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $pdo = get_pdo();
    $all = ($argv[1] ?? '') === 'all';
    $tot = 0;
    do { $r = photo_thumbs_backfill($pdo, $all ? 60.0 : 20.0, $all ? 100000 : 300); $tot += $r['made']; echo date('H:i:s') . " 사본 {$r['made']}장 만듦 (본 줄 {$r['seen']}, 위치 {$r['cursor']})\n"; } while ($all && $r['cursor'] !== 0 && $r['seen'] > 0);
    echo "끝: {$tot}장\n";
}
