<?php
/**
 * sticker_settings.php - 스티커 창 설정 (관리자 → 스티커, admin_stickers.php에서 정함)
 *
 *  - 테마 순서 · 켜고 끄기 (꺼 둔 테마는 고객 스티커 창에 안 나옴)
 *  - 그림 하나씩 숨기기 ('a:테마/이름' = 새 그림 SVG, 'v:이름' = 빈티지 WebP)
 *  - 디자인마다 [추천] 탭에 먼저 보일 테마 2개 (정하지 않은 디자인은 preset.json stickerThemes → 에디터 기본값 STK_REC)
 *  저장: invite/uploads/site/sticker_settings.json (전부 기본값이면 파일을 지움)
 *  직접 열면 JSON (에디터가 읽음, 개인정보 없음). 세션을 열지 않으려고 config.php를 부르지 않는다.
 *  테마 id·기본 추천은 에디터 STK_THEMES·STK_REC와 같게 둘 것.
 */
declare(strict_types=1);

const STICKER_THEMES = [
    'romantic' => '로맨틱', 'cosmos' => '별·우주', 'rain' => '비 오는 날', 'garden' => '가든·꽃',
    'classic' => '클래식', 'party' => '축하·파티', 'webtoon' => '웹툰', 'season' => '봄·여름·가을·겨울', 'vintage' => '빈티지 그림 전체',
];
// 디자인마다 기본 추천 (에디터 STK_REC와 같음)
const STICKER_REC_DEFAULT = [
    'classic' => ['classic', 'romantic'], 'modern' => ['classic', 'party'], 'pastel' => ['garden', 'romantic'], 'p-mono' => ['classic', 'cosmos'],
    'p-romantic' => ['romantic', 'garden'], 'p-garden' => ['garden', 'season'], 'p-navy' => ['cosmos', 'classic'], 'p-earth' => ['garden', 'season'],
    'p-lavender' => ['garden', 'romantic'], 'p-film' => ['classic', 'romantic'], 'p-cinema' => ['cosmos', 'classic'], 'p-photos' => ['party', 'romantic'],
    'p-story' => ['romantic', 'season'], 'p-typo' => ['classic', 'party'], 'p-notice' => ['classic', 'garden'], 'p-scrapbook' => ['party', 'romantic'],
    'p-cosmos' => ['cosmos', 'classic'], 'p-rain' => ['rain', 'garden'], 'p-midnight' => ['cosmos', 'classic'], 'p-weather' => ['rain', 'season'], 'p-webtoon' => ['webtoon', 'party'],
];
const STICKER_FILE_MAX = 65536;
const STICKER_KEY_RE = '#^(a:(romantic|cosmos|rain|garden|classic|party|season|webtoon)/[a-z0-9-]{1,40}|v:[a-z0-9-]{1,40})$#';

function sticker_settings_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/sticker_settings.json';
}

/** 받은 값을 정리 (모르는 테마·이상한 이름은 버림) */
function sticker_settings_clean($in): array
{
    $in = is_array($in) ? $in : [];
    $ids = array_keys(STICKER_THEMES);
    $order = [];
    foreach ((array) ($in['order'] ?? []) as $id) if (is_string($id) && in_array($id, $ids, true) && !in_array($id, $order, true)) $order[] = $id;
    foreach ($ids as $id) if (!in_array($id, $order, true)) $order[] = $id; // 새 테마는 맨 뒤
    $off = [];
    foreach ((array) ($in['off'] ?? []) as $id) if (is_string($id) && in_array($id, $ids, true) && !in_array($id, $off, true)) $off[] = $id;
    if (count(array_diff($ids, $off, ['vintage'])) === 0) $off = array_values(array_diff($off, ['romantic'])); // 그림 테마가 하나는 켜져 있게
    $hide = [];
    foreach ((array) ($in['hide'] ?? []) as $k) if (is_string($k) && preg_match(STICKER_KEY_RE, $k) && !in_array($k, $hide, true)) $hide[] = $k;
    $rec = [];
    foreach ((array) ($in['rec'] ?? []) as $pid => $list) {
        if (!is_string($pid) || !preg_match('/^[a-z0-9_-]{1,40}$/', $pid) || !is_array($list)) continue;
        $l = [];
        foreach ($list as $id) if (is_string($id) && isset(STICKER_THEMES[$id]) && $id !== 'vintage' && !in_array($id, $l, true)) $l[] = $id;
        if ($l) $rec[$pid] = array_slice($l, 0, 2);
        if (count($rec) >= 200) break;
    }
    return ['order' => $order, 'off' => $off, 'hide' => array_slice($hide, 0, 600), 'rec' => $rec];
}

function sticker_settings_get(): array
{
    $f = sticker_settings_file();
    if (!is_file($f) || filesize($f) > STICKER_FILE_MAX) return sticker_settings_clean([]);
    return sticker_settings_clean(json_decode((string) file_get_contents($f), true));
}

function sticker_settings_save($in): bool
{
    $s = sticker_settings_clean($in);
    $f = sticker_settings_file();
    if ($s === sticker_settings_clean([])) return !is_file($f) || @unlink($f);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($s + ['updated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}

// 직접 열었을 때만 JSON (admin_stickers.php가 require하면 함수만 씀)
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $s = sticker_settings_get();
    echo json_encode(['order' => $s['order'], 'off' => $s['off'], 'hide' => $s['hide'], 'rec' => $s['rec'] ?: new stdClass()], JSON_UNESCAPED_UNICODE);
}
