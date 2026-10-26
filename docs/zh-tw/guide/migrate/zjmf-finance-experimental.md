---
source: guide/migrate/zjmf-finance-experimental.md
sourceHash: e4a32200d9fc9b61de7b4801bd387ce485dc8de2315376ca7546474a3ac2d0af
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/migrate/zjmf-finance-experimental.md 產生，請勿手動編輯。 -->
# 從智簡魔方財務系統遷移（實驗）

> ⚠️ **本文件已歸檔為實驗性記錄**，完整遷移教程請見正式指南：[從智簡魔方財務系統遷移](zjmf-finance.md)（已整合生產環境全流程與踩坑記錄，包括 pricing 扁平格式、config_options 組裝、上游解密、服務繫結回填等）。

本文記錄將智簡魔方財務（魔方財務 / ZJMF）業務資料遷移至圖拉雲 `turaidc` 資料庫的實驗性流程。該遷移器未整合進 `install_db.py`，僅作為一次性匯入工具存在；正式生產遷移仍以 `migrate_legacy_dump.py` 為準。

## 1. 適用場景

- 源系統：智簡魔方財務（資料庫表字首 `shd_`，舊版本可能使用 `mccloud_`、`ewytemplate_` 等字首）。
- 目標系統：圖拉雲 `turaidc` 當前 schema。
- 資料規模：約 2.4 萬行業務資料（使用者 1279、商品 1337、訂單 3369、賬單 7814、服務 2970、工單 1130）。
- 遷移方式：從 `mysqldump` 檔案流式解析 + 欄位對映 + 批次寫入，不要求源庫線上。

## 2. 指令碼與依賴

| 指令碼                                                                                    | 作用                                                        |
| --------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| [mofang_to_turaidc_migrator.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/mofang_to_turaidc_migrator.py) | 主遷移器，解析 dump、欄位對映、批次寫入、pricing JSON 轉換  |
| [mofang_migrate.conf.example](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/mofang_migrate.conf.example)     | 遷移器配置檔案模板（複製為 `mofang_migrate.conf` 免輸引數） |
| [normalize_mofang_dump.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/normalize_mofang_dump.py)           | 預處理 dump，剝離 `shd_` 等字首並重命名衝突表               |
| [count_mofang_rows.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/count_mofang_rows.py)                   | 統計源錶行數，評估遷移規模                                  |
| [peek_mofang_rows.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/peek_mofang_rows.py)                     | 抽樣源資料，驗證欄位格式（如 `###md5` 密碼）                |
| [dryrun_migration_analysis.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/dryrun_migration_analysis.py)   | 純 Python dry-run，分析源/目標列交集和必填欄位缺失          |
| [extract_mofang_core_tables.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/extract_mofang_core_tables.py) | 從大 dump 中抽取核心表，便於離線分析                        |

依賴：`pymysql`（已在 backend 虛擬環境中安裝）。

## 3. 連線配置（三種方式，按優先順序合併）

> 命令列引數 > 環境變數 `MOFANG_MIGRATE_<KEY>` > 配置檔案。

**方式一：配置檔案（推薦，零引數執行）**

複製 `mofang_migrate.conf.example` 為 `mofang_migrate.conf`（指令碼同目錄），填寫真實值：

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

> `mofang_migrate.conf` 含資料庫密碼，已加入 `.gitignore`，**不會提交到倉庫**；`dump` 留空時會自動發現專案根 / `backend/scripts` 下的 `25y_*.sql`。

**方式二：環境變數**

```
MOFANG_MIGRATE_HOST / MOFANG_MIGRATE_PORT / MOFANG_MIGRATE_USER /
MOFANG_MIGRATE_PASSWORD / MOFANG_MIGRATE_DATABASE / MOFANG_MIGRATE_DUMP
```

**方式三：命令列引數**（優先順序最高，見下方執行流程）。

## 4. 執行流程

### 1. 預檢：源資料評估

```bat
python backend\scripts\count_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\peek_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\dryrun_migration_analysis.py --dump path\to\shd_dump.sql
```

輸出包含源錶行數、關鍵欄位格式（密碼、pricing 列）、列交集和必填欄位缺失清單。

### 2. 預檢：dry-run 全量預演

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

dry-run 不寫庫，僅解析、對映、計數。期望輸出 `合计: 源行 24595  入库 24595  失败 0`。

### 3. 實際遷移（含清空目標表）

```bat
python backend\scripts\mofang_to_turaidc_migrator.py --truncate
```

`--truncate` 會在遷移前清空 19 張目標表（TRUNCATE，DDL，4 秒完成）。**僅在乾淨庫使用**。

### 4. 按白名單遷移指定表（修復失敗表時使用）

```bat
python backend\scripts\mofang_to_turaidc_migrator.py ^
    --tables shd_configuration,shd_products,shd_invoices,shd_admin_log,shd_pricing
```

### 5. 遷移後校驗

見下文「資料校驗」一節。

## 5. 表對映規則

源表 `shd_*` 剝離字首後，按以下對應關係寫入目標表：

| 源表                              | 目標表                          | 備註                                                  |
| --------------------------------- | ------------------------------- | ----------------------------------------------------- |
| `shd_configuration`               | `settings`                      | 補 `group_key='general'`                              |
| `shd_product_first_groups`        | `first_product_groups`          | 一級商品組                                            |
| `shd_product_groups`              | `second_product_groups`         | 二級商品組，唯一鍵衝突由單行降級跳過                  |
| `shd_upper_reaches`               | `suppliers`                     | 上游供應商（源資料通常為空）                          |
| `shd_clients`                     | `users` + `user_accounts`       | 同源資料雙寫，密碼保留 `###md5`                       |
| `shd_products`                    | `products`                      | `description → remark` 截斷 255；`pricing` 後處理填入 |
| `shd_orders`                      | `orders`                        | `ordernum → order_no`，空值生成 `ORD-{id:08d}`        |
| `shd_invoices`                    | `invoices`                      | `invoice_num → invoice_no`，無效值生成 `INV-{id:08d}` |
| `shd_host`                        | `services`                      | 使用者開通的服務例項                                    |
| `shd_ticket` / `shd_ticket_reply` | `tickets` / `ticket_replies`    | 工單與回覆                                            |
| `shd_promo_code`                  | `coupons`                       | 優惠碼                                                |
| `shd_activity_log`                | `activity_logs`                 | 使用者活動日誌                                          |
| `shd_admin_log`                   | `operation_logs`                | 管理員操作日誌，不遷移源 id                           |
| `shd_message_log`                 | `message_logs`                  | 訊息日誌                                              |
| `shd_pricing`                     | (後處理寫入 `products.pricing`) | 不直接入庫，作為 pricing JSON 源                      |
| `shd_product_groups`              | `third_product_groups`          | 後處理同步二級組到三級組，滿足外部索引鍵                    |

## 6. 關鍵相容性處理

### 密碼：`###md5` 格式

魔方財務密碼格式為 `###md5` 字首 + 32 位 md5，與圖拉雲 bcrypt 不相容。遷移時**原樣保留**，由 [ZjmfLegacyPasswordVerifier.php](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php) 在登入時識別並驗證，使用者登入後可平滑升級到 bcrypt。

### pricing：多列週期價格 → JSON

源 `shd_pricing` 表為每個商品、每個幣種、每個週期（monthly/quarterly/semiannually/annually 等）各一列。遷移器將其聚合為目標 `products.pricing` JSON 欄位：

```json
{
  "monthly": { "price": 25.0 },
  "quarterly": { "price": 75.0 },
  "semiannually": { "price": 150.0 },
  "annually": { "price": 300.0 }
}
```

初裝費 `setup_fee` 從對應週期的 `*setupfee` 列提取。同一產品多幣種記錄時，優先取 `currency=0`（人民幣）。

### invoice_no 唯一性兜底

源資料中約 42% 的 `invoice_num` 為無效值（3280 條字串 `'NULL'`、10 條空字串、部分 `'INV-0'`）。遷移器在 `_post_map_fix` 中檢測無效值集合 `{"", "INV-0", "0", "NULL", "NONE", "N/A", "-"}`（大小寫不敏感），命中時用源 id 生成唯一值 `INV-{id:08d}`，保證 `invoice_no` NOT NULL UNIQUE 約束滿足。

### operation_logs 主鍵衝突

目標庫 `operation_logs` 在應用執行期間持續寫入，主鍵 `id` 由 auto_increment 分配。遷移源 `shd_admin_log` 時**不遷移源 id**，避免與應用日誌主鍵衝突。

### remark 欄位截斷

目標庫所有 `remark` 欄位為 `varchar(255)`，源資料 `description`/`notes` 欄位可能超長。所有 remark 對映統一使用 `lambda v: to_str(v)[:255] if v else None` 截斷。

### 外部索引鍵依賴：三級商品組

目標庫 `products.product_group_id` 外部索引鍵指向 `third_product_groups.id`，源庫只有兩級組。遷移器在主流程結束後呼叫 `_sync_third_product_groups`，將 `shd_product_groups` 資料複製到 `third_product_groups`，滿足外部索引鍵約束。

## 7. 資料校驗

### 行數對賬

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

### 關鍵欄位抽樣

- `users.password` 應為 `###md5` 字首格式。
- `products.pricing` 應為非空 JSON（含 `monthly`/`annually` 等週期鍵）。
- `invoices.invoice_no` 全部唯一（`GROUP BY ... HAVING COUNT(*) > 1` 應返回空）。

## 8. 已知限制

- **不遷移的內容**：知識庫（`shd_knowledge_base`）、郵件模板、支付閘道器配置、上游伺服器配置等非核心業務表保持空表。
- **源資料髒資料**：源 `shd_orders` 存在 2 條重複 `ordernum`，由單行降級插入自動跳過；最終入庫 3367/3369。
- **應用併發寫入**：遷移期間目標庫 NestJS 應用若持續執行，會與遷移器爭用 `operation_logs` 等表，建議遷移時停應用或選擇低峰期。
- **後設資料鎖風險**：遠端 MySQL 對 DDL（TRUNCATE）敏感，若目標庫存在長事務會阻塞 TRUNCATE。遷移前應 `SHOW PROCESSLIST` 檢查並 KILL 遺留空閒連線。
- **實驗性**：該遷移器未納入 `install_db.py` 自動流程，schema 演進時需手動同步對映規則。

## 9. 故障排查

### TRUNCATE 超時（後設資料鎖）

```
(2013, 'Lost connection to MySQL server during query (The read operation timed out)')
```

排查步驟：

1. `SHOW PROCESSLIST` 查詢 `Waiting for table metadata lock` 的會話。
2. 定位鎖源頭（通常是 localhost 上 NestJS 應用的長事務）。
3. `KILL <id>` 終止阻塞程序，再重試 TRUNCATE。

### 欄位長度超限

```
DataError: (1406, "Data too long for column 'remark' at row 1")
```

所有 `remark` 對映已統一截斷到 255 字元；若其他 varchar 欄位報錯，在對應 `FieldMap` 中新增 `[:N]` 截斷。

### 主鍵/唯一鍵衝突

```
IntegrityError: (1062, "Duplicate entry '...' for key '...'")
```

遷移器內建單行降級插入：批次失敗後逐行重試，衝突行跳過並計入 `failed`。若失敗行數異常高，檢查 `_post_map_fix` 是否覆蓋該表的無效值場景。

## 10. 相關文件

- [本地 IDC 資料遷移流程](local-idc.md) — 正式遷移流程
- [ZJMF 統一遷移方案](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/completed/zjmf-unified-migration.md) — ZJMF 相容性整體方案
