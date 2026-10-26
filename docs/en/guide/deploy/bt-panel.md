---
source: guide/deploy/bt-panel.md
sourceHash: 70479deb5dddee1c407ef8f96bb3e3a2c8d1b315b8b61232815cbe06d2d00c72
translatedAt: 2026-10-26
---

# BaoTa Panel Deployment Guide

A complete deployment walkthrough for a brand-new server. If you already operate a production environment, go straight to the [Deployment and Scheduling Guide](scheduling.md).

> 🧭 **Migrating from ZJMF Finance**: If you are running ZJMF Finance and want to switch to TuraIDC, read [Migrating from ZJMF Finance](../migrate/zjmf-finance.md) first, complete the 10-step data migration and upstream integration, and only then apply the site configuration in this guide.

---

## 1. Prerequisites

### 1.1 Installing BaoTa Panel

BaoTa Panel must already be installed. If it is not, refer to the official documentation; a Linux system is recommended (CentOS 7+ / Ubuntu 20.04+ / Debian 10+).

After logging in to BaoTa Panel, open **App Store** and install the following runtimes:

| Software                | Version requirement | Notes                                                                                                                                                          |
| ----------------------- | ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nginx                   | 1.20+               | Web server                                                                                                                                                     |
| PHP                     | 8.3+                | The installation wizard's environment check hard-requires PHP >= 8.3; installing 8.2 is rejected at the check step                                             |
| MySQL                   | 8.0 (5.7.8 minimum) | Database; on 5.7 set `explicit_defaults_for_timestamp=ON` in my.cnf (avoids implicit ON UPDATE CURRENT_TIMESTAMP, see [deployment.md](generic.md) for details) |
| Redis                   | 7.0+                | Cache                                                                                                                                                          |
| phpMyAdmin              | —                   | Database management (optional)                                                                                                                                 |
| Node.js Version Manager | —                   | Manages Node.js versions                                                                                                                                       |

### 1.2 Key PHP Settings

**Install PHP extensions** (PHP → Install Extension):

- `fileinfo`
- `redis`
- `opcache`
- `pdo_mysql`

**Unblock disabled functions** (PHP → Disabled Functions → remove the following functions):

- `putenv`
- `proc_open`
- `symlink`

Restart PHP after the change, then run `php artisan app:doctor` to re-verify. The source of truth for the required extensions and disabled functions is
`backend/app/Support/EnvironmentRequirements.php`; this list is only its manual pre-configuration for this environment.

**PHP CLI version**: make sure the PHP used by the BaoTa command line matches the site (BaoTa → Websites → PHP CLI version).

### 1.3 Node.js Configuration

Install **Node.js 20.19+** in the Node.js Version Manager (the minimum version constrained by the project's package.json).

### 1.4 DNS, Network, and Accounts

- Add DNS records for the four domains `api`, `www`, `console`, and `admin` first, and confirm that all of them resolve to the same server.
- The firewall exposes only `80` and `443` to the public internet; MySQL, Redis, and port `8100` of the VNC Relay must listen on the intranet or `127.0.0.1` only, and must not be opened to the public internet.
- Before an HTTPS deployment, request and bind certificates for the four sites in BaoTa. The four public addresses must be uniformly HTTP or uniformly HTTPS; a production environment should use HTTPS.
- Note the user that BaoTa sites run as (usually `www`). Afterwards, grant write permission on `backend/storage` and `backend/bootstrap/cache` to that user only.

---

## 2. Deployment Overview

The production topology is as follows:

```
宝塔 Nginx
  ├── 站点1: 官网/用户入口 -> frontend-user-v3-www/dist （静态；公开 SEO 路径转发到 API 站点 PHP 动态渲染）
  ├── 站点2: 用户控制台   -> frontend-user-v4-console/dist（静态）
  ├── 站点3: 管理端       -> frontend-admin-v3/dist      （静态）
  └── 站点4: 后端 API     -> backend/public              （PHP-FPM）
                              ├── MySQL 8
                              ├── Redis
                              └── 宝塔计划任务（每分钟 php artisan schedule:run）
```

**Key points**:

- The backend runs through PHP-FPM only; it does not use `php artisan serve`
- There is no long-running queue worker; queue consumption is folded into `schedule:run` (every minute)
- The four sites are deployed independently and may be assigned different domains/ports
- **Official website SEO**: the public pages of the official website (home, products, landing pages, notices/help and their detail pages) are forwarded from the official website's rewrite rules to the API site, where Laravel reads the database and dynamically renders complete HTML (site name/logo/meta/body); sitemap.xml / robots.txt are generated dynamically by Laravel

Database rollout has two mutually exclusive paths:

| Scenario                                                    | Entry point                     | Notes                                                                                                                                |
| ----------------------------------------------------------- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| First deployment on an empty database                       | `python3 scripts/install_db.py` | Imports `database/schema/mysql-schema.sql`, runs incremental migrations, writes default settings, and initializes the administrator. |
| Publishing a new version to an existing production database | `php artisan migrate --force`   | Runs only the incremental migrations that are not yet recorded; take a database backup before deploying.                             |

On a first deployment, do not run `php artisan migrate --force` manually beforehand, and do not use `install_db.py --reset` against a production database.

---

## 3. Creating BaoTa Sites

### 3.1 Create the Backend API Site

BaoTa → Websites → Add Site:

| Config option  | Value                                                                                                |
| -------------- | ---------------------------------------------------------------------------------------------------- |
| Domain         | `api.你的域名.com`                                                                                   |
| Root directory | `你的项目路径/backend/public`                                                                        |
| PHP version    | PHP 8.3 (must be ≥ 8.3.36, the patched build containing the CVE-2026-109618 / CVE-2026-109619 fixes) |
| Database       | Create new (import later)                                                                            |

### 3.2 Create the Frontend Sites

Create three frontend sites. **Set the site directory to the frontend application directory, never directly to `dist`** (for the reason see §6.1:
BaoTa generates an undeletable `.user.ini` in the site directory, and if it lands inside `dist` the frontend build will always fail):

| Site                          | Domain                 | Site directory                      | Run directory |
| ----------------------------- | ---------------------- | ----------------------------------- | ------------- |
| Official website / user entry | `www.你的域名.com`     | `项目路径/frontend-user-v3-www`     | `/dist`       |
| User console                  | `console.你的域名.com` | `项目路径/frontend-user-v4-console` | `/dist`       |
| Admin panel                   | `admin.你的域名.com`   | `项目路径/frontend-admin-v3`        | `/dist`       |

---

## 4. BaoTa Site and Rewrite Rule Configuration

All four sites use the default Nginx configuration that BaoTa generated when it created them. Do not replace or paste a complete `server {}` in "Config File", or you will overwrite the SSL, PHP-FPM, and log settings BaoTa generated.

After completing the following settings in BaoTa Panel, edit only each site's "Settings → Rewrite Rules":

| Site             | Site directory             | Run directory | BaoTa Panel settings         |
| ---------------- | -------------------------- | ------------- | ---------------------------- |
| Official website | `frontend-user-v3-www`     | `/dist`       | Static site; request SSL.    |
| User console     | `frontend-user-v4-console` | `/dist`       | Static site; request SSL.    |
| Admin panel      | `frontend-admin-v3`        | `/dist`       | Static site; request SSL.    |
| API              | `backend/public`           | Leave empty   | Select PHP 8.3; request SSL. |

HTTPS redirects and certificates are configured on BaoTa's "SSL" page; in a plain HTTP environment do not enable Force HTTPS, and set `SESSION_SECURE_COOKIE=false`. The four public addresses must use the same protocol.

### 4.1 Backend API Site Rewrite Rules

Paste the following in full into the API site's "Rewrite Rules":

```nginx
# VNC WebSocket 仅由 API 站点转发到内部 Relay。
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
```

When VNC remote control is not enabled for the project, you can delete the entire `location` block for `/ws/vnc` and keep the Laravel fallback rule. Leave BaoTa's default PHP handling block unchanged.

> Note: API request paths connect directly to PHP-FPM, and Laravel relies on `REMOTE_ADDR`, so the `X-Forwarded-*` headers above do not affect it (those headers are only sent to the VNC Relay). If you instead put a host-level Nginx/1Panel reverse proxy in front of the API port, you must follow the [trusted proxy and source IP contract](scheduling.md): a single trusted proxy resets `X-Forwarded-For` to `$remote_addr`, and must not pass through client-forged headers unchanged.

### 4.2 Rewrite Rules for the Three Frontend Sites

The user console and the admin panel are pure Vue History-routing sites. Paste this into "Rewrite Rules":

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

**The official website** needs the fallback above plus forwarding of public SEO paths to the API site (Laravel reads the database and dynamically renders complete HTML). The complete rewrite rules are:

```nginx
# SEO 动态渲染：公开路径转发到 API 站点（Laravel 读库渲染 title/meta/正文，
# 站名与 Logo 实时取自数据库）。将 api.你的域名.com 换成实际 API 域名。
location = / {
    proxy_pass http://127.0.0.1/seo/www;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location ~ ^/(robots\.txt|sitemap\.xml)$ {
    proxy_pass http://127.0.0.1;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 落地页 / 关于 / 条款 / 隐私 / 开源许可 / 产品列表（单段路径，含尾斜杠变体）
location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|open-source|products)/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 公告/帮助列表与详情（/notices、/notices/123、/help、/help/123，含尾斜杠）
location ~ ^/(notices|help)(/[0-9]+)?/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 产品详情（/products/123；多段购买页路径保持 SPA 静态回退）
location ~ ^/products/[0-9]+/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 其余单段路径转发后端渲染：已登记路径返回可索引的完整 HTML，
# 未登记路径返回品牌 404 壳 + HTTP 404，避免爬虫把 SPA 裸壳 200 当可索引页面。
# 前提：官网自己的单段路由须先在后端 SeoRenderService::resolvePage() 登记，
# 否则登录 / 注册 / 购物车这类路径会被转成 404 壳。
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
```

> Internal forwarding goes over `127.0.0.1:80` (HTTP). If the API site has Force HTTPS enabled in BaoTa's "SSL" settings, port 80 will 301-redirect and block internal forwarding; either allow the local host in the API site's Nginx configuration (add an `if ($remote_addr != 127.0.0.1) { return 301 ...; }` guard to rules such as `location = /`), or change the `proxy_pass` targets above to `https://api.你的域名.com/...` (BaoTa already has the certificate configured, so the same-host loopback handshake succeeds). The backend already trusts loopback/private-network proxies (`trustProxies` in `bootstrap/app.php`), so `X-Forwarded-Proto` passes https through correctly.

Do not add proxies for `/api`, `/uploads`, `/media`, or `/ws/vnc` on the three frontend sites. Browsers reach the API domain directly; `/vnc/vnc.html` is a static build artifact of the console, and that rule matches it directly.

### 4.3 Settings Outside Rewrite Rules

- SSL certificates, Force HTTPS, and the site root directory: set on BaoTa's "Websites" page.
- The API's PHP version and PHP-FPM: the PHP settings of the BaoTa site.
- Upload size: adjust both `upload_max_filesize` and `post_max_size` in the BaoTa PHP settings; adjust the site-level Nginx upload limit through the matching panel setting only when you actually encounter a 413.
- After saving the rewrite rules, use BaoTa's Nginx configuration check and reload; there is no need to edit Nginx configuration files by hand.

---

## 5. Backend Deployment

### 5.1 Upload the Code

Upload the project code to the server, for example `/www/wwwroot/turaidc`.

Recommended approaches:

- **Git clone**: BaoTa → App Store → Git plugin, or `git clone` manually
- **BaoTa file upload**: upload an archive and extract it

### 5.2 Configure .env

```bash
cd /www/wwwroot/你的项目/backend
cp .env.example .env
```

Edit `.env` and change the following required values:

```ini
APP_NAME="图拉云"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                            # 首次初始化脚本会在为空时生成
APP_URL=https://api.你的域名.com
FRONTEND_URL=https://www.你的域名.com
CLIENT_CONSOLE_URL=https://console.你的域名.com
ADMIN_URL=https://admin.你的域名.com
CLIENT_SESSION_COOKIE_DOMAIN=.你的域名.com
SESSION_SECURE_COOKIE=true
INSTALL_ADMIN_PASSWORD=请设置至少12位强密码

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=你的数据库名
DB_USERNAME=你的数据库用户
DB_PASSWORD=你的数据库密码

CACHE_STORE=redis
QUEUE_CONNECTION=database
SESSION_DRIVER=file

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=你的Redis密码
```

`INSTALL_ADMIN_PASSWORD` is the temporary setting required to initialize the default administrator during the first initialization; in production it must not be empty, must not use the default value, and must be at least 12 characters long. Store it through a controlled channel; do not commit `.env` or write the actual password into scheduled tasks, Git, or this document.

Optional settings for official website SEO dynamic rendering (setting them explicitly is recommended for source deployments; see `config/idc.php → idc.seo` for the defaults):

```ini
# 官网公开地址，用于 canonical / sitemap / JSON-LD；默认取 APP_URL
SEO_SITE_URL=https://www.你的域名.com
# 官网 index.html 模板：源码部署直接读本机前端产物文件（零网络依赖）
SEO_FRONTEND_SHELL_URL=file:///www/wwwroot/你的项目/frontend-user-v3-www/dist/index.html
# shell 模板 / 页面渲染结果缓存秒数（默认 600 / 300）
SEO_SHELL_CACHE_TTL=600
SEO_CACHE_TTL=300
```

> `file://` reads the file directly, so the path must match the official website's site root (`frontend-user-v3-www/dist`); the HTTP form (such as `http://127.0.0.1:端口/index.html`) also works, but that port must return the **original index.html** (not content redirected by Force HTTPS or rewritten by the fallback). After the frontend is rebuilt, the new artifacts take effect at the latest after `SEO_SHELL_CACHE_TTL` seconds.

When the frontends are built centrally from the repository root, `APP_URL`, `FRONTEND_URL`, `CLIENT_CONSOLE_URL`, and `ADMIN_URL` are injected from `backend/.env` and override the same-named defaults in each frontend directory. Therefore complete the four public address settings above before building the frontends.

### 5.3 Install Backend Dependencies

```bash
cd /www/wwwroot/你的项目/backend

# 安装依赖（生产环境跳过 dev）
composer install --no-dev --optimize-autoloader
```

### 5.4 Initialize an Empty Database

Run this only against an empty database that does not yet carry business data. The script generates `APP_KEY` when it is missing, then imports the schema baseline, runs incremental migrations, and initializes the default settings and the administrator:

```bash
cd /www/wwwroot/你的项目/backend
python3 scripts/install_db.py
```

Do not additionally run `php artisan key:generate` or a first `php artisan migrate --force`; the script above already completes these steps in the correct order. The database account the script uses needs permission to create the target database, import the table schema, and run migrations.

### 5.5 Publish to an Existing Database

For an existing production database, complete a restorable backup first, then run:

```bash
cd /www/wwwroot/你的项目/backend

# 仅执行未记录的增量迁移
php artisan migrate --force

# 清缓存并生成生产缓存
php artisan optimize:clear
php artisan config:cache && php artisan route:cache
```

Do not use `migrate:fresh`, `migrate:reset`, or `install_db.py --reset` on a production database.

### 5.6 Set File Permissions

```bash
cd /www/wwwroot/你的项目/backend

sudo chown -R www:www storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 public/uploads public/media
```

### 5.7 Restart PHP

BaoTa Panel → App Store → PHP → Restart, so the production cache takes effect.

---

## 6. Frontend Deployment

### 6.1 Build the Frontends

Once the Node.js version meets the requirement, install the workspace dependencies once and build everything centrally from the project root:

```bash
cd /www/wwwroot/你的项目

# 先校验 backend/.env 中注入的四个公开地址和协议是否一致，不生成文件。
# 注意不要写成 `-- --dry-run`：pnpm 9+ 会把 `--` 原样透传给脚本，
# build_frontends.mjs 会抛「不支持的参数：--」。
pnpm run build:frontends --dry-run

# --shamefully-hoist：本项目依赖根 hoist 布局（如 element-plus），避免 monorepo 依赖布局不一致导致构建失败；
# --config.verify-deps-before-run=false：跳过 pnpm 10+ 默认的依赖预校验，适用于宝塔等先 install 再构建的部署流程。
# CI=true：无 TTY 的部署环境下，pnpm 11 清理 node_modules 前会等待交互确认并直接中止
#（ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY），设置后自动放行。
CI=true pnpm install --frozen-lockfile --shamefully-hoist --config.verify-deps-before-run=false
pnpm run build:frontends
# 产物仍分别位于三个前端目录的 dist/，不会写入 backend/public。
```

> **Do not point the BaoTa site root directly at `dist/`.** BaoTa's cross-site attack protection generates `.user.ini` in the site directory
> and sets the immutable `chattr +i` attribute on it; if the site root is `dist/`, emptying the output directory during a Vite build collides
> with this undeletable file and reports `ENOTDIR: not a directory, scandir '.../dist/.user.ini'`, so the build always fails.
> The correct approach is the same as for the API site: set "Site directory" to the application directory (such as `项目路径/frontend-admin-v3`) and
> "Run directory" to `/dist`, so `.user.ini` lands outside `dist`.

> If you deploy static assets on a separate CDN domain, configure `VITE_ADMIN_ASSET_BASE_URL` / `VITE_CONSOLE_ASSET_BASE_URL` / `VITE_WWW_ASSET_BASE_URL` in each project's `.env`. By default assets are published under the root path `/`.

### 6.2 Verify the Sites

After the build completes, open each of the four site domains in a browser and confirm that pages load normally and API requests work.

---

## 7. Scheduled Task Configuration

BaoTa Panel → Scheduled Tasks → Add:

- **Task type**: Shell script
- **Task name**: `Laravel 调度`
- **Execution cycle**: every minute
- **Script content**:

```bash
cd /www/wwwroot/你的项目/backend
php artisan schedule:run >> /dev/null 2>&1
```

> This scheduled task drives heartbeat dispatch every minute (deduplicated per 15-minute slot), `queue:drain` background queue consumption (separate workers for the three queue groups: `provision`, the business group `referral,notification,coupon,default`, and the scheduled group `automation`), and the liveness probe. Do not configure an additional `queue:work` covering the same queues, or consumption will be duplicated.

Also keep the following permanently resident in BaoTa's process daemon:

```bash
cd /www/wwwroot/你的项目/backend
php artisan vnc:relay
```

Confirm that `127.0.0.1:8100` is listening before deploying new code; the daemon restarts the Relay automatically, and `vnc:ensure-relay` is only for manual health checks.

The `php` in the scheduled task must be the same PHP 8.3 CLI as the API site. If BaoTa's default CLI version differs, replace `php` in the command with the actual path, for example `/www/server/php/83/bin/php`.

---

## 8. Importing a Legacy Database (Data Migration Only)

A brand-new empty database is already initialized by `install_db.py` in Section 5. The following steps are only for importing data from a legacy database that has a dedicated migration plan; before running them you must verify the target database and create a separate backup:

```bash
cd /www/wwwroot/你的项目/backend
python3 scripts/migrate_legacy_dump.py --dump "你的dump文件.sql" --dry-run
# 确认无误后执行
python3 scripts/migrate_legacy_dump.py --dump "你的dump文件.sql"
```

> Be sure to confirm the target database, the backup, and `.env` before running. The migration script modifies data, so take a backup first.

---

## 9. FAQ

### 9.1 502 Bad Gateway

The usual cause is that PHP-FPM is not running or the API site has no available PHP version selected:

- Check whether PHP is running (BaoTa → App Store → PHP → Status)
- Re-select PHP 8.3 in the API site's "PHP version", then restart the corresponding PHP service
- Do not hand-edit the `fastcgi_pass` socket configuration BaoTa generated

### 9.2 Page Refresh Returns 404

The frontend SPA site is missing the Vue Router History-mode fallback rule. Make sure the corresponding site's Nginx configuration contains:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### 9.3 Composer Reports `putenv()` and Similar Functions as Undefined

BaoTa's PHP disabled-function list blocks certain functions. Go to BaoTa → App Store → PHP → Disabled Functions, remove `putenv`, `proc_open`, and `symlink`, then restart PHP.

### 9.4 Uploaded Files Are Too Large

Increase both `upload_max_filesize` and `post_max_size` in BaoTa's "PHP → Config Modification", and restart PHP. If HTTP 413 is still returned, adjust the upload limit in the BaoTa site settings; do not replace the complete Nginx configuration just for this item.

### 9.5 Scheduled Tasks Do Not Run

- Check whether the BaoTa scheduled task log looks normal
- Run `php artisan schedule:run` manually to test
- Confirm that `QUEUE_CONNECTION=database`, `TURAIDC_BUSINESS_QUEUES`, and `TURAIDC_SCHEDULE_QUEUE` are configured correctly in `.env`, and that a separate Relay process is listening on `127.0.0.1:8100`

### 9.6 Route or Config Updates Do Not Take Effect

After going live, run the following in order:

```bash
cd /www/wwwroot/你的项目/backend
php artisan route:clear && php artisan config:clear && php artisan cache:clear
php artisan route:cache && php artisan config:cache
```

Then restart PHP-FPM (BaoTa → PHP → Restart).

---

## 10. Operations Reference

Detailed instructions for the following day-to-day operations are in the [Deployment and Scheduling Guide](scheduling.md):

- Viewing scheduler runs
- Triggering a scheduler run / queue consumption manually
- Clearing failed queues
- Rollback strategy
- Monitoring and alerting recommendations

---

## 11. Checklist

Confirm each item after deployment:

- [ ] All four sites are reachable over HTTPS
- [ ] The backend API returns valid JSON (open `https://api.你的域名.com/api/health`)
- [ ] The readiness check returns HTTP 200 (open `https://api.你的域名.com/api/ready`; this check verifies database, cache, storage, and scheduler status)
- [ ] Refreshing frontend pages does not return 404
- [ ] The admin panel accepts the administrator account set separately during the first initialization
- [ ] The BaoTa scheduled task has been added and runs normally
- [ ] PHP `APP_DEBUG=false`
- [ ] All passwords in `.env` are strong passwords
- [ ] Redis is reachable (`redis-cli ping` returns PONG)
- [ ] `storage` and `bootstrap/cache` are owned by `www:www` and writable
- [ ] Database migrations have run without errors

## Related Documents

- [Deployment Guide](generic.md): general deployment steps.
- [Nginx Rewrite Rules for the Four Sites](../maintain/nginx-rules.md): site rewrite rules.
- [Deployment and Scheduling Guide](scheduling.md): scheduler and queue configuration.
