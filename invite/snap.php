<?php
/**
 * snap.php?s=공개코드 - 하객용 게스트스냅 업로드 페이지 (로그인 없음)
 * 청첩장의 "사진 올리기" 버튼이나 식장에 둔 QR코드로 들어온다.
 * 사진은 휴대폰에서 먼저 줄인 뒤(용량 절약) snap_upload.php로 한 장씩 올린다. 영상은 받지 않는다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

$pdo = get_pdo();
$slug = (string) ($_GET['s'] ?? '');
$invite = preg_match('/^[a-z0-9]{4,20}$/', $slug) ? find_invitation_by_slug($pdo, $slug) : null;
if ($invite && !invite_is_live($pdo, $invite)) $invite = null; // 기간 만료된 청첩장
if (!$invite || !snap_enabled($invite)) {
    http_response_code(404);
    exit('게스트스냅을 사용하지 않는 청첩장이거나 주소가 올바르지 않습니다.');
}

$guestKey = snap_guest_key();
$sch = snap_schedule($invite);
$usage = snap_usage($pdo, (int) $invite['id'], $guestKey);
$remaining = max(0, min($usage['quota_invite'] - $usage['total'], $usage['quota_guest'] - $usage['guest']));
$csrf = csrf_token();

// 에디터의 게스트스냅 섹션에서 적은 안내 문구
$title = '게스트스냅';
$desc = '';
foreach ((snap_design($invite)['blocks'] ?? []) as $b) {
    if (($b['id'] ?? '') === 'guestsnap') { $title = $b['fields']['title'] ?? $title; $desc = $b['fields']['desc'] ?? ''; }
}
// 청첩장 안 팝업으로 열릴 때(embed=1): 같은 사이트 안에서만 띄울 수 있게 하고, 위아래 여백을 줄인다 (닫기 ✕ 자리만 남김)
$embed = !empty($_GET['embed']);
header("Content-Security-Policy: frame-ancestors 'self'");
$names = snap_h($invite['groom_name']) . ' ♥ ' . snap_h($invite['bride_name']);
$fmt = fn(?DateTimeImmutable $d) => $d ? $d->format('n월 j일') : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= snap_h($title) ?> · <?= $names ?></title>
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #F6F3EE; color: #2B2320; font-family: "Pretendard Variable", Pretendard, -apple-system, sans-serif; }
    .wrap { max-width: 460px; margin: 0 auto; padding: 36px 20px 60px; }
    .eyebrow { font-size: 11px; letter-spacing: .25em; color: #9A8C80; text-align: center; margin: 0; }
    h1 { text-align: center; font-size: 22px; margin: 8px 0 4px; }
    .names { text-align: center; color: #7A3B41; margin: 0 0 18px; }
    .desc { background: #fff; border-radius: 14px; padding: 16px 18px; font-size: 14px; line-height: 1.7; white-space: pre-line; margin-bottom: 16px; }
    .status { border-radius: 12px; padding: 12px 14px; font-size: 13px; margin-bottom: 16px; line-height: 1.6; }
    .status.open { background: #EAF5EC; color: #2F6B3B; }
    .status.closed { background: #F7ECEC; color: #8A3B3B; }
    label.name { display: block; font-size: 13px; color: #6B5F55; margin: 0 0 6px; }
    input[type=text] { width: 100%; border: 1px solid #DDD3C8; border-radius: 10px; padding: 12px; font-size: 15px; font-family: inherit; margin-bottom: 14px; }
    .pick { display: block; width: 100%; text-align: center; background: #7A3B41; color: #fff; border: 0; border-radius: 12px; padding: 16px; font-size: 16px; font-weight: 700; cursor: pointer; font-family: inherit; }
    .pick[disabled] { background: #C9BDB2; cursor: default; }
    .meter { height: 6px; background: #E7DED5; border-radius: 3px; overflow: hidden; margin: 14px 0 6px; }
    .meter span { display: block; height: 100%; background: #7A3B41; }
    .small { font-size: 12px; color: #8C8076; text-align: center; margin: 0; }
    .list { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-top: 18px; }
    .list div { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; background: #E7DED5; }
    .list img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .list .st { position: absolute; inset: auto 0 0 0; font-size: 11px; color: #fff; background: rgba(0,0,0,.5); text-align: center; padding: 3px 0; }
    .list .st.ok { background: rgba(47,107,59,.8); }
    .list .st.err { background: rgba(138,59,59,.9); }
    .done { text-align: center; font-size: 14px; color: #2F6B3B; margin-top: 14px; display: none; }
    body.embed .wrap { padding: 30px 18px 28px; }
    body.embed h1 { font-size: 20px; }
</style>
</head>
<body<?= $embed ? ' class="embed"' : '' ?>>
<div class="wrap">
    <p class="eyebrow">GUEST SNAP</p>
    <h1><?= snap_h($title) ?></h1>
    <p class="names"><?= $names ?></p>
    <?php if ($desc): ?><div class="desc"><?= snap_h($desc) ?></div><?php endif; ?>

    <?php if ($sch['state'] === 'open'): ?>
        <div class="status open">📷 지금 사진을 올릴 수 있어요 · <?= $fmt($sch['close']->modify('-1 day')) ?>까지<br>사진만 올릴 수 있고 영상은 받지 않아요.</div>
        <label class="name" for="guestName">이름 (선택 · 신랑신부에게 누가 올렸는지 보여져요)</label>
        <input type="text" id="guestName" maxlength="20" placeholder="예: 신랑 친구 김철수">
        <input type="file" id="files" accept="image/jpeg,image/png,image/webp,image/heic,image/*" multiple hidden>
        <button type="button" class="pick" id="pickBtn" <?= $remaining <= 0 ? 'disabled' : '' ?>><?= $remaining > 0 ? '사진 고르기' : '올릴 수 있는 용량을 다 썼어요' ?></button>
        <div class="meter"><span id="meterBar" style="width:<?= $usage['quota_guest'] ? min(100, round($usage['guest'] / $usage['quota_guest'] * 100)) : 100 ?>%"></span></div>
        <p class="small" id="meterText">내가 올린 용량 <?= snap_fmt_mb($usage['guest']) ?> / <?= snap_fmt_mb($usage['quota_guest']) ?></p>
        <div class="list" id="list"></div>
        <p class="done" id="doneMsg">사진을 보내주셔서 감사합니다 💐</p>
    <?php elseif ($sch['state'] === 'before'): ?>
        <div class="status closed">아직 업로드 기간이 아니에요. <?= $fmt($sch['open']) ?>부터 올릴 수 있어요.</div>
    <?php elseif ($sch['state'] === 'nodate'): ?>
        <div class="status closed">예식일이 정해지지 않아 아직 업로드를 받을 수 없어요.</div>
    <?php else: ?>
        <div class="status closed">업로드 기간이 끝났어요. 소중한 사진 감사합니다.</div>
    <?php endif; ?>
</div>
<?php if ($sch['state'] === 'open'): ?>
<script>
(function () {
    const SLUG = <?= json_encode($slug) ?>;
    const CSRF = <?= json_encode($csrf) ?>;
    const MAX_SIDE = 2048; // 휴대폰에서 먼저 줄여서 보낸다 - 서버에서 다시 1920px webp로 저장
    const pick = document.getElementById('pickBtn'), input = document.getElementById('files'), list = document.getElementById('list');
    pick.addEventListener('click', () => input.click());
    input.addEventListener('change', async () => {
        const files = Array.from(input.files || []).filter(f => /^image\//.test(f.type) || /\.(jpe?g|png|webp|heic)$/i.test(f.name));
        input.value = '';
        if (!files.length) { LD.alert('사진만 올릴 수 있어요', { message: '영상은 받지 않아요. 사진 파일을 골라주세요.' }); return; }
        pick.disabled = true;
        for (const f of files) await uploadOne(f);
        pick.disabled = false;
        document.getElementById('doneMsg').style.display = 'block';
    });
    function shrink(file) {
        return new Promise(resolve => {
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                const r = Math.min(1, MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
                const c = document.createElement('canvas');
                c.width = Math.round(img.naturalWidth * r); c.height = Math.round(img.naturalHeight * r);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(b => resolve(b), 'image/jpeg', 0.86);
            };
            img.onerror = () => { URL.revokeObjectURL(url); resolve(null); }; // 브라우저가 못 여는 형식(일부 HEIC 등)
            img.src = url;
        });
    }
    async function uploadOne(file) {
        const cell = document.createElement('div');
        cell.innerHTML = '<span class="st">줄이는 중…</span>';
        list.prepend(cell);
        const st = cell.querySelector('.st');
        const blob = await shrink(file);
        if (!blob) { st.textContent = '열 수 없는 형식'; st.className = 'st err'; return; }
        const img = document.createElement('img'); img.src = URL.createObjectURL(blob); cell.prepend(img);
        st.textContent = '올리는 중…';
        const fd = new FormData();
        fd.append('s', SLUG); fd.append('csrf_token', CSRF);
        fd.append('guest_name', document.getElementById('guestName').value.trim());
        fd.append('photo', blob, 'photo.jpg');
        try {
            const res = await fetch('snap_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || '실패');
            st.textContent = '완료'; st.className = 'st ok';
            document.getElementById('meterBar').style.width = Math.min(100, data.guest_used / data.guest_quota * 100) + '%';
            document.getElementById('meterText').textContent = `내가 올린 용량 ${(data.guest_used / 1048576).toFixed(1)}MB / ${(data.guest_quota / 1048576).toFixed(1)}MB`;
        } catch (e) {
            st.textContent = e.message.length > 14 ? '실패' : e.message; st.className = 'st err';
            if (/용량|한도/.test(e.message)) { LD.alert(e.message, { title: '더 올릴 수 없어요' }); }
        }
    }
})();
</script>
<?php endif; ?>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
