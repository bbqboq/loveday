#!/usr/bin/env bash
# =============================================================================
# backup.sh - 서버 매일 백업 (DB 전부 + /var/www 전부 + 서버 설정), 14일치 보관
#
#  설치 (root, 한 번):
#    sudo install -m 700 backup.sh /root/backup.sh
#    sudo bash /root/backup.sh            # 첫 실행 = 시험 (끝에 '완료'가 나와야 함)
#    sudo crontab -e  →  맨 아래에 한 줄:
#      30 3 * * * bash /root/backup.sh >> /var/log/site_backup.log 2>&1
#
#  만들어지는 것 (/var/backups/site/):
#    db/2026-11-07/<DB이름>.sql.gz     DB마다 한 파일 (사이트 하나만 되살리기 쉽게) + _grants.sql.gz(계정·권한)
#    www/2026-11-07/                   /var/www 통째로 - 어제와 같은 파일은 하드링크라 바뀐 만큼만 자리를 씀
#    etc/2026-11-07.tar.gz             nginx · php · letsencrypt · crontab 설정
#
#  되살리기:
#    DB 하나:   zcat /var/backups/site/db/2026-11-07/<DB이름>.sql.gz | mysql
#               (파일 안에 CREATE DATABASE · USE가 들어 있어 원래 이름의 DB로 되살아남 - 덮어쓰기 전에 지금 것도 한 번 받아 둘 것.
#                DB 이름은 ls /var/backups/site/db/2026-11-07/ 로 확인)
#    파일 하나: cp -a /var/backups/site/www/2026-11-07/loveday/www/invite/uploads/12/ /var/www/loveday/www/invite/uploads/
#    사이트 통째: rsync -a --delete /var/backups/site/www/2026-11-07/loveday/ /var/www/loveday/
#
#  주의: 이 백업은 같은 서버 안에 있음 → 디스크 고장·서버 삭제 때는 같이 사라짐.
#        중요할 때(큰 작업 전 등) /var/backups/site/db/ 최신 폴더를 내 컴퓨터로 내려받아 두기.
# =============================================================================
set -uo pipefail

BK=${BK:-/var/backups/site}      # 백업 놓는 곳
WWW=${WWW:-/var/www}             # 웹 폴더 전체
DAYS=${DAYS:-14}                 # 보관 일수
MIN_FREE_GB=${MIN_FREE_GB:-3}    # 디스크 남은 공간이 이보다 적으면 파일 백업은 건너뜀 (DB는 함)
TODAY=$(date +%F)
LOG() { echo "$(date '+%F %T') $*"; }
FAIL=0

umask 077
mkdir -p "$BK/db/$TODAY" "$BK/www" "$BK/etc" || { LOG "백업 폴더를 못 만듦: $BK"; exit 1; }

# 같은 시각에 두 번 돌지 않게
exec 9>"$BK/.lock"
flock -n 9 || { LOG "이미 실행 중 - 건너뜀"; exit 0; }

LOG "백업 시작 → $BK"

# ---------- 1. DB (DB마다 한 파일) ----------
# 우분투 MariaDB root는 비밀번호 없이(unix_socket) 접속됨. 안 되면 /root/.my.cnf 에 [client] user=root password=… 를 넣을 것
DBS=$(mysql -N -e "SHOW DATABASES" 2>/dev/null | grep -Ev '^(information_schema|performance_schema|sys)$')
if [ -z "$DBS" ]; then
  LOG "❌ DB 목록을 못 읽음 (mysql 접속 실패)"; FAIL=1
else
  for db in $DBS; do
    out="$BK/db/$TODAY/$db.sql.gz"
    if mysqldump --single-transaction --quick --routines --events --triggers --hex-blob \
         --default-character-set=utf8mb4 --databases "$db" 2>"$out.err" | gzip -6 > "$out.tmp" \
       && [ ! -s "$out.err" ] && [ -s "$out.tmp" ]; then
      mv "$out.tmp" "$out"; rm -f "$out.err"
      LOG "DB $db  $(du -h "$out" | cut -f1)"
    else
      mv "$out.tmp" "$out" 2>/dev/null; LOG "❌ DB $db 백업 오류: $(head -c 300 "$out.err" 2>/dev/null)"; FAIL=1
    fi
  done
  # 계정·권한 (mysql DB를 통째로 되살리지 않고 권한만 다시 줄 때)
  mysql -N -e "SELECT CONCAT('SHOW CREATE USER ''',user,'''@''',host,''';SHOW GRANTS FOR ''',user,'''@''',host,''';') FROM mysql.user WHERE user<>''" 2>/dev/null \
    | mysql -N 2>/dev/null | sed 's/$/;/' | gzip > "$BK/db/$TODAY/_grants.sql.gz" || true
fi

# ---------- 2. 웹 폴더 (하드링크 스냅샷) ----------
FREE_GB=$(df -BG --output=avail "$BK" | tail -1 | tr -dc '0-9')
if [ "${FREE_GB:-0}" -lt "$MIN_FREE_GB" ]; then
  LOG "⚠️ 디스크 남은 공간 ${FREE_GB}GB - 파일 백업 건너뜀"; FAIL=1
else
  PREV=$(ls -1d "$BK"/www/20??-??-?? 2>/dev/null | grep -v "/$TODAY\$" | tail -1)
  LINK=(); [ -n "$PREV" ] && LINK=(--link-dest="$PREV")
  if rsync -a --delete "${LINK[@]}" \
       --exclude='*/data/cache/***' --exclude='*/data/session/***' --exclude='*/data/tmp/***' \
       --exclude='*.log' --exclude='*/node_modules/***' --exclude='*/.git/***' \
       "$WWW/" "$BK/www/$TODAY/"; then
    LOG "웹 폴더 → www/$TODAY (어제와 같은 파일은 공유)"
  else
    LOG "❌ 웹 폴더 백업 오류 (rsync 종료 코드 $?)"; FAIL=1
  fi
fi

# ---------- 3. 서버 설정 ----------
crontab -l > "$BK/etc/crontab-root.txt" 2>/dev/null || true
tar -czf "$BK/etc/$TODAY.tar.gz" --ignore-failed-read \
    /etc/nginx /etc/php /etc/letsencrypt /etc/mysql "$BK/etc/crontab-root.txt" 2>/dev/null \
  && LOG "서버 설정 → etc/$TODAY.tar.gz" || { LOG "❌ 서버 설정 백업 오류"; FAIL=1; }

# ---------- 4. 오래된 것 지우기 (DAYS일 넘은 날짜 폴더만) ----------
CUT=$(date -d "-$DAYS days" +%F)
for d in "$BK"/db/20??-??-?? "$BK"/www/20??-??-??; do
  [ -d "$d" ] || continue
  n=$(basename "$d"); [[ "$n" < "$CUT" ]] && rm -rf -- "$d" && LOG "지움 $d"
done
for f in "$BK"/etc/20??-??-??.tar.gz; do
  [ -f "$f" ] || continue
  n=$(basename "$f" .tar.gz); [[ "$n" < "$CUT" ]] && rm -f -- "$f"
done

LOG "백업 폴더 전체 $(du -sh "$BK" 2>/dev/null | cut -f1) · 디스크 남음 $(df -h --output=avail "$BK" | tail -1 | tr -d ' ')"
if [ "$FAIL" = 0 ]; then LOG "완료"; else LOG "끝 (오류 있음 - 위 ❌ 줄 확인)"; fi
exit $FAIL
