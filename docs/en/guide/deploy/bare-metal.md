---
source: guide/deploy/bare-metal.md
sourceHash: a2bcaa122cb21e9be670ec64c9e0d56b1726dd7a06787f1830179e05c72d5e56
translatedAt: 2026-10-26
---

# Bare-Metal Source Deployment Guide

For servers that use neither BaoTa Panel nor Docker: the complete procedure for deploying TuraIDC from source on native system services (nginx + PHP-FPM + systemd + cron).

- Aligned on: `2026-08-28` (actually deployed and verified on Debian 13 (trixie))
- Applies to: intranet / self-managed servers, and environments where a panel and a container runtime cannot or will not be installed
- Companion documents:
  - [Deployment and Scheduling Guide](scheduling.md) (production operations baseline)
  - [BaoTa Panel Deployment Guide](bt-panel.md) (panel version, identical topology)
  - [Generic Deployment Guide](generic.md) (general reference for deploying from scratch)
  - [Docker and 1Panel Deployment Guide](docker-1panel.md) (containerized)

---

## 1. Topology and Its Mapping to the BaoTa Version

The runtime topology is exactly the same as [BaoTa deployment](bt-panel.md); only the management layer differs:

```
系统 nginx（sites-available 四站点）
  ├── 官网/用户入口 -> /opt/turaidc/frontend-user-v3-www/dist 静态资源
  ├── 用户控制台   -> /opt/turaidc/frontend-user-v4-console/dist 静态资源
  ├── 管理端       -> /opt/turaidc/frontend-admin-v3/dist 静态资源
  └── 后端 API     -> /opt/turaidc/backend/public (PHP-FPM)
                        ├── MySQL 8（本机）
                        ├── Redis（本机，仅 127.0.0.1）
                        ├── /etc/cron.d 每分钟 schedule:run
                        └── systemd 常驻 php artisan vnc:relay
```

| BaoTa carrier             | Bare-metal carrier                                             |
| ------------------------- | -------------------------------------------------------------- |
| BaoTa site (Nginx config) | `/etc/nginx/sites-available/*.conf` + `sites-enabled` symlinks |
| BaoTa scheduled task      | `/etc/cron.d/turaidc`                                          |
| BaoTa process daemon      | systemd service unit `turaidc-vnc-relay.service`               |
| BaoTa PHP settings        | `/etc/php/8.4/fpm/` (distribution defaults are sufficient)     |

## 2. Prerequisites

Tested baseline: Debian 13 (trixie), 2 cores / 2 GB RAM / 20 GB disk. Ubuntu 22.04+ and Debian 12 behave the same way, with essentially identical package names.

| Software              | Source                                     | Notes                                                                                                                                                                                                                                                                                                                                |
| --------------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| nginx                 | Distribution repository                    | 1.26+                                                                                                                                                                                                                                                                                                                                |
| PHP 8.4-FPM + CLI     | Distribution repository                    | Debian 13 ships 8.4, which satisfies `^8.3` in the backend `composer.json`; no third-party repository is needed. **A patched build containing the CVE-2026-109618 / CVE-2026-109619 fixes is required** (8.4 branch ≥ 8.4.27; Debian backports them, so `apt upgrade` is enough); after upgrading run `systemctl restart php8.4-fpm` |
| MySQL 8.4 LTS         | MySQL official APT repository              | The official repository already supports trixie; trixie has no official MySQL 8.0 series packages, so choose 8.4 LTS                                                                                                                                                                                                                 |
| Redis                 | Distribution repository                    | 8.x, listens on 127.0.0.1 only, no password by default is fine                                                                                                                                                                                                                                                                       |
| Composer              | Distribution repository `composer` package | 2.8+                                                                                                                                                                                                                                                                                                                                 |
| python3               | Ships with the system                      | Required by `install_db.py`                                                                                                                                                                                                                                                                                                          |
| Node.js 20.19+ / pnpm | **Build machine only**                     | See section 6: do not build the frontend in place on small-memory servers                                                                                                                                                                                                                                                            |

Memory sizing: 2 GB is enough to run (PHP-FPM can keep the Debian default small process pool); to build the frontend in place on the server you must add swap, and on LXC/VE-style containers `swapon` is usually blocked by the kernel (`Operation not permitted`), in which case build on the local machine and upload the dist instead.

### DNS Resolution

Pick one of the two options depending on the server type; all four public addresses (`api` / `www` / `console` / `admin`) must resolve to the deployment server:

**Intranet / no public network deployment (the default approach in this guide)**: add four entries to the hosts file on the client machine (`192.168.1.170 api.tura.cloud` and so on); when the server IP changes or a domain is retired, add or remove them the same way.

**Cloud server with a dedicated public IP**: configure resolution in the console of the DNS provider that hosts the domain, with four A records pointing at the server's public IP:

| Record type | Host record | Record value      |
| ----------- | ----------- | ----------------- |
| A           | api         | `<服务器公网 IP>` |
| A           | www         | `<服务器公网 IP>` |
| A           | console     | `<服务器公网 IP>` |
| A           | admin       | `<服务器公网 IP>` |

- When the server IP changes or an old domain is retired, add or remove records the same way, so that none are left pointing at dead addresses.
- For production cloud servers, use HTTPS consistently: once resolution takes effect, obtain certificates for the four subdomains (for example Let's Encrypt), switch the four public addresses to `https://`, and set `SESSION_SECURE_COOKIE=true`.
- After configuring, wait for the TTL to take effect and use `dig` / `ping` to confirm that all four subdomains point at the server.

## 3. Install System Dependencies

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y wget gnupg ca-certificates curl lsb-release
apt-get install -y nginx redis-server composer \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd php8.4-zip php8.4-intl \
  php8.4-bcmath php8.4-mbstring php8.4-curl php8.4-xml php8.4-redis

# MySQL 官方 APT 源（非交互模式使用默认选择：mysql-8.4-lts）
wget -q https://dev.mysql.com/get/mysql-apt-config_0.8.36-1_all.deb -O /tmp/mysql-apt-config.deb
apt-get install -y /tmp/mysql-apt-config.deb
apt-get update -y
apt-get install -y mysql-server
```

Notes:

- The debconf script of `mysql-apt-config` may print an `lsb_release: command not found` warning; as long as `/etc/apt/sources.list.d/mysql.list` is generated, it has no effect.
- The four services (nginx, php8.4-fpm, redis-server, mysql) start automatically after installation; confirm each one with `systemctl is-active ...`.
- Check the PHP extensions: `php -m | grep -iE "pdo_mysql|redis|gd|zip|intl|bcmath|mbstring|opcache|fileinfo"`. Debian's PHP has none of the BaoTa-style disabled-function problems; `putenv`/`proc_open`/`symlink` are available by default.

### Time Zone Alignment

The project baseline is PHP `Asia/Shanghai` and database connection `+08:00` (`config/app.php`, `config/database.php`); on bare metal you must align this yourself:

```bash
timedatectl set-timezone Asia/Shanghai
echo -e "[mysqld]\ndefault-time-zone=+08:00" > /etc/mysql/mysql.conf.d/99-turaidc-timezone.cnf
systemctl restart mysql
```

## 4. Upload the Code

Package on the build machine (which has Node/pnpm); the server only receives source code and build artifacts:

```bash
# 构建机：从干净提交打包（自动排除 .env、node_modules、dist 等未跟踪内容）
git archive --format=tar -o code.tar HEAD
# 前端 dist 构建完成后追加进同一个包（见第 6 节）
tar -rf code.tar frontend-admin-v3/dist frontend-user-v3-www/dist frontend-user-v4-console/dist

# 上传。scp 在部分环境下会静默失败，用 ssh 管道传输并以 md5 校验更可靠：
cat code.tar | ssh root@<服务器> 'cat > /tmp/turaidc-code.tar && md5sum /tmp/turaidc-code.tar'

# 服务器：解压到统一部署目录
mkdir -p /opt/turaidc
tar -xf /tmp/turaidc-code.tar -C /opt/turaidc
```

## 5. Backend Deployment

### 5.1 Create the Database and Grant Privileges

MySQL root on Debian uses auth_socket by default; run the following directly as root:

```sql
CREATE DATABASE IF NOT EXISTS idc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'idc'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT ALL PRIVILEGES ON idc.* TO 'idc'@'127.0.0.1';
FLUSH PRIVILEGES;
```

> If you write the SQL into a shell heredoc, note that an unquoted delimiter performs command substitution: the backticks in `CREATE DATABASE IF NOT EXISTS \`idc\``are executed by bash as commands (in our test it reported`idc: command not found`). `idc` is not a reserved word, so simply drop the backticks.

### 5.2 Generate backend/.env

Start from `backend/.env.example` and **replace in place with sed**; do not append to the end of the file to override keys of the same name — Laravel Dotenv runs in immutable mode, the first occurrence of a key wins, so appending has no effect:

```bash
cd /opt/turaidc/backend
cp .env.example .env
sed -i \
  -e "s|^APP_ENV=.*|APP_ENV=production|" \
  -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
  -e "s|^APP_URL=.*|APP_URL=http://api.tura.cloud|" \
  -e "s|^FRONTEND_URL=.*|FRONTEND_URL=http://www.tura.cloud|" \
  -e "s|^CLIENT_CONSOLE_URL=.*|CLIENT_CONSOLE_URL=http://console.tura.cloud|" \
  -e "s|^ADMIN_URL=.*|ADMIN_URL=http://admin.tura.cloud|" \
  -e "s|^CLIENT_SESSION_COOKIE_DOMAIN=.*|CLIENT_SESSION_COOKIE_DOMAIN=.tura.cloud|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=<强密码>|" \
  .env
cat >> .env <<'EOF'

# example 中不存在的键才允许追加
INSTALL_ADMIN_PASSWORD=<至少12位强密码>
SEO_SITE_URL=http://www.tura.cloud
SEO_FRONTEND_SHELL_URL=file:///opt/turaidc/frontend-user-v3-www/dist/index.html
EOF
chown www-data:www-data .env && chmod 600 .env
```

Key points:

- The four public addresses share one protocol; the intranet hosts approach uses HTTP, so keep `SESSION_SECURE_COOKIE` at the example default `false`.
- Keep `TURAIDC_BUSINESS_QUEUES` at the example default (`provision` is merged into the correct group automatically at runtime).
- Leave `APP_KEY` empty; `install_db.py` detects the empty value, generates one, and writes it back.
- `INSTALL_ADMIN_PASSWORD` is the temporary password for the administrator `cerbo` during first initialization and only takes effect the first time on an empty database; do not write it into documents, Git, or scheduled tasks.

### 5.3 Install Dependencies and Initialize the Database

```bash
cd /opt/turaidc/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 关键：先预建 Laravel 运行目录，再初始化（否则 optimize:clear 报 View path not found）
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public bootstrap/cache public/uploads public/media

python3 scripts/install_db.py
```

`install_db.py` performs the following in order: validate the database connection, generate `APP_KEY`, import `database/schema/mysql-schema.sql`, run incremental migrations, write the default configuration and notification templates, and create the administrator `cerbo`. It can be run repeatedly on an empty database; do not use `--reset` on a database that holds business data.

### 5.4 Permissions

```bash
cd /opt/turaidc/backend
chown -R www-data:www-data storage bootstrap/cache public/uploads public/media
chmod -R 775 storage bootstrap/cache public/uploads public/media
```

### 5.5 Production Caches

```bash
cd /opt/turaidc/backend
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

In our testing `route:cache` runs successfully in this repository (the claim in the [container documentation](docker-1panel.md) that "routes containing closures make route:cache fail" does not hold for source deployments; the measurement here is authoritative); if closures are introduced into routes later and the step fails, skipping it does not affect operation.

## 6. Frontend Build and Artifacts

Do not build in place on small-memory servers. Run the following on the build machine (Node.js 20.19+, pnpm):

```bash
CI=true \
APP_URL=http://api.tura.cloud \
FRONTEND_URL=http://www.tura.cloud \
CLIENT_CONSOLE_URL=http://console.tura.cloud \
ADMIN_URL=http://admin.tura.cloud \
CLIENT_SESSION_COOKIE_DOMAIN=.tura.cloud \
pnpm run build:frontends
```

- The build script reads the four public addresses **from process environment variables first** (`backend/.env` is only the fallback), so there is no need to modify `backend/.env` on the build machine.
- The four addresses must be distinct from each other and use the same protocol; the script validates this strictly before building.
- Build artifacts land in the `dist/` directory of each of the three frontend directories and are never written into `backend/public`.
- In CI/sandbox environments pnpm 11 may trigger an automatic dependency reinstall before `run` and fail (`ERR_SQLITE_ERROR: unable to open database file`); the `npm_config_verify_deps_before_run=false` environment variable has no effect in pnpm 11, so inject it through the CLI form or a wrapper: `pnpm --config.verify_deps_before_run=false run build:frontends` (indirectly spawned child pnpm processes need a PATH wrapper to receive the same injection).

After the build, append the three `dist` directories to the upload archive from section 4 (`tar -rf`), or package and upload them separately; after extraction they land in `/opt/turaidc/<前端目录>/dist`.

## 7. The Four Nginx Sites

Put the config files in `/etc/nginx/sites-available/`, symlink them into `sites-enabled`, and remove the distribution's default site:

```bash
for h in api www console admin; do
  ln -sf /etc/nginx/sites-available/$h.tura.cloud.conf /etc/nginx/sites-enabled/
done
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

### 7.1 api.tura.cloud (backend PHP-FPM)

```nginx
server {
    listen 80;
    server_name api.tura.cloud;
    root /opt/turaidc/backend/public;
    index index.php index.html;
    client_max_body_size 100m;

    # VNC WebSocket 仅由 API 站点转发到内部 Relay
    location ^~ /ws/vnc {
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        "upgrade";
        proxy_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_pass http://127.0.0.1:8100;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

### 7.2 www.tura.cloud (official site: static fallback + SEO dynamic rendering proxy)

Public paths are proxied to the API site, where Laravel reads the database and renders the complete HTML (the rules follow the [frontend Nginx rules](../maintain/nginx-rules.md) and section 4.2 of the BaoTa guide):

```nginx
server {
    listen 80;
    server_name www.tura.cloud;
    root /opt/turaidc/frontend-user-v3-www/dist;
    index index.html;

    location = / {
        proxy_pass http://127.0.0.1/seo/www;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(robots\.txt|sitemap\.xml)$ {
        proxy_pass http://127.0.0.1;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|products)$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(notices|help)(/[0-9]+)?$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/products/[0-9]+$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # 其余单段路径转发后端渲染：已登记路径返回可索引的完整 HTML，
# 未登记路径返回品牌 404 壳 + HTTP 404，避免爬虫把 SPA 裸壳 200 当可索引页面。
# 前提：官网自己的单段路由须先在后端 SeoRenderService::resolvePage() 登记。
location ~ ^/[^./]+/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location / {
        try_files $uri $uri/ /index.html;
    }
}
```

Plain HTTP internal forwarding has no forced-HTTPS 301 interception problem; if HTTPS is enabled later, follow the loopback allowlist instructions in section 4.2 of the BaoTa guide.

### 7.3 console / admin (SPA static fallback)

```nginx
server {
    listen 80;
    server_name console.tura.cloud;   # admin 站点同理，替换 server_name 与 root
    root /opt/turaidc/frontend-user-v4-console/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## 8. Scheduling and Daemons

### 8.1 Per-Minute Scheduling (cron)

`/etc/cron.d/turaidc` (the file name must not contain a dot; owner root, mode 644):

```cron
* * * * * www-data cd /opt/turaidc/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Run it as `www-data`, the same user as PHP-FPM, to guarantee write access to storage. This scheduled task consumes the business queue and the `automation` queue in parallel every minute; **do not additionally configure a resident `queue:work` that covers the same queues**.

### 8.2 Resident VNC Relay (systemd)

`/etc/systemd/system/turaidc-vnc-relay.service`:

```ini
[Unit]
Description=TuraIDC VNC Relay (php artisan vnc:relay)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/turaidc/backend
ExecStart=/usr/bin/php artisan vnc:relay
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now turaidc-vnc-relay
ss -tlnp | grep 8100   # 确认 127.0.0.1:8100 已监听
```

## 9. Deployment Verification

```bash
curl -s -H "Host: api.tura.cloud" http://127.0.0.1/api/health   # {"status":"alive"}
curl -s -H "Host: api.tura.cloud" http://127.0.0.1/api/ready    # status: ready
```

It is normal for `scheduler` to be `false` in `/api/ready` the first time — cron has not reached its trigger point yet; once cron is installed, wait 1-2 minutes and check again, when it should be all green (database/cache/storage/scheduler/scheduler_liveness/task_readiness/queue all true).

On the browser / build machine side (after configuring hosts):

- [ ] All four endpoints `http://api|www|console|admin.tura.cloud` return 200
- [ ] The official site home page HTML title is the "browser title" set in the site settings (SEO dynamic rendering works; the default fallback is the site name), and `/products`, `/notices`, `/sitemap.xml`, `/robots.txt` return 200
- [ ] Refreshing any SPA route in the console / admin does not return 404
- [ ] The admin can log in with `cerbo` + `INSTALL_ADMIN_PASSWORD`; change the password immediately after logging in
- [ ] `APP_DEBUG=false`, and `.env` is mode 600 owned by www-data

## 10. Upgrading

```bash
# 构建机：新提交重新打包（含重新构建的 dist），上传并解压覆盖 /opt/turaidc
# 服务器：
cd /opt/turaidc/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
systemctl restart php8.4-fpm
systemctl status turaidc-vnc-relay   # relay 由 systemd 自动守护，一般无需处理
```

Back up the database before upgrading; the rollback baseline is in the [Deployment and Scheduling Guide](scheduling.md).

## 11. Pitfalls Found in Testing (2026-08-28, Debian 13 / LXC container)

| Symptom                                                                   | Cause and resolution                                                                                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `swapon: /swapfile: Operation not permitted`                              | The LXC/VE container kernel forbids swap. With the local-build approach the server needs no swap, so skip it                                                                                                                                                                                            |
| Database-creation SQL inside a heredoc reports `idc: command not found`   | An unquoted heredoc performs command substitution, so the backticks are executed as commands; drop the backticks around the identifier                                                                                                                                                                  |
| Keys appended to the end of `.env` have no effect                         | Laravel Dotenv runs in immutable mode, so the first occurrence of a key wins; in-place sed replacement is mandatory                                                                                                                                                                                     |
| `optimize:clear` reports `View path not found`                            | `storage/framework/views` does not exist; **before `install_db.py`** you must pre-create the storage directory tree                                                                                                                                                                                     |
| scp reports success but the file never arrives                            | scp fails silently in some environments; use the `cat file \| ssh 'cat > dest'` pipe and verify with md5                                                                                                                                                                                                |
| pnpm reinstalls dependencies automatically and reports `ERR_SQLITE_ERROR` | pnpm 11's `verify-deps-before-run` triggers an install automatically in CI/sandboxes where the store is not writable; the `npm_config_verify_deps_before_run` environment variable has no effect, so `--config.verify_deps_before_run=false` is needed and must also cover indirectly spawned processes |
| scheduler in `/api/ready` is false                                        | cron was just installed and has not reached its trigger point; wait 1-2 minutes and it turns green automatically                                                                                                                                                                                        |

## Related Documentation

- [Deployment Guide](generic.md): general deployment steps.
- [Nginx Rewrite Rules for the Four Sites](../maintain/nginx-rules.md): site rewrite rules.
