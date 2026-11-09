# Hướng Dẫn Triển Khai: Docker + FrankenPHP + Laravel Octane + PostgreSQL

> **Tài liệu tham chiếu chuẩn cho AI Agent & DevOps**  
> Dựa trên nhánh: `feat/docker-frankenphp-octane-postgres`  
> Khi AI hoặc Kỹ sư được yêu cầu *"triển khai dự án TMS-ASGT theo chuẩn Docker FrankenPHP Octane Postgres"*, hãy đọc và tuân thủ chính xác toàn bộ tài liệu này.

---

## 1. Tổng Quan Kiến Trúc (Architecture Overview)

Dự án bao gồm 5 container được kết nối thông qua Docker bridge network `tms-net`:

| Container | Image / Base | Vai trò | Cổng / Lệnh chạy |
| :--- | :--- | :--- | :--- |
| **`tms_app`** | `dunglas/frankenphp` (PHP 8.5/8.4) | Web Server (Caddy engine) + Laravel Octane Worker | `80:80`, `443:443`, `443:443/udp` (HTTP/3) |
| **`tms_postgres`** | `postgres:16-alpine` | Cơ sở dữ liệu chính (PostgreSQL 16) | `5432:5432` |
| **`tms_queue`** | `tms-asgt-app:latest` | Xử lý hàng đợi nền (Queue Worker) | `php artisan queue:work --tries=3 --timeout=90` |
| **`tms_scheduler`** | `tms-asgt-app:latest` | Tác vụ định kỳ (Cron: GPS sync, ca lái xe) | `php artisan schedule:work` |
| **`tms_reverb`** | `tms-asgt-app:latest` | WebSocket Server thời gian thực | `php artisan reverb:start --host=0.0.0.0 --port=8085` |

---

## 2. Các File Cấu Hình Trọng Tâm

### 2.1. `Dockerfile` (Multi-stage Build)
- **Base image:** `dunglas/frankenphp:latest-php8.5-alpine` (hoặc `8.4`).
- **PHP Extensions:** `pdo_pgsql`, `pgsql`, `pcntl`, `redis`, `intl`, `gd`, `zip`, `bcmath`, `opcache`.
- **Octane Worker Script:** `public/frankenphp-worker.php`.
- **Caddyfile:** Copy cấu hình từ `.docker/frankenphp/Caddyfile` vào `/etc/caddy/Caddyfile`.

### 2.2. `.docker/frankenphp/Caddyfile`
Cấu hình Caddy phục vụ cả HTTP, WebSocket và Static SPA:
```caddy
{
	{$CADDY_GLOBAL_OPTIONS}

	admin {$CADDY_SERVER_ADMIN_HOST:localhost}:{$CADDY_SERVER_ADMIN_PORT:2019}

	frankenphp {
		worker {
			file "{$APP_PUBLIC_PATH:/var/www/html/public}/frankenphp-worker.php"
			{$CADDY_SERVER_WORKER_DIRECTIVE}
			{$CADDY_SERVER_WATCH_DIRECTIVES}
		}
	}
}

{$CADDY_EXTRA_CONFIG}

{$CADDY_SERVER_SERVER_NAME::80} {
	log {
		level {$CADDY_SERVER_LOG_LEVEL:INFO}
	}

	# Giới hạn dung lượng request body (đồng bộ với PHP upload_max_filesize 100M)
	request_body {
		max_size 100MB
	}

	route {
		# 1. Reverb WebSocket Proxy (QUAN TRỌNG: Phải match header Upgrade để tránh xung đột với Filament /app)
		@reverb_ws {
			header Connection *Upgrade*
			header Upgrade websocket
			path /app/*
		}
		reverse_proxy @reverb_ws reverb:8085

		# 2. Reverb REST API Proxy
		reverse_proxy /apps/* reverb:8085

		# 3. Mobile Web SPA
		handle_path /mobile* {
			root * "{$APP_PUBLIC_PATH:/var/www/html/public}/mobile"
			try_files {path} {path}/ /index.html
			file_server
		}

		# 4. Main Laravel Application
		root * "{$APP_PUBLIC_PATH:/var/www/html/public}"
		encode zstd br gzip

		php_server {
			index frankenphp-worker.php
			try_files {path} frankenphp-worker.php
			resolve_root_symlink
		}
	}
}
```

### 2.3. `docker-compose.yml`
Điểm mấu chốt trong volume mounts của `app`, `queue`, `scheduler`, `reverb`:
- Mount code: `.:/var/www/html`
- Mount Caddyfile: `./.docker/frankenphp/Caddyfile:/etc/caddy/Caddyfile:ro` (chỉ cần cho `app`)
- **Mount file môi trường:** `./.env.docker:/var/www/html/.env:ro` (Bắt buộc cho cả 4 services để Laravel không bị lẫn file `.env` local của máy host)
- Persistent volumes: `pgdata` (Postgres data), `app_storage` (Laravel storage), `caddy_data` (SSL certs), `caddy_config` (Caddy runtime config).

### 2.4. Biến Môi Trường (`.env.docker`)
```env
APP_NAME="TMS-ASGT"
APP_ENV=local
APP_KEY=base64:JsgIoaqcpkqlMJyy9gStwN3myarnVA4DtdH6DQ9Zs48=
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# Database: PostgreSQL in Docker
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=tms_asgt
DB_USERNAME=postgres
DB_PASSWORD=secret

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=public
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database

# Laravel Octane & FrankenPHP
OCTANE_SERVER=frankenphp
OCTANE_MAX_REQUESTS=500

# Laravel Reverb WebSocket
REVERB_APP_ID=672914
REVERB_APP_KEY=e8f9b2c3d4a5f6e7g8h9
REVERB_APP_SECRET=k1l2m3n4o5p6q7r8s9t0
REVERB_HOST=localhost
REVERB_PORT=80
REVERB_SCHEME=http

REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8085

VITE_REVERB_APP_KEY=e8f9b2c3d4a5f6e7g8h9
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=80
VITE_REVERB_SCHEME=http
```

### 2.5. Cấu Hình Tên Miền Thật & Tự Động Cấp SSL/TLS (Automatic HTTPS)

FrankenPHP sử dụng engine **Caddy v2**, có cơ chế **Automatic HTTPS** tự động đăng ký và gia hạn chứng chỉ SSL miễn phí (Let's Encrypt / ZeroSSL) mà không cần cài Certbot hay cấu hình Nginx.

#### A. Khi chạy trên môi trường Local:
- Mặc định: `CADDY_SERVER_SERVER_NAME=:80` (chạy HTTP tại cổng 80).

#### B. Khi đưa lên Production với Tên miền thật (ví dụ `tms.asgl.net.vn`):
1. **Trỏ bản ghi DNS:** Trỏ bản ghi `A` của tên miền về IP máy chủ VPS.
2. **Cập nhật `.env.docker` trên Production:**
   ```env
   # 1. Kích hoạt Caddy Automatic HTTPS cho tên miền
   CADDY_SERVER_SERVER_NAME=tms.asgl.net.vn

   # 2. Cập nhật URL ứng dụng chính
   APP_URL=https://tms.asgl.net.vn

   # 3. Cập nhật Reverb WebSocket chạy WSS qua cổng 443
   REVERB_HOST=tms.asgl.net.vn
   REVERB_PORT=443
   REVERB_SCHEME=https

   VITE_REVERB_HOST=tms.asgl.net.vn
   VITE_REVERB_PORT=443
   VITE_REVERB_SCHEME=https
   ```
3. **Cơ chế hoạt động tự động của Caddy:**
   - Caddy tự động mở cổng 80 để tiếp nhận HTTP và tự động chuyển hướng (Redirect 308) toàn bộ sang `https://tms.asgl.net.vn` cổng 443.
   - Caddy tự động xin cấp SSL và tự gia hạn trước khi hết hạn 30 ngày (chứng chỉ được lưu an toàn trong persistent volume `caddy_data:/data`).
   - Tự động kích hoạt **HTTP/2** và **HTTP/3 (QUIC)** qua cổng UDP `443:443/udp` đã mở sẵn trong `docker-compose.yml`.

---

### 2.6. Giới Hạn Dung Lượng File Gửi Lên (Upload File Size Limit: 100MB)

Trong hệ thống TMS, tài xế chụp ảnh mốc hành trình có độ phân giải cao và điều hành tải lên/xuất file Excel dữ liệu lớn. Hệ thống đã được cấu hình đồng bộ giới hạn **100MB** ở cả 2 tầng:

1. **Tầng Web Server (Caddy / FrankenPHP):**
   Được cấu hình trong `.docker/frankenphp/Caddyfile`:
   ```caddy
   request_body {
       max_size 100MB
   }
   ```
   Ngăn chặn request vượt quá dung lượng ngay từ cổng web server trước khi chuyển vào PHP (chống tấn công DDoS gửi payload khổng lồ).

2. **Tầng PHP Runtime (`.docker/php/php.ini`):**
   File `.docker/php/php.ini` được mount trực tiếp vào container (`/usr/local/etc/php/conf.d/custom.ini`):
   ```ini
   upload_max_filesize = 100M
   post_max_size = 100M
   memory_limit = 512M
   max_execution_time = 300
   ```
   - `upload_max_filesize = 100M`: Dung lượng tối đa cho 1 file upload.
   - `post_max_size = 100M`: Tổng dung lượng tối đa cho 1 form submit.
   - `memory_limit = 512M`: Đảm bảo đủ RAM khi xử lý import/export file Excel hàng chục ngàn dòng.

*Nếu muốn tăng/giảm giới hạn:* Chỉ cần sửa giá trị trong `.docker/php/php.ini` và `.docker/frankenphp/Caddyfile`, sau đó restart container `app` (`docker compose restart app`) mà **không cần rebuild lại Docker image**.

---

## 3. Các Cạm Bẫy Kỹ Thuật Cần Tuyệt Đối Tránh (Critical Traps)

> [!CAUTION]
> **Trap 1: Xung Đột Đường Dẫn `/app/*` Giữa Filament Admin và Reverb WebSocket**  
> - Filament Admin sử dụng đường dẫn `/app` (như `/app/login`, `/app/orders`).  
> - Giao thức Pusher/Reverb mặc định cũng mở WebSocket tại `/app/{appKey}`.  
> - **Hậu quả nếu sai:** Nếu Caddy cấu hình `handle /app/* { reverse_proxy reverb:8085 }` hoặc named matcher không kiểm tra header `Upgrade: websocket`, mọi request mở trang web Filament sẽ bị chuyển sang Reverb và Caddy báo `500 Internal server error.`.  
> - **Giải pháp:** Bắt buộc phải có cả `header Connection *Upgrade*` và `header Upgrade websocket` trong named matcher `@reverb_ws`.

> [!WARNING]
> **Trap 2: Docker Compose Tự Thay Thế Biến Dạng `${VAR}` Bằng File `.env` Host**  
> - Nếu trong `.env.docker` viết `VITE_REVERB_PORT="${REVERB_PORT}"`, Docker Compose sẽ tự động lấy giá trị từ file `.env` trên máy host (ví dụ port 8080 của bản cũ) thay vì giá trị của `.env.docker`.  
> - **Giải pháp:** Luôn điền giá trị trực tiếp không dùng `${...}` trong `.env.docker`.

> [!WARNING]
> **Trap 3: Laravel Đọc Nhầm File `.env` Khi Mount Toàn Bộ Codebase**  
> - Khai báo `volumes: - .:/var/www/html` sẽ mang cả file `.env` của máy host vào container. Laravel Dotenv sẽ đọc file này đè lên `env_file`.  
> - **Giải pháp:** Phải mount đè file cấu hình: `- ./.env.docker:/var/www/html/.env:ro`.

> [!NOTE]
> **Trap 4: Toán Tử JSON PostgreSQL Trên Bảng `notifications`**  
> - Filament Notifications query theo cú pháp PostgreSQL: `"data"->>'format' = 'filament'`.  
> - Cột `data` trong bảng `notifications` bắt buộc phải là kiểu `$table->json('data')`, không được dùng `$table->text('data')`.

> [!NOTE]
> **Trap 5: Tránh Git Ignore Nhầm Thư Mục `.docker/frankenphp`**  
> - Trong `.gitignore`, nếu viết bare `frankenphp` thì git sẽ ignore toàn bộ thư mục `.docker/frankenphp/`.  
> - **Giải pháp:** Trong `.gitignore` phải viết có dấu slash ở đầu: `/frankenphp` và `/frankenphp-worker.php`.

---

## 4. Runbook: Các Bước Triển Khai Cho AI Agent / DevOps

Khi bắt đầu setup trên một môi trường mới, thực hiện tuần tự các bước sau:

```bash
# 1. Tạo file cấu hình môi trường từ mẫu
cp .env.docker.example .env.docker

# 2. Khởi tạo và build toàn bộ cụm container
docker compose up -d --build

# 3. Tạo APP_KEY nếu chưa có
docker compose exec app php artisan key:generate --force

# 4. Chạy toàn bộ migrations trên PostgreSQL
docker compose exec app php artisan migrate --force

# 5. Tạo symlink cho storage (public uploads, avatars, photos)
docker compose exec app php artisan storage:link

# 6. Clear cache để nạp cấu hình mới nhất
docker compose exec app php artisan config:clear
docker compose exec app php artisan cache:clear

# 7. Kiểm tra trạng thái hoạt động (Healthcheck)
curl -I http://localhost/up                     # 200 OK
curl -I http://localhost/app/login              # 200 OK
curl -I http://localhost/api/driver/version     # 200 OK
```

---

## 5. Cheatsheet Vận Hành & Khắc Phục Lỗi (Operations Cheatsheet)

### Kiểm tra logs thời gian thực
```bash
# Xem log toàn bộ services
docker compose logs -f --tail 50

# Xem log riêng Web Server / Octane
docker compose logs -f app

# Xem log Reverb WebSocket
docker compose logs -f reverb
```

### Reload Caddy khi thay đổi cấu hình Caddyfile
```bash
docker compose exec app frankenphp reload --config /etc/caddy/Caddyfile
```

### Truy cập PostgreSQL CLI trực tiếp trong container
```bash
docker compose exec postgres psql -U postgres -d tms_asgt
```

### Sao Lưu & Phục Hồi Cơ Sở Dữ Liệu (Backup & Restore)

Hệ thống đã tích hợp sẵn cơ chế sao lưu tự động qua Laravel Scheduler trong container `tms_scheduler`:
- **Lịch chạy tự động:** Hàng ngày lúc `01:00 AM` (`Schedule::command('db:backup --keep=3')`).
- **Thư mục lưu trữ:** `storage/app/backups/tms_asgt_backup_YYYYMMDD_HHmmss.sql.gz` (trong persistent volume `app_storage`).
- **Cơ chế Retention:** Mặc định tự động dọn dẹp các bản backup cũ, chỉ giữ lại 3 bản gần nhất.

```bash
# 1. Chạy sao lưu thủ công ngay lập tức (giữ 3 bản mới nhất)
docker compose exec app php artisan db:backup

# 2. Tuỳ chỉnh số lượng bản sao lưu giữ lại (ví dụ giữ 7 bản)
docker compose exec app php artisan db:backup --keep=7

# 3. Phục hồi dữ liệu từ file sao lưu nén (.sql.gz)
gunzip -c storage/app/backups/tms_asgt_backup_20261109_150844.sql.gz | docker compose exec -T postgres psql -U postgres -d tms_asgt

# 4. Sao lưu trực tiếp bằng psql / pg_dump ra ngoài máy host
docker compose exec postgres pg_dump -U postgres tms_asgt | gzip > backup_manual.sql.gz
```

### Khởi động lại toàn bộ stack sạch sẽ
```bash
docker compose down && docker compose up -d
```
