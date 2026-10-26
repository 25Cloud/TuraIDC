---
source: develop/local-development.md
sourceHash: 5007427a2f90628d183db4330d00d80cc6bae8a00138e5776971808ac531f5b9
translatedAt: 2026-10-26
---

# Local Development Guide

This document covers starting the backend and the three frontends locally, initializing the database, and the common verification commands; for production deployment, see the individual deployment guides.

## 1. Requirements

- PHP 8.3+
- Composer
- MySQL 8
- Redis
- Node.js 20.19+ (the package.json constraint of `frontend-admin-v3` and `frontend-user-v4-console`)

Use `127.0.0.1` consistently for local integration; do not mix in `localhost`.

## 2. Backend

```bash
cd backend
composer install
php artisan key:generate
php artisan migrate
php artisan app:serve
```

### Cache and Redis Configuration

Local development defaults to `CACHE_STORE=file` (configured in `.env`), which is sufficient for a single-process runtime.

**Production must set `CACHE_STORE=redis`**:

- Distributed locks (`Cache::lock`) do not support cross-process mutual exclusion under the file driver
- Critical flows such as payment callbacks, order creation, and rebate calculation rely on Redis locks for idempotency
- When Redis is unavailable, these operations may conflict concurrently and corrupt data

Check whether Redis is running:

```bash
redis-cli ping  # 应返回 PONG
```

`php artisan app:serve` is the unified local backend entry point for this project; it starts Laravel HTTP, VNC Relay, and the business queue Worker. Do not substitute `php artisan serve` for it.

If you also need scheduling locally, run the Relay as a separate process:

```bash
cd backend
php artisan app:serve --with-schedule --without-vnc
php artisan vnc:relay
```

With scheduling enabled, `app:serve --with-schedule --without-vnc` no longer starts a separate business queue Worker; every minute `schedule:run` drives heartbeat dispatch (deduplicated per 15-minute slot) and background consumption through `queue:drain`. Workers are created separately for the three queue groups `provision`, business group, and `automation`, and the drain lock keeps the same queue from running concurrently. The Relay process exiting on its own does not affect the HTTP and scheduling entry points.

Production does not run `app:serve` as a persistent process: PHP-FPM points at `backend/public`, and BaoTa Panel runs the following every minute:

```bash
cd backend
php artisan schedule:run
```

## 3. Frontend

Using the pnpm workspace scripts from the repository root is recommended:

```bash
pnpm run dev:admin-v3
pnpm run dev:user-v3-www
pnpm run dev:user-v4-console
pnpm run dev:docs
```

You can also enter the subprojects:

```bash
cd frontend-admin-v3
pnpm run dev

cd frontend-user-v3-www
pnpm run dev

cd frontend-user-v4-console
pnpm run dev
```

The actual current frontend directories:

- `frontend-admin-v3`: admin side, TDesign Vue Next; the default port follows the Vite configuration.
- `frontend-user-v3-www`: official site and user entry, Element Plus.
- `frontend-user-v4-console`: new user console, TDesign Vue Next.
- `shared`: cross-frontend shared package.
- `docs-web`: standalone VitePress documentation site that reads content directly from `docs/`.

## 4. Build and Verification

```bash
# 全部当前前端
pnpm run build:frontends
pnpm run typecheck:frontends
pnpm run test:frontends
pnpm run verify:frontends

# 单项目
pnpm run build:admin-v3
pnpm run build:user-v3-www
pnpm run build:user-v4-console
pnpm run build:docs
pnpm run docs:check
```

Backend verification:

```bash
cd backend
php artisan test
```

For documentation or configuration changes, at minimum check that the content matches the current directories, scripts, and code.

## 5. Database Initialization and Migration

Initializing a new database and migrating a legacy database are high-impact operations and are not run automatically by the agent:

```bash
python backend/scripts/install_db.py
python backend/scripts/migrate_legacy_dump.py --dump "你的dump文件.sql" --dry-run
python backend/scripts/migrate_legacy_dump.py --dump "你的dump文件.sql"
```

Before running them, confirm the target database, the backup, and `.env`.

## Related Documentation

- [Testing Guide](testing.md): test environment and verification methods.
- [Deployment Guide](../guide/deploy/generic.md): the full production deployment process.
- [Current Architecture](architecture.md): the local and production runtime models.
