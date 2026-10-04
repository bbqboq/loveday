<?php
/**
 * snap_card.php - 내 청첩장 관리 화면(invite_edit.php)의 "게스트스냅(하객 사진)" 카드
 *
 * invite_edit.php 안에서 이렇게 불러온다 ($invite, $token 은 invite_edit.php가 이미 준비해 둔 값):
 *   <?php include __DIR__ . '/snap_card.php'; ?>
 *
 * 보여주는 것: 게스트스냅 사용 여부, 업로드 기간/삭제일, 올라온 사진 수·용량, 남은 다운로드 횟수,
 *             "올라온 사진 보기" 버튼(snap_manage.php).
 * DB 테이블이 없거나 오류가 나도 이 카드만 안 보이고 관리 화면은 멀쩡하다.
 * 모양은 invite_edit.php의 다른 카드와 같은 .panel / .btn 클래스(assets/invite.css)를 쓴다.
 */
declare(strict_types=1);

(function () use (&$invite, &$token) {
    try {
        require_once __DIR__ . '/snap_functions.php';
        $pdo = get_pdo();
        $tk = (string) ($token ?? ($_GET['t'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $tk)) return;
        $inv = (isset($invite) && is_array($invite)) ? $invite : find_invitation_by_token($pdo, $tk);
        if (!$inv) return;

        $id      = (int) $inv['id'];
        $enabled = snap_enabled($inv);
        $sch     = snap_schedule($inv);
        $usage   = snap_usage($pdo, $id);
        $dlLeft  = max(0, app_setting_int('snap_download_limit') - snap_download_count($pdo, $id));
        $fmt     = fn($d) => $d instanceof DateTimeImmutable ? $d->format('n월 j일') : '-';
        $stateText = [
            'before'  => '업로드 전 · ' . $fmt($sch['open'] ?? null) . '부터 하객 사진을 받아요',
            'open'    => '📷 지금 하객 사진을 받는 중 · ' . (isset($sch['close']) ? $fmt($sch['close']->modify('-1 day')) : '') . '까지',
            'closed'  => '업로드 마감 · ' . (isset($sch['delete']) ? $fmt($sch['delete']->modify('-1 day')) : '') . ' 이후 사진 자동 삭제',
            'expired' => '보관 기간이 끝나 사진이 삭제되었어요',
            'nodate'  => '예식일을 정하면 업로드 기간이 자동으로 정해져요',
        ][$sch['state']] ?? '';
        $url = 'snap_manage.php?t=' . rawurlencode($tk);
        ?>
        <div class="panel snap-card" style="text-align:center;">
            <h4 style="margin:0 0 8px;">📷 하객이 올린 사진 (게스트스냅)</h4>
            <?php if (!$enabled): ?>
                <p style="font-size:13.5px;color:var(--muted);margin:0 0 14px;">
                    예식날 하객들이 찍은 사진을 로그인 없이 바로 보내줄 수 있어요.<br>
                    아래 <b>청첩장 디자인 편집하기 → 섹션 목록에서 "게스트스냅"을 켜고 저장</b>하면 시작됩니다.
                </p>
                <?php if ($usage['count'] > 0): ?>
                    <a class="btn" href="<?= snap_h($url) ?>">이전에 올라온 사진 보기 (<?= (int) $usage['count'] ?>장)</a>
                <?php endif; ?>
            <?php else: ?>
                <p style="font-size:13.5px;color:var(--muted);margin:0 0 4px;"><?= snap_h($stateText) ?></p>
                <p style="font-size:13.5px;color:var(--muted);margin:0 0 14px;">
                    올라온 사진 <b style="color:inherit;"><?= (int) $usage['count'] ?>장</b>
                    · <?= snap_fmt_mb($usage['total']) ?> / <?= snap_fmt_mb($usage['quota_invite']) ?>
                    · 전체 다운로드 <b><?= $dlLeft ?>회</b> 남음
                </p>
                <a class="btn btn-primary" href="<?= snap_h($url) ?>">올라온 사진 보기 · QR코드 →</a>
            <?php endif; ?>
        </div>
        <?php
    } catch (Throwable $e) {
        // 게스트스냅 테이블이 아직 없거나 오류가 나도 관리 화면 전체가 깨지지 않게 카드만 생략
    }
})();
