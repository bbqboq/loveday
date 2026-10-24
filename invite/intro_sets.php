<?php
/**
 * intro_sets.php - 간편 만들기 인트로 '분위기' 관리자 설정 (관리자 → 인트로 분위기, admin_intro_sets.php에서 정함)
 *
 *  - sets: {분위기 id: 고친 분위기 통째로} (이름·설명·글자 효과·바탕·글자 색·글꼴·글자 크기·자리·스티커)
 *          고치지 않은 분위기는 assets/intro-sets.js 기본값 그대로
 *  - order: 보여 줄 순서 · off: 고객에게 안 보일 분위기
 *  저장: invite/uploads/site/intro_sets.json (전부 기본값이면 파일을 지움)
 *  직접 열면 JSON (에디터가 읽음, 개인정보 없음). 세션을 열지 않으려고 config.php를 부르지 않는다.
 */
declare(strict_types=1);

const INTRO_SETS_FILE_MAX = 262144;
const INTRO_SETS_ANIMS = ['type', 'fade', 'rise', 'blur', 'zoom', 'pop', 'line', 'draw']; // InviteBlocks.RichText.ANIMS
const INTRO_SETS_FX = ['pop', 'boing', 'spin', 'fade', 'up', 'zoom', 'left', 'right', 'drop', 'flip', 'swing', 'jelly', 'bloom', 'tada', 'rise', 'swirl']; // InviteBlocks.INTRO_STK_FX
const INTRO_SETS_LOOP = ['float', 'sway', 'shake', 'beat', 'twinkle', 'turn', 'bounce', 'breathe']; // InviteBlocks.INTRO_STK_LOOP
const INTRO_SETS_THEMES = ['romantic', 'cosmos', 'rain', 'garden', 'classic', 'party', 'season', 'webtoon'];

function intro_sets_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/intro_sets.json';
}

function intro_sets_num($v, float $lo, float $hi, int $dec = 1): ?float
{
    if (!is_numeric($v)) return null;
    return round(max($lo, min($hi, (float) $v)), $dec);
}

function intro_sets_color($v, bool $dim = false): string
{
    $v = is_string($v) ? trim($v) : '';
    if ($dim && $v === 'dim') return 'dim';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : '';
}

/** 분위기 하나 정리 (모르는 값은 버림) */
function intro_sets_clean_one($in): ?array
{
    if (!is_array($in)) return null;
    $txt = fn($v, $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v))), 0, $n);
    $o = ['n' => $txt($in['n'] ?? '', 16), 'd' => $txt($in['d'] ?? '', 30)];
    if ($o['n'] === '') return null;
    $o['anim'] = in_array($in['anim'] ?? '', INTRO_SETS_ANIMS, true) ? $in['anim'] : 'type';
    $o['bg'] = intro_sets_color($in['bg'] ?? '', true);
    if (($op = intro_sets_num($in['op'] ?? null, 0, 100, 0)) !== null && $op < 100 && $o['bg'] !== 'dim') $o['op'] = (int) $op;
    if (($c = intro_sets_color($in['color'] ?? '')) !== '') $o['color'] = $c;
    if (is_string($in['font'] ?? null) && preg_match('/^[a-z0-9-]{1,40}$/', $in['font'])) $o['font'] = $in['font'];
    if (($fs = intro_sets_num($in['fs'] ?? null, 12, 80, 0)) !== null) $o['fs'] = (int) $fs;
    foreach (['tx', 'ty'] as $k) if (($v = intro_sets_num($in[$k] ?? null, 0, 100)) !== null) $o[$k] = $v;
    if (in_array($in['align'] ?? '', ['left', 'right'], true)) $o['align'] = $in['align'];
    $stk = [];
    foreach ((array) ($in['stk'] ?? []) as $r) {
        if (!is_array($r) || count($stk) >= 12) continue;
        $t = (string) ($r[0] ?? ''); $k = (string) ($r[1] ?? '');
        if (!in_array($t, INTRO_SETS_THEMES, true) || !preg_match('/^[a-z0-9-]{1,40}$/', $k)) continue;
        if (!is_file(__DIR__ . "/assets/stickers/$t/$k.svg")) continue;
        $x = intro_sets_num($r[2] ?? 50, -10, 110) ?? 50.0; $y = intro_sets_num($r[3] ?? 50, -10, 110) ?? 50.0;
        $fx = in_array($r[4] ?? '', INTRO_SETS_FX, true) ? $r[4] : '';
        $sz = (int) (intro_sets_num($r[5] ?? 64, 14, 240, 0) ?? 64);
        $lp = in_array($r[6] ?? '', INTRO_SETS_LOOP, true) ? $r[6] : '';
        $rot = (int) (intro_sets_num($r[7] ?? 0, -180, 180, 0) ?? 0);
        $flip = !empty($r[8]) ? 1 : 0;
        $stk[] = [$t, $k, $x, $y, $fx, $sz, $lp, $rot, $flip];
    }
    $o['stk'] = $stk;
    return $o;
}

/** 받은 값을 정리 */
function intro_sets_clean($in): array
{
    $in = is_array($in) ? $in : [];
    $idOk = fn($id) => is_string($id) && preg_match('/^[a-z0-9-]{1,24}$/', $id);
    $sets = [];
    foreach ((array) ($in['sets'] ?? []) as $id => $set) {
        if (!$idOk($id) || count($sets) >= 60) continue;
        if (($c = intro_sets_clean_one($set)) !== null) $sets[$id] = $c;
    }
    $order = []; foreach ((array) ($in['order'] ?? []) as $id) if ($idOk($id) && !in_array($id, $order, true) && count($order) < 60) $order[] = $id;
    $off = []; foreach ((array) ($in['off'] ?? []) as $id) if ($idOk($id) && !in_array($id, $off, true) && count($off) < 60) $off[] = $id;
    return ['sets' => $sets, 'order' => $order, 'off' => $off];
}

function intro_sets_get(): array
{
    $f = intro_sets_file();
    if (!is_file($f) || filesize($f) > INTRO_SETS_FILE_MAX) return intro_sets_clean([]);
    return intro_sets_clean(json_decode((string) file_get_contents($f), true));
}

function intro_sets_save($in): bool
{
    $s = intro_sets_clean($in);
    $f = intro_sets_file();
    if (!$s['sets'] && !$s['order'] && !$s['off']) return !is_file($f) || @unlink($f);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($s + ['updated_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}

// 직접 열었을 때만 JSON (admin_intro_sets.php가 require하면 함수만 씀)
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $s = intro_sets_get();
    echo json_encode(['sets' => $s['sets'] ?: new stdClass(), 'order' => $s['order'], 'off' => $s['off']], JSON_UNESCAPED_UNICODE);
}
