<?php
/**
 * invite_view.php - 하객이 보는 청첩장 (loveday.kr/코드)
 *
 * 개인정보 보호(봇 수집 방지): 계좌번호·연락처(신랑신부·혼주 전화번호)는 HTML에 넣지 않는다.
 *  - 계좌는 저장할 때부터 가려져(•) 있고, 연락처는 여기서 표시(__LD_SECURE__)로 바꿔 내려보낸다
 *  - 하객이 화면을 실제로 만지면(스크롤·터치·클릭) guest_secure.php에서 받아 채운다 (render-invite.js bindGuestSecure)
 *  - 검색엔진에 청첩장이 검색되지 않게 noindex (카카오톡·문자 링크 미리보기는 그대로 됨)
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php'; // functions.php + 계좌·연락처 보호(guest_*)

// 만에 하나 design_json에 이상한 값이 들어가더라도, 브라우저 차원에서 지정한 도메인 외에는
// iframe으로 못 띄우게 막는 심층방어. 지금은 유튜브(무쿠키 도메인)만 허용한다.
header("Content-Security-Policy: frame-src 'self' https://www.youtube-nocookie.com;");

$pdo = get_pdo();

header('X-Robots-Tag: noindex, nofollow, noarchive'); // 청첩장은 검색엔진에 올라가지 않게 (이름·예식장·날짜 수집 방지)

// 소유자 전용 미리보기 통로 - 발행(published) 여부와 무관하게 edit_token만 맞으면 볼 수 있다.
// edit_token은 64자 랜덤값이라 추측 불가능하므로 이 용도로 안전하게 쓸 수 있다.
$previewToken = (string) ($_GET['preview_t'] ?? '');
if ($previewToken !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $previewToken)) {
        http_response_code(404);
        exit('잘못된 접근입니다.');
    }
    $invite = find_invitation_by_token($pdo, $previewToken);
    if (!$invite) {
        http_response_code(404);
        exit('유효하지 않은 링크입니다.');
    }
    if (invite_owner_locked($pdo, $invite)) invite_owner_lock_fail($invite); // 기간이 끝나면 주인 미리보기도 잠금 (결제하면 풀림)
} else {
    $slug = (string) ($_GET['s'] ?? '');
    if (!preg_match('/^[a-z0-9]{4,20}$/', $slug)) {
        http_response_code(404);
        exit('페이지를 찾을 수 없습니다.');
    }
    $invite = find_invitation_by_slug($pdo, $slug);
    if (!$invite) {
        http_response_code(404);
        exit('청첩장을 찾을 수 없거나 아직 공개되지 않았습니다.');
    }
    // 보관 기간이 끝났거나 삭제된 청첩장은 짧은 주소로 열리지 않게 막는다 (410 = 없어진 페이지 → 검색엔진·미리보기에서도 빠짐)
    // 신랑신부 본인은 내 청첩장(대시보드)의 미리보기(편집 토큰 통로)로는 계속 볼 수 있다.
    if (!invite_is_live($pdo, $invite)) {
        http_response_code(410);
        header('Cache-Control: no-store');
        ?><!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow"><title>보관 기간이 끝난 청첩장</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
        <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F6F4F1;font-family:"Pretendard Variable",Pretendard,-apple-system,sans-serif;color:#1B1A18;padding:24px;box-sizing:border-box;word-break:keep-all}
        .b{background:#fff;border:1px solid #EAE6E0;border-radius:22px;padding:36px 28px;max-width:360px;text-align:center;line-height:1.7}.i{font-size:34px}h1{margin:8px 0 8px;font-size:19px}p{margin:0 0 22px;color:#6F6A63;font-size:14px}
        a{display:inline-block;padding:12px 20px;border-radius:12px;background:#1B1A18;color:#fff;text-decoration:none;font-weight:600;font-size:14px}</style></head>
        <body><div class="b"><div class="i">💌</div><h1>보관 기간이 끝난 청첩장이에요</h1><p>이 청첩장은 더 이상 볼 수 없어요.<br>신랑·신부님이라면 내 청첩장에서 확인해 주세요.</p><a href="/">LOVE DAY 홈으로</a></div></body></html><?php
        exit;
    }
}

$slug = $invite['view_slug'];
// 계좌·연락처를 받아갈 때 필요한 "페이지 입장권" (이 브라우저 쿠키와 짝, 12시간). HTML을 내보내기 전에 만들어야 쿠키가 붙는다
$secureOpts = [
    'url'  => '/invite/guest_secure.php',
    's'    => $slug,
    't'    => $previewToken,
    'k'    => $previewToken === '' ? guest_page_token((int) $invite['id']) : '',
];

$title = htmlspecialchars($invite['groom_name'], ENT_QUOTES, 'UTF-8') . ' ♥ '
       . htmlspecialchars($invite['bride_name'], ENT_QUOTES, 'UTF-8') . ' 결혼합니다';

// design_json은 에디터(editor-prototype-v3-overlay.html)로 만든 경우에만 존재.
// 계좌정보는 invite_save.php 단계에서 이미 마스킹(•)되어 저장되어 있으므로
// 그대로 페이지에 내려보내도 평문 노출이 없다.
$design = null;
if ($invite['design_json']) {
    $decoded = json_decode($invite['design_json'], true);
    if (is_array($decoded)) {
        $design = guest_secure_strip($decoded); // 연락처 전화번호는 표시로 바꿔서 내려보냄 (실제 번호는 guest_secure.php)
        // 관리자 → 섹션 순서에서 "비노출"로 끈 섹션은 이미 만든 청첩장에서도 안 보이게
        require_once __DIR__ . '/section_defaults.php';
        $hiddenSecs = section_defaults_hidden();
        if ($hiddenSecs && is_array($design['blocks'] ?? null)) {
            foreach ($design['blocks'] as &$hb) if (is_array($hb) && in_array($hb['id'] ?? '', $hiddenSecs, true)) $hb['enabled'] = false;
            unset($hb);
        }
    }
}

if ($design):

// 참석여부·방명록 전송용 CSRF 토큰, D-DAY 하객 안내용 예식일(한국 날짜). 예식일은 주문 정보 → 디데이 섹션 순서로 찾는다.
$weddingYmd = '';
try {
    require_once __DIR__ . '/snap_functions.php';
    $wd = snap_wedding_date($invite);
    if ($wd) $weddingYmd = $wd->format('Y-m-d');
} catch (Throwable $e) { /* 날짜를 못 구해도 페이지는 그대로 */ }
$csrfForGuests = csrf_token();

// ---- 링크 미리보기(URL 공유 스타일) - 카카오톡/문자/SNS에 주소를 붙여넣었을 때 보이는 제목·설명·사진 ----
// 에디터 "화면 설정 → 공유"에서 정한 값(design.share)을 쓰고, 비어 있으면 카카오톡 공유 값 → 기본값 순서로 채운다.
// 미리보기 봇은 자바스크립트를 실행하지 않으므로 반드시 서버(여기)에서 meta 태그로 내려줘야 한다.
$share = is_array($design['share'] ?? null) ? $design['share'] : [];
$pick = function (...$vals) { foreach ($vals as $v) { if (is_string($v) && trim($v) !== '') return trim($v); } return ''; };
$absUrl = function (string $u): string {
    if ($u === '') return '';
    if (preg_match('#^https?://#', $u)) return $u;
    if (str_starts_with($u, '/invite/')) return 'https://loveday.kr' . $u;
    return 'https://loveday.kr/invite/' . ltrim(preg_replace('#^/?invite/#', '', $u), '/');
};
// 썸네일을 따로 안 올렸으면 대표사진(히어로) → 갤러리 첫 번째 사진 순서로 쓴다 (실제 고르기·jpg 변환은 share_thumb.php)
$heroImg = '';
foreach (($design['blocks'] ?? []) as $b) {
    if (($b['id'] ?? '') === 'hero' && preg_match('#uploads/\d+/[a-f0-9]{32}\.webp$#', (string) ($b['fields']['heroImage'] ?? ''))) { $heroImg = (string) $b['fields']['heroImage']; break; }
}
if ($heroImg === '') {
    foreach (($design['blocks'] ?? []) as $b) {
        if (($b['id'] ?? '') !== 'gallery') continue;
        foreach ((array) ($b['fields']['images'] ?? []) as $img) {
            $src = is_array($img) ? (string) ($img['src'] ?? '') : (string) $img;
            if (preg_match('#uploads/\d+/[a-f0-9]{32}\.webp$#', $src)) { $heroImg = $src; break 2; }
        }
    }
}
$ogTitle = $pick($share['kakaoTitle'] ?? '', $share['ogTitle'] ?? '', // (에디터에서 링크 공유 칸을 빼고 카카오 값 하나로 - 예전 값은 비었을 때만)
    $invite['groom_name'] . ' ♥ ' . $invite['bride_name'] . ' 결혼합니다');
$ogDesc  = $pick($share['kakaoDesc'] ?? '', $share['ogDesc'] ?? '',
    ($invite['wedding_datetime'] ? date('Y년 n월 j일', strtotime($invite['wedding_datetime'])) . ' ' : '') . ($invite['venue_name'] ?? '') ?: '모바일 청첩장');
$ogImage = $pick($share['kakaoThumb'] ?? '', $share['ogImage'] ?? '', $heroImg) !== ''
    ? 'https://loveday.kr/invite/share_thumb.php?s=' . rawurlencode($slug) . '&k=og'  // webp → jpg 변환 (카카오 미리보기 호환)
    : '';
$ogUrl   = 'https://loveday.kr/' . $slug;
?>
<?php
// 에디터의 fontOptions와 동기화 - customFont/인트로 폰트가 설정돼 있으면 해당 구글폰트 CSS를 미리 불러온다
$fontCssUrls = [
    'noto-serif-kr'  => 'https://fonts.googleapis.com/css2?family=Noto+Serif+KR:wght@400;500;700&display=swap',
    'gowun-batang'   => 'https://fonts.googleapis.com/css2?family=Gowun+Batang&display=swap',
    'nanum-myeongjo' => 'https://fonts.googleapis.com/css2?family=Nanum+Myeongjo:wght@400;700;800&display=swap',
    'gothic-a1'      => 'https://fonts.googleapis.com/css2?family=Gothic+A1:wght@400;500;700;900&display=swap',
    'song-myung'     => 'https://fonts.googleapis.com/css2?family=Song+Myung&display=swap',
    'nanum-pen'      => 'https://fonts.googleapis.com/css2?family=Nanum+Pen+Script&display=swap',
    'nanum-brush'    => 'https://fonts.googleapis.com/css2?family=Nanum+Brush+Script&display=swap',
    'gaegu'          => 'https://fonts.googleapis.com/css2?family=Gaegu:wght@400;700&display=swap',
    'hi-melody'      => 'https://fonts.googleapis.com/css2?family=Hi+Melody&display=swap',
    'gamja-flower'   => 'https://fonts.googleapis.com/css2?family=Gamja+Flower&display=swap',
];
$neededFontIds = array_filter([$design['customFont'] ?? '', ($design['intro'] ?? [])['font'] ?? '']);
// 섹션 문구·자유 텍스트마다 고른 글꼴(layout/layers/customTexts의 "font")도 미리 불러옴
if (is_array($design)) array_walk_recursive($design, function ($v, $k) use (&$neededFontIds, $fontCssUrls) {
    if ($k === 'font' && is_string($v) && isset($fontCssUrls[$v])) $neededFontIds[] = $v;
});
$neededFontIds = array_unique($neededFontIds);
$customFontUrls = array_values(array_filter(array_map(fn($id) => $fontCssUrls[$id] ?? null, $neededFontIds)));
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<meta property="og:type" content="website">
<meta property="og:url" content="<?= htmlspecialchars($ogUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:title" content="<?= htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
<meta name="description" content="<?= htmlspecialchars($ogDesc, ENT_QUOTES, 'UTF-8') ?>">
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow, noarchive">
<meta name="format-detection" content="telephone=no">
<link rel="stylesheet" href="/invite/assets/render-invite.css?v=<?= @filemtime(__DIR__ . '/assets/render-invite.css') ?: time() ?>">
<?php
// 유튜브 히어로 "레터박스 맞춤"(영상 속 검은 띠 잘라내기) - 스크립트가 캐시돼 있어도 적용되게 서버에서 CSS로도 넣음
$lbZoom = 0;
foreach ((array) ($design['blocks'] ?? []) as $b) {
    if (($b['id'] ?? '') === 'heroVideo' && !empty($b['enabled'])) { $z = (float) ($b['fields']['videoLb'] ?? 0); if ($z > 100) $lbZoom = min(160, $z); }
}
if ($lbZoom > 100): ?>
<style>.col[data-block-id="heroVideo"] .video-cover-wrap iframe[data-cover] { transform: scale(<?= round($lbZoom / 100, 3) ?>); }</style>
<?php endif; ?>
<!-- 에디터와 같이 쓰는 공용 섹션(연락하기/프로필/손편지/영상/교통수단/안내문/함께한 시간/엔딩) -->
<link rel="stylesheet" href="/invite/assets/invite-blocks.css?v=<?= @filemtime(__DIR__ . '/assets/invite-blocks.css') ?: time() ?>">
<?php foreach ($customFontUrls as $url): ?><link rel="stylesheet" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach; ?>
</head>
<body>
<div class="invite-frame" id="inviteRoot"></div>

<script src="/invite/assets/invite-blocks.js?v=<?= @filemtime(__DIR__ . '/assets/invite-blocks.js') ?: time() ?>"></script>
<script src="/invite/assets/render-invite.js?v=<?= @filemtime(__DIR__ . '/assets/render-invite.js') ?: time() ?>"></script>
<script>
    window.INVITE_SLUG = <?= json_encode($slug) ?>; // 게스트스냅 "사진 올리기" 버튼, 참석여부·방명록 전송 주소용
    window.INVITE_PREVIEW_T = <?= json_encode($previewToken) ?>; // 신랑신부 미리보기일 때만 - 신혼여행 라이브의 공개 전 소식까지 보여줌
    window.INVITE_CSRF = <?= json_encode($csrfForGuests) ?>; // 참석여부·방명록 전송 시 확인용
    window.INVITE_WEDDING_DATE = <?= json_encode($weddingYmd) ?>; // D-DAY 하객 안내 - 이 날짜(한국 시간)에만 보임
    window.INVITE_SHARE_THUMB = <?= json_encode($ogImage !== '' ? 'https://loveday.kr/invite/share_thumb.php?s=' . rawurlencode($slug) . '&k=kakao&v=' . substr(md5(json_encode([$share['kakaoThumb'] ?? '', $share['kakaoRatio'] ?? '', $share['kakaoFocusX'] ?? 50, $share['kakaoFocusY'] ?? 50, $share['kakaoZoom'] ?? 100])), 0, 8) : '') ?>; // 카카오톡 공유 썸네일(jpg)
    const design = <?= json_encode($design, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const root = document.getElementById('inviteRoot');
    renderInviteReadOnly(root, design, true);
    bindGuestSecure(root, <?= json_encode($secureOpts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>); // 계좌·연락처는 하객이 화면을 만진 뒤에 받아옴
</script>
</body>
</html>
<?php
return;
endif;

/* ── 아래는 하위 호환용: 에디터로 아직 한 번도 저장하지 않은(옛날 방식) 청첩장 ── */
$photos = $pdo->prepare('SELECT file_path FROM invitation_photos WHERE invitation_id = ? ORDER BY sort_order');
$photos->execute([$invite['id']]);
$photoList = $photos->fetchAll();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex, nofollow, noarchive">
<link rel="stylesheet" href="assets/invite.css">
</head>
<body>
    <div class="wrap">
        <div class="hero">
            <p class="names">
                <?= htmlspecialchars($invite['groom_name'], ENT_QUOTES, 'UTF-8') ?><span class="heart">♥</span><?= htmlspecialchars($invite['bride_name'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php if ($invite['wedding_datetime']): ?>
                <p class="datetime"><?= date('Y년 n월 j일 (D) A g:i', strtotime($invite['wedding_datetime'])) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($invite['venue_name']): ?>
        <div class="section">
            <h3>예식장</h3>
            <p class="venue-name"><?= htmlspecialchars($invite['venue_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="venue-address"><?= htmlspecialchars((string)$invite['venue_address'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <?php endif; ?>

        <?php if ($photoList): ?>
        <div class="section">
            <div class="gallery">
                <?php foreach ($photoList as $p): ?>
                    <img src="/invite/uploads/<?= (int) $invite['id'] ?>/<?= htmlspecialchars($p['file_path'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="section gift-action">
            <h3>마음 전하실 곳</h3>
            <button class="btn-reveal" onclick="loadAccount()">계좌번호 보기</button>
            <div id="account-box" class="account-box"></div>
        </div>
    </div>

    <script>
    // 계좌번호는 버튼을 누른 뒤에만 받아온다 (guest_secure.php - 봇 수집 방지)
    const SECURE = <?= json_encode($secureOpts, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
    async function loadAccount() {
        const body = new URLSearchParams({ s: SECURE.s, t: SECURE.t, k: SECURE.k });
        const res = await fetch(SECURE.url, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-LD-Guest': '1' } });
        if (!res.ok) { document.getElementById('account-box').innerText = res.status === 429 ? '잠시 후 다시 눌러주세요' : '불러오기 실패 - 새로고침 후 다시 눌러주세요'; return; }
        const data = (await res.json()).accounts || {};
        const box = document.getElementById('account-box');
        box.innerHTML = `
            <div class="account-card">
                <p class="who">신랑측</p>
                <p class="detail">${data.groom_bank ?? ''} ${data.groom_account ?? ''} (${data.groom_holder ?? ''})</p>
            </div>
            <div class="account-card">
                <p class="who">신부측</p>
                <p class="detail">${data.bride_bank ?? ''} ${data.bride_account ?? ''} (${data.bride_holder ?? ''})</p>
            </div>
        `;
    }
    </script>
</body>
</html>
