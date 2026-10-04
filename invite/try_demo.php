<?php
/**
 * try_demo.php - "회원가입 없이 테스트 해보기" 버튼
 *
 * 로그인 화면의 버튼이 이 주소로 온다:  <a href="try_demo.php">회원가입 없이 테스트 해보기</a>
 *
 *  - 누를 때마다 이 사람만 쓰는 체험용 청첩장을 하나 만들어서 바로 에디터(디자인 고르기 화면)로 보낸다.
 *    (여러 사람이 한 청첩장을 같이 고치는 공용 테스트베드와 달리, 서로의 작업이 섞이지 않는다)
 *  - 같은 브라우저로 다시 누르면 새로 만들지 않고, 만들어 둔 체험 청첩장으로 다시 들어간다.
 *  - 무료체험과 같이 사진에 워터마크가 들어가고, demo_hours(관리자 설정, 기본 24시간) 뒤에 통째로 자동 삭제된다.
 *  - 남용 방지: IP당 1시간에 5개, 동시에 존재하는 체험 청첩장은 demo_max_active(기본 300)개까지.
 *  - 마음에 들면 에디터 아래 [네이버로 저장하기](claim_demo.php)나 네이버 로그인으로, 만들던 그대로 내 계정에 옮겨진다
 *    (같은 브라우저에서만 - guest_functions.php의 demo_claim_from_session).
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

$pdo = get_pdo();
$editor = fn(string $t) => 'editor-prototype-v3-overlay.html?t=' . $t . '&demo=' . max(1, app_setting_int('demo_hours')); // demo=보관 시간 → 에디터 아래 안내 띠에 표시

function demo_page(string $title, string $msg, int $code = 200): void
{
    http_response_code($code);
    ?><!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= snap_h($title) ?></title><meta name="robots" content="noindex"><link rel="stylesheet" href="assets/invite.css"></head>
    <body><div class="wrap" style="text-align:center;padding-top:60px;"><h3><?= snap_h($title) ?></h3>
    <p style="color:var(--muted);line-height:1.8;"><?= $msg ?></p><p><a class="btn" href="javascript:history.back()">← 돌아가기</a></p></div></body></html><?php
    exit;
}

try {
    // 1) 이 브라우저가 전에 만든 체험 청첩장이 아직 살아 있으면 그대로 다시 들어간다
    $prev = (string) ($_SESSION['demo_token'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $prev)) {
        $inv = find_invitation_by_token($pdo, $prev);
        if ($inv && empty($inv['deleted_at']) && $inv['expires_at'] && strtotime((string) $inv['expires_at']) > time()) {
            header('Location: ' . $editor($prev));
            exit;
        }
    }

    // 2) 만료된 체험 청첩장 몇 건씩 정리 (크론이 하루 한 번 돌기 전에도 쌓이지 않게)
    demo_cleanup($pdo, 5);

    // 3) 남용 방지
    if (!check_rate_limit($pdo, 'demo_' . client_ip(), 5, 3600)) {
        demo_page('잠시 후 다시 시도해주세요', '짧은 시간에 체험 청첩장을 너무 많이 만들었어요.<br>1시간 뒤에 다시 시도하거나 네이버로 시작해 주세요.', 429);
    }
    if (demo_active_count($pdo) >= app_setting_int('demo_max_active')) {
        demo_page('체험하는 분이 많아요', '지금 체험하는 분이 많아 잠시 뒤에 다시 시도해 주세요.<br>네이버로 시작하시면 바로 만들 수 있어요.', 503);
    }

    // 4) 체험 청첩장 만들기 (무료체험과 같은 조건 + 짧은 보관 시간)
    $hours = max(1, min(168, app_setting_int('demo_hours')));
    $token = generate_edit_token();
    $slug  = generate_slug($pdo, '', '');
    $pdo->prepare("INSERT INTO invitation_orders
            (order_platform, order_memo, edit_token, view_slug, groom_name, bride_name, status, storage_plan, expires_at)
        VALUES ('self_signup', ?, ?, ?, '', '', 'editing', 'trial', DATE_ADD(NOW(), INTERVAL {$hours} HOUR))")
        ->execute([DEMO_MEMO . ' (' . $hours . '시간 뒤 자동 삭제)', $token, $slug]);
    $_SESSION['demo_token'] = $token;
    header('Location: ' . $editor($token));
    exit;
} catch (Throwable $e) {
    error_log('[try_demo] ' . $e->getMessage());
    demo_page('지금은 체험을 시작할 수 없어요', '잠시 후 다시 시도해 주세요.', 500);
}
