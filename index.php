<?php
/**
 * index.php - LOVE DAY 홈페이지 (loveday.kr 첫 화면)
 *
 * 서버 맨 위 폴더(www)에 둔다. invite/ 폴더 옆.
 * 가격·구매 버튼·사업자 정보·샘플 카드는 관리자 → 사이트 정보(invite/admin_site_settings.php)에서 바꾼다.
 * 샘플 카드는 invite/presets/ 폴더의 디자인을 그대로 읽는다 (thumb.jpg가 있으면 그 사진, 없으면 디자인 색으로 그림).
 *  - 카드를 누르면 그 디자인 샘플(관리자 → 디자인 [에디터로 꾸미기]로 꾸민 모습)을 하객 화면 그대로: invite/invite_view.php?sample=<디자인 id> (꾸민 샘플이 없으면 에디터 ?look=1&d= 둘러보기)
 *  - 카드 이름 = 관리자 → 디자인 [이름 바꾸기]로 바꾼 이름 · 고객에게 안 보이게 끈 디자인·휴지통 디자인은 카드에서 빠짐 (featured_presets.php)
 *
 * ※ 예전 index.html이 같은 폴더에 있으면 서버가 그것부터 열 수 있으니 index.html은 지우거나 이름을 바꿔주세요.
 */
declare(strict_types=1);

$S = [];
$loggedIn = false;
try {
    require_once __DIR__ . '/invite/snap_functions.php'; // app_setting() + 세션
    foreach (['price_one_year', 'price_permanent', 'store_url', 'biz_name', 'biz_owner', 'biz_number', 'biz_mailorder', 'biz_address',
              'biz_contact', 'terms_url', 'home_sample_ids', 'home_sample_url', 'demo_hours', 'snap_retention_days', 'snap_days_after'] as $k) {
        $S[$k] = app_setting($k);
    }
    $loggedIn = !empty($_SESSION['customer_id']);
    require_once __DIR__ . '/invite/site_images.php'; // 첫 화면 휴대폰 사진 (관리자 → 홈 이미지)
    require_once __DIR__ . '/invite/featured_presets.php'; // 디자인 이름 바꾸기 · 보이기/숨기기 · 휴지통
} catch (Throwable $e) {
    // DB·설정 파일에 문제가 있어도 홈페이지는 열리게 (값은 기본값으로)
}
$S += ['home_sample_url' => '', 'demo_hours' => '24', 'snap_retention_days' => '15', 'snap_days_after' => '5'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$price = fn(string $k) => ($S[$k] ?? '') !== '' ? number_format((int) $S[$k]) : '';
$storeUrl = preg_match('#^https?://#i', $S['store_url'] ?? '') ? $S['store_url'] : '';
const LOOK_URL = '/invite/editor-prototype-v3-overlay.html?look=1'; // 샘플 둘러보기 (로그인 없이 모든 디자인 · [크게 보기] · [이 디자인으로 만들어 보기])
$sampleUrl = preg_match('#^(/|https?://)#', $S['home_sample_url']) && !in_array(rtrim($S['home_sample_url'], '/'), ['/testbed', '/invite/testbed'], true) ? $S['home_sample_url'] : LOOK_URL;
$cardUrl = fn(string $id) => '/invite/invite_view.php?sample=' . rawurlencode($id); // 카드 = 관리자가 꾸며 둔 그 디자인 샘플을 하객 화면 그대로 (없으면 그 디자인 둘러보기로)
$demoHours = max(1, (int) $S['demo_hours']);
// 관리자가 올린 사진이 있으면 그 사진, 없으면 원래 그라데이션 (style 속성에 넣을 값)
$imgCss = fn(string $slot, string $overlay = '') => function_exists('site_image_css') ? site_image_css($slot, $overlay) : '';

// ---------- 샘플 디자인 (invite/presets/*/preset.json) ----------
$presets = [];
$hide = []; $names = [];
try {
    if (function_exists('preset_off_get')) $hide = preset_off_get();
    if (function_exists('design_trash_ids')) $hide = array_merge($hide, design_trash_ids());
    if (function_exists('preset_names_get')) $names = preset_names_get();
} catch (Throwable $e) {}
foreach (glob(__DIR__ . '/invite/presets/*/preset.json') ?: [] as $f) {
    if (filesize($f) > 65536) continue;
    $p = json_decode((string) file_get_contents($f), true);
    if (!is_array($p) || !preg_match('/^[a-z0-9-]{2,40}$/', (string) ($p['id'] ?? ''))) continue;
    if (in_array($p['id'], $hide, true)) continue; // 고객에게 안 보이게 끈 디자인 · 휴지통
    if (isset($names[$p['id']]['label'])) $p['label'] = $names[$p['id']]['label']; // 관리자가 바꾼 이름
    $folder = basename(dirname($f));
    $thumb = '';
    foreach (['thumb.jpg', 'thumb.png', 'thumb.webp'] as $t) if (is_file(dirname($f) . '/' . $t)) { $thumb = '/invite/presets/' . rawurlencode($folder) . '/' . $t; break; }
    $pal = is_array($p['palette'] ?? null) ? $p['palette'] : [];
    $col = fn($k, $d) => preg_match('/^#[0-9a-f]{6}$/i', (string) ($pal[$k] ?? '')) ? $pal[$k] : $d;
    $up = function_exists('preset_thumb_image') ? preset_thumb_image($p['id']) : null; // 관리자 → 홈 이미지에서 올린 썸네일이 먼저
    if ($up) $thumb = $up['url'];
    $presets[$p['id']] = [
        'id' => $p['id'], 'no' => (int) ($p['no'] ?? 999), 'pos' => $up['pos'] ?? '50% 50%',
        'label' => mb_substr(trim((string) ($p['label'] ?? $p['name'] ?? $p['id'])), 0, 20),
        'tag' => mb_substr(implode(' · ', array_slice(array_filter((array) ($p['tags'] ?? []), fn($t) => is_string($t) && $t !== 'BETA'), 0, 2)), 0, 24),
        'thumb' => $thumb, 'bg' => $col('bg', '#FAF7F0'), 'ink' => $col('ink', '#2B2320'), 'accent' => $col('accent', '#8A4B55'), 'line' => $col('line', '#E6E0D8'),
        'dark' => (hexdec(substr($col('bg', '#ffffff'), 1, 2)) < 90),
    ];
}
uasort($presets, fn($a, $b) => [$a['no'], $a['id']] <=> [$b['no'], $b['id']]);
$presetCount = count($presets);
$samples = [];
foreach (array_filter(array_map('trim', explode(',', $S['home_sample_ids'] ?? ''))) as $id) if (isset($presets[$id])) $samples[$id] = $presets[$id];
foreach ($presets as $id => $p) { if (count($samples) >= 6) break; $samples[$id] ??= $p; }
$samples = array_slice(array_values($samples), 0, 6);

// ---------- 맨 아래 사업자 정보 ----------
$biz = array_filter([
    '상호' => $S['biz_name'] ?? '', '대표' => $S['biz_owner'] ?? '', '사업자등록번호' => $S['biz_number'] ?? '',
    '통신판매업신고' => $S['biz_mailorder'] ?? '', '주소' => $S['biz_address'] ?? '', '문의' => $S['biz_contact'] ?? '',
], fn($v) => trim((string) $v) !== '');
$termsUrl = preg_match('#^https?://#i', $S['terms_url'] ?? '') ? $S['terms_url'] : '';
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LOVE DAY · 드래그로 완성하는 모바일 청첩장</title>
<meta name="description" content="사진을 넣고 문구를 드래그로 옮기며 꾸미는 모바일 청첩장. 먼저 무료로 만들어 보고 마음에 들 때 결제하세요.">
<meta property="og:title" content="LOVE DAY · 모바일 청첩장">
<meta property="og:description" content="먼저 무료로 만들어 보고, 마음에 들 때 결정하세요.">
<meta property="og:type" content="website">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable.css">
<style>
:root{--bg:#F6F4F1;--surface:#fff;--ink:#1B1A18;--sub:#6F6A63;--faint:#A39D95;--line:#EAE6E0;--soft:#F1EEEA;--accent:#8A4B55;--accent-soft:#F4ECEC}
*{box-sizing:border-box}
html{scroll-behavior:smooth;scroll-padding-top:72px}
body{margin:0;background:var(--bg);font-family:"Pretendard Variable",Pretendard,-apple-system,"Apple SD Gothic Neo","Malgun Gothic",sans-serif;color:var(--ink);-webkit-font-smoothing:antialiased;line-height:1.6;word-break:keep-all}
a{color:inherit;text-decoration:none}
button{font-family:inherit;cursor:pointer}
form.inline{display:inline;margin:0}
svg.i{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:none}
.wrap{max-width:1120px;margin:0 auto;padding:0 28px}
.eyebrow{font-size:12px;letter-spacing:.22em;color:var(--accent);font-weight:700;text-transform:uppercase}
h2.sec{font-size:34px;letter-spacing:-.03em;line-height:1.3;margin:10px 0 12px;font-weight:700}
p.lead{color:var(--sub);font-size:16px;margin:0}
.btn{display:inline-flex;align-items:center;gap:8px;border-radius:12px;padding:15px 22px;font-weight:600;font-size:15px;border:1px solid transparent;line-height:1.2;transition:transform .15s,box-shadow .15s}
.btn:hover{transform:translateY(-1px)}
.btn.dark{background:var(--ink);color:#fff}.btn.dark:hover{box-shadow:0 10px 24px rgba(27,26,24,.2)}
.btn.light{background:#fff;border-color:var(--line);color:var(--ink)}
.nv{width:20px;height:20px;border-radius:6px;background:#03C75A;color:#fff;font:800 12px/20px sans-serif;text-align:center}
.notice{max-width:1120px;margin:16px auto 0;padding:12px 16px;border-radius:12px;background:#F1EEE6;color:#6B675F;font-size:13.5px;text-align:center}
/* nav */
header{position:sticky;top:0;z-index:10;background:rgba(246,244,241,.86);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
header .wrap{height:64px;display:flex;align-items:center;gap:30px}
.logo{font-weight:800;letter-spacing:.22em;font-size:14px}.logo span{color:var(--accent)}
nav{display:flex;gap:26px;font-size:14px;color:var(--sub)}nav a:hover{color:var(--ink)}
.navr{margin-left:auto;display:flex;gap:14px;align-items:center;font-size:14px}
.navr .btn{padding:9px 16px;font-size:13.5px;border-radius:10px}
.navr .login{background:none;border:0;color:var(--sub);font-size:14px;padding:0}
/* hero */
.hero{padding:84px 0 70px;overflow:hidden}
.hero .wrap{display:grid;grid-template-columns:1.05fr .95fr;gap:40px;align-items:center}
.hero h1{font-size:54px;line-height:1.22;letter-spacing:-.04em;margin:16px 0 20px;font-weight:700}
.hero h1 em{font-style:normal;color:var(--accent)}
.hero .lead{font-size:17px;max-width:470px}
.hero .ctas{display:flex;gap:10px;margin:32px 0 16px;flex-wrap:wrap}
.hero .note{font-size:13px;color:var(--faint)}
.stage{position:relative;height:520px;--mx:0;--my:0}
/* 휴대폰 뒤 은은한 빛 */
.stage::before{content:'';position:absolute;left:6%;top:4%;width:84%;height:86%;border-radius:50%;background:radial-gradient(circle at 42% 40%,rgba(138,75,85,.26),rgba(214,170,150,.14) 45%,transparent 70%);filter:blur(26px);animation:halo 11s ease-in-out infinite alternate;pointer-events:none}
@keyframes halo{to{transform:translate(18px,-14px) scale(1.08)}}
.ph{position:absolute;width:240px;height:490px;border-radius:36px;border:9px solid #151413;background:#fff;overflow:hidden;box-shadow:0 30px 60px rgba(40,25,20,.2);
  transition:transform .7s cubic-bezier(.2,.8,.2,1);animation:phIn 1s cubic-bezier(.2,.8,.2,1) both,bob 7s ease-in-out 1s infinite}
.ph.p1{left:40px;top:10px;transform:rotate(calc(-4deg - var(--hs,0) * 8deg)) translate3d(calc(var(--mx) * -14px - var(--hs,0) * 60px),calc(var(--my) * -10px + var(--hs,0) * 40px),0)}
.ph.p2{left:230px;top:30px;transform:rotate(calc(5deg + var(--hs,0) * 8deg)) translate3d(calc(var(--mx) * 12px + var(--hs,0) * 60px),calc(var(--my) * 9px + var(--hs,0) * 20px),0);animation-delay:.14s,1.6s;animation-duration:1s,8s}
/* 화면 위로 지나가는 빛 */
.ph::after{content:'';position:absolute;inset:-20% -60%;background:linear-gradient(105deg,transparent 42%,rgba(255,255,255,.28) 50%,transparent 58%);transform:translateX(-70%);animation:sheen 7s ease-in-out 1.4s infinite;pointer-events:none;z-index:2}
.ph.p2::after{animation-delay:4.9s}
@keyframes phIn{from{opacity:0;scale:.92;translate:0 36px}}
@keyframes bob{50%{translate:0 -10px}}
@keyframes sheen{0%,62%{transform:translateX(-70%)}100%{transform:translateX(70%)}}
.scr{position:absolute;inset:0}
.inv1{background:linear-gradient(170deg,#E5D5CF 0%,#B28A89 55%,#6D3F48 100%)}
.inv1 .t{position:absolute;left:0;right:0;top:52%;text-align:center;color:#fff}
.inv1 .t small{font-size:10px;letter-spacing:.3em;opacity:.85}
.inv1 .t b{display:block;font-size:22px;margin:8px 0;font-weight:600;letter-spacing:.02em}
.inv1 .t span{font-size:11px;opacity:.9}
.inv2{background:#FBF9F6;padding:34px 18px}
.inv2 .ttl{text-align:center;font-size:11px;letter-spacing:.3em;color:var(--accent)}
.inv2 .typed{text-align:center;font-size:19px;margin:12px 0 18px;font-weight:600;min-height:30px}
.inv2 .typed i{display:inline-block;width:2px;height:18px;background:var(--ink);vertical-align:-3px;margin-left:2px;animation:blink 1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.gal{display:grid;grid-template-columns:1fr 1fr;gap:5px}
.gal i{height:70px;border-radius:6px;display:block;background-size:cover;background-position:center}
.g1{background:linear-gradient(150deg,#D9C4BE,#9D7473)}.g2{background:linear-gradient(150deg,#D6DDCE,#879A80)}.g3{background:linear-gradient(150deg,#DADDE3,#8F99AA)}.g4{background:linear-gradient(150deg,#EAD9C6,#B8906A)}
.inv2 .rs{margin-top:14px;border-radius:10px;background:var(--ink);color:#fff;text-align:center;font-size:11px;padding:9px}
.float{position:absolute;z-index:3;background:rgba(255,255,255,.94);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px);border:1px solid var(--line);border-radius:14px;padding:10px 14px;font-size:12.5px;box-shadow:0 12px 30px rgba(40,25,20,.12);display:flex;gap:10px;align-items:center;line-height:1.4;
  transform:translate3d(calc(var(--mx) * 22px),calc(var(--my) * 16px),0);transition:transform .7s cubic-bezier(.2,.8,.2,1);animation:fIn .8s cubic-bezier(.2,.8,.2,1) .7s both,bob 6s ease-in-out 1.5s infinite}
.float.f2{animation-delay:1s,3.4s;transform:translate3d(calc(var(--mx) * -20px),calc(var(--my) * -14px),0)}
@keyframes fIn{from{opacity:0;scale:.85;translate:0 14px}}
.float b{display:block;font-size:13px}.float span{color:var(--faint);font-size:11.5px}
.float .ic{width:32px;height:32px;border-radius:10px;background:var(--accent-soft);color:var(--accent);display:flex;align-items:center;justify-content:center}
/* points */
.points{border-top:1px solid var(--line);border-bottom:1px solid var(--line);background:var(--surface)}
.points .wrap{display:grid;grid-template-columns:repeat(3,1fr)}
.pt{padding:30px 26px;border-right:1px solid var(--line)}.pt:last-child{border-right:0}
.pt b{display:block;font-size:22px;letter-spacing:-.02em;margin-bottom:4px}.pt span{color:var(--sub);font-size:14px}
section{padding:96px 0}
/* samples */
.samples{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-top:40px}
.sm{border-radius:16px;height:300px;position:relative;overflow:hidden;background-size:cover;background-position:center;box-shadow:0 1px 0 var(--line);transition:transform .2s,box-shadow .2s;display:block}
.sm:hover{transform:translateY(-4px);box-shadow:0 18px 34px rgba(40,25,20,.14)}
.sm .mk{position:absolute;inset:0;display:flex;flex-direction:column}
.sm .mk .top{height:52%;position:relative}
.sm .mk .top::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 70% 25%,rgba(255,255,255,.35),transparent 50%)}
.sm .mk .nm{text-align:center;margin-top:14px;font-size:14px;font-weight:600;letter-spacing:.02em}
.sm .mk .ln{height:5px;border-radius:3px;margin:8px auto 0;opacity:.55}
.sm .lb{position:absolute;left:12px;right:12px;bottom:12px;background:rgba(255,255,255,.93);box-shadow:0 2px 10px rgba(40,25,20,.08);border-radius:10px;padding:8px 10px;font-size:12.5px;font-weight:600;color:var(--ink);line-height:1.35}
.sm .lb small{display:block;color:var(--faint);font-weight:500;font-size:11px;margin-top:2px}
.more{display:flex;justify-content:center;margin-top:26px}
/* features */
.fgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:40px}
.fc{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:24px 22px}
.fc .ic{width:44px;height:44px;border-radius:12px;background:var(--soft);display:flex;align-items:center;justify-content:center;margin-bottom:18px}
.fc b{display:block;font-size:17px;letter-spacing:-.02em;margin-bottom:6px}
.fc p{margin:0;color:var(--sub);font-size:13.5px}
.fc.big{grid-column:span 2;display:flex;gap:22px;align-items:center;background:var(--ink);color:#fff;border-color:var(--ink)}
.fc.big p{color:#C9C3BC}.fc.big .ic{background:rgba(255,255,255,.1);color:#fff}
.demo{flex:none;width:150px;height:150px;border-radius:14px;background:#FBF9F6;color:var(--ink);display:flex;align-items:center;justify-content:center;flex-direction:column;font-size:16px;font-weight:600}
.demo .r{color:#C0392B}.demo .h{background:#FFF3A3;padding:0 3px}
/* steps */
.steps{display:grid;grid-template-columns:repeat(4,1fr);margin-top:44px;position:relative}
.st{padding:0 20px 0 0}
.st .n{width:38px;height:38px;border-radius:50%;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;position:relative;z-index:1}
.st .n.o{background:#fff;color:var(--ink);border:1.5px solid var(--ink)}
.steps::before{content:'';position:absolute;top:19px;left:19px;right:12%;height:1px;background:var(--line)}
.st b{display:block;font-size:17px;margin:16px 0 6px}.st p{margin:0;font-size:13.5px;color:var(--sub)}
/* pricing */
.pgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:40px}
.pcard{background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:28px 26px;display:flex;flex-direction:column}
.pcard.rec{border:1.5px solid var(--ink);position:relative}
.pcard.rec::after{content:'많이 선택';position:absolute;top:-12px;left:26px;background:var(--ink);color:#fff;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:999px}
.pcard h4{margin:0 0 6px;font-size:16px}.pcard .pr{font-size:32px;font-weight:700;letter-spacing:-.03em}.pcard .pr small{font-size:14px;color:var(--faint);font-weight:500;margin-left:2px}
.pcard .pr.ask{font-size:24px;color:var(--sub)}
.pcard ul{list-style:none;padding:0;margin:18px 0 22px;font-size:14px;color:var(--sub);flex:1}.pcard li{padding:6px 0;border-bottom:1px solid var(--soft);display:flex;gap:8px}
.pcard li::before{content:'✓';color:var(--accent);font-weight:700}
.pcard .btn,.pcard form .btn{width:100%;justify-content:center}
.pcard .howto{font-size:12.5px;color:var(--faint);text-align:center;margin:0}
.pnote{margin:18px 0 0;font-size:13px;color:var(--faint);text-align:center}
/* faq */
.faq{max-width:760px;margin:36px auto 0}
.faq details{border-bottom:1px solid var(--line);padding:20px 4px}
.faq summary{display:flex;justify-content:space-between;gap:16px;font-size:16px;font-weight:600;cursor:pointer;list-style:none}
.faq summary::-webkit-details-marker{display:none}
.faq summary::after{content:'+';color:var(--faint);font-weight:400;font-size:20px;line-height:1;transition:transform .2s}
.faq details[open] summary::after{transform:rotate(45deg)}
.faq details p{margin:10px 0 0;color:var(--sub);font-size:14.5px}
.center{text-align:center}
/* final */
.final{background:var(--ink);color:#fff;border-radius:28px;padding:64px;text-align:center;max-width:1064px;margin:0 auto}
.final h2{font-size:36px;letter-spacing:-.03em;margin:10px 0 12px}
.final p{color:#C9C3BC;margin:0 0 28px}
.final .btn.ghost{border-color:rgba(255,255,255,.3);color:#fff;background:none}
footer{padding:56px 0 64px;font-size:12.5px;color:var(--faint);line-height:1.8}
footer .row{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
footer .row a{color:var(--sub)}
footer .biz{margin-top:14px;display:flex;flex-wrap:wrap;gap:2px 14px}
footer .biz span b{font-weight:500;color:var(--sub);margin-right:5px}
/* reveal - 스크롤하면 나타나기 */
.rv{opacity:0;transform:translateY(34px);transition:opacity .8s cubic-bezier(.2,.8,.2,1),transform .9s cubic-bezier(.2,.8,.2,1)}
.rv.in{opacity:1;transform:none}
/* 섹션 제목: 작은 영문은 자간이 펴지고, 큰 제목은 아래에서 밀려 올라오며 드러남 */
.rv .eyebrow{display:inline-block;transition:letter-spacing 1.1s cubic-bezier(.2,.8,.2,1),opacity .8s;letter-spacing:.5em;opacity:0}
.rv.in .eyebrow{letter-spacing:.22em;opacity:1}
.rv h2.sec,.final h2{clip-path:inset(0 0 100% 0);transform:translateY(40px);transition:clip-path 1s cubic-bezier(.2,.8,.2,1) .12s,transform 1s cubic-bezier(.2,.8,.2,1) .12s}
.rv.in h2.sec,.final.in h2{clip-path:inset(0 0 -10% 0);transform:none}
.rv p.lead{opacity:0;transform:translateY(16px);transition:opacity .8s .3s,transform .8s cubic-bezier(.2,.8,.2,1) .3s}
.rv.in p.lead{opacity:1;transform:none}
/* 묶음: 안의 카드가 차례로 (--i = 순서) */
.rv-st>*{transition:opacity .75s cubic-bezier(.2,.8,.2,1),transform .9s cubic-bezier(.2,.8,.2,1),filter .8s;transition-delay:calc(var(--i,0) * 95ms)}
.rv-st:not(.in)>*{opacity:0}
.rv-st[data-fx=slide]:not(.in)>*{transform:translateX(-40px)}
.rv-st[data-fx=fan]:not(.in)>*{transform:translateY(90px) rotate(calc((var(--i,0) - 2.5) * 5deg)) scale(.86)}
.rv-st[data-fx=pop]:not(.in)>*{transform:translateY(46px) scale(.9);filter:blur(8px)}
.rv-st[data-fx=flip]{perspective:1200px}
.rv-st[data-fx=flip]:not(.in)>*{transform:rotateX(28deg) translateY(70px);transform-origin:50% 100%}
.rv-st[data-fx=side]:not(.in)>*{transform:translateX(-30px)}
.rv-st[data-fx=steps]:not(.in)>*{transform:translateY(30px)}
.rv-st.in>*{opacity:1;transform:none;filter:none}
/* 이용 방법: 선이 그어지고 번호가 톡톡 */
.steps::before{transform:scaleX(0);transform-origin:left;transition:transform 1.4s cubic-bezier(.6,0,.2,1) .2s}
.steps.in::before{transform:scaleX(1)}
.st .n{transition:transform .6s cubic-bezier(.3,1.6,.5,1);transition-delay:calc(var(--i,0) * 260ms + .35s)}
.steps:not(.in) .st .n{transform:scale(0)}
.rv-st[data-fx=steps]>*{transition-delay:calc(var(--i,0) * 260ms + .25s)}
/* 가격: 추천 카드에 빛이 한 번 지나감 */
.pcard.rec{background-image:linear-gradient(110deg,#fff 38%,#F6ECEE 50%,#fff 62%);background-size:300% 100%;background-position:100% 0}
.pgrid.in .pcard.rec{animation:recSheen 1.8s ease 1.1s both}
@keyframes recSheen{from{background-position:100% 0}to{background-position:0 0}}
/* 샘플 카드: 마우스를 올리면 입체로 기울어짐 (PC) */
.samples{perspective:1000px}
.sm{--rx:0deg;--ry:0deg}
.rv-st.done>*{transition-delay:0s}
.samples.done .sm{transition:transform .3s cubic-bezier(.2,.8,.2,1),box-shadow .3s}
.samples.done .sm:hover{transform:translateY(-6px) rotateX(var(--rx)) rotateY(var(--ry))}
/* 마지막 초대: 커지며 등장 + 빛이 흐름 */
.final{position:relative;overflow:hidden;transform:scale(.9) translateY(30px);opacity:0;border-radius:48px;transition:transform 1s cubic-bezier(.2,.8,.2,1),opacity .8s,border-radius 1s}
.final.in{transform:none;opacity:1;border-radius:28px}
.final::before{content:'';position:absolute;inset:-40%;background:conic-gradient(from 0deg,transparent 0 70%,rgba(217,179,185,.16) 80%,transparent 90%);animation:spinGlow 9s linear infinite;pointer-events:none}
.final>*{position:relative}
@keyframes spinGlow{to{transform:rotate(1turn)}}
/* 맨 위 읽은 만큼 선 */
.sprog{position:fixed;left:0;top:0;height:3px;width:100%;z-index:20;background:linear-gradient(90deg,var(--accent),#D9A0A8);transform-origin:left;transform:scaleX(var(--p,0));pointer-events:none}
/* 첫 화면: 내리면 글은 천천히 올라가며 흐려지고, 휴대폰은 양쪽으로 벌어짐 */
.hero{--hs:0}
.hero .wrap>div:first-child{transform:translateY(calc(var(--hs) * -60px));opacity:calc(1 - var(--hs) * 1.1)}
@media (prefers-reduced-motion:reduce){.rv,.rv-st>*,.final,.rv .eyebrow,.rv h2.sec,.final h2,.rv p.lead,.steps::before,.st .n{opacity:1!important;transform:none!important;clip-path:none!important;filter:none!important;letter-spacing:.22em;transition:none!important}html{scroll-behavior:auto}.ph,.float,.ph::after,.stage::before,.final::before{animation:none}.sprog{display:none}}
/* ---------- 태블릿 ---------- */
@media (max-width:1000px){
  nav{display:none}
  .hero .wrap{grid-template-columns:1fr}.stage{max-width:520px;margin:0 auto;width:100%}
  .samples{grid-template-columns:repeat(3,1fr)}
  .fgrid{grid-template-columns:1fr 1fr}
}
/* ---------- 휴대폰 ---------- */
@media (max-width:600px){
  .wrap{padding:0 18px} .navr .login{display:none} header .wrap{gap:12px}
  .hero{padding:44px 0 30px}.hero .wrap{gap:10px}
  .hero h1{font-size:36px}.hero .lead{font-size:15px}.hero .ctas{flex-direction:column}.hero .ctas .btn,.hero .ctas form,.hero .ctas form .btn{justify-content:center;width:100%}
  .stage{height:430px;margin:0 -18px;width:auto}.ph{width:200px;height:410px}.ph.p1{left:18px}.ph.p2{left:auto;right:18px}
  .float{display:none}
  .points .wrap{grid-template-columns:1fr}.pt{border-right:0;border-bottom:1px solid var(--line);padding:18px 4px}.pt:last-child{border-bottom:0}
  section{padding:60px 0} h2.sec{font-size:26px}
  .samples{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;margin:30px -18px 0;padding:0 18px 10px;scrollbar-width:none}
  .samples::-webkit-scrollbar{display:none}
  .sm{flex:0 0 150px;height:240px;scroll-snap-align:start}
  .fc{padding:18px 16px}.fc.big{grid-column:span 2;flex-direction:column;align-items:flex-start}
  .steps{grid-template-columns:1fr 1fr;row-gap:28px}.steps::before{display:none}
  .pgrid{grid-template-columns:1fr}
  .final{margin:0 18px;padding:40px 22px;border-radius:22px}.final h2{font-size:26px}
}
</style>
</head>
<body>
<svg style="display:none" aria-hidden="true">
 <symbol id="type" viewBox="0 0 24 24"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></symbol>
 <symbol id="cam" viewBox="0 0 24 24"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13.5" r="3.5"/></symbol>
 <symbol id="mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
 <symbol id="note" viewBox="0 0 24 24"><path d="M4 4h16v12H8l-4 4Z"/><path d="M8 9h8M8 12h5"/></symbol>
 <symbol id="cal" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></symbol>
 <symbol id="share" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/></symbol>
 <symbol id="music" viewBox="0 0 24 24"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></symbol>
 <symbol id="arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
</svg>

<div class="sprog" aria-hidden="true"></div>
<header><div class="wrap">
  <a class="logo" href="/">LOVE<span>·</span>DAY</a>
  <nav><a href="#sample">샘플 디자인</a><a href="#feature">기능</a><a href="#how">이용 방법</a><a href="#price">가격</a><a href="#faq">자주 묻는 질문</a></nav>
  <div class="navr">
    <?php if ($loggedIn): ?>
      <a class="btn dark" href="invite/dashboard.php">내 청첩장</a>
    <?php else: ?>
      <form class="inline" method="post" action="invite/start_free.php"><button class="login" type="submit">로그인</button></form>
      <form class="inline" method="post" action="invite/start_free.php"><button class="btn dark" type="submit">무료로 만들기</button></form>
    <?php endif; ?>
  </div>
</div></header>

<?php if (isset($_GET['withdrawn'])): ?>
<div class="wrap"><p class="notice">탈퇴 처리가 완료되었습니다. 그동안 이용해주셔서 감사합니다.</p></div>
<?php endif; ?>

<div class="hero"><div class="wrap">
  <div>
    <span class="eyebrow">Mobile Wedding Invitation</span>
    <h1>두 사람이 함께 쓰는<br><em>첫 번째 편지</em></h1>
    <p class="lead">처음 만난 날부터 오늘까지, 우리의 이야기를 한 통의 초대장에 담아 보세요. 결정이요? 우리의 일기를 다 쓴 뒤에도 늦지 않아요.</p>
    <div class="ctas">
      <?php if ($loggedIn): ?>
        <a class="btn dark" href="invite/dashboard.php">내 청첩장으로 가기 <svg class="i" style="width:16px"><use href="#arrow"/></svg></a>
      <?php else: ?>
        <form class="inline" method="post" action="invite/start_free.php"><button class="btn dark" type="submit"><span class="nv">N</span>네이버로 무료 시작</button></form>
      <?php endif; ?>
      <a class="btn light" href="invite/try_demo.php">회원가입 없이 둘러보기 <svg class="i" style="width:16px"><use href="#arrow"/></svg></a>
    </div>
    <div class="note">무료체험 4일 · 결제 전까지 사진에 워터마크 · 민감한 정보는 모두 암호화됩니다</div>
  </div>
  <div class="stage" aria-hidden="true">
    <div class="ph p1"><div class="scr inv1" style="<?= $imgCss('home_p1', 'linear-gradient(180deg,rgba(20,10,12,0) 38%,rgba(20,10,12,.58) 100%)') ?>"><div class="t"><small>WE ARE GETTING MARRIED</small><b>민준 ♥ 서연</b><span>2026. 11. 14 SAT 2PM</span></div></div></div>
    <div class="ph p2"><div class="scr inv2"><div class="ttl">OUR STORY</div><div class="typed"><span id="typed"></span><i></i></div><div class="gal"><?php foreach (['g1', 'g2', 'g3', 'g4'] as $g): ?><i class="<?= $g ?>" style="<?= $imgCss('home_' . $g) ?>"></i><?php endforeach; ?></div><div class="rs">참석 의사 전달하기</div></div></div>
    <div class="float" style="left:-6px;bottom:70px"><span class="ic"><svg class="i" style="width:17px"><use href="#mail"/></svg></span><div><b>참석 회신이 도착했어요</b><span>신랑측 · 신부측 · 식사 인원까지</span></div></div>
    <div class="float f2" style="right:0;top:40px"><span class="ic"><svg class="i" style="width:17px"><use href="#cam"/></svg></span><div><b>하객 사진 도착</b><span>로그인 없이 바로 업로드</span></div></div>
  </div>
</div></div>

<div class="points"><div class="wrap rv-st" data-fx="slide">
  <div class="pt"><b>무료로 시작하세요</b><span>무료로 만들어보고 타사와 비교도 해보고 마음에 들면 그때 결정하세요.</span></div>
  <div class="pt"><b>형식에 맞추지 마세요</b><span>지겨운 템플릿 청첩장에서 벗어나, 캔버스 위를 꾸미듯 원하는 위치에 배치하세요.</span></div>
  <div class="pt"><b>완벽하지 않아도 괜찮아요</b><span>언제 어디서든 제한 없이, 추가금 없이 자유롭게 수정 가능합니다.</span></div>
</div></div>

<?php if ($samples): ?>
<section id="sample"><div class="wrap">
  <div class="rv">
    <span class="eyebrow">Design</span>
    <h2 class="sec"><?= $presetCount ?>가지 디자인에서 시작하세요</h2>
    <p class="lead">색·글꼴·섹션 구성이 미리 맞춰진 디자인을 고르고, 그 위에 우리 이야기만 채우면 돼요.</p>
  </div>
  <div class="samples rv-st" data-fx="fan">
    <?php foreach ($samples as $p): ?>
      <a class="sm" href="<?= $h($cardUrl($p['id'])) ?>" target="_blank" rel="noopener" style="<?= $p['thumb'] ? "background-image:url('" . $h($p['thumb']) . "');background-position:" . $h($p['pos']) : 'background:' . $h($p['bg']) ?>">
        <?php if (!$p['thumb']): ?>
          <span class="mk" aria-hidden="true">
            <span class="top" style="background:linear-gradient(165deg, <?= $h($p['line']) ?>, <?= $h($p['accent']) ?>)"></span>
            <span class="nm" style="color:<?= $h($p['ink']) ?>">민준 <span style="color:<?= $h($p['accent']) ?>">♥</span> 서연</span>
            <span class="ln" style="width:52%;background:<?= $h($p['line']) ?>"></span>
            <span class="ln" style="width:36%;background:<?= $h($p['line']) ?>"></span>
          </span>
        <?php endif; ?>
        <span class="lb"><?= $h($p['label']) ?><?php if ($p['tag']): ?><small><?= $h($p['tag']) ?></small><?php endif; ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="more"><a class="btn light" href="<?= $h($sampleUrl) ?>" target="_blank" rel="noopener">샘플 청첩장 직접 보기 <svg class="i" style="width:16px"><use href="#arrow"/></svg></a></div>
</div></section>
<?php endif; ?>

<section id="feature" style="background:var(--surface);border-top:1px solid var(--line);border-bottom:1px solid var(--line)"><div class="wrap">
  <div class="rv">
    <span class="eyebrow">Features</span>
    <h2 class="sec">특별하지만, 손쉽고 간편하게</h2>
    <p class="lead">전하는 순간부터 그날의 추억까지, 두 사람의 시작을 함께 기록합니다</p>
  </div>
  <div class="fgrid rv-st" data-fx="pop">
    <div class="fc big"><div class="demo"><span>우리 <span class="r">결혼</span>합니다</span><span class="h" style="margin-top:6px;font-size:14px">2026. 11. 14</span></div>
      <div><span class="ic"><svg class="i"><use href="#type"/></svg></span><b>오프닝 타이핑 애니메이션</b><p>청첩장을 여는 순간 한 글자씩 써지는 첫 인사. 굵게·색·형광펜·글꼴을 꾸민 그대로 재생돼요.</p></div></div>
    <div class="fc"><span class="ic"><svg class="i"><use href="#cam"/></svg></span><b>게스트스냅</b><p>하객들이 찍은 사진을 청첩장에 바로 받아볼 수 있어요.</p></div>
    <div class="fc"><span class="ic"><svg class="i"><use href="#mail"/></svg></span><b>참석 여부 받기</b><p>신랑·신부측, 인원, 식사까지. 명단은 엑셀로 받기가 가능해요.</p></div>
    <div class="fc"><span class="ic"><svg class="i"><use href="#note"/></svg></span><b>방명록</b><p>이모티콘·글 꾸미기로 남기는 축하 한마디. 우리의 추억을 영원히 남기세요.</p></div>
    <div class="fc"><span class="ic"><svg class="i"><use href="#cal"/></svg></span><b>D-DAY 하객 알람 서비스</b><p>예식 당일 주차·식사·버스대절 알람이 떠요.</p></div>
    <div class="fc"><span class="ic"><svg class="i"><use href="#share"/></svg></span><b>나만의 카카오톡 공유</b><p>카카오톡으로 공유되는 썸네일·문구를 직접 정할 수 있어요.</p></div>
    <div class="fc"><span class="ic"><svg class="i"><use href="#music"/></svg></span><b>배경음악</b><p>지정된 음악을 고르거나 나만의 음악을 직접 올릴 수 있어요.</p></div>
  </div>
</div></section>

<section id="how"><div class="wrap">
  <div class="rv"><span class="eyebrow">How it works</span><h2 class="sec">마음에 쏙 들 때까지 무료로 만들어 보세요</h2></div>
  <div class="steps rv-st" data-fx="steps">
    <div class="st"><span class="n">1</span><b>테마 고르기</b><p><?= $presetCount ?: '여러' ?>가지 테마 중 하나를 선택해 시작</p></div>
    <div class="st"><span class="n">2</span><b>내용 채우기</b><p>이름·일시·장소·사진을 넣고 레이아웃을 드래그로 배치</p></div>
    <div class="st"><span class="n">3</span><b>스티커 넣기</b><p>소중한 반려가족부터 둘만의 시그니처까지 자유롭게 배치</p></div>
    <div class="st"><span class="n o">4</span><b>마음에 들면 결제</b><p>결제 후 자동으로 워터마크 제거 및 보관 처리</p></div>
  </div>
</div></section>

<section id="price" style="padding-top:20px"><div class="wrap">
  <div class="rv"><span class="eyebrow">Price</span><h2 class="sec">어렵지 않아요, 보관 방식만 결정하세요</h2><p class="lead">디자인과 기능은 모두 같아요. 청첩장을 얼마나 오랫동안 간직할지만 결정하세요.</p></div>
  <div class="pgrid rv-st" data-fx="flip">
    <div class="pcard"><h4>무료체험</h4><div class="pr">0<small>원</small></div>
      <ul><li>모든 디자인·기능</li><li>사진에 워터마크</li><li>생성 후 4일 뒤 자동 삭제</li></ul>
      <?php if ($loggedIn): ?><a class="btn light" href="invite/dashboard.php">내 청첩장으로</a>
      <?php else: ?><form method="post" action="invite/start_free.php"><button class="btn light" type="submit">무료로 시작</button></form><?php endif; ?>
    </div>
    <?php foreach ([['price_one_year', '1년 보관', ['워터마크 제거', 'QR코드 생성', '1년 동안 보관', '보관 기간 동안 무제한 수정'], true],
                    ['price_permanent', '영구 보관', ['워터마크 제거', 'QR코드 생성', '무제한 온라인 보관', '보관 기간 동안 무제한 수정', '소장용 파일 다운로드 제공'], false]] as [$k, $name, $items, $rec]):
        $pv = $price($k); ?>
    <div class="pcard <?= $rec ? 'rec' : '' ?>"><h4><?= $h($name) ?></h4>
      <?php if ($pv !== ''): ?><div class="pr"><?= $h($pv) ?><small>원</small></div><?php else: ?><div class="pr ask">가격 문의</div><?php endif; ?>
      <ul><?php foreach ($items as $it): ?><li><?= $h($it) ?></li><?php endforeach; ?></ul>
      <?php if ($storeUrl): ?><a class="btn <?= $rec ? 'dark' : 'light' ?>" href="<?= $h($storeUrl) ?>" target="_blank" rel="noopener">스토어에서 구매</a>
      <?php else: ?><p class="howto">청첩장을 만든 뒤, 결제하면 자동으로 저장돼요</p><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <p class="pnote">러브데이 서비스 운영 중 온라인 보관이 유지되며, 서비스가 종료되면 영구 소장용 단일 파일 다운로드를 제공합니다.</p>
</div></section>

<section id="faq" style="padding-top:20px"><div class="wrap">
  <div class="center rv"><span class="eyebrow">FAQ</span><h2 class="sec">자주 묻는 질문</h2></div>
  <div class="faq rv-st" data-fx="side">
    <details open><summary>결제 전에 끝까지 만들어 볼 수 있나요?</summary><p>네. 모든 디자인과 기능을 무료로 써 보고, 마음에 들면 결제를 해주세요. 결제 전까지는 사진에 워터마크가 들어가고, 만든 지 4일이 지나면 자동으로 삭제돼요.</p></details>
    <details><summary>보낸 뒤에 수정해도 되나요?</summary><p>네. 에디터에서 수정 후 저장하면 이미 보낸 링크에 바로 반영돼요. 청첩장 주소는 바뀌지 않아요.</p></details>
    <details><summary>하객이 앱을 깔거나 로그인해야 하나요?</summary><p>아니요. 카카오톡·문자로 받은 링크를 누르면 바로 열려요. 참석 회신, 방명록, 사진 올리기도 로그인 없이 할 수 있어요.</p></details>
    <details><summary>게스트스냅 사진은 언제까지 보관되나요?</summary><p>하객 사진은 예식 후 <?= (int) $S['snap_days_after'] ?>일까지 올릴 수 있고, 예식 후 <?= (int) $S['snap_retention_days'] ?>일이 지나면 서버에서 지워져요. 그 전에 신랑·신부가 한 번에 다운로드해 두세요.</p></details>
    <details><summary>회원가입 없이 둘러보기로 만든 건 어떻게 되나요?</summary><p>둘러보기로 만든 청첩장은 <?= $demoHours ?>시간 뒤에 통째로 삭제돼요. 마음에 드셨다면 편집 화면 아래의 <b>네이버로 저장하기</b>를 눌러 주세요. 만들던 그대로 내 계정에 옮겨지고, 무료체험 4일이 새로 시작돼요.</p></details>
    <details><summary>네이버 로그인을 하면 어떤 정보를 가져가나요?</summary><p>같은 사람이 무료체험을 반복해서 만드는 것을 막는 용도로만 써요. 이름·이메일·휴대폰번호는 요청하지 않고, 같은 계정으로 다시 오면 만들던 청첩장을 이어서 편집할 수 있어요.</p></details>
  </div>
</div></section>

<div class="final rv"><span class="eyebrow" style="color:#D9B3B9">Free trial</span><h2>우리의 새로운 이야기,<br>이곳에서 시작하세요</h2><p>네이버 로그인 한 번이면 시작. 가입 없이 먼저 둘러봐도 좋아요.</p>
  <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
    <?php if ($loggedIn): ?><a class="btn light" href="invite/dashboard.php">내 청첩장으로 가기</a>
    <?php else: ?><form class="inline" method="post" action="invite/start_free.php"><button class="btn light" type="submit"><span class="nv">N</span>네이버로 무료 시작</button></form><?php endif; ?>
    <a class="btn ghost" href="invite/try_demo.php">회원가입 없이 둘러보기</a>
  </div>
</div>

<footer><div class="wrap">
  <div class="row">
    <span class="logo" style="color:var(--ink)">LOVE<span>·</span>DAY</span>
    <?php if ($termsUrl): ?><a href="<?= $h($termsUrl) ?>" target="_blank" rel="noopener">이용약관</a><?php endif; ?>
    <a href="privacy.php" data-privacy><b>개인정보처리방침</b></a>
    <?php if ($storeUrl): ?><a href="<?= $h($storeUrl) ?>" target="_blank" rel="noopener">스토어</a><?php endif; ?>
  </div>
  <?php if ($biz): ?>
  <div class="biz"><?php foreach ($biz as $k => $v): ?><span><b><?= $h($k) ?></b><?= $h($v) ?></span><?php endforeach; ?></div>
  <?php endif; ?>
  <div style="margin-top:10px">© <?= date('Y') ?> LOVE DAY</div>
</div></footer>

<!-- 개인정보처리방침 팝업 (내용은 privacy.php?embed=1 을 불러와 넣음. 스크립트가 꺼져 있으면 링크대로 새 페이지) -->
<div class="pvm" id="pvm" hidden>
  <div class="pvm-bg" data-pvm-close></div>
  <div class="pvm-box" role="dialog" aria-modal="true" aria-label="개인정보처리방침">
    <button type="button" class="pvm-x" data-pvm-close aria-label="닫기"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    <div class="pvm-body" id="pvmBody"><p class="pvm-load">불러오는 중…</p></div>
    <div class="pvm-foot"><a href="privacy.php" target="_blank" rel="noopener">새 창으로 보기</a><button type="button" data-pvm-close>확인</button></div>
  </div>
</div>
<style>
.pvm { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 24px; }
.pvm[hidden] { display: none; }
.pvm-bg { position: absolute; inset: 0; background: rgba(27,26,24,.45); -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px); opacity: 0; transition: opacity .2s; }
.pvm-box { position: relative; width: min(720px, 100%); max-height: min(86vh, 900px); display: flex; flex-direction: column; background: #FDFBF7; border-radius: 22px; box-shadow: 0 30px 80px rgba(30,20,15,.28);
  transform: translateY(14px) scale(.98); opacity: 0; transition: transform .25s cubic-bezier(.22,.9,.32,1), opacity .2s; overflow: hidden; }
.pvm.show .pvm-bg { opacity: 1; } .pvm.show .pvm-box { transform: none; opacity: 1; }
.pvm-body { overflow-y: auto; padding: 34px 34px 10px; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; }
.pvm-load { color: var(--faint); text-align: center; padding: 60px 0; }
.pvm-x { position: absolute; top: 14px; right: 14px; z-index: 2; width: 38px; height: 38px; border-radius: 50%; border: 0; background: rgba(27,26,24,.06); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pvm-x svg { width: 18px; height: 18px; fill: none; stroke: var(--ink); stroke-width: 1.8; stroke-linecap: round; }
.pvm-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 22px; border-top: 1px solid var(--line); background: #fff; }
.pvm-foot a { font-size: 13px; color: var(--sub); }
.pvm-foot button { border: 0; border-radius: 10px; background: var(--ink); color: #fff; font-family: inherit; font-weight: 600; font-size: 14px; padding: 12px 26px; cursor: pointer; }
html.pvm-lock, html.pvm-lock body { overflow: hidden; }
@media (max-width: 600px) {
  .pvm { padding: 0; align-items: flex-end; }
  .pvm-box { max-height: 92vh; border-radius: 22px 22px 0 0; transform: translateY(40px); }
  .pvm-body { padding: 26px 20px 8px; }
  .pvm-foot { padding: 12px 18px max(12px, env(safe-area-inset-bottom)); }
}
</style>
<script>
// 개인정보처리방침 팝업 - 처음 열 때 한 번만 불러오고, 여는 동안 뒤 화면이 같이 스크롤되지 않게 고정
(function () {
  var m = document.getElementById('pvm'), body = document.getElementById('pvmBody'), loaded = false, y = 0, last = null;
  if (!m) return;
  function open(e) {
    if (e) e.preventDefault();
    last = document.activeElement;
    m.hidden = false;
    y = window.scrollY; document.documentElement.classList.add('pvm-lock');
    document.body.style.position = 'fixed'; document.body.style.top = -y + 'px'; document.body.style.width = '100%';
    requestAnimationFrame(function () { m.classList.add('show'); m.querySelector('.pvm-x').focus({ preventScroll: true }); });
    if (!loaded) {
      fetch('privacy.php?embed=1', { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.text(); })
        .then(function (html) { body.innerHTML = html; loaded = true; body.scrollTop = 0; })
        .catch(function () { location.href = 'privacy.php'; });
    } else body.scrollTop = 0;
    try { history.pushState({ pvm: 1 }, ''); } catch (err) {}
  }
  function close(fromPop) {
    if (m.hidden) return;
    m.classList.remove('show');
    setTimeout(function () {
      m.hidden = true;
      document.documentElement.classList.remove('pvm-lock');
      document.body.style.position = ''; document.body.style.top = ''; document.body.style.width = '';
      var html = document.documentElement, sb = html.style.scrollBehavior; html.style.scrollBehavior = 'auto'; window.scrollTo(0, y); html.style.scrollBehavior = sb;
      if (last && last.focus) last.focus({ preventScroll: true });
    }, 220);
    if (!fromPop && history.state && history.state.pvm) history.back();
  }
  document.querySelectorAll('[data-privacy]').forEach(function (a) { a.addEventListener('click', open); });
  m.addEventListener('click', function (e) { if (e.target.closest('[data-pvm-close]')) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  window.addEventListener('popstate', function () { close(true); }); // 휴대폰 뒤로 가기 = 팝업만 닫기
  if (location.hash === '#privacy') open();
})();
</script>
<script>
// 휴대폰 화면 속 타이핑
(function () {
    var el = document.getElementById('typed'), text = '우리 결혼합니다', i = 0;
    if (!el) return;
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) { el.textContent = text; return; }
    (function tick() {
        el.textContent = text.slice(0, i);
        if (i < text.length) { i++; setTimeout(tick, 170); } else { setTimeout(function () { i = 0; tick(); }, 2600); }
    })();
})();
// 첫 화면 휴대폰: 마우스를 따라 살짝 기울어지듯 움직임 (PC에서만)
(function () {
    var stage = document.querySelector('.stage'), hero = document.querySelector('.hero');
    if (!stage || !hero || !matchMedia('(hover: hover) and (pointer: fine)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var raf = 0, tx = 0, ty = 0;
    hero.addEventListener('pointermove', function (e) {
        var r = stage.getBoundingClientRect();
        tx = Math.max(-1, Math.min(1, (e.clientX - (r.left + r.width / 2)) / (r.width / 2)));
        ty = Math.max(-1, Math.min(1, (e.clientY - (r.top + r.height / 2)) / (r.height / 2)));
        if (!raf) raf = requestAnimationFrame(function () { raf = 0; stage.style.setProperty('--mx', tx.toFixed(3)); stage.style.setProperty('--my', ty.toFixed(3)); });
    });
    hero.addEventListener('pointerleave', function () { stage.style.setProperty('--mx', 0); stage.style.setProperty('--my', 0); });
})();
// 스크롤하면 나타나기 - .rv는 통째로, .rv-st는 안의 카드가 차례로 (data-fx 모양대로)
(function () {
    document.querySelectorAll('.rv-st').forEach(function (g) { Array.prototype.forEach.call(g.children, function (c, i) { c.style.setProperty('--i', i); }); });
    var els = document.querySelectorAll('.rv, .rv-st');
    if (!('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('in'); }); return; }
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) {
        if (!e.isIntersecting) return;
        var t = e.target; t.classList.add('in'); io.unobserve(t);
        if (t.classList.contains('rv-st')) setTimeout(function () { t.classList.add('done'); }, 2200); // 다 나타난 뒤엔 마우스 반응이 바로바로
    }); }, { rootMargin: '0px 0px -12% 0px', threshold: .08 });
    els.forEach(function (e) { io.observe(e); });
})();
// 스크롤에 따라: 맨 위 진행 선 + 첫 화면 휴대폰 벌어짐
(function () {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var root = document.documentElement, hero = document.querySelector('.hero'), stage = document.querySelector('.stage'), raf = 0;
    function onScroll() {
        raf = 0;
        var max = root.scrollHeight - innerHeight, y = scrollY;
        root.style.setProperty('--p', max > 0 ? (y / max).toFixed(4) : 0);
        if (hero) { var hs = Math.max(0, Math.min(1, y / (hero.offsetHeight * .9))); hero.style.setProperty('--hs', hs.toFixed(3)); if (stage) stage.style.setProperty('--hs', hs.toFixed(3)); }
    }
    addEventListener('scroll', function () { if (!raf) raf = requestAnimationFrame(onScroll); }, { passive: true });
    onScroll();
})();
// 샘플 카드 입체 기울기 (PC)
(function () {
    if (!matchMedia('(hover: hover) and (pointer: fine)').matches || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    document.querySelectorAll('.sm').forEach(function (c) {
        c.addEventListener('pointermove', function (e) {
            var r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
            c.style.setProperty('--ry', (x * 14).toFixed(1) + 'deg'); c.style.setProperty('--rx', (-y * 14).toFixed(1) + 'deg');
        });
        c.addEventListener('pointerleave', function () { c.style.setProperty('--ry', '0deg'); c.style.setProperty('--rx', '0deg'); });
    });
})();
</script>
</body>
</html>
