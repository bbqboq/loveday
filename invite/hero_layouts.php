<?php
/**
 * hero_layouts.php - 메인 레이아웃 (관리자가 에디터 메인 화면을 꾸며서 저장한 글자 모양·자리 묶음)
 *
 *  기본 레이아웃(27개)은 assets/invite-blocks.js의 HERO_LAYOUTS에 있고, 여기는 관리자가 더 만든 것 + 관리자 설정만 담는다.
 *  고객 에디터(간편 만들기 → 메인 화면 → 레이아웃)가 목록을 받아 기본 레이아웃 뒤에 붙여 보여준다.
 *  1019w: 관리자 화면 admin_hero_layouts.php (숨기기 · 묶음 · 새로 만들기 캔버스)
 *    off  = 고르는 화면에서 숨긴 레이아웃 id (기본·직접 만든 것 모두, 지우지 않음 - 이미 고른 청첩장은 그대로)
 *    cats = 레이아웃 id → {c: 묶음(full·date·box·concept), core: 대표(묶음 첫 줄)} (없으면 에디터 HL_CATS 기본 묶음)
 *
 *  공개 (로그인 필요 없음, 개인정보 없음):
 *    GET  ?a=list            → {"items":[{id,label,desc,photo,video,parts,hide,layers,box,cat,core}],"off":[…],"cats":{…}}
 *  관리자 전용 ("사업자·사이트 정보 수정" 권한):
 *    GET  ?a=me              → {"ok":true,"admin":true}  (에디터가 [레이아웃으로 저장] 칸을 보여줄지)
 *    POST ?a=save            → JSON 본문 {layout:{…}} (id가 없으면 새로 만듦) → {"ok":true,"id":…}
 *    POST ?a=delete          → JSON 본문 {id}
 *    POST ?a=vis             → JSON 본문 {id, on}       (고르는 화면에 보이기/숨기기)
 *    POST ?a=cat             → JSON 본문 {id, c, core}  (묶음·대표, c가 ''면 기본 묶음으로)
 *  저장 위치: uploads/site/hero_layouts.json
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

const HL_MAX_BODY = 200_000;
const HL_MAX_ITEMS = 60;
const HL_PART_KEYS = ['groomName', 'heart', 'brideName', 'datetime'];
const HL_MAX_LAYERS = 12;
const HL_CAT_KEYS = ['full', 'date', 'box', 'concept', 'shape'];
const HL_DECO_KEYS = ['', 'bubble', 'caption', 'caption-w', 'sfx', 'tag', 'seal', 'card', 'line', 'photo'];
const HL_FRAME_KEYS = ['none', 'rounded', 'arch', 'polaroid', 'shadow', 'comic', 'baroque', 'stamp', 'lace', 'botanical', 'ribbon', 'oval', 'circle', 'mat', 'overlap', 'film', 'twin', 'torn', 'blob', 'grid4', 'hidden'];

function hl_file(): string { return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/hero_layouts.json'; }
function hl_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
/** 파일 전체 {items, off, cats} */
function hl_load_cfg(): array
{
    $f = hl_file();
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $off = array_values(array_unique(array_filter(is_array($j['off'] ?? null) ? $j['off'] : [], fn($x) => is_string($x) && preg_match('/^[a-z0-9-]{2,40}$/', $x))));
    $cats = [];
    foreach (is_array($j['cats'] ?? null) ? $j['cats'] : [] as $id => $c) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{2,40}$/', $id) && is_array($c) && in_array($c['c'] ?? '', HL_CAT_KEYS, true)) $cats[$id] = ['c' => $c['c'], 'core' => !empty($c['core'])];
    }
    return ['items' => is_array($j['items'] ?? null) ? array_values($j['items']) : [], 'off' => $off, 'cats' => $cats];
}
function hl_load(): array { return hl_load_cfg()['items']; }
function hl_save_cfg(array $cfg): void
{
    $dir = dirname(hl_file());
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) hl_json(['ok' => false, 'error' => '저장 폴더를 만들 수 없어요.'], 500);
    $out = ['items' => array_values($cfg['items'] ?? []), 'off' => array_values($cfg['off'] ?? []), 'cats' => (object) ($cfg['cats'] ?? [])];
    file_put_contents(hl_file(), json_encode($out, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function hl_save_all(array $items): void { $c = hl_load_cfg(); $c['items'] = $items; hl_save_cfg($c); }
function hl_public(array $c): array { return ['items' => $c['items'], 'off' => $c['off'], 'cats' => (object) $c['cats']]; }
function hl_num($v, float $min, float $max): ?float { return is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : null; }
function hl_str($v, int $len): string { return mb_substr(trim((string) (is_scalar($v) ? $v : '')), 0, $len); }
function hl_hex($v): string { return is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : ''; }

/** 글자 칸 하나의 자리·모양 (정해진 키만, 값 범위도 막아 둠) */
function hl_style($s): array
{
    if (!is_array($s)) return [];
    $o = [];
    foreach (['x' => [0, 100], 'y' => [0, 100], 'fontSize' => [6, 160], 'scaleX' => [30, 300], 'width' => [5, 100], 'rotation' => [-180, 180], 'ls' => [-300, 1000]] as $k => [$mn, $mx]) {
        $n = hl_num($s[$k] ?? null, $mn, $mx);
        if ($n !== null) $o[$k] = $n;
    }
    foreach (['widthAuto', 'outline', 'shadow'] as $k) if (isset($s[$k])) $o[$k] = (bool) $s[$k];
    foreach (['arc' => [-100, 100], 'arcW' => [10, 100], 'animDur' => [0.3, 8], 'animDelay' => [0, 8]] as $k => [$mn, $mx]) { $n = hl_num($s[$k] ?? null, $mn, $mx); if ($n !== null) $o[$k] = $n; }
    if (isset($s['vertical']) && in_array($s['vertical'], ['', 'up', 'side'], true)) $o['vertical'] = $s['vertical'];
    if (isset($s['anim']) && in_array($s['anim'], ['', 'write', 'fade', 'up', 'zoom'], true)) $o['anim'] = $s['anim'];
    if (isset($s['font']) && is_string($s['font']) && preg_match('/^[a-z0-9-]{0,30}$/', $s['font'])) $o['font'] = $s['font'];
    if (isset($s['color'])) $o['color'] = hl_hex($s['color']);
    if (isset($s['glow'])) $o['glow'] = hl_hex($s['glow']);
    if (isset($s['align']) && in_array($s['align'], ['', 'left', 'center', 'right'], true)) $o['align'] = $s['align'];
    if (isset($s['deco']) && in_array($s['deco'], HL_DECO_KEYS, true)) $o['deco'] = $s['deco']; // 1019w 글자 상자 (말풍선 등)
    if (isset($s['stk']) && is_string($s['stk']) && preg_match('#^[a-z0-9-]+/[a-z0-9-]+\.(svg|webp)$#', $s['stk']) && is_file(__DIR__ . '/assets/stickers/' . $s['stk'])) $o['stk'] = $s['stk']; // 1019z2 스티커 칸
    return $o;
}
/** 화면 크기 같은 설정 (정해진 키·값만) */
function hl_view($v, bool $video): array
{
    if (!is_array($v)) return [];
    $o = [];
    if ($video) {
        if (isset($v['heightMode']) && in_array($v['heightMode'], ['full', '3/4', '16/9', '9/16'], true)) $o['heightMode'] = $v['heightMode'];
        if (isset($v['videoTextOver'])) $o['videoTextOver'] = (bool) $v['videoTextOver'];
    } else {
        if (isset($v['heroWidth']) && in_array($v['heroWidth'], ['full', 'frame'], true)) $o['heroWidth'] = $v['heroWidth'];
        if (isset($v['heroRatio']) && is_string($v['heroRatio']) && preg_match('/^[a-z0-9\/]{1,10}$/', $v['heroRatio'])) $o['heroRatio'] = $v['heroRatio'];
        if (isset($v['heroFrame']) && in_array($v['heroFrame'], ['none', 'rounded', 'circle', 'pill', 'arch'], true)) $o['heroFrame'] = $v['heroFrame'];
        if (isset($v['heroTextOver'])) $o['heroTextOver'] = (bool) $v['heroTextOver'];
        foreach (['heroHeightPx' => [160, 1000], 'heroTextSpace' => [0, 500]] as $k => [$mn, $mx]) { $n = hl_num($v[$k] ?? null, $mn, $mx); if ($n !== null) $o[$k] = $n; }
    }
    if (isset($v['heroShade']) && in_array($v['heroShade'], ['none', 'bottom', 'top', 'both', 'full', 'light'], true)) $o['heroShade'] = $v['heroShade'];
    if (isset($v['heroShadeLv']) && in_array((string) $v['heroShadeLv'], ['1', '2', '3'], true)) $o['heroShadeLv'] = (string) $v['heroShadeLv'];
    return $o;
}
/** 사진 칸 (화면 기준 %, 모양, 아래쪽 흐려짐) - 없으면 null */
function hl_box($b): ?array
{
    if (!is_array($b)) return null;
    $o = [];
    foreach (['x' => [-50, 100], 'y' => [-50, 100], 'w' => [5, 200], 'h' => [5, 200], 'fade' => [0, 90]] as $k => [$mn, $mx]) { $n = hl_num($b[$k] ?? null, $mn, $mx); if ($n !== null) $o[$k] = $n; }
    $o['frame'] = in_array($b['frame'] ?? '', HL_FRAME_KEYS, true) ? $b['frame'] : 'none';
    if (hl_hex($b['bg'] ?? '') !== '') $o['bg'] = hl_hex($b['bg']);
    return $o;
}
function hl_clean(array $l): array
{
    $parts = [];
    foreach (HL_PART_KEYS as $k) if (isset($l['parts'][$k])) $parts[$k] = hl_style($l['parts'][$k]);
    $layers = [];
    foreach (array_slice(is_array($l['layers'] ?? null) ? $l['layers'] : [], 0, HL_MAX_LAYERS) as $L) {
        if (!is_array($L)) continue;
        $layers[] = ['text' => hl_str($L['text'] ?? '', 200)] + hl_style($L);
    }
    return [
        'id' => is_string($l['id'] ?? null) && preg_match('/^a-[a-z0-9]{4,20}$/', $l['id']) ? $l['id'] : 'a-' . bin2hex(random_bytes(5)),
        'label' => hl_str($l['label'] ?? '', 40) ?: '새 레이아웃',
        'desc' => hl_str($l['desc'] ?? '', 80),
        'photo' => hl_view($l['photo'] ?? null, false) ?: null, // (비면 null - 예전엔 [] 라서 사진 칸 레이아웃을 영상에 쓸 때 영상이 칸 안으로 안 들어갔음)
        'video' => hl_view($l['video'] ?? null, true) ?: null,
        'edge' => !empty($l['edge']), // 글자를 화면 끝(1~99%)까지 끌 수 있게
        'parts' => $parts,
        'hide' => array_values(array_intersect(HL_PART_KEYS, is_array($l['hide'] ?? null) ? $l['hide'] : [])),
        'layers' => $layers,
        'box' => hl_box($l['box'] ?? null),
        'cat' => in_array($l['cat'] ?? '', HL_CAT_KEYS, true) ? $l['cat'] : '',
        'core' => !empty($l['core']),
        'from' => is_string($l['from'] ?? null) && preg_match('/^[a-z0-9-]{2,40}$/', $l['from']) ? $l['from'] : '', // 복제한 기본 레이아웃
        'updated' => date('Y-m-d H:i'),
    ];
}

$a = (string) ($_GET['a'] ?? 'list');
if ($a === 'list') hl_json(hl_public(hl_load_cfg()));

// ---------------------------------------------------------------- 관리자
define('ADMIN_GUARD_PASSIVE', true); // 로그인이 끊겼으면 화면 이동 대신 JSON으로 알림 (고객 에디터는 이걸 보고 저장 칸을 숨김)
require_once __DIR__ . '/admin_guard.php';
if ($a === 'me') hl_json(['ok' => true, 'admin' => admin_can('site_settings')]);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') hl_json(['ok' => false, 'error' => '허용되지 않는 요청이에요.'], 405);
admin_require('site_settings', '메인 레이아웃 저장');
// 다른 사이트에서 몰래 보내는 요청 막기 (JSON 본문이라 CSRF 칸 대신 출처 확인 - sample_api.php와 같은 방식)
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) hl_json(['ok' => false, 'error' => '허용되지 않은 요청이에요.'], 403);
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > HL_MAX_BODY) hl_json(['ok' => false, 'error' => '너무 커요.'], 413);
$body = json_decode($raw, true);
if (!is_array($body)) hl_json(['ok' => false, 'error' => '잘못된 요청이에요.'], 400);
$cfg = hl_load_cfg();
$items = $cfg['items'];

if ($a === 'save') {
    if (!is_array($body['layout'] ?? null)) hl_json(['ok' => false, 'error' => '저장할 레이아웃이 없어요.'], 400);
    $l = hl_clean($body['layout']);
    if (!$l['layers'] && !$l['parts']) hl_json(['ok' => false, 'error' => '글자 칸이 없어요.'], 400);
    $i = array_search($l['id'], array_column($items, 'id'), true);
    if ($i === false) { if (count($items) >= HL_MAX_ITEMS) hl_json(['ok' => false, 'error' => '레이아웃은 ' . HL_MAX_ITEMS . '개까지 저장할 수 있어요.'], 400); $items[] = $l; }
    else $items[$i] = $l;
    $cfg['items'] = $items;
    hl_save_cfg($cfg);
    hl_json(['ok' => true, 'id' => $l['id']] + hl_public($cfg));
}
if ($a === 'delete') {
    $id = (string) ($body['id'] ?? '');
    $cfg['items'] = array_values(array_filter($items, fn($x) => ($x['id'] ?? '') !== $id));
    $cfg['off'] = array_values(array_diff($cfg['off'], [$id]));
    unset($cfg['cats'][$id]);
    hl_save_cfg($cfg);
    hl_json(['ok' => true] + hl_public($cfg));
}
$id = (string) ($body['id'] ?? '');
if (!preg_match('/^[a-z0-9-]{2,40}$/', $id)) hl_json(['ok' => false, 'error' => '레이아웃 이름이 올바르지 않아요.'], 400);
if ($a === 'vis') {
    $cfg['off'] = array_values(array_diff($cfg['off'], [$id]));
    if (empty($body['on'])) $cfg['off'][] = $id;
    hl_save_cfg($cfg);
    hl_json(['ok' => true] + hl_public($cfg));
}
if ($a === 'cat') {
    $c = (string) ($body['c'] ?? '');
    if ($c === '') unset($cfg['cats'][$id]);
    elseif (in_array($c, HL_CAT_KEYS, true)) $cfg['cats'][$id] = ['c' => $c, 'core' => !empty($body['core'])];
    else hl_json(['ok' => false, 'error' => '묶음이 올바르지 않아요.'], 400);
    hl_save_cfg($cfg);
    hl_json(['ok' => true] + hl_public($cfg));
}
hl_json(['ok' => false, 'error' => '알 수 없는 요청이에요.'], 400);
