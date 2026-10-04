<?php
/**
 * admin_vip.php - VIP 전용 코드 생성 (상단 메뉴 "VIP 코드 생성")
 *  일반 고객은 랜딩페이지에서 네이버 로그인으로 직접 만든다.
 *  이 화면은 관리자가 지정된(원하는) 코드로 VIP 청첩장을 만들어 줄 때만 쓴다.
 *  만든 청첩장은 청첩장 목록 → VIP 탭에서 관리한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
$result = null;
$error  = '';
$old = ['groom_name' => '', 'bride_name' => '', 'custom_slug' => '', 'storage_plan' => 'one_year', 'customer_phone' => '', 'order_memo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $groom  = trim((string) ($_POST['groom_name'] ?? ''));
    $bride  = trim((string) ($_POST['bride_name'] ?? ''));
    $phone  = trim((string) ($_POST['customer_phone'] ?? ''));
    $memo   = trim((string) ($_POST['order_memo'] ?? ''));
    $slug   = strtolower(trim((string) ($_POST['custom_slug'] ?? '')));
    $plan   = (string) ($_POST['storage_plan'] ?? 'one_year');
    $old = ['groom_name' => $groom, 'bride_name' => $bride, 'custom_slug' => $slug, 'storage_plan' => $plan, 'customer_phone' => $phone, 'order_memo' => $memo];

    if ($groom === '' || $bride === '') {
        $error = '신랑, 신부 이름은 필수입니다.';
    } elseif (!preg_match('/^[a-z0-9]{4,20}$/', $slug)) {
        $error = '지정 코드는 영문 소문자·숫자 4~20자로 입력해주세요.';
    } elseif (in_array($slug, ['adm', 'invite', 'assets'], true)) {
        $error = '이 코드는 시스템에서 예약되어 사용할 수 없습니다.';
    } elseif (!in_array($plan, ['one_year', 'permanent'], true)) {
        $error = '잘못된 보관 기간 값입니다.';
    } else {
        $dup = $pdo->prepare('SELECT id FROM invitation_orders WHERE view_slug = ?');
        $dup->execute([$slug]);
        if ($dup->fetch()) {
            $error = '이미 사용중인 코드입니다. 다른 코드를 입력해주세요.';
        } else {
            $editToken = generate_edit_token();
            // 연락처는 평문으로 저장하지 않고 암호화한다 (해킹 시 노출 방지)
            $phoneEnc  = $phone !== '' ? encrypt_data($phone) : null;
            $expiresAt = $plan === 'permanent' ? null : date('Y-m-d H:i:s', strtotime('+1 year'));

            $stmt = $pdo->prepare('
                INSERT INTO invitation_orders
                    (order_platform, order_memo, customer_phone_enc, edit_token, view_slug,
                     groom_name, bride_name, status, storage_plan, paid_at, expires_at)
                VALUES
                    (\'vip_admin\', ?, ?, ?, ?, ?, ?, \'editing\', ?, NOW(), ?)
            ');
            $stmt->execute([$memo, $phoneEnc, $editToken, $slug, $groom, $bride, $plan, $expiresAt]);

            $result = [
                'id'       => (int) $pdo->lastInsertId(),
                'edit_url' => 'https://loveday.kr/invite/dashboard.php?t=' . $editToken,
                'view_url' => 'https://loveday.kr/' . $slug,
            ];
            $old = ['groom_name' => '', 'bride_name' => '', 'custom_slug' => '', 'storage_plan' => 'one_year', 'customer_phone' => '', 'order_memo' => ''];
        }
    }
}

// 최근 발급한 VIP (10건)
$recent = $pdo->query("SELECT id, groom_name, bride_name, view_slug, storage_plan, status, created_at
                       FROM invitation_orders WHERE order_platform = 'vip_admin' AND customer_id IS NULL AND deleted_at IS NULL
                       ORDER BY id DESC LIMIT 10")->fetchAll();
$statusLabel = ['editing' => '편집중', 'published' => '발행됨', 'expired' => '만료'];
$planLabel = ['one_year' => '1년', 'permanent' => '영구', 'trial' => '무료체험'];
$csrf = csrf_token();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VIP 코드 생성 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.vip-done { background: #F2FBF6; border: 1px solid #CFEBDB; border-radius: 14px; padding: 16px 18px; margin: 0 0 20px; }
.vip-done h3 { margin: 0 0 10px; font-size: 15px; color: #1F6B45; }
.vip-link { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; flex-wrap: wrap; }
.vip-link small { width: 64px; flex: none; font-size: 12px; color: #5F7A6B; }
.vip-link code { flex: 1; min-width: 0; overflow-wrap: anywhere; font-size: 12.5px; background: #fff; border: 1px solid #DDEFE4; border-radius: 8px; padding: 7px 10px; }
.vip-link button { flex: none; border: 0; border-radius: 8px; background: #03A04B; color: #fff; font: inherit; font-size: 12.5px; font-weight: 700; padding: 7px 11px; cursor: pointer; }
.vip-warn { margin: 10px 0 0; font-size: 12.5px; color: #7A2E2E; line-height: 1.6; }
.slug-prev { font-size: 12px; color: var(--muted); margin-top: 5px; }
.slug-prev b { color: #2B2B2B; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.vip-list { background: #fff; border: 1px solid #ECE8E2; border-radius: 14px; overflow: hidden; }
.vip-list a { display: grid; grid-template-columns: 44px minmax(0, 1fr) 64px 50px 76px; gap: 10px; align-items: center; padding: 10px 14px; border-bottom: 1px solid #F1EEE9; color: inherit; text-decoration: none; font-size: 13px; }
.vip-list a:last-child { border-bottom: 0; }
.vip-list a:hover { background: #FBF9F6; }
.vip-list .no { font-size: 11.5px; color: #A29C94; font-weight: 600; }
.vip-list .nm { font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vip-list .cd { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: #6F6A63; overflow: hidden; text-overflow: ellipsis; }
.vip-list .dt { font-size: 12px; color: #8F8980; text-align: right; }
.vip-list .pl { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 5px; background: #EEF1F6; color: #4A5A73; justify-self: start; }
.vip-list .pl.permanent { background: #E9F5EE; color: #2F7A4E; }
@media (max-width: 720px) {
    .vip-list a { grid-template-columns: minmax(0, 1fr) 50px 44px; padding: 10px 12px; }
    .vip-list .no, .vip-list .cd { display: none; }
}
</style>
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('vip', 'VIP 코드 생성'); ?>

    <div class="wrap">
        <h2 class="page-title">VIP 전용 코드 생성</h2>
        <p style="font-size:13px;color:var(--muted);margin:-14px 0 20px;line-height:1.7;">
            일반 고객은 랜딩페이지에서 네이버 로그인으로 직접 만듭니다. 이 화면은 특별히
            <b>지정된(원하는) 코드</b>로 VIP 청첩장을 만들어드릴 때만 사용하세요.
            만든 청첩장은 <a href="admin_create.php#tab-vip">청첩장 목록 → VIP</a>에서 관리합니다.
        </p>

        <?php if ($error): ?>
            <p class="notice error"><?= $h($error) ?></p>
        <?php endif; ?>

        <?php if ($result): ?>
            <div class="vip-done">
                <h3>✓ 발급 완료 · 아래 편집 링크를 고객에게 문자/톡톡으로 전달하세요</h3>
                <div class="vip-link"><small>편집 링크</small><code><?= $h($result['edit_url']) ?></code><button type="button" data-copy="<?= $h($result['edit_url']) ?>">복사</button></div>
                <div class="vip-link"><small>공개 링크</small><code><?= $h($result['view_url']) ?></code><button type="button" data-copy="<?= $h($result['view_url']) ?>">복사</button></div>
                <p class="vip-warn">
                    편집 링크는 계좌정보 등을 수정할 수 있는 개인 링크입니다.
                    카카오톡 단체방 등에 공유하지 말고 반드시 신랑신부 본인에게만 전달해주세요.
                    · <a href="admin_edit.php?id=<?= (int) $result['id'] ?>">이 청첩장 수정 화면</a>
                </p>
            </div>
        <?php endif; ?>

        <div class="panel">
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <div class="field-row">
                    <div class="field">
                        <label>신랑 이름</label>
                        <input name="groom_name" value="<?= $h($old['groom_name']) ?>" required>
                    </div>
                    <div class="field">
                        <label>신부 이름</label>
                        <input name="bride_name" value="<?= $h($old['bride_name']) ?>" required>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>지정 코드 (loveday.kr/이코드) — 영문 소문자·숫자 4~20자</label>
                        <input name="custom_slug" id="vipSlug" value="<?= $h($old['custom_slug']) ?>" placeholder="예: kimtaeyeon2026" pattern="[a-z0-9]{4,20}" required>
                        <div class="slug-prev">주소: <b>loveday.kr/<span id="vipSlugPrev"><?= $h($old['custom_slug'] ?: '…') ?></span></b></div>
                    </div>
                    <div class="field">
                        <label>보관 기간</label>
                        <select name="storage_plan">
                            <option value="one_year"<?= $old['storage_plan'] === 'one_year' ? ' selected' : '' ?>>1년 보관</option>
                            <option value="permanent"<?= $old['storage_plan'] === 'permanent' ? ' selected' : '' ?>>영구 보관</option>
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>연락처 (선택)</label>
                        <input name="customer_phone" value="<?= $h($old['customer_phone']) ?>" placeholder="010-0000-0000">
                    </div>
                    <div class="field">
                        <label>메모 (선택)</label>
                        <input name="order_memo" value="<?= $h($old['order_memo']) ?>" placeholder="VIP 사유 등">
                    </div>
                </div>
                <button type="submit" class="btn">생성 및 링크 발급</button>
            </form>
        </div>

        <?php if ($recent): ?>
        <h2 class="page-title" style="font-size:16px;">최근 발급한 VIP</h2>
        <div class="vip-list">
            <?php foreach ($recent as $r): ?>
                <a href="admin_edit.php?id=<?= (int) $r['id'] ?>">
                    <span class="no">#<?= (int) $r['id'] ?></span>
                    <span class="nm"><?= $h(($r['groom_name'] ?: '-') . ' ♥ ' . ($r['bride_name'] ?: '-')) ?></span>
                    <span class="cd"><?= $h($r['view_slug']) ?></span>
                    <span class="pl <?= $h($r['storage_plan']) ?>"><?= $h($planLabel[$r['storage_plan']] ?? $r['storage_plan']) ?></span>
                    <span class="dt"><?= $h(date('m-d', strtotime((string) $r['created_at']))) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
<script src="assets/ld-dialog.js"></script>
<script>
(function () {
    var s = document.getElementById('vipSlug'), p = document.getElementById('vipSlugPrev');
    if (s) s.addEventListener('input', function () { s.value = s.value.toLowerCase().replace(/[^a-z0-9]/g, ''); p.textContent = s.value || '…'; });
    document.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            var v = b.dataset.copy;
            if (window.LD) LD.copy(v);
            else navigator.clipboard.writeText(v).then(function () { b.textContent = '복사됨'; });
        });
    });
})();
</script>
</body>
</html>
