---
status: current
updated: 2026-10-16
owner: ops
source: guide/troubleshooting.md
sourceHash: af19ad9541b58f68986af61dc3caae0115d360af13c33308db8ac1cb13587712
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/guide/troubleshooting.md 產生，請勿手動編輯。 -->
# 故障排查

本文面向負責生產伺服器的站長與運維，按「症狀 → 先查什麼 → 常見根因 → 處理 → 如何確認恢復」組織常見故障的定位路徑。本文只覆蓋排障；日常升級與備份操作見[版本升級](maintain/upgrade.md)與[備份與恢復](maintain/backup.md)。

> 排障前先記住三個前提：生產站點是**四端獨立**部署（官網 / 控制台 / 管理端靜態站點 + API 站點），瀏覽器直連 API 域名；隊列與定時任務由每分鐘一次的 `schedule:run` 驅動，沒有常駐 Worker；命令中的 `php` 必須是與 API 站點相同的 PHP CLI。以運行代碼、日誌和 `/api/ready` 輸出為準，不要憑印象判斷。

## 1. 站點打不開或白屏

```bash
curl -I https://你的官网域名/          # 看 HTTP 状态码，而不是只看浏览器
curl -s -o /dev/null -w '%{http_code}\n' https://你的API域名/api/health
tail -50 /www/wwwroot/你的项目/backend/storage/logs/laravel.log
```

瀏覽器按 F12 看 Console 與 Network：靜態資源 404、接口 CORS/404、`pageerror` 三條線索指向完全不同的問題。

| 現象                                                                        | 根因                                                                                                    | 處理                                                                                       |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| 頁面空白，Console 有 `ReferenceError: t is not defined` 之類 setup 階段報錯 | 前端發佈產物有問題（歷史上出現過 SFC 裡游離 import 導致整個組件掛載失敗）。構建、類型檢查都不會報這種錯 | 用上一次發佈前的 `dist` 備份回滾，重新構建並複測；參考[版本升級](maintain/upgrade.md)      |
| 只有刷新某個路由才 404                                                      | 該 SPA 站點沒配 History 回退                                                                            | 補 `try_files $uri $uri/ /index.html;`，見[四端 Nginx 偽靜態配置](maintain/nginx-rules.md) |
| 頁面能開但數據全是空的、接口 404/403                                        | 產物裡的 API 地址與後端真源不一致，或 CORS 未放行                                                       | 核對 `backend/.env` 的四個公開地址，用 `grep -ro` 校驗產物                                 |
| 官網首頁沒有標題/正文、meta 是空殼                                          | 官網 SEO 動態渲染的轉發規則或模板地址失效                                                               | 見[四端 Nginx 偽靜態配置](maintain/nginx-rules.md)與[部署與調度指南](deploy/scheduling.md) |
| 官網 `index.html` 正常但樣式/JS 全 404                                      | 站點根目錄或「運行目錄」配置錯誤                                                                        | 寶塔站點「網站目錄」填應用目錄、「運行目錄」填 `/dist`                                     |

**如何確認恢復**：四個公開地址各自返回 200；控制台與管理端刷新任意路由都不 404；官網首頁 HTML 的 `title` 是站點名（不是默認殼）。

## 2. HTTP 502 與 PHP 版本、禁用函數

```bash
# 站点错误日志（宝塔：网站 → 站点 → 日志；裸机：/var/log/nginx/）
tail -50 /www/wwwlogs/你的API站点.error.log
php -v && php -m | grep -iE 'pdo_mysql|redis|openssl|mbstring|fileinfo|json'
```

- **PHP-FPM 未運行，或 API 站點沒選中可用的 PHP 版本**（寶塔 → 軟件商店 → PHP → 狀態）：在站點「PHP 版本」裡重新選擇並重啟對應 PHP 服務。不要手工改寶塔生成的 `fastcgi_pass` socket 路徑。
- **PHP 版本低於最低要求**：最低版本的真源是 `backend/app/Support/EnvironmentRequirements.php`（PHP 8.3.0）。裝 8.2 會在環境檢測環節被直接攔下。
- **`putenv`、`proc_open`、`symlink` 被 `disable_functions` 禁用**：這是最高頻的部署故障，寶塔默認禁用這三個。表現為 Composer 報 `Call to undefined function putenv()`，或應用讀取 `.env`、調用外部進程時失敗。處理：寶塔 → 軟件商店 → PHP → 禁用函數，刪掉這三個後重啟 PHP。
- **PHP-FPM 進程池被打滿或超時**：`Request Timeout` / `upstream timed out` 説明是慢請求而不是進程掛掉，去 `laravel.log` 與慢查詢日誌找具體請求。
- **`open_basedir` 限制了站點目錄**：容器化部署下代碼路徑變化會讓全部文件操作被拒（`deploy/docker/backend/entrypoint.sh` 會在容器啟動時重寫 `public/.user.ini`）。

**如何確認恢復**：`curl -I` 返回 200 或業務狀態碼（不再是 502/504）；`php artisan app:doctor` 通過；站點錯誤日誌不再出現 `connect() to unix:/... failed`。

## 3. app:doctor 報錯

先執行 `cd /www/wwwroot/你的项目/backend && php artisan app:doctor`（`--all` 附帶列出建議安裝但不阻斷的擴展）。命令返回非零退出碼，表示**必選項**未通過，輸出裡會逐條列出未通過項與當前 `disable_functions`。

| 未通過項                                                            | 處理                                                                                                                       |
| ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `PHP 版本 >= 8.3.0`                                                 | 升級 PHP，或切換站點與 CLI 到已裝的 8.3+                                                                                   |
| `PHP 扩展 pdo_mysql / redis / openssl / mbstring / fileinfo / json` | 在面板安裝對應擴展並重啟 PHP（必需清單以 `EnvironmentRequirements::REQUIRED_EXTENSIONS` 為準）                             |
| `可调用函数 putenv / proc_open / symlink`                           | 從 `disable_functions` 中刪除後重啟 PHP；被禁用時 `function_exists()` 仍返回 true，只有 `disable_functions` 列表是可靠信號 |

注意 `app:doctor` 檢測的是**當前進程**的 PHP：CLI 通過不代表 FPM 通過，兩者配置可能不同。

**如何確認恢復**：命令輸出「環境檢測通過」且退出碼為 0。

## 4. 隊列與心跳不跑（定時任務沒配）

**症狀**：已付款訂單一直不開通；自動續費、到期停服、賬單逾期都不執行；通知不入隊；管理端「自動任務日誌 → 定時任務」長時間沒有新記錄。

```bash
cd /www/wwwroot/你的项目/backend
php artisan scheduler:liveness      # 心跳活着吗
php artisan schedule:run            # 手动触发一次，看有没有报错
php artisan queue:monitor provision,referral,notification,coupon,default,automation
```

管理端：`/admin/logs/schedules`（定時任務）、`/admin/logs/tasks`（自動任務日誌）。平台層的 `/api/ready` 會分別報告 `scheduler`、`scheduler_liveness`、`task_readiness`、`queue`。

1. **計劃任務根本沒配或沒真正執行**。唯一需要的是每分鐘一次：

   ```cron
   * * * * * cd /www/wwwroot/你的项目/backend && php artisan schedule:run >> /dev/null 2>&1
   ```

   若 `cd` 路徑不對、或 CLI PHP 不在 cron 的 PATH 裡，命令會靜默失敗（錯誤被 `>> /dev/null 2>&1` 吞掉）。寶塔計劃任務默認 PATH 與站點不同，`php` 可能指向另一個版本甚至不存在，先把輸出改到日誌文件排查。

2. **誤配了常駐 `queue:work`**：`schedule:run` 已每分鐘後台驅動 `queue:drain` 消費全部隊列組，再掛常駐 Worker 會形成雙消費者。不要在保留 `queue:drain` 的同時覆蓋同一隊列。
3. **`QUEUE_CONNECTION` 不是 `database`**：`queue:drain` 會直接跳過（返回 `queue_driver_not_database`），表現為隊列靜默不執行。核對 `backend/.env` 的 `QUEUE_CONNECTION`、`TURAIDC_PROVISION_QUEUES`、`TURAIDC_BUSINESS_QUEUES`、`TURAIDC_SCHEDULE_QUEUE`。
4. **`jobs` 表不存在**（drain 返回 `jobs_table_missing`）或 **Worker 被 OOM/強殺留下殘鎖**：前者確認遷移已執行；後者 drain 會按存活心跳判定殘鎖並強制釋放，`laravel.log` 會出現「檢測到隊列 Worker 殘鎖」，反覆出現要先查記憶體與系統日誌。
5. **任務堆積**：按隊列邊界手動消費一次，不要起常駐 Worker。

   ```bash
   php artisan queue:work --once --queue=provision
   php artisan queue:work --once --queue=referral,notification,coupon,default
   php artisan queue:work --once --queue=automation
   php artisan queue:failed            # 列出失败任务
   php artisan queue:retry <id>        # 重试指定失败任务
   ```

**如何確認恢復**：`scheduler:liveness` 輸出「心跳正常」；`/api/ready` 四個相關項為 true；手動跑一次 `schedule:run` 後「定時任務」頁出現新的成功記錄；`queue:monitor` 的待處理數為 0 或持續下降。

## 5. 郵件發不出去

- 管理端「日誌中心 → 郵件日誌」（`/admin/logs/email`），看是根本沒生成記錄、還是生成了但狀態失敗。記錄裡有 `plugin_id`、`driver_key`、`trace_id`。
- 「插件管理 → 郵件發送」（`/admin/integration-plugins/mail`）的插件是否已安裝、配置並綁定。

**關鍵區分**：後台的**測試發信走驅動直髮，不受總開關約束**。所以「測試郵件成功、用戶卻收不到驗證碼」幾乎一定是總開關沒開，而不是 SMTP 壞了。對外是否真的會發郵件的唯一判定源是 `notification.email_enabled`（`NotificationService::isEmailEnabled()`），在「站點設置 → 通知總開關」裡開。

其他常見根因：`MAIL_MAILER=log`（默認值之一，郵件只寫日誌不真發，生產必須由郵件插件接管驅動配置）；郵件模板缺失或未啟用（模板種子由 `SettingsSeeder` 冪等寫入 `notification_templates`，模板數據不在 schema baseline 裡）；發件人被收件方拒收（核對發件域名的 SPF/DKIM，屬郵件服務商側配置，本系統內未在代碼中確認有發信域校驗能力）。

**如何確認恢復**：管理端觸發一次真實的註冊/找回密碼流程，郵件日誌出現 `status` 成功的記錄，收件箱收到郵件。

## 6. 上游開通失敗

**症狀**：用戶已付款，服務停在「開通中」，`provision_data` 裡帶 `provision_error`；或控制台/管理端操作提示上游失敗。

```bash
cd /www/wwwroot/你的项目/backend
php artisan provision:retry-failed                     # 默认 dry-run，只扫描并告警
php artisan provision:retry-failed --limit=10 --max-retries=3
```

命令會列出孤兒單的 `service_id`、`order_id`、`retry_count` 與截斷後的 `provision_error`。

- 上游插件未安裝或未配置：管理端「插件管理 → 上游開通」（`/admin/integration-plugins/upstream`）核對綁定與憑據。
- 上游地址/憑據變更，或上游側服務異常：先用 `provision_error` 裡的原文定位到具體接口。上游可能是純 HTTP 或證書鏈不完整，**不要**因為證書問題就去改校驗邏輯。
- 任務堆積在 `provision` 隊列：見第 4 節，先確認隊列在消費再談重試。
- 重試本身有風險：開通鏈路是「清空購物車 → 加購 → checkout → 取 host_id」，缺少上游冪等鍵回查。若上次失敗發生在 checkout 成功之後，自動重試可能造成**重複開通**。因此 `provision:retry-failed` 默認 dry-run，`--execute` 由管理員在核實上游實際開通狀態後決定。

**如何確認恢復**：`php artisan provision:retry-failed` 輸出「未發現上游開通失敗孤兒單」；管理端「工單推送日誌」/服務詳情顯示開通成功；數據庫內該服務狀態不再停留 `PENDING`。

## 7. 磁盤與日誌暴漲

```bash
df -h
du -sh /www/wwwroot/你的项目/backend/storage/* 2>/dev/null | sort -h | tail
ls -lhS /var/lib/mysql/ | head    # 含 binlog
```

| 佔用源                                                   | 處理                                                                                                                                                                                            |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| MySQL 備份目錄                                           | 腳本默認保留 14 天；用 `BACKUP_RETENTION_DAYS` 調整或把月備份移到別的盤                                                                                                                         |
| Binlog                                                   | 生產 MySQL 8：`SET PERSIST binlog_expire_logs_seconds = 2592000;`（MySQL 5.7 用 `expire_logs_days`），見[日誌歸檔與 MySQL 日誌維護](maintain/log-archive.md)                                    |
| MySQL 錯誤日誌/慢查詢日誌                                | 配置 logrotate，見[日誌歸檔與 MySQL 日誌維護](maintain/log-archive.md)                                                                                                                          |
| 高頻業務日誌表（`operation_logs` 等 7 張 InnoDB 日誌表） | 先 `php artisan db:archive-logs --dry-run` 看影響行數，再 `--execute`；歸檔數據落在 `storage/app/private/log-archives/`，超 180 天的 `*.log` 會自動刪除，歸檔報告在 `storage/logs/log-archive/` |
| 應用日誌堆積                                             | `LOG_DAILY_DAYS`（默認 14）與 `LOG_LEVEL` 決定保留量；生產不要長期開 `debug`                                                                                                                    |
| 工單上游臨時上傳                                         | 已註冊每分鐘清理命令，可查「日誌中心 → 上傳/清理日誌」（`/admin/logs/upstream-uploads`）                                                                                                        |
| `failed_jobs` 累積                                       | `php artisan queue:failed` 查看，`queue:retry <id>` 重試，確認無價值後再 `php artisan queue:flush --hours=168`                                                                                  |
| 日誌歸檔目錄本身                                         | 歸檔數據不通過 `.env` 配置路徑，固定寫在 `storage/app/private/log-archives`，不支持 NAS 或外部目錄                                                                                              |

**如何確認恢復**：`df -h` 使用率回落並保持穩定；連續兩天觀察增速，確認增長來自業務量而不是泄漏。物理刪除不會立即縮小 InnoDB 的獨立 `.ibd` 文件，歸還空間要單獨安排維護窗口。

## 8. 權限 403 的常見原因

```bash
tail -30 /www/wwwlogs/你的站点.error.log          # 是 Nginx 拒绝还是应用返回
ls -ld /www/wwwroot/你的项目/backend /www/wwwroot/你的项目/backend/public
ls -l /www/wwwroot/你的项目/backend/.env
```

- **站點根目錄指向錯誤**：請求落到了不該暴露的目錄，或目錄本身不可讀。寶塔站點「網站目錄」應是應用目錄，不是 `dist`。
- **`.env` 權限不當**：必須嚴格限制（`chmod 600 .env`），屬主要為站點運行用戶；過寬是安全問題，過窄會讓應用讀不到配置。
- **`storage/`、`bootstrap/cache` 或上傳目錄（`public/uploads`、`public/media`）不可寫**：寫入失敗在部分場景表現為 403/500。
- **`disable_functions` 裡含 `symlink`**：`php artisan storage:link` 之類的符號鏈接操作失敗，靜態資源路徑取不到。
- **`open_basedir` 不包含站點目錄**：寶塔的「防跨站攻擊」會在站點根目錄寫 `.user.ini`（`chattr +i`）；容器化部署需重寫該文件指向真實代碼路徑。
- **Nginx `deny all` 規則命中**：配置裡常見的 `location ~ /\.(?!well-known)` 會拒掉點目錄訪問，這是預期行為，不要為繞過它放開隱藏文件。

```bash
cd /www/wwwroot/你的项目/backend
sudo chown -R www:www storage bootstrap/cache public/uploads public/media
sudo chmod -R 775 storage bootstrap/cache public/uploads public/media && sudo chmod 600 .env
```

**如何確認恢復**：`/api/ready` 的 `storage` 為 true；管理端上傳一張圖片或提交一次帶附件的工單成功；`laravel.log` 不再出現 `failed to open stream: Permission denied`。

## 關聯文檔

- [版本升級](maintain/upgrade.md)：升級流程、驗證與回滾路徑。
- [備份與恢復](maintain/backup.md)：備份對象、恢復演練與有效性自證。
- [部署與調度指南](deploy/scheduling.md)：調度、隊列與受信代理契約。
- [四端 Nginx 偽靜態配置](maintain/nginx-rules.md)：四端站點規則與 404 回退。
- [日誌歸檔與 MySQL 日誌維護](maintain/log-archive.md)：日誌與 Binlog 清理。
- [部署指南](deploy/generic.md)：環境要求與從零部署步驟，排查後重新上線時對照。
