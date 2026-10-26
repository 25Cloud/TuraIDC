---
source: guide/migrate/zjmf-finance.md
sourceHash: c186e16c5a818fd65c15d97a58e6b71436e08a78adb491fd8a474a64b3625ade
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/migrate/zjmf-finance.md 產生，請勿手動編輯。 -->
# 從智簡魔方財務系統遷移到 TuraIDC

本文件是**權威遷移教程**，整合了「智簡魔方財務（ZJMF，`shd_` 字首）」老站遷移到 TuraIDC 的全流程、工具與生產環境踩坑記錄。適用於已有魔方財務業務資料、希望切換到 TuraIDC 的部署場景。

> 舊實驗版文件見 [從智簡魔方財務系統遷移（實驗）](zjmf-finance-experimental.md)，本文件已涵蓋其全部內容並按生產經驗修訂（如 pricing 扁平格式）。

---

## 1. 迁移总览

| 项       | 说明                                                                            |
| -------- | ------------------------------------------------------------------------------- |
| 源系统   | 智简魔方财务，数据库表前缀 `shd_`（旧版本可能为 `mccloud_`、`ewytemplate_` 等） |
| 目标系统 | TuraIDC（Laravel 12 + MySQL 8）                                                 |
| 迁移方式 | `mysqldump` 文件流式解析（无需源库在线）+ 字段映射 + 批量写入                   |
| 参考规模 | 用户 1279、商品 1337、订单 3369、账单 7814、服务 2970、工单 1130                |
| 数据安全 | 老站密码 `###md5` 原样保留，登录时验证后平滑升级 bcrypt                         |

迁移分 10 步：

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

## 2. 工具清單

### 遷移指令碼（Python，`backend/scripts/`）

| 指令碼                                           | 作用                                                                                                   |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `mofang_to_turaidc_migrator.py`                | 主遷移器：產品 / 使用者 / 訂單 / 分組 / 主機等（支援 `--dry-run`、`--truncate`、`--tables` 白名單）      |
| `mofang_config_options_migrator.py`            | 解析 dump 的 `shd_product_config_links / options / sub / pricing`，組裝 `products.config_options` JSON |
| `normalize_mofang_dump.py`                     | 預處理 dump，剝離 `shd_` 等字首並重命名衝突表                                                          |
| `count_mofang_rows.py` / `peek_mofang_rows.py` | 統計源錶行數 / 抽樣源資料                                                                              |
| `dryrun_migration_analysis.py`                 | 純 Python dry-run，分析源/目標列交集與必填欄位缺失                                                     |
| `extract_mofang_core_tables.py`                | 從大 dump 抽取核心表，便於離線分析                                                                     |

### Artisan 命令（`backend/app/Console/Commands/`）

| 命令                                  | 作用                                                                                                    |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| `app:reorganize-product-groups`       | 重建三級分組：一級組規範化（ProductType 內建 5 類）、歸屬重對映、`service_type_code` 派生、隱藏空二級組 |
| `app:sync-upstreams`                  | 從舊庫同步上游供應商，解密 API 金鑰寫入 `supplier_plugin_bindings`，並建立產品級繫結                    |
| `app:migrate-real-name`               | 實名認證遷移（`shd_certifi_person / company` → `users` + `verification_histories`）                     |
| `app:fix-os-versions`                 | 修復 OS 配置項 `sub[].version`（`大类^版本`）並清理多餘 `^`                                             |
| `app:backfill-config-options`         | 跨庫補齊缺失的 `products.config_options`（從備份庫回填）                                                |
| `services:backfill-upstream-bindings` | 回填 `service_upstream_bindings`（notes `ID：` + 上游 `/host/list` 按域名匹配）                         |

所有命令均支援 `--dry-run` 預檢。

---

## 3. Step 1：環境準備

- 核對 `backend/.env`：`DB_DATABASE` **必須是當前活躍庫**（歷史教訓：重新 clone 後預設可能指向備份庫，導致"配置丟失 / 分組錯亂"）。
- Redis 密碼、佇列 worker、crontab 按 [部署與排程指南](../deploy/scheduling.md) 核對。
- 遷移器連線配置：命令列引數 > 環境變數 `MOFANG_MIGRATE_*` > `mofang_migrate.conf`（複製 `backend/scripts/mofang_migrate.conf.example`，含密碼，已被 `.gitignore` 忽略）。

---

## 4. Step 2：產品目錄三級結構重建

資料約定：

- 一級 `first_product_groups`：5 個規範型別（與 `ProductType` 內建一致）

| internal_id | code        | product_type    | 名稱     |
| ----------- | ----------- | --------------- | -------- |
| 1           | `vps`       | `cloud_server`  | 雲伺服器 |
| 2           | `dedicated` | `game_cloud`    | 遊戲雲   |
| 3           | `hosting`   | `web_hosting`   | 虛擬主機 |
| 4           | `domain`    | `cloud_desktop` | 雲電腦   |
| 5           | `other`     | `cdn`           | 其他     |

- 二級 `second_product_groups` = 老站一級組（36 個，`id = 旧id + 100`）
- 三級 `third_product_groups` = 老站二級組（207 個，`id = 旧id + 1000`）
- `products.product_group_id` = 三級組 id（`旧 gid + 1000`）

```bash
cd backend
php artisan app:reorganize-product-groups --dry-run   # 先预检
php artisan app:reorganize-product-groups             # 实际重建
```

> 遷移器可能把老站一級組直接當作 TuraIDC 型別（code 錯亂 / visible 被過濾 → 商品列表為空），必須執行本命令規範化。

---

## 5. Step 3：產品 / 使用者 / 訂單遷移

```bash
# dump 已用 normalize_mofang_dump.py 规范化后，执行主迁移器
python3 backend/scripts/mofang_to_turaidc_migrator.py --dry-run   # 预演
python3 backend/scripts/mofang_to_turaidc_migrator.py --truncate  # 干净库实际迁移
```

產出：

- `products` 1337+ 個（id 與老站 `shd_products.id` 一致）
- 使用者 1279+（id 與 `shd_clients.id` 一致）
- 訂單 / 賬單 / 工單 / 服務等關聯資料

關鍵相容性處理：

- **密碼**：`###md5` 字首 + 32 位 MD5，由 `ZjmfLegacyPasswordVerifier` 驗證後升級 bcrypt。
- **pricing（重要）**：目標 `products.pricing` 是**扁平格式** `{"monthly":"25.00"}`（當前模型與結算鏈路均按扁平解析）。切勿使用舊的巢狀格式 `{"monthly":{"price":25}}`，否則價格為 0、報價報"無效計費週期"。
- **invoice_no 唯一性**：源資料約 42% 為無效值（`'NULL'` / `'INV-0'` 等），遷移器自動生成 `INV-{id:08d}`。
- **operation_logs 主鍵**：不遷移源 id，避免與應用日誌主鍵衝突。
- **remark 截斷**：全部 `remark` 對映截斷到 255 字元。
- **外部索引鍵依賴**：主流程結束後自動把二級組複製到 `third_product_groups`，滿足 `products.product_group_id` 外部索引鍵。

---

## 6. Step 4：配置項遷移（config_options）與 OS version 修復

### 1. 組裝 config_options

```bash
python3 backend/scripts/mofang_config_options_migrator.py --dump 25y_*.sql --dry-run
python3 backend/scripts/mofang_config_options_migrator.py --dump 25y_*.sql --output /tmp/config_options.sql
```

來源表：`shd_product_config_links`（按 `link.pid` 關聯產品）+ `shd_product_config_options` + `shd_product_config_options_sub` + `shd_pricing`。

**格式硬性要求（否則報價介面 500）**：

- `config_options` 必須是**配置項陣列**，每項含 `field / name / option_type / hidden / sub / qty_minimum / qty_maximum / parameter / unit`。
- **嚴禁**把對接後設資料（`mofang_host` / `zjmf_api_id` / `upstream_pid`）放進 `config_options`（`parseField(array)` 會型別錯誤）。對接欄位放 `product_upstream_bindings`。
- 配置項型別對映（與後端 `TYPE_FIELD_MAP` 一致）：`6/7/16→cpu`、`8/9/17→memory`、`12→area`、`5→os`、`13/14/19→system_disk_size`、`10/11/18→bw`、`4→ip_num`。

### 2. OS 配置分組修復（使用者可見的"系統選擇"）

前端 `buildOsGroups` 按 `sub[].version` 的 `大类^版本` 格式分組。dump 中 os 的 sub 常為混亂格式（`12|CentOS^CentOS-7.6.1810-x64`、`Windows^Windows^Windows7`、`Windows2022|Windows`），必須修復：

```bash
php artisan app:fix-os-versions --dry-run
php artisan app:fix-os-versions
```

期望結果：`os_items=439、missing_version=0`；分組 CentOS:8、Ubuntu:5、Debian:3、Windows:5、Rocky:2、Tencentos:5。

> **引用陷阱**：`foreach (($o["sub"] ?? []) as &$s)` 中 `??` 產生副本、引用修改丟失（affected=0）。必須先 `isset($o['sub'])` 再 `&$s` 迴圈。

### 3. 跨庫補齊（可選）

若活躍庫 `with_config` 少於備份庫（如 477 vs 1251）：

```bash
php artisan app:backfill-config-options --dry-run
php artisan app:backfill-config-options
```

---

## 7. Step 5：service_type_code 派生

在 `app:reorganize-product-groups` 中完成：

```sql
UPDATE products p
JOIN third_product_groups t ON t.id = p.product_group_id
JOIN second_product_groups s ON s.id = t.second_product_group_id
JOIN first_product_groups f ON f.id = s.first_product_group_id
SET p.service_type_code = f.code
WHERE p.service_type_code IS NULL OR p.service_type_code = '';
```

遷移器 `FieldMap(src="server_type")` 會失敗（dump 無 `server_type` 列），必須由分組命令回填。

---

## 8. Step 6：上游同步（suppliers + 繫結 + 金鑰解密）

```bash
php artisan app:sync-upstreams --dry-run
php artisan app:sync-upstreams --api-keys-file /tmp/keys.json   # 推荐提供预解密明文密钥
```

1. **解析上游主資料**：舊庫 `shd_zjmf_finance_api`（常見 19 個上游）。落表 `suppliers`（`code = zjmf_api_<api_id>`）+ `supplier_plugin_bindings`（base_url / account_name + 加密 `secret_json`）。
2. **安裝啟用外掛**：命令自動 `PluginInstaller` 安裝並啟用 `upstream/zjmf_finance`（provider_key 解析依賴）。
3. **產品級繫結**：對映在舊庫 `shd_products` 的 `zjmf_api_id` / `upstream_pid` 欄位（產品 id 與老站一致），寫入 `product_upstream_bindings`。
4. **API 金鑰解密**（認證必做，否則全部"介面認證失敗"）：
   - 演算法：`DES-CBC`，key = `md5("shundai")` 前 8 位元組 = `3491f0a7`，IV 同值，PKCS#7 填充 + base64。
   - **PHP 8.3 / OpenSSL 3 已移除單 DES**。命令自動呼叫 openssl CLI（`-provider legacy -provider default`）解密；無 CLI 時用 `--api-keys-file` 傳入預解密 JSON（`{"api_id":"明文"}`）。
   - 手動參考：`printf %s 密文 | openssl enc -d -des-cbc -provider legacy -provider default -K 3334393166306137 -iv 3334393166306137`
5. **驗證**：`curl -X POST {base_url}/zjmf_api_login -d "username=..&password=明文"` 返回 jwt + "鑑權成功"。

> **坑位**：`shd_products` 的 dump INSERT 是 **107 欄位**（v10 新增列）與 CREATE TABLE 83 列**順序不同**，`zjmf_api_id`/`upstream_pid` 實際索引為 **[74]/[75]**（不要用 CREATE 列序 50/51）。從舊庫**表**讀取時無此問題。

---

## 9. Step 7：實名認證遷移

老站實名在 `shd_certifi_person`（約 584 條）與 `shd_certifi_company`（約 15 條）；`shd_certification` 空表忽略。

```bash
php artisan app:migrate-real-name --dry-run
php artisan app:migrate-real-name
```

狀態對映（老 → 新）：

| 老 status | 含義      | 新 `is_verified` | 新 `verification_status` |
| --------- | --------- | ---------------- | ------------------------ |
| 1         | 通過      | 1                | 2（已認證）              |
| 2         | 待審      | 0                | 1（待認證）              |
| 3/4       | 駁回/失敗 | 0                | 3（認證失敗）            |

處理規則：

- `auth_user_id` 與新系統 `users.id` 一致（老站客戶端 id）。
- 同一 uid 在 person/company 重疊（約 6 個）：**取 `update_time` 最新**寫 `users`，其餘寫 `verification_histories`。
- `id_card` 經 `LegacyEncrypted` cast 自動加密儲存。

---

## 10. Step 8：老使用者密碼策略

- 老站密碼 `###` + 32 位 MD5 原樣保留，由 `ZjmfLegacyPasswordVerifier` 驗證後升級 bcrypt。
- 若密碼遷移未做 / 失敗：刪除該邏輯，強制老使用者走"忘記密碼"流程重置（避免遺留安全隱患）。

---

## 11. Step 9：快取預熱與驗證

```bash
cd backend
php artisan optimize:clear
php artisan app:warmup-site-cache   # 改动分组 / 商品后必跑
```

驗證清單：

- `GET https://api.25y.cn/api/v2/site/home` → 200
- 產品詳情 API 的 `config_options`：OS 項 `sub[].version` 為 `大类^版本`
- 報價介面：`POST /api/v2/site/products/{id}/quote` → 返回 `total_amount`，不 500
- 官網產品列表"系統選擇"按大類分組顯示
- 供應商管理"測試連線"全部通過（jwt 鑑權成功）
- 後臺使用者列表可見 `real_name` / 實名狀態

---

## 12. Step 10：服務例項上游繫結回填

**現象**：已售出開通的服務在控制台顯示"未接入完整控制能力，只讀展示基礎資訊"。

**根因**：`service_upstream_bindings` 為空 → `upstream.host_id=0` → 前端 `canManageConsole` 不通過。

```bash
php artisan services:backfill-upstream-bindings --dry-run
php artisan services:backfill-upstream-bindings --service-ids 1,2,3   # 可定向
```

匹配邏輯：

1. **notes ID 優先**：從舊庫 `shd_host.notes` 的 `ID：xxx` 提取（全形/半形冒號都相容，用 `mb_strpos` + 子串正則）。
2. **上游 `/host/list` 兜底**：對無 notes 的服務，按供應商分頁拉取全部主機（`page` + `limit=100`，注意 `limit` 生效、`pageSize/pagesize` 無效），按 `domain`（主機名）精確匹配。
3. 寫入 `service_upstream_bindings`：`service_id`、`product_upstream_binding_id`、`supplier_plugin_binding_id`、`plugin_id`、`provider_key`、`upstream_service_id`。

參考結果：寫入 1564 條繫結、0 失敗；Active 309/388、Suspended 226/244 已接入控制。

> **供應商 base_url 坑**：`http://` 會被生產環境 HTTPS 校驗拒絕（`assertTrustedBaseUrl`），先確認上游支援 HTTPS 再改。
> **邊界**：上游 `/host/list` 中不存在的主機（上游已刪除/歷史資料）與自營產品（無 `product_upstream_bindings`）無法繫結，屬正常。

驗證：`GET /api/v2/client/services/{id}` 返回 `upstream.host_id > 0`、`actions.power=true`、`actions.module_status=true`。

---

## 13. 生產部署必踩的坑

1. **兩庫差異**：`tura_25y`（活躍）vs `turaidc`（備份）。修復指令碼要在活躍庫上重跑，別切庫。
2. **`.user.ini`**：寶塔會在 `dist/` 和 `backend/public/` 生成帶 immutable 屬性的 `.user.ini`，前端重建前 `chattr -i` 刪除，否則 vite 報 `ENOTDIR`。
3. **`config_options` 必須是陣列**，否則報價介面 500。
4. **OS version 格式** `大类^版本`，前端 `buildOsGroups` 依賴。
5. **INSERT 107 欄位 ≠ CREATE 83 列**：`zjmf_api_id` / `upstream_pid` 在 [74]/[75]。
6. **PHP 8.3 無單 DES**：DES-CBC 用 openssl legacy provider 或 Python pycryptodome。
7. **`??` 副本引用陷阱**：foreach 引用修改前先 `isset`。
8. **Nginx `error_page 404`**：api 站點必須註釋，否則 Laravel JSON 404 被替換成 HTML。
9. **`perPage` 簽名**：`ListTicketsRequest` / `ListLedgerRequest` / `ListNotificationsRequest` 子類必須與 `ClientFormRequest` 基類簽名一致，否則 PHP Fatal（列表介面 500）。
10. **前端 `.env.production`**：遷移後易丟，官網 / 控制台 / 後臺三個都要建（`VITE_API_BASE_URL=https://api.25y.cn/api`，Cookie 域 `.25y.cn`）。
11. **服務控制台只讀**：`service_upstream_bindings` 必須回填（見 Step 10）。
12. **上游 base_url 強制 HTTPS**：`http://` 被拒。
13. **`/host/list` 分頁引數是 `limit`**：`pageSize` / `pagesize` 無效。
14. **notes ID 提取**：全形冒號正則可能失效，用 `mb_strpos("ID：")` + 子串正則。

---

## 14. 遷移後資料校驗

### 行數對賬

參考指令碼（源資料不同則調整期望值）：

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

### 關鍵欄位抽樣

- `users.password` 應為 `###md5` 字首格式。
- `products.pricing` 應為扁平 JSON（`{"monthly":"25.00"}`）。
- `products.config_options` 每項是陣列，OS 項 `sub[].version` 為 `大类^版本`。
- `products.service_type_code` 全部回填（`vps/dedicated/hosting/domain/other` 之一）。
- `invoices.invoice_no` 全部唯一。

---

## 15. 常見故障排查

| 現象                                | 原因與處理                                                                      |
| ----------------------------------- | ------------------------------------------------------------------------------- |
| `TRUNCATE` 超時 / 後設資料鎖          | `SHOW PROCESSLIST` 查詢 `Waiting for table metadata lock` 會話並 `KILL`，再重試 |
| `Data too long for column 'remark'` | 在對應 `FieldMap` 新增 `[:255]` 截斷                                            |
| 報價介面 500 `parseField(array)`    | `config_options` 裡混入了對接後設資料物件，必須改為配置項陣列並重跑 Step 4        |
| 商品列表為空 / 分組亂               | 一級組 code 錯亂，重跑 `app:reorganize-product-groups`                          |
| 上游全部"介面認證失敗"              | API 金鑰未解密，重跑 `app:sync-upstreams --api-keys-file`                       |
| 服務控制台只讀                      | 未回填 `service_upstream_bindings`，執行 Step 10                                |
| 前端首頁標題 / favicon 不變         | 品牌配置未預熱，`optimize:clear` + `app:warmup-site-cache`                      |

---

## 關聯文件

- [本地 IDC 資料遷移流程](local-idc.md)
- [寶塔部署專案指南](../deploy/bt-panel.md)
- [Docker 與 1Panel 部署指南](../deploy/docker-1panel.md)
- [部署與排程指南](../deploy/scheduling.md)
- [ZJMF 統一遷移方案](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/execution-plans/completed/zjmf-unified-migration.md)
