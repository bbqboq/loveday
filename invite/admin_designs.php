<?php
/**
 * admin_designs.php - 관리자: 추천 디자인 (간편 템플릿)
 *
 *  새 청첩장 → 디자인 고르기 화면에 크게 보여 줄 디자인을 고르고 순서를 정한다 (최대 6개, 추천 3~4개).
 *  고르지 않은 디자인은 그 화면의 "다른 디자인 더 보기"를 눌러야 보인다.
 *  하나도 안 고르고 저장하면 예전처럼 모든 디자인이 한꺼번에 보인다.
 *  디자인 목록은 preset_list.php(presets 폴더)에서 읽는다. 저장: uploads/site/featured_presets.json (featured_presets.php)
 *  디자인 샘플: 디자인마다 [에디터로 꾸미기] → 실제 에디터(?sample=아이디)에서 사진·글·스티커·글자 위치까지 꾸며 저장하면
 *              고객이 그 디자인을 고를 때 꾸민 모습 그대로 시작한다. [원래대로]로 지울 수 있다. (sample_api.php)
 *              [+ 새 디자인 만들기] → 바탕 디자인을 골라 새 디자인을 하나 더 만든다 (한 번 저장해야 고객에게 보임)
 *  디자인 켜고 끄기: 카드마다 [고객에게 보이기] 스위치 → 바로 저장 (featured_presets.php preset_off_* → uploads/site/preset_off.json)
 *              끈 디자인은 고객 화면(디자인 고르기 · 섹션 스킨 · 모양 고르기)에서 빠지고 추천에서도 빠짐. 이미 그 디자인으로 만든 청첩장은 그대로
 *  꾸밈 스타일: 카드마다 [모던 | 예전] (예전 모습이 있는 디자인만) → 바로 저장 (featured_presets.php preset_look_* → uploads/site/preset_look.json)
 *              고객이 그 디자인을 고를 때 처음 꾸밈 스타일. [에디터로 꾸미기]로 꾸민 샘플은 샘플에서 고른 스타일이 먼저
 *  디자인 썸네일: [썸네일 만들기] → 에디터 ?thumbs=make (새 창, PC 크롬·엣지) 가 디자인마다 첫 화면·긴 화면 그림을 찍어 올림
 *              (dpk_thumbs.php → uploads/site/dpk/) - 고객 디자인 고르기 화면이 청첩장을 진짜로 그리지 않고 그림만 보여 줘서 빠름
 *  이름 바꾸기: 카드마다 [이름 바꾸기] → 이름·한 줄 설명 (바로 저장) - 기본 디자인은 featured_presets.php preset_names_* → uploads/site/preset_names.json
 *              (preset_list.php가 label·desc를 바꾸고 원래 값은 orig, [원래 이름으로] = 지움), 새로 만든 디자인은 sample_api.php meta
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장할 수 있다 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/featured_presets.php';
require_once __DIR__ . '/paper_settings.php'; // 종이 질감 그림 (올리기·지우기는 paper_settings.php로 바로)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    if (($_POST['act'] ?? '') === 'look') { // 꾸밈 스타일 기본 (바로 저장)
        $look = json_decode((string) ($_POST['look'] ?? ''), true);
        if (!is_array($look)) { echo json_encode(['ok' => false, 'error' => '저장할 내용이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
        try { $look = preset_look_save($look); }
        catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'look' => $look ?: new stdClass()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $act = (string) ($_POST['act'] ?? '');
    if (in_array($act, ['trash', 'restore', 'purge'], true)) { // 디자인 휴지통: 보내기 · 되살리기 · 지금 완전 삭제 (featured_presets.php design_trash_*)
        $id = (string) ($_POST['id'] ?? '');
        try {
            if ($act === 'trash') design_trash_put($id, (string) ($_POST['label'] ?? ''));
            elseif ($act === 'restore') design_trash_restore($id);
            else { if (!isset(design_trash_get()[$id])) throw new RuntimeException('휴지통에 없는 디자인이에요.'); design_trash_purge($id); if (isset(design_trash_get()[$id])) throw new RuntimeException('디자인 폴더를 지우지 못했어요 (서버 invite/presets 폴더 쓰기 권한을 확인해 주세요). 고객에게는 계속 안 보여요.'); }
        } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'trash' => design_trash_get() ?: new stdClass(), 'ids' => featured_presets_get()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_POST['act'] ?? '') === 'name') { // 기본 디자인 이름·설명 바꾸기 (바로 저장, 빈 값 = 원래대로)
        try { $names = preset_name_set((string) ($_POST['id'] ?? ''), (string) ($_POST['label'] ?? ''), (string) ($_POST['desc'] ?? '')); }
        catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'names' => $names ?: new stdClass()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($_POST['act'] ?? '') === 'off') { // 디자인 켜고 끄기 (바로 저장)
        $off = json_decode((string) ($_POST['off'] ?? ''), true);
        if (!is_array($off)) { echo json_encode(['ok' => false, 'error' => '저장할 내용이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
        try { $off = preset_off_save($off); $feat = array_values(array_filter(featured_presets_get(), fn($id) => !in_array($id, $off, true))); if ($feat !== featured_presets_get()) featured_presets_save($feat); }
        catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'off' => $off, 'ids' => featured_presets_get()], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ids = json_decode((string) ($_POST['ids'] ?? ''), true);
    if (!is_array($ids)) { echo json_encode(['ok' => false, 'error' => '저장할 내용이 올바르지 않아요.'], JSON_UNESCAPED_UNICODE); exit; }
    try { $saved = featured_presets_save($ids); }
    catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['ok' => true, 'ids' => $saved], JSON_UNESCAPED_UNICODE);
    exit;
}
try { design_trash_purge(); } catch (Throwable $e) {}
$csrf = csrf_token();
$cur = featured_presets_get();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>디자인 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
body { background: var(--ui-page, #F6F4F1); }
.fd-intro { font-size: 13px; color: #8A847B; margin: -14px 0 20px; line-height: 1.75; }
.fd-card { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; padding: 18px; margin: 0 0 18px; }
.fd-h { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin: 0 0 12px; }
.fd-h b { font-size: 15px; }
.fd-h small { font-size: 12.5px; color: #8A847B; }
.fd-sel { display: flex; gap: 10px; flex-wrap: wrap; min-height: 64px; }
.fd-sel .empty { font-size: 13px; color: #A29C94; padding: 18px 4px; }
.fd-chip { display: flex; align-items: center; gap: 10px; padding: 8px 10px 8px 8px; border: 1px solid #2B2320; border-radius: 14px; background: #fff; }
.fd-chip .no { width: 22px; height: 22px; border-radius: 50%; background: #2B2320; color: #fff; font-size: 11.5px; font-weight: 700; display: grid; place-items: center; flex: none; }
.fd-chip .sw { width: 34px; height: 44px; border-radius: 8px; flex: none; position: relative; overflow: hidden; box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); }
.fd-chip .sw i { position: absolute; left: 5px; right: 5px; top: 5px; height: 16px; border-radius: 3px; }
.fd-chip .sw u { position: absolute; left: 50%; bottom: 7px; transform: translateX(-50%); font-size: 9px; text-decoration: none; }
.fd-chip b { font-size: 13.5px; }
.fd-chip .ord { display: flex; gap: 2px; margin-left: 4px; }
.fd-chip .ord button { width: 26px; height: 26px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; border-radius: 8px; cursor: pointer; font-size: 12px; color: #6F6A63; }
.fd-chip .ord button:disabled { opacity: .3; cursor: default; }
.fd-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; }
.fd-p { position: relative; border: 1.5px solid var(--ui-line, #ECE8E2); border-radius: 14px; background: #fff; overflow: hidden; cursor: pointer; text-align: left; padding: 0; font: inherit; color: inherit; transition: border-color .15s, box-shadow .15s; }
.fd-p:hover { border-color: var(--ui-line, #C9C1B6); }
.fd-p.on { border-color: #2B2320; box-shadow: 0 0 0 1px #2B2320; }
.fd-p .pv { height: 150px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; position: relative; }
.fd-p .pv i { width: 64%; height: 48px; border-radius: 8px; }
.fd-p .pv span { font-size: 14px; }
/* 디자인 썸네일이 있으면 첫 화면 그림을 가로로 잘라 보여 줌 - 마우스를 올리면 천천히 아래까지 */
.fd-p .pv.th { height: 150px; display: block; overflow: hidden; }
.fd-p .pv.th img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: 50% 0%; transition: object-position 4s ease-in-out; }
.fd-p .top:hover .pv.th img, .fd-p .top:focus-visible .pv.th img { object-position: 50% 100%; }
.fd-chip .sw.th img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: 50% 0%; }
@media (prefers-reduced-motion: reduce) { .fd-p .pv.th img { transition: none; } }
.fd-p .pv .hrt { font-size: .7em; margin: 0 .35em; }
.fd-p .tx { display: block; padding: 10px 12px 12px; border-top: 1px solid var(--ui-soft, #F1EEE9); }
.fd-p .tx b { display: block; font-size: 13.5px; margin-bottom: 2px; }
.fd-p .tx small { font-size: 11.5px; color: #8A847B; line-height: 1.5; display: block; }
.fd-p .tx em.orig { display: block; margin-top: 3px; font-style: normal; font-size: 11px; color: #A39B90; }
.fd-rn { display: flex; flex-direction: column; gap: 7px; padding: 10px 12px 12px; border-top: 1px solid var(--ui-soft, #F1EEE9); }
.fd-rn label { display: flex; flex-direction: column; gap: 3px; font-size: 11.5px; font-weight: 700; color: #6F6A63; }
.fd-rn input { height: 34px; border: 1px solid var(--ui-line, #E3DED6); border-radius: 9px; padding: 0 10px; font: inherit; font-size: 13px; font-weight: 500; color: #2B2320; background: #fff; min-width: 0; }
.fd-rn input:focus { outline: none; border-color: #B8AE9F; }
.fd-rn .b { display: flex; gap: 6px; flex-wrap: wrap; }
.fd-rn .b button { height: 30px; padding: 0 11px; border-radius: 9px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; font: inherit; font-size: 12px; font-weight: 600; color: #4A453F; cursor: pointer; }
.fd-rn .b button.ok { background: #2B2320; border-color: #2B2320; color: #fff; }
.fd-p .ck { position: absolute; top: 8px; right: 8px; min-width: 24px; height: 24px; padding: 0 6px; border-radius: 99px; background: rgba(255,255,255,.92); border: 1px solid var(--ui-line, #DED8CF); font-size: 11.5px; font-weight: 700; display: grid; place-items: center; color: #A29C94; }
.fd-p.on .ck { background: #2B2320; border-color: #2B2320; color: #fff; }
.fd-bar { position: sticky; bottom: 0; display: flex; align-items: center; gap: 12px; justify-content: flex-end; padding: 14px 0; background: linear-gradient(rgba(246,244,241,0), var(--ui-page, #F6F4F1) 40%); }
.fd-bar span { font-size: 13px; color: #8A847B; margin-right: auto; }
.fd-bar button { height: 44px; padding: 0 22px; border-radius: 12px; border: 0; background: #2B2320; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.fd-bar button.ghost { background: #fff; color: #6F6A63; border: 1px solid var(--ui-line, #E5DED3); }
.fd-bar button:disabled { opacity: .45; cursor: default; }
.fd-toast { position: fixed; left: 50%; bottom: 80px; transform: translateX(-50%); background: #2B2320; color: #fff; padding: 10px 18px; border-radius: 99px; font-size: 13px; opacity: 0; transition: opacity .2s; pointer-events: none; }
.fd-toast.on { opacity: 1; }
.fd-p { cursor: default; display: flex; flex-direction: column; }
.fd-p .top { all: unset; display: block; cursor: pointer; position: relative; }
.fd-p .acts { display: flex; gap: 6px; padding: 0 12px 12px; flex-wrap: wrap; margin-top: auto; }
.fd-p .acts a, .fd-p .acts button { height: 30px; padding: 0 10px; border-radius: 9px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; font: inherit; font-size: 12px; font-weight: 600; color: #4A453F; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
.fd-p .acts a.main { background: #2B2320; border-color: #2B2320; color: #fff; }
.fd-p .acts button.del { color: #B24A4A; }
.fd-p .tag { position: absolute; left: 8px; top: 8px; font-size: 10.5px; font-weight: 700; padding: 3px 8px; border-radius: 99px; background: #E7F4EC; color: #2F7A4E; }
.fd-p .tag.draft { background: #FFF3DC; color: #9A6B12; }
.fd-new { display: grid; grid-template-columns: 1fr 1fr 1.4fr auto; gap: 8px; align-items: end; margin: 0 0 14px; padding: 14px; border: 1px dashed var(--ui-line, #DED8CF); border-radius: 14px; background: var(--ui-tint, #FBF9F6); }
.fd-new[hidden] { display: none; }
.fd-new label { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 700; color: #6F6A63; }
.fd-new select, .fd-new input { height: 40px; border: 1px solid var(--ui-line, #DED8CF); border-radius: 10px; padding: 0 10px; font: inherit; font-size: 13.5px; background: #fff; }
.fd-new button, .fd-addbtn { height: 40px; padding: 0 16px; border-radius: 10px; border: 0; background: #2B2320; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
.fd-addbtn { background: #fff; color: #2B2320; border: 1px solid #2B2320; }
@media (max-width: 760px) { .fd-new { grid-template-columns: 1fr; } }
.fd-pp { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.fd-ppc { border: 1px solid var(--ui-line, #ECE8E2); border-radius: 14px; overflow: hidden; background: #fff; display: flex; flex-direction: column; }
.fd-ppc .sw { height: 96px; background-size: 150px; background-blend-mode: multiply; border-bottom: 1px solid var(--ui-soft, #F1EEE9); }
.fd-ppc b { font-size: 13.5px; padding: 10px 12px 0; } .fd-ppc small { font-size: 11.5px; color: #8A847B; padding: 2px 12px 0; } .fd-ppc small.on { color: #2F7A4E; font-weight: 700; }
.fd-ppc .acts { display: flex; gap: 6px; flex-wrap: wrap; padding: 10px 12px 12px; margin-top: auto; }
.fd-ppc .acts label, .fd-ppc .acts button { height: 30px; padding: 0 10px; border-radius: 9px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; font: inherit; font-size: 12px; font-weight: 600; color: #4A453F; cursor: pointer; display: inline-flex; align-items: center; }
.fd-ppc .acts label { background: #2B2320; border-color: #2B2320; color: #fff; } .fd-ppc .acts button { color: #B24A4A; }
.fd-p.off .top { opacity: .45; }
.fd-p .vis { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #4A453F; cursor: pointer; user-select: none; }
.fd-p .vis input { position: absolute; opacity: 0; pointer-events: none; }
.fd-p .vis i { width: 34px; height: 20px; border-radius: 99px; background: #D9D3CA; position: relative; transition: background .15s; flex: none; }
.fd-p .vis i::after { content: ''; position: absolute; left: 2px; top: 2px; width: 16px; height: 16px; border-radius: 50%; background: #fff; box-shadow: 0 1px 2px rgba(0,0,0,.2); transition: transform .15s; }
.fd-p .vis input:checked + i { background: var(--ui-point, #B8935A); }
.fd-p .vis input:checked + i::after { transform: translateX(14px); }
.fd-p .vis input:focus-visible + i { outline: 2px solid #2B2320; outline-offset: 2px; }
.fd-p .lk { display: inline-flex; border: 1px solid var(--ui-line, #E5DED3); border-radius: 9px; overflow: hidden; height: 30px; }
.fd-p .lk button { border: 0; background: #fff; font: inherit; font-size: 12px; font-weight: 600; color: #8A847B; padding: 0 9px; cursor: pointer; }
.fd-p .lk button.on { background: #2B2320; color: #fff; }
.fd-p .tag.off { background: #EFECE7; color: #6F6A63; }
.fd-off-n { font-size: 12.5px; color: #8A847B; margin: -4px 0 12px; }
.fd-th { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 10px; margin-top: 12px; }
.fd-thc { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; color: #6F6A63; min-width: 0; }
.fd-thc img, .fd-thc i { width: 100%; aspect-ratio: 390 / 600; object-fit: cover; object-position: top; border-radius: 9px; background: var(--ui-tint, #F1ECE4); box-shadow: inset 0 0 0 1px rgba(0,0,0,.06); display: block; }
.fd-tha { display: flex; gap: 4px; }
.fd-tha a, .fd-tha button { flex: 1; height: 26px; padding: 0 4px; border: 1px solid var(--ui-line, #E5DED3); border-radius: 7px; background: #fff; font: inherit; font-size: 11.5px; font-weight: 600; color: #4A453F; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; white-space: nowrap; }
.fd-tha button { color: #B24A4A; } .fd-tha a:hover, .fd-tha button:hover { background: #F6F3EE; } .fd-tha button:disabled { opacity: .45; }
.fd-thc b { font-size: 12px; color: #2B2320; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .fd-thc small.no { color: #B0763A; }
.fd-trl { display: grid; gap: 8px; } .fd-trl .empty { font-size: 13px; color: #A29C94; margin: 0; }
.fd-tr { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 12px; }
.fd-tr b { font-size: 13.5px; } .fd-tr small { flex: 1 1 200px; font-size: 12px; color: #8A847B; } .fd-tr small em { color: #B24A4A; font-style: normal; }
.fd-tr span { display: flex; gap: 6px; } .fd-tr button { height: 32px; padding: 0 12px; border-radius: 9px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; font: inherit; font-size: 12.5px; font-weight: 700; color: #4A453F; cursor: pointer; } .fd-tr button.del { color: #B24A4A; font-weight: 600; }
.fd-th-acts { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.fd-th-acts a, .fd-th-acts button { height: 38px; padding: 0 16px; border-radius: 10px; border: 1px solid var(--ui-line, #E5DED3); background: #fff; font: inherit; font-size: 13px; font-weight: 700; color: #4A453F; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
.fd-th-acts a.main { background: #2B2320; border-color: #2B2320; color: #fff; } .fd-th-acts button { color: #B24A4A; font-weight: 600; }
.fd-pp-n { font-size: 12.5px; color: #8A847B; margin: 12px 0 0; line-height: 1.7; }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('designs', '디자인'); ?>
    <div class="wrap">
        <h2 class="page-title">디자인</h2>
        <p class="fd-intro">
            <b>에디터로 꾸미기</b>를 누르면 실제 에디터가 열려요. 사진·글·스티커·글자 위치까지 꾸며서 <b>샘플 저장</b>하면, 고객이 그 디자인을 고를 때 꾸민 모습 그대로 시작해요.<br>
            카드 윗부분을 누르면 <b>추천 디자인</b>에 넣고 빼요. 카드 아래 <b>모던 | 예전</b>은 고객이 그 디자인을 고를 때 처음 꾸밈 모습이에요(예전 = 리뉴얼 전 장식 · 고객이 바꿀 수 있어요). <b>고객에게 보이기</b> 스위치를 끄면 고객의 디자인 고르기 화면에서 그 디자인이 빠져요 (바로 저장 · 이미 그 디자인으로 만든 청첩장은 그대로). 추천은 새 청첩장 화면에 크게 보이고, 나머지는 “다른 디자인 더 보기”에 들어가요 (3~4개 추천, 최대 <?= FEATURED_MAX ?>개).
        </p>
        <div class="fd-card">
            <div class="fd-h"><b>추천 디자인 · 고객에게 보이는 순서</b><small id="fdCount"></small></div>
            <div class="fd-sel" id="fdSel"></div>
        </div>
        <div class="fd-card">
            <div class="fd-h"><b>전체 디자인</b><button type="button" class="fd-addbtn" id="fdAdd">+ 새 디자인 만들기</button></div>
            <form class="fd-new" id="fdNew" hidden>
                <label>바탕 디자인<select id="fdBase"></select></label>
                <label>이름<input id="fdLabel" maxlength="30" placeholder="예: 봄날 가든"></label>
                <label>한 줄 설명<input id="fdDesc" maxlength="60" placeholder="예: 연둣빛 정원에 꽃잎이 흩날리는"></label>
                <button type="submit">만들고 꾸미기</button>
            </form>
            <p class="fd-off-n" id="fdOffN"></p>
            <div class="fd-grid" id="fdGrid"><p style="color:#A29C94;font-size:13px">불러오는 중…</p></div>
        </div>
        <div class="fd-card" id="thumbs">
            <div class="fd-h"><b>디자인 썸네일 · 디자인 고르기 그림</b><small id="thN">불러오는 중…</small></div>
            <p class="fd-pp-n" style="margin:0 0 12px">고객이 간편 만들기에서 디자인을 고를 때, 디자인마다 청첩장을 진짜로 그리면 PC가 느려져요. 디자인을 다 꾸민 뒤 <b>[썸네일 만들기]</b>를 한 번 누르면 디자인마다 그림 두 장(첫 화면 · 긴 화면)을 찍어 두고, 고객 화면은 그 그림만 보여 줘서 빨라요.
                새 창이 열리면 <b>[바뀐 것만 찍기]</b>(처음엔 전부)를 눌러 주세요. PC 크롬·엣지는 묻는 <b>'이 탭 공유'</b>에서 [공유]를 누르면 화면을 그대로 찍고, <b>휴대폰</b>에서는 청첩장을 그림으로 바꿔서 찍어요 (디자인 하나에 몇 초 · 다 찍을 때까지 화면을 켜 두세요). 디자인·샘플·메인 사진 예시를 바꾸면 그 디자인 그림은 '예전 그림'이 되어 고객 화면에선 예전처럼 진짜로 그려요 - 다시 만들어 주세요.</p>
            <div class="fd-th-acts"><a class="main" href="editor-prototype-v3-overlay.html?thumbs=make" target="_blank" rel="noopener">썸네일 만들기 (새 창)</a><button type="button" id="thClear" hidden>썸네일 모두 지우기</button></div>
            <div class="fd-th" id="thGrid"></div>
        </div>
        <div class="fd-card" id="trash">
            <div class="fd-h"><b>휴지통 · 지운 디자인</b><small id="fdTrashN"></small></div>
            <p class="fd-pp-n" style="margin:0 0 12px">디자인 카드의 [휴지통으로]를 누른 디자인이에요. 고객 디자인 고르기 화면엔 안 보이고, <b><?= (int) DESIGN_TRASH_DAYS ?>일 뒤 저절로 완전히 지워져요</b> (관리자가 꾸민 샘플·썸네일 그림도 같이). 이미 그 디자인으로 만든 청첩장의 내용은 그대로예요.</p>
            <div class="fd-trl" id="fdTrash"></div>
        </div>
        <div class="fd-card">
            <div class="fd-h"><b>종이 질감 그림</b><small>청첩장 바탕 '종이 질감'에 깔리는 무늬 · 올리면 바로 저장돼요</small></div>
            <div class="fd-pp" id="fdPP"></div>
            <p class="fd-pp-n">그림을 올리면 기본 무늬 대신 그 그림이 깔려요 (청첩장 바탕 · 섹션 스킨 종이 · 고르기 칸 견본 모두). 300px 크기로 이어서 반복되고 바탕색에 곱해져서 깔리니, 이어 붙여도 이음새가 안 보이는 밝은 종이 무늬 그림(가로·세로 600~1200px)이 좋아요. [기본 무늬로]를 누르면 처음 무늬로 돌아가요.</p>
        </div>
        <div class="fd-bar"><span id="fdState"></span><button type="button" class="ghost" id="fdReset">추천 처음 기본값으로</button><button type="button" id="fdSave" disabled>추천 저장</button></div>
    </div>
    <div class="fd-toast" id="fdToast"></div>
<script src="assets/invite-blocks.js?v=<?= @filemtime(__DIR__ . '/assets/invite-blocks.js') ?: time() ?>"></script>
<script>
(() => {
const CSRF = <?= json_encode($csrf) ?>;
// ---- 종이 질감 그림 ----
let PAPERS = <?= json_encode(paper_settings_get() ?: new stdClass(), JSON_UNESCAPED_SLASHES) ?>;
const PAPER_NAMES = { beige: '코튼지', white: '수채화지', hanji: '한지', linen: '린넨', kraft: '크래프트' };
function drawPapers() {
    const P = (window.InviteBlocks && InviteBlocks.BG_PAPERS) || {}, box = document.getElementById('fdPP');
    box.innerHTML = Object.keys(PAPER_NAMES).map(k => `<div class="fd-ppc"><span class="sw" data-sw="${k}"></span><b>${(P[k] && P[k].label) || PAPER_NAMES[k]}</b>
        <small class="${PAPERS[k] ? 'on' : ''}">${PAPERS[k] ? '올린 그림을 쓰는 중' : '기본 무늬'}</small>
        <span class="acts"><label>${PAPERS[k] ? '그림 바꾸기' : '그림 올리기'}<input type="file" accept="image/jpeg,image/png,image/webp" data-pp="${k}" hidden></label>${PAPERS[k] ? `<button type="button" data-ppx="${k}">기본 무늬로</button>` : ''}</span></div>`).join('');
    box.querySelectorAll('[data-sw]').forEach(el => { const k = el.dataset.sw, p = P[k] || {}; el.style.backgroundColor = p.bg || '#F4ECDF'; el.style.backgroundImage = PAPERS[k] ? `url('${PAPERS[k]}')` : (p.img0 || p.img || 'none'); });
    const send = (k, file) => {
        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('key', k); if (file) fd.append('img', file); else fd.append('remove', '1');
        return fetch('paper_settings.php', { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => { if (!d.ok) throw new Error(d.error || '저장하지 못했어요'); if (d.url) PAPERS[k] = d.url; else delete PAPERS[k]; drawPapers(); });
    };
    box.querySelectorAll('[data-pp]').forEach(inp => inp.addEventListener('change', () => { const f = inp.files && inp.files[0]; if (!f) return; send(inp.dataset.pp, f).then(() => toast('종이 그림을 저장했어요. 청첩장에 바로 반영돼요')).catch(e => toast(e.message)); }));
    box.querySelectorAll('[data-ppx]').forEach(b => b.addEventListener('click', () => { if (!confirm('기본 무늬로 되돌릴까요?')) return; send(b.dataset.ppx, null).then(() => toast('기본 무늬로 되돌렸어요')).catch(e => toast(e.message)); }));
}
const DEFAULT = <?= json_encode(FEATURED_DEFAULT) ?>, MAX = <?= (int) FEATURED_MAX ?>;
let OFF = <?= json_encode(preset_off_get()) ?>;
let TRASH = <?= json_encode(design_trash_get() ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>; // 휴지통에 있는 디자인 (목록·추천에서 뺌)
const TRASH_DAYS = <?= (int) DESIGN_TRASH_DAYS ?>;
let LOOK = <?= json_encode(preset_look_get() ?: new stdClass()) ?>;
const IB = window.InviteBlocks || {};
const lookDeco = p => { const L = IB.THEME_LOOKS || {}; return (p.sectionLook || L[p.__base] || L[p.id] || ''); };
const hasOld = p => (IB.OLD_DECOS || []).includes(lookDeco(p));
let sel = <?= json_encode($cur) ?>, saved = JSON.stringify(sel), presets = [], samples = {}, RENAME = ''; // RENAME = 이름을 고치는 중인 디자인 id
const $ = id => document.getElementById(id);
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const toast = m => { const t = $('fdToast'); t.textContent = m; t.classList.add('on'); clearTimeout(t._t); t._t = setTimeout(() => t.classList.remove('on'), 2200); };
drawPapers();
// 전체 디자인 = 폴더 프리셋 + 새로 만든 샘플 (바탕 디자인의 색을 빌려 보여줌)
function designs() {
    const extra = Object.values(samples).filter(x => x.custom).map(x => { const b = presets.find(p => p.id === x.base) || {}; return Object.assign({}, b, { id: x.id, label: x.label, desc: x.desc, no: '', __custom: true, __base: x.base }); });
    return presets.concat(extra).filter(p => !TRASH[p.id]); // 휴지통에 있는 건 아래 '휴지통' 칸에만
}
function trashPost(act, id, label) {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', act); fd.append('id', id); if (label) fd.append('label', label);
    return fetch('admin_designs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (!d.ok) throw new Error(d.error || '실패했어요'); TRASH = d.trash || {}; const dirty = JSON.stringify(sel) !== saved; saved = JSON.stringify(d.ids); if (!dirty) sel = d.ids.slice(); else sel = sel.filter(x => !TRASH[x]); return d; });
}
function drawTrash() {
    const box = $('fdTrash'), ids = Object.keys(TRASH); if (!box) return;
    $('fdTrashN').textContent = ids.length ? `${ids.length}개 · 넣은 날부터 ${TRASH_DAYS}일 뒤 저절로 완전 삭제` : '비어 있어요';
    const left = t => Math.max(0, Math.ceil(((t.at || 0) + TRASH_DAYS * 86400 - Date.now() / 1000) / 86400));
    box.innerHTML = ids.length ? ids.map(id => { const t = TRASH[id]; return `<div class="fd-tr"><b>${esc(t.label || id)}</b><small>${t.kind === 'preset' ? '기본 디자인' : '새로 만든 디자인'} · ${left(t)}일 뒤 완전 삭제${t.fail ? ' · <em>폴더를 못 지웠어요 (서버 폴더 권한)</em>' : ''}</small>
        <span><button type="button" data-tr-back="${esc(id)}">되살리기</button><button type="button" class="del" data-tr-purge="${esc(id)}">지금 완전히 지우기</button></span></div>`; }).join('') : '<p class="empty">휴지통에 넣은 디자인이 없어요.</p>';
    box.querySelectorAll('[data-tr-back]').forEach(b => b.onclick = () => trashPost('restore', b.dataset.trBack).then(() => Promise.all([loadSamples(), loadThumbs()])).then(() => { draw(); toast('되살렸어요. 고객 화면에 다시 보여요'); }).catch(e => toast(e.message)));
    box.querySelectorAll('[data-tr-purge]').forEach(b => b.onclick = () => {
        if (!confirm(`'${(TRASH[b.dataset.trPurge] || {}).label || b.dataset.trPurge}' 디자인을 지금 완전히 지울까요? 되살릴 수 없어요.\n(이미 이 디자인으로 만든 청첩장의 내용은 그대로예요)`)) return;
        b.disabled = true; trashPost('purge', b.dataset.trPurge).then(() => Promise.all([loadSamples(), loadThumbs()])).then(() => { draw(); toast('완전히 지웠어요'); }).catch(e => { b.disabled = false; toast(e.message); draw(); });
    });
}
const byId = id => designs().find(p => p.id === id);
const canFeature = p => !p.__custom || (samples[p.id] && samples[p.id].saved);
function sw(p) { const pl = p.palette || {}; if (thOf(p.id)) return `<span class="sw th" style="background:${esc(pl.bg)}"><img src="${esc(thOf(p.id).c)}" alt="" loading="lazy"></span>`; return `<span class="sw" style="background:${esc(pl.bg)}"><i style="background:linear-gradient(150deg, ${esc(pl.line)}, ${esc(pl.accent)})"></i><u style="color:${esc(pl.accent)}">♥</u></span>`; }
function post(a, data) {
    const fd = new FormData(); fd.append('csrf_token', CSRF); Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
    return fetch('sample_api.php?a=' + a, { method: 'POST', body: fd, headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => { if (!d.ok) throw new Error(d.error || '실패했어요'); return d; });
}
function loadSamples() {
    return fetch('sample_api.php?a=admin_list', { cache: 'no-store', headers: { Accept: 'application/json' } }).then(r => r.json()).then(d => { samples = {}; (d.items || []).forEach(x => { samples[x.id] = x; }); });
}
function draw() {
    sel = sel.filter(id => byId(id));
    $('fdSel').innerHTML = sel.length ? sel.map((id, i) => { const p = byId(id); return `<div class="fd-chip"><span class="no">${i + 1}</span>${sw(p)}<b>${esc(p.label)}</b>
        <span class="ord"><button type="button" data-mv="${i}" data-d="-1" ${i === 0 ? 'disabled' : ''} aria-label="앞으로">←</button><button type="button" data-mv="${i}" data-d="1" ${i === sel.length - 1 ? 'disabled' : ''} aria-label="뒤로">→</button><button type="button" data-rm="${esc(id)}" aria-label="빼기">✕</button></span></div>`; }).join('')
        : '<p class="empty">고른 디자인이 없어요 → 고객 화면에 모든 디자인이 한꺼번에 보여요.</p>';
    $('fdCount').textContent = `${sel.length} / ${MAX}개`;
    const offN = designs().filter(p => OFF.includes(p.id)).length;
    $('fdOffN').textContent = offN ? `고객에게 안 보이는 디자인 ${offN}개 · 보이는 디자인 ${designs().length - offN}개` : `모든 디자인 ${designs().length}개가 고객에게 보여요`;
    $('fdGrid').innerHTML = designs().map(p => { const i = sel.indexOf(p.id), pl = p.palette || {}, sm = samples[p.id], off = OFF.includes(p.id);
        const tag = off ? `<span class="tag off">고객에게 안 보임</span>` : p.__custom ? (sm && sm.saved ? `<span class="tag">새 디자인</span>` : `<span class="tag draft">아직 저장 전</span>`) : (sm && sm.saved ? `<span class="tag">꾸민 샘플 · ${esc(sm.updated)}</span>` : '');
        return `<div class="fd-p ${i >= 0 ? 'on' : ''}${off ? ' off' : ''}" data-id="${esc(p.id)}">
            <button type="button" class="top" data-fav="${esc(p.id)}" title="추천에 넣기/빼기">${thOf(p.id) ? `<span class="pv th" style="background:${esc(pl.bg)}"><img src="${esc(thOf(p.id).c)}" alt="" loading="lazy" draggable="false"></span>` : `<span class="pv" style="background:${esc(pl.bg)};color:${esc(pl.ink)};font-family:${esc(pl.headFont || 'serif')}"><i style="background:linear-gradient(150deg, ${esc(pl.line)}, ${esc(pl.accent)})"></i><span>민준<span class="hrt" style="color:${esc(pl.accent)}">♥</span>서연</span></span>`}
            ${tag}<span class="ck">${i >= 0 ? i + 1 : '★'}</span>
            ${RENAME === p.id ? '' : `<span class="tx"><b>${p.no ? String(p.no).padStart(2, '0') + ' ' : ''}${esc(p.label)}</b><small>${esc(p.desc || '')}${p.__custom ? ` · 바탕: ${esc((presets.find(x => x.id === p.__base) || {}).label || p.__base)}` : ''}</small>${p.orig ? `<em class="orig">원래 이름: ${esc(p.orig.label)}</em>` : ''}</span>`}</button>
            ${RENAME === p.id ? `<form class="fd-rn" data-rn-form="${esc(p.id)}"><label>이름<input name="label" maxlength="30" value="${esc(p.label)}" placeholder="${esc((p.orig || p).label)}" required></label><label>한 줄 설명<input name="desc" maxlength="120" value="${esc(p.desc || '')}" placeholder="${esc((p.orig || {}).desc || '고객 디자인 고르기 화면에 보이는 설명')}"></label>
                <span class="b"><button type="submit" class="ok">저장</button><button type="button" data-rn-x>취소</button>${p.orig ? '<button type="button" data-rn-orig>원래 이름으로</button>' : ''}</span></form>` : ''}
            <div class="acts"><a class="main" href="editor-prototype-v3-overlay.html?sample=${encodeURIComponent(p.id)}" target="_blank" rel="noopener">에디터로 꾸미기</a>
            <button type="button" data-rename="${esc(p.id)}" title="고객 디자인 고르기 화면에 보이는 이름·설명">이름 바꾸기</button>${!p.__custom && sm && sm.saved ? `<button type="button" class="del" data-reset="${esc(p.id)}">원래대로</button>` : ''}<button type="button" class="del" data-trash="${esc(p.id)}" title="휴지통에 ${TRASH_DAYS}일 보관한 뒤 저절로 완전 삭제">휴지통으로</button>
            ${hasOld(p) ? `<span class="lk" role="group" aria-label="꾸밈 스타일 기본" title="고객이 이 디자인을 고를 때 처음 꾸밈 모습 (고객이 바꿀 수 있어요)"><button type="button" data-lk="${esc(p.id)}" data-v="modern" class="${LOOK[p.id] === 'old' ? '' : 'on'}">모던</button><button type="button" data-lk="${esc(p.id)}" data-v="old" class="${LOOK[p.id] === 'old' ? 'on' : ''}">예전</button></span>` : ''}
            <label class="vis" title="끄면 고객의 디자인 고르기 화면에서 빠져요"><input type="checkbox" data-vis="${esc(p.id)}" ${off ? '' : 'checked'}><i></i>고객에게 보이기</label></div></div>`; }).join('');
    $('fdGrid').querySelectorAll('[data-lk]').forEach(b => b.onclick = () => {
        const next = Object.assign({}, LOOK); if (b.dataset.v === 'old') next[b.dataset.lk] = 'old'; else delete next[b.dataset.lk];
        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'look'); fd.append('look', JSON.stringify(next));
        fetch('admin_designs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (!d.ok) throw new Error(d.error || '저장하지 못했어요');
            LOOK = d.look || {}; draw(); toast(b.dataset.v === 'old' ? '이 디자인은 예전 꾸밈으로 시작해요' : '이 디자인은 모던 꾸밈으로 시작해요');
        }).catch(e => toast(e.message));
    });
    $('fdGrid').querySelectorAll('[data-vis]').forEach(inp => inp.onchange = () => {
        const id = inp.dataset.vis, next = inp.checked ? OFF.filter(x => x !== id) : OFF.concat(id);
        if (!inp.checked && designs().every(p => p.id === id || next.includes(p.id))) { inp.checked = true; toast('디자인은 하나 이상 보여야 해요'); return; }
        const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'off'); fd.append('off', JSON.stringify(next));
        inp.disabled = true;
        fetch('admin_designs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (!d.ok) throw new Error(d.error || '저장하지 못했어요');
            OFF = d.off; const dirty = JSON.stringify(sel) !== saved; saved = JSON.stringify(d.ids); if (!dirty) sel = d.ids.slice(); else sel = sel.filter(x => !OFF.includes(x));
            draw(); toast(inp.checked ? '고객에게 다시 보여요' : '고객 화면에서 뺐어요 (추천에서도 빠짐)');
        }).catch(e => { toast(e.message); draw(); });
    });
    $('fdSel').querySelectorAll('[data-mv]').forEach(b => b.onclick = () => { const i = +b.dataset.mv, j = i + +b.dataset.d; [sel[i], sel[j]] = [sel[j], sel[i]]; draw(); });
    $('fdSel').querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { sel = sel.filter(x => x !== b.dataset.rm); draw(); });
    $('fdGrid').querySelectorAll('[data-fav]').forEach(b => b.onclick = () => {
        const id = b.dataset.fav, p = byId(id);
        if (sel.includes(id)) sel = sel.filter(x => x !== id);
        else if (OFF.includes(id)) { toast('고객에게 안 보이는 디자인은 추천에 넣을 수 없어요 - 먼저 [고객에게 보이기]를 켜 주세요'); return; }
        else if (!canFeature(p)) { toast('새 디자인은 에디터에서 한 번 저장해야 추천에 넣을 수 있어요'); return; }
        else if (sel.length >= MAX) { toast(`최대 ${MAX}개까지 고를 수 있어요`); return; }
        else sel.push(id);
        draw();
    });
    $('fdGrid').querySelectorAll('[data-reset]').forEach(b => b.onclick = () => {
        const id = b.dataset.reset, custom = samples[id] && samples[id].custom;
        if (!confirm(custom ? '이 새 디자인을 지울까요? (이미 이 디자인으로 만든 청첩장은 그대로예요)' : '꾸민 샘플을 지우고 원래 디자인으로 되돌릴까요?')) return;
        post('reset', { id }).then(() => { if (custom) { sel = sel.filter(x => x !== id); } return loadSamples(); }).then(() => { draw(); toast(custom ? '지웠어요' : '원래대로 되돌렸어요'); }).catch(e => toast(e.message));
    });
    $('fdGrid').querySelectorAll('[data-trash]').forEach(b => b.onclick = () => {
        const id = b.dataset.trash, p = byId(id) || {};
        if (designs().every(x => x.id === id || OFF.includes(x.id))) { toast('고객에게 보이는 디자인이 하나는 있어야 해요'); return; }
        if (!confirm(`'${p.label || id}' 디자인을 휴지통으로 옮길까요?\n고객 디자인 고르기 화면에서 바로 빠지고, ${TRASH_DAYS}일 뒤 저절로 완전히 지워져요. 그 전엔 아래 '휴지통'에서 되살릴 수 있어요.\n(이미 이 디자인으로 만든 청첩장은 그대로예요)`)) return;
        trashPost('trash', id, p.label || '').then(() => { draw(); toast('휴지통으로 옮겼어요'); }).catch(e => toast(e.message));
    });
    $('fdGrid').querySelectorAll('[data-rename]').forEach(b => b.onclick = () => {
        RENAME = b.dataset.rename; draw();
        const inp = $('fdGrid').querySelector('[data-rn-form] input'); if (inp) { inp.focus(); inp.select(); }
    });
    $('fdGrid').querySelectorAll('[data-rn-form]').forEach(f => {
        const id = f.dataset.rnForm, p = byId(id) || {};
        const done = (label, desc) => {
            f.querySelectorAll('button, input').forEach(x => { x.disabled = true; });
            const job = p.__custom ? post('meta', { id, label, desc }).then(loadSamples)
                : (() => { const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'name'); fd.append('id', id); fd.append('label', label); fd.append('desc', desc);
                    return fetch('admin_designs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                        if (!d.ok) throw new Error(d.error || '저장하지 못했어요');
                        const pp = presets.find(x => x.id === id); if (!pp) return;
                        const orig = pp.orig || { label: pp.label, desc: pp.desc }, nm = (d.names || {})[id];
                        if (nm) { pp.orig = orig; pp.label = nm.label || orig.label; pp.desc = nm.desc != null ? nm.desc : orig.desc; }
                        else { pp.label = orig.label; pp.desc = orig.desc; delete pp.orig; }
                    }); })();
            job.then(() => { RENAME = ''; draw(); drawThumbs(); toast('이름을 바꿨어요 - 고객 화면엔 1분 안에 보여요'); }).catch(e => { toast(e.message); f.querySelectorAll('button, input').forEach(x => { x.disabled = false; }); });
        };
        f.onsubmit = e => { e.preventDefault(); const label = f.label.value.trim(); if (!label) { toast('이름을 적어 주세요'); f.label.focus(); return; } done(label, f.desc.value.trim()); };
        f.querySelector('[data-rn-x]').onclick = () => { RENAME = ''; draw(); };
        const o = f.querySelector('[data-rn-orig]'); if (o) o.onclick = () => done('', '');
        f.onkeydown = e => { if (e.key === 'Escape') { RENAME = ''; draw(); } };
    });
    drawTrash();
    const dirty = JSON.stringify(sel) !== saved;
    $('fdSave').disabled = !dirty;
    $('fdState').textContent = dirty ? '추천 순서가 바뀌었어요 - 저장을 눌러주세요' : '';
}
$('fdAdd').onclick = () => { const f = $('fdNew'); f.hidden = !f.hidden; if (!f.hidden) $('fdLabel').focus(); };
$('fdNew').onsubmit = e => {
    e.preventDefault();
    post('create', { base: $('fdBase').value, label: $('fdLabel').value, desc: $('fdDesc').value }).then(d => {
        $('fdNew').hidden = true; $('fdLabel').value = ''; $('fdDesc').value = '';
        window.open('editor-prototype-v3-overlay.html?sample=' + encodeURIComponent(d.id), '_blank', 'noopener');
        return loadSamples();
    }).then(() => { draw(); toast('새 디자인을 만들었어요. 열린 에디터에서 꾸미고 저장해 주세요'); }).catch(err => toast(err.message));
};
$('fdReset').onclick = () => { sel = DEFAULT.slice(); draw(); };
$('fdSave').onclick = () => {
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('ids', JSON.stringify(sel));
    $('fdSave').disabled = true;
    fetch('admin_designs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        if (!d.ok) throw new Error(d.error || '저장하지 못했어요');
        sel = d.ids; saved = JSON.stringify(sel); draw(); toast('저장했어요. 새 청첩장 화면에 바로 반영돼요');
    }).catch(e => { toast(e.message); draw(); });
};
// ---- 디자인 썸네일 (dpk_thumbs.php) ----
let THUMBS = null;
const thOf = id => THUMBS && THUMBS[id]; // 카드·추천 칩에 보일 디자인 썸네일 (첫 화면 그림)
function loadThumbs() { return fetch('dpk_thumbs.php', { cache: 'no-store' }).then(r => r.json()).then(d => { THUMBS = d.items || {}; drawThumbs(); if (presets.length) draw(); }).catch(() => { $('thN').textContent = '썸네일 목록을 불러오지 못했어요'; }); }
function drawThumbs() {
    if (!THUMBS) return;
    const ids = (presets.length ? presets.map(p => p.id).concat(Object.keys(samples).filter(id => !presets.some(p => p.id === id))) : Object.keys(THUMBS)).filter(id => !TRASH[id]);
    const have = ids.filter(id => THUMBS[id]).length, name = id => { const p = presets.find(x => x.id === id), sm = samples[id]; return (sm && sm.label) || (p && p.label) || id; };
    const day = t => { const d = new Date(t * 1000); return `${d.getMonth() + 1}/${d.getDate()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`; };
    $('thN').textContent = have ? `${ids.length}개 중 ${have}개 그림 있음` : '아직 만든 그림이 없어요 (고객 화면은 진짜로 그려서 보여 줘요)';
    $('thClear').hidden = !have;
    $('thGrid').innerHTML = ids.map(id => { const t = THUMBS[id]; return `<span class="fd-thc">${t ? `<img src="${esc(t.c)}" alt="" loading="lazy">` : '<i></i>'}<b>${esc(name(id))}</b>${t ? `<small>${day(t.at)} 찍음</small>` : '<small class="no">그림 없음</small>'}`
        + `<span class="fd-tha"><a href="editor-prototype-v3-overlay.html?thumbs=make&amp;only=${encodeURIComponent(id)}" target="_blank" rel="noopener" title="새 창에서 이 디자인만 찍기">${t ? '다시 찍기' : '찍기'}</a>${t ? `<button type="button" data-th-del="${esc(id)}" title="이 디자인 그림만 지우기">지우기</button>` : ''}</span></span>`; }).join('');
}
// 디자인 하나만 지우기 (다시 찍기는 새 창 에디터 ?thumbs=make&only=디자인 → 그 줄의 [다시 찍기])
$('thGrid').addEventListener('click', e => {
    const d = e.target.closest('[data-th-del]'); if (!d) return;
    const id = d.dataset.thDel;
    if (!confirm(`'${name0(id)}' 썸네일을 지울까요? 고객 화면은 다시 찍을 때까지 진짜로 그려서 보여 줘요.`)) return;
    d.disabled = true;
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'del'); fd.append('id', id);
    fetch('dpk_thumbs.php', { method: 'POST', body: fd }).then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error || '지우지 못했어요'); toast('썸네일을 지웠어요'); return loadThumbs(); }).catch(err => { d.disabled = false; toast(err.message); });
});
function name0(id) { const p = presets.find(x => x.id === id), sm = samples[id]; return (sm && sm.label) || (p && p.label) || id; }
$('thClear').onclick = () => {
    if (!confirm('디자인 썸네일을 모두 지울까요? 고객 화면은 다시 만들 때까지 진짜로 그려서 보여 줘요.')) return;
    const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('act', 'clear');
    fetch('dpk_thumbs.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (!d.ok) throw new Error(d.error || '지우지 못했어요'); toast('썸네일을 모두 지웠어요'); return loadThumbs(); }).catch(e => toast(e.message));
};
loadThumbs();
// 다른 탭(에디터)에서 샘플을 저장하고 돌아오면 표시를 새로
window.addEventListener('focus', () => { loadSamples().then(draw).catch(() => {}); loadThumbs(); });
Promise.all([
    fetch('preset_list.php', { cache: 'no-store' }).then(r => r.json()).then(d => { presets = (d.presets || []).slice().sort((a, b) => (a.no || 99) - (b.no || 99)); }),
    loadSamples().catch(() => {})
]).then(() => { $('fdBase').innerHTML = presets.map(p => `<option value="${esc(p.id)}">${esc(p.label)}</option>`).join(''); draw(); drawThumbs(); })
  .catch(() => { $('fdGrid').innerHTML = '<p style="color:#B24A4A;font-size:13px">디자인 목록(preset_list.php)을 불러오지 못했어요.</p>'; });
window.addEventListener('beforeunload', e => { if (JSON.stringify(sel) !== saved) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
</body>
</html>
