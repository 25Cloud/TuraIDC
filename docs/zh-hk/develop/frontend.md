---
status: current
updated: 2026-07-02
owner: frontend
source: develop/frontend.md
sourceHash: 2b3064c3af1da2812df4e4f011513fae579e260923f888a8c8cfe65863dc79b7
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/frontend.md 產生，請勿手動編輯。 -->
# 前端項目規範

本文是三個前端應用與 `shared` 共享包的當前規範，面向維護這些項目的開發者和代理。UI 框架不可混用：管理端與控制台用 TDesign，官網用 Element Plus。

## 1. 當前前端項目

- `frontend-admin-v3`：管理端，Vue 3 + Vite + TypeScript + TDesign Vue Next。
- `frontend-user-v3-www`：官網與用戶入口，Vue 3 + Vite + Element Plus。
- `frontend-user-v4-console`：新版用戶控制台，Vue 3 + Vite + TypeScript + TDesign Vue Next。
- `shared`：跨端共享包，導出狀態、runtime、content 和用戶控制台基礎組件。

舊路徑 `frontend-admin`、`frontend-client`、`frontend-user-v3-console`、`frontend-www-v2`、`frontend-console-v2` 當前不存在，不要新增引用。

## 2. 通用規則

- 使用 Vue 3 `script setup` + Composition API。
- HTTP 請求走各端現有 `src/api/*` 與 request/runtime 工具，不直接創建 axios 實例。
- Token、session、站點信息走現有 auth/runtime/store，不直接讀寫 localStorage 鍵。
- 複雜頁面邏輯下沉到同目錄 `composables`、`domains`、`features`、`services` 或 `utils`。
- 用戶可見文案使用簡體中文，錯誤提示優先讀取後端 `message` 和 `data.errors`，不要展示英文異常或第三方原始錯誤。
- 狀態展示先複用 `@turaidc/shared`。

## 3. `frontend-admin-v3`

目錄約定：

- `src/main.ts`：應用入口。
- `src/permission.ts`：路由守衞。
- `src/router/modules/`：按業務域拆分路由。
- `src/store/modules/`：Pinia 狀態。
- `src/pages/`：頁面，按業務域組織。
- `src/api/`：管理端 API 封裝。
- `src/style/`：Less 樣式與主題變量。
- `src/layouts/`：管理端佈局。

實現約束：

- UI 只用 TDesign Vue Next。
- 圖標只用 `tdesign-icons-vue-next`。
- 權限碼與後端 `App\Support\AdminPermissions` 對齊。
- 頁面不新增 Element Plus 組件、樣式或圖標。
- 管理列表頁不做説明型頁頭大卡片，優先篩選區、指標區、工具欄、表格卡片。
- 管理端頁面禁止新增獨立“頭部説明卡片”。

驗證：

```bash
cd frontend-admin-v3
pnpm run build
```

## 4. `frontend-user-v3-www`

目錄約定：

- `src/main.js`：應用入口。
- `src/app/`：bootstrap、router、runtime、stores 等核心能力。
- `src/api/`：站點與用戶 API。
- `src/pages/website/`：官網頁面路由入口；實現主體在 `src/views/website/`，`pages` 下多為轉發殼。
- `src/pages/common/`：通用頁面（當前為 `NotFound.vue`）。
- `src/views/website/`：官網頁面實現主體（首頁、產品、產品詳情、內容、SEO 落地頁）。
- `src/domains/`：領域邏輯（當前僅 `products`）。
- `src/assets/styles/`：Sass token、全局樣式、Element Plus 樣式入口。

實現約束：

- UI 使用 Element Plus 和 `@element-plus/icons-vue`。
- 本應用是純官網門戶，不含登錄註冊與用戶中心；認證與控制台頁面在 `frontend-user-v4-console`。
- 官網首頁與產品頁可以有更強視覺表現。
- 購買、結算、優惠券、恢復下單優先複用 `src/domains/products/*` 與現有 composables。
- SEO 由後端 Laravel 動態渲染（`backend/app/Services/Site/SeoRenderService.php`）：公開頁面 head meta、JSON-LD 與正文快照由後端讀數據庫生成，前端構建不再做 prerender/sitemap/robots 靜態生成（`scripts/generate-sitemap.mjs`、`scripts/prerender-www.mjs` 已移除）；頁面路由 meta 仍用於 SPA 運行時的 `document.title` 等前端同步。
- 新增強公開頁面時，同步在後端 `SeoRenderService::resolvePage()` 登記路徑與 meta，避免前端路由與 SEO 渲染口徑不一致。

驗證：

```bash
cd frontend-user-v3-www
pnpm run build
pnpm run test:e2e
pnpm run verify:refactor
```

只做小改時至少執行 `pnpm run build`；涉及重構或共享邏輯時追加 `verify:refactor`；改動頂欄下拉、加載態、鍵盤交互等可迴歸行為時追加 `test:e2e`（Playwright，用例自帶接口攔截，不依賴後端與數據庫）。

## 5. `frontend-user-v4-console`

目錄約定：

- `src/main.ts`：應用入口。
- `src/permission.ts`：路由守衞。
- `src/router/`：控制台路由。
- `src/store/`：Pinia 狀態。
- `src/pages/client/`：控制台頁面。
- `src/domains/`：賬戶、財務、服務、工單、內容、營銷、工具等領域邏輯。
- `src/composables/`：控制台組合邏輯。
- `src/api/`：用戶端 API 封裝。
- `src/style/`：Less 樣式和 TDesign token。

實現約束：

- UI 只用 TDesign Vue Next。
- 圖標只用 `tdesign-icons-vue-next`。
- 優先複用 `shared/user-v3` 的 `PageScaffold`、`DataState`、`StatusTag`、彈窗/抽屜/佈局組件。
- 控制台頁面優先穩定信息架構，不做官網式 Hero、深色大屏或裝飾優先佈局。
- `/client/*` 控制台業務能力與後端 `/api/v2/client/*` 對齊。
- 財務記錄頁面（賬單、訂單、充值的列表和詳情頁）禁止使用統計/指標卡片。
- 頁面根元素使用 `padding: var(--td-comp-paddingTB-l) var(--td-comp-paddingLR-l)`；手機端所有頁面 padding 統一為 12px，禁止使用 `paddingLR-s` 或 `paddingTB-m`。

驗證：

```bash
cd frontend-user-v4-console
pnpm run build
pnpm run verify:refactor
```

只做小改時至少執行 `pnpm run build`；涉及重構或共享邏輯時追加 `verify:refactor`。

## 6. `shared`

主要導出：

- `@turaidc/shared/statusConfig`（狀態文案、顏色與標籤的唯一真源）
- `@turaidc/shared/runtime`
- `@turaidc/shared/content`
- `@turaidc/shared/user-v3`（含 `StatusTag.vue` 等共用組件）

規則：

- 狀態文案、顏色和標籤不要在頁面裡另寫一套。
- runtime 能力優先從 `shared/runtime` 或各端已有 runtime 封裝接入。
- 修改 `shared` 後至少執行：

```bash
pnpm run typecheck:shared
pnpm run test:shared
```

並按影響範圍執行對應前端 build。

## 7. 根 workspace 命令

```bash
pnpm run dev:admin-v3
pnpm run dev:user-v3-www
pnpm run dev:user-v4-console

pnpm run build:frontends
pnpm run typecheck:frontends
pnpm run test:frontends
pnpm run verify:frontends
```

根 `package.json` 只保留當前真實 workspace。新增前端項目時，必須同步更新 `package.json`、`package-lock.json`、`AGENTS.md`、`docs/develop/local-development.md` 和本規範。

## 關聯文檔

- [視覺與交互規範](design.md)：三端視覺 token 與交互邊界。
- [架構現狀説明](architecture.md)：前端在系統中的位置與運行方式。
- [本地啟動指南](local-development.md)：開發與構建命令。
