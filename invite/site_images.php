<?php
/**
 * site_images.php - 홈페이지(index.php)·관리자 로그인(admin_login.php)의 장식 사진
 *
 *  관리자 → 홈 이미지(admin_home_images.php)에서 칸마다 사진을 올리고, 사진을 눌러 초점(보일 부분)을 정한다.
 *  사진이 없는 칸은 원래 색 그라데이션이 그대로 나온다.
 *
 *  샘플 디자인 썸네일도 같은 방식: 칸 이름 'preset_{프리셋 id}' (홈페이지 "N가지 디자인에서 시작하세요" 카드)
 *   우선순위: 여기서 올린 사진 → invite/presets/{폴더}/thumb.jpg|png|webp → 디자인 색으로 그린 카드
 *  저장: 파일은 invite/uploads/site/ (공개 폴더, webp로 다시 만들어 저장)
 *        설정은 app_settings 'site_img_{칸}' = "파일이름|초점"  (예: "3fa9….webp|50% 30%")
 *  snap_functions.php(app_setting)가 먼저 불려 있어야 한다.
 */
declare(strict_types=1);

/** 칸 [키 => [묶음, 이름, 설명, 권장 비율(가로/세로)]] */
const SITE_IMAGE_SLOTS = [
    'home_p1'  => ['home',  '왼쪽 휴대폰 화면', '신랑신부 이름·날짜가 위에 얹혀요. 세로로 긴 사진', 0.47],
    'home_g1'  => ['home',  '오른쪽 휴대폰 갤러리 1', '작은 사진 칸 (위 왼쪽)', 1.35],
    'home_g2'  => ['home',  '오른쪽 휴대폰 갤러리 2', '작은 사진 칸 (위 오른쪽)', 1.35],
    'home_g3'  => ['home',  '오른쪽 휴대폰 갤러리 3', '작은 사진 칸 (아래 왼쪽)', 1.35],
    'home_g4'  => ['home',  '오른쪽 휴대폰 갤러리 4', '작은 사진 칸 (아래 오른쪽)', 1.35],
    'login_c1' => ['login', '로그인 카드 1 (왼쪽)', 'WEDDING 카드', 0.67],
    'login_c2' => ['login', '로그인 카드 2 (가운데)', 'INVITATION 카드', 0.67],
    'login_c3' => ['login', '로그인 카드 3 (오른쪽)', 'SAVE THE DATE 카드', 0.67],
];

function site_image_dir(): string { return UPLOAD_DIR . 'site/'; }
function site_image_url(string $file): string { return '/invite/uploads/site/' . rawurlencode($file); }

/** 칸의 사진 ['file', 'url', 'pos'] 또는 null */
function site_image(string $slot): ?array
{
    if ((!isset(SITE_IMAGE_SLOTS[$slot]) && !preg_match('/^preset_[a-z0-9-]{2,40}$/', $slot)) || !function_exists('app_setting')) return null;
    $v = app_setting('site_img_' . $slot);
    if ($v === '') return null;
    [$file, $pos] = array_pad(explode('|', $v, 2), 2, '');
    if (!preg_match('/^[a-f0-9]{32}\.webp$/', $file) || !is_file(site_image_dir() . $file)) return null;
    if (!preg_match('/^\d{1,3}% \d{1,3}%$/', $pos)) $pos = '50% 50%';
    return ['file' => $file, 'url' => site_image_url($file), 'pos' => $pos];
}

/** style="" 안에 넣을 배경 (사진이 없으면 빈 문자열 → 원래 CSS 그라데이션 그대로) */
function site_image_css(string $slot, string $overlay = ''): string
{
    $img = site_image($slot);
    if (!$img) return '';
    $u = htmlspecialchars($img['url'], ENT_QUOTES, 'UTF-8');
    return 'background:' . ($overlay !== '' ? $overlay . ',' : '') . "url('{$u}') " . $img['pos'] . '/cover no-repeat;';
}

/** 샘플 디자인(프리셋)에 올린 썸네일 ['file', 'url', 'pos'] 또는 null */
function preset_thumb_image(string $presetId): ?array
{
    return site_image('preset_' . $presetId);
}

/**
 * 프리셋 목록 (invite/presets/폴더/preset.json) - 번호 순서
 * [id => ['id','no','label','tag','folderThumb','bg','ink','accent','line']]
 */
function site_presets(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/presets/*/preset.json') ?: [] as $f) {
        if (filesize($f) > 65536) continue;
        $p = json_decode((string) file_get_contents($f), true);
        if (!is_array($p) || !preg_match('/^[a-z0-9-]{2,40}$/', (string) ($p['id'] ?? ''))) continue;
        $folder = basename(dirname($f));
        $thumb = '';
        foreach (['thumb.jpg', 'thumb.png', 'thumb.webp'] as $t) if (is_file(dirname($f) . '/' . $t)) { $thumb = '/invite/presets/' . rawurlencode($folder) . '/' . $t; break; }
        $pal = is_array($p['palette'] ?? null) ? $p['palette'] : [];
        $col = fn($k, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string) ($pal[$k] ?? '')) ? $pal[$k] : $d;
        $out[$p['id']] = [
            'id' => $p['id'], 'no' => (int) ($p['no'] ?? 999),
            'label' => mb_substr(trim((string) ($p['label'] ?? $p['name'] ?? $p['id'])), 0, 20),
            'tag' => mb_substr(implode(' · ', array_slice(array_filter((array) ($p['tags'] ?? []), fn($t) => is_string($t) && $t !== 'BETA'), 0, 2)), 0, 24),
            'folderThumb' => $thumb,
            'bg' => $col('bg', '#FAF7F0'), 'ink' => $col('ink', '#2B2320'), 'accent' => $col('accent', '#8A4B55'), 'line' => $col('line', '#E6E0D8'),
        ];
    }
    uasort($out, fn($a, $b) => [$a['no'], $a['id']] <=> [$b['no'], $b['id']]);
    return $out;
}
