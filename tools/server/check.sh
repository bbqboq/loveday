#!/bin/bash
# LOVE DAY 서버 점검 (읽기만 함 - 아무것도 바꾸지 않음)
#   sudo bash check.sh > check.txt   → check.txt 내용을 Claude에게 보내 주세요 (비밀번호·키는 안 나옴)
echo "== 기본";  uname -a; nproc; free -m; df -h / | tail -1; uptime
echo "== 웹서버"; for s in nginx apache2 httpd litespeed lsws; do systemctl is-active $s 2>/dev/null | sed "s/^/$s: /"; done
ss -ltnp 2>/dev/null | grep -E ':(80|443) ' | sed 's/users:.*"\(.*\)".*/\1/' | head
echo "== PHP"; php -v | head -1; ls /etc/php/ 2>/dev/null
for d in /etc/php/*/fpm; do [ -d "$d" ] || continue; echo "-- $d"; grep -hE '^\s*(pm|pm\.[a-z_]+|request_terminate_timeout)\s*=' $d/pool.d/*.conf
  grep -hE '^\s*(opcache\.(enable|memory_consumption|max_accelerated_files|validate_timestamps|revalidate_freq)|memory_limit|upload_max_filesize|post_max_size|session\.gc_maxlifetime|session\.save_path)\s*=' $d/php.ini $d/conf.d/*.ini 2>/dev/null; done
systemctl list-units --type=service 2>/dev/null | grep -E 'php.*fpm' | head
echo "== PHP 모듈"; php -m 2>/dev/null | grep -iE 'opcache|gd|apcu|redis|imagick' | tr '\n' ' '; echo
echo "== GD webp"; php -r 'echo function_exists("imagewebp") ? "있음\n" : "없음\n";'
echo "== MariaDB/MySQL"; mysql -V 2>/dev/null; (mysql -NBe "SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','max_connections','performance_schema','key_buffer_size','tmp_table_size')" 2>/dev/null || echo "(root로 접속 안 됨 - 건너뜀)")
echo "== 메모리 많이 쓰는 것"; ps -eo rss,comm --sort=-rss | head -12 | awk '{printf "%6.0fMB %s\n",$1/1024,$2}'
echo "== 스왑"; swapon --show 2>/dev/null || echo 없음
echo "== nginx gzip·캐시"; [ -d /etc/nginx ] && grep -rhE '^\s*(gzip|expires|add_header Cache-Control|client_max_body_size)' /etc/nginx/ 2>/dev/null | sort | uniq | head -20
echo "== apache 모듈"; command -v apache2ctl >/dev/null && apache2ctl -M 2>/dev/null | grep -E 'expires|headers|deflate|brotli|rewrite|mpm|php' | tr '\n' ' '; echo
echo "== 크론"; crontab -l 2>/dev/null | grep -v '^#' | head
echo "== 업로드 폴더 크기"; for p in /var/www/*/www/invite/uploads /var/www/*/invite/uploads /var/www/html/invite/uploads; do [ -d "$p" ] && du -sh "$p" && ls "$p" | wc -l; done 2>/dev/null
