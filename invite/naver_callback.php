<?php
/**
 * naver_callback.php - 네이버 로그인을 마치고 돌아오는 곳
 * (네이버 개발자센터에 등록한 Callback URL: https://loveday.kr/invite/naver_callback.php)
 *
 * 로그인에 성공하면 고객(customers)을 찾거나 만들고 대시보드로 보낸다.
 * 이 브라우저에서 "회원가입 없이 둘러보기"로 만든 청첩장이 있으면 그 청첩장을 이 계정으로 옮긴다
 * (만들던 내용 그대로, 무료체험 4일로 전환 - guest_functions.php의 demo_claim_from_session 참고).
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php'; // functions.php + 둘러보기 청첩장 옮기기

$pdo = get_pdo();

if (isset($_GET['error'])) {
    exit('네이버 로그인이 취소되었습니다. <a href="/">처음으로</a>');
}

$state = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code'] ?? '');

if ($state === '' || !hash_equals($_SESSION['naver_oauth_state'] ?? '', $state)) {
    http_response_code(400);
    exit('로그인 요청이 올바르지 않습니다. 다시 시도해주세요. <a href="/">처음으로</a>');
}
unset($_SESSION['naver_oauth_state']);

if ($code === '') {
    exit('네이버 인증 코드가 없습니다. <a href="/">처음으로</a>');
}

$accessToken = naver_exchange_token($code, $state);
if (!$accessToken) {
    exit('네이버 인증에 실패했습니다. 다시 시도해주세요. <a href="/">처음으로</a>');
}

// 여기서 받아오는 건 response.id(서비스별 고유 식별자) 하나뿐이다.
// 이름/이메일/휴대폰 등은 애초에 스코프를 요청하지 않았으므로 넘어오지도 않는다.
$naverUid = naver_fetch_uid($accessToken);
if (!$naverUid) {
    exit('네이버 사용자 정보를 가져오지 못했습니다. 다시 시도해주세요. <a href="/">처음으로</a>');
}

$uidHash = hash_naver_uid($naverUid);

// 로그인 순간 세션 번호를 새로 발급 (세션 고정 공격 방지). 세션에 담긴 값(둘러보기 청첩장 표시 등)은 그대로 옮겨진다.
session_regenerate_id(true);
$_SESSION['naver_uid_hash'] = $uidHash; // 세션에만 보관 (원본 식별자는 어디에도 저장하지 않음)

$customer = find_or_create_customer($pdo, $uidHash);
$_SESSION['customer_id'] = (int) $customer['id'];

// 둘러보기로 만든 청첩장이 있으면 이 계정으로 옮기고, 그 청첩장을 골라 둔 채로 대시보드 열기
$r = demo_claim_from_session($pdo, (int) $customer['id']);
header('Location: ' . demo_claim_redirect($r));
exit;
