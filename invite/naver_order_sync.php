<?php
// naver_order_sync.php - 스마트스토어 주문 자동 확인 (서버 cron 전용, 브라우저로는 열리지 않음)
//
// crontab -e 에 한 줄 추가 (2분마다):
//   */2 * * * * php /var/www/loveday/www/invite/naver_order_sync.php >> /var/log/loveday_naver.log 2>&1
//
// 관리자 → 스마트스토어 연동에서 "자동 확인 켜기"를 켜 둔 경우에만 실제로 네이버에 물어본다.
// 같은 시각에 두 번 겹쳐 돌지 않도록 잠금 파일을 쓴다.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/naver_commerce.php';

$lock = fopen(sys_get_temp_dir() . '/loveday_naver_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // 이전 실행이 아직 도는 중

if (app_setting('naver_enabled') !== '1' || !nc_configured()) exit(0);
try {
    $r = nc_sync(get_pdo());
    if ($r['checked']) echo date('Y-m-d H:i:s') . " 확인 {$r['checked']} · 처리 {$r['applied']} · 확인필요 {$r['review']} · 취소로 되돌림 {$r['reverted']}\n";
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' 오류: ' . $e->getMessage() . "\n";
    exit(1);
}
