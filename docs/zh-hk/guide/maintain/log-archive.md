---
source: guide/maintain/log-archive.md
sourceHash: 933468182b5ead1c3f3d2b4bcaa33dc4b1b2f2d6578c9ca56419cfffbeaef470
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/guide/maintain/log-archive.md 產生，請勿手動編輯。 -->
# 日誌歸檔與 MySQL 日誌維護

本文是生產環境日誌歸檔、MySQL 日誌輪轉和 Binlog 過期策略的操作真源。

## 1. 數據庫日誌歸檔

### 1.1 處理範圍

`db:archive-logs` 固定處理以下 7 張 InnoDB 日誌表（清單以 `backend/config/log_archive.php` 的 `tables` 為準）：

| 表                                | 內容                          |
| --------------------------------- | ----------------------------- |
| `operation_logs`                  | API、後台操作和管理員登錄日誌 |
| `activity_logs`                   | 系統與業務活動日誌            |
| `message_logs`                    | 短信、郵件統一消息日誌        |
| `automation_logs`                 | 自動化業務任務日誌            |
| `schedule_run_logs`               | Laravel 調度運行日誌          |
| `integration_plugin_runtime_logs` | 插件運行日誌                  |
| `gateway_logs`                    | 支付網關交互日誌              |

歸檔條件固定為：

```sql
created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
```

財務流水、賬單、支付、歸檔審計和業務快照不在此命令的處理範圍內。

### 1.2 文件位置與格式

歸檔目錄由後端固定管理：

```text
backend/storage/app/private/log-archives/
└── operation_logs/
    └── operation_logs_20260721.log
```

歸檔數據文件格式為：

```text
backend/storage/app/private/log-archives/{表名}/{表名}_YYYYMMDD.log
```

文件內容是帶表頭的 CSV。同一張表同一天重跑時，pt-archiver 追加到原文件且不重複寫表頭。執行報告和機器可讀運行日誌固定存放在 `backend/storage/logs/log-archive/`，不與歸檔數據混放。

超過 180 天的歸檔數據 `*.log` 會在正式歸檔結束後自動刪除。dry-run 不創建歸檔數據文件，也不執行歷史文件清理。

### 1.3 安裝與憑據

目標 Linux 伺服器必須安裝 Percona Toolkit，並先驗證：

```bash
/usr/bin/pt-archiver --version
```

創建 `/etc/turaidc/pt-archiver.cnf`：

```ini
[client]
host=127.0.0.1
port=3306
user=turaidc_archiver
password=替换为强密码
default-character-set=utf8mb4
```

```bash
sudo chown root:root /etc/turaidc/pt-archiver.cnf
sudo chmod 600 /etc/turaidc/pt-archiver.cnf
```

歸檔賬號只授予目標庫上述 8 張表的 `SELECT`、`DELETE` 權限。不要把密碼寫入 Crontab、命令參數、項目代碼或執行日誌。

歸檔目錄不通過 `.env` 或管理員後台配置：歸檔數據固定寫入 `storage_path('app/private/log-archives')`，報告固定寫入 `storage_path('logs/log-archive')`。不支持 NAS 掛載路徑或外部目錄。

管理員後台的“日誌歸檔”設置僅用於保留天數、pt-archiver 可執行文件與憑據文件路徑、併發數、批量大小和批次間隔；歸檔數據目錄與報告目錄不可編輯。

### 1.4 執行與斷點續傳

先預檢：

```bash
cd /www/wwwroot/turaidc/backend
/usr/bin/php artisan db:archive-logs --dry-run
```

小表試運行：

```bash
/usr/bin/php artisan db:archive-logs --execute --table=gateway_logs
```

正式執行全部表：

```bash
/usr/bin/php artisan db:archive-logs --execute
```

臨時調整限流：

```bash
/usr/bin/php artisan db:archive-logs --execute --concurrency=2 --batch-size=500 --sleep-seconds=2
```

程序使用 pt-archiver 的 `--limit`、`--commit-each`、`--sleep`、遞增主鍵掃描和 `--purge`。已提交批次已經寫入文件並從原表刪除，中斷後再次執行會繼續處理剩餘記錄。極端中斷可能讓最後一個未提交批次在文件中重複，但不會遺漏已經刪除的記錄；恢復或分析歸檔時按主鍵 `id` 去重。

每張表的成功、失敗、影響行數、文件大小、SHA-256 和工具退出碼會寫入：

- `archive_audit_logs`：數據庫內的歸檔審計；
- `backend/storage/logs/log-archive/run_*.json`：單次完整執行報告；
- `backend/storage/logs/log-archive/archive-YYYY-MM-DD.log`：逐事件 JSON 日誌。

### 1.5 平台定時任務

日誌歸檔註冊為平台核心定時任務 `log-archive`，Cron 表達式為 `0 2 * * *`。Laravel 的唯一調度入口仍是每分鐘執行的 `schedule:run`，它觸發 `scheduler:heartbeat`；心跳把到期歸檔任務投遞到默認隊列，並由既有任務運行記錄保存結果。

任務固定以 `--execute --json` 調用 `db:archive-logs`，最長運行 1 小時，任務鎖為 61 分鐘，以滿足現有數據庫隊列的可見性超時約束。歸檔服務自身仍保留全局文件鎖和每表 PID 文件，避免重疊歸檔同一張表。該任務不可從管理端手動觸發；人工操作必須使用本節的命令並遵循預檢流程。

生產只保留每分鐘一次的 `schedule:run`。切換後必須移除原來直接執行 `db:archive-logs --execute` 的每日 02:00 Crontab，不能讓兩種觸發方式同時運行。

## 2. MySQL 錯誤日誌與慢查詢日誌輪轉

先確認實際日誌路徑：

```sql
SHOW VARIABLES WHERE Variable_name IN ('log_error', 'slow_query_log', 'slow_query_log_file');
```

將查詢出的錯誤日誌和慢查詢日誌絕對路徑填入 `/etc/logrotate.d/turaidc-mysql`：

```text
/实际路径/mysql-error.log /实际路径/mysql-slow.log {
    su mysql mysql
    daily
    rotate 30
    compress
    dateext
    missingok
    notifempty
    create 0640 mysql mysql
    sharedscripts
    postrotate
        /usr/bin/mysqladmin --defaults-extra-file=/root/.my.cnf flush-logs > /dev/null 2>&1 || true
    endscript
}
```

`/root/.my.cnf` 必須由 root 持有、權限為 `0600`，並使用具備 `RELOAD` 權限的運維賬號；不要複用低權限歸檔賬號。

驗證配置：

```bash
sudo logrotate -d /etc/logrotate.d/turaidc-mysql
# 确认调试输出无错误后，在维护窗口验证一次实际轮转
sudo logrotate -f /etc/logrotate.d/turaidc-mysql
```

## 3. Binlog 過期策略

當前實庫為 MySQL 8.0，執行：

```sql
SET PERSIST binlog_expire_logs_seconds = 2592000;
SHOW GLOBAL VARIABLES LIKE 'binlog_expire_logs_seconds';
```

MySQL 5.7 使用以下配置，不要與 MySQL 8.0 參數同時配置：

```ini
[mysqld]
expire_logs_days=30
```

也可以先在線執行 `SET GLOBAL expire_logs_days = 30`，但仍需寫入 `my.cnf` 並在重啟後再次驗證。

## 4. 空間口徑與上線檢查

物理刪除會讓 InnoDB 表空間內部可複用，但獨立 `.ibd` 文件不保證立即縮小。本任務不自動執行 `OPTIMIZE TABLE`；確需向作業系統歸還空間時，使用管理端現有的智能優化能力並安排獨立維護窗口。

上線前依次確認：

1. pt-archiver 版本、憑據文件權限和歸檔目錄可寫；
2. 全表 dry-run 的影響行數符合預期；
3. `gateway_logs` 小表試運行後，CSV 表頭、行數、數據庫剩餘記錄和審計日誌一致；
4. 確認每分鐘 `schedule:run`、默認隊列消費和 `log-archive` 定時任務均正常，並觀察首輪完整報告；
5. `logrotate -d` 無錯誤，Binlog 過期變量為 2592000 秒。

## 5. 應用日誌（Laravel）輪轉

應用日誌和上面幾節的數據庫日誌是兩套東西，需要單獨確認。

線上出現過一個實例的 `backend/storage/logs/laravel.log` 長到 **1.2GB 單文件**，原因有三層，缺一層都不會這樣：

| 層     | 問題                                                                                       | 現在的口徑                                                                                      |
| ------ | ------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| `.env` | `LOG_STACK=single` + `LOG_LEVEL=debug`，`single` 通道寫死一個文件且永不切分                 | `.env.example` 已改為 `LOG_STACK=daily`，`LOG_LEVEL` 留空按環境推導（生產為 `info`）             |
| 代碼   | 上游接口每調一次就按 INFO 落一條完整響應摘要，定時任務每天數萬次，單條几 KB                 | 只有 4xx/5xx 或耗時 ≥ 2 秒才升級為 warning 並帶響應體，正常的 2xx 快響應降為 debug              |
| 系統   | 沒有任何 logrotate 規則管應用日誌                                                          | 倉庫內 [deploy/logrotate/turaidc](https://github.com/25Cloud/TuraIDC/blob/main/deploy/logrotate/turaidc) |

安裝兜底輪轉規則（即使有人把 `LOG_STACK` 改回 `single` 也不會無限增長）：

```bash
# 在仓库根目录执行，需要 root
install -m 644 deploy/logrotate/turaidc /etc/logrotate.d/turaidc
logrotate -d /etc/logrotate.d/turaidc     # 干跑，确认匹配到的文件清单
```

規則用 `copytruncate` 而不是 `create`：Monolog 的 StreamHandler 長期持有 append 句柄，不截斷原文件的話，進程會繼續往已改名的舊文件裡寫。

確認生效：

```bash
ls -lh backend/storage/logs/
du -sh backend/storage/logs/
```

期望結果是 `laravel-YYYY-MM-DD.log` 這類按天文件名，或 `laravel.log.1.gz` 這類輪轉產物；不再是單個無後綴的巨型 `laravel.log`。

> 排查上游接口時如果要看全量響應，臨時把 `LOG_LEVEL` 改成 `debug`，問題定位完立刻改回來——`debug` 下每一條上游調用都會落盤，一天可以產出上百 GB。

## 關聯文檔

- [部署與調度指南](../deploy/scheduling.md)：定時任務與隊列環境。
- [MySQL 版本兼容基線](../../develop/mysql-compatibility.md)：SQL 兼容性約束。
