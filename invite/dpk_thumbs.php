<?php
/**
 * dpk_thumbs.php - 디자인 고르기 썸네일 (관리자 → 추천 디자인 → [디자인 썸네일 만들기])
 *
 *  고객 디자인 고르기 화면은 디자인마다 청첩장을 진짜로 그리면 PC가 느려서, 관리자가 미리 찍어 둔 그림 두 장을 보여 준다.
 *   c = 첫 화면 (390×844 비율) · l = 긴 화면 (위쪽 섹션들, 가운데 휴대폰에서 천천히 흘러감)
 *  찍는 곳: 에디터 ?thumbs=make (관리자 PC 크롬·엣지에서 '이 탭 공유'로 화면을 그대로 찍어 올림 - dpkThMake)
 *  저장: uploads/site/dpk/<디자인>-<c|l>-<8자>.webp + uploads/site/dpk/thumbs.json {v, items:{id:{c,l,h,sig,at}}}
 *   sig = 찍을 때 디자인 내용(디자인·샘플·메인 사진 예시)의 지문 - 에디터가 지금 지문과 다르면 그 디자인만 예전처럼 진짜로 그림
 *  GET            → {"ok":true,"v":숫자,"items":{...}} (공개)
 *  GET ?csrf=1    → {"ok":true,"csrf":"..."} (관리자)
 *  POST (관리자) act=put  id, sig, h, c=파일, l=파일 → 저장 / act=del id → 하나 지움 / act=clear → 모두 지움
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

const DPK_TH_ID_RE = '/^[a-z0-9-]{2,40}$/';
const DPK_TH_MAX = 2 * 1048576; // 그림 한 장 2MB까지

function dpk_th_dir(): string { return UPLOAD_DIR . 'site/dpk/'; }
function dpk_th_file(): string { return dpk_th_dir() . 'thumbs.json'; }

/** 저장된 썸네일 목록 (없거나 깨졌으면 빈 목록) */
function dpk_th_get(): array
{
    $f = dpk_th_file();
    if (!is_file($f) || filesize($f) > 262144) return ['v' => 0, 'items' => []];
    $j = json_decode((string) file_get_contents($f), true);
    $items = [];
    foreach ((is_array($j) && is_array($j['items'] ?? null) ? $j['items'] : []) as $id => $t) {
        if (!is_string($id) || !preg_match(DPK_TH_ID_RE, $id) || !is_array($t)) continue;
        $c = (string) ($t['c'] ?? ''); $l = (string) ($t['l'] ?? '');
        $re = '#^/invite/uploads/site/dpk/' . preg_quote($id, '#') . '-[cl]-[a-f0-9]{8}\.(webp|jpg)$#';
        if (!preg_match($re, $c) || !preg_match($re, $l)) continue;
        $items[$id] = ['c' => $c, 'l' => $l, 'h' => max(1, min(9000, (int) ($t['h'] ?? 0))), 'sig' => preg_replace('/[^a-z0-9]/', '', (string) ($t['sig'] ?? '')), 'at' => (int) ($t['at'] ?? 0)];
    }
    return ['v' => (int) ($j['v'] ?? 0), 'items' => $items];
}

function dpk_th_save(array $items): void
{
    $dir = dpk_th_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('uploads/site/dpk 폴더를 만들 수 없어요.');
    if (!$items) { @unlink(dpk_th_file()); return; }
    $tmp = dpk_th_file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode(['v' => time(), 'items' => $items], JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !@rename($tmp, dpk_th_file())) {
        @unlink($tmp);
        throw new RuntimeException('저장하지 못했어요. uploads/site 폴더 쓰기 권한을 확인해 주세요.');
    }
}

/** 올라온 그림 한 장 검사 후 저장 → 주소 */
function dpk_th_store(string $id, string $kind, ?array $file): string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('그림이 올라오지 않았어요.');
    if ((int) $file['size'] > DPK_TH_MAX) throw new RuntimeException('그림이 너무 커요.');
    $mime = mime_content_type($file['tmp_name']);
    $ext = ['image/webp' => 'webp', 'image/jpeg' => 'jpg'][$mime] ?? '';
    $info = @getimagesize($file['tmp_name']);
    if ($ext === '' || $info === false) throw new RuntimeException('그림(webp, jpg)만 올릴 수 있어요.');
    [$w, $h] = $info;
    if ($w < 100 || $w > 1600 || $h < 100 || $h > 9000) throw new RuntimeException('그림 크기가 올바르지 않아요.');
    $name = $id . '-' . $kind . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], dpk_th_dir() . $name)) throw new RuntimeException('그림을 저장하지 못했어요.');
    @chmod(dpk_th_dir() . $name, 0644);
    return '/invite/uploads/site/dpk/' . $name;
}

function dpk_th_unlink(array $t): void
{
    foreach (['c', 'l'] as $k) if (!empty($t[$k])) @unlink(dpk_th_dir() . basename((string) $t[$k]));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Cache-Control: no-store');
        if (!empty($_GET['csrf'])) {
            define('ADMIN_GUARD_PASSIVE', true); // (로그인 안 했으면 JSON으로 알려 줌)
            require_once __DIR__ . '/admin_guard.php';
            echo json_encode(['ok' => true, 'csrf' => csrf_token()]);
            exit;
        }
        $all = dpk_th_get();
        echo json_encode(['ok' => true, 'v' => $all['v'], 'items' => $all['items'] ?: new stdClass()], JSON_UNESCAPED_SLASHES);
        exit;
    }
    require_once __DIR__ . '/admin_guard.php';
    csrf_verify($_POST['csrf_token'] ?? null);
    try {
        $all = dpk_th_get()['items'];
        $act = (string) ($_POST['act'] ?? '');
        if ($act === 'clear') {
            foreach ($all as $t) dpk_th_unlink($t);
            dpk_th_save([]);
            echo json_encode(['ok' => true]);
            exit;
        }
        $id = (string) ($_POST['id'] ?? '');
        if (!preg_match(DPK_TH_ID_RE, $id)) throw new RuntimeException('디자인 이름이 올바르지 않아요.');
        if ($act === 'del') {
            if (isset($all[$id])) { dpk_th_unlink($all[$id]); unset($all[$id]); dpk_th_save($all); }
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($act !== 'put') throw new RuntimeException('할 일이 올바르지 않아요.');
        $dir = dpk_th_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('uploads/site/dpk 폴더를 만들 수 없어요.');
        $sig = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['sig'] ?? ''));
        $h = (int) ($_POST['h'] ?? 0);
        if ($sig === '' || strlen($sig) > 16 || $h < 100 || $h > 9000) throw new RuntimeException('그림 정보가 올바르지 않아요.');
        $c = dpk_th_store($id, 'c', $_FILES['c'] ?? null);
        try { $l = dpk_th_store($id, 'l', $_FILES['l'] ?? null); }
        catch (Throwable $e) { @unlink($dir . basename($c)); throw $e; }
        $all = dpk_th_get()['items']; // (그 사이 다른 디자인이 저장됐을 수 있어서 다시 읽음)
        if (isset($all[$id])) dpk_th_unlink($all[$id]);
        $all[$id] = ['c' => $c, 'l' => $l, 'h' => $h, 'sig' => $sig, 'at' => time()];
        dpk_th_save($all);
        echo json_encode(['ok' => true, 'item' => $all[$id]], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
