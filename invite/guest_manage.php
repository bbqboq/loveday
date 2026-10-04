<?php
/**
 * guest_manage.php?t=편집토큰&tab=rsvp|guestbook - 신랑신부용 "참석여부 명단 · 방명록" 관리 페이지
 * 내 청첩장 관리 화면(invite_edit.php)의 카드, 에디터의 참석여부/방명록 섹션 편집창에서 들어온다.
 *
 *  - 참석여부: 신랑측/신부측 인원 합계, 식사 인원, 명단(연락처는 여기서만 보임), 엑셀용 CSV 다운로드, 삭제
 *  - 방명록: 전체 글 보기, 삭제
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php';

$pdo = get_pdo();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$invite = snap_owner_invite($pdo, $token);
if (!$invite) {
    http_response_code(403);
    exit('편집 링크가 올바르지 않거나 PIN 확인이 필요합니다. 내 청첩장 관리 화면에서 다시 들어와주세요.');
}
$invitationId = (int) $invite['id'];
$tab = ($_GET['tab'] ?? '') === 'guestbook' ? 'guestbook' : 'rsvp';
$labels = array_merge(['groom' => '신랑', 'bride' => '신부'], array_filter((array) ((snap_design($invite) ?? [])['labels'] ?? []), 'is_string'));
$sideName = fn(string $s) => ($s === 'bride' ? $labels['bride'] : $labels['groom']) . '측';
$mealName = ['yes' => '식사함', 'no' => '안 함', 'unknown' => '미정'];

$tableReady = true;
try { $pdo->query('SELECT 1 FROM rsvp_responses LIMIT 1'); $pdo->query('SELECT 1 FROM guestbook_entries LIMIT 1'); }
catch (Throwable $e) { $tableReady = false; }

$notice = '';
if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $ids = array_map('intval', (array) ($_POST['delete_ids'] ?? []));
    if ($ids) {
        $table = $tab === 'guestbook' ? 'guestbook_entries' : 'rsvp_responses';
        $del = $pdo->prepare("DELETE FROM {$table} WHERE invitation_id = ? AND id = ?");
        $n = 0;
        foreach ($ids as $id) { $del->execute([$invitationId, $id]); $n += $del->rowCount(); }
        $notice = $n . '건 삭제했어요.';
    }
}

$rsvps = []; $entries = [];
if ($tableReady) {
    $st = $pdo->prepare('SELECT * FROM rsvp_responses WHERE invitation_id = ? ORDER BY COALESCE(updated_at, created_at) DESC');
    $st->execute([$invitationId]);
    $rsvps = $st->fetchAll();
    foreach ($rsvps as &$r) { $r['phone'] = $r['phone_enc'] ? (string) decrypt_data($r['phone_enc']) : ''; } unset($r);
    $st = $pdo->prepare('SELECT id, author, message, created_at FROM guestbook_entries WHERE invitation_id = ? ORDER BY id DESC');
    $st->execute([$invitationId]);
    $entries = $st->fetchAll();
}

// ---- 엑셀용 CSV 다운로드 (한글 깨짐 방지 BOM 포함) ----
if ($tableReady && $tab === 'rsvp' && isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rsvp_' . $invite['view_slug'] . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['구분', '참석', '성함', '인원', '식사', '연락처', '전하는 말', '보낸 시각']);
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v; // 엑셀 수식 주입 방지
    foreach ($rsvps as $r) {
        fputcsv($out, array_map($safe, [$sideName($r['side']), $r['attend'] === 'yes' ? '참석' : '불참', $r['guest_name'],
            $r['attend'] === 'yes' ? (int) $r['headcount'] : 0, $r['attend'] === 'yes' ? $mealName[$r['meal']] : '-',
            $r['phone'], (string) $r['memo'], $r['updated_at'] ?: $r['created_at']]));
    }
    fclose($out);
    exit;
}

$sum = rsvp_summary($pdo, $invitationId);
$rsvpOn = guest_block($invite, 'rsvp') !== null;
$gbOn = guest_block($invite, 'guestbook') !== null;
$wedding = snap_wedding_date($invite);
$retention = app_setting_int('rsvp_retention_days');
$csrf = csrf_token();
$tk = rawurlencode($token);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>참석여부 · 방명록 관리</title>
<meta name="referrer" content="no-referrer">
<meta name="robots" content="noindex">
<style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #F6F5F1; color: #232320; font-family: "Pretendard Variable", Pretendard, -apple-system, sans-serif; font-size: 14px; }
    .top { background: #fff; border-bottom: 1px solid #E4E1D9; padding: 14px 20px; display: flex; align-items: center; gap: 10px; }
    .top a { color: #3D4F66; text-decoration: none; font-size: 13px; }
    .wrap { max-width: 980px; margin: 0 auto; padding: 20px 20px 60px; }
    .tabs { display: flex; gap: 6px; margin-bottom: 16px; }
    .tabs a { padding: 9px 16px; border-radius: 999px; background: #fff; border: 1px solid #E4E1D9; color: #3D4F66; text-decoration: none; font-size: 13px; }
    .tabs a.on { background: #3D4F66; color: #fff; border-color: #3D4F66; }
    .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 16px; }
    .card { background: #fff; border: 1px solid #E4E1D9; border-radius: 12px; padding: 14px 16px; }
    .card h3 { margin: 0 0 6px; font-size: 12.5px; color: #6B675F; font-weight: 600; }
    .big { font-size: 22px; font-weight: 700; }
    .sub { font-size: 12px; color: #8A857C; margin-top: 2px; }
    .btn { display: inline-block; border: 1px solid #3D4F66; background: #3D4F66; color: #fff; border-radius: 999px; padding: 8px 15px; font-size: 13px; cursor: pointer; text-decoration: none; font-family: inherit; }
    .btn.line { background: #fff; color: #3D4F66; }
    .btn.danger { background: #fff; color: #B03A3A; border-color: #B03A3A; }
    .notice { background: #EAF5EC; color: #2F6B3B; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; }
    .warn { background: #FFF6E5; color: #8A5A00; border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; line-height: 1.6; }
    .toolbar { display: flex; gap: 8px; align-items: center; margin: 4px 0 10px; flex-wrap: wrap; }
    .filters { display: flex; gap: 6px; flex-wrap: wrap; }
    .filters button { border: 1px solid #E4E1D9; background: #fff; border-radius: 999px; padding: 6px 12px; font-size: 12.5px; cursor: pointer; font-family: inherit; color: #555; }
    .filters button.on { background: #232320; color: #fff; border-color: #232320; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E4E1D9; border-radius: 12px; overflow: hidden; }
    th, td { padding: 10px 10px; border-bottom: 1px solid #F0EEE8; text-align: left; font-size: 13px; vertical-align: top; }
    th { background: #FAF9F6; font-weight: 600; color: #6B675F; font-size: 12px; white-space: nowrap; }
    td.memo { color: #555; max-width: 260px; white-space: pre-wrap; word-break: break-all; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
    .tag.groom { background: #E8F0F3; color: #3F6B7A; } .tag.bride { background: #F7EAEA; color: #9A4F50; }
    .tag.yes { background: #EAF5EC; color: #2F6B3B; } .tag.no { background: #EEE; color: #777; }
    .gb { background: #fff; border: 1px solid #E4E1D9; border-radius: 12px; padding: 12px 14px; margin-bottom: 8px; display: flex; gap: 10px; }
    .gb .who { font-weight: 700; } .gb .when { font-size: 12px; color: #8A857C; margin-left: 6px; font-weight: 400; }
    .gb p { margin: 6px 0 0; white-space: pre-wrap; word-break: break-all; line-height: 1.6; }
    .empty { background: #fff; border: 1px dashed #D8D4CA; border-radius: 12px; padding: 30px; text-align: center; color: #8A857C; }
    @media (max-width: 640px) { .hide-m { display: none; } th, td { padding: 9px 6px; } }
</style>
</head>
<body>
<div class="top">
    <strong>💌 참석여부 · 방명록</strong>
    <span style="flex:1"></span>
    <a href="dashboard.php?t=<?= snap_h($token) ?>">← 내 청첩장으로</a>
</div>
<div class="wrap">
    <?php if (!$tableReady): ?>
        <div class="warn">참석여부·방명록 테이블이 아직 없어요. 서버에서 <b>stage4_setup.sql</b>을 먼저 실행해주세요.</div>
    <?php else: ?>
    <div class="tabs">
        <a class="<?= $tab === 'rsvp' ? 'on' : '' ?>" href="?t=<?= $tk ?>&tab=rsvp">참석여부 (<?= $sum['total'] ?>)</a>
        <a class="<?= $tab === 'guestbook' ? 'on' : '' ?>" href="?t=<?= $tk ?>&tab=guestbook">방명록 (<?= count($entries) ?>)</a>
    </div>
    <?php if ($notice): ?><div class="notice"><?= snap_h($notice) ?></div><?php endif; ?>

    <?php if ($tab === 'rsvp'): ?>
        <?php if (!$rsvpOn): ?>
            <div class="warn">청첩장에 <b>참석여부</b> 섹션이 꺼져 있어서 지금은 응답을 받지 않아요. 디자인 편집 → 섹션 목록에서 "참석 의사 전달"을 켜고 저장하세요.</div>
        <?php endif; ?>
        <div class="cards">
            <div class="card"><h3>참석 인원 (본인 포함)</h3><div class="big"><?= $sum['people'] ?>명</div><div class="sub">응답 <?= $sum['yes'] ?>건</div></div>
            <div class="card"><h3><?= snap_h($sideName('groom')) ?></h3><div class="big"><?= $sum['groomPeople'] ?>명</div><div class="sub">응답 <?= $sum['groom'] ?>건</div></div>
            <div class="card"><h3><?= snap_h($sideName('bride')) ?></h3><div class="big"><?= $sum['bridePeople'] ?>명</div><div class="sub">응답 <?= $sum['bride'] ?>건</div></div>
            <div class="card"><h3>식사 인원</h3><div class="big"><?= $sum['mealYes'] ?>명</div><div class="sub">미정 <?= $sum['mealUnknown'] ?>명</div></div>
            <div class="card"><h3>불참</h3><div class="big"><?= $sum['no'] ?>건</div></div>
        </div>
        <p class="sub" style="margin:0 0 12px;">
            하객이 같은 휴대폰으로 다시 보내면 새로 쌓이지 않고 수정돼요.
            <?php if ($wedding): ?>명단(이름·연락처)은 예식일 <?= $retention ?>일 뒤(<?= $wedding->modify('+' . $retention . ' days')->format('Y.m.d') ?>)에 자동 삭제되니 필요하면 미리 받아두세요.<?php endif; ?>
        </p>
        <?php if (!$rsvps): ?>
            <div class="empty">아직 받은 참석 여부가 없어요.</div>
        <?php else: ?>
        <form method="post" onsubmit="return confirm('선택한 응답을 삭제할까요?');">
            <input type="hidden" name="csrf_token" value="<?= snap_h($csrf) ?>">
            <input type="hidden" name="t" value="<?= snap_h($token) ?>">
            <div class="toolbar">
                <div class="filters" id="filters">
                    <button type="button" class="on" data-f="all">전체</button>
                    <button type="button" data-f="yes">참석</button>
                    <button type="button" data-f="no">불참</button>
                    <button type="button" data-f="groom"><?= snap_h($sideName('groom')) ?></button>
                    <button type="button" data-f="bride"><?= snap_h($sideName('bride')) ?></button>
                </div>
                <span style="flex:1"></span>
                <a class="btn line" href="?t=<?= $tk ?>&tab=rsvp&csv=1">📥 엑셀(CSV) 받기</a>
                <button class="btn danger" type="submit" formaction="?t=<?= $tk ?>&tab=rsvp">선택 삭제</button>
            </div>
            <table>
                <thead><tr><th style="width:28px;"><input type="checkbox" id="checkAll"></th><th>구분</th><th>참석</th><th>성함</th><th>인원</th><th>식사</th><th class="hide-m">연락처</th><th>전하는 말</th><th class="hide-m">보낸 날</th></tr></thead>
                <tbody>
                <?php foreach ($rsvps as $r): ?>
                    <tr data-side="<?= snap_h($r['side']) ?>" data-attend="<?= snap_h($r['attend']) ?>">
                        <td><input type="checkbox" name="delete_ids[]" value="<?= (int) $r['id'] ?>"></td>
                        <td><span class="tag <?= snap_h($r['side']) ?>"><?= snap_h($sideName($r['side'])) ?></span></td>
                        <td><span class="tag <?= snap_h($r['attend']) ?>"><?= $r['attend'] === 'yes' ? '참석' : '불참' ?></span></td>
                        <td><?= snap_h($r['guest_name']) ?></td>
                        <td><?= $r['attend'] === 'yes' ? (int) $r['headcount'] . '명' : '-' ?></td>
                        <td><?= $r['attend'] === 'yes' ? snap_h($mealName[$r['meal']] ?? '-') : '-' ?></td>
                        <td class="hide-m"><?= $r['phone'] !== '' ? '<a href="tel:' . snap_h($r['phone']) . '">' . snap_h(preg_replace('/^(\d{3})(\d{3,4})(\d{4})$/', '$1-$2-$3', $r['phone'])) . '</a>' : '-' ?></td>
                        <td class="memo"><?= snap_h((string) $r['memo']) ?></td>
                        <td class="hide-m"><?= snap_h(date('m.d H:i', strtotime((string) ($r['updated_at'] ?: $r['created_at'])))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <?php endif; ?>

    <?php else: ?>
        <?php if (!$gbOn): ?>
            <div class="warn">청첩장에 <b>방명록</b> 섹션이 꺼져 있어서 하객에게 보이지 않아요. 디자인 편집 → 섹션 목록에서 "방명록"을 켜고 저장하세요.</div>
        <?php endif; ?>
        <?php if (!$entries): ?>
            <div class="empty">아직 방명록 글이 없어요.</div>
        <?php else: ?>
        <form method="post" action="?t=<?= $tk ?>&tab=guestbook" onsubmit="return confirm('선택한 글을 삭제할까요? 하객 화면에서도 바로 사라져요.');">
            <input type="hidden" name="csrf_token" value="<?= snap_h($csrf) ?>">
            <input type="hidden" name="t" value="<?= snap_h($token) ?>">
            <div class="toolbar"><span class="sub">전체 <?= count($entries) ?>개</span><span style="flex:1"></span><button class="btn danger" type="submit">선택 삭제</button></div>
            <?php foreach ($entries as $e): ?>
                <label class="gb">
                    <input type="checkbox" name="delete_ids[]" value="<?= (int) $e['id'] ?>" style="margin-top:3px;">
                    <div style="flex:1;min-width:0;">
                        <span class="who"><?= snap_h($e['author']) ?></span><span class="when"><?= snap_h(date('Y.m.d H:i', strtotime((string) $e['created_at']))) ?></span>
                        <p><?= snap_h($e['message']) ?></p>
                    </div>
                </label>
            <?php endforeach; ?>
        </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
<script>
(function () {
    const all = document.getElementById('checkAll');
    if (all) all.addEventListener('change', () => document.querySelectorAll('tbody tr:not([hidden]) input[name="delete_ids[]"]').forEach(c => c.checked = all.checked));
    const f = document.getElementById('filters');
    if (f) f.addEventListener('click', e => {
        const b = e.target.closest('[data-f]'); if (!b) return;
        f.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
        const v = b.dataset.f;
        document.querySelectorAll('tbody tr').forEach(tr => {
            tr.hidden = !(v === 'all' || tr.dataset.attend === v || tr.dataset.side === v);
        });
    });
})();
</script>
<script src="assets/ld-dialog.js"></script>
</body>
</html>
