<?php
/**
 * admin_login.php - 관리자 로그인
 *  - 대표 관리자: "대표 관리자" 탭에서 비밀번호만 (config.php의 ADMIN_PASSWORD_HASH)
 *  - 부관리자  : "부관리자" 탭에서 대표가 만들어 준 아이디 + 비밀번호 (admin_subadmins.php)
 *  잘못 넣으면 IP별로 횟수를 세서 잠시 잠근다 (MAX_LOGIN_ATTEMPTS / LOGIN_LOCKOUT_SECONDS)
 *  화면: 왼쪽 브랜드 패널 + 오른쪽 로그인 카드 (휴대폰에서는 위아래). 스타일은 이 파일 안에만 있다.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_auth.php';
try { require_once __DIR__ . '/snap_functions.php'; require_once __DIR__ . '/site_images.php'; } catch (Throwable $e) {} // 카드 사진 (관리자 → 홈 이미지)

$pdo = get_pdo();
$ip  = client_ip();
$error = '';
$notice = '';
$lockMin = 0;

// 관리자 → 홈 이미지의 "로그인 화면 보기": 로그인한 관리자에게 화면만 보여줌 (로그인 폼은 잠금)
$preview = isset($_GET['preview']) && !empty($_SESSION['is_admin']);
if (!$preview && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    header('Location: admin_create.php');
    exit;
}
if ($preview && $_SERVER['REQUEST_METHOD'] === 'POST') { header('Location: admin_login.php?preview=1'); exit; }
$cardCss = fn(string $slot) => function_exists('site_image_css') ? site_image_css($slot, 'linear-gradient(180deg,rgba(20,10,12,0) 45%,rgba(20,10,12,.62) 100%)') : '';
if (isset($_GET['expired'])) $notice = '30분 동안 사용하지 않아 로그아웃됐어요. 다시 로그인해주세요.';
if (isset($_GET['off'])) $notice = '계정 정보가 바뀌어 로그아웃됐어요. 다시 로그인해주세요.';
if (isset($_GET['bye'])) $notice = '로그아웃했어요. 오늘도 수고 많으셨어요.';

/** 이 IP가 잠겨 있으면 남은 분, 아니면 0 (잠김 안내를 예쁜 화면으로 보여주려고 먼저 확인) */
function login_lock_minutes(PDO $pdo, string $ip): int
{
    try {
        $st = $pdo->prepare('SELECT locked_until FROM admin_login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        $u = (string) ($st->fetchColumn() ?: '');
        if ($u !== '' && strtotime($u) > time()) return (int) ceil((strtotime($u) - time()) / 60);
    } catch (Throwable $e) {}
    return 0;
}
/** 잠기기 전까지 남은 횟수 */
function login_tries_left(PDO $pdo, string $ip): int
{
    try {
        $st = $pdo->prepare('SELECT attempts FROM admin_login_attempts WHERE ip = ?');
        $st->execute([$ip]);
        return max(0, MAX_LOGIN_ATTEMPTS - (int) $st->fetchColumn());
    } catch (Throwable $e) { return MAX_LOGIN_ATTEMPTS; }
}

$mode = ($_POST['mode'] ?? $_GET['mode'] ?? '') === 'sub' ? 'sub' : 'owner';
$username = '';
$lockMin = login_lock_minutes($pdo, $ip);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($lockMin > 0) {
        $error = "로그인 시도가 너무 많아요. {$lockMin}분 뒤에 다시 해주세요.";
    } else {
        check_login_lockout($pdo, $ip);

        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($mode === 'owner') $username = '';

        if ($username === '' || $username === 'admin') {
            // 대표 관리자
            if (password_verify($password, ADMIN_PASSWORD_HASH)) {
                clear_login_failures($pdo, $ip);
                session_regenerate_id(true); // 세션 고정 공격 방지
                $_SESSION['is_admin'] = true;
                $_SESSION['admin_role'] = 'owner';
                $_SESSION['admin_login_at'] = time();
                header('Location: admin_create.php');
                exit;
            }
        } else {
            // 부관리자
            $row = null;
            try {
                admin_users_ensure($pdo);
                $st = $pdo->prepare('SELECT id, password_hash, active FROM admin_users WHERE username = ?');
                $st->execute([$username]);
                $row = $st->fetch() ?: null;
            } catch (Throwable $e) {}
            // 아이디가 없어도 같은 시간이 걸리게 (아이디가 있는지 알아내지 못하게)
            $ok = password_verify($password, $row['password_hash'] ?? '$2y$12$f20P2In.lQ4L9ww5RpK/0.7NuDXJS4oD5vf3zgvZ2cO54d.LxTLRO');
            if ($row && $ok && (int) $row['active']) {
                clear_login_failures($pdo, $ip);
                session_regenerate_id(true);
                $_SESSION['is_admin'] = true;
                $_SESSION['admin_role'] = 'sub';
                $_SESSION['admin_uid'] = (int) $row['id'];
                $_SESSION['admin_login_at'] = time();
                $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
                header('Location: admin_create.php');
                exit;
            }
            if ($row && $ok && !(int) $row['active']) {
                $error = '사용이 중지된 계정이에요. 대표 관리자에게 문의해주세요.';
            }
        }
        record_login_failure($pdo, $ip);
        $lockMin = login_lock_minutes($pdo, $ip);
        if ($lockMin > 0) {
            $error = "로그인 시도가 너무 많아요. {$lockMin}분 뒤에 다시 해주세요.";
        } elseif ($error === '') {
            $left = login_tries_left($pdo, $ip);
            $error = ($mode === 'sub' ? '아이디 또는 비밀번호가' : '비밀번호가') . ' 맞지 않아요.' . ($left > 0 && $left <= 3 ? " {$left}번 더 틀리면 잠시 잠겨요." : '');
        }
    }
}
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>관리자 로그인 · LOVE DAY</title>
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#2A1C1F">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Serif+KR:wght@400;600&display=swap">
<style>
:root { --bg: var(--ui-page, #F6F4F1); --ink: #1B1A18; --sub: #6F6A63; --faint: #A39D95; --line: var(--ui-line, #EAE6E0); --soft: var(--ui-soft, #F1EEEA); --accent: #8A4B55; --accent-soft: #F4ECEC; --wine: #2A1C1F; }
* { box-sizing: border-box; }
html, body { height: 100%; }
body { margin: 0; background: var(--bg); color: var(--ink); font-family: "Pretendard Variable", Pretendard, -apple-system, "Apple SD Gothic Neo", "Malgun Gothic", sans-serif; -webkit-font-smoothing: antialiased; word-break: keep-all; }
button, input { font-family: inherit; }

.shell { min-height: 100vh; min-height: 100dvh; display: grid; grid-template-columns: minmax(420px, 1.05fr) 1fr; }

/* ---------- 왼쪽: 브랜드 패널 ---------- */
.brand { position: relative; overflow: hidden; color: #F7EFEA; padding: 44px 52px; display: flex; flex-direction: column;
    background: radial-gradient(120% 90% at 0% 0%, #5B2F38 0%, transparent 60%), radial-gradient(90% 80% at 100% 100%, #3E2A2E 0%, transparent 60%), linear-gradient(160deg, #3A2328 0%, var(--wine) 60%, #1E1517 100%); }
.brand::after { /* 은은한 결 */
    content: ''; position: absolute; inset: 0; pointer-events: none; opacity: .18; mix-blend-mode: overlay;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); }
.glow { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .55; pointer-events: none; animation: drift 18s ease-in-out infinite alternate; }
.glow.a { width: 340px; height: 340px; background: #9C5A66; top: -80px; right: -60px; }
.glow.b { width: 280px; height: 280px; background: #6E4A3E; bottom: -90px; left: -40px; animation-duration: 22s; }
@keyframes drift { to { transform: translate(-30px, 26px) scale(1.08); } }

.logo { position: relative; z-index: 1; font-weight: 800; letter-spacing: .24em; font-size: 14px; color: #fff; text-decoration: none; }
.logo span { color: #E3A9B1; }
.brand-mid { position: relative; z-index: 1; margin: auto 0; padding: 40px 0; }
.eyebrow { font-size: 11.5px; letter-spacing: .28em; font-weight: 700; color: #E3A9B1; }
.brand h1 { font-family: "Noto Serif KR", serif; font-weight: 600; font-size: 40px; line-height: 1.38; letter-spacing: -.02em; margin: 16px 0 16px; color: #fff; }
.brand h1 em { font-style: normal; color: #F0C4C9; }
.brand p { margin: 0; font-size: 15px; line-height: 1.75; color: rgba(247, 239, 234, .72); max-width: 360px; }

/* 떠 있는 청첩장 카드들 (장식) */
.deck { position: relative; z-index: 1; height: 210px; margin-top: 36px; }
.card { position: absolute; width: 132px; height: 196px; border-radius: 16px; box-shadow: 0 24px 50px rgba(0, 0, 0, .35); overflow: hidden; border: 1px solid rgba(255, 255, 255, .14); animation: bob 7s ease-in-out infinite; }
.card .t { position: absolute; left: 0; right: 0; bottom: 26px; text-align: center; color: #fff; }
.card .t small { display: block; font-size: 8px; letter-spacing: .3em; opacity: .85; }
.card .t b { display: block; font-family: "Noto Serif KR", serif; font-weight: 600; font-size: 14px; margin-top: 6px; }
.card.c1 { left: 0; top: 6px; transform: rotate(-7deg); background: linear-gradient(170deg, #E5D5CF 0%, #B28A89 55%, #6D3F48 100%); }
.card.c2 { left: 104px; top: 0; transform: rotate(3deg); background: linear-gradient(165deg, var(--ui-line, #E9E4DA) 0%, #A9B39E 60%, #5E6D57 100%); animation-delay: -2.4s; }
.card.c3 { left: 208px; top: 12px; transform: rotate(9deg); background: linear-gradient(170deg, #EDE3D6 0%, #C49E7C 55%, #7B5638 100%); animation-delay: -4.6s; }
@keyframes bob { 50% { translate: 0 -8px; } }
.chip { position: absolute; z-index: 2; left: 250px; top: 150px; display: flex; align-items: center; gap: 9px; padding: 9px 13px 9px 10px; border-radius: 14px; background: rgba(255, 255, 255, .95); color: var(--ink); font-size: 12px; line-height: 1.35; box-shadow: 0 14px 30px rgba(0, 0, 0, .25); animation: bob 6s ease-in-out infinite; animation-delay: -1s; }
.chip i { width: 28px; height: 28px; border-radius: 9px; background: var(--accent-soft); color: var(--accent); display: grid; place-items: center; font-style: normal; font-size: 14px; }
.chip b { display: block; font-size: 12.5px; } .chip span { color: var(--faint); font-size: 11px; }
.brand-foot { position: relative; z-index: 1; font-size: 12px; color: rgba(247, 239, 234, .45); }

/* ---------- 오른쪽: 로그인 ---------- */
.side { display: flex; align-items: center; justify-content: center; padding: 40px 28px; position: relative; }
.box { width: 100%; max-width: 380px; animation: rise .5s cubic-bezier(.2, .8, .3, 1) both; }
@keyframes rise { from { opacity: 0; transform: translateY(14px); } }
.box h2 { font-size: 26px; letter-spacing: -.03em; margin: 0 0 6px; }
.box .lead { margin: 0 0 26px; font-size: 14px; color: var(--sub); }

.seg { position: relative; display: grid; grid-template-columns: 1fr 1fr; padding: 4px; border-radius: 14px; background: var(--soft); margin: 0 0 20px; }
.seg button { position: relative; z-index: 1; border: 0; background: none; padding: 10px 0; border-radius: 10px; font-size: 14px; font-weight: 600; color: var(--sub); cursor: pointer; transition: color .2s; }
.seg button.on { color: var(--ink); }
.seg .pill { position: absolute; top: 4px; bottom: 4px; left: 4px; width: calc(50% - 4px); border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(30, 20, 10, .08); transition: transform .28s cubic-bezier(.3, .8, .3, 1); }
.seg[data-mode="sub"] .pill { transform: translateX(100%); }

.msg { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; font-size: 13.5px; line-height: 1.55; margin: 0 0 18px; }
.msg.ok { background: #EEF5EF; color: #2F6B45; }
.msg.err { background: #FBEFEF; color: #9A3A41; animation: shake .38s; }
.msg.lock { background: #FFF6E5; color: #8A5A00; }
@keyframes shake { 20%, 60% { transform: translateX(-5px); } 40%, 80% { transform: translateX(5px); } }

.field { position: relative; margin: 0 0 12px; }
.field.hide { display: none; }
.field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--sub); margin: 0 0 6px; }
.inp { position: relative; }
.inp svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; fill: none; stroke: var(--faint); stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; pointer-events: none; transition: stroke .2s; }
.inp input { width: 100%; height: 52px; padding: 0 48px 0 44px; border: 1px solid var(--line); border-radius: 14px; background: #fff; font-size: 15.5px; color: var(--ink); transition: border-color .2s, box-shadow .2s; }
.inp input::placeholder { color: #BDB7AF; }
.inp input:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 4px rgba(27, 26, 24, .07); }
.inp input:focus + svg, .inp:focus-within svg { stroke: var(--ink); }
.eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border: 0; border-radius: 10px; background: none; color: var(--faint); cursor: pointer; display: grid; place-items: center; }
.eye:hover { background: var(--soft); color: var(--ink); }
.eye svg { position: static; transform: none; width: 19px; height: 19px; stroke: currentColor; }
.caps { display: none; margin: 7px 2px 0; font-size: 12px; color: #8A5A00; }
.caps.on { display: block; }

.go { position: relative; width: 100%; height: 54px; margin-top: 10px; border: 0; border-radius: 14px; background: var(--ink); color: #fff; font-size: 15.5px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: transform .15s, box-shadow .2s, opacity .2s; }
.go:hover { box-shadow: 0 12px 26px rgba(27, 26, 24, .22); transform: translateY(-1px); }
.go:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
.go .sp { display: none; width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(255, 255, 255, .35); border-top-color: #fff; animation: spin .8s linear infinite; }
.go.busy .sp { display: block; } .go.busy .lb { opacity: .8; }
@keyframes spin { to { transform: rotate(360deg); } }
.hint { margin: 16px 2px 0; font-size: 12.5px; color: var(--faint); line-height: 1.6; text-align: center; }
.side-foot { position: absolute; left: 0; right: 0; bottom: 22px; text-align: center; font-size: 12px; color: var(--faint); }
.side-foot a { color: var(--sub); text-decoration: none; }
.side-foot a:hover { color: var(--ink); }

/* ---------- 태블릿·휴대폰 ---------- */
@media (max-width: 900px) {
    .shell { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
    .brand { padding: 22px 22px 26px; min-height: 0; }
    .brand-mid { padding: 22px 0 0; margin: 0; }
    .brand h1 { font-size: 27px; margin: 10px 0 8px; }
    .brand p { font-size: 13.5px; }
    .deck, .brand-foot { display: none; }
    .glow.a { width: 220px; height: 220px; }
    .side { align-items: flex-start; padding: 28px 20px 70px; }
    .box h2 { font-size: 22px; }
}
.pv-bar { position: fixed; left: 50%; top: 14px; z-index: 50; transform: translateX(-50%); display: flex; gap: 12px; align-items: center; padding: 9px 16px; border-radius: 999px; background: rgba(27, 26, 24, .88); color: #fff; font-size: 13px; box-shadow: 0 10px 24px rgba(0, 0, 0, .25); white-space: nowrap; }
.pv-bar a { color: #F0C4C9; text-decoration: none; font-weight: 600; }
@media (prefers-reduced-motion: reduce) { .glow, .card, .chip, .box { animation: none; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
<?php if ($preview): ?><div class="pv-bar">👀 미리보기 화면이에요 · 로그인은 되지 않아요 <a href="admin_home_images.php">← 홈 이미지로</a></div><?php endif; ?>
<div class="shell">
    <aside class="brand" aria-hidden="false">
        <i class="glow a"></i><i class="glow b"></i>
        <a class="logo" href="/">LOVE<span>·</span>DAY</a>
        <div class="brand-mid">
            <div class="eyebrow">ADMIN STUDIO</div>
            <h1>오늘도 누군가의<br><em>첫 번째 편지</em>를 지켜요</h1>
            <p>청첩장 관리, 결제 확인, 게스트스냅과 휴지통까지. 러브데이 운영에 필요한 모든 걸 한곳에서.</p>
            <div class="deck" aria-hidden="true">
                <div class="card c1" style="<?= $cardCss('login_c1') ?>"><div class="t"><small>WEDDING</small><b>민준 · 서연</b></div></div>
                <div class="card c2" style="<?= $cardCss('login_c2') ?>"><div class="t"><small>INVITATION</small><b>지훈 · 하은</b></div></div>
                <div class="card c3" style="<?= $cardCss('login_c3') ?>"><div class="t"><small>SAVE THE DATE</small><b>현우 · 수아</b></div></div>
                <div class="chip"><i>💌</i><div><b>새 청첩장 발행</b><span>방금 전</span></div></div>
            </div>
        </div>
        <div class="brand-foot">관리자 전용 페이지 · 허가된 분만 들어올 수 있어요</div>
    </aside>

    <main class="side">
        <div class="box">
            <h2>관리자 로그인</h2>
            <p class="lead">로그인할 계정을 골라주세요.</p>

            <div class="seg" id="seg" data-mode="<?= $mode ?>" role="tablist" aria-label="계정 종류">
                <i class="pill"></i>
                <button type="button" role="tab" data-mode="owner" class="<?= $mode === 'owner' ? 'on' : '' ?>" aria-selected="<?= $mode === 'owner' ? 'true' : 'false' ?>">대표 관리자</button>
                <button type="button" role="tab" data-mode="sub" class="<?= $mode === 'sub' ? 'on' : '' ?>" aria-selected="<?= $mode === 'sub' ? 'true' : 'false' ?>">부관리자</button>
            </div>

            <?php if ($lockMin > 0): ?>
                <div class="msg lock" role="alert">⏳ <span><?= $h($error ?: "로그인 시도가 너무 많아요. {$lockMin}분 뒤에 다시 해주세요.") ?></span></div>
            <?php elseif ($error): ?>
                <div class="msg err" role="alert">⚠️ <span><?= $h($error) ?></span></div>
            <?php elseif ($notice): ?>
                <div class="msg ok" role="status">✓ <span><?= $h($notice) ?></span></div>
            <?php endif; ?>

            <form method="post" id="loginForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="mode" id="modeInput" value="<?= $mode ?>">

                <div class="field<?= $mode === 'owner' ? ' hide' : '' ?>" id="idField">
                    <label for="username">아이디</label>
                    <div class="inp">
                        <input type="text" id="username" name="username" value="<?= $h($username) ?>" placeholder="대표 관리자가 만들어 준 아이디" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
                    </div>
                </div>

                <div class="field">
                    <label for="password">비밀번호</label>
                    <div class="inp">
                        <input type="password" id="password" name="password" placeholder="비밀번호" autocomplete="current-password" required>
                        <svg viewBox="0 0 24 24"><rect x="4.5" y="10.5" width="15" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg>
                        <button type="button" class="eye" id="eye" aria-label="비밀번호 보기">
                            <svg viewBox="0 0 24 24" id="eyeOn"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg viewBox="0 0 24 24" id="eyeOff" style="display:none"><path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6A17 17 0 0 0 2 12s3.6 7 10 7a10 10 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                        </button>
                    </div>
                    <div class="caps" id="caps">⇪ Caps Lock이 켜져 있어요</div>
                </div>

                <button type="submit" class="go" id="go"<?= $lockMin > 0 || $preview ? ' disabled' : '' ?>><span class="sp"></span><span class="lb">로그인</span></button>
            </form>
            <p class="hint" id="hint"><?= $mode === 'owner' ? '대표 관리자는 비밀번호만 넣으면 돼요.' : '아이디·비밀번호를 잊었다면 대표 관리자에게 바꿔 달라고 요청해주세요.' ?></p>
        </div>
        <div class="side-foot"><a href="/">← 러브데이 홈으로</a></div>
    </main>
</div>

<script>
(function () {
    var seg = document.getElementById('seg'), idField = document.getElementById('idField'), user = document.getElementById('username'),
        pw = document.getElementById('password'), modeInput = document.getElementById('modeInput'), hint = document.getElementById('hint');
    var HINT = { owner: '대표 관리자는 비밀번호만 넣으면 돼요.', sub: '아이디·비밀번호를 잊었다면 대표 관리자에게 바꿔 달라고 요청해주세요.' };
    function setMode(m, focus) {
        seg.dataset.mode = m; modeInput.value = m;
        seg.querySelectorAll('button').forEach(function (b) { var on = b.dataset.mode === m; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); });
        idField.classList.toggle('hide', m === 'owner');
        user.required = m === 'sub';
        hint.textContent = HINT[m];
        try { localStorage.setItem('ld_admin_mode', m); } catch (e) {}
        if (focus) (m === 'sub' && !user.value ? user : pw).focus();
    }
    seg.addEventListener('click', function (e) { var b = e.target.closest('button[data-mode]'); if (b) setMode(b.dataset.mode, true); });
    // 지난번에 고른 탭 기억 (서버가 정한 게 없을 때만)
    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['mode'])): ?>
    try { var saved = localStorage.getItem('ld_admin_mode'); if (saved === 'sub' || saved === 'owner') setMode(saved, false); } catch (e) {}
    <?php endif; ?>
    setTimeout(function () { (seg.dataset.mode === 'sub' && !user.value ? user : pw).focus(); }, 60);

    // 비밀번호 보기
    var eye = document.getElementById('eye');
    eye.addEventListener('click', function () {
        var show = pw.type === 'password'; pw.type = show ? 'text' : 'password';
        document.getElementById('eyeOn').style.display = show ? 'none' : '';
        document.getElementById('eyeOff').style.display = show ? '' : 'none';
        eye.setAttribute('aria-label', show ? '비밀번호 숨기기' : '비밀번호 보기'); pw.focus();
    });
    // Caps Lock 안내
    var caps = document.getElementById('caps');
    ['keydown', 'keyup'].forEach(function (t) { pw.addEventListener(t, function (e) { if (e.getModifierState) caps.classList.toggle('on', e.getModifierState('CapsLock')); }); });
    pw.addEventListener('blur', function () { caps.classList.remove('on'); });

    // 보내기: 빈칸이면 흔들기, 보내는 중엔 버튼 잠금
    var form = document.getElementById('loginForm'), go = document.getElementById('go');
    form.addEventListener('submit', function (e) {
        var empty = (seg.dataset.mode === 'sub' && !user.value.trim()) ? user : (!pw.value ? pw : null);
        if (empty) { e.preventDefault(); empty.focus(); var f = empty.closest('.inp'); f.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-5px)' }, { transform: 'translateX(5px)' }, { transform: 'translateX(0)' }], { duration: 280 }); return; }
        go.classList.add('busy'); go.disabled = true;
    });
})();
</script>
</body>
</html>
