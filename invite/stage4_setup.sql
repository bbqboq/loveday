-- =====================================================================
-- 4단계: 참석여부(RSVP) + 방명록 테이블, 추가 관리자 설정값 (딱 한 번만 실행)
--
-- 실행 방법 (서버 터미널):
--   mysql -u loveday_user -p loveday_invite < stage4_setup.sql
--
-- 여러 번 실행해도 안전합니다 (IF NOT EXISTS / INSERT IGNORE).
-- 기존 테이블(invitation_orders 등)은 전혀 건드리지 않습니다.
-- snap_setup.sql(app_settings 테이블)을 먼저 실행해 둔 상태여야 합니다.
-- =====================================================================

-- 1) 참석여부 응답. 하객 한 명(브라우저 1개)당 청첩장마다 한 줄 - 다시 보내면 덮어써진다(수정).
CREATE TABLE IF NOT EXISTS rsvp_responses (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id INT UNSIGNED NOT NULL,                      -- invitation_orders.id
    guest_key     CHAR(32)     NOT NULL,                      -- 하객 브라우저 구분값(쿠키) - 본인 응답 수정용
    side          ENUM('groom','bride') NOT NULL,             -- 신랑측 / 신부측
    attend        ENUM('yes','no') NOT NULL,                  -- 참석 / 불참
    guest_name    VARCHAR(40)  NOT NULL,
    headcount     TINYINT UNSIGNED NOT NULL DEFAULT 1,        -- 본인 포함 인원 (불참이면 0)
    meal          ENUM('yes','no','unknown') NOT NULL DEFAULT 'unknown',
    phone_enc     TEXT         NULL,                          -- 연락처(선택) - 암호화 저장, 신랑신부 관리 화면에서만 복호화
    memo          VARCHAR(300) NULL,                          -- 전하는 말(선택)
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL,
    UNIQUE KEY uq_invite_guest (invitation_id, guest_key),
    KEY idx_invite_time (invitation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) 방명록
CREATE TABLE IF NOT EXISTS guestbook_entries (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id INT UNSIGNED NOT NULL,
    guest_key     CHAR(32)     NOT NULL,                      -- 같은 브라우저면 비밀번호 없이 본인 글 삭제 가능
    author        VARCHAR(30)  NOT NULL,
    message       VARCHAR(500) NOT NULL,                      -- 글자만 (관리 화면·검색용)
    message_html  TEXT         NULL,                          -- 글꾸미기(굵게·색·글꼴 등) - 서버에서 걸러낸 HTML
    pw_hash       VARCHAR(255) NOT NULL,                      -- 작성자가 정한 삭제용 비밀번호 (해시)
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invite_time (invitation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2-1) 이미 이 파일을 한 번 실행해서 방명록 테이블이 있는 경우: 글꾸미기 칸만 추가 (MariaDB)
ALTER TABLE guestbook_entries ADD COLUMN IF NOT EXISTS message_html TEXT NULL AFTER message;

-- 2-2) 설정값 칸을 긴 글(방명록 금지어 목록 등)도 들어가게 넓힘
ALTER TABLE app_settings MODIFY setting_value TEXT NOT NULL;

-- 2-3) 내 청첩장 화면(dashboard.php)에서 청첩장마다 붙이는 별칭 (예: 본식용, 테스트)
ALTER TABLE invitation_orders ADD COLUMN IF NOT EXISTS nickname VARCHAR(40) NULL;

-- 2-4) 스마트스토어(네이버 커머스API) 주문 → 청첩장 자동 결제 처리 기록 (naver_commerce.php / admin_naver.php)
--      주문자 이름은 가운데를 가린 형태(홍*동)로만 저장한다
CREATE TABLE IF NOT EXISTS naver_orders (
    product_order_id VARCHAR(40)  NOT NULL PRIMARY KEY,   -- 네이버 상품주문번호
    order_id         VARCHAR(40)  NOT NULL DEFAULT '',    -- 네이버 주문번호
    product_name     VARCHAR(200) NULL,
    product_option   VARCHAR(500) NULL,                   -- 구매자가 고른 옵션 + 직접 입력한 청첩장 코드
    orderer          VARCHAR(40)  NULL,
    amount           INT          NULL,
    order_status     VARCHAR(40)  NULL,                   -- PAYED / DELIVERED / CANCELED …
    plan             VARCHAR(20)  NULL,                   -- one_year / permanent
    code             VARCHAR(40)  NULL,                   -- 찾은 청첩장 코드(view_slug)
    invitation_id    INT UNSIGNED NULL,
    result           VARCHAR(20)  NOT NULL DEFAULT 'waiting', -- applied(처리됨) / review(확인 필요) / reverted(취소로 되돌림) / exchange_hold(교환 확인 중) / closed / ignored / waiting
    message          VARCHAR(255) NULL,
    paid_at          DATETIME     NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NULL,
    KEY idx_result (result),
    KEY idx_invitation (invitation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2-4b) 취소·교환 자동 처리용 칸 (이미 만든 표에도 추가)
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS claim        VARCHAR(60) NULL AFTER order_status;  -- 취소·반품·교환 진행 상태
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS prev_plan    VARCHAR(20) NULL AFTER invitation_id; -- 결제 처리 전 보관 기간 (취소되면 여기로 되돌림)
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS prev_expires DATETIME    NULL AFTER prev_plan;
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS prev_paid_at DATETIME    NULL AFTER prev_expires;
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS decided_at   DATETIME    NULL AFTER paid_at;       -- 구매확정 시각 (파일 다운로드 조건)
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS reverted_at  DATETIME    NULL AFTER decided_at;    -- 취소로 되돌린 시각
ALTER TABLE naver_orders ADD COLUMN IF NOT EXISTS grace_given  TINYINT(1)  NOT NULL DEFAULT 0 AFTER reverted_at; -- 되돌릴 때 6시간 추가를 줬는지 (청첩장당 1회)

-- 2-5) 신랑신부가 직접 올린 배경음악 기록 (upload_music.php / admin_music.php)
--      올릴 때 "음원 사용 권리 확인"에 동의한 시각을 남기고, 저작권 신고 시 관리자가 음악만 지울 수 있게 한다
CREATE TABLE IF NOT EXISTS music_uploads (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id  INT UNSIGNED NOT NULL,
    file_name      VARCHAR(80)  NOT NULL,                  -- uploads/{청첩장 id}/ 아래 파일 이름
    orig_name      VARCHAR(150) NULL,                      -- 올린 사람이 고른 원래 파일 이름
    orig_size      INT UNSIGNED NULL,                      -- 줄이기 전 크기
    size_bytes     INT UNSIGNED NULL,                      -- 저장된 크기
    agreed_at      DATETIME     NULL,                      -- 권리 확인 동의 시각 (이 기능 전에 올린 곡은 비어 있음)
    ip_hash        CHAR(64)     NULL,                      -- 올린 곳 확인용 (IP 그대로는 저장하지 않음)
    status         VARCHAR(20)  NOT NULL DEFAULT 'active', -- active / replaced(새 곡으로 바뀜) / removed(관리자 삭제)
    removed_at     DATETIME     NULL,
    removed_reason VARCHAR(200) NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inv (invitation_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 관리자 설정값 (admin_snap_settings.php에서 수정)
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('rsvp_retention_days', '90'),    -- 예식일 며칠 후에 참석여부 명단(이름·연락처) 자동 삭제
    ('demo_hours',          '24'),    -- 회원가입 없이 만든 체험 청첩장 보관 시간
    ('demo_max_active',     '300');   -- 동시에 있을 수 있는 체험 청첩장 최대 수

-- 확인
SHOW TABLES LIKE 'rsvp_responses';
SHOW TABLES LIKE 'guestbook_entries';
SELECT * FROM app_settings;
