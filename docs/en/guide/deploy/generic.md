---
source: guide/deploy/generic.md
sourceHash: cef83a388d79f811d266f67952c32b7f4ee4354fbecc7570afc893fe2cb7ffc7
translatedAt: 2026-10-26
---

# Deployment Guide

This document describes how to deploy the TuraIDC business/finance system to a production environment. Deployment topology: backend API, three frontend static sites, Redis and MySQL; queues and scheduling are driven by the Laravel scheduler that runs once per minute, so no resident queue worker is required.

## 1. Environment Requirements

- Operating system: Linux (Ubuntu 22.04+ / Debian 12 recommended) or Windows Server
- PHP 8.3+, and it must be **a version that has been patched for CVE-2026-109618 / CVE-2026-109619**: the minimum patched release in the upstream branch is 8.3 ≥ 8.3.36 / 8.4 ≥ 8.4.27 / 8.5 ≥ 8.5.12; backport packages from distributions and LTS vendors are equally valid (the distribution version number may be lower than the figures above — the vendor advisory is authoritative). If you cannot patch immediately, first write `session.upload_progress.enabled=0` into php.ini / the FPM pool and restart all workers; this blocks the known attack path (it is not a complete fix)
- MySQL 8.0+ (recommended; minimum compatible version 5.7.8 — 5.7 is EOL and gets compatibility support only. When backing up a 5.7 server with an 8.0 mysqldump client, add `--column-statistics=0`. **A 5.7 deployment must set, in the `[mysqld]` section of my.cnf, `explicit_defaults_for_timestamp=ON`**: with the 5.7 default of OFF, the first `timestamp` column in a table without an explicit default is implicitly given `ON UPDATE CURRENT_TIMESTAMP`, which is inconsistent with the 8.0 behavior and may silently rewrite business time fields)
- Redis 6.0+ (required in production: distributed locks, cache)
- Composer 2.x
- Node.js 20+ (needed only when building the frontends)
- Nginx (or any static server + reverse proxy)

### 1.1 Extensions and Disabled Functions Follow the Code

The single source of truth for the PHP version, the required extensions and the disabled-function list is `backend/app/Support/EnvironmentRequirements.php`; this document does not maintain a second copy of the list, so it cannot drift from the code. After the dependencies are installed you can check at any time:

```bash
cd backend
php artisan app:doctor          # 必选项：版本、必需扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

**Disabled functions are the most frequent deployment failure**: BaoTa Panel disables `putenv`, `proc_open` and `symlink` by default, which directly breaks `.env` reading and process invocation. To unblock them: BaoTa → App Store → PHP → Disabled Functions, delete these three functions, restart PHP, then re-check with `php artisan app:doctor`.

`app:doctor` can be executed both before and after installation: the installer's environment check calls the same list, but the wizard disappears with a 404 once the site is installed, so after a PHP upgrade or a php.ini change this command is the only way to re-check.

## 2. Prepare the Code

```bash
git clone <你的仓库地址> TuraIDC
cd TuraIDC
```

## 3. Backend Deployment

### 3.1 Install Dependencies and Configure

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env` and confirm in particular:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://www.example.com
CLIENT_CONSOLE_URL=https://console.example.com
ADMIN_URL=https://admin.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=finance
DB_USERNAME=finance
DB_PASSWORD=你的强密码

CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
```

> The four URLs (APP_URL / FRONTEND_URL / CLIENT_CONSOLE_URL / ADMIN_URL) must be distinct HTTPS domains. The frontend build script reads these addresses and injects them into each frontend build output.

### 3.2 Initialize the Database

```bash
# 创建数据库
mysql -u root -p -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 导入完整结构基线
mysql -u root -p finance < database/schema/mysql-schema.sql

# 执行增量迁移
php artisan migrate --force

# 写入系统默认配置
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder
```

> `database/schema/mysql-schema.sql` is the complete schema baseline and must not be edited by hand; new features only add incremental migrations under `database/migrations/`.

### 3.3 Create the Administrator Account

```bash
php artisan tinker
App\Models\AdminUser::create([
    'username' => 'admin',
    'password' => '你的强密码',
    'role_id'  => 1,
    'nickname' => '管理员',
    'status'   => 1,
]);
```

> `role_id` must be the id of a super administrator role (the record id of the default `super_admin` role). Additional roles and staff accounts can be created in the admin panel.

### 3.4 Configure Scheduled Tasks and Queues

Add the following command to the system crontab (runs the Laravel scheduler once per minute):

```cron
* * * * * cd /path/to/TuraIDC/backend && php artisan schedule:run >> /dev/null 2>&1
```

Internally, the Laravel scheduler drives heartbeat dispatch at the cadence defined in `routes/console.php` (deduplicated per 15-minute slot), `queue:drain` queue consumption (a separate worker per queue group — `provision`, the business group and `automation`; the drain lock guarantees that the same queue is not consumed concurrently), liveness probes and periodic cleanup. The database is the only clock source, so there is no need to configure several separate cron entries.

> **Do not configure an additional resident `queue:work` that covers the same queue**: `schedule:run` already triggers `queue:drain` in the background every minute to consume all queue groups, and adding a resident worker creates a second consumer; to investigate a queue backlog, see the manual consumption command in the [Deployment and Scheduling Guide](scheduling.md).

### 3.5 Cache Clearing and Resources

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

> If `SESSION_DRIVER` is set to file, `storage/framework/sessions` must be writable.

## 4. Frontend Build

Run from the repository root (requires Node.js 20+ and pnpm):

```bash
pnpm install --frozen-lockfile --shamefully-hoist
pnpm run build:frontends
```

The command reads the four public addresses from `backend/.env` and builds the three frontends in sequence into their own `dist/`:

| Frontend         | Output directory                 | Port (development) |
| ---------------- | -------------------------------- | ------------------ |
| Official website | `frontend-user-v3-www/dist/`     | 5175               |
| User console     | `frontend-user-v4-console/dist/` | 5173               |
| Admin panel      | `frontend-admin-v3/dist/`        | 5174               |

All three are pure static sites; to publish, deploy the contents of each `dist/` to the corresponding Nginx site.

## 5. Nginx Configuration Example

### Backend API (api.example.com)

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    # ssl_certificate / ssl_certificate_key ...

    root /path/to/TuraIDC/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

### Frontend Sites (www.example.com / console.example.com / admin.example.com)

All three frontends use the same configuration template; only `server_name` and `root` need to be replaced:

```nginx
server {
    listen 443 ssl http2;
    server_name www.example.com;

    root /path/to/TuraIDC/frontend-user-v3-www/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## 6. Process Supervision (supervisor)

Queue consumption is driven by the scheduler's `queue:drain` every minute, so a resident queue worker is **not** required; the process that needs supervision is VNC Relay (optional).

### VNC Relay (Optional, Console Remote Desktop)

If the VNC remote desktop feature is enabled, run the `vnc:relay` process and reverse proxy the public WebSocket path (default `/ws/vnc`) to `127.0.0.1:8100`:

```ini
[program:finance-vnc-relay]
directory=/path/to/TuraIDC/backend
command=php artisan vnc:relay --host=127.0.0.1 --port=8100
autostart=true
autorestart=true
user=www-data
```

You can also use the unified entry point `php artisan app:serve --with-schedule` to host HTTP, VNC Relay, queues and scheduling together, but that is a local and integration entry point; in production, split them into separate processes with PHP-FPM + cron + supervisor as described above.

## 7. Optional Integration Configuration

Enable the following capabilities in `backend/.env` as needed (placeholders are in `.env.example`):

| Integration                  | Environment variables                                                              | Description           |
| ---------------------------- | ---------------------------------------------------------------------------------- | --------------------- |
| Alipay                       | `ALIPAY_APP_ID` / `ALIPAY_PRIVATE_KEY` / `ALIPAY_PUBLIC_KEY` / `ALIPAY_NOTIFY_URL` | Online payment        |
| SMS (Alibaba Cloud)          | `SMS_API_ENDPOINT`                                                                 | Verification code SMS |
| GeeTest                      | `GEETEST_CAPTCHA_ID` / `GEETEST_CAPTCHA_KEY` / `GEETEST_ENABLED`                   | Human verification    |
| Real-name verification (IDC) | `VERIFICATION_KEY` / `VERIFICATION_API_ENDPOINT`                                   | Real-name check       |
| VNC Relay                    | `VNC_RELAY_HOST` / `VNC_RELAY_PORT`                                                | Remote desktop        |
| Sentry                       | `SENTRY_DSN`                                                                       | Error monitoring      |

Vendor adapters (such as `zjmf_finance`) are bound through the "Vendors" module in the admin panel; keys are never written into code.

## 8. Backup

See `backend/scripts/backup_mysql.sh` (backs up MySQL every night and keeps 14 days):

```bash
bash backend/scripts/backup_mysql.sh
```

Also back up:

- The database (`mysqldump --single-transaction --routines --triggers`)
- `backend/storage/app/private/` (uploaded files, invoice attachments, log archiving)
- `backend/.env` (application keys and configuration)

## 9. Pre-launch Checklist

- [ ] `.env` has `APP_DEBUG=false`, `APP_ENV=production` and `SESSION_SECURE_COOKIE=true`
- [ ] Production uses `CACHE_STORE=redis` (required by distributed locks)
- [ ] All four public domains use HTTPS and are distinct from each other
- [ ] All migrations have been run and the default configuration has been initialized
- [ ] A super administrator has been created and the default credentials changed
- [ ] crontab is configured with `php artisan schedule:run`
- [ ] supervisor is supervising the queue worker
- [ ] The build output of all three frontends is deployed and caches are refreshed
- [ ] Permissions are correct for `backend/public/uploads/` and the `storage` directory

## 10. Official Website SEO Deployment

The official website is a Vue single-page application; the bare shell `index.html` carries `noindex, nofollow`, and the body is rendered dynamically from the database by the backend `SeoRenderService` and then injected. **If this chain is not wired up, search engines only get an empty shell carrying `noindex`, which means the site is effectively not indexed at all.** For the complete rewrite rules see [Nginx rewrite rules for all four sites](../maintain/nginx-rules.md); this section lists only the parts that must be in place for each deployment form and how to verify them.

### 10.1 What Each of the Three Deployment Forms Must Do

| Deployment form                                   | Where the forwarding rules go                                                                                                                                                                    | Frontend shell address                                                                                                                                                                                                     | Caveats                                                                                                                                                                  |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| BaoTa Panel / traditional Nginx source deployment | Copy section 2 of [Nginx rewrite rules for all four sites](../maintain/nginx-rules.md) into the "rewrite rules" of the official website site; see section 4.2 of [BaoTa deployment](bt-panel.md) | Leave empty to auto-detect `file://` and read `frontend-user-v3-www/dist/index.html` on the same host directly; you can also set `SEO_FRONTEND_SHELL_URL=file:///绝对路径/frontend-user-v3-www/dist/index.html` explicitly | Internal forwarding goes to `127.0.0.1:80`; when the API site enforces HTTPS, allow requests from the local host, otherwise the loopback request is intercepted by a 301 |
| Docker / 1Panel orchestration                     | Already built into `deploy/docker/frontends/nginx-default.conf`, no need to configure it again                                                                                                   | Defaults to `http://frontends:8081/index.html` (compose internal network)                                                                                                                                                  | See [Docker / 1Panel deployment](docker-1panel.md)                                                                                                                       |
| Bare-metal systemd deployment                     | Copy section 2 of [Nginx rewrite rules for all four sites](../maintain/nginx-rules.md); see section 7.2 of [bare-metal deployment](bare-metal.md)                                                | Same as the BaoTa form: leave empty to auto-detect                                                                                                                                                                         | The loopback redirect of a forced-HTTPS API site applies here as well                                                                                                    |

### 10.2 Environment Variables

```bash
# 官网公开地址：canonical / sitemap / JSON-LD 都以它为准。
# 不填会退化成 API 域名，全站被规范化到非 HTML 端点。
SEO_SITE_URL=https://www.你的域名.com

# 前端 shell 模板地址。源码部署留空即自动判定为 file:// 直读同机构建产物；
# 容器部署留空即用 compose 内部网络地址。只有自定义路径时才需要显式填写。
# SEO_FRONTEND_SHELL_URL=file:///www/wwwroot/你的项目/frontend-user-v3-www/dist/index.html

# shell 模板 / 渲染结果缓存秒数。站点内容发布后靠内容版本号自动失效，
# 这两个值只决定静态兜底的最长延迟。
SEO_SHELL_CACHE_TTL=600
SEO_CACHE_TTL=300
```

### 10.3 Which Paths Must Be Rendered by the Backend

- Home page `/`, `/products`, `/products/{商品ID}`, landing pages (`/cloud-server` etc.), `/about`, `/terms`, `/privacy`, `/notices`, `/notices/{id}`, `/help`, `/help/{id}`, `/open-source`
- `/robots.txt`, `/sitemap.xml` (the backend must generate the real text and XML; these must not fall through to the SPA static fallback)
- Other single-segment paths are forwarded to the backend, which returns the branded 404 shell plus HTTP 404, so that crawlers do not treat the bare SPA shell with a 200 status as an indexable page
- **Multi-segment paths** (the product purchase flow `/products/{slug}/{groupId}/{childGroupId}/{productId}`) keep the SPA static fallback

> Wildcard forwarding has one precondition: the official website's own single-segment routes must first be registered in `SeoRenderService::resolvePage()`, otherwise they are turned into the 404 shell. When you add a single-segment route to the official website, register it in the backend at the same time.

### 10.4 Post-launch Self-check

```bash
# 1) robots 与 sitemap 必须是真实内容，不是 SPA 壳
curl -s https://www.你的域名.com/robots.txt | head -3
curl -s https://www.你的域名.com/sitemap.xml | head -c 200

# 2) 爬虫 UA 取首页：应有 canonical 与 JSON-LD，且没有 noindex
curl -s -A 'Mozilla/5.0 (compatible; Googlebot/2.1)' https://www.你的域名.com/ \
  | grep -E 'rel="canonical"|ld\+json|name="robots"'

# 3) 商品详情页应带 Product 结构化数据
curl -s -A 'Mozilla/5.0 (compatible; Googlebot/2.1)' https://www.你的域名.com/products/1 \
  | grep -o '"@type":"Product"'
```

Once the self-check passes, submit `https://www.你的域名.com/sitemap.xml` to the Baidu Search Resource Platform (the site already carries the `msvalidate.01` verification meta), and run "Crawl diagnostics" once on the platform to confirm the live crawl result.

### 10.5 Common Pitfalls

| Symptom                                                            | Cause                                                                                                                 | Resolution                                                                                                                                 |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| The crawler gets the empty shell and the source contains `noindex` | Rewrite rules are not configured, so every request lands in `try_files ... /index.html`                               | Add the forwarding rules as described in 10.1                                                                                              |
| `/robots.txt` returns HTML                                         | The static fallback swallows this path as well                                                                        | Same as above                                                                                                                              |
| robots is normal but the page returns 500                          | `SEO_FRONTEND_SHELL_URL` points at the Docker service name `frontends:8081`, which a source deployment cannot resolve | Leave it empty so the code auto-detects, or set an explicit `file://` local path                                                           |
| The page has content but no canonical / JSON-LD                    | The path is not registered in the backend `resolvePage()`, so the 404 shell is returned                               | Register it and run the check again                                                                                                        |
| The content is the oldest one                                      | The shell cache has not expired after the frontend was rebuilt                                                        | Wait for `SEO_SHELL_CACHE_TTL` seconds or clear the cache                                                                                  |
| Changes do not take effect                                         | The repository was changed but the frontend was not rebuilt / Nginx was not reloaded                                  | Rebuild the frontend and reload Nginx                                                                                                      |
| The home page title contains only the brand name                   | The site's "browser title" has no keywords                                                                            | Fill in a value with keywords under "Site settings → Browser title" in the admin panel, using the same wording on the frontend and backend |

> When the domain is served through a CDN, the origin-pull rules still apply, but the CDN may cache an old shell; refresh the cache on the CDN side when necessary.

## Related Documentation

- [BaoTa deployment project guide](bt-panel.md): panel-based deployment workflow.
- [Docker / 1Panel deployment guide](docker-1panel.md): containerized deployment.
- [Bare-metal source deployment guide](bare-metal.md): deployment without a panel or containers.
- [Deployment and scheduling guide](scheduling.md): scheduling, queues and operations rules.
