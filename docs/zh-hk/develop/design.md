---
status: current
updated: 2026-09-13
owner: frontend
source: develop/design.md
sourceHash: d4380e9ac146f409f44174d950c74d16aab9210af2c9eecebc1722cfee81ab36
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/develop/design.md 產生，請勿手動編輯。 -->
# 視覺與交互規範

本文是三端視覺 token、佈局與交互的當前約束；實現與本文衝突時，按工作規則處理並回寫本文。視覺基線是淺色企業級雲服務控制台 + 清爽官網，不做深色大屏後台。

## 1. 總體定位

當前前端分工：

- `frontend-admin-v3`：管理端，TDesign Vue Next。
- `frontend-user-v3-www`：官網門戶（首頁、產品展示與購買、公告、幫助中心），Element Plus。
- `frontend-user-v4-console`：新版用戶控制台（含登錄註冊與用戶中心），TDesign Vue Next。

## 2. 通用視覺 Token

- 品牌主色：`#165DFF`
- 主色 hover：`#0E4FCC`
- 頁面背景：`#F5F7FB`
- 二級背景：`#F8FAFC`
- 卡片背景：`#FFFFFF`
- 邊框：`#E5EAF3`
- 分割線：`#EEF2F7`
- 主文本：`#1F2937`
- 次文本：`#5B6B82`
- 佔位文本：`#94A0B2`
- 成功：`#12B76A` / 淺底 `#EAFBF3`
- 警告：`#F59E0B` / 淺底 `#FFF6E5`
- 危險：`#F04438` / 淺底 `#FFF1F0`

排版使用系統字體棧：`Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif`。

## 3. 佈局原則

- 控制台頁面以淺灰背景承載白色內容區，信息優先於裝飾。
- 頁面區塊間距按 8px 柵格，常用間距 `16px` / `20px` / `24px`。
- 卡片只用於承載獨立業務內容、重複項、彈窗和表單區塊，不把頁面 section 層層套卡片。
- 狀態統一用淺底標籤，不直接使用高飽和純色文本。
- 表格表頭用淺灰底，行高保持穩定，金額列可右對齊。
- 按鈕高度常用 `36px` - `40px`；主操作藍底白字，次操作白底描邊。

## 4. 管理端 `frontend-admin-v3`

- 使用 TDesign 組件、TDesign Icons、TDesign Starter 佈局和 `src/style/` Less 體系。
- 普通管理列表頁不要新增説明型頁頭大卡片，不做 `Hero + 三卡片` 公式。
- 列表頁從篩選、指標、工具欄或表格卡片開始；詳情頁的返回、保存、刷新放緊湊工具欄。
- 表單頁必須有校驗、提交 loading、禁用態和錯誤反饋。
- 權限、路由、菜單、麪包屑、用戶菜單沿用現有 layout/store/router 模式。
- 禁止混入 Element Plus 組件、圖標和樣式。

## 5. 官網門戶 `frontend-user-v3-www`

- 使用 Element Plus 與 `src/assets/styles/variables.scss`、`global.scss`、`element/index.scss`。
- 本應用是純官網門戶，登錄註冊與用戶中心在 `frontend-user-v4-console`。
- 官網首頁、產品頁可以有更強視覺表達，但要保持可讀、真實、業務導向。
- 購買和結算流程不要改成營銷頁佈局，優先清晰的配置、價格、優惠和確認狀態。

## 6. 用戶控制台 `frontend-user-v4-console`

- 使用 TDesign 組件、TDesign Icons、`src/style/` Less 體系和 `shared/user-v3` 控制台組件。
- 控制台頁面重視掃描效率：清晰導航、穩定列表、明確狀態、緊湊操作。
- 詳情、賬單、服務控制台、工單聊天等頁面優先複用 `PageScaffold`、`DataState`、`StatusTag`、抽屜/彈窗組件。
- 禁止官網式大 Hero、深色大屏、重漸變背景和純裝飾卡片。

## 7. 圖標與素材

- TDesign 端：只用 `tdesign-icons-vue-next`。
- Element Plus 端：只用 `@element-plus/icons-vue`。
- 不用 emoji 代替功能圖標。
- 品牌與公共素材優先放 `public/branding/` 或按語義歸類到 `public/img/`。

## 8. 禁止項

- 禁止把後台默認做成深色大屏風格。
- 禁止在普通列表頁使用官網式 Hero 橫幅。
- 禁止整頁鋪滿高飽和漸變、玻璃擬態、發光裝飾。
- 禁止同一頁面混用多套圓角、陰影、主色和 UI 框架。
- 禁止把業務頁面做成只展示説明文案的營銷卡片。

## 關聯文檔

- [前端項目規範](frontend.md)：三端工程約束與驗證命令。
- [架構現狀説明](architecture.md)：系統結構與運行模型。
