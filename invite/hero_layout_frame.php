<?php
/**
 * hero_layout_frame.php - 관리자 '첫 화면 레이아웃' 캔버스 안의 휴대폰 화면 (1019w)
 *
 *  하객 화면과 같은 그림 도구(render-invite.js · invite-blocks.js)로 첫 화면 하나만 그린다.
 *  부모 화면(admin_hero_layouts.php)이 window.hlDraw(design)를 불러 다시 그리고, 글자 칸 끌기 등은 부모가 이 문서를 직접 만짐 (같은 사이트).
 *  저장하는 것 없음 · 관리자만 열림
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
header('X-Frame-Options: SAMEORIGIN');
function hlf_asset(string $name): string
{
    $min = preg_replace('/\.(css|js)$/', '.min.$1', $name);
    $use = is_file(__DIR__ . '/assets/' . $min) ? $min : $name;
    return '/invite/assets/' . $use . '?v=' . (@filemtime(__DIR__ . '/assets/' . $use) ?: time());
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>첫 화면 미리보기</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<link rel="stylesheet" href="<?= hlf_asset('render-invite.css') ?>">
<link rel="stylesheet" href="<?= hlf_asset('invite-blocks.css') ?>">
<style>
html, body { margin: 0; overflow: hidden; }
/* 첫 화면만 보면 되는 것은 숨김 */
.ib-share-fab, .ib-fab, .ld-wm, .ib-bgm, .bgm-btn, .video-fullscreen-btn, .ib-sharebar { display: none !important; }
/* 캔버스 도구: 고른 칸 · 가운데 맞춤 선 · 사진 칸 손잡이 */
.hlx-sel { outline: 2px solid #3B82F6 !important; outline-offset: 3px; }
.hlx-hover { outline: 1px dashed rgba(59,130,246,.8); outline-offset: 3px; }
.hlx-ov { position: fixed; inset: 0; pointer-events: none; z-index: 99999; }
.hlx-guide { position: absolute; background: #F0445A; display: none; }
.hlx-guide.v { top: 0; bottom: 0; width: 1px; left: 50%; } .hlx-guide.h { left: 0; right: 0; height: 1px; top: 50%; }
.hlx-box { position: absolute; border: 1.5px solid #3B82F6; display: none; box-sizing: border-box; }
/* --hk = 부모가 휴대폰을 줄인 배율의 역수 (휴대폰 관리자 화면에서 손잡이·선이 너무 작아지지 않게) */
:root { --hk: 1; }
.hlx-sel { outline-width: calc(2px * var(--hk)) !important; outline-offset: calc(3px * var(--hk)); }
.hlx-guide.v { width: calc(1px * var(--hk)); } .hlx-guide.h { height: calc(1px * var(--hk)); }
.hlx-box { border-width: calc(1.5px * var(--hk)); }
.hlx-box i { --s: calc(12px * var(--hk)); position: absolute; width: var(--s); height: var(--s); background: #fff; border: calc(1.5px * var(--hk)) solid #3B82F6; border-radius: calc(3px * var(--hk)); pointer-events: auto; box-sizing: border-box; touch-action: none; }
.hlx-box i::after { content: ''; position: absolute; inset: calc(-8px * var(--hk)); } /* 손가락으로 잡기 쉽게 */
.hlx-box i[data-c="nw"] { left: calc(var(--s) / -2); top: calc(var(--s) / -2); cursor: nwse-resize; } .hlx-box i[data-c="ne"] { right: calc(var(--s) / -2); top: calc(var(--s) / -2); cursor: nesw-resize; }
.hlx-box i[data-c="sw"] { left: calc(var(--s) / -2); bottom: calc(var(--s) / -2); cursor: nesw-resize; } .hlx-box i[data-c="se"] { right: calc(var(--s) / -2); bottom: calc(var(--s) / -2); cursor: nwse-resize; }
.hlx-box b { position: absolute; left: 50%; top: calc(-30px * var(--hk)); transform: translateX(-50%); background: #3B82F6; color: #fff; font: 600 calc(11px * var(--hk))/1 Pretendard, sans-serif; padding: calc(5px * var(--hk)) calc(7px * var(--hk)); border-radius: calc(6px * var(--hk)); white-space: nowrap; }
.drag-part { cursor: move; touch-action: none; }
.hero-box { touch-action: none; }
.hlx-editing .drag-part { user-select: none; -webkit-user-select: none; }
</style>
</head>
<body class="hlx-editing">
<div class="invite-frame" id="inviteRoot"></div>
<div class="hlx-ov" id="hlxOv"><i class="hlx-guide v"></i><i class="hlx-guide h"></i><div class="hlx-box" id="hlxBox"><b></b><i data-c="nw"></i><i data-c="ne"></i><i data-c="sw"></i><i data-c="se"></i></div></div>
<script src="<?= hlf_asset('invite-blocks.js') ?>"></script>
<script src="<?= hlf_asset('render-invite.js') ?>"></script>
<script>
// 부모가 부름: 디자인(첫 화면 섹션 + 날짜·예식장)을 하객 화면처럼 그림. 등장 효과는 다 나온 모습으로 (hlPlay로 다시 보기)
window.hlDraw = function (design) {
    const root = document.getElementById('inviteRoot');
    (JSON.stringify(design).match(/"font":"([a-z0-9-]+)"/g) || []).forEach(m => { try { InviteBlocks.ensureFont(m.slice(8, -1)); } catch (e) {} });
    renderInviteReadOnly(root, design, true);
    root.querySelectorAll('.hl-arm').forEach(e => e.classList.remove('hl-arm'));
    return root;
};
window.hlPlay = function () { const root = document.getElementById('inviteRoot'); try { InviteBlocks.playHeroAnims(root); } catch (e) {} };
window.hlSkins = function () { try { return Object.keys(skins); } catch (e) { return ['classic']; } };
window.hlReady = true;
try { parent.postMessage({ hlFrame: 'ready' }, location.origin); } catch (e) {}
</script>
</body>
</html>
