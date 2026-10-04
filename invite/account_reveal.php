<?php
/**
 * account_reveal.php - 닫힘 (예전 계좌번호 전달 주소)
 *
 * 예전엔 청첩장 코드만 알면 누구나(봇 포함) 이 주소로 계좌번호를 받아갈 수 있었다.
 * 이제 계좌번호·연락처는 guest_secure.php가 페이지 입장권·요청 횟수 제한 등을 확인한 뒤에만 건넨다.
 * 서버에 남아 있는 예전 파일을 이 파일로 덮어써서 닫는다. (지워도 된다)
 */
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
echo json_encode(['ok' => false, 'error' => '더 이상 쓰지 않는 주소예요.'], JSON_UNESCAPED_UNICODE);
