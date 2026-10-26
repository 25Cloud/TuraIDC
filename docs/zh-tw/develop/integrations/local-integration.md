---
source: develop/integrations/local-integration.md
sourceHash: bb8d06afeaba7e733a4d03cbe0e43e85e65dad968cfa352a811a140b530be252
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/integrations/local-integration.md 產生，請勿手動編輯。 -->
# 本地對接說明

## 文件用途

- 只寫**專案實際對接**的第三方上游，不寫官方參考文件裡的大而全列表
- 對齊時間：`2026-07-02`
- 配套參考：
  - 歷史官方 ZJMF 財務 API 參考不再隨倉庫儲存；需要時從受控上游來源獲取
  - `docs/develop/architecture.md` - 架構位置與入口

## 1. 上游供應商

當前實庫 `suppliers` 表中已啟用的上游：

| 供應商 | `code`       | `interface_type`    | 對接協議         |
| ------ | ------------ | ------------------- | ---------------- |
| 極點雲 | `supplier_1` | `hosting_panel_api` | 主機面板 OpenAPI |
| 美得雲 | `supplier_2` | `hosting_panel_api` | 主機面板 OpenAPI |

說明：

- 兩家已啟用上游底層是同一套主機面板 API 協議，本地走 provider 驅動體系適配；`hosting_panel_api` 使用 `HostingPanelApiDriver`
- ZJMF 財務上游統一通過 `backend/plugins/servers/zjmf_finance/` 外掛承載；`zjmf_finance_api` 使用外掛內 `Lib\ZjmfFinanceDriver` + `Lib\ZjmfFinanceAdapter`
- `zjmf_finance_api` 是獨立 provider key，禁止在解析、開通、同步或服務繫結時歸一化為 `hosting_panel_api`
- 新增同類上游只需在後臺新增 `suppliers` 記錄並維護 API 地址、賬號、金鑰

### 1.1 中間層邊界

| 層                  | 職責                                                                | ZJMF落點                                                                                                                         |
| ------------------- | ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| Provider 解析       | 按服務繫結、商品繫結、供應商配置解析真實 provider key               | `ProviderResolver`、`ProviderRegistry`                                                                                           |
| Plugin              | 宣告 `domain=upstream`、`slug=zjmf_finance`、`key=zjmf_finance_api` | `backend/plugins/servers/zjmf_finance/config.php`、`ZjmfFinancePlugin.php`                                                       |
| Driver              | 暴露供應商 key、能力清單和能力物件                                  | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                                                 |
| Adapter             | 顯式宣告平臺業務 Service 可呼叫的ZJMF能力方法                       | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                                                |
| Plugin Services     | 登入、商品、開通、續費、狀態、控制台、網路、安全組等協議動作        | `backend/plugins/servers/zjmf_finance/lib/Zjmf*Service.php`                                                                      |
| Plugin Transport    | ZJMF 財務請求入口、JWT 解析、401 失效處理和文本/JSON 請求封裝       | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`、`ZjmfAuthManager.php`                                       |
| Shared Transport    | 僅作為底層 HTTP、DNS/TLS 安全、超時、日誌脫敏等共享傳輸輔助         | `HostingPanelApiTransport.php`                                                                                                   |
| Legacy Zjmf Support | 舊密碼相容、賬單恢復等非資料面相容能力                              | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`、`ZjmfBillingRestoreService.php`，由外掛 Provider 註冊 |

擴充套件規則：

- 業務 Service 不直接拼ZJMF請求，也不直接讀取官方欄位差異，新增差異先落到外掛 service/adapter。
- 能複用主機面板底層傳輸能力時只能經由 `ZjmfFinanceTransport` 包裝；provider key 和能力物件必須保持ZJMF獨立身份。
- ZJMF 雲商品配置項模板優先在外掛內 `ZjmfCloudConfigTemplate` 擴充套件，通用 fallback 才放到 `Services/Upstream/Support/CloudConfigTemplate`。
- 舊ZJMF密碼相容走 `LegacyPasswordVerifier` 聚合器，具體格式判斷放在 `ZjmfLegacyPasswordVerifier`。
- 新增 provider 或能力時同時補最小測試：provider 解析、adapter 顯式方法、關鍵欄位對映、失敗降級。
- 不允許新增或依賴 `ZjmfFinanceAdapter::__call()` 動態轉發；新增能力應先擴充套件外掛內部 service，再在 adapter 上補明確方法。

## 2. 入口與關鍵檔案

| 職責                     | 入口                                                                                               |
| ------------------------ | -------------------------------------------------------------------------------------------------- |
| 上游解析入口             | `backend/app/Services/Upstream/ProviderResolver.php`                                               |
| 驅動登錄檔               | `backend/app/Services/Upstream/ProviderRegistry.php`                                               |
| 主機面板驅動             | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiDriver.php`                  |
| 主機面板傳輸層           | `backend/app/Services/Upstream/Drivers/HostingPanelApi/HostingPanelApiTransport.php`               |
| ZJMF 財務外掛            | `backend/plugins/servers/zjmf_finance/`                                                            |
| ZJMF 財務驅動            | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceDriver.php`                                   |
| ZJMF 財務介面卡          | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php`                                  |
| ZJMF 財務傳輸入口        | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceTransport.php`                                |
| ZJMF 財務登入與 JWT 管理 | `backend/plugins/servers/zjmf_finance/lib/ZjmfAuthManager.php`                                     |
| ZJMF 雲配置項模板        | `backend/plugins/servers/zjmf_finance/lib/ZjmfCloudConfigTemplate.php`                             |
| ZJMF商品型別對映         | `backend/plugins/servers/zjmf_finance/lib/ZjmfProductTypeMapper.php`                               |
| 舊ZJMF密碼相容           | `backend/plugins/servers/zjmf_finance/lib/ZjmfLegacyPasswordVerifier.php`                          |
| 舊密碼相容聚合器         | `backend/app/Services/Auth/LegacyPasswordVerifier.php`                                             |
| 上游協議輔助             | `backend/app/Services/Upstream/Drivers/HostingPanelApi/Concerns/`                                  |
| 商品配置項歸一           | `backend/plugins/servers/zjmf_finance/lib/ZjmfCatalogService.php`、`ZjmfCloudConfigTemplate.php`   |
| ZJMF賬單恢復工具         | `backend/plugins/servers/zjmf_finance/lib/ZjmfBillingRestoreService.php`                           |
| 支付寶 F2F 外掛          | `backend/plugins/gateways/ali_pay/`                                                                |
| 支付寶 F2F 客戶端        | `backend/plugins/gateways/ali_pay/lib/AlipayClient.php`                                            |
| 支付寶 F2F 能力服務      | `backend/plugins/gateways/ali_pay/lib/AlipayService.php`                                           |
| 支付寶回撥               | `backend/app/Http/Controllers/Client/PaymentCallbackController.php`                                |
| 實名回撥                 | `backend/app/Http/Controllers/Client/VerificationController@callback`，走 `verify.callback` 中介軟體 |
| 回撥簽名中介軟體           | `backend/app/Http/Middleware/VerifyCallbackSignature.php`                                          |

## 3. 對接的上游能力清單

只列專案真實呼叫的，不代表官方全量：

| 能力                           | 方法         | 路徑                                                          | 本地呼叫入口                                                                      |
| ------------------------------ | ------------ | ------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| ZJMF登入並換 JWT               | `POST`       | `/zjmf_api_login`                                             | `ZjmfAuthManager::login()` / `ZjmfFinanceTransport::login()`                      |
| ZJMF獲取使用者資料               | `GET`        | `/v1/user`                                                    | `ZjmfFinanceTransport::getUserProfile()`                                          |
| ZJMF獲取賬戶餘額               | `GET`        | `/cart/credit`                                                | `ZjmfFinanceTransport::getBalance()`                                              |
| ZJMF商品目錄                   | `GET`        | `/cart/all`                                                   | `ZjmfCatalogService::getProductCatalog()`                                         |
| ZJMF商品配置與庫存             | `GET`        | `/cart/get_product_config?pid={productId}`                    | `ZjmfCatalogService`                                                              |
| ZJMF同系統主機詳情             | `GET`        | `/host/header?host_id={hostId}&source=API`                    | `ZjmfFinanceTransport::getHostDetail()`；將 `data.host_data` 歸一為 `data.host`。 |
| ZJMF開通                       | 多個官方介面 | 購物車、結算、上游支付、host 回查                             | `ZjmfProvisionService::provisionOrder()`                                          |
| ZJMF續費                       | `POST`       | `/host/renew`；`/pay?action=billing&pay=true`；`/check_order` | `ZjmfRenewService`；自動續費僅由本站賬單與排程執行，不同步上游開關。              |
| ZJMF狀態同步                   | `GET`        | `/host/header?host_id={hostId}&source=API`，以及執行狀態介面  | `ZjmfStatusService`                                                               |
| ZJMF VNC、電源、重灌、重置密碼 | 多個官方介面 | host action / console 介面                                    | `ZjmfConsoleService`                                                              |
| ZJMF流量包、升降級             | 多個官方介面 | upgrade / flowpacket 介面                                     | `ZjmfNetworkService`                                                              |
| ZJMF NAT、安全組、自定義模組   | `GET/POST`   | servicedetail 自定義模組頁面和提交介面                        | `ZjmfSecurityService`、`ZjmfConsoleService`                                       |

外掛 service 內部通過 `ZjmfFinanceTransport::get/post/put/delete/parallelGet` 呼叫上游。平臺業務 Service 只調用 capability 暴露的高層方法，不直接拼ZJMF請求路徑、請求引數或官方響應欄位。

## 4. 簽名與認證約束

- ZJMF JWT 按 `supplier_id + provider_key` 隔離快取，`provider_key` 必須保持 `zjmf_finance_api`
- ZJMF外掛呼叫前由 `ZjmfAuthManager` 自動 `login`，失敗或 401 會清理快取並觸發重新整理/重登
- 普通 ZJMF 資料面在獲取 JWT 後使用 `Authorization: Bearer <token>`；同系統自定義模組頁面和動作使用 `Authorization: JWT <token>`，其中頁面請求還必須傳入 `id`、`key` 和 `jwt` 查詢引數；`/zjmf_api_login` 與官方參考的 `/v1/login_api` 不能混用
- 上游請求失敗由外掛傳輸入口和共享底層傳輸記錄脫敏日誌，日誌必須能區分 `zjmf_finance_api` 與 `hosting_panel_api`
- 上游回撥（供應商主動推我們）當前**未**暴露標準 webhook；業務主要靠輪詢

## 5. 支付對接：支付寶 F2F

### 5.1 能力

| 能力                 | 阿里方法名               | 本地入口                                       |
| -------------------- | ------------------------ | ---------------------------------------------- |
| 預下單生成付款二維碼 | `alipay.trade.precreate` | `AlipayService` action `payment.precreate`     |
| 主動查詢訂單狀態     | `alipay.trade.query`     | `AlipayService` action `payment.query`         |
| 交易退款             | `alipay.trade.refund`    | `AlipayService` action `payment.refund`        |
| 驗證非同步通知簽名     | -                        | `AlipayService` action `payment.verify_notify` |

### 5.2 配置入口

通過管理端"通知配置/支付配置"設定以下 `settings` 項：

- `alipay_app_id`
- `alipay_private_key`
- 其餘支付寶相關 `alipay_*` 項

### 5.3 回撥路徑

- 支付寶非同步通知：`POST /api/v2/client/payment/alipay/notify`
- 本地回撥 URL 約定：`{APP_URL}/api/v2/client/payment/alipay/notify`
- 回撥裡通過支付外掛執行時執行 `payment.verify_notify` 做簽名校驗，`PaymentService` 做冪等 + 狀態落庫 + 後續開通/續費派發

## 6. 實名認證對接：支付寶實名

### 6.1 客戶端介面（面向使用者端 SPA）

- `GET /api/v2/client/verification/fee-config` - 獲取實名費用配置
- `POST /api/v2/client/verification/init` - 初始化實名（拿 `certify_id`）
- `POST /api/v2/client/verification/qrcode` - 獲取掃碼二維碼
- `GET /api/v2/client/verification/status` - 查詢當前狀態
- `GET /api/v2/client/verification/scan` - 掃碼跳轉中轉

### 6.2 實名非同步回撥

- 路徑：`GET|POST /api/v2/client/verification/callback`
- 中介軟體：`verify.callback`（對應 `VerifyCallbackSignature`）
- 控制器：`Client\V2\VerificationController::callback`
- 成功後會更新 `users.verification_*`、`user_accounts` 對應欄位，並落 `verification_histories`

### 6.3 管理端可見性

- `GET /api/v2/admin/verifications` - 實名列表
- `GET /api/v2/admin/verifications/{user}/history` - 歷史
- `POST /api/v2/admin/verifications/{user}/unbindings` - 後臺解綁（按許可權）

## 7. 與官方文件的對映

| 官方文件                      | 對應本地檔案                                                                                                        |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| 受控上游的 ZJMF 財務 API 參考 | `backend/plugins/servers/zjmf_finance/lib/ZjmfFinanceAdapter.php` + `Zjmf*Service.php` + `ZjmfFinanceTransport.php` |

建議閱讀順序：

1. 本文（專案對接範圍）
2. 對應的官方參考文件（欄位語義）
3. 對應的本地 Service 實現

## 8. 新增對接時的基線清單

新增上游或支付渠道時，至少滿足：

- 在 `suppliers` 表建立記錄（或接入相應配置表）
- 新增或複用 `Service` 客戶端（不要直接在 Controller 裡 `Http::post`）
- 入參、出參、異常落日誌（`trace_id` + `supplier_id` + 呼叫上下文）
- JWT / Token 做快取 + 過期回退機制
- 呼叫失敗有可觀測性（管理端日誌頁能查）
- 回撥必須過 `verify.callback` 或等價簽名中介軟體
- 回撥處理必須冪等
- 回撥路徑、公鑰/金鑰、商戶號等敏感配置走 `settings` 或 `.env`，**不要硬編碼**

## 9. 尚未接入的官方能力

以下在官方文件裡存在，但當前專案**未**主動呼叫：

- 通用 webhook 推送接收（專案主要依賴輪詢）
- 訂單維度回撥（訂單狀態靠查詢 + 內部狀態機自愈）
- 產品庫一鍵全量同步（當前只做 `batch-sync` 與按需拉取）

需要接入時，先在本目錄追加章節，再同步 `Upstream` 驅動與傳輸層。
