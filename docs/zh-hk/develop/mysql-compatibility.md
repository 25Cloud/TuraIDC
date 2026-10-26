---
source: develop/mysql-compatibility.md
sourceHash: ca23de043a3bf90dd64da344c2ae8fcadad9de96b6102ca18d2079a614689692
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/mysql-compatibility.md 產生，請勿手動編輯。 -->
# MySQL 版本兼容基線（5.7.44 / 8.x 雙版本）

本文是數據庫與 SQL 改動的兼容性真源。**任何涉及數據庫的改動（遷移、原生 SQL、查詢構造器寫法、模型寫法）都必須同時在 MySQL 5.7.44 與 8.x 上成立。**

## 1. 為什麼必須雙版本兼容

- 系統最低支持 MySQL **5.7.8**，推薦 8.0+，5.7 屬兼容支持檔（5.7 已 EOL）。存量用戶裡有相當比例仍在 5.7 上運行。
- 從智簡魔方財務（ZJMF）搬遷過來的老站，其原有環境普遍是 5.7。搬遷工具鏈依賴表結構穩定。
- 只在 8.0 上驗證的寫法，在 5.7 上往往不是"降級運行"而是**直接拋 SQL 語法錯誤**，且通常只在特定頁面觸發，線上極難排查。

驗證環境：`5.7.44`（當前 5.7 末版）與 `8.0.x`。以這兩個版本為準，不以"理論上 5.7 支持"推斷。

## 2. 硬性禁令

### 2.1 禁止 8.0 專屬語法

| 特性                                  | 5.7 | 替代寫法                                                        |
| ------------------------------------- | :-: | --------------------------------------------------------------- |
| 窗口函數 `ROW_NUMBER()/RANK()/OVER()` | ❌  | 兩級聚合：先 `MAX()` 分組求極值，再 join 回原表；並列用主鍵打破 |
| CTE `WITH ... AS`、遞歸 CTE           | ❌  | 子查詢 / `joinSub()`                                            |
| 每組 top-N（借窗口函數實現）          | ❌  | 每組一條帶 `LIMIT` 的子查詢 `UNION ALL`，必要時在 PHP 側再排序  |
| `JSON_TABLE()`                        | ❌  | 應用層展開                                                      |
| 降序索引（`INDEX (col DESC)`）        | ⚠️  | 5.7 會靜默忽略方向，不報錯但不生效；不要依賴其排序效果          |

> 現網案例：管理端工單上游投遞日誌曾用 `ROW_NUMBER() OVER (PARTITION BY ...)` 取每工單最新一條，在 5.7 上直接 `ERROR 1064`，整個頁面不可用。已改寫為兩級 `MAX` + `joinSub`。

### 2.2 5.7 的隱式 `ON UPDATE CURRENT_TIMESTAMP` 陷阱

MySQL 5.7 默認 `explicit_defaults_for_timestamp=OFF`（8.0 默認 ON）。此時**表內第一順位、且沒有顯式 `DEFAULT` 的 `timestamp NOT NULL` 列**，會被 MySQL 自動附加 `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`。後果是：任何不包含該列的 `UPDATE`，都會把它悄悄改寫成當前時間。

因此：

- **新增表**：第一個 `timestamp` 列必須顯式給 `DEFAULT`，或聲明為 `nullable`（`$table->timestamp('x')->nullable()`），不要留裸的 `timestamp NOT NULL`。
- **更新既有行**：若目標表的首個 `timestamp` 列屬上述形態，`UPDATE` 語句必須**顯式帶上該列的原值**來覆蓋隱式行為（`Model::save()` 只寫髒字段，不足以防護）。
- **部署要求**：5.7 部署應在 `my.cnf` 的 `[mysqld]` 設 `explicit_defaults_for_timestamp=ON`。這是縱深防禦，不能替代上面兩條代碼層要求——不能假設用戶改過配置。

> 現網案例：`schedule_ticks.slot_started_at` 正屬此形態。同一 15 分鐘槽內第二次心跳更新 `triggered_at` 時，5.7 把槽起點改成了當前時間，槽唯一鍵語義崩壞，調度器從該槽第 ~3 分鐘起持續撞 `global_number` 唯一鍵。修復方式是在 `UPDATE` 中顯式回寫 `slot_started_at` 原值（零 DDL）。

### 2.3 版本下限相關

- `json` 列類型、虛擬生成列上的唯一索引：**5.7.8 起**才支持，這是最低版本 5.7.8 的由來。基線含 58 個 `json` 列，不可退到 5.6。
- 索引鍵長度：5.7 若未開 `innodb_large_prefix`（默認值隨小版本變化），`utf8mb4` 下單列索引超過 191 字符可能失敗。新增索引列儘量控制在 `varchar(191)` 以內，超長字段用前綴索引或哈希列。

## 3. 數據庫只增不刪（鐵律）

**絕不刪除任何表、列或索引。** 只允許新增。

原因：我們不是原始開發者，存量用戶會從更老的版本升級上來，還有用戶正從魔方財務搬遷過來——遷移工具鏈（`backend/scripts/mofang_to_turaidc_migrator.py` 等）依賴表結構穩定。任何刪除操作都可能讓存量升級路徑在毫無徵兆處崩掉，且難以回滾。

- 發現冗餘表 / 冗餘列：**只記錄、不動手**，把處置權留給上游維護者。
- 遷移只新增，且必須冪等（先 `Schema::hasTable` / `hasColumn` 再動手），不補跑歷史激進遷移。
- 需要改變約束語義時（如放寬 `NOT NULL`、替換唯一鍵），走"新增/放寬/替換"路徑，不走"先刪後建"的淨刪除。

## 4. 框架層已自適應的部分（無需額外處理）

Laravel 12 內部已按服務端版本分支處理以下差異，**不要為它們手寫版本判斷**：

- `sql_mode` 設置：`MySqlConnector` 以 8.0.11 為界分支。
- 隊列 `SKIP LOCKED`：`DatabaseQueue` 以 8.0.1 為界，5.7 自動降級。
- `upsert` 的 `VALUES()` 語法：按版本選擇寫法。

## 5. 改動前後的自檢清單

1. 新增/修改的 SQL 是否用到窗口函數、CTE、`JSON_TABLE`？→ 有則必須改寫。
2. 新增表是否有裸 `timestamp NOT NULL` 作為首個時間列？→ 有則加顯式默認值或改可空。
3. 是否新增了對既有表的 `UPDATE` 路徑？→ 檢查該表首個 `timestamp` 列形態，必要時顯式回寫。
4. 遷移裡有沒有 `dropColumn` / `dropIndex` / `dropIfExists` 出現在 `up()`？→ 有則必須去掉（`down()` 中的回滾邏輯不受此限）。
5. 是否在 5.7 與 8.0 兩套庫上都跑過受影響的測試？→ 兩邊的失敗集合應當一致。

## 6. 如何在本地/伺服器驗證 5.7

不要動生產庫。用獨立 datadir 的 5.7 沙箱：

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

隨後以 `DB_PORT=3307` 覆蓋環境變量跑測試套件，與 8.0 的結果逐條比對：**5.7 的失敗集合必須與 8.0 完全一致**，多出任何一條都要按真實缺陷對待，而不是"5.7 就這樣"。

## 關聯文檔

- [從智簡魔方財務系統遷移](../guide/migrate/zjmf-finance.md)：遷移流程中的兼容性實踐。
- [數據庫結構説明](/develop/database)：當前庫表結構快照。
