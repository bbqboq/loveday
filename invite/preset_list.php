<?php
/**
 * preset_list.php - 디자인 프리셋 목록 (DB 없음, 폴더 기반)
 *
 * /invite/presets/ 아래 폴더 하나 = 프리셋 하나.
 *   presets/
 *     16-scrapbook/
 *       preset.json   (필수 - 색·글꼴·섹션 순서·효과·인트로·스티커)
 *       thumb.jpg     (선택 - 선택 화면 썸네일. thumb.jpg / thumb.png / thumb.webp 중 하나)
 *       assets/       (선택 - 스티커·장식 이미지. preset.json에서 "assets/파일명"으로 참조)
 *
 * FTP로 폴더를 올리기만 하면 에디터 디자인 선택 화면에 자동으로 나타난다.
 * preset.json 형식이 틀렸거나, 참조한 이미지가 없거나 이미지가 아니면 "그 프리셋만" 건너뛰고
 * 이유는 skipped 목록으로 알려준다(에디터 화면에는 안 보이고, 이 주소를 직접 열어보면 확인 가능).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60'); // 1분 캐시 - 폴더를 새로 올리면 늦어도 1분 뒤 반영
header('X-Content-Type-Options: nosniff');

const PRESET_DIR      = __DIR__ . '/presets';
const PRESET_URL_BASE = '/invite/presets';
const MAX_JSON_BYTES  = 65536;
const ALLOWED_BLOCKS  = ['heroVideo', 'hero', 'greeting', 'location', 'gallery', 'account', 'dday', 'timeline', 'interview',
                         'family', 'profile', 'contact', 'letter', 'video', 'transport', 'notice', 'together', 'ending', 'guestsnap', 'share', 'rsvp', 'guestbook', 'dayinfo'];
const ALLOWED_FX      = ['none', 'up', 'zoom', 'pop', 'bounce', 'left', 'right', 'fade'];
const ALLOWED_AMBIENT = ['none', 'hearts', 'petals', 'sparkles', 'cherry', 'leaves', 'autumn', 'snow', 'snowflake', 'rain', 'sunset', 'fireworks', 'popper', 'meteor', 'weather', 'confetti', 'sunshine', 'stars', 'daisy', 'bokeh', 'flash'];
const ALLOWED_PAPER   = ['beige', 'white', 'hanji', 'linen', 'kraft'];
// 고를 수 있는 값 (섹션별 모양) - blockFields 안의 이 값들이 목록에 없으면 버림
const ALLOWED_STYLE   = [
    'gallery.layoutType' => ['grid', 'tall', 'collage', 'wide', 'circle', 'slide', 'pages'],
    'dday.calendarStyle' => ['classic', 'vintage', 'minimal', 'week', 'desk', 'night', 'heart', 'planner'],
    'dday.counterStyle'  => ['classic', 'bigd', 'flip', 'ring', 'sentence', 'line', 'ticket', 'bubble'],
    'account.accStyle'   => ['basic', 'line', 'outline', 'center', 'vintage'],
    'contact.contactStyle' => ['basic', 'line', 'soft', 'vintage', 'outline', 'center', 'capsule'],
    'notice.style'       => ['card', 'box', 'slide', 'tabs'],
    'guestbook.style'    => ['card', 'line'],
    'gallery.reveal'     => ['', 'seq', 'random'], // 사진 나타나는 방식 (차례로 · 무작위로 차라락)
];
const ALLOWED_HERO    = ['video', 'photo', 'text'];
const ALLOWED_FRAMES  = ['none', 'rounded', 'circle', 'pill', 'arch'];
const ALLOWED_FONTS   = ['', 'pretendard', 'noto-serif-kr', 'gowun-batang', 'nanum-myeongjo', 'gothic-a1', 'song-myung',
                         'nanum-pen', 'nanum-brush', 'gaegu', 'hi-melody', 'gamja-flower', 'bagel-fat-one', 'black-han-sans'];
const IMAGE_EXTS      = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

final class PresetError extends Exception {}

function is_hex_color($v): bool { return is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) === 1; }
function clean_text($v, int $max = 200): string {
    $v = is_string($v) ? trim($v) : '';
    return mb_substr(strip_tags($v), 0, $max);
}

/** 프리셋 폴더 안의 이미지 파일을 안전하게 URL로 바꾼다 (폴더 밖으로 나가는 경로·이미지가 아닌 파일은 거부) */
function preset_asset_url(string $folder, string $rel): string {
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    if ($rel === '' || strpos($rel, '..') !== false || !preg_match('#^[A-Za-z0-9_./-]+$#', $rel)) {
        throw new PresetError("잘못된 파일 경로: {$rel}");
    }
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    if (!in_array($ext, IMAGE_EXTS, true)) throw new PresetError("이미지가 아닌 파일: {$rel}");
    $path = PRESET_DIR . '/' . $folder . '/' . $rel;
    if (!is_file($path)) throw new PresetError("파일이 없음: {$rel}");
    if (@getimagesize($path) === false) throw new PresetError("이미지로 읽을 수 없음: {$rel}");
    return PRESET_URL_BASE . '/' . rawurlencode($folder) . '/' . implode('/', array_map('rawurlencode', explode('/', $rel))) . '?v=' . filemtime($path);
}

function load_preset(string $folder): array {
    $jsonPath = PRESET_DIR . '/' . $folder . '/preset.json';
    if (filesize($jsonPath) > MAX_JSON_BYTES) throw new PresetError('preset.json이 너무 큼 (64KB 초과)');
    $p = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($p)) throw new PresetError('preset.json 형식 오류: ' . json_last_error_msg());

    // --- 필수값 ---
    $id = $p['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[a-z0-9-]{2,40}$/', $id)) throw new PresetError('id는 영문 소문자·숫자·하이픈 2~40자');
    $label = clean_text($p['label'] ?? '', 40);
    if ($label === '') throw new PresetError('label(이름) 없음');
    $pal = $p['palette'] ?? null;
    if (!is_array($pal)) throw new PresetError('palette 없음');
    foreach (['bg', 'ink', 'accent', 'line', 'muted'] as $k) {
        if (!is_hex_color($pal[$k] ?? null)) throw new PresetError("palette.{$k} 색상 오류 (#RRGGBB 형식)");
    }
    $sections = $p['sections'] ?? null;
    if (!is_array($sections) || !$sections) throw new PresetError('sections(섹션 순서) 없음');
    foreach ($sections as $s) {
        if (!in_array($s, ALLOWED_BLOCKS, true)) throw new PresetError("알 수 없는 섹션: " . (is_string($s) ? $s : '?'));
    }

    // --- 선택값 (틀리면 기본값으로) ---
    $out = [
        'id'       => $id,
        'no'       => (int) ($p['no'] ?? 999),
        'group'    => in_array($p['group'] ?? '', ['mood', 'layout'], true) ? $p['group'] : 'mood',
        'label'    => $label,
        'desc'     => clean_text($p['desc'] ?? '', 120),
        'tags'     => array_values(array_slice(array_map(fn($t) => clean_text($t, 12), array_filter((array) ($p['tags'] ?? []), 'is_string')), 0, 4)),
        'palette'  => array_intersect_key($pal, array_flip(['bg', 'ink', 'accent', 'line', 'muted', 'radius', 'headFont', 'bodyFont', 'headWeight'])),
        'font'     => in_array($p['font'] ?? '', ALLOWED_FONTS, true) ? (($p['font'] ?? '') ?: null) : null,
        'hero'     => in_array($p['hero'] ?? '', ALLOWED_HERO, true) ? $p['hero'] : 'photo',
        'heroFrame'=> in_array($p['heroFrame'] ?? '', ALLOWED_FRAMES, true) ? $p['heroFrame'] : 'rounded',
        'sections' => array_values($sections),
        'ambient'  => in_array($p['ambient'] ?? 'none', ALLOWED_AMBIENT, true) ? ($p['ambient'] ?? 'none') : 'none',
        'sideBg'   => is_hex_color($p['sideBg'] ?? null) ? $p['sideBg'] : null,
        'shadow'   => !empty($p['shadow']),
    ];
    if (isset($p['heroHeight']) && in_array($p['heroHeight'], ['3/4', '4/5', '9/16', 'full'], true)) $out['heroHeight'] = $p['heroHeight'];
    // 첫 화면 레이아웃 (invite-blocks.js HERO_LAYOUTS 또는 관리자가 저장한 레이아웃 id) · 종이 질감 · 장식 효과 범위·진하기
    if (!empty($p['stickerThemes']) && is_array($p['stickerThemes'])) { // 스티커 창 [추천]에 먼저 보일 테마 (에디터 STK_THEMES id)
        $st = array_values(array_intersect(array_map('strval', $p['stickerThemes']), ['romantic', 'cosmos', 'rain', 'garden', 'classic', 'party', 'season', 'webtoon']));
        if ($st) $out['stickerThemes'] = array_slice($st, 0, 2);
    }
    if (isset($p['heroLayout']) && is_string($p['heroLayout']) && preg_match('/^[a-z0-9_-]{1,40}$/', $p['heroLayout'])) $out['heroLayout'] = $p['heroLayout'];
    if (isset($p['paper']) && in_array($p['paper'], ALLOWED_PAPER, true)) $out['paper'] = $p['paper'];
    if (($p['ambientScope'] ?? '') === 'hero') $out['ambientScope'] = 'hero';
    if (isset($p['ambientOpacity'])) $out['ambientOpacity'] = max(10, min(100, (int) $p['ambientOpacity']));
    // 폰트 필드(headFont 등)는 CSS에 그대로 들어가므로 따옴표·세미콜론 같은 위험 문자 제거
    foreach (['headFont', 'bodyFont', 'headWeight', 'radius'] as $k) {
        if (isset($out['palette'][$k])) $out['palette'][$k] = preg_replace('/[^A-Za-z0-9 ",\-.%]/', '', (string) $out['palette'][$k]);
    }
    $fx = [];
    foreach ((array) ($p['fx'] ?? []) as $k => $v) {
        if (($k === 'default' || in_array($k, ALLOWED_BLOCKS, true)) && in_array($v, ALLOWED_FX, true)) $fx[$k] = $v;
    }
    $out['fx'] = $fx ?: ['default' => 'up'];
    if (isset($p['intro']) && is_array($p['intro'])) {
        $i = $p['intro'];
        $out['intro'] = array_filter([
            'enabled'  => !empty($i['enabled']),
            'text'     => clean_text($i['text'] ?? '', 60),
            'font'     => in_array($i['font'] ?? '', ALLOWED_FONTS, true) ? ($i['font'] ?? '') : '',
            'bg'       => is_hex_color($i['bg'] ?? null) ? $i['bg'] : '',
            'color'    => is_hex_color($i['color'] ?? null) ? $i['color'] : '',
            'fontSize' => max(12, min(60, (int) ($i['fontSize'] ?? 26))),
            'duration' => max(1, min(8, (float) ($i['duration'] ?? 2.5))),
        ], fn($v) => $v !== '' && $v !== null);
    }
    if (isset($p['blockFields']) && is_array($p['blockFields'])) {
        $bf = [];
        foreach ($p['blockFields'] as $blockId => $fields) {
            if (!in_array($blockId, ALLOWED_BLOCKS, true) || !is_array($fields)) continue;
            // 값은 문자열/숫자/불리언만 (객체·스크립트 같은 건 버림). 사진 개수(images)는 숫자만 허용.
            $bf[$blockId] = array_filter($fields, fn($v) => is_scalar($v));
            foreach ($bf[$blockId] as $k => $v) {
                $sk = $blockId . '.' . $k;
                if (isset(ALLOWED_STYLE[$sk]) && !in_array($v, ALLOWED_STYLE[$sk], true)) unset($bf[$blockId][$k]);
                if ($k === 'skin' && !in_array($v, ['', 'vintage', 'night', 'mist', 'webtoon'], true)) unset($bf[$blockId][$k]); // 섹션 테마
            }
        }
        $out['blockFields'] = $bf;
    }
    $stickers = [];
    foreach ((array) ($p['stickers'] ?? []) as $st) {
        if (!is_array($st) || !in_array($st['sectionId'] ?? '', ALLOWED_BLOCKS, true)) continue;
        $s = [
            'sectionId' => $st['sectionId'],
            'relX'      => max(0, min(1, (float) ($st['relX'] ?? .5))),
            'relY'      => max(0, min(1, (float) ($st['relY'] ?? .5))),
            'size'      => max(8, min(300, (int) ($st['size'] ?? 40))),
            'rotation'  => max(-180, min(180, (int) ($st['rotation'] ?? 0))),
            'effect'    => in_array($st['effect'] ?? 'none', ALLOWED_FX, true) ? ($st['effect'] ?? 'none') : 'none',
        ];
        if (!empty($st['image']) && preg_match('#^stk:([a-z]+/[a-z0-9-]{1,40})$#', (string) $st['image'], $sm)) { // 스티커 창 그림 (assets/stickers/테마/이름.svg)
            if (!is_file(__DIR__ . '/assets/stickers/' . $sm[1] . '.svg')) continue;
            $s['image'] = '/invite/assets/stickers/' . $sm[1] . '.svg';
        }
        elseif (!empty($st['image'])) $s['image'] = preset_asset_url($folder, (string) $st['image']);
        elseif (!empty($st['emoji'])) $s['emoji'] = mb_substr(strip_tags((string) $st['emoji']), 0, 4);
        else continue;
        $stickers[] = $s;
    }
    if ($stickers) $out['stickers'] = $stickers;
    // 첫 화면 예시 사진 (폴더에 hero.jpg / hero.png / hero.webp가 있으면 - 없으면 관리자 손쉬운 제작의 메인 사진 예시)
    foreach (['hero.jpg', 'hero.png', 'hero.webp'] as $t) {
        if (is_file(PRESET_DIR . '/' . $folder . '/' . $t)) { $out['heroImage'] = preset_asset_url($folder, $t); break; }
    }
    // 선택 화면 썸네일
    foreach (['thumb.jpg', 'thumb.png', 'thumb.webp'] as $t) {
        if (is_file(PRESET_DIR . '/' . $folder . '/' . $t)) { $out['thumb'] = preset_asset_url($folder, $t); break; }
    }
    if (isset($p['notes']) && is_array($p['notes'])) {
        $out['notes'] = array_values(array_map(fn($n) => clean_text($n, 200), array_filter($p['notes'], 'is_string')));
    }
    return $out;
}

$presets = [];
$skipped = [];
$seen = [];
foreach (glob(PRESET_DIR . '/*/preset.json') ?: [] as $jsonPath) {
    $folder = basename(dirname($jsonPath));
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $folder)) { $skipped[] = ['folder' => $folder, 'reason' => '폴더 이름은 영문·숫자·-·_만']; continue; }
    try {
        $p = load_preset($folder);
        if (isset($seen[$p['id']])) throw new PresetError("id 중복 ({$p['id']}) - {$seen[$p['id']]} 폴더와 겹침");
        $seen[$p['id']] = $folder;
        $presets[] = $p;
    } catch (Throwable $e) {
        $skipped[] = ['folder' => $folder, 'reason' => $e instanceof PresetError ? $e->getMessage() : '읽기 오류'];
    }
}
usort($presets, fn($a, $b) => [$a['no'], $a['id']] <=> [$b['no'], $b['id']]);

echo json_encode(['ok' => true, 'presets' => $presets, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
