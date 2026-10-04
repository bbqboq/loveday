<?php
/**
 * admin_edit.php - 관리자: 청첩장 하나 수정
 *  - 링크·코드, 연결된 고객코드, 결제/보관 기간, 자동 삭제까지 남은 시간(직접 조정), 이름·연락처·메모·상태, 사진 삭제
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php'; // functions.php + DEMO_MEMO(둘러보기 표시) + app_setting
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM invitation_orders WHERE id = ?');
$stmt->execute([$id]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    exit('존재하지 않는 청첩장입니다.');
}

$saved = false;
$error = '';
$notice = '';
$isAjax = isset($_POST['ajax']); // 남은 시간 버튼은 새로고침 없이 (화면이 위로 튀지 않게)

/** 삭제 예정 시각을 DB가 직접 초로 바꿔서 준다 - PHP와 DB 시간대가 달라도 항상 맞게 (서버 시간 점검 전에도) */
function adm_exp_info(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT UNIX_TIMESTAMP(expires_at) AS exp, UNIX_TIMESTAMP() AS now, NOW() AS dbnow FROM invitation_orders WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch() ?: ['exp' => null, 'now' => time(), 'dbnow' => date('Y-m-d H:i:s')];
    return ['exp' => $r['exp'] !== null ? (int) $r['exp'] : 0, 'now' => (int) $r['now'],
            'skew' => abs(strtotime((string) $r['dbnow']) - (int) $r['now']) > 120]; // PHP와 DB 시각이 어긋났는지
}
/** 초 → 한국 시간 글자 (PHP 기본 시간대와 상관없이 항상 서울 기준) */
function adm_kst(int $ts, string $fmt = 'Y-m-d H:i:s'): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Seoul'))->format($fmt);
}
/** 회원 무료체험이면 체험이 끝난 뒤 이만큼(시간) 뒤에 삭제 (대시보드 "삭제까지" 시계와 같은 값) */
function adm_grace_h(array $inv): int
{
    return ($inv['storage_plan'] ?? '') === 'trial' && !empty($inv['customer_id']) ? trial_grace_hours() : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    if (isset($_POST['delete_photo_id'])) {
        $photoId = (int) $_POST['delete_photo_id'];
        $p = $pdo->prepare('SELECT file_path FROM invitation_photos WHERE id = ? AND invitation_id = ?');
        $p->execute([$photoId, $id]);
        $photo = $p->fetch();
        if ($photo) {
            $dir = invitation_upload_dir($id);
            @unlink($dir . $photo['file_path']);
            @unlink($dir . 'master/' . $photo['file_path']);
            $pdo->prepare('DELETE FROM invitation_photos WHERE id = ?')->execute([$photoId]);
        }
    } elseif (isset($_POST['set_plan'])) {
        $plan = (string) $_POST['set_plan'];
        if (!in_array($plan, ['trial', 'one_year', 'permanent'], true)) {
            $error = '잘못된 보관 기간 값입니다.';
        } else {
            $paidAt = in_array($plan, ['one_year', 'permanent'], true) ? date('Y-m-d H:i:s') : null;
            $expiresAt = match ($plan) {
                'trial'     => date('Y-m-d H:i:s', strtotime('+4 days')),
                'one_year'  => date('Y-m-d H:i:s', strtotime('+1 year')),
                'permanent' => null,
            };
            $pdo->prepare('UPDATE invitation_orders SET storage_plan = ?, paid_at = ?, expires_at = ? WHERE id = ?')
                ->execute([$plan, $paidAt, $expiresAt, $id]);
            // 결제(유료) 전환이면 워터마크 제거, 체험으로 되돌리면 워터마크 다시 적용
            reprocess_invitation_photos($pdo, $id, $plan === 'trial');
            $saved = true;
        }
    } elseif (isset($_POST['set_expiry'])) {
        // 무료체험(보관 기간)이 끝나는 시각을 지금부터 N초 뒤로 (편집 화면 카운트다운·만료 팝업 확인, 고객 응대용)
        //  빠른 버튼은 초 단위 값, 직접 입력은 일·시간·분·초
        $v = (string) $_POST['set_expiry'];
        $sec = $v === 'custom'
            ? max(0, (int) ($_POST['exp_d'] ?? 0)) * 86400 + max(0, (int) ($_POST['exp_h'] ?? 0)) * 3600 + max(0, (int) ($_POST['exp_m'] ?? 0)) * 60 + max(0, (int) ($_POST['exp_s'] ?? 0))
            : (int) $v;
        if ($invite['storage_plan'] === 'permanent') {
            $error = '영구 보관 청첩장은 자동 삭제가 없어서 남은 시간을 정할 수 없어요.';
        } elseif ($sec < 1 || $sec > 3650 * 86400) {
            $error = '남은 시간은 1초부터 10년 사이로 정해주세요.';
        } else {
            $pdo->prepare('UPDATE invitation_orders SET expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?')->execute([$sec, $id]);
            if ($invite['status'] === 'expired') { // 만료 상태로 바뀌어 있었으면 다시 편집할 수 있게
                $pdo->prepare("UPDATE invitation_orders SET status = 'editing' WHERE id = ?")->execute([$id]);
            }
            $d = intdiv($sec, 86400); $hh = intdiv($sec % 86400, 3600); $mm = intdiv($sec % 3600, 60); $ss = $sec % 60;
            $notice = ($invite['storage_plan'] === 'trial' ? '무료체험 끝까지 ' : '보관 기간 끝까지 ')
                . trim(($d ? "{$d}일 " : '') . ($hh ? "{$hh}시간 " : '') . ($mm ? "{$mm}분 " : '') . ($ss ? "{$ss}초" : '')) . ' 남은 것으로 바꿨어요. 열려 있는 편집 화면은 30초 안에, 고객 대시보드는 새로고침하면 반영돼요.';
            $saved = true;
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($error) { echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE); exit; }
            $x = adm_exp_info($pdo, $id);
            $gh = adm_grace_h($invite);
            echo json_encode(['ok' => true, 'notice' => $notice, 'exp_ms' => $x['exp'] * 1000, 'purge_ms' => $gh ? ($x['exp'] + $gh * 3600) * 1000 : null,
                'now_ms' => (int) round(microtime(true) * 1000),
                'due' => adm_kst($x['exp']), 'purge_due' => $gh ? adm_kst($x['exp'] + $gh * 3600) : null], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // 저장 (아래 정보 칸 + 위 "연결된 고객"의 고객코드를 한 번에) - 오른쪽 아래 떠 있는 저장 버튼도 이걸 부른다
        $groom  = trim((string) ($_POST['groom_name'] ?? ''));
        $bride  = trim((string) ($_POST['bride_name'] ?? ''));
        $phone  = trim((string) ($_POST['customer_phone'] ?? ''));
        $memo   = trim((string) ($_POST['order_memo'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'editing');
        $canPrivate = admin_can('customer_private'); // 부관리자에게 개인정보 권한이 없으면 연락처·고객코드는 건드리지 않음
        $newCode = $canPrivate && isset($_POST['customer_code']) ? trim((string) $_POST['customer_code']) : null;
        $codeChanged = false;
        if ($newCode !== null && $invite['customer_id']) {
            $cur = $pdo->prepare('SELECT customer_code FROM customers WHERE id = ?');
            $cur->execute([$invite['customer_id']]);
            $codeChanged = $newCode !== (string) $cur->fetchColumn();
        }

        // 고객이 직접 만든 청첩장은 이름이 아직 비어 있을 수 있어서 이름은 필수로 막지 않는다
        if (!in_array($status, ['editing', 'published', 'expired'], true)) {
            $error = '잘못된 상태값입니다.';
        } elseif ($codeChanged && $newCode === '') {
            $error = '고객코드를 비울 수는 없어요.';
        } elseif ($codeChanged && !preg_match('/^[A-Za-z0-9_-]{2,30}$/', $newCode)) {
            $error = '고객코드는 영문·숫자·-·_ 2~30자로 넣어주세요.';
        } elseif ($codeChanged && (function () use ($pdo, $newCode, $invite) {
            $dup = $pdo->prepare('SELECT id FROM customers WHERE customer_code = ? AND id != ?');
            $dup->execute([$newCode, $invite['customer_id']]);
            return (bool) $dup->fetch();
        })()) {
            $error = '이미 사용중인 고객코드입니다.';
        } else {
            if ($codeChanged) {
                $pdo->prepare('UPDATE customers SET customer_code = ? WHERE id = ?')->execute([$newCode, $invite['customer_id']]);
            }
            if ($canPrivate) {
                // 연락처는 평문으로 저장하지 않고 암호화한다 (해킹 시 노출 방지)
                $phoneEnc = $phone !== '' ? encrypt_data($phone) : null;
                $update = $pdo->prepare('
                    UPDATE invitation_orders
                    SET groom_name = ?, bride_name = ?, customer_phone = NULL, customer_phone_enc = ?, order_memo = ?, status = ?
                    WHERE id = ?
                ');
                $update->execute([$groom, $bride, $phoneEnc, $memo, $status, $id]);
            } else {
                $pdo->prepare('UPDATE invitation_orders SET groom_name = ?, bride_name = ?, order_memo = ?, status = ? WHERE id = ?')
                    ->execute([$groom, $bride, $memo, $status, $id]);
            }
            $saved = true;
            $notice = $codeChanged ? '저장했어요. 고객코드도 ' . $newCode . '(으)로 바꿨어요.' : '저장했어요.';
        }
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($error ? ['ok' => false, 'error' => $error] : ['ok' => true, 'notice' => $notice], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // 저장/삭제 후 최신 상태 재조회 (별도 변수 사용 - $stmt 재사용 금지)
    $refetch = $pdo->prepare('SELECT * FROM invitation_orders WHERE id = ?');
    $refetch->execute([$id]);
    $invite = $refetch->fetch();
}

$photos = $pdo->prepare('SELECT id, file_path FROM invitation_photos WHERE invitation_id = ? ORDER BY sort_order');
$photos->execute([$id]);
$photoList = $photos->fetchAll();

$editUrl = 'https://loveday.kr/invite/invite_edit.php?t=' . $invite['edit_token'];
$viewUrl = 'https://loveday.kr/' . $invite['view_slug'];

// 관리자 화면에서만 복호화해서 보여준다 (DB에는 암호화된 채로만 저장됨)
$decryptedPhone = $invite['customer_phone_enc'] ? decrypt_data($invite['customer_phone_enc']) : (string) $invite['customer_phone'];

$linkedCustomer = null;
if ($invite['customer_id']) {
    $custStmt = $pdo->prepare('SELECT * FROM customers WHERE id = ?');
    $custStmt->execute([$invite['customer_id']]);
    $linkedCustomer = $custStmt->fetch();
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>청첩장 수정 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('list', '청첩장 수정'); ?>

    <div class="wrap">
        <h2 class="page-title">청첩장 수정 (ID: <?= (int) $invite['id'] ?>)</h2>

        <?php if ($saved): ?><p class="notice success"><?= htmlspecialchars($notice ?: '저장되었습니다.', ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($error): ?><p class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

        <div class="panel">
            <div class="field-row">
                <div class="field">
                    <label>편집 링크</label>
                    <?php if (admin_can('customer_private')): ?>
                    <span class="token-box"><?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php else: ?>
                    <span class="token-box" style="color:#A29C94">🔒 가려짐 - 계좌정보가 보이는 고객 편집 화면이라 개인정보 권한이 필요해요</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label>공개 링크</label>
                    <span class="token-box"><?= htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label>코드 (view_slug) — 고객이 결제 시 이걸 알려줍니다</label>
                    <span class="token-box"><?= htmlspecialchars($invite['view_slug'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>

        <?php if ($linkedCustomer): ?>
        <div class="panel">
            <h4 style="margin:0 0 12px;">연결된 고객</h4>
            <div class="field" style="margin:0;">
                <label>고객코드 (순차 자동배정, 직접 수정 가능)</label>
                <?php if (admin_can('customer_private')): ?>
                <input name="customer_code" form="infoForm" value="<?= htmlspecialchars($linkedCustomer['customer_code'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <?php else: ?>
                <input value="<?= htmlspecialchars($linkedCustomer['customer_code'], ENT_QUOTES, 'UTF-8') ?>" disabled title="고객코드 변경은 개인정보 권한이 필요해요">
                <?php endif; ?>
            </div>
            <p style="font-size:12.5px;color:var(--muted);margin:10px 0 0;line-height:1.6;">
                고객코드를 바꾸면 오른쪽 아래 <b>저장</b> 버튼으로 이름·연락처·상태와 함께 저장돼요.
                이 고객이 만든 다른 청첩장은 <a href="admin_create.php#tab-cust">청첩장 목록 → 고객</a>에서 고객코드로 묶여 보여요.
            </p>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h4 style="margin:0 0 4px;">결제 / 보관 기간</h4>
            <p style="font-size:13px;color:var(--muted);margin:0 0 16px;">
                현재: <b><?= ['trial'=>'무료체험 (사진에 워터마크)', 'one_year'=>'결제완료 · 1년 보관', 'permanent'=>'결제완료 · 영구보관'][$invite['storage_plan']] ?? $invite['storage_plan'] ?></b>
                <?php if ($invite['expires_at']): ?> · 자동삭제 예정: <?= htmlspecialchars($invite['expires_at'], ENT_QUOTES, 'UTF-8') ?>
                <?php else: ?> · 자동삭제 없음
                <?php endif; ?>
            </p>
            <?php if (!admin_can('plan_edit')): ?>
            <p style="font-size:12.5px;color:#8A5A00;background:#FFF6E5;border-radius:10px;padding:9px 12px;margin:0;">🔒 결제·보관 기간 처리 권한이 없어요.</p>
            <?php else: ?>
            <form method="post" style="display:flex; gap:10px; flex-wrap:wrap;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                <button type="submit" name="set_plan" value="one_year" class="btn">결제 확인 → 1년 보관 (워터마크 해제)</button>
                <button type="submit" name="set_plan" value="permanent" class="btn">영구저장 지정 (워터마크 해제)</button>
                <button type="submit" name="set_plan" value="trial" class="btn btn-danger" onclick="return confirm('무료체험으로 되돌리면 사진에 워터마크가 다시 적용되고 4일 뒤 자동삭제 대상이 됩니다. 계속할까요?');">체험으로 되돌리기</button>
            </form>
            <?php endif; ?>
        </div>

        <?php
        $isDemo = empty($invite['customer_id']) && str_starts_with((string) $invite['order_memo'], DEMO_MEMO);
        $xi = adm_exp_info($pdo, (int) $invite['id']);
        $expTs = $xi['exp'];
        $cdMin = max(1, app_setting_int('editor_countdown_min') ?: 60);
        ?>
        <div class="panel" id="expiry">
            <h4 style="margin:0 0 4px;">자동 삭제까지 남은 시간 <span style="font-weight:400;font-size:12.5px;color:var(--muted);">· 테스트·고객 응대용으로 직접 조정</span></h4>
            <?php if ($invite['storage_plan'] === 'permanent' || !$expTs): ?>
                <p style="font-size:13px;color:var(--muted);margin:6px 0 0;">영구 보관이라 자동 삭제가 없어요.</p>
            <?php else: ?>
            <?php if ($xi['skew']): ?>
            <div class="exp-warn">⚠ 서버의 PHP와 DB 시각이 9시간쯤 어긋나 있어요. 새 <b>config.php</b>로 바꾼 뒤 <a href="admin_timezone.php">서버 시간 점검</a>에서 예전 기록을 한 번 옮겨주세요. (이 칸의 남은 시간은 어긋나도 맞게 보여요)</div>
            <?php endif; ?>
            <?php $gh = adm_grace_h($invite); $purgeTs = $gh ? $expTs + $gh * 3600 : 0; $nowMs = (int) round(microtime(true) * 1000); ?>
            <div class="exp-rows" data-now-ms="<?= $nowMs ?>">
                <div class="exp-now">
                    <span class="exp-lb"><?= $invite['storage_plan'] === 'trial' ? ($isDemo ? '둘러보기 끝까지' : '무료체험 끝까지') : '보관 기간 끝까지' ?></span>
                    <span class="exp-left" id="expLeft" data-exp-ms="<?= $expTs * 1000 ?>">…</span>
                    <span class="exp-meta"><span id="expDue"><?= htmlspecialchars(adm_kst($expTs), ENT_QUOTES, 'UTF-8') ?></span> · <?= $isDemo ? '둘러보기(비회원) - 끝나면 바로 삭제' : ($invite['customer_id'] ? '회원' : '회원 아님') . ' · ' . ($invite['storage_plan'] === 'trial' ? '무료체험' : '결제됨') ?></span>
                </div>
                <?php if ($gh): ?>
                <div class="exp-now sub">
                    <span class="exp-lb">삭제까지 <small>(유예 <?= $gh ?>시간 포함 · 고객 대시보드 시계와 같아요)</small></span>
                    <span class="exp-left" id="purgeLeft" data-exp-ms="<?= $purgeTs * 1000 ?>">…</span>
                    <span class="exp-meta"><span id="purgeDue"><?= htmlspecialchars(adm_kst($purgeTs), ENT_QUOTES, 'UTF-8') ?></span>에 휴지통으로</span>
                </div>
                <?php endif; ?>
                <p class="exp-clock">모든 시각은 <b>한국 시간</b>이에요 · 지금 서버 시각 <b id="srvNow"><?= htmlspecialchars(adm_kst((int) floor($nowMs / 1000)), ENT_QUOTES, 'UTF-8') ?></b><span id="pcSkew"></span></p>
            </div>
            <?php if (!admin_can('expiry_edit')): ?>
            <p style="font-size:12.5px;color:#8A5A00;background:#FFF6E5;border-radius:10px;padding:9px 12px;margin:0;">🔒 무료 시간 수정 권한이 없어요. 남은 시간은 볼 수만 있어요.</p>
            <?php else: ?>
            <form method="post" class="exp-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                <div class="exp-quick">
                    <span>지금부터</span>
                    <?php foreach ([60 => '1분', 180 => '3분', 600 => '10분', 3600 => '1시간', 86400 => '1일', 345600 => '4일'] as $m => $lb): ?>
                        <button type="submit" name="set_expiry" value="<?= $m ?>" class="btn btn-sm"><?= $lb ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="exp-custom">
                    <span>직접 입력</span>
                    <label><input type="number" name="exp_d" min="0" max="3650" value="0">일</label>
                    <label><input type="number" name="exp_h" min="0" max="23" value="0">시간</label>
                    <label><input type="number" name="exp_m" min="0" max="59" value="5">분</label>
                    <label><input type="number" name="exp_s" min="0" max="59" value="0">초</label>
                    <button type="submit" name="set_expiry" value="custom" class="btn btn-sm">이 시간으로 바꾸기</button>
                </div>
            </form>
            <?php endif; ?>
            <p class="exp-help">
                <?php if ($invite['storage_plan'] === 'trial'): ?>편집 화면에서는 삭제 <b><?= $cdMin ?>분 전</b>부터 가운데에 남은 시간이 크게 흐르고(<a href="admin_extra_settings.php">부가기능</a>에서 변경), 0이 되면 <?= $isDemo ? '"지금 가입하면 편집 시간이 늘어나요"' : '"결제하면 삭제되지 않아요"' ?> 팝업이 떠요.<br><?php endif; ?>
                ⚠ 시간이 지나면 하객 주소(loveday.kr/코드)는 바로 막히고, <?= $invite['customer_id'] ? '네이버 회원 청첩장이라 <b>' . trial_grace_hours() . '시간 유예</b> 뒤에 <a href="admin_trash.php">휴지통</a>으로 옮겨지고, ' . trash_keep_days() . '일 뒤 사진까지 완전히 삭제돼요' : '둘러보기(비회원)라 <b>바로</b> 사진까지 완전히 삭제돼요 (휴지통 없음)' ?> (정리 작업 expire_cleanup.php). 테스트한 뒤에는 다시 넉넉하게 늘려 두세요.
            </p>
            <?php endif; ?>
        </div>

        <div class="panel">
            <form method="post" autocomplete="off" id="infoForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                <input type="hidden" name="save_info" value="1">
                <div class="field-row">
                    <div class="field">
                        <label>신랑 이름</label>
                        <input name="groom_name" value="<?= htmlspecialchars($invite['groom_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="비어 있어도 저장돼요">
                    </div>
                    <div class="field">
                        <label>신부 이름</label>
                        <input name="bride_name" value="<?= htmlspecialchars($invite['bride_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="비어 있어도 저장돼요">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>연락처</label>
                        <?php if (admin_can('customer_private')): ?>
                        <input name="customer_phone" value="<?= htmlspecialchars($decryptedPhone, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                        <?php else: ?>
                        <input value="<?= $decryptedPhone !== '' ? '🔒 비공개' : '' ?>" placeholder="🔒 비공개" disabled title="개인정보 권한이 필요해요">
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label>주문메모</label>
                        <input name="order_memo" value="<?= htmlspecialchars((string)$invite['order_memo'], ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>상태</label>
                        <select name="status">
                            <option value="editing" <?= $invite['status'] === 'editing' ? 'selected' : '' ?>>editing</option>
                            <option value="published" <?= $invite['status'] === 'published' ? 'selected' : '' ?>>published</option>
                            <option value="expired" <?= $invite['status'] === 'expired' ? 'selected' : '' ?>>expired</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn">저장</button>
            </form>
        </div>

        <h2 class="page-title">사진 (<?= count($photoList) ?>장)</h2>
        <?php if (!admin_can('customer_photos')): ?>
        <div class="panel" style="text-align:center;color:var(--muted);font-size:13.5px;">🔒 고객이 올린 사진을 볼 권한이 없어요.</div>
        <?php else: ?>
        <div class="panel">
            <div class="photo-grid">
            <?php foreach ($photoList as $p): ?>
                <div class="photo-cell">
                    <img src="uploads/<?= (int) $invite['id'] ?>/<?= htmlspecialchars($p['file_path'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <form method="post" onsubmit="return confirm('이 사진을 삭제하시겠습니까?');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= (int) $invite['id'] ?>">
                        <input type="hidden" name="delete_photo_id" value="<?= (int) $p['id'] ?>">
                        <button type="submit" class="btn btn-danger">삭제</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
<style>
.exp-now { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin: 10px 0 6px; }
.exp-now.sub { margin-top: 0; padding-top: 8px; border-top: 1px dashed #EAE6E0; }
.exp-now.sub .exp-left { font-size: 22px; color: #9A3434; }
.exp-lb { width: 100%; font-size: 12px; font-weight: 600; color: var(--muted); }
.exp-lb small { font-weight: 400; }
.exp-clock { margin: 4px 0 14px; font-size: 12px; color: var(--muted); }
.exp-clock span { color: #8A5A00; }
.exp-left { font-size: 30px; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.exp-left.over { color: #B24A4A; }
.exp-meta { font-size: 12.5px; color: var(--muted); }
.exp-form { display: flex; flex-direction: column; gap: 10px; }
.exp-quick, .exp-custom { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.exp-quick > span, .exp-custom > span { font-size: 12.5px; color: var(--muted); width: 56px; flex: none; }
.exp-custom label { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; }
.exp-custom input { width: 64px; padding: 6px 8px; }
.btn.btn-sm { padding: 7px 12px; font-size: 13px; }
.exp-warn { margin: 10px 0 0; padding: 10px 12px; border-radius: 10px; background: #FFF6E5; color: #8A5A00; font-size: 12.5px; line-height: 1.6; }
.exp-warn a { color: inherit; font-weight: 700; }
.exp-form button.busy { opacity: .5; pointer-events: none; }
.exp-left.flash { animation: expFlash .6s ease; }
@keyframes expFlash { 0% { color: #2F7D55; transform: scale(1.06); } 100% { transform: none; } }
.exp-help { font-size: 12.5px; color: var(--muted); line-height: 1.7; margin: 12px 0 0; }
</style>
<script>
// 남은 시간 실시간 표시 (서버 시각 기준으로 보정) + 버튼은 새로고침 없이 바로 반영
(function () {
    var el = document.getElementById('expLeft'); if (!el) return;
    var pel = document.getElementById('purgeLeft'), rows = document.querySelector('.exp-rows');
    var exp = +el.dataset.expMs, purge = pel ? +pel.dataset.expMs : 0, off = +rows.dataset.nowMs - Date.now();
    // 이 컴퓨터 시계가 서버와 1분 넘게 다르면 알려줌 (남은 시간은 서버 기준이라 맞게 보임)
    if (Math.abs(off) > 60000) document.getElementById('pcSkew').textContent = ' · 이 컴퓨터 시계가 서버보다 ' + Math.round(Math.abs(off) / 60000) + '분 ' + (off > 0 ? '느려요' : '빨라요') + ' (남은 시간은 서버 기준으로 맞게 보여요)';
    var form = document.querySelector('.exp-form');
    if (form) form.addEventListener('submit', function (e) {
        var btn = e.submitter; if (!btn || btn.name !== 'set_expiry') return;
        e.preventDefault();
        var fd = new FormData(form); fd.set('set_expiry', btn.value); fd.set('ajax', '1');
        btn.classList.add('busy');
        fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
            btn.classList.remove('busy');
            if (!j.ok) { (window.LD ? LD.alert(j.error) : alert(j.error)); return; }
            exp = j.exp_ms; off = j.now_ms - Date.now(); if (j.purge_ms) purge = j.purge_ms; tick();
            document.getElementById('expDue').textContent = j.due;
            if (j.purge_due && document.getElementById('purgeDue')) document.getElementById('purgeDue').textContent = j.purge_due;
            el.classList.remove('flash'); void el.offsetWidth; el.classList.add('flash');
            if (window.LD) LD.toast(j.notice);
        }).catch(function () { btn.classList.remove('busy'); form.submit(); }); // 안 되면 예전처럼 새로고침으로
    });
    function p(n) { return String(n).padStart(2, '0'); }
    function show(node, target) {
        var ms = target - (Date.now() + off), over = ms <= 0, s = Math.floor(Math.abs(ms) / 1000);
        var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60;
        node.textContent = (over ? '지남 ' : '') + (d ? d + '일 ' : '') + p(h) + ':' + p(m) + ':' + p(x);
        node.classList.toggle('over', over);
    }
    var srv = document.getElementById('srvNow');
    function tick() {
        show(el, exp); if (pel) show(pel, purge);
        var n = new Date(Date.now() + off + 9 * 3600000); // 서버 시각을 한국 시간으로
        srv.textContent = n.toISOString().slice(0, 19).replace('T', ' ');
    }
    tick(); setInterval(tick, 1000);
})();
</script>
<!-- 오른쪽 아래 떠 있는 저장 버튼: 이름·연락처·메모·상태 + 고객코드를 새로고침 없이 저장 -->
<button type="submit" form="infoForm" class="save-fab" id="saveFab" aria-label="저장"><span class="dot"></span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h11l3 3v12a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4Z"/><path d="M8 4v5h7V4M8 20v-6h8v6"/></svg><b>저장</b></button>
<style>
body { padding-bottom: 96px; } /* 맨 아래 내용이 떠 있는 버튼에 가리지 않게 */
.token-box { overflow-wrap: anywhere; word-break: break-all; }
.save-fab { position: fixed; right: max(16px, env(safe-area-inset-right)); bottom: max(18px, env(safe-area-inset-bottom)); z-index: 70;
    display: inline-flex; align-items: center; gap: 7px; height: 48px; padding: 0 18px 0 15px; border: 0; border-radius: 999px;
    background: rgba(43, 35, 32, .62); color: #fff; font: inherit; font-size: 14.5px; cursor: pointer;
    -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); box-shadow: 0 8px 24px rgba(30, 20, 10, .18);
    transition: transform .28s cubic-bezier(.2, .8, .3, 1), opacity .25s, background .2s; }
.save-fab svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linejoin: round; }
.save-fab b { font-weight: 700; }
.save-fab .dot { position: absolute; top: 7px; right: 10px; width: 8px; height: 8px; border-radius: 50%; background: #F2A14B; box-shadow: 0 0 0 2px rgba(43, 35, 32, .9); transform: scale(0); transition: transform .2s; }
.save-fab:hover { background: rgba(43, 35, 32, .82); }
.save-fab.dirty { background: rgba(43, 35, 32, .9); }
.save-fab.dirty .dot { transform: scale(1); }
.save-fab.busy { opacity: .6; pointer-events: none; }
.save-fab.done { background: rgba(47, 125, 85, .92); }
/* 스크롤 중이거나 버튼·글자 위에 겹치면 아래로 비켜서 작게 (탭하면 다시 올라옴) */
.save-fab.tuck { transform: translateY(calc(100% - 14px)) scale(.9); opacity: .45; }
.save-fab.tuck.dirty { opacity: .8; }
@media (min-width: 761px) { .save-fab { right: 28px; bottom: 28px; } }
</style>
<script>
(function () {
    var fab = document.getElementById('saveFab'), form = document.getElementById('infoForm');
    if (!fab || !form) return;
    // 바뀐 게 있으면 점 표시
    var fields = Array.prototype.filter.call(form.elements, function (el) { return el.name && el.type !== 'hidden' && el.type !== 'submit'; });
    var initial = {};
    function snap() { fields.forEach(function (el) { initial[el.name] = el.value; }); }
    function dirty() { return fields.some(function (el) { return el.value !== initial[el.name]; }); }
    function mark() { fab.classList.toggle('dirty', dirty()); }
    snap();
    fields.forEach(function (el) { el.addEventListener('input', mark); el.addEventListener('change', mark); });
    // 저장 안 하고 나가려 하면 LOVE DAY 팝업으로 확인 (ld-dialog.js가 아래에서 불러와지므로 화면이 다 그려진 뒤에 연결)
    var guard = null;
    document.addEventListener('DOMContentLoaded', function () {
        if (window.LD && LD.guardLeave) guard = LD.guardLeave(dirty, { except: '#infoForm' });
        else window.addEventListener('beforeunload', function (e) { if (dirty()) { e.preventDefault(); e.returnValue = ''; } });
    });

    // 저장 (새로고침 없이) - 안 되면 원래대로 폼 전송
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var fd = new FormData(form); fd.set('ajax', '1');
        fab.classList.add('busy');
        fetch(location.pathname + '?id=' + encodeURIComponent(form.elements.id.value), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                fab.classList.remove('busy');
                if (!j.ok) { (window.LD ? LD.alert(j.error) : alert(j.error)); return; }
                snap(); mark();
                fab.classList.add('done'); setTimeout(function () { fab.classList.remove('done'); }, 1200);
                if (window.LD) LD.toast(j.notice || '저장했어요');
            })
            .catch(function () { fab.classList.remove('busy'); snap(); if (guard) guard.release(); form.submit(); });
    });

    // 겹침 확인: 버튼 아래에 누를 것(버튼·링크·입력칸)이나 중요한 글자가 있으면 비켜 줌
    var IMPORTANT = 'a, button, input, select, textarea, label, .btn, h2, h3, h4, .notice, .token-box, .exp-left, .exp-warn';
    var scrolling = false, stopT = 0, raf = 0, forced = false;
    function covered() {
        var r = fab.getBoundingClientRect(), pts = [[r.left + 10, r.top + 10], [r.right - 10, r.top + 10], [r.left + r.width / 2, r.top + r.height / 2], [r.left + 10, r.bottom - 10], [r.right - 10, r.bottom - 10]];
        fab.style.pointerEvents = 'none';
        var hit = pts.some(function (p) { var el = document.elementFromPoint(p[0], p[1]); return el && el !== fab && !fab.contains(el) && el.closest(IMPORTANT); });
        fab.style.pointerEvents = '';
        return hit;
    }
    function update() { raf = 0; fab.classList.toggle('tuck', !forced && (scrolling || covered())); }
    function queue() { if (!raf) raf = requestAnimationFrame(update); }
    window.addEventListener('scroll', function () {
        scrolling = true; forced = false; queue();
        clearTimeout(stopT); stopT = setTimeout(function () { scrolling = false; queue(); }, 450);
    }, { passive: true });
    window.addEventListener('resize', queue);
    // 비켜 있을 때 누르면: 먼저 올라오기만 (바로 저장하지 않음). 한 번 더 누르면 저장
    fab.addEventListener('click', function (e) {
        if (fab.classList.contains('tuck')) { e.preventDefault(); forced = true; fab.classList.remove('tuck'); }
    });
    queue();
})();
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
