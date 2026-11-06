#!/usr/bin/env bash
# =============================================================================
# loadtest.sh - 동시 접속 시험 (서버에서 직접, 하객이 적은 새벽에 1~2분)
#
#   sudo bash loadtest.sh                      # 홈 · 샘플 청첩장 · 그림 파일
#   sudo bash loadtest.sh 내테스트청첩장코드     # + 그 청첩장 하객 화면 (고객 청첩장 코드는 쓰지 말 것 - 방문 수가 올라감)
#
#  동시에 10명 · 50명 · 100명이 계속 여는 상황을 흉내 내서
#   '1초에 몇 번 처리' · '평균 몇 초' · '실패 몇 건'을 보여 줌. 끝나면 PHP가 줄을 세웠는지(max_children)도 봄.
#  결과를 통째로 붙여 주면 됨.
# =============================================================================
set -u
SITE=${SITE:-https://loveday.kr}
SLUG=${1:-}
UA='Mozilla/5.0 (Linux; Android 14; SM-S918N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36'

command -v ab >/dev/null || { echo "ab가 없어서 설치함 (apache2-utils - 시험 도구만, 웹서버 아님)"; apt-get install -y apache2-utils >/dev/null || exit 1; }

URLS=("$SITE/" "$SITE/invite/invite_view.php?sample=classic" "$SITE/invite/assets/invite-blocks.min.js")
NAMES=("홈페이지" "샘플 청첩장(하객 화면)" "그림·JS 파일(nginx)")
if [ -n "$SLUG" ]; then URLS+=("$SITE/$SLUG"); NAMES+=("내 청첩장 $SLUG"); fi

LOAD0=$(cut -d' ' -f1 /proc/loadavg)
printf '%-26s %6s %10s %10s %8s\n' "주소" "동시" "1초에 처리" "평균(ms)" "실패"
for i in "${!URLS[@]}"; do
  for c in 10 50 100; do
    n=$((c * 10))
    out=$(ab -q -n "$n" -c "$c" -s 20 -H "User-Agent: $UA" -H 'Accept-Encoding: gzip' "${URLS[$i]}" 2>&1)
    rps=$(echo "$out" | awk '/Requests per second/{print int($4)}')
    mean=$(echo "$out" | awk '/Time per request.*\(mean\)$/{print int($4)}')
    fail=$(echo "$out" | awk '/Failed requests/{print $3}')
    non2=$(echo "$out" | awk '/Non-2xx responses/{print $3}')
    printf '%-26s %6s %10s %10s %8s\n' "${NAMES[$i]}" "$c" "${rps:-?}" "${mean:-?}" "${fail:-?}${non2:+ (2xx 아님 $non2)}"
    sleep 2
  done
done
echo
echo "서버 부하(1분 평균): 시작 $LOAD0 → 끝 $(cut -d' ' -f1 /proc/loadavg)"
echo "메모리:"; free -m | head -2
echo "PHP가 줄을 세운 기록(최근):"
grep -h "max_children" /var/log/php8.5-fpm.log 2>/dev/null | tail -3 || true
echo "(아무것도 없으면 PHP 칸이 모자란 적 없음)"
echo "참고: 샘플 청첩장이 '2xx 아님'이면 관리자가 꾸민 classic 샘플이 없어 다른 화면으로 넘기는 것(302) - 실패 아님"
