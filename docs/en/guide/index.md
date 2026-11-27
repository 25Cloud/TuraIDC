---
source: guide/index.md
sourceHash: 73f37b2caf1aa6519671deeaef2504df256ea222236eb76ddf6be5d3661620f5
translatedAt: 2026-11-27
---

# User Documentation

This documentation is for site operators and operations staff who deploy and run TuraIDC.

For system architecture, API contracts, or code changes, see the [Developer Documentation](../develop/index.md).

## Introduction

<p align="center">
    <img src="/branding/turaidc-logo.png" alt="TuraIDC Logo" width="400" />
</p>
TuraIDC is a business and finance management system for IDC and cloud service providers. It covers product management, order billing, automatic provisioning, invoices, top-ups, referral commissions, tickets and notifications, and ships with three frontends: the admin panel, the official website and the user console. Upstream providers and third-party services are connected through an extensible plugin mechanism.

## Core Capabilities

| Area               | Capabilities                                                                                                           |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| 🛒 Products        | Product groups, config options, stock management, automatic provisioning, multiple billing cycles                      |
| 💰 Finance         | Payments, balance, top-ups, invoices, refunds, reconciliation, balance history                                         |
| 👤 User management | Registration and login, optional real-name verification, membership tiers, referral commissions, promotion withdrawals |
| 🎫 Tickets         | Department assignment, ticket replies, automatic timeout closure, auto-replies, upstream/downstream handoff            |
| 🔔 Notifications   | Internal messages, email, SMS (configurable templates)                                                                 |
| ⚙️ Automation      | Scheduled tasks, heartbeat monitoring, scheduled reconciliation, log archiving                                         |
| 🔌 Plugins         | Provider adapters, payment gateways, SMS, captcha and email are all integrated as plugins                              |

## Comparison with Other Products

Compared with similar products, TuraIDC offers the following characteristics:

- **Server-side pricing**: amounts, stock, coupons and renewals are all recalculated and locked on the server to prevent concurrent tampering.
- **Complete audit logs**: key funds, orders and admin actions are recorded, with export and archiving support.
- **Login risk controls**: account- and IP-level soft lockout, per-target captcha rate limiting and unified validation across entry points.
- **Plugin architecture**: providers, payments, SMS, captcha, real-name verification and email are integrated as plugins with clear, replaceable boundaries.
- **Automation safety nets**: scheduled tasks, heartbeat monitoring, scheduled reconciliation, retries and automatic suspend/release.
- **Open source and auditable**: free under AGPL-3.0-or-later; an optional commercial license is available (CNY 200/year).

## Quick Start

1. Open [Deployment and Installation](deploy/index.md) and choose a deployment method.
2. Open [Deployment and Scheduling](deploy/scheduling.md) to configure server-side scheduled tasks, queues and heartbeat monitoring.
3. Open [Initial Configuration](configure/index.md) to set the site name and branding, email and SMS, payment channels and upstream providers before listing products.
4. Create a test account and run through order placement → payment → automatic provisioning → notification; make it public only after it works.
5. Schedule [Backup and Restore](maintain/backup.md) before you start selling publicly.

## Environment Health Check

After deployment, you can check the PHP version, extensions and disabled functions at any time; re-check them after upgrading PHP or changing `php.ini`:

```bash
cd backend
php artisan app:doctor          # 必选项：版本、扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

## Related Documentation

- [Developer Documentation](../develop/index.md)
