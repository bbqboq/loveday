<?php
/**
 * admin_extra_settings.php - 관리자: 부가기능 설정
 *  - 방명록 금지어 (막기 / 가리기)
 *  - 참석 회신 명단 자동 삭제 기간
 *  - 회원가입 없이 체험하기 (보관 시간, 동시 최대 수)
 *  - 배경음악 직접 올리기 최대 용량
 *  - 편집 화면 삭제 카운트다운 표시 시작 시각
 * 게스트스냅(하객 사진) 설정은 admin_snap_settings.php.
 * 값은 app_settings 테이블에 저장되고, 저장 즉시 모든 청첩장에 적용된다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
// [키 => [라벨, 단위, 최소, 최대]] - 묶음 제목별로
$groups = [
    '참석 여부' => [
        'rsvp_retention_days' => ['예식일 며칠 후에 하객 명단(이름·연락처) 자동 삭제', '일 후', 7, 730],
    ],
    '회원가입 없이 체험하기' => [
        'demo_hours'      => ['체험 청첩장 보관 시간 (지나면 통째로 삭제)', '시간', 1, 168],
        'demo_max_active' => ['동시에 있을 수 있는 체험 청첩장 최대 수 (넘으면 잠시 후 다시 시도 안내)', '건', 10, 5000],
    ],
    '무료체험 (네이버 회원)' => [
        'trial_grace_hours' => ['무료체험 기간이 끝난 뒤 휴지통으로 옮기기까지 유예 (그동안 대시보드에 "삭제까지" 시계, 하객 주소는 바로 막힘)', '시간', 0, 168],
    ],
    '휴지통' => [
        'trash_keep_days' => ['고객이 지운 청첩장·기간이 끝난 회원 무료체험을 휴지통에 두는 기간 (지나면 사진까지 완전 삭제, 비회원 둘러보기는 휴지통 없이 바로 삭제)', '일', 1, 365],
    ],
    '배경음악' => [
        'bgm_max_mb' => ['신랑신부가 직접 올리는 음악 최대 용량 (자동으로 줄인 뒤 기준)', 'MB', 1, 30],
    ],
    '편집 화면' => [
        'editor_countdown_min' => ['무료체험·둘러보기 청첩장: 자동 삭제까지 이만큼 남으면 편집 화면 가운데에 남은 시간을 크게 표시', '분 전부터', 1, 1440],
    ],
];
$fields = array_merge(...array_values($groups));
// 관리자 메뉴에서 나눠 보여 줌: guest = 하객 화면 → 방명록·참석 / keep = 사이트 → 체험·보관 시간 / (없음 = 예전처럼 전부)
//  배경음악 최대 용량은 배경음악 → 고객이 올린 음악 화면에서 이 화면으로 보냄 (back=music)
const EXTRA_PARTS = ['guest' => ['참석 여부'], 'keep' => ['회원가입 없이 체험하기', '무료체험 (네이버 회원)', '휴지통', '편집 화면'], 'music' => ['배경음악']];
$part = isset(EXTRA_PARTS[$_GET['part'] ?? '']) ? (string) $_GET['part'] : '';
$showGroups = $part === '' ? $groups : array_intersect_key($groups, array_flip(EXTRA_PARTS[$part]));
$back = ($_POST['back'] ?? '') === 'music' ? 'music' : '';
$selfUrl = 'admin_extra_settings.php' . ($part !== '' ? '?part=' . $part : '');

$notice = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    if (($_POST['form'] ?? '') === 'zoom') {
        // ---- 하객 화면 확대 막기 (기본 꺼짐) ----
        save_app_setting($pdo, 'guest_nozoom', !empty($_POST['guest_nozoom']) ? '1' : '0');
        header('Location: admin_extra_settings.php?' . ($part !== '' ? 'part=' . $part . '&' : '') . 'saved=1#zoom');
        exit;
    } elseif (($_POST['form'] ?? '') === 'gbfilter') {
        // ---- 방명록 금지어 ----
        $words = [];
        foreach (preg_split('/[\r\n,]+/u', (string) ($_POST['gb_banned_words'] ?? '')) as $w) {
            $w = trim(preg_replace('/\s+/u', '', $w));
            if ($w !== '' && mb_strlen($w) <= 30) $words[$w] = true;
        }
        $mode = ($_POST['gb_filter_mode'] ?? '') === 'mask' ? 'mask' : 'block';
        try {
            save_app_setting($pdo, 'gb_banned_words', implode("\n", array_slice(array_keys($words), 0, 1000)));
            save_app_setting($pdo, 'gb_filter_mode', $mode);
            header('Location: admin_extra_settings.php?' . ($part !== '' ? 'part=' . $part . '&' : '') . 'saved=gb#gbfilter');
            exit;
        } catch (Throwable $e) {
            $error = '금지어를 저장하지 못했어요. 서버에서 stage4_setup.sql을 한 번 더 실행해주세요 (설정 칸을 긴 글도 들어가게 넓힘).';
        }
    } else {
        // ---- 숫자 설정 ----
        $vals = [];
        foreach ($fields as $key => [$label, $unit, $min, $max]) {
            if (!isset($_POST[$key])) continue; // 나눠 보여 준 화면 - 이 화면에 없는 칸은 그대로 둠
            $v = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) { $error = "{$label}: {$min}~{$max} 사이 숫자로 입력해주세요."; break; }
            $vals[$key] = $v;
        }
        if (!$error) {
            foreach ($vals as $k => $v) save_app_setting($pdo, $k, (string) $v);
            header('Location: ' . ($back === 'music' ? 'admin_music.php?tab=active&msg=max_saved' : 'admin_extra_settings.php?' . ($part !== '' ? 'part=' . $part . '&' : '') . 'saved=1'));
            exit;
        }
    }
}
if (isset($_GET['saved'])) $notice = '저장했습니다. 지금부터 모든 청첩장에 적용됩니다.';
$gbWords = $error && isset($_POST['gb_banned_words']) ? (string) $_POST['gb_banned_words'] : app_setting('gb_banned_words');
$gbMode = app_setting('gb_filter_mode') === 'mask' ? 'mask' : 'block';
$gbCount = count(array_filter(array_map('trim', preg_split('/[\r\n,]+/u', $gbWords))));
$cur = [];
foreach ($fields as $key => $_) $cur[$key] = ($error && isset($_POST[$key])) ? (string) $_POST[$key] : app_setting($key);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(['guest' => '방명록·참석', 'keep' => '체험·보관 시간', 'music' => '고객이 올린 음악'][$part ?? ''] ?? '부가기능', ENT_QUOTES, 'UTF-8') ?> - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .set-row { display: grid; grid-template-columns: 1fr 140px; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid #eee; }
    .set-row input { width: 90px; padding: 7px 8px; }
    .set-row .unit { font-size: 12px; color: #888; margin-left: 4px; }
    h3.grp { margin: 20px 0 2px; font-size: 14.5px; }
    h3.grp:first-of-type { margin-top: 0; }
    @media (max-width: 560px) { .set-row { grid-template-columns: 1fr; gap: 6px; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php $ttl = ['guest' => '방명록·참석', 'keep' => '체험·보관 시간', 'music' => '고객이 올린 음악'][$part] ?? '부가기능';
    require_once __DIR__ . '/admin_nav.php'; admin_topbar($part === 'keep' ? 'keep' : ($part === 'music' ? 'music_cust' : 'extra'), $ttl); ?>
    <div class="wrap">
        <h2 class="page-title"><?= snap_h($ttl) ?></h2>
        <p style="font-size:13px;color:#888;margin:-14px 0 18px;"><?php if ($part === 'guest'): ?>하객이 쓰는 방명록 금지어와 참석 회신 명단 보관 기간이에요. 체험·휴지통 보관 시간은 <a href="admin_extra_settings.php?part=keep">사이트 → 체험·보관 시간</a>에 있어요.<?php elseif ($part === 'keep'): ?>체험 청첩장·무료체험·휴지통을 얼마나 두었다가 지우는지 정해요. 방명록 금지어는 <a href="admin_extra_settings.php?part=guest">하객 화면 → 방명록·참석</a>에 있어요.<?php else: ?>방명록 금지어, 참석 회신, 체험하기, 배경음악, 편집 화면 카운트다운 설정이에요. 하객 사진 설정은 <a href="admin_snap_settings.php">게스트스냅</a>에 있어요.<?php endif; ?> 시각이 9시간씩 어긋나 보이면 <a href="admin_timezone.php">서버 시간 점검</a>.</p>
        <?php if ($notice): ?><div class="notice success"><?= snap_h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= snap_h($error) ?></div><?php endif; ?>

        <?php if ($part === '' || $part === 'guest'): ?>
        <h2 class="page-title" id="gbfilter">방명록 금지어 · <?= $gbCount ?>개</h2>
        <form method="post" class="panel" action="<?= snap_h($selfUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="gbfilter">
            <p style="font-size:13px;color:#666;margin:0 0 10px;line-height:1.7;">
                한 줄에 하나씩 (또는 쉼표로 구분) 적어주세요. 하객이 글자 사이에 띄어쓰기·숫자·특수문자를 끼워 넣어도 걸러집니다 (예: "시 1 발").<br>
                이름과 축하 메시지 모두 검사하고, 모든 청첩장의 방명록에 바로 적용됩니다. 영어는 대소문자를 가리지 않아요.
            </p>
            <textarea name="gb_banned_words" rows="10" style="width:100%;box-sizing:border-box;font-family:inherit;font-size:14px;padding:10px;border:1px solid #ddd;border-radius:8px;"><?= snap_h($gbWords) ?></textarea>
            <div style="display:flex;gap:18px;margin:12px 0 14px;font-size:14px;flex-wrap:wrap;">
                <label><input type="radio" name="gb_filter_mode" value="block" <?= $gbMode === 'block' ? 'checked' : '' ?>> <b>막기</b> - 금지어가 있으면 글을 안 받고 "고운 말로 써주세요" 안내</label>
                <label><input type="radio" name="gb_filter_mode" value="mask" <?= $gbMode === 'mask' ? 'checked' : '' ?>> <b>가리기</b> - 글은 받고 금지어만 ***로 가림</label>
            </div>
            <button class="btn" type="submit">금지어 저장</button>
        </form>

        <h2 class="page-title" id="zoom" style="margin-top:30px;">하객 화면 확대</h2>
        <form method="post" class="panel" action="<?= snap_h($selfUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="form" value="zoom">
            <label style="display:flex;gap:10px;align-items:flex-start;font-size:14px;line-height:1.6;cursor:pointer;">
                <input type="checkbox" name="guest_nozoom" value="1" <?= app_setting('guest_nozoom') === '1' ? 'checked' : '' ?> style="margin-top:4px;">
                <span><b>하객 화면 확대 막기</b><br><span style="color:#777;font-size:13px;">켜면 하객이 두 손가락으로 벌리거나 두 번 톡 눌러도 화면이 커지지 않아요. 끄면(기본) 글자가 작게 보이는 하객도 키워서 볼 수 있어요.</span></span>
            </label>
            <p style="margin:12px 0 0;"><button class="btn" type="submit">저장</button></p>
        </form>
        <?php endif; ?>

        <h2 class="page-title" style="margin-top:30px;"><?= $part === '' ? '기타 설정' : ($part === 'guest' ? '참석 여부' : '보관 시간') ?></h2>
        <form method="post" class="panel" action="<?= snap_h($selfUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php foreach ($showGroups as $title => $gf): ?>
                <h3 class="grp"><?= snap_h($title) ?></h3>
                <?php foreach ($gf as $key => [$label, $unit, $min, $max]): ?>
                <div class="set-row">
                    <label for="<?= $key ?>"><?= snap_h($label) ?></label>
                    <span><input type="number" id="<?= $key ?>" name="<?= $key ?>" min="<?= $min ?>" max="<?= $max ?>" value="<?= snap_h($cur[$key]) ?>" required><span class="unit"><?= snap_h($unit) ?></span></span>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <p style="margin-top:14px;"><button class="btn" type="submit">저장</button></p>
        </form>
    </div>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
