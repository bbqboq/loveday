<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';

$pdo    = get_pdo();
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);

    $settings = [
        'text'              => trim((string) ($_POST['text'] ?? '')) ?: 'LOVEDAY.KR 무료 체험 사용중입니다.',
        'width_ratio'       => max(0.1, min(1.0, (float) ($_POST['width_ratio'] ?? 0.5))),
        'step_x_ratio'      => max(0.2, min(2.0, (float) ($_POST['step_x_ratio'] ?? 0.55))),
        'step_y_multiplier' => max(1.0, min(20.0, (float) ($_POST['step_y_multiplier'] ?? 9.0))),
        'angle'             => max(-89, min(89, (int) ($_POST['angle'] ?? 30))),
        'opacity'           => max(0, min(100, (int) ($_POST['opacity'] ?? 70))),
    ];
    save_watermark_settings($pdo, $settings);
    $notice = '저장되었습니다. 지금부터 새로 업로드되는 무료체험 사진에 이 설정이 적용됩니다.';
}

$row = $pdo->query('SELECT * FROM watermark_settings WHERE id = 1')->fetch();
$s = [
    'text'              => $row['text'] ?? 'LOVEDAY.KR 무료 체험 사용중입니다.',
    'width_ratio'       => (float) ($row['width_ratio'] ?? 0.5),
    'step_x_ratio'      => (float) ($row['step_x_ratio'] ?? 0.55),
    'step_y_multiplier' => (float) ($row['step_y_multiplier'] ?? 9.0),
    'angle'             => (int) ($row['angle'] ?? 30),
    'opacity'           => (int) ($row['opacity'] ?? 70),
];
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>워터마크 설정 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.wm-layout { display: flex; gap: 28px; align-items: flex-start; flex-wrap: wrap; }
.wm-controls { flex: 1; min-width: 280px; }
.wm-preview { flex: none; }
.wm-preview img { width: 320px; max-width: 80vw; border-radius: 8px; border: 1px solid var(--line); display: block; }
.wm-row { margin-bottom: 18px; }
.wm-row label { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 6px; }
.wm-row label span.val { color: var(--accent); font-weight: 600; font-family: ui-monospace, monospace; }
.wm-row input[type="range"] { width: 100%; }
.wm-row input[type="text"] { width: 100%; box-sizing: border-box; }
.wm-hint { font-size: 11.5px; color: var(--muted); margin: 4px 0 0; }
.wm-preview img { transition: opacity .15s; }
.wm-preview img.loading { opacity: .6; }
@media (max-width: 640px) { .wm-layout { flex-direction: column-reverse; gap: 18px; } .wm-preview { align-self: center; } .wm-preview img { width: 260px; } }
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('watermark', '워터마크 설정'); ?>

    <div class="wrap">
        <h2 class="page-title">워터마크 설정</h2>
        <p style="font-size:13px;color:var(--muted);margin:-14px 0 20px;">
            값을 바꾸면 미리보기 그림이 바로 바뀝니다 (실제 업로드 때 쓰는 것과 똑같은 방식으로 그려서 보여줍니다).
            마음에 들면 아래 저장 버튼을 눌러야 실제로 반영됩니다.
        </p>

        <?php if ($notice): ?><p class="notice success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

        <div class="panel">
            <form method="post" id="wmForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="wm-layout">
                    <div class="wm-controls">
                        <div class="wm-row">
                            <label>문구</label>
                            <input type="text" name="text" id="f_text" value="<?= htmlspecialchars($s['text'], ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="wm-row">
                            <label>글자 크기 (사진 폭 대비) <span class="val" id="v_width_ratio"><?= $s['width_ratio'] ?></span></label>
                            <input type="range" name="width_ratio" id="f_width_ratio" min="0.1" max="1.0" step="0.02" value="<?= $s['width_ratio'] ?>">
                        </div>
                        <div class="wm-row">
                            <label>가로 간격 (문구 폭 대비) <span class="val" id="v_step_x_ratio"><?= $s['step_x_ratio'] ?></span></label>
                            <input type="range" name="step_x_ratio" id="f_step_x_ratio" min="0.2" max="2.0" step="0.05" value="<?= $s['step_x_ratio'] ?>">
                            <p class="wm-hint">작을수록 옆으로 촘촘하게(겹치듯) 배치됩니다.</p>
                        </div>
                        <div class="wm-row">
                            <label>줄 간격 (글자 크기 대비) <span class="val" id="v_step_y_multiplier"><?= $s['step_y_multiplier'] ?></span></label>
                            <input type="range" name="step_y_multiplier" id="f_step_y_multiplier" min="1" max="20" step="0.5" value="<?= $s['step_y_multiplier'] ?>">
                            <p class="wm-hint">작을수록 위아래로 촘촘해지지만, 너무 작으면 줄끼리 겹쳐서 지저분해집니다.</p>
                        </div>
                        <div class="wm-row">
                            <label>회전 각도 <span class="val" id="v_angle"><?= $s['angle'] ?>°</span></label>
                            <input type="range" name="angle" id="f_angle" min="-60" max="60" step="1" value="<?= $s['angle'] ?>">
                        </div>
                        <div class="wm-row">
                            <label>선명도 <span class="val" id="v_opacity"><?= $s['opacity'] ?>%</span></label>
                            <input type="range" name="opacity" id="f_opacity" min="0" max="100" step="5" value="<?= $s['opacity'] ?>">
                            <p class="wm-hint">높을수록 더 진하고 잘 보입니다.</p>
                        </div>
                        <button type="submit" class="btn">이 설정 저장하기</button>
                    </div>
                    <div class="wm-preview">
                        <img id="wmPreview" src="" alt="워터마크 미리보기">
                        <p class="wm-hint" style="text-align:center;">실제 업로드 사진과 동일한 방식으로 그려집니다</p>
                    </div>
                </div>
            </form>
        </div>
    </div>

<script>
const fields = ['text','width_ratio','step_x_ratio','step_y_multiplier','angle','opacity'];
// 슬라이더를 움직이는 동안 숫자는 바로 바꾸고, 미리보기 그림은 손을 잠깐 멈췄을 때(0.15초) 한 번만 새로 그린다
// (예전엔 슬라이더가 1칸 움직일 때마다 서버에 그림을 요청해서, 휴대폰에서 끌면 버벅였다)
const img = document.getElementById('wmPreview');
let previewTimer = 0;
function updatePreview(immediate) {
    const params = new URLSearchParams();
    fields.forEach(f => {
        const el = document.getElementById('f_' + f);
        params.set(f, el.value);
        const valEl = document.getElementById('v_' + f);
        if (valEl) valEl.textContent = el.value + (f === 'angle' ? '°' : (f === 'opacity' ? '%' : ''));
    });
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => { img.classList.add('loading'); img.src = 'admin_watermark_preview.php?' + params.toString(); }, immediate === true ? 0 : 150);
}
img.addEventListener('load', () => img.classList.remove('loading'));
img.addEventListener('error', () => img.classList.remove('loading'));
fields.forEach(f => document.getElementById('f_' + f).addEventListener('input', updatePreview));
updatePreview(true);
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
