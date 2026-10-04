# LOVE DAY (loveday.kr) – 모바일 청첩장 플랫폼

> 이 파일은 Claude(클라우드 세션)가 작업을 이어받기 위한 인수인계 문서입니다.
> 2026-10-04 Cowork 세션에서 정리. 이 저장소의 `invite/` 폴더가 서버의 `/invite/` 폴더와 같습니다.

## 1. 작업 규칙 (사용자 선호 – 반드시 지킬 것)

- 답변은 **한국어**, 제목·볼드·표로 정리. "~라고 하셨듯이" 같은 서두 금지. 후속 질문은 최대 1개.
- 결과물은 **바뀐 파일만** 전달 (`invite/...` 폴더 구조 유지한 zip 또는 커밋).
- 공용 자산(`assets/invite-blocks.js` 등)을 바꾸면 에디터의 `?v=` 값을 올린다 (현재 `v=1017d`).
- 디자인은 **차분하고 세련된(muted)** 톤.
- 버튼·안내 문구는 왕초보도 알아듣는 쉬운 말.

## 2. 보안 규칙 (절대)

- **`invite/config.php`는 절대 커밋·전달하지 않는다.** (DB 비밀번호, 암호화 키, 네이버 키, 관리자 해시 등). 값도 바꾸지 않는다.
- 계좌번호는 평문으로 저장하지 않는다 (서버는 `account_info_enc`로 암호화, 편집자에게만 복호화해서 내려줌).
- `uploads/` (사용자 사진·음악·설정 json)는 커밋하지 않는다.

## 3. 핵심 파일

| 파일 | 역할 |
|---|---|
| `invite/editor-prototype-v3-overlay.html` | 고객 에디터 전체 (약 1.5MB, 단일 파일). 전문가 모드 + 이지모드(간편 만들기) |
| `invite/assets/invite-blocks.js` / `.css` | 에디터·공개 페이지 공용 섹션 렌더러 (`InviteBlocks`) |
| `invite/render-invite.js` / `.css` | 공개 청첩장 렌더러. **배포 시 `invite/assets/render-invite.js`로 복사**해서 씀 (두 곳 동일하게 유지) |
| `invite/invite_view.php` | 공개 청첩장 페이지 |
| `invite/invite_load.php` / `invite_save.php` | 에디터 저장·불러오기 (계좌 암호화) |
| `invite/section_defaults.php` | 섹션 순서·묶음·이름·손쉬운 제작 단계 설정 저장/조회 (`uploads/site/section_defaults.json`) |
| `invite/admin_sections.php` | 관리자 → 에디터 섹션 설정 (탭: 섹션 순서 / 편집창·메뉴 모양 / 섹션 묶음 / **손쉬운 제작**) |
| `invite/admin_tip_tours.php`, `editor_tips.php` | 커스텀 편집팁(코치 투어) |
| `invite/admin_designs.php`, `featured_presets.php` | 추천 디자인 |
| `invite/weather.php` | 예식장 날씨(장식 효과 실시간 날씨) |
| `invite/gdrive*.php` | 게스트스냅 구글 드라이브 연동 |
| `invite/hero_layouts.php` | 메인 레이아웃 (관리자 저장분 `uploads/site/hero_layouts.json`, 기본 3개는 `invite-blocks.js` `HERO_LAYOUTS`) |
| `invite/sample_api.php` | 디자인 샘플 (관리자가 에디터로 꾸며 저장한 완성 디자인) |

## 4. 최근 작업 (2026-10-04 기준 완료)

### 이지모드 (간편 만들기)
- 진입: 도구 메뉴 → 간편 만들기(템플릿 모드), 위쪽 **정보** 버튼(지금 디자인 그대로, in-place 모드). 버튼명 "자세히 꾸미기" → **"전문가 모드"**.
- 단계는 관리자 **손쉬운 제작** 탭 설정 순서·켜기대로 (`section_defaults.php` → `easy.steps`). 새 단계는 기본 순서상 앞 단계 뒤에 자동 삽입.
- 단계: 두 사람 · 메인 화면 · **테마** · 갤러리 · 예식장 · 교통 안내 · 인사말 · 혼주·연락처 · 마음 전할 곳 · 디데이·달력 · 안내 말씀 · 참석 여부 · 방명록 · 영상 · 배경음악 · 마무리.
- 이지모드를 열면 청첩장 섹션 순서도 단계 순서대로 정렬 (`spApplyEasyOrder`, 메인 사진·영상 맨 위, 공유하기 맨 아래). 이지모드 중엔 S10 묶음 정렬(`bsS10Normalize`) 안 함.
- 단계별 세부 옵션은 관리자에서 켜고 끔 (`easy.off = {step:[key]}`, 카탈로그 `SECTION_EASY_OPTS`, 에디터 `spOpt(step,key)`).
- 메인 화면: 사진/유튜브 → 업로드/주소 → 화면 크기(프레임 안에·가로 꽉·세로(높이 조절)·전체화면) → 보일 부분·확대 → 글자 잘 보이게. 전체화면이면 목록이 접히고 **↓ 스크롤 버튼** 화면(모양·직접 꾸미기·크기·진하기·움직임·등장 효과).
- 되돌리기/다시하기 버튼, 같은 단계 안에서 다시 그려도 스크롤 위치 유지.
- 계좌: 디자인 예시 계좌는 지우지 않고 안내만, 은행·이름만 적어도 미리보기 반영.

### 2026-10-04 오후 추가 (브랜치 `claude/easy-account-fix`)
- 간편 만들기 흐름: 디자인 고르기(카드마다 [샘플 확인하기]=화면 가득 미리보기 / [이 샘플로 선택]) → **빠른 시작 4단계**(성함 → 예식 일시 → 예식장 → 메인 사진) → 미리보기 → [닫기] → **옵션 창**(모든 단계를 펼침 목록 + 켜기/끄기, `state.spView`='list'). 위쪽 링크로 한 단계씩 화면과 오감.
- 아래 버튼 줄은 간편 만들기에서 **[저장 | 미리보기]** (원래 action-bar 숨김). 미리보기 화면 = 에디터 미리보기를 화면 가득 + 아래 띠. 마무리/옵션 창 끝 → 저장 + 완성본(띠에 [발행하기][수정하기]).
- 3단계 "테마" → **"색·글꼴"**: 카드 이름은 색 이름(`spHexName`), 맨 위 [디자인 바꾸기](`spChangeDesign`, 내용은 `spExtract/spPour`로 옮김).
- PC 간편 만들기: 왼쪽 아래 떠 있는 되돌리기 탭 + 머리줄 오른쪽 끝(`#spTopRight`)에 되돌리기·전문가 모드. 지금 단계 섹션은 여백 눈금자·글자 끌기 가능(`spLiveSync`).
- **메인 레이아웃**: 메인 사진·영상 문구 칸 `heroLayers`(글 `f[키]`, 자리·글꼴 `f.layout[키]`, `{신랑}{신부}{날짜:영문}{날짜:점}{요일:영문}{시간}{예식장}`), 이름·날짜 숨기기 `hideParts`. 기본 3개 + 관리자 저장(전문가 모드 메인 편집창 맨 아래, 관리자만). 간편 만들기 메인 화면 단계 맨 위 레이아웃 고르기.
- 영문 글꼴 6개(`en:true`, 전체 글꼴 목록에선 숨김). 글꼴 목록은 **4곳**을 같이 고칠 것: 에디터 `fontOptions`, `render-invite.js` `fontOptions`, `invite_view.php` `$fontCssUrls`, `invite-blocks.js` `FONT_CSS`.
- 엔딩 섹션 "엔딩 크레딧(스탭롤)" 모양(`creditsHtml`). 계좌 복사: 첫 누름에 받아온 뒤 복사, 자유 배치 줄 누르면 복사(`data-ib-copyrow`).
- 마음 전하실 곳 자유 배치: 이름 칸 폭 `accLw`(신랑·신부만이면 좁게 → 가운데 정렬), 빈 줄 숨김.
- 메인 레이아웃 2차: 문구 칸 세로(`vertical`)·곡선(`arc`,`arcW`)·등장 효과(`anim` write/fade/up/zoom), 사진 칸 모드 `heroBox`(x·y·w·h %, frame, fade). 기본 레이아웃 9개(참고 샘플 구성).
- 안내 말씀 모양: 카드·박스·**슬라이드**·**탭** + 예시(포토부스·주차·답례품·식사·화환·셔틀) `NOTICE_TPL`.
- **사이트 색상**: 화면 CSS의 베이지는 `var(--ui-page|tint|soft|line, #원래색)`. 관리자 → 사이트 정보 → 사이트 색상(프리셋/직접) → `app_settings.ui_colors` → `site_colors.php`(`:root` 값). 새 화면을 만들면 색을 이 4개 변수로 쓰고 `<?= site_colors_link() ?>`를 `</head>` 앞에. `assets/admin.css`는 저장소에 없어서 아직 안 바꿈.
- zip으로 줄 때 루트 `invite/render-invite.js`는 빼고 `invite/assets/render-invite.js`만 (서버는 assets만 씀).

### 기타
- 공유하기 섹션은 항상 맨 아래(기타 묶음 끝), 새 공유하기는 "섹션 자리 공유버튼" 기본 OFF.
- 디데이: 달력 보여주기 / 카운트 보여주기(`showCounter`) 따로, 편집창에서 짝지어 배치.
- 관리자 섹션 이름 변경은 즉시 저장.
- 손쉬운 제작 탭: 끌어서 순서(Sortable, forceFallback), 꺼진 단계 칸, 단계별 옵션 폴더.

## 5. 남은 일 / 확인 필요

- 사용자 보고 "이지모드 계좌 입력 안 됨"은 위 수정으로 해결된 것으로 보이나 실서버 확인 필요.
- 유튜브 보일 부분 창은 테스트 환경에서 썸네일이 막혀 실제 확인 못 함.
- 실제 음악 재생·비트 웨이브, 실제 날씨·지오코딩은 테스트 환경에서 확인 못 함 (목업).
- 전문가 모드에서 S10 그룹 섹션이면 이지모드 순서와 묶음 순서가 다를 수 있음 (설계상 한계).

## 6. 테스트 환경 만들기 (참고)

- PHP 8.x 내장 서버 + MariaDB. `invite/testbed_setup.sql`, `snap_setup.sql`, `stage4_setup.sql`, `stage5_setup.sql`로 테이블 생성.
- `config.php`는 테스트용으로 따로 만든다 (실서버 값 사용 금지). 테스트 전용 define: `GDRIVE_*_BASE`, `WEATHER_API_BASE`, `TRIP_GEOCODE_BASE`, `NAVER_COMMERCE_API_BASE`로 외부 API를 목업 서버로 돌릴 수 있음.
- 에디터 확인은 Playwright(Chromium)로 `editor-prototype-v3-overlay.html?t=<편집토큰>` 열어서 스크린샷. 외부 CDN(Sortable 등)은 로컬 파일로 라우팅.
- 저장소에 기본 테이블(invitation_orders 등) 만드는 SQL이 없음 → 코드 보고 추정해서 만듦. `client_ip()`는 실서버 config.php에 있음(테스트 config에도 넣을 것). `admin_login_attempts`(ip PK, attempts, locked_until, updated_at).
- 테스트 환경은 바깥 주소가 막혀서 Sortable·구글 글꼴은 npm(`sortablejs`, `@fontsource/*`)에서 받아 로컬로 라우팅.
- 디자인 JSON을 DB에서 다룰 때 `mysql -N` 출력으로 JSON을 옮기지 말 것 (이스케이프 깨짐) – PDO로 읽고 쓰기.
