---
source: guide/deploy/index.md
sourceHash: ceee0050b1ed01fc7d0608ad966805a2c7c6e27b45c09dddc16fcbfb2449d7ee
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/guide/deploy/index.md 產生，請勿手動編輯。 -->
# 部署安裝

把 TuraIDC 部署到生產環境。先按下表選擇與當前伺服器和運維流程匹配的方式，完成部署後再照[部署與調度](scheduling.md)配置計劃任務、隊列與就緒檢查——漏掉這一步，自動開通、自動續費與通知都不會執行。

<DeploymentSelector />

## 1. 選哪種方式

| 方式                                        | 適用場景                                                   |
| ------------------------------------------- | ---------------------------------------------------------- |
| [通用部署](generic.md)                      | 不綁定面板或容器：按環境要求手工完成後端、前端與進程守護   |
| [寶塔面板部署](bt-panel.md)                 | 伺服器上已有寶塔面板管理 PHP、Nginx 與計劃任務             |
| [裸機源碼部署](bare-metal.md)               | 無面板、無容器，以 nginx + PHP-FPM + systemd + cron 原生運行 |
| [Docker 與 1Panel](docker-1panel.md)        | Docker Compose 部署，或用 1Panel 統一管理容器               |

## 2. 部署後必做

1. **確認依賴服務**：應用、數據庫與 Redis 都正常運行；Redis 在生產是必需的（分佈式鎖與快取）。
2. **配好調度**：按[部署與調度](scheduling.md)設置每分鐘一次的計劃任務、隊列組與心跳探針，並配置 VNC Relay 常駐進程。
3. **自檢環境**：在 `backend/` 下執行 `php artisan app:doctor`，確認 PHP 版本、必需擴展與禁用函數都滿足要求。
4. **跑一次真實鏈路**：用一筆小額訂單走通「下單 → 支付 → 自動開通 → 通知」。

## 3. 相關約束

- 數據庫最低兼容 MySQL 5.7.44，推薦 8.x；SQL 必須同時兼容兩個版本。
- 生產不使用 `php artisan serve`，後端入口走 PHP-FPM。
- 版本升級只跑新增遷移，絕不刪表、刪列、刪索引。

## 關聯文檔

- [初始配置](../configure/index.md)：部署完成後配置站點、通知、支付與上游。
- [維護與升級](../maintain/index.md)：備份、升級與日誌歸檔。
- [開發者文檔](../../develop/index.md)：本地開發、前端構建與測試命令。
