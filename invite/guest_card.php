<?php
/**
 * guest_card.php - 내 청첩장 관리 화면(invite_edit.php)의 "참석여부 · 방명록" 카드
 *
 * invite_edit.php 안에서 이렇게 불러온다 ($invite, $token 은 invite_edit.php가 이미 준비해 둔 값):
 *   <?php include __DIR__ . '/guest_card.php'; ?>
 *
 * 테이블이 없거나 오류가 나도 이 카드만 안 보이고 관리 화면은 멀쩡하다.
 */
declare(strict_types=1);

(function () use (&$invite, &$token) {
    try {
        require_once __DIR__ . '/guest_functions.php';
        $pdo = get_pdo();
        $tk = (string) ($token ?? ($_GET['t'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $tk)) return;
        $inv = (isset($invite) && is_array($invite)) ? $invite : find_invitation_by_token($pdo, $tk);
        if (!$inv) return;
        $pdo->query('SELECT 1 FROM rsvp_responses LIMIT 1'); // 테이블 없으면 여기서 예외 → 카드 생략

        $id    = (int) $inv['id'];
        $sum   = rsvp_summary($pdo, $id);
        $gbCnt = guestbook_count($pdo, $id);
        $rsvpOn = guest_block($inv, 'rsvp') !== null;
        $gbOn   = guest_block($inv, 'guestbook') !== null;
        $base  = 'guest_manage.php?t=' . rawurlencode($tk);
        ?>
        <div class="panel guest-card" style="text-align:center;">
            <h4 style="margin:0 0 8px;">💌 참석여부 · 방명록</h4>
            <?php if (!$rsvpOn && !$gbOn && $sum['total'] === 0 && $gbCnt === 0): ?>
                <p style="font-size:13.5px;color:var(--muted);margin:0;">
                    하객에게 참석 여부를 미리 받거나, 축하 메시지를 받을 수 있어요.<br>
                    아래 <b>청첩장 디자인 편집하기 → 섹션 목록에서 "참석 의사 전달"·"방명록"을 켜고 저장</b>하면 시작됩니다.
                </p>
            <?php else: ?>
                <p style="font-size:13.5px;color:var(--muted);margin:0 0 14px;">
                    참석 <b style="color:inherit;"><?= $sum['people'] ?>명</b>
                    (<?= snap_h('신랑측 ' . $sum['groomPeople'] . ' · 신부측 ' . $sum['bridePeople']) ?>) · 불참 <?= $sum['no'] ?>건
                    · 방명록 <b style="color:inherit;"><?= $gbCnt ?>개</b>
                    <?php if (!$rsvpOn || !$gbOn): ?><br><span style="font-size:12.5px;">(<?= !$rsvpOn ? '참석여부' : '방명록' ?> 섹션은 꺼져 있어요)</span><?php endif; ?>
                </p>
                <a class="btn btn-primary" href="<?= snap_h($base . '&tab=rsvp') ?>">참석 명단 보기 →</a>
                <a class="btn" href="<?= snap_h($base . '&tab=guestbook') ?>" style="margin-left:6px;">방명록 관리</a>
            <?php endif; ?>
        </div>
        <?php
    } catch (Throwable $e) {
        // 테이블이 아직 없거나 오류가 나도 관리 화면 전체가 깨지지 않게 카드만 생략
    }
})();
