---
source: guide/migrate/zjmf-finance.md
sourceHash: c186e16c5a818fd65c15d97a58e6b71436e08a78adb491fd8a474a64b3625ade
translatedAt: 2026-10-26
---

# Migrating from ZJMF Finance to TuraIDC

This document is the **authoritative migration tutorial**. It consolidates the full workflow, the tooling, and the production-environment pitfalls for migrating a legacy ZJMF Finance site (`shd_` table prefix) to TuraIDC. It applies to deployments that already hold ZJMF business data and want to switch to TuraIDC.

> For the older experimental document, see [Migrating from ZJMF Finance (Experimental)](zjmf-finance-experimental.md). This document covers all of its content and revises it according to production experience (for example, the flat pricing format).

---

## 1. Migration Overview

| Item             | Description                                                                                                   |
| ---------------- | ------------------------------------------------------------------------------------------------------------- |
| Source system    | ZJMF Finance, database table prefix `shd_` (older versions may use `mccloud_`, `ewytemplate_`, etc.)          |
| Target system    | TuraIDC (Laravel 12 + MySQL 8)                                                                                |
| Migration method | Streaming parse of a `mysqldump` file (the source database need not be online) + field mapping + batch writes |
| Reference scale  | Users 1279, products 1337, orders 3369, invoices 7814, services 2970, tickets 1130                            |
| Data safety      | Legacy `###md5` passwords are preserved as-is and upgraded seamlessly to bcrypt after verification at login   |

The migration runs in 10 steps:

```text
Step 1  环境准备（核对 .env 数据库指向）
Step 2  产品目录三级结构重建
Step 3  产品 / 用户 / 订单 / 工单迁移
Step 4  配置项迁移（products.config_options）与 OS version 修复
Step 5  service_type_code 派生
Step 6  上游同步（suppliers + 绑定 + API 密钥解密）
Step 7  实名认证迁移
Step 8  老用户密码策略
Step 9  缓存预热与验证
Step 10 服务实例上游绑定回填（恢复控制台管理能力）
```

---

## 2. Tool Inventory

### Migration scripts (Python, `backend/scripts/`)

| Script                                         | Purpose                                                                                                                          |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `mofang_to_turaidc_migrator.py`                | Main migrator: products / users / orders / groups / hosts, etc. (supports `--dry-run`, `--truncate`, and a `--tables` allowlist) |
| `mofang_config_options_migrator.py`            | Parses `shd_product_config_links / options / sub / pricing` from the dump and assembles the `products.config_options` JSON       |
| `normalize_mofang_dump.py`                     | Preprocesses the dump: strips prefixes such as `shd_` and renames conflicting tables                                             |
| `count_mofang_rows.py` / `peek_mofang_rows.py` | Counts source table rows / samples source data                                                                                   |
| `dryrun_migration_analysis.py`                 | Pure-Python dry run that analyses the intersection of source and target columns and missing required fields                      |
| `extract_mofang_core_tables.py`                | Extracts core tables from a large dump for offline analysis                                                                      |

### Artisan commands (`backend/app/Console/Commands/`)

| Command                               | Purpose                                                                                                                                                                                      |
| ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app:reorganize-product-groups`       | Rebuilds the three-level groups: first-level group normalisation (the 5 built-in ProductType classes), ownership remapping, `service_type_code` derivation, hiding empty second-level groups |
| `app:sync-upstreams`                  | Syncs upstream suppliers from the legacy database, decrypts API keys into `supplier_plugin_bindings`, and creates product-level bindings                                                     |
| `app:migrate-real-name`               | Real-name verification migration (`shd_certifi_person / company` → `users` + `verification_histories`)                                                                                       |
| `app:fix-os-versions`                 | Repairs the OS config option `sub[].version` (`大类^版本`) and strips redundant `^`                                                                                                          |
| `app:backfill-config-options`         | Cross-database backfill of missing `products.config_options` (restored from the backup database)                                                                                             |
| `services:backfill-upstream-bindings` | Backfills `service_upstream_bindings` (notes `ID：` plus the upstream `/host/list` match by domain)                                                                                          |

All commands support a `--dry-run` precheck.

---

## 3. Step 1: Environment Preparation

- Verify `backend/.env`: `DB_DATABASE` **must point at the currently active database** (historical lesson: after a fresh clone the default may point at a backup database, which shows up as "config lost / groups scrambled").
- Check the Redis password, queue workers, and crontab against the [Deployment and Scheduling Guide](../deploy/scheduling.md).
- Migrator connection settings, in precedence order: command-line arguments > `MOFANG_MIGRATE_*` environment variables > `mofang_migrate.conf` (copy `backend/scripts/mofang_migrate.conf.example`; it holds passwords and is already ignored by `.gitignore`).

---

## 4. Step 2: Rebuilding the Three-Level Product Catalog

Data conventions:

- First level `first_product_groups`: the 5 canonical types (matching the built-in `ProductType`)

| internal_id | code        | product_type    | Name          |
| ----------- | ----------- | --------------- | ------------- |
| 1           | `vps`       | `cloud_server`  | Cloud server  |
| 2           | `dedicated` | `game_cloud`    | Game cloud    |
| 3           | `hosting`   | `web_hosting`   | Web hosting   |
| 4           | `domain`    | `cloud_desktop` | Cloud desktop |
| 5           | `other`     | `cdn`           | Other         |

- Second level `second_product_groups` = the legacy site's first-level groups (36 of them, `id = 旧id + 100`)
- Third level `third_product_groups` = the legacy site's second-level groups (207 of them, `id = 旧id + 1000`)
- `products.product_group_id` = third-level group id (`旧 gid + 1000`)

```bash
cd backend
php artisan app:reorganize-product-groups --dry-run   # 先预检
php artisan app:reorganize-product-groups             # 实际重建
```

> The migrator may take the legacy first-level groups directly as TuraIDC types (code scrambled / visible filtered out → empty product list), so this command must be run to normalise them.

---

## 5. Step 3: Product / User / Order Migration

```bash
# dump 已用 normalize_mofang_dump.py 规范化后，执行主迁移器
python3 backend/scripts/mofang_to_turaidc_migrator.py --dry-run   # 预演
python3 backend/scripts/mofang_to_turaidc_migrator.py --truncate  # 干净库实际迁移
```

Output:

- `products` 1337+ (ids identical to the legacy `shd_products.id`)
- users 1279+ (ids identical to `shd_clients.id`)
- related data such as orders / invoices / tickets / services

Key compatibility handling:

- **Passwords**: the `###md5` prefix plus a 32-character MD5, verified by `ZjmfLegacyPasswordVerifier` and then upgraded to bcrypt.
- **pricing (important)**: the target `products.pricing` uses the **flat format** `{"monthly":"25.00"}` (both the current model and the settlement path parse it as flat). Never use the old nested format `{"monthly":{"price":25}}`, otherwise prices become 0 and quotes fail with "invalid billing period".
- **invoice_no uniqueness**: about 42% of the source values are invalid (`'NULL'`, `'INV-0'`, etc.), so the migrator generates `INV-{id:08d}` automatically.
- **operation_logs primary key**: source ids are not migrated, to avoid primary-key collisions with application logs.
- **remark truncation**: every mapped `remark` is truncated to 255 characters.
- **Foreign-key dependencies**: once the main flow finishes, second-level groups are copied into `third_product_groups` automatically to satisfy the `products.product_group_id` foreign key.

---

## 6. Step 4: Config Option Migration (config_options) and OS version Repair

### 1. Assembling config_options

```bash
python3 backend/scripts/mofang_config_options_migrator.py --dump 25y_*.sql --dry-run
python3 backend/scripts/mofang_config_options_migrator.py --dump 25y_*.sql --output /tmp/config_options.sql
```

Source tables: `shd_product_config_links` (products joined via `link.pid`) + `shd_product_config_options` + `shd_product_config_options_sub` + `shd_pricing`.

**Hard format requirements (otherwise the quote API returns 500)**:

- `config_options` must be an **array of config options**, each entry containing `field / name / option_type / hidden / sub / qty_minimum / qty_maximum / parameter / unit`.
- Integration metadata (`mofang_host` / `zjmf_api_id` / `upstream_pid`) must **never** be placed inside `config_options` (`parseField(array)` then raises a type error). Integration fields belong in `product_upstream_bindings`.
- Config option type mapping (identical to `TYPE_FIELD_MAP` in the backend): `6/7/16→cpu`, `8/9/17→memory`, `12→area`, `5→os`, `13/14/19→system_disk_size`, `10/11/18→bw`, `4→ip_num`.

### 2. Repairing OS Config Grouping (the user-visible "OS selection")

The frontend `buildOsGroups` groups by the `大类^版本` format of `sub[].version`. In the dump, OS subs are often malformed (`12|CentOS^CentOS-7.6.1810-x64`, `Windows^Windows^Windows7`, `Windows2022|Windows`), so they must be repaired:

```bash
php artisan app:fix-os-versions --dry-run
php artisan app:fix-os-versions
```

Expected result: `os_items=439、missing_version=0`; groups CentOS:8, Ubuntu:5, Debian:3, Windows:5, Rocky:2, Tencentos:5.

> **Reference trap**: in `foreach (($o["sub"] ?? []) as &$s)` the `??` operator produces a copy, so reference modifications are lost (affected=0). Check `isset($o['sub'])` first, then loop with `&$s`.

### 3. Cross-Database Backfill (optional)

If the active database has fewer `with_config` than the backup database (for example 477 vs 1251):

```bash
php artisan app:backfill-config-options --dry-run
php artisan app:backfill-config-options
```

---

## 7. Step 5: service_type_code Derivation

This is done inside `app:reorganize-product-groups`:

```sql
UPDATE products p
JOIN third_product_groups t ON t.id = p.product_group_id
JOIN second_product_groups s ON s.id = t.second_product_group_id
JOIN first_product_groups f ON f.id = s.first_product_group_id
SET p.service_type_code = f.code
WHERE p.service_type_code IS NULL OR p.service_type_code = '';
```

The migrator's `FieldMap(src="server_type")` fails (the dump has no `server_type` column), so the group command must backfill it.

---

## 8. Step 6: Upstream Synchronisation (suppliers + bindings + key decryption)

```bash
php artisan app:sync-upstreams --dry-run
php artisan app:sync-upstreams --api-keys-file /tmp/keys.json   # 推荐提供预解密明文密钥
```

1. **Parse upstream master data**: the legacy `shd_zjmf_finance_api` table (19 upstreams is typical). Rows land in `suppliers` (`code = zjmf_api_<api_id>`) and `supplier_plugin_bindings` (base_url / account_name plus the encrypted `secret_json`).
2. **Install and enable the plugin**: the command uses `PluginInstaller` to install and enable `upstream/zjmf_finance` automatically (provider_key resolution depends on it).
3. **Product-level bindings**: mapped from the `zjmf_api_id` / `upstream_pid` fields of the legacy `shd_products` table (product ids match the legacy site) and written to `product_upstream_bindings`.
4. **API key decryption** (required for authentication, otherwise every call fails with "interface authentication failed"):
   - Algorithm: `DES-CBC`, key = the first 8 bytes of `md5("shundai")` = `3491f0a7`, IV the same value, PKCS#7 padding + base64.
   - **PHP 8.3 / OpenSSL 3 removed single DES.** The command invokes the openssl CLI (`-provider legacy -provider default`) to decrypt; without the CLI, pass pre-decrypted JSON via `--api-keys-file` (`{"api_id":"明文"}`).
   - Manual reference: `printf %s 密文 | openssl enc -d -des-cbc -provider legacy -provider default -K 3334393166306137 -iv 3334393166306137`
5. **Verification**: `curl -X POST {base_url}/zjmf_api_login -d "username=..&password=明文"` returns a jwt plus `鉴权成功` ("authentication succeeded").

> **Pitfall**: in the `shd_products` dump, the INSERT statements carry **107 fields** (columns added in v10) in an order **different** from the 83 columns of CREATE TABLE, so `zjmf_api_id`/`upstream_pid` actually sit at indexes **[74]/[75]** (do not use 50/51 from the CREATE column order). Reading from the legacy **table** avoids this problem.

---

## 9. Step 7: Real-Name Verification Migration

Legacy real-name records live in `shd_certifi_person` (about 584 rows) and `shd_certifi_company` (about 15 rows); the empty `shd_certification` table is ignored.

```bash
php artisan app:migrate-real-name --dry-run
php artisan app:migrate-real-name
```

Status mapping (legacy → new):

| Legacy status | Meaning         | New `is_verified` | New `verification_status` |
| ------------- | --------------- | ----------------- | ------------------------- |
| 1             | Passed          | 1                 | 2 (verified)              |
| 2             | Pending review  | 0                 | 1 (pending verification)  |
| 3/4           | Rejected/failed | 0                 | 3 (verification failed)   |

Processing rules:

- `auth_user_id` matches the new system's `users.id` (the legacy client id).
- For the same uid present in both person and company (about 6 cases): **take the one with the latest `update_time`** for `users` and write the rest to `verification_histories`.
- `id_card` is encrypted automatically on write through the `LegacyEncrypted` cast.

---

## 10. Step 8: Legacy User Password Policy

- Legacy passwords are kept as `###` plus a 32-character MD5 and upgraded to bcrypt after `ZjmfLegacyPasswordVerifier` validates them.
- If the password migration was not done / failed: remove that logic and force legacy users through the "forgot password" flow to reset it (avoiding residual security risk).

---

## 11. Step 9: Cache Warm-Up and Verification

```bash
cd backend
php artisan optimize:clear
php artisan app:warmup-site-cache   # 改动分组 / 商品后必跑
```

Verification checklist:

- `GET https://api.25y.cn/api/v2/site/home` → 200
- `config_options` of the product detail API: the OS entry's `sub[].version` is `大类^版本`
- Quote API: `POST /api/v2/site/products/{id}/quote` → returns `total_amount`, no 500
- The public site's product list shows "OS selection" grouped by category
- Supplier management "Test connection" passes for all (jwt authentication succeeds)
- The admin user list shows `real_name` / real-name status

---

## 12. Step 10: Backfilling Upstream Bindings for Service Instances

**Symptom**: services that were sold and provisioned show "full control capabilities are not connected; basic information is displayed read-only" in the console.

**Root cause**: `service_upstream_bindings` is empty → `upstream.host_id=0` → the frontend `canManageConsole` check fails.

```bash
php artisan services:backfill-upstream-bindings --dry-run
php artisan services:backfill-upstream-bindings --service-ids 1,2,3   # 可定向
```

Matching logic:

1. **notes ID first**: extracted from `ID：xxx` in the legacy `shd_host.notes` (both full-width and half-width colons are handled, using `mb_strpos` plus a substring regex).
2. **Upstream `/host/list` fallback**: for services without notes, page through every host per supplier (`page` + `limit=100`; note that `limit` takes effect while `pageSize/pagesize` are ignored) and match exactly on `domain` (the hostname).
3. Write to `service_upstream_bindings`: `service_id`, `product_upstream_binding_id`, `supplier_plugin_binding_id`, `plugin_id`, `provider_key`, `upstream_service_id`.

Reference result: 1564 bindings written, 0 failures; Active 309/388 and Suspended 226/244 are now under console control.

> **Supplier base_url pitfall**: `http://` is rejected by the production HTTPS check (`assertTrustedBaseUrl`); confirm that the upstream supports HTTPS before changing it.
> **Boundaries**: hosts absent from the upstream `/host/list` (deleted upstream / historical data) and self-operated products (no `product_upstream_bindings`) cannot be bound, which is expected.

Verification: `GET /api/v2/client/services/{id}` returns `upstream.host_id > 0`, `actions.power=true`, and `actions.module_status=true`.

---

## 13. Pitfalls You Will Hit in Production

1. **Two databases differ**: `tura_25y` (active) vs `turaidc` (backup). Re-run fix scripts against the active database; do not switch databases.
2. **`.user.ini`**: BaoTa Panel generates an immutable `.user.ini` in `dist/` and `backend/public/`; delete it with `chattr -i` before rebuilding the frontend, otherwise vite fails with `ENOTDIR`.
3. **`config_options` must be an array**, otherwise the quote API returns 500.
4. **OS version format** is `大类^版本`; the frontend `buildOsGroups` depends on it.
5. **INSERT 107 fields ≠ CREATE 83 columns**: `zjmf_api_id` / `upstream_pid` are at [74]/[75].
6. **PHP 8.3 has no single DES**: use the openssl legacy provider for DES-CBC, or Python pycryptodome.
7. **`??` copy reference trap**: call `isset` before modifying a foreach reference.
8. **Nginx `error_page 404`**: it must be commented out on the API site, otherwise Laravel's JSON 404 is replaced with HTML.
9. **`perPage` signature**: the `ListTicketsRequest` / `ListLedgerRequest` / `ListNotificationsRequest` subclasses must match the signature of the `ClientFormRequest` base class, otherwise PHP raises a fatal error (list APIs return 500).
10. **Frontend `.env.production`**: easily lost after migration; create one for each of the public site / console / admin (`VITE_API_BASE_URL=https://api.25y.cn/api`, cookie domain `.25y.cn`).
11. **Read-only service console**: `service_upstream_bindings` must be backfilled (see Step 10).
12. **Upstream base_url requires HTTPS**: `http://` is rejected.
13. **The `/host/list` pagination parameter is `limit`**: `pageSize` / `pagesize` are ignored.
14. **notes ID extraction**: a full-width colon regex may fail; use `mb_strpos("ID：")` plus a substring regex.

---

## 14. Post-Migration Data Validation

### Row-Count Reconciliation

Reference script (adjust the expected values for different source data):

```python
import pymysql
from pymysql.cursors import DictCursor

conn = pymysql.connect(host='...', user='...', password='...', database='...',
                       charset='utf8mb4', cursorclass=DictCursor)
expected = {
    "users": 1279, "products": 1337, "orders": 3369, "invoices": 7814,
    "services": 2970, "tickets": 1130, "ticket_replies": 2814,
    "first_product_groups": 36, "second_product_groups": 207,
}
for t, exp in expected.items():
    with conn.cursor() as cur:
        cur.execute(f"SELECT COUNT(*) AS c FROM `{t}`")
        actual = cur.fetchone()['c']
    print(f"  {t:<28} {actual:>8}/{exp:<8} {'✓' if actual == exp else '✗'}")
```

### Sampling Key Fields

- `users.password` should use the `###md5` prefix format.
- `products.pricing` should be flat JSON (`{"monthly":"25.00"}`).
- Each entry of `products.config_options` is an array, and the OS entry's `sub[].version` is `大类^版本`.
- `products.service_type_code` is backfilled everywhere (one of `vps/dedicated/hosting/domain/other`).
- `invoices.invoice_no` is unique everywhere.

---

## 15. Troubleshooting

| Symptom                                                  | Cause and handling                                                                                                     |
| -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `TRUNCATE` timeout / metadata lock                       | Find the `Waiting for table metadata lock` session with `SHOW PROCESSLIST`, `KILL` it, then retry                      |
| `Data too long for column 'remark'`                      | Add `[:255]` truncation to the corresponding `FieldMap`                                                                |
| Quote API 500 `parseField(array)`                        | An integration metadata object leaked into `config_options`; change it to an array of config options and re-run Step 4 |
| Empty product list / scrambled groups                    | The first-level group code is scrambled; re-run `app:reorganize-product-groups`                                        |
| Every upstream reports "interface authentication failed" | The API key was not decrypted; re-run `app:sync-upstreams --api-keys-file`                                             |
| Service console is read-only                             | `service_upstream_bindings` was not backfilled; run Step 10                                                            |
| Frontend home title / favicon unchanged                  | The brand config was not warmed up; run `optimize:clear` + `app:warmup-site-cache`                                     |

---

## Related Documents

- [Local IDC Data Migration Workflow](local-idc.md)
- [BaoTa Panel Project Deployment Guide](../deploy/bt-panel.md)
- [Docker and 1Panel Deployment Guide](../deploy/docker-1panel.md)
- [Deployment and Scheduling Guide](../deploy/scheduling.md)
- [ZJMF Unified Migration Plan](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/completed/zjmf-unified-migration.md)
