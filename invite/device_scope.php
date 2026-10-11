<?php
/**
 * device_scope.php - 기기별로 섹션·옵션 켜고 끄기 (관리자 → 에디터 섹션 설정 → 기기별 켜고 끄기)
 *
 *  화면 네 가지마다 따로: easy-m 간편 만들기·휴대폰 / easy-pc 간편 만들기·PC / exp-m 전문가 모드·휴대폰 / exp-pc 전문가 모드·PC
 *  - sec: 그 화면에서 숨길 칸 (간편 = 손쉬운 제작 단계 id, 전문가 = 섹션 id)
 *  - opt: {칸 id: [끌 옵션]} (간편 = SECTION_EASY_OPTS 키, 'flow' = 간편 만들기 전체 기능 / 전문가 = 편집창 칸 이름 - 에디터 devKeyOf)
 *  전체 설정(손쉬운 제작 탭 · 섹션 순서 탭 비노출)에 더해서 그 화면에서만 더 숨김 (여기서 켜도 전체에서 끈 것은 안 보임)
 *  휴대폰 = 화면 폭 860px 이하이거나 손가락으로 쓰는 기기 (에디터 hhMobile)
 *  저장: invite/uploads/site/device_scope.json (모두 비우면 파일을 지움) · 에디터는 section_defaults.php의 'scope'로 받음
 */
declare(strict_types=1);

const DEVICE_SCOPES = ['easy-m' => '간편 만들기 · 휴대폰', 'easy-pc' => '간편 만들기 · PC', 'exp-m' => '전문가 모드 · 휴대폰', 'exp-pc' => '전문가 모드 · PC'];
const DEVICE_ID_RE = '/^[A-Za-z0-9_-]{1,40}$/';

function device_scope_file(): string
{
    return (defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/') . 'site/device_scope.json';
}

/** 옵션 이름 정리 - 전문가 모드 편집 칸 이름은 한글 글자라 글자 수·모양만 봄 */
function device_scope_key($k): string
{
    if (!is_string($k)) return '';
    $k = trim(preg_replace('/[\x00-\x1F\x7F<>"]+/u', '', $k) ?? '');
    return mb_strlen($k) >= 1 && mb_strlen($k) <= 60 ? $k : '';
}

function device_scope_clean($in): array
{
    $out = [];
    if (!is_array($in)) return $out;
    foreach (DEVICE_SCOPES as $ctx => $_) {
        $c = is_array($in[$ctx] ?? null) ? $in[$ctx] : [];
        $sec = [];
        foreach ((array) ($c['sec'] ?? []) as $id) if (is_string($id) && preg_match(DEVICE_ID_RE, $id) && !in_array($id, $sec, true) && count($sec) < 200) $sec[] = $id;
        $opt = [];
        foreach ((is_array($c['opt'] ?? null) ? $c['opt'] : []) as $id => $keys) {
            if (!is_string($id) || !preg_match(DEVICE_ID_RE, $id) || !is_array($keys)) continue;
            $l = [];
            foreach ($keys as $k) { $k = device_scope_key($k); if ($k !== '' && !in_array($k, $l, true) && count($l) < 200) $l[] = $k; }
            if ($l) $opt[$id] = $l;
            if (count($opt) >= 200) break;
        }
        if ($sec || $opt) $out[$ctx] = ['sec' => $sec, 'opt' => $opt ?: new stdClass()];
    }
    return $out;
}

function device_scope_get(): array
{
    $f = device_scope_file();
    if (!is_file($f)) return [];
    $j = json_decode((string) @file_get_contents($f), true);
    return device_scope_clean($j);
}

function device_scope_save($in): bool
{
    $data = device_scope_clean($in);
    $f = device_scope_file();
    if (!$data) return !is_file($f) || @unlink($f);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 262144) return false;
    $tmp = $f . '.tmp' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $f);
}
