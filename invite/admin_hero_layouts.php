<?php
/**
 * admin_hero_layouts.php - 관리자: 첫 화면 레이아웃 (1019w)
 *
 *  사용자: '일단 다 살리고 관리자 모드에서 지울 수 있게 하자. 거기서 새롭게 생성도 가능한 미리캔버스나 피그마 같은 기능 넣자'
 *  - 목록: 기본 27개 + 직접 만든 것. 고객에게 보이기 스위치(숨기기 = 고르는 화면에서만 빠짐, 이미 고른 청첩장은 그대로) ·
 *          묶음(사진 가득·큰 날짜·사진 칸·컨셉)과 대표(묶음 첫 줄) 바꾸기 · 직접 만든 것은 지우기
 *  - 캔버스: 하객 화면과 같은 그림(hero_layout_frame.php)에서 글자 칸·사진 칸을 끌어 옮기고 크기 바꾸기,
 *            왼쪽 칸 목록(레이어) · 오른쪽 모양(글꼴·크기·색·자간·회전·세로·휘기·글자 상자·등장 효과) · 되돌리기(Ctrl+Z) · 가운데 맞춤 선
 *            기본 레이아웃은 [복제해서 고치기] (기본은 그대로 두고 새 레이아웃으로 저장)
 *  저장: hero_layouts.php (uploads/site/hero_layouts.json) → 고객 에디터가 새로 열 때 읽음
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';

$readonly = function_exists('admin_page_readonly') && admin_page_readonly();
// 예시 사진: 간편 만들기 메인 사진 예시 + 디자인 폴더 사진 (없으면 바탕색만)
$pics = [];
try { require_once __DIR__ . '/section_defaults.php'; if (function_exists('section_defaults_easy_hero') && ($u = section_defaults_easy_hero()) !== '') $pics[] = ['간편 만들기 예시 사진', $u]; } catch (Throwable $e) {}
foreach (glob(__DIR__ . '/presets/*/hero.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $p) {
    $dir = basename(dirname($p));
    if (preg_match('/^[A-Za-z0-9_-]{1,60}$/', $dir)) $pics[] = ['디자인 ' . $dir, '/invite/presets/' . $dir . '/' . basename($p)];
}
// 스티커 그림 (1019z2 스티커 칸): assets/stickers/<테마>/*.svg|webp - 폴더를 읽음
$stkNames = ['romantic' => '로맨틱', 'cosmos' => '별·우주', 'classic' => '클래식', 'garden' => '가든·꽃', 'party' => '축하', 'season' => '계절', 'rain' => '비 오는 날', 'webtoon' => '웹툰', 'vintage' => '빈티지'];
$stkLib = [];
foreach ($stkNames as $t => $lab) {
    $fs = [];
    foreach (glob(__DIR__ . '/assets/stickers/' . $t . '/*.{svg,webp}', GLOB_BRACE) ?: [] as $f) { $b = basename($f); if (preg_match('/^[a-z0-9-]+\.(svg|webp)$/', $b)) $fs[] = $b; }
    if ($fs) { sort($fs); $stkLib[] = [$t, $lab, $fs]; }
}
$vb = @filemtime(__DIR__ . '/assets/invite-blocks.js') ?: time();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>첫 화면 레이아웃 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<link rel="stylesheet" href="assets/invite-blocks.css?v=<?= $vb ?>">
<style>
.hx { max-width: 1320px; margin: 0 auto; padding: 24px 20px 90px; font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; color: #2B2320; box-sizing: border-box; }
.hx * { box-sizing: border-box; }
.hx h2 { font-size: 20px; margin: 0 0 6px; }
.hx .lead { font-size: 13px; color: #8A847B; margin: 0 0 18px; line-height: 1.7; }
.btn2 { border: 1px solid #E6E0D8; background: #fff; border-radius: 10px; height: 36px; padding: 0 14px; font: inherit; font-size: 13px; font-weight: 600; color: #5A524A; cursor: pointer; white-space: nowrap; }
.btn2:hover { border-color: #CFC6BA; }
.btn2.pri { background: #2B2320; border-color: #2B2320; color: #fff; }
.btn2.sm { height: 30px; padding: 0 10px; font-size: 12px; border-radius: 8px; }
.btn2.dng { color: #B24A4A; }
.btn2:disabled { opacity: .4; cursor: default; }
.ro .rw { display: none !important; }
.ro-note { display: none; background: #FBF3E4; border: 1px solid #EBD9B5; border-radius: 12px; padding: 10px 14px; font-size: 13px; color: #7A5A2A; margin: 0 0 14px; }
.ro .ro-note { display: block; }
/* 목록 */
.hx-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.hx-chips { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }
.hx-chips button { border: 1px solid #E6E0D8; background: #fff; border-radius: 99px; height: 32px; padding: 0 13px; font: inherit; font-size: 12.5px; font-weight: 600; color: #8A847B; cursor: pointer; }
.hx-chips button.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.hx-chips button em { font-style: normal; opacity: .6; margin-left: 4px; }
.hx-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
.hx-card { background: #fff; border: 1px solid #ECE6DD; border-radius: 16px; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
.hx-card.off .hx-th { opacity: .35; filter: grayscale(.6); }
.hx-card.off { background: #FAF8F5; }
.hx-th { cursor: pointer; }
.hx-nm { display: flex; align-items: baseline; gap: 6px; min-width: 0; }
.hx-nm b { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-nm em { flex: none; font-style: normal; font-size: 10px; font-weight: 700; border-radius: 5px; padding: 1px 5px; background: #F1ECE5; color: #8A847B; }
.hx-nm em.me { background: #E7F0FF; color: #2F6BD8; }
.hx-ds { font-size: 11.5px; color: #8A847B; line-height: 1.45; min-height: 2.9em; margin: -4px 0 0; }
.hx-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px; color: #5A524A; }
.hx-row select { height: 30px; border: 1px solid #E6E0D8; border-radius: 8px; font: inherit; font-size: 12px; padding: 0 6px; background: #fff; flex: 1; min-width: 0; }
.hx-row label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
.sw { position: relative; width: 40px; height: 24px; flex: none; display: inline-block; }
.sw input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; z-index: 1; }
.sw i { position: absolute; inset: 0; border-radius: 12px; background: #D9D3CB; transition: background .2s; }
.sw i::after { content: ''; position: absolute; left: 3px; top: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .2s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.sw input:checked + i { background: var(--ui-point, #B08A5A); }
.sw input:checked + i::after { transform: translateX(16px); }
.hx-empty { grid-column: 1 / -1; text-align: center; color: #8A847B; font-size: 13px; padding: 40px 0; }
/* 작은 그림 (에디터 간편 만들기 레이아웃 카드와 같은 그림 - editor.css .hl-m) */
.hl-m { position: relative; display: block; aspect-ratio: 9 / 19.5; border-radius: 10px; overflow: hidden; background: #3A342F center / cover no-repeat; container-type: inline-size; }
.hl-m .hl-sh { position: absolute; inset: 0; background: linear-gradient(rgba(0,0,0,.28), rgba(0,0,0,0) 35%, rgba(0,0,0,0) 65%, rgba(0,0,0,.32)); }
.hl-m span { position: absolute; white-space: nowrap; line-height: 1.02; text-align: center; text-shadow: 0 1px 6px rgba(0,0,0,.2); }
.hl-m-box .hl-mb { position: absolute; overflow: hidden; display: block; } .hl-m-box .hl-mb em { position: absolute; inset: 0; background: center / cover no-repeat; }
.hl-m-box .hl-mb.hb-fr-comic { border: 1.5px solid #1B1B1F; box-shadow: 2px 2px 0 #1B1B1F; }
.hl-m-box .hl-mb.hb-fr-baroque { border: 1px solid #B8955A; outline: 1px solid rgba(184,149,90,.6); outline-offset: 1.5px; box-sizing: border-box; }
.hl-m-box .hl-mb.hb-fr-stamp { outline: 2px dotted #fff; outline-offset: 1px; box-shadow: 0 0 0 3px #FFFDF8, 0 2px 4px rgba(0,0,0,.15); transform: rotate(-1.2deg); }
.hl-m-box .hl-mb.hb-fr-lace { box-shadow: 0 0 0 2px #fff, 0 0 0 3px rgba(0,0,0,.08); border-radius: 2px; }
.hl-m-box .hl-mb.hb-fr-botanical { outline: 1px solid rgba(110,127,94,.7); outline-offset: 1.5px; }
.hl-m-box .hl-mb.hb-fr-ribbon { outline: 1px solid rgba(142,47,60,.55); outline-offset: 1.5px; }
.hl-m span.hl-d-caption, .hl-m span.hl-d-caption-w { background: #FFD93D; border: 1px solid #1B1B1F; box-shadow: 1px 1px 0 #1B1B1F; padding: .35em .6em .25em; line-height: 1.3; text-shadow: none; font-weight: 700; }
.hl-m span.hl-d-caption-w { background: #fff; }
.hl-m span.hl-d-bubble { background: #fff; border: 1.2px solid #1B1B1F; border-radius: 50% / 46%; padding: .6em .9em .5em; line-height: 1.2; text-shadow: none; }
.hl-m span.hl-d-sfx { -webkit-text-stroke: .08em #1B1B1F; paint-order: stroke fill; text-shadow: .06em .06em 0 #1B1B1F; }
.hl-m span.hl-d-tag { background: #F0445A; border: 1px solid #1B1B1F; border-radius: 99px; padding: .25em .6em .12em; text-shadow: none; }
.hl-m span.hl-d-seal { background: #B04A35; color: #fff; border-radius: 2px; padding: .25em .3em .2em; line-height: 1; text-shadow: none; }
.hl-m span.hl-d-card { background: #fff; color: #1E2833; border-radius: 4px; padding: .5em .7em; box-shadow: 0 2px 6px rgba(0,0,0,.2); text-shadow: none; }
.hl-m span.hl-d-line { border-top: 1px solid currentColor; border-bottom: 1px solid currentColor; padding: .3em .5em; }
.hl-m-box .hb-fr-rounded { border-radius: 5px; } .hl-m-box .hb-fr-arch { border-radius: 999px 999px 0 0; } .hl-m-box .hb-fr-shadow { box-shadow: 0 4px 10px rgba(0,0,0,.2); }
.hl-m-box .hb-fr-polaroid { background: #fff; box-shadow: 0 3px 8px rgba(0,0,0,.15); } .hl-m-box .hb-fr-polaroid em { inset: 5% 5% 26% 5%; }
.hl-m-box span { text-shadow: none; }
.hl-m .hl-vert { writing-mode: vertical-rl; text-orientation: upright; line-height: 1.15; } .hl-m .hl-vert-side { text-orientation: mixed; } .hl-m .hl-curve { line-height: 0; } .hl-m .hl-arc { display: block; overflow: visible; }
/* 캔버스 */
.cv { display: none; }
.hx.editing .ls { display: none; } .hx.editing .cv { display: block; }
.cv-top { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; position: sticky; top: 56px; z-index: 20; background: #F7F5F2; padding: 8px 0; }
.cv-top input.nm { height: 38px; border: 1px solid #E6E0D8; border-radius: 10px; padding: 0 12px; font: inherit; font-size: 15px; font-weight: 700; width: 240px; }
.cv-top .sp { flex: 1; }
.cv-top small { font-size: 12px; color: #8A847B; }
.cv-g { display: grid; grid-template-columns: 220px 410px minmax(0, 1fr); gap: 16px; align-items: start; }
.pn { background: #fff; border: 1px solid #ECE6DD; border-radius: 16px; padding: 12px; }
.pn h4 { margin: 0 0 8px; font-size: 13px; display: flex; align-items: center; gap: 6px; }
.pn h4 .sp { flex: 1; }
.lyr { display: flex; flex-direction: column; gap: 3px; }
.lyr-r { display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-radius: 9px; cursor: pointer; font-size: 12.5px; border: 1.5px solid transparent; }
.lyr-r:hover { background: #F7F4F0; }
.lyr-r.on { border-color: #3B82F6; background: #F2F7FF; }
.lyr-r.hid { opacity: .45; }
.lyr-r i { font-style: normal; width: 18px; text-align: center; color: #8A847B; flex: none; font-size: 12px; }
.lyr-r span { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lyr-r button { border: 0; background: transparent; color: #8A847B; cursor: pointer; font-size: 12px; padding: 2px 4px; border-radius: 5px; }
.lyr-r button:hover { background: #ECE6DD; color: #2B2320; }
.lyr-h { font-size: 11px; color: #A69E93; font-weight: 700; margin: 8px 0 2px 4px; }
.add { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 10px; }
.add button { border: 1px dashed #D9D3CB; background: #FBFAF8; border-radius: 10px; padding: 8px 6px; font: inherit; font-size: 12px; color: #5A524A; cursor: pointer; text-align: center; line-height: 1.3; }
.add button:hover { border-color: #3B82F6; color: #2F6BD8; background: #F2F7FF; }
.add button b { display: block; font-size: 13px; }
.ph-w { position: sticky; top: 118px; }
.ph { width: 410px; padding: 10px; background: #1F1B19; border-radius: 34px; box-shadow: 0 18px 40px rgba(30,20,15,.22); }
.ph iframe { display: block; width: 390px; height: 760px; border: 0; border-radius: 26px; background: #fff; }
.ph-bar { display: flex; gap: 6px; align-items: center; justify-content: center; margin-top: 10px; flex-wrap: wrap; font-size: 12px; color: #8A847B; }
.ph-bar select { height: 30px; border: 1px solid #E6E0D8; border-radius: 8px; font: inherit; font-size: 12px; background: #fff; max-width: 150px; }
.kb { font-size: 11px; color: #A69E93; text-align: center; margin-top: 8px; line-height: 1.6; }
.kb kbd { font: inherit; background: #fff; border: 1px solid #E6E0D8; border-bottom-width: 2px; border-radius: 4px; padding: 0 4px; }
.pr { display: flex; flex-direction: column; gap: 0; }
.pr .sec { border-top: 1px solid #F1ECE5; padding: 11px 0; display: flex; flex-direction: column; gap: 8px; }
.pr .sec:first-child { border-top: 0; padding-top: 0; }
.pr .sec > b { font-size: 12px; color: #8A847B; }
.f { display: grid; grid-template-columns: 74px minmax(0, 1fr); align-items: center; gap: 8px; font-size: 12.5px; }
.f > span { color: #5A524A; }
.f .in { display: flex; align-items: center; gap: 6px; min-width: 0; }
.f input[type=range] { flex: 1; min-width: 0; accent-color: #2B2320; }
.f input[type=number] { width: 62px; height: 30px; border: 1px solid #E6E0D8; border-radius: 8px; padding: 0 6px; font: inherit; font-size: 12px; }
.f input[type=text], .f select, .pr textarea { width: 100%; height: 32px; border: 1px solid #E6E0D8; border-radius: 8px; padding: 0 8px; font: inherit; font-size: 12.5px; background: #fff; }
.pr textarea { height: auto; min-height: 64px; padding: 8px; line-height: 1.5; resize: vertical; }
.f input[type=color] { width: 36px; height: 30px; border: 1px solid #E6E0D8; border-radius: 8px; padding: 2px; background: #fff; cursor: pointer; }
.seg { display: inline-flex; background: #F4EFE9; border-radius: 9px; padding: 3px; gap: 2px; flex-wrap: wrap; }
.seg button { border: 0; background: transparent; border-radius: 7px; height: 26px; padding: 0 9px; font: inherit; font-size: 12px; font-weight: 600; color: #8A847B; cursor: pointer; }
.seg button.on { background: #fff; color: #2B2320; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
.tok { display: flex; flex-wrap: wrap; gap: 4px; }
.tok button { border: 1px solid #E6E0D8; background: #FBFAF8; border-radius: 99px; height: 24px; padding: 0 8px; font: inherit; font-size: 11px; color: #5A524A; cursor: pointer; }
.tok button:hover { border-color: #3B82F6; color: #2F6BD8; }
.hint { font-size: 11.5px; color: #8A847B; line-height: 1.55; margin: 0; }
.acts { display: flex; gap: 6px; flex-wrap: wrap; }
@media (max-width: 1180px) { .cv-g { grid-template-columns: 410px minmax(0, 1fr); } .cv-g > .pn.lp { grid-column: 1 / -1; order: 3; } }
.ph-fit { position: relative; }
.ph { transform-origin: 0 0; }
.btn2.mob { display: none; }
/* 휴대폰 (1019y 사용자: '모바일에서 너무 보기 어렵다'): 미리보기 휴대폰을 화면에 맞게 줄여 위에 붙여 두고(크게 보기 단추), 그 아래 모양 칸 → 칸 목록,
   되돌리기·효과·저장은 화면 아래 띠 · 손가락으로 끌기 (iframe 안 touch-action) */
@media (max-width: 860px) {
  .hx { padding: 12px 12px 120px; }
  .hx.editing .lead { display: none; }
  .cv-top { position: static; padding: 0 0 8px; gap: 6px; background: none; }
  .cv-top input.nm { flex: 1; min-width: 0; width: auto; font-size: 14px; height: 36px; }
  .cv-top small { order: 9; width: 100%; font-size: 11px; line-height: 1.4; }
  .cv-top .sp { display: none; }
  .cv-act { position: fixed; left: 0; right: 0; bottom: 0; z-index: 70; display: flex; gap: 6px; padding: 8px 12px calc(8px + env(safe-area-inset-bottom)); background: rgba(255,255,255,.96); border-top: 1px solid #E8E4DC; box-shadow: 0 -6px 18px rgba(30,20,15,.06); }
  .cv-act .btn2 { height: 40px; flex: none; }
  .cv-act #btnSave { flex: 1; height: 40px; }
  .cv-act .lb { display: none; }
  .btn2.mob { display: inline-block; }
  .cv-g { grid-template-columns: minmax(0, 1fr); gap: 12px; }
  .cv-g > .ph-w { order: 1; position: sticky; top: var(--hx-top, 52px); z-index: 30; margin: 0 -12px; padding: 8px 0 6px; background: #F7F5F2; box-shadow: 0 8px 14px -12px rgba(30,20,15,.35); }
  .cv-g > .pn.pr { order: 2; } .cv-g > .pn.lp { order: 3; grid-column: auto; }
  .ph-fit { margin: 0 auto; }
  .ph { border-radius: 22px; padding: 6px; } .ph iframe { border-radius: 17px; }
  .ph-bar { margin-top: 6px; } .ph-bar > span { display: none; }
  .kb { display: none; }
  .pn { padding: 12px; }
  .f { grid-template-columns: 66px minmax(0, 1fr); }
  .f input[type=number] { width: 56px; height: 34px; font-size: 14px; }
  .f input[type=text], .f select, .pr textarea { height: 36px; font-size: 14px; }
  .pr textarea { height: auto; font-size: 15px; }
  .seg button { height: 32px; padding: 0 10px; }
  .tok button { height: 30px; padding: 0 10px; font-size: 12px; }
  .lyr-r { padding: 9px 8px; font-size: 13.5px; }
  .lyr-r button { padding: 6px 8px; font-size: 13px; }
  .add button { padding: 10px 6px; }
  .hx-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
  .hx-card { padding: 8px; border-radius: 14px; }
  .hx-ds { display: none; }
  .hx .lead { font-size: 12.5px; line-height: 1.6; }
  .hx-bar { flex-direction: column; align-items: stretch; }
  .hx-chips { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; margin: 0 -12px; padding: 0 12px; }
  .hx-chips::-webkit-scrollbar { display: none; }
  .hx-chips button { flex: none; }
  #btnNew { height: 42px; }
}
/* 1019z3 작은 그림 (레이아웃 고르기·관리자 목록) */
.hl-m-box .hl-mb:is(.hb-fr-oval, .hb-fr-circle), .hl-m-box .hl-mb:is(.hb-fr-oval, .hb-fr-circle) em { border-radius: 50%; }
.hl-m-box .hl-mb.hb-fr-circle { aspect-ratio: 1; height: auto !important; outline: 1px dashed rgba(138,109,69,.6); outline-offset: 3px; }
.hl-m-box .hl-mb.hb-fr-oval { outline: 1px solid #B8955A; outline-offset: 2px; }
.hl-m-box .hl-mb.hb-fr-mat { background: #fff; } .hl-m-box .hl-mb.hb-fr-mat em { inset: 6% 6% 14%; }
.hl-m-box .hl-mb.hb-fr-overlap { overflow: visible; }
.hl-m-box .hl-mb.hb-fr-overlap::after { content: ''; position: absolute; right: -22%; bottom: -24%; width: 62%; height: 58%; background: #CDBFAF; border: 2px solid #fff; }
.hl-m-box .hl-mb.hb-fr-film { background: #1D1A18; } .hl-m-box .hl-mb.hb-fr-film em { inset: 4% 14%; }
.hl-m-box .hl-mb.hb-fr-film em { -webkit-mask: linear-gradient(#000 0 0) top / 100% 31% no-repeat, linear-gradient(#000 0 0) center / 100% 31% no-repeat, linear-gradient(#000 0 0) bottom / 100% 31% no-repeat; mask: linear-gradient(#000 0 0) top / 100% 31% no-repeat, linear-gradient(#000 0 0) center / 100% 31% no-repeat, linear-gradient(#000 0 0) bottom / 100% 31% no-repeat; }
.hl-m-box .hl-mb.hb-fr-twin em { -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath d='M0 100V36A24 36 0 0 1 48 36V100Z M52 100V36A24 36 0 0 1 100 36V100Z'/%3E%3C/svg%3E") center / 100% 100% no-repeat; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath d='M0 100V36A24 36 0 0 1 48 36V100Z M52 100V36A24 36 0 0 1 100 36V100Z'/%3E%3C/svg%3E") center / 100% 100% no-repeat; }
.hl-m-box .hl-mb.hb-fr-torn em { -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath d='M0 0H100V95L96 98.5 91 94.5 86 99.5 80 95 74 99 69 93.5 63 98 57 95 52 100 46 94 40 98.5 34 95 29 99.5 23 94 17 98 12 93.5 6 98.5 0 95Z'/%3E%3C/svg%3E") center / 100% 100% no-repeat; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100' preserveAspectRatio='none'%3E%3Cpath d='M0 0H100V95L96 98.5 91 94.5 86 99.5 80 95 74 99 69 93.5 63 98 57 95 52 100 46 94 40 98.5 34 95 29 99.5 23 94 17 98 12 93.5 6 98.5 0 95Z'/%3E%3C/svg%3E") center / 100% 100% no-repeat; }
.hl-m-box .hl-mb.hb-fr-blob, .hl-m-box .hl-mb.hb-fr-blob em { border-radius: 58% 42% 47% 53% / 44% 52% 48% 56%; }
.hl-m-box .hl-mb.hb-fr-grid4 em { -webkit-mask: linear-gradient(#000 0 0) 0 0 / 48% 48% no-repeat, linear-gradient(#000 0 0) 100% 0 / 48% 48% no-repeat, linear-gradient(#000 0 0) 0 100% / 48% 48% no-repeat, linear-gradient(#000 0 0) 100% 100% / 48% 48% no-repeat; mask: linear-gradient(#000 0 0) 0 0 / 48% 48% no-repeat, linear-gradient(#000 0 0) 100% 0 / 48% 48% no-repeat, linear-gradient(#000 0 0) 0 100% / 48% 48% no-repeat, linear-gradient(#000 0 0) 100% 100% / 48% 48% no-repeat; }
.hl-m-box .hl-mb.hb-fr-hidden em { visibility: hidden; }
.hl-m span.hl-d-photo { background: linear-gradient(160deg, #B08A60, #4A3B30); -webkit-background-clip: text; background-clip: text; color: transparent !important; -webkit-text-fill-color: transparent; font-weight: 900; line-height: .88; }
/* 스티커 칸 (1019z2) */
.hl-m span.hl-stkl { line-height: 0; } .hl-m .hl-stk-img { display: block; width: 100%; height: auto; }
.add button.stk { grid-column: 1 / -1; border-style: solid; border-color: #E8C9A8; background: #FFF8F0; }
.skp-bg { position: fixed; inset: 0; background: rgba(20,16,12,.42); z-index: 9000; display: flex; align-items: center; justify-content: center; padding: 16px; }
.skp { background: #fff; border-radius: 18px; width: min(560px, 100%); max-height: min(82vh, 760px); display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.25); overflow: hidden; }
.skp-h { display: flex; align-items: center; gap: 8px; padding: 14px 16px 8px; } .skp-h b { font-size: 16px; } .skp-h small { color: #8A847B; } .skp-h button { margin-left: auto; border: 0; background: none; font-size: 18px; cursor: pointer; color: #8A847B; }
.skp-t { display: flex; gap: 6px; overflow-x: auto; padding: 0 16px 10px; scrollbar-width: none; border-bottom: 1px solid #EFEAE3; } .skp-t::-webkit-scrollbar { display: none; }
.skp-t button { flex: none; border: 1px solid #E5DFD7; background: #fff; border-radius: 99px; padding: 6px 12px; font: inherit; font-size: 12.5px; cursor: pointer; color: #5A524A; }
.skp-t button.on { background: #2B2320; border-color: #2B2320; color: #fff; }
.skp-b { overflow-y: auto; padding: 4px 16px 16px; }
.skp-lab { font-size: 12.5px; font-weight: 700; color: #5A524A; padding: 12px 0 6px; position: sticky; top: 0; background: #fff; }
.skp-g { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 6px; }
.skp-g button { aspect-ratio: 1; border: 1.5px solid transparent; background: #FAF8F6; border-radius: 10px; padding: 5px; cursor: pointer; min-width: 0; }
.skp-g button:hover { border-color: #E0526A; } .skp-g button.on { border-color: #3B82F6; background: #F2F7FF; }
.skp-g img { width: 100%; height: 100%; object-fit: contain; display: block; }
.stk-pv { display: flex; align-items: center; gap: 10px; } .stk-pv img { width: 64px; height: 64px; object-fit: contain; background: #FAF8F6; border-radius: 10px; padding: 4px; }
@media (max-width: 560px) { .skp-bg { align-items: flex-end; padding: 0; } .skp { border-radius: 18px 18px 0 0; max-height: 78vh; } .skp-g { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
</style>
</head>
<body>
<?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('herolayouts', '첫 화면 레이아웃'); ?>
<div class="hx<?= $readonly ? ' ro' : '' ?>" id="hx">
  <div class="ls">
    <h2>첫 화면 레이아웃</h2>
    <p class="lead">고객이 간편 만들기·전문가 모드의 <b>첫 화면 → 레이아웃</b>에서 고르는 모양이에요. 스위치를 끄면 고르는 화면에서만 빠지고, 이미 그 레이아웃을 고른 청첩장은 그대로예요.<br>
      묶음·대표를 바꾸면 간편 만들기 격자에서 그 묶음 첫 줄(대표)이나 '비슷한 모양 더 보기'로 가요. <b>새로 만들기</b>나 기본 레이아웃의 <b>복제해서 고치기</b>로 캔버스에서 직접 꾸밀 수 있어요.</p>
    <div class="ro-note">보기 전용이에요. 바꾸려면 대표 관리자에게 '사업자·사이트 정보 수정' 권한을 받아 주세요.</div>
    <div class="hx-bar"><div class="hx-chips" id="chips"></div><button type="button" class="btn2 pri rw" id="btnNew">+ 새로 만들기</button></div>
    <div class="hx-grid" id="grid"></div>
  </div>
  <div class="cv">
    <div class="cv-top">
      <button type="button" class="btn2" id="btnBack">← 목록</button>
      <input type="text" class="nm" id="nm" maxlength="40" placeholder="레이아웃 이름">
      <small id="cvFrom"></small>
      <span class="sp"></span>
      <div class="cv-act"><button type="button" class="btn2 sm" id="btnUndo" title="되돌리기 (Ctrl+Z)">↶<span class="lb"> 되돌리기</span></button>
      <button type="button" class="btn2 sm" id="btnRedo" title="다시 하기 (Ctrl+Shift+Z)">↷</button>
      <button type="button" class="btn2 sm" id="btnPlay">▶<span class="lb"> 효과 보기</span></button>
      <button type="button" class="btn2 sm mob" id="btnBig" aria-pressed="false">휴대폰 크게</button>
      <button type="button" class="btn2 pri rw" id="btnSave">저장</button></div>
    </div>
    <div class="cv-g">
      <div class="pn lp"><h4>칸 목록<span class="sp"></span></h4><div class="lyr" id="lyr"></div>
        <div class="add rw" id="add"></div></div>
      <div class="ph-w" id="phw"><div class="ph-fit" id="phFit"><div class="ph" id="ph"><iframe id="fr" src="hero_layout_frame.php" title="첫 화면 미리보기"></iframe></div></div>
        <div class="ph-bar"><span>예시</span><select id="pic"></select><select id="skin" title="바탕 색"></select></div>
        <div class="kb">끌어서 옮기기 · <kbd>←↑→↓</kbd> 조금씩 (<kbd>Shift</kbd> 크게) · <kbd>Del</kbd> 지우기 · <kbd>Ctrl</kbd>+<kbd>D</kbd> 복제 · <kbd>Esc</kbd> 선택 풀기<br>가운데에 가까우면 빨간 선에 붙어요</div></div>
      <div class="pn pr" id="pr"></div>
    </div>
  </div>
</div>
<script src="assets/invite-blocks.js?v=<?= $vb ?>"></script>
<script src="assets/ld-dialog.js"></script>
<script>
(function () {
'use strict';
const RO = <?= $readonly ? 'true' : 'false' ?>;
const STK_LIB = <?= json_encode($stkLib, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>; // [[테마, 이름, [파일…]]]
const PICS = <?= json_encode($pics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const IB = window.InviteBlocks, esc = IB.esc;
const BUILT = IB.HERO_LAYOUTS || [];
const CATS = (IB.HERO_CATS || []).map(c => [c[0], c[1]]);
const FONTS = [['', '스킨 기본 글꼴'], ['pretendard', '프리텐다드 (고딕)']].concat(Object.entries(IB.FONT_CSS || {}).map(([k, v]) => [k, v.label]));
const TOKENS = [['{신랑}', '신랑'], ['{신부}', '신부'], ['{날짜:점}', '2026.11.14'], ['{날짜:영문}', 'Sat, Nov 14th, 2026'], ['{날짜}', '날짜 그대로'], ['{요일:영문}', 'SATURDAY'], ['{요일:영문짧게}', 'SAT'], ['{년:2}', '26'], ['{월}', '월'], ['{일}', '일'], ['{시간}', '2:00 PM'], ['{예식장}', '예식장']];
const PART_NAMES = { groomName: '신랑 이름', heart: '♥ 하트', brideName: '신부 이름', datetime: '날짜·시간 줄' };
const PART_KEYS = ['datetime', 'groomName', 'heart', 'brideName']; // 하객 화면에 그려지는 순서 (render-invite.js templates.hero)
const MAX_LAYERS = 12;
let CFG = { items: [], off: [], cats: {} };
let filt = 'all';

// ---------------------------------------------------------------- 공통
const $ = id => document.getElementById(id);
const clone = o => JSON.parse(JSON.stringify(o));
const num = (v, d) => { const n = Number(v); return isFinite(n) ? n : d; };
const toast = m => { try { LD.toast(m); } catch (e) {} };
async function post(a, body) {
    const r = await fetch('hero_layouts.php?a=' + a, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const j = await r.json().catch(() => null);
    if (!j || !j.ok) throw new Error((j && (j.expired ? '관리자 로그인이 끊겼어요. 다시 로그인해 주세요.' : j.error)) || '저장하지 못했어요.');
    take(j); return j;
}
function take(j) { CFG = { items: Array.isArray(j.items) ? j.items : [], off: Array.isArray(j.off) ? j.off : [], cats: j.cats && !Array.isArray(j.cats) ? j.cats : {} }; }
const all = () => BUILT.concat(CFG.items);
const isMine = l => !BUILT.some(b => b.id === l.id);
// 묶음 (에디터 hlCatOf와 같은 순서: 관리자가 정한 것 → 직접 만든 레이아웃의 cat → 기본 묶음)
function catOf(l) {
    const o = CFG.cats[l.id]; if (o && CATS.some(c => c[0] === o.c)) return [o.c, !!o.core, true];
    if (l.cat && CATS.some(c => c[0] === l.cat)) return [l.cat, !!l.core, false];
    for (const [c, , core, more] of IB.HERO_CATS || []) { if (core.includes(l.id)) return [c, true, false]; if (more.includes(l.id)) return [c, false, false]; }
    return [l.box ? 'box' : 'full', false, false];
}
function defCatOf(l) { for (const [c, , core, more] of IB.HERO_CATS || []) { if (core.includes(l.id)) return [c, true]; if (more.includes(l.id)) return [c, false]; } return [l.cat || (l.box ? 'box' : 'full'), !!l.core]; }
const catName = c => (CATS.find(x => x[0] === c) || [, c])[1];

// ---------------------------------------------------------------- 작은 그림 (에디터 spHeroLayoutMini와 같은 방식)
const SK = { bg: '#FAF7F0', ink: '#2B2320', accent: '#7A3B41', head: "'Noto Serif KR', serif" };
const famOf = id => { if (!id) return ''; if (id === 'pretendard') return "'Pretendard Variable', Pretendard, sans-serif"; const f = (IB.FONT_CSS || {})[id]; if (!f) return ''; IB.ensureFont(id); return f.family.replace(/"/g, "'"); };
const SAMPLE = { groomName: '민준', brideName: '서연', datetime: '2026.11.14 (토) 오후 2:00' };
function fillTok(t) {
    const v = { '신랑': SAMPLE.groomName, '신부': SAMPLE.brideName, '날짜': SAMPLE.datetime, '날짜:영문': 'Sat, Nov 14th, 2026', '날짜:점': '2026.11.14', '요일:영문': 'SATURDAY', '요일:영문짧게': 'SAT', '년:2': '26', '월': '11', '일': '14', '시간': '2:00 PM', '예식장': '라움아트센터' };
    return String(t || '').replace(/\{(신랑|신부|날짜:영문|날짜:점|날짜|요일:영문짧게|요일:영문|년:2|월|일|시간|예식장)\}/g, (m, k) => v[k] || '');
}
function mini(lay) {
    const box = lay.box, pic = PIC ? `url('${esc(PIC)}')` : '', ph = pic || `linear-gradient(160deg, ${SK.accent}, ${SK.ink})`;
    const txt = (L, t) => { if (!L) return ''; const fam = famOf(L.font) || SK.head, col = L.color || (box ? SK.ink : '#fff'), L2 = Object.assign({}, L, { anim: '' });
        return `<span class="${IB.heroLayerCls(L2)}" style="left:${L.x}%;top:${L.y}%;font-size:${(num(L.fontSize, 16) / 390 * 100).toFixed(2)}cqw;font-family:${fam};color:${col};${L.ls ? `letter-spacing:${L.ls / 1000}em;` : ''}transform:translate(-50%,-50%) rotate(${L.rotation || 0}deg);${L.align ? `text-align:${L.align};` : ''}${IB.heroLayerCss(L2)}">${IB.heroLayerInner(t, L)}</span>`; };
    const hide = lay.hide || [], P = lay.parts || {};
    const parts = [['groomName', SAMPLE.groomName], ['heart', '♥'], ['brideName', SAMPLE.brideName], ['datetime', SAMPLE.datetime]].filter(([k]) => !hide.includes(k) && P[k]).map(([k, t]) => txt(P[k], t)).join('');
    const layers = (lay.layers || []).map(L => txt(L, fillTok(L.text))).join('');
    if (box) {
        const fade = num(box.fade, 0), mask = fade ? `-webkit-mask-image:linear-gradient(#000 ${100 - fade}%,transparent);mask-image:linear-gradient(#000 ${100 - fade}%,transparent);` : '';
        return `<span class="hl-m hl-m-box" style="background:${box.bg || SK.bg}"><i class="hl-mb hb-fr-${esc(box.frame || 'none')}" style="left:${box.x}%;top:${box.y}%;width:${box.w}%;height:${box.h}%;${mask}"><em style="background-image:${ph}"></em></i>${parts}${layers}</span>`;
    }
    return `<span class="hl-m" style="background-image:${ph}"><i class="hl-sh"></i>${parts}${layers}</span>`;
}

// ---------------------------------------------------------------- 목록
function renderList() {
    const L = all(), cnt = c => L.filter(l => c === 'all' ? true : c === 'off' ? CFG.off.includes(l.id) : c === 'mine' ? isMine(l) : catOf(l)[0] === c).length;
    $('chips').innerHTML = [['all', '전체'], ...CATS, ['mine', '직접 만든 것'], ['off', '숨긴 것']].map(([c, t]) => `<button type="button" data-f="${c}" class="${filt === c ? 'on' : ''}">${esc(t)}<em>${cnt(c)}</em></button>`).join('');
    const list = L.filter(l => filt === 'all' ? true : filt === 'off' ? CFG.off.includes(l.id) : filt === 'mine' ? isMine(l) : catOf(l)[0] === filt);
    // 묶음 → 대표 먼저 (고객 화면 순서와 비슷하게)
    const ord = l => { const [c, core] = catOf(l); return CATS.findIndex(x => x[0] === c) * 2 + (core ? 0 : 1); };
    if (filt !== 'mine') list.sort((a, b) => ord(a) - ord(b));
    $('grid').innerHTML = list.length ? list.map(l => {
        const mine = isMine(l), off = CFG.off.includes(l.id), [c, core, over] = catOf(l), d = defCatOf(l);
        const opts = `<option value="">기본: ${esc(catName(d[0]))}${d[1] ? ' · 대표' : ''}</option>` + CATS.map(([v, t]) => `<option value="${v}"${over && c === v ? ' selected' : ''}>${esc(t)}</option>`).join('');
        return `<div class="hx-card${off ? ' off' : ''}" data-id="${esc(l.id)}">
            <div class="hx-th" data-open="${esc(l.id)}" title="${mine ? '고치기' : '복제해서 고치기'}">${mini(l)}</div>
            <div class="hx-nm"><b>${esc(l.label)}</b><em class="${mine ? 'me' : ''}">${mine ? '직접 만듦' : '기본'}</em></div>
            <p class="hx-ds">${esc(l.desc || '')}</p>
            <div class="hx-row"><label class="sw" title="고객에게 보이기"><input type="checkbox" data-vis="${esc(l.id)}"${off ? '' : ' checked'}${RO ? ' disabled' : ''}><i></i></label><span>${off ? '숨김' : '보임'}</span>
              <span style="flex:1"></span><span>${esc(catName(c))}${core ? ' · 대표' : ''}</span></div>
            <div class="hx-row rw"><select data-cat="${esc(l.id)}" title="묶음">${opts}</select><label title="묶음 첫 줄에 보이기"><input type="checkbox" data-core="${esc(l.id)}"${core ? ' checked' : ''}${over || mine ? '' : ' disabled'}> 대표</label></div>
            <div class="hx-row rw"><button type="button" class="btn2 sm" data-open="${esc(l.id)}">${mine ? '고치기' : '복제해서 고치기'}</button>${mine ? `<button type="button" class="btn2 sm dng" data-del="${esc(l.id)}">지우기</button>` : ''}</div>
        </div>`;
    }).join('') : '<div class="hx-empty">해당하는 레이아웃이 없어요.</div>';
}
$('chips').addEventListener('click', e => { const b = e.target.closest('[data-f]'); if (b) { filt = b.dataset.f; renderList(); } });
$('grid').addEventListener('change', async e => {
    const t = e.target;
    try {
        if (t.dataset.vis) { await post('vis', { id: t.dataset.vis, on: t.checked }); toast(t.checked ? '고르는 화면에 보여요' : '고르는 화면에서 숨겼어요'); }
        else if (t.dataset.cat !== undefined) { const id = t.dataset.cat, l = all().find(x => x.id === id), core = !!(t.value && catOf(l)[1]); await post('cat', { id, c: t.value, core }); toast('묶음을 바꿨어요'); }
        else if (t.dataset.core) { const id = t.dataset.core, l = all().find(x => x.id === id), [c] = catOf(l); await post('cat', { id, c, core: t.checked }); toast(t.checked ? '대표로 보여요' : '비슷한 모양 더 보기로 옮겼어요'); }
    } catch (err) { LD.alert(err.message); }
    renderList();
});
$('grid').addEventListener('click', async e => {
    const o = e.target.closest('[data-open]'); if (o) { openEditor(o.dataset.open); return; }
    const d = e.target.closest('[data-del]'); if (!d) return;
    const l = CFG.items.find(x => x.id === d.dataset.del); if (!l) return;
    if (!await LD.confirm(`'${l.label}' 레이아웃을 지울까요?`, { ok: '지우기', danger: true, note: '이미 이 레이아웃을 고른 청첩장은 그대로예요 (모양은 청첩장에 저장돼 있어요).' })) return;
    try { await post('delete', { id: l.id }); toast('지웠어요'); } catch (err) { LD.alert(err.message); }
    renderList();
});

// ---------------------------------------------------------------- 캔버스
const BASE = { id: '', label: '', desc: '', photo: { heroWidth: 'full', heroRatio: 'screen', heroTextOver: true, heroShade: 'both', heroShadeLv: '1' }, video: { heightMode: 'full', videoTextOver: true, heroShade: 'both', heroShadeLv: '1' },
    parts: {}, hide: ['groomName', 'heart', 'brideName', 'datetime'],
    layers: [{ text: '{신랑} & {신부}', x: 50, y: 18, fontSize: 34, font: 'cormorant', color: '#FFFFFF' }, { text: '{날짜:점} {시간}', x: 50, y: 86, fontSize: 13, color: '#FFFFFF', ls: 80 }], box: null };
let E = null, sel = null, hist = [], fut = [], dirty = false, PIC = (PICS[0] || [])[1] || '', SKIN = 'classic', FW = null, FD = null, keyMap = [];
function normalize(l) {
    const o = clone(l);
    o.photo = Object.assign({ heroWidth: 'full', heroRatio: 'screen', heroTextOver: true }, o.photo && !Array.isArray(o.photo) ? o.photo : {});
    o.video = o.video && !Array.isArray(o.video) && Object.keys(o.video).length ? o.video : (o.box ? null : { heightMode: 'full', videoTextOver: true, heroShade: o.photo.heroShade || 'none', heroShadeLv: o.photo.heroShadeLv });
    o.parts = o.parts && !Array.isArray(o.parts) ? o.parts : {};
    o.hide = Array.isArray(o.hide) ? o.hide : [];
    o.layers = Array.isArray(o.layers) ? o.layers : [];
    o.box = o.box || null;
    // 숨기지 않은 이름·날짜 칸은 자리가 있어야 끌 수 있음 (기본 자리)
    const DEF = { datetime: { x: 50, y: 26, fontSize: 13 }, groomName: { x: 36, y: 56, fontSize: 26 }, heart: { x: 50, y: 56, fontSize: 22 }, brideName: { x: 64, y: 56, fontSize: 26 } };
    PART_KEYS.forEach(k => { if (!o.hide.includes(k) && !o.parts[k]) o.parts[k] = clone(DEF[k]); });
    return o;
}
function openEditor(id) {
    const src = id ? all().find(l => l.id === id) : null;
    if (id && !src) return;
    const mine = src && isMine(src);
    E = normalize(src || BASE);
    if (!mine) { E.from = src ? src.id : ''; E.id = ''; E.label = src ? src.label + ' 복사본' : '새 레이아웃'; }
    const [c, core] = src ? catOf(src) : [E.box ? 'box' : 'full', false];
    E._cat = c; E._core = core;
    sel = null; hist = []; fut = []; dirty = false;
    $('nm').value = E.label;
    $('cvFrom').textContent = mine ? '직접 만든 레이아웃 고치기' : src ? `기본 '${src.label}'을 복제 - 저장하면 새 레이아웃이 돼요 (기본은 그대로)` : '새 레이아웃';
    $('hx').classList.add('editing'); window.scrollTo(0, 0); requestAnimationFrame(fitPhone);
    history.replaceState(null, '', '#edit');
    renderAll();
}
async function closeEditor() {
    if (dirty && !RO && !await LD.confirm('저장하지 않은 것이 있어요. 목록으로 갈까요?', { ok: '저장 안 하고 나가기', danger: true })) return;
    $('hx').classList.remove('editing'); E = null; history.replaceState(null, '', location.pathname); renderList();
}
$('btnBack').addEventListener('click', closeEditor);
$('btnNew').addEventListener('click', () => openEditor(''));
$('nm').addEventListener('input', () => { if (E) { E.label = $('nm').value; dirty = true; } });
function snap() { hist.push(JSON.stringify(E)); if (hist.length > 80) hist.shift(); fut = []; dirty = true; }
function undo() { if (!hist.length) return; fut.push(JSON.stringify(E)); E = JSON.parse(hist.pop()); fixSel(); renderAll(); }
function redo() { if (!fut.length) return; hist.push(JSON.stringify(E)); E = JSON.parse(fut.pop()); fixSel(); renderAll(); }
function fixSel() { if (!sel) return; if (sel.t === 'l' && !E.layers[sel.i]) sel = null; if (sel && sel.t === 'p' && E.hide.includes(sel.k)) sel = null; if (sel && sel.t === 'box' && !E.box) sel = null; }
$('btnUndo').addEventListener('click', undo); $('btnRedo').addEventListener('click', redo);
$('btnPlay').addEventListener('click', () => { if (FW && FW.hlPlay) FW.hlPlay(); });

// 저장할 레이아웃 (편집용 값 _cat·_core 빼고)
function outLayout() {
    const o = clone(E); delete o._cat; delete o._core; delete o.updated;
    o.label = ($('nm').value || '').trim() || '새 레이아웃';
    o.parts = {}; PART_KEYS.forEach(k => { if (!o.hide.includes(k) && E.parts[k]) o.parts[k] = clone(E.parts[k]); });
    if (!o.box) delete o.box;
    if (!o.video) delete o.video;
    o.cat = E._cat; o.core = !!E._core;
    return o;
}
$('btnSave').addEventListener('click', async () => {
    if (RO || !E) return;
    const o = outLayout();
    if (!o.layers.length && !Object.keys(o.parts).length) { LD.alert('글자 칸이 하나는 있어야 해요.'); return; }
    try {
        const j = await post('save', { layout: o });
        E.id = j.id; dirty = false; hist = []; fut = [];
        if (CFG.cats[j.id]) await post('cat', { id: j.id, c: '' }); // (목록에서 바꾼 묶음보다 캔버스에서 고른 묶음이 먼저)
        $('cvFrom').textContent = '직접 만든 레이아웃 고치기';
        toast('저장했어요 - 고객 에디터를 새로 열면 보여요');
    } catch (err) { LD.alert(err.message); }
});

// ---- 미리보기 휴대폰 (하객 화면과 같은 그림)
$('pic').innerHTML = PICS.map(([t, u]) => `<option value="${esc(u)}">${esc(t)}</option>`).join('') + '<option value="">사진 없이 (바탕색만)</option><option value="#file">내 컴퓨터 사진으로 보기…</option>';
$('pic').addEventListener('change', () => {
    const v = $('pic').value;
    if (v !== '#file') { PIC = v; draw(); return; }
    const inp = document.createElement('input'); inp.type = 'file'; inp.accept = 'image/*';
    inp.onchange = () => { const f = inp.files && inp.files[0]; if (!f) return; const img = new Image(); img.onload = () => { // 미리보기용으로만 줄여서 (올리지 않음)
        const k = Math.min(1, 1000 / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); PIC = c.toDataURL('image/jpeg', .85); URL.revokeObjectURL(img.src);
        const op = document.createElement('option'); op.value = '#mine'; op.textContent = '내 사진: ' + f.name.slice(0, 20); $('pic').insertBefore(op, $('pic').lastElementChild); $('pic').value = '#mine'; draw(); renderList(); };
        img.src = URL.createObjectURL(f); };
    inp.click(); $('pic').value = PIC && PIC.startsWith('data:') ? '#mine' : PIC;
});
$('skin').addEventListener('change', () => { SKIN = $('skin').value; draw(); });
// 휴대폰 크기 맞추기: iframe은 늘 390px로 그리고(하객 화면과 같게) 겉을 줄임 - 좁은 화면은 화면 높이의 약 45%(크게 보기 = 폭에 맞춤)
let phBig = false, phK = 1;
function fitPhone() {
    const ph = $('ph'), fit = $('phFit'), W0 = 410, H0 = ph.offsetHeight || 780, mob = innerWidth <= 860;
    const top = document.querySelector('.adm-top'); document.documentElement.style.setProperty('--hx-top', (top ? top.offsetHeight : 52) + 'px');
    const avail = Math.min($('hx').clientWidth - (mob ? 24 : 0), W0);
    phK = mob ? Math.min(avail / W0, phBig ? 1 : (innerHeight * .46) / H0) : 1;
    ph.style.transform = phK < 1 ? `scale(${phK})` : '';
    fit.style.width = mob ? W0 * phK + 'px' : ''; fit.style.height = mob ? H0 * phK + 'px' : '';
    if (FD) FD.documentElement.style.setProperty('--hk', String(1 / phK)); // (줄인 만큼 손잡이·선을 키움)
    if (E) markSel();
}
addEventListener('resize', fitPhone);
$('btnBig').addEventListener('click', () => { phBig = !phBig; $('btnBig').textContent = phBig ? '휴대폰 작게' : '휴대폰 크게'; $('btnBig').setAttribute('aria-pressed', phBig); fitPhone(); });
function frameReady() {
    FW = $('fr').contentWindow; FD = $('fr').contentDocument;
    if (!FW || !FW.hlReady) return false;
    const sk = (FW.hlSkins && FW.hlSkins()) || ['classic'];
    $('skin').innerHTML = sk.map(k => `<option value="${esc(k)}"${k === SKIN ? ' selected' : ''}>${esc(k)}</option>`).join('');
    bindFrame(); fitPhone(); if (E) draw();
    return true;
}
$('fr').addEventListener('load', frameReady);
function designOf() {
    const hb = { id: 'hero', enabled: true, width: 100, scrollEffect: 'none', fields: { groomName: SAMPLE.groomName, brideName: SAMPLE.brideName, datetime: SAMPLE.datetime, heroImage: PIC, heroFrame: 'rounded', customTexts: [], layout: {} } };
    const lay = outLayout(); lay.id = E.id || 'a-draft';
    IB.applyHeroLayout(hb, lay);
    keyMap = (hb.fields.heroLayers || []).slice(); // 그려진 문구 칸 키 ↔ E.layers 순서
    return { skin: SKIN, intro: { enabled: false }, effects: {}, extras: { scrollFx: false }, stickers: [], customTexts: [], imgFocus: {}, imgZoom: {}, labels: {},
        blocks: [hb, { id: 'dday', enabled: false, width: 100, fields: { year: 2026, month: 11, day: 14, hour: 14, minute: 0 } }, { id: 'location', enabled: false, width: 100, fields: { venue: '라움아트센터' } }] };
}
function draw() {
    if (!E || !FW || !FW.hlDraw) return;
    let root; try { root = FW.hlDraw(designOf()); } catch (err) { console.error(err); return; }
    // 그려진 칸에 이름표 (끌기·고르기용)
    const cv = root.querySelector('.blk-hero .free-canvas');
    if (cv) {
        const vis = PART_KEYS.filter(k => !E.hide.includes(k));
        [...cv.querySelectorAll(':scope > .drag-part:not(.op-layer)')].forEach((el, i) => { if (vis[i]) el.dataset.hlx = 'p:' + vis[i]; });
        cv.querySelectorAll(':scope > .drag-part.op-layer').forEach(el => { const i = keyMap.indexOf(el.dataset.part); if (i >= 0) el.dataset.hlx = 'l:' + i; });
    }
    markSel();
}
function elOf(s) { if (!FD || !s) return null; if (s.t === 'box') return FD.querySelector('.hero-box'); return FD.querySelector(`[data-hlx="${s.t}:${s.t === 'l' ? s.i : s.k}"]`); }
function markSel() {
    if (!FD) return;
    FD.querySelectorAll('.hlx-sel').forEach(e => e.classList.remove('hlx-sel'));
    const bx = FD.getElementById('hlxBox');
    if (sel && sel.t !== 'box') { const el = elOf(sel); if (el) el.classList.add('hlx-sel'); }
    if (bx) { const b = sel && sel.t === 'box' && elOf(sel); bx.style.display = b ? 'block' : 'none';
        if (b) { const r = b.getBoundingClientRect(); Object.assign(bx.style, { left: r.left + 'px', top: r.top + 'px', width: r.width + 'px', height: r.height + 'px' }); bx.querySelector('b').textContent = `사진 칸 ${Math.round(E.box.w)} × ${Math.round(E.box.h)}%`; } }
}
function guides(v, h) { if (!FD) return; const g = FD.querySelectorAll('.hlx-guide'); if (g[0]) g[0].style.display = v ? 'block' : 'none'; if (g[1]) g[1].style.display = h ? 'block' : 'none'; }
const objOf = s => !s ? null : s.t === 'l' ? E.layers[s.i] : s.t === 'p' ? E.parts[s.k] : s.t === 'box' ? E.box : null;
function select(s) { sel = s; markSel(); renderLayers(); renderProps(); }

// ---- 끌기 (글자 칸 = 칸 기준 % 가운데 자리 / 사진 칸 = 첫 화면 기준 % 왼쪽 위·크기)
function bindFrame() {
    if (!FD || FD._hlxBound) return; FD._hlxBound = 1;
    let drag = null;
    const SNAP = 1.2;
    FD.addEventListener('pointerdown', e => {
        if (!E || e.button > 0) return;
        const corner = e.target.closest('.hlx-box i');
        const part = !corner && e.target.closest('[data-hlx]');
        let s = null;
        if (corner) s = { t: 'box' };
        else if (part) { const [t, k] = part.dataset.hlx.split(':'); s = t === 'l' ? { t, i: +k } : { t, k }; }
        else { const b = FD.querySelector('.hero-box'); if (b) { const r = b.getBoundingClientRect(); if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) s = { t: 'box' }; } }
        if (!s) { select(null); return; }
        e.preventDefault();
        if (!sel || sel.t !== s.t || sel.i !== s.i || sel.k !== s.k) select(s);
        if (RO) return;
        const o = objOf(s); if (!o) return;
        const ref = s.t === 'box' ? FD.querySelector('.hero-photo-wrap') : (part.closest('.drag-canvas') || part.parentElement);
        drag = { s, o0: clone(o), x0: e.clientX, y0: e.clientY, r: ref.getBoundingClientRect(), el: s.t === 'box' ? FD.querySelector('.hero-box') : part, c: corner ? corner.dataset.c : '', moved: false, before: JSON.stringify(E) };
        try { FD.documentElement.setPointerCapture && e.target.setPointerCapture(e.pointerId); } catch (err) {}
    });
    FD.addEventListener('pointermove', e => {
        if (!drag) { const h = e.target.closest && e.target.closest('[data-hlx]'); FD.querySelectorAll('.hlx-hover').forEach(x => x !== h && x.classList.remove('hlx-hover')); if (h && !h.classList.contains('hlx-sel')) h.classList.add('hlx-hover'); return; }
        const dx = (e.clientX - drag.x0) / drag.r.width * 100, dy = (e.clientY - drag.y0) / drag.r.height * 100;
        if (!drag.moved && Math.abs(e.clientX - drag.x0) + Math.abs(e.clientY - drag.y0) < 3) return;
        drag.moved = true;
        const o = objOf(drag.s), a = drag.o0, r1 = v => Math.round(v * 10) / 10;
        if (drag.s.t === 'box') {
            if (!drag.c) { let x = a.x + dx, y = a.y + dy; const cx = x + a.w / 2, cy = y + a.h / 2, sv = Math.abs(cx - 50) < SNAP, sh = Math.abs(cy - 50) < SNAP;
                if (sv) x = 50 - a.w / 2; if (sh) y = 50 - a.h / 2; o.x = r1(Math.max(-50, Math.min(100, x))); o.y = r1(Math.max(-50, Math.min(100, y))); guides(sv, sh); }
            else { let { x, y, w, h } = a; const c = drag.c;
                if (c.includes('e')) w = a.w + dx; if (c.includes('s')) h = a.h + dy;
                if (c.includes('w')) { w = a.w - dx; x = a.x + dx; } if (c.includes('n')) { h = a.h - dy; y = a.y + dy; }
                if (e.shiftKey) { const k = Math.max(w / a.w, h / a.h); w = a.w * k; h = a.h * k; if (c.includes('w')) x = a.x + a.w - w; if (c.includes('n')) y = a.y + a.h - h; } // Shift = 비율 그대로
                o.w = r1(Math.max(5, Math.min(200, w))); o.h = r1(Math.max(5, Math.min(200, h))); o.x = r1(x); o.y = r1(y); }
            Object.assign(drag.el.style, { left: o.x + '%', top: o.y + '%', width: o.w + '%', height: o.h + '%' });
            markSel();
        } else {
            let x = num(a.x, 50) + dx, y = num(a.y, 50) + dy; const sv = Math.abs(x - 50) < SNAP, sh = Math.abs(y - 50) < SNAP;
            if (sv) x = 50; if (sh) y = 50;
            const lo = E.edge ? 0 : 1, hi = E.edge ? 100 : 99;
            o.x = r1(Math.max(lo, Math.min(hi, x))); o.y = r1(Math.max(0, Math.min(100, y)));
            drag.el.style.left = o.x + '%'; drag.el.style.top = o.y + '%'; guides(sv, sh);
        }
        renderPropsXY();
    });
    const up = () => { if (!drag) return; const d = drag; drag = null; guides(false, false);
        if (d.moved) { hist.push(d.before); fut = []; dirty = true; draw(); renderProps(); } };
    FD.addEventListener('pointerup', up); FD.addEventListener('pointercancel', up);
    FD.addEventListener('keydown', onKey);
    FD.addEventListener('dblclick', e => { const p = e.target.closest('[data-hlx^="l:"]'); if (p) { const ta = $('pr').querySelector('textarea[data-k="text"]'); if (ta) { ta.focus(); ta.select(); } } });
}

// ---- 키보드
function onKey(e) {
    if (!E || !$('hx').classList.contains('editing')) return;
    const tag = (e.target && e.target.tagName) || '', typing = /INPUT|TEXTAREA|SELECT/.test(tag) && e.target.ownerDocument === document;
    const mod = e.ctrlKey || e.metaKey;
    if (mod && e.key.toLowerCase() === 'z' && !typing) { e.preventDefault(); e.shiftKey ? redo() : undo(); return; }
    if (mod && e.key.toLowerCase() === 'y' && !typing) { e.preventDefault(); redo(); return; }
    if (mod && e.key.toLowerCase() === 's') { e.preventDefault(); $('btnSave').click(); return; }
    if (typing || RO) return;
    if (e.key === 'Escape') { select(null); return; }
    if (!sel) return;
    if (mod && e.key.toLowerCase() === 'd' && sel.t === 'l') { e.preventDefault(); dupLayer(); return; }
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); delSel(); return; }
    const d = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key]; if (!d) return;
    e.preventDefault(); const o = objOf(sel); if (!o) return; snap();
    const st = e.shiftKey ? 2 : .5; o.x = Math.round((num(o.x, 50) + d[0] * st) * 10) / 10; o.y = Math.round((num(o.y, 50) + d[1] * st) * 10) / 10;
    draw(); renderProps();
}
document.addEventListener('keydown', onKey);
window.addEventListener('beforeunload', e => { if (E && dirty && !RO) { e.preventDefault(); e.returnValue = ''; } });

// ---- 칸 목록 (레이어)
function renderLayers() {
    if (!E) return;
    const row = (s, ic, t, extra, hid) => `<div class="lyr-r${sel && sel.t === s.t && sel.i === s.i && sel.k === s.k ? ' on' : ''}${hid ? ' hid' : ''}" data-s='${JSON.stringify(s)}'><i>${ic}</i><span>${esc(t)}</span>${extra || ''}</div>`;
    let h = '<div class="lyr-h">글자 · 스티커 칸 (위에 있는 것이 앞)</div>';
    h += E.layers.map((L, i) => ({ L, i })).reverse().map(({ L, i }) => row({ t: 'l', i }, L.stk ? '★' : L.deco ? '▢' : L.vertical ? '↕' : 'T', L.stk ? '스티커 · ' + stkName(L.stk) : String(L.text || '(빈 칸)').replace(/\n/g, ' / '),
        RO ? '' : `<button type="button" data-up="${i}" title="앞으로">▲</button><button type="button" data-dn="${i}" title="뒤로">▼</button><button type="button" data-rm="${i}" title="지우기">✕</button>`)).join('') || '<p class="hint">글자 칸이 없어요. 아래에서 넣어 주세요.</p>';
    h += '<div class="lyr-h">이름·날짜 (고객이 적은 그대로)</div>';
    h += PART_KEYS.map(k => row({ t: 'p', k }, '◎', PART_NAMES[k], RO ? '' : `<button type="button" data-eye="${k}" title="${E.hide.includes(k) ? '보이기' : '숨기기'}">${E.hide.includes(k) ? '보이기' : '숨기기'}</button>`, E.hide.includes(k))).join('');
    h += '<div class="lyr-h">사진</div>';
    h += E.box ? row({ t: 'box' }, '▣', `사진 칸 (${frameName(E.box.frame)})`, RO ? '' : '<button type="button" data-nobox title="사진을 화면 가득">없애기</button>') : `<div class="lyr-r" data-s='{"t":"bg"}'><i>▣</i><span>사진 화면 가득</span>${RO ? '' : '<button type="button" data-mkbox>사진 칸으로</button>'}</div>`;
    $('lyr').innerHTML = h;
    $('add').innerHTML = [['big', '큰 제목', 'our wedding day'], ['names', '이름', '{신랑} ♥ {신부}'], ['date', '날짜', '{날짜:점}'], ['venue', '예식장', '{예식장}'], ['vert', '세로 글씨', '세로로'], ['bubble', '말풍선', '웹툰'], ['caption', '나레이션', '노란 상자'], ['seal', '도장', '포인트 색'], ['arc', '휜 글씨', '아치'], ['small', '작은 글귀', '두 줄 문장']].map(([k, t, s]) => `<button type="button" data-add="${k}"><b>${esc(t)}</b>${esc(s)}</button>`).join('')
        + '<button type="button" class="stk" data-add="stk"><b>★ 스티커 넣기</b>하트 · 꽃 · 반짝이는 별 …</button>';
}
const frameName = f => ((IB.HERO_BOX_FRAMES || []).find(x => x[0] === f) || [, '사각형'])[1];
$('lyr').addEventListener('click', e => {
    if (!E) return;
    const b = e.target.closest('button');
    if (b && !RO) {
        if (b.dataset.rm != null) { sel = { t: 'l', i: +b.dataset.rm }; delSel(); return; }
        if (b.dataset.up != null || b.dataset.dn != null) { const i = +(b.dataset.up ?? b.dataset.dn), j = b.dataset.up != null ? i + 1 : i - 1; if (j < 0 || j >= E.layers.length) return; snap(); [E.layers[i], E.layers[j]] = [E.layers[j], E.layers[i]]; sel = { t: 'l', i: j }; renderAll(); return; }
        if (b.dataset.eye) { const k = b.dataset.eye; snap(); if (E.hide.includes(k)) { E.hide = E.hide.filter(x => x !== k); if (!E.parts[k]) E.parts[k] = { x: 50, y: 50, fontSize: k === 'datetime' ? 13 : 24 }; sel = { t: 'p', k }; } else { E.hide.push(k); if (sel && sel.k === k) sel = null; } renderAll(); return; }
        if (b.hasAttribute('data-nobox')) { snap(); E.box = null; E.video = E.video || { heightMode: 'full', videoTextOver: true, heroShade: E.photo.heroShade || 'both', heroShadeLv: E.photo.heroShadeLv }; sel = null; renderAll(); return; }
        if (b.hasAttribute('data-mkbox')) { mkBox(); return; }
    }
    const r = e.target.closest('[data-s]'); if (!r) return;
    const s = JSON.parse(r.dataset.s); if (s.t === 'bg') { select(null); return; }
    select(s);
});
function mkBox() { snap(); E.box = { x: 9, y: 14, w: 82, h: 56, frame: 'rounded', fade: 0 }; E.photo.heroShade = 'none'; E.video = null; E.layers.forEach(L => { if ((L.color || '').toUpperCase() === '#FFFFFF') L.color = ''; }); sel = { t: 'box' }; renderAll(); }
$('add').addEventListener('click', e => {
    const b = e.target.closest('[data-add]'); if (!b || !E || RO) return;
    if (E.layers.length >= MAX_LAYERS) { LD.alert(`글자 · 스티커 칸은 모두 ${MAX_LAYERS}개까지 넣을 수 있어요.`); return; }
    if (b.dataset.add === 'stk') { stkPick('', k => { if (E.layers.length >= MAX_LAYERS) return; snap(); E.layers.push({ text: '', stk: k, x: 50, y: 50, width: 22, widthAuto: false }); sel = { t: 'l', i: E.layers.length - 1 }; renderAll(); }); return; }
    const ink = E.box ? '' : '#FFFFFF';
    const P = { big: { text: 'our wedding day', fontSize: 44, font: 'cormorant', color: ink }, names: { text: '{신랑} ♥ {신부}', fontSize: 24, color: ink, ls: 40 }, date: { text: '{날짜:점}', fontSize: 14, color: ink, ls: 120 },
        venue: { text: '{예식장}', fontSize: 13, color: ink, ls: 40 }, vert: { text: '{신랑} {신부}', fontSize: 13, color: ink, vertical: 'up', ls: 120 }, bubble: { text: '우리 결혼해요!', fontSize: 15, color: '#1B1B1F', deco: 'bubble' },
        caption: { text: '{날짜:점} {시간}\n{예식장}', fontSize: 12, color: '#1B1B1F', deco: 'caption', rotation: -2 }, seal: { text: '囍', fontSize: 22, deco: 'seal', color: '#FFFFFF' },
        arc: { text: 'WE ARE GETTING MARRIED', fontSize: 15, color: ink, arc: 40, arcW: 72, ls: 120 }, small: { text: '서로가 마주보며 다져온 사랑을\n이제 함께 한곳을 바라보며 걸어가려 합니다.', fontSize: 12, color: ink, align: 'center' } }[b.dataset.add];
    snap(); const L = Object.assign({ x: 50, y: 50 }, P); if (!L.color) delete L.color;
    E.layers.push(L); sel = { t: 'l', i: E.layers.length - 1 }; renderAll();
});
function dupLayer() { if (!sel || sel.t !== 'l' || E.layers.length >= MAX_LAYERS) return; snap(); const c = clone(E.layers[sel.i]); c.x = Math.min(99, num(c.x, 50) + 3); c.y = Math.min(99, num(c.y, 50) + 3); E.layers.push(c); sel = { t: 'l', i: E.layers.length - 1 }; renderAll(); }
function delSel() {
    if (!sel) return; snap();
    if (sel.t === 'l') E.layers.splice(sel.i, 1); else if (sel.t === 'p') { if (!E.hide.includes(sel.k)) E.hide.push(sel.k); } else if (sel.t === 'box') { E.box = null; E.video = { heightMode: 'full', videoTextOver: true, heroShade: E.photo.heroShade || 'both', heroShadeLv: E.photo.heroShadeLv }; }
    sel = null; renderAll();
}

// ---- 오른쪽 모양 칸
const opt = (list, cur) => list.map(([v, t]) => `<option value="${esc(v)}"${String(cur ?? '') === String(v) ? ' selected' : ''}>${esc(t)}</option>`).join('');
const seg = (k, list, cur) => `<span class="seg" data-seg="${k}">${list.map(([v, t]) => `<button type="button" data-v="${esc(v)}" class="${String(cur || '') === String(v) ? 'on' : ''}">${esc(t)}</button>`).join('')}</span>`;
const rng = (k, v, mn, mx, st, lab) => `<div class="f"><span>${lab}</span><div class="in"><input type="range" data-k="${k}" min="${mn}" max="${mx}" step="${st}" value="${v}"><input type="number" data-k="${k}" min="${mn}" max="${mx}" step="${st}" value="${v}"></div></div>`;
const col = (k, v, lab, autoTxt) => `<div class="f"><span>${lab}</span><div class="in"><input type="color" data-k="${k}" value="${/^#[0-9A-Fa-f]{6}$/.test(v || '') ? v : '#FFFFFF'}"><button type="button" class="btn2 sm${v ? '' : ' pri'}" data-clr="${k}">${autoTxt || '자동'}</button></div></div>`;
function renderProps() {
    if (!E) return;
    const o = objOf(sel), dis = RO ? ' disabled' : '';
    let h = '';
    const isStk = sel && sel.t === 'l' && o && !!o.stk;
    if (isStk) {
        h += `<div class="sec"><b>스티커</b><div class="stk-pv"><img src="/invite/assets/stickers/${esc(o.stk)}" alt=""><div><div style="font-size:13px;font-weight:600">${esc(stkName(o.stk))}</div>${RO ? '' : '<button type="button" class="btn2 sm" data-act="stkswap" style="margin-top:6px">다른 그림으로 바꾸기</button>'}</div></div>
            ${rng('width', num(o.width, 22), 3, 100, 1, '크기 (폭 %)')}
            ${rng('rotation', num(o.rotation, 0), -180, 180, 1, '기울기')}
            <div class="f"><span>자리</span><div class="in">가로 <input type="number" data-k="x" step=".5" value="${num(o.x, 50)}"> 세로 <input type="number" data-k="y" step=".5" value="${num(o.y, 50)}"></div></div>
            <p class="hint">휴대폰 화면에서 스티커를 끌어 옮겨요. 움직이는 그림은 하객 화면에서도 계속 움직여요.</p></div>
            <div class="sec"><b>등장 효과</b>
            <div class="f"><span>효과</span><select data-k="anim"${dis}>${opt(IB.HL_ANIMS || [['', '없음']], o.anim || '')}</select></div>
            ${o.anim ? rng('animDelay', num(o.animDelay, 0), 0, 8, .1, '시작 (초)') + rng('animDur', num(o.animDur, 1.8), .3, 8, .1, '길이 (초)') : ''}</div>
            <div class="sec rw"><div class="acts"><button type="button" class="btn2 sm" data-act="dup">복제 (Ctrl+D)</button><button type="button" class="btn2 sm" data-act="front">맨 앞으로</button><button type="button" class="btn2 sm" data-act="back">맨 뒤로</button><button type="button" class="btn2 sm dng" data-act="del">지우기</button></div></div>`;
    }
    if (sel && sel.t === 'l' && o && !isStk) {
        h += `<div class="sec"><b>글자</b><textarea data-k="text" rows="3"${dis}>${esc(o.text || '')}</textarea>
            <div class="tok">${TOKENS.map(([t, s]) => `<button type="button" data-tok="${esc(t)}" title="${esc(s)}">${esc(t.replace(/[{}]/g, ''))}</button>`).join('')}</div>
            <p class="hint">눌러서 넣는 칸은 고객이 적은 이름·날짜·예식장으로 바뀌어요. 줄을 바꾸면 여러 줄로 보여요.</p></div>`;
    }
    if (sel && (sel.t === 'l' || sel.t === 'p') && o && !isStk) {
        h += `<div class="sec"><b>${sel.t === 'p' ? esc(PART_NAMES[sel.k]) + ' - ' : ''}글자 모양</b>
            <div class="f"><span>글꼴</span><select data-k="font"${dis}>${opt(FONTS, o.font || '')}</select></div>
            ${rng('fontSize', num(o.fontSize, 16), 6, 120, .5, '크기')}
            ${col('color', o.color, '색', sel.t === 'l' ? '자동' : '자동')}
            ${rng('ls', num(o.ls, 0), -100, 600, 10, '자간')}
            ${rng('rotation', num(o.rotation, 0), -45, 45, 1, '기울기')}
            <div class="f"><span>정렬</span>${seg('align', [['', '기본'], ['left', '왼쪽'], ['center', '가운데'], ['right', '오른쪽']], o.align)}</div>
            ${sel.t === 'l' ? `<div class="f"><span>그림자</span><div class="in"><label><input type="checkbox" data-k="shadow"${o.shadow ? ' checked' : ''}${dis}> 그림자</label><label><input type="checkbox" data-k="outline"${o.outline ? ' checked' : ''}${dis}> 테두리</label></div></div>` : ''}
            <div class="f"><span>자리</span><div class="in">가로 <input type="number" data-k="x" step=".5" value="${num(o.x, 50)}"> 세로 <input type="number" data-k="y" step=".5" value="${num(o.y, 50)}"></div></div></div>`;
    }
    if (sel && sel.t === 'l' && o && !isStk) {
        h += `<div class="sec"><b>꾸밈</b>
            <div class="f"><span>글자 상자</span><select data-k="deco"${dis}>${opt(IB.HL_DECOS || [['', '없음']], o.deco || '')}</select></div>
            <div class="f"><span>세로 쓰기</span>${seg('vertical', [['', '가로'], ['up', '세로 (글자 바로)'], ['side', '세로 (눕혀서)']], o.vertical)}</div>
            ${rng('arc', num(o.arc, 0), -100, 100, 5, '휘기')}
            ${num(o.arc, 0) ? rng('arcW', num(o.arcW, 70), 10, 100, 1, '휜 폭 %') : ''}</div>
            <div class="sec"><b>등장 효과</b>
            <div class="f"><span>효과</span><select data-k="anim"${dis}>${opt(IB.HL_ANIMS || [['', '없음']], o.anim || '')}</select></div>
            ${o.anim ? rng('animDelay', num(o.animDelay, 0), 0, 8, .1, '시작 (초)') + rng('animDur', num(o.animDur, 1.8), .3, 8, .1, '길이 (초)') : ''}
            <p class="hint">위 [▶ 효과 보기]로 확인해요. 효과가 없는 칸은 처음부터 보여요.</p></div>`;
        h += `<div class="sec rw"><div class="acts"><button type="button" class="btn2 sm" data-act="dup">복제 (Ctrl+D)</button><button type="button" class="btn2 sm" data-act="front">맨 앞으로</button><button type="button" class="btn2 sm" data-act="back">맨 뒤로</button><button type="button" class="btn2 sm dng" data-act="del">지우기</button></div></div>`;
    }
    if (sel && sel.t === 'p' && o) h += `<div class="sec rw"><p class="hint">고객이 적은 이름·날짜가 그대로 들어가요. 문장을 바꾸려면 숨기고 글자 칸을 넣어 주세요.</p><div class="acts"><button type="button" class="btn2 sm dng" data-act="del">숨기기</button></div></div>`;
    if (sel && sel.t === 'box' && o) {
        h += `<div class="sec"><b>사진 칸</b>
            <div class="f"><span>모양</span><select data-k="frame"${dis}>${opt(IB.HERO_BOX_FRAMES || [['none', '사각형']], o.frame || 'none')}</select></div>
            ${rng('fade', num(o.fade, 0), 0, 90, 5, '아래 흐리게')}
            ${col('bg', o.bg, '칸 밖 바탕', '스킨 바탕')}
            <div class="f"><span>자리·크기</span><div class="in">X <input type="number" data-k="x" step=".5" value="${num(o.x, 0)}"> Y <input type="number" data-k="y" step=".5" value="${num(o.y, 0)}"></div></div>
            <div class="f"><span></span><div class="in">폭 <input type="number" data-k="w" step=".5" value="${num(o.w, 80)}"> 높이 <input type="number" data-k="h" step=".5" value="${num(o.h, 60)}"></div></div>
            <p class="hint">사진 칸을 끌어서 옮기고 네 귀퉁이로 크기를 바꿔요 (<b>Shift</b> = 비율 그대로). 좌표는 첫 화면 기준 %.</p>
            <div class="acts rw"><button type="button" class="btn2 sm" data-act="center">가운데로</button><button type="button" class="btn2 sm dng" data-act="del">사진 칸 없애기</button></div></div>`;
    }
    if (!sel) {
        const ph = E.photo || {};
        h += `<div class="sec"><b>레이아웃</b>
            <div class="f"><span>설명</span><input type="text" data-g="desc" maxlength="80" value="${esc(E.desc || '')}" placeholder="예: 위에 큰 영문 제목 · 아래 날짜"${dis}></div>
            <div class="f"><span>묶음</span><select data-g="_cat"${dis}>${opt(CATS, E._cat)}</select></div>
            <div class="f"><span>대표</span><label><input type="checkbox" data-g="_core"${E._core ? ' checked' : ''}${dis}> 묶음 첫 줄에 (아니면 '비슷한 모양 더 보기')</label></div>
            <div class="f"><span>끝까지</span><label><input type="checkbox" data-g="edge"${E.edge ? ' checked' : ''}${dis}> 고객이 글자를 화면 끝까지 끌 수 있게</label></div></div>
            <div class="sec"><b>사진</b>
            <div class="f"><span>모양</span>${seg('_box', [['', '화면 가득'], ['1', '사진 칸']], E.box ? '1' : '')}</div>
            <div class="f"><span>글자 잘 보이게</span><select data-g="heroShade"${dis}>${opt(IB.HERO_SHADES || [['none', '없음']], ph.heroShade || 'none')}</select></div>
            ${ph.heroShade && ph.heroShade !== 'none' ? `<div class="f"><span>진하기</span>${seg('_lv', IB.HERO_SHADE_LV || [], ph.heroShadeLv || '1')}</div>` : ''}
            <p class="hint">영상(유튜브) 첫 화면에도 같은 레이아웃이 쓰여요. 사진 칸 레이아웃이면 영상도 칸 안에 들어가요.</p></div>
            <div class="sec"><b>쓰는 법</b><p class="hint">휴대폰 화면에서 글자를 눌러 고르고 끌어서 옮겨요. 왼쪽 아래 버튼으로 글자 칸을 넣고, 오른쪽에서 글꼴·크기·색·꾸밈·등장 효과를 바꿔요.<br>
            빈 곳을 누르면 이 칸(레이아웃 설정)으로 돌아와요. 다 되면 위 [저장] (Ctrl+S).</p></div>`;
    }
    $('pr').innerHTML = h;
}
function renderPropsXY() { const o = objOf(sel); if (!o) return; ['x', 'y', 'w', 'h'].forEach(k => $('pr').querySelectorAll(`input[type=number][data-k="${k}"]`).forEach(i => { if (o[k] != null) i.value = o[k]; })); }
function renderAll() { renderLayers(); renderProps(); draw(); }
let typingSnap = 0;
function setProp(k, v, live) {
    const o = objOf(sel); if (!o || RO) return;
    if (!live || Date.now() - typingSnap > 800) snap(); typingSnap = Date.now(); dirty = true;
    if (v === '' || v == null) delete o[k]; else o[k] = v;
    if (k === 'arc' && !v) delete o.arcW;
    draw();
}
$('pr').addEventListener('input', e => {
    const t = e.target, k = t.dataset.k;
    if (t.dataset.g) { setG(t.dataset.g, t.type === 'checkbox' ? t.checked : t.value); return; }
    if (!k) return;
    let v = t.type === 'checkbox' ? t.checked : t.value;
    if (t.type === 'range' || t.type === 'number') { v = Number(v); if (!isFinite(v)) return; const twin = $('pr').querySelector(`input[data-k="${k}"][type=${t.type === 'range' ? 'number' : 'range'}]`); if (twin) twin.value = v; if (v === 0 && ['ls', 'rotation', 'arc', 'animDelay', 'fade'].includes(k)) v = ''; }
    if (t.type === 'checkbox' && !v) v = '';
    if (t.type === 'color') { v = t.value.toUpperCase(); const b = $('pr').querySelector(`[data-clr="${k}"]`); if (b) b.classList.remove('pri'); }
    setProp(k, v, t.type !== 'checkbox');
    if (k === 'text') renderLayers();
});
$('pr').addEventListener('change', e => { const k = e.target.dataset.k; if (['anim', 'arc', 'deco'].includes(k)) renderProps(); if (e.target.dataset.g === 'heroShade') renderProps(); });
function setG(k, v) {
    if (RO) return; snap();
    if (k === 'desc') E.desc = v; else if (k === '_cat') E._cat = v; else if (k === '_core') E._core = !!v; else if (k === 'edge') { if (v) E.edge = true; else delete E.edge; }
    else if (k === 'heroShade') { E.photo.heroShade = v; if (E.video) E.video.heroShade = v; if (v !== 'none' && !E.photo.heroShadeLv) { E.photo.heroShadeLv = '1'; if (E.video) E.video.heroShadeLv = '1'; } }
    draw();
}
$('pr').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b || RO) return;
    if (b.dataset.tok) { const ta = $('pr').querySelector('textarea[data-k="text"]'); if (!ta) return; const s = ta.selectionStart ?? ta.value.length, en = ta.selectionEnd ?? s; ta.value = ta.value.slice(0, s) + b.dataset.tok + ta.value.slice(en); ta.focus(); ta.selectionStart = ta.selectionEnd = s + b.dataset.tok.length; ta.dispatchEvent(new Event('input', { bubbles: true })); return; }
    if (b.dataset.clr) { setProp(b.dataset.clr, ''); renderProps(); return; }
    const sg = b.closest('[data-seg]');
    if (sg) { const k = sg.dataset.seg, v = b.dataset.v;
        if (k === '_box') { if (v && !E.box) mkBox(); else if (!v && E.box) { sel = { t: 'box' }; delSel(); } return; }
        if (k === '_lv') { snap(); E.photo.heroShadeLv = v; if (E.video) E.video.heroShadeLv = v; draw(); renderProps(); return; }
        setProp(k, v); renderProps(); return; }
    const a = b.dataset.act; if (!a) return;
    if (a === 'dup') dupLayer();
    else if (a === 'del') delSel();
    else if (a === 'front' || a === 'back') { snap(); const L = E.layers.splice(sel.i, 1)[0]; if (a === 'front') { E.layers.push(L); sel.i = E.layers.length - 1; } else { E.layers.unshift(L); sel.i = 0; } renderAll(); }
    else if (a === 'stkswap' && sel && sel.t === 'l') { const L = E.layers[sel.i]; if (L) stkPick(L.stk, k => { snap(); L.stk = k; renderAll(); }); }
    else if (a === 'center' && E.box) { snap(); E.box.x = Math.round((50 - E.box.w / 2) * 10) / 10; draw(); renderProps(); }
});

// ---- 스티커 고르기 창 (1019z2) - 테마 칩을 누르면 그 묶음으로 내려감, 모든 그림이 한 번에 보임
function stkName(k) { const [t, f] = String(k || '').split('/'); const g = STK_LIB.find(x => x[0] === t); return (g ? g[1] + ' · ' : '') + String(f || '').replace(/\.(svg|webp)$/, ''); }
function stkPick(cur, done) {
    if (!STK_LIB.length) { LD.alert('스티커 그림이 없어요.'); return; }
    const bg = document.createElement('div'); bg.className = 'skp-bg';
    const n = STK_LIB.reduce((a, g) => a + g[2].length, 0);
    bg.innerHTML = `<div class="skp" role="dialog" aria-label="스티커 고르기"><div class="skp-h"><b>스티커 고르기</b><small>${n}개 · 누르면 넣어요</small><button type="button" data-x aria-label="닫기">✕</button></div>
        <div class="skp-t">${STK_LIB.map(([t, l], i) => `<button type="button" data-t="${esc(t)}" class="${i ? '' : 'on'}">${esc(l)}</button>`).join('')}</div>
        <div class="skp-b">${STK_LIB.map(([t, l, fs]) => `<div class="skp-lab" id="skp-${esc(t)}">${esc(l)} <small style="font-weight:400;color:#A39890">${fs.length}</small></div><div class="skp-g">${fs.map(f => { const k = t + '/' + f; return `<button type="button" data-k="${esc(k)}" class="${k === cur ? 'on' : ''}" title="${esc(f.replace(/\.(svg|webp)$/, ''))}"><img src="/invite/assets/stickers/${esc(k)}" alt="" loading="lazy"></button>`; }).join('')}</div>`).join('')}</div></div>`;
    document.body.appendChild(bg);
    const body = bg.querySelector('.skp-b'), close = () => { bg.remove(); document.removeEventListener('keydown', esc1); };
    const esc1 = e => { if (e.key === 'Escape') close(); }; document.addEventListener('keydown', esc1);
    bg.addEventListener('click', e => {
        if (e.target === bg || e.target.closest('[data-x]')) { close(); return; }
        const t = e.target.closest('[data-t]'); if (t) { const lab = bg.querySelector('#skp-' + t.dataset.t); if (lab) body.scrollTo({ top: lab.offsetTop - 4, behavior: 'smooth' }); return; }
        const k = e.target.closest('[data-k]'); if (k) { close(); done(k.dataset.k); }
    });
    body.addEventListener('scroll', () => { let on = STK_LIB[0][0]; STK_LIB.forEach(([t]) => { const lab = bg.querySelector('#skp-' + t); if (lab && lab.offsetTop - 30 <= body.scrollTop) on = t; }); bg.querySelectorAll('.skp-t button').forEach(b => b.classList.toggle('on', b.dataset.t === on)); }, { passive: true });
    if (cur) { const c = bg.querySelector('.skp-g .on'); if (c) c.scrollIntoView({ block: 'center' }); }
}

// ---------------------------------------------------------------- 시작
fetch('hero_layouts.php?a=list', { cache: 'no-store' }).then(r => r.json()).then(j => { take(j); renderList(); }).catch(() => { renderList(); });
renderList();
if ($('fr').contentWindow && $('fr').contentWindow.hlReady) frameReady();
window.addEventListener('message', ev => { if (ev.origin === location.origin && ev.data && ev.data.hlFrame === 'ready') frameReady(); });
})();
</script>
</body>
</html>
