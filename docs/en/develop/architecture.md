---
status: current
updated: 2026-09-13
owner: backend-platform
source: develop/architecture.md
sourceHash: 3fec19539f44c9ba8385420bcc0b164a9fb01fa1b3058990e74e4f95b6c69fa4
translatedAt: 2026-10-26
---

# Current Architecture

This document is the current source of truth for the system architecture. It is written for developers new to the project, agents, and backend/frontend/operations collaborators, and describes the repository layout, the backend and frontend architecture, how the system runs locally and in production, and the conventions for the database and documentation assets. Details follow the running code, the tests, and the live database.

## 1. Repository Layout

```text
turaidc/
├── backend/                  Laravel 12 后端
├── frontend-admin-v3/        当前管理端，Vue 3 + TDesign
├── frontend-user-v3-www/     官网与用户入口，Vue 3 + Element Plus
├── frontend-user-v4-console/ 新版用户控制台，Vue 3 + TDesign
├── shared/                   跨前端共享包
└── docs/                     项目长期开发文档
```

The paths `frontend-admin`, `frontend-client`, `frontend-user-v3-console`, `frontend-www-v2`, and `frontend-console-v2` do not exist. If these paths appear in documentation or scripts, treat them as historical references first and verify them against the real directories.

## 2. Backend Architecture

Technology baseline:

- PHP 8.3+
- Laravel 12
- Sanctum 4 token authentication
- MySQL 8
- Redis cache
- `database` queue driver
- Local unified entry point: `php artisan app:serve`

Main directories:

- `app/Http/Controllers/Admin/V2/`: admin-side API controllers.
- `app/Http/Controllers/Client/V2/`: client-side API controllers.
- `app/Http/Controllers/`: public site API and base classes, such as `SiteHomeController`, `SiteProductController`, `SecureAssetController`.
- `app/Http/Requests/Admin/V2|Client/V2/`: admin-side and client-side FormRequests.
- `app/Http/Resources/Admin/V2|Client/V2/`: admin-side and client-side API Resources; the remaining Resources are organized by public business domain.
- `app/Services/`: the main business layer, already consolidated by domain into `Finance`, `Order`, `ProductCatalog`, `ClientServiceConsole`, `Provisioning`, `Upstream`, `Supplier`, `Integrations`, `OpenApi`, `ZjmfUpstream`, `Notification`, `Ticket`, and others.
- `app/Services/Integrations/Plugins/`: plugin scanning, installation, configuration, runtime registration, and capability adaptation.
- `plugins/`: implementation directory for plugins such as payment, real-name verification, SMS, mail, and upstream servers.
- `plugins/servers/zjmf_finance/`: hosts the ZJMF Finance data-plane integration plus ZJMF-specific capabilities such as legacy password compatibility and invoice recovery; the compatibility implementations live in its `lib/` and are registered by the plugin Provider.
- `app/Constants/`: statuses, enums, business constants.
- `app/Console/Commands/`: Artisan commands, including migration, sync, VNC Relay, and `app:serve`.

Route responsibilities:

- `routes/api.php`: public site V2 API, mainly `/api/v2/site/*` plus `/api/health` and `/api/ready`, and the ticket upstream reply callback `/ticket_reply/sync` (with its own signature verification).
- `routes/v2-admin.php`: admin-side V2 API, passing through `auth:sanctum` + `ensure.admin` + `permission:{code}`.
- `routes/v2-client.php`: client-side V2 API, passing through `auth:sanctum` + `ensure.client`; callbacks and public entry points such as payment, real-name verification, and VNC token have their own signature verification and rate limiting.
- `routes/v2-open.php`: open API (`/api/v2/open/*`), API Key authentication (`api.key:{domain},{action}` middleware, scope authorization + IP allowlist) plus `throttle:open-api` rate limiting, for system-to-system integration and resale-chain calls.
- `routes/v2-zjmf-upstream.php`: ZJMF upstream protocol entry (`/api/v2/zjmf/*`), allowing ZJMF Finance to connect to this system as an upstream provider; `/zjmf_api_login` exchanges for a JWT and then passes through the `zjmf.upstream` middleware.
- `routes/console.php`: scheduled tasks (heartbeat, queue consumption, probes, and periodic cleanup).
- `routes/web.php`: non-API entry points (installation wizard, SEO dynamic rendering, robots/sitemap) and static compatibility.

Direct API refactor route conventions:

- The legacy interfaces `/api/admin/*`, `/api/client/*`, and `/api/site/*` have been removed; no compatibility layer is registered or retained.
- Current business interfaces all use the `/api/v2/admin/*`, `/api/v2/client/*`, and `/api/v2/site/*` namespaces.
- The v2 routes still reuse the existing authentication, permission codes, business Services, plugin boundaries, and unified response envelope; the changes are concentrated in FormRequest, Resource/Response DTO, field projection, pagination, and sub-resource splitting.
- For specific tasks, interface splitting, and acceptance criteria, see `docs/internal/designs/backend/direct-api-refactor.md`.

Response conventions:

- Success always uses `code = 0`.
- The JSON envelope is generated by `App\Traits\ApiResponse` / `App\Support\ApiResponseBuilder`.
- The pagination structure lives inside `data`: `list`, `total`, `page`, `page_size`.
- Validation failures uniformly return `42200`, with field errors in `data.errors`.
- New v2 interfaces must not inherit the legacy `per_page`, mixed list/detail payloads, wide Resources, or deeply aggregated responses; the default JSON response should stay within `100KB`.

Key business constraints:

- Controllers do not write complex business logic directly and do not call third-party `Http::*` directly.
- Third-party and upstream calls go through dedicated layers such as the plugin directory, `Services/Integrations/Plugins`, and `Services/Upstream`.
- `zjmf_finance_api` is an independent provider key and must not be normalized into `hosting_panel_api`.
- The `payments` table records only real third-party fund inflows; it does not record balance payments, manual provisioning, or free orders. Physical deletion of a Payment is not allowed.
- Finance, orders, balance, referral commission, provisioning, and callbacks must account for transactions, idempotency, and audit fields.

Current state of third-party pluginization:

- Plugin metadata and enabled configuration are carried by `integration_plugins` and `integration_plugin_configs`.
- Plugins live in `backend/plugins/{domain}/{slug}/` by capability domain; the current domains include `addons`, `captcha`, `certification`, `gateways`, `mail`, `servers`, `sms`, and others.
- Sensitive fields in plugin configuration are stored encrypted; the frontend only shows whether they are configured plus a masked preview.
- Payment, real-name verification, SMS, mail, and upstream server capabilities are invoked through internal platform interfaces/managers; business Controllers must not depend directly on plugin implementation classes.
- Upstream provider drivers uniformly use the `UpstreamDriver` contract and the `Provides*` capability interface layering under `app/Services/Upstream/`; `ProviderRegistry` resolves drivers only from enabled upstream-domain plugins. Current provider keys: `hosting_panel_api` (in-app transport), `zjmf_finance_api` (ZJMF Finance plugin), `tura_open_api` (TuraIDC open API plugin, a pure proprietary-protocol resale chain).
- The ZJMF Finance upstream keeps its independent `zjmf_finance_api` provider key and must not be collapsed into `hosting_panel_api`.
- The ZJMF Finance adapter must explicitly declare the methods the platform can call; do not restore `__call()` dynamic forwarding.
- This system can also serve as an upstream for ZJMF Finance: `app/Services/ZjmfUpstream/` + the `/api/v2/zjmf` route, with the protocol documented in `docs/develop/integrations/zjmf-upstream-api.md`.

Responsibility boundaries for orders, invoices, and top-up records:

- `orders` is the purchase contract, carrying which product the user bought, the config option, duration, quantity, and the config pricing snapshot; `config_snapshot` and `config_pricing_snapshot` are displayed only on the order detail.
- `invoices` is the settlement voucher, carrying amount due, amount paid, invoice status, invoice line items, and payment details; the invoice detail does not display the product config snapshot.
- `payments` is the third-party fund inflow record, and only records real receipts from external channels such as Alipay; balance payments, admin manual provisioning, and free orders do not produce a Payment.
- The three record types are linked by ID; detail pages keep only the necessary navigation or traceability references and do not inline redundant fields that belong to another responsibility.
- Order, invoice, and payment status enums all come from `shared/statusConfig.js`; frontend status display should reuse the shared status capability first.

## 3. Frontend Architecture

### 3.1 `frontend-admin-v3`

- Role: the current admin side.
- Tech: Vue 3, Vite, TypeScript, TDesign Vue Next, TDesign Icons Vue Next, Pinia, Less.
- Entry points: `src/main.ts`, `src/permission.ts`, `src/router/`, `src/store/`.
- Pages: `src/pages/`, organized by business domain.
- API: `src/api/`.
- Styles: `src/style/`, following TDesign/TDesign Starter tokens.
- Verification: `cd frontend-admin-v3 && pnpm run build`.

### 3.2 `frontend-user-v3-www`

- Role: official website portal — home page, product display and purchase, notices, help center, and legal terms pages. Login, registration, and the user center have moved to `frontend-user-v4-console` (`src/pages/client-auth/` and `src/pages/client/`); this app no longer contains authentication routes.
- Tech: Vue 3, Vite, Element Plus, Pinia, Sass.
- Entry points: `src/main.js`, `src/app/` (bootstrap, http, router, runtime, stores), `src/router/`.
- Pages: `src/pages/website`, `src/pages/common`. Note that the implementation bodies live in `src/views/website`; most `index.vue` files under `src/pages` are only route forwarding shells pointing at `views` (for example, `pages/website/products/index.vue` is only 154 bytes).
- Domain logic: `src/domains/` (currently only `products`).
- Styles: `src/assets/styles/`.
- SEO: the public pages of the official website (home, products, landing pages, notices/help, and so on) are dynamically rendered as complete HTML by the Laravel backend (reading the database site name/Logo/meta plus a body snapshot); the frontend Nginx forwards public paths to the backend `/seo/www/{path}`; sitemap.xml and robots.txt are also generated dynamically by the backend. See `backend/app/Services/Site/SeoRenderService.php` for details.
- Verification: `cd frontend-user-v3-www && pnpm run build`; for refactors, additionally run `pnpm run verify:refactor`.

### 3.3 `frontend-user-v4-console`

- Role: the new user console.
- Tech: Vue 3, Vite, TypeScript, TDesign Vue Next, TDesign Icons Vue Next, Pinia, Less.
- Entry points: `src/main.ts`, `src/permission.ts`, `src/router/`, `src/store/`.
- Pages: `src/pages/client/`.
- Domain logic: `src/domains/`, `src/composables/`, `src/api/`.
- Shared components: reuse `shared/user-v3` and `@turaidc/shared` first.
- Verification: `cd frontend-user-v4-console && pnpm run build`; for refactors, additionally run `pnpm run verify:refactor`.

### 3.4 `shared`

Cross-frontend shared package, currently carrying:

- `status`: status mappings, status tags.
- `runtime`: session, network, branding, route loading, HTTP runtime.
- `content`: Markdown/HTML safe rendering.
- `user-v3`: general TDesign-style components for the user console.

When status copy, status colors, status tags, or console base components are involved, check `shared` first.

## 4. Local Startup

```bash
cd backend
php artisan app:serve

pnpm run dev:admin-v3
pnpm run dev:user-v3-www
pnpm run dev:user-v4-console
```

Do not use `php artisan serve` in place of `app:serve`.

## 5. Production Runtime

- Backend production entry: PHP-FPM pointing at `backend/public`.
- Scheduling and queues: driven by BaoTa Panel's per-minute `php artisan schedule:run`, which runs three resident entry points — `scheduler:heartbeat` (the only clock source, dispatching scheduled tasks deduplicated by 15-minute slot, without consuming the queue inline), `queue:drain` (background subprocesses consuming the queue, creating one Worker per queue group across the three groups `provision`, the business group `referral,notification,coupon,default`, and the schedule group `automation`; the drain lock guarantees the same queue is not consumed concurrently), and `scheduler:liveness` (the heartbeat liveness probe). There is also a per-minute cleanup of uploaded ticket files and a weekly cleanup of open API usage logs.
- Queue groups are configured by `TURAIDC_PROVISION_QUEUES` / `TURAIDC_BUSINESS_QUEUES` / `TURAIDC_SCHEDULE_QUEUE`; provision-type task Workers can time out at up to 1200 seconds, and missed 15-minute slots are not replayed automatically by default.
- VNC Relay: runs independently via `php artisan vnc:relay`, auto-restarted by the BaoTa daemon or a process manager, and not launched by the heartbeat task.
- Asynchronous tasks may have a 0-60 second delay.
- Frontends are built separately and published as site statics:
  - `frontend-admin-v3/dist`
  - `frontend-user-v3-www/dist`
  - `frontend-user-v4-console/dist`
- Official website SEO rendering (under container deployment): Nginx inside the `frontends` container forwards the official website's public paths (`/`, `/products`, landing pages, `/notices`, `/help` and their details, `/products/{id}`) to `/seo/www/{path}` on the `app` container; Laravel assembles the complete HTML using `http://frontends:8081/index.html` (or a local `file://` path under source deployment) as the template. Related configuration is in `config/idc.php → idc.seo` and `deploy/docker/frontends/nginx-default.conf`.

## 6. Database Conventions

- Table and column names use `snake_case`.
- Migrations only add files and never modify historical migrations.
- The repository contains historical aggressive migrations; never re-run them.
- The real table structure follows the live database's `information_schema`; `docs/develop/database.md` is the summary document.

## 7. Documentation and Historical Asset Conventions

- `docs/` is the record system for long-term development, integration, deployment, and maintenance.
- Phase execution records, review snapshots, one-off audit reports, and plans are maintained by the `docs/` classification; rules that still apply should be distilled into the corresponding source of truth.
- Unpacked directories of the legacy system, frontend zip import packages, root-level SQL dumps, and old build artifacts are temporary migration/import assets and should be cleaned up or moved out of the repository once confirmed unused.

## Related Documents

- [Backend engineering standards](backend.md) / [Frontend project standards](frontend.md): engineering constraint details for each side.
- [User documentation](../guide/index.md): entry points for deployment, scheduling, and day-to-day operations.
- [Design document index](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/designs/index.md): records of architecture- and solution-level decisions.
