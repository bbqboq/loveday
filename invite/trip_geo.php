<?php
/**
 * trip_geo.php - 신혼여행 라이브: 장소 찾기 (신랑신부만)
 *   ?t=편집토큰&lat=..&lng=..   휴대폰 위치 → "파리, 프랑스" 같은 도시 이름
 *   ?t=편집토큰&q=니스          이름 → 좌표
 * 결과 좌표는 도시 수준(소수점 한 자리 ≈ 10km)으로 줄여서 돌려준다. 자세한 위치는 서버에 남기지 않는다.
 * 지명 검색은 OpenStreetMap(Nominatim)을 쓴다 - 무료, 하루 몇 번 쓰는 정도라 이용 규칙(초당 1회) 안.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$pdo = get_pdo();
$invite = snap_owner_invite($pdo, (string) ($_GET['t'] ?? ''));
if (!$invite) { http_response_code(403); echo '{"ok":false,"error":"편집 링크가 올바르지 않아요."}'; exit; }
if (!check_rate_limit($pdo, 'tripgeo_' . (int) $invite['id'], 40, 600)) { http_response_code(429); echo json_encode(['ok' => false, 'error' => '잠시 후 다시 시도해주세요.'], JSON_UNESCAPED_UNICODE); exit; }

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $r = trip_geocode(['q' => mb_substr($q, 0, 80)]);
} elseif (is_numeric($_GET['lat'] ?? null) && is_numeric($_GET['lng'] ?? null)) {
    $r = trip_geocode(['lat' => round((float) $_GET['lat'], 3), 'lon' => round((float) $_GET['lng'], 3)]);
    if (!$r) $r = ['place' => '', 'lat' => round((float) $_GET['lat'], 1), 'lng' => round((float) $_GET['lng'], 1)]; // 이름을 못 찾아도 지도 핀은 찍히게
} else {
    $r = null;
}
echo json_encode($r ? ['ok' => true] + $r : ['ok' => false, 'error' => '장소를 찾지 못했어요. 도시 이름으로 다시 찾아보세요.'], JSON_UNESCAPED_UNICODE);
