---
source: guide/migrate/zjmf-finance-experimental.md
sourceHash: e4a32200d9fc9b61de7b4801bd387ce485dc8de2315376ca7546474a3ac2d0af
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/guide/migrate/zjmf-finance-experimental.md 產生，請勿手動編輯。 -->
# 從智簡魔方財務系統遷移（實驗）

> ⚠️ **本文檔已歸檔為實驗性記錄**，完整遷移教程請見正式指南：[從智簡魔方財務系統遷移](zjmf-finance.md)（已整合生產環境全流程與踩坑記錄，包括 pricing 扁平格式、config_options 組裝、上游解密、服務綁定回填等）。

本文記錄將智簡魔方財務（魔方財務 / ZJMF）業務數據遷移至圖拉雲 `turaidc` 數據庫的實驗性流程。該遷移器未集成進 `install_db.py`，僅作為一次性導入工具存在；正式生產遷移仍以 `migrate_legacy_dump.py` 為準。

## 1. 適用場景

- 源系統：智簡魔方財務（數據庫表前綴 `shd_`，舊版本可能使用 `mccloud_`、`ewytemplate_` 等前綴）。
- 目標系統：圖拉雲 `turaidc` 當前 schema。
- 數據規模：約 2.4 萬行業務數據（用戶 1279、商品 1337、訂單 3369、賬單 7814、服務 2970、工單 1130）。
- 遷移方式：從 `mysqldump` 文件流式解析 + 字段映射 + 批量寫入，不要求源庫在線。

## 2. 腳本與依賴

| 腳本                                                                                    | 作用                                                        |
| --------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| [mofang_to_turaidc_migrator.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/mofang_to_turaidc_migrator.py) | 主遷移器，解析 dump、字段映射、批量寫入、pricing JSON 轉換  |
| [mofang_migrate.conf.example](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/mofang_migrate.conf.example)     | 遷移器配置文件模板（複製為 `mofang_migrate.conf` 免輸參數） |
| [normalize_mofang_dump.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/normalize_mofang_dump.py)           | 預處理 dump，剝離 `shd_` 等前綴並重命名衝突表               |
| [count_mofang_rows.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/count_mofang_rows.py)                   | 統計源錶行數，評估遷移規模                                  |
| [peek_mofang_rows.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/peek_mofang_rows.py)                     | 抽樣源數據，驗證字段格式（如 `###md5` 密碼）                |
| [dryrun_migration_analysis.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/dryrun_migration_analysis.py)   | 純 Python dry-run，分析源/目標列交集和必填字段缺失          |
| [extract_mofang_core_tables.py](https://github.com/25Cloud/TuraIDC/blob/main/backend/scripts/extract_mofang_core_tables.py) | 從大 dump 中抽取核心表，便於離線分析                        |

依賴：`pymysql`（已在 backend 虛擬環境中安裝）。

## 3. 連接配置（三種方式，按優先級合併）

> 命令行參數 > 環境變量 `MOFANG_MIGRATE_<KEY>` > 配置文件。

**方式一：配置文件（推薦，零參數運行）**

複製 `mofang_migrate.conf.example` 為 `mofang_migrate.conf`（腳本同目錄），填寫真實值：

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

> `mofang_migrate.conf` 含數據庫密碼，已加入 `.gitignore`，**不會提交到倉庫**；`dump` 留空時會自動發現項目根 / `backend/scripts` 下的 `25y_*.sql`。

**方式二：環境變量**

```
MOFANG_MIGRATE_HOST / MOFANG_MIGRATE_PORT / MOFANG_MIGRATE_USER /
MOFANG_MIGRATE_PASSWORD / MOFANG_MIGRATE_DATABASE / MOFANG_MIGRATE_DUMP
```

**方式三：命令行參數**（優先級最高，見下方執行流程）。

## 4. 執行流程

### 1. 預檢：源數據評估

```bat
python backend\scripts\count_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\peek_mofang_rows.py --dump path\to\shd_dump.sql
python backend\scripts\dryrun_migration_analysis.py --dump path\to\shd_dump.sql
```

輸出包含源錶行數、關鍵字段格式（密碼、pricing 列）、列交集和必填字段缺失清單。

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

dry-run 不寫庫，僅解析、映射、計數。期望輸出 `合计: 源行 24595  入库 24595  失败 0`。

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

見下文「數據校驗」一節。

## 5. 表映射規則

源表 `shd_*` 剝離前綴後，按以下對應關係寫入目標表：

| 源表                              | 目標表                          | 備註                                                  |
| --------------------------------- | ------------------------------- | ----------------------------------------------------- |
| `shd_configuration`               | `settings`                      | 補 `group_key='general'`                              |
| `shd_product_first_groups`        | `first_product_groups`          | 一級商品組                                            |
| `shd_product_groups`              | `second_product_groups`         | 二級商品組，唯一鍵衝突由單行降級跳過                  |
| `shd_upper_reaches`               | `suppliers`                     | 上游供應商（源數據通常為空）                          |
| `shd_clients`                     | `users` + `user_accounts`       | 同源數據雙寫，密碼保留 `###md5`                       |
| `shd_products`                    | `products`                      | `description → remark` 截斷 255；`pricing` 後處理填入 |
| `shd_orders`                      | `orders`                        | `ordernum → order_no`，空值生成 `ORD-{id:08d}`        |
| `shd_invoices`                    | `invoices`                      | `invoice_num → invoice_no`，無效值生成 `INV-{id:08d}` |
| `shd_host`                        | `services`                      | 用戶開通的服務實例                                    |
| `shd_ticket` / `shd_ticket_reply` | `tickets` / `ticket_replies`    | 工單與回覆                                            |
| `shd_promo_code`                  | `coupons`                       | 優惠碼                                                |
| `shd_activity_log`                | `activity_logs`                 | 用戶活動日誌                                          |
| `shd_admin_log`                   | `operation_logs`                | 管理員操作日誌，不遷移源 id                           |
| `shd_message_log`                 | `message_logs`                  | 消息日誌                                              |
| `shd_pricing`                     | (後處理寫入 `products.pricing`) | 不直接入庫，作為 pricing JSON 源                      |
| `shd_product_groups`              | `third_product_groups`          | 後處理同步二級組到三級組，滿足外鍵                    |

## 6. 關鍵兼容性處理

### 密碼：`###md5` 格式

魔方財務密碼格式為 `###md5` 前綴 + 32 位 md5，與圖拉雲 bcrypt 不兼容。遷移時**原樣保留**，由 [ZjmfLegacyPasswordVerifier.php](https://github.com/25Cloud/TuraIDC/blob/main/backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php) 在登錄時識別並驗證，用戶登錄後可平滑升級到 bcrypt。

### pricing：多列週期價格 → JSON

源 `shd_pricing` 表為每個商品、每個幣種、每個週期（monthly/quarterly/semiannually/annually 等）各一列。遷移器將其聚合為目標 `products.pricing` JSON 字段：

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

源數據中約 42% 的 `invoice_num` 為無效值（3280 條字符串 `'NULL'`、10 條空字符串、部分 `'INV-0'`）。遷移器在 `_post_map_fix` 中檢測無效值集合 `{"", "INV-0", "0", "NULL", "NONE", "N/A", "-"}`（大小寫不敏感），命中時用源 id 生成唯一值 `INV-{id:08d}`，保證 `invoice_no` NOT NULL UNIQUE 約束滿足。

### operation_logs 主鍵衝突

目標庫 `operation_logs` 在應用運行期間持續寫入，主鍵 `id` 由 auto_increment 分配。遷移源 `shd_admin_log` 時**不遷移源 id**，避免與應用日誌主鍵衝突。

### remark 字段截斷

目標庫所有 `remark` 字段為 `varchar(255)`，源數據 `description`/`notes` 字段可能超長。所有 remark 映射統一使用 `lambda v: to_str(v)[:255] if v else None` 截斷。

### 外鍵依賴：三級商品組

目標庫 `products.product_group_id` 外鍵指向 `third_product_groups.id`，源庫只有兩級組。遷移器在主流程結束後調用 `_sync_third_product_groups`，將 `shd_product_groups` 數據複製到 `third_product_groups`，滿足外鍵約束。

## 7. 數據校驗

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

### 關鍵字段抽樣

- `users.password` 應為 `###md5` 前綴格式。
- `products.pricing` 應為非空 JSON（含 `monthly`/`annually` 等週期鍵）。
- `invoices.invoice_no` 全部唯一（`GROUP BY ... HAVING COUNT(*) > 1` 應返回空）。

## 8. 已知限制

- **不遷移的內容**：知識庫（`shd_knowledge_base`）、郵件模板、支付網關配置、上游伺服器配置等非核心業務表保持空表。
- **源數據髒數據**：源 `shd_orders` 存在 2 條重複 `ordernum`，由單行降級插入自動跳過；最終入庫 3367/3369。
- **應用併發寫入**：遷移期間目標庫 NestJS 應用若持續運行，會與遷移器爭用 `operation_logs` 等表，建議遷移時停應用或選擇低峯期。
- **元數據鎖風險**：遠程 MySQL 對 DDL（TRUNCATE）敏感，若目標庫存在長事務會阻塞 TRUNCATE。遷移前應 `SHOW PROCESSLIST` 檢查並 KILL 遺留空閒連接。
- **實驗性**：該遷移器未納入 `install_db.py` 自動流程，schema 演進時需手動同步映射規則。

## 9. 故障排查

### TRUNCATE 超時（元數據鎖）

```
(2013, 'Lost connection to MySQL server during query (The read operation timed out)')
```

排查步驟：

1. `SHOW PROCESSLIST` 查找 `Waiting for table metadata lock` 的會話。
2. 定位鎖源頭（通常是 localhost 上 NestJS 應用的長事務）。
3. `KILL <id>` 終止阻塞進程，再重試 TRUNCATE。

### 字段長度超限

```
DataError: (1406, "Data too long for column 'remark' at row 1")
```

所有 `remark` 映射已統一截斷到 255 字符；若其他 varchar 字段報錯，在對應 `FieldMap` 中添加 `[:N]` 截斷。

### 主鍵/唯一鍵衝突

```
IntegrityError: (1062, "Duplicate entry '...' for key '...'")
```

遷移器內置單行降級插入：批量失敗後逐行重試，衝突行跳過並計入 `failed`。若失敗行數異常高，檢查 `_post_map_fix` 是否覆蓋該表的無效值場景。

## 10. 相關文檔

- [本地 IDC 數據遷移流程](local-idc.md) — 正式遷移流程
- [ZJMF 統一遷移方案](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/completed/zjmf-unified-migration.md) — ZJMF 兼容性整體方案
