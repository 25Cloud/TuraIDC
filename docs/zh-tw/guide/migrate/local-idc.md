---
source: guide/migrate/local-idc.md
sourceHash: 04d5e837a7acab1280e2248a555c5261cb616bb681b02f71b970ed07eea2cc06
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/migrate/local-idc.md 產生，請勿手動編輯。 -->
# 本地 IDC 資料遷移流程

本文記錄本地資料庫從 MySQL dump 重置、初始化、遷移資料的固定流程。

> 若源系統是智簡魔方財務（表字首 `shd_`），請改用實驗性遷移器：[從智簡魔方財務系統遷移（實驗）](zjmf-finance-experimental.md)。本文件針對當前結構內的 dump 遷移，不剝離表字首。

## 一鍵指令碼

將 dump 的業務資料遷移到**已是當前結構**的本地 `idc` 庫（不改表結構）：

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql
```

遷移前執行完整隻讀演練（解析全部 INSERT、核驗欄位對映和分類層級，不寫庫）：

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql --dry-run
```

對已經執行的遷移做獨立核驗（逐錶行數、核心引用和三級商品分類）：

```bat
python backend\scripts\migrate_legacy_dump.py --dump C:\path\to\idc_dump.sql --verify
```

如需連同當前結構初始化一起重建本地庫，再遷移指定 dump：

```bat
python backend\scripts\reset_init_and_migrate_idc_dump.py --dump C:\path\to\idc_dump.sql
```

所有遷移指令碼都要求顯式傳入 `--dump`，避免誤用已清理的歷史備份。

僅初始化當前庫結構，不匯入舊 dump：

```bat
python backend\scripts\install_db.py --reset
```

只檢查不寫庫：

```bat
python backend\scripts\reset_init_and_migrate_idc_dump.py --dump C:\path\to\idc_dump.sql --dry-run
```

遷移記錄預設寫入：

```text
控制台输出或脚本指定的本地日志位置
```

一次性遷移日誌不進入長期文件樹；如需保留結論，應提煉為本檔案中的相容規則。

## 執行順序

1. `backend/scripts/install_db.py --reset`
   - 刪除並重建 `.env` 指向的本地資料庫。
   - 使用當前專案 schema 初始化表結構。
   - 執行當前遷移。
   - 初始化預設 `settings`、通知模板和預設管理員。
   - schema baseline 只承載表結構和 `migrations` 狀態，不承載業務行資料；預設執行資料由本指令碼顯式補齊。

2. `backend/scripts/migrate_legacy_dump.py`
   - 直接解析 dump 的 INSERT，並在單一事務中按當前目標表欄位對映複製資料；不會恢復舊 dump 的表結構，也不需要建立臨時庫。
   - 遷移會清空並重建目標業務資料，且保留當前 `migrations`，避免以舊遷移狀態覆蓋當前程式碼結構。
   - 舊單表 `product_groups` 按層級對映到當前的 `first_product_groups`、`second_product_groups`、`third_product_groups`，保留 ID 並回填商品三級分類欄位；舊二級分類直掛商品或未掛分類商品會自動建立歷史承接三級分類，確保當前結構中的每個商品都有有效三級分類。
   - 備份中缺少的當前表（如新增的 `recharge_records`、`refunds`）會置空；備份存在而指令碼未定義對映的表會直接失敗，禁止靜默丟失。
   - 清空執行態表 `jobs`、`password_reset_tokens`、`personal_access_tokens`、`sessions`，不遷移舊執行態資料。
   - 保留當前庫 `settings` 中的 `codex_runtime`、`codex_service` 臨時配置，不用 dump 覆蓋。
   - 自動發現 PATH 或 BaoTa 安裝目錄中的 `mysql.exe`；也可通過 `MYSQL_BIN` 指定客戶端路徑。
   - 遷移完成後校驗逐錶行數、關鍵外部索引鍵引用、執行態表為空及三層商品分類完整性。

## 已知相容規則

- 舊 dump 缺少當前新增日誌表 `activity_logs`、`gateway_logs`、`schedule_run_logs` 時，初始化後的目標表保持空表。
- `products.name` 等舊欄位不會被恢復。
- `orders`、`invoices` 的商品規格快照會優先對映 `product_spec_snapshot`，缺失時相容舊的 `product_name_snapshot`。

## 遷移後檢查

- 核心錶行數符合預期：`users`、`products`、`invoices`、`services`、`payments`、`operation_logs`。
- 執行態表保持清空：`jobs`、`sessions`、`password_reset_tokens`、`personal_access_tokens`。
- 當前結構與 `docs/develop/database.md` 一致。
- 前後端能使用遷移後的管理員、使用者、商品和服務資料完成最小冒煙。

## 重新整理當前結構基線

當前庫結構或資料遷移完成後，需要同步重新整理空庫初始化 baseline 和資料庫結構快照：

```bat
php backend\scripts\export_schema_baseline.php
php backend\scripts\export_database_structure.php
```

`export_schema_baseline.php` 只匯出表結構和 `migrations` 記錄；`install_db.py` 會在匯入 baseline 後補預設配置、通知模板和管理員。
