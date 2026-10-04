<?php
declare(strict_types=1);

/**
 * 계좌정보 암호화/복호화 + DB에 실제로 뭐가 들어있는지 진단하는 스크립트.
 * invite_save.php / invite_load.php 가 있는 폴더에 이 파일을 같이 올려두고
 * SSH에서 아래처럼 실행하면 됩니다:
 *
 *   php debug_account_crypto.php 23
 *
 * (23 자리에 확인하고 싶은 invitation_orders.id 값)
 * 확인 끝나면 서버에서 이 파일은 지워주세요 (config.php를 require하기 때문에
 * 웹에서 직접 열 수 있는 곳에 두면 안 됩니다 - 반드시 CLI로만 실행).
 */

if (php_sapi_name() !== 'cli') {
    exit("이 스크립트는 웹이 아니라 SSH에서 'php debug_account_crypto.php 23' 처럼 CLI로만 실행해주세요.\n");
}

require_once __DIR__ . '/functions.php'; // config.php는 여기서 이미 require됨

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    exit("사용법: php debug_account_crypto.php <invitation_orders.id>\n");
}

echo "=== 1) 암호화 함수 자체 왕복 테스트 (DB랑 무관) ===\n";
$sample = json_encode(['groomBank' => '국민 123-456-789 (테스트)'], JSON_UNESCAPED_UNICODE);
$enc = encrypt_data($sample);
echo "원문:        $sample\n";
echo "암호화 길이:  " . strlen($enc) . " 자\n";
$dec = decrypt_data($enc);
echo "복호화 결과: " . var_export($dec, true) . "\n";
echo ($dec === $sample) ? "결과: 일치함 (암호화 함수 자체는 정상)\n\n" : "결과: !!! 불일치 - 암호화 함수 자체에 문제 있음 !!!\n\n";

echo "=== 2) DB에 실제로 저장된 값 확인 (id=$id) ===\n";
$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, account_info_enc, design_json FROM invitation_orders WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) {
    exit("id=$id 인 행을 찾을 수 없습니다.\n");
}

if (!$row['account_info_enc']) {
    echo "account_info_enc: 비어있음(NULL) - 아직 한 번도 저장 안 됐거나 방금 초기화한 상태입니다.\n";
} else {
    echo "account_info_enc 길이(base64): " . strlen($row['account_info_enc']) . " 자\n";
    echo "account_info_enc 앞부분: " . substr($row['account_info_enc'], 0, 60) . "...\n";
    $plain = decrypt_data($row['account_info_enc']);
    echo "복호화 결과(원문): " . var_export($plain, true) . "\n";
    $arr = json_decode($plain, true);
    if (is_array($arr)) {
        echo "json_decode 성공. 내용:\n";
        print_r($arr);
    } else {
        echo "!!! json_decode 실패 (json_last_error: " . json_last_error_msg() . ") - 복호화 자체가 깨진 값을 내놓고 있다는 뜻입니다.\n";
    }
}

echo "\n=== 3) design_json 안의 account 블록 (마스킹된 값) ===\n";
$design = json_decode($row['design_json'] ?? 'null', true);
foreach ($design['blocks'] ?? [] as $block) {
    if (($block['id'] ?? '') === 'account') {
        print_r($block['fields']);
    }
}
