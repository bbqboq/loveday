<?php
/**
 * sample_api.php - 디자인 샘플 (관리자가 에디터로 직접 꾸며 저장한 디자인)
 *
 *  디자인 프리셋(presets 폴더의 preset.json)은 색·글꼴·섹션 순서 정도만 담는다.
 *  "샘플"은 관리자가 실제 에디터(editor-prototype-v3-overlay.html?sample=아이디)에서 사진·글·스티커·글자 위치까지
 *  전부 꾸며서 저장한 완성 디자인(design_json과 같은 모양)이다.
 *   - 기존 디자인을 편집해 저장하면 → 그 디자인을 고른 고객은 이 샘플 상태로 시작한다 (원래대로 되돌리기 가능)
 *   - 새 샘플 만들기 → 바탕 디자인을 골라 새 디자인을 하나 더 만든다 (디자인 고르기 화면·추천 디자인에 나옴)
 *
 *  공개 (로그인 필요 없음, 개인정보 없음):
 *    GET ?a=list          → {"items":[{"id","label","desc","base","custom","updated"}]}
 *    GET ?a=get&id=…      → {"ok":true,"design":{…}}  (고객 에디터가 디자인을 고를 때)
 *  관리자 전용 ("사업자·사이트 정보 수정" 권한):
 *    GET  ?a=admin_list   → 관리자 화면용 전체 목록 (저장 안 한 새 샘플 포함)
 *    GET  ?a=load&id=…    → 에디터 불러오기 (invite_load.php와 같은 모양, 저장한 적 없으면 design:null + sample_base)
 *    POST ?a=save&id=…    → 에디터 저장 (JSON 본문 {design:{…}} - invite_save.php와 같은 모양)
 *    POST ?a=upload&id=…  → 샘플용 사진·스티커 올리기 (photo 또는 sticker 파일) → {"ok":true,"url":…}
 *    POST ?a=create       → 새 샘플 {base, label, desc} → {"ok":true,"id":"s-…"}
 *    POST ?a=reset        → 편집 내용 지우기 (기존 디자인은 원래대로, 새 샘플은 목록에서 삭제) {id}
 *    POST ?a=meta         → 새 샘플 이름·설명 바꾸기 {id, label, desc}
 *  저장 위치: uploads/site/samples/index.json (목록), samples/<id>.json (디자인), samples/img/ (사진)
 */
declare(strict_types=1);

const SAMPLE_ID_RE = '/^[a-z0-9-]{2,40}$/';
const SAMPLE_MAX_JSON = 2_000_000;

function sample_dir(): string { return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/samples/'; }
function sample_json(array $d, int $code = 200): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function sample_index(): array
{
    $f = sample_dir() . 'index.json';
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($j['items'] ?? null) ? $j['items'] : [];
}
function sample_index_save(array $items): void
{
    if (!is_dir(sample_dir()) && !mkdir(sample_dir(), 0750, true) && !is_dir(sample_dir())) throw new RuntimeException('저장 폴더를 만들 수 없어요');
    file_put_contents(sample_dir() . 'index.json', json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function sample_id(string $k = 'id'): string
{
    $id = (string) ($_GET[$k] ?? $_POST[$k] ?? '');
    if (!preg_match(SAMPLE_ID_RE, $id)) sample_json(['ok' => false, 'error' => '잘못된 샘플 이름이에요.'], 400);
    return $id;
}
function sample_clean_text($v, int $max): string { return mb_substr(trim(strip_tags((string) $v)), 0, $max); }

$a = (string) ($_GET['a'] ?? '');

// ---------------------------------------------------------------- 공개
if ($a === 'list') {
    $out = [];
    foreach (sample_index() as $id => $m) {
        if (!is_file(sample_dir() . $id . '.json')) continue; // 아직 한 번도 저장 안 한 새 샘플은 고객에게 안 보임
        $out[] = ['id' => $id, 'label' => (string) ($m['label'] ?? ''), 'desc' => (string) ($m['desc'] ?? ''), 'base' => (string) ($m['base'] ?? ''), 'custom' => !empty($m['custom']), 'updated' => (string) ($m['updated'] ?? ''), 'cover' => (string) ($m['cover'] ?? '')];
    }
    sample_json(['items' => $out]);
}
if ($a === 'get') {
    $id = sample_id();
    $f = sample_dir() . $id . '.json';
    if (!is_file($f)) sample_json(['ok' => false, 'error' => '샘플이 없어요.'], 404);
    $d = json_decode((string) file_get_contents($f), true);
    sample_json(['ok' => is_array($d), 'design' => $d]);
}

// ---------------------------------------------------------------- 관리자
define('ADMIN_GUARD_PASSIVE', true); // 로그인이 끊겼으면 화면 이동 대신 JSON으로 알림
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_require('site_settings', '디자인 샘플 저장');
    // 다른 사이트에서 몰래 보내는 요청 막기 (에디터 저장은 JSON 본문이라 CSRF 칸 대신 출처 확인)
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '') && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        sample_json(['ok' => false, 'error' => '허용되지 않은 요청이에요.'], 403);
    }
}

if ($a === 'admin_list') { // 관리자 화면: 아직 저장 안 한 새 샘플까지 전부
    $out = [];
    foreach (sample_index() as $id => $m) $out[] = ['id' => $id, 'label' => (string) ($m['label'] ?? ''), 'desc' => (string) ($m['desc'] ?? ''), 'base' => (string) ($m['base'] ?? ''), 'custom' => !empty($m['custom']), 'updated' => (string) ($m['updated'] ?? ''), 'saved' => is_file(sample_dir() . $id . '.json')];
    sample_json(['ok' => true, 'items' => $out]);
}
if ($a === 'load') {
    $id = sample_id();
    $idx = sample_index();
    $f = sample_dir() . $id . '.json';
    $design = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    $m = $idx[$id] ?? [];
    sample_json([
        'ok' => true, 'design' => is_array($design) ? $design : null,
        'sample_base' => (string) ($m['base'] ?? $id), 'sample_label' => (string) ($m['label'] ?? ''), 'sample_custom' => !empty($m['custom']),
        'csrf_token' => csrf_token(), 'personal_stickers' => [],
        'groom_name' => '', 'bride_name' => '', 'status' => 'editing', 'view_slug' => null, 'expiry' => null,
    ]);
}
if ($a === 'save') {
    $id = sample_id();
    $raw = (string) file_get_contents('php://input');
    if (strlen($raw) > SAMPLE_MAX_JSON) sample_json(['ok' => false, 'error' => '디자인이 너무 커요.'], 413);
    $body = json_decode($raw, true);
    $design = is_array($body['design'] ?? null) ? $body['design'] : null;
    if (!$design || !is_array($design['blocks'] ?? null)) sample_json(['ok' => false, 'error' => '저장할 디자인이 올바르지 않아요.'], 400);
    if (!is_dir(sample_dir()) && !mkdir(sample_dir(), 0750, true)) sample_json(['ok' => false, 'error' => '저장 폴더를 만들 수 없어요.'], 500);
    $design['presetId'] = $id;
    file_put_contents(sample_dir() . $id . '.json', json_encode($design, JSON_UNESCAPED_UNICODE), LOCK_EX);
    // 디자인 고르기 카드에 쓸 대표 사진 (샘플에 올린 사진만: 메인 사진 → 갤러리 첫 장)
    $cover = '';
    foreach ($design['blocks'] as $b) {
        if (!is_array($b) || empty($b['enabled'])) continue;
        $cands = ($b['id'] ?? '') === 'hero' ? [$b['fields']['heroImage'] ?? ''] : (($b['id'] ?? '') === 'gallery' ? array_map(fn($x) => is_array($x) ? ($x['src'] ?? '') : $x, (array) ($b['fields']['images'] ?? [])) : []);
        foreach ($cands as $c) if (is_string($c) && preg_match('#^/?(?:invite/)?uploads/site/samples/img/[a-f0-9]{32}\.webp$#', $c)) { $cover = '/invite/' . preg_replace('#^/?(?:invite/)?#', '', $c); break 2; }
    }
    $idx = sample_index();
    $idx[$id] = array_merge(['label' => '', 'desc' => '', 'base' => $id, 'custom' => false], $idx[$id] ?? [], ['updated' => date('Y-m-d H:i'), 'cover' => $cover]);
    sample_index_save($idx);
    sample_json(['ok' => true, 'saved_at' => $idx[$id]['updated']]);
}
if ($a === 'upload') {
    sample_id();
    csrf_verify($_POST['csrf_token'] ?? null);
    $file = $_FILES['photo'] ?? $_FILES['sticker'] ?? null;
    if (!$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) sample_json(['ok' => false, 'error' => '파일을 받지 못했어요.'], 400);
    if ((int) $file['size'] > 15 * 1048576) sample_json(['ok' => false, 'error' => '15MB 이하만 올릴 수 있어요.'], 400);
    $mime = (string) mime_content_type((string) $file['tmp_name']);
    $src = match ($mime) { 'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']), 'image/png' => @imagecreatefrompng($file['tmp_name']), 'image/webp' => @imagecreatefromwebp($file['tmp_name']), default => false };
    if (!$src) sample_json(['ok' => false, 'error' => 'JPG·PNG·WebP 사진만 올릴 수 있어요.'], 400);
    $w = imagesx($src); $h = imagesy($src); $r = min(1, 2000 / max($w, $h));
    $nw = max(1, (int) round($w * $r)); $nh = max(1, (int) round($h * $r));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false); imagesavealpha($dst, true); // 스티커(투명 배경)도 그대로
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = sample_dir() . 'img/';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) sample_json(['ok' => false, 'error' => '저장 폴더를 만들 수 없어요.'], 500);
    $name = bin2hex(random_bytes(16)) . '.webp';
    imagewebp($dst, $dir . $name, 84);
    $url = 'uploads/site/samples/img/' . $name;
    sample_json(['ok' => true, 'url' => $url, 'id' => 0]);
}
if ($a === 'create') {
    $base = (string) ($_POST['base'] ?? '');
    if (!preg_match(SAMPLE_ID_RE, $base)) sample_json(['ok' => false, 'error' => '바탕 디자인을 골라주세요.'], 400);
    $label = sample_clean_text($_POST['label'] ?? '', 30);
    if ($label === '') sample_json(['ok' => false, 'error' => '이름을 적어주세요.'], 400);
    $idx = sample_index();
    do { $id = 's-' . bin2hex(random_bytes(3)); } while (isset($idx[$id]));
    $idx[$id] = ['label' => $label, 'desc' => sample_clean_text($_POST['desc'] ?? '', 60), 'base' => $base, 'custom' => true, 'updated' => ''];
    sample_index_save($idx);
    // 바탕 디자인을 이미 꾸며 둔 샘플이 있으면 그 상태에서 시작
    if (is_file(sample_dir() . $base . '.json')) {
        $d = json_decode((string) file_get_contents(sample_dir() . $base . '.json'), true);
        if (is_array($d)) { $d['presetId'] = $id; file_put_contents(sample_dir() . $id . '.json', json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); $idx[$id]['updated'] = date('Y-m-d H:i'); sample_index_save($idx); }
    }
    sample_json(['ok' => true, 'id' => $id]);
}
if ($a === 'meta') {
    $id = sample_id();
    $idx = sample_index();
    if (empty($idx[$id]['custom'])) sample_json(['ok' => false, 'error' => '새로 만든 샘플만 이름을 바꿀 수 있어요.'], 400);
    $label = sample_clean_text($_POST['label'] ?? '', 30);
    if ($label === '') sample_json(['ok' => false, 'error' => '이름을 적어주세요.'], 400);
    $idx[$id]['label'] = $label; $idx[$id]['desc'] = sample_clean_text($_POST['desc'] ?? '', 60);
    sample_index_save($idx);
    sample_json(['ok' => true]);
}
if ($a === 'reset') {
    $id = sample_id();
    $idx = sample_index();
    @unlink(sample_dir() . $id . '.json');
    if (!empty($idx[$id]['custom'])) unset($idx[$id]); else unset($idx[$id]);
    sample_index_save($idx);
    sample_json(['ok' => true]);
}
sample_json(['ok' => false, 'error' => '잘못된 요청입니다.'], 400);
