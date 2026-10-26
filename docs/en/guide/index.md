---
source: guide/index.md
sourceHash: f962f04c4a10a9d72917842d04852a76f4054db042788dab3d27c63fc3782e44
translatedAt: 2026-10-26
---

# User Documentation

This section is for the site operators and operations staff who deploy and run TuraIDC. It covers only how to get the site running, how to configure the business, how to run operations, and how to diagnose problems; for system architecture, API contracts, or code changes, see the [Developer Documentation](../develop/index.md).

## 1. Enter by Stage

| Stage                       | What to Complete                                                                                               | Entry Point                                          |
| --------------------------- | -------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| Deployment and Installation | Choose a deployment method, prepare the environment, and bring up the backend and the three frontends          | [Deployment and Installation](deploy/index.md)       |
| Initial Configuration       | Site name and branding, email and SMS, payment channels, upstream providers                                    | [Initial Configuration](configure/index.md)          |
| Daily Operations            | Product listing, order provisioning, fund reconciliation, support tickets, notifications                       | [Daily Operations](operate/index.md)                 |
| Maintenance and Upgrades    | Version upgrades, backup and restore, log archiving, frontend site Nginx rules                                 | [Maintenance and Upgrades](maintain/index.md)        |
| Data Migration              | Migrate business data from the ZJMF Finance billing system or legacy IDC systems                               | [Data Migration](migrate/index.md)                   |
| Licensing and Compliance    | Confirm the mandatory obligations of the AGPL free path and determine whether a commercial license is required | [Open Source and Commercial Licensing](licensing.md) |
| Troubleshooting             | Diagnose site outages, a stalled queue, undelivered email, and similar failures                                | [Troubleshooting](troubleshooting.md)                |

## 2. Shortest Path to Launch

1. Complete the deployment with one of the methods described in [Deployment and Installation](deploy/index.md), then configure scheduled tasks, the queue, and the heartbeat as described in [Deployment and Scheduling](deploy/scheduling.md) — skip this step and automatic provisioning, automatic renewal, and notifications will not run.
2. Go to [Initial Configuration](configure/index.md) and set up the site name and branding, email and SMS, payment channels, and upstream providers before listing any product.
3. Take one real small-value order through the whole flow of order placement → payment → automatic provisioning → notification, and confirm the closed loop before scaling up.
4. Set up [Backup and Restore](maintain/backup.md) as a scheduled task before you start selling publicly.

## 3. Environment Health Check

After deployment, you can check the PHP version, required extensions, and disabled functions at any time; re-check them after upgrading PHP or changing `php.ini`:

```bash
cd backend
php artisan app:doctor          # 必选项：版本、必需扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

## Related Documentation

- [Developer Documentation](../develop/index.md): System architecture, engineering conventions, API contracts, and plugin development.
