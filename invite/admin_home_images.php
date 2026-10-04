<?php
/**
 * admin_home_images.php - 관리자: 홈 이미지 (홈페이지 첫 화면 휴대폰 사진 + 관리자 로그인 화면 카드 사진)
 *
 *  - 칸마다 사진 고르기(끌어다 놓기도 됨) → 왼쪽 미리보기에 바로 보임 → 아래 "저장"
 *  - 사진을 누르면 그 자리가 초점(잘릴 때 보일 부분)이 됨
 *  - "기본으로"를 누르면 원래 색 그라데이션으로
 *  - 사진은 브라우저에서 먼저 줄여서(최대 1600px) 올리고, 서버에서 webp로 다시 만들어 저장 (site_images.php)
 *  - 샘플 디자인 썸네일: 홈페이지 "N가지 디자인에서 시작하세요" 카드 사진 + 홈에 보일 디자인 고르기(최대 6개, home_sample_ids)
 *  부관리자: "사업자·사이트 정보 수정" 권한(site_settings)이 있어야 저장할 수 있다 (없으면 보기 전용)
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/site_images.php';

$pdo = get_pdo();
$presets = site_presets();
$slotKeys = array_merge(array_keys(SITE_IMAGE_SLOTS), array_map(fn($id) => 'preset_' . $id, array_keys($presets)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    header('Content-Type: application/json; charset=utf-8');
    $changed = 0;
    try {
        $dir = site_image_dir();
        foreach ($slotKeys as $slot) {
            $cur = site_image($slot);
            $pos = (string) ($_POST['pos'][$slot] ?? '');
            if (!preg_match('/^\d{1,3}% \d{1,3}%$/', $pos)) $pos = '50% 50%';
            $file = $_FILES['img_' . $slot] ?? null;
            if (!empty($_POST['remove'][$slot])) {
                if ($cur) { @unlink($dir . $cur['file']); save_app_setting($pdo, 'site_img_' . $slot, ''); $changed++; }
            } elseif ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                [$name] = snap_store_image($file, 0, $dir);
                save_app_setting($pdo, 'site_img_' . $slot, $name . '|' . $pos);
                if ($cur) @unlink($dir . $cur['file']);
                $changed++;
            } elseif ($cur && $cur['pos'] !== $pos) {
                save_app_setting($pdo, 'site_img_' . $slot, $cur['file'] . '|' . $pos);
                $changed++;
            }
        }
        // 홈에 보일 샘플 디자인 (최대 6개, 고른 순서대로)
        if (isset($_POST['home_ids'])) {
            $ids = array_slice(array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $_POST['home_ids'])), fn($id) => isset($presets[$id])))), 0, 6);
            if (implode(',', $ids) !== app_setting('home_sample_ids')) { save_app_setting($pdo, 'home_sample_ids', implode(',', $ids)); $changed++; }
        }
        echo json_encode(['ok' => true, 'changed' => $changed], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 지금 사진 + 기본 그라데이션 (index.php / admin_login.php와 같은 색)
$DEFAULT = [
    'home_p1'  => 'linear-gradient(170deg,#E5D5CF 0%,#B28A89 55%,#6D3F48 100%)',
    'home_g1'  => 'linear-gradient(150deg,#D9C4BE,#9D7473)',
    'home_g2'  => 'linear-gradient(150deg,#D6DDCE,#879A80)',
    'home_g3'  => 'linear-gradient(150deg,#DADDE3,#8F99AA)',
    'home_g4'  => 'linear-gradient(150deg,#EAD9C6,#B8906A)',
    'login_c1' => 'linear-gradient(170deg,#E5D5CF 0%,#B28A89 55%,#6D3F48 100%)',
    'login_c2' => 'linear-gradient(165deg,#E9E4DA 0%,#A9B39E 60%,#5E6D57 100%)',
    'login_c3' => 'linear-gradient(170deg,#EDE3D6 0%,#C49E7C 55%,#7B5638 100%)',
];
$OVERLAY = [
    'home_p1'  => 'linear-gradient(180deg,rgba(20,10,12,0) 38%,rgba(20,10,12,.58) 100%)',
    'login_c1' => 'linear-gradient(180deg,rgba(20,10,12,0) 45%,rgba(20,10,12,.62) 100%)',
    'login_c2' => 'linear-gradient(180deg,rgba(20,10,12,0) 45%,rgba(20,10,12,.62) 100%)',
    'login_c3' => 'linear-gradient(180deg,rgba(20,10,12,0) 45%,rgba(20,10,12,.62) 100%)',
];
$state = [];
foreach (SITE_IMAGE_SLOTS as $slot => [$group, $label, $desc, $ratio]) {
    $img = site_image($slot);
    $state[$slot] = ['url' => $img['url'] ?? null, 'pos' => $img['pos'] ?? '50% 50%', 'def' => $DEFAULT[$slot], 'ov' => $OVERLAY[$slot] ?? '', 'ratio' => $ratio, 'defLabel' => '기본 그라데이션', 'mk' => false];
}
// 샘플 디자인: 기본은 presets 폴더의 thumb 사진, 없으면 디자인 색으로 그린 카드 (index.php와 같게)
foreach ($presets as $id => $p) {
    $img = site_image('preset_' . $id);
    $state['preset_' . $id] = ['url' => $img['url'] ?? null, 'pos' => $img['pos'] ?? '50% 50%',
        'def' => $p['folderThumb'] ? "url('" . $p['folderThumb'] . "') center/cover no-repeat" : $p['bg'], 'ov' => '', 'ratio' => 0.57,
        'defLabel' => $p['folderThumb'] ? '기본 썸네일' : '디자인 색 카드', 'mk' => !$p['folderThumb']];
}
$homeIds = array_values(array_filter(array_map('trim', explode(',', app_setting('home_sample_ids'))), fn($id) => isset($presets[$id])));
/** 디자인 색으로 그린 카드 (사진이 없을 때) */
$mk = function (array $p) {
    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    return '<span class="mk" aria-hidden="true"><span class="top" style="background:linear-gradient(165deg,' . $e($p['line']) . ',' . $e($p['accent']) . ')"></span>'
         . '<span class="nm" style="color:' . $e($p['ink']) . '">민준 <span style="color:' . $e($p['accent']) . '">♥</span> 서연</span>'
         . '<span class="ln" style="width:52%;background:' . $e($p['line']) . '"></span><span class="ln" style="width:36%;background:' . $e($p['line']) . '"></span></span>';
};
$csrf = csrf_token();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$bg = function (string $slot) use ($state): string {
    $s = $state[$slot];
    if (!$s['url']) return 'background:' . $s['def'];
    return 'background:' . ($s['ov'] ? $s['ov'] . ',' : '') . "url('" . htmlspecialchars($s['url'], ENT_QUOTES, 'UTF-8') . "') " . $s['pos'] . '/cover no-repeat';
};
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>홈 이미지 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.hi, .hi * { box-sizing: border-box; }
.hi-top { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin: 0 0 22px; }
.hi-top .page-title { margin: 0; }
.hi-top p { flex-basis: 100%; margin: 4px 0 0; font-size: 13px; color: var(--muted); line-height: 1.7; }
.hi-links { margin-left: auto; display: flex; gap: 8px; }
.hi-links a { display: inline-flex; align-items: center; gap: 6px; padding: 8px 13px; border-radius: 999px; border: 1px solid #E3DED6; background: #fff; font-size: 13px; color: #2B2B2B; text-decoration: none; }
.hi-links a:hover { background: #F6F3EE; }

.hi-sec { display: grid; grid-template-columns: minmax(300px, 420px) 1fr; gap: 22px; align-items: start; margin: 0 0 34px; }
.hi-sec h3 { grid-column: 1 / -1; margin: 0; font-size: 16px; display: flex; align-items: center; gap: 8px; }
.hi-sec h3 small { font-size: 12.5px; color: var(--muted); font-weight: 500; }

/* 미리보기 */
.pv { position: sticky; top: 72px; border-radius: 18px; overflow: hidden; border: 1px solid #ECE8E2; box-shadow: 0 12px 30px rgba(40, 25, 20, .08); }
.pv-cap { position: absolute; left: 12px; top: 12px; z-index: 5; font-size: 11px; font-weight: 700; letter-spacing: .08em; padding: 4px 9px; border-radius: 999px; background: rgba(255, 255, 255, .9); color: #6F6A63; }
@keyframes hbob { 50% { translate: 0 -7px; } }
/* 홈 미리보기 */
.pv-home { height: 360px; background: #F6F4F1; position: relative; }
.pv-home::before { content: ''; position: absolute; left: 8%; top: 8%; width: 84%; height: 80%; border-radius: 50%; background: radial-gradient(circle at 42% 40%, rgba(138, 75, 85, .24), rgba(214, 170, 150, .12) 45%, transparent 70%); filter: blur(22px); }
.mph { position: absolute; width: 150px; height: 306px; border-radius: 24px; border: 6px solid #151413; background: #fff; overflow: hidden; box-shadow: 0 20px 40px rgba(40, 25, 20, .2); animation: hbob 7s ease-in-out infinite; }
.mph.a { left: calc(50% - 150px); top: 30px; transform: rotate(-4deg); }
.mph.b { left: calc(50% - 22px); top: 42px; transform: rotate(5deg); animation-duration: 8s; animation-delay: -2s; background: #FBF9F6; padding: 22px 11px; }
.mph .scr { position: absolute; inset: 0; transition: background .25s; }
.mph .t { position: absolute; left: 0; right: 0; top: 52%; text-align: center; color: #fff; }
.mph .t small { font-size: 6.5px; letter-spacing: .28em; opacity: .85; }
.mph .t b { display: block; font-size: 14px; margin: 5px 0; font-weight: 600; }
.mph .t span { font-size: 7.5px; opacity: .9; }
.mph .ttl { text-align: center; font-size: 7px; letter-spacing: .3em; color: #8A4B55; }
.mph .ty { text-align: center; font-size: 12px; margin: 8px 0 12px; font-weight: 600; }
.mph .gal { display: grid; grid-template-columns: 1fr 1fr; gap: 3px; }
.mph .gal i { height: 44px; border-radius: 4px; display: block; transition: background .25s; }
.mph .rs { margin-top: 9px; border-radius: 7px; background: #1B1A18; color: #fff; text-align: center; font-size: 7px; padding: 6px; }
/* 로그인 미리보기 */
.pv-login { height: 300px; position: relative; color: #fff; background: radial-gradient(120% 90% at 0% 0%, #5B2F38 0%, transparent 60%), radial-gradient(90% 80% at 100% 100%, #3E2A2E 0%, transparent 60%), linear-gradient(160deg, #3A2328 0%, #2A1C1F 60%, #1E1517 100%); }
.pv-login .ey { position: absolute; left: 22px; top: 44px; font-size: 9px; letter-spacing: .28em; font-weight: 700; color: #E3A9B1; }
.pv-login .hd { position: absolute; left: 22px; top: 60px; font-size: 19px; line-height: 1.4; font-weight: 600; }
.pv-login .hd em { font-style: normal; color: #F0C4C9; }
.mcard { position: absolute; width: 92px; height: 136px; border-radius: 12px; overflow: hidden; border: 1px solid rgba(255, 255, 255, .14); box-shadow: 0 16px 34px rgba(0, 0, 0, .35); animation: hbob 7s ease-in-out infinite; transition: background .25s; }
.mcard .t { position: absolute; left: 0; right: 0; bottom: 16px; text-align: center; }
.mcard .t small { display: block; font-size: 5.5px; letter-spacing: .3em; opacity: .85; }
.mcard .t b { display: block; font-size: 10px; margin-top: 4px; font-weight: 600; }
.mcard.c1 { left: 22px; top: 140px; transform: rotate(-7deg); }
.mcard.c2 { left: 94px; top: 134px; transform: rotate(3deg); animation-delay: -2.4s; }
.mcard.c3 { left: 166px; top: 144px; transform: rotate(9deg); animation-delay: -4.6s; }

/* 칸 목록 */
.slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; }
.slot { background: #fff; border: 1px solid #ECE8E2; border-radius: 16px; padding: 12px; display: flex; gap: 12px; align-items: flex-start; transition: border-color .2s, box-shadow .2s; }
.slot.dirty { border-color: #2B2B2B; box-shadow: 0 0 0 3px rgba(43, 43, 43, .06); }
.slot.drag { border-color: #03A04B; background: #F2FBF6; }
.thumb { position: relative; flex: none; width: 84px; border-radius: 10px; overflow: hidden; cursor: crosshair; border: 1px solid #ECE8E2; transition: background .25s; }
.thumb.def { cursor: pointer; }
.thumb .dot { position: absolute; width: 18px; height: 18px; margin: -9px 0 0 -9px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0, 0, 0, .35), 0 2px 6px rgba(0, 0, 0, .3); pointer-events: none; display: none; }
.thumb:not(.def) .dot { display: block; }
.thumb .plus { position: absolute; inset: 0; display: grid; place-items: center; color: rgba(255, 255, 255, .9); font-size: 22px; font-weight: 300; text-shadow: 0 1px 4px rgba(0, 0, 0, .3); pointer-events: none; }
.thumb:not(.def) .plus { display: none; }
.slot-b { flex: 1; min-width: 0; }
.slot-b b { display: block; font-size: 13.5px; }
.slot-b small { display: block; font-size: 11.5px; color: #A29C94; line-height: 1.5; margin: 2px 0 8px; }
.slot-b .tag { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background: #F1EEE9; color: #8A847B; margin-bottom: 8px; }
.slot-b .tag.photo { background: #E9F5EE; color: #2F7A4E; }
.slot-b .tag.new { background: #2B2B2B; color: #fff; }
.slot-act { display: flex; gap: 6px; flex-wrap: wrap; }
.slot-act button { border: 1px solid #E3DED6; background: #fff; border-radius: 9px; padding: 6px 10px; font: inherit; font-size: 12.5px; cursor: pointer; color: #2B2B2B; }
.slot-act button:hover { background: #F6F3EE; }
.slot-act .pick { background: #2B2B2B; border-color: #2B2B2B; color: #fff; }
.slot-act .pick:hover { background: #111; }
.slot-act button:disabled { opacity: .45; cursor: not-allowed; }
.slot-hint { font-size: 11px; color: #A29C94; margin-top: 7px; }

/* 샘플 디자인 */
.hi-sec.wide { grid-template-columns: 1fr; }
.pv-samples { background: #F6F4F1; padding: 44px 16px 18px; display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
.msm { position: relative; height: 210px; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 0 #EAE6E0; transition: background .25s; }
.mk { position: absolute; inset: 0; display: flex; flex-direction: column; pointer-events: none; }
.mk .top { height: 52%; position: relative; }
.mk .nm { text-align: center; margin-top: 10px; font-size: 11px; font-weight: 600; }
.mk .ln { height: 4px; border-radius: 3px; margin: 6px auto 0; opacity: .55; }
.msm .lb { position: absolute; left: 8px; right: 8px; bottom: 8px; background: rgba(255, 255, 255, .93); border-radius: 8px; padding: 6px 8px; font-size: 11px; font-weight: 600; color: #1B1A18; line-height: 1.3; }
.msm .lb small { display: block; color: #A39D95; font-weight: 500; font-size: 9.5px; }
.msm .ord { position: absolute; top: 7px; left: 7px; width: 20px; height: 20px; border-radius: 50%; background: rgba(27, 26, 24, .8); color: #fff; font-size: 10.5px; font-weight: 700; display: grid; place-items: center; }
.msm.auto .ord { background: rgba(255, 255, 255, .85); color: #8A847B; }
.pv-note { padding: 0 16px 14px; background: #F6F4F1; font-size: 11.5px; color: #8A847B; }
.slot .thumb .mk .nm { font-size: 8px; margin-top: 6px; } .slot .thumb .mk .ln { height: 3px; margin-top: 4px; }
.homesw { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 8px; margin: 0 0 8px; font-size: 12px; color: #6F6A63; white-space: nowrap; cursor: pointer; }
.homesw b { font-size: 10.5px; font-weight: 700; color: #fff; background: #2B2B2B; border-radius: 999px; padding: 1px 7px; }
.homesw b:empty { display: none; }
.sw { position: relative; flex: none; display: inline-block; width: 40px; height: 24px; cursor: pointer; }
.sw input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 1; }
.sw i { position: absolute; inset: 0; border-radius: 999px; background: #E3E0DB; transition: background .2s; }
.sw i::after { content: ''; position: absolute; top: 2px; left: 2px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 2px 5px rgba(0, 0, 0, .22); transition: transform .22s cubic-bezier(.3, .7, .4, 1.2); }
.sw input:checked + i { background: #34C759; }
.sw input:checked + i::after { transform: translateX(16px); }
.sw input:disabled + i { opacity: .5; }
.hi-sec.wide .pv { position: relative; top: 0; }
@media (max-width: 860px) { .pv-samples { grid-template-columns: repeat(3, 1fr); } }

/* 저장 막대 */
.savebar { position: fixed; left: 50%; bottom: 18px; z-index: 90; transform: translate(-50%, 140%); display: flex; align-items: center; gap: 10px; padding: 10px 10px 10px 18px; border-radius: 16px; background: #2B2B2B; color: #fff; font-size: 13.5px; box-shadow: 0 16px 34px rgba(0, 0, 0, .25); transition: transform .3s cubic-bezier(.2, .8, .3, 1); white-space: nowrap; }
.savebar.on { transform: translate(-50%, 0); }
.savebar button { border: 0; border-radius: 10px; padding: 9px 16px; font: inherit; font-size: 13.5px; font-weight: 700; cursor: pointer; }
.savebar .undo { background: transparent; color: #CFC9C1; }
.savebar .save { background: #fff; color: #2B2B2B; }
.savebar.busy .save { opacity: .6; pointer-events: none; }
body { padding-bottom: 90px; }

@media (max-width: 860px) {
    .hi-sec { grid-template-columns: 1fr; }
    .pv { position: relative; top: 0; }
    .hi-links { margin-left: 0; }
}
@media (prefers-reduced-motion: reduce) { .mph, .mcard { animation: none; } }
</style>
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('homeimg', '홈 이미지'); ?>

    <div class="wrap hi">
        <div class="hi-top">
            <h2 class="page-title">홈 이미지</h2>
            <div class="hi-links">
                <a href="/" target="_blank" rel="noopener">홈페이지 보기 ↗</a>
                <a href="admin_login.php?preview=1" target="_blank" rel="noopener">로그인 화면 보기 ↗</a>
            </div>
            <p>홈페이지 첫 화면의 휴대폰 속 사진, 샘플 디자인 카드, 관리자 로그인 화면의 카드 사진을 바꿔요. 사진을 고르면 왼쪽 미리보기에 바로 보이고, 아래 <b>저장</b>을 눌러야 실제 화면에 반영돼요.
               사진을 누르면 그 자리가 <b>초점</b>(잘릴 때 보이는 부분)이 돼요. 사진이 없는 칸은 원래 색 그라데이션이 나와요.</p>
        </div>

        <form method="post" id="hiForm" onsubmit="return false">
        <?php foreach (['home' => ['홈페이지 첫 화면', 'loveday.kr 맨 위 휴대폰 두 대'], 'login' => ['관리자 로그인 화면', '왼쪽 와인색 패널의 카드 세 장']] as $group => [$title, $sub]): ?>
        <section class="hi-sec">
            <h3><?= $h($title) ?> <small><?= $h($sub) ?></small></h3>
            <div class="pv">
                <span class="pv-cap">미리보기</span>
                <?php if ($group === 'home'): ?>
                <div class="pv-home">
                    <div class="mph a"><div class="scr" data-pv="home_p1" style="<?= $bg('home_p1') ?>"><div class="t"><small>WE ARE GETTING MARRIED</small><b>민준 ♥ 서연</b><span>2026. 11. 14 SAT 2PM</span></div></div></div>
                    <div class="mph b"><div class="ttl">OUR STORY</div><div class="ty">우리 결혼합니다</div>
                        <div class="gal"><?php foreach (['g1', 'g2', 'g3', 'g4'] as $g): ?><i data-pv="home_<?= $g ?>" style="<?= $bg('home_' . $g) ?>"></i><?php endforeach; ?></div>
                        <div class="rs">참석 의사 전달하기</div></div>
                </div>
                <?php else: ?>
                <div class="pv-login">
                    <div class="ey">ADMIN STUDIO</div>
                    <div class="hd">오늘도 누군가의<br><em>첫 번째 편지</em>를 지켜요</div>
                    <?php foreach ([1 => ['WEDDING', '민준 · 서연'], 2 => ['INVITATION', '지훈 · 하은'], 3 => ['SAVE THE DATE', '현우 · 수아']] as $n => [$e1, $e2]): ?>
                        <div class="mcard c<?= $n ?>" data-pv="login_c<?= $n ?>" style="<?= $bg('login_c' . $n) ?>"><div class="t"><small><?= $e1 ?></small><b><?= $e2 ?></b></div></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="slots">
                <?php foreach (SITE_IMAGE_SLOTS as $slot => [$g, $label, $desc, $ratio]): if ($g !== $group) continue; $s = $state[$slot]; ?>
                <div class="slot" data-slot="<?= $slot ?>">
                    <div class="thumb<?= $s['url'] ? '' : ' def' ?>" style="aspect-ratio: <?= $ratio ?>; <?= $s['url'] ? "background:url('" . $h($s['url']) . "') " . $s['pos'] . '/cover no-repeat' : 'background:' . $s['def'] ?>" title="사진을 누르면 그 자리가 초점이 돼요">
                        <i class="dot"></i><span class="plus">＋</span>
                    </div>
                    <div class="slot-b">
                        <b><?= $h($label) ?></b>
                        <small><?= $h($desc) ?></small>
                        <span class="tag<?= $s['url'] ? ' photo' : '' ?>" data-tag><?= $s['url'] ? '사진' : '기본 그라데이션' ?></span>
                        <div class="slot-act">
                            <button type="button" class="pick" data-pick>사진 고르기</button>
                            <button type="button" data-def<?= $s['url'] ? '' : ' disabled' ?>>기본으로</button>
                        </div>
                        <input type="file" accept="image/jpeg,image/png,image/webp" hidden>
                        <div class="slot-hint">끌어다 놓아도 돼요</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <?php if ($presets): ?>
        <section class="hi-sec wide">
            <h3>홈페이지 샘플 디자인 <small>"<?= count($presets) ?>가지 디자인에서 시작하세요" 카드 · 스위치로 홈에 보일 디자인을 골라요 (최대 6개, 켠 순서대로)</small></h3>
            <div class="pv">
                <span class="pv-cap">미리보기</span>
                <div class="pv-samples" id="pvSamples"></div>
                <div class="pv-note" id="pvNote"></div>
            </div>
            <div class="slots">
                <?php foreach ($presets as $id => $p): $slot = 'preset_' . $id; $s = $state[$slot]; ?>
                <div class="slot" data-slot="<?= $h($slot) ?>" data-preset="<?= $h($id) ?>">
                    <div class="thumb<?= $s['url'] ? '' : ' def' ?>" style="aspect-ratio: .57; <?= $s['url'] ? "background:url('" . $h($s['url']) . "') " . $s['pos'] . '/cover no-repeat' : 'background:' . $h($s['def']) ?>" title="사진을 누르면 그 자리가 초점이 돼요">
                        <?= $s['mk'] ? $mk($p) : '' ?><i class="dot"></i><span class="plus">＋</span>
                    </div>
                    <div class="slot-b">
                        <b><?= $h($p['label']) ?></b>
                        <small><?= $h($p['tag'] ?: $id) ?></small>
                        <label class="homesw"><span class="sw"><input type="checkbox" data-home<?= in_array($id, $homeIds, true) ? ' checked' : '' ?>><i></i></span>홈에 보이기 <b data-ord></b></label>
                        <span class="tag<?= $s['url'] ? ' photo' : '' ?>" data-tag><?= $s['url'] ? '사진' : $h($s['defLabel']) ?></span>
                        <div class="slot-act">
                            <button type="button" class="pick" data-pick>사진 고르기</button>
                            <button type="button" data-def<?= $s['url'] ? '' : ' disabled' ?>>기본으로</button>
                        </div>
                        <input type="file" accept="image/jpeg,image/png,image/webp" hidden>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        </form>
    </div>

    <div class="savebar" id="saveBar"><span id="saveCnt">바뀐 칸 0개</span><button type="button" class="undo" id="undoAll">되돌리기</button><button type="button" class="save" id="saveAll">저장</button></div>

<script src="assets/ld-dialog.js"></script>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>;
    const S = <?= json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const P = <?= json_encode(array_map(fn($p) => ['label' => $p['label'], 'tag' => $p['tag'], 'mk' => $mk($p)], $presets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const HOME0 = <?= json_encode($homeIds) ?>;
    let homeIds = HOME0.slice();
    const readonly = !!document.getElementById('admRo');
    const cur = {}; // 칸마다 {url, pos, blob, remove}
    Object.keys(S).forEach(k => cur[k] = { url: S[k].url, pos: S[k].pos, blob: null, remove: false });
    const toast = m => window.LD ? LD.toast(m) : null;

    const css = (k, withOv) => {
        const c = cur[k];
        if (!c.url) return S[k].def;
        return (withOv && S[k].ov ? S[k].ov + ',' : '') + `url("${c.url}") ${c.pos}/cover no-repeat`;
    };
    const isDirty = k => cur[k].blob || cur[k].remove || (cur[k].url && cur[k].pos !== S[k].pos);
    const homeDirty = () => homeIds.join(',') !== HOME0.join(',');
    function paintPv(k) {
        const show = !cur[k].url && S[k].mk;
        document.querySelectorAll(`[data-pv="${k}"]`).forEach(el => { el.style.background = css(k, true); const m = el.querySelector('.mk'); if (m) m.style.display = show ? '' : 'none'; });
    }
    function updateBar() {
        const n = Object.keys(cur).filter(isDirty).length + (homeDirty() ? 1 : 0);
        document.getElementById('saveCnt').textContent = `바뀐 곳 ${n}개`;
        document.getElementById('saveBar').classList.toggle('on', n > 0);
    }
    function render(k) {
        const c = cur[k], slot = document.querySelector(`.slot[data-slot="${k}"]`), th = slot.querySelector('.thumb');
        th.style.background = css(k, false); th.classList.toggle('def', !c.url);
        const m = th.querySelector('.mk'); if (m) m.style.display = (!c.url && S[k].mk) ? '' : 'none';
        const [x, y] = c.pos.split(' '); const dot = th.querySelector('.dot'); dot.style.left = x; dot.style.top = y;
        paintPv(k);
        const tag = slot.querySelector('[data-tag]');
        tag.textContent = c.blob ? '새 사진 (저장 전)' : c.remove ? '기본으로 (저장 전)' : c.url ? '사진' : S[k].defLabel;
        tag.className = 'tag' + (c.blob || c.remove ? ' new' : c.url ? ' photo' : '');
        slot.querySelector('[data-def]').disabled = readonly || !c.url;
        slot.classList.toggle('dirty', !!isDirty(k));
        updateBar();
    }

    // 샘플 디자인 미리보기: 켠 디자인(순서대로) + 6개가 안 되면 번호 순서로 채움 (index.php와 같게)
    const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    function renderSamples() {
        const box = document.getElementById('pvSamples'); if (!box) return;
        const list = homeIds.slice(0, 6); const auto = [];
        Object.keys(P).forEach(id => { if (list.length < 6 && !list.includes(id)) { list.push(id); auto.push(id); } });
        box.innerHTML = list.map((id, i) => `<div class="msm${auto.includes(id) ? ' auto' : ''}" data-pv="preset_${esc(id)}">${P[id].mk}<span class="ord">${i + 1}</span><span class="lb">${esc(P[id].label)}${P[id].tag ? `<small>${esc(P[id].tag)}</small>` : ''}</span></div>`).join('');
        list.forEach(id => paintPv('preset_' + id));
        document.querySelectorAll('.slot[data-preset]').forEach(sl => {
            const i = homeIds.indexOf(sl.dataset.preset); sl.querySelector('[data-ord]').textContent = i >= 0 ? `${i + 1}번째` : '';
            sl.querySelector('[data-home]').checked = i >= 0;
        });
        document.getElementById('pvNote').textContent = homeIds.length >= 6 ? '홈에 6개가 모두 골라져 있어요.'
            : `${homeIds.length}개 골랐어요. 나머지 ${6 - homeIds.length}칸은 디자인 번호 순서로 자동으로 채워져요 (흐린 번호).`;
        updateBar();
    }
    document.querySelectorAll('[data-home]').forEach(cb => {
        if (readonly) { cb.disabled = true; return; }
        cb.addEventListener('change', () => {
            const id = cb.closest('.slot').dataset.preset;
            if (cb.checked) {
                if (homeIds.length >= 6) { cb.checked = false; window.LD ? LD.alert('홈에는 6개까지 보여요. 다른 디자인을 먼저 꺼주세요.') : alert('6개까지만 고를 수 있어요.'); return; }
                homeIds.push(id);
            } else homeIds = homeIds.filter(x => x !== id);
            renderSamples();
        });
    });
    renderSamples();
    Object.keys(cur).forEach(render);

    // 브라우저에서 먼저 줄이기 (긴 쪽 1600px, jpg) - 휴대폰 사진도 빠르게 올라가게
    async function shrink(file) {
        try {
            const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const r = Math.min(1, 1600 / Math.max(bmp.width, bmp.height));
            const c = document.createElement('canvas'); c.width = Math.round(bmp.width * r); c.height = Math.round(bmp.height * r);
            c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
            return await new Promise(res => c.toBlob(b => res(b || file), 'image/jpeg', .9));
        } catch (e) { return file; }
    }
    async function setFile(k, file) {
        if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) { window.LD ? LD.alert('jpg, png, webp 사진만 올릴 수 있어요.') : alert('사진만 올릴 수 있어요.'); return; }
        const blob = await shrink(file);
        if (cur[k].blob && cur[k].url) URL.revokeObjectURL(cur[k].url);
        cur[k] = { url: URL.createObjectURL(blob), pos: '50% 50%', blob, remove: false };
        render(k);
    }

    document.querySelectorAll('.slot').forEach(slot => {
        const k = slot.dataset.slot, input = slot.querySelector('input[type=file]'), th = slot.querySelector('.thumb');
        if (readonly) { th.style.cursor = 'default'; return; }
        slot.querySelector('[data-pick]').addEventListener('click', () => input.click());
        input.addEventListener('change', () => { if (input.files[0]) setFile(k, input.files[0]); input.value = ''; });
        slot.querySelector('[data-def]').addEventListener('click', () => {
            if (cur[k].blob) URL.revokeObjectURL(cur[k].url);
            cur[k] = S[k].url ? { url: null, pos: '50% 50%', blob: null, remove: true } : { url: null, pos: '50% 50%', blob: null, remove: false };
            render(k);
        });
        // 사진 누르기 → 초점 / 비어 있으면 사진 고르기
        th.addEventListener('click', e => {
            if (!cur[k].url) { input.click(); return; }
            const r = th.getBoundingClientRect();
            const x = Math.round(Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * 100), y = Math.round(Math.max(0, Math.min(1, (e.clientY - r.top) / r.height)) * 100);
            cur[k].pos = `${x}% ${y}%`; render(k);
        });
        // 끌어다 놓기
        ['dragenter', 'dragover'].forEach(t => slot.addEventListener(t, e => { e.preventDefault(); slot.classList.add('drag'); }));
        ['dragleave', 'drop'].forEach(t => slot.addEventListener(t, e => { e.preventDefault(); slot.classList.remove('drag'); }));
        slot.addEventListener('drop', e => { const f = e.dataTransfer.files[0]; if (f) setFile(k, f); });
    });

    document.getElementById('undoAll').addEventListener('click', () => {
        Object.keys(cur).forEach(k => { if (cur[k].blob) URL.revokeObjectURL(cur[k].url); cur[k] = { url: S[k].url, pos: S[k].pos, blob: null, remove: false }; render(k); });
        homeIds = HOME0.slice(); renderSamples();
    });
    const bar = document.getElementById('saveBar');
    document.getElementById('saveAll').addEventListener('click', () => {
        const fd = new FormData(); fd.set('csrf_token', CSRF); fd.set('ajax', '1');
        Object.keys(cur).forEach(k => {
            const c = cur[k];
            fd.set(`pos[${k}]`, c.pos);
            if (c.remove) fd.set(`remove[${k}]`, '1');
            if (c.blob) fd.set('img_' + k, c.blob, k + '.jpg');
        });
        if (homeDirty()) fd.set('home_ids', homeIds.join(','));
        bar.classList.add('busy');
        fetch('admin_home_images.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json()).then(j => {
                if (!j.ok) throw new Error(j.error || '저장하지 못했어요.');
                guard.release(); toast('저장했어요. 홈페이지와 로그인 화면에 바로 반영돼요');
                setTimeout(() => location.reload(), 700);
            })
            .catch(e => { bar.classList.remove('busy'); window.LD ? LD.alert(e.message) : alert(e.message); });
    });
    // 저장 안 하고 나가려 하면 LOVE DAY 팝업으로 확인 (새로고침·탭 닫기만 브라우저 기본 창)
    const guard = LD.guardLeave(() => Object.keys(cur).some(isDirty) || homeDirty(), { message: '바꾼 사진이 아직 저장되지 않았어요.\n아래 "저장"을 누르지 않고 나가면 사라져요.' });
})();
</script>
</body>
</html>
