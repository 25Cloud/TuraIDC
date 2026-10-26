---
source: guide/maintain/index.md
sourceHash: 5157680a1f17dc0a7c784d1e4f95e241f4cb07008e37e283c01cc15043c16080
translatedAt: 2026-10-26
---

# Maintenance and Upgrades

The operations needed to keep a site running long term: upgrading to a new version, backup and restore, log archiving and database maintenance, and the Nginx caching and rewrite rules for the frontend sites.

## 1. Maintenance Tasks

| Task                                                  | When to Do It                                                                             |
| ----------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| [Version Upgrade](upgrade.md)                         | When a new version is released or a production issue needs fixing                         |
| [Backup and Restore](backup.md)                       | Configure once before go-live, then run on a schedule and rehearse a restore periodically |
| [Log Archiving and MySQL Maintenance](log-archive.md) | When log tables grow, disk space is tight, or hot and cold data must be separated         |
| [Frontend Site Nginx Rules](nginx-rules.md)           | When deploying the three frontend apps and configuring rewrite and caching policies       |

## 2. Maintenance Principles

- **Migrations are additive only**: an upgrade runs only the new database migrations and never drops tables, columns, or indexes — both in-place upgrades and the ZJMF Finance migration depend on a stable table structure.
- **Back up first, then act**: take a verifiable backup before any upgrade, data repair, or configuration change; see [Backup and Restore](backup.md).
- **Failures must be rollback-able**: record the current version and the location of the frontend artifacts before upgrading, so you can roll back to the previous artifacts and code if something goes wrong.
- **Keep the scheduler running**: scheduled tasks, queues, and heartbeats are interrupted during an upgrade and must be confirmed running again afterwards, otherwise automatic provisioning and renewal stall silently.

## 3. Routine Checks

```bash
cd backend
php artisan app:doctor          # PHP 版本、扩展、禁用函数是否仍满足要求
php artisan schedule:list       # 定时任务是否登记完整
```

Disk and log growth, database size, and whether backup artifacts are produced on time are all worth a manual review once a week.

## Related Documents

- [Troubleshooting](../troubleshooting.md): abnormal symptoms that appear during maintenance and how to handle them.
- [Data Migration](../migrate/index.md): go here when switching systems or moving data.
