<?php
/**
 * admin_snap_settings.php - 관리자: 게스트스냅(하객 사진) 설정 + 사용 현황
 * 값은 app_settings 테이블에 저장되고, 저장 즉시 모든 청첩장에 적용된다.
 * 방명록 금지어·참석 회신·체험하기·배경음악 설정은 "부가기능"(admin_extra_settings.php)으로 옮겼다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/gdrive.php';

$pdo = get_pdo();
$fields = [
    'snap_quota_invite_mb' => ['청첩장 1건당 전체 업로드 한도', 'MB', 1, 5000],
    'snap_quota_guest_mb'  => ['하객 1명당 업로드 한도 (청첩장 1건 기준)', 'MB', 1, 5000],
    'snap_max_file_mb'     => ['사진 1장 최대 크기 (서버에서 줄이기 전)', 'MB', 1, 30],
    'snap_days_before'     => ['예식일 며칠 전부터 업로드 허용', '일 전', 0, 60],
    'snap_days_after'      => ['예식일 며칠 후까지 업로드 허용', '일 후', 0, 60],
    'snap_retention_days'  => ['예식일 며칠 후에 사진 자동 삭제', '일 후', 1, 365],
    'snap_download_limit'  => ['신랑신부 전체 다운로드(zip) 가능 횟수', '회', 1, 20],
];

$notice = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'gdrive') {
    // 구글 드라이브 연동 (구글 승인 전에는 꺼 두기). 보안 비밀번호는 입력했을 때만 바꾸고 암호화해서 저장
    csrf_verify($_POST['csrf_token'] ?? null);
    $cid = trim((string) ($_POST['gdrive_client_id'] ?? ''));
    $sec = trim((string) ($_POST['gdrive_client_secret'] ?? ''));
    if ($cid !== '' && !preg_match('/^[A-Za-z0-9._-]{10,200}$/', $cid)) $error = '클라이언트 ID 형식이 올바르지 않아요.';
    $enable = !empty($_POST['gdrive_enabled']);
    if (!$error && $enable && ($cid === '' || ($sec === '' && app_setting('gdrive_client_secret') === '' && !defined('GDRIVE_CLIENT_SECRET')))) $error = '드라이브 연동을 켜려면 클라이언트 ID와 보안 비밀번호를 먼저 넣어주세요.';
    if (!$error) {
        save_app_setting($pdo, 'gdrive_client_id', $cid);
        if ($sec !== '') save_app_setting($pdo, 'gdrive_client_secret', encrypt_data($sec));
        if (!empty($_POST['gdrive_secret_clear'])) save_app_setting($pdo, 'gdrive_client_secret', '');
        save_app_setting($pdo, 'gdrive_enabled', $enable ? '1' : '0');
        save_app_setting($pdo, 'snap_server_download', !empty($_POST['snap_server_download']) ? '1' : '0');
        save_app_setting($pdo, 'export_server_download', !empty($_POST['export_server_download']) ? '1' : '0');
        header('Location: admin_snap_settings.php?saved=gd#gdrive');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $vals = [];
    foreach ($fields as $key => [$label, $unit, $min, $max]) {
        $v = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) { $error = "{$label}: {$min}~{$max} 사이 숫자로 입력해주세요."; break; }
        $vals[$key] = $v;
    }
    if (!$error && $vals['snap_retention_days'] < $vals['snap_days_after']) $error = '자동 삭제일은 업로드 마감일보다 뒤여야 해요.';
    if (!$error && $vals['snap_quota_guest_mb'] > $vals['snap_quota_invite_mb']) $error = '하객 1명당 한도는 청첩장 전체 한도보다 클 수 없어요.';
    if (!$error) {
        foreach ($vals as $k => $v) save_app_setting($pdo, $k, (string) $v);
        header('Location: admin_snap_settings.php?saved=1');
        exit;
    }
}
if (isset($_GET['saved'])) $notice = $_GET['saved'] === 'gd' ? '구글 드라이브 설정을 저장했습니다.' : '저장했습니다. 지금부터 모든 청첩장에 적용됩니다.';

// 현재값 (저장 실패 시에는 방금 입력한 값을 그대로 다시 보여줌)
$cur = [];
foreach ($fields as $key => $_) $cur[$key] = ($error && ($_POST['act'] ?? '') !== 'gdrive') ? (string) ($_POST[$key] ?? '') : app_setting($key);
$gd = [
    'enabled' => app_setting('gdrive_enabled') === '1', 'cid' => gdrive_client_id(), 'hasSecret' => gdrive_client_secret() !== '',
    'cidConst' => defined('GDRIVE_CLIENT_ID'), 'secConst' => defined('GDRIVE_CLIENT_SECRET'),
    'snapDl' => snap_server_download_on(), 'expDl' => export_server_download_on(), 'redirect' => gdrive_redirect_uri(),
];
$gdLinked = 0;
try { gdrive_tables($pdo); $gdLinked = (int) $pdo->query('SELECT COUNT(*) FROM gdrive_links')->fetchColumn(); } catch (Throwable $e) {}

// 사용 현황 (용량 많은 순 50건)
$tableReady = true;
try {
    $stats = $pdo->query('
        SELECT s.invitation_id, COUNT(*) cnt, SUM(s.bytes) bytes, MAX(s.created_at) last_at,
               o.view_slug, o.groom_name, o.bride_name,
               (SELECT COUNT(*) FROM guest_snap_downloads d WHERE d.invitation_id = s.invitation_id) dl
        FROM guest_snaps s LEFT JOIN invitation_orders o ON o.id = s.invitation_id
        GROUP BY s.invitation_id ORDER BY bytes DESC LIMIT 50')->fetchAll();
    $totalBytes = (int) $pdo->query('SELECT COALESCE(SUM(bytes),0) FROM guest_snaps')->fetchColumn();
} catch (Throwable $e) {
    $tableReady = false; $stats = []; $totalBytes = 0;
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>게스트스냅 설정 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .set-row { display: grid; grid-template-columns: 1fr 140px; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
    .set-row input { width: 90px; padding: 7px 8px; }
    .set-row .unit { font-size: 12px; color: #888; margin-left: 4px; }
    .set-row.wide { grid-template-columns: 220px 1fr; }
    .set-row.wide input[type=text], .set-row.wide input[type=password] { width: 100%; max-width: 420px; }
    .set-row label small { color: #999; font-weight: 400; }
    .gd-desc, .gd-help { font-size: 12.5px; color: #777; line-height: 1.7; margin: 0 0 10px; }
    .gd-help ol { margin: 6px 0 0; padding-left: 18px; }
    .gd-sub { font-size: 13px; margin: 18px 0 2px; color: #555; }
    .sw-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eee; cursor: pointer; }
    .sw-row small { display: block; font-size: 12px; color: #999; margin-top: 2px; font-weight: 400; }
    .sw { appearance: none; -webkit-appearance: none; width: 40px; height: 22px; border-radius: 11px; background: var(--ui-line, #D5D2CB); position: relative; cursor: pointer; transition: background .2s; flex: none; margin: 0; }
    .sw::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: left .2s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
    .sw:checked { background: #3D4F66; } .sw:checked::after { left: 21px; }
    .copy { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; } .copy code { font-size: 12px; background: var(--ui-soft, #F4F2EE); padding: 5px 8px; border-radius: 6px; word-break: break-all; }
    .btn.line { background: #fff; color: #3D4F66; border: 1px solid #C9CED6; } .btn.sm { padding: 4px 10px; font-size: 12px; } .mini { font-size: 12px; color: #888; margin-left: 8px; }
    table.stat { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.stat th, table.stat td { padding: 8px 6px; border-bottom: 1px solid #eee; text-align: left; }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('snap', '게스트스냅 설정'); ?>
    <div class="wrap">
        <h2 class="page-title">게스트스냅 설정</h2>
        <p style="font-size:13px;color:#888;margin:-14px 0 18px;">하객이 로그인 없이 올리는 사진의 한도·기간·자동 삭제를 정해요. 방명록 금지어 등은 <a href="admin_extra_settings.php">부가기능</a>에 있어요.</p>
        <?php if (!$tableReady): ?><div class="notice error">DB 테이블이 아직 없습니다. 서버에서 <b>snap_setup.sql</b>을 먼저 실행해주세요.</div><?php endif; ?>
        <?php if ($notice): ?><div class="notice success"><?= snap_h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= snap_h($error) ?></div><?php endif; ?>

        <form method="post" class="panel">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php foreach ($fields as $key => [$label, $unit, $min, $max]): ?>
            <div class="set-row">
                <label for="<?= $key ?>"><?= snap_h($label) ?></label>
                <span><input type="number" id="<?= $key ?>" name="<?= $key ?>" min="<?= $min ?>" max="<?= $max ?>" value="<?= snap_h($cur[$key]) ?>" required><span class="unit"><?= snap_h($unit) ?></span></span>
            </div>
            <?php endforeach; ?>
            <p style="font-size:12px;color:#888;">사진은 서버에서 1920px webp로 줄여 저장하고, 한도는 줄인 뒤 크기로 계산합니다 (사진 1장 약 0.3~0.6MB → 50MB면 약 100장). 영상은 받지 않습니다.</p>
            <button class="btn" type="submit">저장</button>
        </form>

        <h2 class="page-title" id="gdrive" style="margin-top:30px;">구글 드라이브 연동</h2>
        <form method="post" class="panel gd-panel">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="act" value="gdrive">
            <p class="gd-desc">켜면 신랑신부의 <b>게스트스냅 관리</b> 화면에 "구글 드라이브 연결하기"가 생기고, 하객 사진이 신랑신부 본인 드라이브 폴더로 바로 저장돼요. 내 청첩장의 <b>파일로 받기</b>도 드라이브로 보낼 수 있어요. 구글 앱 인증(검토)이 끝나기 전에는 <b>꺼 두세요</b> &mdash; 꺼 두면 고객 화면에 아무것도 안 보여요.</p>
            <label class="sw-row"><span><b>드라이브 연동 사용</b><small>지금 연결된 청첩장 <?= $gdLinked ?>개</small></span>
                <input type="checkbox" class="sw" name="gdrive_enabled" value="1" <?= $gd['enabled'] ? 'checked' : '' ?>></label>
            <div class="set-row wide"><label for="gcid">OAuth 클라이언트 ID</label>
                <input type="text" id="gcid" name="gdrive_client_id" value="<?= snap_h($gd['cid']) ?>" placeholder="1234-xxxx.apps.googleusercontent.com" <?= $gd['cidConst'] ? 'readonly title="config.php의 GDRIVE_CLIENT_ID가 우선 적용 중"' : '' ?>></div>
            <div class="set-row wide"><label for="gsec">클라이언트 보안 비밀번호</label>
                <span><input type="password" id="gsec" name="gdrive_client_secret" value="" autocomplete="new-password" placeholder="<?= $gd['hasSecret'] ? '저장됨 (바꿀 때만 입력)' : 'GOCSPX-…' ?>" <?= $gd['secConst'] ? 'disabled' : '' ?>>
                <?php if ($gd['hasSecret'] && !$gd['secConst']): ?><label class="mini"><input type="checkbox" name="gdrive_secret_clear" value="1"> 지우기</label><?php endif; ?></span></div>
            <div class="set-row wide"><label>승인된 리디렉션 URI <small>(구글 콘솔에 그대로 등록)</small></label>
                <span class="copy"><code id="gRedir"><?= snap_h($gd['redirect']) ?></code><button type="button" class="btn line sm" onclick="navigator.clipboard.writeText(document.getElementById('gRedir').textContent);this.textContent='복사됨'">복사</button></span></div>
            <h3 class="gd-sub">서버 다운로드 (서버 부담 줄이기)</h3>
            <label class="sw-row"><span><b>게스트스냅 전체 사진 zip 받기</b><small>끄면 신랑신부는 구글 드라이브로만 사진을 받아요</small></span>
                <input type="checkbox" class="sw" name="snap_server_download" value="1" <?= $gd['snapDl'] ? 'checked' : '' ?>></label>
            <label class="sw-row"><span><b>청첩장 HTML 파일 기기로 받기</b><small>끄면 "파일로 받기"는 구글 드라이브로만 보내요</small></span>
                <input type="checkbox" class="sw" name="export_server_download" value="1" <?= $gd['expDl'] ? 'checked' : '' ?>></label>
            <p class="gd-help">드라이브 연동이 꺼져 있을 때 서버 다운로드까지 끄면 고객이 사진·파일을 받을 방법이 없어요. 드라이브 연동을 켠 뒤에 끄세요.</p>
            <details class="gd-help"><summary>구글 콘솔 설정 순서</summary>
                <ol><li>console.cloud.google.com → 프로젝트 만들기 → <b>Google Drive API</b> 사용 설정</li>
                <li>OAuth 동의 화면: 외부, 앱 이름 LOVE DAY, 범위 <code>drive.file</code>, <code>openid</code>, <code>email</code></li>
                <li>사용자 인증 정보 → OAuth 클라이언트 ID (웹 애플리케이션) → 위 리디렉션 URI 등록</li>
                <li>ID와 보안 비밀번호를 여기에 넣고 저장 → 테스트 사용자로 먼저 확인 → 앱 게시(인증) 후 "사용" 켜기</li></ol></details>
            <button class="btn" type="submit">드라이브 설정 저장</button>
        </form>

        <h2 class="page-title" style="margin-top:30px;">사용 현황 · 전체 <?= snap_fmt_mb($totalBytes) ?></h2>
        <div class="panel">
            <?php if (!$stats): ?><p>아직 올라온 사진이 없습니다.</p><?php else: ?>
            <table class="stat">
                <tr><th>청첩장</th><th>코드</th><th>사진</th><th>용량</th><th>다운로드</th><th>마지막 업로드</th></tr>
                <?php foreach ($stats as $s): ?>
                <tr>
                    <td><?= snap_h(($s['groom_name'] ?? '?') . ' ♥ ' . ($s['bride_name'] ?? '?')) ?></td>
                    <td><?= $s['invitation_id'] ? '<a href="admin_edit.php?id=' . (int) $s['invitation_id'] . '">' . snap_h($s['view_slug'] ?? '-') . '</a>' : '-' ?></td>
                    <td><?= (int) $s['cnt'] ?>장</td>
                    <td><?= snap_fmt_mb((int) $s['bytes']) ?></td>
                    <td><?= (int) $s['dl'] ?>회</td>
                    <td><?= snap_h($s['last_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
