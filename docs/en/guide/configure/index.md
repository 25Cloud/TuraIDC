---
source: guide/configure/index.md
sourceHash: 699c1b9b53281560881fa6d327be8929a120da95a2b79a0964ebc9cc0d6338a1
translatedAt: 2026-10-26
---

# Initial Configuration

This page covers the configuration work between a finished deployment and selling publicly. Finish it in the order below before listing any product — go live with the payment channel or the upstream provider still unconfigured and users will pay without ever getting a machine.

## 1. Configuration Order

| Order | Config option                                  | Description                                                                                                                                 |
| ----- | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| 1     | [Site and branding settings](site-settings.md) | Site name, browser title, Logo, theme color, and other information visible across all three frontends                                       |
| 2     | [Email and SMS](mail-and-sms.md)               | Notification channel; leave it unconfigured and registration verification codes, provisioning notifications, and ticket alerts never go out |
| 3     | [Payment channel integration](payment.md)      | The channel users pay through, including callback URLs and key discipline                                                                   |
| 4     | [Upstream provider integration](upstream.md)   | Where machines get provisioned from: credentials, inventory and pricing sync, connectivity self-check                                       |

## 2. Go-Live Checklist

- The site name and browser title carry your own brand, and the change is live in the admin, the frontends, and the official site.
- A test email arrives, and a test verification code arrives by SMS.
- The payment channel's asynchronous callback URL points to the production domain and is registered on the channel side.
- The upstream provider connectivity self-check passes: at least one product returns real inventory and price.
- The scheduled task, queue, and heartbeat covered in [Deployment and Scheduling](../deploy/scheduling.md) are running.

## 3. Related Constraints

- Keys, tokens, and private keys go only into server environment variables or encrypted admin configuration — never into documents, screenshots, or tickets.
- When a signing or token secret reads back empty, the service must refuse to serve; it must never degrade to “compute as usual with an empty key”.
- After changing branding and site information, static assets may be cached; confirm the cache policy per [Frontend Site Nginx Rules](../maintain/nginx-rules.md).

## Related Documentation

- [Deployment and Installation](../deploy/index.md): start here if the deployment is not finished yet.
- [Daily Operations](../operate/index.md): once configuration is done, move on to the day-to-day work on products, orders, and finances.
- [Rainyun RCS plugin](rainyun-rcs.md): installation prerequisites, provider pricing, direct-connect console, and integration boundaries.
