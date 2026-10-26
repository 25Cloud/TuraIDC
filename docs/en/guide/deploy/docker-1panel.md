---
source: guide/deploy/docker-1panel.md
sourceHash: 2e06393b03e23d045a10ddfe6e2b2a4f297ce856cc73ddb43f88653fe79daeba
translatedAt: 2026-10-26
---

# Docker / 1Panel Deployment Guide

A complete guide to deploying TuraIDC in a containerized way on a fresh server. Docker Compose brings up all services in one shot, GitHub Actions builds and pushes the images automatically, and the server only pulls and runs them instead of deploying each frontend by hand; you can also use 1Panel's orchestration and reverse proxy capabilities to host the same Compose file.

- Aligned as of: `2026-08-22`
- For traditional BaoTa Panel deployment, see [BaoTa Panel deployment project guide](bt-panel.md); for current production operations conventions, see [Deployment and Scheduling Guide](scheduling.md)
- Deployment files live in `deploy/docker/` at the repository root; the only configuration you need to change is `deploy/docker/.env`
- For CI build and push, see `.github/workflows/docker-image.yml`
- 🧭 **Migrating from ZJMF Finance**: before deployment, if you need to import legacy site data from ZJMF, read [Migrating from ZJMF Finance](../migrate/zjmf-finance.md) first (full workflow and pitfalls for products/users/orders/upstream/real-name verification)

## 1. Container Topology

```
deploy/docker/docker-compose.yml  (project: turaidc)
├── mysql (mysql:8.0, 命名卷 mysql-data)      [可选：DB_HOST 留空时才拉取并启动]
├── redis (redis:7-alpine, 命名卷 redis-data) [可选：REDIS_HOST 留空时才拉取并启动]
├── app   (后端镜像, :80)
│     ├── php-fpm   (PHP 8.3, 含 pdo_mysql/redis/pcntl/opcache/gd/zip/intl/bcmath；镜像用 php:8.3-fpm 浮动标签，重建即取最新补丁版，须 ≥ 8.3.36 以含 CVE-2026-109618 / CVE-2026-109619 修复)
│     ├── nginx     (API + /ws/vnc WebSocket -> 127.0.0.1:8100)
│     ├── cron      (每分钟 php artisan schedule:run，驱动心跳与队列消费)
│     └── vnc:relay (VNC WebSocket 中继, 127.0.0.1:8100)
│     命名卷: app-storage / app-uploads / app-media
├── frontends (nginx:alpine, 三端口: 8081=官网 / 8082=控制台 / 8083=管理端)
│     └── 官网公开路径转发到 app 的 /seo/www/*（SEO 动态渲染）
```

Site SEO: Nginx inside the `frontends` container forwards the public site paths (home page, products, landing pages, announcements/help and their detail pages) with `proxy_pass` to `/seo/www/{path}` on the `app` container; Laravel reads the database to generate complete HTML (site name/Logo/meta/body), using `http://frontends:8081/index.html` as the template (direct access to static files over the container network, no loop). `/sitemap.xml` and `/robots.txt` are generated dynamically by Laravel.

When `DB_HOST` / `REDIS_HOST` are left empty (the default), the local mysql / redis containers start together with the stack; if you fill in a remote address, the corresponding local container is not created and its image is not pulled by `docker compose pull`, and app connects directly to the remote service. The two are independent and can be switched separately.

Image flow: GitHub Actions builds and pushes `ghcr.io/<owner>/turaidc-{app,frontends}` → the server runs `docker compose pull`.

This matches the BaoTa Panel production topology one to one: the three frontends are merged into one (a single container with three ports hosting the www/console/admin static sites respectively), the backend runs on PHP-FPM with no resident `queue:work` (business queues and the `automation` queue are consumed in parallel by `schedule:run` every minute), and VNC Relay runs resident separately.

## 2. Prerequisites

- Server: Linux (Ubuntu 20.04+/Debian 11+/CentOS 7+), 4G+ memory recommended, 20G+ disk
- Docker Engine 24+ and Compose v2 (check with `docker compose version`)
- Four domains resolving to this machine (`api`, `www`, `console`, `admin`); production must use the same protocol (HTTPS)
- GitHub repository (currently `github.com/25Cloud/TuraIDC`) for CI builds and GHCR image hosting

## 3. Configuration (deploy/docker/.env)

Copy the template and edit it first:

```bash
cd deploy/docker
cp .env.example .env
vim .env
```

| Config option                                                      | Required | Description                                                                                                                                                                                                                                                                                      |
| ------------------------------------------------------------------ | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `APP_URL` / `FRONTEND_URL` / `CLIENT_CONSOLE_URL` / `ADMIN_URL`    | Yes      | The four public addresses: distinct from each other, same protocol, no path; in the form `https://api.example.com`                                                                                                                                                                               |
| `CLIENT_SESSION_COOKIE_DOMAIN`                                     | No       | Fill in the parent domain such as `.example.com` when the site and console share login state across subdomains; leave empty for a single domain                                                                                                                                                  |
| `REGISTRY` / `IMAGE_NAMESPACE` / `IMAGE_TAG`                       | No       | Image registry address, defaults to `ghcr.io` / `25cloud` / `latest`, consistent with the CI push target                                                                                                                                                                                         |
| `APP_KEY`                                                          | No       | **Do not set it in this file.** It is generated at container startup and written to `backend/.env`. If you set an empty value here, Docker env_file injects it as a container environment variable, and Dotenv does not override existing environment variables, so the generated key is ignored |
| `INSTALL_ADMIN_PASSWORD`                                           | Yes      | Password of the default administrator `cerbo` on first initialization, at least 12 characters of a strong password, takes effect only once on an empty database                                                                                                                                  |
| `SESSION_SECURE_COOKIE`                                            | Yes      | `true` in HTTPS environments, `false` in plain HTTP environments                                                                                                                                                                                                                                 |
| `DB_HOST` / `DB_PORT`                                              | No       | Database source: leave empty to use the mysql container in the stack (default); fill in a remote address and the local mysql is neither pulled nor started, app connects directly to the remote database, port defaults to 3306                                                                  |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_ROOT_PASSWORD` | Yes      | Database name and accounts; used by both the mysql container and the app container (remote mode still requires them so app can connect)                                                                                                                                                          |
| `REDIS_HOST` / `REDIS_PORT`                                        | No       | Redis source: leave empty to use the redis container in the stack (default); fill in a remote address and the local redis is neither pulled nor started, app connects directly to the remote one, port defaults to 6379                                                                          |
| `REDIS_PASSWORD`                                                   | No       | Redis password; empty by default. Never expose 6379 on the public network; in remote mode it must match the remote instance                                                                                                                                                                      |
| `API_PORT` / `WWW_PORT` / `CONSOLE_PORT` / `ADMIN_PORT`            | No       | Host-mapped ports, defaults to 8080/8081/8082/8083                                                                                                                                                                                                                                               |
| `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER`              | No       | Consistent with live production conventions: redis / database / file, usually no change needed                                                                                                                                                                                                   |
| `SENTRY_LARAVEL_DSN`                                               | No       | Sentry DSN, leave empty to disable                                                                                                                                                                                                                                                               |
| `MAIL_FROM_ADDRESS`                                                | No       | Default sender address; SMTP credentials are configured through the admin panel's "mail plugin"                                                                                                                                                                                                  |
| `SEO_SITE_URL`                                                     | No       | Public site address used for canonical / sitemap / JSON-LD; defaults to `APP_URL`, explicitly set the site domain for multi-domain deployments                                                                                                                                                   |
| `SEO_FRONTEND_SHELL_URL`                                           | No       | Site index.html template address, defaults to `http://frontends:8081/index.html` (internal compose network, usually no configuration needed)                                                                                                                                                     |
| `SEO_SHELL_CACHE_TTL` / `SEO_CACHE_TTL`                            | No       | Cache seconds for the shell template / page render results, defaults to 600 / 300; after a frontend rebuild it takes up to that long to take effect                                                                                                                                              |

> Constraint: password and address values must not contain double quotes `"` or backslashes `\`, otherwise `.env` parsing breaks. `.env` is excluded by `.dockerignore`, so it never enters the image or the repository.
>
> Remote database / Redis mode: once `DB_HOST` or `REDIS_HOST` is set to a remote address, the corresponding local container is not created and `docker compose pull` does not pull its image either. This requires Compose v2.20+ on the server (it relies on skipping with `required: false`); for a remote database you must create the database yourself and authorize the server IP; the database schema and initialization are still performed by the app container entrypoint at startup.

## 4. CI Automatic Build and Push

`.github/workflows/docker-image.yml` triggers a build at the following moments:

| Trigger                                    | Description                                                |
| ------------------------------------------ | ---------------------------------------------------------- |
| push to `main` / `master`                  | Regular release, pushes `latest` + `main` + `sha-<短哈希>` |
| push any tag (e.g. `v1.2.0`)               | Pushes `1.2.0`, `1.2`, `latest` + `sha-<短哈希>`           |
| Manual trigger (Actions page Run workflow) | Produce a version manually when needed                     |

Build artifacts (GHCR, package names all lowercase):

```
ghcr.io/<owner>/turaidc-app
ghcr.io/<owner>/turaidc-frontends
```

### 4.1 Configuration required on first use

1. In the repository, go to **Settings → Secrets and variables → Actions → New repository secret** and add:

   | Secret                         | Example                       | Description                                                                                            |
   | ------------------------------ | ----------------------------- | ------------------------------------------------------------------------------------------------------ |
   | `APP_URL`                      | `https://api.example.com`     | Required, injected as the API address during the frontend build                                        |
   | `FRONTEND_URL`                 | `https://www.example.com`     | Required                                                                                               |
   | `CLIENT_CONSOLE_URL`           | `https://console.example.com` | Required                                                                                               |
   | `ADMIN_URL`                    | `https://admin.example.com`   | Required                                                                                               |
   | `CLIENT_SESSION_COOKIE_DOMAIN` | `.example.com`                | Optional, set when sharing login state across subdomains; the build passes an empty value when not set |

   When the four public addresses are not configured, the frontend build fails outright (`build_frontends.mjs` validation); this is intentional, to remind you to fill them in.

2. After the first push, open GitHub → avatar → Your packages (or Packages on the right side of the repository) and set the 2 `turaidc-*` packages to **public**, so the server can pull them anonymously; private packages require `docker login ghcr.io` on the server.

### 4.2 Switching the image registry (e.g. Tencent Cloud TCR / Alibaba Cloud ACR)

When pulling from GHCR is slow on mainland China servers, switch to a domestic registry:

1. Enable the personal-edition container image service at the corresponding cloud vendor and create two repositories, `turaidc-app` / `turaidc-frontends`.
2. Add Secrets/variables for `REGISTRY` and `IMAGE_NAMESPACE` in the repository (e.g. `REGISTRY=ccr.ccs.tencentyun.com`), and change `env.REGISTRY` in the workflow to the corresponding value.
3. In the workflow, replace the GHCR login step with the username/password of the target registry (or the cloud vendor's long-lived credential Secret), and configure the corresponding `login-action`.
4. Update `REGISTRY` / `IMAGE_NAMESPACE` in the server's `.env` accordingly.

## 5. Docker Compose Deployment

### 5.1 Upload the code

```bash
git clone <你的仓库地址> /opt/turaidc && cd /opt/turaidc
```

### 5.2 Configure and start

```bash
cd deploy/docker
cp .env.example .env
vim .env            # 填写四个地址、密码等

# 拉取 CI 推送的镜像并启动（推荐）
docker compose pull && docker compose up -d
# 或本地直接构建（无镜像仓库时）
docker compose up -d --build
```

On first startup (empty database) `install_db.py` runs automatically: it imports the schema baseline, runs incremental migrations, writes the default configuration, and creates the administrator `cerbo`.

### 5.3 Verification

```bash
docker compose ps                      # 全部 running
curl http://127.0.0.1:8080/api/health  # {"status":"ok"} 类响应
curl http://127.0.0.1:8080/api/ready   # DB/Cache/Storage/Scheduler 就绪检查
```

Visit `http://服务器IP:8081` (site), `:8082` (console), `:8083` (admin panel) in a browser, and log in to the admin panel with `cerbo` + `INSTALL_ADMIN_PASSWORD`.

## 6. 1Panel Deployment

1Panel (https://1panel.cn) ships with Docker and Compose orchestration. Recommended flow:

### 6.1 Upload the code

Panel → Files, upload/extract the repository to `/opt/turaidc`, or run `git clone` over SSH.

### 6.2 Prepare .env

Run over SSH:

```bash
cd /opt/turaidc/deploy/docker
cp .env.example .env
vim .env
```

### 6.3 Create the orchestration

Panel → Containers → Orchestration → Create orchestration:

| Config option | Value                                           |
| ------------- | ----------------------------------------------- |
| Name          | `turaidc`                                       |
| Source        | Local path                                      |
| Path          | `/opt/turaidc/deploy/docker/docker-compose.yml` |

After creating it, click "Build and start" (1Panel runs `pull`/`build` automatically). Check the "Containers" page to confirm that all 4 containers, such as `turaidc-mysql`, `turaidc-app`, and `turaidc-frontends`, are running; open the logs of `turaidc-app` to confirm the entrypoint has finished database initialization.

> Variables in the orchestration come from `.env` in the same directory, behaving the same as the command-line `docker compose`.

### 6.4 Reverse proxy and HTTPS

Panel → Websites → Create website → Reverse proxy, create one entry for each of the four domains:

| Domain             | Target address          |
| ------------------ | ----------------------- |
| `api.你的域名`     | `http://127.0.0.1:8080` |
| `www.你的域名`     | `http://127.0.0.1:8081` |
| `console.你的域名` | `http://127.0.0.1:8082` |
| `admin.你的域名`   | `http://127.0.0.1:8083` |

Panel → Certificates → Request certificate (Let's Encrypt; the four domains can be combined into one wildcard certificate), bind it to the corresponding websites and enable force HTTPS. 1Panel's reverse proxy supports WebSocket by default, so `/ws/vnc` passes through directly; if the console VNC cannot connect, check whether that reverse proxy preserves the `Upgrade`/`Connection` headers.

### 6.5 Hardening

Once the reverse proxy works, change the four ports in `.env` to listen on localhost only (to prevent direct public access to ports such as 8080), then recreate the containers:

```bash
cd /opt/turaidc/deploy/docker
# 例如 API_PORT=127.0.0.1:8080 其余同理
docker compose up -d
```

Panel → Firewall, allow only `80`/`443` (plus the panel port).

## 7. HTTPS in a Pure Docker Environment

Without 1Panel, you can use host Nginx/Caddy or Cloudflare to reverse proxy to the four ports. Host Nginx example (one server per domain):

```nginx
# WebSocket 升级映射放在 http 块（例如 /etc/nginx/conf.d/ 下的公共片段）
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

# 80 端口统一跳转，避免用户直接访问 http 得到 404
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;                       # nginx < 1.25.1 写作 listen 443 ssl http2;
    server_name api.example.com;
    ssl_certificate     /etc/nginx/certs/api/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/api/privkey.pem;

    client_max_body_size 100m;

    # 关键：转发头必须放在 server 级。nginx 的 proxy_set_header 不跨 location 继承，
    # 若只写在 location / 内，下面的 /ws/vnc 因为自己声明了 proxy_set_header，
    # 会完全拿不到 Host / X-Forwarded-* —— Host 退化为 $proxy_host（127.0.0.1:8080），
    # 导致 VNC token 的 Origin 校验与 wss 地址推导失败。
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    location / {
        proxy_pass http://127.0.0.1:8080;
    }

    # VNC WebSocket 需升级头
    # 注意：location 里一旦出现 proxy_set_header，就不再继承 server 级的同类指令，
    # 因此 Host / X-Real-IP / X-Forwarded-For / X-Forwarded-Proto 必须在这里重复声明，
    # 否则 VNC 通道会丢失真实客户端 IP 与 HTTPS 标识（后端据此判定 isSecure()）。
    location /ws/vnc {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

For the frontend sites, simply drop the WebSocket section. Requirements: all four addresses use the same protocol (all HTTPS), and `SESSION_SECURE_COOKIE=true` in `.env`.

`X-Forwarded-Proto: https` is the only signal the backend uses to detect HTTPS: inside the container `deploy/docker/backend/nginx.conf` uses `map` to turn it into `fastcgi_param HTTPS on`, so that Laravel's `isSecure()` returns true. If the reverse proxy does not pass this header, the backend still treats requests as HTTP even in an HTTPS environment.

> **Source IP contract**: the backend `trustProxies` only trusts loopback and RFC1918/ULA private ranges and honors `X-Forwarded-For`; `request()->ip()` and the `/upload_image` whitelist/rate limiting both depend on it. The `$proxy_add_x_forwarded_for` above preserves the XFF the client sends, which public clients can use to forge their source. A single trusted reverse proxy should reset this header to `$remote_addr` (appending is acceptable only with multiple trusted proxies that sanitize hop by hop), and the API port should be bound to localhost only (`127.0.0.1:8080`); do not expose container ports to the public network. For the full contract, see "Trusted Proxies and Source IP Contract" in the [Deployment and Scheduling Guide](scheduling.md).

Compression is already handled inside the containers: the `frontends` container configures gzip and `gzip_static` for static assets (preferring the `.gz` files generated at build time), and `backend/nginx.conf` also enables gzip for API JSON responses. Host Nginx does **not** need to configure it again for these two kinds of responses — Nginx does not compress twice when a proxied response already carries `Content-Encoding`.

If other responses on the host that do not go through these two containers need compression (for example static files served directly by the host or third-party upstreams), add it to the http block as needed. Note that Nginx defaults to `gzip off`; there is no such thing as "global compression that takes effect automatically":

```nginx
gzip            on;
gzip_vary       on;
gzip_min_length 1024;
gzip_proxied    any;               # 必须，否则代理响应不会被压缩
gzip_types      text/plain text/css application/javascript application/json
                application/xml image/svg+xml;
```

## 8. Upgrade and Rollback

### 8.1 Upgrade (pull the new CI image)

```bash
cd /opt/turaidc
git pull                      # 更新 compose 与 .env 模板（若有变化）
cd deploy/docker
docker compose pull           # 拉取 CI 推送的最新镜像
docker compose up -d          # 增量迁移由 app 容器启动时自动执行（migrate --force）
docker compose ps
```

> Back up the database before upgrading (see the next section). When a migration fails, check `docker compose logs app`.

### 8.2 Rollback

Re-launch with an old image tag:

```bash
cd deploy/docker
sed -i 's/^IMAGE_TAG=.*/IMAGE_TAG=<上一版本或 sha-xxx>/' .env
docker compose pull && docker compose up -d
```

Database migrations are irreversible in principle; when you need to roll back the database, restore from a backup. Never run irreversible `migrate:rollback` migrations against a production database.

## 9. Backup and Restore

### 9.1 Scheduled backup (host crontab)

```bash
0 3 * * * cd /opt/turaidc/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
```

Backup files are located at `deploy/docker/backups/turaidc-<时间戳>.sql.gz` and are kept for 14 days by default (change with `./backup.sh 30`).

### 9.2 Manual backup

```bash
cd /opt/turaidc/deploy/docker && ./backup.sh
```

### 9.3 Restore

```bash
gunzip -c backups/turaidc-xxx.sql.gz | docker compose exec -T mysql \
  sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 10. Operations Command Quick Reference

```bash
docker compose ps                        # 状态
docker compose logs -f app               # 后端日志（php-fpm/nginx/cron/relay 均走 stdout）
docker compose logs -f frontends          # 前端日志
docker compose exec app bash             # 进入后端容器
docker compose exec app php artisan schedule:run   # 手动触发一次调度
docker compose exec app php artisan queue:retry all # 重试失败队列
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose restart app               # 重启后端
```

The backend log file `storage/logs/laravel.log` lives in the named volume `app-storage`; health checks: `/api/health` (liveness), `/api/ready` (readiness).

## 11. FAQ

### 11.1 Cannot log in to the admin panel after first startup

Confirm that `install_db.py` completed on the first startup with an empty database (`docker compose logs app` contains `执行 install_db.py` and no errors); the administrator is `cerbo` + the `INSTALL_ADMIN_PASSWORD` from `.env` (takes effect only once; change the password in the admin panel immediately afterwards).

### 11.2 502 / 504

- Check `docker compose logs app`: when php-fpm is not started by supervisor, or MySQL is not ready and the entrypoint gets stuck, the symptom is 502
- Confirm the `mysql` container healthcheck passes (shows healthy in `docker compose ps`)
- A missing `APP_KEY` causes Laravel 500: confirm that `docker compose exec app sh -c 'grep ^APP_KEY= .env'` returns a value

### 11.3 Pulling images returns 401 / cannot pull

- After the first CI push, GHCR packages are private by default: go to the Packages page and set the 2 `turaidc-*` packages to public
- Or run `docker login ghcr.io` on the server with a PAT
- If pulling is slow in mainland China, switch to a Tencent Cloud/Alibaba Cloud registry (see 4.2)

### 11.4 CI build fails

- Check whether the repository Actions secrets have all four public addresses configured (the failure message mentions "must be an HTTP(S) root address" or reports them as missing)
- Check the `ghcr.io` login: the first build requires GITHUB_TOKEN to have `packages: write` (the workflow already declares permissions)

### 11.5 Page refresh returns 404

The frontend nginx already ships with `try_files $uri $uri/ /index.html;`; if it still returns 404, confirm you are accessing the frontend container (8081/8082/8083) rather than the API port.

### 11.6 Wrong time zone / time

The app container's `TZ=Asia/Shanghai` is already set in the orchestration; MySQL sets it via `--default-time-zone=+08:00`; `DB_TIMEZONE=+08:00` in `.env`.

### 11.7 Uploaded files are too large

The defaults are `client_max_body_size 100m`, `upload_max_filesize=100M`, `post_max_size=120M`; if you need more, change both `deploy/docker/backend/nginx.conf` and `backend/php.ini`, then rebuild the image.

### 11.8 Changes to .env do not take effect

The entrypoint regenerates `backend/.env` and runs `config:cache` at container startup, so after modifying `deploy/docker/.env` you must restart the container:

```bash
docker compose up -d --force-recreate app
```

Changes to the four public addresses require rebuilding/pulling the frontend image (the frontends are injected at build time).

### 11.9 VNC console cannot connect

- Check whether the reverse proxy drops the Upgrade header for `/ws/vnc` (see section 6)
- Run `docker compose exec app supervisorctl status` inside the container to confirm `vnc-relay` is RUNNING
- Direct test: `docker compose exec app sh -c 'curl -s http://127.0.0.1:8100 || true'` (the Relay returning 400 for non-WebSocket requests is normal)

### 11.10 fpm reports "Operation not permitted" under Docker Desktop Windows

The overlayfs of the Docker Desktop WSL2 backend has a known bug: when a forked php-fpm worker reads files in the image layer, `open()` returns EPERM. The compose file works around it with `tmpfs: /var/www/backend` — at startup the entrypoint copies the code from the image layer `/opt/backend-src` to the tmpfs (a memory filesystem), and fpm only reads tmpfs files. Linux production environments do not have this bug, and the tmpfs copy takes <2s, which is negligible.

### 11.11 Retry after a failed migration/initialization

When no data has been written yet (still an empty database), you can recreate the database volume and start over:

```bash
docker compose down -v   # 注意：-v 会删除全部命名卷（含数据库数据），仅限全新环境
docker compose up -d
```

## 12. Differences from BaoTa Panel Deployment

- Same conventions: queues are not resident, `schedule:run` drives every minute, `CACHE_STORE=redis`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file`, VNC Relay resident on 8100
- No `route:cache` inside the container (routes contain closures and would fail); only `config:cache`
- Code, PHP extensions, and Nginx configuration all ship with the image; `storage`, `public/uploads`, `public/media` persist through named volumes
- Production HTTPS always terminates outside the containers (1Panel/host Nginx/Cloudflare)

## Related Documentation

- [Deployment guide](generic.md): general deployment steps.
- [Four-frontend Nginx rewrite rules](../maintain/nginx-rules.md): site rewrite rules.
- [Deployment and Scheduling Guide](scheduling.md): scheduling and queue configuration.
