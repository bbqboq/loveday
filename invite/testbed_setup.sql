-- =====================================================================
-- 공용 테스트베드용 청첩장 1건을 만드는 초기 설정 SQL (딱 한 번만 실행)
--
-- 별도의 testbed_*.php 파일 없이, 실제 서비스와 완전히 동일한
-- invite_load.php / invite_save.php / upload_photo.php / upload_sticker.php /
-- invite_view.php를 그대로 재사용하기 위해, "고정된 edit_token을 가진
-- 테스트 전용 청첩장 1건"을 DB에 미리 만들어두는 스크립트입니다.
--
-- 이후 관리는 새 관리자 페이지가 필요 없습니다 - 이 테스트 청첩장도
-- admin_create.php 목록에서 "코드로 찾기"에 view_slug(testbed)를 입력하거나,
-- admin_edit.php?id=<이 행의 id> 로 들어가서 다른 청첩장과 똑같이
-- 수정/상태변경(admin_edit.php)하거나 완전삭제(admin_delete.php)하면 됩니다.
-- =====================================================================

-- 1) 테스트베드 전용 더미 고객 생성
--    (upload_sticker.php가 customer_id를 요구하므로 "내 스티커" 업로드를 쓰려면 필요합니다)
INSERT INTO customers (customer_code, naver_uid_hash)
VALUES ('TESTBED', SHA2('testbed-public-customer-fixed-id', 256));

-- 2) 방금 생성된 고객의 id 확인 - 아래 3번 INSERT의 <CUSTOMER_ID> 자리에 이 값을 넣어주세요.
SELECT id FROM customers WHERE customer_code = 'TESTBED';

-- 3) 테스트베드 전용 청첩장 행 생성
--    - edit_token은 반드시 editor-prototype-v3-overlay.html의 TESTBED_EDIT_TOKEN 상수와 똑같아야 합니다.
--    - status를 처음부터 published로 해둬서 손님용 짧은주소(loveday.kr/testbed)가 바로 동작합니다.
--    - storage_plan을 permanent로 해둬서 워터마크가 안 붙고 자동만료도 없습니다.
--    - edit_pin_hash는 비워둡니다(=NULL 기본값) → PIN 없이 이 링크로 들어오는 누구나 바로 편집 가능
--      (요청하신 "미리보기/테스트용으로 아무나 수정할 수 있게"에 해당하는 부분입니다)
INSERT INTO invitation_orders
    (order_platform, customer_id, edit_token, view_slug, groom_name, bride_name,
     status, storage_plan, expires_at)
VALUES
    ('vip_admin', <CUSTOMER_ID>, 'b35f36b5390b0084c620db3e82bdeb49e1ad2f82b94f4064a596554dde1a8f07', 'testbed',
     '테스트 신랑', '테스트 신부', 'published', 'permanent', NULL);

-- =====================================================================
-- 완료 후 확인:
--   에디터: https://loveday.kr/invite/editor-prototype-v3-overlay.html?testbed=1
--   공개보기: https://loveday.kr/testbed
--
-- 나중에 완전히 초기화하고 싶으면(디자인/사진/스티커 전부 삭제):
--   관리자 로그인 → admin_create.php에서 "testbed"로 코드 찾기 → admin_edit.php → 사진 개별 삭제,
--   또는 admin_delete.php로 이 행 자체를 지운 뒤 이 SQL을 처음부터 다시 실행하면 됩니다.
-- =====================================================================
