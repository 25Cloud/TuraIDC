---
status: current
updated: 2026-10-16
owner: ops
source: guide/maintain/upgrade.md
sourceHash: 65a81737509953a27a6c5be081f5d729aaa7250b8f52d7f051682128f5162d83
translatedAt: 2026-10-26
---

# Version Upgrade

This article is for site operators and operations staff responsible for production servers. It describes the complete procedure for upgrading a running TuraIDC to a new version: pre-upgrade checks, code and dependency updates, migrations, frontend rebuild, cache and queue reload, verification, and rollback on failure. For differences between deployment methods, see the [Deployment and Scheduling Guide](../deploy/scheduling.md), the [Bare-Metal Source Deployment Guide](../deploy/bare-metal.md), and the [Docker / 1Panel Deployment Guide](../deploy/docker-1panel.md).

## 1. Pre-Upgrade Checks

Complete these in order; do not start if any item fails.

| Check item               | Command / location                                                 | Pass criteria                                     |
| ------------------------ | ------------------------------------------------------------------ | ------------------------------------------------- |
| Database backup          | See [Backup and Restore](backup.md)                                | The backup file is non-empty and can be extracted |
| Frontend artifact backup | See the command below                                              | One copy of each of the three `dist` directories  |
| Current version trace    | `git log --oneline -1`, `php artisan migrate:status \| tail -5`    | Record the commit hash and the last migration     |
| Free disk space          | `df -h`                                                            | Enough space for one full build plus a backup     |
| Production config        | `node backend/scripts/production_preflight.mjs --env=backend/.env` | All `[PASS]`, no `[FAIL]`                         |

```bash
TS=$(date +%Y%m%d-%H%M%S)
tar -czf /tmp/backup/dist-$TS.tar.gz \
  frontend-user-v3-www/dist frontend-user-v4-console/dist frontend-admin-v3/dist

cd backend
composer check-platform-reqs --no-dev   # 只校验版本与扩展，不装包
php artisan app:doctor                  # PHP 版本、必需扩展、禁用函数
```

The source of truth for the `app:doctor` checks is `backend/app/Support/EnvironmentRequirements.php`. Three compatibility notes:

- The minimum PHP version is constrained by code (the `php` constraint in `composer.json` and `EnvironmentRequirements::MIN_PHP_VERSION`); a major PHP version upgrade must be done separately from the code upgrade and verified separately.
- The `php` used by scheduled tasks must be the same PHP CLI as the API site. The default CLI version in BaoTa Panel often differs from the site's; change it to an absolute path when necessary (e.g. `/www/server/php/83/bin/php`), otherwise the new code will execute on the old interpreter.
- `production_preflight.mjs --network` actually probes TLS, `/api/ready`, and CORS on the four public addresses, and can be re-run after the upgrade (optional `--min-cert-days=`, `--json`).

## 2. Update the Code

```bash
cd /www/wwwroot/你的项目      # 换成你的实际部署目录
git status --short            # 先看清工作区有什么
git pull --ff-only origin main
git log --oneline -1          # 确认 HEAD 已推进到目标提交
```

Two practices you must avoid:

- **Do not use `git checkout origin/main -- <file>` for precise syncing**. It only changes working tree content and does not advance HEAD; doing this over the long term leaves the production HEAD stuck on a very early commit, and the next full pull brings every intermediate commit by others into production at once.
- **Do not delete working tree files just to make the pull pass**. `git pull` is blocked by untracked files with the same name (`error: Untracked working tree files ...`). Compare the content first and delete only when identical:

```bash
sha1sum backend/public/media/console-panel/jquery.min.js
git show origin/main:backend/public/media/console-panel/jquery.min.js | sha1sum   # 两行一致才继续
rm -rf backend/public/media/console-panel && git pull --ff-only origin main
```

First run the same commit on staging or locally to confirm which commits this release carries, then decide on the release window.

## 3. Install Dependencies

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

You must re-run this whenever `composer.lock` changes, otherwise the new code still calls the old dependency versions. When running as root, prefix the command with `COMPOSER_ALLOW_SUPERUSER=1`. You do not need to clear `bootstrap/cache` manually afterwards; the cache rebuild in section 6 covers it. **Do not run `php artisan key:generate`**: `APP_KEY` is used to decrypt existing ciphertext (ID card and key-type fields), and replacing it makes the already encrypted data permanently unreadable; `install_db.py` generates the key only once, when it is empty.

## 4. Run Database Migrations

Migration files are **add-only**: `up()` only creates tables, adds columns, and adds indexes; it does not drop tables, columns, or indexes. A few migrations do contain `dropColumn` / `dropIndex` in `down()`, but that is for local test databases and is not executed in production (for example, `2026_08_29_000001_add_secret_hash_index_to_api_keys.php` explicitly does not drop the index in `down()`).

```bash
cd backend
php artisan migrate:status        # 只读，看哪些还没跑
php artisan migrate --force       # 生产必须带 --force
php artisan migrate:status | grep -i pending || echo "无待执行迁移"
```

Rules:

- **Finish the backup in section 1 before migrating**. Once a migration has landed in the database, restoring from backup is the only way back.
- Do not run `migrate:fresh`, `migrate:reset`, or `migrate:rollback` on the production database, and do not run `python3 scripts/install_db.py --reset` on a database that carries business data.
- `database/schema/mysql-schema.sql` is used only for initializing an empty database; editing it by hand is forbidden.
- If a migration fails, do not retry it repeatedly. Read the failing statement and table name in `backend/storage/logs/laravel.log` first, determine whether it is a schema conflict or a data conflict, and take the rollback path in section 8 if necessary.

## 5. Rebuild Frontend Artifacts

The three frontends are pure static sites with addresses injected at build time, so an upgrade requires a rebuild, and **it must go through the unified entry point**:

```bash
cd /www/wwwroot/你的项目
pnpm install --frozen-lockfile --shamefully-hoist
CI=true pnpm run build:frontends
```

- `pnpm run build:frontends` resolves the `VITE_*` values for the build from the four public addresses in `backend/.env`, and is the single source of truth for site addresses.
- Running `pnpm run build` one by one instead reads each frontend directory's own `.env`; once the two drift apart, the canonical, `og:url`, and checkout return addresses in the artifacts are all written with the stale values from the frontend directories — **every site returns HTTP 200, but every address is wrong**.
- If `pnpm` is not in PATH on the production machine, run `corepack enable pnpm` first, otherwise the build script fails with `spawn pnpm ENOENT`.
- Remote commands have a timeout limit, and a build lasting tens of seconds gets interrupted. Run it in the background and poll the log:

```bash
nohup bash -c 'cd /www/wwwroot/你的项目 && CI=true pnpm run build:frontends > /tmp/build.log 2>&1; echo "exit=$?" >> /tmp/build.log' > /dev/null 2>&1 &
sleep 100 && tail -10 /tmp/build.log     # 轮询直到出现 exit=
```

Artifacts land in each frontend directory's `dist/` and are not written to `backend/public`. On release, replace the contents of the corresponding BaoTa site root directory; the BaoTa site's "website directory" must be the application directory (e.g. `frontend-admin-v3`) and its "run directory" must be `/dist`, otherwise the build will hit the undeletable `.user.ini`.

## 6. Reload Cache and Queues

```bash
cd backend
php artisan optimize:clear      # 清配置、路由、视图、事件、编译缓存
php artisan config:cache
php artisan route:cache
```

- Queues and scheduling need no extra restart: `schedule:run` triggers `queue:drain` every minute to spawn a new Worker, and new code takes effect on the next trigger.
- Restart PHP-FPM (BaoTa Panel → PHP → Restart) only if you changed `php.ini`, PHP extensions, or `bootstrap/cache`.
- In environments running VNC Relay, restart the resident process once (BaoTa process daemon / `systemctl restart`).
- **Do not configure a resident `queue:work` covering the same queue**; it forms a second consumer alongside `queue:drain`.

## 7. Verification

```bash
curl -s -H "Host: api.example.com" http://127.0.0.1/api/health   # 存活
curl -s -H "Host: api.example.com" http://127.0.0.1/api/ready    # 就绪
php artisan app:doctor
```

`/api/ready` reports `database`, `cache`, `storage`, `scheduler`, `scheduler_liveness`, `task_readiness`, and `queue` separately. `scheduler` being `false` right after the scheduler was reloaded or before the cron trigger point is normal; check again after 1–2 minutes.

```bash
# 产物地址必须与后台真源一致
grep -ro "<你的官网域名>" frontend-user-v3-www/dist/ | wc -l   # 应 > 0
grep -ro "<旧域名或其它域名>" frontend-user-v3-www/dist/ | wc -l # 应为 0
grep -ro "<本次改动的新关键字>" frontend-admin-v3/dist/ | wc -l
```

Manual checklist:

- [ ] The home pages of all three sites (official website, console, admin) work, and refreshing any SPA route does not return 404
- [ ] The title of the official website home page HTML is the site name (SEO dynamic rendering is in effect), and `/sitemap.xml` and `/robots.txt` return 200
- [ ] You can log in to the admin site; "Log Center → Runtime Logs" shows no new errors, and "Automated Task Logs → Scheduled Tasks" shows new success records
- [ ] The heartbeat is alive: `php artisan scheduler:liveness` prints "heartbeat normal"
- [ ] No queue backlog: `php artisan queue:monitor provision,referral,notification,coupon,default,automation`
- [ ] Run one real small-amount order through "order → payment → provisioning → notification" before scaling up

Response caches with a TTL (such as `console/capabilities` at 300 seconds and settings groups at 600 seconds) do not expire immediately just because of a redeploy; clear the corresponding cache keys before verification so you do not misjudge it as "changed but not in effect".

## 8. Rollback on Failure

Freeze automated actions first, then touch the data:

```bash
# 注释掉计划任务中的 schedule:run 行（宝塔计划任务页 / /etc/cron.d/turaidc）
```

Rollback has three independent paths; when something breaks, classify the severity first and then choose:

- **Frontend**: Unpack the pre-upgrade dist backup over the corresponding directories; no service restart is needed. If the APIs referenced by the new artifacts have been withdrawn by the old backend, roll the frontend back as well.
- **Backend code**: Switch back to the pre-upgrade commit (find the commit hash with `git log --oneline`), then run `composer install --no-dev --optimize-autoloader`, then `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`.
- **Database**: Migrations are add-only, so you usually do **not** need to roll back the database; restore from backup only when the migration itself failed or data was written incorrectly, and see [Backup and Restore](backup.md) for the procedure. Running `migrate:rollback` on irreversible migrations directly in production is forbidden.

Rollback wrap-up: confirm that `php artisan scheduler:liveness` is normal and `/api/ready` is all green, then restore the scheduled tasks; before restoring, record the rollback reason and impact scope in the execution plan so the same upgrade route is not taken twice.

## Related Documents

- [Backup and Restore](backup.md): backup scope, ready-made scripts, and restore drills.
- [Deployment and Scheduling Guide](../deploy/scheduling.md): field-tested pitfalls and operational conventions for incremental updates.
- [Troubleshooting](../troubleshooting.md): locating symptoms when anomalies appear after an upgrade.
- [Log Archiving and MySQL Log Maintenance](log-archive.md): archiving and database log rotation.
