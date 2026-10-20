<?php
/**
 * featured_presets.php - 추천 디자인 (간편 템플릿)
 *
 *  새 청첩장을 만들 때 디자인 고르기 화면에 크게 보여 줄 디자인 몇 개.
 *  나머지 디자인은 "다른 디자인 더 보기"를 눌러야 펼쳐진다 (처음 보는 사람이 16개 앞에서 막막하지 않게).
 *  관리자 → 추천 디자인(admin_designs.php)에서 고르고 순서를 정한다. 정한 적이 없으면 아래 기본값.
 *
 *   GET 응답: {"ids":["modern","classic","p-cinema","p-photos"]}
 *  저장 위치: invite/uploads/site/featured_presets.json
 *  공개 주소 (디자인 id 목록만 내보냄, 개인정보 없음). 세션을 열지 않으려고 config.php를 부르지 않는다.
 *  관리자 화면은 이 파일을 require 해서 featured_presets_get / featured_presets_save 를 쓴다.
 */
declare(strict_types=1);

const FEATURED_DEFAULT = ['modern', 'classic', 'p-cinema', 'p-photos']; // 처음 기본값 (관리자가 정하기 전까지)
const FEATURED_MAX = 6;

function featured_presets_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/featured_presets.json';
}
function featured_presets_clean($ids): array
{
    $out = [];
    foreach ((array) $ids as $id) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{2,40}$/', $id) && !in_array($id, $out, true)) $out[] = $id;
        if (count($out) >= FEATURED_MAX) break;
    }
    return $out;
}
/** 정한 적이 없으면 기본값 (빈 목록으로 저장했으면 빈 목록 = 전부 펼쳐 보여줌) */
function featured_presets_get(): array
{
    $f = featured_presets_file();
    if (is_file($f)) {
        $j = json_decode((string) @file_get_contents($f, false, null, 0, 8192), true);
        if (is_array($j) && array_key_exists('ids', $j)) return featured_presets_clean($j['ids']);
    }
    return FEATURED_DEFAULT;
}
function featured_presets_save(array $ids): array
{
    $ids = featured_presets_clean($ids);
    $f = featured_presets_file();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    if (@file_put_contents($f, json_encode(['ids' => $ids, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('저장 파일을 쓸 수 없어요 (uploads/site 폴더 권한 확인).');
    }
    return $ids;
}

// ---- 디자인 켜고 끄기 (관리자 → 추천 디자인 · 카드마다 [고객에게 보이기] 스위치) ----
//  끈 디자인은 고객 화면(디자인 고르기 · 섹션 스킨 · 모양 고르기)에서 빠짐. 이미 그 디자인으로 만든 청첩장은 그대로 보임
//  preset_list.php가 끈 디자인에 "off": true 를 붙이고 목록 맨 위에 "off": [...] 를 내보냄 (에디터 PRESET_OFF)
//  저장 위치: invite/uploads/site/preset_off.json {"off": [디자인 id …]}
function preset_off_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/preset_off.json';
}
function preset_off_clean($ids): array
{
    $out = [];
    foreach ((array) $ids as $id) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{2,40}$/', $id) && !in_array($id, $out, true)) $out[] = $id;
        if (count($out) >= 300) break;
    }
    return $out;
}
function preset_off_get(): array
{
    $f = preset_off_file();
    if (!is_file($f)) return [];
    $j = json_decode((string) @file_get_contents($f, false, null, 0, 32768), true);
    return is_array($j) ? preset_off_clean($j['off'] ?? []) : [];
}
function preset_off_save(array $ids): array
{
    $ids = preset_off_clean($ids);
    $f = preset_off_file();
    if (!$ids) { if (is_file($f)) @unlink($f); return []; }
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    if (@file_put_contents($f, json_encode(['off' => $ids, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('저장 파일을 쓸 수 없어요 (uploads/site 폴더 권한 확인).');
    }
    return $ids;
}

// ---- 꾸밈 스타일 기본값 (관리자 → 추천 디자인 · 카드마다 [모던 | 예전]) ----
//  고객이 그 디자인을 고를 때 처음 꾸밈 스타일 (고객이 화면 설정 · 간편 색·글꼴 칸에서 바꿀 수 있음). 정하지 않은 디자인은 모던
//  preset_list.php가 "lookDefault": "old" 로 내보냄 → 에디터 presetLookDefault. 저장: invite/uploads/site/preset_look.json {"look": {디자인 id: "old"}}
function preset_look_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/preset_look.json';
}
function preset_look_clean($map): array
{
    $out = [];
    foreach ((array) $map as $id => $v) {
        if (is_string($id) && preg_match('/^[a-z0-9-]{2,40}$/', $id) && $v === 'old') $out[$id] = 'old';
        if (count($out) >= 300) break;
    }
    return $out;
}
function preset_look_get(): array
{
    $f = preset_look_file();
    if (!is_file($f)) return [];
    $j = json_decode((string) @file_get_contents($f, false, null, 0, 32768), true);
    return is_array($j) ? preset_look_clean($j['look'] ?? []) : [];
}
function preset_look_save($map): array
{
    $map = preset_look_clean($map);
    $f = preset_look_file();
    if (!$map) { if (is_file($f)) @unlink($f); return []; }
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    if (@file_put_contents($f, json_encode(['look' => $map, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('저장 파일을 쓸 수 없어요 (uploads/site 폴더 권한 확인).');
    }
    return $map;
}

// ---- 디자인 이름 바꾸기 (관리자 → 디자인 · 카드마다 [이름 바꾸기]) ----
//  기본 디자인(presets 폴더)의 이름·한 줄 설명을 관리자가 정함 - preset.json은 그대로 두고 덮어씀 (preset_list.php가 label·desc에 넣고 원래 값은 orig)
//  저장: invite/uploads/site/preset_names.json {"names": {디자인 id: {"label": "…", "desc": "…"}}} · 비우면 원래 이름
//  관리자가 새로 만든 디자인(샘플)은 sample_api.php meta로 그 샘플에 바로 저장
function preset_names_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/preset_names.json';
}
function preset_names_clean($map): array
{
    $out = [];
    $cut = function ($v, int $n): string {
        $v = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
        return mb_substr($v, 0, $n);
    };
    foreach ((array) $map as $id => $v) {
        if (!is_string($id) || !preg_match('/^[a-z0-9-]{2,40}$/', $id) || !is_array($v)) continue;
        $row = array_filter(['label' => $cut($v['label'] ?? '', 30), 'desc' => $cut($v['desc'] ?? '', 120)], fn($x) => $x !== '');
        if ($row) $out[$id] = $row;
        if (count($out) >= 300) break;
    }
    return $out;
}
function preset_names_get(): array
{
    $f = preset_names_file();
    if (!is_file($f)) return [];
    $j = json_decode((string) @file_get_contents($f, false, null, 0, 131072), true);
    return is_array($j) ? preset_names_clean($j['names'] ?? []) : [];
}
function preset_names_save($map): array
{
    $map = preset_names_clean($map);
    $f = preset_names_file();
    if (!$map) { if (is_file($f)) @unlink($f); return []; }
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    if (@file_put_contents($f, json_encode(['names' => $map, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
        throw new RuntimeException('저장 파일을 쓸 수 없어요 (uploads/site 폴더 권한 확인).');
    }
    return $map;
}
/** 디자인 하나의 이름·설명 바꾸기 (빈 값 = 원래대로) */
function preset_name_set(string $id, string $label, string $desc): array
{
    if (!preg_match('/^[a-z0-9-]{2,40}$/', $id)) throw new RuntimeException('디자인을 찾을 수 없어요.');
    $map = preset_names_get();
    $map[$id] = ['label' => $label, 'desc' => $desc];
    return preset_names_save($map);
}

// ---- 디자인 휴지통 (관리자 → 디자인 · 카드마다 [휴지통으로]) ----
//  지운 디자인은 바로 없애지 않고 30일 보관 → 그 뒤 자동으로 완전 삭제 (expire_cleanup.php 크론 + 관리자 화면·디자인 목록을 열 때)
//  보관하는 동안: 고객 디자인 고르기 화면에서 빠짐 (preset_list.php "off"+"trash", 샘플은 sample_api list에서 빠짐)
//               이미 그 디자인으로 만든 청첩장은 그대로 (색·꾸밈은 청첩장에 저장돼 있음) · 관리자 → 휴지통 '지운 디자인'에서 되살리기 / 지금 완전 삭제
//  완전 삭제 = 폴더 디자인은 invite/presets/<폴더>를 지움(서버 폴더 쓰기 권한 필요 - 안 되면 목록에 '못 지움' 표시), 관리자가 꾸민 샘플·썸네일 그림도 같이
//  저장: invite/uploads/site/design_trash.json {"items": {디자인 id: {kind: preset|sample, label, at, folder}}}
const DESIGN_TRASH_DAYS = 30;
function design_trash_file(): string { return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/design_trash.json'; }
function design_trash_get(): array
{
    $f = design_trash_file();
    if (!is_file($f)) return [];
    $j = json_decode((string) @file_get_contents($f), true);
    $out = [];
    foreach ((array) ($j['items'] ?? []) as $id => $it) if (is_string($id) && preg_match('/^[a-z0-9-]{2,40}$/', $id) && is_array($it)) $out[$id] = $it;
    return $out;
}
function design_trash_save(array $items): void
{
    $f = design_trash_file();
    if (!$items) { if (is_file($f)) @unlink($f); return; }
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    if (@file_put_contents($f, json_encode(['items' => $items], JSON_UNESCAPED_UNICODE), LOCK_EX) === false) throw new RuntimeException('저장 파일을 쓸 수 없어요 (uploads/site 폴더 권한 확인).');
}
function design_trash_ids(): array { return array_keys(design_trash_get()); }
/** 폴더 디자인 id → presets 폴더 이름 */
function design_preset_folder(string $id): ?string
{
    foreach (glob(__DIR__ . '/presets/*/preset.json') ?: [] as $jp) {
        $j = json_decode((string) @file_get_contents($jp), true);
        if (is_array($j) && ($j['id'] ?? '') === $id) return basename(dirname($jp));
    }
    return null;
}
function design_sample_dir(): string { return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/samples/'; }
function design_sample_index(): array { $f = design_sample_dir() . 'index.json'; $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; return is_array($j['items'] ?? null) ? $j['items'] : []; }
/** 휴지통으로 (kind: preset = presets 폴더 디자인 / sample = 관리자가 새로 만든 디자인) */
function design_trash_put(string $id, string $label = ''): array
{
    if (!preg_match('/^[a-z0-9-]{2,40}$/', $id)) throw new RuntimeException('디자인 이름이 올바르지 않아요.');
    $folder = design_preset_folder($id);
    $smp = design_sample_index()[$id] ?? null;
    if ($folder === null && !$smp) throw new RuntimeException('지울 디자인을 찾지 못했어요.');
    $items = design_trash_get();
    $items[$id] = ['kind' => $folder !== null ? 'preset' : 'sample', 'label' => mb_substr($label !== '' ? $label : (string) ($smp['label'] ?? $id), 0, 40), 'at' => time(), 'folder' => $folder];
    design_trash_save($items);
    $feat = featured_presets_get();
    if (in_array($id, $feat, true)) featured_presets_save(array_values(array_filter($feat, fn($x) => $x !== $id)));
    return $items[$id];
}
function design_trash_restore(string $id): void
{
    $items = design_trash_get();
    if (!isset($items[$id])) throw new RuntimeException('휴지통에 없는 디자인이에요.');
    unset($items[$id]);
    design_trash_save($items);
}
function design_rrmdir(string $dir, string $root): bool
{
    $real = realpath($dir); $rootReal = realpath($root);
    if ($real === false || $rootReal === false || strpos($real, $rootReal . DIRECTORY_SEPARATOR) !== 0) return false; // presets 폴더 안만
    $ok = true;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $ok = ($f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname())) && $ok;
    }
    return @rmdir($real) && $ok;
}
/** 완전 삭제: $only = 그 디자인만 지금 / null = 보관 기간이 지난 것 모두. 지운 수 */
function design_trash_purge(?string $only = null): int
{
    $items = design_trash_get(); if (!$items) return 0;
    $n = 0; $changed = false; $limit = time() - DESIGN_TRASH_DAYS * 86400;
    foreach ($items as $id => $it) {
        if ($only !== null ? $id !== $only : (int) ($it['at'] ?? 0) > $limit) continue;
        $ok = true;
        if (($it['kind'] ?? '') === 'preset') {
            $folder = (string) ($it['folder'] ?? '') ?: (design_preset_folder($id) ?? '');
            if ($folder !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $folder) && is_dir(__DIR__ . '/presets/' . $folder)) $ok = design_rrmdir(__DIR__ . '/presets/' . $folder, __DIR__ . '/presets');
        }
        if (!$ok) { if (empty($it['fail'])) { $items[$id]['fail'] = 1; $changed = true; } continue; }
        // 관리자가 꾸민 샘플 · 디자인 썸네일 그림
        $sd = design_sample_dir(); @unlink($sd . $id . '.json');
        $idx = design_sample_index(); if (isset($idx[$id])) { unset($idx[$id]); @file_put_contents($sd . 'index.json', json_encode(['items' => $idx], JSON_UNESCAPED_UNICODE), LOCK_EX); }
        $tf = (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/dpk/thumbs.json';
        $tj = is_file($tf) ? json_decode((string) file_get_contents($tf), true) : null;
        if (is_array($tj['items'][$id] ?? null)) {
            foreach (['c', 'l'] as $k) { $b = basename((string) ($tj['items'][$id][$k] ?? '')); if ($b !== '' && preg_match('/^[a-z0-9-]+\.(webp|jpg)$/', $b)) @unlink(dirname($tf) . '/' . $b); }
            unset($tj['items'][$id]); $tj['v'] = time(); @file_put_contents($tf, json_encode($tj, JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
        $off = preset_off_get(); if (in_array($id, $off, true)) preset_off_save(array_values(array_filter($off, fn($x) => $x !== $id)));
        $look = preset_look_get(); if (isset($look[$id])) { unset($look[$id]); preset_look_save($look); }
        unset($items[$id]); $changed = true; $n++;
    }
    if ($changed) design_trash_save($items);
    return $n;
}

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ids' => featured_presets_get()], JSON_UNESCAPED_UNICODE);
}
