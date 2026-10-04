-- =========================================================
-- stage5_setup.sql - 신혼여행 라이브 (사진 + 여행 체크인) + 식장 추첨
--   실행: mysql -u loveday_user -p --default-character-set=utf8mb4 loveday_invite < /var/www/loveday/www/invite/stage5_setup.sql
--   여러 번 실행해도 안전 (IF NOT EXISTS)
-- =========================================================

-- 신랑신부가 여행지에서 올리는 소식 1건 = 사진 0~6장 + 한마디 + 체크인 장소
--  - 사진 파일: uploads/{청첩장 id}/trip/ 아래 (청첩장을 완전히 지우면 폴더째 지워짐)
--  - 위치는 도시 수준으로만 저장 (소수점 한 자리 ≈ 10km) - 숙소 위치 같은 자세한 곳은 남기지 않는다
--  - visible_at: 하객에게 보이기 시작하는 시각 (섹션의 "공개 늦추기" 설정 - 안전을 위해 몇 시간 뒤에 보이게 할 수 있음)
CREATE TABLE IF NOT EXISTS trip_posts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id INT UNSIGNED NOT NULL,
    photos        TEXT          NULL,                -- JSON [{"f":"파일.webp","w":가로,"h":세로}, …]
    caption       VARCHAR(300)  NULL,
    place         VARCHAR(80)   NULL,                -- 보여줄 장소 이름 (예: 파리, 프랑스)
    lat           DECIMAL(6,1)  NULL,                -- 도시 수준 좌표
    lng           DECIMAL(6,1)  NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    visible_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_inv_time (invitation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW TABLES LIKE 'trip_posts';

-- 식장 추첨 (에디터 섹션 "행운의 추첨" - 참여 방식: 현장 코드 / 게스트스냅 참여자 / 누구나(예식 시간))
--  lottery_state: 청첩장마다 현장 코드(4자리, 하객 화면에는 절대 안 나감)·응모 열기/마감 수동 설정·진행한 라운드 수
--  lottery_entries: 응모 1건 = 하객 브라우저 1개 (같은 하객이 여러 번 응모 못 함). 당첨되면 won_round·prize가 채워진다
CREATE TABLE IF NOT EXISTS lottery_state (
    invitation_id INT UNSIGNED NOT NULL PRIMARY KEY,
    code          CHAR(4)      NOT NULL,
    manual        VARCHAR(8)   NOT NULL DEFAULT 'auto',   -- auto(예식 시간 기준) / open(지금 받기) / closed(마감)
    round_no      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lottery_entries (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id INT UNSIGNED NOT NULL,
    guest_key     CHAR(32)     NOT NULL,                  -- 하객 브라우저 구분값 (게스트스냅과 같은 쿠키)
    name          VARCHAR(20)  NOT NULL,
    entry_no      CHAR(4)      NOT NULL,                  -- 응모 번호 (당첨 확인용 - 하객 화면에 표시)
    source        VARCHAR(8)   NOT NULL,                  -- code / snap / open
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    won_round     SMALLINT UNSIGNED NULL,
    prize         VARCHAR(60)  NULL,
    won_at        DATETIME     NULL,
    UNIQUE KEY uq_guest (invitation_id, guest_key),
    KEY idx_inv (invitation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SHOW TABLES LIKE 'lottery%';
