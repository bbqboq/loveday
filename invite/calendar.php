<?php
/**
 * calendar.php - 하객의 휴대폰 캘린더에 예식 일정을 넣는 파일(.ics)
 *
 *   /invite/calendar.php?s=청첩장코드          → .ics 파일 (아이폰 캘린더·삼성 캘린더·아웃룩 등이 바로 "추가"를 띄움)
 *   /invite/calendar.php?s=청첩장코드&json=1   → 버튼이 쓰는 정보 (구글 캘린더 링크, 대절버스 여부)
 *
 * 알림 (캘린더 앱이 직접 울림 - 문자·앱 설치 없이 무료)
 *   - 예식: 3시간 전
 *   - 대절·셔틀버스: 교통수단 섹션에 "출발 시각"을 적어 둔 버스마다 따로 일정 + 출발 30분 전
 * 일정 설명에 청첩장 주소가 들어가서, 알림을 누르면 청첩장(예식 당일이면 주차·식사 안내 팝업)이 열린다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

const CAL_WEDDING_ALARM_MIN = 180; // 예식 3시간 전
const CAL_BUS_ALARM_MIN     = 30;  // 버스 출발 30분 전
const CAL_WEDDING_LENGTH_MIN = 120;

function cal_fail(int $code, string $msg): void { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); exit($msg); }
function cal_plain($s): string { return trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', (string) $s)))); }
function cal_first_line($s): string { return trim(explode("\n", trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $s))))[0]); }
/** .ics 글자 규칙: 쉼표·세미콜론·역슬래시·줄바꿈 이스케이프 */
function cal_esc(string $s): string { return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s); }
/** .ics 한 줄은 75바이트까지 - 넘으면 접는다 (한글이 깨지지 않게 글자 단위로) */
function cal_fold(string $line): string
{
    $out = ''; $cur = '';
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur . $ch) > ($out === '' ? 75 : 74)) { $out .= ($out === '' ? '' : "\r\n ") . $cur; $cur = ''; }
        $cur .= $ch;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $cur;
}

$slug = strtolower((string) ($_GET['s'] ?? ''));
$pdo = get_pdo();
$inv = guest_invite_by_slug($pdo, $slug);
if (!$inv) cal_fail(404, '발행된 청첩장이 아니에요.');
$d = snap_design($inv) ?? [];
$blocks = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
$blk = function (string $id) use ($blocks): ?array { foreach ($blocks as $b) if (($b['id'] ?? '') === $id) return $b; return null; };
$tz = new DateTimeZone('Asia/Seoul');

// ---- 예식 일시: D-day 섹션 → 주문 정보
$start = null;
$dd = $blk('dday');
if ($dd && !empty($dd['fields']['year'])) {
    $f = $dd['fields'];
    $start = (new DateTimeImmutable('now', $tz))->setDate((int) $f['year'], (int) $f['month'], (int) $f['day'])->setTime((int) ($f['hour'] ?? 12), (int) ($f['minute'] ?? 0));
} elseif (!empty($inv['wedding_datetime'])) {
    $start = new DateTimeImmutable((string) $inv['wedding_datetime'], $tz);
}
if (!$start) cal_fail(404, '예식 일시가 아직 정해지지 않았어요.');
$end = $start->modify('+' . CAL_WEDDING_LENGTH_MIN . ' minutes');

// ---- 이름·장소
$g = trim((string) $inv['groom_name']); $b = trim((string) $inv['bride_name']);
foreach (['hero', 'heroVideo'] as $hid) { $hb = $blk($hid); if ($hb && ($g === '' || $b === '')) { $g = $g ?: cal_first_line($hb['fields']['groomName'] ?? ''); $b = $b ?: cal_first_line($hb['fields']['brideName'] ?? ''); } }
$names = ($g !== '' && $b !== '') ? "{$g} ♥ {$b}" : '';
$title = $names !== '' ? "{$names} 결혼식" : '결혼식';
$loc = $blk('location');
$venue = cal_plain($loc['fields']['venue'] ?? ($inv['venue_name'] ?? ''));
$addr = cal_plain(trim(($loc['fields']['address'] ?? ($inv['venue_address'] ?? '')) . ' ' . ($loc['fields']['addressDetail'] ?? '')));
$where = trim($venue . ($addr !== '' ? ($venue !== '' ? ', ' : '') . $addr : ''));
$url = 'https://loveday.kr/' . $inv['view_slug'];

// ---- 대절·셔틀버스 (교통수단 섹션에서 "출발 시각"을 적은 항목)
$buses = [];
$tr = $blk('transport');
if ($tr && !empty($tr['enabled'])) {
    foreach ((array) ($tr['fields']['items'] ?? []) as $it) {
        if (!preg_match('/^\s*(\d{1,2})\s*[:시]\s*(\d{2})?/u', (string) ($it['depart'] ?? ''), $m)) continue;
        $h = (int) $m[1]; $mi = (int) ($m[2] ?? 0);
        if ($h > 23 || $mi > 59) continue;
        $t = $start->setTime($h, $mi);
        if ($t > $start) $t = $t->modify('-1 day'); // 예식보다 늦은 시각이면 전날 출발로 봄 (예: 전날 밤 출발)
        $label = cal_plain($it['label'] ?? '') ?: '대절버스';
        $buses[] = ['start' => $t, 'label' => $label, 'text' => cal_plain($it['text'] ?? '')];
    }
}

// ---- 버튼용 정보 (JSON)
if (isset($_GET['json'])) {
    $gfmt = fn(DateTimeImmutable $t) => $t->format('Ymd\THis');
    $google = 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE', 'text' => $title, 'dates' => $gfmt($start) . '/' . $gfmt($end), 'ctz' => 'Asia/Seoul',
        'location' => $where, 'details' => "모바일 청첩장: {$url}\n예식 당일 청첩장을 열면 주차·식사 안내가 먼저 보여요.",
    ]);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // 안드로이드 캘린더 앱 바로 열기(intent)에 쓰는 값: 시작·끝 시각(밀리초), 설명
    echo json_encode(['ok' => true, 'title' => $title, 'start' => $start->format('c'), 'where' => $where, 'google' => $google,
        'startMs' => $start->getTimestamp() * 1000, 'endMs' => $end->getTimestamp() * 1000, 'details' => "모바일 청첩장: {$url}",
        'buses' => array_map(fn($x) => ['label' => $x['label'], 'time' => $x['start']->format('H:i')], $buses)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- .ics
$stamp = gmdate('Ymd\THis\Z');
$at = fn(DateTimeImmutable $t) => 'TZID=Asia/Seoul:' . $t->format('Ymd\THis');
$lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LOVE DAY//Mobile Invitation//KO', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'X-WR-CALNAME:' . cal_esc($title), 'X-WR-TIMEZONE:Asia/Seoul',
    'BEGIN:VTIMEZONE', 'TZID:Asia/Seoul', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0900', 'TZOFFSETTO:+0900', 'TZNAME:KST', 'END:STANDARD', 'END:VTIMEZONE',
    'BEGIN:VEVENT',
    'UID:wedding-' . $inv['view_slug'] . '@loveday.kr',
    'DTSTAMP:' . $stamp,
    'DTSTART;' . $at($start),
    'DTEND;' . $at($end),
    'SUMMARY:' . cal_esc($title),
    'LOCATION:' . cal_esc($where),
    'DESCRIPTION:' . cal_esc("모바일 청첩장: {$url}\n예식 당일 청첩장을 열면 주차·식사 안내가 먼저 보여요."),
    'URL:' . $url,
    'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT' . CAL_WEDDING_ALARM_MIN . 'M',
    'DESCRIPTION:' . cal_esc(($names !== '' ? $names . ' ' : '') . '결혼식이 3시간 뒤에 시작돼요'), 'END:VALARM',
    'END:VEVENT',
];
foreach ($buses as $i => $bus) {
    $what = $bus['label'] . ' 출발';
    array_push($lines,
        'BEGIN:VEVENT',
        'UID:bus' . ($i + 1) . '-' . $inv['view_slug'] . '@loveday.kr',
        'DTSTAMP:' . $stamp,
        'DTSTART;' . $at($bus['start']),
        'DTEND;' . $at($bus['start']->modify('+30 minutes')),
        'SUMMARY:' . cal_esc(($names !== '' ? $names . ' 결혼식 · ' : '') . $what),
        'LOCATION:' . cal_esc(mb_substr($bus['text'], 0, 120)),
        'DESCRIPTION:' . cal_esc(($bus['text'] !== '' ? $bus['text'] . "\n" : '') . "모바일 청첩장: {$url}"),
        'URL:' . $url,
        'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER:-PT' . CAL_BUS_ALARM_MIN . 'M',
        'DESCRIPTION:' . cal_esc($what . ' 30분 전이에요'), 'END:VALARM',
        'END:VEVENT');
}
$lines[] = 'END:VCALENDAR';
$ics = implode("\r\n", array_map('cal_fold', $lines)) . "\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="wedding.ics"; filename*=UTF-8\'\'' . rawurlencode($title . '.ics'));
header('Cache-Control: no-store');
echo $ics;
