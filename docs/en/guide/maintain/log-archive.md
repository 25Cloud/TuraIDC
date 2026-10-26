---
source: guide/maintain/log-archive.md
sourceHash: 07cb38b3eb84eb1c74c44fb1ae932e4aaf57ef1b3a82ffa237d2e95687a062c9
translatedAt: 2026-10-26
---

# Log Archiving and MySQL Log Maintenance

This article is the source of truth for log archiving, MySQL log rotation, and Binlog expiration policy in production environments.

## 1. Database Log Archiving

### 1.1 Scope

`db:archive-logs` always processes the following 7 InnoDB log tables (the list is defined by `tables` in `backend/config/log_archive.php`):

| Table                             | Content                                            |
| --------------------------------- | -------------------------------------------------- |
| `operation_logs`                  | API, admin operation, and administrator login logs |
| `activity_logs`                   | System and business activity logs                  |
| `message_logs`                    | Unified SMS and email message logs                 |
| `automation_logs`                 | Automated business task logs                       |
| `schedule_run_logs`               | Laravel schedule run logs                          |
| `integration_plugin_runtime_logs` | Plugin runtime logs                                |
| `gateway_logs`                    | Payment gateway interaction logs                   |

The archive condition is fixed:

```sql
created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
```

Financial transactions, bills, payments, archive audits, and business snapshots are outside the scope of this command.

### 1.2 File Location and Format

The archive directory is fixed and managed by the backend:

```text
backend/storage/app/private/log-archives/
└── operation_logs/
    └── operation_logs_20260721.log
```

The archive data file format is:

```text
backend/storage/app/private/log-archives/{表名}/{表名}_YYYYMMDD.log
```

File content is CSV with a header row. When the same table is re-run on the same day, pt-archiver appends to the existing file and does not write the header row again. Execution reports and machine-readable run logs are always stored in `backend/storage/logs/log-archive/` and are never mixed with archive data.

Archive data `*.log` older than 180 days is deleted automatically after the formal archiving finishes. A dry-run neither creates archive data files nor runs historical file cleanup.

### 1.3 Installation and Credentials

The target Linux server must have Percona Toolkit installed; verify it first:

```bash
/usr/bin/pt-archiver --version
```

Create `/etc/turaidc/pt-archiver.cnf`:

```ini
[client]
host=127.0.0.1
port=3306
user=turaidc_archiver
password=替换为强密码
default-character-set=utf8mb4
```

```bash
sudo chown root:root /etc/turaidc/pt-archiver.cnf
sudo chmod 600 /etc/turaidc/pt-archiver.cnf
```

The archive account is granted only `SELECT` and `DELETE` on the 8 tables above in the target database. Never write the password into Crontab, command arguments, project code, or execution logs.

The archive directory is not configured through `.env` or the admin console: archive data is always written to `storage_path('app/private/log-archives')`, and reports are always written to `storage_path('logs/log-archive')`. NAS mount paths and external directories are not supported.

The "Log Archiving" settings in the admin console only control retention days, the pt-archiver executable and credentials file paths, concurrency, batch size, and batch interval; the archive data directory and the report directory are not editable.

### 1.4 Execution and Resumability

Run the pre-check first:

```bash
cd /www/wwwroot/turaidc/backend
/usr/bin/php artisan db:archive-logs --dry-run
```

Trial run on a small table:

```bash
/usr/bin/php artisan db:archive-logs --execute --table=gateway_logs
```

Formal run over all tables:

```bash
/usr/bin/php artisan db:archive-logs --execute
```

Adjust the rate limit temporarily:

```bash
/usr/bin/php artisan db:archive-logs --execute --concurrency=2 --batch-size=500 --sleep-seconds=2
```

The program uses pt-archiver's `--limit`, `--commit-each`, `--sleep`, incrementing primary key scan, and `--purge`. A committed batch is already written to the file and deleted from the source table, so re-running after an interruption continues with the remaining records. An extreme interruption may duplicate the last uncommitted batch in the file, but does not drop records that were already deleted; deduplicate by primary key `id` when restoring or analyzing an archive.

For each table, success, failure, affected rows, file size, SHA-256, and the tool exit code are written to:

- `archive_audit_logs`: the archive audit inside the database;
- `backend/storage/logs/log-archive/run_*.json`: the full report of a single run;
- `backend/storage/logs/log-archive/archive-YYYY-MM-DD.log`: the per-event JSON log.

### 1.5 Platform Scheduled Task

Log archiving is registered as the platform core scheduled task `log-archive`, with the Cron expression `0 2 * * *`. The single scheduling entry point of Laravel is still `schedule:run`, executed every minute; it triggers `scheduler:heartbeat`, and the heartbeat dispatches due archive tasks to the default queue, with results saved by the existing task run records.

The task always invokes `db:archive-logs` with `--execute --json`, runs for at most 1 hour, and holds a task lock of 61 minutes to satisfy the visibility timeout constraint of the existing database queue. The archiving service itself still keeps a global file lock and a per-table PID file, so the same table is never archived in overlapping runs. This task cannot be triggered manually from the admin console; manual operations must use the commands in this section and follow the pre-check procedure.

Production keeps only the once-per-minute `schedule:run`. After the switch, the old daily 02:00 Crontab that ran `db:archive-logs --execute` directly must be removed; the two trigger paths must never run at the same time.

## 2. MySQL Error Log and Slow Query Log Rotation

Confirm the actual log paths first:

```sql
SHOW VARIABLES WHERE Variable_name IN ('log_error', 'slow_query_log', 'slow_query_log_file');
```

Fill the queried absolute paths of the error log and the slow query log into `/etc/logrotate.d/turaidc-mysql`:

```text
/实际路径/mysql-error.log /实际路径/mysql-slow.log {
    su mysql mysql
    daily
    rotate 30
    compress
    dateext
    missingok
    notifempty
    create 0640 mysql mysql
    sharedscripts
    postrotate
        /usr/bin/mysqladmin --defaults-extra-file=/root/.my.cnf flush-logs > /dev/null 2>&1 || true
    endscript
}
```

`/root/.my.cnf` must be owned by root with permission `0600`, and must use an operations account that holds the `RELOAD` privilege; do not reuse the low-privilege archive account.

Verify the configuration:

```bash
sudo logrotate -d /etc/logrotate.d/turaidc-mysql
# 确认调试输出无错误后，在维护窗口验证一次实际轮转
sudo logrotate -f /etc/logrotate.d/turaidc-mysql
```

## 3. Binlog Expiration Policy

The current live database is MySQL 8.0; run:

```sql
SET PERSIST binlog_expire_logs_seconds = 2592000;
SHOW GLOBAL VARIABLES LIKE 'binlog_expire_logs_seconds';
```

MySQL 5.7 uses the following configuration; never configure it together with the MySQL 8.0 parameter:

```ini
[mysqld]
expire_logs_days=30
```

You can also run `SET GLOBAL expire_logs_days = 30` online first, but you still have to write it into `my.cnf` and verify it again after a restart.

## 4. Space Accounting and Go-Live Checks

Physical deletion makes space inside the InnoDB tablespace reusable, but a standalone `.ibd` file is not guaranteed to shrink immediately. This task does not run `OPTIMIZE TABLE` automatically; when you really need to return space to the operating system, use the existing smart optimization capability in the admin console and schedule a separate maintenance window.

Confirm the following in order before going live:

1. pt-archiver version, credentials file permissions, and archive directory writability;
2. the affected row count of the full-table dry-run matches expectations;
3. after the trial run on the small `gateway_logs` table, the CSV header, the row count, the remaining database records, and the audit log are consistent;
4. confirm that the per-minute `schedule:run`, the default queue consumption, and the `log-archive` scheduled task all work normally, then watch the first full report;
5. `logrotate -d` reports no errors, and the Binlog expiration variable is 2592000 seconds.

## Related Documents

- [Deployment and Scheduling Guide](../deploy/scheduling.md): scheduled tasks and the queue environment.
- [MySQL Version Compatibility Baseline](../../develop/mysql-compatibility.md): SQL compatibility constraints.
