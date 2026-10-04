<?php
/**
 * naver_commerce.php - 네이버 스마트스토어(커머스API) 주문을 읽어서 청첩장을 자동으로 결제 처리한다.
 *
 * 흐름
 *   1) 스마트스토어 상품에 "청첩장 코드"를 적는 직접 입력형 옵션 + "1년 보관 / 영구 보관" 선택 옵션을 만든다.
 *   2) 서버가 1~2분마다(naver_order_sync.php, cron) 최근에 상태가 바뀐 주문을 네이버에 물어본다.
 *      (네이버는 주문 알림을 먼저 보내주지 않아서, 우리가 주기적으로 물어보는 방식)
 *   3) 결제완료 주문의 옵션 글에서 청첩장 코드와 보관 기간을 읽고 → 코드로 청첩장을 찾아
 *      관리자 화면의 "결제 확인"과 똑같이 보관 기간 변경 + 사진 워터마크 제거.
 *   4) 코드가 틀리거나 없으면 관리자 → 스마트스토어 연동(admin_naver.php)의 "확인 필요"에 올린다.
 *   5) 취소·반품: 구매자가 "요청"하는 순간 결제 전 상태(보관 기간·만료일)로 되돌린다.
 *      이때 청첩장마다 1회에 한해 사용 기간을 6시간 더 준다 (바로 지워지지 않게).
 *      요청이 철회·거부되면 다시 결제 상태로 되돌린다.
 *   6) 교환: 결제 전에도 유료(1년/영구)였다면 교환 처리가 끝날 때까지 그대로 두고,
 *      결제 전이 무료체험이었다면 취소와 똑같이 바로 되돌린다 (교환 완료되면 다시 적용).
 *   7) 파일 다운로드(영구 보관)는 구매확정 뒤, 예식 전날부터 열린다 (nc_export_gate).
 *
 * 설정값(app_settings) - 관리자 → 스마트스토어 연동에서 입력
 *   naver_enabled, naver_client_id, naver_client_secret_enc(암호화), naver_product_ids, naver_auto_confirm
 *   naver_sync_from(다음에 어디서부터 물어볼지), naver_last_sync, naver_last_error, naver_token_enc, naver_token_exp
 *
 * 테스트용: config.php에 define('NAVER_COMMERCE_API_BASE', 'http://…') 를 넣으면 그 주소로 요청한다.
 */
declare(strict_types=1);
require_once __DIR__ . '/guest_functions.php'; // snap_functions(app_setting) + DEMO_MEMO

const NC_DEFAULT_BASE = 'https://api.commerce.naver.com/external';
const NC_PAID_STATUSES = ['PAYED', 'DELIVERING', 'DELIVERED', 'PURCHASE_DECIDED', 'EXCHANGED'];
const NC_CANCEL_DONE = ['CANCELED', 'RETURNED', 'CANCELED_BY_NOPAYMENT'];
const NC_GRACE_HOURS = 6; // 취소로 되돌릴 때 1회에 한해 더 주는 시간

class NaverCommerceError extends RuntimeException {}

function nc_base(): string { return defined('NAVER_COMMERCE_API_BASE') ? rtrim((string) NAVER_COMMERCE_API_BASE, '/') : NC_DEFAULT_BASE; }
function nc_now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('Asia/Seoul')); }
function nc_iso(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('Asia/Seoul'))->format('Y-m-d\TH:i:s.vP'); }
function nc_client_secret(): string
{
    $enc = app_setting('naver_client_secret_enc');
    if ($enc === '') return '';
    try { return decrypt_data($enc); } catch (Throwable $e) { return ''; }
}
function nc_configured(): bool { return app_setting('naver_client_id') !== '' && nc_client_secret() !== ''; }

/** 커머스API 전자서명: bcrypt(client_id + "_" + timestamp, salt = client_secret) → base64 */
function nc_sign(string $clientId, string $clientSecret, string $timestamp): string
{
    if (!preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{22}/', $clientSecret)) throw new NaverCommerceError('애플리케이션 시크릿 형식이 올바르지 않아요. ($2a$로 시작하는 값을 그대로 붙여넣어 주세요)');
    $hash = crypt($clientId . '_' . $timestamp, $clientSecret);
    if (strlen($hash) < 60) throw new NaverCommerceError('전자서명을 만들지 못했어요.');
    return base64_encode($hash);
}

/** HTTP 요청 (curl) - 응답 JSON 배열을 돌려준다 */
function nc_http(string $method, string $path, array $query = [], ?array $json = null, array $form = [], ?string $token = null): array
{
    $url = nc_base() . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method];
    if ($json !== null) { $headers[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE); }
    elseif ($form) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; $opts[CURLOPT_POSTFIELDS] = http_build_query($form); }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new NaverCommerceError('네이버에 연결하지 못했어요: ' . $err);
    $data = json_decode((string) $body, true);
    if ($code >= 400 || !is_array($data)) {
        $msg = is_array($data) ? (string) ($data['message'] ?? $data['code'] ?? '') : '';
        $hint = match (true) {
            $code === 401 => ' (아이디·시크릿을 확인해주세요)',
            $code === 403 => ' (커머스API센터에 이 서버 IP가 등록돼 있는지 확인해주세요)',
            $code === 429 => ' (요청이 너무 많아요. 잠시 뒤 다시 시도돼요)',
            default => '',
        };
        throw new NaverCommerceError("네이버 응답 오류 {$code}" . ($msg !== '' ? ": {$msg}" : '') . $hint, $code);
    }
    return $data;
}

/** 인증 토큰 - 유효기간 동안 저장해 두고 다시 쓴다 */
function nc_token(PDO $pdo, bool $fresh = false): string
{
    static $mem = null; // 같은 실행 안에서는 다시 받지 않음 (app_setting은 실행 중 캐시라 방금 저장한 값을 못 봄)
    if (!$fresh && $mem && $mem[1] > time() + 120) return $mem[0];
    if (!$fresh && (int) app_setting('naver_token_exp') > time() + 120) {
        try { $t = decrypt_data(app_setting('naver_token_enc')); if ($t !== '') return $t; } catch (Throwable $e) {}
    }
    $clientId = app_setting('naver_client_id');
    $secret = nc_client_secret();
    if ($clientId === '' || $secret === '') throw new NaverCommerceError('애플리케이션 ID와 시크릿을 먼저 저장해주세요.');
    $ts = (string) (int) round(microtime(true) * 1000);
    $data = nc_http('POST', '/v1/oauth2/token', [], null, [
        'client_id' => $clientId, 'timestamp' => $ts, 'grant_type' => 'client_credentials',
        'client_secret_sign' => nc_sign($clientId, $secret, $ts), 'type' => 'SELF',
    ]);
    $token = (string) ($data['access_token'] ?? '');
    if ($token === '') throw new NaverCommerceError('토큰을 받지 못했어요.');
    $exp = time() + max(300, (int) ($data['expires_in'] ?? 10800));
    save_app_setting($pdo, 'naver_token_enc', encrypt_data($token));
    save_app_setting($pdo, 'naver_token_exp', (string) $exp);
    $mem = [$token, $exp];
    return $token;
}

/** 토큰이 만료돼 401이 나면 한 번만 새로 받아서 다시 요청 */
function nc_api(PDO $pdo, string $method, string $path, array $query = [], ?array $json = null): array
{
    try { return nc_http($method, $path, $query, $json, [], nc_token($pdo)); }
    catch (NaverCommerceError $e) {
        if ($e->getCode() !== 401) throw $e;
        return nc_http($method, $path, $query, $json, [], nc_token($pdo, true));
    }
}

/** 기간 안에 상태가 바뀐 상품주문 목록 (최대 24시간 단위, 다음 페이지가 있으면 이어서) */
function nc_changed(PDO $pdo, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $out = []; $moreSeq = null; $cursor = $from;
    for ($page = 0; $page < 50; $page++) {
        $q = ['lastChangedFrom' => nc_iso($cursor), 'lastChangedTo' => nc_iso($to)];
        if ($moreSeq !== null) $q['moreSequence'] = $moreSeq;
        $data = nc_api($pdo, 'GET', '/v1/pay-order/seller/product-orders/last-changed-statuses', $q);
        $d = $data['data'] ?? [];
        foreach ((array) ($d['lastChangeStatuses'] ?? []) as $row) if (!empty($row['productOrderId'])) $out[(string) $row['productOrderId']] = $row;
        $more = $d['more'] ?? null;
        if (!$more || empty($more['moreFrom'])) break;
        $cursor = new DateTimeImmutable((string) $more['moreFrom']);
        $moreSeq = (string) ($more['moreSequence'] ?? '');
    }
    return $out;
}

/** 상품주문 상세 (한 번에 최대 300개) */
function nc_details(PDO $pdo, array $productOrderIds): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($productOrderIds)), 300) as $chunk) {
        $data = nc_api($pdo, 'POST', '/v1/pay-order/seller/product-orders/query', [], ['productOrderIds' => $chunk]);
        foreach ((array) ($data['data'] ?? []) as $row) {
            $po = $row['productOrder'] ?? [];
            if (!empty($po['productOrderId'])) $out[(string) $po['productOrderId']] = $row;
        }
    }
    return $out;
}

/** 발주확인 (선택 기능) */
function nc_confirm(PDO $pdo, array $productOrderIds): void
{
    if ($productOrderIds) nc_api($pdo, 'POST', '/v1/pay-order/seller/product-orders/confirm', [], ['productOrderIds' => array_values($productOrderIds)]);
}

/** 옵션 글에서 보관 기간 읽기 */
function nc_plan_from_text(string $text): ?string
{
    $t = preg_replace('/\s+/u', '', mb_strtolower($text));
    if (preg_match('/영구|평생|permanent/u', $t)) return 'permanent';
    if (preg_match('/1년|일년|12개월|oneyear|1year/u', $t)) return 'one_year';
    return null;
}

/**
 * 옵션 글에서 청첩장 코드 찾기 - 실제로 있는 청첩장 주소(view_slug)와 맞는 것만 인정한다.
 * "loveday.kr/abc123", "코드: abc123", 대문자·공백 섞인 입력도 받아준다.
 * @return array{0: ?array, 1: string} [청첩장 행 또는 null, 찾은 코드 또는 사유]
 */
function nc_find_invitation(PDO $pdo, string $text): array
{
    $low = mb_strtolower($text);
    $cands = [];
    if (preg_match_all('#loveday\.kr/(?:invite/)?([a-z0-9]{4,20})#', $low, $m)) $cands = array_merge($cands, $m[1]);
    if (preg_match_all('/(?<![a-z0-9])([a-z0-9]{4,20})(?![a-z0-9])/', $low, $m)) $cands = array_merge($cands, $m[1]);
    $cands = array_values(array_unique(array_filter($cands, fn($c) => !in_array($c, ['loveday', 'invite', 'https', 'http', 'www'], true))));
    if (!$cands) return [null, '옵션에 청첩장 코드가 없어요'];
    $in = implode(',', array_fill(0, count($cands), '?'));
    $st = $pdo->prepare("SELECT * FROM invitation_orders WHERE view_slug IN ($in)");
    $st->execute($cands);
    $rows = $st->fetchAll();
    if (count($rows) === 1) return [$rows[0], $rows[0]['view_slug']];
    if (count($rows) > 1) return [null, '코드가 여러 개 적혀 있어요: ' . implode(', ', array_column($rows, 'view_slug'))];
    return [null, '일치하는 청첩장이 없어요 (적힌 값: ' . mb_substr(implode(', ', array_slice($cands, 0, 3)), 0, 60) . ')'];
}

/** 발송처리 (선택) - 무형 상품은 "직접전달/배송없음"으로 발송처리해야 구매확정(=다운로드 열림)까지 진행된다 */
function nc_dispatch(PDO $pdo, array $productOrderIds, string $method): void
{
    if (!$productOrderIds || !in_array($method, ['DIRECT_DELIVERY', 'NOTHING'], true)) return;
    $when = nc_iso(nc_now());
    nc_api($pdo, 'POST', '/v1/pay-order/seller/product-orders/dispatch', [], ['dispatchProductOrders' => array_map(
        fn($id) => ['productOrderId' => (string) $id, 'deliveryMethod' => $method, 'dispatchDate' => $when], array_values($productOrderIds))]);
}

/**
 * 클레임(취소·반품·교환) 상태 읽기
 * @return array{kind: ?string, stage: ?string, label: string}  kind: cancel|exchange|null, stage: active|done|rejected
 */
function nc_claim(array $detail): array
{
    $po = $detail['productOrder'] ?? [];
    $ps = strtoupper((string) ($po['productOrderStatus'] ?? ''));
    if (in_array($ps, NC_CANCEL_DONE, true)) return ['kind' => 'cancel', 'stage' => 'done', 'label' => $ps];
    $type = strtoupper((string) ($po['claimType'] ?? '')); $status = strtoupper((string) ($po['claimStatus'] ?? ''));
    if ($type === '' && is_array($detail['currentClaim'] ?? null)) { // 새 응답 형식 대비
        foreach ($detail['currentClaim'] as $k => $c) if (is_array($c) && !empty($c['claimStatus'])) { $type = strtoupper((string) ($c['claimType'] ?? $k)); $status = strtoupper((string) $c['claimStatus']); break; }
    }
    if ($type === '') foreach (['cancel' => 'CANCEL', 'return' => 'RETURN', 'exchange' => 'EXCHANGE'] as $k => $t) {
        if (!empty($detail[$k]['claimStatus'])) { $type = $t; $status = strtoupper((string) $detail[$k]['claimStatus']); break; }
    }
    if ($ps === 'EXCHANGED') return ['kind' => 'exchange', 'stage' => 'done', 'label' => $ps];
    if ($type === '' || str_contains($type, 'HOLDBACK') && !str_contains($type, 'EXCHANGE') && !str_contains($type, 'RETURN')) return ['kind' => null, 'stage' => null, 'label' => ''];
    $kind = str_contains($type, 'EXCHANGE') ? 'exchange' : ((str_contains($type, 'CANCEL') || str_contains($type, 'RETURN')) ? 'cancel' : null);
    if (!$kind) return ['kind' => null, 'stage' => null, 'label' => ''];
    $stage = str_ends_with($status, '_REJECT') ? 'rejected' : ((str_ends_with($status, '_DONE') && $status !== 'COLLECT_DONE') ? 'done' : 'active');
    return ['kind' => $kind, 'stage' => $stage, 'label' => trim($type . ' ' . $status)];
}

/**
 * 결제 처리 - admin_edit.php의 "결제 확인" 버튼과 같은 동작
 * @return array{0: bool, 1: string} [적용했는지, 메시지]
 */
function nc_apply_plan(PDO $pdo, array $inv, string $plan): array
{
    if (!empty($inv['deleted_at'])) return [false, '휴지통에 있는 청첩장이에요. 복원한 뒤 직접 처리해주세요'];
    if (str_starts_with((string) ($inv['order_memo'] ?? ''), DEMO_MEMO)) return [false, '가입 없이 만든 체험용 청첩장이라 결제 처리할 수 없어요'];
    $cur = (string) $inv['storage_plan'];
    if ($cur === 'permanent') return [true, '이미 영구 보관이라 바꿀 것이 없어요'];
    if ($cur === 'one_year' && $plan === 'one_year') {
        // 1년 보관 중에 1년을 또 사면 연장 - 남은 기간 끝에서 1년 더
        $base = max(time(), $inv['expires_at'] ? strtotime((string) $inv['expires_at']) : 0);
        $until = date('Y-m-d H:i:s', strtotime('+1 year', $base));
        $pdo->prepare('UPDATE invitation_orders SET expires_at = ?, paid_at = NOW(), status = IF(status = \'expired\', \'editing\', status) WHERE id = ?')->execute([$until, $inv['id']]);
        return [true, '1년 연장했어요 (' . date('Y. n. j', strtotime($until)) . '까지)'];
    }
    $expires = $plan === 'permanent' ? null : date('Y-m-d H:i:s', strtotime('+1 year'));
    $status = $inv['status'] === 'expired' ? 'editing' : $inv['status'];
    $pdo->prepare('UPDATE invitation_orders SET storage_plan = ?, paid_at = NOW(), expires_at = ?, status = ? WHERE id = ?')
        ->execute([$plan, $expires, $status, $inv['id']]);
    if ($cur === 'trial') reprocess_invitation_photos($pdo, (int) $inv['id'], false); // 워터마크 제거
    return [true, ($plan === 'permanent' ? '영구 보관' : '1년 보관') . '으로 바꾸고 워터마크를 지웠어요'];
}

function nc_invitation(PDO $pdo, ?int $id): ?array
{
    if (!$id) return null;
    $st = $pdo->prepare('SELECT * FROM invitation_orders WHERE id = ?'); $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * 취소·반품(요청 포함) 또는 무료였던 청첩장의 교환 요청 → 결제 전 상태로 되돌리기
 * 청첩장마다 1회에 한해 사용 기간을 NC_GRACE_HOURS 시간 더 준다.
 * @return array{0: bool, 1: string, 2: bool} [되돌렸는지, 메시지, 이번에 추가 시간을 줬는지]
 */
function nc_revert(PDO $pdo, array $row, string $why): array
{
    $inv = nc_invitation($pdo, (int) ($row['invitation_id'] ?? 0));
    if (!$inv) return [false, '청첩장을 찾지 못했어요', false];
    // 같은 청첩장에 아직 살아있는 다른 결제가 있으면 되돌리지 않는다 (두 번 결제하고 하나만 취소한 경우 등)
    $o = $pdo->prepare("SELECT COUNT(*) FROM naver_orders WHERE invitation_id = ? AND product_order_id <> ? AND result IN ('applied', 'exchange_hold')");
    $o->execute([$inv['id'], $row['product_order_id']]);
    if ((int) $o->fetchColumn() > 0) return [false, $why . ' - 같은 청첩장에 다른 결제가 있어서 그대로 두었어요', false];

    $plan = in_array($row['prev_plan'] ?? '', ['trial', 'one_year', 'permanent'], true) ? $row['prev_plan'] : 'trial';
    $expires = $row['prev_expires'] ?? null;
    if ($plan !== 'permanent' && !$expires) $expires = date('Y-m-d H:i:s');
    $grace = false;
    if ($plan !== 'permanent') {
        $g = $pdo->prepare('SELECT COUNT(*) FROM naver_orders WHERE invitation_id = ? AND grace_given = 1');
        $g->execute([$inv['id']]);
        if ((int) $g->fetchColumn() === 0) {
            $expires = date('Y-m-d H:i:s', max(time(), strtotime((string) $expires)) + NC_GRACE_HOURS * 3600);
            $grace = true;
        }
    }
    $pdo->prepare('UPDATE invitation_orders SET storage_plan = ?, expires_at = ?, paid_at = ? WHERE id = ?')
        ->execute([$plan, $plan === 'permanent' ? null : $expires, $row['prev_paid_at'] ?? null, $inv['id']]);
    if ($plan === 'trial' && $inv['storage_plan'] !== 'trial') reprocess_invitation_photos($pdo, (int) $inv['id'], true); // 워터마크 다시
    $label = ['trial' => '무료체험', 'one_year' => '1년 보관', 'permanent' => '영구 보관'][$plan];
    $msg = $why . ' → ' . $label . '으로 되돌림' . ($plan !== 'permanent' ? ' (' . date('n/j H:i', strtotime((string) $expires)) . '까지' . ($grace ? ', ' . NC_GRACE_HOURS . '시간 추가' : '') . ')' : '');
    return [true, $msg, $grace];
}

function nc_mask_name(string $n): string
{
    $n = trim($n); $len = mb_strlen($n);
    if ($len <= 1) return $n;
    if ($len === 2) return mb_substr($n, 0, 1) . '*';
    return mb_substr($n, 0, 1) . str_repeat('*', $len - 2) . mb_substr($n, -1);
}

/** naver_orders 한 줄 저장 (없으면 추가, 있으면 고침) */
function nc_save_row(PDO $pdo, array $r): void
{
    $cols = ['product_order_id', 'order_id', 'product_name', 'product_option', 'orderer', 'amount', 'order_status', 'plan', 'code', 'invitation_id',
             'result', 'message', 'paid_at', 'claim', 'prev_plan', 'prev_expires', 'prev_paid_at', 'decided_at', 'reverted_at', 'grace_given'];
    $r['grace_given'] = (int) ($r['grace_given'] ?? 0);
    $vals = array_map(fn($c) => $r[$c] ?? null, $cols);
    $upd = implode(', ', array_map(fn($c) => "$c = VALUES($c)", array_slice($cols, 1)));
    $pdo->prepare('INSERT INTO naver_orders (' . implode(',', $cols) . ', updated_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ', NOW())
        ON DUPLICATE KEY UPDATE ' . $upd . ', updated_at = NOW()')->execute($vals);
}

/**
 * 상품주문 하나를 처리 (새 주문 / 상태 바뀐 주문 모두)
 * @return array{0: string, 1: string, 2: bool, 3: bool} [결과, 상품주문번호, 발주확인·발송할지, 결과가 바뀌었는지]
 */
function nc_handle(PDO $pdo, array $detail, array $productFilter): array
{
    $po = $detail['productOrder'] ?? []; $od = $detail['order'] ?? [];
    $poid = (string) $po['productOrderId'];
    $status = (string) ($po['productOrderStatus'] ?? '');
    $productId = (string) ($po['productId'] ?? $po['originalProductId'] ?? '');
    if ($productFilter && !in_array($productId, $productFilter, true) && !in_array((string) ($po['originalProductId'] ?? ''), $productFilter, true)) return ['skip', '', false, false];
    $option = trim((string) ($po['productOption'] ?? ''));
    $optionAll = trim($option . ' ' . (string) ($po['optionManageCode'] ?? '') . ' ' . (string) ($po['shippingMemo'] ?? ''));

    $st = $pdo->prepare('SELECT * FROM naver_orders WHERE product_order_id = ?');
    $st->execute([$poid]);
    $prev = $st->fetch() ?: null;
    $prevResult = $prev['result'] ?? null;
    $claim = nc_claim($detail);

    $r = array_merge($prev ?: [], [
        'product_order_id' => $poid,
        'order_id' => (string) ($od['orderId'] ?? $po['orderId'] ?? ''),
        'product_name' => mb_substr((string) ($po['productName'] ?? ''), 0, 200),
        'product_option' => mb_substr($option, 0, 500),
        'orderer' => nc_mask_name((string) ($od['ordererName'] ?? '')) ?: ($prev['orderer'] ?? null),
        'amount' => (int) ($po['totalPaymentAmount'] ?? $po['totalProductAmount'] ?? 0),
        'order_status' => $status,
        'claim' => $claim['label'] !== '' ? $claim['label'] : null,
        'paid_at' => !empty($od['paymentDate']) ? date('Y-m-d H:i:s', strtotime((string) $od['paymentDate'])) : ($prev['paid_at'] ?? null),
    ]);
    $r['result'] = $prevResult ?? 'waiting';
    if ($status === 'PURCHASE_DECIDED' && empty($r['decided_at'])) $r['decided_at'] = date('Y-m-d H:i:s'); // 구매확정 → 다운로드 조건 중 하나
    $newlyPaid = false;

    // 결제 처리 (새 주문 / 되돌렸다가 취소가 철회·거부됐거나 교환이 끝난 주문)
    $apply = function (string $note = '') use ($pdo, &$r, $option, $optionAll, $po, &$newlyPaid): void {
        $plan = nc_plan_from_text($option . ' ' . (string) ($po['productName'] ?? ''));
        $inv = null; $found = '';
        if (!empty($r['invitation_id']) && $r['result'] === 'reverted') $inv = nc_invitation($pdo, (int) $r['invitation_id']); // 되돌렸던 그 청첩장
        if (!$inv) [$inv, $found] = nc_find_invitation($pdo, $optionAll);
        $r['plan'] = $plan; $r['code'] = $inv['view_slug'] ?? null; $r['invitation_id'] = $inv ? (int) $inv['id'] : null;
        if (!$inv) { $r['result'] = 'review'; $r['message'] = $found; return; }
        if (!$plan) { $r['result'] = 'review'; $r['message'] = '옵션에서 보관 기간(1년/영구)을 찾지 못했어요'; return; }
        if (empty($r['prev_plan'])) { // 결제 전 상태를 한 번만 기억해 둔다 (취소되면 여기로 되돌림)
            $r['prev_plan'] = $inv['storage_plan']; $r['prev_expires'] = $inv['expires_at']; $r['prev_paid_at'] = $inv['paid_at'];
        }
        [$ok, $m] = nc_apply_plan($pdo, $inv, $plan);
        $r['result'] = $ok ? 'applied' : 'review'; $r['message'] = ($note !== '' ? $note . ' · ' : '') . $m; $newlyPaid = $ok;
    };
    $revert = function (string $why) use ($pdo, &$r): void {
        [$ok, $m, $grace] = nc_revert($pdo, $r, $why);
        $r['result'] = $ok ? 'reverted' : 'closed'; $r['message'] = $m;
        if ($ok) { $r['reverted_at'] = date('Y-m-d H:i:s'); if ($grace) $r['grace_given'] = 1; }
    };
    $wasPaid = in_array($prevResult, ['applied', 'exchange_hold'], true);

    if ($claim['kind'] === 'cancel' && in_array($claim['stage'], ['active', 'done'], true)) {
        // 취소·반품: 요청 단계부터 바로 되돌린다
        $why = $claim['stage'] === 'done' ? '취소·반품 완료' : '취소·반품 요청';
        if ($wasPaid) $revert($why);
        elseif ($prevResult === 'reverted') { if ($claim['stage'] === 'done') $r['message'] = preg_replace('/^취소·반품 요청/u', '취소·반품 완료', (string) $r['message']); }
        elseif (!in_array($prevResult, ['closed', 'ignored', 'canceled'], true)) { $r['result'] = 'closed'; $r['message'] = '결제 처리 전에 취소됐어요'; }
    } elseif ($claim['kind'] === 'exchange' && $claim['stage'] === 'active') {
        // 교환: 결제 전에도 유료였다면 교환이 끝날 때까지 그대로, 무료였다면 바로 되돌림
        if ($prevResult === 'applied') {
            if (in_array($r['prev_plan'] ?? 'trial', ['one_year', 'permanent'], true)) { $r['result'] = 'exchange_hold'; $r['message'] = '교환 요청 - 결제 전에도 유료라 교환이 끝날 때까지 그대로 둬요'; }
            else $revert('교환 요청');
        }
    } elseif (in_array($status, NC_PAID_STATUSES, true)) {
        // 정상 결제 상태 (취소 요청이 철회·거부됐거나 교환이 끝난 경우 포함)
        if ($prevResult === 'exchange_hold') { $r['result'] = 'applied'; $r['message'] = '교환 처리 끝 - 결제 상태 그대로 유지'; }
        elseif ($prevResult === 'reverted') $apply($claim['kind'] === 'exchange' ? '교환 완료로 다시 적용' : '취소 요청이 철회·거부돼 다시 적용');
        elseif (in_array($prevResult, [null, 'waiting', 'review'], true)) $apply();
    } elseif (!$prev) {
        $r['result'] = 'waiting'; $r['message'] = '결제 대기 중이에요 (' . $status . ')';
    }
    $r['message'] = mb_substr((string) ($r['message'] ?? ''), 0, 255);
    nc_save_row($pdo, $r);
    return [$r['result'], $poid, $newlyPaid && $status === 'PAYED', $prevResult !== $r['result']];
}

/**
 * 주문 확인 한 번 돌리기 (cron / 관리자 "지금 확인" 버튼)
 * @return array{checked:int, applied:int, review:int, reverted:int}
 */
function nc_sync(PDO $pdo, ?DateTimeImmutable $fromOverride = null): array
{
    $sum = ['checked' => 0, 'applied' => 0, 'review' => 0, 'reverted' => 0];
    $now = nc_now();
    $saved = app_setting('naver_sync_from');
    $from = $fromOverride ?? ($saved !== '' ? new DateTimeImmutable($saved) : $now->modify('-3 hours'));
    if ($from < $now->modify('-7 days')) $from = $now->modify('-7 days'); // 너무 오래 쉬었으면 최근 7일만
    $productFilter = array_values(array_filter(array_map('trim', explode(',', app_setting('naver_product_ids')))));
    try {
        $changed = [];
        for ($winFrom = $from; $winFrom < $now; $winFrom = $winTo) { // 네이버는 한 번에 24시간까지만 조회된다
            $winTo = min($now, $winFrom->modify('+23 hours'));
            $changed += nc_changed($pdo, $winFrom, $winTo);
        }
        $confirmIds = [];
        if ($changed) {
            foreach (nc_details($pdo, array_keys($changed)) as $detail) {
                try {
                    // 한 주문의 청첩장 변경 + 기록 저장을 한 묶음으로 - 중간에 실패하면 청첩장 변경도 없던 일로
                    $pdo->beginTransaction();
                    [$result, , $confirm, $changedNow] = nc_handle($pdo, $detail, $productFilter);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    // 한 주문에서 문제가 나도 나머지 주문은 계속 처리하고, 이 주문은 "확인 필요"로 남긴다
                    $po = $detail['productOrder'] ?? [];
                    $pdo->prepare("INSERT INTO naver_orders (product_order_id, order_id, product_name, product_option, order_status, result, message, updated_at)
                        VALUES (?,?,?,?,?,'review',?,NOW()) ON DUPLICATE KEY UPDATE result=IF(result IN ('applied','reverted','exchange_hold'),result,'review'), message=VALUES(message), updated_at=NOW()")
                        ->execute([(string) $po['productOrderId'], (string) ($detail['order']['orderId'] ?? ''), mb_substr((string) ($po['productName'] ?? ''), 0, 200),
                                   mb_substr((string) ($po['productOption'] ?? ''), 0, 500), (string) ($po['productOrderStatus'] ?? ''), mb_substr('처리 중 오류: ' . $e->getMessage(), 0, 255)]);
                    $result = 'review'; $confirm = false; $changedNow = true;
                }
                if ($result === 'skip') continue;
                $sum['checked']++;
                if ($confirm) $confirmIds[] = $detail['productOrder']['productOrderId'];
                if (!$changedNow) continue; // 결과가 그대로인 주문(이미 처리됨 등)은 숫자에 다시 세지 않음
                if (isset($sum[$result])) $sum[$result]++;
            }
        }
        if ($confirmIds && app_setting('naver_auto_confirm') === '1') {
            try { nc_confirm($pdo, $confirmIds); } catch (Throwable $e) { save_app_setting($pdo, 'naver_last_error', '발주확인 실패: ' . $e->getMessage()); }
            $dispatch = app_setting('naver_auto_dispatch');
            if ($dispatch !== '') { try { nc_dispatch($pdo, $confirmIds, $dispatch); } catch (Throwable $e) { save_app_setting($pdo, 'naver_last_error', '발송처리 실패: ' . $e->getMessage()); } }
        }
        // 다음 번엔 5분 겹치게 물어본다 (늦게 반영되는 주문 대비 - 같은 주문은 두 번 처리되지 않음)
        save_app_setting($pdo, 'naver_sync_from', nc_iso($now->modify('-5 minutes')));
        save_app_setting($pdo, 'naver_last_sync', $now->format('Y-m-d H:i:s'));
        if (!str_contains(app_setting('naver_last_error'), '실패')) save_app_setting($pdo, 'naver_last_error', '');
        save_app_setting($pdo, 'naver_last_result', json_encode($sum));
    } catch (Throwable $e) {
        save_app_setting($pdo, 'naver_last_error', $now->format('m-d H:i') . ' ' . $e->getMessage());
        throw $e;
    }
    return $sum;
}

/** 관리자가 "확인 필요"(또는 되돌린) 주문에 코드를 직접 넣어 처리 */
function nc_manual_apply(PDO $pdo, string $poid, string $code, string $plan): array
{
    if (!in_array($plan, ['one_year', 'permanent'], true)) return [false, '보관 기간을 골라주세요'];
    $inv = find_invitation_by_slug_any_status($pdo, strtolower(trim($code)));
    if (!$inv) return [false, '그 코드의 청첩장이 없어요'];
    $st = $pdo->prepare('SELECT * FROM naver_orders WHERE product_order_id = ?'); $st->execute([$poid]);
    $row = $st->fetch() ?: [];
    $keepPrev = !empty($row['prev_plan']) && (int) ($row['invitation_id'] ?? 0) === (int) $inv['id'];
    [$ok, $msg] = nc_apply_plan($pdo, $inv, $plan);
    if ($ok) {
        $pdo->prepare("UPDATE naver_orders SET result='applied', plan=?, code=?, invitation_id=?, message=?,
                prev_plan=?, prev_expires=?, prev_paid_at=?, updated_at=NOW() WHERE product_order_id=?")
            ->execute([$plan, $inv['view_slug'], $inv['id'], '관리자가 직접 처리 · ' . $msg,
                       $keepPrev ? $row['prev_plan'] : $inv['storage_plan'], $keepPrev ? $row['prev_expires'] : $inv['expires_at'], $keepPrev ? $row['prev_paid_at'] : $inv['paid_at'], $poid]);
    }
    return [$ok, $msg];
}

/**
 * 파일 다운로드(영구 보관) 가능 여부 - 구매확정 뒤, 예식 전날부터
 *  - 스마트스토어로 결제한 청첩장: 그 주문이 구매확정(또는 관리자가 "구매확정으로 보기")돼야 함
 *  - 관리자가 직접 결제 처리한 청첩장(스마트스토어 주문 없음): 구매확정 조건 없음
 * @return array{ok: bool, reason: string, from: ?string}
 */
function nc_export_gate(PDO $pdo, array $inv): array
{
    $wd = snap_wedding_date($inv);
    if (!$wd) return ['ok' => false, 'reason' => '예식일을 정하면 예식 전날부터 받을 수 있어요. 에디터의 예식 일시(D-day)를 채워주세요.', 'from' => null];
    $from = (new DateTimeImmutable($wd->format('Y-m-d'), new DateTimeZone('Asia/Seoul')))->modify('-1 day');
    $decidedOk = true;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM naver_orders WHERE invitation_id = ? AND result = 'applied' AND plan = 'permanent' AND decided_at IS NULL");
        $st->execute([$inv['id']]);
        $pending = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM naver_orders WHERE invitation_id = ? AND result = 'applied' AND plan = 'permanent' AND decided_at IS NOT NULL");
        $st->execute([$inv['id']]);
        $decidedOk = $pending === 0 || (int) $st->fetchColumn() > 0;
    } catch (Throwable $e) { /* 표가 없으면(연동 전) 조건 없음 */ }
    $fromText = $from->format('Y. n. j');
    if (!$decidedOk) return ['ok' => false, 'reason' => "스마트스토어에서 구매확정을 누른 뒤, 예식 전날({$fromText})부터 받을 수 있어요.", 'from' => $from->format('Y-m-d')];
    if (nc_now() < $from) return ['ok' => false, 'reason' => "예식 전날({$fromText})부터 받을 수 있어요.", 'from' => $from->format('Y-m-d')];
    return ['ok' => true, 'reason' => '', 'from' => $from->format('Y-m-d')];
}
