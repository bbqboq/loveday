<?php
/**
 * section_defaults.php - 에디터 섹션 기본 순서
 *
 *  관리자 → 섹션 순서(admin_sections.php)에서 정한 순서를 저장·조회한다.
 *  에디터(editor-prototype-v3-overlay.html)는 이 주소를 GET으로 불러서
 *  새 청첩장을 만들거나 디자인을 고를 때 섹션을 이 순서로 놓는다.
 *   GET 응답: {"order":["heroVideo","hero","greeting", ...], "hidden":["lottery", ...]}   (정한 적이 없으면 "order":[] → 디자인 기본 순서)
 *   hidden : 관리자가 "비노출"로 끈 섹션 - 고객 에디터 섹션 목록·미리보기에서 빠지고, 실제 청첩장(invite_view.php)에서도 안 보임
 *   panelStyle : 고객 에디터 섹션 편집창 모양 - "old"(예전 세로 나열) | "A"(한 줄 정리) | "B"(탭) | "C"(타일, 기본)
 *   groups     : 섹션 묶음 [{"k":"first","n":"첫 화면","ic":"play","ids":["hero","heroVideo"]}, ...] - 섹션 목록 창 S10 그룹 섹션(·S7 묶음별)에서 씀
 *                (관리자가 정한 적 없으면 기본 묶음). groupEtc : 어느 묶음에도 안 넣은 섹션이 들어가는 묶음 이름 (기본 "기타")
 *   start      : 새 청첩장을 만들 때 섹션 시작 상태 {"guestbook":"on", "lottery":"off", ...} - on = 처음부터 넣기(적용), off = 대기 중.
 *                안 적힌 섹션은 디자인마다 정해진 대로. 첫 화면(메인 영상·메인 사진)은 디자인이 정하므로 안 받음
 *   labels     : 관리자가 바꾼 섹션 이름 {"heroVideo":"메인 영상", ...} - 바꾼 것만 (안 적힌 섹션은 원래 이름). 고객 에디터·관리자 화면에 쓰임
 *   easy       : 손쉬운 제작(간편 만들기) 단계 {"steps":[{"id":"names","on":true}, ...]} - 관리자 → 섹션 설정 → "손쉬운 제작" 탭.
 *                단계 순서와 켜기/끄기. names(두 사람)는 늘 켜짐, finish(마무리)는 늘 맨 끝. 정한 적 없으면 기본 순서·모두 켜짐
 *   prefs      : 세부 모양 {heroPicker: old|A~E, videoPicker: old|A~E, photoField: old|P1~P4, guideStyle: old|G1~G8, overToggle: old|T1|T2|T3|T4|T7|T8, nextPicker: old|N5|N6, toggleChip: old|K1~K5, feAnim: old|none|F1~F8, jumpFix: old|S1|S2|S3|S4|S6, menuStyle: old|M1~M6, menuStylePc: old|M1~M6, sheetStyle: old|S1~S10, setStyle: old|D1~D8|A3|A7|A8, setInner: old|B4|B5, s10Add: old|C4|C5|C6}
 *
 *  저장 위치: invite/uploads/site/section_defaults.json
 *   (app_settings 칸은 255자라 섹션이 늘어나면 모자라서 파일로 저장)
 *  이미 만들어진 청첩장의 섹션 순서는 바꾸지 않는다.
 *
 *  이 파일은 공개 주소지만 섹션 이름 목록만 내보낸다 (개인정보 없음).
 *  세션을 열지 않으려고 config.php를 부르지 않는다.
 */
declare(strict_types=1);

const SECTION_ID_RE = '/^[A-Za-z0-9_-]{1,40}$/';
const SECTION_FILE_MAX = 65536; // 저장 파일 최대 크기 (섹션 묶음까지 넣어도 넉넉하게)

function section_defaults_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/section_defaults.json';
}

const SECTION_PANEL_STYLES = ['old', 'A', 'B', 'C'];
const SECTION_PANEL_DEFAULT = 'C';

/** 세부 모양 (사진 칸·히어로 사진 위치·영상 위치·사용법 버튼) - 정한 적 없으면 추천값 */
const SECTION_PREF_OPTS = [
    'heroPicker'  => ['old', 'A', 'B', 'C', 'D', 'E'],
    'videoPicker' => ['old', 'A', 'B', 'C', 'D', 'E'],
    'photoField'  => ['old', 'P1', 'P2', 'P3', 'P4'],
    'guideStyle'  => ['old', 'G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'],
    'overToggle'  => ['old', 'T1', 'T2', 'T3', 'T4', 'T7', 'T8'],
    'nextPicker'  => ['old', 'N5', 'N6'],
    'toggleChip'  => ['old', 'K1', 'K2', 'K3', 'K4', 'K5'],
    'feAnim'      => ['old', 'none', 'F1', 'F2', 'F3', 'F4', 'F5', 'F6', 'F7', 'F8'],
    'jumpFix'     => ['old', 'S1', 'S2', 'S3', 'S4', 'S6'],
    'menuStyle'   => ['old', 'M1', 'M2', 'M3', 'M4', 'M5', 'M6'],
    'menuStylePc' => ['old', 'M1', 'M2', 'M3', 'M4', 'M5', 'M6'],
    'sheetStyle'  => ['old', 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10'],
    'setStyle'    => ['old', 'D1', 'D2', 'D3', 'D4', 'D5', 'D6', 'D7', 'D8', 'A3', 'A7', 'A8'],
    'setInner'    => ['old', 'B4', 'B5'],
    's10Add'      => ['old', 'C4', 'C5', 'C6'],
];
const SECTION_PREF_DEFAULTS = ['heroPicker' => 'E', 'videoPicker' => 'D', 'photoField' => 'P1', 'guideStyle' => 'G2', 'overToggle' => 'T1', 'nextPicker' => 'N5', 'toggleChip' => 'K5', 'feAnim' => 'F1', 'jumpFix' => 'S1', 'menuStyle' => 'old', 'menuStylePc' => 'old', 'sheetStyle' => 'old', 'setStyle' => 'old', 'setInner' => 'old', 's10Add' => 'old'];
function section_defaults_prefs(?array $in = null): array
{
    if ($in === null) {
        $f = section_defaults_file();
        $j = is_file($f) && filesize($f) <= SECTION_FILE_MAX ? json_decode((string) file_get_contents($f), true) : null;
        $in = is_array($j) && is_array($j['prefs'] ?? null) ? $j['prefs'] : [];
    }
    $out = [];
    foreach (SECTION_PREF_OPTS as $k => $opts) $out[$k] = in_array($in[$k] ?? '', $opts, true) ? $in[$k] : SECTION_PREF_DEFAULTS[$k];
    return $out;
}

/** 섹션 묶음 (S10 그룹 섹션) - 아이콘은 에디터에 있는 그림 이름만 */
const SECTION_GROUP_ICONS = ['play', 'photo', 'cal', 'pin', 'note', 'mail', 'chat', 'share', 'grid4', 'camera', 'people', 'heart', 'gift', 'phone', 'plane', 'wallet', 'dot'];
const SECTION_GROUP_MAX = 12;
const SECTION_GROUP_DEFAULTS = [
    ['k' => 'first', 'n' => '첫 화면', 'ic' => 'play', 'ids' => ['hero', 'heroVideo']],
    ['k' => 'info', 'n' => '예식 안내', 'ic' => 'cal', 'ids' => ['greeting', 'dday', 'dayinfo', 'calendar', 'location', 'transport', 'notice', 'account', 'contact']],
    ['k' => 'guest', 'n' => '하객 소통', 'ic' => 'mail', 'ids' => ['rsvp', 'guestbook', 'guestsnap', 'lottery', 'together']],
    ['k' => 'photo', 'n' => '사진·영상', 'ic' => 'grid4', 'ids' => ['gallery', 'video', 'timeline', 'interview', 'profile', 'family', 'trip']],
    ['k' => 'end', 'n' => '마무리', 'ic' => 'heart', 'ids' => ['ending', 'letter', 'thanks']],
];
const SECTION_GROUP_ETC_DEFAULT = '기타';

/** 묶음 목록 정리 (이상한 값은 버림, 한 섹션은 한 묶음에만). 남는 게 없으면 빈 배열 */
function section_groups_clean($in): array
{
    if (!is_array($in)) return [];
    $out = []; $keys = []; $used = [];
    foreach ($in as $g) {
        if (!is_array($g) || count($out) >= SECTION_GROUP_MAX) continue;
        $k = (string) ($g['k'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,24}$/', $k) || $k === 'etc' || isset($keys[$k])) continue;
        $n = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($g['n'] ?? ''))) ?? '');
        $n = mb_substr($n, 0, 20);
        if ($n === '') continue;
        $ic = in_array($g['ic'] ?? '', SECTION_GROUP_ICONS, true) ? $g['ic'] : 'dot';
        $ids = [];
        foreach ((is_array($g['ids'] ?? null) ? $g['ids'] : []) as $id) {
            if (is_string($id) && preg_match(SECTION_ID_RE, $id) && !isset($used[$id]) && count($ids) < 200) { $ids[] = $id; $used[$id] = 1; }
        }
        $keys[$k] = 1;
        $out[] = ['k' => $k, 'n' => $n, 'ic' => $ic, 'ids' => $ids];
    }
    return $out ? section_groups_pin_first($out) : [];
}
/**
 * 첫 화면(메인 영상·메인 사진)은 항상 맨 위 "첫 화면" 묶음에 고정 (다른 묶음에 넣었거나, 첫 화면 묶음을 지웠거나 아래로 내렸어도).
 * 묶음 순서가 곧 섹션 순서라서(S10), 그대로 두면 새 청첩장의 메인 사진·영상이 아래로 내려간다.
 */
function section_groups_pin_first(array $groups): array
{
    $hero = ['heroVideo', 'hero'];
    $first = null; $rest = [];
    foreach ($groups as $g) {
        $g['ids'] = array_values(array_filter($g['ids'], fn($id) => !in_array($id, $hero, true) && $id !== 'share')); // 공유하기는 늘 기타 맨 아래
        if ($g['k'] === 'first' && $first === null) $first = $g; else $rest[] = $g;
    }
    $first = $first ?? ['k' => 'first', 'n' => '첫 화면', 'ic' => 'play', 'ids' => []];
    $first['ids'] = array_merge($hero, $first['ids']);
    return array_merge([$first], $rest);
}
function section_groups_etc_clean($v): string
{
    $v = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)) ?? ''), 0, 20);
    return $v === '' ? SECTION_GROUP_ETC_DEFAULT : $v;
}
/** 섹션 이름 정리 {id: 이름} - 태그·줄바꿈 제거, 20자까지, 빈 이름은 버림 (= 원래 이름) */
function section_labels_clean($in): array
{
    $out = [];
    foreach ((is_array($in) ? $in : []) as $id => $n) {
        if (!is_string($id) || !preg_match(SECTION_ID_RE, $id) || !is_scalar($n)) continue;
        $n = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $n)) ?? ''), 0, 20);
        if ($n !== '') $out[$id] = $n;
        if (count($out) >= 200) break;
    }
    return $out;
}
/** 섹션 시작 상태 정리 {id: on|off} */
function section_start_clean($in): array
{
    $out = [];
    foreach ((is_array($in) ? $in : []) as $id => $v) {
        if (!is_string($id) || !preg_match(SECTION_ID_RE, $id) || in_array($id, ['heroVideo', 'hero'], true)) continue;
        if ($v === 'on' || $v === 'off') $out[$id] = $v;
        if (count($out) >= 200) break;
    }
    return $out;
}
/** 관리자가 정한 섹션 시작 상태 (없으면 빈 배열 = 모두 디자인대로) */
function section_defaults_start(): array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return [];
    $j = json_decode((string) file_get_contents($f), true);
    return section_start_clean(is_array($j) ? ($j['start'] ?? null) : null);
}
/** 관리자가 바꾼 섹션 이름 (없으면 빈 배열) */
function section_defaults_labels(): array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return [];
    $j = json_decode((string) file_get_contents($f), true);
    return section_labels_clean(is_array($j) ? ($j['labels'] ?? null) : null);
}
/** 저장된 묶음 (없으면 null) */
function section_defaults_groups_saved(): ?array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return null;
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j) || !is_array($j['groups'] ?? null)) return null;
    $g = section_groups_clean($j['groups']);
    return $g ? ['groups' => $g, 'etc' => section_groups_etc_clean($j['groupEtc'] ?? '')] : null;
}
/** 실제로 쓸 묶음 (저장한 게 없으면 기본 묶음) */
function section_defaults_groups(): array
{
    return section_defaults_groups_saved() ?? ['groups' => SECTION_GROUP_DEFAULTS, 'etc' => SECTION_GROUP_ETC_DEFAULT];
}

/** 섹션 편집창 모양 (정한 적 없으면 C 타일형) */
function section_defaults_panel_style(): string
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return SECTION_PANEL_DEFAULT;
    $j = json_decode((string) file_get_contents($f), true);
    $p = is_array($j) ? ($j['panelStyle'] ?? '') : '';
    return in_array($p, SECTION_PANEL_STYLES, true) ? $p : SECTION_PANEL_DEFAULT;
}

/** 손쉬운 제작(간편 만들기) 단계 - id => 기본 이름 (에디터 spSteps와 같은 id) */
const SECTION_EASY_STEPS = [
    'names' => '두 사람', 'hero' => '메인 화면', 'theme' => '색·글꼴', 'gallery' => '갤러리', 'venue' => '예식장', 'transport' => '교통 안내',
    'greet' => '인사말', 'family' => '혼주·연락처', 'account' => '마음 전할 곳', 'dday' => '디데이·달력', 'notice' => '안내 말씀',
    'rsvp' => '참석 여부', 'guestbook' => '방명록', 'video' => '영상', 'music' => '배경음악', 'finish' => '마무리',
];
/** 단계 정리: 아는 id만, 중복 없이, 빠진 단계는 뒤에(켜짐), names 늘 켜짐, finish 늘 맨 끝 */
function section_easy_clean($in): array
{
    $out = []; $seen = [];
    foreach ((is_array($in) ? $in : []) as $st) {
        $id = is_array($st) ? (string) ($st['id'] ?? '') : '';
        if (!isset(SECTION_EASY_STEPS[$id]) || isset($seen[$id]) || $id === 'finish') continue;
        $seen[$id] = 1;
        $out[] = ['id' => $id, 'on' => $id === 'names' ? true : !empty($st['on'])];
    }
    // 빠진 단계(새로 생긴 단계 등)는 기본 순서에서 바로 앞 단계 뒤에 켜진 채로 끼워 넣음
    $all = array_keys(SECTION_EASY_STEPS);
    foreach ($all as $i => $id) {
        if (isset($seen[$id]) || $id === 'finish') continue;
        $at = -1;
        for ($k = $i - 1; $k >= 0 && $at < 0; $k--) foreach ($out as $j => $st) if ($st['id'] === $all[$k]) { $at = $j; break; }
        array_splice($out, $at + 1, 0, [['id' => $id, 'on' => true]]);
        $seen[$id] = 1;
    }
    $out[] = ['id' => 'finish', 'on' => true];
    return $out;
}
function section_easy_default(): array { return section_easy_clean([]); }
/** 단계마다 켜고 끌 수 있는 세부 옵션 (관리자 → 손쉬운 제작 → 단계를 누르면 폴더처럼 펼쳐짐). 끈 옵션은 고객 간편 만들기에서 안 보임 */
const SECTION_EASY_OPTS = [
    'names' => ['time' => '예식 시간'],
    'hero' => ['layout' => '레이아웃 고르기', 'kind' => '사진 / 유튜브 고르기', 'crop' => '보일 부분 · 확대', 'shade' => '글자 잘 보이게 (그라데이션)',
               'scroll' => '↓ 스크롤 버튼', 'scrollSize' => '스크롤 버튼 크기', 'scrollCustom' => '스크롤 버튼 직접 꾸미기', 'scrollMotion' => '스크롤 버튼 움직임', 'scrollFx' => '스크롤 버튼 등장 효과', 'scrollOpacity' => '스크롤 버튼 진하기'],
    'theme' => ['design' => '디자인 바꾸기', 'skin' => '색 묶음', 'font' => '글꼴', 'accent' => '포인트 색', 'paper' => '종이 질감', 'reset' => '따로 바꾼 값 되돌리기'],
    'gallery' => ['type' => '갤러리 모양', 'clearEx' => '예시 사진 모두 빼기'],
    'venue' => ['detail' => '층 · 홀 이름', 'phone' => '예식장 전화', 'map' => '지도 보여주기', 'mapWide' => '지도 가로 꽉 채우기', 'mapHeight' => '지도 높이'],
    'transport' => ['title' => '제목', 'quick' => '버튼으로 칸 추가'],
    'greet' => ['tones' => '예시 인사말 고르기'],
    'family' => ['family' => '혼주 성함', 'deceased' => '고인 표시', 'contact' => '연락처'],
    'account' => ['parents' => '혼주(부모님) 계좌', 'display' => '표시 방식 · 카드 디자인', 'kakao' => '카카오페이 송금 링크'],
    'dday' => ['calendar' => '달력 보여주기', 'calStyle' => '달력 모양', 'counter' => '카운트 보여주기', 'counterStyle' => '카운터 모양', 'label' => '위쪽 작은 글씨'],
    'notice' => ['title' => '제목', 'quick' => '버튼으로 칸 추가', 'style' => '모양 (카드 / 박스)'],
    'rsvp' => ['deadline' => '마감일', 'headcount' => '참석 인원', 'meal' => '식사 여부', 'phone' => '연락처 받기', 'memo' => '전하는 말', 'popup' => '열 때 팝업'],
    'guestbook' => ['desc' => '안내 문구', 'allowWrite' => '하객 글쓰기 켜고 끄기', 'style' => '모양 (카드 / 줄글)'],
    'video' => ['title' => '영상 제목', 'fullWidth' => '가로 꽉 채우기'],
    'music' => ['library' => '기본 음악 고르기', 'upload' => '내 음악 올리기', 'autoplay' => '자동 재생', 'volume' => '음량'],
    'finish' => ['intro' => '인트로'],
];
/** 끈 세부 옵션 정리 {step: [key, ...]} */
function section_easy_off_clean($in): array
{
    $out = [];
    foreach ((is_array($in) ? $in : []) as $st => $keys) {
        if (!is_string($st) || !isset(SECTION_EASY_OPTS[$st]) || !is_array($keys)) continue;
        $k = array_values(array_unique(array_filter($keys, fn($x) => is_string($x) && isset(SECTION_EASY_OPTS[$st][$x]))));
        if ($k) $out[$st] = $k;
    }
    return $out;
}
function section_defaults_easy_off(): array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return [];
    $j = json_decode((string) file_get_contents($f), true);
    return section_easy_off_clean(is_array($j) ? ($j['easy']['off'] ?? null) : null);
}
/** 저장된 손쉬운 제작 단계 (없으면 null = 기본) */
function section_defaults_easy_saved(): ?array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return null;
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j) || !is_array($j['easy']['steps'] ?? null)) return null;
    return section_easy_clean($j['easy']['steps']);
}
function section_defaults_easy(): array { return section_defaults_easy_saved() ?? section_easy_default(); }

/** 저장된 순서 (없거나 깨졌으면 빈 배열) */
function section_defaults_get(): array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return [];
    $j = json_decode((string) file_get_contents($f), true);
    $order = is_array($j) && is_array($j['order'] ?? null) ? $j['order'] : [];
    $out = [];
    foreach ($order as $id) if (is_string($id) && preg_match(SECTION_ID_RE, $id) && !in_array($id, $out, true)) $out[] = $id;
    return array_slice($out, 0, 200);
}

/** 비노출로 끈 섹션 id 목록 */
function section_defaults_hidden(): array
{
    $f = section_defaults_file();
    if (!is_file($f) || filesize($f) > SECTION_FILE_MAX) return [];
    $j = json_decode((string) file_get_contents($f), true);
    $out = [];
    foreach ((is_array($j) && is_array($j['hidden'] ?? null) ? $j['hidden'] : []) as $id) if (is_string($id) && preg_match(SECTION_ID_RE, $id) && !in_array($id, $out, true)) $out[] = $id;
    return array_slice($out, 0, 200);
}

/** 순서·비노출·편집창 모양 저장 (전부 기본값이면 파일 삭제 = 디자인 기본 순서로). null로 넘긴 값은 저장된 값 유지 */
function section_defaults_save(array $order, ?array $hidden = null, ?string $panel = null, ?array $prefs = null, ?array $groups = null, ?array $labels = null, ?array $start = null, ?array $easy = null, ?array $easyOff = null): bool
{
    // $easyOff: null = 저장된 값 그대로, [] = 모두 켬, {step:[key]} = 새로 저장
    $easyOff = $easyOff === null ? section_defaults_easy_off() : section_easy_off_clean($easyOff);
    // $easy: null = 저장된 단계 그대로, [] = 기본으로, [[id,on], ...] = 새로 저장 (기본과 같으면 안 적음)
    $easy = $easy === null ? section_defaults_easy_saved() : ($easy ? section_easy_clean($easy) : null);
    if ($easy === section_easy_default()) $easy = null;
    // $start: null = 저장된 시작 상태 그대로, [] = 모두 디자인대로, [id => on|off] = 새로 저장
    $start = $start === null ? section_defaults_start() : section_start_clean($start);
    // $labels: null = 저장된 이름 그대로, [] = 모두 원래 이름으로, [id => 이름] = 새로 저장
    $labels = $labels === null ? section_defaults_labels() : section_labels_clean($labels);
    // $groups: null = 저장된 묶음 그대로, [] = 기본 묶음으로, ['groups'=>[...], 'etc'=>'기타'] = 새로 저장
    if ($groups === null) $groups = section_defaults_groups_saved();
    elseif ($groups) { $gl = section_groups_clean($groups['groups'] ?? []); $groups = $gl ? ['groups' => $gl, 'etc' => section_groups_etc_clean($groups['etc'] ?? '')] : null; }
    else $groups = null;
    $prefs = section_defaults_prefs($prefs === null ? null : array_merge(section_defaults_prefs(), $prefs));
    if ($panel === null || !in_array($panel, SECTION_PANEL_STYLES, true)) $panel = section_defaults_panel_style();
    $clean = [];
    foreach ($order as $id) if (is_string($id) && preg_match(SECTION_ID_RE, $id) && !in_array($id, $clean, true)) $clean[] = $id;
    if ($hidden === null) $hidden = section_defaults_hidden();
    $hid = [];
    foreach ($hidden as $id) if (is_string($id) && preg_match(SECTION_ID_RE, $id) && !in_array($id, $hid, true)) $hid[] = $id;
    $f = section_defaults_file();
    if (!$clean && !$hid && !$groups && !$labels && !$start && !$easy && !$easyOff && $panel === SECTION_PANEL_DEFAULT && $prefs === SECTION_PREF_DEFAULTS) return !is_file($f) || @unlink($f);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $data = ['order' => array_slice($clean, 0, 200), 'hidden' => array_slice($hid, 0, 200), 'panelStyle' => $panel, 'prefs' => $prefs];
    if ($groups) { $data['groups'] = $groups['groups']; $data['groupEtc'] = $groups['etc']; }
    if ($labels) $data['labels'] = $labels;
    if ($start) $data['start'] = $start;
    if ($easy || $easyOff) $data['easy'] = ['steps' => $easy ?: section_easy_default()] + ($easyOff ? ['off' => $easyOff] : []);
    $data['updated_at'] = date('c');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}

// 직접 열었을 때만 JSON 응답 (admin_sections.php가 require하면 함수만 씀)
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $grp = section_defaults_groups();
    echo json_encode(['order' => section_defaults_get(), 'hidden' => section_defaults_hidden(), 'panelStyle' => section_defaults_panel_style(), 'prefs' => section_defaults_prefs(), 'groups' => $grp['groups'], 'groupEtc' => $grp['etc'], 'labels' => section_defaults_labels() ?: new stdClass(), 'start' => section_defaults_start() ?: new stdClass(), 'easy' => ['steps' => section_defaults_easy(), 'off' => section_defaults_easy_off() ?: new stdClass()]], JSON_UNESCAPED_UNICODE);
}
