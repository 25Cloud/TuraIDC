---
source: guide/operate/index.md
sourceHash: ab19611d0c1edc8de2cc86dfd58f2dd50a758e53005f544bccb6933459d0e3e7
translatedAt: 2026-10-26
---

# Daily Operations

Day-to-day business operations after the system goes live: listing products, handling orders and provisioning, reconciliation and refunds, maintaining users and referral relationships, replying to tickets, and managing notifications.

## 1. Operations Map

| Area                                                   | What You Do Here                                                                                                                     |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------ |
| [Products and Billing](products.md)                    | Product groups and config options, pricing across multiple billing cycles, stock and listing                                         |
| [Orders and Automatic Provisioning](orders.md)         | Order placement, payment, automatic provisioning, retry on failure, manual re-run, and suspend/release                               |
| [Finance and Statements](finance.md)                   | Balance and top-up, invoices and statements, refunds, reconciliation rules, and inconsistency investigation                          |
| [Users and Referral](users.md)                         | Registration, sign-in and risk control, real-name verification, membership tiers, agent discount, referral commission and withdrawal |
| [Tickets and Customer Support](tickets.md)             | Ticket departments and permissions, assignment and replies, canned replies, timeout closure, upstream transfer                       |
| [Notifications and In-site Messages](notifications.md) | Notification templates, trigger points, send rate limiting and retry on failure, send log investigation                              |

## 2. Recommended Daily Checks

1. **Unpaid orders**: check the count and amount of overdue unpaid orders and decide whether payment reminders are needed.
2. **Provisioning failure queue**: instances that were paid for but not provisioned must be handled the same day — the user has already paid.
3. **Tickets and timeouts**: answer unanswered tickets first, so the timeout auto-close is not triggered.
4. **Accounting consistency**: verify that balance changes match the accounting ledger; if something is off, investigate it as described in [Finance and Statements](finance.md).
5. **Notification failures**: repeated email or SMS failures usually mean a channel configuration or quota problem; see [Notifications and In-site Messages](notifications.md).

## 3. Non-Negotiable Operating Boundaries

- Payment records are only ever changed in status, **never physically deleted**; any adjustment involving funds must leave a traceable record.
- Order and renewal amounts are recomputed server-side according to the rules, and client-side parameters must not be used as the basis for an amount — the same rule applies when editing an order by hand.
- Instances already purchased by users do not become invalid when a product is delisted; delisting only affects new purchases.
- Operations that delete tables are never performed, because upgrade and migration paths depend on a stable table structure.

## Related Documents

- [Initial Configuration](../configure/index.md): go back here first if payment and upstream are not configured yet.
- [Troubleshooting](../troubleshooting.md): abnormal symptoms encountered during operations and how to handle them.
