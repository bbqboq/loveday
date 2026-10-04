<?php
/**
 * share_thumb.php?s=공개코드&k=og|kakao - 공유용 썸네일을 JPG로 변환해서 내려준다
 * 업로드 사진은 전부 webp로 저장되는데, 카카오톡·일부 메신저 링크 미리보기는 webp를 못 보여주는 경우가 있어서
 * 공유 썸네일만 JPG로 바꿔 캐시해 둔다 (uploads/{id}/share_{해시}.jpg, 원본이 바뀌면 새로 만듦).
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php'; // functions.php + invite_is_live

$pdo = get_pdo();
$slug = (string) ($_GET['s'] ?? '');
$kind = ($_GET['k'] ?? 'og') === 'kakao' ? 'kakao' : 'og';
$invite = preg_match('/^[a-z0-9]{4,20}$/', $slug) ? find_invitation_by_slug($pdo, $slug) : null;
if (!$invite || empty($invite['design_json']) || !invite_is_live($pdo, $invite)) { http_response_code(404); exit; } // 기간 만료된 청첩장은 미리보기 사진도 안 줌
$design = json_decode($invite['design_json'], true) ?: [];
$share = is_array($design['share'] ?? null) ? $design['share'] : [];

// 썸네일 고르는 순서: 직접 올린 썸네일(카카오/링크) → 대표사진(히어로) → 갤러리 첫 번째 사진
// (켜진 섹션을 먼저 보고, 없으면 꺼진 섹션에 남아 있는 사진이라도 쓴다)
$candidates = [$share['kakaoThumb'] ?? '', $share['ogImage'] ?? '']; // 카카오·링크 미리보기 모두 같은 썸네일 (에디터에서 링크 공유 칸을 뺌)
$blocks = is_array($design['blocks'] ?? null) ? $design['blocks'] : [];
foreach ([true, false] as $wantEnabled) {
    foreach ($blocks as $b) if (($b['id'] ?? '') === 'hero' && !empty($b['enabled']) === $wantEnabled) $candidates[] = $b['fields']['heroImage'] ?? '';
}
foreach ([true, false] as $wantEnabled) {
    foreach ($blocks as $b) {
        if (($b['id'] ?? '') !== 'gallery' || !empty($b['enabled']) !== $wantEnabled) continue;
        foreach ((array) ($b['fields']['images'] ?? []) as $img) { $candidates[] = is_array($img) ? ($img['src'] ?? '') : (string) $img; }
    }
}
$id = (int) $invite['id'];
$src = null;
foreach ($candidates as $c) {
    // 이 청첩장 폴더의 업로드 파일만 허용 (다른 경로·외부 주소는 무시)
    if (is_string($c) && preg_match('#^/?(?:invite/)?uploads/' . $id . '/([a-f0-9]{32}\.webp)$#', $c, $m)) {
        $p = invitation_upload_dir($id) . $m[1];
        if (is_file($p)) { $src = $p; break; }
    }
}
if (!$src) { http_response_code(404); exit; }

// 카카오톡 썸네일: 에디터 "화면 설정 → 공유"에서 고른 비율(세로·정사각·가로)로 미리 잘라서 보낸다.
//  보이는 부분(kakaoFocusX/Y 0~100, 가운데 50)과 확대(kakaoZoom 100~300%)를 그대로 따른다 - 카카오가 가운데만 잘라 쓰지 않게
$crop = null;
if ($kind === 'kakao') {
    $ratio = in_array($share['kakaoRatio'] ?? '', ['portrait', 'square', 'landscape'], true) ? $share['kakaoRatio'] : 'portrait';
    $fx = max(0, min(100, (float) ($share['kakaoFocusX'] ?? 50)));
    $fy = max(0, min(100, (float) ($share['kakaoFocusY'] ?? 50)));
    $zm = max(100, min(300, (float) ($share['kakaoZoom'] ?? 100))) / 100;
    $crop = [$ratio, $fx, $fy, $zm];
}
$out = invitation_upload_dir($id) . 'share_' . substr(sha1($src . filemtime($src) . json_encode($crop)), 0, 16) . '.jpg';
if (!is_file($out)) {
    foreach (glob(invitation_upload_dir($id) . 'share_*.jpg') ?: [] as $old) {
        if (filemtime($old) < time() - 60) @unlink($old); // (og·kakao 썸네일이 서로 지우지 않게 방금 만든 건 남김)
    }
    $img = @imagecreatefromwebp($src);
    if (!$img) { http_response_code(500); exit; }
    $w = imagesx($img); $h = imagesy($img);
    if ($crop) {
        [$ratio, $fx, $fy, $zm] = $crop;
        [$ow, $oh] = ['portrait' => [900, 1200], 'square' => [1000, 1000], 'landscape' => [1200, 600]][$ratio];
        $a = $ow / $oh;
        $cw = $w / $h > $a ? $h * $a : $w; $ch = $cw / $a; // 사진 안에 들어가는 가장 큰 그 비율 칸
        $cw /= $zm; $ch /= $zm;
        $x0 = max(0, min($w - $cw, $fx / 100 * $w - $cw / 2));
        $y0 = max(0, min($h - $ch, $fy / 100 * $h - $ch / 2));
        $dw = max(1, (int) round(min($cw, $ow))); $dh = max(1, (int) round($dw / $a)); // 원본이 작으면 그 크기 그대로 (억지로 키우지 않음)
        $dst = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($dst, $img, 0, 0, (int) round($x0), (int) round($y0), $dw, $dh, (int) round($cw), (int) round($ch));
    } else {
        $r = min(1, 1200 / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) ($w * $r)), max(1, (int) ($h * $r)));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
    }
    imagejpeg($dst, $out, 85);
    imagedestroy($img); imagedestroy($dst);
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($out));
header('Cache-Control: public, max-age=3600');
readfile($out);
