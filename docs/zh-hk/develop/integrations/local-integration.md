---
source: develop/integrations/local-integration.md
sourceHash: bb8d06afeaba7e733a4d03cbe0e43e85e65dad968cfa352a811a140b530be252
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/integrations/local-integration.md 產生，請勿手動編輯。 -->
# 本地對接説明

## 文檔用途

- 只寫**項目實際對接**的第三方上游，不寫官方參考文檔裡的大而全列表
- 對齊時間：`2026-07-02`
- 配套參考：
  - 歷史官方 ZJMF 財務 API 參考不再隨倉庫保存；需要時從受控上游來源獲取
  - `docs/develop/architecture.md` - 架構位置與入口

## 1. 上游供應商

當前實庫 `suppliers` 表中已啟用的上游：

| 供應商 | `code`       | `interface_type`    | 對接協議         |
| ------ | ------------ | ------------------- | ---------------- |
| 極點雲 | `supplier_1` | `hosting_panel_api` | 主機面板 OpenAPI |
| 美得雲 | `supplier_2` | `hosting_panel_api` | 主機面板 OpenAPI |

説明：

- 兩家已啟用上游底層是同一套主機面板 API 協議，本地走 provider 驅動體系適配；`hosting_panel_api` 使用 `HostingPanelApiDriver`
- ZJMF 財務上游統一通過 `backend/plugins/servers/zjmf_finance/` 插件承載；`zjmf_finance_api` 使用插件內 `Lib\ZjmfFinanceDriver` + `Lib\ZjmfFinanceAdapter`
- `zjmf_finance_api` 是獨立 provider key，禁止在解析、開通、同步或服務綁定時歸一化為 `hosting_panel_api`
- 新增同類上游只需在後台添加 `suppliers` 記錄並維護 API 地址、賬號、密鑰

### 1.1 中間層邊界

| 層                  | 職責                                                                | ZJMF落點                                                                                                                         |
| ------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Provider 解析       | 按服務綁定、商品綁定、供應商配置解析真實 provider key               | `ProviderResolver`、`ProviderRegistry`                                                                                           |
| Plugin              | 聲明 `domain=upstream`、`slug=zjmf_finance`、`key=zjmf_finance_api` | `backend/plugins/servers/zjmf_finance/config.php`、`ZjmfFinancePlugin.php`                                                       |
| Driver              | 暴露供應商 key、能力清單和能力對象                                  | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                                                 |
| Adapter             | 顯式聲明平台業務 Service 可調用的ZJMF能力方法                       | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                                                |
| Plugin Services     | 登錄、商品、開通、續費、狀態、控制台、網絡、安全組等協議動作        | `backend/plugins/servers/zjmf_finance/lib/Zjmf*Service.php`                                                                      |
| Plugin Transport    | ZJMF 財務請求入口、JWT 解析、401 失效處理和文本/JSON 請求封裝       | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`、`ZjmfAuthManager.php`                                       |
| Shared Transport    | 僅作為底層 HTTP、DNS/TLS 安全、超時、日誌脫敏等共享傳輸輔助         | `HostingPanelApiTransport.php`                                                                                                   |
| Legacy Zjmf Support | 舊密碼兼容、賬單恢復等非數據面兼容能力                              | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`、`ZjmfBillingRestoreService.php`，由插件 Provider 註冊 |

擴展規則：

- 業務 Service 不直接拼ZJMF請求，也不直接讀取官方字段差異，新增差異先落到插件 service/adapter。
- 能複用主機面板底層傳輸能力時只能經由 `ZjmfFinanceTransport` 包裝；provider key 和能力對象必須保持ZJMF獨立身份。
- ZJMF 雲商品配置項模板優先在插件內 `ZjmfCloudConfigTemplate` 擴展，通用 fallback 才放到 `Services/Upstream/Support/CloudConfigTemplate`。
- 舊ZJMF密碼兼容走 `LegacyPasswordVerifier` 聚合器，具體格式判斷放在 `ZjmfLegacyPasswordVerifier`。
- 新增 provider 或能力時同時補最小測試：provider 解析、adapter 顯式方法、關鍵字段映射、失敗降級。
- 不允許新增或依賴 `ZjmfFinanceAdapter::__call()` 動態轉發；新增能力應先擴展插件內部 service，再在 adapter 上補明確方法。

## 2. 入口與關鍵文件

| 職責                     | 入口                                                                                               |
| ------------------------ | -------------------------------------------------------------------------------------------------- |
| 上游解析入口             | `backend/app/Services/Upstream/ProviderResolver.php`                                               |
| 驅動註冊表               | `backend/app/Services/Upstream/ProviderRegistry.php`                                               |
| 主機面板驅動             | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiDriver.php`                  |
| 主機面板傳輸層           | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiTransport.php`               |
| ZJMF 財務插件            | `backend/plugins/servers/zjmf_finance/`                                                            |
| ZJMF 財務驅動            | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                   |
| ZJMF 財務適配器          | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                  |
| ZJMF 財務傳輸入口        | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`                                |
| ZJMF 財務登錄與 JWT 管理 | `backend/plugins/servers/zjmf_finance/lib/ZjmfAuthManager.php`                                     |
| ZJMF 雲配置項模板        | `backend/plugins/servers/zjmf_finance/lib/ZjmfCloudConfigTemplate.php`                             |
| ZJMF商品類型映射         | `backend/plugins/servers/zjmf_finance/lib/ZjmfProductTypeMapper.php`                               |
| 舊ZJMF密碼兼容           | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`                          |
| 舊密碼兼容聚合器         | `backend/app/Services/Auth/LegacyPasswordVerifier.php`                                             |
| 上游協議輔助             | `backend/app/Services/Upstream/Drivers/HostingPanelApi/Concerns/`                                  |
| 商品配置項歸一           | `backend/plugins/servers/zjmf_finance/lib/ZjmfCatalogService.php`、`ZjmfCloudConfigTemplate.php`   |
| ZJMF賬單恢復工具         | `backend/plugins/servers/zjmf_finance/lib/ZjmfBillingRestoreService.php`                           |
| 支付寶 F2F 插件          | `backend/plugins/gateways/ali_pay/`                                                                |
| 支付寶 F2F 客戶端        | `backend/plugins/gateways/ali_pay/lib/AlipayClient.php`                                            |
| 支付寶 F2F 能力服務      | `backend/plugins/gateways/ali_pay/lib/AlipayService.php`                                           |
| 支付寶回調               | `backend/app/Http/Controllers/Client/PaymentCallbackController.php`                                |
| 實名回調                 | `backend/app/Http/Controllers/Client/VerificationController@callback`，走 `verify.callback` 中間件 |
| 回調簽名中間件           | `backend/app/Http/Middleware/VerifyCallbackSignature.php`                                          |

## 3. 對接的上游能力清單

只列項目真實調用的，不代表官方全量：

| 能力                           | 方法         | 路徑                                                          | 本地調用入口                                                                      |
| ------------------------------ | ------------ | ------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| ZJMF登錄並換 JWT               | `POST`       | `/zjmf_api_login`                                             | `ZjmfAuthManager::login()` / `ZjmfFinanceTransport::login()`                      |
| ZJMF獲取用戶資料               | `GET`        | `/v1/user`                                                    | `ZjmfFinanceTransport::getUserProfile()`                                          |
| ZJMF獲取賬戶餘額               | `GET`        | `/cart/credit`                                                | `ZjmfFinanceTransport::getBalance()`                                              |
| ZJMF商品目錄                   | `GET`        | `/cart/all`                                                   | `ZjmfCatalogService::getProductCatalog()`                                         |
| ZJMF商品配置與庫存             | `GET`        | `/cart/get_product_config?pid={productId}`                    | `ZjmfCatalogService`                                                              |
| ZJMF同系統主機詳情             | `GET`        | `/host/header?host_id={hostId}&source=API`                    | `ZjmfFinanceTransport::getHostDetail()`；將 `data.host_data` 歸一為 `data.host`。 |
| ZJMF開通                       | 多個官方接口 | 購物車、結算、上游支付、host 回查                             | `ZjmfProvisionService::provisionOrder()`                                          |
| ZJMF續費                       | `POST`       | `/host/renew`；`/pay?action=billing&pay=true`；`/check_order` | `ZjmfRenewService`；自動續費僅由本站賬單與調度執行，不同步上游開關。              |
| ZJMF狀態同步                   | `GET`        | `/host/header?host_id={hostId}&source=API`，以及運行狀態接口  | `ZjmfStatusService`                                                               |
| ZJMF VNC、電源、重裝、重置密碼 | 多個官方接口 | host action / console 接口                                    | `ZjmfConsoleService`                                                              |
| ZJMF流量包、升降級             | 多個官方接口 | upgrade / flowpacket 接口                                     | `ZjmfNetworkService`                                                              |
| ZJMF NAT、安全組、自定義模塊   | `GET/POST`   | servicedetail 自定義模塊頁面和提交接口                        | `ZjmfSecurityService`、`ZjmfConsoleService`                                       |

插件 service 內部通過 `ZjmfFinanceTransport::get/post/put/delete/parallelGet` 調用上游。平台業務 Service 只調用 capability 暴露的高層方法，不直接拼ZJMF請求路徑、請求參數或官方響應字段。

## 4. 簽名與認證約束

- ZJMF JWT 按 `supplier_id + provider_key` 隔離快取，`provider_key` 必須保持 `zjmf_finance_api`
- ZJMF插件調用前由 `ZjmfAuthManager` 自動 `login`，失敗或 401 會清理快取並觸發刷新/重登
- 普通 ZJMF 數據面在獲取 JWT 後使用 `Authorization: Bearer <token>`；同系統自定義模塊頁面和動作使用 `Authorization: JWT <token>`，其中頁面請求還必須傳入 `id`、`key` 和 `jwt` 查詢參數；`/zjmf_api_login` 與官方參考的 `/v1/login_api` 不能混用
- 上游請求失敗由插件傳輸入口和共享底層傳輸記錄脫敏日誌，日誌必須能區分 `zjmf_finance_api` 與 `hosting_panel_api`
- 上游回調（供應商主動推我們）當前**未**暴露標準 webhook；業務主要靠輪詢

## 5. 支付對接：支付寶 F2F

### 5.1 能力

| 能力                 | 阿里方法名               | 本地入口                                       |
| -------------------- | ------------------------ | ---------------------------------------------- |
| 預下單生成付款二維碼 | `alipay.trade.precreate` | `AlipayService` action `payment.precreate`     |
| 主動查詢訂單狀態     | `alipay.trade.query`     | `AlipayService` action `payment.query`         |
| 交易退款             | `alipay.trade.refund`    | `AlipayService` action `payment.refund`        |
| 驗證異步通知簽名     | -                        | `AlipayService` action `payment.verify_notify` |

### 5.2 配置入口

通過管理端"通知配置/支付配置"設置以下 `settings` 項：

- `alipay_app_id`
- `alipay_private_key`
- 其餘支付寶相關 `alipay_*` 項

### 5.3 回調路徑

- 支付寶異步通知：`POST /api/v2/client/payment/alipay/notify`
- 本地回調 URL 約定：`{APP_URL}/api/v2/client/payment/alipay/notify`
- 回調裡通過支付插件運行時執行 `payment.verify_notify` 做簽名校驗，`PaymentService` 做冪等 + 狀態落庫 + 後續開通/續費派發

## 6. 實名認證對接：支付寶實名

### 6.1 客戶端接口（面向用戶端 SPA）

- `GET /api/v2/client/verification/fee-config` - 獲取實名費用配置
- `POST /api/v2/client/verification/init` - 初始化實名（拿 `certify_id`）
- `POST /api/v2/client/verification/qrcode` - 獲取掃碼二維碼
- `GET /api/v2/client/verification/status` - 查詢當前狀態
- `GET /api/v2/client/verification/scan` - 掃碼跳轉中轉

### 6.2 實名異步回調

- 路徑：`GET|POST /api/v2/client/verification/callback`
- 中間件：`verify.callback`（對應 `VerifyCallbackSignature`）
- 控制器：`Client\V2\VerificationController::callback`
- 成功後會更新 `users.verification_*`、`user_accounts` 對應字段，並落 `verification_histories`

### 6.3 管理端可見性

- `GET /api/v2/admin/verifications` - 實名列表
- `GET /api/v2/admin/verifications/{user}/history` - 歷史
- `POST /api/v2/admin/verifications/{user}/unbindings` - 後台解綁（按權限）

## 7. 與官方文檔的映射

| 官方文檔                      | 對應本地文件                                                                                                        |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| 受控上游的 ZJMF 財務 API 參考 | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php` + `Zjmf*Service.php` + `ZjmfFinanceTransport.php` |

建議閲讀順序：

1. 本文（項目對接範圍）
2. 對應的官方參考文檔（字段語義）
3. 對應的本地 Service 實現

## 8. 新增對接時的基線清單

新增上游或支付渠道時，至少滿足：

- 在 `suppliers` 表建立記錄（或接入相應配置表）
- 新增或複用 `Service` 客戶端（不要直接在 Controller 裡 `Http::post`）
- 入參、出參、異常落日誌（`trace_id` + `supplier_id` + 調用上下文）
- JWT / Token 做快取 + 過期回退機制
- 調用失敗有可觀測性（管理端日誌頁能查）
- 回調必須過 `verify.callback` 或等價簽名中間件
- 回調處理必須冪等
- 回調路徑、公鑰/密鑰、商戶號等敏感配置走 `settings` 或 `.env`，**不要硬編碼**

## 9. 尚未接入的官方能力

以下在官方文檔裡存在，但當前項目**未**主動調用：

- 通用 webhook 推送接收（項目主要依賴輪詢）
- 訂單維度回調（訂單狀態靠查詢 + 內部狀態機自愈）
- 產品庫一鍵全量同步（當前只做 `batch-sync` 與按需拉取）

需要接入時，先在本目錄追加章節，再同步 `Upstream` 驅動與傳輸層。
