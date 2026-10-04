<?php
/**
 * admin_naver.php - 관리자: 스마트스토어 연동
 *  - 커머스API 애플리케이션 ID·시크릿 저장, 연결 테스트, 지금 주문 확인
 *  - 주문 목록: 확인 필요(코드 틀림·없음) / 교환 확인 중 / 취소로 되돌림 / 자동 처리됨 / 전체
 *  - 취소·반품은 요청 단계에서 자동으로 되돌린다 (naver_commerce.php nc_revert) - 여기서는 기록 확인과 "다시 적용"만
 *  - 확인 필요 주문은 코드를 직접 넣어 처리하거나 "처리 안 함"으로 닫는다
 * 실제 주문 확인 로직은 naver_commerce.php, 자동 실행은 naver_order_sync.php(cron)
 */
declare(strict_types=1);
require_once __DIR__ . '/naver_commerce.php';
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$notice = ''; $error = '';
$tableOk = true;
try { $pdo->query('SELECT 1 FROM naver_orders LIMIT 1'); } catch (Throwable $e) { $tableOk = false; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            $cid = trim((string) ($_POST['client_id'] ?? ''));
            $secret = trim((string) ($_POST['client_secret'] ?? ''));
            $pids = implode(',', array_filter(array_map(fn($x) => preg_replace('/\D/', '', $x), explode(',', (string) ($_POST['product_ids'] ?? '')))));
            if ($cid !== '' && !preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $cid)) throw new RuntimeException('애플리케이션 ID 형식을 확인해주세요.');
            if ($secret !== '' && !preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{22}/', $secret)) throw new RuntimeException('애플리케이션 시크릿은 $2a$로 시작하는 값을 그대로 붙여넣어 주세요.');
            if ($cid !== app_setting('naver_client_id')) { save_app_setting($pdo, 'naver_token_exp', '0'); }
            save_app_setting($pdo, 'naver_client_id', $cid);
            if ($secret !== '') { save_app_setting($pdo, 'naver_client_secret_enc', encrypt_data($secret)); save_app_setting($pdo, 'naver_token_exp', '0'); }
            if (!empty($_POST['clear_secret'])) save_app_setting($pdo, 'naver_client_secret_enc', '');
            save_app_setting($pdo, 'naver_product_ids', $pids);
            save_app_setting($pdo, 'naver_enabled', !empty($_POST['enabled']) ? '1' : '0');
            save_app_setting($pdo, 'naver_auto_confirm', !empty($_POST['auto_confirm']) ? '1' : '0');
            save_app_setting($pdo, 'naver_auto_dispatch', in_array($_POST['auto_dispatch'] ?? '', ['DIRECT_DELIVERY', 'NOTHING'], true) ? $_POST['auto_dispatch'] : '');
            header('Location: admin_naver.php?msg=saved'); exit;
        }
        if ($action === 'test') {
            nc_token($pdo, true);
            header('Location: admin_naver.php?msg=test_ok'); exit;
        }
        if ($action === 'sync') {
            if (!$tableOk) throw new RuntimeException('먼저 서버에서 stage4_setup.sql을 실행해주세요.');
            $days = (int) ($_POST['days'] ?? 0);
            $r = nc_sync($pdo, $days > 0 ? nc_now()->modify("-{$days} days") : null);
            header('Location: admin_naver.php?msg=sync&c=' . $r['checked'] . '&a=' . $r['applied'] . '&r=' . $r['review'] . '&x=' . $r['reverted']); exit;
        }
        $poid = (string) ($_POST['poid'] ?? '');
        $st = $pdo->prepare('SELECT * FROM naver_orders WHERE product_order_id = ?'); $st->execute([$poid]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('주문을 찾지 못했어요.');
        if ($action === 'manual') {
            [$ok, $m] = nc_manual_apply($pdo, $poid, (string) ($_POST['code'] ?? ''), (string) ($_POST['plan'] ?? ''));
            if (!$ok) throw new RuntimeException($m);
            header('Location: admin_naver.php?tab=applied&msg=manual'); exit;
        }
        if ($action === 'reapply') { // 취소로 되돌린 주문을 관리자가 다시 결제 상태로
            [$ok, $m] = nc_manual_apply($pdo, $poid, (string) ($row['code'] ?? ''), (string) ($row['plan'] ?? ''));
            if (!$ok) throw new RuntimeException($m);
            header('Location: admin_naver.php?tab=applied&msg=manual'); exit;
        }
        if ($action === 'decide') { // 구매확정 전이지만 파일 다운로드 조건을 채운 것으로 본다
            $pdo->prepare('UPDATE naver_orders SET decided_at = NOW(), message = CONCAT(COALESCE(message, \'\'), \' · 관리자가 구매확정으로 처리\'), updated_at = NOW() WHERE product_order_id = ?')->execute([$poid]);
            header('Location: admin_naver.php?tab=applied&msg=done'); exit;
        }
        if ($action === 'ignore') {
            $pdo->prepare("UPDATE naver_orders SET result='ignored', message=CONCAT('관리자가 처리 안 함으로 닫음 · ', COALESCE(message,'')), updated_at=NOW() WHERE product_order_id=?")->execute([$poid]);
            header('Location: admin_naver.php?tab=review&msg=done'); exit;
        }
        if ($action === 'revert' && $row['invitation_id']) {
            // admin_edit.php의 "체험으로 되돌리기"와 같은 동작
            $pdo->prepare("UPDATE invitation_orders SET storage_plan='trial', paid_at=NULL, expires_at=? WHERE id=?")->execute([date('Y-m-d H:i:s', strtotime('+4 days')), $row['invitation_id']]);
            reprocess_invitation_photos($pdo, (int) $row['invitation_id'], true);
            $pdo->prepare("UPDATE naver_orders SET result='closed', message='취소·환불로 체험으로 되돌림', updated_at=NOW() WHERE product_order_id=?")->execute([$poid]);
            header('Location: admin_naver.php?tab=canceled&msg=done'); exit;
        }
        if ($action === 'keep') {
            $pdo->prepare("UPDATE naver_orders SET result='closed', message='취소·환불됐지만 결제 상태 그대로 둠', updated_at=NOW() WHERE product_order_id=?")->execute([$poid]);
            header('Location: admin_naver.php?tab=canceled&msg=done'); exit;
        }
        throw new RuntimeException('잘못된 요청입니다.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$msgs = [
    'saved' => '저장했어요.', 'test_ok' => '연결 성공! 네이버에서 인증 토큰을 받았어요.', 'manual' => '처리했어요. 워터마크도 지웠어요.', 'done' => '처리했어요.',
    'sync' => '주문 확인을 마쳤어요. 확인 ' . (int) ($_GET['c'] ?? 0) . '건 · 자동 처리 ' . (int) ($_GET['a'] ?? 0) . '건 · 확인 필요 ' . (int) ($_GET['r'] ?? 0) . '건 · 취소로 되돌림 ' . (int) ($_GET['x'] ?? 0) . '건',
];
if (!$error && isset($msgs[$_GET['msg'] ?? ''])) $notice = $msgs[$_GET['msg']];

$tabs = ['review' => '확인 필요', 'exchange_hold' => '교환 확인 중', 'reverted' => '취소로 되돌림', 'applied' => '자동 처리됨', 'all' => '전체'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'review';
$counts = ['review' => 0, 'exchange_hold' => 0, 'reverted' => 0, 'applied' => 0, 'all' => 0];
$rows = [];
if ($tableOk) {
    foreach ($pdo->query('SELECT result, COUNT(*) c FROM naver_orders GROUP BY result') as $r) {
        $k = $r['result'] === 'canceled' ? 'review' : $r['result']; // 예전 방식의 "처리 후 취소"는 확인 필요에 함께
        if (isset($counts[$k])) $counts[$k] += (int) $r['c'];
        $counts['all'] += (int) $r['c'];
    }
    $sql = 'SELECT n.*, i.view_slug AS inv_slug, i.storage_plan AS inv_plan FROM naver_orders n LEFT JOIN invitation_orders i ON i.id = n.invitation_id';
    $sql .= $tab === 'all' ? '' : ($tab === 'review' ? " WHERE n.result IN ('review','canceled')" : ' WHERE n.result = ' . $pdo->quote($tab));
    $sql .= ' ORDER BY COALESCE(n.paid_at, n.created_at) DESC LIMIT 200';
    $rows = $pdo->query($sql)->fetchAll();
}
$enabled = app_setting('naver_enabled') === '1';
$configured = nc_configured();
$lastSync = app_setting('naver_last_sync');
$lastErr = app_setting('naver_last_error');
$cronLine = '*/2 * * * * php ' . __DIR__ . '/naver_order_sync.php >> /var/log/loveday_naver.log 2>&1';
$stale = $enabled && $lastSync !== '' && strtotime($lastSync) < time() - 15 * 60;
$csrf = csrf_token();
$resultLabel = ['applied' => ['처리됨', '#3E8A68'], 'review' => ['확인 필요', '#C7823A'], 'reverted' => ['취소로 되돌림', '#B24A4A'], 'exchange_hold' => ['교환 확인 중', '#6A5ACD'],
                'canceled' => ['처리 후 취소', '#B24A4A'], 'closed' => ['닫힘', '#8A8A8A'], 'ignored' => ['처리 안 함', '#8A8A8A'], 'waiting' => ['결제 대기', '#8A8A8A']];
$dispatch = app_setting('naver_auto_dispatch');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>스마트스토어 연동 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .nv-status { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 18px; }
    .nv-status > div { background: #fff; border: 1px solid #e6e2da; border-radius: 12px; padding: 12px 14px; }
    .nv-status small { display: block; font-size: 12px; color: #888; margin-bottom: 4px; }
    .nv-status b { font-size: 15px; }
    .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: 1px; }
    .set-row { display: grid; grid-template-columns: 200px 1fr; gap: 14px; align-items: start; padding: 11px 0; border-bottom: 1px solid #eee; }
    .set-row label.l { font-size: 14px; padding-top: 8px; }
    .set-row input[type=text], .set-row input[type=password] { width: 100%; padding: 9px 10px; box-sizing: border-box; }
    .help { font-size: 12px; color: #888; margin-top: 5px; line-height: 1.6; }
    .cron { display: block; background: #1f1e1c; color: #f2efe9; border-radius: 8px; padding: 10px 12px; font: 12px ui-monospace, Menlo, monospace; overflow-x: auto; white-space: nowrap; margin-top: 6px; }
    .guide ol { margin: 6px 0 0; padding-left: 20px; font-size: 13.5px; line-height: 1.9; }
    .tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 6px 0 12px; }
    .tabs a { padding: 7px 13px; border-radius: 999px; border: 1px solid #ddd; font-size: 13px; text-decoration: none; color: #555; background: #fff; }
    .tabs a.on { background: #2b2320; color: #fff; border-color: #2b2320; }
    .tabs a i { font-style: normal; font-weight: 700; margin-left: 4px; }
    table.nv { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }
    table.nv th, table.nv td { border-bottom: 1px solid #eee; padding: 9px 8px; text-align: left; vertical-align: top; }
    table.nv th { font-size: 12px; color: #888; font-weight: 600; background: #faf9f7; }
    .opt { color: #555; font-size: 12.5px; word-break: break-all; }
    .res { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; color: #fff; white-space: nowrap; }
    .rowact { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 6px; }
    .rowact input { width: 120px; padding: 6px 8px; }
    .rowact select { padding: 6px; }
    .mini { font-size: 12.5px; padding: 6px 10px; }
    @media (max-width: 760px) { .set-row { grid-template-columns: 1fr; gap: 4px; } table.nv thead { display: none; } table.nv td { display: block; border: 0; padding: 4px 8px; } table.nv tr { display: block; border-bottom: 1px solid #eee; padding: 8px 0; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('naver', '스마트스토어 연동'); ?>
    <div class="wrap">
        <h2 class="page-title">스마트스토어 연동</h2>
        <p style="font-size:13px;color:#888;margin:-14px 0 18px;">스마트스토어에서 결제가 끝나면, 구매자가 적은 청첩장 코드를 찾아 자동으로 보관 기간을 바꾸고 워터마크를 지워요.</p>
        <?php if ($notice): ?><div class="notice success"><?= $h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= $h($error) ?></div><?php endif; ?>
        <?php if (!$tableOk): ?><div class="notice error">주문 기록 표가 아직 없어요. 서버에서 <b>stage4_setup.sql</b>을 한 번 더 실행해주세요.</div><?php endif; ?>

        <div class="nv-status">
            <div><small>연결 정보</small><b><span class="dot" style="background:<?= $configured ? '#3E8A68' : '#bbb' ?>"></span><?= $configured ? '입력됨' : '입력 전' ?></b></div>
            <div><small>자동 확인</small><b><span class="dot" style="background:<?= $enabled ? '#3E8A68' : '#bbb' ?>"></span><?= $enabled ? '켜짐 (2분마다)' : '꺼짐' ?></b></div>
            <div><small>마지막 확인</small><b><?= $lastSync !== '' ? $h(date('m-d H:i', strtotime($lastSync))) : '아직 없음' ?></b><?php if ($stale): ?><div class="help" style="color:#B24A4A">15분 넘게 확인이 없어요. cron이 도는지 봐주세요.</div><?php endif; ?></div>
            <div><small>확인 필요</small><b style="color:<?= $counts['review'] ? '#C7823A' : 'inherit' ?>"><?= $counts['review'] ?>건</b><?php if ($counts['exchange_hold']): ?> <b style="color:#6A5ACD;font-size:13px;margin-left:6px">교환 <?= $counts['exchange_hold'] ?>건</b><?php endif; ?></div>
        </div>
        <?php if ($lastErr !== ''): ?><div class="notice error">최근 오류: <?= $h($lastErr) ?></div><?php endif; ?>

        <form method="post" class="panel">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="save">
            <div class="set-row"><label class="l">자동 확인</label>
                <div><label><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> 켜기 - 2분마다 새 결제를 확인해서 자동 처리</label>
                <div class="help">서버에 아래 cron 한 줄이 등록돼 있어야 실제로 돌아가요.<code class="cron"><?= $h($cronLine) ?></code></div></div></div>
            <div class="set-row"><label class="l" for="client_id">애플리케이션 ID</label>
                <div><input type="text" id="client_id" name="client_id" value="<?= $h(app_setting('naver_client_id')) ?>" autocomplete="off">
                <div class="help">커머스API센터 → 애플리케이션 → 내 스토어 애플리케이션에서 복사</div></div></div>
            <div class="set-row"><label class="l" for="client_secret">애플리케이션 시크릿</label>
                <div><input type="password" id="client_secret" name="client_secret" value="" placeholder="<?= $configured ? '저장됨 - 바꿀 때만 입력' : '$2a$04$…' ?>" autocomplete="new-password">
                <div class="help">암호화해서 저장해요. 화면에 다시 보여주지 않아요.<?php if ($configured): ?> <label style="margin-left:8px"><input type="checkbox" name="clear_secret" value="1"> 저장된 시크릿 지우기</label><?php endif; ?></div></div></div>
            <div class="set-row"><label class="l" for="product_ids">청첩장 상품번호</label>
                <div><input type="text" id="product_ids" name="product_ids" value="<?= $h(app_setting('naver_product_ids')) ?>" placeholder="예: 1234567890, 2345678901">
                <div class="help">이 상품의 주문만 처리해요. 비우면 스토어의 모든 주문을 봐요. (스마트스토어센터 상품 목록의 상품번호, 쉼표로 여러 개)</div></div></div>
            <div class="set-row"><label class="l">발주확인</label>
                <div><label><input type="checkbox" name="auto_confirm" value="1" <?= app_setting('naver_auto_confirm') === '1' ? 'checked' : '' ?>> 자동 처리된 주문은 발주확인까지 자동으로</label>
                <div class="help">코드를 못 찾은 주문은 발주확인하지 않아요.</div></div></div>
            <div class="set-row"><label class="l" for="auto_dispatch">발송처리</label>
                <div><select id="auto_dispatch" name="auto_dispatch" style="padding:8px">
                    <option value="" <?= $dispatch === '' ? 'selected' : '' ?>>하지 않음 (스마트스토어센터에서 직접)</option>
                    <option value="DIRECT_DELIVERY" <?= $dispatch === 'DIRECT_DELIVERY' ? 'selected' : '' ?>>발주확인 뒤 자동 발송처리 - 직접전달</option>
                    <option value="NOTHING" <?= $dispatch === 'NOTHING' ? 'selected' : '' ?>>발주확인 뒤 자동 발송처리 - 배송 없음</option>
                </select>
                <div class="help">청첩장은 배송이 없는 상품이라, 발송처리가 돼야 구매자가 구매확정을 할 수 있어요 (구매확정 = 파일 다운로드 조건).
                    상품의 배송 방법과 같은 것을 골라주세요. 발송처리 뒤 취소는 "반품 요청"으로 들어오고, 이것도 요청 즉시 되돌려요.</div></div></div>
            <p style="margin-top:16px;"><button class="btn" type="submit">저장</button></p>
        </form>

        <div class="panel" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <form method="post" style="margin:0"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="test">
                <button class="btn" type="submit" <?= $configured ? '' : 'disabled' ?>>연결 테스트</button></form>
            <form method="post" style="margin:0;display:flex;gap:6px;align-items:center"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="action" value="sync">
                <select name="days"><option value="0">지난 확인 이후</option><option value="1">최근 1일 다시</option><option value="3">최근 3일 다시</option><option value="7">최근 7일 다시</option></select>
                <button class="btn" type="submit" <?= $configured && $tableOk ? '' : 'disabled' ?>>지금 주문 확인</button></form>
            <span class="help" style="margin:0">이미 처리한 주문은 다시 확인해도 두 번 처리되지 않아요.</span>
        </div>

        <details class="panel guide">
            <summary style="cursor:pointer;font-weight:600">처음 연결하는 방법</summary>
            <ol>
                <li><b>커머스API센터</b>(apicenter.commerce.naver.com)에 스마트스토어 판매자 계정으로 로그인 → <b>애플리케이션 등록</b> → "내 스토어 애플리케이션"</li>
                <li>API 권한에서 <b>주문(판매자 주문 조회·발주확인)</b>을 선택하고, <b>API 호출 IP</b>에 이 서버의 공인 IP를 등록</li>
                <li>발급된 <b>애플리케이션 ID·시크릿</b>을 위에 붙여넣고 저장 → <b>연결 테스트</b></li>
                <li>스마트스토어 청첩장 상품에 옵션 2개 만들기
                    <br>· 선택형 옵션 <b>"보관 기간"</b>: <b>1년 보관</b> / <b>영구 보관</b> (이름에 "1년", "영구"가 들어가면 돼요)
                    <br>· 직접 입력형 옵션 <b>"청첩장 코드"</b>: 안내 문구 예) "내 청첩장 화면의 코드(loveday.kr/ 뒤 영문·숫자)를 적어주세요"</li>
                <li>서버에 위 <b>cron 한 줄</b>을 등록하고 "자동 확인"을 켜기</li>
                <li>테스트 주문 1건으로 확인 → 아래 "자동 처리됨"에 뜨면 완료</li>
            </ol>
            <p style="margin:14px 0 6px;font-weight:600">상품 상세 · 취소 안내에 넣을 문구 (복사해서 쓰세요)</p>
            <textarea readonly rows="7" style="width:100%;box-sizing:border-box;font-size:13px;line-height:1.6;padding:10px" onclick="this.select()">[결제·취소 안내]
· 결제가 확인되면 1~2분 안에 청첩장의 워터마크가 사라지고 보관 기간이 적용돼요.
· 주문 취소(또는 반품)를 요청하는 즉시 청첩장은 결제 전 상태(무료체험)로 돌아가요.
  이때 1회에 한해 6시간 더 편집·보관할 수 있어요. 그 뒤에는 무료체험 기간이 끝나면 자동으로 삭제돼요.
· 교환을 요청하면, 결제 전에도 유료였던 청첩장은 교환이 끝날 때까지 그대로 두고, 무료체험이었던 청첩장은 취소와 같이 바로 돌아가요.
· 영구 보관의 "파일로 다운로드"는 구매확정 뒤, 예식 전날부터 받을 수 있어요.</textarea>
        </details>

        <?php if ($tableOk): ?>
        <div class="tabs">
            <?php foreach ($tabs as $k => $label): ?><a href="?tab=<?= $k ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $h($label) ?><i><?= $counts[$k] ?></i></a><?php endforeach; ?>
        </div>
        <div class="panel" style="padding:0;overflow:hidden">
            <table class="nv">
                <thead><tr><th style="width:92px">결제일</th><th>주문</th><th style="width:88px">금액</th><th style="width:120px">청첩장</th><th style="width:34%">결과</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;color:#999;padding:28px"><?= $tab === 'review' ? '확인할 주문이 없어요.' : '주문이 없어요.' ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $r): [$rl, $rc] = $resultLabel[$r['result']] ?? [$r['result'], '#888']; ?>
                    <tr>
                        <td><?= $r['paid_at'] ? $h(date('m-d H:i', strtotime($r['paid_at']))) : '-' ?></td>
                        <td><b><?= $h($r['orderer'] ?: '-') ?></b> <span style="color:#999;font-size:12px"><?= $h($r['product_order_id']) ?> · <?= $h($r['order_status']) ?><?= !empty($r['claim']) ? ' · ' . $h($r['claim']) : '' ?><?= !empty($r['decided_at']) ? ' · 구매확정' : '' ?></span>
                            <div class="opt"><?= $h($r['product_name']) ?><?= $r['product_option'] ? ' · ' . $h($r['product_option']) : '' ?></div></td>
                        <td><?= $r['amount'] ? number_format((int) $r['amount']) . '원' : '-' ?></td>
                        <td><?php if ($r['invitation_id']): ?><a href="admin_edit.php?id=<?= (int) $r['invitation_id'] ?>"><?= $h($r['inv_slug'] ?: $r['code']) ?></a><div style="font-size:12px;color:#888"><?= $h(['trial' => '체험', 'one_year' => '1년', 'permanent' => '영구'][$r['inv_plan']] ?? '') ?></div><?php else: ?>-<?php endif; ?></td>
                        <td><span class="res" style="background:<?= $rc ?>"><?= $h($rl) ?></span> <span style="font-size:12.5px"><?= $h($r['message']) ?></span>
                            <?php if ($r['result'] === 'review'): ?>
                            <form method="post" class="rowact"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="poid" value="<?= $h($r['product_order_id']) ?>"><input type="hidden" name="action" value="manual">
                                <input name="code" placeholder="청첩장 코드" value="<?= $h($r['code'] ?? '') ?>" required>
                                <select name="plan"><option value="one_year" <?= $r['plan'] === 'one_year' ? 'selected' : '' ?>>1년 보관</option><option value="permanent" <?= $r['plan'] === 'permanent' ? 'selected' : '' ?>>영구 보관</option></select>
                                <button class="btn mini" type="submit">처리</button></form>
                            <form method="post" class="rowact" data-confirm="이 주문을 처리하지 않고 닫을까요?" data-confirm-ok="닫기"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="poid" value="<?= $h($r['product_order_id']) ?>"><input type="hidden" name="action" value="ignore">
                                <button class="btn mini" type="submit" style="background:#f1eeea;color:#555">처리 안 함</button></form>
                            <?php elseif ($r['result'] === 'reverted' && $r['code'] && $r['plan']): ?>
                            <form method="post" class="rowact" data-confirm="이 주문을 다시 결제 상태로 적용할까요?&#10;취소가 잘못 처리됐을 때만 써주세요." data-confirm-ok="다시 적용"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="poid" value="<?= $h($r['product_order_id']) ?>"><input type="hidden" name="action" value="reapply">
                                <button class="btn mini" type="submit">다시 적용</button></form>
                            <?php elseif ($r['result'] === 'applied' && $r['plan'] === 'permanent' && empty($r['decided_at'])): ?>
                            <form method="post" class="rowact" data-confirm="구매확정 전이지만 파일 다운로드를 열까요?&#10;다운로드는 예식 전날부터 가능한 조건은 그대로예요." data-confirm-ok="구매확정으로 보기"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="poid" value="<?= $h($r['product_order_id']) ?>"><input type="hidden" name="action" value="decide">
                                <button class="btn mini" type="submit" style="background:#f1eeea;color:#555">구매확정으로 보기</button></form>
                            <?php elseif ($r['result'] === 'canceled'): ?>
                            <form method="post" class="rowact" data-confirm="체험으로 되돌릴까요?&#10;사진에 워터마크가 다시 들어가고 4일 뒤 자동 삭제 대상이 돼요." data-confirm-danger data-confirm-ok="되돌리기"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="poid" value="<?= $h($r['product_order_id']) ?>"><input type="hidden" name="action" value="revert">
                                <button class="btn mini btn-danger" type="submit">체험으로 되돌리기</button></form>
                            <form method="post" class="rowact"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="poid" value="<?= $h($r['product_order_id']) ?>"><input type="hidden" name="action" value="keep">
                                <button class="btn mini" type="submit" style="background:#f1eeea;color:#555">그대로 두기</button></form>
                            <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
