---
status: current
updated: 2026-09-13
owner: backend-platform
source: develop/architecture.md
sourceHash: 965039831acfd7259096942bba5e8222dbec842228c65395a536ef6b89f2d703
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/develop/architecture.md 產生，請勿手動編輯。 -->
# 架構現狀說明

本文是系統架構的當前真源，面向新入項開發者、代理和後端/前端/運維協作者，說明倉庫結構、後端與前端架構、本地與生產執行方式以及資料庫與文件資產口徑。細節以執行程式碼、測試和實庫為準。

## 1. 倉庫結構

```text
turaidc/
├── backend/                  Laravel 12 后端
├── frontend-admin-v3/        当前管理端，Vue 3 + TDesign
├── frontend-user-v3-www/     官网与用户入口，Vue 3 + Element Plus
├── frontend-user-v4-console/ 新版用户控制台，Vue 3 + TDesign
├── shared/                   跨前端共享包
└── docs/                     项目长期开发文档
```

當前不存在 `frontend-admin`、`frontend-client`、`frontend-user-v3-console`、`frontend-www-v2`、`frontend-console-v2`。文件或指令碼中若出現這些路徑，先按歷史引用處理並核對真實目錄。

## 2. 後端架構

技術基線：

- PHP 8.3+
- Laravel 12
- Sanctum 4 Token 鑑權
- MySQL 8
- Redis 快取
- `database` 佇列驅動
- 本地統一入口：`php artisan app:serve`

主要目錄：

- `app/Http/Controllers/Admin/V2/`：管理端 API 控制器。
- `app/Http/Controllers/Client/V2/`：使用者端 API 控制器。
- `app/Http/Controllers/`：基類控制器與公共入口（如 `SecureAssetController`）；公開站點 API 在 `Controllers/Site/V2/` 下。
- `app/Http/Requests/Admin/V2|Client/V2/`：管理端和使用者端 FormRequest。
- `app/Http/Resources/Admin/V2|Client/V2/`：管理端和使用者端 API Resource；其餘 Resource 按公共業務域組織。
- `app/Services/`：業務主層，已按 `Finance`、`Order`、`ProductCatalog`、`ClientServiceConsole`、`Provisioning`、`Upstream`、`Supplier`、`Integrations`、`OpenApi`、`ZjmfUpstream`、`Notification`、`Ticket` 等領域收斂。
- `app/Services/Integrations/Plugins/`：外掛掃描、安裝、配置、執行時註冊和能力適配。
- `plugins/`：支付、實名、簡訊、郵件、上游伺服器等外掛實現目錄。
- `plugins/servers/zjmf_finance/`：承載 ZJMF 財務資料面對接，以及舊密碼相容、賬單恢復等 ZJMF 專屬能力；相容實現位於其 `lib/`，由外掛 Provider 註冊。
- `app/Constants/`：狀態、列舉、業務常量。
- `app/Console/Commands/`：Artisan 命令，包括遷移、同步、VNC Relay、`app:serve`。

路由分工：

- `routes/api.php`：公開站點 V2 API，主要是 `/api/v2/site/*` 和 `/api/health`、`/api/ready`，以及工單上游回覆回撥 `/ticket_reply/sync`（獨立簽名校驗）。
- `routes/v2-admin.php`：管理端 V2 API，走 `auth:sanctum` + `ensure.admin` + `permission:{code}`。
- `routes/v2-client.php`：使用者端 V2 API，走 `auth:sanctum` + `ensure.client`，支付/實名/VNC token 等回撥或公開入口有獨立簽名/限流。
- `routes/v2-open.php`：開放 API（`/api/v2/open/*`），API Key 認證（`api.key:{domain},{action}` 中介軟體，scope 授權 + IP 白名單）+ `throttle:open-api` 限流，供系統間對接與轉售鏈呼叫。
- `routes/v2-zjmf-upstream.php`：ZJMF 上游協議入口（`/api/v2/zjmf/*`），供魔方財務把本系統作為上游服務商對接；`/zjmf_api_login` 換 JWT 後走 `zjmf.upstream` 中介軟體。
- `routes/console.php`：排程任務（心跳、佇列消費、探針與週期清理）。
- `routes/web.php`：非 API 入口（安裝嚮導、SEO 動態渲染、robots/sitemap）與靜態相容。

API 直接重構路由口徑：

- 舊介面 `/api/admin/*`、`/api/client/*`、`/api/site/*` 已刪除，不再註冊或保留相容層。
- 當前業務介面統一使用 `/api/v2/admin/*`、`/api/v2/client/*`、`/api/v2/site/*` 名稱空間。
- v2 路由仍復用現有鑑權、許可權碼、業務 Service、外掛邊界和統一響應外層；變化集中在 FormRequest、Resource/Response DTO、欄位投影、分頁和子資源拆分。
- 具體任務、介面拆分和驗收標準見 `docs/internal/designs/backend/direct-api-refactor.md`。

響應規範：

- 成功固定 `code = 0`。
- JSON 外層由 `App\Traits\ApiResponse` / `App\Support\ApiResponseBuilder` 生成。
- 分頁結構放在 `data` 內：`list`、`total`、`page`、`page_size`。
- 校驗失敗統一 `42200`，欄位錯誤在 `data.errors`。
- v2 新介面禁止繼承舊 `per_page`、列表詳情混用、寬 Resource 和深層聚合響應；預設 JSON 響應應控制在 `100KB` 以內。

關鍵業務約束：

- Controller 不直接寫複雜業務，不直接呼叫第三方 `Http::*`。
- 第三方和上游呼叫走外掛目錄、`Services/Integrations/Plugins`、`Services/Upstream` 等專用層。
- `zjmf_finance_api` 是獨立 provider key，禁止歸一化為 `hosting_panel_api`。
- `payments` 表只記錄真實第三方資金流入，不記錄餘額支付、手動開服、免費訂單；禁止物理刪除 Payment。
- 財務、訂單、餘額、返佣、開通、回撥必須考慮事務、冪等和審計欄位。

第三方外掛化現狀：

- 外掛後設資料和啟用配置由 `integration_plugins`、`integration_plugin_configs` 承載。
- 外掛按能力域放在 `backend/plugins/{domain}/{slug}/`，當前包含 `addons`、`captcha`、`certification`、`gateways`、`mail`、`servers`、`sms` 等域。
- 外掛配置中的敏感欄位進入加密儲存，前端只展示是否已配置和脫敏預覽。
- 支付、實名、簡訊、郵件、上游伺服器能力由平臺內部介面/manager 呼叫，不允許業務 Controller 直接依賴外掛實現類。
- 上游供應商驅動統一走 `app/Services/Upstream/` 的 `UpstreamDriver` 契約與 `Provides*` 能力介面分層；`ProviderRegistry` 只從已啟用的 upstream 域外掛解析驅動。當前 provider key：`hosting_panel_api`（應用內 transport）、`zjmf_finance_api`（ZJMF 財務外掛）、`tura_open_api`（TuraIDC 開放介面外掛，純自有協議轉售鏈）。
- ZJMF 財務上游保持獨立 `zjmf_finance_api` provider key，不得摺疊為 `hosting_panel_api`。
- ZJMF 財務 adapter 必須顯式宣告平臺可呼叫方法，不得恢復 `__call()` 動態轉發。
- 本系統亦可作為上游被魔方財務對接：`app/Services/ZjmfUpstream/` + 路由 `/api/v2/zjmf`，協議參考 `docs/develop/integrations/zjmf-upstream-api.md`。

訂單、賬單、充值記錄職責邊界：

- `orders` 是購買契約，承載使用者買了什麼產品、配置、週期、數量和配置定價快照；`config_snapshot`、`config_pricing_snapshot` 只在訂單詳情展示。
- `invoices` 是結算憑證，承載應付、實付、賬單狀態、賬單行專案和支付明細；賬單詳情不展示產品配置快照。
- `payments` 是第三方資金流入記錄，只記錄支付寶等外部渠道真實入賬；餘額支付、管理員手動開服、免費訂單不產生 Payment。
- 三類記錄通過 ID 互聯，詳情頁只保留必要跳轉或追溯引用，不內聯不屬於自身職責的冗餘欄位。
- 訂單、賬單、支付狀態列舉統一來自 `shared/statusConfig.js`，前端狀態展示優先複用共享狀態能力。

## 3. 前端架構

### 3.1 `frontend-admin-v3`

- 定位：當前管理端。
- 技術：Vue 3、Vite、TypeScript、TDesign Vue Next、TDesign Icons Vue Next、Pinia、Less。
- 入口：`src/main.ts`、`src/permission.ts`、`src/router/`、`src/store/`。
- 頁面：`src/pages/`，按業務域組織。
- API：`src/api/`。
- 樣式：`src/style/`，沿用 TDesign/TDesign Starter token。
- 驗證：`cd frontend-admin-v3 && pnpm run build`。

### 3.2 `frontend-user-v3-www`

- 定位：官網門戶——首頁、產品展示與購買、公告、幫助中心與法律條款頁。登入註冊與使用者中心已遷至 `frontend-user-v4-console`（`src/pages/client-auth/` 與 `src/pages/client/`），本應用不再包含認證路由。
- 技術：Vue 3、Vite、Element Plus、Pinia、Sass。
- 入口：`src/main.js`、`src/app/`（bootstrap、http、router、runtime、stores）、`src/router/`。
- 頁面：`src/pages/website`、`src/pages/common`。注意實現主體位於 `src/views/website`，`src/pages` 下多數 `index.vue` 只是指向 `views` 的路由轉發殼（例如 `pages/website/products/index.vue` 僅 154 位元組）。
- 領域邏輯：`src/domains/`（當前僅 `products`）。
- 樣式：`src/assets/styles/`。
- SEO：官網公開頁面（首頁、產品、落地頁、公告/幫助等）由後端 Laravel 動態渲染完整 HTML（讀資料庫站名/Logo/meta + 正文快照），前端 Nginx 將公開路徑轉發到後端 `/seo/www/{path}`；sitemap.xml / robots.txt 亦由後端動態生成。詳見 `backend/app/Services/Site/SeoRenderService.php`。
- 驗證：`cd frontend-user-v3-www && pnpm run build`，重構時追加 `pnpm run verify:refactor`。

### 3.3 `frontend-user-v4-console`

- 定位：新版使用者控制台。
- 技術：Vue 3、Vite、TypeScript、TDesign Vue Next、TDesign Icons Vue Next、Pinia、Less。
- 入口：`src/main.ts`、`src/permission.ts`、`src/router/`、`src/store/`。
- 頁面：`src/pages/client/`。
- 領域邏輯：`src/domains/`、`src/composables/`、`src/api/`。
- 共享元件：優先複用 `shared/user-v3` 與 `@turaidc/shared`。
- 驗證：`cd frontend-user-v4-console && pnpm run build`，重構時追加 `pnpm run verify:refactor`。

### 3.4 `shared`

跨端共享包，當前承載：

- `status`：狀態對映、狀態標籤。
- `runtime`：session、network、branding、route loading、HTTP runtime。
- `content`：Markdown/HTML 安全渲染。
- `user-v3`：使用者控制台通用 TDesign 風格元件。

涉及狀態文案、狀態顏色、狀態標籤、控制台基礎元件時，先查 `shared`。

## 4. 本地啟動

```bash
cd backend
php artisan app:serve

pnpm run dev:admin-v3
pnpm run dev:user-v3-www
pnpm run dev:user-v4-console
```

不要使用 `php artisan serve` 替代 `app:serve`。

## 5. 生產執行

- 後端生產入口：PHP-FPM 指向 `backend/public`。
- 排程與佇列：由寶塔每分鐘 `php artisan schedule:run` 驅動三個常駐入口——`scheduler:heartbeat`（唯一時鐘源，按 15 分鐘槽位去重派發定時任務，不內聯消費佇列）、`queue:drain`（後臺子程序消費佇列，按 `provision`、業務組 `referral,notification,coupon,default`、定時組 `automation` 三個佇列組分別建 Worker，drain 鎖保證同一佇列不併發）、`scheduler:liveness`（心跳存活探針）。另有每分鐘的工單上傳檔案清理與每週的開放 API 用量日誌清理。
- 佇列分組由 `TURAIDC_PROVISION_QUEUES` / `TURAIDC_BUSINESS_QUEUES` / `TURAIDC_SCHEDULE_QUEUE` 配置；開通類任務 Worker 超時可達 1200 秒，錯過的 15 分鐘槽位預設不自動回放。
- VNC Relay：獨立執行 `php artisan vnc:relay`，由寶塔守護或程序管理器自動重啟，不由心跳任務拉起。
- 非同步任務可能有 0-60 秒延遲。
- 前端分別構建後按站點靜態釋出：
  - `frontend-admin-v3/dist`
  - `frontend-user-v3-www/dist`
  - `frontend-user-v4-console/dist`
- 官網 SEO 渲染（容器部署下）：`frontends` 容器內 Nginx 將官網公開路徑（`/`、`/products`、落地頁、`/notices`、`/help` 及其詳情、`/products/{id}`）轉發到 `app` 容器的 `/seo/www/{path}`；Laravel 以 `http://frontends:8081/index.html`（或原始碼部署下 `file://` 本地路徑）為模板組裝完整 HTML。相關配置見 `config/idc.php → idc.seo` 與 `deploy/docker/frontends/nginx-default.conf`。

## 6. 資料庫口徑

- 表名和欄位名使用 `snake_case`。
- 遷移只新增檔案，不改歷史遷移。
- 倉庫存在歷史激進遷移，禁止補跑。
- 真實表結構以實庫 `information_schema` 為準；文件以 `docs/develop/database.md` 為摘要。

## 7. 文件與歷史資產口徑

- `docs/` 是長期開發、聯調、部署和維護的記錄系統。
- 階段執行記錄、審查快照、一次性審計報告和計劃按 `docs/` 的分類維護；仍有效的規則應沉澱到對應真源。
- 歷史舊系統解包目錄、前端 zip 匯入包、根目錄 SQL dump、舊構建產物屬於遷移/匯入臨時資產，確認無使用後應清理或移出倉庫。

## 關聯文件

- [後端工程規範](backend.md) / [前端專案規範](frontend.md)：兩側的工程約束細節。
- [使用者文件](../guide/index.md)：部署、排程與日常運營操作入口。
- [設計文件索引](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/designs/index.md)：架構與方案級決策記錄。
