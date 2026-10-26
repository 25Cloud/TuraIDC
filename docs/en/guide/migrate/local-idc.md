---
source: guide/migrate/local-idc.md
sourceHash: 04d5e837a7acab1280e2248a555c5261cb616bb681b02f71b970ed07eea2cc06
translatedAt: 2026-10-26
---

# Local IDC Data Migration Workflow

This article records the fixed procedure for resetting a local database from a MySQL dump, initializing it, and migrating data.

> If the source system is ZJMF Finance (table prefix `shd_`), use the experimental migrator instead: [Migrating from ZJMF Finance (Experimental)](zjmf-finance-experimental.md). This document covers dump migration within the current schema and does not strip the table prefix.

## One-Click Scripts

Migrate the business data of a dump into the local `idc` database that **already uses the current schema** (no schema changes):

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql
```

Run a full read-only rehearsal before migrating (parses every INSERT, verifies the field mapping and the category hierarchy, writes nothing to the database):

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql --dry-run
```

Independently verify a migration that has already run (per-table row counts, core references, and three-level product categories):

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql --verify
```

To rebuild the local database together with the current-schema initialization and then migrate the specified dump:

```bat
python backend\scripts\reset_init_and_migrate_idc_dump.py --dump C:\path\to\idc_dump.sql
```

Every migration script requires an explicit `--dump`, so a cleaned-up historical backup is not used by mistake.

Initialize the current database schema only, without importing a legacy dump:

```bat
python backend\scripts\install_db.py --reset
```

Check only, write nothing:

```bat
python backend\scripts\reset_init_and_migrate_idc_dump.py --dump C:\path\to\idc_dump.sql --dry-run
```

Migration records are written by default to:

```text
控制台输出或脚本指定的本地日志位置
```

One-off migration logs do not enter the long-term documentation tree; if a conclusion must be preserved, distill it into the compatibility rules in this file.

## Execution Order

1. `backend/scripts/install_db.py --reset`
   - Drops and recreates the local database that `.env` points to.
   - Initializes the table structure from the current project schema.
   - Runs the current migrations.
   - Initializes the default `settings`, notification templates, and default administrator.
   - The schema baseline carries only the table structure and the `migrations` state, not business rows; this script explicitly fills in the default runtime data.

2. `backend/scripts/migrate_legacy_dump.py`
   - Parses the INSERT statements of the dump directly and copies data in a single transaction using the field mapping of the current target tables; it does not restore the table structure of the legacy dump and does not need a temporary database.
   - The migration clears and rebuilds the target business data and keeps the current `migrations`, so the legacy migration state does not overwrite the current code schema.
   - The legacy single table `product_groups` is mapped by level onto the current `first_product_groups`, `second_product_groups`, and `third_product_groups`, keeping IDs and backfilling the three-level product category fields; products that were attached directly to a legacy second-level category or to no category receive an automatically created historical carrier third-level category, so every product in the current schema has a valid three-level category.
   - Current tables missing from the backup (such as the newly added `recharge_records` and `refunds`) are left empty; a table that exists in the backup but has no mapping defined in the script fails the run outright instead of being dropped silently.
   - Clears the runtime tables `jobs`, `password_reset_tokens`, `personal_access_tokens`, and `sessions`; legacy runtime data is not migrated.
   - Keeps the temporary `codex_runtime` and `codex_service` configuration in the current database's `settings` instead of overwriting it from the dump.
   - Discovers `mysql.exe` in PATH or in the BaoTa Panel installation directory automatically; the client path can also be set through `MYSQL_BIN`.
   - After the migration it verifies per-table row counts, key foreign-key references, empty runtime tables, and the completeness of the three-level product categories.

## Known Compatibility Rules

- When the legacy dump lacks the newly added log tables `activity_logs`, `gateway_logs`, and `schedule_run_logs`, the initialized target tables stay empty.
- Legacy columns such as `products.name` are not restored.
- The product specification snapshot of `orders` and `invoices` maps to `product_spec_snapshot` first and falls back to the legacy `product_name_snapshot` when it is missing.

## Post-Migration Checks

- Core table row counts match expectations: `users`, `products`, `invoices`, `services`, `payments`, `operation_logs`.
- Runtime tables stay empty: `jobs`, `sessions`, `password_reset_tokens`, `personal_access_tokens`.
- The current schema matches `docs/develop/database.md`.
- Frontend and backend pass a minimal smoke test with the migrated administrator, user, product, and service data.

## Refreshing the Current Schema Baseline

After the current database schema or the data migration is finished, refresh the empty-database initialization baseline and the database structure snapshot together:

```bat
php backend\scripts\export_schema_baseline.php
php backend\scripts\export_database_structure.php
```

`export_schema_baseline.php` exports only the table structure and the `migrations` records; after importing the baseline, `install_db.py` fills in the default configuration, notification templates, and administrator.
