<?php
/**
 * site_colors.php - 관리자가 정한 사이트 화면 색 (에디터·내 청첩장·관리자 화면의 바탕·옅은 면·칸·선)
 *
 *  각 화면의 CSS는 색을 var(--ui-page, #원래색)처럼 써 두었고, 이 파일이 :root에 값을 넣으면 그 색으로 바뀐다.
 *  관리자 → 사이트 정보 → "사이트 색상"에서 정하고, 정한 적 없거나 "따뜻한 베이지(처음 색)"면 아무것도 안 내보냄(원래 색).
 *  하객이 보는 청첩장 페이지(invite_view.php)는 디자인 색을 따로 쓰므로 여기와 상관없음.
 */
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo site_colors_css();
