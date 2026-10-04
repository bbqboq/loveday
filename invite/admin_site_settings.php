<?php
/**
 * admin_site_settings.php - 관리자: 사이트 정보
 * 홈페이지(index.php)의 가격표, 구매 버튼 주소, 맨 아래 사업자 정보, 샘플 카드를 여기서 정한다.
 * 값은 app_settings 테이블에 저장되고 저장 즉시 홈페이지에 반영된다.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';
require_once __DIR__ . '/admin_guard.php';

$pdo = get_pdo();
// [키, 라벨, 입력 종류, 도움말]
$groups = [
    '가격 (홈페이지 가격표)' => [
        ['price_one_year',  '1년 보관 가격', 'price', '숫자만 (예: 29000). 비우면 "문의"로 보여요.'],
        ['price_permanent', '영구 보관 가격', 'price', ''],
        ['store_url',       '구매 버튼 주소', 'url', '가격표의 [스토어에서 구매] 버튼이 여는 주소 (네이버 스마트스토어 상품 페이지 등)'],
    ],
    '사업자 정보 (홈페이지 맨 아래)' => [
        ['biz_name',      '상호', 'text', ''],
        ['biz_owner',     '대표자', 'text', ''],
        ['biz_number',    '사업자등록번호', 'text', '예: 000-00-00000'],
        ['biz_mailorder', '통신판매업 신고번호', 'text', '선택 - 온라인 판매 시 필요'],
        ['biz_address',   '사업장 주소', 'text', '선택'],
        ['biz_contact',   '고객 문의', 'text', '예: 스마트스토어 톡톡 · 010-0000-0000 · help@loveday.kr'],
        ['terms_url',     '이용약관 주소', 'url', '선택 - 있으면 맨 아래에 링크가 생겨요'],
    ],
    '개인정보처리방침 (홈페이지 맨 아래 → 팝업)' => [
        ['privacy_officer',   '개인정보 보호책임자', 'text', '이름 (비우면 대표자 이름을 씀)'],
        ['privacy_email',     '보호책임자 연락처', 'text', '이메일 또는 전화 (비우면 고객 문의 값을 씀)'],
        ['privacy_effective', '방침 시행일', 'date', '방침을 게시(또는 고친)한 날'],
    ],
    '홈페이지 샘플 디자인' => [
        ['home_sample_ids', '샘플 카드에 보일 프리셋', 'text', '프리셋 id를 쉼표로 최대 6개 (예: classic,minimal,scrapbook). 비우면 프리셋 번호 순서대로 앞의 6개'],
        ['home_sample_url', '"샘플 청첩장 직접 보기" 주소', 'text', '기본: /testbed (공용 테스트 청첩장)'],
    ],
];

$notice = ''; $error = '';
// 사이트 색상 (에디터·내 청첩장·관리자 화면의 바탕·옅은 면·칸·선) - 아래 큰 폼과 따로 저장
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ui_form'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    $preset = (string) ($_POST['ui_preset'] ?? 'warm');
    $val = ['preset' => isset(SITE_COLOR_PRESETS[$preset]) || $preset === 'custom' ? $preset : 'warm'];
    if ($val['preset'] === 'custom') foreach (array_keys(SITE_COLOR_KEYS) as $k) {
        $v = strtoupper(trim((string) ($_POST['ui_' . $k] ?? '')));
        $val[$k] = preg_match('/^#[0-9A-F]{6}$/', $v) ? $v : SITE_COLOR_PRESETS['warm'][$k];
    }
    try { save_app_setting($pdo, 'ui_colors', json_encode($val)); header('Location: admin_site_settings.php?saved=ui#ui-colors'); exit; }
    catch (Throwable $e) { $error = '색을 저장하지 못했어요.'; }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['ui_form'])) {
    csrf_verify($_POST['csrf_token'] ?? null);
    $vals = [];
    foreach ($groups as $fields) {
        foreach ($fields as [$key, $label, $type]) {
            $v = trim((string) ($_POST[$key] ?? ''));
            if ($type === 'price') {
                $v = preg_replace('/[^0-9]/', '', $v);
                if ($v !== '' && (int) $v > 10000000) { $error = "{$label}: 금액을 확인해주세요."; break 2; }
            } elseif ($type === 'date') {
                if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { $error = "{$label}: 날짜를 골라주세요."; break 2; }
            } elseif ($type === 'url') {
                if ($v !== '' && !preg_match('#^https?://#i', $v)) { $error = "{$label}: https:// 로 시작하는 주소를 넣어주세요."; break 2; }
            }
            $vals[$key] = mb_substr(strip_tags($v), 0, 300);
        }
    }
    if (!$error && $vals['home_sample_url'] !== '' && !preg_match('#^(/|https?://)#', $vals['home_sample_url'])) $error = '"샘플 청첩장 직접 보기" 주소는 / 또는 https:// 로 시작해야 해요.';
    if (!$error) {
        try {
            foreach ($vals as $k => $v) save_app_setting($pdo, $k, $v);
            header('Location: admin_site_settings.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $error = '저장하지 못했어요. 서버에서 stage4_setup.sql을 한 번 더 실행해주세요.';
        }
    }
}
if (isset($_GET['saved'])) $notice = $_GET['saved'] === 'ui' ? '사이트 색상을 저장했어요. 에디터는 5분 안에 바뀌어요(열려 있으면 새로고침).' : '저장했습니다. 홈페이지에 바로 반영됩니다.';
$uiCur = site_colors_get(); $uiPreset = $uiCur['preset'] ?? 'warm'; $uiVals = site_colors_values();
$csrf = csrf_token();
$cur = fn(string $k) => $error ? (string) ($_POST[$k] ?? '') : app_setting($k);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>사이트 정보 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
    .set-row { display: grid; grid-template-columns: 220px 1fr; gap: 14px; align-items: start; padding: 12px 0; border-bottom: 1px solid #eee; }
    .set-row label { font-size: 14px; padding-top: 8px; }
    .set-row input { width: 100%; padding: 9px 10px; box-sizing: border-box; }
    .set-row .help { font-size: 12px; color: #888; margin-top: 5px; }
    .price-wrap { display: flex; align-items: center; gap: 6px; max-width: 220px; }
    h3.grp { margin: 22px 0 4px; font-size: 15px; }
    h3.grp:first-of-type { margin-top: 0; }
    @media (max-width: 640px) { .set-row { grid-template-columns: 1fr; gap: 4px; } }
    .ui-presets { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin: 10px 0 14px; }
    .ui-p { position: relative; display: flex; flex-direction: column; gap: 8px; padding: 10px; border: 1.5px solid #e3e3e3; border-radius: 12px; cursor: pointer; background: #fff; font-size: 13px; }
    .ui-p:has(input:checked) { border-color: #1B1A18; box-shadow: 0 0 0 1px #1B1A18; }
    .ui-p input { position: absolute; opacity: 0; }
    .ui-sw { display: flex; height: 34px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(0,0,0,.08); }
    .ui-sw i { flex: 1; }
    .ui-custom[hidden] { display: none; }
    .ui-custom { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin: 6px 0 14px; }
    .ui-custom label { display: flex; align-items: center; gap: 8px; font-size: 13px; }
    .ui-custom input[type=color] { width: 44px; height: 32px; border: 1px solid #ddd; border-radius: 8px; padding: 2px; background: #fff; }
    .ui-demo { display: flex; gap: 10px; align-items: stretch; padding: 14px; border-radius: 12px; border: 1px solid var(--d-line); background: var(--d-page); font-size: 12.5px; max-width: 520px; }
    .ui-demo > div { flex: 1; border-radius: 10px; padding: 12px; background: var(--d-tint); border: 1px solid var(--d-line); display: flex; flex-direction: column; gap: 8px; }
    .ui-demo span { display: inline-block; padding: 6px 10px; border-radius: 99px; background: var(--d-soft); }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('site', '사이트 정보'); ?>
    <div class="wrap">
        <h2 class="page-title">사이트 정보</h2>
        <p style="font-size:13px;color:#888;margin:-14px 0 20px;">홈페이지(loveday.kr) 가격표·구매 버튼·맨 아래 사업자 정보·개인정보처리방침·샘플 카드에 쓰이는 값이에요. <a href="/privacy.php" target="_blank" rel="noopener">개인정보처리방침 보기 ↗</a></p>
        <?php if ($notice): ?><div class="notice success"><?= snap_h($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?= snap_h($error) ?></div><?php endif; ?>
        <form method="post" class="panel">
            <input type="hidden" name="csrf_token" value="<?= snap_h($csrf) ?>">
            <?php foreach ($groups as $title => $fields): ?>
                <h3 class="grp"><?= snap_h($title) ?></h3>
                <?php foreach ($fields as [$key, $label, $type, $help]): ?>
                <div class="set-row">
                    <label for="<?= $key ?>"><?= snap_h($label) ?></label>
                    <div>
                        <?php if ($type === 'price'): ?>
                            <span class="price-wrap"><input id="<?= $key ?>" name="<?= $key ?>" inputmode="numeric" value="<?= snap_h($cur($key)) ?>" placeholder="예: 29000"> 원</span>
                        <?php else: ?>
                            <input id="<?= $key ?>" name="<?= $key ?>" type="<?= $type === 'url' ? 'url' : ($type === 'date' ? 'date' : 'text') ?>" value="<?= snap_h($cur($key)) ?>"<?= $type === 'date' ? ' style="max-width:200px"' : '' ?>>
                        <?php endif; ?>
                        <?php if ($help): ?><div class="help"><?= snap_h($help) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <p style="margin-top:18px;"><button class="btn" type="submit">저장</button> <a href="/" target="_blank" rel="noopener" style="margin-left:10px;font-size:13px;">홈페이지 열어보기 ↗</a></p>
        </form>

        <form method="post" class="panel" id="ui-colors">
            <input type="hidden" name="csrf_token" value="<?= snap_h($csrf) ?>"><input type="hidden" name="ui_form" value="1">
            <h3 class="grp">사이트 색상 (에디터 · 내 청첩장 · 관리자 화면)</h3>
            <p class="help" style="font-size:12.5px;color:#888;margin:4px 0 0;">화면 바탕과 칸·버튼에 쓰는 베이지 색을 바꿔요. 하객이 보는 청첩장 색(디자인 색)은 바뀌지 않아요.</p>
            <div class="ui-presets">
                <?php foreach (SITE_COLOR_PRESETS + ['custom' => ['label' => '직접 고르기'] + $uiVals] as $pk => $pv): ?>
                <label class="ui-p"><input type="radio" name="ui_preset" value="<?= $pk ?>" <?= $uiPreset === $pk ? 'checked' : '' ?> data-c='<?= snap_h(json_encode(array_intersect_key($pk === 'custom' ? $uiVals : $pv, SITE_COLOR_KEYS))) ?>'>
                    <span class="ui-sw"><?php foreach (array_keys(SITE_COLOR_KEYS) as $k): ?><i style="background:<?= snap_h(($pk === 'custom' ? $uiVals : $pv)[$k]) ?>"></i><?php endforeach; ?></span><b><?= snap_h($pv['label']) ?></b></label>
                <?php endforeach; ?>
            </div>
            <div class="ui-custom" id="uiCustom"<?= $uiPreset === 'custom' ? '' : ' hidden' ?>>
                <?php foreach (SITE_COLOR_KEYS as $k => $lab): ?><label><input type="color" name="ui_<?= $k ?>" value="<?= snap_h($uiVals[$k]) ?>"><?= snap_h($lab) ?></label><?php endforeach; ?>
            </div>
            <div class="ui-demo" id="uiDemo"><div><b>미리 보기</b><span>칸·버튼</span><span>선택 칩</span></div><div>옅은 면 위의 글<br><small style="color:#888">바탕 위에 옅은 면 · 칸 · 선이 이렇게 보여요</small></div></div>
            <p style="margin-top:14px;"><button class="btn" type="submit">색 저장</button></p>
        </form>
        <script>
        (function () { // 고르는 대로 미리 보기
            const f = document.getElementById('ui-colors'), demo = document.getElementById('uiDemo'), cu = document.getElementById('uiCustom');
            const apply = () => { const r = f.querySelector('[name=ui_preset]:checked'); if (!r) return; const custom = r.value === 'custom'; cu.hidden = !custom;
                const c = custom ? Object.fromEntries([...cu.querySelectorAll('input')].map(i => [i.name.slice(3), i.value])) : JSON.parse(r.dataset.c);
                ['page', 'tint', 'soft', 'line'].forEach(k => demo.style.setProperty('--d-' + k, c[k])); };
            f.addEventListener('input', apply); f.addEventListener('change', apply); apply();
        })();
        </script>
    </div>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
