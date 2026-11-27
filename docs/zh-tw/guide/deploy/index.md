---
source: guide/deploy/index.md
sourceHash: f80a3303eb7dfb41a858704df620e3e88ef2c4a4d71f115460702c3b99e4b001
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/deploy/index.md 產生，請勿手動編輯。 -->
# 部署安裝

<DeploymentSelector />

## 部署後

1. **確認依賴服務**：確保應用、資料庫正常執行。
2. **啟用排程**：根據 [部署與排程](scheduling.md) 設定計劃任務、佇列組與探針，並配置 VNC Relay 常駐程序。
3. **自檢**：在 `backend/` 下執行 `php artisan app:doctor`，確認 PHP、擴充套件與停用函式滿足要求。
4. **測試後上線**：使用測試賬戶對「下單 → 支付 → 自動開通 → 通知」進行測試。

## 必要條件

- 資料庫最低相容 MySQL 5.7.44，推薦 8.3以上；SQL 必須同時相容兩個版本。
- 生產不使用 `php artisan serve`，後端使用 PHP-FPM。

## 關聯文件

- [初始配置](../configure/index.md)：配置站點、通知、支付與上游。
- [維護與升級](../maintain/index.md)：備份、升級與日誌歸檔。
- [開發者文件](../../develop/index.md)：本地開發、前端構建與測試命令。
