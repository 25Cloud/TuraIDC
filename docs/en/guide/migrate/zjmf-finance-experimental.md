---
source: guide/migrate/zjmf-finance-experimental.md
sourceHash: e4a32200d9fc9b61de7b4801bd387ce485dc8de2315376ca7546474a3ac2d0af
translatedAt: 2026-10-26
---

# Migrating from ZJMF Finance (Experimental)

> ⚠️ **This document is archived as an experimental record.** For the complete migration tutorial, see the official guide: [Migrating from ZJMF Finance](zjmf-finance.md) (it consolidates the full production workflow and the recorded pitfalls, including the flat pricing format, config_options assembly, upstream decryption, and service binding backfill).

This document records the experimental workflow for migrating ZJMF Finance business data into the TuraIDC `turaidc` database. The migrator is not integrated into `install_db.py` and exists only as a one-off import tool; production migration still follows `migrate_legacy_dump.py`.

## 1. Applicable Scenarios

- Source system: ZJMF Finance (database table prefix `shd_`; older versions may use prefixes such as `mccloud_` or `ewytemplate_`).
- Target system: the current TuraIDC `turaidc` schema.
- Data volume: about 24,000 rows of business data (users 1279, products 1337, orders 3369, invoices 7814, services 2970, tickets 1130).
- Migration method: streaming parse of a `mysqldump` file + field mapping + batch writes; the source database does not need to be online.

## 2. Scripts and Dependencies

| Script                                                                                                                      | Purpose                                                                                                 |
| --------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| [mofang_to_turaidc_migrator.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/mofang_to_turaidc_migrator.py) | Main migrator: parses the dump, maps fields, performs batch writes, and converts pricing JSON           |
| [mofang_migrate.conf.example](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/mofang_migrate.conf.example)     | Migrator config file template (copy it to `mofang_migrate.conf` to run without passing arguments)       |
| [normalize_mofang_dump.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/normalize_mofang_dump.py)           | Preprocesses the dump, strips prefixes such as `shd_`, and renames conflicting tables                   |
| [count_mofang_rows.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/count_mofang_rows.py)                   | Counts source table rows to estimate the migration scale                                                |
| [peek_mofang_rows.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/peek_mofang_rows.py)                     | Samples source data to verify field formats (such as `###md5` passwords)                                |
| [dryrun_migration_analysis.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/dryrun_migration_analysis.py)   | Pure-Python dry run that analyzes the source/target column intersection and any missing required fields |
| [extract_mofang_core_tables.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/extract_mofang_core_tables.py) | Extracts the core tables from a large dump for offline analysis                                         |

Dependencies: `pymysql` (already installed in the backend virtual environment).

## 3. Connection Configuration (three methods, merged by priority)

> Command-line arguments > the `MOFANG_MIGRATE_<KEY>` environment variables > the config file.

**Method 1: config file (recommended, runs with no arguments)**

Copy `mofang_migrate.conf.example` to `mofang_migrate.conf` (in the same directory as the scripts) and fill in the real values:

```ini
[db]
host = 43.240.220.81
port = 3306
user = turaidc
password = <PASSWORD>
database = turaidc

[source]
dump = e:\TuraIDC\25y_2026-08-17_20-39-46_mysql_data_HSoN5.sql
```

> `mofang_migrate.conf` contains the database password and is listed in `.gitignore`, so **it is never committed to the repository**; when `dump` is left empty, `25y_*.sql` under the project root or `backend/scripts` is discovered automatically.

**Method 2: environment variables**

```
MOFANG_MIGRATE_HOST / MOFANG_MIGRATE_PORT / MOFANG_MIGRATE_USER /
MOFANG_MIGRATE_PASSWORD / MOFANG_MIGRATE_DATABASE / MOFANG_MIGRATE_DUMP
```

**Method 3: command-line arguments** (highest priority; see the execution workflow below).

## 4. Execution Workflow

### 1. Pre-check: source data assessment

```bat
python backend\scripts\count_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\peek_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\dryrun_migration_analysis.py --dump path\to\shd_dump.sql
```

The output includes source table row counts, key field formats (passwords, pricing columns), the column intersection, and a list of missing required fields.

### 2. Pre-check: full dry run

```bat
:: 配置文件就绪后，零参数直接预演（自动发现 dump）
python backend\scripts\mofang_to_turaidc_migrator.py --dry-run

:: 或全部参数手动指定
python backend\scripts\mofang_to_turaidc_migrator.py ^
    --dump path\to\shd_dump.sql ^
    --host 43.240.220.81 --port 3306 ^
    --user turaidc --password <PASSWORD> ^
    --database turaidc --dry-run
```

The dry run writes nothing to the database; it only parses, maps, and counts. The expected output is `合计: 源行 24595  入库 24595  失败 0`.

### 3. Actual migration (including truncating the target tables)

```bat
python backend\scripts\mofang_to_turaidc_migrator.py --truncate
```

`--truncate` empties the 19 target tables before migrating (TRUNCATE, DDL, completes in 4 seconds). **Use it on a clean database only**.

### 4. Migrate specific tables by allowlist (used to repair tables that failed)

```bat
python backend\scripts\mofang_to_turaidc_migrator.py ^
    --tables shd_configuration,shd_products,shd_invoices,shd_admin_log,shd_pricing
```

### 5. Post-migration verification

See the "Data Verification" section below.

## 5. Table Mapping Rules

After the prefix is stripped from the source `shd_*` tables, the data is written to the target tables according to the following mapping:

| Source table                      | Target table                                           | Notes                                                                                            |
| --------------------------------- | ------------------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| `shd_configuration`               | `settings`                                             | Adds `group_key='general'`                                                                       |
| `shd_product_first_groups`        | `first_product_groups`                                 | First-level product groups                                                                       |
| `shd_product_groups`              | `second_product_groups`                                | Second-level product groups; unique-key conflicts are skipped by degrading to single-row inserts |
| `shd_upper_reaches`               | `suppliers`                                            | Upstream suppliers (the source data is usually empty)                                            |
| `shd_clients`                     | `users` + `user_accounts`                              | Written to both tables from the same source rows; passwords keep the `###md5` format             |
| `shd_products`                    | `products`                                             | `description → remark` truncated to 255; `pricing` filled in during post-processing              |
| `shd_orders`                      | `orders`                                               | `ordernum → order_no`; empty values generate `ORD-{id:08d}`                                      |
| `shd_invoices`                    | `invoices`                                             | `invoice_num → invoice_no`; invalid values generate `INV-{id:08d}`                               |
| `shd_host`                        | `services`                                             | Service instances provisioned for users                                                          |
| `shd_ticket` / `shd_ticket_reply` | `tickets` / `ticket_replies`                           | Tickets and replies                                                                              |
| `shd_promo_code`                  | `coupons`                                              | Coupon codes                                                                                     |
| `shd_activity_log`                | `activity_logs`                                        | User activity logs                                                                               |
| `shd_admin_log`                   | `operation_logs`                                       | Administrator operation logs; source IDs are not migrated                                        |
| `shd_message_log`                 | `message_logs`                                         | Message logs                                                                                     |
| `shd_pricing`                     | (written to `products.pricing` during post-processing) | Not written directly; used as the source for the pricing JSON                                    |
| `shd_product_groups`              | `third_product_groups`                                 | Post-processing copies second-level groups into third-level groups to satisfy the foreign key    |

## 6. Key Compatibility Handling

### Passwords: the `###md5` format

The ZJMF password format is a `###md5` prefix followed by a 32-character MD5 hash, which is incompatible with TuraIDC's bcrypt. It is **preserved as-is** during migration; [ZjmfLegacyPasswordVerifier.php](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php) recognizes and verifies it at login, and users can be upgraded to bcrypt seamlessly after they log in.

### pricing: multi-column billing-cycle prices → JSON

The source `shd_pricing` table has one column per product, per currency, and per billing cycle (monthly/quarterly/semiannually/annually, and so on). The migrator aggregates these into the target `products.pricing` JSON field:

```json
{
  "monthly": { "price": 25.0 },
  "quarterly": { "price": 75.0 },
  "semiannually": { "price": 150.0 },
  "annually": { "price": 300.0 }
}
```

The setup fee `setup_fee` is taken from the `*setupfee` column of the corresponding cycle. When a product has records in multiple currencies, `currency=0` (CNY) is preferred.

### invoice_no uniqueness fallback

About 42% of the `invoice_num` values in the source data are invalid (3280 rows with the string `'NULL'`, 10 with an empty string, and some with `'INV-0'`). The migrator detects the invalid-value set `{"", "INV-0", "0", "NULL", "NONE", "N/A", "-"}` (case-insensitive) in `_post_map_fix` and, on a match, generates a unique value `INV-{id:08d}` from the source ID, so the `invoice_no` NOT NULL UNIQUE constraint is satisfied.

### operation_logs primary key conflicts

In the target database, `operation_logs` is written continuously while the application runs, and its primary key `id` is assigned by auto_increment. When migrating the source `shd_admin_log`, **the source IDs are not migrated**, which avoids primary key conflicts with application logs.

### remark field truncation

Every `remark` field in the target database is `varchar(255)`, while the `description`/`notes` fields in the source data may exceed that length. All remark mappings truncate uniformly with `lambda v: to_str(v)[:255] if v else None`.

### Foreign key dependency: third-level product groups

In the target database, the `products.product_group_id` foreign key points to `third_product_groups.id`, while the source database has only two levels of groups. After the main workflow finishes, the migrator calls `_sync_third_product_groups` to copy the `shd_product_groups` data into `third_product_groups`, satisfying the foreign key constraint.

## 7. Data Verification

### Row count reconciliation

```python
import pymysql
from pymysql.cursors import DictCursor

conn = pymysql.connect(host='...', user='turaidc', password='...', database='turaidc',
                       charset='utf8mb4', cursorclass=DictCursor)
expected = {
    "users": 1279, "products": 1337, "orders": 3369, "invoices": 7814,
    "services": 2970, "tickets": 1130, "ticket_replies": 2814,
    "first_product_groups": 36, "second_product_groups": 207,
    "settings": 331, "coupons": 19, "message_logs": 1826,
}
for t, exp in expected.items():
    with conn.cursor() as cur:
        cur.execute(f"SELECT COUNT(*) AS c FROM `{t}`")
        actual = cur.fetchone()['c']
    status = "✓" if actual == exp else "✗"
    print(f"  {t:<28} {actual:>8}/{exp:<8} {status}")
```

### Key field sampling

- `users.password` should use the `###md5` prefix format.
- `products.pricing` should be non-empty JSON containing billing-cycle keys such as `monthly` and `annually`.
- `invoices.invoice_no` must be unique across all rows (`GROUP BY ... HAVING COUNT(*) > 1` should return no rows).

## 8. Known Limitations

- **What is not migrated**: non-core business tables such as the knowledge base (`shd_knowledge_base`), mail templates, payment gateway configuration, and upstream server configuration are left empty.
- **Dirty source data**: the source `shd_orders` table has 2 duplicate `ordernum` values, which the single-row fallback insert skips automatically; the final import is 3367 of 3369 rows.
- **Concurrent application writes**: if the target database's NestJS application keeps running during the migration, it contends with the migrator for tables such as `operation_logs`; stop the application during the migration or run the migration during off-peak hours.
- **Metadata lock risk**: remote MySQL is sensitive to DDL (TRUNCATE), and long-running transactions in the target database block TRUNCATE. Before migrating, run `SHOW PROCESSLIST` and KILL leftover idle connections.
- **Experimental**: this migrator is not part of the automated `install_db.py` workflow, so the mapping rules must be updated manually as the schema evolves.

## 9. Troubleshooting

### TRUNCATE timeout (metadata lock)

```
(2013, 'Lost connection to MySQL server during query (The read operation timed out)')
```

Troubleshooting steps:

1. Run `SHOW PROCESSLIST` to find the session in `Waiting for table metadata lock`.
2. Locate the lock source (usually a long-running transaction from the NestJS application on localhost).
3. Run `KILL <id>` to terminate the blocking process, then retry TRUNCATE.

### Field length exceeded

```
DataError: (1406, "Data too long for column 'remark' at row 1")
```

All `remark` mappings already truncate to 255 characters uniformly; if another varchar field reports this error, add a `[:N]` truncation to the corresponding `FieldMap`.

### Primary key / unique key conflicts

```
IntegrityError: (1062, "Duplicate entry '...' for key '...'")
```

The migrator has a built-in single-row fallback insert: after a batch fails, it retries row by row, skips conflicting rows, and counts them in `failed`. If the number of failed rows is unusually high, check whether `_post_map_fix` covers the invalid-value cases for that table.

## 10. Related Documents

- [Local IDC data migration workflow](local-idc.md) — the production migration workflow
- [ZJMF unified migration plan](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/completed/zjmf-unified-migration.md) — the overall ZJMF compatibility plan
