<?php
/**
 * admin_tips.php - 관리자: 에디터 도움말
 *
 *  - 도움말 전체 켜기/끄기 (기본: 끔 = 고객 에디터에서 모두 숨김, 둘러보기도 안 뜸)
 *  - 기능 (여러 개 같이 켤 수 있음)
 *      ⓘ 접어두기          : "ⓘ 도움말"을 눌러야 펼쳐짐
 *      처음 한 번만 펼치기  : 처음 보는 도움말만 펼쳐서 보여주고, 한 번 본 건 ⓘ로 접어둠 (고객 브라우저마다 기억)
 *      움직이는 시범        : 도움말마다 손가락 동작 그림(끌기·누르기·두 번 누르기·크기 조절·넘기기) 또는 올린 GIF·영상
 *      첫 방문 둘러보기     : 에디터에 처음 들어온 고객에게 말풍선 4단계 (상단 ? 버튼으로 다시 보기)
 *  - 도움말마다 켜기/끄기, 문구 수정 (**굵게**, 줄바꿈), 원래 문구로, 시범 정하기
 *  - 둘러보기 단계마다 켜기/끄기, 제목·문구 수정, "이미 본 고객에게도 다시 보여주기"
 *  - 도움말 목록은 에디터를 화면 밖에서 몰래 열어(?tips=harvest) 모든 섹션 편집창에서 자동으로 모은다
 *    → 앞으로 섹션·도움말이 늘어나면 여기에 "새 팁"으로 자동 추가됨
 *    (어떤 옵션을 켜야만 나오는 도움말은 기본 화면에 없으면 목록에 안 잡힐 수 있음)
 *  같은 문구의 도움말은 여러 섹션에 있어도 하나로 묶여서 한 번만 고치면 다 바뀐다.
 *  저장: invite/uploads/site/editor_tips.json, 시범 파일: invite/uploads/site/tips/ (editor_tips.php)
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장할 수 있다 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/editor_tips.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    $fail = function (string $m): void { echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; };

    // 시범 파일 올리기 (저장 전에 먼저 올려 두고, 저장할 때 도움말에 연결됨. 안 쓰이는 파일은 1시간 뒤 정리)
    if (($_POST['act'] ?? '') === 'upload') {
        [$name, $err] = editor_tips_store_media($_FILES['media'] ?? []);
        if (!$name) $fail($err);
        echo json_encode(['ok' => true, 'file' => $name], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $in = json_decode((string) ($_POST['cfg'] ?? ''), true);
    if (!is_array($in)) $fail('저장할 내용이 올바르지 않아요. 새로고침 후 다시 해주세요.');
    // 이번 화면에서 못 찾은 도움말(조건부 등)의 예전 설정은 그대로 남긴다
    $old = editor_tips_get();
    $tips = is_array($in['tips'] ?? null) ? $in['tips'] : [];
    foreach ((array) $old['tips'] as $k => $v) if (!array_key_exists($k, $tips)) $tips[$k] = $v;
    $in['tips'] = $tips;
    $in['known'] = array_values(array_unique(array_merge((array) ($in['known'] ?? []), $old['known'])));
    // 편집창·팝업창 바탕은 섹션 순서 → 편집창 모양에서 정함 → 여기서 저장할 땐 그대로 둠
    if (!array_key_exists('popupTheme', $in)) $in['popupTheme'] = $old['popupTheme'];
    if (!array_key_exists('custom', $in)) $in['custom'] = $old['custom']; // 커스텀 편집팁은 따로 (admin_tip_tours.php)
    if (!array_key_exists('notices', $in)) $in['notices'] = $old['notices']; // 첫 방문 안내 팝업 (예전 화면에서 저장해도 지워지지 않게)
    if (!editor_tips_save($in)) $fail('저장하지 못했어요. uploads/site 폴더 쓰기 권한을 확인해주세요.');
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = editor_tips_get();
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>에디터 도움말 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<link rel="stylesheet" href="assets/tip-demos.css">
<style>
.tp-intro { font-size: 13px; color: var(--muted); margin: -14px 0 18px; line-height: 1.75; }
.tp-card { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; padding: 4px 18px; margin: 0 0 18px; }
.tp-set { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--ui-soft, #F1EEE9); flex-wrap: wrap; }
.tp-set:last-child { border-bottom: 0; }
.tp-set .tx { flex: 1; min-width: 200px; }
.tp-set .tx b { display: block; font-size: 14.5px; margin: 0 0 3px; }
.tp-set .tx small { font-size: 12.5px; color: var(--muted); line-height: 1.6; }
/* 아이폰 스위치 */
.sw { position: relative; flex: none; width: 46px; height: 28px; }
.sw input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 1; }
.sw i { position: absolute; inset: 0; border-radius: 999px; background: var(--ui-line, #E3E0DB); transition: background .2s; }
.sw i::after { content: ''; position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; box-shadow: 0 2px 5px rgba(0, 0, 0, .22); transition: transform .22s cubic-bezier(.3, .7, .4, 1.2); }
.sw input:checked + i { background: #34C759; }
.sw input:checked + i::after { transform: translateX(18px); }
.sw input:disabled + i { opacity: .5; }
.sw.sm { width: 40px; height: 24px; }
.sw.sm i::after { width: 18px; height: 18px; }
.sw.sm input:checked + i::after { transform: translateX(16px); }
/* 기능 (여러 개 선택) */
.tp-feats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; width: 100%; }
.tp-feat { position: relative; border: 1.5px solid var(--ui-line, #E6E1DA); border-radius: 14px; padding: 12px 12px 12px; cursor: pointer; background: #fff; text-align: left; font: inherit; color: inherit; display: flex; flex-direction: column; gap: 8px; transition: border-color .15s, box-shadow .15s; }
.tp-feat:hover { border-color: var(--ui-line, #CFC8BE); }
.tp-feat.on { border-color: #2B2320; box-shadow: 0 0 0 3px rgba(43, 35, 32, .08); }
.tp-feat .ck { position: absolute; top: 10px; right: 10px; width: 20px; height: 20px; border-radius: 6px; border: 1.5px solid var(--ui-line, #D3CCC2); display: grid; place-items: center; background: #fff; transition: all .15s; }
.tp-feat.on .ck { background: #2B2320; border-color: #2B2320; }
.tp-feat .ck svg { width: 12px; height: 12px; stroke: #fff; stroke-width: 3; fill: none; opacity: 0; }
.tp-feat.on .ck svg { opacity: 1; }
.tp-feat b { font-size: 13.5px; padding-right: 26px; }
.tp-feat small { font-size: 11.5px; color: #8A847B; line-height: 1.55; }
.tp-feat .mk { border-radius: 9px; background: var(--ui-tint, #FAF8F4); padding: 9px; font-size: 10.5px; color: #8A847B; height: 86px; box-sizing: border-box; overflow: hidden; position: relative; }
.mk .ln { height: 6px; border-radius: 3px; background: var(--ui-line, #E7E2DA); margin: 0 0 6px; }
.mk .hb { background: var(--ui-soft, #F1EFE9); border-radius: 4px; padding: 5px 7px; font-size: 10px; color: #8A847B; margin: 0 0 6px; border: 1px solid var(--ui-line, #ECE7DF); }
.mk .q { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--ui-line, #E6E0D8); border-radius: 999px; padding: 2px 8px 2px 3px; background: #fff; font-size: 10px; margin: 0 0 6px; }
.mk .q i { width: 12px; height: 12px; border-radius: 50%; background: #8A847B; color: #fff; display: grid; place-items: center; font-size: 8px; font-style: italic; font-family: Georgia, serif; }
.mk .two { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.mk .two > div { background: #fff; border-radius: 6px; padding: 5px; border: 1px solid var(--ui-line, #EEE9E1); }
.mk .two em { display: block; font-style: normal; font-size: 9px; font-weight: 800; color: #A29C94; margin: 0 0 4px; }
.mk .two .hb { padding: 3px 5px; font-size: 9px; margin: 0; }
.mk .tip-demo { height: 66px; margin: 0; }
.mk .tip-demo .tg-cap { display: none; }
.mk.tour { background: #4B443D; }
.mk.tour .shot { position: absolute; left: 10px; top: 12px; width: 40px; height: 22px; border-radius: 6px; background: var(--ui-tint, #FAF8F4); box-shadow: 0 0 0 2px #fff; }
.mk.tour .bub { position: absolute; left: 10px; top: 42px; right: 10px; background: #fff; border-radius: 7px; padding: 6px 7px; font-size: 9.5px; color: #4A443E; font-weight: 700; }
.mk.tour .bub::before { content: ''; position: absolute; left: 22px; top: -4px; width: 8px; height: 8px; background: #fff; transform: rotate(45deg); }
.tp-feat-note { font-size: 12px; color: #8A847B; margin: 10px 0 0; line-height: 1.8; width: 100%; }
.tp-linkbtn { border: 0; background: none; padding: 0; font: inherit; font-size: 12px; font-weight: 700; color: #3557B7; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
.tp-off-note { display: none; font-size: 12.5px; color: #8A5A00; background: #FFF7E3; border: 1px solid #F3E2B5; border-radius: 10px; padding: 9px 12px; margin: 0 0 14px; line-height: 1.6; }
body.tips-off .tp-off-note { display: block; }
/* 둘러보기 */
/* 기능 카드에서 켠 것만 아래에 설정 칸이 나타남 (첫 방문 둘러보기 → 단계, 섹션별 사용법 → 카드) */
.tp-tour, .tp-guide { animation: tpReveal .32s ease; }
@keyframes tpReveal { from { opacity: 0; transform: translateY(-8px); } to { opacity: 1; transform: none; } }
body.tour-off .tp-tour, body.guide-off .tp-guide { display: none; }
.tp-feat-empty { display: none; border: 1.5px dashed var(--ui-line, #E1DBD2); border-radius: 14px; padding: 16px; margin: 0 0 14px; text-align: center; font-size: 12.5px; color: #A29C94; line-height: 1.7; }
body.tour-off.guide-off .tp-feat-empty { display: block; }
.tp-feat .go { display: none; align-self: flex-start; margin-top: auto; font-size: 11.5px; font-weight: 800; color: #3557B7; cursor: pointer; }
.tp-feat.on .go { display: inline-block; }
.tp-flash { animation: tpReveal .32s ease, tpFlash 1.4s ease .1s; }
@keyframes tpFlash { 0%, 100% { box-shadow: none; } 30% { box-shadow: 0 0 0 4px rgba(53, 87, 183, .22); } }
.tp-tour-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 0 0 10px; }
.tp-tour-head h3 { margin: 0; font-size: 15px; flex: 1; }
.tp-tour-head label.again { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6F6A63; cursor: pointer; }
.tp-step { display: grid; grid-template-columns: 30px 40px minmax(0, 1fr) 250px; gap: 12px; align-items: start; padding: 14px 0; border-bottom: 1px solid var(--ui-soft, #F1EEE9); }
.tp-step:last-child { border-bottom: 0; }
.tp-step .no { width: 26px; height: 26px; border-radius: 50%; background: #2B2320; color: #fff; display: grid; place-items: center; font-size: 12px; font-weight: 800; margin-top: 2px; }
.tp-step.off .no { background: var(--ui-line, #D3CCC2); }
.tp-step input[type=text], .tp-step textarea { width: 100%; box-sizing: border-box; border: 1px solid var(--ui-line, #E3DED7); border-radius: 9px; padding: 8px 10px; font: inherit; font-size: 13px; }
.tp-step input[type=text] { font-weight: 700; margin: 0 0 6px; }
.tp-step textarea { min-height: 62px; resize: vertical; line-height: 1.55; }
.tp-step .where { font-size: 11.5px; color: #A29C94; margin: 4px 0 0; display: flex; gap: 8px; align-items: center; }
.tp-step .where button { border: 0; background: none; font: inherit; font-size: 11.5px; color: #6F6A63; text-decoration: underline; cursor: pointer; padding: 0; }
.tp-step .pv { background: #4B443D; border-radius: 12px; padding: 12px; }
.tp-step.off .pv { opacity: .45; }
.tp-step .pv .bub { position: relative; background: #fff; border-radius: 11px; padding: 10px 12px; font-size: 12px; color: #5A544D; line-height: 1.55; }
.tp-step .pv .bub h5 { margin: 0 0 4px; font-size: 13px; color: #2B2B2B; }
.tp-step .pv .bub .nx { display: flex; justify-content: flex-end; margin-top: 7px; }
/* 첫 방문 안내 팝업 (notices) - 도움말 보이기와 상관없이 따로 */
.tp-nt-sub { font-size: 12.5px; color: #8A847B; margin: -2px 0 6px; line-height: 1.7; }
.tp-step .dev { display: inline-block; font-size: 11px; font-weight: 800; padding: 3px 7px; border-radius: 999px; background: #EEF1FA; color: #3557B7; white-space: nowrap; margin-top: 4px; }
.tp-step .dev.m { background: #E9F6EF; color: #23804F; }
.tp-nt.tp-step { grid-template-columns: 62px 40px minmax(0, 1fr) 250px; }
.tp-step .pv.nt { background: rgba(20,16,12,.55); display: flex; align-items: center; }
.tp-step .pv.nt .bub { width: 100%; border-radius: 14px; }
.tp-step .pv.nt .k { font-size: 10px; font-weight: 800; color: #E85267; margin: 0 0 2px; }
.tp-step .pv.nt .nx span { display: block; width: 100%; text-align: center; padding: 6px 0; }
@media (max-width: 760px) { .tp-nt.tp-step { grid-template-columns: 62px 40px minmax(0, 1fr); } .tp-nt.tp-step .pv { grid-column: 1 / -1; } }
.tp-step .pv .bub .nx span { background: #2B2320; color: #fff; font-size: 10.5px; font-weight: 700; padding: 4px 9px; border-radius: 7px; }
/* 섹션별 사용법 */
.mk.gd { background: var(--ui-soft, #F6F3EE); padding: 6px; display: flex; flex-direction: column; align-items: center; gap: 4px; }
.mk.gd .bt { align-self: flex-start; font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 999px; background: #fff; border: 1px solid var(--ui-line, #E3DED7); color: #4A443E; }
.mk.gd .tip-demo { width: 150px; height: 50px; margin: 0; transform: scale(.6); transform-origin: top center; border: 0; }
.tp-gsec { border-top: 1px solid var(--ui-soft, #F1EEE9); }
.tp-gsec:first-child { border-top: 0; }
.tp-gsec > button { width: 100%; display: flex; align-items: center; gap: 8px; padding: 12px 2px; border: 0; background: none; font: inherit; font-size: 13.5px; font-weight: 700; color: #2B2B2B; cursor: pointer; text-align: left; }
.tp-gsec > button .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
.tp-gsec > button small { font-weight: 600; color: #A29C94; font-size: 12px; }
.tp-gsec > button .ar { margin-left: auto; color: #A29C94; transition: transform .2s; }
.tp-gsec.open > button .ar { transform: rotate(90deg); }
.tp-gbody { display: none; padding: 0 0 14px; }
.tp-gsec.open .tp-gbody { display: block; }
.tp-gi { display: grid; grid-template-columns: 26px 132px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px dashed var(--ui-line, #EEE9E1); }
.tp-gi.off { opacity: .5; }
.tp-gi .no { width: 22px; height: 22px; border-radius: 50%; background: #2B2320; color: #fff; display: grid; place-items: center; font-size: 11px; font-weight: 800; }
.tp-gi .th { width: 132px; height: 52px; overflow: hidden; border-radius: 8px; background: var(--ui-tint, #FAF8F4); border: 1px solid var(--ui-line, #EEE9E1); }
.tp-gi .th .tip-demo { width: 210px; margin: 0; transform: scale(.62); transform-origin: 0 0; border: 0; }
.tp-gi .th .tip-demo .tg-cap { display: none; }
.tp-gi .fl { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.tp-gi select, .tp-gi input[type=text] { width: 100%; box-sizing: border-box; border: 1px solid var(--ui-line, #E3DED7); border-radius: 8px; padding: 6px 8px; font: inherit; font-size: 12.5px; background: #fff; }
.tp-gi .ac { display: flex; gap: 4px; align-items: center; }
.tp-gi .ac button { width: 28px; height: 28px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 8px; background: #fff; cursor: pointer; font-size: 12px; color: #6F6A63; }
.tp-gi .ac button:disabled { opacity: .35; cursor: default; }
.tp-gfoot { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.tp-gfoot button { height: 32px; padding: 0 12px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 9px; background: #fff; font: inherit; font-size: 12.5px; font-weight: 600; color: #4A443E; cursor: pointer; }
@media (max-width: 600px) { .tp-gi { grid-template-columns: 22px minmax(0, 1fr) auto; } .tp-gi .th { display: none; } }
/* ===== 기타 설정: 섹션별 사용법 펼친 칸 모양 ===== */
.tp-lays { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
.tp-lay { border: 1.5px solid var(--ui-line, #E6E1DA); border-radius: 14px; background: #fff; padding: 10px; text-align: left; font: inherit; cursor: pointer; display: flex; flex-direction: column; gap: 4px; transition: border-color .15s, box-shadow .15s; }
.tp-lay:hover { border-color: var(--ui-line, #CFC8BE); }
.tp-lay.on { border-color: #2B2320; box-shadow: 0 0 0 3px rgba(43, 35, 32, .08); }
.tp-lay b { font-size: 13px; }
.tp-lay small { font-size: 11px; color: #8A847B; line-height: 1.5; }
.tp-lay .sk { position: relative; display: flex; gap: 4px; height: 58px; border-radius: 9px; background: var(--ui-tint, #FAF8F4); padding: 8px; margin: 0 0 4px; overflow: hidden; }
.sk i, .sk u { display: block; border-radius: 4px; background-color: var(--ui-tint, #FBF8F2); background-image: radial-gradient(rgba(120,100,70,.16) 1px, transparent 1.2px); background-size: 4px 4px; border: 1px solid var(--ui-line, #E6E0D8); }
.sk-list { flex-direction: column; } .sk-list i { height: 10px; }
.sk-story i { flex: 1; border-radius: 6px; } .sk-story i:nth-child(2) { box-shadow: 0 0 0 1.5px #2B2320; }
.sk-studio em { flex: 1; display: flex; flex-direction: column; gap: 4px; } .sk-studio em i { height: 10px; } .sk-studio u { width: 26px; border: 3px solid #2B2320; border-radius: 6px; }
.sk-board { flex-wrap: wrap; } .sk-board i { width: calc(33% - 3px); height: 38px; border-top: 3px solid #9B59B6; } .sk-board i:nth-child(2) { transform: rotate(-3deg); } .sk-board i:nth-child(3) { transform: rotate(2deg); }
.sk-time { flex-direction: column; padding-left: 22px; } .sk-time::before { content: ''; position: absolute; left: 13px; top: 8px; bottom: 8px; border-left: 2px dashed var(--ui-line, #D9D3CB); } .sk-time i { height: 10px; width: 70%; }
.gl-quick { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; margin: 0 0 12px; font-size: 12px; }
.gl-quick span { color: #A29C94; font-weight: 700; margin-right: 2px; }
.gl-quick button { height: 28px; padding: 0 10px; border-radius: 999px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; font: inherit; font-size: 12px; font-weight: 700; color: #6F6A63; cursor: pointer; }
.gl-quick button.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.gl-quick .gl-etc { margin-left: auto; border-color: #D6DEF3; background: #F3F6FD; color: #3557B7; }
.gl-quick .gl-etc:hover { background: #E8EEFB; }
/* 기타 설정 팝업 */
.tp-pop { position: fixed; inset: 0; z-index: 5000; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(27, 24, 21, 0); transition: background .2s ease; }
.tp-pop[hidden] { display: none; }
.tp-pop.show { background: rgba(27, 24, 21, .45); }
.tp-pop-box { width: min(880px, 100%); max-height: calc(100vh - 40px); overflow-y: auto; background: #fff; border-radius: 20px; padding: 20px 22px 18px; box-shadow: 0 24px 60px rgba(0, 0, 0, .25); transform: translateY(12px) scale(.98); opacity: 0; transition: transform .22s ease, opacity .22s ease; }
.tp-pop.show .tp-pop-box { transform: none; opacity: 1; }
.tp-pop-head { display: flex; align-items: center; margin: 0 0 12px; }
.tp-pop-head h3 { margin: 0; font-size: 16px; flex: 1; }
.tp-pop-x { width: 34px; height: 34px; border: 0; border-radius: 10px; background: var(--ui-soft, #F6F3EE); font-size: 14px; color: #6F6A63; cursor: pointer; }
.tp-pop-foot { display: flex; align-items: center; gap: 12px; margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--ui-soft, #F1EEE9); font-size: 12px; color: #8A847B; line-height: 1.6; }
.tp-pop-foot span { flex: 1; }
.tp-pop-ok { height: 38px; padding: 0 20px; border: 0; border-radius: 10px; background: #2B2320; color: #fff; font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; flex: none; }
@media (max-width: 600px) { .tp-pop { padding: 10px; align-items: flex-end; } .tp-pop-box { border-radius: 18px; padding: 16px 14px 14px; } }
@media (max-width: 900px) { .tp-lays { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 600px) { .tp-lays { grid-template-columns: repeat(2, minmax(0, 1fr)); } .tp-lay small { display: none; } }

/* ===== 섹션별 사용법 펼친 칸 (공통 조각) ===== */
.gl-texdot { width: 16px; height: 16px; border-radius: 5px; border: 1px solid var(--ui-line, #E3DED7); margin-left: 4px; }
.gl-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 2px 0 12px; }
.gl-hint { font-size: 11.5px; color: #A29C94; }
.gl-texs { display: inline-flex; gap: 5px; align-items: center; }
.gl-texs .lb { font-size: 11.5px; color: #A29C94; font-weight: 700; margin-right: 2px; }
.gl-texs button { width: 26px; height: 26px; border-radius: 8px; border: 1.5px solid var(--ui-line, #E3DED7); cursor: pointer; padding: 0; }
.gl-texs button.on { border-color: #2B2320; box-shadow: 0 0 0 2px #fff inset; }
.tp-gi .th { position: relative; }
.tp-gi .th .thx { position: absolute; inset: 0; display: block; }
.tp-gi .th .thx .tip-demo { background: transparent; }
.gl-stg { position: relative; overflow: hidden; }
.gl-stg .in { position: absolute; left: 50%; top: 50%; width: 200px; height: 84px; transform: translate(-50%, -50%) scale(var(--s, 1)); pointer-events: none; }
.gl-stg .tip-demo { margin: 0; border: 0; background: transparent; width: 200px; }
.gl-stg .tip-demo .tg-cap { display: none; }
.gl-ptools { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: 0 0 10px; }
.gl-ptools input { flex: 1; min-width: 140px; height: 34px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 9px; padding: 0 10px; font: inherit; font-size: 12.5px; }
.gl-chip { height: 30px; padding: 0 11px; border-radius: 999px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; font: inherit; font-size: 12px; font-weight: 700; color: #6F6A63; cursor: pointer; white-space: nowrap; }
.gl-chip.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.gl-pal { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 8px; }
.gl-none { color: #A29C94; font-size: 12px; margin: 6px 0; }
.gl-tile { border: 1.5px solid var(--ui-line, #ECE7E0); border-radius: 12px; background: #fff; padding: 6px 6px 7px; cursor: pointer; font: inherit; text-align: center; transition: transform .12s, border-color .12s, box-shadow .12s; }
.gl-tile:hover:not(:disabled) { transform: translateY(-2px); border-color: var(--ui-line, #CFC8BE); box-shadow: 0 6px 14px rgba(0,0,0,.06); }
.gl-tile.on { border-color: #2B2320; box-shadow: 0 0 0 3px rgba(43,35,32,.08); }
.gl-tile:disabled { cursor: default; }
.gl-tile .gl-stg { height: 50px; border-radius: 8px; }
.gl-tile span { display: block; font-size: 11px; font-weight: 700; color: #4A443E; margin-top: 5px; line-height: 1.3; word-break: keep-all; }
.gl-ib { width: 28px; height: 28px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 8px; background: #fff; cursor: pointer; font-size: 12px; color: #6F6A63; display: inline-grid; place-items: center; padding: 0; flex: none; }
.gl-ib:disabled { opacity: .35; cursor: default; }
.tp-gsec .no { width: 22px; height: 22px; border-radius: 50%; background: #2B2320; color: #fff; display: inline-grid; place-items: center; font-size: 11px; font-weight: 800; flex: none; }
.gl-cap { width: 100%; box-sizing: border-box; border: 1px solid transparent; border-radius: 8px; padding: 6px 8px; font: inherit; font-size: 12.5px; background: transparent; color: #2B2320; resize: none; line-height: 1.5; }
.gl-cap:hover { border-color: var(--ui-line, #EEE9E1); }
.gl-cap:focus { outline: none; border-color: var(--ui-line, #CFC8BE); background: #fff; }
.gl-ac { display: flex; gap: 4px; align-items: center; }
.tp-gsec .off .gl-stg, .tp-gsec .off .gl-cap { opacity: .45; }
.gl-story .tp-gbody, .gl-studio .tp-gbody, .gl-board .tp-gbody, .gl-timeline .tp-gbody { padding-top: 4px; }
/* A. 스토리 카드 */
.gl-a-strip { display: flex; gap: 14px; overflow-x: auto; padding: 4px 4px 14px; scroll-snap-type: x proximity; }
.gl-a-card { flex: none; width: 210px; border-radius: 18px; background: #fff; border: 1px solid var(--ui-line, #ECE7E0); box-shadow: 0 6px 18px rgba(0,0,0,.05); scroll-snap-align: start; overflow: hidden; display: flex; flex-direction: column; }
.gl-a-card.sel { box-shadow: 0 0 0 2px #2B2320, 0 10px 24px rgba(0,0,0,.08); }
.gl-a-top { display: flex; align-items: center; justify-content: space-between; padding: 10px 10px 0; }
.gl-a-scr { margin: 8px 10px 0; border-radius: 14px; height: 138px; cursor: pointer; border: 1px solid rgba(0,0,0,.04); }
.gl-a-scr .chg { position: absolute; left: 50%; bottom: 8px; transform: translateX(-50%); font-size: 10.5px; font-weight: 800; color: #fff; background: rgba(43,35,32,.72); padding: 3px 9px; border-radius: 999px; opacity: 0; transition: opacity .15s; white-space: nowrap; }
.gl-a-scr:hover .chg, .gl-a-card.sel .chg { opacity: 1; }
.gl-a-name { font-size: 11px; font-weight: 800; color: #A29C94; padding: 8px 14px 0; }
.gl-a-card .gl-cap { margin: 2px 6px 0; width: calc(100% - 12px); min-height: 52px; }
.gl-a-foot { display: flex; gap: 4px; justify-content: flex-end; padding: 6px 10px 10px; margin-top: auto; }
.gl-sheet { border-top: 1px solid var(--ui-soft, #F1EEE9); padding-top: 14px; margin-top: 4px; animation: tpReveal .25s ease; }
.gl-sheet-h { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 13px; font-weight: 800; }
/* B. 스튜디오 2단 */
.gl-b { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 20px; align-items: start; }
.gl-b-row { display: grid; grid-template-columns: 22px 92px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 8px; border-radius: 12px; cursor: pointer; border: 1.5px solid transparent; }
.gl-b-row:hover { background: var(--ui-tint, #FAF8F4); }
.gl-b-row.sel { border-color: #2B2320; background: #fff; }
.gl-b-row .gl-stg { height: 46px; border-radius: 9px; border: 1px solid var(--ui-line, #EEE9E1); }
.gl-b-pal { margin-top: 16px; }
.gl-b-pal h4 { font-size: 13px; margin: 0 0 10px; } .gl-b-pal h4 span { color: #A29C94; }
.gl-b-pal .gl-pal { max-height: 420px; overflow: auto; padding: 2px; }
.gl-b-side { position: sticky; top: 76px; }
.gl-b-phone { border-radius: 30px; background: #1F1B18; padding: 12px; box-shadow: 0 18px 40px rgba(0,0,0,.18); }
.gl-b-scr { border-radius: 20px; background: #fff; overflow: hidden; padding-bottom: 4px; }
.gl-b-bar { display: flex; align-items: center; gap: 6px; padding: 12px 14px 8px; font-size: 12px; font-weight: 800; }
.gl-b-bar .pill { background: var(--ui-soft, #F3EEE6); border-radius: 999px; padding: 3px 9px; } .gl-b-bar .ct { color: #A29C94; font-weight: 700; } .gl-b-bar .x { margin-left: auto; color: #A29C94; }
.gl-b-prog { height: 3px; background: var(--ui-soft, #F1EEE9); margin: 0 12px 10px; border-radius: 2px; overflow: hidden; }
.gl-b-prog i { display: block; height: 100%; width: 0; background: #2B2320; animation: glProg 3.2s linear forwards; }
@keyframes glProg { to { width: 100%; } }
.gl-b-stage { height: 160px; margin: 0 12px; border-radius: 14px; border: 1px solid rgba(0,0,0,.05); }
.gl-b-empty { display: grid; place-items: center; color: #A29C94; font-size: 12px; }
.gl-b-txt { padding: 12px 16px 4px; font-size: 13.5px; font-weight: 700; line-height: 1.55; min-height: 52px; text-align: center; animation: tpReveal .3s ease; }
.gl-b-nav { display: flex; align-items: center; justify-content: center; gap: 5px; padding: 6px 0 12px; }
.gl-b-nav span { width: 6px; height: 6px; border-radius: 3px; background: var(--ui-line, #E1DBD2); transition: width .2s; }
.gl-b-nav span.on { width: 18px; background: #2B2320; }
.gl-b-cap { font-size: 11.5px; color: #A29C94; text-align: center; margin: 8px 0 0; }
/* C. 스토리보드 */
.gl-c-wrap { background: var(--ui-soft, #F3EFE8); border-radius: 16px; padding: 14px; }
.gl-c { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px 16px; padding-top: 8px; }
.gl-c-card { position: relative; background: #fff; border-radius: 6px 6px 14px 14px; box-shadow: 0 1px 0 var(--ui-line, #EDE7DE), 0 8px 20px rgba(60,40,20,.07); border-top: 5px solid var(--sc, #9B59B6); transition: transform .15s; }
.gl-c-card:nth-child(3n+2) { transform: rotate(-.6deg); } .gl-c-card:nth-child(3n) { transform: rotate(.5deg); }
.gl-c-card:hover, .gl-c-card.pop { transform: none; z-index: 3; }
.gl-c-pin { position: absolute; top: -12px; left: 14px; width: 26px; height: 26px; border-radius: 50%; background: #2B2320; color: #fff; font-weight: 800; font-size: 12px; display: grid; place-items: center; box-shadow: 0 3px 8px rgba(0,0,0,.2); z-index: 2; }
.gl-c-stage { height: 120px; margin: 14px 12px 0; border-radius: 10px; cursor: pointer; border: 1px solid rgba(0,0,0,.04); }
.gl-c-meta { font-size: 11.5px; font-weight: 800; color: #4A443E; padding: 8px 12px 0; } .gl-c-meta em { font-style: normal; color: #A29C94; font-weight: 700; }
.gl-c-card .gl-cap { margin: 2px 4px 0; width: calc(100% - 8px); min-height: 44px; }
.gl-c-tool { position: absolute; top: 20px; right: 18px; display: flex; gap: 3px; opacity: 0; transition: opacity .15s; z-index: 2; }
.gl-c-card:hover .gl-c-tool, .gl-c-card.pop .gl-c-tool { opacity: 1; }
.gl-c-tool .gl-ib { background: rgba(255,255,255,.95); }
.gl-c-bot { display: flex; justify-content: space-between; align-items: center; padding: 6px 12px 12px; font-size: 11px; color: #A29C94; font-weight: 700; }
.gl-c-pop { position: absolute; left: -6px; top: 140px; z-index: 20; background: #fff; border-radius: 16px; box-shadow: 0 18px 40px rgba(0,0,0,.18); padding: 12px; border: 1px solid var(--ui-line, #ECE7E0); width: min(480px, calc(100vw - 60px)); animation: tpReveal .2s ease; }
.gl-c-pop .gl-pal { grid-template-columns: repeat(4, minmax(0, 1fr)); max-height: 300px; overflow: auto; padding: 2px; }
.gl-c-wrap .tp-gfoot { margin-top: 16px; }
/* D. 타임라인 */
.gl-d-list { position: relative; padding-left: 4px; }
.gl-d-step { position: relative; display: grid; grid-template-columns: 30px 150px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 10px 0; }
.gl-d-step::before { content: ''; position: absolute; left: 14px; top: 0; bottom: 0; width: 2px; background: repeating-linear-gradient(var(--ui-line, #E1DBD2) 0 5px, transparent 5px 9px); }
.gl-d-step:first-child::before { top: 50%; } .gl-d-step:last-child::before { bottom: 50%; }
.gl-d-step:first-child:last-child::before { display: none; }
.gl-d-step > .no { position: relative; z-index: 1; width: 26px; height: 26px; font-size: 12px; box-shadow: 0 0 0 4px #fff; }
.gl-d-stg { height: 64px; border-radius: 12px; border: 1px solid rgba(0,0,0,.05); cursor: pointer; }
.gl-d-step.sel > .gl-d-stg { box-shadow: 0 0 0 2px #2B2320; }
.gl-d-name { font-size: 11px; font-weight: 800; color: #A29C94; padding: 0 8px; }
.gl-d-film { grid-column: 2 / -1; background: var(--ui-tint, #FAF8F4); border-radius: 14px; padding: 12px; border: 1px solid var(--ui-line, #EEE9E1); animation: tpReveal .25s ease; }
.gl-d-hd { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 12px; font-weight: 800; color: #6F6A63; }
.gl-d-hd input { height: 30px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 8px; padding: 0 9px; font: inherit; font-size: 12px; width: 150px; }
.gl-d-row { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 10px; scroll-snap-type: x proximity; }
.gl-d-row .gl-tile { flex: none; width: 124px; scroll-snap-align: start; }
.gl-d-grp { flex: none; writing-mode: vertical-rl; font-size: 10.5px; font-weight: 800; color: #A29C94; padding: 0 2px; align-self: center; letter-spacing: .1em; }
@media (max-width: 900px) { .gl-b { grid-template-columns: 1fr; } .gl-b-side { position: static; max-width: 340px; margin: 0 auto; } }
@media (max-width: 600px) {
    .gl-d-step { grid-template-columns: 26px 110px minmax(0, 1fr); } .gl-d-step .gl-ac { grid-column: 2 / -1; justify-content: flex-end; }
    .gl-b-row { grid-template-columns: 22px 70px minmax(0, 1fr); } .gl-b-row .gl-ac { grid-column: 2 / -1; justify-content: flex-end; }
    .gl-c-pop .gl-pal { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
/* 도움말 목록 탭 + 여러 섹션 공통의 섹션 칩 (불 들어온 칩 = 그 섹션에 보임) */
.tp-tabs { display: flex; gap: 4px; margin: 0 0 14px; border-bottom: 1px solid var(--ui-line, #E6E1DA); }
.tp-tabs button { position: relative; height: 40px; padding: 0 14px; border: 0; background: none; font: inherit; font-size: 13.5px; font-weight: 700; color: #A29C94; cursor: pointer; }
.tp-tabs button em { font-style: normal; font-size: 11.5px; margin-left: 5px; padding: 1px 7px; border-radius: 999px; background: var(--ui-line, #EFEAE3); color: #8A847B; }
.tp-tabs button.on { color: #2B2320; }
.tp-tabs button.on::after { content: ''; position: absolute; left: 10px; right: 10px; bottom: -1px; height: 2.5px; border-radius: 2px; background: #2B2320; }
.tp-tabs button.on em { background: #2B2320; color: #fff; }
.tp-grp-note { margin: -2px 0 10px; font-size: 12px; color: #8A847B; line-height: 1.6; }
.tp-where { margin-top: 8px; }
.tp-secs { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.tp-sec { display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 10px 0 8px; border-radius: 999px; border: 1px solid var(--ui-line, #E3DED7); background: var(--ui-soft, #F6F4F0); font: inherit; font-size: 11.5px; font-weight: 700; color: #B3ACA2; cursor: pointer; transition: all .15s; }
.tp-sec i { width: 7px; height: 7px; border-radius: 50%; background: var(--ui-line, #D3CCC2); transition: all .15s; }
.tp-sec:hover:not(:disabled) { border-color: var(--ui-line, #CFC8BE); }
.tp-sec.on { background: #fff; color: #2B2320; border-color: color-mix(in srgb, var(--c) 55%, var(--ui-line, #E3DED7)); box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 14%, transparent); }
.tp-sec.on i { background: var(--c); box-shadow: 0 0 6px 1px color-mix(in srgb, var(--c) 70%, transparent); }
.tp-sec:not(.on) { text-decoration: line-through; text-decoration-color: rgba(0,0,0,.18); }
.tp-sec:disabled { cursor: default; }
.tp-secn { font-size: 11px; color: #A29C94; font-weight: 700; margin-left: 4px; }
/* 섹션별 도움말: 접힌 섹션 머리 (누르면 그 섹션만 펼침) */
.tp-grp.fold { margin: 0 0 8px; }
.tp-grp-h { width: 100%; display: flex; align-items: center; gap: 8px; padding: 13px 16px; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 14px; background: #fff; font: inherit; font-size: 13.5px; color: #2B2B2B; cursor: pointer; text-align: left; transition: border-color .15s, box-shadow .15s; scroll-margin-top: 70px; }
.tp-grp-h:hover { border-color: var(--ui-line, #D9D3CB); }
.tp-grp-h .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
.tp-grp-h b { font-weight: 800; }
.tp-grp-h small { font-weight: 700; color: #A29C94; font-size: 12px; margin-right: 4px; }
.tp-grp-h .tp-tag { margin-left: 2px; }
.tp-grp-h .ar { margin-left: auto; color: #A29C94; font-size: 16px; transition: transform .2s; }
.tp-grp.fold.open .tp-grp-h { border-radius: 14px 14px 0 0; border-bottom-color: var(--ui-soft, #F1EEE9); box-shadow: 0 6px 18px rgba(0,0,0,.04); }
.tp-grp.fold.open .tp-grp-h .ar { transform: rotate(90deg); }
.tp-grp.fold.open .tp-list { border-top: 0; border-radius: 0 0 14px 14px; animation: tpReveal .25s ease; }
.tp-grp.fold.open { margin: 0 0 14px; }
/* 도구줄 */
.tp-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
.tp-tools input[type=search] { flex: 1; min-width: 180px; height: 38px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 10px; padding: 0 12px; font: inherit; font-size: 13.5px; background: #fff; }
.tp-chip { height: 34px; padding: 0 12px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 999px; background: #fff; font: inherit; font-size: 12.5px; font-weight: 600; color: #6F6A63; cursor: pointer; }
.tp-chip.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.tp-chip em { font-style: normal; opacity: .7; margin-left: 3px; }
.tp-bulk { margin-left: auto; display: flex; gap: 6px; }
.tp-bulk button { height: 34px; padding: 0 12px; border: 1px solid var(--ui-line, #E3DED7); border-radius: 10px; background: #fff; font: inherit; font-size: 12.5px; color: #6F6A63; cursor: pointer; }
.tp-h2 { font-size: 15px; margin: 26px 0 12px; }
/* 목록 */
.tp-grp { margin: 0 0 18px; }
.tp-grp h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-size: 13.5px; color: #4A443E; }
.tp-grp h3 .dot { width: 10px; height: 10px; border-radius: 50%; }
.tp-grp h3 small { font-weight: 600; color: #A29C94; font-size: 12px; }
.tp-list { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 14px; overflow: hidden; }
.tp-item { display: flex; gap: 12px; padding: 12px 14px; border-bottom: 1px solid var(--ui-soft, #F1EEE9); align-items: flex-start; }
.tp-item:last-child { border-bottom: 0; }
.tp-item.is-off .tp-body .hb { opacity: .45; }
.tp-item .sw { margin-top: 4px; }
.tp-body { flex: 1; min-width: 0; }
.tp-body .hb { font-size: 12px; color: #8A847B; background: var(--ui-soft, #F1EFE9); border-radius: 6px; padding: 9px 10px; line-height: 1.65; overflow-wrap: anywhere; }
.tp-body .hb b { color: #4A443E; }
.tp-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 7px; }
.tp-tag { font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background: var(--ui-soft, #F2EFEA); color: #7A746C; }
.tp-tag.new { background: #FFE9EE; color: #C2405E; }
.tp-tag.mod { background: #E9F0FF; color: #3557B7; }
.tp-tag.demo { background: #EAF6EE; color: #2F7A4E; }
.tp-meta .sp { flex: 1; }
.tp-meta button { border: 0; background: none; font: inherit; font-size: 12px; font-weight: 600; color: #6F6A63; cursor: pointer; padding: 4px 6px; border-radius: 6px; }
.tp-meta button:hover { background: var(--ui-soft, #F6F3EE); color: #2B2B2B; }
.tp-meta button:disabled { opacity: .4; cursor: default; }
.tp-edit { margin-top: 8px; }
.tp-edit textarea { width: 100%; box-sizing: border-box; min-height: 84px; border: 1px solid var(--ui-line, #D9D3CB); border-radius: 10px; padding: 9px 10px; font: inherit; font-size: 13px; line-height: 1.6; resize: vertical; }
.tp-edit p { margin: 4px 0 0; font-size: 11.5px; color: #A29C94; }
.tp-demoed { margin-top: 8px; padding: 10px; border-radius: 10px; background: var(--ui-tint, #FAF8F4); border: 1px solid var(--ui-line, #EEE9E1); }
.tp-demoed .gh { font-size: 11.5px; font-weight: 800; color: #8A847B; margin: 6px 0 6px; }
.tp-demoed .gs { display: grid; grid-template-columns: repeat(auto-fill, minmax(124px, 1fr)); gap: 8px; margin: 0 0 10px; }
.tp-demoed .gs button { position: relative; padding: 0 0 6px; border: 1.5px solid var(--ui-line, #E3DED7); border-radius: 10px; background: #fff; font: inherit; font-size: 11.5px; font-weight: 600; color: #4A443E; cursor: pointer; overflow: hidden; text-align: center; }
.tp-demoed .gs button:hover { border-color: #BFB7AC; }
.tp-demoed .gs button.on { border-color: #2B2320; box-shadow: 0 0 0 3px rgba(43, 35, 32, .1); }
.tp-demoed .gs button.on::after { content: '✓'; position: absolute; right: 5px; top: 5px; width: 16px; height: 16px; border-radius: 50%; background: #2B2320; color: #fff; font-size: 10px; line-height: 16px; }
.tp-demoed .gs .th { display: block; height: 52px; overflow: hidden; background: var(--ui-tint, #FAF8F4); border-bottom: 1px solid var(--ui-line, #EEE9E1); margin: 0 0 6px; }
.tp-demoed .gs .th .tip-demo { width: 210px; margin: 0; transform: scale(.6); transform-origin: 0 0; border: 0; pointer-events: none; }
.tp-demoed .gs .th .tip-demo .tg-cap { display: none; }
.tp-demoed .gs .th.tx { display: grid; place-items: center; font-size: 20px; color: #B9B1A6; }
.tp-demoed .up { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 11.5px; color: #A29C94; }
.tp-demoed .up button { height: 30px; padding: 0 12px; border: 1px dashed #C9BFB1; border-radius: 9px; background: #fff; font: inherit; font-size: 12px; font-weight: 600; color: #4A443E; cursor: pointer; }
.tp-demoed .up button.rm { border-style: solid; color: #A33A3A; }
.tp-body .hb .tip-demo { margin: 0 0 8px; }
.tp-empty, .tp-load { padding: 34px 16px; text-align: center; color: var(--muted); font-size: 13px; background: #fff; border: 1px dashed var(--ui-line, #E3DED7); border-radius: 14px; }
.tp-load i { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--ui-line, #D9D3CB); border-top-color: #2B2320; border-radius: 50%; animation: tpSpin .8s linear infinite; vertical-align: -2px; margin-right: 8px; }
@keyframes tpSpin { to { transform: rotate(360deg); } }
/* 저장 막대 */
.savebar { position: fixed; left: 50%; bottom: 18px; z-index: 90; transform: translate(-50%, 140%); display: flex; align-items: center; gap: 10px; padding: 10px 10px 10px 18px; border-radius: 16px; background: #2B2B2B; color: #fff; font-size: 13.5px; box-shadow: 0 16px 34px rgba(0, 0, 0, .25); transition: transform .3s cubic-bezier(.2, .8, .3, 1); white-space: nowrap; }
.savebar.on { transform: translate(-50%, 0); }
.savebar button { border: 0; border-radius: 10px; padding: 9px 16px; font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; }
.savebar .undo { background: transparent; color: #CFC9C1; }
.savebar .save { background: #fff; color: #2B2B2B; }
.savebar.busy .save { opacity: .6; pointer-events: none; }
.tp-bottom { height: 90px; }
#tpHarvest { position: absolute; left: -10000px; top: 0; width: 1300px; height: 900px; border: 0; visibility: hidden; }
@media (max-width: 900px) {
    .tp-feats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .tp-step { grid-template-columns: 30px 40px minmax(0, 1fr); }
    .tp-step .pv { grid-column: 3; }
}
@media (max-width: 600px) {
    .tp-feats { gap: 8px; }
    .tp-feat { padding: 10px; }
    .tp-feat small { display: none; }
    .tp-step { grid-template-columns: 26px 40px minmax(0, 1fr); gap: 8px; }
    .tp-step .pv { grid-column: 1 / -1; }
    .tp-bulk { margin-left: 0; width: 100%; }
    .tp-bulk button { flex: 1; }
    .tp-item { padding: 12px; gap: 10px; }
    .savebar { left: 12px; right: 12px; transform: translateY(150%); padding: 8px 8px 8px 14px; gap: 4px; font-size: 12.5px; }
    .savebar.on { transform: none; }
    .savebar span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .savebar button { padding: 9px 12px; }
}

/* 기타 설정: 에디터 팝업창 바탕 */
.tp-pt { display: grid; grid-template-columns: 1fr 240px; gap: 16px; align-items: start; }
@media (max-width: 640px) { .tp-pt { grid-template-columns: 1fr; } }
.tp-pt-lab { font-size: 12px; font-weight: 700; color: #6F6A63; margin-bottom: 6px; }
.tp-pt-sws { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.tp-pt-sws button, .tp-pt-sws label { position: relative; width: 30px; height: 30px; border-radius: 50%; border: 0; padding: 0; cursor: pointer; box-shadow: inset 0 0 0 1px rgba(0,0,0,.15); }
.tp-pt-sws .on { box-shadow: inset 0 0 0 1px rgba(0,0,0,.15), 0 0 0 2px #fff, 0 0 0 4px #2B2320; }
.tp-pt-sws label { width: 20px; height: 20px; margin-left: 2px; background: conic-gradient(#F66, #FC5, #6D8, #5BE, #A7F, #F6B, #F66); box-shadow: none; overflow: hidden; }
.tp-pt-sws label::after { content: ''; position: absolute; inset: 5px; border-radius: 50%; background: var(--c, transparent); }
.tp-pt-sws label.on { box-shadow: 0 0 0 2px #fff, 0 0 0 3.5px #2B2320; }
.tp-pt-sws label input { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; border: 0; padding: 0; }
.tp-pt-tex { display: flex; flex-wrap: wrap; gap: 6px; }
.tp-pt-tex button { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px 0 5px; border-radius: 999px; border: 1px solid var(--ui-line, #E3DED7); background: #fff; font: inherit; font-size: 12px; font-weight: 700; color: #6F6A63; cursor: pointer; }
.tp-pt-tex button i { width: 22px; height: 22px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(0,0,0,.08); background-color: var(--pbg, #FBF8F2); }
.tp-pt-tex button.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.tp-pt-warn { margin: 10px 0 0; font-size: 12px; color: #B5522F; }
.ldp-dot { background-image: radial-gradient(rgba(120,100,70,.10) 1px, transparent 1.2px); background-size: 5px 5px; }
.ldp-plain { background-image: none; }
.ldp-linen { background-image: repeating-linear-gradient(0deg, rgba(120,100,70,.06) 0 1px, transparent 1px 3px), repeating-linear-gradient(90deg, rgba(120,100,70,.05) 0 1px, transparent 1px 4px); }
.ldp-grid { background-image: linear-gradient(rgba(120,100,70,.08) 1px, transparent 1px), linear-gradient(90deg, rgba(120,100,70,.08) 1px, transparent 1px); background-size: 12px 12px; }
.ldp-paper { background-image: radial-gradient(rgba(120,100,70,.07) .8px, transparent 1px), radial-gradient(rgba(120,100,70,.05) .8px, transparent 1px); background-size: 7px 7px, 11px 11px; background-position: 0 0, 3px 4px; }
.tp-pt-prev { border-radius: 18px; border: 1px solid var(--ui-line, #ECE4D8); box-shadow: 0 14px 30px rgba(40,30,20,.16); padding: 10px 12px 12px; color: #2B2320; font-size: 12px; background-color: var(--pbg, #FBF8F2); }
.tp-pt-prev .pv-head { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.tp-pt-prev .pv-head b { display: inline-flex; align-items: center; gap: 5px; height: 26px; padding: 0 10px 0 3px; border-radius: 999px; background: #fff; border: 1px solid var(--ui-line, #E3DED7); font-size: 11.5px; }
.tp-pt-prev .pv-head b i { font-style: normal; width: 19px; height: 19px; border-radius: 50%; background: #2B2320; color: #fff; display: grid; place-items: center; font-size: 9px; font-family: Georgia, serif; }
.tp-pt-prev .pv-head em { font-style: normal; height: 26px; padding: 0 8px; display: inline-flex; align-items: center; border-radius: 999px; border: 1px dashed #D9C28E; background: var(--ui-tint, #FFFBF1); color: #9A6A0A; font-size: 10.5px; font-weight: 800; }
.tp-pt-prev .pv-head span { margin-left: auto; color: #8A8278; }
.tp-pt-prev .pv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px; }
.tp-pt-prev .pv-grid > div { background: #fff; border: 1px solid var(--ui-line, #E8E1D6); border-radius: 12px; padding: 4px 6px 6px; }
.tp-pt-prev small { font-size: 10px; font-weight: 700; color: #A29C94; }
.tp-pt-prev .pv-grid p { margin: 2px 0 0; display: flex; align-items: center; justify-content: space-between; }
.tp-pt-prev .pv-grid s { text-decoration: none; width: 22px; height: 22px; border-radius: 7px; background: var(--ui-soft, #F3EEE6); display: grid; place-items: center; }
.tp-pt-prev .pv-row { display: flex; align-items: center; gap: 6px; min-height: 28px; }
.tp-pt-prev .pv-row small { width: 38px; }
.tp-pt-prev .sw { width: 18px; height: 18px; border-radius: 50%; box-shadow: inset 0 0 0 1px rgba(0,0,0,.14); }
.tp-pt-prev .sw.on { box-shadow: inset 0 0 0 1px rgba(0,0,0,.14), 0 0 0 2px var(--pbg, #FBF8F2), 0 0 0 3.5px #2B2320; }
.tp-pt-prev .tg { width: 32px; height: 19px; border-radius: 999px; background: #2B2320; position: relative; }
.tp-pt-prev .tg i { position: absolute; right: 2px; top: 2px; width: 15px; height: 15px; border-radius: 50%; background: #fff; }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('tips', '에디터 도움말'); ?>

    <div class="wrap">
        <h2 class="page-title">에디터 도움말</h2>
        <p class="tp-intro">
            고객 에디터의 섹션 편집창에 나오는 회색 안내 상자(도움말)와 첫 방문 둘러보기를 정해요.
            도움말 목록은 에디터에서 <b>자동으로 모아 와서</b> 앞으로 섹션·도움말이 늘어나면 <span class="tp-tag new">새 팁</span>으로 나타나요.
            같은 문구는 여러 섹션에 있어도 하나로 묶여서 한 번만 고치면 다 바뀌어요.
        </p>

        <div class="tp-card">
            <div class="tp-set">
                <div class="tx"><b>에디터에 도움말 보이기</b><small>끄면 아래 설정과 상관없이 고객 에디터에서 도움말·둘러보기가 모두 숨겨져요. (원래 문구는 지워지지 않아요)</small></div>
                <label class="sw"><input type="checkbox" id="tpShow"><i></i></label>
            </div>
            <div class="tp-set">
                <div class="tx" style="flex-basis:100%"><b>기능 <span style="font-weight:600;color:#A29C94;font-size:12.5px">· 여러 개 같이 켤 수 있어요</span></b><small>아무것도 안 고르면 회색 상자로 바로 보여요.</small></div>
                <div class="tp-feats">
                    <button type="button" class="tp-feat" data-feat="fold"><span class="ck"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
                        <b>ⓘ 접어두기</b><small>"ⓘ 도움말"을 눌러야 펼쳐져요. 편집창이 깔끔해져요.</small>
                        <div class="mk"><div class="ln" style="width:40%"></div><span class="q"><i>i</i>도움말</span><div class="ln"></div><div class="ln" style="width:70%"></div></div></button>
                    <button type="button" class="tp-feat" data-feat="once"><span class="ck"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
                        <b>처음 한 번만 펼치기</b><small>처음 보는 도움말만 펼쳐서 보여주고, 한 번 본 건 ⓘ로 접어둬요.</small>
                        <div class="mk"><div class="two"><div><em>처음</em><div class="hb">사진을 끌어서…</div></div><div><em>다음부터</em><span class="q" style="margin:0"><i>i</i>도움말</span></div></div></div></button>
                    <button type="button" class="tp-feat" data-feat="demo"><span class="ck"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
                        <b>움직이는 시범</b><small>도움말에 손가락 동작 그림이나 올린 GIF·영상을 같이 보여줘요. (아래 목록에서 도움말마다 정해요)</small>
                        <div class="mk" style="padding:0;background:#fff"><div class="tip-demo g-drag" style="height:86px;margin:0;border:0"><span class="tg-card"><i></i><i></i></span><span class="tg-fin"></span></div></div></button>
                    <button type="button" class="tp-feat" data-feat="guide"><span class="ck"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
                        <b>섹션별 사용법</b><small>섹션 편집창 위에 "▶ 사용법" 버튼 - 그 섹션 기능을 움직이는 카드로 차례차례 보여줘요. (아래에서 섹션마다 정해요)</small>
                        <div class="mk gd"><span class="bt">▶ 갤러리 사용법 5</span><div class="tip-demo g-photoorder"><span class="tg-img i1"></span><span class="tg-img i2"></span><span class="tg-img i3"></span><span class="tg-fin"></span></div></div>
                        <span class="go" data-go="tp-guide">↓ 아래에서 섹션별 카드 설정</span></button>
                    <button type="button" class="tp-feat" data-feat="tour"><span class="ck"><svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg></span>
                        <b>첫 방문 둘러보기</b><small>에디터에 처음 들어온 고객에게 말풍선으로 주요 버튼을 짚어줘요. 상단 ? 버튼으로 다시 볼 수 있어요.</small>
                        <div class="mk tour"><div class="shot"></div><div class="bub">① 섹션 목록이에요 →</div></div>
                        <span class="go" data-go="tp-tour">↓ 아래에서 둘러보기 단계 설정</span></button>
                </div>
                <p class="tp-feat-note">💡 <b>처음 한 번만 펼치기</b>: 처음 보는 도움말은 펼쳐서, 다른 섹션으로 갔다 오거나 다시 들어오면 ⓘ로 접혀요. (고객이 본 도움말은 그 브라우저에 기억돼요)<br>
                    💡 <b>움직이는 시범</b>: 프리셋 <b id="tpPresetN">47</b>가지(미리보기 조작·편집창·안내 그림). 시범을 따로 안 정한 도움말은 문구를 보고 <b>자동으로</b> 알맞은 걸 골라요. 아래 목록의 🎬 시범에서 바꾸거나 끌 수 있어요.<br>
                    🧪 직접 확인할 때: 이 브라우저는 이미 본 걸로 기억돼 있을 수 있어요 → <button type="button" id="tpResetMine" class="tp-linkbtn">이 브라우저의 '이미 본 도움말·둘러보기' 기록 지우기</button></p>
            </div>
        </div>

        <div class="tp-off-note">지금은 <b>도움말 보이기</b>가 꺼져 있어서 고객 에디터에서는 아래 도움말·둘러보기가 모두 숨겨져 있어요. (<b>첫 방문 안내 팝업</b>은 따로 켜고 꺼요) 보이게 하려면 위 스위치를 켜고 저장하세요.</div>

        <div class="tp-feat-empty">위 기능에서 <b>섹션별 사용법</b>이나 <b>첫 방문 둘러보기</b>를 켜면 여기에 설정 칸이 나타나요.</div>

        <div class="tp-card tp-tour">
            <div class="tp-set" style="display:block">
                <div class="tp-tour-head">
                    <h3>첫 방문 둘러보기 단계</h3>
                    <label class="again"><input type="checkbox" id="tpAgain"> 저장하면 이미 본 고객에게도 다시 보여주기</label>
                </div>
                <div id="tpSteps"></div>
            </div>
        </div>

        <div class="tp-card tp-notice">
            <div class="tp-set" style="display:block">
                <div class="tp-tour-head">
                    <h3>첫 방문 안내 팝업</h3>
                    <label class="again"><input type="checkbox" id="tpNtAgain"> 저장하면 이미 본 고객에게도 다시 보여주기</label>
                </div>
                <p class="tp-nt-sub">에디터에 처음 들어온 고객에게 <b>기기에 맞는 안내 하나</b>를 한 번 띄워요. (휴대폰 → 모바일 안내, PC → PC 안내)<br>
                    위 <b>도움말 보이기</b>와 상관없이 여기 스위치로 따로 켜고 꺼요. 둘러보기가 켜져 있으면 둘러보기가 끝난 뒤에 나와요.</p>
                <div id="tpNotices"></div>
            </div>
        </div>

        <div class="tp-card tp-guide">
            <div class="tp-set" style="display:block">
                <div class="tp-tour-head"><h3>섹션별 사용법 카드</h3><span style="font-size:12px;color:#A29C94">섹션을 눌러 펼치고, 카드마다 움직이는 그림·설명·바탕 무늬를 고르세요. 새 섹션도 자동으로 나와요.</span></div>
                <div class="gl-quick"><span>펼친 칸 모양</span><button type="button" data-glay="list">기본</button><button type="button" data-glay="story">A 스토리</button><button type="button" data-glay="studio">B 스튜디오</button><button type="button" data-glay="board">C 스토리보드</button><button type="button" data-glay="timeline">D 타임라인</button><button type="button" class="gl-etc" data-open-etc>⚙ 기타 설정</button></div>
                <div id="tpGuides"><div class="tp-load"><i></i>섹션 목록을 불러오는 중…</div></div>
            </div>
        </div>

        <!-- 기타 설정 (섹션별 사용법 칸의 "⚙ 기타 설정"을 누르면 팝업) -->
        <div class="tp-pop" id="tpEtc" hidden>
            <div class="tp-pop-box" role="dialog" aria-modal="true" aria-labelledby="tpEtcTitle">
                <div class="tp-pop-head"><h3 id="tpEtcTitle">기타 설정</h3><button type="button" class="tp-pop-x" data-pop-close aria-label="닫기">✕</button></div>
                <div class="tx" style="margin:0 0 10px"><b style="font-size:13.5px">섹션별 사용법 · 펼친 칸 모양</b><small style="display:block;font-size:12px;color:#8A847B;margin-top:3px;line-height:1.6">이 화면에서 섹션을 펼쳤을 때 카드를 고치는 모양이에요. 고르고 저장하면 다음에도 그 모양으로 열려요. (고객 에디터 모양은 바뀌지 않고, 카드 바탕 무늬만 고객 에디터 "▶ 사용법"에 깔려요)</small></div>
                <div class="tp-lays">
                    <button type="button" class="tp-lay" data-glay="list"><span class="sk sk-list"><i></i><i></i><i></i></span><b>기본 목록</b><small>지금처럼 줄마다 목록에서 고르기</small></button>
                    <button type="button" class="tp-lay" data-glay="story"><span class="sk sk-story"><i></i><i></i><i></i></span><b>A. 스토리 카드</b><small>고객이 보는 카드 모양 그대로 가로로</small></button>
                    <button type="button" class="tp-lay" data-glay="studio"><span class="sk sk-studio"><em><i></i><i></i><i></i></em><u></u></span><b>B. 스튜디오 2단</b><small>목록 + 휴대폰 화면 자동 재생</small></button>
                    <button type="button" class="tp-lay" data-glay="board"><span class="sk sk-board"><i></i><i></i><i></i></span><b>C. 스토리보드</b><small>무늬 인덱스카드를 보드에 꽂은 느낌</small></button>
                    <button type="button" class="tp-lay" data-glay="timeline"><span class="sk sk-time"><i></i><i></i><i></i></span><b>D. 타임라인</b><small>순서를 선으로, 시범은 필름처럼</small></button>
                </div>
                <div class="tx" style="margin:20px 0 0;padding-top:16px;border-top:1px dashed #E3DAD0"><b style="font-size:13.5px">에디터 편집창·팝업창 바탕</b><small style="display:block;font-size:12px;color:#8A847B;margin-top:3px;line-height:1.6">바탕색·무늬는 <a href="admin_sections.php#panel" style="color:#9A6A0A;font-weight:700">섹션 순서 → 편집창 모양</a> 탭으로 옮겼어요.</small></div>
                <div class="tp-pop-foot"><span>고른 모양이 바로 뒤 화면에 보여요. 아래 <b>저장</b>을 눌러야 다음에도 유지돼요.</span><button type="button" class="tp-pop-ok" data-pop-close>확인</button></div>
            </div>
        </div>

        <h3 class="tp-h2">도움말 목록</h3>
        <div class="tp-tools">
            <input type="search" id="tpQ" placeholder="도움말 문구·섹션 이름 검색">
            <button type="button" class="tp-chip on" data-f="all">전체<em id="cAll"></em></button>
            <button type="button" class="tp-chip" data-f="on">켜짐<em id="cOn"></em></button>
            <button type="button" class="tp-chip" data-f="off">꺼짐<em id="cOff"></em></button>
            <button type="button" class="tp-chip" data-f="mod">수정함<em id="cMod"></em></button>
            <button type="button" class="tp-chip" data-f="demo">시범 있음<em id="cDemo"></em></button>
            <button type="button" class="tp-chip" data-f="new">새 팁<em id="cNew"></em></button>
            <div class="tp-bulk"><button type="button" id="tpAllOn">보이는 것 모두 켜기</button><button type="button" id="tpAllOff">모두 끄기</button></div>
        </div>

        <div class="tp-tabs" id="tpTabs" role="tablist">
            <button type="button" class="on" data-tab="sec" role="tab">섹션별 도움말<em id="tabSec"></em></button>
            <button type="button" data-tab="common" role="tab">여러 섹션 공통<em id="tabCom"></em></button>
        </div>
        <div id="tpOut"><div class="tp-load"><i></i>에디터에서 도움말을 모으는 중…</div></div>
        <div class="tp-bottom"></div>
    </div>

    <div class="savebar" id="saveBar"><span id="saveCnt">바뀐 내용이 있어요</span><button type="button" class="undo" id="undoAll">되돌리기</button><button type="button" class="save" id="saveAll">저장</button></div>
    <input type="file" id="tpFile" accept="image/gif,image/webp,image/png,image/jpeg,video/mp4,video/webm" hidden>
    <iframe id="tpHarvest" title="도움말 모으기" aria-hidden="true" tabindex="-1"></iframe>

<script src="assets/ld-dialog.js"></script>
<script src="assets/tip-demos.js"></script>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>;
    const SAVED = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE) ?>;
    const readonly = !!document.getElementById('admRo');
    const toast = m => window.LD ? LD.toast(m) : null;
    const say = m => window.LD ? LD.alert(m) : alert(m);
    const out = document.getElementById('tpOut');
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fmt = t => esc(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
    const TD = window.TipDemos || { LIST: [], html: () => '', auto: () => '', label: () => '' }; // assets/tip-demos.js (프리셋 47가지)
    const WHERE = { sections: 'PC: 왼쪽 섹션 목록 / 휴대폰: ☰ 버튼', expand: 'PC: 섹션 줄의 ⤢ 버튼 / 휴대폰: 화면 가운데 말풍선', preview: '청첩장 미리보기',
        history: '위쪽 ↺ ↻ 버튼', view: 'PC: 위쪽 표준·좁게·꽉채움·확대 / 휴대폰: 아래 왼쪽 표준·좁게', bezel: 'PC: 위쪽 테두리 / 휴대폰: 아래 왼쪽 테두리', save: '아래 임시저장·발행하기' };
    // 시범을 따로 안 정하면 문구를 보고 자동으로 고름 (에디터와 같은 규칙 - TipDemos.auto)
    const autoDemo = t => TD.auto(t);
    const effDemo = (t, c) => c.demo === 'none' ? '' : (c.demo || autoDemo(c.text || t.text));
    const savedTips = SAVED.tips && typeof SAVED.tips === 'object' ? SAVED.tips : {};
    const known = new Set(SAVED.known || []);

    // 지금 화면 상태
    let show = !!SAVED.show, feats = Object.assign({ fold: false, once: false, demo: false, tour: false, guide: false }, SAVED.features || {});
    let guides = JSON.parse(JSON.stringify(SAVED.guides && typeof SAVED.guides === 'object' && !Array.isArray(SAVED.guides) ? SAVED.guides : {})); // 바꾼 섹션만
    const gOpen = new Set();
    let steps = (SAVED.tour && SAVED.tour.steps || []).map(s => ({ id: s.id, on: !!s.on, title: s.title, text: s.text, defTitle: s.defTitle, defText: s.defText }));
    let again = false;
    // 첫 방문 안내 팝업 (editor_tips.php notices) - 종류는 고정, 켜기/끄기·문구만
    let notices = (SAVED.notices && SAVED.notices.items || []).map(n => ({ id: n.id, dev: n.dev, on: !!n.on, title: n.title, text: n.text, defTitle: n.defTitle, defText: n.defText }));
    let ntAgain = false;
    let TIPS = [], LABELS = {}, COLORS = {}, ORDER = [];
    let cur = {}, start = '';
    let filter = 'all', query = '';
    let tab = 'sec'; // 도움말 목록 탭: sec 섹션별 / common 여러 섹션 공통
    let tipOpen = null; // 섹션별 탭에서 펼친 섹션 (한 번에 하나)
    const editing = new Set(), demoing = new Set();
    const INIT = JSON.parse(JSON.stringify({ show, feats, steps, guides, notices }));

    const snapshot = () => JSON.stringify({ show, feats, steps, again, cur, guides, guideTex, gLayout, pTheme: typeof pTheme === 'undefined' ? null : pTheme, notices, ntAgain });
    const dirty = () => snapshot() !== start;

    function demoHtml(c, demo) {
        if (c.media) {
            const u = 'uploads/site/tips/' + encodeURIComponent(c.media);
            return /\.(mp4|webm)$/.test(c.media) ? `<div class="tip-demo media"><video src="${u}" autoplay muted loop playsinline></video></div>`
                : `<div class="tip-demo media"><img src="${u}" alt=""></div>`;
        }
        return TD.html(demo);
    }

    // 시범 고르기: 자동·없음 + 프리셋을 묶음별로 작은 움직이는 그림으로
    function pickerHtml(c, auto) {
        const tile = (key, label, thumb) => `<button type="button" data-g="${key}" class="${c.demo === key ? 'on' : ''}" title="${esc(label)}">${thumb}${esc(label)}</button>`;
        const groups = [];
        TD.LIST.forEach(([k, label, grp]) => { let g = groups.find(x => x.n === grp); if (!g) groups.push(g = { n: grp, items: [] }); g.items.push([k, label]); });
        return `<div class="gh">기본</div><div class="gs">`
            + tile('', '자동' + (auto ? ' · ' + TD.label(auto) : ' (맞는 게 없음)'), auto ? `<span class="th">${TD.html(auto)}</span>` : '<span class="th tx">∅</span>')
            + tile('none', '시범 없음', '<span class="th tx">—</span>') + '</div>'
            + groups.map(g => `<div class="gh">${esc(g.n)} <span style="font-weight:600;color:#B9B1A6">${g.items.length}</span></div><div class="gs">`
                + g.items.map(([k, label]) => tile(k, label, `<span class="th">${TD.html(k)}</span>`)).join('') + '</div>').join('');
    }

    // ---- 상단 설정 ----
    const showCb = document.getElementById('tpShow');
    const nEl = document.getElementById('tpPresetN'); if (nEl) nEl.textContent = TD.LIST.length;
    showCb.addEventListener('change', () => { show = showCb.checked; refresh(); });
    document.querySelectorAll('.tp-feat').forEach(b => b.addEventListener('click', e => {
        if (readonly) return;
        const go = e.target.closest('[data-go]');
        if (go && feats[b.dataset.feat]) { jumpTo(go.dataset.go); return; } // "↓ 아래에서 설정" - 끄지 않고 그 칸으로 이동
        feats[b.dataset.feat] = !feats[b.dataset.feat]; if (b.dataset.feat === 'demo') render(); else refresh();
        if (feats[b.dataset.feat] && (b.dataset.feat === 'guide' || b.dataset.feat === 'tour')) flash(b.dataset.feat === 'guide' ? 'tp-guide' : 'tp-tour');
    }));
    // 켜자마자 나타난 설정 칸을 잠깐 반짝여서 어디 생겼는지 알려줌 (화면은 안 움직임)
    function flash(cls) {
        const el = document.querySelector('.' + cls); if (!el) return;
        el.classList.remove('tp-flash'); void el.offsetWidth; el.classList.add('tp-flash');
    }
    function jumpTo(cls) {
        const el = document.querySelector('.' + cls); if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' }); flash(cls);
    }
    // 에디터와 같은 주소(loveday.kr)라서 여기서 지우면 이 브라우저로 에디터를 열 때 처음 방문처럼 보임
    document.getElementById('tpResetMine').addEventListener('click', () => {
        try { localStorage.removeItem('ldTipSeen'); localStorage.removeItem('ldTourVer'); localStorage.removeItem('ldNoticeSeen'); } catch (e) {}
        toast('지웠어요. 이 브라우저로 에디터를 새로 열면 처음 방문처럼 보여요');
    });
    const againCb = document.getElementById('tpAgain');
    againCb.addEventListener('change', () => { again = againCb.checked; refresh(); });
    if (readonly) { showCb.disabled = true; againCb.disabled = true; }

    // ---- 둘러보기 단계 ----
    const stepsEl = document.getElementById('tpSteps');
    function renderSteps() {
        stepsEl.innerHTML = steps.map((s, i) => `<div class="tp-step${s.on ? '' : ' off'}" data-i="${i}">
            <span class="no">${i + 1}</span>
            <label class="sw sm" title="이 단계 켜기/끄기"><input type="checkbox" data-step-on ${s.on ? 'checked' : ''} ${readonly ? 'disabled' : ''}><i></i></label>
            <div><input type="text" data-step-title maxlength="40" value="${esc(s.title)}" ${readonly ? 'disabled' : ''}>
                <textarea data-step-text maxlength="400" ${readonly ? 'disabled' : ''}>${esc(s.text)}</textarea>
                <div class="where">📍 ${esc(WHERE[s.id] || s.id)}<button type="button" data-step-reset ${(s.title !== s.defTitle || s.text !== s.defText) && !readonly ? '' : 'hidden'}>기본 문구로</button></div></div>
            <div class="pv"><div class="bub"><h5>${esc(s.title)}</h5><div>${fmt(s.text)}</div><div class="nx"><span>${i === steps.length - 1 ? '시작하기' : '다음'}</span></div></div></div>
        </div>`).join('');
    }
    stepsEl.addEventListener('input', e => {
        const row = e.target.closest('.tp-step'); if (!row) return;
        const s = steps[+row.dataset.i];
        if (e.target.matches('[data-step-title]')) s.title = e.target.value;
        if (e.target.matches('[data-step-text]')) s.text = e.target.value.replace(/\r/g, '');
        row.querySelector('.bub h5').textContent = s.title;
        row.querySelector('.bub > div').innerHTML = fmt(s.text);
        row.querySelector('[data-step-reset]').hidden = readonly || (s.title === s.defTitle && s.text === s.defText);
        refresh();
    });
    stepsEl.addEventListener('change', e => {
        const row = e.target.closest('.tp-step'); if (!row || !e.target.matches('[data-step-on]')) return;
        steps[+row.dataset.i].on = e.target.checked; row.classList.toggle('off', !e.target.checked); refresh();
    });
    stepsEl.addEventListener('click', e => {
        const row = e.target.closest('.tp-step'); if (!row || !e.target.closest('[data-step-reset]')) return;
        const s = steps[+row.dataset.i]; s.title = s.defTitle; s.text = s.defText; renderSteps(); refresh();
    });

    // ---- 첫 방문 안내 팝업 ----
    const ntEl = document.getElementById('tpNotices');
    const ntAgainCb = document.getElementById('tpNtAgain');
    ntAgainCb.addEventListener('change', () => { ntAgain = ntAgainCb.checked; refresh(); });
    if (readonly) ntAgainCb.disabled = true;
    function renderNotices() {
        ntAgainCb.checked = ntAgain;
        ntEl.innerHTML = notices.map((n, i) => `<div class="tp-step tp-nt${n.on ? '' : ' off'}" data-i="${i}">
            <span class="dev ${n.dev === 'pc' ? 'pc' : 'm'}">${n.dev === 'pc' ? '🖥 PC' : '📱 모바일'}</span>
            <label class="sw sm" title="이 안내 켜기/끄기"><input type="checkbox" data-nt-on ${n.on ? 'checked' : ''} ${readonly ? 'disabled' : ''}><i></i></label>
            <div><input type="text" data-nt-title maxlength="40" value="${esc(n.title)}" ${readonly ? 'disabled' : ''}>
                <textarea data-nt-text maxlength="400" ${readonly ? 'disabled' : ''}>${esc(n.text)}</textarea>
                <div class="where">📍 ${n.dev === 'pc' ? 'PC(마우스)로 처음 에디터를 열 때' : '휴대폰으로 처음 에디터를 열 때'}<button type="button" data-nt-reset ${(n.title !== n.defTitle || n.text !== n.defText) && !readonly ? '' : 'hidden'}>기본 문구로</button></div></div>
            <div class="pv nt"><div class="bub"><div class="k">${n.dev === 'pc' ? 'PC 편집 팁' : '모바일 편집 팁'}</div><h5>${esc(n.title)}</h5><div class="ntx">${fmt(n.text)}</div><div class="nx"><span>알겠어요</span></div></div></div>
        </div>`).join('') || '<p class="tp-nt-sub">안내 목록을 불러오지 못했어요.</p>';
    }
    ntEl.addEventListener('input', e => {
        const row = e.target.closest('.tp-nt'); if (!row) return;
        const n = notices[+row.dataset.i];
        if (e.target.matches('[data-nt-title]')) n.title = e.target.value;
        if (e.target.matches('[data-nt-text]')) n.text = e.target.value.replace(/\r/g, '');
        row.querySelector('.bub h5').textContent = n.title;
        row.querySelector('.bub .ntx').innerHTML = fmt(n.text);
        row.querySelector('[data-nt-reset]').hidden = readonly || (n.title === n.defTitle && n.text === n.defText);
        refresh();
    });
    ntEl.addEventListener('change', e => {
        const row = e.target.closest('.tp-nt'); if (!row || !e.target.matches('[data-nt-on]')) return;
        notices[+row.dataset.i].on = e.target.checked; row.classList.toggle('off', !e.target.checked); refresh();
    });
    ntEl.addEventListener('click', e => {
        const row = e.target.closest('.tp-nt'); if (!row || !e.target.closest('[data-nt-reset]')) return;
        const n = notices[+row.dataset.i]; n.title = n.defTitle; n.text = n.defText; renderNotices(); refresh();
    });

    // ---- 섹션별 사용법 카드 ----
    // 펼친 칸 모양(기타 설정): list 기본 목록 · story A 스토리 카드 · studio B 스튜디오 2단 · board C 스토리보드 · timeline D 타임라인
    // 모양만 다르고 고치는 데이터는 같다 (guides[섹션] = [{d 시범, t 설명, on}], guideTex[섹션] = 카드 바탕 무늬)
    const gEl = document.getElementById('tpGuides');
    const LAYOUTS = [['list', '기본 목록', '지금처럼 줄마다 고르기'], ['story', 'A. 스토리 카드', '고객이 보는 카드 모양 그대로 가로로'],
        ['studio', 'B. 스튜디오 2단', '목록 + 휴대폰 화면 자동 재생'], ['board', 'C. 스토리보드', '무늬 인덱스카드를 보드에 꽂은 느낌'], ['timeline', 'D. 타임라인', '1→2→3 순서를 선으로, 시범은 필름처럼']];
    const TEXS = TD.TEXTURES || [['plain', '없음']];
    let gLayout = LAYOUTS.some(l => l[0] === SAVED.guideLayout) ? SAVED.guideLayout : 'list';
    let guideTex = Object.assign({}, SAVED.guideTex && typeof SAVED.guideTex === 'object' && !Array.isArray(SAVED.guideTex) ? SAVED.guideTex : {});
    INIT.gLayout = gLayout; INIT.guideTex = JSON.parse(JSON.stringify(guideTex));
    // 에디터 팝업창 바탕 (기타 설정) - editor_tips.php popupTheme
    const PT_COLORS = [['#FBF8F2', '크림 (기본)'], ['#FFFFFF', '화이트'], ['#F6EFE4', '샌드'], ['#FDF3F4', '블러시'], ['#F1F6F1', '세이지'], ['#F0F5FB', '스카이'], ['#F5F2FB', '라벤더']];
    const PT_TEX = [['dot', '도트'], ['plain', '없음'], ['linen', '린넨'], ['grid', '모눈'], ['paper', '종이결']];
    var pTheme = Object.assign({ bg: '#FBF8F2', tex: 'dot' }, SAVED.popupTheme && typeof SAVED.popupTheme === 'object' ? SAVED.popupTheme : {});
    INIT.pTheme = JSON.parse(JSON.stringify(pTheme));
    function renderPT() {
        const sws = document.getElementById('tpPtSws'), tex = document.getElementById('tpPtTex'), prev = document.getElementById('tpPtPrev');
        if (!sws) return;
        const bg = String(pTheme.bg).toUpperCase(), isPreset = PT_COLORS.some(c => c[0] === bg);
        sws.innerHTML = PT_COLORS.map(([c, n]) => `<button type="button" data-ptc="${c}" class="${c === bg ? 'on' : ''}" style="background:${c}" title="${esc(n)}"${readonly ? ' disabled' : ''}></button>`).join('')
            + `<label class="${isPreset ? '' : 'on'}" style="--c:${isPreset ? 'transparent' : bg}" title="직접 고르기"><input type="color" value="${bg.toLowerCase()}"${readonly ? ' disabled' : ''}></label>`;
        tex.innerHTML = PT_TEX.map(([k, n]) => `<button type="button" data-ptt="${k}" class="${k === pTheme.tex ? 'on' : ''}"${readonly ? ' disabled' : ''}><i class="ldp-${k}" style="--pbg:${bg}"></i>${esc(n)}</button>`).join('');
        prev.className = 'tp-pt-prev ldp-' + pTheme.tex;
        prev.style.setProperty('--pbg', bg);
        const m = bg.match(/^#(..)(..)(..)$/), lum = m ? (0.299 * parseInt(m[1], 16) + 0.587 * parseInt(m[2], 16) + 0.114 * parseInt(m[3], 16)) / 255 : 1;
        document.getElementById('tpPtWarn').hidden = lum > 0.62;
    }
    document.addEventListener('click', e => {
        const c = e.target.closest('[data-ptc]'), t = e.target.closest('[data-ptt]');
        if (c && !readonly) { pTheme.bg = c.dataset.ptc; renderPT(); refresh(); }
        if (t && !readonly) { pTheme.tex = t.dataset.ptt; renderPT(); refresh(); }
    });
    document.addEventListener('input', e => {
        if (e.target.matches('#tpPtSws input[type=color]') && !readonly) { pTheme.bg = e.target.value.toUpperCase(); renderPT(); refresh(); }
    });
    renderPT();
    const gPick = {}, gSel = {}, gPlay = {}, gPlayAt = {}; // 섹션마다: 시범 고르기 창이 열린 카드 · 스튜디오에서 고른 카드 · 스튜디오 재생 중인 카드
    let palGrp = '', palQ = '';
    const PAL_GROUPS = []; TD.LIST.forEach(([k, l, g]) => { let x = PAL_GROUPS.find(y => y.n === g); if (!x) PAL_GROUPS.push(x = { n: g, items: [] }); x.items.push([k, l]); });
    const gList = id => guides[id] || (TD.guide ? TD.guide(id) : []);
    const texOf = id => guideTex[id] || 'plain';
    const presetOptions = sel => PAL_GROUPS.map(g => `<optgroup label="${esc(g.n)}">${g.items.map(([k, l]) => `<option value="${k}"${k === sel ? ' selected' : ''}>${esc(l)}</option>`).join('')}</optgroup>`).join('');
    // 움직이는 그림 무대 (tip-demo는 200×84 안에 그려짐 → 가운데 놓고 --s 배율로)
    const stg = (id, key, s, cls, attrs, inner) => `<div class="gl-stg tdx-${texOf(id)} ${cls || ''}" style="--s:${s}" ${attrs || ''}><div class="in">${TD.html(key)}</div>${inner || ''}</div>`;
    function renderGuides() {
        if (!ORDER.length) return;
        // 다시 그려도 시범 목록의 스크롤 자리·검색칸 입력은 그대로
        const keep = {}; gEl.querySelectorAll('[data-keep]').forEach(el => { keep[el.dataset.keep] = [el.scrollLeft, el.scrollTop]; });
        const fa = document.activeElement, fq = fa && fa.matches && fa.matches('[data-gq]') ? [fa.closest('.tp-gsec').dataset.sec, fa.selectionStart] : null;
        gEl.innerHTML = ORDER.map(id => {
            const list = gList(id), mod = !!guides[id];
            const n = list.filter(x => x.on !== false).length;
            return `<div class="tp-gsec gl-${gLayout}${gOpen.has(id) ? ' open' : ''}" data-sec="${esc(id)}" style="--sc:${esc(COLORS[id] || '#999')}">
                <button type="button" data-gtoggle><span class="dot" style="background:${esc(COLORS[id] || '#999')}"></span>${esc(LABELS[id] || id)} <small>카드 ${n}장</small>${mod ? '<span class="tp-tag mod">수정함</span>' : ''}${guideTex[id] ? `<span class="gl-texdot tdx-${esc(guideTex[id])}" title="카드 바탕 무늬"></span>` : ''}<span class="ar">›</span></button>
                <div class="tp-gbody">${gOpen.has(id) ? gBody(id, list, mod) : ''}</div>
            </div>`;
        }).join('');
        gEl.querySelectorAll('[data-keep]').forEach(el => { const k = keep[el.dataset.keep]; if (k) { el.scrollLeft = k[0]; el.scrollTop = k[1]; } });
        if (fq) { const q = gEl.querySelector(`.tp-gsec[data-sec="${fq[0]}"] [data-gq]`); if (q) { q.focus(); try { q.setSelectionRange(fq[1], fq[1]); } catch (e) {} } }
        document.querySelectorAll('[data-glay]').forEach(b => b.classList.toggle('on', b.dataset.glay === gLayout));
    }
    function gBody(id, list, mod) {
        const dis = readonly ? 'disabled' : '';
        const texs = `<div class="gl-bar"><span class="gl-texs"><span class="lb">카드 바탕</span>${TEXS.map(([k, l]) => `<button type="button" class="tdx-${k}${texOf(id) === k ? ' on' : ''}" data-gtex="${k}" title="${esc(l)}" ${dis}></button>`).join('')}</span>
            <span class="gl-hint">${gLayout === 'list' ? '' : gLayout === 'studio' ? '줄을 눌러 고르고, 아래에서 시범을 바꿔요' : '움직이는 그림을 누르면 시범을 바꿔요'}</span></div>`;
        const sw = (it, i) => `<label class="sw sm" title="이 카드 켜기/끄기"><input type="checkbox" data-gon data-i="${i}" ${it.on !== false ? 'checked' : ''} ${dis}><i></i></label>`;
        const mv = (i, a, b) => `<button type="button" class="gl-ib" data-gmv="-1" data-i="${i}" ${i === 0 || readonly ? 'disabled' : ''}>${a || '↑'}</button><button type="button" class="gl-ib" data-gmv="1" data-i="${i}" ${i === list.length - 1 || readonly ? 'disabled' : ''}>${b || '↓'}</button><button type="button" class="gl-ib" data-gdel data-i="${i}" ${dis}>✕</button>`;
        const cap = (it, i, rows) => `<textarea class="gl-cap" rows="${rows || 2}" data-gt data-i="${i}" maxlength="120" placeholder="설명 (예: 사진을 끌어 순서를 바꿔요)" ${dis}>${esc(it.t)}</textarea>`;
        const foot = `<div class="tp-gfoot"><button type="button" data-gadd ${dis}>+ 카드 추가</button>${mod ? `<button type="button" data-greset ${dis}>기본 카드로 되돌리기</button>` : ''}</div>`;
        const tools = `<div class="gl-ptools"><input type="search" data-gq placeholder="시범 이름으로 찾기 (예: 끌어, 사진)" value="${esc(palQ)}">
            <button type="button" class="gl-chip${palGrp ? '' : ' on'}" data-ggrp="">전체 ${TD.LIST.length}</button>${PAL_GROUPS.map(g => `<button type="button" class="gl-chip${palGrp === g.n ? ' on' : ''}" data-ggrp="${esc(g.n)}">${esc(g.n)}</button>`).join('')}</div>`;
        const items = () => TD.LIST.filter(([k, l, g]) => (!palGrp || g === palGrp) && (!palQ || l.includes(palQ)));
        const tile = (k, l, i) => `<button type="button" class="gl-tile${list[i] && list[i].d === k ? ' on' : ''}" data-gpick="${k}" data-i="${i}" ${dis}>${stg(id, k, .5)}<span>${esc(l)}</span></button>`;
        const palette = (i, keepKey) => tools + `<div class="gl-pal" data-keep="${keepKey}">${items().map(([k, l]) => tile(k, l, i)).join('') || '<p class="gl-none">맞는 시범이 없어요</p>'}</div>`;
        const pk = gPick[id] != null ? gPick[id] : -1;

        if (gLayout === 'story') {
            return texs + `<div class="gl-a-strip" data-keep="a-${id}">${list.map((it, i) => `<div class="gl-a-card${i === pk ? ' sel' : ''}${it.on === false ? ' off' : ''}">
                    <div class="gl-a-top"><span class="no">${i + 1}</span>${sw(it, i)}</div>
                    ${stg(id, it.d, .95, 'gl-a-scr', `data-gopen data-i="${i}"`, '<span class="chg">🎬 시범 바꾸기</span>')}
                    <div class="gl-a-name">${esc(TD.label(it.d))}</div>${cap(it, i, 3)}
                    <div class="gl-a-foot">${mv(i, '←', '→')}</div></div>`).join('')}</div>
                ${pk >= 0 && list[pk] ? `<div class="gl-sheet"><div class="gl-sheet-h"><span class="no">${pk + 1}</span>번 카드 시범 고르기<span style="flex:1"></span><button type="button" class="gl-chip" data-gopen data-i="${pk}">닫기</button></div>${palette(pk, 'ap-' + id)}</div>` : ''}` + foot;
        }
        if (gLayout === 'studio') {
            const sel = Math.min(gSel[id] || 0, Math.max(0, list.length - 1));
            return `<div class="gl-b"><div>${texs}${list.map((it, i) => `<div class="gl-b-row${i === sel ? ' sel' : ''}${it.on === false ? ' off' : ''}" data-gsel data-i="${i}">
                    <span class="no">${i + 1}</span>${stg(id, it.d, .52)}<input type="text" class="gl-cap" data-gt data-i="${i}" maxlength="120" value="${esc(it.t)}" placeholder="설명" ${dis}>
                    <span class="gl-ac">${sw(it, i)}${mv(i)}</span></div>`).join('')}${foot}
                    ${list[sel] ? `<div class="gl-b-pal"><h4>${sel + 1}번 카드 시범 · <span>${esc(TD.label(list[sel].d))}</span></h4>${palette(sel, 'bp-' + id)}</div>` : ''}</div>
                <div class="gl-b-side" data-side="${esc(id)}">${bSide(id)}</div></div>`;
        }
        if (gLayout === 'board') {
            return `<div class="gl-c-wrap">${texs}<div class="gl-c">${list.map((it, i) => `<div class="gl-c-card${it.on === false ? ' off' : ''}${pk === i ? ' pop' : ''}">
                    <span class="gl-c-pin">${i + 1}</span>
                    <div class="gl-c-tool">${mv(i)}</div>
                    ${stg(id, it.d, 1.05, 'gl-c-stage', `data-gopen data-i="${i}"`)}
                    <div class="gl-c-meta">🎬 ${esc(TD.label(it.d))} <em>· 눌러서 바꾸기</em></div>
                    ${cap(it, i, 2)}
                    <div class="gl-c-bot"><span>${it.on === false ? '숨김' : '보여요'}</span>${sw(it, i)}</div>
                    ${pk === i ? `<div class="gl-c-pop">${palette(i, 'cp-' + id)}</div>` : ''}</div>`).join('')}</div>${foot}</div>`;
        }
        if (gLayout === 'timeline') {
            const film = i => `<div class="gl-d-film"><div class="gl-d-hd">🎬 ${i + 1}번 시범 고르기<span style="flex:1"></span><input type="search" data-gq placeholder="찾기" value="${esc(palQ)}"></div>
                <div class="gl-d-row" data-keep="dp-${id}">${PAL_GROUPS.map(g => { const its = g.items.filter(([k, l]) => !palQ || l.includes(palQ)); return its.length ? `<span class="gl-d-grp">${esc(g.n)}</span>` + its.map(([k, l]) => tile(k, l, i)).join('') : ''; }).join('')}</div></div>`;
            return texs + `<div class="gl-d-list">${list.map((it, i) => `<div class="gl-d-step${pk === i ? ' sel' : ''}${it.on === false ? ' off' : ''}">
                    <span class="no">${i + 1}</span>${stg(id, it.d, .78, 'gl-d-stg', `data-gopen data-i="${i}"`)}
                    <div><div class="gl-d-name">${esc(TD.label(it.d))}</div>${cap(it, i, 2)}</div>
                    <span class="gl-ac">${sw(it, i)}${mv(i)}</span>
                    ${pk === i ? film(i) : ''}</div>`).join('')}</div>` + foot;
        }
        // 기본 목록 (예전 모양)
        return texs + list.map((it, i) => `<div class="tp-gi${it.on === false ? ' off' : ''}" data-i="${i}">
                <span class="no">${i + 1}</span>
                <span class="th"><span class="thx tdx-${texOf(id)}">${TD.html(it.d)}</span></span>
                <span class="fl"><select data-gd data-i="${i}" ${dis}>${presetOptions(it.d)}</select><input type="text" data-gt data-i="${i}" maxlength="120" value="${esc(it.t)}" placeholder="설명 (예: 사진을 끌어 순서를 바꿔요)" ${dis}></span>
                <span class="ac">${sw(it, i)}${mv(i)}</span>
            </div>`).join('') + foot;
    }
    // 스튜디오 오른쪽: 고객 에디터 "▶ 사용법"처럼 켜진 카드가 3초마다 넘어감
    function bSide(id) {
        const on = gList(id).filter(x => x.on !== false);
        const k = on.length ? (gPlay[id] || 0) % on.length : 0, it = on[k];
        return `<div class="gl-b-phone"><div class="gl-b-scr">
                <div class="gl-b-bar"><span class="pill">▶ ${esc(LABELS[id] || id)} 사용법</span><span class="ct">${on.length ? k + 1 : 0}/${on.length}</span><span class="x">✕</span></div>
                <div class="gl-b-prog"><i></i></div>
                ${it ? stg(id, it.d, 1.3, 'gl-b-stage') : '<div class="gl-b-stage gl-b-empty">켜진 카드가 없어요</div>'}
                <div class="gl-b-txt">${it ? fmt(it.t || TD.label(it.d)) : ''}</div>
                <div class="gl-b-nav">${on.map((_, j) => `<span class="${j === k ? 'on' : ''}"></span>`).join('')}</div></div></div>
            <p class="gl-b-cap">고객 에디터에서 보이는 모습 · 3초마다 넘어가요</p>`;
    }
    setInterval(() => {
        if (gLayout !== 'studio') return;
        gEl.querySelectorAll('.gl-b-side[data-side]').forEach(side => {
            if (side.matches(':hover')) return;
            const id = side.dataset.side, on = gList(id).filter(x => x.on !== false);
            const curIt = on.length ? on[(gPlay[id] || 0) % on.length] : null;
            if (curIt && TD.dur && Date.now() - (gPlayAt[id] || 0) < TD.dur(curIt.d)) return; // 한 바퀴가 긴 시범은 끝까지 보고 넘어감
            gPlay[id] = (gPlay[id] || 0) + 1; gPlayAt[id] = Date.now(); side.innerHTML = bSide(id);
        });
    }, 3200);
    // 고치는 순간 그 섹션 목록을 "바꾼 목록"으로 복사해 둠
    const editList = id => (guides[id] = guides[id] || JSON.parse(JSON.stringify(gList(id))));
    const setLayout = v => { if (!LAYOUTS.some(l => l[0] === v)) return; gLayout = v; Object.keys(gPick).forEach(k => delete gPick[k]); renderGuides(); refresh(); };
    document.addEventListener('click', e => {
        const b = e.target.closest('[data-glay]'); if (b) { setLayout(b.dataset.glay); return; }
        // 스토리보드: 카드 바깥을 누르면 시범 고르기 창 닫기
        if (gLayout === 'board' && Object.values(gPick).some(v => v >= 0) && !e.target.closest('.gl-c-card')) { Object.keys(gPick).forEach(k => delete gPick[k]); renderGuides(); }
    });
    gEl.addEventListener('click', e => {
        const sec = e.target.closest('.tp-gsec'); if (!sec) return;
        const id = sec.dataset.sec;
        if (e.target.closest('[data-gtoggle]')) { gOpen.has(id) ? gOpen.delete(id) : gOpen.add(id); renderGuides(); return; }
        const at = e.target.closest('[data-i]'), i = at ? +at.dataset.i : -1;
        const grp = e.target.closest('[data-ggrp]'); if (grp) { palGrp = grp.dataset.ggrp; renderGuides(); return; }
        if (e.target.closest('[data-gopen]')) { gPick[id] = gPick[id] === i ? -1 : i; renderGuides(); return; }
        if (e.target.closest('[data-gsel]') && !e.target.closest('input, label, button')) { gSel[id] = i; renderGuides(); return; }
        if (readonly) return;
        const tx = e.target.closest('[data-gtex]');
        if (tx) { if (tx.dataset.gtex === 'plain') delete guideTex[id]; else guideTex[id] = tx.dataset.gtex; renderGuides(); refresh(); return; }
        const pick = e.target.closest('[data-gpick]');
        if (pick) { editList(id)[i].d = pick.dataset.gpick; if (gLayout === 'board' || gLayout === 'timeline') gPick[id] = -1; renderGuides(); refresh(); return; }
        if (e.target.closest('[data-gadd]')) { const L = editList(id); L.push({ d: 'tap', t: '', on: true }); gPick[id] = gSel[id] = L.length - 1; renderGuides(); refresh(); }
        else if (e.target.closest('[data-greset]')) { delete guides[id]; gPick[id] = -1; renderGuides(); refresh(); }
        else if (e.target.closest('[data-gdel]')) { editList(id).splice(i, 1); gPick[id] = -1; gSel[id] = Math.max(0, Math.min(gSel[id] || 0, gList(id).length - 1)); renderGuides(); refresh(); }
        else if (e.target.closest('[data-gmv]')) {
            const L = editList(id), j = i + +e.target.closest('[data-gmv]').dataset.gmv;
            if (j >= 0 && j < L.length) { [L[i], L[j]] = [L[j], L[i]]; if (gSel[id] === i) gSel[id] = j; gPick[id] = -1; renderGuides(); refresh(); }
        }
    });
    gEl.addEventListener('change', e => {
        if (readonly || !e.target.closest('.tp-gsec')) return;
        const id = e.target.closest('.tp-gsec').dataset.sec, at = e.target.closest('[data-i]');
        if (!at) return;
        const i = +at.dataset.i, it = editList(id)[i];
        if (e.target.matches('[data-gd]')) { it.d = e.target.value; const th = e.target.closest('.tp-gi').querySelector('.thx'); if (th) th.innerHTML = TD.html(it.d); }
        if (e.target.matches('[data-gon]')) { it.on = e.target.checked; renderGuides(); }
        refresh();
    });
    gEl.addEventListener('input', e => {
        if (e.target.matches('[data-gq]')) { palQ = e.target.value.trim(); renderGuides(); return; }
        if (readonly || !e.target.matches('[data-gt]')) return;
        const id = e.target.closest('.tp-gsec').dataset.sec;
        editList(id)[+e.target.dataset.i].t = e.target.value;
        refresh();
    });

    // ---- 에디터에서 도움말 모으기 ----
    const frame = document.getElementById('tpHarvest');
    let gotIt = false;
    window.addEventListener('message', e => {
        if (e.origin !== location.origin || !e.data || e.data.type !== 'ld-tips' || gotIt) return;
        gotIt = true;
        TIPS = e.data.tips || []; LABELS = e.data.labels || {}; COLORS = e.data.colors || {}; ORDER = e.data.order || [];
        // 스티커는 섹션이 아니지만 에디터 😀 스티커 창에 "▶ 스티커 사용법"이 나와서, 섹션별 사용법 목록 맨 끝에 같이 둠
        if (!ORDER.includes('sticker')) { ORDER = ORDER.concat(['sticker']); LABELS.sticker = LABELS.sticker || '스티커 (꾸미기 😀)'; COLORS.sticker = COLORS.sticker || '#E0A72E'; }
        TIPS.forEach(t => {
            const s = savedTips[t.key] || {};
            cur[t.key] = { on: s.on !== false, text: s.text || '', demo: s.demo || '', media: s.media || '', offIn: Array.isArray(s.offIn) ? s.offIn.filter(b => t.blocks.includes(b)) : [] };
        });
        start = JSON.stringify({ show: INIT.show, feats: INIT.feats, steps: INIT.steps, again: false, cur, guides: INIT.guides, guideTex: INIT.guideTex, gLayout: INIT.gLayout, pTheme: INIT.pTheme, notices: INIT.notices, ntAgain: false }); // 처음 상태 (모으는 동안 바꾼 건 바뀐 걸로)
        renderGuides();
        setTimeout(() => frame.remove(), 50); // 다 모았으면 숨은 에디터는 닫음
        render();
    });
    frame.src = 'editor-prototype-v3-overlay.html?tips=harvest&_=' + Date.now();
    setTimeout(() => {
        if (gotIt) return;
        out.innerHTML = '<div class="tp-empty">에디터에서 도움말을 불러오지 못했어요.<br>editor-prototype-v3-overlay.html이 최신인지 확인하고 새로고침해주세요.</div>';
    }, 25000);

    // ---- 목록 ----
    const isMod = t => !!cur[t.key].text && cur[t.key].text !== t.text;
    const isNew = t => known.size > 0 && !known.has(t.key);
    const hasDemo = t => !!(cur[t.key].media || effDemo(t, cur[t.key]));
    function passes(t) {
        const c = cur[t.key];
        if (filter === 'on' && !c.on) return false;
        if (filter === 'off' && c.on) return false;
        if (filter === 'mod' && !isMod(t)) return false;
        if (filter === 'demo' && !hasDemo(t)) return false;
        if (filter === 'new' && !isNew(t)) return false;
        if (query) {
            const hay = (t.text + ' ' + c.text + ' ' + t.blocks.map(b => LABELS[b] || b).join(' ')).toLowerCase();
            if (!hay.includes(query)) return false;
        }
        return true;
    }
    function itemHtml(t) {
        const c = cur[t.key], mod = isMod(t), ed = editing.has(t.key), de = demoing.has(t.key);
        const dis = readonly ? 'disabled' : '';
        const shown = mod ? c.text : t.text;
        const names = t.blocks.map(b => LABELS[b] || b);
        // 여러 섹션 공통 팁: 섹션 칩을 눌러 섹션마다 켜고 끔 (불 들어온 칩 = 그 섹션 편집창에 보임)
        const offIn = c.offIn || [];
        const where = t.blocks.length > 1 ? `<span class="tp-secs">${t.blocks.map(b => { const on = !offIn.includes(b);
            return `<button type="button" class="tp-sec${on ? ' on' : ''}" data-sec-tg="${esc(b)}" style="--c:${esc(COLORS[b] || '#999')}" title="${on ? '눌러서 이 섹션에서 숨기기' : '눌러서 이 섹션에서 보이기'}" ${dis}><i></i>${esc(LABELS[b] || b)}</button>`; }).join('')}
            <span class="tp-secn">${t.blocks.length - offIn.length}/${t.blocks.length} 섹션에 보임</span></span>` : '';
        const ed2 = effDemo(t, c), auto = autoDemo(c.text || t.text);
        const demoTag = c.media ? '<span class="tp-tag demo">🎬 올린 파일</span>' : (ed2 ? `<span class="tp-tag demo">🎬 ${c.demo ? '' : '자동: '}${TD.label(ed2)}</span>` : '');
        return `<div class="tp-item${c.on ? '' : ' is-off'}" data-key="${t.key}">
            <label class="sw sm" title="이 도움말 켜기/끄기"><input type="checkbox" data-on ${c.on ? 'checked' : ''} ${dis}><i></i></label>
            <div class="tp-body">
                <div class="hb">${feats.demo ? demoHtml(c, ed2) : ''}${fmt(shown)}</div>
                ${ed ? `<div class="tp-edit"><textarea data-text>${esc(shown)}</textarea><p>**굵게** 처럼 별표 두 개로 감싸면 굵은 글씨, 줄바꿈은 그대로 보여요. 비우면 원래 문구.</p></div>` : ''}
                ${de ? `<div class="tp-demoed">${pickerHtml(c, auto)}
                    <div class="up"><button type="button" data-up>⬆ GIF·영상 올리기</button>${c.media ? '<button type="button" class="rm" data-rm>올린 파일 빼기</button>' : ''}<span>올린 파일이 있으면 동작 그림 대신 보여요 · 6MB 이하, 3~5초 짧은 영상 권장</span></div></div>` : ''}
                ${where ? `<div class="tp-where">${where}</div>` : ''}
                <div class="tp-meta">${isNew(t) ? '<span class="tp-tag new">새 팁</span>' : ''}${mod ? '<span class="tp-tag mod">수정함</span>' : ''}${demoTag}
                    <span class="sp"></span>
                    ${mod ? `<button type="button" data-reset ${dis}>원래 문구로</button>` : ''}
                    <button type="button" data-demo ${dis}>${de ? '시범 접기' : '🎬 시범'}</button>
                    <button type="button" data-edit ${dis}>${ed ? '접기' : '✎ 문구 수정'}</button>
                </div>
            </div>
        </div>`;
    }
    function render() {
        if (!gotIt) return;
        if (!TIPS.length) { out.innerHTML = '<div class="tp-empty">에디터에 도움말이 없어요.</div>'; refresh(); return; }
        // 탭: 섹션별(한 섹션에만 있는 도움말, 섹션 순서대로) / 여러 섹션 공통(섹션 칩으로 섹션마다 켜고 끔)
        const groups = [];
        const common = TIPS.filter(t => t.blocks.length > 1 && passes(t));
        const single = TIPS.filter(t => t.blocks.length === 1 && passes(t));
        document.getElementById('tabSec').textContent = single.length;
        document.getElementById('tabCom').textContent = common.length;
        document.querySelectorAll('#tpTabs [data-tab]').forEach(b => b.classList.toggle('on', b.dataset.tab === tab));
        if (tab === 'common') {
            if (common.length) groups.push({ title: '여러 섹션 공통', color: '#2B2320', items: common,
                note: '섹션 칩을 눌러 섹션마다 켜고 꺼요. <b>불이 들어온 칩</b>의 섹션 편집창에만 이 도움말이 보여요.' });
        } else {
            // 섹션마다 접어 두고, 누른 섹션 하나만 펼침 (검색 중에는 찾은 섹션을 모두 펼쳐서 보여줌)
            ORDER.forEach(id => {
                const items = single.filter(t => t.blocks[0] === id);
                if (items.length) groups.push({ id, title: LABELS[id] || id, color: COLORS[id] || '#999', items, fold: true });
            });
        }
        const isOpen = g => !g.fold || !!query || tipOpen === g.id;
        const summary = g => {
            const off = g.items.filter(t => !cur[t.key].on).length, mod = g.items.filter(isMod).length, nw = g.items.filter(isNew).length;
            return (nw ? `<span class="tp-tag new">새 팁 ${nw}</span>` : '') + (mod ? `<span class="tp-tag mod">수정 ${mod}</span>` : '') + (off ? `<span class="tp-tag">꺼짐 ${off}</span>` : '');
        };
        out.innerHTML = groups.length ? groups.map(g => `<section class="tp-grp${g.fold ? ' fold' : ''}${isOpen(g) ? ' open' : ''}"${g.id ? ` data-grp="${esc(g.id)}"` : ''}>
            ${g.fold ? `<button type="button" class="tp-grp-h" data-grp-open="${esc(g.id)}" aria-expanded="${isOpen(g)}"><span class="dot" style="background:${esc(g.color)}"></span><b>${esc(g.title)}</b><small>${g.items.length}</small>${summary(g)}<span class="ar">›</span></button>`
                : `<h3><span class="dot" style="background:${esc(g.color)}"></span>${esc(g.title)} <small>${g.items.length}</small></h3>`}
            ${g.note ? `<p class="tp-grp-note">${g.note}</p>` : ''}${isOpen(g) ? `<div class="tp-list">${g.items.map(itemHtml).join('')}</div>` : ''}</section>`).join('')
            : '<div class="tp-empty">조건에 맞는 도움말이 없어요.</div>';
        refresh();
    }
    function refresh() {
        showCb.checked = show;
        againCb.checked = again;
        document.body.classList.toggle('tips-off', !show);
        document.body.classList.toggle('tour-off', !feats.tour);
        document.body.classList.toggle('guide-off', !feats.guide);
        document.querySelectorAll('.tp-feat').forEach(b => b.classList.toggle('on', !!feats[b.dataset.feat]));
        document.querySelectorAll('[data-glay]').forEach(b => b.classList.toggle('on', b.dataset.glay === gLayout));
        const n = f => TIPS.filter(f).length;
        document.getElementById('cAll').textContent = TIPS.length;
        document.getElementById('cOn').textContent = n(t => cur[t.key].on);
        document.getElementById('cOff').textContent = n(t => !cur[t.key].on);
        document.getElementById('cMod').textContent = n(isMod);
        document.getElementById('cDemo').textContent = n(hasDemo);
        document.getElementById('cNew').textContent = n(isNew);
        document.getElementById('saveBar').classList.toggle('on', start !== '' && dirty());
    }

    out.addEventListener('change', e => {
        const it = e.target.closest('.tp-item'); if (!it || readonly) return;
        if (e.target.matches('[data-on]')) { cur[it.dataset.key].on = e.target.checked; it.classList.toggle('is-off', !e.target.checked); refresh(); }
    });
    out.addEventListener('input', e => {
        const it = e.target.closest('.tp-item'); if (!it || !e.target.matches('[data-text]')) return;
        const t = TIPS.find(x => x.key === it.dataset.key);
        const v = e.target.value.replace(/\r/g, '').trim();
        cur[t.key].text = v === t.text ? '' : v;
        it.querySelector('.hb').innerHTML = (feats.demo ? demoHtml(cur[t.key], effDemo(t, cur[t.key])) : '') + fmt(v || t.text);
        refresh();
    });
    let upKey = null;
    const fileIn = document.getElementById('tpFile');
    out.addEventListener('click', e => {
        const gh = e.target.closest('[data-grp-open]');
        if (gh) {
            const id = gh.dataset.grpOpen;
            tipOpen = tipOpen === id ? null : id;
            render();
            const sec = out.querySelector(`.tp-grp[data-grp="${id}"] .tp-grp-h`); // 펼친 섹션 머리가 화면 위로 밀려나 있으면 보이게
            if (sec && tipOpen && sec.getBoundingClientRect().top < 70) sec.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }
    });
    out.addEventListener('click', e => {
        const it = e.target.closest('.tp-item'); if (!it || readonly) return;
        const key = it.dataset.key, c = cur[key];
        const toggle = (set, k) => { if (set.has(k)) set.delete(k); else set.add(k); };
        if (e.target.closest('[data-edit]')) {
            toggle(editing, key); render();
            const ta = document.querySelector(`.tp-item[data-key="${key}"] textarea`);
            if (ta) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); }
        }
        const st = e.target.closest('[data-sec-tg]');
        if (st) {
            const b = st.dataset.secTg, L = c.offIn || (c.offIn = []), i = L.indexOf(b);
            if (i >= 0) L.splice(i, 1); else L.push(b);
            const on = i >= 0; st.classList.toggle('on', on); st.title = on ? '눌러서 이 섹션에서 숨기기' : '눌러서 이 섹션에서 보이기';
            const t = TIPS.find(x => x.key === key), n = it.querySelector('.tp-secn');
            if (n && t) n.textContent = `${t.blocks.length - L.length}/${t.blocks.length} 섹션에 보임`;
            refresh(); return;
        }
        if (e.target.closest('[data-demo]')) { toggle(demoing, key); render(); }
        if (e.target.closest('[data-reset]')) { c.text = ''; editing.delete(key); render(); }
        const g = e.target.closest('[data-g]');
        if (g) { c.demo = g.dataset.g; render(); }
        if (e.target.closest('[data-rm]')) { c.media = ''; render(); }
        if (e.target.closest('[data-up]')) { upKey = key; fileIn.value = ''; fileIn.click(); }
    });
    fileIn.addEventListener('change', () => {
        const f = fileIn.files[0]; if (!f || !upKey) return;
        if (f.size > 6 * 1024 * 1024) { say('6MB 이하 파일만 올릴 수 있어요.'); return; }
        const key = upKey, fd = new FormData();
        fd.set('csrf_token', CSRF); fd.set('ajax', '1'); fd.set('act', 'upload'); fd.set('media', f);
        toast('올리는 중…');
        fetch('admin_tips.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error || '올리지 못했어요.'); cur[key].media = j.file; render(); toast('올렸어요. 저장을 눌러야 적용돼요'); })
            .catch(e => say(e.message));
    });
    document.getElementById('tpQ').addEventListener('input', e => { query = e.target.value.trim().toLowerCase(); render(); });
    // ---- 기타 설정 팝업 ----
    const etc = document.getElementById('tpEtc');
    document.body.appendChild(etc); // 화면 안 다른 칸(겹침 순서)에 갇히지 않게 맨 바깥으로 - 위쪽 관리자 메뉴까지 덮음
    const openEtc = () => { etc.hidden = false; requestAnimationFrame(() => etc.classList.add('show')); document.addEventListener('keydown', etcKey); setTimeout(() => etc.querySelector('.tp-lay.on, .tp-lay')?.focus(), 60); };
    const closeEtc = () => { etc.classList.remove('show'); document.removeEventListener('keydown', etcKey); setTimeout(() => { etc.hidden = true; }, 200); };
    const etcKey = e => { if (e.key === 'Escape') closeEtc(); };
    document.addEventListener('click', e => { if (e.target.closest('[data-open-etc]')) openEtc(); });
    etc.addEventListener('click', e => { if (e.target === etc || e.target.closest('[data-pop-close]')) closeEtc(); });
    document.getElementById('tpTabs').addEventListener('click', e => { const b = e.target.closest('[data-tab]'); if (!b || b.dataset.tab === tab) return; tab = b.dataset.tab; render(); });
    document.querySelectorAll('.tp-chip').forEach(b => b.addEventListener('click', () => {
        filter = b.dataset.f;
        document.querySelectorAll('.tp-chip').forEach(x => x.classList.toggle('on', x === b));
        render();
    }));
    // 지금 목록에 보이는(검색·필터에 걸린) 것만 한꺼번에
    const bulk = on => { if (readonly) return; TIPS.filter(passes).forEach(t => { cur[t.key].on = on; }); render(); };
    document.getElementById('tpAllOn').addEventListener('click', () => bulk(true));
    document.getElementById('tpAllOff').addEventListener('click', () => bulk(false));
    if (readonly) document.querySelectorAll('.tp-bulk button').forEach(b => { b.disabled = true; });

    document.getElementById('undoAll').addEventListener('click', () => {
        const s = JSON.parse(start); show = s.show; feats = s.feats; steps = s.steps; again = s.again; cur = s.cur; guides = s.guides || {}; guideTex = s.guideTex || {}; gLayout = s.gLayout || 'list'; if (s.pTheme) pTheme = s.pTheme; renderPT();
        notices = s.notices || notices; ntAgain = !!s.ntAgain;
        editing.clear(); demoing.clear(); renderSteps(); renderNotices(); render(); renderGuides(); refresh();
    });
    const bar = document.getElementById('saveBar');
    document.getElementById('saveAll').addEventListener('click', () => {
        const tips = {};
        TIPS.forEach(t => {
            const c = cur[t.key], o = {};
            if (!c.on) o.on = false;
            if (c.text && c.text !== t.text) o.text = c.text;
            if (c.demo) o.demo = c.demo;
            if (c.media) o.media = c.media;
            if (c.offIn && c.offIn.length && t.blocks.length > 1) o.offIn = c.offIn.slice();
            tips[t.key] = o;
        });
        const ver = ((SAVED.tour && SAVED.tour.ver) || 1) + (again ? 1 : 0);
        const nver = ((SAVED.notices && SAVED.notices.ver) || 1) + (ntAgain ? 1 : 0);
        const fd = new FormData(); fd.set('csrf_token', CSRF); fd.set('ajax', '1');
        fd.set('cfg', JSON.stringify({ show, features: feats, tips, tour: { ver, steps: steps.map(s => ({ id: s.id, on: s.on, title: s.title, text: s.text })) },
            notices: { ver: nver, items: notices.map(n => ({ id: n.id, on: n.on, title: n.title, text: n.text })) }, guides, guideTex, guideLayout: gLayout, known: TIPS.map(t => t.key) }));
        bar.classList.add('busy');
        fetch('admin_tips.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json()).then(j => {
                if (!j.ok) throw new Error(j.error || '저장하지 못했어요.');
                guard.release(); toast('저장했어요. 고객 에디터에 바로 반영돼요');
                setTimeout(() => location.reload(), 700);
            })
            .catch(e => { bar.classList.remove('busy'); say(e.message); });
    });
    const guard = LD.guardLeave(() => start !== '' && dirty(), { message: '바꾼 도움말 설정이 아직 저장되지 않았어요.\n아래 "저장"을 누르지 않고 나가면 사라져요.' });
    start = snapshot();
    renderSteps();
    renderNotices();
    refresh();
})();
</script>
</body>
</html>
