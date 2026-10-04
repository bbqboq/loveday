<?php
/**
 * music_list.php - 배경음악 목록 (폴더 기반, DB 없음)
 *
 * /invite/music/ 폴더에 mp3(또는 m4a) 파일을 올리면 에디터의 배경음악 목록에 자동으로 나온다.
 * 곡 이름·태그를 붙이고 싶으면 같은 폴더에 tracks.json을 두면 된다 (없으면 파일명이 곡 이름):
 *   { "gentle-steps.mp3": { "title": "Gentle Steps in Sunlight", "tags": ["피아노", "잔잔한"] } }
 * 저작권: 직접 제작했거나 상업적 이용이 허락된(로열티 프리) 음원만 올려주세요.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$dir = __DIR__ . '/music';
$meta = [];
if (is_file($dir . '/tracks.json')) {
    $m = json_decode((string) file_get_contents($dir . '/tracks.json'), true);
    if (is_array($m)) $meta = $m;
}
$tracks = [];
foreach (glob($dir . '/*.{mp3,m4a,MP3,M4A}', GLOB_BRACE) ?: [] as $path) {
    $file = basename($path);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $file)) continue; // 파일명은 영문·숫자·._- 만 (주소가 깨지지 않게)
    $info = is_array($meta[$file] ?? null) ? $meta[$file] : [];
    $title = is_string($info['title'] ?? null) ? mb_substr(strip_tags($info['title']), 0, 60) : pathinfo($file, PATHINFO_FILENAME);
    $tags = array_values(array_slice(array_map(fn($t) => mb_substr(strip_tags((string) $t), 0, 10), array_filter((array) ($info['tags'] ?? []), 'is_string')), 0, 4));
    $tracks[] = ['id' => $file, 'title' => $title, 'tags' => $tags, 'url' => '/invite/music/' . rawurlencode($file) . '?v=' . filemtime($path)];
}
usort($tracks, fn($a, $b) => strcmp($a['title'], $b['title']));
echo json_encode(['ok' => true, 'tracks' => $tracks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
