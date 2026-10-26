---
status: current
updated: 2026-10-16
owner: ops
source: guide/maintain/backup.md
sourceHash: ef00bed1b118b245a87b254046df61df3e752d0a0a70d36c405d08a8590c41f7
translatedAt: 2026-10-26
---

# Backup and Restore

This document is aimed at site operators and operations staff responsible for production servers. It describes what TuraIDC needs to back up, the ready-made backup scripts and retention policy in the repository, the restore procedure and drill essentials, and how to prove that a backup is genuinely usable. Backup is a precondition for upgrades and troubleshooting; for upgrade operations see [Version Upgrade](upgrade.md).

## 1. Backup Object Checklist

Losing any single one of the four object categories makes it impossible to fully restore a site. The configuration of external integrations (payment channels, upstream providers) and the plugin secrets are stored in the database and are preserved together with the database backup, so no separate export is needed.

| Object                    | Location                                                                      | Consequence of loss                                                                                                 |
| ------------------------- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Database                  | MySQL database (the database name comes from `DB_DATABASE` in `backend/.env`) | Orders, services, funds, and users are all lost                                                                     |
| Uploads and private files | `backend/storage/app/private/`                                                | Ticket upstream attachments, log archive files, and plugin private files are lost                                   |
| Site media and uploads    | `backend/public/uploads/`, `backend/public/media/`                            | Ticket attachments, the logo, and the home page banner image and video are lost                                     |
| Configuration             | `backend/.env`                                                                | All integration credentials and `APP_KEY` are lost; losing `APP_KEY` means encrypted fields can never be read again |
| Frontend artifacts        | `dist/` in the three frontend directories                                     | The site does not open; it can be rebuilt, but depends on a build machine and a correct `backend/.env`              |

`.env` is a credential file and must be managed as credentials once backed up: stored encrypted or offline, permission `600`, and never committed to any Git repository. The **storage location** of a backup cannot be the same disk or the same machine as the site: the repository script writes into `storage/backups/` under the site directory, so a whole-machine failure loses it together with the business data, and you have to sync it off-site yourself.

## 2. Database Backup Script

The repository provides two ready-made scripts; pick one according to the deployment method.

### 2.1 Source / BaoTa Panel Deployment

```bash
cd /www/wwwroot/你的项目/backend
bash scripts/backup_mysql.sh
```

Behavior of `backend/scripts/backup_mysql.sh` (the source is authoritative): it reads `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` from `backend/.env`; it passes the credentials through a temporary `defaults-extra-file` (so the password does not appear in the process list) and deletes it immediately after execution; it calls `mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF` and writes the output to disk with `gzip`; it verifies that the file is not empty, and an empty file is deleted with a non-zero exit code; finally it cleans up expired files and prints the path.

| Environment variable    | Default value                   | Description                                     |
| ----------------------- | ------------------------------- | ----------------------------------------------- |
| `BACKUP_DIR`            | `backend/storage/backups/mysql` | Backup directory                                |
| `BACKUP_RETENTION_DAYS` | 14                              | Retention days; cleans `*.sql.gz` by file mtime |

File names look like `<库名>_20261016_030000.sql.gz`. Override the two variables with environment variables before invoking the script; there is no need to edit it. Scheduled execution: BaoTa scheduled task → Shell script, every day at 3:00 AM, with the same script content as above.

### 2.2 Docker / 1Panel Deployment

```bash
cd /opt/turaidc/deploy/docker
./backup.sh            # 默认保留 14 天
./backup.sh 30         # 保留 30 天
```

The script runs `mysqldump` inside the mysql container (reusing the root password of the container environment, so the host machine stores no password), uses `pipefail` to guarantee that a failed dump does not produce a truncated file, and verifies integrity with `gzip -t`: **a failed check keeps the file and exits**, and old backups are not cleaned up. Files land in `deploy/docker/backups/turaidc-<时间戳>.sql.gz`. Host crontab:

```cron
0 3 * * * cd /opt/turaidc/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
```

Uploads and media data in Docker named volumes are outside the scope of the script and must be backed up separately (see section 3).

## 3. File-Type Backup

The repository has **no** ready-made file backup script; the commands below are manual (no automated replacement was confirmed in the code):

```bash
cd /www/wwwroot/你的项目
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p /backup/turaidc

# 私有文件：工单上游附件、日志归档数据
tar -czf /backup/turaidc/storage-app-$TS.tar.gz -C backend/storage app

# 站点媒体与上传
tar -czf /backup/turaidc/public-files-$TS.tar.gz -C backend/public uploads media

# 配置：单独加密存放，不要与站点数据同盘
cp backend/.env /backup/turaidc/env-$TS.bak && chmod 600 /backup/turaidc/env-$TS.bak

# 前端产物：发布前打一次即可，产物可重建
tar -czf /backup/turaidc/dist-$TS.tar.gz \
  frontend-user-v3-www/dist frontend-user-v4-console/dist frontend-admin-v3/dist
```

`backend/storage/logs/` and the application logs do not need long-term backup; the log archive data lives in `backend/storage/app/private/log-archives/` and is packed together with `storage/app`, and the archiving policy is in [Log Archiving and MySQL Log Maintenance](log-archive.md). A Docker deployment corresponds to three named volumes (`app-storage`, `app-uploads`, `app-media`), which can be exported with a container; the volume names follow the actual `docker volume ls` output (the compose project name is used as a prefix):

```bash
docker run --rm -v turaidc_app-storage:/data -v /backup/turaidc:/out alpine \
  tar -czf /out/app-storage-$TS.tar.gz -C /data .
```

## 4. Retention Policy

| Object                  | Suggested retention                                                                                  | Basis                                             |
| ----------------------- | ---------------------------------------------------------------------------------------------------- | ------------------------------------------------- |
| Database daily backup   | The most recent 14 days (script default); keep one extra manual snapshot around a key release window | `BACKUP_RETENTION_DAYS`                           |
| Database monthly backup | Keep 6–12 months in a separate directory, unaffected by the script's automatic cleanup               | Used for reconciliation and audit look-back       |
| Uploads and media files | Weekly full backup plus one before a change                                                          | Attachments can only be recovered from a backup   |
| `.env`                  | Keep one after every change, retaining the most recent 5 over the long term                          | Configuration rollback and `APP_KEY` traceability |
| Frontend `dist`         | One before every release, retaining the most recent 5                                                | Release rollback                                  |

The script's automatic cleanup deletes only files inside its own directory, by mtime, and does not touch other directories. Keep monthly backups in a separate directory and daily cleanup will not take them away.

## 5. Restore Procedure and Drills

### 5.1 Isolated Database Drill

The correct restore order is **drill in an isolated database first, then touch production**. Do not import repeatedly into the same temporary database; create a new database and then import, and on failure simply switch to another database name and start over.

```bash
# 1. 建一个与生产无关的复原库
mysql -u root -p -e "CREATE DATABASE idc_restore_drill_20261016 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 导入
gunzip -c /backup/turaidc/idc_20261016_030000.sql.gz | mysql -u root -p idc_restore_drill_20261016

# 3. 比对表数量与关键财务行数（表清单以当前库为准）
mysql -u root -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='idc_restore_drill_20261016';"
mysql -u root -p -N -e "SELECT COUNT(*) FROM idc_restore_drill_20261016.migrations;"
```

When an import fails, look at the error line number and table name first instead of immediately switching to another backup. There has been a recorded case where the import failed at line 715: the same dump imported completely with the native `mysql` client, which shows the failure was in the import path/client rather than in the dump itself (the original record is maintainer material and is not linked from this document).

Drill at least once a quarter, or run a minimal drill (verifying only the import and the table count) before every upgrade. Record the backup file, timestamp, checksum, import result, and difference list clearly; **a drill passes only when the difference is zero**. A backup that has never been through a restore drill only counts as "the file exists", not as "restorable".

### 5.2 Production Restore

Run it only after confirming the backup is usable and explicitly accepting the data cutoff moment. Before restoring, stop the actions that write:

```bash
# 1. 冻结调度：注释掉计划任务中的 schedule:run 行（宝塔计划任务页 / /etc/cron.d/turaidc）
# 2. 对当前（故障）库再备份一次，保留现场，不要直接覆盖
bash scripts/backup_mysql.sh

# 3. 还原
gunzip -c /backup/turaidc/idc_<确认过的时间戳>.sql.gz | mysql -u <用户> -p <库名>

# 4. 恢复调度：取消第 1 步的注释，等 1-2 分钟让心跳与队列重新跑起来
```

The restore scope is limited to the database. File-type restores are simply unpacked inside the site directory (back up the current directory before unpacking):

```bash
tar -xzf /backup/turaidc/storage-app-$TS.tar.gz -C backend/storage
tar -xzf /backup/turaidc/public-files-$TS.tar.gz -C backend/public
```

Restoring `.env` may change `APP_KEY`. If it differs from the value before the failure, encrypted fields (ID card numbers and so on) can no longer be decrypted — which is exactly why `.env` must be saved **in the same batch** as the database backup. After modifying `.env`, run `php artisan config:clear && php artisan config:cache`. A drill must run the whole chain: create the database → import → verify tables/row counts → point the application at it (use an isolated environment, do not modify the production `.env`) → log in to the admin panel and check the orders and funds pages.

## 6. How to Prove Backup Validity

Judge in four progressive layers; the further you go, the more credible the evidence: **the file is not empty** (the script does this automatically, and an empty file is deleted with a non-zero exit); **the archive is intact** (source deployments can add `gzip -t` themselves, and the Docker script has it built in; this catches truncation but not missing content); **the content can be compared**; **a real restore works** (the isolated database import plus row-count comparison in section 5, the only evidence that counts).

```bash
gunzip -c idc_20261016_030000.sql.gz | grep -cE '^CREATE TABLE'
gunzip -c idc_20261016_030000.sql.gz | grep -oE 'INSERT INTO `(orders|services|users)`' | sort | uniq -c

# 每次备份后记录校验值与大小，形成可追溯台账
cd backend/storage/backups/mysql && sha256sum *.sql.gz > SHA256SUMS-$(date +%Y%m%d).txt && ls -lh --time-style=long-iso
```

Monitoring and alerting: wire the backup script's exit code into the existing alerting channel (for example the BaoTa scheduled task log/email), and a failure must reach a human; `backend/scripts/production_preflight.mjs` checks only the configuration and site reachability and **does not check backups** (no backup inspection task was confirmed in the code).

## 7. Common Errors

| Symptom                                                   | Cause                                                                                                                | Handling                                                                                                                       |
| --------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| The script reports `.env 文件不存在`                      | It is not executed in the `backend` directory, or `.env` was moved                                                   | Confirm that `backend/.env` exists and is readable by its owner                                                                |
| The backup file is 0 bytes                                | `mysqldump` failed (credentials, permissions, full disk)                                                             | Check the script's stderr; confirm the database account has the `SELECT`, `LOCK TABLES`, `SHOW VIEW`, and `TRIGGER` privileges |
| No new file appears in the directory after a backup       | The `cd` path in the scheduled task is wrong, or CLI PHP is unavailable                                              | Use absolute paths and write stderr to a log file instead of `/dev/null`                                                       |
| Old backups are not cleaned up                            | `BACKUP_DIR` is pointed elsewhere by an environment variable                                                         | Print the actual path to verify; the script cleans only `*.sql.gz`                                                             |
| The system reports a decryption failure after restore     | `APP_KEY` is not from the same batch as the backup data                                                              | Restore from the `.env` backup from the same batch as the database                                                             |
| A syntax/encoding error occurs halfway through the import | Client version or character set mismatch (backing up 5.7 with the MySQL 8.0 mysqldump needs `--column-statistics=0`) | Retry with the native client and keep the error line number as evidence                                                        |

## Related Documents

- [Version Upgrade](upgrade.md): backup requirements before an upgrade and the rollback path on failure.
- [Troubleshooting](../troubleshooting.md): locating symptoms such as disk and log surges and an unreachable database.
- [Deployment and Scheduling Guide](../deploy/scheduling.md): how scripts are invoked and how scheduling is configured.
- [Log Archiving and MySQL Log Maintenance](log-archive.md): the log archive directory and the Binlog expiration policy.
