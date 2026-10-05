<?php
/**
 * admin_subadmins.php - 부관리자 관리 (대표 관리자만)
 *  - 부관리자 아이디·비밀번호 만들기
 *  - 권한을 항목별로 켜고 끄기 (아이폰식 스위치, 누르면 바로 저장 → 부관리자의 다음 클릭부터 적용)
 *  - 계정 사용 중지 / 비밀번호 바꾸기 (바꾸면 그 부관리자는 바로 로그아웃) / 삭제
 *  권한 목록과 기본값: admin_auth.php의 ADMIN_PERMS
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin_guard.php'; // 대표만 들어올 수 있음 (ADMIN_PAGE_RULES)

$pdo = get_pdo();
admin_users_ensure($pdo);
$error = '';
$notice = '';
$old = ['username' => '', 'display_name' => ''];

function sa_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function sa_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string) ($_POST['action'] ?? '');
    $ajax = isset($_POST['ajax']);
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create') {
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $display  = mb_substr(trim(strip_tags((string) ($_POST['display_name'] ?? ''))), 0, 20);
        $pw  = (string) ($_POST['password'] ?? '');
        $pw2 = (string) ($_POST['password2'] ?? '');
        $old = ['username' => $username, 'display_name' => $display];
        $perms = [];
        foreach (ADMIN_PERMS as $k => $_) $perms[$k] = !empty($_POST['perm'][$k]);

        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) $error = '아이디는 영문 소문자·숫자·밑줄(_) 3~20자로 만들어주세요.';
        elseif (in_array($username, ['admin', 'root', 'owner', 'loveday'], true)) $error = '이 아이디는 쓸 수 없어요.';
        elseif (mb_strlen($pw) < 8) $error = '비밀번호는 8자 이상으로 해주세요.';
        elseif ($pw !== $pw2) $error = '비밀번호 확인이 달라요.';
        else {
            $dup = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
            $dup->execute([$username]);
            if ($dup->fetch()) $error = '이미 있는 아이디예요.';
            else {
                $pdo->prepare('INSERT INTO admin_users (username, display_name, password_hash, perms) VALUES (?, ?, ?, ?)')
                    ->execute([$username, $display, password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]), json_encode($perms)]);
                header('Location: admin_subadmins.php?created=' . rawurlencode($username) . '#u-' . (int) $pdo->lastInsertId());
                exit;
            }
        }
    } else {
        $u = sa_get($pdo, $id);
        if (!$u) { $ajax ? sa_json(['ok' => false, 'error' => '부관리자를 찾지 못했어요.'], 404) : ($error = '부관리자를 찾지 못했어요.'); }
        elseif ($action === 'perm') {
            $key = (string) ($_POST['key'] ?? '');
            if (!isset(ADMIN_PERMS[$key])) sa_json(['ok' => false, 'error' => '없는 권한이에요.'], 400);
            $perms = admin_perms_decode($u['perms']);
            $perms[$key] = ($_POST['value'] ?? '') === '1';
            $pdo->prepare('UPDATE admin_users SET perms = ? WHERE id = ?')->execute([json_encode($perms), $id]);
            sa_json(['ok' => true, 'on' => $perms[$key], 'count' => count(array_filter($perms))]);
        } elseif ($action === 'perm_all') {
            $mode = (string) ($_POST['value'] ?? '');
            $perms = $mode === 'default' ? admin_perm_defaults() : array_fill_keys(array_keys(ADMIN_PERMS), $mode === '1');
            $pdo->prepare('UPDATE admin_users SET perms = ? WHERE id = ?')->execute([json_encode($perms), $id]);
            sa_json(['ok' => true, 'perms' => $perms, 'count' => count(array_filter($perms))]);
        } elseif ($action === 'active') {
            $on = ($_POST['value'] ?? '') === '1';
            $pdo->prepare('UPDATE admin_users SET active = ? WHERE id = ?')->execute([$on ? 1 : 0, $id]);
            sa_json(['ok' => true, 'on' => $on]);
        } elseif ($action === 'password') {
            $pw = (string) ($_POST['password'] ?? '');
            if (mb_strlen($pw) < 8) sa_json(['ok' => false, 'error' => '비밀번호는 8자 이상으로 해주세요.'], 400);
            // pw_changed_at을 1초 뒤로 → 지금 로그인해 있는 그 부관리자는 다음 클릭에 로그아웃
            $pdo->prepare('UPDATE admin_users SET password_hash = ?, pw_changed_at = DATE_ADD(NOW(), INTERVAL 1 SECOND) WHERE id = ?')
                ->execute([password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
            sa_json(['ok' => true]);
        } elseif ($action === 'rename') {
            $display = mb_substr(trim(strip_tags((string) ($_POST['display_name'] ?? ''))), 0, 20);
            $pdo->prepare('UPDATE admin_users SET display_name = ? WHERE id = ?')->execute([$display, $id]);
            sa_json(['ok' => true, 'name' => $display]);
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
            header('Location: admin_subadmins.php?deleted=' . rawurlencode((string) $u['username']));
            exit;
        } else {
            $ajax ? sa_json(['ok' => false, 'error' => '잘못된 요청이에요.'], 400) : ($error = '잘못된 요청이에요.');
        }
    }
}
if (isset($_GET['created'])) $notice = '부관리자 "' . $_GET['created'] . '"를 만들었어요. 아이디와 비밀번호를 전달해주세요 (로그인 화면에서 아이디 + 비밀번호).';
if (isset($_GET['deleted'])) $notice = '부관리자 "' . $_GET['deleted'] . '"를 삭제했어요. 로그인해 있었다면 바로 로그아웃돼요.';

$users = $pdo->query('SELECT * FROM admin_users ORDER BY id')->fetchAll();
$groups = [];
foreach (ADMIN_PERMS as $k => $p) $groups[$p[0]][$k] = $p;
$groupIcon = ['청첩장' => '💌', '개인정보' => '🔐', '휴지통' => '🗑', '설정' => '⚙️'];
$csrf = csrf_token();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** 아이폰식 스위치 */
function sa_switch(string $name, bool $on, array $attrs = []): string
{
    $a = '';
    foreach ($attrs as $k => $v) $a .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '"';
    return '<label class="sw"><input type="checkbox" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" value="1"' . ($on ? ' checked' : '') . $a . '><i></i></label>';
}
/** 권한 목록 (묶음별) */
function sa_perm_list(array $groups, array $groupIcon, array $perms, string $namePrefix, bool $live): string
{
    ob_start();
    foreach ($groups as $g => $items): ?>
        <div class="pg">
            <div class="pg-t"><?= $groupIcon[$g] ?? '' ?> <?= htmlspecialchars($g, ENT_QUOTES, 'UTF-8') ?></div>
            <?php foreach ($items as $k => $p): ?>
                <div class="pr<?= $k === 'trash_purge' || $k === 'customer_private' ? ' risky' : '' ?>">
                    <div class="pr-t"><b><?= htmlspecialchars($p[1], ENT_QUOTES, 'UTF-8') ?></b><small><?= htmlspecialchars($p[2], ENT_QUOTES, 'UTF-8') ?></small></div>
                    <?= sa_switch($namePrefix . '[' . $k . ']', !empty($perms[$k]), $live ? ['data-perm' => $k] : []) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach;
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>부관리자 - 관리자</title>
<meta name="referrer" content="no-referrer">
<link rel="stylesheet" href="assets/admin.css">
<style>
.sa, .sa * { box-sizing: border-box; }
.sa-lead { font-size: 13px; color: var(--muted); margin: -14px 0 20px; line-height: 1.7; }

/* 아이폰식 스위치 */
.sw { position: relative; flex: none; display: inline-block; width: 46px; height: 28px; cursor: pointer; }
.sw input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 1; }
.sw i { position: absolute; inset: 0; border-radius: 999px; background: var(--ui-line, #E3E0DB); transition: background .2s; }
.sw i::after { content: ''; position: absolute; top: 2px; left: 2px; width: 24px; height: 24px; border-radius: 50%; background: #fff; box-shadow: 0 2px 5px rgba(0, 0, 0, .22); transition: transform .22s cubic-bezier(.3, .7, .4, 1.2); }
.sw input:checked + i { background: #34C759; }
.sw input:checked + i::after { transform: translateX(18px); }
.sw input:focus-visible + i { box-shadow: 0 0 0 3px rgba(52, 199, 89, .3); }
.sw input:disabled + i { opacity: .5; }
.pr.risky .sw input:checked + i { background: #FF9F0A; } /* 위험한 권한은 켜면 주황 */

/* 권한 목록 */
.perms { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.pg { background: #FCFBF9; border: 1px solid var(--ui-line, #F0ECE6); border-radius: 14px; padding: 6px 14px; }
.pg-t { font-size: 12px; font-weight: 700; color: #8A847B; padding: 8px 0 4px; letter-spacing: .02em; }
.pr { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid var(--ui-line, #F0ECE6); }
.pg-t + .pr { border-top: 0; }
.pr-t { flex: 1; min-width: 0; }
.pr-t b { display: block; font-size: 13.5px; font-weight: 600; }
.pr-t small { display: block; font-size: 11.5px; color: #A29C94; line-height: 1.5; margin-top: 1px; }

/* 부관리자 카드 */
.su { background: #fff; border: 1px solid var(--ui-line, #ECE8E2); border-radius: 16px; margin: 0 0 16px; overflow: hidden; }
.su.off { opacity: .72; }
.su-h { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--ui-soft, #F1EEE9); flex-wrap: wrap; }
.su-av { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; background: #EEF2F7; color: #4A5A73; font-weight: 800; font-size: 16px; }
.su-id { flex: 1; min-width: 140px; }
.su-id b { font-size: 15px; } .su-id b[contenteditable] { outline: none; border-radius: 6px; padding: 0 3px; margin-left: -3px; }
.su-id b[contenteditable]:focus { background: var(--ui-soft, #F6F3EE); }
.su-id small { display: block; font-size: 12px; color: #8A847B; margin-top: 1px; }
.su-id code { font-size: 12px; }
.su-act { display: flex; align-items: center; gap: 8px; }
.su-act .lbl { font-size: 12.5px; color: #6F6A63; }
.su-b { padding: 14px 16px; }
.su-tools { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin: 0 0 12px; }
.su-tools .cnt { font-size: 12.5px; color: #6F6A63; margin-right: auto; }
.su-tools .cnt b { color: #2B2B2B; }
.chip { border: 1px solid var(--ui-line, #E3DED6); background: #fff; border-radius: 999px; padding: 6px 12px; font: inherit; font-size: 12.5px; cursor: pointer; color: #555; }
.chip:hover { background: var(--ui-soft, #F6F3EE); }
.chip.danger { color: #A8434B; border-color: #EBCDD0; }
.pwbox { display: none; gap: 8px; align-items: center; margin: 0 0 12px; padding: 10px 12px; background: var(--ui-soft, #F6F3EE); border-radius: 12px; flex-wrap: wrap; }
.pwbox.on { display: flex; }
.pwbox input { flex: 1; min-width: 160px; padding: 9px 10px; border: 1px solid var(--ui-line, #E3DED6); border-radius: 9px; font: inherit; }
.pwbox small { width: 100%; font-size: 11.5px; color: #8A847B; }
.su-empty { padding: 26px; text-align: center; color: #A29C94; border: 1px dashed var(--ui-line, #DDD7CE); border-radius: 14px; margin: 0 0 16px; font-size: 13.5px; }

/* 새로 만들기 */
.new-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px 14px; margin: 0 0 14px; }
.new-grid label { display: block; font-size: 12.5px; color: #6F6A63; margin: 0 0 5px; }
.new-grid input { width: 100%; padding: 10px 11px; border: 1px solid var(--ui-line, #E3DED6); border-radius: 10px; font: inherit; font-size: 14px; }
.new-grid .pwin { position: relative; display: block; }
.new-grid .pwin input { padding-right: 58px; }
.new-grid .eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; font-size: 12px; color: #8A847B; cursor: pointer; padding: 5px 6px; }
.gen { font-size: 12px; margin-top: 5px; }
.gen button { border: 0; background: none; color: #4A5A73; text-decoration: underline; cursor: pointer; font: inherit; padding: 0; }
.new-foot { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.new-foot small { font-size: 12px; color: #8A847B; }
@media (max-width: 720px) {
    .perms { grid-template-columns: 1fr; }
    .su-h, .su-b { padding-left: 14px; padding-right: 14px; }
}
</style>
<?= site_colors_link() ?><!-- 관리자가 정한 사이트 화면 색 -->
</head>
<body>
    <?php require_once __DIR__ . '/admin_nav.php'; admin_topbar('subadmin', '부관리자'); ?>

    <div class="wrap sa">
        <h2 class="page-title">부관리자</h2>
        <p class="sa-lead">
            부관리자는 로그인 화면에서 <b>아이디 + 비밀번호</b>로 들어옵니다. 스위치를 켜면 그 일을 할 수 있고, 끄면 막혀요.
            바꾸면 바로 저장되고 부관리자의 <b>다음 클릭부터</b> 적용돼요.
            <span style="color:#B7791F">주황 스위치</span>는 개인정보·되돌릴 수 없는 삭제라 신중하게 켜주세요.
            부관리자 관리·서버 시간 점검·스마트스토어 API 키는 대표 관리자만 할 수 있어요.
        </p>

        <?php if ($notice): ?><p class="notice success"><?= $h($notice) ?></p><?php endif; ?>
        <?php if ($error): ?><p class="notice error"><?= $h($error) ?></p><?php endif; ?>

        <?php if (!$users): ?><div class="su-empty">아직 부관리자가 없어요. 아래에서 만들어주세요.</div><?php endif; ?>

        <?php foreach ($users as $u):
            $perms = admin_perms_decode($u['perms']);
            $name = $u['display_name'] ?: $u['username'];
        ?>
        <div class="su<?= (int) $u['active'] ? '' : ' off' ?>" id="u-<?= (int) $u['id'] ?>" data-id="<?= (int) $u['id'] ?>" data-username="<?= $h($u['username']) ?>">
            <div class="su-h">
                <div class="su-av"><?= $h(mb_substr($name, 0, 1)) ?></div>
                <div class="su-id">
                    <b contenteditable="plaintext-only" spellcheck="false" data-rename title="눌러서 이름 바꾸기"><?= $h($u['display_name']) ?: $h($u['username']) ?></b>
                    <small>아이디 <code><?= $h($u['username']) ?></code> · 최근 로그인 <?= $u['last_login_at'] ? $h(date('m-d H:i', strtotime((string) $u['last_login_at']))) : '아직 없음' ?></small>
                </div>
                <div class="su-act"><span class="lbl" data-active-label><?= (int) $u['active'] ? '사용 중' : '사용 중지' ?></span><?= sa_switch('active', (bool) (int) $u['active'], ['data-active' => '1']) ?></div>
            </div>
            <div class="su-b">
                <div class="su-tools">
                    <span class="cnt">권한 <b data-count><?= count(array_filter($perms)) ?></b> / <?= count(ADMIN_PERMS) ?>개 켜짐</span>
                    <button type="button" class="chip" data-all="default">기본값</button>
                    <button type="button" class="chip" data-all="1">모두 켜기</button>
                    <button type="button" class="chip" data-all="0">모두 끄기</button>
                    <button type="button" class="chip" data-pw>비밀번호 바꾸기</button>
                    <button type="button" class="chip danger" data-del>삭제</button>
                </div>
                <div class="pwbox">
                    <input type="text" placeholder="새 비밀번호 (8자 이상)" autocomplete="new-password" data-pwinput>
                    <button type="button" class="chip" data-gen>자동 만들기</button>
                    <button type="button" class="btn btn-sm" data-pwsave>바꾸기</button>
                    <small>바꾸면 이 부관리자는 바로 로그아웃되고, 새 비밀번호로 다시 로그인해야 해요.</small>
                </div>
                <div class="perms"><?= sa_perm_list($groups, $groupIcon, $perms, 'p' . (int) $u['id'], true) ?></div>
            </div>
        </div>
        <?php endforeach; ?>

        <h2 class="page-title" style="margin-top:30px;">새 부관리자 만들기</h2>
        <div class="panel">
            <form method="post" autocomplete="off" id="newForm">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="create">
                <div class="new-grid">
                    <div><label>아이디 (영문 소문자·숫자·_ 3~20자)</label><input name="username" value="<?= $h($old['username']) ?>" required pattern="[a-z0-9_]{3,20}" autocapitalize="none" spellcheck="false" placeholder="예: staff01"></div>
                    <div><label>이름 (화면에 보일 이름, 선택)</label><input name="display_name" value="<?= $h($old['display_name']) ?>" maxlength="20" placeholder="예: 김매니저"></div>
                    <div class="pwrow"><label>비밀번호 (8자 이상)</label><span class="pwin"><input type="password" name="password" id="npw" required minlength="8" autocomplete="new-password"><button type="button" class="eye" data-eye="npw">보기</button></span>
                        <div class="gen"><button type="button" id="npwGen">안전한 비밀번호 자동 만들기</button></div></div>
                    <div class="pwrow"><label>비밀번호 확인</label><span class="pwin"><input type="password" name="password2" id="npw2" required minlength="8" autocomplete="new-password"><button type="button" class="eye" data-eye="npw2">보기</button></span></div>
                </div>
                <div class="su-tools" style="margin-bottom:10px;"><span class="cnt">권한 (처음엔 기본값 - 개인정보·완전 삭제·설정 변경은 꺼져 있어요)</span>
                    <button type="button" class="chip" data-newall="1">모두 켜기</button><button type="button" class="chip" data-newall="0">모두 끄기</button></div>
                <div class="perms"><?= sa_perm_list($groups, $groupIcon, $_SERVER['REQUEST_METHOD'] === 'POST' && $error ? array_map(fn($v) => (bool) $v, (array) ($_POST['perm'] ?? [])) : admin_perm_defaults(), 'perm', false) ?></div>
                <div class="new-foot"><button type="submit" class="btn">부관리자 만들기</button><small>만든 뒤 아이디와 비밀번호를 직접 전달해주세요. 비밀번호는 다시 볼 수 없어요 (바꾸기만 가능).</small></div>
            </form>
        </div>
    </div>

<script src="assets/ld-dialog.js"></script>
<script>
(function () {
    const CSRF = <?= json_encode($csrf) ?>;
    const toast = m => window.LD ? LD.toast(m) : null;
    const alertMsg = m => window.LD ? LD.alert(m) : alert(m);
    function post(data) {
        const fd = new FormData();
        fd.set('csrf_token', CSRF); fd.set('ajax', '1');
        Object.entries(data).forEach(([k, v]) => fd.set(k, v));
        return fetch('admin_subadmins.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json()).then(j => { if (!j.ok) throw new Error(j.error || '저장하지 못했어요.'); return j; });
    }
    function genPw() {
        const c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
        const a = new Uint32Array(14); crypto.getRandomValues(a);
        return Array.from(a, n => c[n % c.length]).join('');
    }

    document.querySelectorAll('.su').forEach(card => {
        const id = card.dataset.id;
        const setCount = n => card.querySelector('[data-count]').textContent = n;
        // 권한 스위치 - 누르면 바로 저장
        card.querySelectorAll('[data-perm]').forEach(sw => sw.addEventListener('change', () => {
            sw.disabled = true;
            post({ action: 'perm', id, key: sw.dataset.perm, value: sw.checked ? '1' : '0' })
                .then(j => { setCount(j.count); toast((sw.checked ? '켰어요: ' : '껐어요: ') + sw.closest('.pr').querySelector('b').textContent); })
                .catch(e => { sw.checked = !sw.checked; alertMsg(e.message); })
                .finally(() => sw.disabled = false);
        }));
        // 모두 켜기·끄기·기본값
        card.querySelectorAll('[data-all]').forEach(b => b.addEventListener('click', () => {
            post({ action: 'perm_all', id, value: b.dataset.all }).then(j => {
                card.querySelectorAll('[data-perm]').forEach(sw => sw.checked = !!j.perms[sw.dataset.perm]);
                setCount(j.count); toast(b.textContent + ' 적용했어요');
            }).catch(e => alertMsg(e.message));
        }));
        // 사용 / 사용 중지
        const act = card.querySelector('[data-active]');
        act.addEventListener('change', () => {
            act.disabled = true;
            post({ action: 'active', id, value: act.checked ? '1' : '0' }).then(j => {
                card.classList.toggle('off', !j.on);
                card.querySelector('[data-active-label]').textContent = j.on ? '사용 중' : '사용 중지';
                toast(j.on ? '다시 쓸 수 있게 했어요' : '사용 중지했어요 (로그인해 있었다면 바로 로그아웃)');
            }).catch(e => { act.checked = !act.checked; alertMsg(e.message); }).finally(() => act.disabled = false);
        });
        // 이름 바꾸기 (이름을 눌러 고치고 Enter)
        const nm = card.querySelector('[data-rename]'); let before = nm.textContent;
        nm.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); nm.blur(); } if (e.key === 'Escape') { nm.textContent = before; nm.blur(); } });
        nm.addEventListener('blur', () => {
            const v = nm.textContent.trim().slice(0, 20);
            if (v === before) return;
            post({ action: 'rename', id, display_name: v }).then(j => { before = j.name || card.dataset.username; nm.textContent = before; card.querySelector('.su-av').textContent = before.slice(0, 1); toast('이름을 바꿨어요'); })
                .catch(e => { nm.textContent = before; alertMsg(e.message); });
        });
        // 비밀번호 바꾸기
        const box = card.querySelector('.pwbox'), inp = card.querySelector('[data-pwinput]');
        card.querySelector('[data-pw]').addEventListener('click', () => { box.classList.toggle('on'); if (box.classList.contains('on')) inp.focus(); });
        card.querySelector('[data-gen]').addEventListener('click', () => { inp.value = genPw(); inp.select(); });
        card.querySelector('[data-pwsave]').addEventListener('click', () => {
            if (inp.value.length < 8) { alertMsg('비밀번호는 8자 이상으로 해주세요.'); return; }
            const pw = inp.value;
            post({ action: 'password', id, password: pw }).then(() => {
                box.classList.remove('on'); inp.value = '';
                if (window.LD) LD.copy(pw, '바꿨어요. 새 비밀번호를 복사했어요'); else toast('바꿨어요');
            }).catch(e => alertMsg(e.message));
        });
        // 삭제
        card.querySelector('[data-del]').addEventListener('click', async () => {
            const ok = window.LD ? await LD.confirm(`부관리자 "${nm.textContent.trim()}"(${card.dataset.username})를 삭제할까요?\n로그인해 있었다면 바로 로그아웃돼요.`, { ok: '삭제', danger: true }) : confirm('삭제할까요?');
            if (!ok) return;
            const f = document.createElement('form'); f.method = 'post'; f.action = 'admin_subadmins.php';
            f.innerHTML = `<input type="hidden" name="csrf_token" value="${CSRF}"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="${id}">`;
            document.body.appendChild(f); f.submit();
        });
    });

    // 새로 만들기
    document.querySelectorAll('[data-eye]').forEach(b => b.addEventListener('click', () => {
        const i = document.getElementById(b.dataset.eye); const show = i.type === 'password';
        i.type = show ? 'text' : 'password'; b.textContent = show ? '숨기기' : '보기';
    }));
    document.getElementById('npwGen').addEventListener('click', () => {
        const pw = genPw(); ['npw', 'npw2'].forEach(k => { const i = document.getElementById(k); i.value = pw; i.type = 'text'; });
        document.querySelectorAll('[data-eye]').forEach(b => b.textContent = '숨기기');
        if (window.LD) LD.copy(pw, '비밀번호를 만들고 복사했어요');
    });
    document.querySelectorAll('[data-newall]').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('#newForm .perms input[type=checkbox]').forEach(c => c.checked = b.dataset.newall === '1');
    }));
    document.getElementById('newForm').addEventListener('submit', e => {
        const f = e.target;
        if (f.password.value !== f.password2.value) { e.preventDefault(); alertMsg('비밀번호 확인이 달라요.'); }
    });
})();
</script>
</body>
</html>
