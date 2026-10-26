---
status: current
updated: 2026-09-13
owner: backend-platform
source: develop/testing.md
sourceHash: e99cc960d8c33d3edb2a6030d2449499bcf29328ed1b9f59b1caefab9ccc837d
translatedAt: 2026-10-26
---

# Testing Guide

This document is the current testing guide, written for testing, backend, frontend, integration, delivery, and operations, and covers `backend`, the three frontends, `shared`, and their integration, regression, and pre-release acceptance.

## 1. Document Goal

This document defines the full test scope, test environments, execution order, test item checklist, automation commands, manual verification focus, defect severity levels, and delivery outputs of the current `turaidc` project.

When conflicts arise, resolve them in the following order:

1. The actual behavior of the current code
2. `AGENTS.md`
3. `docs/develop/architecture.md`
4. This document
5. Historical practice

## 2. General Testing Principles

- The goal is not merely to verify that a page opens, but to verify whether critical business flows and high-risk domains such as payment, billing, service, and tickets are stable in the current version.
- Automation prioritizes scenarios that are repeatable, decidable, and regressed frequently; manual testing focuses on visuals, interaction, real end-to-end business chains, third-party dependencies, and the quality of error messages.
- Backend, frontend, integration, and release acceptance are executed in dependency order, with no skipped steps.
- Every test run must record the test baseline explicitly: branch, commit hash, environment, database source, configuration source, and test time.
- High-risk changes must go through targeted regression; do not ship straight to production after running only the general build.

## 3. Test Layers

### 3.1 Smoke Testing

- Objective: quickly confirm that the system is basically usable; suitable for developer self-testing, before handing over to testing, and for the first check after a release.
- Duration target: 15 to 30 minutes.
- Focus:
  - The backend starts
  - Both frontends build
  - The administrator can log in
  - A user can log in
  - The site home page, product pages, user center, and admin home page are reachable
  - Core APIs do not return 500

### 3.2 Regression Testing

- Objective: confirm that changes did not break existing critical flows.
- Duration target: 0.5 to 1 working day.
- Focus:
  - The full flow of the changed business domain
  - The impact surface upstream and downstream of the changed business domain
  - Accompanying capabilities such as permissions, logs, notifications, caching, and scheduling

### 3.3 Full Testing

- Objective: complete business coverage and cross-app integration confirmation before a version release.
- Duration target: 1 to 2 working days.
- Focus:
  - Availability of every business module
  - Closed loops of key cross-domain flows
  - Multiple roles, multiple states, multiple error paths
  - Compatibility between the pre-release environment and production configuration

### 3.4 Release Acceptance

- Objective: confirm that build artifacts, configuration, scheduling, database, and post-launch entry points work.
- Focus:
  - Build artifacts are complete
  - All config options are present
  - Scheduling and queue behavior are normal
  - Primary flows such as login, order placement, payment callback, and service provisioning are available online

## 4. Test Environment Matrix

### 4.1 Local Development Environment

- Backend: PHP 8.3, Laravel 12, MySQL 8, Redis
- Official website and purchase entry: the port follows the Vite configuration of `frontend-user-v3-www`
- Admin side: the port follows the Vite configuration of `frontend-admin-v3`
- User console: the port follows the Vite configuration of `frontend-user-v4-console`
- Backend entry: `http://127.0.0.1:8000`
- Start commands:

```bash
cd backend
php artisan app:serve

cd frontend-user-v3-www
pnpm run dev

cd frontend-admin-v3
pnpm run dev

cd frontend-user-v4-console
pnpm run dev
```

### 4.2 Test Database Requirements

- Use a dedicated test database; do not reuse a production snapshot directly.
- To verify the fresh-install flow, run the following against an empty database:

```bash
python backend/scripts/install_db.py --reset
```

- To verify that the current schema baseline can be exported, run the following as well after updating the database structure:

```bash
php backend/scripts/export_database_structure.php
```

### 4.3 Required Config Options

- The database, Redis, APP_URL, FRONTEND_URL, and ADMIN_URL in `backend/.env` are correct.
- When third-party dependencies such as SMS, email, upstream panels, or Alipay are not ready, label them explicitly as "mocked", "unverified", or "local degradation behavior only".

## 5. Preparation Before Testing

### 5.1 Code Preparation

- Pull the target branch code.
- Confirm that Composer and pnpm dependencies are installed.
- Record the current commit hash:

```bash
git rev-parse HEAD
```

### 5.2 Data Preparation

- Fresh-install verification: an empty database plus `backend/scripts/install_db.py --reset` (available on Windows) or `backend/scripts/install_db.sh` (Shell environments)
- Normal regression verification: use the latest test baseline database
- When scenarios involve payment, bills, service, referral commission, tickets, or content, prepare the corresponding test data

### 5.3 Account Preparation

- **Administrator**: the username is fixed to `cerbo`, created by `backend/scripts/install_db.py` (or `install_db.sh` in Shell environments) when an empty database is initialized. The initial password comes from `INSTALL_ADMIN_PASSWORD`; when it is not configured, the script generates a random one and prints it only once. For the local test database, the password is whatever the local machine environment provides.
- **Regular user**: register one yourself or prepare test data according to the test objective.

A fresh installation guarantees only that the administrator account exists; products, suppliers, content, and similar data must be filled in according to the test objective. Test account passwords are obtained through controlled channels; **documents and commits record only the username and its purpose, never the password**.

## 6. Automated Test Entry Points

### 6.1 Backend Automation

Full backend test run:

```bash
cd backend
php artisan test
```

List the tests:

```bash
cd backend
php artisan test --list-tests
```

**Speeding up local development on Windows** (measured 2026-09): on Windows, `php artisan test` forwards output through a Symfony Process wrapper, and a single minimal test class can stall for minutes; running `vendor/bin/phpunit` directly on the same file takes only about 0.6 seconds (same `phpunit.xml.dist`, with `APP_ENV=testing` and the test database configuration taking effect identically). For incremental regression after local changes:

```bash
cd backend
# 单文件（最快，不扫描其余测试）
php vendor/bin/phpunit tests/Feature/目标测试.php
# 少量相关类（--filter 仍会扫描全部测试文件，文件多时明显慢于传路径）
php vendor/bin/phpunit --filter="测试类A|测试类B"
# 全量回归可加 opcache 降低框架编译开销（phpunit.xml 的 <ini> 对 opcache 无效，必须启动时 -d）
php -d opcache.enable_cli=1 vendor/bin/phpunit
```

Test dependencies: the `idc_test` test database (import the baseline `database/schema/mysql-schema.sql`, then run `php artisan migrate --env=testing --force`); when the local machine has no Redis, `phpunit.xml.dist` already switches the `redis_volatile` store to `array` (`CACHE_VOLATILE_DRIVER`), so no real Redis is needed.

Current backend test composition:

- `tests/Feature/`: a large number of business regression tests covering login, users, products, site, payment, billing, tickets, referral commission, scheduling, service console, VNC, security, blackhole lookup, and more
- `tests/Unit/`: unit tests for rules, numbering, snapshots, drivers, risk control, and similar

### 6.2 Admin Side Automation

The repository has no complete unit test framework yet, but it does have minimal script verification and build verification:

```bash
cd frontend-admin-v3
pnpm run build
pnpm run test
```

### 6.3 User Side Automation

```bash
cd frontend-user-v3-www
pnpm run build
pnpm run test:e2e
```

`test:e2e` is a Playwright suite (`tests/e2e/`); the cases intercept read-only site APIs themselves through `page.route` and
do not depend on the backend or the database. `playwright.config.ts` starts its own Vite on port 5176.
Coverage includes when the top bar dropdown expands, data-fetch placeholders, retry on failure, and keyboard accessibility.

For structural refactors or major page changes, run this as well:

```bash
cd frontend-user-v3-www
pnpm run verify:refactor
```

## 7. Recommended Execution Order

### 7.1 Developer Self-Test Order

1. Targeted backend tests
2. Build the corresponding frontend
3. Local integration smoke test
4. Manual regression of the changed business domain

### 7.2 Order Before Handing Over to Testing

1. Full `backend` test run
2. `frontend-admin-v3` build
3. `frontend-user-v3-www` build
4. `frontend-user-v4-console` build
5. Integration of critical paths between the user side and the admin side
6. Submit the test record

### 7.3 Order Before Release

1. Empty-database installation flow verification
2. Full backend test run
3. Both frontend builds
4. Manual end-to-end regression
5. Verification of scheduling and asynchronous behavior
6. Sign-off on the release acceptance form

## 8. Full Test Scope

### 8.1 Installation and Basic Availability

- Running `backend/scripts/install_db.py --reset` against an empty database succeeds
- `mysql-schema.sql` loads correctly
- The default administrator can log in
- The backend `app:serve` starts
- `pnpm run build` succeeds for the user side and the admin side
- The site, user center, and admin home page are all reachable

### 8.2 Authentication and Permissions

- Administrator login, logout, token lifetime, idle timeout
- User login, registration, password recovery, profile update
- Admin side `login as` redirect and exchange code
- Whether permission code control is accurate
- Disabled accounts cannot access the system
- The response codes for unauthenticated, unauthorized, and expired-token requests match `API格式规范.md`

### 8.3 Site and Public Products

- Home page configuration reads correctly
- Announcement and help lists and details are reachable
- Product categories, lists, details, and quotes work
- Product card monthly price, specs, copy, and stock display are correct

### 8.4 Admin Side Products and Catalogs

- Product category tree, filtering, paging, and sorting work
- Product detail loads with no missing-field errors
- Drag-and-drop product sorting persists
- Moving products in bulk works
- Product splitting, the spec catalog, and the CPU model catalog are readable and writable
- Bulk linking of supplier products is available

### 8.5 User Orders and Billing

- Product quoting succeeds
- Order creation succeeds
- Quantity, config snapshot, fingerprint, and token are correct
- Balance payment, zero-amount payment, and pending-payment states are handled correctly
- Bill generation, bill line items, and bill status transitions work
- Payment records and bill projection stay in sync

### 8.6 Payment and Top-up

- Payment session creation succeeds
- The message shown when the Alipay pre-order fails matches expectations
- Signature verification of asynchronous callbacks works
- Top-up status queries correctly update the balance and ledger entries
- Callback idempotency verification passes

### 8.7 Service Lifecycle

- After an order is placed, the service is created or sits in the pending-provisioning state correctly
- The auto-renewal switch takes effect
- Expiry reminders, the renewal window, and cancellation logic are correct
- Buying a traffic package generates the correct bill
- Deleting a service keeps the accounting records

### 8.8 Service Console

- Service details, status, specs, and supplier binding work
- The VNC token security policy is correct
- Permissions for reinstall, power on/off, password reset, and similar operations are correct
- Reading and operating the NAT, monitoring, security group, and blackhole tool pages works
- Cache hits and forced refresh behave as expected

### 8.9 Users and Real-Name Verification

- User creation, update, and phone number uniqueness validation are correct
- Profile page updates succeed
- Real-name status queries, unbinding, and verification history display work
- Compatibility logic still runs when legacy tables are missing

### 8.10 Coupons, Referral Commission, and Membership

- Coupon creation, update, issuance, claiming, use, and rollback work
- Coupon paging and summary statistics are correct
- Referral relationship creation, referral commission issuance, release, and rollback are correct
- Withdrawals, referral commission logs, and account logs display correctly
- Membership level configuration and association logic work

### 8.11 Tickets and Content

- Ticket creation, replies, assignment, and closing work
- Image upload and path security validation are correct
- Announcement and help categories and article management are readable and writable
- The content publishing cache version updates correctly

### 8.12 Settings, Logs, and Notifications

- The `settings` table reads and writes correctly
- Operation logs, system logs, SMS logs, and email log queries work
- SMS log masking is correct
- Email and notification log degradation writes work

### 8.13 Scheduling and Asynchronous Processing

- The scheduled task overview API works
- Manually triggerable tasks execute
- Queue backlog cleanup behavior matches the current architecture
- Execution paths for authentication refresh, reconciliation, bill compensation, and automated tasks work

### 8.14 Database Engineering and Compatibility

- The base schema imports successfully
- Current migrations run to the latest state on the schema baseline
- Core indexes exist
- Engineering command dry-run output is readable
- Compatibility logic does not raise errors when legacy tables are missing

## 9. Backend Test Checklist

### 9.1 Required Runs

```bash
cd backend
php artisan test
```

### 9.2 Targeted Tests Added for High-Risk Changes

- Login/authentication: `AuthLoginRegressionTest`, `SanctumIdleTimeoutTest`
- Products/site: `AdminCatalogRegressionTest`, `SiteProductReadServiceTest`, `SiteHomeControllerTest`
- Ordering/payment/billing: `OrderQuantityCheckoutFlowTest`, `OrderZeroAmountPaymentFlowTest`, `RechargeStatusBalanceRegressionTest`, `PaymentInvoiceProjectionSyncTest`
- Service console/VNC: `ServiceVncTokenSecurityTest`, `ServiceConsoleSupplierBindingTest`
- Coupons/referral commission: `CouponLifecycleRegressionTest`, `ReferralRewardLifecycleRegressionTest`
- Tickets/upload: `TicketServiceRegressionTest`, `UploadSecurityTest`, `ZjmfFinanceTransportTest`
- Scheduling: `ScheduleTaskExecutionCoverageTest`, `ScheduleTaskOverviewTest`
- Installation/database: `DatabaseEngineeringCommandTest`, `DatabaseIndexOptimizationRegressionTest`

Targeted verification of upload security and upstream transport:

```bash
cd backend
php artisan test --filter=UploadSecurityTest
php artisan test --filter=ZjmfFinanceTransportTest
php artisan test --filter=ServiceProvisionDataTokenSanitizationTest
```

- `UploadSecurityTest` covers `/upload_image` being disabled by default, credentials/allowlist/rate limiting, and orphan file cleanup. The test cache is `array` (`phpunit.xml.dist`), so rate-limit counters are not written to Redis; the case `tearDown` clears the `RateLimiter` key for the fixed test IP, keeping repeated runs idempotent.
- `V2AdminTicketActionApiTest` covers reading and writing the upload protection configuration, the pre-checks when `/upload_image` is enabled, uploads outside the allowlist being rejected by default, and protection when creating or updating rules.
- `ZjmfFinanceTransportTest` covers attachment upload: reuse of the shared TLS options (`ssl_verify`/`ca_bundle`), rejection of path escapes, and a business exception raised when the upstream fails.
- `ServiceProvisionDataTokenSanitizationTest` covers that callback tokens do not enter `services.provision_data` and are kept only in the encrypted secret snapshot.

### 9.3 Empty-Database Initialization Flow Verification

```bash
python backend/scripts/install_db.py --reset
```

Verification items:

- The database table count is restored
- `cerbo` exists in `admin_users`
- `POST /api/v2/admin/login` returns `code = 0`

### 9.4 Principles for Recording the Backend Baseline

The number of tests keeps changing with features and regression cases, so this document does not maintain fixed passed/skipped/assertions numbers. After every release or major change, record the following in the current task, PR, or release record:

- Branch and commit hash.
- `php artisan test` results.
- Static analysis and format check results.
- Skipped cases and the reasons.
- Whether the newly failing cases are related to the change.

## 10. Frontend Test Checklist

### 10.1 Admin Side

Required runs:

```bash
cd frontend-admin-v3
pnpm run build
pnpm run test
```

Manual focus:

- Login page
- Home dashboard
- Product center
- User detail
- Ticket page
- Settings page
- Logs page

### 10.2 User Side and Official Website

Required runs:

```bash
cd frontend-user-v3-www
pnpm run build
pnpm run test:e2e
```

For structural changes, run this as well:

```bash
cd frontend-user-v3-www
pnpm run verify:refactor
```

Manual focus:

- Home page
- Product list / detail
- Login / registration / password recovery
- User center home
- Bills / orders / services / tickets / referral commission / top-up
- Blackhole tool page
- Service console page

## 11. Integration Test Checklist

### 11.1 Basic Integration

- All frontend request URLs go through `127.0.0.1`
- Tokens are stored correctly after logging in on both sides
- Admin side and user side APIs both return the unified JSON envelope
- Error messages for 401 / 403 / 422 / 500 are readable

### 11.2 Cross-App Flows

- A product added on the admin side is visible on the site
- Home page configuration changed on the admin side is visible on the official website
- `login as` from the admin side into the user side succeeds
- After a user places an order, the bill/service/log is visible on the admin side
- After a user submits a ticket, the admin side can handle it

### 11.3 Scheduling Integration

- Follow-up actions after order payment can be consumed under the current scheduling model
- A manual scheduled task trigger produces a visible status change in the frontend

## 12. Manual Test Case Design Method

Every business domain covers at least the following dimensions:

- Normal path
- Missing parameters
- Illegal status transitions
- Insufficient permissions
- Empty data
- Paging over large data sets
- Concurrent or duplicate submissions
- Asynchronous delay
- Third-party failure
- Legacy compatibility paths

Examples:

- Login: correct password, wrong password, disabled account, expired token, idle timeout
- Ordering: normal purchase, zero-amount purchase, insufficient balance, real-name verification restriction, unbound phone restriction
- Coupons: valid coupon, expired coupon, private coupon, public coupon, repeated use, rollback on payment cancellation
- Service console: manageable, not manageable, missing supplier, cache hit, forced refresh, upstream error masking

## 13. Defect Severity Standard

- `P0`
  - Cannot start
  - Cannot log in
  - The main ordering/payment/provisioning chain is broken
  - Accounting errors, permission leaks, security issues
- `P1`
  - A major feature is unavailable
  - A critical page is blank or errors
  - An error message or wrong state that triggers with high probability
- `P2`
  - A secondary flow misbehaves
  - Incorrect display under specific conditions
  - Build warnings, compatibility issues
- `P3`
  - Copy, styling, and minor interaction issues
  - Edge cases that do not affect the main flow

## 14. Test Record Requirements

Each test round produces at least the following:

- Test time
- Tester
- Branch / commit hash
- Environment information
- Database baseline source
- Commands executed
- Modules covered
- Defect list
- Items not covered and the reasons
- Final conclusion: pass / conditional pass / fail

## 15. Pre-Release Acceptance Checklist

- Backend `php artisan test` passes
- `frontend-admin-v3` builds
- `frontend-user-v3-www` builds
- `frontend-user-v4-console` builds
- If a refactor is involved, `verify:refactor` passes for the affected frontend
- The fresh-install script verification passes
- Default administrator login verification passes
- At least one user ordering flow is verified
- At least one ticket flow is verified
- At least one manual scheduled task trigger is verified
- The release configuration and `.env` check are completed
- Risk items are recorded with owners confirmed

## 16. Currently Known Limitations

- `frontend-admin-v3` has no complete unit test system yet and relies mainly on the build plus a few script checks.
- `frontend-user-v3-www` has Playwright wired in (`pnpm run test:e2e`), but it currently covers only the top bar dropdown flow; product purchase, content pages, and the remaining official website sections still rely on the build, scripts, and manual integration. Automated test coverage in `frontend-user-v4-console` is similarly light.
- Real third-party capabilities such as SMS, email, Alipay, and upstream provider APIs can only be verified for local degradation and mock behavior when no isolated test environment is available.
- Scheduled and asynchronous flows are affected by the current `schedule:run` model; verification must account for the 0 to 60 second delay window.

## 17. Recommended Execution Templates

### 17.1 Quick Version Before Handing Over to Testing

```bash
cd backend
php artisan test
composer analyse
composer format:check

cd ../frontend-admin-v3
pnpm run build
pnpm run test

cd ../frontend-user-v3-www
pnpm run build
pnpm run test:e2e
```

Record template:

- Commit hash:
- Test environment:
- Database baseline:
- Backend result:
- Admin side build result:
- User side build result:
- Manual smoke test scope:
- Risks and notes:

### 17.2 Full Version Before Release

```bash
# 1. 空库安装验证
python backend/scripts/install_db.py --reset

# 2. 后端全量
cd backend
php artisan test
composer analyse
composer format:check

# 3. 管理端
cd ../frontend-admin-v3
pnpm run build
pnpm run test

# 4. 官网与购买入口
cd ../frontend-user-v3-www
pnpm run build
pnpm run test:e2e
pnpm run verify:refactor

# 5. 用户控制台
cd ../frontend-user-v4-console
pnpm run build
```

Suggested additional manual checks:

- Administrator login, user login
- Product admin list, detail, category tree
- Site home page, public product reads, quoting
- Ordering, bills, balance payment, zero-amount payment
- Service console details, VNC, NAT, security groups
- Tickets, referral commission, scheduled tasks
- Database engineering commands and parsing of key Job injections

## Related Documentation

- [Startup Guide](local-development.md): local environment and build commands.
- [Deployment and Scheduling Guide](../guide/deploy/scheduling.md): production scheduling and queue rules.
