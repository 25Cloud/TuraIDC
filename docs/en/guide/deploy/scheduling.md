---
source: guide/deploy/scheduling.md
sourceHash: 5d1d858353114fd24bbf68e01c7fb2e64cfd80f8c0ac711d70cb251f57968e11
translatedAt: 2026-10-26
---

# Deployment and Scheduling Guide

## Purpose of This Document

- Describe the real deployment topology and scheduling model of the current production environment
- Aligned as of: `2026-09-13`
- For a **brand-new server deployment**, read `docs/guide/deploy/bt-panel.md` (BaoTa Panel) or `docs/guide/deploy/bare-metal.md` (native service deployment without a panel or containers) first
- For **containerized deployment (Docker Compose / 1Panel / CI automatic packaging)**, read `docs/guide/deploy/docker-1panel.md` first
- Companion documents:
  - `docs/README.md` (documentation entry point)
  - `docs/develop/local-development.md` (local startup)
  - `docs/develop/architecture.md` (overall architecture)

## 1. Current Production Topology

The project runs on a single BaoTa server with the following structure:

```
宝塔站点 (Nginx)
  ├── 官网/用户入口 -> frontend-user-v3-www/dist 静态资源
  ├── 用户控制台   -> frontend-user-v4-console/dist 静态资源
  ├── 管理端站点   -> frontend-admin-v3/dist 静态资源
  └── 后端 API 站点 -> backend/public (PHP-FPM)
                       │
                       ├── MySQL 8      (本机或独立实例)
                       ├── Redis        (缓存)
                       ├── 宝塔计划任务   每分钟执行一次 schedule:run
                       └── 宝塔守护进程   常驻 php artisan vnc:relay
```

Production environment characteristics:

- **Four independently deployed BaoTa sites**: the browser reaches the API domain directly from the three frontend sites; the frontend Nginx does not reverse-proxy the API
- **The backend entry runs through PHP-FPM**; neither `php artisan serve` nor `app:serve` is used
- **No resident Queue Worker**: a per-minute `schedule:run` drives three entry points: `scheduler:heartbeat` (the single clock source, dispatching scheduled tasks deduplicated by 15-minute slot), `queue:drain` (background subprocesses consuming the queue, creating one Worker per queue group across the three groups `provision`, the business group `referral,notification,coupon,default`, and the timing group `automation`; the drain lock guarantees the same queue is not consumed concurrently, and Workers exit automatically when idle), and `scheduler:liveness` (the heartbeat liveness probe, which can still raise alerts when the heartbeat is unhealthy)
- **VNC Relay runs independently as a resident process**: restarting the Relay does not block heartbeat, payments, new purchases, or renewals
- **Fulfillment runs synchronously after new-purchase and renewal payments**: the payment callback calls upstream provisioning/renewal directly, so it takes effect immediately after the user pays; only when the synchronous call fails does it fall back to the `provision` queue, where the per-minute heartbeat Worker retries — it no longer depends on queue polling latency

## 2. Scheduled Task Configuration

### 2.1 BaoTa Scheduled Task

In BaoTa Panel → Scheduled Tasks → Add:

- Task type: Shell script
- Execution period: every minute
- Script content:

```bash
cd /你的项目/backend
php artisan schedule:run >> /dev/null 2>&1
```

Notes:

- This schedule fires three things every minute at the same time: heartbeat dispatch (deduplicated by 15-minute slot), queue consumption (`queue:drain` spawning Workers by queue group in the background), and the liveness probe; while any queue is still being consumed, the drain lock only skips the duplicate Worker for that queue, and the remaining queue groups keep consuming every minute
- Do not configure another resident `queue:work` that covers the same queue, to avoid duplicate consumption; when low latency is required, evaluate it separately along queue boundaries
- Hard-killing timed-out task executions (converged through `--timeout` and `JobTimedOut`) depends on Linux `pcntl`/`SIGALRM` and has no effect in Windows development environments; timeout issues must be verified in a Linux environment, and on Windows long-running tasks are never terminated automatically
- Missed 15-minute heartbeat slots are not replayed automatically by default; recovery depends on the task's own idempotent compensation or a manual trigger from the admin console

### 2.2 VNC Relay Daemon

Add a resident command in the BaoTa process daemon or another process manager:

```bash
cd /www/wwwroot/你的项目/backend
php artisan vnc:relay
```

Before going live, confirm that `127.0.0.1:8100` is listening; when the Relay fails, the daemon restarts it automatically. `vnc:ensure-relay` is kept only as a manual health-check/troubleshooting command and is no longer registered in the heartbeat task.

### 2.3 Backend Schedule Content

The actual scheduling capability is defined by the real commands and route registrations. For the list of what is actually enabled, see:

- `backend/routes/console.php`
- `backend/app/Console/Commands/`
- Admin console "Schedule Overview": `GET /api/v2/admin/schedules/overview`

Typical tasks (the code is authoritative):

- `scheduler:heartbeat` / `queue:drain` / `scheduler:liveness`: every minute, the scheduling and queue backbone
- `tickets:cleanup-unused-upstream-uploads`: every minute, deletes upstream upload files that are past the retention period and no longer referenced by any ticket reply
- `open-api:prune-usage-logs`: weekly, purges API usage logs according to `open_api.usage_log_retention_days` (90 days by default)
- Automatic renewal checks, bill overdue handling, service expiry suspension and termination, upstream status sync, and the various log cleanups: dispatched by the heartbeat according to slot rules; see "Schedule Overview" in the admin console for the list

## 3. Deployment Process

### 3.1 Backend Deployment

```bash
# 项目根下切到 backend
cd backend

# 拉代码（宝塔一般自带 Git 钩子或手动 git pull）
git pull

# 安装依赖
composer install --no-dev --optimize-autoloader

# 数据库初始化/更新
# 新环境首次部署：使用 schema baseline
python scripts/install_db.py
# 后续增量更新：
php artisan migrate --force

# 清旧缓存
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# 生产缓存
php artisan config:cache
php artisan route:cache
```

> **Database initialization notes**:
>
> - `database/schema/mysql-schema.sql` is a complete structural snapshot of the current production database, containing 62 tables and the `migrations` record
> - Historical migration files have been archived to `database/migrations/_archive/` and are no longer executed one by one
> - Incremental migrations (`database/migrations/*.php`) are used for subsequent feature releases
> - At the end, `install_db.py` idempotently seeds through `SettingsSeeder::seed()`:
>   - System core default settings (the `settings` table, including `notification.email_enabled` / `sms_enabled` / `sms_template_code`)
>   - Default notification template data (the `notification_templates` table): the full email template set + the sms verification code template
>   - The schema baseline exports table structure only and contains no template data, **so template seeds must be executed through that Seeder**; never rely on migrations or manual seeding
>
> **Check the following after the first deployment (Admin backend → Site Settings → Notification master switch)**:
>
> - Email notifications / SMS notifications are off by default; turn them on in the admin backend as needed
> - The email/SMS plugins must be installed, configured, and bound under "Plugin Management", before going to "Notifications and APIs" to maintain the corresponding templates

### 3.2 Frontend Deployment

```bash
# 项目根目录：只安装一次 workspace 依赖（pnpm monorepo，四个 workspace）。
# --shamefully-hoist：多个 vite build（尤其 www 的 element-plus 深路径 import）
# 会从根 node_modules 解析依赖，必须扁平提升，否则构建报 load-fallback ENOENT。
# verify-deps-before-run=false：关闭 pnpm run 前的依赖检查，避免它重新 purge 掉 hoist 布局。
pnpm install --frozen-lockfile --shamefully-hoist
pnpm config set verify-deps-before-run false

# 每个前端构建时读取各自目录下的 .env 获取后端/前端地址，
# 构建三个完全独立的 dist，不复制任何文件到 backend/public。
pnpm run build:frontends
# 若在无 TTY 的 CI/脚本环境跑 build，需 CI=true（跳过 pnpm 的模块目录移除交互确认）。
CI=true pnpm run build:frontends
```

The output directories are `frontend-user-v3-www/dist`, `frontend-user-v4-console/dist`, and `frontend-admin-v3/dist`, each pointing to the root directory of its corresponding BaoTa site.

The VitePress documentation site is not one of the three frontends above; it must be built and published separately via `pnpm run build:docs`. For content maintenance, output directories, and release checks, see the [docs site maintenance guide](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/docs-web-maintenance.md).

> **Separate env files for dev and build**: local development (`pnpm run dev`) reads `.env.dev` in each frontend directory (the dev script uses `--mode dev`); a build (`pnpm run build:frontends`) reads `.env` in each frontend directory. Frontend builds do not depend on the backend environment file.
>
> Each frontend directory (`frontend-admin-v3`, `frontend-user-v3-www`, `frontend-user-v4-console`) needs two env files (both gitignored and never committed):
>
> - `.env.dev`: local development addresses (`127.0.0.1` ports for each frontend, local API)
> - `.env`: build addresses (production domain); fill in the real domain before packaging for deployment
>
> Before deployment, confirm that `VITE_API_BASE_URL`, `VITE_PUBLIC_SITE_URL`, `VITE_CONSOLE_SITE_URL`, and `VITE_SESSION_COOKIE_DOMAIN` in `.env` match production; a missing or wrong value makes the build point at the wrong addresses.
>
> The single source of truth for site addresses is the four public addresses in `backend/.env`: `backend/scripts/build_frontends.mjs` resolves them into `VITE_*` values injected into the build, and `--print-env` prints this set of variables on its own for reuse by other build entry points. That way the `.env` in each frontend directory is only a local convenience and will not drift from the online configuration.

**Difference in build configuration sources:**

- **Running a frontend build directly** (`pnpm run build`): each frontend directory reads its own `.env` (or `.env.production`) and uses only the configuration inside that directory.
- **Building uniformly through `pnpm run build:frontends`**: `backend/scripts/build_frontends.mjs` reads the four public addresses from `backend/.env`, resolves them, and injects them into each frontend build as `VITE_*` environment variables. That way the `.env` in each frontend directory is only a local convenience and will not drift from the online configuration.

`backend/scripts/build_frontends.mjs --print-env` can print this set of variables on its own for reuse by other build entry points (such as `inject_backend_site_env` in `deploy/deploy-www.sh`), ensuring every build entry point reads the same single source of truth for site addresses and does not contradict the actual entry point behavior.

```bash
# 只预览构建目标，不实际构建
pnpm run build:frontends:dry

# 只打印从 backend/.env 解析出的 VITE_* 站点地址（供部署脚本复用）
node backend/scripts/build_frontends.mjs --print-env
```

### 3.2.1 Console Address Under Single-Domain Subpath Deployment

The current live environment merges the marketing site, console, admin console, and API into the same domain, distinguished by subpath:

| Frontend       | Access path | Corresponding `backend/.env`                  |
| -------------- | ----------- | --------------------------------------------- |
| Marketing site | `/`         | `FRONTEND_URL=https://你的域名`               |
| Console        | `/console/` | `CLIENT_CONSOLE_URL=https://你的域名/console` |
| Admin console  | `/admin/`   | `ADMIN_URL=https://你的域名/admin`            |
| API            | `/api`      | `APP_URL=https://你的域名`                    |

In a single-domain deployment `VITE_CONSOLE_SITE_URL` **must include the `/console` subpath**. The console router base is exactly `/console/`; writing a bare domain without the subpath makes the marketing site's "Sign in / Free sign-up" jump to `https://你的域名/client/login` — that path lands on the marketing site SPA and can never reach the console login page.

For this reason `frontend-user-v3-www/vite.config.js` has a build guard: if `VITE_CONSOLE_SITE_URL` is empty, or is same-origin with `VITE_PUBLIC_SITE_URL` but has no subpath, `pnpm build` fails immediately. A misconfigured console address is exposed at build time, so no release ships with a broken link.

In a single-domain deployment, leave `CLIENT_SESSION_COOKIE_DOMAIN` empty (the login state is same-origin anyway); fill in the parent domain only when the login state must be shared across subdomains.

> **`CLIENT_CONSOLE_URL` must include `/console`**: the backend `AuthService::normalizeConfiguredUrl()`
> allows an address with a path (a prerequisite for single-domain deployment), and the admin
> impersonation link appends `/client/login-as` to that address. If you write a bare domain without
> the subpath, the marketing site login/registration jumps to
> `https://你的域名/client/login` (the marketing site SPA), and impersonation lands on the marketing site
> page as well. The admin console appends `/client/login-as` once more to the address delivered by the
> backend; both sides handle it as "base path preserved".

### 3.3 Key .env Items

The minimal `.env` entries to verify:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://api.你的域名` (API, `/media/*`, `/uploads/*`, VNC WebSocket)
- `FRONTEND_URL=https://www.你的域名` (for single-domain subpath deployment write `https://你的域名`, see 3.2.1)
- `CLIENT_CONSOLE_URL=https://console.你的域名` (for single-domain subpath deployment write `https://你的域名/console`)
- `ADMIN_URL=https://admin.你的域名` (for single-domain subpath deployment write `https://你的域名/admin`)
- `CLIENT_SESSION_COOKIE_DOMAIN=.你的域名` (set when the marketing site and console must share login state across subdomains; leave empty locally)
- `SESSION_SECURE_COOKIE=true` (set to `false` in an HTTP environment)
- `DB_*`: MySQL 8 connection
- `REDIS_*`: Redis connection
- `CACHE_STORE=redis`
- `SESSION_DRIVER=file` (current production policy)
- `QUEUE_CONNECTION=database` (current production policy)
- `TURAIDC_PROVISION_QUEUES=provision` (provisioning queue group; Worker timeout can reach 1200 seconds)
- `TURAIDC_BUSINESS_QUEUES=referral,notification,coupon,default` (business queue group; if an existing `.env` still contains `provision`, it is removed automatically at runtime, so nothing is consumed twice)
- `TURAIDC_SCHEDULE_QUEUE=automation` (scheduled task queue group)

All four public addresses may uniformly use HTTP or HTTPS; an HTTPS frontend cannot point at an HTTP API. CORS generates a precise allowlist automatically from the three frontend URLs, so no additional proxy is needed in the frontend Nginx.

Redis currently serves as the cache; do not switch sessions or queues to Redis without a dedicated plan and verification.

### 3.4 Trusted Proxy and Source IP Contract

The backend configures `trustProxies` in `backend/bootstrap/app.php`: it trusts only loopback and the RFC1918/ULA private ranges, and honors only `X-Forwarded-For`, `X-Forwarded-Port`, and `X-Forwarded-Proto` (it does not trust `X-Forwarded-Host`). `request()->ip()` relies on this configuration to resolve the real client IP, which directly affects:

- Audit fields (such as `last_login_ip`) and the "registration IP == referrer IP" judgment in referral risk control;
- The enable switch for `/upload_image` upstream attachment uploads, and the allowlist/rate-limit middleware (`TicketUpstreamUploadThrottle`). The endpoint is off by default; it must be enabled in the admin console before ticket transfer rules are configured. Uploads outside the allowlist are rejected by default.

The deployment must satisfy the following contract, otherwise the IP allowlist and rate limiting are bypassed or misjudged:

1. **The edge reverse proxy is the only public entry**: the backend API port (the BaoTa PHP-FPM site / container `8080`) may only be reached by the trusted reverse proxy and must not be exposed directly to the public internet or to untrusted private sources.
2. **The proxy must genuinely produce the source headers**: the external reverse proxy must carry `X-Forwarded-For` and `X-Forwarded-Proto`. A single trusted proxy should **reset `X-Forwarded-For` to `$remote_addr`** instead of appending with `$proxy_add_x_forwarded_for` as-is — otherwise clients can bring a forged XFF themselves and pollute `request()->ip()` directly. Appending is allowed only when multiple trusted proxy layers definitely exist and every layer sanitizes.
3. **Never add public proxy addresses to `trustProxies`**: the current configuration trusts only loopback and private ranges; if the actual entry proxy is not inside those ranges (for example an LB hosted on a public cloud), evaluate the exposure surface and narrow it to explicit entry IPs/CIDRs before changing the configuration.
4. The BaoTa PHP-FPM direct form (frontend Nginx does not reverse-proxy the API) produces no XFF by itself; the backend uses the `REMOTE_ADDR` received by PHP-FPM and is unaffected by the contract above, but it likewise cannot rely on XFF to obtain the client IP.

> Container deployment note: `deploy/docker/.env.example` exposes `API_PORT=8080` on all interfaces by default. Before going live, change it to bind only on the local host (`127.0.0.1:8080`) and let the host Nginx/1Panel reverse-proxy into it; otherwise private network sources can reach the API directly and forge XFF.

### 3.5 Measured Pitfalls of Incremental Updates

The following are problems actually hit during incremental updates (`git pull` + rebuilding dist) in the live environment.

#### 3.5.1 Frontends Must Go Through `pnpm run build:frontends`, Not One `pnpm run build` at a Time

Building one by one reads the `.env` / `.env.production` **of each frontend directory itself**, while the project source of truth is
the four public addresses in `backend/.env` (injected by `build_frontends.mjs`). Once the two drift, canonical, `og:url`, and the checkout return address in the output are all written with the stale values from the frontend directory.

Measured: the marketing site `.env.production` was `idc.25y.cn`, while `FRONTEND_URL` in `backend/.env` was
`next2.25y.cn`. After building one by one, `idc.25y.cn` appeared 4 times in the output and `next2.25y.cn` 0 times —
**the site returned HTTP 200 everywhere, but SEO and return addresses were all wrong**. After rebuilding with the standard script they went to zero.

After deployment, verify against the source of truth; do not just check whether the site is reachable:

```bash
grep -ro "$FRONTEND_HOST" frontend-user-v3-www/dist/ | wc -l   # 应 > 0
grep -ro "<其他域名>"   frontend-user-v3-www/dist/ | wc -l       # 应为 0
```

#### 3.5.2 Production Machines Have No `pnpm`, So the Standard Script Fails With ENOENT

`build_frontends.mjs` runs `spawn pnpm` internally. If the production PATH has only node and corepack
(the pnpm version is pinned by `packageManager` and present in the corepack cache, but `enable` was never run),
`pnpm run build:frontends` fails with `ENOENT: spawn pnpm`.

One-time fix:

```bash
corepack enable pnpm   # 生成 /usr/bin/pnpm shim
pnpm -v                # 确认版本与 packageManager 一致
```

Before `corepack enable`, the only option is building one by one with `corepack pnpm run build`, **but that is exactly
the approach 3.5.1 says bypasses address injection**; do not treat it as the norm.

#### 3.5.3 `git pull` Is Blocked by Untracked Files

Static assets placed by hand into `backend/public/media/console-panel/` to stop the bleeding in an emergency
conflicted with the same paths in a later commit, and the pull was rejected outright:

```
error: Untracked working tree files ... Please move or remove them before you merge.
```

Compare the contents first, then delete the working-tree copy and let git take over (delete only if the contents match):

```bash
sha1sum backend/public/media/console-panel/jquery.min.js
git show origin/main:backend/public/media/console-panel/jquery.min.js | sha1sum   # 两行一致才继续
rm -rf backend/public/media/console-panel && git pull --ff-only origin main
```

#### 3.5.4 Do Not Use `git checkout origin/main -- <file>` for Precise Sync

That command only changes working-tree file contents and **does not advance HEAD**. After hot-fixing a few files with it,
the production HEAD stays parked on a very old commit for a long time; when a full pull is finally needed, it brings every
other commit in between (virtual host panel, phone-number rebinding, marketing-site checkout bar, and so on) into production
all at once, and the deployment scope runs out of control.

**Countermeasure**: either pull fully with `git pull --ff-only`; or insist on precise sync, but know exactly how many commits
you owe and state clearly, before deploying, "which other people's commits will go live along with this one", so a human can decide.

#### 3.5.5 Back Up dist Before Building

```bash
TS=$(date +%Y%m%d-%H%M%S)
tar -czf /tmp/backup/www-dist-$TS.tar.gz frontend-user-v3-www/dist
```

Keep it under `/tmp/backup/`; to roll back, unpack it over the target.

#### 3.5.6 PHP Changes Do Not Require Restarting PHP-FPM

The local machine runs opcache with `validate_timestamps=On` and `revalidate_freq=2`, so synced PHP files take effect
automatically within 2 seconds. Verify with: `php -i | grep -i opcache`. A restart is needed only after changing `php.ini`,
an extension, or `bootstrap/cache`.

#### 3.5.7 With Multiple Servers, First Confirm Which One the Current SSH Connection Points At

When several resources are connected, remote commands hit the "currently active connection", which may not be the production
machine. The symptom is `cd /www/wwwroot/TuraIDC: No such file or directory`. Run `ssh_list` before each round of operations
to confirm the current connection, or use `ssh_connect_profile` to name one explicitly.

#### 3.5.8 Use nohup in the Background Plus Polling for Long Tasks

Remote commands have an upper timeout limit (30 seconds by default), so a build taking tens of seconds gets interrupted. The right approach:

```bash
nohup bash -c 'cd /www/wwwroot/TuraIDC && CI=true pnpm run build:frontends > /tmp/build.log 2>&1; echo "exit=$?" >> /tmp/build.log' > /dev/null 2>&1 &
sleep 100 && tail -10 /tmp/build.log     # 轮询直到出现 exit=
```

#### 3.5.9 Always grep the Build Output After Deployment

HTTP 200 only means the site opens; it does not mean the content is correct (3.5.1 is the case where everything was 200 but the
addresses were wrong). At minimum, verify three things when wrapping up:
the output contains the new keywords from this change, the old keyword count before the change is 0, and hardcoded domains match the `backend/.env` source of truth.

#### 3.5.10 Caches With a TTL Cause "Changed but Not Showing"

Responses such as `console/capabilities` are cached for 300 seconds, and settings groups for 600 seconds, so an administrator's
change does not take effect immediately, and stale values do not disappear after a redeployment either. Clear the corresponding
cache key by hand before verifying.

## 4. Local Startup Differences

Local development also uses a direct API connection: the three frontends run on `127.0.0.1:5175/5173/5174` and the API on
`127.0.0.1:8000`. `VITE_API_BASE_URL` in the frontend development environment already points at `http://127.0.0.1:8000/api`,
so CORS and the VNC WebSocket are exercised for real.

The backend brings up HTTP, Relay, and the queue together with one wrapper command:

```bash
cd backend
php artisan app:serve
# 等价于：HTTP + VNC Relay + 业务队列 Worker（queue:work 消费业务队列组）

# 如果本地想同时拉起调度，Relay 另行独立运行；
# 带调度时不再另起常驻 Worker，队列由每分钟 queue:drain 按队列组后台消费
php artisan app:serve --with-schedule --without-vnc
php artisan vnc:relay
```

For details see `docs/develop/local-development.md` and `backend/app/Console/Commands/ServeBackendStackCommand.php`.

## 5. Common Operations

### 5.1 Check Schedule Status

- The BaoTa scheduled task page shows script logs
- `backend/storage/logs/laravel.log`
- Admin console `/api/v2/admin/schedules/overview` and `/api/v2/admin/logs/tasks`

### 5.2 Trigger a Schedule Run Manually

```bash
cd backend
php artisan schedule:run
```

### 5.3 Run Queue Consumption Once Manually

Normally unnecessary. Only when investigating "the queue has a backlog but is not being consumed", run it manually per target queue:

```bash
cd backend
php artisan queue:work --once --queue=provision
php artisan queue:work --once --queue=referral,notification,coupon,default
php artisan queue:work --once --queue=automation
```

### 5.4 Clear the Failed Queue

```bash
cd backend
php artisan queue:retry all     # 重试全部失败
php artisan queue:flush         # 清空失败队列（慎用）
```

### 5.5 Refresh OPcache / Route / Config Caches

After going live, if routes or configuration do not take effect, run in order:

```bash
cd backend
php artisan route:clear && php artisan config:clear && php artisan cache:clear
php artisan route:cache && php artisan config:cache
# 重启 PHP-FPM（宝塔面板 → PHP → 重启）
```

### 5.6 PHP Disabled Functions Issues

When Composer reports errors such as `Call to undefined function putenv()`, first check the disabled functions in BaoTa's PHP and
the configuration differences between CLI and FPM; this repository keeps no separate fix guide.

## 6. Monitoring and Alerting

The Sentry SDK is already integrated (activated by configuring `SENTRY_LARAVEL_DSN`); interim monitoring relies on:

- Laravel logs (`backend/storage/logs/laravel.log`)
- The BaoTa monitoring panel
- The `/api/v2/admin/logs/*` log pages in the admin console
- Health check endpoints: `/api/health` (liveness), `/api/ready` (readiness, checking DB/Cache/Storage/Scheduler)

`/api/ready` reports heartbeat liveness, task run status, and queue status separately; the heartbeat processes only the current
15-minute slot, and missed slots are not replayed automatically by default, so recovery depends on the task's idempotent
compensation or a manual trigger from the admin console.

Recommended remediation directions:

- Configure the Sentry DSN and activate error tracking
- Alert on critical task failures (automatic renewal, payment callbacks, real-name verification callbacks)
- Enable `long_query_time=1` in the database slow query log

## 7. Rollback Strategy

- Backend: revert the code + `composer install` + `php artisan migrate:rollback` (only when the migration is reversible)
- Frontend: roll back per site, restoring the old `dist` directly
- Database: never run `migrate:rollback` on irreversible migrations in production; restore from backup instead
- Before rolling back critical business paths (payments, provisioning, referral commission), freeze `schedule:run` first, so no new asynchronous actions are produced while the rollback is in progress

## 8. File Permissions (After BaoTa Deployment)

```bash
sudo chown -R www:www storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 public/uploads public/media
# .env 文件必须严格权限，防止凭据泄露
sudo chmod 600 .env
```

## 9. Database Backup

The project provides a MySQL scheduled backup script, `backend/scripts/backup_mysql.sh`, which reads the database connection information from `.env`.

### 9.1 BaoTa Scheduled Task Configuration

In BaoTa Panel → Scheduled Tasks → Add:

- Task type: Shell script
- Execution period: every day at 3:00 AM
- Script content:

```bash
cd /你的项目/backend
bash scripts/backup_mysql.sh
```

### 9.2 Backup Configuration

| Environment variable    | Default value                   | Description                             |
| ----------------------- | ------------------------------- | --------------------------------------- |
| `BACKUP_DIR`            | `backend/storage/backups/mysql` | Directory where backup files are stored |
| `BACKUP_RETENTION_DAYS` | 14                              | Number of days to retain backups        |

### 9.3 Manual Backup

```bash
cd backend
bash scripts/backup_mysql.sh
```

### 9.4 Restore a Backup

```bash
gunzip -c /path/to/backup.sql.gz | mysql -u用户名 -p密码 数据库名
```

## 10. Production OPcache Configuration

Enabling OPcache with a tuned configuration is strongly recommended for Laravel production environments. In BaoTa Panel → PHP → configuration file, add or modify:

```ini
[opcache]
opcache.enable=1
opcache.enable_cli=1
; 根据项目大小调整，建议 256MB
opcache.memory_consumption=256
; Laravel 项目约 8000+ 文件，建议 20000
opcache.max_accelerated_files=20000
; 生产环境关闭时间戳验证，部署后需手动重启 PHP-FPM 或清除 OPcache
opcache.validate_timestamps=0
; 禁用文件保存，减少 I/O
opcache.save_comments=1
opcache.fast_shutdown=1
; 仅缓存项目文件，排除 vendor 中不常用的文件
opcache.blacklist_filename=/www/server/php/82/etc/opcache_blacklist.txt
```

**PHP-FPM must be restarted after deployment** for OPcache and the route/config caches to take effect:

```bash
# 宝塔面板 → PHP → 重启
# 或命令行
sudo systemctl restart php-fpm-82
```

**OPcache blacklist file** (optional, excludes files that do not need caching):

```text
# /www/server/php/82/etc/opcache_blacklist.txt
/vendor/phpunit/
/vendor/mockery/
/vendor/phpspec/
/vendor/phpstan/
```

## Related Documents

- [Startup guide](../../develop/local-development.md): local startup and verification.
- [Nginx rewrite rules for the four sites](../maintain/nginx-rules.md): site rewrite rules.
- [Log archiving and MySQL maintenance](../maintain/log-archive.md): log and database operations.
- [Engineering pitfalls](../../develop/pitfalls.md): measured pitfalls in development, verification, and commit steps.
