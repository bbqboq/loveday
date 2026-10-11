<?php
/**
 * paper_settings.php - 종이 질감 그림 바꾸기 (관리자 → 추천 디자인 → 종이 질감 그림)
 *
 *  종이 질감 5가지(코튼지·수채화지·한지·린넨·크래프트)는 기본으로 코드가 그린 무늬(InviteBlocks.BG_PAPERS)를 쓰고,
 *  관리자가 그림을 올리면 그 그림이 대신 깔린다 (청첩장 바탕 · 섹션 스킨 종이 · 고르기 칸 견본 모두).
 *  저장: uploads/site/papers.json {"beige": "/invite/uploads/site/32자.webp", ...} (그림은 같은 폴더)
 *  GET  → {"ok":true,"papers":{...}} (공개 - 에디터가 읽음, 공개 페이지는 invite_view.php가 바로 넣음)
 *  POST (관리자) key=beige|white|hanji|linen|kraft + img=파일 → 바꿈 / remove=1 → 기본 무늬로
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

const PAPER_KEYS = ['beige', 'white', 'hanji', 'linen', 'kraft'];

function paper_settings_file(): string { return UPLOAD_DIR . 'site/papers.json'; }

/** 올린 종이 그림 [키 => 주소] (없으면 빈 배열) */
function paper_settings_get(): array
{
    $f = paper_settings_file();
    if (!is_file($f) || filesize($f) > 8192) return [];
    $j = json_decode((string) file_get_contents($f), true);
    $out = [];
    foreach (PAPER_KEYS as $k) {
        $u = is_array($j) ? (string) ($j[$k] ?? '') : '';
        if (preg_match('#^/invite/uploads/site/[a-f0-9]{32}\.webp$#', $u)) $out[$k] = $u;
    }
    return $out;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true, 'papers' => paper_settings_get() ?: new stdClass()], JSON_UNESCAPED_SLASHES);
        exit;
    }
    require_once __DIR__ . '/admin_guard.php';
    csrf_verify($_POST['csrf_token'] ?? null);
    $key = (string) ($_POST['key'] ?? '');
    try {
        if (!in_array($key, PAPER_KEYS, true)) throw new RuntimeException('종이 이름이 올바르지 않아요.');
        require_once __DIR__ . '/snap_functions.php';
        $dir = UPLOAD_DIR . 'site/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('uploads/site 폴더를 만들 수 없어요.');
        $all = paper_settings_get();
        $old = $all[$key] ?? '';
        if (!empty($_POST['remove'])) unset($all[$key]);
        else {
            $file = $_FILES['img'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('그림을 골라 주세요.');
            [$name] = snap_store_image($file, 0, $dir);
            $all[$key] = '/invite/uploads/site/' . $name;
        }
        if (file_put_contents(paper_settings_file(), json_encode($all, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('저장하지 못했어요. uploads/site 폴더 쓰기 권한을 확인해 주세요.');
        if ($old !== '' && $old !== ($all[$key] ?? '')) @unlink($dir . basename($old));
        echo json_encode(['ok' => true, 'url' => $all[$key] ?? ''], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
