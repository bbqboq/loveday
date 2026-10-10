# 디자인 프리셋 폴더

폴더 하나 = 프리셋 하나. 이 폴더(`/invite/presets/`)에 새 폴더를 올리면 에디터의 디자인 선택 화면에 자동으로 추가됩니다.
확인: 브라우저에서 `https://loveday.kr/invite/preset_list.php`를 열면 읽힌 목록(`presets`)과 건너뛴 폴더·이유(`skipped`)가 보입니다.

```
17-mydesign/
  preset.json   필수
  thumb.jpg     선택 (선택 화면 카드 이미지, 없으면 색으로 미니 미리보기를 자동 생성)
  assets/       선택 (스티커 이미지 - preset.json에서 "assets/파일명.png"로 참조)
```

## preset.json 항목

| 항목 | 필수 | 설명 |
|---|---|---|
| id | ✔ | 영문 소문자·숫자·하이픈 (다른 프리셋과 겹치면 안 됨) |
| no | | 정렬 순서 숫자 |
| group | | `mood`(색·분위기) / `layout`(구성) |
| label, desc, tags | label ✔ | 이름, 설명, 태그(최대 4개, "BETA"는 베타 표시) |
| palette | ✔ | bg, ink, accent, line, muted (#RRGGBB) + 선택: radius, headFont, bodyFont, headWeight |
| font | | pretendard, noto-serif-kr, gowun-batang, nanum-myeongjo, gothic-a1, song-myung, nanum-pen, nanum-brush, gaegu, hi-melody, gamja-flower |
| hero, heroFrame, heroHeight | | hero: video/photo/text · heroFrame: none/rounded/circle/pill/arch · heroHeight(영상): 3/4, 4/5, 9/16, full |
| sections | ✔ | 켤 섹션 순서. heroVideo, hero, greeting, location, gallery, account, dday, timeline, interview, family, profile, contact, letter, video, transport, notice, together, ending, guestsnap, rsvp(참석 의사 전달), guestbook(방명록), dayinfo(D-DAY 하객 안내), share (공유하기 - 안 적어도 자동으로 맨 뒤에 켜짐. 적으면 그 자리에) |
| fx | | 섹션 등장 효과 `{"default":"up","gallery":"pop"}` - none/up/zoom/pop/bounce/left/right/fade |
| ambient | | none, hearts, petals, sparkles, cherry, leaves, autumn, snow, snowflake, rain(빗방울), sunset, fireworks, popper, meteor(별똥별), weather(날씨 따라 - 식장 날씨로 비·눈·햇살 + 첫 화면 색감, 미리보기는 비→눈→맑음 되풀이), confetti, sunshine, stars, daisy, bokeh(빛망울), flash |
| ambientScope, ambientOpacity | | 장식 효과를 첫 화면에만(`"hero"`) · 진하기 10~100 |
| heroLayout | | 첫 화면 레이아웃 id: our-wedding-day, getting-married, names-vertical, polaroid, happily-ever-after, happy-wedding-day, save-the-date, arch, fade-date, magazine, minimal-line, arc-top, split-top, big-date, webtoon-cover, webtoon-bubble, angel-letter (관리자가 저장한 레이아웃 id도 됨). 없으면 간편 만들기는 매거진 표지 |
| stickerThemes | | 스티커 창 [추천]에 먼저 보일 테마 2개: romantic, cosmos, rain, garden, classic, party, season (없으면 에디터 STK_REC 기본값) |
| paper | | 종이 질감: beige, white, hanji, linen, kraft |
| hero.jpg | | 폴더에 hero.jpg/png/webp를 넣으면 그 디자인의 첫 화면 예시 사진 (없으면 관리자 손쉬운 제작의 메인 사진 예시) |
| sideBg, shadow | | PC 옆 배경색, PC 그림자(true/false) |
| intro | | enabled, text, font, bg, color, fontSize, duration |
| blockFields | | 섹션별 기본값 `{"gallery":{"layoutType":"slide","images":8}}` (문자·숫자·참거짓만). 섹션 모양: gallery.layoutType(grid·tall·collage·wide·circle·slide·pages) · dday.calendarStyle(classic·vintage·minimal·week·desk·night·heart·planner) · dday.counterStyle(classic·bigd·flip·ring·sentence·line·ticket·bubble) · dday.showCounter · account.accStyle(basic·line·outline·center·vintage) · contact.contactStyle · notice.style(card·box·slide·tabs) · guestbook.style(card·line) · interview.style(''·chat·card·mag·split) · thanks.style(card·letter·photo·plain) |
| sectionLook | | 전문가 모드 **섹션 스킨**에서 이 디자인을 고르면 섹션에 입히는 꾸밈: rule·bold·soft·mono·leaf·gold·kraft·lace·film·cinema·polaroid·note·typo·board·tape·deco·sky·cupid·stars·drops·angel (또는 @vintage·@night·@mist·@webtoon = 섹션 테마). 없으면 에디터 기본 목록(InviteBlocks.THEME_LOOKS) |
| stickers | | `[{"sectionId":"hero","relX":0.5,"relY":0.1,"size":40,"emoji":"💗","effect":"pop"}]` - emoji 대신 `"image":"assets/heart.png"` 가능, 스티커 창 그림은 `"image":"stk:테마/이름"` (svg, 빈티지 그림은 `stk:vintage/cupid-bow` 처럼 webp) |
| notes | | 개발 메모(화면엔 안 나옴) |

> 관리자 → 추천 디자인 → [에디터로 꾸미기]로 꾸며 저장한 샘플이 있으면, 고객에게는 이 폴더 값 대신 **꾸민 샘플 모습 그대로**(첫 화면 포함) 보입니다.
