---
source: guide/deploy/index.md
sourceHash: ceee0050b1ed01fc7d0608ad966805a2c7c6e27b45c09dddc16fcbfb2449d7ee
translatedAt: 2026-10-26
---

# Deployment and Installation

Deploy TuraIDC to a production environment. First choose from the table below the method that matches your server and operations workflow, then after deployment follow [Deployment and Scheduling](scheduling.md) to configure the scheduled task, queue, and readiness check — skip this step and automatic provisioning, automatic renewal, and notifications will not run.

<DeploymentSelector />

## 1. Choose a Method

| Method                                        | When to use                                                                                                                        |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| [Generic deployment](generic.md)              | Not tied to a panel or container: set up the backend, frontend, and process supervision manually per your environment requirements |
| [BaoTa Panel deployment](bt-panel.md)         | The server already runs BaoTa Panel to manage PHP, Nginx, and scheduled tasks                                                      |
| [Bare-metal source deployment](bare-metal.md) | No panel and no containers: run natively on nginx + PHP-FPM + systemd + cron                                                       |
| [Docker and 1Panel](docker-1panel.md)         | Deploy with Docker Compose, or manage containers centrally with 1Panel                                                             |

## 2. Required After Deployment

1. **Verify dependent services**: the application, database, and Redis are all running; Redis is required in production (distributed locks and cache).
2. **Configure scheduling**: per [Deployment and Scheduling](scheduling.md), set up the once-per-minute scheduled task, queue group, and heartbeat probe, and configure the VNC Relay long-running process.
3. **Check the environment**: run `php artisan app:doctor` under `backend/` to confirm the PHP version, required extensions, and disabled functions all meet the requirements.
4. **Run one real end-to-end flow**: use one small-value order to walk through "order placement → payment → automatic provisioning → notification".

## 3. Related Constraints

- The database must be compatible with MySQL 5.7.44 at minimum; 8.x is recommended. SQL must be compatible with both versions at the same time.
- Do not use `php artisan serve` in production; the backend entry point goes through PHP-FPM.
- Version upgrades run only newly added migrations; never drop tables, columns, or indexes.

## Related Documentation

- [Initial configuration](../configure/index.md): configure the site, notifications, payment, and upstream after deployment.
- [Maintenance and upgrades](../maintain/index.md): backup, upgrade, and log archiving.
- [Developer documentation](../../develop/index.md): local development, frontend builds, and test commands.
