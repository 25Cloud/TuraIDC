---
source: develop/api/catalog-navigation.md
sourceHash: b99feef6fcccf934b514c0181eb883fe38548c4627c958f16e491ab95f11a1b1
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/api/catalog-navigation.md 產生，請勿手動編輯。 -->
# 後端 API 清單導航

## 1. 文件用途

- 給 `docs/develop/api/backend-api-catalog.md` 這份自動生成清單提供一份人類可讀的業務導航
- 對齊時間：`2026-09-19`
- 本文手工維護，不會被匯出指令碼覆蓋
- 具體方法、控制器動作、中介軟體和鑑權仍以 `docs/develop/api/backend-api-catalog.md` 為準

## 2. 分組速覽

| 分組              | 路徑範圍                                 | 預設鑑權/特徵                                                                                       |
| ----------------- | ---------------------------------------- | --------------------------------------------------------------------------------------------------- |
| 管理端            | `/api/v2/admin/*`                        | 除 `/api/v2/admin/login` 外，預設 `auth:sanctum + ensure.admin`，多數介面疊加 `permission:{code}`   |
| 使用者端            | `/api/v2/client/*`                       | 公開認證、回撥、VNC Token 和實名回撥混在同一字首下；其餘主體介面預設 `auth:sanctum + ensure.client` |
| 公開站點          | `/api/v2/site/*`                         | 官網、公開產品、公開內容、報價、站點配置                                                            |
| 開放 API          | `/api/v2/open/*`                         | API Key 認證（scope 授權 + IP 白名單）+ `throttle:open-api` 限流，供系統間對接與轉售鏈呼叫          |
| ZJMF 上游協議     | `/api/v2/zjmf/*`                         | `/zjmf_api_login` 換 JWT 後走 `zjmf.upstream` 中介軟體，供魔方財務作為下游對接                        |
| 其他公開/受控介面 | `/api/health`、`/api/secure-assets/view` | 健康檢查或按資源引數校驗訪問                                                                        |

## 3. 業務域速覽

### 管理端（admin）

| 業務域         | 關鍵路徑字首                                                                                                                    | 說明                                                             |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 認證           | `/api/v2/admin/login`、`/api/v2/admin/auth/*`                                                                                   | 管理員登入、資訊、資料更新、退出                                 |
| 儀表盤         | `/api/v2/admin/dashboard*`                                                                                                      | 首頁指標與最近賬單                                               |
| 使用者           | `/api/v2/admin/users*`                                                                                                          | 使用者列表、詳情、充值、代理登入、巢狀賬單/服務/日誌/工單            |
| 賬單           | `/api/v2/admin/invoices*`、`/api/v2/admin/users/{user}/invoices*`                                                               | 當前主財務實體是發票/賬單，不再以訂單路由為主                    |
| 商品           | `/api/v2/admin/products*`、`/api/v2/admin/product-categories*`、`/api/v2/admin/product-groups*`、`/api/v2/admin/product-types*` | 商品、分類、分組、型別、批次同步與排序                           |
| 服務例項       | `/api/v2/admin/services*`、`/api/v2/admin/users/{user}/services*`                                                               | 管理端例項概覽與使用者下例項操作                                   |
| 供應商         | `/api/v2/admin/suppliers*`                                                                                                      | 供應商餘額、商品拉取、批次對接                                   |
| 整合外掛       | `/api/v2/admin/integration-plugins*`                                                                                            | 支付、實名、簡訊、郵件、上游外掛掃描、安裝、配置、啟停、健康檢查 |
| 代理折扣       | `/api/v2/admin/agent-discounts*`（折扣組/代理組/折扣矩陣）                                                                      | 代理折扣體系配置，含代理組預設折扣率                             |
| 開放介面       | `/api/v2/admin/open-api*`                                                                                                       | 開放 API 開關、配額與金鑰管理、用量審計                          |
| 優惠券         | `/api/v2/admin/coupons*`、`/api/v2/admin/coupon-campaigns*`                                                                     | 優惠券與活動發券                                                 |
| 推薦返佣       | `/api/v2/admin/referral*`、`/api/v2/admin/referral-withdrawals*`                                                                | 返佣概覽、獎勵、賬變、提現稽核                                   |
| 實名認證       | `/api/v2/admin/verifications*`                                                                                                  | 實名稽核、詳情、編輯、歷史、解綁                                 |
| 工單           | `/api/v2/admin/tickets*`                                                                                                        | 工單列表、回覆、關閉、指派、圖片上傳、上游傳遞規則與日誌         |
| 內容與媒體     | `/api/v2/admin/content*`、`/api/v2/admin/media-files*`                                                                          | 文章分類、文章內容、媒體庫                                       |
| 日誌           | `/api/v2/admin/logs*`                                                                                                           | API、簡訊、郵件、任務、系統、登入日誌與清理                      |
| 設定與站點運營 | `/api/v2/admin/settings`、`/api/v2/admin/site/home-hero`                                                                        | 系統配置（含開放 API、工單預回覆等分組）、站點首頁 Hero          |
| 排程           | `/api/v2/admin/schedules*`                                                                                                      | 排程總覽、運行臺賬與手動觸發                                     |
| 會員等級       | `/api/v2/admin/member-levels*`                                                                                                  | 等級配置                                                         |

### 使用者端（client）

| 業務域   | 關鍵路徑字首                                                                                          | 說明                                                                               |
| -------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| 認證     | `/api/v2/client/login`、`/api/v2/client/register`、`/api/v2/client/auth/*`、`/api/v2/client/password` | 登入、註冊、找回密碼、資料、通知偏好、支付寶賬號                                   |
| 實名認證 | `/api/v2/client/verification*`                                                                        | 狀態、初始化、二維碼、重試、回撥、掃碼                                             |
| 賬單     | `/api/v2/client/invoices*`                                                                            | 當前使用者側下單與支付主實體是發票                                                   |
| 充值     | `/api/v2/client/recharge*`                                                                            | 充值下單與狀態輪詢                                                                 |
| 服務例項 | `/api/v2/client/services*`、`/api/v2/client/vnc-tokens/*`                                             | 例項詳情、監控、續費、重灌、VNC、NAT、安全組、流量包、自定義面板區域與上游資源反代 |
| 餘額     | `/api/v2/client/balance-logs*`                                                                        | 餘額流水和彙總                                                                     |
| 優惠券   | `/api/v2/client/coupons*`                                                                             | 優惠券、彙總、領取；其中 `coupons/public*` 仍需客戶端鑑權                          |
| 推薦返佣 | `/api/v2/client/referral*`                                                                            | 概覽、獎勵、賬變、提現申請                                                         |
| 工單     | `/api/v2/client/tickets*`                                                                             | 列表、詳情、回覆、關閉、上傳圖片                                                   |
| 內容     | `/api/v2/client/content/overview`、`/api/v2/client/notices*`、`/api/v2/client/help-articles*`         | 使用者側公告與幫助中心                                                               |
| API 金鑰 | `/api/v2/client/api-keys*`                                                                            | 金鑰增刪改、啟停與用量日誌；明文僅建立時返回一次                                   |
| 支付回撥 | `/api/v2/client/payment/alipay/notify`                                                                | 支付寶非同步通知                                                                     |

### 開放 API（open）

業務域 × 讀寫 scope 組合授權（`products/orders/services/finance` × `read/write`），寫端點疊加 `throttle:open-api-write`：

| 業務域 | 關鍵路徑字首                                                               | 說明                              |
| ------ | -------------------------------------------------------------------------- | --------------------------------- |
| 商品   | `GET /api/v2/open/products*`（列表/詳情/報價）                             | 目錄匯入與按週期報價              |
| 訂單   | `GET/POST /api/v2/open/orders*`（下單、餘額支付）                          | 下單冪等，賬單投影含 `service_id` |
| 服務   | `GET/POST /api/v2/open/services*`（詳情/續費預覽/重灌選項/電源/續費/重灌） | 供下游驅動做狀態同步與控制        |
| 財務   | `GET /api/v2/open/balance`                                                 | 供應商餘額查詢，供低餘額預警      |
| 金鑰   | `GET /api/v2/open/keys/self`、`POST /api/v2/open/keys/self/disable`        | 金鑰自助檢視與停用                |

### 公開站點與其他公開介面

| 業務域       | 關鍵路徑字首                                                                                                               | 說明                              |
| ------------ | -------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| 站點配置     | `/api/v2/site/config`、`/api/v2/site/home`、`/api/v2/site/home-hero`                                                       | 官網基礎配置、首頁聚合、首頁 Hero |
| 公開商品     | `/api/v2/site/product-types`、`/api/v2/site/product-groups*`、`/api/v2/site/product-categories*`、`/api/v2/site/products*` | 官網商品瀏覽、庫存、報價          |
| 公開內容     | `/api/v2/site/content/overview`、`/api/v2/site/notices*`、`/api/v2/site/help-articles*`                                    | 官網公告與幫助中心                |
| 健康檢查     | `/api/health`                                                                                                              | 服務可用性探針                    |
| 受控資源檢視 | `/api/secure-assets/view`                                                                                                  | 資源檢視，不屬於普通公開靜態資源  |

## 4. 核心業務流程 -> 關鍵介面

### 官網選購 -> 建立賬單 -> 支付 -> 開通

| 步驟         | 介面                                                 |
| ------------ | ---------------------------------------------------- |
| 瀏覽商品     | `GET /api/v2/site/products`                          |
| 檢視詳情     | `GET /api/v2/site/products/{productId}`              |
| 獲取報價     | `POST /api/v2/site/products/{productId}/quote`       |
| 建立賬單     | `POST /api/v2/client/invoices`                       |
| 餘額支付     | `POST /api/v2/client/invoices/{id}/pay/balance`      |
| 支付寶支付   | `POST /api/v2/client/invoices/{id}/pay/alipay`       |
| 查詢支付狀態 | `GET /api/v2/client/invoices/{id}/pay/alipay/status` |
| 檢視賬單詳情 | `GET /api/v2/client/invoices/{id}`                   |
| 檢視服務例項 | `GET /api/v2/client/services/{id}`                   |

### 服務續費

| 步驟         | 介面                                      |
| ------------ | ----------------------------------------- |
| 檢視續費預覽 | `GET /api/v2/client/services/{id}/renew`  |
| 生成續費賬單 | `POST /api/v2/client/services/{id}/renew` |
| 後續支付     | 複用 `/api/v2/client/invoices/{id}/pay/*` |

### 流量包加購

| 步驟           | 介面                                                       |
| -------------- | ---------------------------------------------------------- |
| 檢視流量包列表 | `GET /api/v2/client/services/{id}/traffic-packages`        |
| 獲取加購報價   | `POST /api/v2/client/services/{id}/traffic-packages/quote` |
| 建立加購單     | `POST /api/v2/client/services/{id}/traffic-packages/order` |

### 自定義面板區域（上游模組頁）

上游（如魔方財務）為面板型產品下發的自定義 tab，經本系統以 iframe 隔離渲染；內容與動作走同一套短時效票據，瀏覽器不再持有上游憑據，上游域名與靜態資源也經本系統反代：

| 步驟                   | 介面                                                                      |
| ---------------------- | ------------------------------------------------------------------------- |
| 查詢可用 tab / 能力    | `GET /api/v2/client/services/{id}/console/capabilities`                   |
| 換取短時效票據         | `POST /api/v2/client/services/{id}/console/tickets`                       |
| 取模組頁 HTML          | `GET /api/v2/client/services/{id}/console-area/content?ticket=…&module=…` |
| 反代上游靜態資源與圖片 | `GET /api/v2/client/services/{id}/console-area/asset?ticket=…&path=…`     |
| 回發模組動作           | `POST /api/v2/client/services/{id}/console-area/actions?ticket=…`         |

`asset` 端點只接受以 `/` 開頭的路徑，目標主機由服務繫結的供應商推導，因此不能被當成任意 URL 的開放反代。該端點返回 CSS 時，會把檔案內部的相對 `url()` 改寫成同一端點的根相對地址：跨源反代換掉了相對引用的解析基準（瀏覽器按反代後的 URL 解析），原樣透傳會讓 CSS 裡的圖片請求到本站一個不存在的路徑。改寫只作用於 CSS —— JS 裡的相對路徑沒有可靜態解析的形態。

`capabilities` 返回的 `area_preload_all` 表示是否在首屏預熱全部自定義面板區域（預設按開啟處理）。面板內容介面是 `no-store`，瀏覽器快取複用不了，所以面板只能靠 iframe 常駐來預熱：開啟時每個自定義區域各掛一個例項，切換 tab 不再等待，也不必來回切換重複回源上游。它由後臺設定 `system.console_area_preload_all_enabled`（管理端「整合外掛 → 上游 → 面板區域首屏預熱」）控制，且刻意不寫進能力快取 —— 管理員改完設定立即生效，不必等 300 秒的能力快取過期。

### 實名認證

| 步驟         | 介面                                                                                    |
| ------------ | --------------------------------------------------------------------------------------- |
| 查詢狀態     | `GET /api/v2/client/verification/status`                                                |
| 獲取費用配置 | `GET /api/v2/client/verification/fee-config`                                            |
| 初始化實名   | `POST /api/v2/client/verification/init`                                                 |
| 獲取二維碼   | `POST /api/v2/client/verification/qrcode`                                               |
| 重試流程     | `POST /api/v2/client/verification/restart`                                              |
| 非同步回撥     | `GET /api/v2/client/verification/callback`、`POST /api/v2/client/verification/callback` |
| 管理端查詢   | `GET /api/v2/admin/verifications`                                                       |
| 管理端歷史   | `GET /api/v2/admin/verifications/{user}/history`                                        |

### 工單

| 步驟       | 介面                                         |
| ---------- | -------------------------------------------- |
| 使用者提交   | `POST /api/v2/client/tickets`                |
| 使用者回覆   | `POST /api/v2/client/tickets/{id}/reply`     |
| 使用者關閉   | `POST /api/v2/client/tickets/{id}/close`     |
| 管理端列表 | `GET /api/v2/admin/tickets`                  |
| 管理端回覆 | `POST /api/v2/admin/tickets/{ticket}/reply`  |
| 管理端指派 | `POST /api/v2/admin/tickets/{ticket}/assign` |

### 內容與媒體

| 步驟                    | 介面                                      |
| ----------------------- | ----------------------------------------- |
| 官網公告列表            | `GET /api/v2/site/notices`                |
| 官網幫助列表            | `GET /api/v2/site/help-articles`          |
| 使用者側內容總覽          | `GET /api/v2/client/content/overview`     |
| 管理端文章列表          | `GET /api/v2/admin/content/articles`      |
| 管理端上傳封面/正文圖片 | `POST /api/v2/admin/content/upload-image` |
| 管理端媒體庫            | `GET /api/v2/admin/media-files`           |

### 站點運營配置

| 步驟          | 介面                                |
| ------------- | ----------------------------------- |
| 拉取系統配置  | `GET /api/v2/admin/settings`        |
| 儲存系統配置  | `POST /api/v2/admin/settings`       |
| 檢視首頁 Hero | `GET /api/v2/admin/site/home-hero`  |
| 更新首頁 Hero | `POST /api/v2/admin/site/home-hero` |

## 5. 維護建議

- 新增或移除介面後，先執行 `php backend/scripts/export_api_inventory.php` 重刷 `docs/develop/api/backend-api-catalog.md`
- 如果業務主實體變化，例如從 `orders` 切到 `invoices`，必須同步更新本文的“業務域速覽”和“核心業務流程”
- 新增公開介面時，同時檢查它屬於：
  - 站點公開
  - 客戶端字首下的公開認證能力
  - 回撥介面
  - 受控資源介面
