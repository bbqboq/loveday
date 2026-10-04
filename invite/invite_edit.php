<?php
/**
 * invite_edit.php - 예전 "청첩장 관리" 주소.
 * 이제 관리 화면은 dashboard.php 하나로 합쳐졌다. 예전에 나간 편집 링크(invite_edit.php?t=…)가
 * 계속 열리도록 같은 토큰을 붙여 dashboard.php로 넘긴다. (PIN 확인도 dashboard.php에서 한다)
 */
declare(strict_types=1);
$token = (string) ($_GET['t'] ?? $_POST['edit_token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit('잘못된 접근입니다.');
}
header('Location: dashboard.php?t=' . $token, true, 302);
exit;
