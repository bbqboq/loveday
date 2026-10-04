<?php
/**
 * claim_demo.php - 둘러보기 청첩장을 내 계정에 저장하는 버튼
 *  (에디터 아래 체험 안내 띠의 [네이버로 저장하기], 보관 시간이 끝났을 때 뜨는 팝업의 [네이버로 가입하고 이어서 만들기])
 *
 *  - 로그인 전이면 → 네이버 로그인. 돌아오면 naver_callback.php가 이 브라우저의 둘러보기 청첩장을 계정으로 옮긴다
 *  - 이미 로그인했으면 → 바로 옮기고 대시보드(옮긴 청첩장 선택된 채로)
 *  - 보관 시간이 지났어도 아직 지워지기 전이면 옮길 수 있다
 *
 * 옮길 수 있는 건 "이 브라우저가 만든" 둘러보기 청첩장뿐이다(세션의 demo_token).
 * 주소에 붙은 t는 버튼을 누른 청첩장이 그것과 같은지 확인하는 데만 쓴다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

function claim_page(string $title, string $msg): void
{
    http_response_code(400);
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title><?= $h($title) ?></title><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--ui-page, #F6F4F1);font-family:"Pretendard Variable",Pretendard,-apple-system,sans-serif;color:#1B1A18;padding:20px;box-sizing:border-box}
    .b{background:#fff;border:1px solid var(--ui-line, #EAE6E0);border-radius:20px;padding:32px 26px;max-width:380px;text-align:center;line-height:1.7;word-break:keep-all}h3{margin:0 0 8px;font-size:18px}p{margin:0 0 20px;color:#6F6A63;font-size:14px}
    a{display:inline-block;padding:13px 22px;border-radius:12px;background:#1B1A18;color:#fff;text-decoration:none;font-weight:600;font-size:14.5px}</style></head>
    <body><div class="b"><h3><?= $h($title) ?></h3><p><?= $msg ?></p><a href="/">처음으로</a></div></body></html><?php
    exit;
}

$t = (string) ($_GET['t'] ?? '');
$mine = (string) ($_SESSION['demo_token'] ?? '');

// 이 브라우저에서 만든 둘러보기 청첩장이 아니면(다른 기기, 이미 옮김, 세션 만료) 안내만
if ($mine === '' || ($t !== '' && !hash_equals($mine, $t))) {
    if (!empty($_SESSION['customer_id'])) { header('Location: dashboard.php'); exit; }
    claim_page('이 청첩장은 옮길 수 없어요', '둘러보기 청첩장은 처음 만든 브라우저에서만 내 계정으로 옮길 수 있어요.<br>이미 옮겼을 수도 있어요.');
}
// 이미 지워졌으면 로그인시키기 전에 알려준다
if (!demo_exists(get_pdo(), $mine)) {
    unset($_SESSION['demo_token']);
    if (!empty($_SESSION['customer_id'])) { header('Location: dashboard.php?demo_gone=1'); exit; }
    claim_page('이미 삭제된 청첩장이에요', '둘러보기 청첩장은 보관 시간이 지나 삭제되어 옮길 수 없어요.<br>네이버로 시작해서 새로 만들어 주세요.');
}

// 로그인 전 → 네이버 로그인 (돌아오면 naver_callback.php가 옮김)
if (empty($_SESSION['customer_id'])) {
    header('Location: naver_login.php');
    exit;
}

// 로그인한 상태 → 바로 옮기기
$r = demo_claim_from_session(get_pdo(), (int) $_SESSION['customer_id']);
header('Location: ' . demo_claim_redirect($r, true));
exit;
