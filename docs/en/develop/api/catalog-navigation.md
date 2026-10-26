---
source: develop/api/catalog-navigation.md
sourceHash: b99feef6fcccf934b514c0181eb883fe38548c4627c958f16e491ab95f11a1b1
translatedAt: 2026-10-26
---

# Backend API Catalog Navigation

## 1. Purpose of This Document

- Provide a human-readable business navigation for the auto-generated catalog in `docs/develop/api/backend-api-catalog.md`
- Aligned on: `2026-09-19`
- This document is maintained by hand and is never overwritten by the export script
- Actual methods, controller actions, middleware, and authentication still follow `docs/develop/api/backend-api-catalog.md`

## 2. Group Overview

| Group                               | Path range                               | Default authentication / characteristics                                                                                                                             |
| ----------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Admin side                          | `/api/v2/admin/*`                        | Except `/api/v2/admin/login`, defaults to `auth:sanctum + ensure.admin`; most endpoints additionally require `permission:{code}`                                     |
| Client side                         | `/api/v2/client/*`                       | Public authentication, callbacks, VNC tokens, and real-name callbacks share the same prefix; all other principal endpoints default to `auth:sanctum + ensure.client` |
| Public site                         | `/api/v2/site/*`                         | Official site, public products, public content, quotes, site config                                                                                                  |
| Open API                            | `/api/v2/open/*`                         | API Key authentication (scope authorization + IP whitelist) + `throttle:open-api` rate limiting, used for system-to-system integration and resale-chain calls        |
| ZJMF upstream protocol              | `/api/v2/zjmf/*`                         | Exchanges JWT via `/zjmf_api_login`, then passes through the `zjmf.upstream` middleware, for ZJMF Finance to integrate as a downstream                               |
| Other public / controlled endpoints | `/api/health`, `/api/secure-assets/view` | Health check, or access verified by resource parameters                                                                                                              |

## 3. Business Domain Overview

### Admin side (admin)

| Business domain              | Key path prefixes                                                                                                               | Description                                                                                                                  |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| Authentication               | `/api/v2/admin/login`, `/api/v2/admin/auth/*`                                                                                   | Administrator login, profile, profile update, logout                                                                         |
| Dashboard                    | `/api/v2/admin/dashboard*`                                                                                                      | Home metrics and recent invoices                                                                                             |
| Users                        | `/api/v2/admin/users*`                                                                                                          | User list, details, recharge, login as user, nested invoices / services / logs / tickets                                     |
| Invoices                     | `/api/v2/admin/invoices*`, `/api/v2/admin/users/{user}/invoices*`                                                               | The current primary finance entity is the invoice; order routes are no longer the primary carrier                            |
| Products                     | `/api/v2/admin/products*`, `/api/v2/admin/product-categories*`, `/api/v2/admin/product-groups*`, `/api/v2/admin/product-types*` | Products, categories, groups, types, bulk sync, and sorting                                                                  |
| Service instances            | `/api/v2/admin/services*`, `/api/v2/admin/users/{user}/services*`                                                               | Admin-side instance overview and instance operations under a user                                                            |
| Suppliers                    | `/api/v2/admin/suppliers*`                                                                                                      | Supplier balance, product pulling, bulk integration                                                                          |
| Integration plugins          | `/api/v2/admin/integration-plugins*`                                                                                            | Scan, install, configure, enable/disable, and health-check payment, real-name verification, SMS, email, and upstream plugins |
| Agent discounts              | `/api/v2/admin/agent-discounts*` (discount groups / agent groups / discount matrix)                                             | Agent discount system configuration, including the default discount rate of agent groups                                     |
| Open API                     | `/api/v2/admin/open-api*`                                                                                                       | Open API switch, quota and key management, usage audit                                                                       |
| Coupons                      | `/api/v2/admin/coupons*`, `/api/v2/admin/coupon-campaigns*`                                                                     | Coupons and campaign coupon issuing                                                                                          |
| Referral commission          | `/api/v2/admin/referral*`, `/api/v2/admin/referral-withdrawals*`                                                                | Commission overview, rewards, balance changes, withdrawal review                                                             |
| Real-name verification       | `/api/v2/admin/verifications*`                                                                                                  | Real-name review, details, editing, history, unbinding                                                                       |
| Tickets                      | `/api/v2/admin/tickets*`                                                                                                        | Ticket list, reply, close, assign, image upload, upstream forwarding rules and logs                                          |
| Content and media            | `/api/v2/admin/content*`, `/api/v2/admin/media-files*`                                                                          | Article categories, article content, media library                                                                           |
| Logs                         | `/api/v2/admin/logs*`                                                                                                           | API, SMS, email, task, system, and login logs, plus cleanup                                                                  |
| Settings and site operations | `/api/v2/admin/settings`, `/api/v2/admin/site/home-hero`                                                                        | System configuration (including groups such as Open API and ticket pre-replies), site home Hero                              |
| Scheduling                   | `/api/v2/admin/schedules*`                                                                                                      | Scheduling overview, run ledger, and manual trigger                                                                          |
| Member levels                | `/api/v2/admin/member-levels*`                                                                                                  | Level configuration                                                                                                          |

### Client side (client)

| Business domain        | Key path prefixes                                                                                     | Description                                                                                                                                           |
| ---------------------- | ----------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Authentication         | `/api/v2/client/login`, `/api/v2/client/register`, `/api/v2/client/auth/*`, `/api/v2/client/password` | Login, registration, password recovery, profile, notification preferences, Alipay account                                                             |
| Real-name verification | `/api/v2/client/verification*`                                                                        | Status, initialization, QR code, retry, callback, scan                                                                                                |
| Invoices               | `/api/v2/client/invoices*`                                                                            | On the current user side, the invoice is the primary entity for ordering and payment                                                                  |
| Recharge               | `/api/v2/client/recharge*`                                                                            | Recharge ordering and status polling                                                                                                                  |
| Service instances      | `/api/v2/client/services*`, `/api/v2/client/vnc-tokens/*`                                             | Instance details, monitoring, renewal, reinstall, VNC, NAT, security groups, traffic packages, custom panel area, and upstream resource reverse proxy |
| Balance                | `/api/v2/client/balance-logs*`                                                                        | Balance transactions and summary                                                                                                                      |
| Coupons                | `/api/v2/client/coupons*`                                                                             | Coupons, summary, claim; `coupons/public*` still requires client authentication                                                                       |
| Referral commission    | `/api/v2/client/referral*`                                                                            | Overview, rewards, balance changes, withdrawal applications                                                                                           |
| Tickets                | `/api/v2/client/tickets*`                                                                             | List, details, reply, close, image upload                                                                                                             |
| Content                | `/api/v2/client/content/overview`, `/api/v2/client/notices*`, `/api/v2/client/help-articles*`         | User-side notices and help center                                                                                                                     |
| API keys               | `/api/v2/client/api-keys*`                                                                            | Key create/update/delete, enable/disable, and usage logs; the plaintext is returned only once at creation                                             |
| Payment callback       | `/api/v2/client/payment/alipay/notify`                                                                | Alipay asynchronous notification                                                                                                                      |

### Open API (open)

Business domain × read/write scope combined authorization (`products/orders/services/finance` × `read/write`); write endpoints additionally apply `throttle:open-api-write`:

| Business domain | Key path prefixes                                                                                               | Description                                                       |
| --------------- | --------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------- |
| Products        | `GET /api/v2/open/products*` (list / details / quote)                                                           | Catalog import and per-cycle quoting                              |
| Orders          | `GET/POST /api/v2/open/orders*` (create order, balance payment)                                                 | Idempotent ordering; the invoice projection includes `service_id` |
| Services        | `GET/POST /api/v2/open/services*` (details / renewal preview / reinstall options / power / renewal / reinstall) | For downstream drivers to perform state sync and control          |
| Finance         | `GET /api/v2/open/balance`                                                                                      | Supplier balance query, for low-balance alerts                    |
| Keys            | `GET /api/v2/open/keys/self`, `POST /api/v2/open/keys/self/disable`                                             | Key self-service view and disable                                 |

### Public site and other public endpoints

| Business domain             | Key path prefixes                                                                                                          | Description                                              |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| Site config                 | `/api/v2/site/config`, `/api/v2/site/home`, `/api/v2/site/home-hero`                                                       | Official site base config, home aggregation, home Hero   |
| Public products             | `/api/v2/site/product-types`, `/api/v2/site/product-groups*`, `/api/v2/site/product-categories*`, `/api/v2/site/products*` | Official site product browsing, stock, quotes            |
| Public content              | `/api/v2/site/content/overview`, `/api/v2/site/notices*`, `/api/v2/site/help-articles*`                                    | Official site notices and help center                    |
| Health check                | `/api/health`                                                                                                              | Service availability probe                               |
| Controlled resource viewing | `/api/secure-assets/view`                                                                                                  | Resource viewing; not an ordinary public static resource |

## 4. Core Business Flows -> Key Endpoints

### Official Site Purchase -> Invoice Creation -> Payment -> Provisioning

| Step                      | Endpoint                                             |
| ------------------------- | ---------------------------------------------------- |
| Browse products           | `GET /api/v2/site/products`                          |
| View details              | `GET /api/v2/site/products/{productId}`              |
| Get a quote               | `POST /api/v2/site/products/{productId}/quote`       |
| Create an invoice         | `POST /api/v2/client/invoices`                       |
| Balance payment           | `POST /api/v2/client/invoices/{id}/pay/balance`      |
| Alipay payment            | `POST /api/v2/client/invoices/{id}/pay/alipay`       |
| Query payment status      | `GET /api/v2/client/invoices/{id}/pay/alipay/status` |
| View invoice details      | `GET /api/v2/client/invoices/{id}`                   |
| View the service instance | `GET /api/v2/client/services/{id}`                   |

### Service Renewal

| Step                     | Endpoint                                   |
| ------------------------ | ------------------------------------------ |
| View renewal preview     | `GET /api/v2/client/services/{id}/renew`   |
| Generate renewal invoice | `POST /api/v2/client/services/{id}/renew`  |
| Subsequent payment       | Reuse `/api/v2/client/invoices/{id}/pay/*` |

### Traffic Package Add-on

| Step                    | Endpoint                                                   |
| ----------------------- | ---------------------------------------------------------- |
| List traffic packages   | `GET /api/v2/client/services/{id}/traffic-packages`        |
| Get the add-on quote    | `POST /api/v2/client/services/{id}/traffic-packages/quote` |
| Create the add-on order | `POST /api/v2/client/services/{id}/traffic-packages/order` |

### Custom Panel Area (upstream module page)

Custom tabs issued by the upstream (for example ZJMF Finance) for panel-type products are rendered by this system in an isolated iframe; content and actions go through the same short-lived ticket, the browser no longer holds upstream credentials, and upstream domains and static assets are also reverse-proxied through this system:

| Step                                            | Endpoint                                                                  |
| ----------------------------------------------- | ------------------------------------------------------------------------- |
| Query available tabs / capabilities             | `GET /api/v2/client/services/{id}/console/capabilities`                   |
| Exchange for a short-lived ticket               | `POST /api/v2/client/services/{id}/console/tickets`                       |
| Fetch the module page HTML                      | `GET /api/v2/client/services/{id}/console-area/content?ticket=…&module=…` |
| Reverse-proxy upstream static assets and images | `GET /api/v2/client/services/{id}/console-area/asset?ticket=…&path=…`     |
| Post back module actions                        | `POST /api/v2/client/services/{id}/console-area/actions?ticket=…`         |

The `asset` endpoint only accepts paths starting with `/`, and the target host is derived from the supplier bound to the service, so it cannot be used as an open reverse proxy for arbitrary URLs. When this endpoint returns CSS, it rewrites the relative `url()` references inside the file into root-relative addresses of the same endpoint: the cross-origin reverse proxy replaces the base against which relative references are resolved (browsers resolve them against the proxied URL), so passing them through verbatim would make images in the CSS request a path that does not exist on this site. The rewrite applies to CSS only — relative paths inside JS have no statically parseable form.

`area_preload_all` returned by `capabilities` indicates whether all custom panel areas are preloaded on the first screen (treated as enabled by default). The panel content endpoint is `no-store`, so the browser cache cannot be reused, and panels can only be preloaded by keeping their iframes resident: when enabled, each custom area mounts one instance, so switching tabs no longer waits and there is no need to switch back and forth hitting the upstream repeatedly. It is controlled by the backend setting `system.console_area_preload_all_enabled` (admin side "Integration plugins → Upstream → Panel area first-screen preload"), and is deliberately not written into the capability cache — a change made by an administrator takes effect immediately, without waiting for the 300-second capability cache to expire.

### Real-name Verification

| Step                              | Endpoint                                                                                |
| --------------------------------- | --------------------------------------------------------------------------------------- |
| Query status                      | `GET /api/v2/client/verification/status`                                                |
| Get the fee config                | `GET /api/v2/client/verification/fee-config`                                            |
| Initialize real-name verification | `POST /api/v2/client/verification/init`                                                 |
| Get the QR code                   | `POST /api/v2/client/verification/qrcode`                                               |
| Retry the flow                    | `POST /api/v2/client/verification/restart`                                              |
| Asynchronous callback             | `GET /api/v2/client/verification/callback`, `POST /api/v2/client/verification/callback` |
| Admin query                       | `GET /api/v2/admin/verifications`                                                       |
| Admin history                     | `GET /api/v2/admin/verifications/{user}/history`                                        |

### Tickets

| Step         | Endpoint                                     |
| ------------ | -------------------------------------------- |
| User submits | `POST /api/v2/client/tickets`                |
| User replies | `POST /api/v2/client/tickets/{id}/reply`     |
| User closes  | `POST /api/v2/client/tickets/{id}/close`     |
| Admin list   | `GET /api/v2/admin/tickets`                  |
| Admin reply  | `POST /api/v2/admin/tickets/{ticket}/reply`  |
| Admin assign | `POST /api/v2/admin/tickets/{ticket}/assign` |

### Content and Media

| Step                            | Endpoint                                  |
| ------------------------------- | ----------------------------------------- |
| Official site notice list       | `GET /api/v2/site/notices`                |
| Official site help list         | `GET /api/v2/site/help-articles`          |
| User-side content overview      | `GET /api/v2/client/content/overview`     |
| Admin article list              | `GET /api/v2/admin/content/articles`      |
| Admin upload cover / body image | `POST /api/v2/admin/content/upload-image` |
| Admin media library             | `GET /api/v2/admin/media-files`           |

### Site Operations Configuration

| Step                       | Endpoint                            |
| -------------------------- | ----------------------------------- |
| Fetch system configuration | `GET /api/v2/admin/settings`        |
| Save system configuration  | `POST /api/v2/admin/settings`       |
| View home Hero             | `GET /api/v2/admin/site/home-hero`  |
| Update home Hero           | `POST /api/v2/admin/site/home-hero` |

## 5. Maintenance Notes

- After adding or removing endpoints, first run `php backend/scripts/export_api_inventory.php` to regenerate `docs/develop/api/backend-api-catalog.md`
- If the primary business entity changes, for example from `orders` to `invoices`, the "Business Domain Overview" and "Core Business Flows" sections of this document must be updated as well
- When adding a public endpoint, also check which of the following it belongs to:
  - Site public
  - Public authentication capabilities under the client prefix
  - Callback endpoint
  - Controlled resource endpoint
