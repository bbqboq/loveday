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

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ids' => featured_presets_get()], JSON_UNESCAPED_UNICODE);
}
