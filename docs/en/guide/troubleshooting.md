---
status: current
updated: 2026-10-16
owner: ops
source: guide/troubleshooting.md
sourceHash: af19ad9541b58f68986af61dc3caae0115d360af13c33308db8ac1cb13587712
translatedAt: 2026-10-26
---

# Troubleshooting

This document is written for site operators and operations engineers who run production servers, and organizes the diagnostic path for common failures as "symptom → what to check first → common root cause → handling → how to confirm recovery". It covers troubleshooting only; for routine upgrades and backups, see [Version Upgrade](maintain/upgrade.md) and [Backup and Restore](maintain/backup.md).

> Keep three premises in mind before troubleshooting: the production site is deployed as **four independently deployed frontends** (official website / console / admin static sites + API site), and browsers connect to the API domain directly; queues and scheduled tasks are driven by `schedule:run` once per minute, with no resident Worker; the `php` in commands must be the same PHP CLI that the API site uses. Judge by running code, logs, and `/api/ready` output, not by impression.

## 1. Site Does Not Open or Shows a Blank Page

```bash
curl -I https://你的官网域名/          # 看 HTTP 状态码，而不是只看浏览器
curl -s -o /dev/null -w '%{http_code}\n' https://你的API域名/api/health
tail -50 /www/wwwroot/你的项目/backend/storage/logs/laravel.log
```

Press F12 in the browser and check Console and Network: static asset 404s, API CORS/404, and `pageerror` are three clues that point to completely different problems.

| Symptom                                                                                            | Root cause                                                                                                                                                                               | Handling                                                                                                                               |
| -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| The page is blank and Console shows a setup-stage error such as `ReferenceError: t is not defined` | The frontend release artifact is faulty (historically, a stray import in an SFC made the whole component fail to mount). Neither the build nor the type check reports this kind of error | Roll back with the `dist` backup from before the previous release, then rebuild and retest; see [Version Upgrade](maintain/upgrade.md) |
| Only refreshing a specific route returns 404                                                       | The SPA site has no History fallback configured                                                                                                                                          | Add `try_files $uri $uri/ /index.html;`, see [Four-Frontend Nginx Rewrite Configuration](maintain/nginx-rules.md)                      |
| The page opens but all data is empty and the API returns 404/403                                   | The API address in the artifact does not match the backend source of truth, or CORS does not allow the origin                                                                            | Check the four public addresses in `backend/.env` and validate the artifact with `grep -ro`                                            |
| The official website home page has no title or body and the meta is an empty shell                 | The forwarding rule or template address for the official website's SEO dynamic rendering has failed                                                                                      | See [Four-Frontend Nginx Rewrite Configuration](maintain/nginx-rules.md) and [Deployment and Scheduling Guide](deploy/scheduling.md)   |
| The official website `index.html` is fine but all styles/JS return 404                             | The site root directory or the "run directory" is misconfigured                                                                                                                          | In the BaoTa Panel site, set "website directory" to the application directory and "run directory" to `/dist`                           |

**How to confirm recovery**: each of the four public addresses returns 200; refreshing any route in the console and the admin no longer returns 404; the `title` in the official website home page HTML is the site name (not the default shell).

## 2. HTTP 502, PHP Version, and Disabled Functions

```bash
# 站点错误日志（宝塔：网站 → 站点 → 日志；裸机：/var/log/nginx/）
tail -50 /www/wwwlogs/你的API站点.error.log
php -v && php -m | grep -iE 'pdo_mysql|redis|openssl|mbstring|fileinfo|json'
```

- **PHP-FPM is not running, or the API site has not selected an available PHP version** (BaoTa → App Store → PHP → Status): select it again under the site's "PHP version" and restart the corresponding PHP service. Do not hand-edit the `fastcgi_pass` socket path generated by BaoTa.
- **The PHP version is below the minimum requirement**: the source of truth for the minimum version is `backend/app/Support/EnvironmentRequirements.php` (PHP 8.3.0). Installing 8.2 is rejected outright at the environment check stage.
- **`putenv`, `proc_open`, `symlink` are disabled by `disable_functions`**: this is the most frequent deployment failure, and BaoTa disables all three by default. It shows up as Composer reporting `Call to undefined function putenv()`, or the application failing when reading `.env` or calling an external process. Handling: BaoTa → App Store → PHP → Disabled functions, remove these three, then restart PHP.
- **The PHP-FPM process pool is exhausted or timing out**: `Request Timeout` / `upstream timed out` means slow requests rather than a dead process; look for the specific request in `laravel.log` and the slow query log.
- **`open_basedir` restricts the site directory**: under containerized deployment, changed code paths cause every file operation to be rejected (`deploy/docker/backend/entrypoint.sh` rewrites `public/.user.ini` when the container starts).

**How to confirm recovery**: `curl -I` returns 200 or a business status code (no longer 502/504); `php artisan app:doctor` passes; the site error log no longer shows `connect() to unix:/... failed`.

## 3. app:doctor Reports Errors

First run `cd /www/wwwroot/你的项目/backend && php artisan app:doctor` (`--all` additionally lists recommended extensions that do not block deployment). A non-zero exit code means a **required** item did not pass, and the output lists every failing item together with the current `disable_functions`.

| Failed item                                                         | Handling                                                                                                                                                                        |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PHP 版本 >= 8.3.0`                                                 | Upgrade PHP, or switch the site and the CLI to an installed 8.3+                                                                                                                |
| `PHP 扩展 pdo_mysql / redis / openssl / mbstring / fileinfo / json` | Install the corresponding extension in the panel and restart PHP (the authoritative required list is `EnvironmentRequirements::REQUIRED_EXTENSIONS`)                            |
| `可调用函数 putenv / proc_open / symlink`                           | Remove them from `disable_functions` and restart PHP; while they are disabled `function_exists()` still returns true, so only the `disable_functions` list is a reliable signal |

Note that `app:doctor` checks the PHP of the **current process**: passing on the CLI does not mean FPM passes, because the two configurations may differ.

**How to confirm recovery**: the command outputs `环境检测通过` and the exit code is 0.

## 4. Queues and Heartbeat Not Running (Scheduled Task Not Configured)

**Symptom**: paid orders are never provisioned; automatic renewal, expiration suspension, and overdue billing do not run; notifications are not queued; the admin "Automatic Task Log → Scheduled Task" page shows no new records for a long time.

```bash
cd /www/wwwroot/你的项目/backend
php artisan scheduler:liveness      # 心跳活着吗
php artisan schedule:run            # 手动触发一次，看有没有报错
php artisan queue:monitor provision,referral,notification,coupon,default,automation
```

Admin: `/admin/logs/schedules` (scheduled tasks), `/admin/logs/tasks` (automatic task log). At the platform level, `/api/ready` reports `scheduler`, `scheduler_liveness`, `task_readiness`, and `queue` separately.

1. **The scheduled task is not configured at all, or does not actually run**. The only thing needed is once per minute:

   ```cron
   * * * * * cd /www/wwwroot/你的项目/backend && php artisan schedule:run >> /dev/null 2>&1
   ```

   If the `cd` path is wrong, or the CLI PHP is not in cron's PATH, the command fails silently (the error is swallowed by `>> /dev/null 2>&1`). BaoTa scheduled tasks use a different default PATH from the site, so `php` may point to another version or not exist at all; redirect the output to a log file first when investigating.

2. **A resident `queue:work` was misconfigured**: `schedule:run` already drives `queue:drain` in the background every minute to consume all queue groups, so adding a resident Worker creates two consumers. Do not cover the same queue while keeping `queue:drain`.
3. **`QUEUE_CONNECTION` is not `database`**: `queue:drain` skips immediately (returning `queue_driver_not_database`), and the queue silently stops running. Check `QUEUE_CONNECTION`, `TURAIDC_PROVISION_QUEUES`, `TURAIDC_BUSINESS_QUEUES`, and `TURAIDC_SCHEDULE_QUEUE` in `backend/.env`.
4. **The `jobs` table does not exist** (drain returns `jobs_table_missing`), or **the Worker was OOM-killed and left a stale lock**: in the first case confirm the migration has run; in the second, drain judges stale locks by liveness heartbeat and forces their release, `laravel.log` shows `检测到队列 Worker 残锁`, and if this recurs, check memory and system logs first.
5. **Tasks are piling up**: consume manually once per queue boundary; do not start a resident Worker.

   ```bash
   php artisan queue:work --once --queue=provision
   php artisan queue:work --once --queue=referral,notification,coupon,default
   php artisan queue:work --once --queue=automation
   php artisan queue:failed            # 列出失败任务
   php artisan queue:retry <id>        # 重试指定失败任务
   ```

**How to confirm recovery**: `scheduler:liveness` outputs `心跳正常`; the four related items in `/api/ready` are true; after running `schedule:run` manually once, the "Scheduled Task" page shows a new successful record; the pending count from `queue:monitor` is 0 or keeps decreasing.

## 5. Email Cannot Be Sent

- Admin "Log Center → Email Log" (`/admin/logs/email`): check whether no record was generated at all, or a record was generated but its status failed. Records contain `plugin_id`, `driver_key`, and `trace_id`.
- Whether the plugin under "Plugin Management → Email Sending" (`/admin/integration-plugins/mail`) is installed, configured, and bound.

**Key distinction**: in the admin panel, **test sending goes straight through the driver and is not bound by the master switch**. So "the test email succeeds but users never receive verification codes" is almost certainly the master switch being off, not broken SMTP. The only source of truth for whether email is actually sent externally is `notification.email_enabled` (`NotificationService::isEmailEnabled()`), enabled under "Site Settings → Notification Master Switch".

Other common root causes: `MAIL_MAILER=log` (one of the defaults; email is only written to the log and never actually sent, so production must let a mail plugin take over the driver configuration); the email template is missing or not enabled (template seeds are written idempotently into `notification_templates` by `SettingsSeeder`, and the template data is not in the schema baseline); the sender is rejected by the recipient (check the SPF/DKIM of the sending domain — that is configuration on the mail provider's side, and this system's code has not been confirmed to have any sending-domain verification capability).

**How to confirm recovery**: trigger a real registration/password recovery flow in the admin panel, the email log shows a record with a successful `status`, and the mailbox receives the email.

## 6. Upstream Provisioning Failure

**Symptom**: the user has paid but the service stays at "provisioning", with `provision_error` inside `provision_data`; or an operation in the console/admin panel reports an upstream failure.

```bash
cd /www/wwwroot/你的项目/backend
php artisan provision:retry-failed                     # 默认 dry-run，只扫描并告警
php artisan provision:retry-failed --limit=10 --max-retries=3
```

The command lists the orphaned orders' `service_id`, `order_id`, `retry_count`, and the truncated `provision_error`.

- The upstream plugin is not installed or not configured: check the binding and credentials under admin "Plugin Management → Upstream Provisioning" (`/admin/integration-plugins/upstream`).
- The upstream address or credentials changed, or the upstream service is abnormal: use the original text in `provision_error` to locate the specific endpoint. The upstream may be plain HTTP or have an incomplete certificate chain; **do not** change the verification logic because of a certificate problem.
- Tasks are piling up in the `provision` queue: see section 4, and confirm the queue is being consumed before talking about retries.
- Retrying is itself risky: the provisioning chain is "clear cart → add to cart → checkout → fetch host_id", with no upstream idempotency-key lookup. If the previous failure happened after checkout succeeded, an automatic retry may cause **duplicate provisioning**. Therefore `provision:retry-failed` defaults to dry-run, and `--execute` is left to the administrator after verifying the actual upstream provisioning state.

**How to confirm recovery**: `php artisan provision:retry-failed` outputs `未发现上游开通失败孤儿单`; the admin "Ticket Push Log"/service details show successful provisioning; the service status in the database no longer stays at `PENDING`.

## 7. Disk and Log Growth

```bash
df -h
du -sh /www/wwwroot/你的项目/backend/storage/* 2>/dev/null | sort -h | tail
ls -lhS /var/lib/mysql/ | head    # 含 binlog
```

| Source of usage                                                                   | Handling                                                                                                                                                                                                                                                                    |
| --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MySQL backup directory                                                            | The script keeps 14 days by default; adjust with `BACKUP_RETENTION_DAYS` or move monthly backups to another disk                                                                                                                                                            |
| Binlog                                                                            | Production MySQL 8: `SET PERSIST binlog_expire_logs_seconds = 2592000;` (MySQL 5.7 uses `expire_logs_days`), see [Log Archiving and MySQL Log Maintenance](maintain/log-archive.md)                                                                                         |
| MySQL error log / slow query log                                                  | Configure logrotate, see [Log Archiving and MySQL Log Maintenance](maintain/log-archive.md)                                                                                                                                                                                 |
| High-frequency business log tables (7 InnoDB log tables such as `operation_logs`) | First run `php artisan db:archive-logs --dry-run` to see the affected row count, then `--execute`; archived data lands in `storage/app/private/log-archives/`, `*.log` older than 180 days is deleted automatically, and archive reports are in `storage/logs/log-archive/` |
| Application log accumulation                                                      | `LOG_DAILY_DAYS` (default 14) and `LOG_LEVEL` determine retention; do not leave `debug` on in production long-term                                                                                                                                                          |
| Temporary uploads for upstream tickets                                            | A cleanup command is registered to run every minute; see "Log Center → Upload/Cleanup Log" (`/admin/logs/upstream-uploads`)                                                                                                                                                 |
| `failed_jobs` accumulation                                                        | Inspect with `php artisan queue:failed`, retry with `queue:retry <id>`, and once confirmed worthless, use `php artisan queue:flush --hours=168`                                                                                                                             |
| The log archive directory itself                                                  | Archived data does not take a path from `.env`; it is written to the fixed location `storage/app/private/log-archives` and NAS or external directories are not supported                                                                                                    |

**How to confirm recovery**: `df -h` usage drops back and stays stable; observe the growth rate over two consecutive days to confirm the growth comes from business volume rather than a leak. Physical deletion does not immediately shrink InnoDB's standalone `.ibd` files; returning the space requires a separate maintenance window.

## 8. Common Causes of 403 Permission Errors

```bash
tail -30 /www/wwwlogs/你的站点.error.log          # 是 Nginx 拒绝还是应用返回
ls -ld /www/wwwroot/你的项目/backend /www/wwwroot/你的项目/backend/public
ls -l /www/wwwroot/你的项目/backend/.env
```

- **The site root directory points to the wrong location**: requests land in a directory that should never be exposed, or the directory itself is unreadable. In a BaoTa Panel site, "website directory" should be the application directory, not `dist`.
- **`.env` permissions are inappropriate**: it must be strictly restricted (`chmod 600 .env`) and owned by the site's runtime user; too broad is a security problem, too narrow keeps the application from reading its configuration.
- **`storage/`, `bootstrap/cache`, or the upload directories (`public/uploads`, `public/media`) are not writable**: write failures surface as 403/500 in some scenarios.
- **`disable_functions` contains `symlink`**: symbolic link operations such as `php artisan storage:link` fail, and static asset paths cannot be resolved.
- **`open_basedir` does not include the site directory**: BaoTa's "cross-site attack protection" writes `.user.ini` in the site root (`chattr +i`); containerized deployment must rewrite that file to point at the real code path.
- **An Nginx `deny all` rule matches**: the common `location ~ /\.(?!well-known)` in configurations rejects access to dot directories; this is expected behavior, so do not loosen hidden-file access to work around it.

```bash
cd /www/wwwroot/你的项目/backend
sudo chown -R www:www storage bootstrap/cache public/uploads public/media
sudo chmod -R 775 storage bootstrap/cache public/uploads public/media && sudo chmod 600 .env
```

**How to confirm recovery**: `storage` in `/api/ready` is true; uploading an image in the admin panel or submitting a ticket with an attachment succeeds; `laravel.log` no longer shows `failed to open stream: Permission denied`.

## Related Documents

- [Version Upgrade](maintain/upgrade.md): upgrade process, verification, and rollback path.
- [Backup and Restore](maintain/backup.md): backup scope, restore drills, and proof of validity.
- [Deployment and Scheduling Guide](deploy/scheduling.md): scheduling, queues, and the trusted proxy contract.
- [Four-Frontend Nginx Rewrite Configuration](maintain/nginx-rules.md): rules for the four frontend sites and the 404 fallback.
- [Log Archiving and MySQL Log Maintenance](maintain/log-archive.md): log and Binlog cleanup.
- [Deployment Guide](deploy/generic.md): environment requirements and from-scratch deployment steps, for cross-checking when bringing the site back online after troubleshooting.
