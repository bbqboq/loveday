<?php
/**
 * anniversary.php?t=편집토큰 - 결혼기념일을 신랑신부 휴대폰 캘린더에 "매년 반복"으로 넣는 파일(.ics)
 *
 *  - 매년 예식일에 하루 종일 일정 "💍 결혼기념일" + 그날 아침 9시 알림
 *  - 일정 메모에 청첩장 주소 → 기념일마다 청첩장을 다시 열어볼 수 있게 (그날 청첩장 맨 위엔 "결혼 N주년" 띠가 뜸)
 * 문자·앱 알림 없이 캘린더 앱이 알아서 매년 알려준다. 내 청첩장(대시보드)의 "기념일 캘린더" 버튼.
 */
declare(strict_types=1);
require_once __DIR__ . '/snap_functions.php';

$invite = snap_owner_invite(get_pdo(), (string) ($_GET['t'] ?? ''));
if (!$invite) { http_response_code(403); exit('편집 링크가 올바르지 않아요.'); }
$wd = snap_wedding_date($invite);
if (!$wd) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('예식일이 아직 정해지지 않았어요. 에디터에서 예식일을 넣어주세요.'); }

$esc = fn(string $s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
$fold = function (string $line): string {
    $out = ''; $cur = '';
    foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($cur . $ch) > ($out === '' ? 75 : 74)) { $out .= ($out === '' ? '' : "\r\n ") . $cur; $cur = ''; }
        $cur .= $ch;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $cur;
};
$g = trim((string) $invite['groom_name']); $b = trim((string) $invite['bride_name']);
$who = $g !== '' && $b !== '' ? "{$g} ♥ {$b} " : '';
$url = 'https://loveday.kr/' . $invite['view_slug'];
$day = $wd->format('Ymd');
$next = $wd->modify('+1 day')->format('Ymd');
$lines = [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LOVE DAY//Anniversary//KO', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    'UID:anniversary-' . $invite['view_slug'] . '@loveday.kr',
    'DTSTAMP:' . gmdate('Ymd\THis\Z'),
    'DTSTART;VALUE=DATE:' . $day,
    'DTEND;VALUE=DATE:' . $next,
    'RRULE:FREQ=YEARLY',
    'SUMMARY:' . $esc('💍 ' . $who . '결혼기념일'),
    'DESCRIPTION:' . $esc($wd->format('Y년 n월 j일') . "에 결혼했어요.\n우리 청첩장 다시 보기: {$url}"),
    'URL:' . $url,
    'TRANSP:TRANSPARENT',
    'BEGIN:VALARM', 'ACTION:DISPLAY', 'TRIGGER;RELATED=START:PT9H', 'DESCRIPTION:' . $esc('오늘은 결혼기념일이에요 💍'), 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR',
];
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="anniversary.ics"; filename*=UTF-8\'\'' . rawurlencode('결혼기념일.ics'));
header('Cache-Control: no-store');
echo implode("\r\n", array_map($fold, $lines)) . "\r\n";
