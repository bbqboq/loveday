# LOVE DAY (loveday.kr) – 모바일 청첩장 플랫폼

> 이 파일은 Claude(클라우드 세션)가 작업을 이어받기 위한 인수인계 문서입니다.
> 2026-10-04 Cowork 세션에서 정리. 이 저장소의 `invite/` 폴더가 서버의 `/invite/` 폴더와 같습니다.

## 1. 작업 규칙 (사용자 선호 – 반드시 지킬 것)

- 답변은 **한국어**, 제목·볼드·표로 정리. "~라고 하셨듯이" 같은 서두 금지. 후속 질문은 최대 1개.
- 결과물은 **바뀐 파일만** 전달 (`invite/...` 폴더 구조 유지한 zip 또는 커밋).
- 공용 자산(`assets/invite-blocks.js` 등)을 바꾸면 에디터의 `?v=` 값을 올린다 (현재 `v=1017y`).
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
- 단계별 세부 옵션은 관리자에서 켜고 끔 (`easy.off = {step:[key]}`, 카탈로그 `SECTION_EASY_OPTS`, 에디터 `spOpt(step,key)`). 단계가 아닌 전체 기능은 `flow`(질문 답하기·나중에 할게요·지금 청첩장 카드·정리 화면·섹션 순서) - 관리자 손쉬운 제작 탭 맨 위 "간편 만들기 전체" 칸. 새 기능을 만들면 여기 옵션도 추가할 것.
- **관리자 손쉬운 제작 탭 = 실제 화면 구조** (2026-10-05): ① **처음 만들 때 질문** `easy.ask` [{id,on}] (id = `SECTION_EASY_ASK` = 에디터 `SP_ASK`의 `k` 또는 `k:part`, `spAskId`) - 순서·켜기 그대로 `spAskSteps`가 물음 (예전 flow askGreet 등은 `section_defaults_easy_ask_saved`가 옮겨 읽음). ② **목록 화면 메뉴** `easy.steps` [{id,on,g}] - on = 목록에 보임, g = 묶음(must/more/deco, `SECTION_EASY_GROUPS`), 배열 순서 = 묶음 안 순서·섹션 순서(`spApplyEasyOrder`). 관리자에서 묶음 사이로 끌면 `ezPlace`가 새 이웃 옆에만 끼움(전체 순서 유지). `easy.showOff`(기본 false) = 디자인에서 꺼 둔 섹션도 목록에 보이기. 오른쪽 폰 미리보기는 [① 질문 화면 | ② 목록 화면] 탭. 새 질문을 만들면 `SP_ASK`와 `SECTION_EASY_ASK` 둘 다 고칠 것.
- **질문으로 처음 만들 땐 메인 화면만 켜고 시작** (`spEasyStartOff`, 메인 사진·영상·공유하기만 그대로, 디자인에 켜져 있던 것은 `state.spDesignOn`): 질문에 닿으면 그 섹션을 켬 `spAskReveal`/`SP_ASK_REVEAL` (날짜→디데이(일정 미정이면 안 켬), 예식장→오시는 길, 인사말→인사말 + '예시 문구 그대로' 표시). 계좌·참석 여부는 [넣을게요·받을게요]를 고를 때. 한 번 켠 섹션은 `state.spRevealed`에 남아 다시 켜지 않음. 질문을 마쳐도 나머지(갤러리 등)는 꺼진 채 → 목록에서 스위치로 켬. 미리보기가 이미 그 섹션 근처(160px 안)면 다시 스크롤하지 않음(`spScrollPreview`). 다른 질문에서 첫 화면 질문으로 올 땐 스크롤 없이 바로 그 자리 + 살짝 나타나기(`spScrollPreview(ids, pop)`, `.sp-pop`). 나머지 섹션은 질문 뒤에도 꺼 둠 - 고객이 목록에서 켬 (사용자 결정 2026-10-05).
- **메인 사진 예시**(관리자 손쉬운 제작 탭 맨 위 칸, 올리면 바로 저장 `act=easy_hero` → `uploads/site/32자.webp`, `easy.heroSample`): 간편 만들기에서 메인 사진이 고객 사진이 아니면 이 사진을 넣어 둠(`spApplyHeroSample` - 처음 만들 때·간편 만들기를 열 때). `spIsReal`은 `uploads/site/`를 고객 사진으로 안 침 → 사진 질문·목록은 '아직 안 넣었어요'. 기본으로 되돌리기를 눌러도 예시 사진은 그대로. **보일 부분·확대**도 관리자 칸에서 사진을 끌고 막대로 정함(바로 저장 `act=easy_hero_pos` → `easy.heroPos` {focus:'x% y%', zoom}, 새 사진을 올리면 처음대로) → `spApplyHeroSample`이 그 사진의 `state.imgFocus`/`imgZoom`에 넣음(열 때마다 관리자 값으로 맞춤). 예시 사진일 땐 고객 목록의 '보일 부분 · 확대' 칸은 안 보임. 질문 화면 '지금 청첩장' 카드(`spHeroLayoutMini`, 예전엔 kind 'hero'로 불려 사진이 안 나왔음)·대시보드 표지(`dash_img_ok`가 `uploads/site/` 허용, `coverPos`/`coverZ` → `.cvz`)도 그 위치·확대로 보임. 고객이 '보일 부분 · 확대' 칸에서 끌거나 키우는 동안 작은 그림들(지금 청첩장 카드·레이아웃 카드)도 `spMiniFocus`로 바로 따라감 (휴대폰은 큰 미리보기가 안 보여서).
- **스타터 ON/OFF** (관리자 ② 목록 화면 칸의 켜짐 스위치 아래, `easy.steps[].start`, 기본 OFF): ON이면 질문으로 처음 만들 때 그 칸의 섹션을 **그 질문에 닿을 때** 켬 (`spAskReveal`, 디자인에서 꺼져 있었어도 · 처음엔 다른 섹션처럼 꺼져 있음 · 고르는 질문이면 '넣을게요' 쪽을 골라 둠). 두 사람·메인 화면·색·음악·인트로는 없음(`SECTION_EASY_NOSTART`). 목록에 안 보이게 끄면 스타터도 꺼짐. 스타터 섹션은 따로 묻는 질문이 없으면 **질문 흐름 끝에 한 장씩** 나옴(`spAskSteps`의 `starter:true`, 그 섹션 편집 칸 + '청첩장에 넣기' 스위치), 정리 화면 줄·지금 청첩장 카드 꼬리표에도 나옴.
- **전문가 모드에만 있던 섹션 11개**(타임라인·인터뷰·프로필형 소개·손편지·함께한 시간·엔딩·게스트스냅·신혼여행 라이브·행운의 추첨·D-DAY 하객 안내·예식 후 감사 인사)도 손쉬운 제작 목록 메뉴 (`SECTION_EASY_EXTRA` = 에디터 `SP_EXTRA`, 단계 id = 섹션 id, **기본 꺼짐**). 관리자가 켜서 묶음에 놓으면 고객 목록에 나오고, 칸 안은 전문가 모드 편집창과 같은 것(`spGeneric` → `renderTimelineEditor`/`renderInterviewEditor`/`renderSchemaEditor`). 그 칸이 다시 그릴 때 `renderFieldEditor`가 간편 칸(`spGenBox`)에 그림. 모양은 `spGenTidy`가 간편 만들기 모양으로 바꿈 (켜고 끄기 → `.sp-sw` 스위치 줄 + 설명, 고르는 버튼 → 칩, 사진 버튼 → 점선 버튼, 삭제·↑↓ 작게, 미리보기 조작 안내 빼기, 다시 그려도 MutationObserver로 다시 적용) + CSS `.sp-gen`. 전문가 모드는 pz 타일 모양 그대로. 디자인에서 꺼져 있어도 숨기지 않음. 새 공용 섹션을 만들면 두 곳에 추가할 것.
- 디자인에서 꺼 둔 섹션 = `state.spDesignOff` (디자인 고를 때·디자인 바꿀 때·처음 간편 만들기를 열 때 `spMarkDesignOff`). 목록에서 그 섹션이 꺼져 있으면 숨김, 고객이 켜면 보이고, 목록 스위치로 직접 끄면 계속 보임.
- 스크롤 버튼(↓) 다음 섹션 이동 `goNextSection`: offsetTop 기준 (등장 효과로 밀린 자리 무시).
- 메인 화면: 사진/유튜브 → 업로드/주소 → 화면 크기(프레임 안에·가로 꽉·세로(높이 조절)·전체화면) → 보일 부분·확대 → 글자 잘 보이게. 전체화면이면 목록이 접히고 **↓ 스크롤 버튼** 화면(모양·직접 꾸미기·크기·진하기·움직임·등장 효과).
- 되돌리기/다시하기 버튼, 같은 단계 안에서 다시 그려도 스크롤 위치 유지.
- 계좌: 디자인 예시 계좌는 지우지 않고 안내만, 은행·이름만 적어도 미리보기 반영.

### 2026-10-04 오후 추가 (브랜치 `claude/easy-account-fix`)
- 간편 만들기 흐름 (2026-10-05 바뀜): 디자인 고르기(카드마다 [샘플 확인하기] / [이 샘플로 선택]) → **질문에 답하기**(`state.spView`='quick', `SP_ASK`: 이름 → 예식 일시 → 예식장 → 첫 화면 사진(`spHeroPart`='media', 올리면 바로 다음) → 첫 화면 꾸미기('look') → 인사말 → 계좌 → 참석 여부 → 배경음악, 고르는 질문은 섹션을 켜고 끔(고른 표시만, 넘어가기는 [다음]), 답은 `state.spAsk`) → 미리보기 → [닫기] → **목록 한 장**(`spRenderList`: 꼭 필요한 것 n/4 · 채우면 좋은 것 · 꾸미기 3묶음, 칸 한 줄 요약 `spSum`, 한 번에 하나만 펼침). 예전 '한 단계씩(2/16)' 화면은 없앰. 정보 버튼(in-place)도 목록 화면.
- **간편 만들기 진행 상태 저장** (`SP_KEEP`: spView·spAt·spAsk·spRevealed·spDesignOff·spDesignOn·spListG·spOrderCustom을 디자인과 같이 저장): 지금 질문은 `state.spAt`(질문 id, 정리 화면은 '#done'), 질문을 넘길 때마다 0.7초 뒤 자동 저장(`spAskAutoSave`), 다시 열면 `spResumeStep()` 질문부터 (예전엔 저장 안 돼서 새로고침하면 목록 화면=끝으로 감).
- 질문 화면 모양(C안 목업): 위 ‹ 질문 n/N · 진행 막대 · [나중에 할게요] · **지금 청첩장 카드**(`spNowCardHtml`, 누르면 미리보기), 아래 [이전]은 둘째 질문부터 왼쪽에서 들어옴(`.sp-ask-nav.first`). 마지막 질문 뒤 **정리 화면**(spStep === 질문 수, [고치기] · [처음부터] · [완성본 보기] → 발행 미리보기 → 닫으면 목록). 날짜 질문 **[아직 일정을 잡지 않았어요]**(누른 뒤 [다음]으로 넘어감, `spSetDateTbd`: `state.info.undecided`, `state.dateTbd` → 첫 화면 날짜 자리 안내 문구, 디데이 잠시 끔, 레이아웃 날짜 글자 칸은 비움). 예식장 질문 지도 예시(`#spMapDemo`, 가로 꽉·높이 바로 반영, 휴대폰에서만 - PC는 왼쪽 미리보기에 실제 지도). 첫 화면 사진 질문은 큰 버튼(`.sp-bigup`).
- **PC 목록 화면은 칸을 바로 끌어서 섹션 순서 바꾸기** (`spBindListDrag`, Sortable, 칸 머리줄 잡고 끌기 · 마우스를 올리면 왼쪽 ⠿, 휴대폰은 안 됨, 관리자 `flow.order`를 끄면 같이 꺼짐). 옮긴 칸의 섹션만 새 이웃 옆으로(`spListMove`), 다른 묶음에 놓으면 그 묶음에 남음(`state.spListG`), 묶음 안 칸은 청첩장 섹션 순서대로 보임(`spSortByBlocks`). 섹션이 없는 칸(두 사람·메인 화면·색·음악·인트로)은 못 옮김.
- 목록 화면 맨 아래 **[섹션 순서 바꾸기]**(`spRenderOrder`, 끌기 Sortable + ↑↓, 첫 화면 맨 위·공유하기 맨 아래 고정). 바꾸면 `state.spOrderCustom` → `spApplyEasyOrder`가 다시 정렬하지 않음.
- 질문 5(첫 화면 꾸미기)에서 [다음] → 같은 질문 안에서 스크롤 버튼(↓) 꾸미기(`spHeroPhase`='scroll') → [다음] → 질문 6.
- 인사말 예시 문구 `WZ_TONES` 11개(격식 있게·다정하게·감성적으로·짧게·혼주 인사) - 간편 만들기 인사말 칸과 전문가 모드 인사말 편집창 [예시 문구에서 고르기](`appendGreetPresets`)가 같이 씀.
- 종이 질감: `setPaper(k)` (간편·전문가 같이) - 고르기 전 바탕색을 `state.bgBeforePaper`에 두고 "없음"이면 되돌림.
- 간편 만들기에서 질문·칸·화면을 옮기면 음악 멈춤 `stopAllBgm()` (곡 미리 듣기 + 미리보기 음악 버튼).
- 간편 만들기에서 메인 사진·영상은 **전체화면만** (`spForceFull`, 간편 만들기를 열 때도 적용), 처음 만들 때 첫 화면 레이아웃은 **매거진 표지**, 화면 크기·프레임·세로 높이 고르기 없음. 스크롤 버튼(↓)은 목록의 메인 화면 칸 → [↓ 스크롤 버튼 모양 바꾸기].
- 간편 만들기엔 아래 버튼 줄이 **없음** (예전 [저장 | 미리보기] 삭제, action-bar도 숨김, body 아래 여백 0). 저장은 [완성본 보기]·질문 넘길 때 자동(`spAskAutoSave`). **미리보기·완성본 = 하객이 보는 실제 화면**: `spOpenPreview`가 저장 뒤 `invite_view.php?preview_t=편집토큰`을 `#spPvReal` iframe(PC는 폭 430px)으로 띄움 → 고칠 수 없고, 배경음악을 켰으면 iframe의 `__ibBgmAudio`를 틀어 줌. 아래 띠 [발행하기][수정하기]. 닫으면 iframe을 지워 음악도 멈춤. 편집 토큰이 없을 때(샘플 편집)만 에디터 미리보기를 통째로 보기 전용으로. 서버가 끼워 보이기를 막으면(X-Frame-Options: DENY 등 - 실서버에서 '완성본 화면이 아예 안 뜸' 보고) load 때 iframe 안을 못 읽음 → 에디터 미리보기(보기 전용) + [새 창으로 보기](`#spPvOpen`)로 자동 전환, 9초 안에 안 열려도 같음. 서버는 `X-Frame-Options: SAMEORIGIN`이면 창 안에 그대로 뜸.
- 3단계 "테마" → **"색·글꼴"**: 카드 이름은 색 이름(`spHexName`), 맨 위 [디자인 바꾸기](`spChangeDesign`, 내용은 `spExtract/spPour`로 옮김).
- PC 간편 만들기: 왼쪽 아래 떠 있는 되돌리기 탭 + 머리줄 오른쪽 끝(`#spTopRight`)에 되돌리기·전문가 모드. 지금 단계 섹션은 여백 눈금자·글자 끌기 가능(`spLiveSync`).
- **메인 레이아웃**: 메인 사진·영상 문구 칸 `heroLayers`(글 `f[키]`, 자리·글꼴 `f.layout[키]`, `{신랑}{신부}{날짜:영문}{날짜:점}{요일:영문}{시간}{예식장}`), 이름·날짜 숨기기 `hideParts`. 기본 3개 + 관리자 저장(전문가 모드 메인 편집창 맨 아래, 관리자만). 간편 만들기 메인 화면 단계 맨 위 레이아웃 고르기.
- 영문 글꼴 6개(`en:true`, 전체 글꼴 목록에선 숨김). 글꼴 목록은 **4곳**을 같이 고칠 것: 에디터 `fontOptions`, `render-invite.js` `fontOptions`, `invite_view.php` `$fontCssUrls`, `invite-blocks.js` `FONT_CSS`.
- 엔딩 섹션 "엔딩 크레딧(스탭롤)" 모양(`creditsHtml`). 계좌 복사: 첫 누름에 받아온 뒤 복사, 자유 배치 줄 누르면 복사(`data-ib-copyrow`).
- 마음 전하실 곳 자유 배치: 이름 칸 폭 `accLw`(신랑·신부만이면 좁게 → 가운데 정렬), 빈 줄 숨김.
- 메인 레이아웃 2차: 문구 칸 세로(`vertical`)·곡선(`arc`,`arcW`)·등장 효과(`anim` write/fade/up/zoom), 사진 칸 모드 `heroBox`(x·y·w·h %, frame, fade). 기본 레이아웃 14개(참고 샘플 구성 9 + 매거진 표지·미니멀 아래 세 줄·둥근 영문 제목·위 사진 아래 글·큰 날짜 숫자).
- **갤러리 '넘기는 콜라주'**(`layoutType:'pages'`, `galleryHtml`): 사진 6장씩 두 줄 높낮이 콜라주(`.ib-gp-page` > `.ib-gp-col`, 높이 비율 왼쪽 4·3·5 / 오른쪽 5·4·3) 한 장씩 옆으로 넘김, 다음 장이 오른쪽에 살짝 보임, 진행바는 슬라이드와 같은 `data-ib-slide`.
- **인트로 '메인 화면 위'**(`intro.bgType:'clear'`, 어둡게 `intro.clearDim` 0~70%, 글자 기본 흰색): 배경 없이 청첩장 메인 사진·영상 위에 글자만 나타났다 사라짐 (`.intro-overlay.intro-clear`). 에디터 인트로 미리보기는 메인 사진(영상이면 썸네일)을 깔아서 보여줌.
- **인트로 글자 효과**(`intro.anim`, 기본 'type'): 타자 · 천천히 나타나기 · 한 글자씩 떠오르기 · 흐릿하게 선명해지기 · 크게서 작게 · 톡톡 튀기 · 한 줄씩 올라오기 · 손글씨처럼 그려지기 - `InviteBlocks.RichText.animate(el, html, anim, 초)` (글자마다 `.ibx-ch` --i/--l, 낱말 `.ibx-w`, CSS `.ibx-a-*` in invite-blocks.css). '손글씨처럼'(draw)은 글자마다 SVG `<text>` 윤곽선(`.ibx-stroke`, stroke-dashoffset)을 0.6~1.8초에 걸쳐 그린 뒤 속(`.ibx-fill`)을 채우고 선은 옅어짐, 앞 글자부터 `--ibx-dstep` 간격으로 이어짐. 영문 낱말은 통째로 한 칸(`.ibx-word`, 필기체 이음 유지 + 왼쪽부터 쓸듯이 `ibxSweep`). 기준선 위치는 글꼴을 다 받은 뒤 다시 잼(`document.fonts` ready·loadingdone → `place`). 에디터 인트로 설정(기본 탭 '글자 효과')·간편 만들기 인트로 칸(관리자 옵션 `finish.introFx`)·공개 페이지 같이 씀. 재생 길이 최대 4초.
- **참석 여부 팝업 시점**(`rsvp.popupAt`, 기본 'scroll'): 맨 위가 메인 사진·영상이면 하객이 메인 화면을 절반 넘게 지나 내려갈 때 뜸(`afterHeroScroll`), 'open'이면 예전처럼 열자마자.
- **캘린더에 저장**(`addToCalendar`): 안드로이드 크롬·삼성 인터넷은 보안 정책상 웹페이지에서 캘린더 앱 '새 일정' 화면(intent INSERT)을 못 엶 (앱 화면이 BROWSABLE이 아니라 늘 fallback으로 넘어감 - 2026-10-05 시도했다가 되돌림). 그래서 안드로이드 = [삼성 캘린더 · 휴대폰 캘린더] .ics 파일(받은 뒤 [열기] 안내 토스트) / [구글 캘린더] 웹 저장(같은 계정 앱에 바로 보임). 아이폰은 .ics → 아이폰 캘린더 '추가' 화면(카카오톡 안이면 기본 브라우저로). `calendar.php?json=1`의 startMs·endMs·details는 지금 안 씀.
- 관리자 손쉬운 제작 탭 휴대폰(640px 이하): 칸 1줄 = 번호·이름·설명, 2줄 = ▲▼·켜짐·스타터·옵션.
- 예식 후 감사 인사 카드형·편지지·`.ib-thanks`는 바탕이 흰색이라 글자는 늘 어둡게(`color-mix(디자인 글자색 22%, #2B2320)`, 서명 #8A7F72) - 어두운 디자인에서 안 보였음.
- **글 줄바꿈** (invite-blocks.css 맨 아래, 청첩장 전체 `.col[data-block-id]`): `word-break: keep-all`(낱말 가운데서 안 끊음) + 문단 `text-wrap: pretty`(마지막 줄에 한두 글자만 남지 않게) + 제목 `balance`. 자유 배치 글자 칸(`.drag-part`, `.ib-title-layer`)은 `fitTextLayers`(invite-blocks.js, MutationObserver·ResizeObserver·글꼴 받은 뒤 저절로): 한 줄(직접 줄바꿈한 그대로)로 들어가면 칸을 글자만큼 넓혀 한 줄로(왼·오른쪽 정렬 칸은 그쪽 끝 고정, 화면 3% 안쪽으로), 화면 92%를 넘으면 글자를 72%까지 줄여 한 줄, 그래도 길면 원래대로. 계좌·세로·곡선·편집 중 칸은 제외.
- 계좌 한 줄 예금주는 괄호 대신 옅은 글씨 `InviteBlocks.accHtml` → `.ib-acc-hd` (에디터 자유 배치·공개 자유 배치·카드형·받아온 뒤 채우기 모두). 저장값은 그대로 "은행 번호 (이름)".
- 정리 화면 '거의 다 됐어요' 아래 **기능 더 넣기** 칸(`spAddMoreHtml`): 아직 안 넣은 섹션 칩(최대 8개, `spAddable`) → 누르면 그 섹션을 켜고 목록 화면에서 그 칸을 펼침(`spAddGo`) + [모든 칸 보며 더 꾸미기](예전 '하나씩 더 꾸미기' 글자 링크).
- 안내 말씀 모양: 카드·박스·**슬라이드**·**탭** + 예시(포토부스·주차·답례품·식사·화환·셔틀) `NOTICE_TPL`.
- **사이트 색상**: 화면 CSS의 베이지는 `var(--ui-page|tint|soft|line, #원래색)`, 켜짐 스위치 금색은 `--ui-point`, 간편 만들기 [미리보기] 버튼은 `--ui-pointsoft`(글자 `--ui-pointink`, 자동 계산). 관리자 색 칸은 늘 보이고 하나라도 바꾸면 '직접 고르기'. 새 베이지를 쓰면 이 변수로 감쌀 것 (청첩장 디자인 미리보기 안의 색·노란 알림·NEW 꼬리표 같은 일부러 넣은 색은 제외). 관리자 → 사이트 정보 → 사이트 색상(프리셋/직접) → `app_settings.ui_colors` → `site_colors.php`(`:root` 값). 새 화면을 만들면 색을 이 4개 변수로 쓰고 `<?= site_colors_link() ?>`를 `</head>` 앞에. `assets/admin.css`는 저장소에 없어서 아직 안 바꿈.
- 메인 문구 칸 편집(A안): 글 칸 오른쪽 [효과 ▾] → 작은 창(`hlPopHtml`, 간편=효과만 / 전문가=효과·방향·휘기), 아래 "모두 같게" 한 줄. 고른 줄만 재생 `playHeroAnimsTwice(root, key)`. 곡선 글씨는 `fitHeroArcs`가 곡선 길이에 맞춰 글자 크기를 줄임.
- 글자 끌기 범위 `dragRange`: 보통 8~92%, 레이아웃에 `edge:true`(이름 양쪽·세로 글씨)면 1~99%.
- 유튜브 히어로도 사진 칸 레이아웃 가능: `heroVideoBox`가 영상을 `.hero-box > .hero-vbox` 안에 넣고 `fitVideoCover`는 칸 크기로 맞춤 (에디터·render-invite 둘 다).
- 공용 섹션 편집창 목록(`type:'items'`)도 `showIf` 적용, `compact:true`면 한 줄짜리 목록(엔딩 크레딧 줄).
- zip으로 줄 때 루트 `invite/render-invite.js`는 빼고 `invite/assets/render-invite.js`만 (서버는 assets만 씀).

### 기타
- 공유하기 섹션은 항상 맨 아래(기타 묶음 끝), 새 공유하기는 "섹션 자리 공유버튼" 기본 OFF.
- 디데이: 달력 보여주기 / 카운트 보여주기(`showCounter`) 따로, 편집창에서 짝지어 배치.
- 관리자 섹션 이름 변경은 즉시 저장.
- 손쉬운 제작 탭: 끌어서 순서(Sortable, forceFallback), 꺼진 단계 칸, 단계별 옵션 폴더.

## 5. 남은 일 / 확인 필요

- 에디터 화면 덮개(`ld-boot`): 불러오는 중 오류가 나면 0.3초 뒤, 아니어도 12초 뒤 맨 위 인라인 스크립트가 걷음 (예전엔 맨 아래 코드까지 못 가면 영영 하얀 화면). Sortable(끌기 부품)은 `typeof Sortable` 확인 후에만 씀 - CDN을 못 받아도 에디터는 뜸.

- 사용자 보고 "이지모드 계좌 입력 안 됨"은 위 수정으로 해결된 것으로 보이나 실서버 확인 필요.
- 유튜브 보일 부분 창은 테스트 환경에서 썸네일이 막혀 실제 확인 못 함.
- 실제 음악 재생·비트 웨이브, 실제 날씨·지오코딩은 테스트 환경에서 확인 못 함 (목업).
- 간편 만들기 → 전문가 모드: 상태를 그대로 씀 (이름·글·켠 섹션·순서·메인 레이아웃·색·글꼴·종이·계좌·음악 확인함 2026-10-05). S10 그룹 섹션이면 `bsS10Adopt`가 순서는 그대로 두고 순서가 안 맞는 섹션만 앞 섹션의 묶음으로 옮김(`b.grp`, 가장 긴 오름차순 줄은 그대로) → 묶음 정렬로 순서가 섞이지 않음. 단 '전에 꾸민 모습으로' 열면 전문가 모드 때 순서.

## 6. 테스트 환경 만들기 (참고)

- PHP 8.x 내장 서버 + MariaDB. `invite/testbed_setup.sql`, `snap_setup.sql`, `stage4_setup.sql`, `stage5_setup.sql`로 테이블 생성.
- `config.php`는 테스트용으로 따로 만든다 (실서버 값 사용 금지). 테스트 전용 define: `GDRIVE_*_BASE`, `WEATHER_API_BASE`, `TRIP_GEOCODE_BASE`, `NAVER_COMMERCE_API_BASE`로 외부 API를 목업 서버로 돌릴 수 있음.
- 에디터 확인은 Playwright(Chromium)로 `editor-prototype-v3-overlay.html?t=<편집토큰>` 열어서 스크린샷. 외부 CDN(Sortable 등)은 로컬 파일로 라우팅.
- 저장소에 기본 테이블(invitation_orders 등) 만드는 SQL이 없음 → 코드 보고 추정해서 만듦. `client_ip()`는 실서버 config.php에 있음(테스트 config에도 넣을 것). `admin_login_attempts`(ip PK, attempts, locked_until, updated_at).
- 테스트 환경은 바깥 주소가 막혀서 Sortable·구글 글꼴은 npm(`sortablejs`, `@fontsource/*`)에서 받아 로컬로 라우팅.
- 디자인 JSON을 DB에서 다룰 때 `mysql -N` 출력으로 JSON을 옮기지 말 것 (이스케이프 깨짐) – PDO로 읽고 쓰기.
