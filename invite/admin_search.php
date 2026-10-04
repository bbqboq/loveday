<?php
/**
 * admin_search.php - 관리자 검색 (청첩장 목록 · 휴지통 위의 검색칸)
 *
 *  한 칸에 아무거나 넣으면 찾는다:
 *   - 코드 (loveday.kr/코드), 고객코드 (C0001)
 *   - 신랑·신부 이름 (관리자가 넣은 이름 + 고객이 에디터에 쓴 이름·부모님 이름), 별칭, 주문메모
 *   - 전화번호 (4자리 이상 숫자) - 관리자가 넣은 연락처 + 청첩장 연락처 섹션의 번호
 *   - 스마트스토어 구매자 이름
 *  여러 단어는 모두 들어간 것만 (예: "철수 영희"). 띄어쓰기·하이픈은 무시.
 *  부관리자: 전화번호·구매자 이름으로 찾기는 개인정보 권한(customer_private)이 있어야 한다.
 */
declare(strict_types=1);

/** 청첩장 디자인(JSON)에서 이름과 전화번호 뽑기 */
function admin_search_design(?string $json): array
{
    $names = []; $phones = [];
    $d = json_decode((string) $json, true);
    if (!is_array($d)) return [$names, $phones];
    foreach (($d['blocks'] ?? []) as $b) {
        if (!is_array($b) || !is_array($b['fields'] ?? null)) continue;
        foreach ($b['fields'] as $k => $v) {
            if (!is_string($v) || $v === '') continue;
            $k = (string) $k;
            if (str_ends_with($k, 'Phone')) {
                $p = preg_replace('/\D/', '', $v);
                if (strlen($p) >= 7) $phones[] = $p;
            } elseif (preg_match('/(Name|Father|Mother)$/', $k) && mb_strlen($v) <= 40) {
                $names[] = strip_tags($v);
            }
        }
    }
    return [$names, $phones];
}

/** 전화번호 숫자 맞추기 (+82 10… → 010…) */
function admin_search_phone_norm(string $p): string
{
    $p = preg_replace('/\D/', '', $p);
    if (str_starts_with($p, '82') && strlen($p) >= 11) $p = '0' . substr($p, 2);
    return $p;
}

/**
 * 검색
 * @param bool $inTrash true = 휴지통 안에서만 / false = 휴지통 밖에서만
 * @return array ['ids' => [청첩장 id => [찾은 이유, …]], 'phone_blocked' => 부관리자라 전화번호 검색을 못 했는지]
 */
function admin_search_invitations(PDO $pdo, string $q, bool $inTrash, int $limit = 3000): array
{
    $q = trim($q);
    $out = ['ids' => [], 'phone_blocked' => false];
    if ($q === '') return $out;
    $canPrivate = admin_can('customer_private');
    $isPhone = (bool) preg_match('/^[\d\s\-+().]+$/', $q) && strlen(preg_replace('/\D/', '', $q)) >= 4;
    $digits = admin_search_phone_norm($q);
    if ($isPhone && !$canPrivate) { $out['phone_blocked'] = true; }
    $tokens = array_values(array_filter(preg_split('/[\s,♥♡&+\/]+/u', mb_strtolower($q)), fn($t) => $t !== ''));
    $squash = fn(string $s) => preg_replace('/[\s\-_.]+/u', '', mb_strtolower($s));

    // 스마트스토어 구매자 이름 (개인정보 권한이 있을 때만)
    $orderers = [];
    if ($canPrivate) {
        try {
            foreach ($pdo->query('SELECT invitation_id, orderer FROM naver_orders WHERE invitation_id IS NOT NULL AND orderer IS NOT NULL')->fetchAll() as $r) {
                $orderers[(int) $r['invitation_id']][] = (string) $r['orderer'];
            }
        } catch (Throwable $e) {}
    }

    $st = $pdo->query('SELECT o.*, c.customer_code FROM invitation_orders o LEFT JOIN customers c ON c.id = o.customer_id
                       WHERE o.deleted_at IS ' . ($inTrash ? 'NOT NULL' : 'NULL') . ' ORDER BY o.id DESC LIMIT ' . max(1, $limit));
    foreach ($st as $r) {
        $id = (int) $r['id'];
        [$dNames, $dPhones] = admin_search_design($r['design_json'] ?? null);
        $why = [];

        if ($isPhone) {
            if (!$canPrivate) continue;
            $phones = $dPhones;
            if (!empty($r['customer_phone_enc'])) { $p = decrypt_data((string) $r['customer_phone_enc']); if ($p !== '') $phones[] = $p; }
            if (!empty($r['customer_phone'])) $phones[] = (string) $r['customer_phone'];
            foreach ($phones as $p) { if (str_contains(admin_search_phone_norm($p), $digits)) { $why[] = '전화번호'; break; } }
            // 숫자만 넣었어도 코드·고객코드에 들어 있으면 같이 보여줌
            if (!$why && (str_contains(strtolower((string) $r['view_slug']), $digits) || str_contains(strtolower((string) ($r['customer_code'] ?? '')), $digits))) $why[] = '코드';
        } else {
            $fields = [
                '코드'   => [(string) $r['view_slug']],
                '고객코드' => [(string) ($r['customer_code'] ?? '')],
                '이름'   => array_merge([(string) $r['groom_name'], (string) $r['bride_name'], (string) ($r['nickname'] ?? '')], $dNames),
                '메모'   => [(string) ($r['order_memo'] ?? '')],
                '구매자'  => $orderers[$id] ?? [],
            ];
            $hay = [];
            foreach ($fields as $label => $vals) $hay[$label] = $squash(implode(' ', $vals));
            $all = $squash(implode(' ', array_merge(...array_values($fields))));
            $ok = true;
            foreach ($tokens as $t) { if (!str_contains($all, $squash($t))) { $ok = false; break; } }
            if (!$ok) continue;
            foreach ($hay as $label => $h) {
                foreach ($tokens as $t) { if ($h !== '' && str_contains($h, $squash($t))) { $why[] = $label; break; } }
            }
        }
        if ($why) $out['ids'][$id] = array_values(array_unique($why));
    }
    return $out;
}
