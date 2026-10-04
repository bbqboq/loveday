-- =====================================================================
-- 게스트스냅 + 관리자 설정 테이블 (딱 한 번만 실행)
--
-- 실행 방법 (서버 터미널):
--   mysql -u loveday_user -p loveday_invite < snap_setup.sql
--
-- 여러 번 실행해도 안전합니다 (IF NOT EXISTS / INSERT IGNORE).
-- 기존 테이블(invitation_orders 등)은 전혀 건드리지 않습니다.
-- =====================================================================

-- 1) 관리자 모드에서 바꿀 수 있는 설정값 (키-값). 앞으로 다른 설정도 여기에 추가하면 된다.
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 게스트스냅 기본값 (관리자 페이지 admin_snap_settings.php에서 수정)
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES
    ('snap_quota_invite_mb', '50'),   -- 청첩장 1건당 전체 업로드 한도 (MB)
    ('snap_quota_guest_mb',  '50'),   -- 하객 1명당 업로드 한도 (MB, 청첩장 1건 기준)
    ('snap_max_file_mb',     '10'),   -- 사진 1장 최대 크기 (MB, 서버에서 줄이기 전 기준)
    ('snap_days_before',     '0'),    -- 예식일 며칠 전부터 업로드 허용
    ('snap_days_after',      '5'),    -- 예식일 며칠 후까지 업로드 허용
    ('snap_retention_days',  '15'),   -- 예식일 며칠 후에 사진을 자동 삭제 (다운로드도 그때까지)
    ('snap_download_limit',  '2'),    -- 신랑신부가 전체 사진(zip)을 받을 수 있는 횟수
    ('bgm_max_mb',           '10');   -- 배경음악 직접 올리기 최대 용량 (MB)

-- 2) 하객이 올린 사진 (영상은 받지 않음)
CREATE TABLE IF NOT EXISTS guest_snaps (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id INT UNSIGNED NOT NULL,              -- invitation_orders.id
    guest_key     CHAR(32)     NOT NULL,              -- 하객 브라우저 구분값(쿠키) - 1인당 한도 계산용, 개인정보 아님
    guest_name    VARCHAR(40)  NULL,                  -- 하객이 적은 이름 (선택)
    file_name     VARCHAR(64)  NOT NULL,              -- 서버에 저장된 랜덤 파일명 (uploads/snap/{invitation_id}/)
    bytes         INT UNSIGNED NOT NULL,              -- 저장된 크기 (한도 계산은 이 값의 합계)
    width         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    height        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invite_time  (invitation_id, created_at),
    KEY idx_invite_guest (invitation_id, guest_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) 전체 사진(zip) 다운로드 기록 - 횟수 제한 확인용
CREATE TABLE IF NOT EXISTS guest_snap_downloads (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id INT UNSIGNED NOT NULL,
    photo_count   INT UNSIGNED NOT NULL DEFAULT 0,
    downloaded_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_invite (invitation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 확인
SHOW TABLES LIKE 'app_settings';
SHOW TABLES LIKE 'guest_snap%';
SELECT * FROM app_settings;
