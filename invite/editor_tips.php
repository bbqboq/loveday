<?php
/**
 * editor_tips.php - 에디터 도움말 설정 (섹션 편집창의 회색 안내 상자 + 첫 방문 둘러보기)
 *
 *  관리자 → 에디터 도움말(admin_tips.php)에서 정한 값을 저장·조회한다.
 *  에디터(editor-prototype-v3-overlay.html)는 이 주소를 GET으로 불러서 그대로 따른다.
 *   GET 응답 예:
 *   {"show":true,
 *    "features":{"fold":false,"once":true,"demo":true,"tour":true},   ← 여러 개 같이 켤 수 있음
 *    "tips":{"t1abc":{"on":false},"t9xyz":{"text":"바꾼 문구","demo":"drag","media":"3fa9….gif"}},
 *    "tour":{"ver":2,"steps":[{"id":"sections","on":true,"title":"…","text":"…"}, …]},
 *    "known":["t1abc", …]}
 *
 *   show      : 도움말 전체 켜기/끄기 (정한 적이 없으면 false = 모두 숨김, 둘러보기도 안 뜸)
 *   features  : fold = "ⓘ 도움말"을 눌러야 펼쳐짐
 *               once = 처음 보는 도움말만 펼쳐서 보여주고, 한 번 본 건 ⓘ로 접어둠 (고객 브라우저마다 기억)
 *               demo = 도움말에 움직이는 시범(assets/tip-demos.js 프리셋 그림 또는 올린 GIF·영상)을 같이 보여줌
 *               guide = 섹션 편집창 위 "▶ 사용법" (섹션마다 움직이는 그림 카드 여러 장, guides)
 *               tour = 에디터에 처음 들어온 고객에게 말풍선 둘러보기 (상단 ? 버튼으로 다시 보기)
 *                      단계: 섹션 목록 → ⤢ 크게 편집 → 미리보기 → 되돌리기·다시하기 → 작업 화면 크기 → 테두리 → 저장·발행
 *   tips      : 팁마다 끄기(on:false), 바꾼 문구(text, **굵게**·줄바꿈), 시범(demo: 없으면 자동 | none | assets/tip-demos.js의 프리셋 키), 올린 파일(media),
 *               여러 섹션 공통 팁을 일부 섹션에서만 끄기(offIn: [섹션 id, …])
 *               안 적힌 팁은 켜짐 + 원래 문구
 *   tour      : 둘러보기 단계 (단계 종류는 고정, 켜기/끄기·제목·문구만 바꿈). ver가 오르면 이미 본 고객에게도 다시 뜸
 *   guides    : 섹션별 사용법 카드 [섹션 id => [{d: 프리셋 키, t: 설명, on}]] - 바꾼 섹션만, 나머지는 tip-demos.js 기본
 *   guideTex  : 섹션별 사용법 카드 바탕 무늬 [섹션 id => paper|linen|grid|dot|kraft|blush] - 에디터 "▶ 사용법" 무대에 깔림
 *   guideLayout : 관리자 화면에서 섹션을 펼쳤을 때 카드 고치는 모양 (list 기본 목록 | story | studio | board | timeline)
 *   popupTheme : 에디터 섹션 편집창 + 문구·스티커 팝업창 바탕 {bg: "#RRGGBB", tex: dot|plain|linen|grid|paper} (안 정하면 크림색 + 도트)
 *                관리자 → 섹션 순서 → "편집창 모양" 탭에서 정함 (editor_tips_set_popup_theme)
 *   notices   : 첫 방문 안내 팝업 {ver, items:[{id, on, title, text}]} - 기기에 맞는 것만 한 번씩 뜸 (show와 상관없이 기본 켜짐)
 *               mscroll = 모바일: 미리보기에서 스크롤은 화면 양 끝을 밀어서 · pcmulti = PC: 빈 곳을 끌어서 여러 개 고르기
 *               ver가 오르면 이미 본 고객에게도 다시 뜸
 *   known     : 관리자가 마지막으로 저장할 때 있던 팁 목록 (그 뒤에 생긴 팁에 "새 팁" 표시용)
 *  저장 위치: invite/uploads/site/editor_tips.json, 올린 시범 파일: invite/uploads/site/tips/
 *  세션을 열지 않으려고 config.php를 부르지 않는다.
 */
declare(strict_types=1);

const EDITOR_TIP_KEY_RE   = '/^t[0-9a-z]{1,10}$/';
const EDITOR_TIP_MEDIA_RE = '/^[a-f0-9]{24}\.(gif|webp|png|jpg|mp4|webm)$/';
const EDITOR_TIP_DEMO_RE  = '/^[a-z0-9]{2,20}$/'; // 프리셋 키 (assets/tip-demos.js) 또는 none = 시범 없음. 안 적으면 "자동"(문구를 보고 에디터가 고름)
const EDITOR_TIP_FEATURES = ['fold', 'once', 'demo', 'tour', 'guide'];
const EDITOR_GUIDE_TEXTURES = ['plain', 'paper', 'linen', 'grid', 'dot', 'kraft', 'blush']; // assets/tip-demos.js TEXTURES
const EDITOR_GUIDE_LAYOUTS  = ['list', 'story', 'studio', 'board', 'timeline'];
const EDITOR_POPUP_TEXTURES = ['dot', 'plain', 'linen', 'grid', 'paper'];                 // 에디터 팝업창 바탕 무늬 (기타 설정)          // 관리자 화면 "섹션별 사용법" 펼친 칸 모양 (기타 설정)

/** 둘러보기 단계 [id => [제목, 문구]] - 순서 고정, 관리자는 켜기/끄기·문구만 바꿈 */
const EDITOR_TOUR_STEPS = [
    'sections' => ['섹션 목록', "청첩장을 이루는 섹션들이에요.\n줄을 누르면 바로 아래에 편집창이 열리고, **⠿**를 끌면 순서가 바뀌고, 스위치로 켜고 꺼요."],
    'expand'   => ['크게 띄워서 편집', "**⤢** 버튼을 누르면 큰 창으로 편집해요.\n위아래로 보이는 카드나 **▲▼**로 다음 섹션으로 넘어가요."],
    'preview'  => ['미리보기', "하객에게 보일 화면이에요.\n글자는 **끌어서** 옮기고, **두 번 누르면** 바로 고쳐 쓸 수 있어요."],
    'history'  => ['되돌리기 · 다시하기', "실수했다면 **↺ 되돌리기**로 바로 전 상태로, **↻ 다시하기**로 다시 앞으로 가요.\nPC에서는 **Ctrl+Z / Ctrl+Y**도 돼요."],
    'view'     => ['작업 화면 크기', "미리보기 크기를 바꿔 볼 수 있어요.\n**표준**은 보통 휴대폰, **좁게**는 작은 휴대폰 화면이에요. PC에서는 **꽉채움**·**확대**로 크게 볼 수도 있어요."],
    'bezel'    => ['테두리 보이기', "**테두리**를 켜면 휴대폰 모양 테두리와 바깥 배경색이 같이 보여요.\n끄면 청첩장 화면만 보여요."],
    'save'     => ['저장과 발행', "**임시저장**은 나만 보여요.\n**발행하기**를 눌러야 하객에게 청첩장이 보여요."],
];

/** 첫 방문 안내 팝업 [id => [기기(m|pc), 제목, 문구]] - 종류는 고정, 관리자는 켜기/끄기·문구만 바꿈 */
const EDITOR_NOTICES = [
    'mscroll' => ['m', '스크롤은 화면 양 끝에서', "미리보기 가운데는 글자·사진을 **고르는 곳**이라, 손가락이 닿으면 스크롤 대신 선택될 수 있어요.\n위아래로 내릴 때는 화면 **왼쪽·오른쪽 끝**을 밀어 주세요."],
    'pcmulti' => ['pc', '끌어서 여러 개 고르기', "미리보기의 **빈 곳을 마우스로 끌면** 사각형 안의 글자가 한꺼번에 골라져요.\n고른 것 중 하나를 끌면 **같이 옮겨지고**, **Shift+클릭**으로 하나씩 넣고 빼요. **Ctrl+A**는 보이는 글자 전부, **Esc**나 빈 곳 클릭으로 풀려요."],
];

/** 예전 기본 문구 (그대로 저장돼 있으면 지금 기본 문구로 바꿔 보여줌) */
const EDITOR_NOTICES_OLD_TEXT = [
    'pcmulti' => ["미리보기의 **빈 곳을 마우스로 끌면** 사각형 안의 글자가 한꺼번에 골라져요.\n고른 것 중 하나를 끌면 **같이 옮겨지고**, **Shift+클릭**으로 하나씩 넣고 빼요. **Esc**나 빈 곳 클릭으로 풀려요."],
];

function editor_tips_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/editor_tips.json';
}
function editor_tips_media_dir(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/tips/';
}

/** 저장 형식으로 정리 (잘못된 값은 버리고, 빠진 둘러보기 단계는 기본 문구로 채움) */
function editor_tips_clean($j): array
{
    $j = is_array($j) ? $j : [];
    $str = fn($v, int $max) => mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $max);

    // 기능 (예전 형식 mode:"icon" = fold)
    $f = is_array($j['features'] ?? null) ? $j['features'] : [];
    $features = [];
    foreach (EDITOR_TIP_FEATURES as $k) $features[$k] = !empty($f[$k]);
    if (!isset($j['features']) && ($j['mode'] ?? '') === 'icon') $features['fold'] = true;

    // 팁
    $src = $j['tips'] ?? null;
    if ($src instanceof stdClass) $src = (array) $src;
    $tips = [];
    foreach ((is_array($src) ? $src : []) as $k => $v) {
        if (!is_string($k) || !preg_match(EDITOR_TIP_KEY_RE, $k)) continue;
        if ($v instanceof stdClass) $v = (array) $v;
        if (!is_array($v)) continue;
        $t = [];
        if (array_key_exists('on', $v) && !$v['on']) $t['on'] = false;
        $text = $str($v['text'] ?? '', 800);
        if ($text !== '') $t['text'] = $text;
        if (preg_match(EDITOR_TIP_DEMO_RE, (string) ($v['demo'] ?? ''))) $t['demo'] = $v['demo'];
        if (preg_match(EDITOR_TIP_MEDIA_RE, (string) ($v['media'] ?? ''))) $t['media'] = $v['media'];
        // 여러 섹션 공통 팁: 이 섹션들에서는 안 보이게 (섹션 id 목록)
        $off = [];
        foreach ((is_array($v['offIn'] ?? null) ? $v['offIn'] : []) as $sec) {
            if (is_string($sec) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $sec)) $off[$sec] = true;
            if (count($off) >= 80) break;
        }
        if ($off) $t['offIn'] = array_keys($off);
        if ($t) $tips[$k] = $t;
        if (count($tips) >= 600) break;
    }

    // 둘러보기
    $tin = is_array($j['tour'] ?? null) ? $j['tour'] : [];
    $given = [];
    foreach ((is_array($tin['steps'] ?? null) ? $tin['steps'] : []) as $s) {
        if (is_array($s) && isset(EDITOR_TOUR_STEPS[$s['id'] ?? ''])) $given[$s['id']] = $s;
    }
    $steps = [];
    foreach (EDITOR_TOUR_STEPS as $id => [$dt, $dx]) {
        $s = $given[$id] ?? [];
        $title = $str($s['title'] ?? '', 40);
        $text = $str($s['text'] ?? '', 400);
        $steps[] = ['id' => $id, 'on' => !array_key_exists('on', $s) || !empty($s['on']), 'title' => $title !== '' ? $title : $dt, 'text' => $text !== '' ? $text : $dx,
                    'defTitle' => $dt, 'defText' => $dx];
    }

    // 섹션별 사용법 (관리자가 바꾼 섹션만 저장, 나머지는 assets/tip-demos.js 기본값) [섹션 => [{d, t, on}, …]]
    $guides = [];
    $gsrc = $j['guides'] ?? null;
    if ($gsrc instanceof stdClass) $gsrc = (array) $gsrc;
    foreach ((is_array($gsrc) ? $gsrc : []) as $sec => $list) {
        if (!is_string($sec) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $sec) || !is_array($list)) continue;
        $out = [];
        foreach ($list as $it) {
            if ($it instanceof stdClass) $it = (array) $it;
            if (!is_array($it) || !preg_match(EDITOR_TIP_DEMO_RE, (string) ($it['d'] ?? ''))) continue;
            $out[] = ['d' => $it['d'], 't' => $str($it['t'] ?? '', 120), 'on' => !array_key_exists('on', $it) || !empty($it['on'])];
            if (count($out) >= 12) break;
        }
        $guides[$sec] = $out;
        if (count($guides) >= 80) break;
    }

    // 사용법 카드 바탕 무늬 [섹션 => 무늬 키] (없음은 저장 안 함) · 관리자 화면 펼친 칸 모양
    $guideTex = [];
    $tsrc = $j['guideTex'] ?? null;
    if ($tsrc instanceof stdClass) $tsrc = (array) $tsrc;
    foreach ((is_array($tsrc) ? $tsrc : []) as $sec => $tx) {
        if (is_string($sec) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $sec) && in_array($tx, EDITOR_GUIDE_TEXTURES, true) && $tx !== 'plain') $guideTex[$sec] = $tx;
        if (count($guideTex) >= 80) break;
    }
    $guideLayout = in_array($j['guideLayout'] ?? '', EDITOR_GUIDE_LAYOUTS, true) ? $j['guideLayout'] : 'list';
    // 에디터 팝업창 바탕 (색 + 무늬). 기본값(#FBF8F2 + 도트)이면 저장 안 함
    $pt = $j['popupTheme'] ?? null;
    if ($pt instanceof stdClass) $pt = (array) $pt;
    $popupTheme = null;
    if (is_array($pt)) {
        $bg = strtoupper((string) ($pt['bg'] ?? ''));
        $tex = (string) ($pt['tex'] ?? '');
        if (!preg_match('/^#[0-9A-F]{6}$/', $bg)) $bg = '#FBF8F2';
        if (!in_array($tex, EDITOR_POPUP_TEXTURES, true)) $tex = 'dot';
        if ($bg !== '#FBF8F2' || $tex !== 'dot') $popupTheme = ['bg' => $bg, 'tex' => $tex];
    }

    // 첫 방문 안내 팝업
    $nin = $j['notices'] ?? null;
    if ($nin instanceof stdClass) $nin = (array) $nin;
    $nin = is_array($nin) ? $nin : [];
    $ngiven = [];
    foreach ((is_array($nin['items'] ?? null) ? $nin['items'] : []) as $it) {
        if ($it instanceof stdClass) $it = (array) $it;
        if (is_array($it) && isset(EDITOR_NOTICES[$it['id'] ?? ''])) $ngiven[$it['id']] = $it;
    }
    $nitems = [];
    foreach (EDITOR_NOTICES as $id => [$dev, $dt, $dx]) {
        $it = $ngiven[$id] ?? [];
        $title = $str($it['title'] ?? '', 40);
        $text = $str($it['text'] ?? '', 400);
        if (in_array($text, EDITOR_NOTICES_OLD_TEXT[$id] ?? [], true)) $text = '';
        $nitems[] = ['id' => $id, 'dev' => $dev, 'on' => !array_key_exists('on', $it) || !empty($it['on']), 'title' => $title !== '' ? $title : $dt, 'text' => $text !== '' ? $text : $dx,
                     'defTitle' => $dt, 'defText' => $dx];
    }

    $known = [];
    foreach ((is_array($j['known'] ?? null) ? $j['known'] : []) as $k) {
        if (is_string($k) && preg_match(EDITOR_TIP_KEY_RE, $k)) $known[$k] = true;
        if (count($known) >= 800) break;
    }
    return [
        'show' => !empty($j['show']),
        'features' => $features,
        'tips' => $tips ?: new stdClass(),
        'guides' => $guides ?: new stdClass(),
        'guideTex' => $guideTex ?: new stdClass(),
        'guideLayout' => $guideLayout,
        'popupTheme' => $popupTheme,
        'tour' => ['ver' => max(1, min(100000, (int) ($tin['ver'] ?? 1))), 'steps' => $steps],
        'notices' => ['ver' => max(1, min(100000, (int) ($nin['ver'] ?? 1))), 'items' => $nitems],
        'custom' => editor_tips_clean_custom($j['custom'] ?? null),
        'known' => array_keys($known),
    ];
}

/**
 * 커스텀 편집팁 (관리자 → 커스텀 편집팁 admin_tip_tours.php)
 *  화면이 어두워지고 눌러야 할 곳만 밝게 + 손가락 아이콘 + "여기를 눌러주세요" + 설명 창, [다음]으로 다음 자리
 *  custom = {tours: [{id, name, on, device: all|pc|m, trigger: first(처음 들어온 고객에게 한 번)|manual(? 도움말에서만), ver,
 *            steps: [{id, dev: all|pc|m, sel: 가리킬 곳(CSS 선택자), pre: 먼저 눌러 둘 곳(선택), title, text, next: btn(다음 버튼)|click(그곳을 누르면 다음)}]}]}
 */
function editor_tips_clean_custom($in): array
{
    if ($in instanceof stdClass) $in = (array) $in;
    $in = is_array($in) ? $in : [];
    $str = fn($v, int $max) => mb_substr(trim(str_replace("\r", '', (string) $v)), 0, $max);
    $sel = fn($v) => mb_substr(preg_replace('/[\x00-\x1F<>{};]/u', '', trim((string) $v)), 0, 300);
    $dev = fn($v) => in_array($v, ['all', 'pc', 'm'], true) ? $v : 'all';
    $tours = []; $ids = [];
    foreach ((is_array($in['tours'] ?? null) ? $in['tours'] : []) as $t) {
        if ($t instanceof stdClass) $t = (array) $t;
        if (!is_array($t) || count($tours) >= 20) continue;
        $id = preg_match('/^c[a-z0-9]{2,16}$/', (string) ($t['id'] ?? '')) ? $t['id'] : 'c' . substr(md5(uniqid('', true)), 0, 8);
        if (isset($ids[$id])) continue; $ids[$id] = 1;
        $steps = [];
        foreach ((is_array($t['steps'] ?? null) ? $t['steps'] : []) as $st) {
            if ($st instanceof stdClass) $st = (array) $st;
            if (!is_array($st) || count($steps) >= 30) continue;
            $steps[] = ['id' => preg_match('/^s[a-z0-9]{2,16}$/', (string) ($st['id'] ?? '')) ? $st['id'] : 's' . substr(md5(uniqid('', true)), 0, 8),
                'dev' => $dev($st['dev'] ?? 'all'), 'sel' => $sel($st['sel'] ?? ''), 'pre' => $sel($st['pre'] ?? ''),
                'title' => $str($st['title'] ?? '', 40), 'text' => $str($st['text'] ?? '', 400), 'next' => ($st['next'] ?? '') === 'click' ? 'click' : 'btn'];
        }
        $tours[] = ['id' => $id, 'name' => $str($t['name'] ?? '', 30) ?: '편집팁', 'on' => !empty($t['on']), 'device' => $dev($t['device'] ?? 'all'),
            'trigger' => ($t['trigger'] ?? '') === 'first' ? 'first' : 'manual', 'ver' => max(1, min(100000, (int) ($t['ver'] ?? 1))), 'steps' => $steps];
    }
    return ['tours' => $tours];
}
/** 커스텀 편집팁만 저장 (나머지 도움말 설정은 그대로) */
function editor_tips_set_custom(array $custom): bool
{
    $f = editor_tips_file();
    $raw = is_file($f) && filesize($f) <= 400000 ? json_decode((string) file_get_contents($f), true) : [];
    if (!is_array($raw)) $raw = [];
    $raw['custom'] = editor_tips_clean_custom($custom);
    return editor_tips_save($raw);
}

/** 편집창·팝업창 바탕만 바꿔서 저장 (관리자 → 섹션 순서 → 편집창 모양). 나머지 도움말 설정은 그대로 */
function editor_tips_set_popup_theme(string $bg, string $tex): bool
{
    $f = editor_tips_file();
    $raw = is_file($f) && filesize($f) <= 400000 ? json_decode((string) file_get_contents($f), true) : [];
    if (!is_array($raw)) $raw = [];
    $raw['popupTheme'] = ['bg' => strtoupper($bg), 'tex' => $tex];
    return editor_tips_save($raw);
}

function editor_tips_get(): array
{
    $f = editor_tips_file();
    if (!is_file($f) || filesize($f) > 400000) return editor_tips_clean([]);
    return editor_tips_clean(json_decode((string) file_get_contents($f), true));
}

function editor_tips_save(array $cfg): bool
{
    $f = editor_tips_file();
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $data = editor_tips_clean($cfg);
    foreach ($data['tour']['steps'] as &$s) unset($s['defTitle'], $s['defText']); // 기본 문구는 저장하지 않음 (코드에서 채움)
    unset($s);
    foreach ($data['notices']['items'] as &$s) { // 기본 문구 그대로면 저장 안 함 → 나중에 기본 문구를 고치면 그대로 따라감
        if ($s['title'] === $s['defTitle']) unset($s['title']);
        if ($s['text'] === $s['defText']) unset($s['text']);
        unset($s['defTitle'], $s['defText'], $s['dev']);
    }
    unset($s);
    $data['updated_at'] = date('c');
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    if (!@rename($tmp, $f)) return false;
    editor_tips_media_cleanup($data);
    return true;
}

/** 어디에도 안 쓰이는 시범 파일 정리 (방금 올리고 아직 저장 전일 수 있으니 1시간 지난 것만) */
function editor_tips_media_cleanup(array $data): void
{
    $used = [];
    foreach ((array) $data['tips'] as $t) if (!empty($t['media'])) $used[$t['media']] = true;
    foreach (glob(editor_tips_media_dir() . '*') ?: [] as $p) {
        $b = basename($p);
        if (preg_match(EDITOR_TIP_MEDIA_RE, $b) && !isset($used[$b]) && filemtime($p) < time() - 3600) @unlink($p);
    }
}

/**
 * 시범 파일 저장 (GIF·WEBP·PNG·JPG 이미지 또는 MP4·WEBM 영상, 최대 6MB) → 파일 이름 또는 오류 문구
 * @return array{0:?string,1:string}
 */
function editor_tips_store_media(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) return [null, '파일을 받지 못했어요.'];
    if ((int) $file['size'] > 6 * 1024 * 1024) return [null, '6MB 이하 파일만 올릴 수 있어요. (짧은 GIF나 3~5초 영상을 권장해요)'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $ext = ['image/gif' => 'gif', 'image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'video/mp4' => 'mp4', 'video/webm' => 'webm'][$mime] ?? null;
    if (!$ext) return [null, 'GIF·WEBP·PNG·JPG 이미지나 MP4·WEBM 영상만 올릴 수 있어요.'];
    $dir = editor_tips_media_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [null, '저장 폴더를 만들지 못했어요. uploads/site 폴더 쓰기 권한을 확인해주세요.'];
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@move_uploaded_file((string) $file['tmp_name'], $dir . $name)) return [null, '파일을 저장하지 못했어요.'];
    @chmod($dir . $name, 0644);
    return [$name, ''];
}

// 직접 열었을 때만 JSON 응답 (admin_tips.php가 require하면 함수만 씀)
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $out = editor_tips_get();
    unset($out['known']);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}
