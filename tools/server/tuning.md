# LOVE DAY 서버 설정 권장값 (Vultr 1 vCPU · 2GB · NVMe, Ubuntu 24.04)

check.sh 결과를 보고 다른 값만 바꿀 것. 바꾼 뒤엔 해당 서비스 재시작.

## PHP-FPM (`/etc/php/8.x/fpm/pool.d/www.conf`)
```
pm = dynamic
pm.max_children = 16        ; 작업자 하나 약 40~60MB → 16개면 약 0.8GB
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500       ; 메모리 새는 것 방지
request_terminate_timeout = 60
```

## OPcache (`/etc/php/8.x/fpm/conf.d/10-opcache.ini`)
```
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

## PHP 공통 (`/etc/php/8.x/fpm/php.ini`)
```
memory_limit = 256M          ; GD로 1920px 사진 처리에 충분
upload_max_filesize = 16M
post_max_size = 20M
session.gc_maxlifetime = 43200
```

## MariaDB (`/etc/mysql/mariadb.conf.d/60-loveday.cnf`)
```
[mysqld]
innodb_buffer_pool_size = 384M
max_connections = 60
performance_schema = OFF     ; 메모리 약 200MB 아낌
tmp_table_size = 32M
max_heap_table_size = 32M
```

## 스왑 2GB (메모리가 갑자기 몰릴 때 서버가 죽지 않게)
```
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swap.conf && sudo sysctl -p /etc/sysctl.d/99-swap.conf
```

## 정적 파일 캐시·압축
- nginx: `nginx-cache.conf`
- Apache: `htaccess-cache.txt`

## 사진 사본 한 번에 채우기 (1019z11 이후 한 번, 한가한 시간에)
```
php /var/www/…/invite/photo_thumbs.php all
```
(안 해도 1시간 크론(expire_cleanup.php)이 한 번에 300장씩 채움)

## 실서버 점검 결과 (artcenter, 2026-11-05 check.sh)

- nginx + php8.5-fpm (8.3 fpm은 꺼져 있음) · MariaDB 10.11 · 웹 폴더 `/var/www/loveday/www/invite`
- **php 8.5 `upload_max_filesize = 2M`** → 배경음악(줄여도 약 3MB)·큰 사진 원본이 막힘 → 20M / post_max_size 24M / nginx `client_max_body_size 24M`
- `session.gc_maxlifetime = 1440` → Ubuntu sessionclean 크론은 php.ini 값만 봐서 `.user.ini` 43200이 안 먹음 → fpm php.ini 43200
- `pm.max_children 5` (프로세스 약 54MB) → 10
- 크론이 root로 돌아 expire_cleanup이 만든 사진 사본이 root 소유 → 1019z13 `photo_own`이 원본 주인으로 바꿈 + 크론을 `sudo -u www-data`로
- 스왑 1.8GB 사용(107일 켜 둠, 부하 0) → vm.swappiness 10

## 백업 · 크론 · 시험 도구 (2026-11-07)

- `backup.sh` → `/root/backup.sh` · root 크론 `30 3 * * *` · `/var/backups/site/{db,www,etc}/날짜` 14일 (DB마다 한 파일 · /var/www 하드링크 스냅샷 · nginx/php/letsencrypt/mysql 설정). Vultr 자동 백업은 안 씀(사용자 결정) → 서버 밖 사본 없음
- 크론: naver_order_sync · cron_cleanup도 `runuser -u www-data --` · cron_cleanup 기록은 `/var/log/loveday_cron.log` (예전 invite/cron.log는 웹에서 열릴 수 있었음)
- `loadtest.sh [테스트 청첩장 코드]` - ab로 동시 10·50·100
