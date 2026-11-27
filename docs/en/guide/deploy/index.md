---
source: guide/deploy/index.md
sourceHash: f80a3303eb7dfb41a858704df620e3e88ef2c4a4d71f115460702c3b99e4b001
translatedAt: 2026-11-27
---

# Deployment and Installation

<DeploymentSelector />

## After Deployment

1. **Verify dependent services**: make sure the application and database are running normally.
2. **Enable scheduling**: per [Deployment and Scheduling](scheduling.md), configure scheduled tasks, queue groups and probes, and set up the VNC Relay resident process.
3. **Check the environment**: run `php artisan app:doctor` under `backend/` to confirm PHP, extensions and disabled functions meet the requirements.
4. **Test before launch**: use a test account to walk through order placement → payment → automatic provisioning → notification.

## Requirements

- The database must be compatible with MySQL 5.7.44 at minimum; MySQL 8.3 or newer is recommended. SQL must be compatible with both versions at the same time.
- Do not use `php artisan serve` in production; the backend runs through PHP-FPM.

## Related Documentation

- [Initial configuration](../configure/index.md): configure the site, notifications, payments and upstream providers.
- [Maintenance and upgrades](../maintain/index.md): backup, upgrades and log archiving.
- [Developer documentation](../../develop/index.md): local development, frontend builds and test commands.
