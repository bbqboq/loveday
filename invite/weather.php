<?php
/**
 * weather.php?s=청첩장코드 (공개) | ?t=편집토큰 (에디터 미리보기)
 *  화면 설정 > 효과 "날씨 따라": 예식장 주소의 지금 날씨를 알려준다 → 청첩장이 효과를 고름 (invite-blocks.js WEATHER_FX)
 *   cond: clear(맑음·낮) | night(맑음·밤) | cloudy(흐림) | fog(안개) | rain(비) | snow(눈) | storm(뇌우)
 *  주소 → 좌표: OpenStreetMap Nominatim (trip_geocode, 도시 수준) / 날씨: Open-Meteo (무료, 키 없음)
 *  같은 청첩장은 30분 동안 저장해 둔 값을 돌려줌 (uploads/site/weather/번호.json) - 하객이 많아도 바깥 요청은 30분에 한 번
 *  테스트 서버는 config.php의 WEATHER_API_BASE 로 가짜 날씨 서버를 쓸 수 있다
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function wx_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
$out = 'wx_out';

$pdo = get_pdo();
$slug = (string) ($_GET['s'] ?? ''); $tok = (string) ($_GET['t'] ?? '');
$inv = null;
if (preg_match('/^[a-f0-9]{64}$/', $tok)) $inv = find_invitation_by_token($pdo, $tok);
elseif (preg_match('/^[a-z0-9]{3,20}$/', $slug)) $inv = find_invitation_by_slug($pdo, $slug);
if (!$inv || !empty($inv['deleted_at'])) $out(['ok' => false, 'error' => 'not found'], 404);
if (!check_rate_limit($pdo, 'wx_' . client_ip(), 120, 600)) $out(['ok' => false, 'error' => 'busy'], 429);

// 예식장 주소 (오시는 길 섹션 → 없으면 주문 정보)
$d = json_decode((string) ($inv['design_json'] ?? ''), true) ?: [];
$addr = '';
foreach (($d['blocks'] ?? []) as $b) if (($b['id'] ?? '') === 'location') { $f = $b['fields'] ?? []; $addr = trim((string) ($f['addressBase'] ?? '') ?: (string) ($f['address'] ?? '')); }
if ($addr === '') $addr = trim((string) ($inv['venue_address'] ?? ''));
if ($addr === '') $out(['ok' => false, 'error' => 'no address']);

$dir = UPLOAD_DIR . 'site/weather/';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$cf = $dir . (int) $inv['id'] . '.json';
$c = is_file($cf) ? (json_decode((string) @file_get_contents($cf), true) ?: []) : [];
$addrKey = md5($addr);

if (($c['addr'] ?? '') === $addrKey && !empty($c['cond']) && time() - (int) ($c['at'] ?? 0) < 1800) {
    $out(['ok' => true, 'cond' => $c['cond'], 'code' => $c['code'] ?? null, 'temp' => $c['temp'] ?? null, 'place' => $c['place'] ?? '', 'label' => $c['label'] ?? '', 'cached' => true]);
}

// 좌표 (주소가 바뀌지 않았으면 저장해 둔 값) - 긴 도로명 주소가 안 찾아지면 앞 단어만으로 다시 (시·구 수준이면 날씨엔 충분)
$lat = $c['lat'] ?? null; $lng = $c['lng'] ?? null; $place = $c['place'] ?? '';
if (($c['addr'] ?? '') !== $addrKey || $lat === null) {
    $lat = $lng = null;
    $words = preg_split('/\s+/u', preg_replace('/\(.*?\)|,/u', ' ', $addr), -1, PREG_SPLIT_NO_EMPTY);
    foreach ([count($words), 3, 2, 1] as $n) {
        if ($n < 1 || $n > count($words)) continue;
        $g = trip_geocode(['q' => implode(' ', array_slice($words, 0, $n))]);
        if ($g) { $lat = $g['lat']; $lng = $g['lng']; $place = $g['place']; break; }
    }
    if ($lat === null) $out(['ok' => false, 'error' => 'geocode']);
}

$base = defined('WEATHER_API_BASE') ? rtrim((string) WEATHER_API_BASE, '/') : 'https://api.open-meteo.com';
$ch = curl_init($base . '/v1/forecast?' . http_build_query(['latitude' => $lat, 'longitude' => $lng, 'current' => 'weather_code,is_day,temperature_2m', 'timezone' => 'Asia/Seoul']));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4]);
$body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$j = $code === 200 ? json_decode((string) $body, true) : null;
$cur = is_array($j['current'] ?? null) ? $j['current'] : null;
if (!$cur || !isset($cur['weather_code'])) {
    if (!empty($c['cond'])) $out(['ok' => true, 'cond' => $c['cond'], 'code' => $c['code'] ?? null, 'temp' => $c['temp'] ?? null, 'place' => $place, 'label' => $c['label'] ?? '', 'stale' => true]); // 예전 값이라도
    $out(['ok' => false, 'error' => 'weather']);
}
// WMO 날씨 코드 → 효과 분류
$w = (int) $cur['weather_code']; $day = !empty($cur['is_day']);
[$cond, $label] = match (true) {
    $w <= 1 => [$day ? 'clear' : 'night', '맑음'],
    $w <= 3 => ['cloudy', '흐림'],
    $w === 45 || $w === 48 => ['fog', '안개'],
    ($w >= 71 && $w <= 77) || $w === 85 || $w === 86 => ['snow', '눈'],
    $w >= 95 => ['storm', '뇌우'],
    default => ['rain', '비'],
};
$temp = isset($cur['temperature_2m']) ? round((float) $cur['temperature_2m']) : null;
@file_put_contents($cf, json_encode(['addr' => $addrKey, 'lat' => $lat, 'lng' => $lng, 'place' => $place, 'cond' => $cond, 'code' => $w, 'temp' => $temp, 'label' => $label, 'at' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
$out(['ok' => true, 'cond' => $cond, 'code' => $w, 'temp' => $temp, 'place' => $place, 'label' => $label]);
