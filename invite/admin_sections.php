<?php
/**
 * admin_sections.php - 관리자: 섹션 순서 (에디터 섹션 기본 위치)
 *
 *  - 고객이 에디터에서 새 청첩장을 만들거나 디자인을 고르면 섹션이 여기서 정한 순서로 놓인다.
 *  - 섹션 목록은 assets/invite-blocks.js(InviteBlocks.sectionCatalog())에서 자동으로 읽는다.
 *    → 앞으로 섹션이 새로 추가되면 이 화면에 "새 섹션" 표시와 함께 자동으로 나타난다.
 *      (저장 전까지는 개발할 때 넣은 자리 = 바로 앞 섹션 뒤에 놓임. 원하는 자리로 옮기고 저장하면 고정)
 *  - 이미 만들어진 청첩장의 순서는 바뀌지 않는다 (고객이 직접 옮긴 순서 유지).
 *  - "디자인 기본 순서로"를 누르면 저장한 순서를 지우고 디자인(프리셋)마다 정해진 순서를 쓴다.
 *  저장: invite/uploads/site/section_defaults.json (section_defaults.php)
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장할 수 있다 (없으면 보기 전용)
 *  탭 "손쉬운 제작": 간편 만들기 단계의 켜기/끄기와 순서 (section_defaults.json easy)
 *  탭 "편집창 모양": 고객 에디터에서 섹션을 눌렀을 때 뜨는 편집창 모양 (예전 / A 한 줄 정리 / B 탭 / C 타일) - 고르면 바로 저장
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/section_defaults.php';
require_once __DIR__ . '/editor_tips.php'; // 편집창·팝업창 바탕 (popupTheme)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    $act = (string) ($_POST['act'] ?? '');
    if ($act === 'reset') {
        $ok = section_defaults_save([]); // 순서만 지움 (비노출·편집창 모양은 그대로)
    } elseif ($act === 'theme') {
        // 편집창·팝업창 바탕 (색 + 무늬) - editor_tips.json의 popupTheme
        $bg = strtoupper((string) ($_POST['bg'] ?? ''));
        $tex = (string) ($_POST['tex'] ?? '');
        if (!preg_match('/^#[0-9A-F]{6}$/', $bg) || !in_array($tex, EDITOR_POPUP_TEXTURES, true)) {
            echo json_encode(['ok' => false, 'error' => '바탕 값이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok = editor_tips_set_popup_theme($bg, $tex);
    } elseif ($act === 'pref') {
        $k = (string) ($_POST['key'] ?? ''); $v = (string) ($_POST['val'] ?? '');
        if (!isset(SECTION_PREF_OPTS[$k]) || !in_array($v, SECTION_PREF_OPTS[$k], true)) {
            echo json_encode(['ok' => false, 'error' => '세부 모양 값이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok = section_defaults_save(section_defaults_get(), null, null, [$k => $v]);
    } elseif ($act === 'panel') {
        $ps = (string) ($_POST['panel'] ?? '');
        if (!in_array($ps, SECTION_PANEL_STYLES, true)) {
            echo json_encode(['ok' => false, 'error' => '편집창 모양 값이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok = section_defaults_save(section_defaults_get(), null, $ps);
    } elseif ($act === 'groups') {
        // 섹션 묶음 (S10 그룹 섹션) - JSON으로 받음
        $gj = json_decode((string) ($_POST['groups'] ?? ''), true);
        $gl = section_groups_clean($gj);
        if (!is_array($gj) || !$gl) {
            echo json_encode(['ok' => false, 'error' => '묶음이 하나 이상 있어야 해요. (이름이 빈 묶음은 저장되지 않아요)'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ok = section_defaults_save(section_defaults_get(), null, null, null, ['groups' => $gl, 'etc' => (string) ($_POST['etc'] ?? '')]);
    } elseif ($act === 'labels') {
        // 섹션 이름만 바로 저장 (순서·비노출은 그대로)
        $lj = json_decode((string) ($_POST['labels'] ?? ''), true);
        $ok = is_array($lj) && section_defaults_save(section_defaults_get(), null, null, null, null, $lj);
    } elseif ($act === 'easy') {
        // 손쉬운 제작(간편 만들기) 단계 순서·켜기 - JSON [{id,on}, ...]
        $ej = json_decode((string) ($_POST['steps'] ?? ''), true);
        if (!is_array($ej) || !$ej) {
            echo json_encode(['ok' => false, 'error' => '단계 목록이 올바르지 않아요. 새로고침 후 다시 해주세요.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $oj = isset($_POST['off']) ? json_decode((string) $_POST['off'], true) : null; // 단계별로 끈 세부 옵션
        $ok = section_defaults_save(section_defaults_get(), null, null, null, null, null, null, $ej, is_array($oj) ? $oj : null);
    } elseif ($act === 'easy_reset') {
        $ok = section_defaults_save(section_defaults_get(), null, null, null, null, null, null, [], []);
    } elseif ($act === 'groups_reset') {
        $ok = section_defaults_save(section_defaults_get(), null, null, null, []);
    } elseif ($act === 'save') {
        $ids = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['order'] ?? ''))), fn($v) => $v !== ''));
        $hid = array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['hidden'] ?? ''))), fn($v) => $v !== ''));
        $bad = array_filter(array_merge($ids, $hid), fn($id) => !preg_match(SECTION_ID_RE, $id));
        if (!$ids || $bad || count($ids) > 200 || count($ids) !== count(array_unique($ids))) {
            echo json_encode(['ok' => false, 'error' => '섹션 목록이 올바르지 않아요. 새로고침 후 다시 해주세요.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 섹션 이름 (바꾼 것만 JSON으로) - 안 보내면 저장된 이름 그대로
        $lj = isset($_POST['labels']) ? json_decode((string) $_POST['labels'], true) : null;
        // 새 청첩장 시작 상태 (on 적용 / off 대기, 바꾼 것만 JSON) - 안 보내면 저장된 값 그대로
        $sj = isset($_POST['start']) ? json_decode((string) $_POST['start'], true) : null;
        $ok = section_defaults_save($ids, array_values(array_intersect($hid, $ids)), null, null, null, is_array($lj) ? $lj : null, is_array($sj) ? $sj : null);
    } else {
        $ok = false;
    }
    echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => '저장하지 못했어요. uploads/site 폴더 쓰기 권한을 확인해주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$saved = section_defaults_get();
$savedHidden = section_defaults_hidden();
$panelStyle = section_defaults_panel_style();
$prefs = section_defaults_prefs();
$groupsNow = section_defaults_groups();
$savedLabels = section_defaults_labels();
$savedStart = section_defaults_start();
$groupsCustom = section_defaults_groups_saved() !== null;
$easyNow = section_defaults_easy();
$easyOff = section_defaults_easy_off();
$easyCustom = section_defaults_easy_saved() !== null || $easyOff;
// 손쉬운 제작 단계마다: [아이콘, 고객이 그 단계에서 하는 일, 연결된 섹션]
$easyInfo = [
    'names' => ['♥', '신랑·신부 이름, 예식 날짜·시간', '메인 화면·디데이 (늘 켜짐)'],
    'hero' => ['▣', '사진 / 유튜브 고르기 → 사진 올리기(또는 영상 주소) → 화면 크기(프레임·가로 꽉·전체화면)', '메인 사진 · 메인 영상'],
    'theme' => ['◐', '청첩장 색·제목 글꼴 묶음(테마) 고르기', ''],
    'gallery' => ['▦', '갤러리 사진 여러 장 올리기, 예시 사진 빼기', '갤러리'],
    'venue' => ['⌖', '예식장 이름, 주소 찾기, 층·홀, 전화', '오시는 길'],
    'transport' => ['⇄', '지하철·버스·자가용·주차 안내 (버튼 하나로 칸 추가)', '교통수단'],
    'greet' => ['✎', '말투 고르기 → 예시 인사말 고르고 고치기', '인사말'],
    'family' => ['☺', '혼주 성함, 연락처', '혼주 소개 · 연락하기'],
    'account' => ['₩', '신랑·신부(·혼주) 은행, 예금주, 계좌번호', '마음 전하실 곳'],
    'dday' => ['◷', '달력 보여주기 / 카운트 보여주기 켜고 끄기', '디데이 카운트다운'],
    'notice' => ['ⓘ', '식사·주차 같은 안내 제목과 내용', '안내문'],
    'rsvp' => ['✉', '마감일, 받을 항목(인원·식사), 열 때 팝업', '참석 여부'],
    'guestbook' => ['❝', '안내 문구, 하객 글쓰기 허용', '방명록'],
    'video' => ['▶', '식전 영상 유튜브 주소', '영상'],
    'music' => ['♪', '기본 제공 음악에서 미리 듣고 고르기, 켜고 끄기', '배경음악'],
    'finish' => ['✓', '인트로 켜기, 저장·발행 (늘 맨 끝)', ''],
];
$prefRows = [
    'heroPicker'  => ['메인 사진 칸', [['old', '예전 (크게)'], ['A', 'A 썸네일 한 줄'], ['B', 'B 자르기 창'], ['C', 'C 미리보기에서 끌기'], ['D', 'D 9칸 + 썸네일'], ['E', 'E 타일 + 자르기 창 ★']], '대표 사진과 "잘릴 때 보일 부분" 정하기. E는 C 타일형 편집창에서만 (아니면 B)'],
    'videoPicker' => ['영상 위치', [['old', '예전 (크게)'], ['A', 'A 썸네일 한 줄'], ['B', 'B 작은 자르기 창'], ['C', 'C 3칸 버튼'], ['D', 'D 타일 + 자르기 창 ★'], ['E', 'E 미리보기에서 끌기']], '메인 영상에서 화면에 보일 부분. D는 C 타일형에서만 (아니면 B)'],
    'photoField'  => ['섹션 사진 칸', [['old', '예전 (크게)'], ['P1', 'P1 썸네일 칩 ★'], ['P2', 'P2 가로 카드'], ['P3', 'P3 끌어 놓기 칸'], ['P4', 'P4 타일']], '디데이·타임라인·인터뷰·프로필·손편지·안내문·엔딩 등의 사진 칸. P4는 C 타일형에서만 (아니면 P1)'],
    'overToggle'  => ['글자 사진·영상 위로 스위치', [['old', '예전 (긴 문장)'], ['T1', 'T1 타일로 합치기 ★'], ['T2', 'T2 두 칸 고르기'], ['T3', 'T3 설명 스위치 카드'], ['T4', 'T4 그림 카드 두 개'], ['T7', 'T7 움직이는 그림'], ['T8', 'T8 대표 사진 칸 안']], '메인 사진 "글자를 사진 위로" · 메인 영상 "글자를 영상 위로". T1은 C 타일형에서만 (아니면 T3), T8은 사진 칸이 예전 모양이면 T3'],
    'nextPicker'  => ['"스크롤 ↓" 버튼', [['old', '예전 (긴 문장)'], ['N5', 'N5 모양 칩 한 줄 ★'], ['N6', 'N6 타일']], '전체화면 + 글자를 사진/영상 위로 켰을 때만 넣을 수 있어요. N5는 없음·유리·어둡게·밝게·포인트·선·직접 중 하나를 누르면 바로 켜짐. N6은 C 타일형에서만 (아니면 N5)'],
    'jumpFix'     => ['옵션 바뀔 때 화면', [['S1', 'S1 누른 자리 고정 ★'], ['S2', 'S2 회색 비활성'], ['S3', 'S3 바뀌는 칸은 아래로'], ['S4', 'S4 아래 시트로 열기'], ['S6', 'S6 S1+S2 같이'], ['old', '예전 (칸이 튐)']], '옵션을 눌러 위쪽 칸이 생기거나 사라질 때 화면이 위아래로 튀지 않게. S2는 사라질 칸(글자를 사진 위로 등)을 회색으로 잠가 남겨 둬요. S4는 휴대폰에서 옵션 창이 아래에서 올라와요'],
    'toggleChip'  => ['켜고 끄는 버튼', [['old', '예전 (어두운 스위치 알약)'], ['K1', 'K1 타일로 합치기'], ['K2', 'K2 설명 스위치 줄'], ['K3', 'K3 체크 알약'], ['K4', 'K4 끔|켬 두 칸'], ['K5', 'K5 아이콘 칩 ★']], '"카카오맵 표시"·"지도 가로 100%"처럼 누르면 켜지고 꺼지는 버튼. K1은 C 타일형에서만 (아니면 K3)'],
    'feAnim'      => ['칸 나타남·사라짐 효과', [['F1', 'F1 담백 페이드 ★'], ['F2', 'F2 노란 하이라이트'], ['F3', 'F3 테두리 퍼짐'], ['F4', 'F4 살짝 내려오기'], ['F5', 'F5 위에서 펼치기'], ['F6', 'F6 빛 지나가기'], ['F7', 'F7 NEW 배지'], ['F8', 'F8 깜빡 두 번'], ['none', '효과 없음'], ['old', '예전 (출렁임)']], '옵션을 켜고 끌 때 새로 생기는 칸·사라지는 칸에 주는 효과. 미리보기에서 칸이 생겼다 사라졌다 반복돼요'],
    'menuStyle'   => ['휴대폰 에디터 메뉴', [['old', '예전 (아이콘 줄)'], ['M1', 'M1 아이콘+글자 한 줄'], ['M2', 'M2 아래 도구 막대'], ['M3', 'M3 ＋ 버튼 하나'], ['M4', 'M4 탭 + 도구 칩'], ['M5', 'M5 큰 글자 버튼 2개'], ['M6', 'M6 글자 버튼 한 줄']], '휴대폰에서 에디터 위쪽(또는 아래쪽) 메뉴 모양. 스티커·글자·인트로·도구(여백 자·격자·끌기·여러 개 고르기)·화면 크기를 어떻게 보여줄지. PC 화면은 그대로예요. 되돌리기 버튼은 고객이 "화면에 떠 있게 / 위쪽 메뉴에" 직접 고를 수 있어요'],
    'menuStylePc' => ['PC 에디터 메뉴', [['old', '예전 (아이콘 줄)'], ['M1', 'M1 아이콘+글자 한 줄'], ['M2', 'M2 아래 떠 있는 도구 막대'], ['M3', 'M3 ＋ 버튼 하나'], ['M4', 'M4 탭 + 도구 칩'], ['M5', 'M5 큰 글자 버튼 2개'], ['M6', 'M6 글자 버튼 한 줄']], 'PC 화면(폭 861px 이상)의 에디터 위쪽 메뉴 모양. 휴대폰과 따로 골라요. 화면 크기(표준·좁게·꽉채움·확대·테두리) 버튼은 그대로 위 줄에 있고, M4만 "화면" 탭으로 들어가요'],
    'sheetStyle'  => ['섹션 순서 & 폭 창', [['old', '예전 (줄 목록)'], ['S1', 'S1 아이콘 줄 + 폭 버튼'], ['S2', 'S2 켜진 섹션 + 아래 추가 칸'], ['S3', 'S3 2칸 카드'], ['S4', 'S4 미리보기 그림'], ['S5', 'S5 탭 (순서·켜기·폭)'], ['S6', 'S6 번호 타임라인'], ['S7', 'S7 묶음별 (▲▼ 이동)'], ['S8', 'S8 검색 + 칩'], ['S9', 'S9 타임라인 + 아래 대기 섹션'], ['S10', 'S10 그룹 섹션']], '섹션 목록(순서·켜기·폭) 창 모양. 휴대폰·PC 같이 바뀌어요. S9는 S6처럼 번호 타임라인으로 켜진 섹션을 보여주고, 꺼진 섹션은 S2처럼 아래 "추가할 섹션" 칸에 모아 눌러서 켜요. S10은 S9에 묶음(첫 화면·예식 안내…)을 더한 모양 - 묶음은 "섹션 묶음" 탭에서 정해요'],
    's10Add'      => ['S10 섹션 넣기 모양', [['old', '예전 (초록 상자)'], ['C4', 'C4 아래 고정 서랍'], ['C5', 'C5 ＋ 옆 말풍선'], ['C6', 'C6 합본 (PC 말풍선 · 휴대폰 서랍) ★']], 'S10 그룹 섹션에서 묶음의 ＋를 눌렀을 때. C4는 대기 중인 섹션이 늘 창 아래 서랍에 있고, ＋를 누르면 서랍이 그 묶음에 넣는 모드로 바뀌어요. C5는 ＋ 바로 옆에 작은 아이콘판이 떠요. C6은 PC에선 C5, 휴대폰에선 C4로 바뀌고, 대기 칸을 작게(PC는 한 줄 칩, 휴대폰은 20% 작게) 해서 한눈에 더 많이 보여요'],
    'setStyle'    => ['화면 설정 창', [['old', '예전 (한 장에 전부)'], ['D1', 'D1 아이콘 8칸'], ['D2', 'D2 테마 카드 + 줄'], ['D3', 'D3 설정 앱 목록'], ['D4', 'D4 위 탭 4개'], ['D5', 'D5 낮은 창 + 칩'], ['D6', 'D6 접는 카드'], ['D7', 'D7 한 장 + 목차 칩'], ['D8', 'D8 분위기 먼저'], ['A3', 'A3 한 줄 + 미니 견본'], ['A7', 'A7 손잡이 띠'], ['A8', 'A8 압축형 ★']], '⚙ 화면 설정(테마·글꼴·색·효과·스크롤바·음악·공유·더보기) 창 모양. 안의 설정 칸은 그대로 옮겨 담기만 해서 동작은 같아요. 휴대폰·PC 같이 바뀌어요. A3·A8은 값 대신 작은 견본(색점·Aa·효과 그림)을 보여주고, 음악은 줄 오른쪽 스피커 버튼으로 바로 켜고 꺼요(꺼지면 음소거 모양). A7·A8은 제목줄 대신 얇은 손잡이 띠'],
    'setInner'    => ['화면 설정 세부 칸', [['old', '예전 (그대로 나열)'], ['B4', 'B4 값 타일 → 작은 팝업'], ['B5', 'B5 작은 미리보기 + 칩']], 'D3·A3·A7·A8에서 항목(색·음악·공유…)을 눌렀을 때 안쪽 모양. B4는 지금 값이 보이는 타일만 먼저 보이고 누른 칸만 열려요(스위치는 타일을 누르면 바로 켜고 꺼짐). B5는 위에 청첩장 작은 미리보기, 아래 칩으로 설정을 하나씩 골라요'],
    'guideStyle'  => ['사용법 버튼', [['old', '예전 알약'], ['G1', 'G1 물음표'], ['G2', 'G2 작은 칩 ★'], ['G3', 'G3 움직이는 그림'], ['G4', 'G4 맨 아래 링크'], ['G5', 'G5 처음만 말풍선'], ['G6', 'G6 편집|사용법 탭'], ['G7', 'G7 떠 있는 ?'], ['G8', 'G8 한 줄 팁']], '섹션 편집창의 "▶ 사용법" 버튼 모양과 자리'],
];
$prefGroups = ['에디터 화면' => ['menuStyle', 'menuStylePc', 'sheetStyle', 's10Add', 'setStyle', 'setInner'], '첫 화면 (메인 영상·사진)' => ['heroPicker', 'videoPicker', 'overToggle', 'nextPicker'], '모든 섹션' => ['jumpFix', 'feAnim', 'toggleChip', 'photoField', 'guideStyle']];
$popupTheme = editor_tips_get()['popupTheme'] ?: ['bg' => '#FBF8F2', 'tex' => 'dot'];
$savedAt = is_file(section_defaults_file()) ? date('Y-m-d H:i', (int) filemtime(section_defaults_file())) : '';
$csrf = csrf_token();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>섹션 설정 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.so-intro { font-size: 13px; color: var(--muted); margin: -14px 0 18px; line-height: 1.75; }
.so-state { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 999px; background: #EEF1F6; color: #4A5A73; margin: 0 0 14px; }
.so-state.custom { background: #E9F5EE; color: #2F7A4E; }
.so-grid { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 22px; align-items: start; }

/* 섹션 목록 */
.so-list { list-style: none; margin: 0; padding: 0; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; overflow: hidden; }
.so-row { display: flex; align-items: center; gap: 10px; padding: 6px 10px 6px 6px; border-bottom: 1px solid var(--ui-soft, #F1EEE9); background: #fff; position: relative; user-select: none; -webkit-user-select: none; }
.so-row:last-child { border-bottom: 0; }
.so-row.dragging { z-index: 3; background: var(--ui-tint, #FFFDF8); box-shadow: 0 10px 26px rgba(40, 30, 20, .16); border-radius: 12px; }
.so-row.flash { animation: soFlash 1s ease; }
@keyframes soFlash { 0% { background: #FFF4D6; } 100% { background: #fff; } }
.so-h { flex: none; width: 34px; height: 34px; display: grid; place-items: center; color: #B9B2A8; cursor: grab; touch-action: none; border-radius: 8px; }
.so-h:hover { background: var(--ui-soft, #F6F3EE); color: #6F6A63; }
.so-row.dragging .so-h { cursor: grabbing; color: #2B2B2B; }
.so-no { flex: none; width: 22px; font-size: 11.5px; font-weight: 700; color: #A29C94; text-align: right; font-variant-numeric: tabular-nums; }
.so-dot { flex: none; width: 10px; height: 10px; border-radius: 50%; }
.so-nm { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.so-nm b { font-size: 14px; font-weight: 700; }
.so-nm code { font-size: 11px; color: #A29C94; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.so-tag { font-size: 10.5px; font-weight: 800; padding: 2px 7px; border-radius: 5px; letter-spacing: .02em; }
.so-tag.new { background: #FFE9EE; color: #C2405E; }
.so-tag.hero { background: #EEF1F6; color: #4A5A73; }
/* 섹션 이름 바꾸기 (✎) */
.so-ren { flex: none; width: 24px; height: 24px; border: 0; border-radius: 7px; background: transparent; color: #B9B1A6; cursor: pointer; display: inline-grid; place-items: center; padding: 0; }
.so-ren svg { width: 14px; height: 14px; }
.so-row:hover .so-ren, .so-ren:focus-visible { background: var(--ui-soft, #F3EEE6); color: #6F6A63; }
@media (hover: none) { .so-ren { color: #8A847B; } }
.so-tag.ren { background: #FFF6E3; color: #8A6510; cursor: pointer; font-weight: 700; }
.so-tag.ren:hover { text-decoration: line-through; }
.so-nm input.so-in { font: inherit; font-size: 14px; font-weight: 700; width: min(200px, 100%); padding: 3px 8px; border: 1.5px solid #3557B7; border-radius: 7px; outline: none; user-select: text; -webkit-user-select: text; }
.so-nm small.so-in-h { font-size: 11px; color: #A29C94; }
.so-mv { flex: none; display: flex; gap: 4px; }
/* 노출 스위치 */
.so-sw { flex: none; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 11.5px; font-weight: 700; color: #2F7A4E; margin-right: 4px; user-select: none; }
.so-sw input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.so-sw i { position: relative; width: 36px; height: 21px; border-radius: 999px; background: #34A060; transition: background .15s; }
.so-sw i::after { content: ''; position: absolute; left: 2px; top: 2px; width: 17px; height: 17px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transform: translateX(15px); transition: transform .16s; }
.so-sw input:not(:checked) + i { background: var(--ui-line, #D5CFC6); }
.so-sw input:not(:checked) + i::after { transform: none; }
.so-sw input:focus-visible + i { outline: 2px solid #3C7DFF; outline-offset: 2px; }
.so-sw span { width: 34px; }
.so-row.off .so-sw { color: #A29C94; }
.so-row.off .so-nm, .so-row.off .so-dot { opacity: .4; }
.so-b.off { opacity: .35; text-decoration: line-through; }
.so-b.wait { opacity: .55; background: transparent !important; border: 1.5px dashed var(--ui-line, #D8CFC2); }
/* 새 청첩장 시작 상태 (디자인 / 적용 / 대기) */
.so-st { flex: none; display: inline-flex; gap: 2px; padding: 2px; border-radius: 9px; background: var(--ui-soft, #F3EEE6); }
.so-st button { border: 0; background: transparent; font: inherit; font-size: 11px; font-weight: 800; color: #8A8278; padding: 5px 8px; border-radius: 7px; cursor: pointer; white-space: nowrap; }
.so-st button.on { background: #fff; color: #2B2320; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
.so-st button.on[data-st="on"] { background: #E9F6EF; color: #23804F; box-shadow: inset 0 0 0 1px #BFE3CC; }
.so-st button.on[data-st="off"] { background: #FFF6E3; color: #8A6510; box-shadow: inset 0 0 0 1px #F0DCA8; }
.so-st.fixed { padding: 5px 9px; font-size: 11px; font-weight: 700; color: #A29C94; background: transparent; }
.so-row.off .so-st { opacity: .4; pointer-events: none; }
.so-help i.st { background: #E9F6EF; color: #23804F; font-style: normal; font-size: 11px; font-weight: 900; }
@media (max-width: 640px) { .so-row { flex-wrap: wrap; } .so-st { order: 10; margin: 2px 0 0 34px; } }
@media (max-width: 520px) { .so-sw span { display: none; } }
.so-mv button { width: 30px; height: 30px; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 9px; background: #fff; color: #6F6A63; cursor: pointer; display: grid; place-items: center; padding: 0; }
.so-mv button:hover:not(:disabled) { background: var(--ui-soft, #F6F3EE); color: #2B2B2B; }
.so-mv button:disabled { opacity: .3; cursor: default; }
.so-warn { display: none; margin: 10px 0 0; font-size: 12.5px; color: #8A5A00; background: #FFF7E3; border: 1px solid #F3E2B5; border-radius: 10px; padding: 9px 12px; line-height: 1.6; }
.so-warn.on { display: block; }
/* S10(그룹 섹션)일 때: 고객 에디터는 "섹션 묶음" 순서로 정렬 → 이 순서와 다르면 알려주고 한 번에 맞추기 */
.so-s10 { display: none; margin: 10px 0 0; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 12.5px; line-height: 1.6; color: #3D4F66; background: #EEF3F9; border: 1px solid #D5E0EE; border-radius: 10px; padding: 9px 12px; }
.so-s10.on { display: flex; }
.so-s10.ok { color: #2F7A4E; background: #EFF8F2; border-color: #CFE8D8; }
.so-s10 span { flex: 1; min-width: 200px; }
.so-s10 button { flex: none; height: 32px; padding: 0 14px; border: 0; border-radius: 9px; background: #2B2320; color: #fff; font: inherit; font-size: 12.5px; font-weight: 800; cursor: pointer; }
.so-s10 button[hidden] { display: none; }
.so-reset { margin: 14px 0 90px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; color: #6F6A63; border-radius: 10px; padding: 9px 14px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; }
.so-reset:hover:not(:disabled) { background: var(--ui-soft, #F6F3EE); color: #2B2B2B; }
.so-reset:disabled { opacity: .45; cursor: default; }

/* 미리보기 (휴대폰) */
.so-pv { position: sticky; top: 84px; }
.so-pv h4 { margin: 0 0 10px; font-size: 12.5px; color: var(--muted); font-weight: 700; }
.so-phone { width: 230px; height: 470px; margin: 0 auto; border-radius: 34px; background: #1F1D1B; padding: 9px; box-shadow: 0 20px 44px rgba(30, 20, 10, .18); }
.so-scr { width: 100%; height: 100%; border-radius: 26px; background: var(--ui-soft, #FAF7F0); overflow: hidden; position: relative; }
.so-scr .in { position: absolute; left: 0; right: 0; top: 0; padding: 0 10px 14px; animation: soScroll 16s ease-in-out infinite alternate; }
.so-phone:hover .so-scr .in { animation-play-state: paused; }
@keyframes soScroll { 0%, 8% { transform: translateY(0); } 92%, 100% { transform: translateY(var(--sy, 0px)); } }
.so-b { margin: 0 0 7px; border-radius: 9px; padding: 9px 10px; font-size: 10.5px; font-weight: 700; color: #3A332E; display: flex; align-items: center; gap: 6px; transition: transform .25s; }
.so-b i { width: 7px; height: 7px; border-radius: 50%; flex: none; }
.so-b.hero { margin: 0 -10px 10px; border-radius: 0; height: 120px; align-items: flex-end; padding: 12px; color: #fff; font-size: 12px; }
.so-b.bump { animation: soBump .5s ease; }
@keyframes soBump { 40% { transform: scale(1.05); } }
.so-pv p { font-size: 11.5px; color: #A29C94; text-align: center; margin: 10px 0 0; line-height: 1.6; }

/* 저장 막대 */
.savebar { position: fixed; left: 50%; bottom: 18px; z-index: 90; transform: translate(-50%, 140%); display: flex; align-items: center; gap: 10px; padding: 10px 10px 10px 18px; border-radius: 16px; background: #2B2B2B; color: #fff; font-size: 13.5px; box-shadow: 0 16px 34px rgba(0, 0, 0, .25); transition: transform .3s cubic-bezier(.2, .8, .3, 1); white-space: nowrap; }
.savebar.on { transform: translate(-50%, 0); }
.savebar button { border: 0; border-radius: 10px; padding: 9px 16px; font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; }
.savebar .undo { background: transparent; color: #CFC9C1; }
.savebar .save { background: #fff; color: #2B2B2B; }
.savebar.busy .save { opacity: .6; pointer-events: none; }
.so-load { padding: 30px; text-align: center; color: var(--muted); font-size: 13px; }

/* 탭 */
.so-tabs { display: flex; gap: 4px; background: var(--ui-line, #EFEAE2); border-radius: 13px; padding: 4px; margin: -6px 0 20px; max-width: 520px; }
.so-tabs a { flex: 1; text-align: center; height: 36px; line-height: 36px; border-radius: 10px; font-size: 13.5px; font-weight: 700; color: #8A8278; text-decoration: none; }
.so-tabs a.on { background: #fff; color: #2B2B2B; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
body[data-so-tab]:not([data-so-tab="order"]) #saveBar { display: none; }
.so-tab[hidden] { display: none; }
/* 편집창 모양 카드 */
.pz-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 14px; margin-bottom: 90px; }
.pz-card { position: relative; display: flex; flex-direction: column; gap: 10px; background: #fff; border: 1.5px solid var(--ui-line, #ECE8E2); border-radius: 18px; padding: 14px; cursor: pointer; transition: border-color .15s, box-shadow .15s, transform .15s; }
.pz-card:hover { border-color: #CFC5B6; transform: translateY(-2px); }
.pz-card input { position: absolute; opacity: 0; pointer-events: none; }
.pz-card:has(input:checked) { border-color: #2B2B2B; box-shadow: 0 0 0 1.5px #2B2B2B, 0 12px 26px rgba(40,30,20,.10); }
.pz-card:has(input:focus-visible) { outline: 2px solid #3C7DFF; outline-offset: 2px; }
.pz-card .pz-ck { position: absolute; right: 12px; top: 12px; width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid var(--ui-line, #D5CFC6); background: #fff; display: grid; place-items: center; color: transparent; font-size: 12px; font-weight: 900; }
.pz-card:has(input:checked) .pz-ck { background: #2B2B2B; border-color: #2B2B2B; color: #fff; }
.pz-card .pz-name { font-size: 14.5px; display: flex; align-items: center; gap: 6px; }
.pz-card .pz-name em { font-style: normal; font-size: 10.5px; font-weight: 800; background: #E0A72E; color: #fff; border-radius: 999px; padding: 2px 8px; }
.pz-card p { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.6; }
.pz-card.busy { opacity: .6; pointer-events: none; }
/* 작은 그림 */
.pz-ill { height: 150px; border-radius: 12px; background-color: var(--pbg, #FBF8F2); border: 1px solid var(--ui-line, #F0EBE3); padding: 10px; display: flex; flex-direction: column; gap: 5px; overflow: hidden; }
.pz-ill i { display: block; height: 9px; border-radius: 4px; background: var(--ui-line, #E3DED7); flex: none; }
.pz-ill .ln { display: grid; grid-template-columns: 26px 1fr; gap: 6px; align-items: center; }
.pz-ill .ln s { height: 6px; border-radius: 3px; background: var(--ui-line, #D5CFC6); }
.pz-ill .sg { height: 14px; border-radius: 5px; background: var(--ui-soft, #EFE8DD); display: flex; gap: 2px; padding: 2px; }
.pz-ill .sg u { flex: 1; border-radius: 3px; }
.pz-ill .sg u:first-child { background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
.pz-ill .tb { display: flex; gap: 2px; background: var(--ui-soft, #EFE8DD); border-radius: 6px; padding: 2px; height: 16px; flex: none; }
.pz-ill .tb u { flex: 1; border-radius: 4px; }
.pz-ill .tb u:first-child { background: #fff; }
.pz-ill .in { height: 14px; border-radius: 5px; background: #fff; border: 1px solid var(--ui-line, #E8E1D6); flex: none; }
.pz-ill .tl { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; }
.pz-ill .tl u { height: 28px; border-radius: 6px; background: #fff; border: 1px solid var(--ui-line, #E8E1D6); position: relative; }
.pz-ill .tl u::after { content: ''; position: absolute; left: 30%; right: 30%; top: 7px; height: 5px; border-radius: 3px; background: #CFC5B6; }
.pz-ill .tl u.on { border-color: #2B2B2B; }
.pz-ill .bar { margin-top: auto; display: flex; gap: 4px; border-top: 1px dashed var(--ui-line, #E3DAD0); padding-top: 5px; }
.pz-ill .bar u { flex: 1; height: 12px; border-radius: 4px; background: #fff; border: 1px solid var(--ui-line, #E8E1D6); }
.pz-ill.old i:nth-child(odd) { width: 40%; height: 6px; background: var(--ui-line, #D5CFC6); }
/* 편집창 바탕 (색 + 무늬) - 카드 그림에도 바로 보임 */
.ldp-dot { background-image: radial-gradient(rgba(120,100,70,.12) 1px, transparent 1.2px); background-size: 5px 5px; }
.ldp-plain { background-image: none; }
.ldp-linen { background-image: repeating-linear-gradient(0deg, rgba(120,100,70,.06) 0 1px, transparent 1px 3px), repeating-linear-gradient(90deg, rgba(120,100,70,.05) 0 1px, transparent 1px 4px); }
.ldp-grid { background-image: linear-gradient(rgba(120,100,70,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(120,100,70,.08) 1px, transparent 1px); background-size: 12px 12px; }
.ldp-paper { background-image: radial-gradient(rgba(120,100,70,.07) .8px, transparent 1px), radial-gradient(rgba(120,100,70,.05) .8px, transparent 1px); background-size: 7px 7px, 11px 11px; background-position: 0 0, 3px 4px; }
.pz-bgbox { display: flex; flex-direction: column; gap: 10px; background: #fff; border: 1.5px dashed var(--ui-line, #E3DAD0); border-radius: 18px; padding: 16px 16px 14px; }
@media (min-width: 640px) { .pz-bgbox { grid-column: span 2; } }
.pz-bgbox .pz-name { font-size: 14.5px; }
.pz-bgbox > p { margin: -4px 0 0; font-size: 12px; color: var(--muted); line-height: 1.6; }
.pz-bgrow { display: grid; grid-template-columns: 44px 1fr; gap: 8px; align-items: center; }
.pz-bgrow > small { font-size: 11.5px; font-weight: 700; color: #8A8278; }
.pz-sws { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.pz-sws button, .pz-sws label { position: relative; width: 28px; height: 28px; border-radius: 50%; border: 0; padding: 0; cursor: pointer; box-shadow: inset 0 0 0 1px rgba(0,0,0,.15); }
.pz-sws .on { box-shadow: inset 0 0 0 1px rgba(0,0,0,.15), 0 0 0 2px #fff, 0 0 0 4px #2B2320; }
.pz-sws label { width: 20px; height: 20px; margin-left: 2px; background: conic-gradient(#F66, #FC5, #6D8, #5BE, #A7F, #F6B, #F66); box-shadow: none; overflow: hidden; }
.pz-sws label::after { content: ''; position: absolute; inset: 5px; border-radius: 50%; background: var(--c, transparent); }
.pz-sws label.on { box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #2B2320; }
.pz-sws label input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; border: 0; padding: 0; }
.pz-tex { display: flex; flex-wrap: wrap; gap: 6px; }
.pz-tex button { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 11px 0 4px; border-radius: 999px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; font: inherit; font-size: 12px; font-weight: 700; color: #6F6A63; cursor: pointer; }
.pz-tex button i { width: 22px; height: 22px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(0,0,0,.08); background-color: var(--pbg, #FBF8F2); }
.pz-tex button.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.pz-sws button:disabled, .pz-tex button:disabled { cursor: default; opacity: .6; }
.pz-bgwarn { margin: 0; font-size: 12px; color: #B5522F; }
.pz-bgstate { font-size: 11.5px; color: #2F7A4E; font-weight: 700; min-height: 1em; }
.pz-subs { grid-column: 1 / -1; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 18px; padding: 16px 16px 12px; display: flex; flex-direction: column; gap: 12px; }
.pz-subs > p { margin: -6px 0 0; font-size: 12px; color: var(--muted); line-height: 1.6; }
.pz-sub { display: grid; grid-template-columns: 110px minmax(0, 1fr); gap: 10px; align-items: start; padding-top: 12px; border-top: 1px dashed var(--ui-line, #EAE3D8); }
.pz-sub > b { font-size: 13px; padding-top: 6px; }
.pz-sub small { display: block; font-size: 11.5px; color: var(--muted); margin-top: 6px; line-height: 1.5; }
.pz-segs { display: flex; flex-wrap: wrap; gap: 5px; }
.pz-segs button { height: 30px; padding: 0 11px; border-radius: 999px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; font: inherit; font-size: 12px; font-weight: 700; color: #6F6A63; cursor: pointer; }
.pz-segs button.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.pz-segs button:disabled { opacity: .55; cursor: default; }
@media (max-width: 640px) { .pz-sub { grid-template-columns: 1fr; gap: 6px; } .pz-sub > b { padding-top: 0; } }
@media (max-width: 860px) {
    .so-grid { grid-template-columns: 1fr; }
    .so-pv { display: none; }
}
@media (max-width: 520px) {
    .savebar { left: 12px; right: 12px; transform: translateY(150%); padding: 8px 8px 8px 14px; gap: 4px; font-size: 12.5px; }
    .savebar.on { transform: none; }
    .savebar span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .savebar button { padding: 9px 12px; }
    .so-row { gap: 7px; padding: 8px 8px 8px 2px; }
    .so-nm code { display: none; }
    .so-mv button { width: 30px; height: 30px; }
}

/* ===== 화면 정리 (1002t) ===== */
.so-page { max-width: 1080px; }
.so-tabs { margin: -4px 0 18px; }
.so-help { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin: 0 0 16px; }
.so-help > div { display: flex; gap: 10px; align-items: flex-start; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 14px; padding: 11px 12px; }
.so-help i { flex: none; width: 30px; height: 30px; border-radius: 9px; background: var(--ui-soft, #F3EEE6); color: #8A8278; display: grid; place-items: center; font-style: normal; font-weight: 900; font-size: 14px; }
.so-help i.sw { position: relative; }
.so-help i.sw::before { content: ''; width: 18px; height: 11px; border-radius: 999px; background: var(--ui-line, #D5CFC6); }
.so-help i.sw::after { content: ''; position: absolute; left: 7px; top: 10px; width: 9px; height: 9px; border-radius: 50%; background: #fff; }
.so-help i.nw { background: #FFE9EE; color: #C2405E; font-size: 12px; }
.so-help span { font-size: 11.5px; color: var(--muted); line-height: 1.5; }
.so-help span b { display: block; font-size: 12.5px; color: #2B2B2B; margin-bottom: 1px; }
.so-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0 0 12px; }
.so-bar .so-state { margin: 0; }
.so-bar .so-reset { margin: 0 0 0 auto; padding: 7px 12px; font-size: 12.5px; }
.so-grid + * { margin-bottom: 90px; }
.so-list { margin-bottom: 90px; }

.so-nm code { opacity: .8; }
.savebar:not(.on) { visibility: hidden; transition: transform .3s cubic-bezier(.2, .8, .3, 1), visibility 0s .3s; }
/* 편집창 모양 탭: 번호 붙은 칸 3개 */
.ad-sec { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 18px; padding: 16px; margin: 0 0 14px; }
.ad-hd { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 14px; }
.ad-hd > div { flex: 1; min-width: 0; }
.ad-hd h3 { margin: 1px 0 3px; font-size: 15px; }
.ad-hd p { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.6; }
.ad-no { flex: none; width: 24px; height: 24px; border-radius: 50%; background: #2B2320; color: #fff; font-size: 12px; font-weight: 800; display: grid; place-items: center; margin-top: 1px; }
.ad-auto { flex: none; font-size: 11px; font-weight: 700; color: #2F7A4E; background: #E9F5EE; border-radius: 999px; padding: 3px 9px; margin-top: 2px; }
.ad-sec .pz-cards { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin: 0; }
.ad-sec .pz-card { padding: 12px; gap: 8px; border-radius: 16px; }
.ad-sec .pz-ill { height: 112px; }
.ad-sec .pz-card p { font-size: 11.5px; }
.pz-grp { margin: 6px 0 0; font-size: 11.5px; font-weight: 800; color: #9A6A0A; letter-spacing: .02em; }
#pzSubs .pz-sub { grid-template-columns: 210px minmax(0, 1fr); gap: 14px; padding: 12px 0; border-top: 1px dashed var(--ui-line, #EAE3D8); }
#pzSubs .pz-grp + .pz-sub { border-top: 0; padding-top: 8px; }
.pz-sub-l b { font-size: 13px; display: block; }
.pz-sub-l small { margin-top: 3px !important; font-size: 11px !important; }
.ad-sec.pz-bgbox { border-style: dashed; border-width: 1.5px; gap: 10px; }
@media (max-width: 860px) {
    .so-help { grid-template-columns: 1fr; gap: 6px; }
    .so-help > div { padding: 8px 10px; align-items: center; }
    .so-help i { width: 26px; height: 26px; font-size: 12px; }
    .ad-sec .pz-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
}
@media (max-width: 640px) {
    .ad-sec { padding: 13px 12px; border-radius: 16px; }
    .ad-hd { flex-wrap: wrap; }
    .ad-auto { order: 3; margin-left: 34px; }
    .ad-sec .pz-card { padding: 9px; gap: 6px; }
    .ad-sec .pz-ill { height: 74px; padding: 7px; gap: 3px; }
    .ad-sec .pz-ill i { height: 6px; }
    .ad-sec .pz-ill .tl u { height: 18px; }
    .ad-sec .pz-card .pz-name { font-size: 13px; }
    .ad-sec .pz-card p { font-size: 11px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .ad-sec .pz-card .pz-ck { width: 18px; height: 18px; right: 9px; top: 9px; font-size: 10px; }
    #pzSubs .pz-sub { grid-template-columns: 1fr; gap: 7px; }
    .so-bar .so-reset { margin-left: 0; }
}

/* ===== 편집창 모양 탭: 오른쪽 실시간 미리보기 (에디터를 ?apv=1 로 작게 열어 둠) ===== */
.pz-layout { display: grid; grid-template-columns: minmax(0, 1fr) 336px; gap: 16px; align-items: start; }
.pz-main { min-width: 0; }
.pz-layout .ad-sec .pz-cards { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
.pz-layout #pzSubs .pz-sub { grid-template-columns: 170px minmax(0, 1fr); }
.apv-box { position: sticky; top: 84px; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 18px; padding: 12px; display: flex; flex-direction: column; gap: 10px; }
.apv-hd { display: flex; align-items: center; gap: 8px; }
.apv-hd b { font-size: 14px; }
.apv-hd span { flex: 1; min-width: 0; font-size: 11.5px; font-weight: 700; color: #9A6A0A; background: #FFF4D6; border-radius: 999px; padding: 3px 9px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.apv-x { display: none; }
.apv-phone { position: relative; width: 300px; height: 560px; margin: 0 auto; border-radius: 26px; background: #1F1D1B; padding: 8px; box-shadow: 0 16px 36px rgba(30,20,10,.18); }
.apv-phone iframe { display: block; width: 390px; height: 708px; border: 0; border-radius: 20px; background: var(--ui-tint, #FBF8F2); transform: scale(.729); transform-origin: 0 0; }
.apv-load { position: absolute; inset: 8px; border-radius: 20px; background: var(--ui-tint, #FBF8F2); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; font-size: 12px; color: var(--muted); transition: opacity .25s; }
.apv-load i { width: 26px; height: 26px; border-radius: 50%; border: 3px solid var(--ui-line, #E8E1D6); border-top-color: #2B2320; animation: apvSpin .8s linear infinite; }
@keyframes apvSpin { to { transform: rotate(360deg); } }
.apv-load.done { opacity: 0; pointer-events: none; }
.apv-tip { margin: 0; font-size: 11.5px; color: var(--muted); line-height: 1.6; }
.apv-tip b { color: #2B2B2B; }
.apv-fab { display: none; }
/* PC 메뉴 미리보기: 넓은 화면(1280px)을 작게 줄여서 */
.apv-box.pc .apv-phone { width: 300px; height: 230px; border-radius: 14px; padding: 6px; }
.apv-box.pc .apv-phone iframe { width: 1280px; height: 960px; transform: scale(.2250); border-radius: 8px; }
#pzSubs .pz-segs button.pv, .pz-card.pv { outline: 2px dashed #E0A72E; outline-offset: 2px; }
#pzSubs .pz-sub.pv-row { background: var(--ui-tint, #FFFBF0); border-radius: 12px; }
@media (max-width: 1000px) {
    .pz-layout { grid-template-columns: 1fr; }
    .apv-box { position: fixed; left: 0; right: 0; bottom: 0; top: auto; z-index: 95; border-radius: 20px 20px 0 0; box-shadow: 0 -12px 40px rgba(0,0,0,.25); transform: translateY(105%); transition: transform .3s cubic-bezier(.2,.8,.3,1); max-height: 86vh; }
    .apv-box.open { transform: none; }
    .apv-x { display: grid; place-items: center; width: 30px; height: 30px; border: 0; border-radius: 50%; background: var(--ui-soft, #F3EEE6); font-size: 13px; cursor: pointer; }
    .apv-phone { width: 270px; height: min(520px, calc(86vh - 120px)); }
    .apv-phone iframe { transform: scale(.651); height: calc(min(520px, calc(86vh - 120px)) / .651); }
    .apv-fab { position: fixed; right: 16px; bottom: 18px; z-index: 94; display: flex; align-items: center; gap: 6px; height: 44px; padding: 0 16px 0 13px; border: 0; border-radius: 999px; background: #2B2320; color: #fff; font: inherit; font-size: 13px; font-weight: 800; box-shadow: 0 8px 22px rgba(0,0,0,.28); cursor: pointer; }
    .apv-fab svg { width: 18px; height: 18px; }
    .so-tab[hidden] ~ .apv-fab, body:not(.on-panel) .apv-fab { display: none; }
}
/* ===== 섹션 묶음 탭 (S10 그룹 섹션) ===== */
.gp-intro { font-size: 13px; color: var(--muted); line-height: 1.75; margin: -6px 0 14px; }
.gp-intro b { color: #2B2B2B; }
.gp-grid { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 22px; align-items: start; }
.gp-list { display: flex; flex-direction: column; gap: 10px; }
.gp-card { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; padding: 10px 12px 12px; transition: box-shadow .15s, border-color .15s; }
.gp-card.flash { animation: soFlash 1s ease; }
.gp-hd { display: flex; align-items: center; gap: 8px; }
.gp-ic { position: relative; flex: none; width: 36px; height: 36px; border-radius: 10px; border: 0; background: var(--ui-soft, #F3EEE6); color: #4A3F37; display: grid; place-items: center; cursor: pointer; }
.gp-ic:hover { background: var(--ui-line, #EAE2D6); }
.gp-ic svg, .gp-icm svg, .gp-pv svg, .gp-gm svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.gp-nm { flex: 1; min-width: 0; height: 36px; border: 1px solid var(--ui-line, #E8E1D6); border-radius: 10px; padding: 0 11px; font: inherit; font-size: 14px; font-weight: 700; color: #2B2B2B; background: var(--ui-tint, #FFFDF9); }
.gp-nm:focus { outline: 2px solid #E0A72E; outline-offset: 0; border-color: transparent; }
.gp-cnt { flex: none; font-size: 11.5px; font-weight: 700; color: #A29C94; min-width: 34px; text-align: right; }
.gp-hd > button:not(.gp-ic) { flex: none; width: 30px; height: 30px; border: 0; border-radius: 8px; background: var(--ui-soft, #F6F3EE); color: #6F6A63; cursor: pointer; display: grid; place-items: center; font-size: 13px; }
.gp-hd > button:not(.gp-ic):hover:not(:disabled) { background: var(--ui-line, #EDE7DE); color: #2B2B2B; }
.gp-hd > button:disabled { opacity: .3; cursor: default; }
.gp-hd > button[data-gdel]:hover:not(:disabled) { background: #FBEAEA; color: #B03A3A; }
.gp-chips { display: flex; flex-wrap: wrap; gap: 6px; min-height: 40px; margin-top: 9px; padding: 6px; border-radius: 12px; background: var(--ui-tint, #FBF8F3); border: 1.5px dashed transparent; align-content: flex-start; }
.gp-chips.over { border-color: #E0A72E; background: #FFF8E6; }
.gp-chip { display: inline-flex; align-items: center; gap: 6px; height: 30px; padding: 0 10px 0 8px; border: 1px solid var(--ui-line, #E8E1D6); border-radius: 999px; background: #fff; font: inherit; font-size: 12.5px; font-weight: 700; color: #3A332E; cursor: grab; user-select: none; }
.gp-chip i { width: 8px; height: 8px; border-radius: 50%; flex: none; }
.gp-chip em { font-style: normal; font-size: 10.5px; font-weight: 800; color: #A29C94; }
.gp-chip.off { opacity: .45; }
.gp-chip.lock { cursor: default; background: var(--ui-soft, #F6F3EE); }
.gp-chip.drag { opacity: .35; }
.gp-chip:hover { border-color: #CDBFA9; }
.gp-empty { font-size: 12px; color: #B3ACA2; align-self: center; padding: 0 6px; }
.gp-add { margin-top: 10px; width: 100%; height: 42px; border: 1.5px dashed var(--ui-line, #D8CFC2); border-radius: 14px; background: transparent; font: inherit; font-size: 13px; font-weight: 800; color: #6F6A63; cursor: pointer; }
.gp-add:hover:not(:disabled) { border-color: #34A060; color: #2F7A4E; background: #F6FCF8; }
.gp-etc { margin-top: 14px; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; padding: 12px; }
.gp-etc-hd { display: flex; align-items: center; gap: 6px 10px; flex-wrap: wrap; font-size: 12.5px; color: #6F6A63; }
.gp-etc-hd b { font-size: 13.5px; color: #2B2B2B; }
.gp-etc-hd input { width: 110px; height: 30px; border: 1px solid var(--ui-line, #E8E1D6); border-radius: 8px; padding: 0 8px; font: inherit; font-size: 13px; font-weight: 700; }
.gp-foot { position: sticky; bottom: 12px; display: flex; align-items: center; gap: 10px; justify-content: flex-end; margin-top: 14px; padding: 10px 12px; background: rgba(255,255,255,.94); border: 1px solid var(--ui-line, #ECE8E2); border-radius: 14px; box-shadow: 0 6px 20px rgba(0,0,0,.06); }
.gp-foot span { flex: 1; font-size: 12.5px; font-weight: 700; color: #8A8278; }
.gp-foot span.dirty { color: #C2405E; }
.gp-foot button { height: 38px; padding: 0 18px; border: 0; border-radius: 10px; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; }
.gp-foot .gp-undo { background: var(--ui-soft, #F3EEE6); color: #6F6A63; }
.gp-foot .gp-save { background: #2B2320; color: #fff; }
.gp-foot button:disabled { opacity: .35; cursor: default; }
/* 아이콘 고르기 / 칩 → 묶음 고르기 작은 창 */
.gp-pop { position: absolute; z-index: 120; background: #fff; border-radius: 14px; box-shadow: 0 14px 40px rgba(0,0,0,.2); padding: 8px; }
.gp-icm { display: grid; grid-template-columns: repeat(6, 36px); gap: 4px; }
.gp-icm button { width: 36px; height: 36px; border: 0; border-radius: 9px; background: var(--ui-soft, #F6F3EE); color: #4A3F37; display: grid; place-items: center; cursor: pointer; }
.gp-icm button.on, .gp-icm button:hover { background: #2B2320; color: #fff; }
.gp-gm { display: flex; flex-direction: column; min-width: 190px; }
.gp-gm svg { flex: none; width: 16px; height: 16px; color: #8A8278; }
.gp-gm small { font-size: 11px; font-weight: 800; color: #A29C94; padding: 4px 8px 6px; }
.gp-gm button { display: flex; align-items: center; gap: 8px; height: 36px; padding: 0 10px; border: 0; border-radius: 9px; background: transparent; font: inherit; font-size: 13px; font-weight: 700; color: #2B2B2B; cursor: pointer; text-align: left; }
.gp-gm button:hover { background: var(--ui-soft, #F6F3EE); }
.gp-gm button.on { color: #2F7A4E; }
.gp-gm button.on::after { content: '✓'; margin-left: auto; }
.gp-gm hr { border: 0; border-top: 1px solid var(--ui-soft, #F1EEE9); margin: 4px 0; }
/* 미리보기 (고객 섹션 목록 S10 모양) */
.gp-pv { position: sticky; top: 76px; background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 18px; padding: 14px; }
.gp-pv h4 { margin: 0 0 10px; font-size: 12.5px; color: #6F6A63; }
.gp-pv p { margin: 10px 0 0; font-size: 11.5px; color: var(--muted); line-height: 1.6; }
.gp-scr { max-height: 560px; overflow: auto; border-radius: 14px; background: var(--ui-tint, #FBF8F3); padding: 10px 10px 12px; }
.gp-pv-tl { position: relative; padding-left: 38px; }
.gp-pv-tl::before { content: ''; position: absolute; left: 17px; top: 6px; bottom: 6px; width: 2px; background: var(--ui-line, #E5DDD1); }
.gp-pv-h { position: relative; z-index: 1; display: flex; align-items: center; gap: 7px; margin: 10px 0 5px -32px; height: 30px; padding: 0 9px; border-radius: 9px; background: var(--ui-line, #F1EADF); font-size: 12px; font-weight: 800; color: #3A332E; }
.gp-pv-h:first-child { margin-top: 0; }
.gp-pv-h small { margin-left: auto; font-size: 10.5px; color: #8A8278; font-weight: 700; }
.gp-pv-h svg { width: 15px; height: 15px; }
.gp-pv-r { position: relative; height: 30px; margin: 0 0 4px; padding: 0 10px; border-radius: 9px; background: #fff; border: 1px solid var(--ui-line, #EEE6DA); display: flex; align-items: center; font-size: 11.5px; font-weight: 700; color: #3A332E; }
.gp-pv-r::before { content: attr(data-n); position: absolute; left: -32px; width: 20px; height: 20px; border-radius: 50%; background: #2B2320; color: #fff; font-size: 9.5px; font-weight: 900; display: grid; place-items: center; box-shadow: 0 0 0 3px var(--ui-tint, #FBF8F3); }
.gp-pv-e { margin: 0 0 4px; font-size: 10.5px; color: #B3ACA2; padding: 6px 10px; border: 1px dashed var(--ui-line, #E5DDD1); border-radius: 9px; }
@media (max-width: 900px) {
    .gp-grid { grid-template-columns: 1fr; }
    .gp-pv { position: static; }
    .gp-hd { flex-wrap: wrap; }
    .gp-nm { min-width: 140px; }
}

/* 손쉬운 제작 탭 */
.ez-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 22px; align-items: start; }
.ez-list { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.ez-row { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid var(--ui-line, #E8E3DB); border-radius: 12px; padding: 10px 12px; }
.ez-row.off { background: #FAF8F5; }
.ez-row { flex-wrap: wrap; }
.ez-fold-btn { flex: none; height: 30px; padding: 0 10px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 99px; background: #fff; font: inherit; font-size: 11.5px; font-weight: 700; color: #6F6A63; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
.ez-fold-btn b { color: #2F7A4E; } .ez-fold-btn.part b { color: #B5713A; }
.ez-fold-btn i { font-style: normal; transition: transform .2s; } .ez-fold-btn.open i { transform: rotate(180deg); }
.ez-fold-btn.open { border-color: #2B2B2B; color: #2B2B2B; }
.ez-fold { flex-basis: 100%; display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 6px; margin: 8px 0 2px 58px; padding: 10px; border-radius: 12px; background: var(--ui-soft, #F8F5F0); border: 1px solid var(--ui-line, #EEE8DF); animation: ezFold .22s ease; }
.ez-fold[hidden] { display: none; }
@keyframes ezFold { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
.ez-opt { display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 10px; background: #fff; border: 1px solid var(--ui-line, #ECE6DD); cursor: pointer; font-size: 12.5px; font-weight: 600; }
.ez-opt input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.ez-opt i { flex: none; position: relative; width: 30px; height: 18px; border-radius: 99px; background: #34A060; transition: background .15s; }
.ez-opt i::after { content: ''; position: absolute; left: 2px; top: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transform: translateX(12px); transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.ez-opt.off { color: #A29C94; } .ez-opt.off i { background: var(--ui-line, #D5CFC6); } .ez-opt.off i::after { transform: none; }
.ez-fold-act { grid-column: 1 / -1; display: flex; gap: 6px; justify-content: flex-end; }
.ez-fold-act button { border: 0; background: none; font: inherit; font-size: 11.5px; color: #8A8278; text-decoration: underline; cursor: pointer; }
.ez-body .sk.op { height: auto; padding: 8px 10px; font-size: 10.5px; color: #6F6A63; }
@media (max-width: 640px) { .ez-fold { margin-left: 0; } }
.ez-grip { flex: none; width: 18px; text-align: center; color: #B5AEA4; font-size: 15px; cursor: grab; touch-action: none; user-select: none; }
.ez-grip-x { cursor: default; }
.ez-ghost { opacity: .45; background: var(--ui-soft, #F3EFE8) !important; }
.ez-offhd { display: flex; align-items: baseline; gap: 10px; margin: 22px 2px 8px; }
.ez-offhd b { font-size: 13.5px; } .ez-offhd span { font-size: 11.5px; color: #8A8278; }
.ez-off { min-height: 54px; border: 1.5px dashed var(--ui-line, #E3DED7); border-radius: 14px; padding: 6px; }
.ez-empty { list-style: none; font-size: 12px; color: #A29C94; text-align: center; padding: 12px; }
.ez-row.off .ez-t, .ez-row.off .ez-ic { opacity: .45; }
.ez-no { flex: none; width: 22px; height: 22px; border-radius: 50%; background: #2B2B2B; color: #fff; font-size: 11px; font-weight: 700; display: grid; place-items: center; }
.ez-row.off .ez-no { background: var(--ui-line, #D5CFC6); }
.ez-ic { flex: none; width: 30px; height: 30px; border-radius: 9px; background: var(--ui-soft, #F3EFE8); display: grid; place-items: center; font-size: 14px; color: #6F6A63; }
.ez-t { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ez-t b { font-size: 14px; } .ez-t small { font-size: 11.5px; color: #8A8278; line-height: 1.4; }
.ez-t em { font-style: normal; font-size: 10.5px; color: #A29C94; }
.ez-mv { flex: none; display: flex; flex-direction: column; gap: 2px; }
.ez-mv button { width: 26px; height: 20px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; border-radius: 6px; font-size: 10px; color: #6F6A63; cursor: pointer; padding: 0; }
.ez-mv button:disabled { opacity: .3; cursor: default; }
.ez-lock { flex: none; font-size: 11px; font-weight: 700; color: #A29C94; width: 76px; text-align: right; }
.ez-pv { position: sticky; top: 80px; }
.ez-pv h4 { margin: 0 0 10px; font-size: 13px; color: #6F6A63; }
.ez-pv p { font-size: 11.5px; color: #8A8278; margin: 10px 2px 0; }
.ez-phone { background: var(--ui-page, #F6F4F1); border: 8px solid #2B2B2B; border-radius: 28px; padding: 14px 0 0; min-height: 420px; display: flex; flex-direction: column; overflow: hidden; }
.ez-hd { padding: 0 16px 8px; } .ez-hd small { display: block; font-size: 10px; font-weight: 700; color: #B08A5A; } .ez-hd b { font-size: 13px; }
.ez-steps { display: flex; gap: 3px; padding: 0 12px 8px; overflow-x: auto; scrollbar-width: none; }
.ez-steps button { flex: none; display: flex; align-items: center; gap: 4px; border: 0; background: none; padding: 4px; font: inherit; font-size: 10.5px; font-weight: 600; color: #A29C94; cursor: pointer; white-space: nowrap; }
.ez-steps button i { width: 15px; height: 15px; border-radius: 50%; border: 1px solid var(--ui-line, #DDD6CC); background: #fff; display: grid; place-items: center; font-style: normal; font-size: 8.5px; }
.ez-steps button.on { color: #2B2B2B; } .ez-steps button.on i { background: #2B2B2B; border-color: #2B2B2B; color: #fff; }
.ez-body { flex: 1; padding: 10px 16px; }
.ez-body h5 { margin: 0 0 6px; font-size: 16px; }
.ez-body p { margin: 0 0 12px; font-size: 11.5px; color: #8A8278; line-height: 1.5; }
.ez-body .sk { height: 34px; border-radius: 10px; background: #fff; border: 1px solid var(--ui-line, #E8E3DB); margin-bottom: 8px; }
.ez-body .sw { display: flex; justify-content: space-between; align-items: center; height: 38px; padding: 0 12px; border-radius: 10px; background: #fff; border: 1px solid var(--ui-line, #E8E3DB); margin-bottom: 10px; font-size: 11.5px; font-weight: 700; }
.ez-body .sw i { width: 30px; height: 18px; border-radius: 99px; background: #1B1A18; }
.ez-nav { display: flex; gap: 8px; padding: 10px 14px 14px; } .ez-nav span, .ez-nav b { flex: 1; text-align: center; height: 34px; line-height: 34px; border-radius: 10px; font-size: 12px; }
.ez-nav span { border: 1px solid var(--ui-line, #DDD6CC); color: #6F6A63; } .ez-nav b { background: #1B1A18; color: #fff; }
@media (max-width: 860px) { .ez-grid { grid-template-columns: 1fr; } .ez-pv { position: static; } }</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('sections', '섹션 설정'); ?>

    <div class="wrap so-page">
        <h2 class="page-title">에디터 섹션 설정</h2>
        <nav class="so-tabs" role="tablist">
            <a href="#order" data-so-tab="order" class="on" role="tab">섹션 순서</a>
            <a href="#panel" data-so-tab="panel" role="tab">편집창·메뉴 모양</a>
            <a href="#groups" data-so-tab="groups" role="tab">섹션 묶음</a>
            <a href="#easy" data-so-tab="easy" role="tab">손쉬운 제작</a>
        </nav>

        <div class="so-tab" data-so-pane="easy" hidden>
            <p class="gp-intro">왕초보 고객이 쓰는 <b>간편 만들기</b>(에디터 ‘도구 → 간편 만들기’, 위쪽 <b>정보</b> 버튼)의 단계예요. 단계를 <b>켜고 끄고</b>, <b>⠿를 끌거나 ▲▼로 제작 순서</b>를 정한 뒤 저장하세요. 고객 청첩장의 섹션도 이 순서로 놓여요 (메인 사진·영상은 늘 맨 위).<br>단계는 그 섹션이 디자인에 있을 때만 보이고, 고객은 단계마다 ‘청첩장에 넣기’ 스위치로 섹션을 넣고 뺄 수 있어요. <b>두 사람</b>은 늘 켜지고 <b>마무리</b>는 늘 맨 끝이에요.</p>
            <div class="so-bar">
                <div class="so-state<?= $easyCustom ? ' custom' : '' ?>">● <?= $easyCustom ? '관리자가 정한 단계 사용 중' : '기본 단계 사용 중 (모두 켜짐)' ?></div>
                <button type="button" class="so-reset" id="ezReset"<?= $easyCustom ? '' : ' disabled' ?>>기본 단계로 되돌리기</button>
            </div>
            <div class="ez-grid">
                <div>
                    <ul class="ez-list" id="ezList"></ul>
                    <div class="ez-offhd"><b>꺼진 단계</b><span>스위치를 켜거나 위로 끌어 올리면 다시 들어가요</span></div>
                    <ul class="ez-list ez-off" id="ezOff"></ul>
                    <div class="gp-foot"><span id="ezState">켜진 단계만 고객에게 이 순서로 보여요</span><button type="button" class="gp-undo" id="ezUndo" disabled>되돌리기</button><button type="button" class="gp-save" id="ezSave" disabled>단계 저장</button></div>
                </div>
                <aside class="ez-pv">
                    <h4>미리보기 · 고객 간편 만들기</h4>
                    <div class="ez-phone"><div class="ez-hd"><small>간편 만들기</small><b id="ezPvNow"></b></div><div class="ez-steps" id="ezPvSteps"></div><div class="ez-body" id="ezPvBody"></div><div class="ez-nav"><span>이전</span><b>다음</b></div></div>
                    <p>단계를 누르면 그 단계에서 고객이 하는 일을 보여줘요.</p>
                </aside>
            </div>
        </div>

        <div class="so-tab" id="soTabPanel" data-so-pane="panel" hidden>
          <div class="pz-layout"><div class="pz-main">
            <section class="ad-sec">
                <header class="ad-hd"><span class="ad-no">1</span><div><h3>편집창 모양</h3><p>고객이 섹션을 눌렀을 때 뜨는 편집창의 배치예요. 기능은 같고 <b>배치만</b> 달라져요.</p></div><span class="ad-auto">누르면 바로 저장</span></header>
                <div class="pz-cards" id="pzCards" style="--pbg:<?= $h($popupTheme['bg']) ?>">
                <?php
                $pzOpts = [
                    'C' => ['C. 타일형', '추천', '글·사진은 위에서 바로 고치고, 모양 옵션은 <b>지금 값이 보이는 타일</b>을 눌러 작은 창에서 바꿔요. 가장 짧아요.',
                        '<i style="width:60%"></i><span class="in"></span><span class="in"></span><span class="tl"><u></u><u class="on"></u><u></u><u></u></span><span class="sg"><u></u><u></u><u></u></span><span class="bar"><u></u><u></u><u></u></span>'],
                    'B' => ['B. 탭형', '', '<b>내용 · 모양 · 꾸미기</b> 탭으로 나눠요. 짧지만 옵션이 어느 탭에 있는지 찾아야 해요.',
                        '<span class="tb"><u></u><u></u><u></u></span><span class="in"></span><span class="in"></span><span class="ln"><s></s><span class="sg"><u></u><u></u><u></u></span></span><span class="ln"><s></s><span class="sg"><u></u><u></u></span></span>'],
                    'A' => ['A. 한 줄 정리형', '', '모든 옵션이 보이되 <b>왼쪽 작은 이름 + 버튼 한 줄</b>로 정리해요. 옵션이 많은 섹션은 조금 길어요.',
                        '<span class="ln"><s></s><span class="in"></span></span><span class="ln"><s></s><span class="in"></span></span><span class="ln"><s></s><span class="sg"><u></u><u></u><u></u></span></span><span class="ln"><s></s><span class="sg"><u></u><u></u></span></span><span class="ln"><s></s><span class="sg"><u></u><u></u><u></u><u></u></span></span><span class="bar"><u></u><u></u><u></u></span>'],
                    'old' => ['예전 모양', '', '모든 옵션을 <b>세로로 길게</b> 나열해요. (이전 화면 그대로)',
                        str_repeat('<i></i><i style="width:90%"></i>', 6)],
                ];
                foreach ($pzOpts as $k => [$name, $badge, $desc, $ill]): ?>
                <label class="pz-card">
                    <input type="radio" name="pz" value="<?= $h($k) ?>"<?= $panelStyle === $k ? ' checked' : '' ?>>
                    <span class="pz-ck">✓</span>
                    <span class="pz-ill ldp-<?= $h($popupTheme['tex']) ?><?= $k === 'old' ? ' old' : '' ?>"><?= $ill ?></span>
                    <strong class="pz-name"><?= $h($name) ?><?= $badge ? '<em>' . $h($badge) . '</em>' : '' ?></strong>
                    <p><?= $desc ?></p>
                </label>
                <?php endforeach; ?>
                </div>
            </section>
            <section class="ad-sec" id="pzSubs">
                <header class="ad-hd"><span class="ad-no">2</span><div><h3>세부 모양</h3><p>편집창 안 사진 칸·스위치·버튼 모양이에요. <b>★</b>가 추천이에요.</p></div><span class="ad-auto">누르면 바로 저장</span></header>
                <?php foreach ($prefGroups as $gname => $gkeys): ?>
                <h4 class="pz-grp"><?= $h($gname) ?></h4>
                <?php foreach ($gkeys as $pk): [$pname, $popts, $pdesc] = $prefRows[$pk]; ?>
                <div class="pz-sub"><div class="pz-sub-l"><b><?= $h($pname) ?></b><small><?= $h($pdesc) ?></small></div><div class="pz-segs" data-pref="<?= $h($pk) ?>">
                    <?php foreach ($popts as [$pv, $pl]): ?><button type="button" data-v="<?= $h($pv) ?>" class="<?= $prefs[$pk] === $pv ? 'on' : '' ?>"><?= $h($pl) ?></button><?php endforeach; ?>
                </div></div>
                <?php endforeach; endforeach; ?>
            </section>
            <section class="ad-sec pz-bgbox" id="pzBg">
                <header class="ad-hd"><span class="ad-no">3</span><div><h3>편집창 바탕</h3><p>섹션 편집창과 글자·스티커 팝업창의 바탕이에요. 위 1번 그림에도 바로 보여요.</p></div><span class="ad-auto">누르면 바로 저장</span></header>
                <div class="pz-bgrow"><small>바탕색</small><div class="pz-sws" id="pzSws"></div></div>
                <div class="pz-bgrow"><small>무늬</small><div class="pz-tex" id="pzTex"></div></div>
                <p class="pz-bgwarn" id="pzBgWarn" hidden>어두운 색이라 편집창 글씨가 잘 안 보일 수 있어요. 밝은 색을 권장해요.</p>
                <span class="pz-bgstate" id="pzBgState"></span>
            </section>
          </div>
          <aside class="apv-box" id="apvBox" aria-label="편집창 미리보기">
            <div class="apv-hd"><b>미리보기</b><span id="apvWhat">고객 편집창</span><button type="button" class="apv-x" id="apvX" aria-label="닫기">✕</button></div>
            <div class="apv-phone"><iframe id="apvFrame" title="편집창 미리보기" tabindex="-1"></iframe><div class="apv-load" id="apvLoad"><i></i>편집창 불러오는 중…</div></div>
            <p class="apv-tip"><b>옵션에 마우스를 올리면</b> 그 모양을 미리 보여주고, <b>누르면</b> 저장과 함께 보여줘요. (예시 내용이라 실제 청첩장은 바뀌지 않아요)</p>
          </aside>
          </div>
          <button type="button" class="apv-fab" id="apvFab"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>미리보기</button>
        </div>

        <div class="so-tab" data-so-pane="groups" hidden>
            <p class="gp-intro">에디터 <b>섹션 목록 창</b>을 <b>S10 그룹 섹션</b>(또는 S7 묶음별)으로 골랐을 때 보이는 묶음이에요. 고객은 섹션을 다른 묶음으로 끌어 옮기거나, 대기 중인 섹션을 원하는 묶음에 넣을 수 있어요.<br>청첩장 순서는 <b>이 묶음 순서</b>대로 정리되고, 묶음 안 순서는 <b>섹션 순서</b> 탭 순서를 따라요. 이미 만든 청첩장도 고객이 에디터를 열면 이 묶음으로 보여요.</p>
            <div class="so-bar">
                <div class="so-state<?= $groupsCustom ? ' custom' : '' ?>">● <?= $groupsCustom ? '관리자가 정한 묶음 사용 중' : '기본 묶음 사용 중 (아직 정한 묶음 없음)' ?></div>
                <button type="button" class="so-reset" id="gpReset"<?= $groupsCustom ? '' : ' disabled' ?>>기본 묶음으로 되돌리기</button>
            </div>
            <div class="gp-grid">
                <div>
                    <div class="gp-list" id="gpList"></div>
                    <button type="button" class="gp-add" id="gpAdd">＋ 묶음 추가</button>
                    <div class="gp-etc">
                        <div class="gp-etc-hd"><b>묶음에 안 넣은 섹션</b><span>고객 화면에선 맨 아래</span><input type="text" id="gpEtc" maxlength="20" placeholder="기타"><span>묶음으로 들어가요</span></div>
                        <div class="gp-chips" id="gpPool" data-g=""></div>
                    </div>
                    <div class="gp-foot"><span id="gpState">칩을 끌어 다른 묶음에 놓거나, 눌러서 묶음을 고르세요</span><button type="button" class="gp-undo" id="gpUndo" disabled>되돌리기</button><button type="button" class="gp-save" id="gpSave" disabled>묶음 저장</button></div>
                </div>
                <aside class="gp-pv">
                    <h4>미리보기 · 고객 섹션 목록 (S10)</h4>
                    <div class="gp-scr"><div class="gp-pv-tl" id="gpPv"></div></div>
                    <p>숨김(비노출) 섹션은 빠지고, 고객이 꺼 둔 섹션은 실제로는 아래 "대기 중" 칸에 있어요.</p>
                </aside>
            </div>
        </div>

        <div class="so-tab" data-so-pane="order">
        <div class="so-help">
            <div><i>⠿</i><span><b>끌거나 ▲▼로 옮기고 저장</b>새 청첩장을 만들거나 디자인을 고르면 이 순서로 놓여요</span></div>
            <div><i class="sw"></i><span><b>스위치를 끄면 비노출</b>고객 에디터 목록과 청첩장에서 모두 빠져요</span></div>
            <div><i class="st">적</i><span><b>새 청첩장: 디자인 · 적용 · 대기</b>처음부터 넣을지(적용), 섹션 창 아래 ‘대기 중’에 둘지(대기) 정해요. ‘디자인’은 디자인마다 정해진 대로</span></div>
            <div><i class="nw">N</i><span><b>이미 만든 청첩장은 그대로</b>앞으로 생기는 섹션은 <span class="so-tag new">새 섹션</span>으로 나타나요</span></div>
        </div>
        <div class="so-bar">
        <?php if ($saved): ?>
            <div class="so-state custom">● 관리자가 정한 순서 사용 중 · <?= $h($savedAt) ?> 저장</div>
        <?php else: ?>
            <div class="so-state">● 디자인 기본 순서 사용 중 (아직 정한 순서 없음)</div>
        <?php endif; ?>
            <button type="button" class="so-reset" id="soReset"<?= $saved ? '' : ' disabled' ?>>디자인 기본 순서로 되돌리기</button>
        </div>

        <div class="so-grid">
            <div>
                <ul class="so-list" id="soList"><li class="so-load">섹션 목록을 불러오는 중…</li></ul>
                <div class="so-warn" id="soWarn">첫 화면 섹션(메인 영상 / 메인 사진)은 맨 위에 두는 걸 권장해요. 둘 중 디자인에 맞는 하나만 켜져요.</div>
                <div class="so-s10" id="soS10"><span id="soS10Txt"></span><button type="button" id="soS10Fix">묶음 순서대로 정리</button></div>
            </div>
            <aside class="so-pv">
                <h4>미리보기 · 청첩장 위에서 아래로</h4>
                <div class="so-phone"><div class="so-scr"><div class="in" id="soPv"></div></div></div>
                <p>디자인에서 꺼져 있는 섹션은 실제 화면에선 안 보여요.<br>(순서만 이대로 정해져요)</p>
            </aside>
        </div>
        </div>
    </div>

    <div class="savebar" id="saveBar"><span id="saveCnt">순서를 바꿨어요</span><button type="button" class="undo" id="undoAll">되돌리기</button><button type="button" class="save" id="saveAll">저장</button></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.2/Sortable.min.js"></script>
<script src="assets/ld-dialog.js"></script>
<script src="assets/invite-blocks.js"></script>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>;
    const POPUP_THEME = <?= json_encode($popupTheme, JSON_UNESCAPED_UNICODE) ?>;
    const SAVED = <?= json_encode($saved, JSON_UNESCAPED_UNICODE) ?>;
    const SAVED_HIDDEN = <?= json_encode($savedHidden, JSON_UNESCAPED_UNICODE) ?>;
    const SAVED_LABELS = <?= json_encode($savedLabels ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>; // 관리자가 바꾼 섹션 이름
    const SAVED_START = <?= json_encode($savedStart ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>; // 새 청첩장 시작 상태 (on 적용 / off 대기)
    let hidden = new Set(SAVED_HIDDEN); // 비노출로 끈 섹션
    const readonly = !!document.getElementById('admRo');
    const toast = m => window.LD ? LD.toast(m) : null;
    const say = m => window.LD ? LD.alert(m) : alert(m);
    const list = document.getElementById('soList'), pv = document.getElementById('soPv');
    const HERO = ['heroVideo', 'hero'];

    // 탭: 섹션 순서 / 편집창 모양 (주소 #panel 로 바로 열림)
    function showTab(t) {
        document.querySelectorAll('[data-so-tab]').forEach(a => a.classList.toggle('on', a.dataset.soTab === t));
        document.querySelectorAll('[data-so-pane]').forEach(p => { p.hidden = p.dataset.soPane !== t; });
        document.body.dataset.soTab = t; // 섹션 순서 저장 막대는 섹션 순서 탭에서만
    }
    document.querySelectorAll('[data-so-tab]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); history.replaceState(null, '', '#' + a.dataset.soTab); showTab(a.dataset.soTab); }));
    showTab(['#panel', '#groups', '#easy'].includes(location.hash) ? location.hash.slice(1) : 'order');
    // 편집창 모양: 고르면 바로 저장
    const pzCards = document.getElementById('pzCards');
    let pzNow = (pzCards.querySelector('input:checked') || {}).value || 'C';
    if (readonly) pzCards.querySelectorAll('input').forEach(i => { i.disabled = true; });
    pzCards.addEventListener('change', e => {
        const inp = e.target.closest('input[name=pz]'); if (!inp || readonly) return;
        const card = inp.closest('.pz-card'); card.classList.add('busy');
        post({ act: 'panel', panel: inp.value }).then(() => { pzNow = inp.value; toast('편집창 모양을 바꿨어요. 고객이 에디터를 새로 열면 적용돼요'); })
            .catch(err => { const prev = pzCards.querySelector(`input[value="${pzNow}"]`); if (prev) prev.checked = true; say(err.message); })
            .finally(() => card.classList.remove('busy'));
    });
    // 세부 모양: 누르면 바로 저장
    const subs = document.getElementById('pzSubs');
    if (readonly) subs.querySelectorAll('button').forEach(b => { b.disabled = true; });
    subs.addEventListener('click', e => {
        const b = e.target.closest('[data-pref] button'); if (!b || readonly || b.classList.contains('on')) return;
        const row = b.closest('[data-pref]'), prev = row.querySelector('.on');
        row.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
        post({ act: 'pref', key: row.dataset.pref, val: b.dataset.v }).then(() => toast('바꿨어요. 고객이 에디터를 새로 열면 적용돼요'))
            .catch(err => { row.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === prev)); say(err.message); });
    });
    // 편집창 바탕 (색 + 무늬): 고르면 바로 저장 (색을 끌며 고를 땐 멈춘 뒤 저장)
    const PT_COLORS = [['#FBF8F2', '크림 (기본)'], ['#FFFFFF', '화이트'], ['#F6EFE4', '샌드'], ['#FDF3F4', '블러시'], ['#F1F6F1', '세이지'], ['#F0F5FB', '스카이'], ['#F5F2FB', '라벤더']];
    const PT_TEX = [['dot', '도트'], ['plain', '없음'], ['linen', '린넨'], ['grid', '모눈'], ['paper', '종이결']];
    const theme = { bg: String(POPUP_THEME.bg || '#FBF8F2').toUpperCase(), tex: POPUP_THEME.tex || 'dot' };
    let themeTimer = null;
    const escT = v => String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function renderTheme() {
        const bg = theme.bg, isPreset = PT_COLORS.some(c => c[0] === bg), dis = readonly ? ' disabled' : '';
        document.getElementById('pzSws').innerHTML = PT_COLORS.map(([c, n]) => `<button type="button" data-ptc="${c}" class="${c === bg ? 'on' : ''}" style="background:${c}" title="${escT(n)}"${dis}></button>`).join('')
            + `<label class="${isPreset ? '' : 'on'}" style="--c:${isPreset ? 'transparent' : bg}" title="직접 고르기"><input type="color" value="${bg.toLowerCase()}"${dis}></label>`;
        document.getElementById('pzTex').innerHTML = PT_TEX.map(([k, n]) => `<button type="button" data-ptt="${k}" class="${k === theme.tex ? 'on' : ''}"${dis}><i class="ldp-${k}" style="--pbg:${bg}"></i>${escT(n)}</button>`).join('');
        pzCards.style.setProperty('--pbg', bg);
        pzCards.querySelectorAll('.pz-ill').forEach(el => { el.className = el.className.replace(/\bldp-\w+/g, '').trim() + ' ldp-' + theme.tex; });
        const m = bg.match(/^#(..)(..)(..)$/), lum = m ? (0.299 * parseInt(m[1], 16) + 0.587 * parseInt(m[2], 16) + 0.114 * parseInt(m[3], 16)) / 255 : 1;
        document.getElementById('pzBgWarn').hidden = lum > 0.62;
    }
    function saveTheme(delay) {
        clearTimeout(themeTimer);
        const st = document.getElementById('pzBgState');
        themeTimer = setTimeout(() => {
            st.textContent = '저장 중…';
            post({ act: 'theme', bg: theme.bg, tex: theme.tex }).then(() => { st.textContent = '✓ 저장했어요 · 고객이 에디터를 새로 열면 적용돼요'; })
                .catch(err => { st.textContent = ''; say(err.message); });
        }, delay || 0);
    }
    document.getElementById('pzBg').addEventListener('click', e => {
        if (readonly) return;
        const c = e.target.closest('[data-ptc]'), t = e.target.closest('[data-ptt]');
        if (c) { theme.bg = c.dataset.ptc; renderTheme(); saveTheme(); }
        if (t) { theme.tex = t.dataset.ptt; renderTheme(); saveTheme(); }
    });
    document.getElementById('pzBg').addEventListener('input', e => {
        if (readonly || !e.target.matches('input[type=color]')) return;
        theme.bg = e.target.value.toUpperCase();
        pzCards.style.setProperty('--pbg', theme.bg);
        const lab = e.target.closest('label'); lab.classList.add('on'); lab.style.setProperty('--c', theme.bg);
        document.querySelectorAll('#pzSws button').forEach(b => b.classList.remove('on'));
        document.querySelectorAll('#pzTex i').forEach(i => i.style.setProperty('--pbg', theme.bg));
        saveTheme(600);
    });
    renderTheme();

    // ===== 편집창 모양 탭: 실시간 미리보기 =====
    //  옵션에 마우스를 올리거나(미리 보기만) 누르면(저장) 오른쪽 작은 에디터가 그 모양으로 바로 바뀜
    const apv = { ready: false, loaded: false, focus: 'panel', pending: null, t: 0 };
    const apvFrame = document.getElementById('apvFrame'), apvBox = document.getElementById('apvBox');
    const APV_NAMES = Object.assign({ panel: '편집창 모양' }, <?= json_encode(array_map(fn($r) => $r[0], $prefRows), JSON_UNESCAPED_UNICODE) ?>);
    const curPrefs = () => { const o = {}; subs.querySelectorAll('[data-pref]').forEach(r => { const b = r.querySelector('button.on'); if (b) o[r.dataset.pref] = b.dataset.v; }); return o; };
    const curPanel = () => (pzCards.querySelector('input[name=pz]:checked') || {}).value || 'C';
    function apvSend(focus, over, label) {
        if (focus) apv.focus = focus;
        if (!apv.ready) { apv.pending = [focus, over, label]; apvLoad(); return; }
        apvBox.classList.toggle('pc', apv.focus === 'menuStylePc'); // PC 메뉴는 넓은 화면으로
        const prefs = Object.assign(curPrefs(), (over && over.prefs) || {});
        if (apv.focus === 's10Add') prefs.sheetStyle = 'S10'; // S10에서만 쓰는 옵션이라 미리보기는 S10으로
        if (apv.focus === 'setInner' && !['D3', 'A3', 'A7', 'A8'].includes(prefs.setStyle)) prefs.setStyle = 'A8'; // 목록형 창에서만 쓰는 옵션
        const panel = (over && over.panel) || curPanel();
        apvFrame.contentWindow.postMessage({ type: 'ld-apv', prefs, panel, theme: { bg: theme.bg, tex: theme.tex }, focus: apv.focus }, location.origin);
        const what = apv.focus === 'panel' ? (pzCards.querySelector(`input[value="${panel}"]`)?.closest('.pz-card')?.querySelector('.pz-name')?.childNodes[0]?.textContent || panel)
            : (label || subs.querySelector(`[data-pref="${apv.focus}"] button.on`)?.textContent || '');
        document.getElementById('apvWhat').textContent = (APV_NAMES[apv.focus] || '') + ' · ' + what.trim();
    }
    function apvLoad() { if (apv.loaded) return; apv.loaded = true; apvFrame.src = 'editor-prototype-v3-overlay.html?apv=1&_=' + Date.now(); }
    window.addEventListener('message', e => {
        if (e.origin !== location.origin || !e.data || e.data.type !== 'ld-apv-ready') return;
        apv.ready = true; document.getElementById('apvLoad').classList.add('done');
        const p = apv.pending; apv.pending = null; p ? apvSend(p[0], p[1], p[2]) : apvSend();
    });
    const markPv = el => { document.querySelectorAll('.pv, .pv-row').forEach(x => x.classList.remove('pv', 'pv-row')); if (el) { el.classList.add('pv'); el.closest('.pz-sub')?.classList.add('pv-row'); } };
    // 세부 모양: 올리면 미리보기, 벗어나면 지금 값으로
    subs.addEventListener('mouseover', e => {
        const b = e.target.closest('[data-pref] button'); if (!b) return;
        const k = b.closest('[data-pref]').dataset.pref;
        clearTimeout(apv.t); apv.t = setTimeout(() => { markPv(b); apvSend(k, { prefs: { [k]: b.dataset.v } }, b.textContent); }, 110);
    });
    subs.addEventListener('mouseleave', () => { clearTimeout(apv.t); apv.t = setTimeout(() => { markPv(null); apvSend(); }, 200); });
    subs.addEventListener('click', e => { // 이름을 누르거나 옵션을 눌러 저장한 뒤에도 그 칸을 보여줌
        const row = e.target.closest('.pz-sub'); if (!row) return;
        const seg = row.querySelector('[data-pref]'), b = e.target.closest('[data-pref] button');
        setTimeout(() => { markPv(null); apvSend(seg.dataset.pref, b ? { prefs: { [seg.dataset.pref]: b.dataset.v } } : null, b ? b.textContent : ''); }, 0);
    });
    // 편집창 모양 카드
    pzCards.addEventListener('mouseover', e => {
        const card = e.target.closest('.pz-card'); if (!card) return;
        const v = card.querySelector('input').value;
        clearTimeout(apv.t); apv.t = setTimeout(() => { markPv(card); apvSend('panel', { panel: v }); }, 110);
    });
    pzCards.addEventListener('mouseleave', () => { clearTimeout(apv.t); apv.t = setTimeout(() => { markPv(null); apvSend(); }, 200); });
    pzCards.addEventListener('change', () => setTimeout(() => apvSend('panel'), 0));
    document.getElementById('pzBg').addEventListener('click', () => setTimeout(() => apv.ready && apvSend(), 0));
    document.getElementById('pzBg').addEventListener('input', () => { clearTimeout(apv.t); apv.t = setTimeout(() => apv.ready && apvSend(), 250); });
    // 휴대폰: 아래에서 올라오는 미리보기 창
    document.getElementById('apvFab').addEventListener('click', () => { apvBox.classList.add('open'); apvLoad(); });
    document.getElementById('apvX').addEventListener('click', () => apvBox.classList.remove('open'));
    const onPanel = () => { const on = !document.getElementById('soTabPanel').hidden; document.body.classList.toggle('on-panel', on); if (on && window.innerWidth > 1000) apvLoad(); };
    document.querySelectorAll('[data-so-tab]').forEach(a => a.addEventListener('click', () => setTimeout(onPanel, 0)));
    onPanel();

    if (!window.InviteBlocks || !InviteBlocks.sectionCatalog) {
        list.innerHTML = '<li class="so-load">섹션 목록(assets/invite-blocks.js)을 불러오지 못했어요. 최신 파일을 올렸는지 확인해주세요.</li>';
        return;
    }
    // 섹션 목록은 에디터와 같은 파일에서 자동으로 읽는다 → 새 섹션이 생기면 여기도 자동으로 추가됨
    const CAT = InviteBlocks.sectionCatalog();
    const info = {}; CAT.forEach(s => { info[s.id] = Object.assign({}, s, { orig: s.label }); });
    // 섹션 이름: 바꾼 것만 labels에 (빈 칸·원래 이름이면 지움)
    let labels = {};
    Object.keys(SAVED_LABELS).forEach(id => { if (info[id]) labels[id] = SAVED_LABELS[id]; });
    const SAVED_LBL = JSON.stringify(labels);
    const applyLabels = () => Object.values(info).forEach(s => { s.label = labels[s.id] || s.orig; });
    applyLabels();
    const lblKey = () => JSON.stringify(Object.keys(labels).sort().map(k => [k, labels[k]]));
    let LBL_START = lblKey(); // 이름은 바꾸는 즉시 저장 (저장 버튼을 안 눌러 에디터에 안 바뀌던 문제)
    // 새 청첩장 시작 상태: 바꾼 것만 start에 (on 적용 / off 대기). 첫 화면은 디자인이 정함
    let start = {};
    Object.keys(SAVED_START).forEach(id => { if (info[id] && !HERO.includes(id)) start[id] = SAVED_START[id]; });
    const SAVED_ST = JSON.stringify(start);
    const stKey = () => JSON.stringify(Object.keys(start).sort().map(k => [k, start[k]]));
    const ST_START = stKey();
    const stHtml = id => HERO.includes(id) ? '<span class="so-st fixed" title="메인 영상·메인 사진은 디자인마다 정해져요">디자인이 정함</span>'
        : `<span class="so-st" role="group" aria-label="새 청첩장 시작 상태">${[['', '디자인', '디자인마다 정해진 대로'], ['on', '적용', '새 청첩장에 처음부터 넣기'], ['off', '대기', '섹션 창 아래 대기 중에 두기']].map(([v, l, t]) => `<button type="button" data-st="${v}" class="${(start[id] || '') === v ? 'on' : ''}" title="${t}"${readonly ? ' disabled' : ''}>${l}</button>`).join('')}</span>`;
    const catIds = CAT.map(s => s.id);

    // 저장된 순서 + 새로 생긴 섹션은 개발할 때 넣은 자리(바로 앞 섹션 뒤)에 끼워 넣음 - 에디터와 같은 규칙
    const shareLast = ids => ids.filter(id => id !== 'share').concat(ids.includes('share') ? ['share'] : []); // 공유하기는 늘 맨 아래
    function merge(saved) { return shareLast(merge0(saved)); }
    function merge0(saved) {
        if (!saved.length) return catIds.slice();
        const known = saved.filter(id => info[id]);
        let last = -1, sub = 0;
        const keyed = catIds.map((id, i) => {
            const r = known.indexOf(id);
            if (r >= 0) { last = r; sub = 0; return [id, r, 0, i]; }
            return [id, last, ++sub, i];
        });
        return keyed.sort((a, c) => (a[1] - c[1]) || (a[2] - c[2]) || (a[3] - c[3])).map(x => x[0]);
    }
    const isNew = id => SAVED.length > 0 && !SAVED.includes(id);
    const START = merge(SAVED);
    let order = START.slice();
    // 섹션 목록 창이 S10(그룹 섹션)이면 고객 에디터는 "섹션 묶음" 순서대로 섹션을 다시 놓는다 (묶음 → 묶음 안에서는 이 순서)
    const IS_S10 = <?= json_encode(($prefs['sheetStyle'] ?? '') === 'S10') ?>;
    function s10Sort(ids) {
        const G = (typeof gp !== 'undefined' && gp ? gp.groups : <?= json_encode($groupsNow['groups'] ?? [], JSON_UNESCAPED_UNICODE) ?>);
        const gi = id => { if (HERO.includes(id)) return -1; if (id === 'share') return G.length + 1; const i = G.findIndex(g => g.ids.includes(id)); return i < 0 ? G.length : i; };
        return ids.map((id, i) => [id, gi(id), i]).sort((a, c) => (a[1] - c[1]) || (a[2] - c[2])).map(x => x[0]);
    }
    function s10Bar() {
        const el = document.getElementById('soS10'); if (!el) return;
        el.classList.toggle('on', IS_S10); if (!IS_S10) return;
        const same = s10Sort(order).join(',') === order.join(',');
        el.classList.toggle('ok', same);
        document.getElementById('soS10Txt').textContent = same
            ? '✓ 섹션 묶음 순서와 같아요. 고객 에디터(S10 그룹 섹션)에서도 이 순서 그대로 보여요.'
            : '섹션 목록 창이 S10(그룹 섹션)이라 고객 에디터에서는 "섹션 묶음" 순서가 먼저예요. 이 순서와 달라서 새 청첩장에서 다르게 보일 수 있어요.';
        document.getElementById('soS10Fix').hidden = same || readonly;
    }

    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const grip = '<svg width="14" height="20" viewBox="0 0 14 20" fill="currentColor"><circle cx="4" cy="4" r="1.6"/><circle cx="10" cy="4" r="1.6"/><circle cx="4" cy="10" r="1.6"/><circle cx="10" cy="10" r="1.6"/><circle cx="4" cy="16" r="1.6"/><circle cx="10" cy="16" r="1.6"/></svg>';
    const up = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 15l6-6 6 6"/></svg>';
    const dn = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';

    function rowHtml(id) {
        const s = info[id];
        return `<li class="so-row${hidden.has(id) ? ' off' : ''}" data-id="${esc(id)}">
            <span class="so-h" title="끌어서 옮기기">${grip}</span>
            <span class="so-no"></span>
            <span class="so-dot" style="background:${esc(s.color)}"></span>
            <span class="so-nm"><b>${esc(s.label)}</b>${readonly ? '' : `<button type="button" class="so-ren" data-ren title="이름 바꾸기" aria-label="이름 바꾸기"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg></button>`}${labels[id] ? `<span class="so-tag ren" data-ren-reset title="누르면 원래 이름으로">원래: ${esc(s.orig)}</span>` : ''}<code>${esc(id)}</code>${HERO.includes(id) ? '<span class="so-tag hero">첫 화면</span>' : ''}${isNew(id) ? '<span class="so-tag new">새 섹션</span>' : ''}</span>
            ${stHtml(id)}
            <label class="so-sw" title="끄면 고객 에디터와 청첩장에서 이 섹션이 안 보여요"><input type="checkbox" data-vis ${hidden.has(id) ? '' : 'checked'}><i></i><span>${hidden.has(id) ? '숨김' : '노출'}</span></label>
            <span class="so-mv"><button type="button" data-mv="-1" title="위로">${up}</button><button type="button" data-mv="1" title="아래로">${dn}</button></span>
        </li>`;
    }
    function build() {
        list.innerHTML = order.map(rowHtml).join('');
        if (readonly) list.querySelectorAll('button').forEach(b => { b.disabled = true; });
        refresh();
    }
    // 번호·버튼·미리보기·저장 막대 갱신
    function refresh(bumpId) {
        { const sh = list.querySelector('.so-row[data-id="share"]'); if (sh && sh !== list.lastElementChild) list.appendChild(sh); } // 공유하기는 늘 맨 아래
        order = [...list.children].map(li => li.dataset.id);
        [...list.children].forEach((li, i) => {
            li.querySelector('.so-no').textContent = i + 1;
            if (!readonly) {
                li.querySelector('[data-mv="-1"]').disabled = i === 0;
                li.querySelector('[data-mv="1"]').disabled = i === order.length - 1;
            }
        });
        pv.innerHTML = order.map(id => {
            const s = info[id], hero = HERO.includes(id);
            const bg = hero ? `linear-gradient(160deg, ${s.color}, #2B2320)` : s.color + '22';
            return `<div class="so-b${hero ? ' hero' : ''}${id === bumpId ? ' bump' : ''}${hidden.has(id) ? ' off' : ''}${start[id] === 'off' ? ' wait' : ''}" style="background:${bg}"><i style="background:${hero ? '#fff' : s.color}"></i>${esc(s.label)}</div>`;
        }).join('');
        requestAnimationFrame(() => {
            const scr = pv.parentElement;
            pv.style.setProperty('--sy', Math.min(0, scr.clientHeight - pv.scrollHeight) + 'px');
        });
        const heroIdx = order.map((id, i) => HERO.includes(id) ? i : -1).filter(i => i >= 0);
        document.getElementById('soWarn').classList.toggle('on', heroIdx.some(i => i > 1));
        try { s10Bar(); } catch (e) {}
        document.getElementById('saveBar').classList.toggle('on', dirty());
        if (typeof gpRender === 'function' && gpList) gpRender();
        const nNew = order.filter(isNew).length;
        document.getElementById('saveCnt').textContent = changed() ? (order.join(',') !== START.join(',') ? '순서를 바꿨어요' : hidKey(hidden) !== hidKey(new Set(SAVED_HIDDEN)) ? '노출 설정을 바꿨어요' : stChanged() ? '새 청첩장 시작 상태를 바꿨어요' : '섹션 이름을 바꿨어요') : `새 섹션 ${nNew}개 · 이 자리로 정할까요?`;
    }
    const hidKey = set => [...set].sort().join(',');
    const lblChanged = () => lblKey() !== LBL_START;
    function saveLabels() {
        const key = lblKey();
        post({ act: 'labels', labels: JSON.stringify(labels) }).then(() => { LBL_START = key; toast('섹션 이름을 저장했어요. 고객 에디터에 바로 바뀌어 보여요'); try { refresh(); } catch (e) {} }).catch(err => say(err.message));
    }
    const stChanged = () => stKey() !== ST_START;
    const changed = () => order.join(',') !== START.join(',') || hidKey(hidden) !== hidKey(new Set(SAVED_HIDDEN)) || lblChanged() || stChanged();
    const dirty = () => changed() || (SAVED.length > 0 && order.some(isNew));

    // 부드럽게 자리 바꾸기 (FLIP)
    function animateMove(fn) {
        const rows = [...list.children], before = new Map(rows.map(r => [r, r.getBoundingClientRect().top]));
        fn();
        rows.forEach(r => {
            if (r.classList.contains('dragging')) return;
            const d = before.get(r) - r.getBoundingClientRect().top;
            if (!d) return;
            r.style.transition = 'none'; r.style.transform = `translateY(${d}px)`;
            requestAnimationFrame(() => { r.style.transition = 'transform .18s ease'; r.style.transform = ''; });
        });
    }

    // ▲▼ 버튼
    list.addEventListener('click', e => {
        const b = e.target.closest('[data-mv]'); if (!b || readonly) return;
        const li = b.closest('.so-row'), dir = +b.dataset.mv;
        const sib = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
        if (!sib) return;
        animateMove(() => list.insertBefore(li, dir < 0 ? sib : sib.nextElementSibling));
        li.classList.remove('flash'); void li.offsetWidth; li.classList.add('flash');
        refresh(li.dataset.id);
    });

    // 손잡이 끌기 (마우스·터치 공통)
    let drag = null;
    list.addEventListener('pointerdown', e => {
        const h = e.target.closest('.so-h'); if (!h || readonly) return;
        e.preventDefault();
        const li = h.closest('.so-row');
        h.setPointerCapture(e.pointerId);
        drag = { li, grab: e.clientY - li.getBoundingClientRect().top, t: 0 };
        li.classList.add('dragging');
    });
    list.addEventListener('pointermove', e => {
        if (!drag) return;
        const { li } = drag, y = e.clientY;
        // 다른 줄의 가운데를 넘으면 자리를 바꾸고, 끌고 있는 줄은 손가락을 그대로 따라온다
        let target = null;
        for (const r of list.children) {
            if (r === li) continue;
            const rc = r.getBoundingClientRect();
            if (y < rc.top + rc.height / 2) { target = r; break; }
        }
        if (target !== li.nextElementSibling) animateMove(() => list.insertBefore(li, target));
        const natural = li.getBoundingClientRect().top - drag.t;
        drag.t = (y - drag.grab) - natural;
        li.style.transition = 'none';
        li.style.transform = `translateY(${drag.t}px)`;
        if (y < 70) window.scrollBy(0, -8); else if (y > innerHeight - 90) window.scrollBy(0, 8); // 화면 끝 근처면 자동 스크롤
    });
    function endDrag() {
        if (!drag) return;
        const { li } = drag; drag = null;
        li.style.transition = 'transform .16s ease'; li.style.transform = '';
        setTimeout(() => { li.classList.remove('dragging'); li.style.transition = ''; }, 170);
        refresh(li.dataset.id);
    }
    list.addEventListener('pointerup', endDrag);
    list.addEventListener('pointercancel', endDrag);

    document.getElementById('undoAll').addEventListener('click', () => { order = START.slice(); hidden = new Set(SAVED_HIDDEN); labels = JSON.parse(SAVED_LBL); applyLabels(); start = JSON.parse(SAVED_ST); build(); });
    // 새 청첩장 시작 상태 (디자인 / 적용 / 대기)
    list.addEventListener('click', e => {
        const b = e.target.closest('.so-st [data-st]'); if (!b || readonly) return;
        const id = b.closest('.so-row').dataset.id;
        if (b.dataset.st) start[id] = b.dataset.st; else delete start[id];
        b.parentElement.querySelectorAll('[data-st]').forEach(x => x.classList.toggle('on', x === b));
        refresh(id);
    });
    // ✎ 섹션 이름 바꾸기 - 그 자리에서 입력 → Enter/바깥 누르기로 확정, Esc 취소. 비우면 원래 이름
    function renRow(id) { const li = list.querySelector(`.so-row[data-id="${CSS.escape(id)}"]`); if (li) { li.outerHTML = rowHtml(id); refresh(id); } }
    list.addEventListener('click', e => {
        if (readonly) return;
        const rs = e.target.closest('[data-ren-reset]');
        if (rs) { const id = rs.closest('.so-row').dataset.id; delete labels[id]; applyLabels(); renRow(id); saveLabels(); return; }
        const b = e.target.closest('[data-ren]'); if (!b) return;
        const li = b.closest('.so-row'), id = li.dataset.id, s = info[id];
        const nm = li.querySelector('.so-nm');
        nm.innerHTML = `<input type="text" class="so-in" maxlength="20" value="${esc(s.label)}" aria-label="섹션 이름"><small class="so-in-h">원래: ${esc(s.orig)} · 비우면 원래 이름</small>`;
        const inp = nm.querySelector('input');
        inp.focus(); inp.select();
        let done = false;
        const commit = keep => {
            if (done) return; done = true;
            if (keep) {
                const v = inp.value.replace(/\s+/g, ' ').trim().slice(0, 20);
                const before = labels[id] || '';
                if (!v || v === s.orig) delete labels[id]; else labels[id] = v;
                applyLabels();
                if ((labels[id] || '') !== before) saveLabels();
            }
            renRow(id);
        };
        inp.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); commit(true); } else if (ev.key === 'Escape') { ev.preventDefault(); commit(false); } });
        inp.addEventListener('blur', () => commit(true));
    });
    // 노출 스위치
    list.addEventListener('change', e => {
        const cb = e.target.closest('[data-vis]'); if (!cb || readonly) return;
        const li = cb.closest('.so-row'), id = li.dataset.id;
        if (cb.checked) hidden.delete(id); else hidden.add(id);
        li.classList.toggle('off', !cb.checked);
        li.querySelector('.so-sw span').textContent = cb.checked ? '노출' : '숨김';
        refresh(id);
    });

    const bar = document.getElementById('saveBar');
    function post(data) {
        const fd = new FormData(); fd.set('csrf_token', CSRF); fd.set('ajax', '1');
        Object.keys(data).forEach(k => fd.set(k, data[k]));
        return fetch('admin_sections.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error || '저장하지 못했어요.'); return j; });
    }
    document.getElementById('saveAll').addEventListener('click', () => {
        bar.classList.add('busy');
        post({ act: 'save', order: order.join(','), hidden: [...hidden].join(','), labels: JSON.stringify(labels), start: JSON.stringify(start) }).then(() => {
            guard.release(); toast('저장했어요. 이제 새로 만드는 청첩장은 이 순서로 시작해요');
            setTimeout(() => location.reload(), 700);
        }).catch(e => { bar.classList.remove('busy'); say(e.message); });
    });
    document.getElementById('soReset').addEventListener('click', () => {
        const go = () => post({ act: 'reset' }).then(() => {
            guard.release(); toast('디자인 기본 순서로 되돌렸어요');
            setTimeout(() => location.reload(), 700);
        }).catch(e => say(e.message));
        if (window.LD) LD.confirm('저장한 순서를 지우고 디자인마다 정해진 기본 순서를 쓸까요?').then(ok => { if (ok) go(); });
        else if (confirm('저장한 순서를 지울까요?')) go();
    });

    // ===== 섹션 묶음 탭 (S10 그룹 섹션) =====
    //  묶음 = {k: 키, n: 이름, ic: 아이콘, ids: [섹션 id]} - 칩을 끌거나 눌러서 묶음을 옮기고 "묶음 저장"
    const GP_SAVED = <?= json_encode($groupsNow, JSON_UNESCAPED_UNICODE) ?>;
    const GP_DEFAULT = <?= json_encode(['groups' => SECTION_GROUP_DEFAULTS, 'etc' => SECTION_GROUP_ETC_DEFAULT], JSON_UNESCAPED_UNICODE) ?>;
    const GP_MAX = <?= (int) SECTION_GROUP_MAX ?>;
    const GP_IC = {
        play: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9.5v5l4-2.5z"/>',
        photo: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="9" cy="10" r="1.8"/><path d="M21 16l-5-5-8 9"/>',
        cal: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
        pin: '<path d="M12 21s-6-5.5-6-11a6 6 0 0 1 12 0c0 5.5-6 11-6 11z"/><circle cx="12" cy="10" r="2.2"/>',
        note: '<path d="M6 3.5h9l3 3v14H6z"/><path d="M9 11h6M9 15h6"/>',
        mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6l8.5 7 8.5-7"/>',
        chat: '<path d="M5 5h14v10H9l-4 4z"/>',
        share: '<circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="18" cy="18" r="2.5"/><path d="M8.2 10.8l7.6-3.6M8.2 13.2l7.6 3.6"/>',
        grid4: '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        camera: '<path d="M4 8.5h3l1.5-2h7L17 8.5h3V18H4z"/><circle cx="12" cy="13" r="3.2"/>',
        people: '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><circle cx="17" cy="9.5" r="2.4"/><path d="M15.5 14.2A4.5 4.5 0 0 1 21 18.5"/>',
        heart: '<path d="M12 20s-7-4.4-7-9.4A4 4 0 0 1 12 8a4 4 0 0 1 7 2.6C19 15.6 12 20 12 20z"/>',
        gift: '<rect x="4" y="9" width="16" height="11" rx="1.5"/><path d="M3 9h18M12 9v11M12 9S10 4 7.5 5.5 9 9 12 9zm0 0s2-5 4.5-3.5S15 9 12 9z"/>',
        phone: '<path d="M6 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6.5 6.5l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4 5.5a2 2 0 0 1 2-2z"/>',
        plane: '<path d="M10.5 13.5L3 11l1.5-1.5 8 1L17 6a2 2 0 0 1 3 3l-4.5 4.5 1 8L15 23l-2.5-7.5"/>',
        wallet: '<rect x="3" y="6" width="18" height="13" rx="2.5"/><path d="M16 12.5h2"/><path d="M5 6l10-2.5 1 2.5"/>',
        dot: '<circle cx="12" cy="12" r="3.5"/>',
    };
    const gpSvg = k => `<svg viewBox="0 0 24 24" aria-hidden="true">${GP_IC[k] || GP_IC.dot}</svg>`;
    const gpCopy = src => ({ groups: src.groups.map(g => ({ k: g.k, n: g.n, ic: g.ic, ids: g.ids.filter(id => info[id]) })), etc: src.etc || '기타' });
    let gp = gpCopy(GP_SAVED);
    const gpKey = () => JSON.stringify([gp.groups.map(g => [g.k, g.n.trim(), g.ic, g.ids.slice().sort()]), (gp.etc || '').trim() || '기타']);
    let gpStart = gpKey();
    const gpList = document.getElementById('gpList'), gpPool = document.getElementById('gpPool'), gpEtc = document.getElementById('gpEtc'), gpPv = document.getElementById('gpPv');
    const gpOf = id => (gp.groups.find(g => g.ids.includes(id)) || {}).k || '';
    const gpSort = ids => ids.slice().sort((a, b) => order.indexOf(a) - order.indexOf(b)); // 묶음 안 순서 = 섹션 순서 탭 순서
    const gpDirty = () => gpKey() !== gpStart;
    const GP_HERO = ['heroVideo', 'hero']; // 첫 화면은 항상 맨 위 "첫 화면" 묶음에 고정 (서버 section_groups_pin_first와 같은 규칙)
    gp.groups.forEach(g => { g.ids = g.ids.filter(id => id !== 'share'); }); gpStart = gpKey(); // 공유하기는 늘 "묶음에 안 넣은 섹션"(기타) 맨 아래
    function gpChip(id, inGroup) {
        const s = info[id]; if (!s) return '';
        if (id === 'share') return `<span class="gp-chip lock" data-id="share" title="공유하기는 항상 맨 아래(기타 묶음 끝)에 자동으로 놓여요"><i style="background:${esc(s.color)}"></i>${esc(s.label)}<em>맨 아래 고정</em></span>`;
        if (GP_HERO.includes(id)) return `<span class="gp-chip lock" data-id="${esc(id)}" title="메인 영상·메인 사진은 항상 맨 위 첫 화면 묶음에 고정돼요"><i style="background:${esc(s.color)}"></i>${esc(s.label)}<em>고정</em></span>`;
        return `<button type="button" class="gp-chip${hidden.has(id) ? ' off' : ''}" draggable="${readonly ? 'false' : 'true'}" data-id="${esc(id)}" title="${hidden.has(id) ? '숨김(비노출) 섹션 · ' : ''}끌어서 옮기거나 눌러서 묶음 고르기"><i style="background:${esc(s.color)}"></i>${esc(s.label)}${hidden.has(id) ? '<em>숨김</em>' : ''}</button>`;
    }
    function gpRender(flashK) {
        const dis = readonly ? ' disabled' : '';
        gpList.innerHTML = gp.groups.map((g, i, all) => { const pin = g.k === 'first', below = i === 1 && all[0].k === 'first'; return `<div class="gp-card${g.k === flashK ? ' flash' : ''}" data-g="${esc(g.k)}">
            <div class="gp-hd">
                <button type="button" class="gp-ic" data-gic title="아이콘 바꾸기"${dis}>${gpSvg(g.ic)}</button>
                <input type="text" class="gp-nm" value="${esc(g.n)}" maxlength="20" placeholder="묶음 이름"${dis}>
                <span class="gp-cnt">${g.ids.length}개</span>
                <button type="button" data-gmv="-1" title="${pin ? '첫 화면 묶음은 항상 맨 위' : '위로'}"${i === 0 || pin || below || readonly ? ' disabled' : ''}>▲</button>
                <button type="button" data-gmv="1" title="${pin ? '첫 화면 묶음은 항상 맨 위' : '아래로'}"${i === gp.groups.length - 1 || pin || readonly ? ' disabled' : ''}>▼</button>
                <button type="button" data-gdel title="${pin ? '첫 화면 묶음은 지울 수 없어요' : "묶음 지우기 (안의 섹션은 '묶음에 안 넣은 섹션'으로)"}"${gp.groups.length <= 1 || pin || readonly ? ' disabled' : ''}>✕</button>
            </div>
            <div class="gp-chips" data-g="${esc(g.k)}">${gpSort(g.ids).map(id => gpChip(id, true)).join('') || '<span class="gp-empty">여기로 섹션 칩을 끌어 놓으세요</span>'}</div>
        </div>`; }).join('');
        const pool = gpSort(catIds.filter(id => !gpOf(id) && id !== 'share')).concat(catIds.includes('share') ? ['share'] : []);
        gpPool.innerHTML = pool.map(id => gpChip(id, false)).join('') || '<span class="gp-empty">모든 섹션이 묶음에 들어가 있어요</span>';
        if (document.activeElement !== gpEtc) gpEtc.value = gp.etc;
        gpEtc.disabled = readonly;
        document.getElementById('gpAdd').disabled = readonly || gp.groups.length >= GP_MAX;
        gpPreview(); gpBar();
    }
    function gpPreview() {
        let n = 0;
        const vis = id => info[id] && !hidden.has(id);
        const part = (name, ic, ids) => {
            const rows = gpSort(ids.filter(vis));
            return `<div class="gp-pv-h">${gpSvg(ic)}${esc(name || '(이름 없음)')}<small>${rows.length}</small></div>` + (rows.map(id => `<div class="gp-pv-r" data-n="${++n}">${esc(info[id].label)}</div>`).join('') || '<div class="gp-pv-e">비어 있어요 · 고객이 ＋로 넣을 수 있어요</div>');
        };
        const etcIds = catIds.filter(id => !gpOf(id) && vis(id) && id !== 'share').concat(vis('share') ? ['share'] : []);
        gpPv.innerHTML = gp.groups.map(g => part(g.n.trim(), g.ic, g.ids)).join('') + (etcIds.length ? part((gp.etc || '').trim() || '기타', 'dot', etcIds) : '');
    }
    function gpBar() {
        const d = gpDirty(), st = document.getElementById('gpState');
        document.getElementById('gpSave').disabled = !d || readonly;
        document.getElementById('gpUndo').disabled = !d || readonly;
        st.textContent = d ? '바꾼 묶음이 아직 저장되지 않았어요' : '칩을 끌어 다른 묶음에 놓거나, 눌러서 묶음을 고르세요';
        st.classList.toggle('dirty', d);
    }
    function gpMove(id, k) { // k '' = 묶음에서 빼기
        if (GP_HERO.includes(id) || id === 'share') return; // 첫 화면·공유하기 고정
        gp.groups.forEach(g => { g.ids = g.ids.filter(x => x !== id); });
        const g = gp.groups.find(x => x.k === k); if (g) g.ids.push(id);
        gpRender(k);
    }
    // 작은 창 (아이콘 고르기 / 묶음 고르기)
    let gpPopEl = null;
    function gpPopClose() { if (gpPopEl) { gpPopEl.remove(); gpPopEl = null; } }
    function gpPop(anchor, html, onClick) {
        gpPopClose();
        const p = document.createElement('div'); p.className = 'gp-pop'; p.innerHTML = html; document.body.appendChild(p); gpPopEl = p;
        const r = anchor.getBoundingClientRect(), pw = p.offsetWidth, ph = p.offsetHeight;
        p.style.left = (scrollX + Math.max(8, Math.min(innerWidth - pw - 8, r.left))) + 'px'; // 페이지에 붙임 (스크롤해도 칩 옆에 그대로)
        p.style.top = (scrollY + (r.bottom + ph + 8 > innerHeight ? Math.max(8, r.top - ph - 6) : r.bottom + 6)) + 'px';
        p.addEventListener('click', e => { const b = e.target.closest('button'); if (b) { onClick(b); gpPopClose(); } });
    }
    document.addEventListener('click', e => { if (gpPopEl && !gpPopEl.contains(e.target) && !e.target.closest('[data-gic], .gp-chip')) gpPopClose(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') gpPopClose(); });
    document.querySelector('[data-so-pane="groups"]').addEventListener('click', e => {
        if (readonly) return;
        const card = e.target.closest('.gp-card'), k = card && card.dataset.g, gi = card ? gp.groups.findIndex(g => g.k === k) : -1;
        const ic = e.target.closest('[data-gic]'), mv = e.target.closest('[data-gmv]'), del = e.target.closest('[data-gdel]'), chip = e.target.closest('.gp-chip:not(.lock)');
        if (ic && gi >= 0) {
            gpPop(ic, `<div class="gp-icm">${Object.keys(GP_IC).map(n => `<button type="button" data-icn="${n}" class="${gp.groups[gi].ic === n ? 'on' : ''}">${gpSvg(n)}</button>`).join('')}</div>`, b => { gp.groups[gi].ic = b.dataset.icn; gpRender(); });
        } else if (mv && gi >= 0) {
            const j = gi + +mv.dataset.gmv; if (j < 0 || j >= gp.groups.length) return;
            const [m] = gp.groups.splice(gi, 1); gp.groups.splice(j, 0, m); gpRender(k);
        } else if (del && gi >= 0) {
            const g = gp.groups[gi];
            const go = () => { gp.groups.splice(gi, 1); gpRender(); };
            if (!g.ids.length) go();
            else LD.confirm(`"${g.n || '이름 없음'}" 묶음을 지울까요?`, { message: `안에 있던 섹션 ${g.ids.length}개는 "묶음에 안 넣은 섹션"으로 가요.` }).then(ok => { if (ok) go(); });
        } else if (chip) {
            const id = chip.dataset.id, cur = gpOf(id);
            gpPop(chip, `<div class="gp-gm"><small>${esc(info[id].label)} → 어느 묶음으로?</small>${gp.groups.map(g => `<button type="button" data-to="${esc(g.k)}" class="${g.k === cur ? 'on' : ''}">${gpSvg(g.ic)}${esc(g.n || '(이름 없음)')}</button>`).join('')}<hr><button type="button" data-to="" class="${cur ? '' : 'on'}">${gpSvg('dot')}묶음에서 빼기 (${esc(gp.etc || '기타')})</button></div>`, b => gpMove(id, b.dataset.to));
        }
    });
    document.getElementById('gpAdd').addEventListener('click', () => {
        if (readonly || gp.groups.length >= GP_MAX) return;
        const k = 'g' + Date.now().toString(36);
        gp.groups.push({ k, n: '새 묶음', ic: 'dot', ids: [] }); gpRender(k);
        const inp = gpList.querySelector(`.gp-card[data-g="${k}"] .gp-nm`); if (inp) { inp.focus(); inp.select(); inp.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    });
    gpList.addEventListener('input', e => {
        const inp = e.target.closest('.gp-nm'); if (!inp) return;
        const g = gp.groups.find(x => x.k === inp.closest('.gp-card').dataset.g); if (g) { g.n = inp.value; gpPreview(); gpBar(); }
    });
    gpEtc.addEventListener('input', () => { gp.etc = gpEtc.value; gpPreview(); gpBar(); });
    // 칩 끌어 옮기기 (PC 마우스)
    let gpDrag = null;
    const gpPane = document.querySelector('[data-so-pane="groups"]');
    gpPane.addEventListener('dragstart', e => { const c = e.target.closest('.gp-chip'); if (!c || readonly) return; gpDrag = c.dataset.id; c.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', gpDrag); } catch (er) {} gpPopClose(); });
    gpPane.addEventListener('dragend', () => { gpDrag = null; gpPane.querySelectorAll('.drag, .over').forEach(x => x.classList.remove('drag', 'over')); });
    gpPane.addEventListener('dragover', e => { const z = e.target.closest('.gp-chips'); if (!z || !gpDrag) return; e.preventDefault(); gpPane.querySelectorAll('.gp-chips.over').forEach(x => x !== z && x.classList.remove('over')); z.classList.add('over'); });
    gpPane.addEventListener('dragleave', e => { const z = e.target.closest('.gp-chips'); if (z && !z.contains(e.relatedTarget)) z.classList.remove('over'); });
    gpPane.addEventListener('drop', e => { const z = e.target.closest('.gp-chips'); if (!z || !gpDrag) return; e.preventDefault(); const id = gpDrag; gpDrag = null; if (gpOf(id) !== z.dataset.g) gpMove(id, z.dataset.g); else gpRender(); });
    document.getElementById('gpUndo').addEventListener('click', () => { gp = JSON.parse(JSON.stringify(gpStartObj)); gpRender(); });
    const gpStartObj = JSON.parse(JSON.stringify(gp));
    document.getElementById('gpSave').addEventListener('click', () => {
        if (gp.groups.some(g => !g.n.trim())) { say('이름이 빈 묶음이 있어요. 이름을 넣어주세요.'); return; }
        const btn = document.getElementById('gpSave'); btn.disabled = true;
        post({ act: 'groups', groups: JSON.stringify(gp.groups.map(g => ({ k: g.k, n: g.n.trim(), ic: g.ic, ids: g.ids }))), etc: (gp.etc || '').trim() }).then(() => {
            guard.release(); toast('묶음을 저장했어요. 고객이 에디터를 새로 열면 적용돼요');
            setTimeout(() => { location.hash = '#groups'; location.reload(); }, 700);
        }).catch(err => { btn.disabled = false; say(err.message); });
    });
    document.getElementById('gpReset').addEventListener('click', () => {
        LD.confirm('관리자가 정한 묶음을 지우고 기본 묶음(첫 화면·예식 안내·하객 소통·사진·영상·마무리)을 쓸까요?').then(ok => {
            if (!ok) return;
            post({ act: 'groups_reset' }).then(() => { guard.release(); toast('기본 묶음으로 되돌렸어요'); setTimeout(() => { location.hash = '#groups'; location.reload(); }, 700); }).catch(err => say(err.message));
        });
    });

    // 저장 안 하고 나가려 하면 LOVE DAY 팝업으로 확인
    const guard = LD.guardLeave(() => changed() || gpDirty() || ezDirty(), { message: '바꾼 섹션 순서·묶음이 아직 저장되지 않았어요.\n"저장"을 누르지 않고 나가면 사라져요.' });

    // ===== 손쉬운 제작 단계 =====
    const EZ_NAMES = <?= json_encode(SECTION_EASY_STEPS, JSON_UNESCAPED_UNICODE) ?>;
    const EZ_INFO = <?= json_encode($easyInfo, JSON_UNESCAPED_UNICODE) ?>;
    const EZ_SAVED = (l => { const fin = l.filter(x => x.id === 'finish'); return l.filter(x => x.id !== 'finish' && x.on).concat(l.filter(x => x.id !== 'finish' && !x.on), fin); })(<?= json_encode($easyNow, JSON_UNESCAPED_UNICODE) ?>); // (켜진 단계 → 꺼진 단계 → 마무리 순으로 정리)
    let ez = JSON.parse(JSON.stringify(EZ_SAVED)), ezSel = 'names';
    const EZ_OPTS = <?= json_encode(SECTION_EASY_OPTS, JSON_UNESCAPED_UNICODE) ?>; // 단계별 세부 옵션 {step: {key: 이름}}
    const EZ_OFF0 = <?= json_encode($easyOff ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>;
    let ezOff = JSON.parse(JSON.stringify(EZ_OFF0)); const ezOpen = new Set();
    const ezOffKey = o => JSON.stringify(Object.keys(o).sort().filter(k => o[k] && o[k].length).map(k => [k, o[k].slice().sort()]));
    const ezSig = a => JSON.stringify(a);
    const ezDirty = () => ezSig(ez) !== ezSig(EZ_SAVED) || ezOffKey(ezOff) !== ezOffKey(EZ_OFF0);
    const ezEsc = v => String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // 켜진 단계(위, 끌어서 순서) · 꺼진 단계(아래). ez 배열 순서 = 켜진 단계 → 꺼진 단계 → 마무리
    const ezNorm = () => { const fin = ez.find(x => x.id === 'finish') || { id: 'finish', on: true }; ez = ez.filter(x => x.id !== 'finish' && x.on).concat(ez.filter(x => x.id !== 'finish' && !x.on)).concat([fin]); };
    function ezRow(st, i, no) {
        const [ic, what, sec] = EZ_INFO[st.id] || ['·', '', ''], lock = st.id === 'names' || st.id === 'finish';
        const onList = ez.filter(x => x.on && x.id !== 'finish'), k = onList.indexOf(st);
        return `<li class="ez-row${st.on ? '' : ' off'}${st.id === 'finish' ? ' ez-fixed' : ''}" data-id="${st.id}">
            ${st.id === 'finish' || readonly ? '<span class="ez-grip ez-grip-x"></span>' : '<span class="ez-grip" title="끌어서 순서 바꾸기">⠿</span>'}
            <span class="ez-no">${no}</span><span class="ez-ic">${ezEsc(ic)}</span>
            <span class="ez-t"><b>${ezEsc(EZ_NAMES[st.id] || st.id)}</b><small>${ezEsc(what)}</small>${sec ? `<em>섹션: ${ezEsc(sec)}</em>` : ''}</span>
            ${st.on && st.id !== 'finish' ? `<span class="ez-mv"><button type="button" data-ez-up="${st.id}"${k <= 0 || readonly ? ' disabled' : ''} aria-label="위로">▲</button><button type="button" data-ez-dn="${st.id}"${k >= onList.length - 1 || readonly ? ' disabled' : ''} aria-label="아래로">▼</button></span>` : '<span class="ez-mv"></span>'}
            ${lock ? `<span class="ez-lock">${st.id === 'names' ? '늘 켜짐' : '늘 맨 끝'}</span>` : `<label class="so-sw"><input type="checkbox" data-ez-on="${st.id}"${st.on ? ' checked' : ''}${readonly ? ' disabled' : ''}><i></i><span>${st.on ? '켜짐' : '꺼짐'}</span></label>`}
            ${ezFold(st.id)}</li>`;
    }
    // 세부 옵션 폴더: 단계 줄의 [옵션 n/m ▾]를 누르면 아래로 펼쳐지고, 옵션마다 켜고 끔
    function ezFold(id) {
        const ops = EZ_OPTS[id]; if (!ops) return '';
        const keys = Object.keys(ops), off = ezOff[id] || [], on = keys.filter(k => !off.includes(k)).length, open = ezOpen.has(id);
        return `<button type="button" class="ez-fold-btn${open ? ' open' : ''}${on < keys.length ? ' part' : ''}" data-ez-fold="${id}">옵션 <b>${on}/${keys.length}</b><i>▾</i></button>
            <div class="ez-fold"${open ? '' : ' hidden'}>${keys.map(k => `<label class="ez-opt${off.includes(k) ? ' off' : ''}"><input type="checkbox" data-ez-opt="${id}:${k}"${off.includes(k) ? '' : ' checked'}${readonly ? ' disabled' : ''}><i></i><span>${ezEsc(ops[k])}</span></label>`).join('')}
            <span class="ez-fold-act"><button type="button" data-ez-all="${id}:1">모두 켜기</button><button type="button" data-ez-all="${id}:0">모두 끄기</button></span></div>`;
    }
    function ezRender() {
        const ul = document.getElementById('ezList'), off = document.getElementById('ezOff'); if (!ul) return;
        ezNorm();
        let n = 0;
        ul.innerHTML = ez.filter(x => x.on).map((st, i) => ezRow(st, i, ++n)).join('');
        const offs = ez.filter(x => !x.on);
        off.innerHTML = offs.map((st, i) => ezRow(st, i, '–')).join('') || '<li class="ez-empty">꺼진 단계가 없어요. 위에서 스위치를 끄면 여기로 내려와요.</li>';
        const byId = id => ez.find(x => x.id === id);
        const mv = (id, d) => { const on = ez.filter(x => x.on && x.id !== 'finish'), k = on.findIndex(x => x.id === id), t = k + d; if (k < 0 || t < 0 || t >= on.length) return; [on[k], on[t]] = [on[t], on[k]]; ez = on.concat(ez.filter(x => !x.on || x.id === 'finish')); ezSel = id; ezRender(); };
        [ul, off].forEach(box => {
            box.querySelectorAll('[data-ez-up]').forEach(b => b.addEventListener('click', () => mv(b.dataset.ezUp, -1)));
            box.querySelectorAll('[data-ez-dn]').forEach(b => b.addEventListener('click', () => mv(b.dataset.ezDn, 1)));
            box.querySelectorAll('[data-ez-on]').forEach(c => c.addEventListener('change', () => {
                const st = byId(c.dataset.ezOn); st.on = c.checked; ezSel = st.id;
                ez = ez.filter(x => x !== st); // 켜면 켜진 단계 맨 끝(마무리 앞)으로, 끄면 꺼진 단계로
                const at = st.on ? ez.findIndex(x => !x.on || x.id === 'finish') : ez.length;
                ez.splice(at < 0 ? ez.length : at, 0, st); ezRender();
            }));
            box.querySelectorAll('[data-ez-fold]').forEach(b => b.addEventListener('click', () => { const id = b.dataset.ezFold; if (ezOpen.has(id)) ezOpen.delete(id); else ezOpen.add(id); ezSel = id; ezRender(); }));
            box.querySelectorAll('[data-ez-opt]').forEach(c => c.addEventListener('change', () => {
                const [id, k] = c.dataset.ezOpt.split(':'), l = new Set(ezOff[id] || []);
                if (c.checked) l.delete(k); else l.add(k);
                ezOff[id] = [...l]; if (!ezOff[id].length) delete ezOff[id]; ezSel = id; ezRender();
            }));
            box.querySelectorAll('[data-ez-all]').forEach(b => b.addEventListener('click', () => { const [id, v] = b.dataset.ezAll.split(':'); if (v === '1') delete ezOff[id]; else ezOff[id] = Object.keys(EZ_OPTS[id] || {}); ezRender(); }));
            box.querySelectorAll('.ez-row').forEach(li => li.addEventListener('click', e => { if (e.target.closest('button, label, .ez-grip, .ez-fold')) return; if (byId(li.dataset.id).on) { ezSel = li.dataset.id; ezPv(); } }));
        });
        // 끌어서 순서 바꾸기 (켜진 단계 안에서, 또는 꺼진 단계 ↔ 켜진 단계)
        if (window.Sortable && !readonly) {
            const done = () => {
                const ids = [...ul.querySelectorAll(':scope > .ez-row')].map(li => li.dataset.id).filter(id => id !== 'finish');
                const offIds = [...off.querySelectorAll(':scope > .ez-row')].map(li => li.dataset.id).filter(id => id !== 'names');
                if (!ids.includes('names')) ids.unshift('names');
                ez = ids.map(id => Object.assign(byId(id), { on: true })).concat(offIds.filter(id => !ids.includes(id)).map(id => Object.assign(byId(id), { on: false }))).concat([byId('finish')]);
                ezRender();
            };
            [ul, off].forEach(box => {
                if (box._sb) { try { box._sb.destroy(); } catch (e) {} }
                box._sb = Sortable.create(box, { group: 'ez', handle: '.ez-grip', forceFallback: true, fallbackOnBody: true, draggable: '.ez-row:not(.ez-fixed)', filter: '.ez-empty', animation: 160, ghostClass: 'ez-ghost', onEnd: done,
                    onMove: ev => !(ev.dragged.dataset.id === 'names' && ev.to === off) }); // (두 사람은 끌 수 없음 · 마무리는 늘 맨 끝)
            });
        }
        document.getElementById('ezSave').disabled = !ezDirty() || readonly;
        document.getElementById('ezUndo').disabled = !ezDirty();
        document.getElementById('ezState').textContent = ezDirty() ? '바꾼 단계가 있어요 - 저장을 눌러야 적용돼요' : `켜진 단계 ${ez.filter(x => x.on).length}개가 고객에게 이 순서로 보여요`;
        ezPv();
    }
    function ezPv() {
        const on = ez.filter(x => x.on); if (!on.find(x => x.id === ezSel)) ezSel = on[0].id;
        const idx = on.findIndex(x => x.id === ezSel);
        document.getElementById('ezPvNow').textContent = `${idx + 1} / ${on.length} · ${EZ_NAMES[ezSel]}`;
        const st = document.getElementById('ezPvSteps');
        st.innerHTML = on.map((x, i) => `<button type="button" class="${x.id === ezSel ? 'on' : ''}" data-ez-pv="${x.id}"><i>${i + 1}</i>${ezEsc(EZ_NAMES[x.id])}</button>`).join('');
        st.querySelectorAll('[data-ez-pv]').forEach(b => b.addEventListener('click', () => { ezSel = b.dataset.ezPv; ezPv(); }));
        const [, what, sec] = EZ_INFO[ezSel] || [];
        const opt = !['names', 'hero', 'music', 'finish', 'theme'].includes(ezSel);
        const ops = EZ_OPTS[ezSel] || {}, offL = ezOff[ezSel] || [], onOps = Object.keys(ops).filter(k => !offL.includes(k));
        document.getElementById('ezPvBody').innerHTML = `<h5>${ezEsc(EZ_NAMES[ezSel])}</h5><p>${ezEsc(what || '')}</p>${opt ? '<div class="sw">청첩장에 넣기<i></i></div>' : ''}${onOps.length ? onOps.map(k => `<div class="sk op">${ezEsc(ops[k])}</div>`).join('') : '<div class="sk"></div><div class="sk" style="width:80%"></div>'}`;
        st.querySelector('.on')?.scrollIntoView({ inline: 'center', block: 'nearest' });
    }
    document.getElementById('ezUndo')?.addEventListener('click', () => { ez = JSON.parse(JSON.stringify(EZ_SAVED)); ezOff = JSON.parse(JSON.stringify(EZ_OFF0)); ezRender(); });
    document.getElementById('ezSave')?.addEventListener('click', () => {
        const btn = document.getElementById('ezSave'); btn.disabled = true;
        post({ act: 'easy', steps: JSON.stringify(ez), off: JSON.stringify(ezOff) }).then(() => {
            guard.release(); toast('저장했어요. 고객이 에디터를 새로 열면 이 단계로 보여요');
            setTimeout(() => { location.hash = '#easy'; location.reload(); }, 700);
        }).catch(err => { btn.disabled = false; say(err.message); });
    });
    document.getElementById('ezReset')?.addEventListener('click', () => {
        LD.confirm('관리자가 정한 단계를 지우고 기본 단계(모두 켜짐)로 되돌릴까요?').then(ok => {
            if (!ok) return;
            post({ act: 'easy_reset' }).then(() => { guard.release(); toast('기본 단계로 되돌렸어요'); setTimeout(() => { location.hash = '#easy'; location.reload(); }, 700); }).catch(err => say(err.message));
        });
    });
    ezRender();

    document.getElementById('soS10Fix')?.addEventListener('click', () => { order = s10Sort(order); build(); }); // 묶음 순서대로 정리 (저장 버튼을 눌러야 저장)
    build();
    gpRender();
})();
</script>
</body>
</html>
