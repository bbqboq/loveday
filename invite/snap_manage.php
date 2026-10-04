<?php
/**
 * snap_manage.php?t=편집토큰 - 신랑신부용 게스트스냅 관리 페이지
 * 하객이 올린 사진 보기 · 삭제 · 전체 다운로드(zip, 횟수 제한) · 하객에게 줄 주소/QR코드
 * 에디터의 게스트스냅 섹션 편집창에 있는 "올라온 사진 관리" 버튼으로 들어온다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/gdrive.php';

$pdo = get_pdo();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$invite = snap_owner_invite($pdo, $token);
if (!$invite) {
    http_response_code(403);
    exit('편집 링크가 올바르지 않거나 PIN 확인이 필요합니다. 청첩장 편집 화면에서 다시 들어와주세요.');
}
$invitationId = (int) $invite['id'];
$sch = snap_schedule($invite);

// 보관 기간이 지났으면 여기서도 바로 지운다 (크론이 아직 안 돌았어도 기간 지난 사진은 안 보이게)
if ($sch['state'] === 'expired') snap_delete_all($pdo, $invitationId);

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if (isset($_POST['delete_ids'])) {
        $ids = array_map('intval', (array) $_POST['delete_ids']);
        $st = $pdo->prepare('SELECT id, file_name FROM guest_snaps WHERE invitation_id = ? AND id = ?');
        $del = $pdo->prepare('DELETE FROM guest_snaps WHERE id = ?');
        $n = 0;
        foreach ($ids as $id) {
            $st->execute([$invitationId, $id]);
            if ($row = $st->fetch()) { @unlink(snap_dir($invitationId) . $row['file_name']); $del->execute([$row['id']]); $n++; }
        }
        $notice = $n . '장 삭제했어요.';
    }
    if (isset($_POST['gd_push']) && gdrive_on()) {
        @set_time_limit(300);
        try { $n = gdrive_push_snaps($pdo, $invite, null, 300); $notice = $n ? $n . '장을 구글 드라이브로 보냈어요.' : '드라이브로 보낼 새 사진이 없어요.'; }
        catch (Throwable $e) { $gdErr = $e instanceof RuntimeException ? $e->getMessage() : '드라이브로 보내지 못했어요.'; }
    }
}
$gdOn = gdrive_on();
$gdLink = $gdOn ? gdrive_link($pdo, $invitationId) : null;
$gdSent = $gdLink ? gdrive_sent_count($pdo, $invitationId) : 0;
$srvDl = snap_server_download_on();
$gdMsg = ['ok' => '구글 드라이브를 연결했어요.' . (!empty($_GET['n']) ? ' 지금까지 올라온 사진 ' . (int) $_GET['n'] . '장도 보냈어요.' : ''), 'off' => '구글 드라이브 연결을 해제했어요.'][$_GET['gd'] ?? ''] ?? '';
if ($gdMsg && !$notice) $notice = $gdMsg;
if (($_GET['gd'] ?? '') === 'err') $gdErr = mb_substr((string) ($_GET['m'] ?? '연결하지 못했어요.'), 0, 120);
$gdErr = $gdErr ?? ($gdLink['last_error'] ?? '');

$usage = snap_usage($pdo, $invitationId);
$photos = $pdo->prepare('SELECT id, guest_name, bytes, created_at FROM guest_snaps WHERE invitation_id = ? ORDER BY created_at DESC');
$photos->execute([$invitationId]);
$photos = $photos->fetchAll();
$downloads = snap_download_count($pdo, $invitationId);
$dlLimit = app_setting_int('snap_download_limit');
$enabled = snap_enabled($invite);
$guestUrl = 'https://loveday.kr/invite/snap.php?s=' . rawurlencode($invite['view_slug']);
$csrf = csrf_token();
$fmt = fn(?DateTimeImmutable $d) => $d ? $d->format('Y.m.d') : '-';
$pct = $usage['quota_invite'] ? min(100, round($usage['total'] / $usage['quota_invite'] * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>게스트스냅 관리</title>
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #F6F5F1; color: #232320; font-family: "Pretendard Variable", Pretendard, -apple-system, sans-serif; font-size: 14px; }
    .top { background: #fff; border-bottom: 1px solid #E4E1D9; padding: 14px 20px; display: flex; align-items: center; gap: 10px; }
    .top a { color: #3D4F66; text-decoration: none; font-size: 13px; }
    .wrap { max-width: 980px; margin: 0 auto; padding: 24px 20px 60px; }
    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .card { background: #fff; border: 1px solid #E4E1D9; border-radius: 12px; padding: 16px; }
    .card h3 { margin: 0 0 8px; font-size: 13px; color: #6B675F; font-weight: 600; }
    .big { font-size: 22px; font-weight: 700; }
    .meter { height: 6px; background: #EEE; border-radius: 3px; overflow: hidden; margin-top: 8px; }
    .meter span { display: block; height: 100%; background: #3D4F66; }
    .btn { display: inline-block; border: 1px solid #3D4F66; background: #3D4F66; color: #fff; border-radius: 999px; padding: 9px 16px; font-size: 13px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn.line { background: #fff; color: #3D4F66; }
    .btn.danger { background: #fff; color: #B03A3A; border-color: #B03A3A; }
    .btn[disabled] { opacity: .45; cursor: default; }
    .notice { background: #EAF5EC; color: #2F6B3B; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; }
    .warn { background: #FFF6E5; color: #8A5A00; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
    .ph { position: relative; background: #fff; border: 1px solid #E4E1D9; border-radius: 10px; overflow: hidden; }
    .ph img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; background: #EEE; cursor: zoom-in; }
    .ph .meta { font-size: 11px; color: #6B675F; padding: 6px 8px; display: flex; justify-content: space-between; gap: 6px; }
    .ph input { position: absolute; top: 8px; left: 8px; width: 18px; height: 18px; }
    .qr { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; }
    #qr { width: 120px; height: 120px; background: #fff; }
    .url { font-size: 12px; word-break: break-all; color: #3D4F66; }
    .toolbar { display: flex; gap: 8px; align-items: center; margin: 18px 0 10px; flex-wrap: wrap; }
    .gd { margin-bottom: 12px; }
    .gd-head { display: flex; align-items: center; gap: 8px; }
    .gd-txt { margin: 10px 0 12px; font-size: 13px; line-height: 1.6; color: #45423C; }
    .gd-note { margin: 8px 0 0; font-size: 11.5px; color: #8A867D; }
    .notice.err { background: #FBECEC; color: #9A3030; }
    .lb { position: fixed; inset: 0; background: rgba(0,0,0,.9); display: none; align-items: center; justify-content: center; z-index: 9; }
    .lb img { max-width: 94vw; max-height: 90vh; }
</style>
</head>
<body>
<div class="top">
    <strong>📷 게스트스냅 관리</strong>
    <span style="flex:1"></span>
    <a href="dashboard.php?t=<?= snap_h($token) ?>">← 내 청첩장으로</a>
</div>
<div class="wrap">
    <?php if ($notice): ?><div class="notice"><?= snap_h($notice) ?></div><?php endif; ?>
    <?php if (!$enabled): ?><div class="warn">에디터에서 <b>게스트스냅 섹션이 꺼져 있어</b> 하객이 사진을 올릴 수 없어요. 섹션 목록에서 켜고 저장해주세요.</div><?php endif; ?>
    <?php if ($sch['state'] === 'nodate'): ?><div class="warn">예식일이 정해지지 않아 업로드 기간을 계산할 수 없어요. 에디터의 기본 정보(또는 디데이 섹션)에서 예식일을 넣어주세요.</div><?php endif; ?>

    <div class="cards">
        <div class="card"><h3>올라온 사진</h3><div class="big"><?= count($photos) ?>장</div>
            <div class="meter"><span style="width:<?= $pct ?>%"></span></div>
            <p style="margin:6px 0 0;font-size:12px;color:#6B675F;"><?= snap_fmt_mb($usage['total']) ?> / <?= snap_fmt_mb($usage['quota_invite']) ?> 사용</p></div>
        <div class="card"><h3>일정</h3>
            <div>업로드: <?= $fmt($sch['open'] ?? null) ?> ~ <?= isset($sch['close']) ? $fmt($sch['close']->modify('-1 day')) : '-' ?></div>
            <div style="margin-top:4px;color:#B03A3A;">자동 삭제: <?= isset($sch['delete']) ? $fmt($sch['delete']->modify('-1 day')) . ' 이후' : '-' ?></div>
            <p style="margin:6px 0 0;font-size:12px;color:#6B675F;"><?= $gdLink ? '사진은 연결한 구글 드라이브에도 저장돼요.' : ($srvDl ? '삭제 전에 꼭 전체 다운로드 해주세요.' : '삭제 전에 구글 드라이브를 연결해 사진을 옮겨두세요.') ?></p></div>
        <?php if ($srvDl): ?>
        <div class="card"><h3>전체 다운로드 (zip)</h3>
            <div class="big"><?= max(0, $dlLimit - $downloads) ?>회 남음</div>
            <form method="post" action="snap_download.php" style="margin-top:8px;" onsubmit="return confirm('전체 다운로드는 <?= $dlLimit ?>회까지만 가능해요. 지금 받을까요? (남은 횟수가 1회 줄어듭니다)');">
                <input type="hidden" name="t" value="<?= snap_h($token) ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button class="btn" <?= (!$photos || $downloads >= $dlLimit) ? 'disabled' : '' ?>>전체 사진 받기</button>
            </form></div>
        <?php endif; ?>
    </div>


    <?php if ($gdOn): ?>
    <div class="card gd">
        <div class="gd-head">
            <svg width="22" height="20" viewBox="0 0 87 78" aria-hidden="true"><path fill="#0066DA" d="m6.6 66.85 3.85 6.65c.8 1.4 1.95 2.5 3.3 3.3L27.5 53H0c0 1.55.4 3.1 1.2 4.5z"/><path fill="#00AC47" d="M43.65 25 29.9 1.2c-1.35.8-2.5 1.9-3.3 3.3L1.2 48.5A9 9 0 0 0 0 53h27.5z"/><path fill="#EA4335" d="M73.55 76.8c1.35-.8 2.5-1.9 3.3-3.3l1.6-2.75 7.65-13.25c.8-1.4 1.2-2.95 1.2-4.5H59.8l5.85 11.5z"/><path fill="#00832D" d="M43.65 25 57.4 1.2C56.05.4 54.5 0 52.9 0H34.4c-1.6 0-3.15.45-4.5 1.2z"/><path fill="#2684FC" d="M59.8 53H27.5L13.75 76.8c1.35.8 2.9 1.2 4.5 1.2h50.8c1.6 0 3.15-.45 4.5-1.2z"/><path fill="#FFBA00" d="M73.4 26.5 60.7 4.5c-.8-1.4-1.95-2.5-3.3-3.3L43.65 25 59.8 53h27.45c0-1.55-.4-3.1-1.2-4.5z"/></svg>
            <h3 style="margin:0;">구글 드라이브로 사진 받기</h3>
        </div>
        <?php if ($gdErr): ?><div class="warn" style="margin:10px 0 0;"><?= snap_h($gdErr) ?></div><?php endif; ?>
        <?php if (!$gdLink): ?>
            <p class="gd-txt">내 구글 드라이브를 연결하면 하객이 올린 사진이 <b>드라이브 폴더로 바로 저장</b>돼요. 원본을 횟수 제한 없이 드라이브에서 받을 수 있고, 자동 삭제 기간이 지나도 드라이브에는 남아요.</p>
            <a class="btn" href="gdrive_connect.php?t=<?= snap_h($token) ?>">구글 드라이브 연결하기</a>
            <p class="gd-note">LOVE DAY가 만든 폴더와 파일에만 접근해요. 드라이브의 다른 파일은 볼 수 없어요.</p>
        <?php else: ?>
            <p class="gd-txt">연결된 계정 <b><?= snap_h($gdLink['email'] ?: '구글 계정') ?></b> · 보낸 사진 <b><?= $gdSent ?></b> / <?= count($photos) ?>장</p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <?php if (!empty($gdLink['folder_url'])): ?><a class="btn" href="<?= snap_h($gdLink['folder_url']) ?>" target="_blank" rel="noopener">드라이브 폴더 열기</a><?php endif; ?>
                <form method="post" style="margin:0;"><input type="hidden" name="t" value="<?= snap_h($token) ?>"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button class="btn line" name="gd_push" value="1" <?= $gdSent >= count($photos) ? 'disabled' : '' ?>>안 간 사진 보내기</button></form>
                <form method="post" action="gdrive_connect.php" style="margin:0;" onsubmit="return confirm('구글 드라이브 연결을 해제할까요? 이미 드라이브에 저장된 사진은 그대로 남아요.');"><input type="hidden" name="t" value="<?= snap_h($token) ?>"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button class="btn line" name="off" value="1">연결 해제</button></form>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="card">
        <h3>하객에게 알려줄 주소</h3>
        <div class="qr">
            <div id="qr"></div>
            <div style="flex:1;min-width:200px;">
                <div class="url" id="guestUrl"><?= snap_h($guestUrl) ?></div>
                <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
                    <button type="button" class="btn line" id="copyUrl">주소 복사</button>
                    <button type="button" class="btn line" id="saveQr">QR 이미지 저장</button>
                </div>
                <p style="font-size:12px;color:#6B675F;margin:8px 0 0;">QR코드를 출력해서 식장 테이블에 두면 하객이 바로 사진을 올릴 수 있어요. 청첩장 안의 "사진 올리기" 버튼도 같은 주소예요.</p>
            </div>
        </div>
    </div>

    <form method="post" id="delForm">
        <input type="hidden" name="t" value="<?= snap_h($token) ?>">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="toolbar">
            <strong>사진 (<?= count($photos) ?>)</strong>
            <span style="flex:1"></span>
            <label style="font-size:13px;"><input type="checkbox" id="checkAll"> 전체 선택</label>
            <button class="btn danger" onclick="return confirm('선택한 사진을 삭제할까요? 되돌릴 수 없어요.');">선택 삭제</button>
        </div>
        <?php if (!$photos): ?>
            <p style="color:#6B675F;">아직 올라온 사진이 없어요.</p>
        <?php else: ?>
        <div class="grid">
            <?php foreach ($photos as $p): ?>
            <div class="ph">
                <input type="checkbox" name="delete_ids[]" value="<?= (int) $p['id'] ?>">
                <img loading="lazy" src="snap_image.php?t=<?= snap_h($token) ?>&id=<?= (int) $p['id'] ?>" alt="">
                <div class="meta"><span><?= snap_h($p['guest_name'] ?: '이름 없음') ?></span><span><?= date('n/j H:i', strtotime($p['created_at'])) ?></span></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
<div class="lb" id="lb"><img alt=""></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    const url = document.getElementById('guestUrl').textContent.trim();
    try { new QRCode(document.getElementById('qr'), { text: url, width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M }); } catch (e) {}
    document.getElementById('copyUrl').onclick = () => LD.copy(url, '주소를 복사했어요');
    document.getElementById('saveQr').onclick = () => {
        const big = document.createElement('div');
        new QRCode(big, { text: url, width: 800, height: 800, correctLevel: QRCode.CorrectLevel.M });
        setTimeout(() => { const c = big.querySelector('canvas'); if (!c) return; const a = document.createElement('a'); a.href = c.toDataURL('image/png'); a.download = 'guestsnap-qr.png'; a.click(); }, 100);
    };
    const all = document.getElementById('checkAll');
    if (all) all.onchange = () => document.querySelectorAll('input[name="delete_ids[]"]').forEach(c => c.checked = all.checked);
    const lb = document.getElementById('lb');
    document.querySelectorAll('.ph img').forEach(img => img.onclick = () => { lb.querySelector('img').src = img.src; lb.style.display = 'flex'; });
    lb.onclick = () => lb.style.display = 'none';
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
