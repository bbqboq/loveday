<?php
declare(strict_types=1);
/**
 * sticker_delete.php - 에디터 '내가 올린 스티커'에서 하나 지우기 (POST edit_token · csrf_token · id)
 *  - 이 편집 링크의 고객(customer_id)이 올린 스티커만 지울 수 있음
 *  - 목록(customer_stickers)에서는 바로 빼고, 파일은 이 고객의 다른 청첩장에 아직 붙어 있으면 남겨 둠
 *    (지우면 이미 발행한 청첩장에서 스티커가 빈 그림으로 보이니까) → 응답 kept: true
 */
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

function sd_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sd_fail(405, '허용되지 않는 요청입니다.');
csrf_verify($_POST['csrf_token'] ?? null);

$pdo = get_pdo();
$token = (string) ($_POST['edit_token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) sd_fail(404, '잘못된 접근입니다.');
$invite = find_invitation_by_token($pdo, $token);
if (!$invite || !empty($invite['deleted_at'])) sd_fail(404, '유효하지 않은 링크입니다.');
if (empty($_SESSION['pin_ok_' . $invite['id']]) && $invite['edit_pin_hash']) sd_fail(403, 'PIN 인증이 필요합니다.');
$cid = (int) ($invite['customer_id'] ?? 0);
if ($cid <= 0) sd_fail(400, '이 청첩장에는 내가 올린 스티커가 없어요.');

$id = (int) ($_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT id, file_path FROM customer_stickers WHERE id = ? AND customer_id = ? LIMIT 1');
$st->execute([$id, $cid]);
$row = $st->fetch();
if (!$row) sd_fail(404, '이미 지운 스티커예요.');

$name = basename((string) $row['file_path']);
$pdo->prepare('DELETE FROM customer_stickers WHERE id = ? AND customer_id = ?')->execute([$id, $cid]);

// 이 고객의 청첩장 디자인에 아직 쓰이면 파일은 그대로 (이 청첩장은 에디터가 곧 스티커를 떼고 저장함)
$kept = false;
if ($name !== '') {
    $use = $pdo->prepare('SELECT COUNT(*) FROM invitation_orders WHERE customer_id = ? AND id <> ? AND design_json LIKE ?');
    $use->execute([$cid, (int) $invite['id'], '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name) . '%']);
    $kept = (int) $use->fetchColumn() > 0;
    if (!$kept) {
        $path = customer_sticker_dir($cid) . $name;
        if (is_file($path)) @unlink($path);
    }
}
echo json_encode(['ok' => true, 'kept' => $kept], JSON_UNESCAPED_UNICODE);
