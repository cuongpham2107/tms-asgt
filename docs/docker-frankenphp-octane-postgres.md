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

### Backup & Restore Database
```bash
# Backup
docker compose exec postgres pg_dump -U postgres tms_asgt > backup.sql

# Restore
cat backup.sql | docker compose exec -T postgres psql -U postgres -d tms_asgt
```

### Khởi động lại toàn bộ stack sạch sẽ
```bash
docker compose down && docker compose up -d
```
