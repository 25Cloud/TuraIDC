---
source: develop/mysql-compatibility.md
sourceHash: ca23de043a3bf90dd64da344c2ae8fcadad9de96b6102ca18d2079a614689692
translatedAt: 2026-10-26
---

# MySQL Version Compatibility Baseline (5.7.44 / 8.x Dual Version)

This document is the source of truth for compatibility of database and SQL changes. **Every database-related change (migration, raw SQL, query builder code, model code) must hold on both MySQL 5.7.44 and 8.x.**

## 1. Why Dual-Version Compatibility Is Mandatory

- The minimum supported MySQL version is **5.7.8**, 8.0+ is recommended, and 5.7 is a compatibility-support tier (5.7 is EOL). A significant share of existing users still run on 5.7.
- Legacy sites migrated from ZJMF Finance commonly run 5.7 in their existing environments. The migration toolchain depends on a stable table structure.
- Code validated only on 8.0 often does not "degrade" on 5.7 but **raises a SQL syntax error outright**, and usually only on specific pages, which makes it extremely hard to diagnose in production.

Verification environments: `5.7.44` (the current last 5.7 release) and `8.0.x`. These two versions are authoritative; do not reason from "5.7 supports it in theory".

## 2. Hard Prohibitions

### 2.1 No 8.0-Only Syntax

| Feature                                            | 5.7 | Alternative                                                                                                                                    |
| -------------------------------------------------- | :-: | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Window functions `ROW_NUMBER()/RANK()/OVER()`      | ❌  | Two-level aggregation: first `MAX()` per group to get the extreme value, then join back to the original table; break ties with the primary key |
| CTE `WITH ... AS`, recursive CTE                   | ❌  | Subquery / `joinSub()`                                                                                                                         |
| Top-N per group (implemented via window functions) | ❌  | One subquery with `LIMIT` per group combined by `UNION ALL`; sort again on the PHP side if necessary                                           |
| `JSON_TABLE()`                                     | ❌  | Expand in the application layer                                                                                                                |
| Descending index (`INDEX (col DESC)`)              | ⚠️  | 5.7 silently ignores the direction: no error, but no effect; do not rely on it for ordering                                                    |

> Production case: the admin panel ticket upstream delivery log used `ROW_NUMBER() OVER (PARTITION BY ...)` to fetch the latest record per ticket, which raised `ERROR 1064` on 5.7 and made the entire page unusable. It has been rewritten as two-level `MAX` + `joinSub`.

### 2.2 The 5.7 Implicit `ON UPDATE CURRENT_TIMESTAMP` Trap

MySQL 5.7 defaults to `explicit_defaults_for_timestamp=OFF` (8.0 defaults to ON). In that state, **the first `timestamp NOT NULL` column in a table that has no explicit `DEFAULT`** is automatically given `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` by MySQL. The consequence: any `UPDATE` that does not include that column silently rewrites it to the current time.

Therefore:

- **New tables**: the first `timestamp` column must be given an explicit `DEFAULT`, or declared `nullable` (`$table->timestamp('x')->nullable()`); never leave a bare `timestamp NOT NULL`.
- **Updating existing rows**: if the first `timestamp` column of the target table has that shape, the `UPDATE` statement must **explicitly carry the column's original value** to override the implicit behavior (`Model::save()` writes only dirty fields, which is not enough protection).
- **Deployment requirement**: 5.7 deployments should set `explicit_defaults_for_timestamp=ON` under `[mysqld]` in `my.cnf`. This is defense in depth and does not replace the two code-level requirements above — do not assume the user has changed the configuration.

> Production case: `schedule_ticks.slot_started_at` has exactly this shape. When the second heartbeat in the same 15-minute slot updated `triggered_at`, 5.7 changed the slot start to the current time, which broke the semantics of the slot unique key, and the scheduler kept hitting the `global_number` unique key from about minute 3 of that slot onward. The fix was to explicitly write the original `slot_started_at` value back in the `UPDATE` (zero DDL).

### 2.3 Minimum Version Constraints

- The `json` column type and unique indexes on virtual generated columns are supported only **from 5.7.8**, which is where the 5.7.8 minimum comes from. The baseline contains 58 `json` columns, so the baseline cannot drop to 5.6.
- Index key length: on 5.7 without `innodb_large_prefix` enabled (the default varies by minor version), a single-column index longer than 191 characters under `utf8mb4` may fail. Keep new indexed columns within `varchar(191)` where possible, and use prefix indexes or hash columns for longer fields.

## 3. Additions Only, Never Deletions (Iron Rule)

**Never drop any table, column, or index.** Additions only.

Reason: we are not the original developers; existing users upgrade from older versions, and some users are migrating from ZJMF Finance right now — the migration toolchain (`backend/scripts/mofang_to_turaidc_migrator.py` and similar) depends on a stable table structure. Any deletion can break an existing upgrade path without warning and is hard to roll back.

- When redundant tables or redundant columns are found: **record only, do not act**; leave the decision to the upstream maintainers.
- Migrations only add, and must be idempotent (check `Schema::hasTable` / `hasColumn` before acting); do not re-run historical aggressive migrations.
- When constraint semantics must change (for example relaxing `NOT NULL` or replacing a unique key), take the "add / relax / replace" path, never the "drop then create" net-deletion path.

## 4. Parts the Framework Already Handles (No Extra Work Needed)

Laravel 12 already branches on the server version for the following differences internally; **do not hand-write version checks for them**:

- `sql_mode` handling: `MySqlConnector` branches at 8.0.11.
- Queue `SKIP LOCKED`: `DatabaseQueue` branches at 8.0.1 and degrades automatically on 5.7.
- The `VALUES()` syntax of `upsert`: the form is chosen per version.

## 5. Checklist Before and After a Change

1. Does the added or modified SQL use window functions, CTEs, or `JSON_TABLE`? → If so, it must be rewritten.
2. Does a new table have a bare `timestamp NOT NULL` as its first time column? → If so, add an explicit default or make it nullable.
3. Was an `UPDATE` path added against an existing table? → Check the shape of that table's first `timestamp` column and write it back explicitly where necessary.
4. Does any migration contain `dropColumn` / `dropIndex` / `dropIfExists` in `up()`? → If so, it must be removed (rollback logic in `down()` is not subject to this).
5. Were the affected tests run against both the 5.7 and the 8.0 database? → The failure sets on both sides should be identical.

## 6. How to Verify 5.7 Locally or on a Server

Do not touch the production database. Use a 5.7 sandbox with its own datadir:

```bash
# 关键：mysqld / mysql 的每次调用都必须把 --no-defaults 放在第一个参数，
# 否则会读取系统 my.cnf 里的 datadir，导致沙箱进程去锁生产数据目录。
mysqld --no-defaults --initialize-insecure --user=root \
  --basedir=<解压目录> --datadir=<沙箱目录>/data \
  --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

mysqld --no-defaults --user=root --basedir=<解压目录> --datadir=<沙箱目录>/data \
  --port=3307 --bind-address=127.0.0.1 --socket=<沙箱目录>/m57.sock \
  --pid-file=<沙箱目录>/m57.pid --performance-schema=OFF --skip-name-resolve
```

Then run the test suite with the `DB_PORT=3307` environment override and compare the results with 8.0 line by line: **the 5.7 failure set must be exactly the same as 8.0**; any extra failure must be treated as a real defect, not as "that is just how 5.7 is".

## Related Documents

- [Migrating from ZJMF Finance](../guide/migrate/zjmf-finance.md): compatibility practices in the migration process.
- [Database Schema](/develop/database): snapshot of the current table structure.
