---
source: guide/deploy/generic.md
sourceHash: 74d49601b9b17904a44825fb18deb86660abf107a79c4a3ac68126a11cbf09f0
translatedAt: 2026-11-27
---

# Deployment Guide

This document describes how to deploy the TuraIDC business/finance system to a production environment.

## 1. Environment Requirements

- Linux (Ubuntu 22.04+ / Debian 12 recommended) or Windows Server
- PHP 8.3+
- MySQL 8.0+
- Redis 6.0+
- Composer 2.x
- Node.js 20+ (for building the frontends)
- Nginx (or any static server and reverse proxy)

> [!WARNING] Warning for PHP 8.3 users
> Use **a version that has been patched for [CVE-2026-109618](https://nvd.nist.gov/vuln/detail/cve-2026-109618) / [CVE-2026-109619](https://nvd.nist.gov/vuln/detail/cve-2026-109619)**.
>
> If you cannot apply the patch immediately, write `session.upload_progress.enabled=0` into php.ini / the FPM pool and restart all workers, then upgrade PHP at the earliest opportunity.

> [!TIP] Note
> The minimum compatible version is MySQL 5.7.8. MySQL 5.7 is EOL and is supported for compatibility only.
>
> When backing up a 5.7 server with an 8.0 mysqldump client, add `--column-statistics=0`.
>
> **A 5.7 deployment must set `explicit_defaults_for_timestamp=ON` in the `mysqld` section of my.cnf.**

### Extensions and Disabled Functions

The PHP version, required extensions and disabled-function list live in `backend/app/Support/EnvironmentRequirements.php`.

This document does not duplicate that list. After installing the dependencies you can check at any time:

```bash
cd backend
php artisan app:doctor          # 必选项：版本、必需扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

> [!IMPORTANT] Note
> BaoTa Panel disables `putenv`, `proc_open` and `symlink` by default, which breaks `.env` reading and process invocation.
>
> To fix it: BaoTa → App Store → PHP → Disabled Functions, remove those three functions, restart PHP, then re-check with `php artisan app:doctor`.

## 2. Prepare the Code

```bash
git clone https://github.com/25Cloud/TuraIDC TuraIDC
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

Edit `.env` and confirm the following:

```bash
APP_ENV=production
APP_DEBUG=false

# 地址分配
APP_URL=https://api.example.com
FRONTEND_URL=https://www.example.com
CLIENT_CONSOLE_URL=https://console.example.com
ADMIN_URL=https://admin.example.com

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=finance
DB_USERNAME=finance
DB_PASSWORD=你的强密码

# Redis配置
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
```

> [!IMPORTANT] Note
> Give each URL a distinct domain; these addresses are used when building the frontends.

### 3.2 Initialize the Database

```bash
# 创建数据库
mysql -u root -p -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# 导入结构
mysql -u root -p finance < database/schema/mysql-schema.sql
# 增量迁移
php artisan migrate --force
# 写入默认配置
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder
```

### 3.3 Create the Super Administrator Account

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

> [!TIP]
> After the super administrator is created, you can create additional roles and staff accounts in the admin panel.

### 3.4 Configure Scheduled Tasks

Add the following `cron` entry to the system crontab:

```bash
* * * * * cd /TuraIDC根目录/backend && php artisan schedule:run >> /dev/null 2>&1
```

> [!CAUTION] Warning: do not configure multiple cron entries
> Do not configure multiple resident `queue:work` processes, otherwise there may be two consumers; to investigate a queue backlog, see the manual consumption commands in the [Deployment and Scheduling Guide](scheduling.md).

### 3.5 Clear Caches

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

## 4. Frontend Build

Run from the repository root:

```bash
pnpm install --frozen-lockfile --shamefully-hoist
pnpm run build:frontends
```

| Frontend         | Output directory                 | Port (development) |
| ---------------- | -------------------------------- | ------------------ |
| Official website | `frontend-user-v3-www/dist/`     | 5175               |
| User console     | `frontend-user-v4-console/dist/` | 5173               |
| Admin panel      | `frontend-admin-v3/dist/`        | 5174               |

When publishing, deploy the contents of each `dist/` to its corresponding Nginx site.

## 5. Nginx Configuration Example

### Backend

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

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

### Frontend

All three frontends use the same configuration template; just replace `server_name` and `root`:

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

### CSP and Clickjacking for Frontend Sites

Because the frontends are static HTML, CSP must be handled by Nginx.

The default file is `deploy/nginx/frontend-csp.conf`; copy it into the corresponding `server {}`. What each directive means:

| Directive                    | Purpose                                                                                                                                                                            |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `script-src 'unsafe-inline'` | The first-paint and branding scripts of the website and console, and the admin bootstrap script, are inline; static hosting has no nonce. Move them to `/assets` to drop this.     |
| `script-src 'unsafe-eval'`   | All three frontends compile vue-i18n messages at runtime (no precompilation); removing this causes `EvalError`. Enable precompilation to drop it.                                  |
| Geetest origins              | Login captcha for the admin panel and console; `gt4.js` loads verification libraries via script tags and reports telemetry, so allowing only `static` fails during initialization. |
| `https://res.25y.cn`         | The external SDK loaded by the captcha adapter script (Corptcha), determined by the plugin's `script_url`.                                                                         |
| `https://tdesign.gtimg.com`  | The SVG sprite loaded by TDesign `t-icon` with default `loadDefaultIcons`.                                                                                                         |
| `style-src 'unsafe-inline'`  | TDesign and Element Plus inject styles at runtime; the first-paint splash and captcha styles are also inline.                                                                      |
| `frame-src ... alipays:`     | The console recharge page launches the Alipay app through a hidden iframe.                                                                                                         |
| `frame-ancestors 'self'`     | Only same-origin pages may frame the site (same-domain panels such as `dash.example.com`).                                                                                         |

> [!WARNING] Warning about Nginx
> Under Nginx, `add_header` is not inherited.
>
> Any `add_header` inside a `location` (for example `Cache-Control` for static assets) prevents the `index.html` on that path from using the policy; paste the CSP again inside that `location`.
>
> Save the snippet as `snippets/frontend-csp-<app>.conf` and `include` it to avoid drift.

> [!TIP] Self-check
> Run `curl -sI https://console.example.com/ | grep -i content-security-policy` to confirm the header is present.
>
> If the browser console reports `Refused to load ...`, an origin is missing; add it according to the table above.

## 6. Process Supervision (supervisor)

Queues are driven by the scheduler and need **no** resident worker; the only optional process needing supervision is VNC Relay.

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

You can also use `php artisan app:serve --with-schedule` to host multiple services at once, but in production use PHP-FPM + cron + supervisor to split them into separate processes.

## 7. Backup

See `backend/scripts/backup_mysql.sh` (backs up MySQL every night and keeps 14 days):

```bash
bash backend/scripts/backup_mysql.sh
```

Also back up:

- The database (`mysqldump --single-transaction --routines --triggers`)
- `backend/storage/app/private/` (uploaded files, invoice attachments, log archives)
- `backend/.env` (application keys and configuration)

## 8. SEO Deployment

The official website is a Vue single-page application; its body is rendered dynamically by `SeoRenderService` and injected.

For the complete rewrite rules see [Nginx rewrite rules for all four sites](../maintain/nginx-rules.md); this section lists only what must be in place for each deployment form and how to verify it.

### 8.1 Three Deployment Forms

| Deployment form | Forwarding rules                                                                                                                          | Frontend shell address                                                                                                                                                                                    |
| --------------- | ----------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BaoTa / Nginx   | Section 2 of [Nginx rewrite rules for all four sites](../maintain/nginx-rules.md) / section 4.2 of [BaoTa deployment](bt-panel.md)        | Leave empty to auto-detect `file://TuraIDC工作目录/frontend-user-v3-www/dist/index.html`; you can also set `SEO_FRONTEND_SHELL_URL=file:///absolute/path/frontend-user-v3-www/dist/index.html` explicitly |
| Docker / 1Panel | Built in, no extra configuration needed                                                                                                   | Defaults to `http://frontends:8081/index.html`                                                                                                                                                            |
| Bare metal      | Section 2 of [Nginx rewrite rules for all four sites](../maintain/nginx-rules.md) / section 7.2 of [bare-metal deployment](bare-metal.md) | Same as BaoTa. Note the forced-HTTPS redirect on the API site.                                                                                                                                            |

### 8.2 Environment Variables

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

### 8.3 Self-check

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

> [!TIP] Note
> CDN origin-pull rules apply as well, but a CDN may cache an old shell; refresh the cache on the CDN side when necessary.

## Related Documentation

- [BaoTa deployment guide](bt-panel.md): panel-based deployment workflow.
- [Docker / 1Panel deployment guide](docker-1panel.md): containerized deployment.
- [Bare-metal source deployment guide](bare-metal.md): deployment without a panel or containers.
- [Deployment and scheduling guide](scheduling.md): scheduling, queues and operations rules.
