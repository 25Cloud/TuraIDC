---
status: current
updated: 2026-09-13
owner: backend-platform
source: develop/testing.md
sourceHash: e99cc960d8c33d3edb2a6030d2449499bcf29328ed1b9f59b1caefab9ccc837d
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/testing.md 產生，請勿手動編輯。 -->
# 測試指南

本文是現行測試指南，面向測試、後端、前端、聯調、交付與運維，覆蓋 `backend`、三個前端、`shared` 及其聯調、迴歸與釋出前驗收。

## 1. 文件目標

本文用於定義當前 `turaidc` 專案的全量測試範圍、測試環境、執行順序、測試項清單、自動化命令、人工驗證重點、缺陷分級和交付產出。

發生衝突時按以下順序處理：

1. 當前程式碼真實行為
2. `AGENTS.md`
3. `docs/develop/architecture.md`
4. 本文件
5. 歷史習慣

## 2. 測試總原則

- 測試目標不是隻驗證“能不能開啟”，而是驗證關鍵業務鏈路、許可權邊界、支付/賬單/服務/工單等高風險域在當前版本下是否穩定。
- 自動化優先覆蓋可重複、可判定、迴歸頻率高的場景；人工重點覆蓋視覺、互動、真實業務串聯、第三方依賴和異常提示質量。
- 後端、前端、聯調、釋出驗收按依賴順序執行，不跳步。
- 每次測試必須明確記錄測試基線：分支、提交雜湊、環境、資料庫來源、配置來源、測試時間。
- 對高風險改動必須做定向迴歸，不允許只跑通用構建後直接上線。

## 3. 測試分層

### 3.1 冒煙測試

- 目標：快速確認系統基本可用，適合開發自測、提測前、釋出後首檢。
- 時長目標：15 到 30 分鐘。
- 重點：
  - 後端可啟動
  - 雙前端可構建
  - 管理員可登入
  - 使用者可登入
  - 站點首頁、商品頁、使用者中心、管理端首頁可訪問
  - 核心介面不返回 500

### 3.2 迴歸測試

- 目標：確認改動沒有破壞既有關鍵鏈路。
- 時長目標：0.5 到 1 個工作日。
- 重點：
  - 被改業務域全鏈路
  - 被改業務域上下游影響面
  - 許可權、日誌、通知、快取、排程等伴隨能力

### 3.3 全量測試

- 目標：版本釋出前做完整業務覆蓋和跨端聯調確認。
- 時長目標：1 到 2 個工作日。
- 重點：
  - 全業務模組可用性
  - 關鍵跨域流程閉環
  - 多角色、多狀態、多異常路徑
  - 釋出前環境與生產配置相容性

### 3.4 釋出驗收

- 目標：確認構建產物、配置、排程、資料庫和上線後入口正常。
- 重點：
  - 構建產物完整
  - 配置項齊全
  - 排程和佇列行為正常
  - 登入、下單、支付回撥、服務開通等首要鏈路線上可用

## 4. 測試環境矩陣

### 4.1 本地開發環境

- 後端：PHP 8.3、Laravel 12、MySQL 8、Redis
- 官網與購買入口：埠以 `frontend-user-v3-www` 的 Vite 配置為準
- 管理端：埠以 `frontend-admin-v3` 的 Vite 配置為準
- 使用者控制台：埠以 `frontend-user-v4-console` 的 Vite 配置為準
- 後端入口：`http://127.0.0.1:8000`
- 啟動命令：

```bash
cd backend
php artisan app:serve

cd frontend-user-v3-www
pnpm run dev

cd frontend-admin-v3
pnpm run dev

cd frontend-user-v4-console
pnpm run dev
```

### 4.2 測試庫要求

- 推薦使用獨立測試資料庫，不直接複用生產快照。
- 若需驗證全新安裝鏈路，應使用空庫執行：

```bash
python backend/scripts/install_db.py --reset
```

- 若需驗證當前 schema 基線可匯出，更新資料庫結構後應同步執行：

```bash
php backend/scripts/export_database_structure.php
```

### 4.3 必要配置項

- `backend/.env` 中資料庫、Redis、APP_URL、FRONTEND_URL、ADMIN_URL 正確。
- 第三方依賴如簡訊、郵件、上游面板、支付寶未準備好時，要明確標註“已模擬”“未驗證”或“僅驗證本地降級行為”。

## 5. 測試前準備

### 5.1 程式碼準備

- 拉取目標分支程式碼。
- 確認 Composer 和 pnpm 依賴已安裝。
- 記錄當前提交雜湊：

```bash
git rev-parse HEAD
```

### 5.2 資料準備

- 全新安裝驗證：空庫 + `backend/scripts/install_db.py --reset`（Windows 可用）或 `backend/scripts/install_db.sh`（Shell 環境）
- 普通迴歸驗證：使用最新測試基線庫
- 需要支付、賬單、服務、返佣、工單、內容等場景時，準備對應測試資料

### 5.3 賬號準備

- **管理員**：使用者名稱固定為 `cerbo`，由 `backend/scripts/install_db.py`（Shell 環境用 `install_db.sh`）在空庫初始化時建立。初始密碼來自 `INSTALL_ADMIN_PASSWORD`，未配置時指令碼隨機生成並只打印一次；本地測試庫的密碼以本機環境為準。
- **普通使用者**：按測試目標自行註冊或準備測試資料。

全新安裝態只保證管理員賬號存在；商品、供應商、內容等需按測試目標補資料。測試賬號的密碼通過受控渠道取得，**文件與提交裡只寫使用者名稱與用途，不寫密碼**。

## 6. 自動化測試入口

### 6.1 後端自動化

全量後端測試：

```bash
cd backend
php artisan test
```

檢視測試清單：

```bash
cd backend
php artisan test --list-tests
```

**Windows 本機開發提速**（2026-09 實測）：`php artisan test` 在 Windows 下經 Symfony Process 包裝轉發輸出，單個最小測試類可卡到分鐘級；`vendor/bin/phpunit` 直跑同一檔案僅約 0.6 秒（同一份 `phpunit.xml.dist`，`APP_ENV=testing` 與測試庫配置一致生效）。本機改動後的增量回歸建議：

```bash
cd backend
# 单文件（最快，不扫描其余测试）
php vendor/bin/phpunit tests/Feature/目标测试.php
# 少量相关类（--filter 仍会扫描全部测试文件，文件多时明显慢于传路径）
php vendor/bin/phpunit --filter="测试类A|测试类B"
# 全量回归可加 opcache 降低框架编译开销（phpunit.xml 的 <ini> 对 opcache 无效，必须启动时 -d）
php -d opcache.enable_cli=1 vendor/bin/phpunit
```

測試依賴：`idc_test` 測試庫（基線 `database/schema/mysql-schema.sql` 匯入後補跑 `php artisan migrate --env=testing --force`）；本機無 Redis 時 `phpunit.xml.dist` 已把 `redis_volatile` store 切為 `array`（`CACHE_VOLATILE_DRIVER`），無需真實 Redis。

當前後端測試組成：

- `tests/Feature/`：大量業務迴歸測試，覆蓋登入、使用者、商品、站點、支付、賬單、工單、返佣、排程、服務控制台、VNC、安全、黑洞查詢等
- `tests/Unit/`：規則、編號、快照、驅動、風控等單元測試

### 6.2 管理端自動化

當前倉庫無完整單測框架，但有最小指令碼驗證與構建驗證：

```bash
cd frontend-admin-v3
pnpm run build
pnpm run test
```

### 6.3 使用者端自動化

```bash
cd frontend-user-v3-www
pnpm run build
pnpm run test:e2e
```

`test:e2e` 是 Playwright 套件（`tests/e2e/`），用例自行 `page.route` 攔截站點只讀介面，
不依賴後端與資料庫；`playwright.config.ts` 會自行拉起 5176 埠的 Vite。
覆蓋頂欄下拉的展開條件、取數佔位、失敗重試與鍵盤可達性。

結構性重構或大改頁面時追加：

```bash
cd frontend-user-v3-www
pnpm run verify:refactor
```

## 7. 建議執行順序

### 7.1 開發自測順序

1. 後端定向測試
2. 前端對應端構建
3. 本地聯調冒煙
4. 被改業務域人工迴歸

### 7.2 提測前順序

1. `backend` 全量測試
2. `frontend-admin-v3` 構建
3. `frontend-user-v3-www` 構建
4. `frontend-user-v4-console` 構建
5. 使用者端/管理端關鍵路徑聯調
6. 提交測試記錄

### 7.3 釋出前順序

1. 空庫安裝鏈路驗證
2. 後端全量測試
3. 雙前端構建
4. 全鏈路人工迴歸
5. 排程與非同步行為驗證
6. 釋出驗收單簽字

## 8. 全量測試範圍

### 8.1 安裝與基礎可用性

- 空庫執行 `backend/scripts/install_db.py --reset` 成功
- `mysql-schema.sql` 可被正確載入
- 預設管理員可登入
- 後端 `app:serve` 可啟動
- 使用者端、管理端 `pnpm run build` 成功
- 站點、使用者中心、管理後臺首頁均可訪問

### 8.2 認證與許可權

- 管理員登入、退出、Token 有效期、空閒超時
- 使用者登入、註冊、找回密碼、資料更新
- 管理端 `login as` 跳轉與交換碼
- 許可權碼控制是否準確
- 停用賬號不可訪問
- 未登入、越權、過期 Token 返回碼符合 `API格式规范.md`

### 8.3 站點與公開產品

- 首頁配置讀取正常
- 公告/幫助列表與詳情可訪問
- 商品分類、列表、詳情、報價正常
- 商品卡月付價格、規格、文案、庫存展示正確

### 8.4 管理端商品與目錄

- 商品分類樹、篩選、分頁、排序正常
- 商品詳情載入無缺欄位錯誤
- 商品拖拽排序可儲存
- 批次移動商品正常
- 商品拆分、規格目錄、CPU 型號目錄可讀可寫
- 供應商商品批次關聯可用

### 8.5 使用者下單與賬單

- 商品報價成功
- 下單建立成功
- 數量、配置快照、指紋與 token 正確
- 餘額支付、零元支付、待支付狀態處理正確
- 賬單生成、賬單明細、賬單狀態流轉正常
- 支付記錄與賬單投影同步正確

### 8.6 支付與充值

- 支付會話生成成功
- 支付寶預下單失敗時提示符合預期
- 非同步回撥驗籤邏輯正常
- 充值狀態查詢可正確更新余額與流水
- 回撥冪等性驗證通過

### 8.7 服務生命週期

- 下單後服務建立或待開通狀態正確
- 自動續費開關生效
- 到期提醒、續費視窗、取消邏輯正確
- 流量包購買生成正確賬單
- 服務刪除保留賬務記錄

### 8.8 服務控制台

- 服務詳情、狀態、規格、供應商繫結正常
- VNC token 安全策略正確
- 重灌、開關機、重置密碼等操作許可權正確
- NAT、監控、安全組、黑洞工具頁讀取與操作正常
- 快取命中與強刷行為符合預期

### 8.9 使用者與實名

- 使用者建立、更新、手機號唯一性校驗正確
- 資料頁更新成功
- 實名狀態查詢、解綁、認證歷史展示正常
- 缺失舊錶時相容邏輯仍可執行

### 8.10 優惠券、返佣、會員

- 優惠券建立、更新、發放、領取、使用、回退正常
- 券分頁和彙總統計正確
- 推薦關係建立、返佣發放、釋放、回退正確
- 提現、返佣日誌、賬戶日誌展示正確
- 會員等級配置和關聯邏輯正常

### 8.11 工單與內容

- 工單建立、回覆、指派、關閉正常
- 圖片上傳、路徑安全校驗正確
- 公告/幫助分類和文章管理可讀可寫
- 內容釋出快取版本更新正常

### 8.12 設定、日誌與通知

- `settings` 表讀寫正常
- 操作日誌、系統日誌、簡訊日誌、郵件日誌查詢正常
- 簡訊日誌脫敏正確
- 郵件/通知日誌降級寫入正常

### 8.13 排程與非同步

- 排程任務概覽介面正常
- 可手動觸發的任務能執行
- 佇列積壓清理行為符合當前架構
- 認證重新整理、對賬、賬單補償、自動化任務執行路徑正常

### 8.14 資料庫工程與相容性

- 基礎 schema 匯入成功
- 當前遷移可在 schema 基線上執行到最新
- 核心索引存在
- 工程命令 dry-run 輸出可讀
- 舊錶缺失下相容邏輯不報錯

## 9. 後端測試清單

### 9.1 必跑

```bash
cd backend
php artisan test
```

### 9.2 高風險改動追加定向測試

- 登入/鑑權：`AuthLoginRegressionTest`、`SanctumIdleTimeoutTest`
- 商品/站點：`AdminCatalogRegressionTest`、`SiteProductReadServiceTest`、`SiteHomeControllerTest`
- 下單/支付/賬單：`OrderQuantityCheckoutFlowTest`、`OrderZeroAmountPaymentFlowTest`、`RechargeStatusBalanceRegressionTest`、`PaymentInvoiceProjectionSyncTest`
- 服務控制台/VNC：`ServiceVncTokenSecurityTest`、`ServiceConsoleSupplierBindingTest`
- 優惠券/返佣：`CouponLifecycleRegressionTest`、`ReferralRewardLifecycleRegressionTest`
- 工單/上傳：`TicketServiceRegressionTest`、`UploadSecurityTest`、`ZjmfFinanceTransportTest`
- 排程：`ScheduleTaskExecutionCoverageTest`、`ScheduleTaskOverviewTest`
- 安裝/資料庫：`DatabaseEngineeringCommandTest`、`DatabaseIndexOptimizationRegressionTest`

上傳安全與上游傳遞的定向驗證：

```bash
cd backend
php artisan test --filter=UploadSecurityTest
php artisan test --filter=ZjmfFinanceTransportTest
php artisan test --filter=ServiceProvisionDataTokenSanitizationTest
```

- `UploadSecurityTest` 覆蓋 `/upload_image` 預設關閉、憑證/白名單/限流與孤兒檔案清理。測試快取為 `array`（`phpunit.xml.dist`），限流計數不落 Redis；用例 `tearDown` 會清理固定測試 IP 的 `RateLimiter` 鍵，重複執行保持冪等。
- `V2AdminTicketActionApiTest` 覆蓋上傳防護配置讀寫、`/upload_image` 開啟前置校驗、白名單外上傳預設拒絕及規則建立/更新保護。
- `ZjmfFinanceTransportTest` 覆蓋附件上傳：複用共享 TLS 選項（`ssl_verify`/`ca_bundle`）、路徑逃逸拒絕、上游失敗拋業務異常。
- `ServiceProvisionDataTokenSanitizationTest` 覆蓋回撥 token 不進入 `services.provision_data`、僅保留在加密 secret 快照。

### 9.3 空庫初始化鏈路驗證

```bash
python backend/scripts/install_db.py --reset
```

驗證項：

- 資料庫表數量恢復
- `admin_users` 中存在 `cerbo`
- `POST /api/v2/admin/login` 返回 `code = 0`

### 9.4 後端基線記錄原則

測試數量隨功能和迴歸用例持續變化，不在本文維護固定 passed/skipped/assertions 數字。每次釋出或大改後應在當前任務、PR 或釋出記錄中記錄：

- 分支與提交雜湊。
- `php artisan test` 結果。
- 靜態分析 / 格式檢查結果。
- 跳過用例和原因。
- 本次新增失敗是否與改動相關。

## 10. 前端測試清單

### 10.1 管理端

必跑：

```bash
cd frontend-admin-v3
pnpm run build
pnpm run test
```

人工重點：

- 登入頁
- 首頁儀表盤
- 商品中心
- 使用者詳情
- 工單頁
- 設定頁
- 日誌頁

### 10.2 使用者端與官網

必跑：

```bash
cd frontend-user-v3-www
pnpm run build
pnpm run test:e2e
```

結構性改動追加：

```bash
cd frontend-user-v3-www
pnpm run verify:refactor
```

人工重點：

- 首頁
- 商品列表 / 詳情
- 登入 / 註冊 / 找回密碼
- 使用者中心首頁
- 賬單 / 訂單 / 服務 / 工單 / 返佣 / 充值
- 黑洞工具頁
- 服務控制台頁面

## 11. 聯調測試清單

### 11.1 基礎聯調

- 前端請求地址均走 `127.0.0.1`
- 雙端登入後 token 儲存正確
- 管理端和使用者端 API 均返回統一 JSON 外層
- 401 / 403 / 422 / 500 的錯誤提示可讀

### 11.2 跨端鏈路

- 管理端新增商品後，站點可見
- 管理端修改首頁配置後，官網可見
- 管理端切換使用者 `login as` 到使用者端成功
- 使用者下單後，管理端賬單/服務/日誌可見
- 使用者提交工單後，管理端可處理

### 11.3 排程聯動

- 下單支付後的後續動作在當前排程模型下可被消費
- 排程任務手動觸發結果可在前端看到狀態變化

## 12. 人工測試用例設計方法

每個業務域至少覆蓋以下維度：

- 正常路徑
- 引數缺失
- 非法狀態流轉
- 許可權不足
- 空資料
- 大數據分頁
- 併發或重複提交
- 非同步延遲
- 第三方失敗
- 歷史相容路徑

示例：

- 登入：正確密碼、錯誤密碼、停用賬號、過期 token、空閒超時
- 下單：正常購買、零元購買、餘額不足、未實名限制、未綁手機限制
- 優惠券：有效券、過期券、私有券、公有券、重複使用、取消支付回退
- 服務控制台：可管理、不可管理、供應商缺失、快取命中、強制重新整理、上游報錯脫敏

## 13. 缺陷分級標準

- `P0`
  - 無法啟動
  - 無法登入
  - 下單/支付/開通主鏈路中斷
  - 資料錯賬、許可權洩漏、安全問題
- `P1`
  - 主要功能不可用
  - 關鍵頁面空白或報錯
  - 高機率觸發的錯誤提示或狀態錯誤
- `P2`
  - 次要流程異常
  - 特定條件下展示錯誤
  - 構建告警、相容性問題
- `P3`
  - 文案、樣式、輕微互動問題
  - 不影響主流程的邊角問題

## 14. 測試記錄要求

每輪測試至少輸出以下內容：

- 測試時間
- 測試人
- 分支 / 提交雜湊
- 環境資訊
- 資料庫基線來源
- 已執行命令
- 已覆蓋模組
- 缺陷列表
- 未覆蓋項與原因
- 最終結論：通過 / 有條件通過 / 不通過

## 15. 釋出前驗收清單

- 後端 `php artisan test` 通過
- `frontend-admin-v3` 構建通過
- `frontend-user-v3-www` 構建通過
- `frontend-user-v4-console` 構建通過
- 若涉及重構，相關前端的 `verify:refactor` 通過
- 全新安裝指令碼驗證通過
- 預設管理員登入驗證通過
- 至少一條使用者下單鏈路驗證通過
- 至少一條工單鏈路驗證通過
- 至少一條排程任務手動觸發驗證通過
- 釋出配置與 `.env` 檢查完成
- 風險項已記錄並確認責任人

## 16. 當前已知限制

- `frontend-admin-v3` 當前沒有完整單元測試體系，主要依賴構建和少量指令碼驗證。
- `frontend-user-v3-www` 已接入 Playwright（`pnpm run test:e2e`），但目前只覆蓋頂欄下拉一條鏈路；商品購買、內容頁與其餘官網區塊仍靠構建、指令碼和人工聯調。`frontend-user-v4-console` 自動化測試覆蓋同樣偏輕。
- 第三方真實能力如簡訊、郵件、支付寶、上游服務商介面，若無隔離測試環境，只能驗證本地降級與模擬行為。
- 排程與非同步鏈路受當前 `schedule:run` 模式影響，驗證時需考慮 0 到 60 秒延遲視窗。

## 17. 推薦執行模板

### 17.1 提測前快速版

```bash
cd backend
php artisan test
composer analyse
composer format:check

cd ../frontend-admin-v3
pnpm run build
pnpm run test

cd ../frontend-user-v3-www
pnpm run build
pnpm run test:e2e
```

記錄模板：

- 提交雜湊：
- 測試環境：
- 資料庫基線：
- 後端結果：
- 管理端構建結果：
- 使用者端構建結果：
- 人工冒煙範圍：
- 風險與備註：

### 17.2 釋出前全量版

```bash
# 1. 空库安装验证
python backend/scripts/install_db.py --reset

# 2. 后端全量
cd backend
php artisan test
composer analyse
composer format:check

# 3. 管理端
cd ../frontend-admin-v3
pnpm run build
pnpm run test

# 4. 官网与购买入口
cd ../frontend-user-v3-www
pnpm run build
pnpm run test:e2e
pnpm run verify:refactor

# 5. 用户控制台
cd ../frontend-user-v4-console
pnpm run build
```

人工補充檢查建議：

- 管理員登入、使用者登入
- 商品後臺列表、詳情、分類樹
- 站點首頁、公開商品讀取、報價
- 下單、賬單、餘額支付、零元支付
- 服務控制台詳情、VNC、NAT、安全組
- 工單、返佣、排程任務
- 資料庫工程命令與關鍵 Job 注入解析

## 關聯文件

- [啟動指南](local-development.md)：本地環境與構建命令。
- [部署與排程指南](../guide/deploy/scheduling.md)：生產排程與佇列規則。
