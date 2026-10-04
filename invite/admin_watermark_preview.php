<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php';

// 미리보기용 배경(고정된 그라디언트 - 실제 사진 없이도 어떤 사진에나 비슷하게 보이는지 확인용)
$w = 700;
$h = 900;
$img = imagecreatetruecolor($w, $h);

// 하늘색→살구색 그라디언트로 채워서, 밝은 배경/어두운 배경 둘 다에서 가독성을 어느 정도 가늠할 수 있게 함
for ($y = 0; $y < $h; $y++) {
    $t = $y / $h;
    $r = (int) round(120 + (250 - 120) * $t);
    $g = (int) round(150 + (200 - 150) * $t);
    $b = (int) round(200 + (160 - 200) * $t);
    $lineColor = imagecolorallocate($img, $r, $g, $b);
    imageline($img, 0, $y, $w, $y, $lineColor);
}

$settings = [
    'text'              => (string) ($_GET['text'] ?? 'LOVEDAY.KR 무료 체험 사용중입니다.'),
    'width_ratio'       => max(0.1, min(1.0, (float) ($_GET['width_ratio'] ?? 0.5))),
    'step_x_ratio'      => max(0.2, min(2.0, (float) ($_GET['step_x_ratio'] ?? 0.55))),
    'step_y_multiplier' => max(1.0, min(20.0, (float) ($_GET['step_y_multiplier'] ?? 9.0))),
    'angle'             => max(-89, min(89, (int) ($_GET['angle'] ?? 30))),
    'opacity'           => max(0, min(100, (int) ($_GET['opacity'] ?? 70))),
];

apply_watermark($img, $settings);

header('Content-Type: image/webp');
imagewebp($img, null, 85);
imagedestroy($img);
