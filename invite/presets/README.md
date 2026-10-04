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
| ambient | | none, hearts, petals, sparkles, cherry, leaves, autumn, snow, confetti, sunshine, stars, daisy |
| sideBg, shadow | | PC 옆 배경색, PC 그림자(true/false) |
| intro | | enabled, text, font, bg, color, fontSize, duration |
| blockFields | | 섹션별 기본값 `{"gallery":{"layoutType":"slide","images":8}}` (문자·숫자·참거짓만) |
| stickers | | `[{"sectionId":"hero","relX":0.5,"relY":0.1,"size":40,"emoji":"💗","effect":"pop"}]` - emoji 대신 `"image":"assets/heart.png"` 가능 |
| notes | | 개발 메모(화면엔 안 나옴) |
