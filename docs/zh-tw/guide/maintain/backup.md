---
status: current
updated: 2026-10-16
owner: ops
source: guide/maintain/backup.md
sourceHash: ef00bed1b118b245a87b254046df61df3e752d0a0a70d36c405d08a8590c41f7
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/maintain/backup.md 產生，請勿手動編輯。 -->
# 備份與恢復

本文面向負責生產伺服器的站長與運維，說明 TuraIDC 需要備份的物件、倉庫裡現成的備份指令碼與保留策略、恢復流程與演練要點，以及如何證明一份備份真的可用。備份是升級與排障的前置條件，升級操作見[版本升級](upgrade.md)。

## 1. 備份物件清單

四類物件缺任何一類都無法完整恢復一個站點。外部整合（支付渠道、上游供應商）的配置與外掛金鑰存放在資料庫裡，隨資料庫備份一起保全，不需要單獨匯出。

| 物件           | 位置                                               | 丟失後果                                                              |
| -------------- | -------------------------------------------------- | --------------------------------------------------------------------- |
| 資料庫         | MySQL 庫（庫名取 `backend/.env` 的 `DB_DATABASE`） | 訂單、服務、資金、使用者全部丟失                                        |
| 上傳與私有檔案 | `backend/storage/app/private/`                     | 工單上游附件、日誌歸檔檔案、外掛私有檔案丟失                          |
| 站點媒體與上傳 | `backend/public/uploads/`、`backend/public/media/` | 工單附件、Logo、首頁 Banner 圖與影片丟失                              |
| 配置           | `backend/.env`                                     | 全部整合憑據與 `APP_KEY` 丟失；`APP_KEY` 丟失等於已加密欄位永久讀不出 |
| 前端產物       | 三個前端目錄的 `dist/`                             | 站點打不開；可重建，但依賴構建機與正確的 `backend/.env`               |

`.env` 屬於憑據檔案，備份後必須按憑據管理：加密或離線存放、許可權 `600`、不進入任何 Git 倉庫。備份的**存放位置**不能與站點同盤同機：倉庫指令碼落在站點目錄下的 `storage/backups/`，整機故障時會與業務資料一起丟失，需要自行同步到異地。

## 2. 資料庫備份指令碼

倉庫提供兩個現成指令碼，按部署方式二選一。

### 2.1 原始碼 / 寶塔部署

```bash
cd /www/wwwroot/你的项目/backend
bash scripts/backup_mysql.sh
```

`backend/scripts/backup_mysql.sh` 的行為（以原始碼為準）：從 `backend/.env` 讀取 `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`；用臨時 `defaults-extra-file` 傳遞憑據（密碼不出現在程序列表），執行完立即刪除；呼叫 `mysqldump --single-transaction --routines --triggers --set-gtid-purged=OFF` 並 `gzip` 落盤；校驗檔案非空，空檔案會被刪掉並以非零碼退出；最後清理過期檔案並列印路徑。

| 環境變數                | 預設值                          | 說明                                   |
| ----------------------- | ------------------------------- | -------------------------------------- |
| `BACKUP_DIR`            | `backend/storage/backups/mysql` | 備份目錄                               |
| `BACKUP_RETENTION_DAYS` | 14                              | 保留天數，按檔案 mtime 清理 `*.sql.gz` |

檔名形如 `<库名>_20261016_030000.sql.gz`。兩個變數在呼叫前用環境變數覆蓋，不必改指令碼。定時執行：寶塔計劃任務 → Shell 指令碼，每天凌晨 3:00，指令碼內容同上。

### 2.2 Docker / 1Panel 部署

```bash
cd /opt/turaidc/deploy/docker
./backup.sh            # 默认保留 14 天
./backup.sh 30         # 保留 30 天
```

該指令碼在 mysql 容器內執行 `mysqldump`（複用容器環境的 root 口令，宿主機不存口令），用 `pipefail` 保證 dump 失敗不會產生截斷檔案，並用 `gzip -t` 校驗完整性：**校驗失敗會保留檔案並退出**，且不清理舊備份。檔案落在 `deploy/docker/backups/turaidc-<时间戳>.sql.gz`。宿主機 crontab：

```cron
0 3 * * * cd /opt/turaidc/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
```

Docker 命名卷裡的上傳與媒體資料不在指令碼範圍內，需要單獨備份（見第 3 節）。

## 3. 檔案類備份

倉庫**沒有**現成的檔案備份指令碼，以下為手工命令（未在程式碼中確認存在自動化替代）：

```bash
cd /www/wwwroot/你的项目
TS=$(date +%Y%m%d-%H%M%S)
mkdir -p /backup/turaidc

# 私有文件：工单上游附件、日志归档数据
tar -czf /backup/turaidc/storage-app-$TS.tar.gz -C backend/storage app

# 站点媒体与上传
tar -czf /backup/turaidc/public-files-$TS.tar.gz -C backend/public uploads media

# 配置：单独加密存放，不要与站点数据同盘
cp backend/.env /backup/turaidc/env-$TS.bak && chmod 600 /backup/turaidc/env-$TS.bak

# 前端产物：发布前打一次即可，产物可重建
tar -czf /backup/turaidc/dist-$TS.tar.gz \
  frontend-user-v3-www/dist frontend-user-v4-console/dist frontend-admin-v3/dist
```

`backend/storage/logs/` 與應用日誌不需要長期備份；日誌歸檔資料在 `backend/storage/app/private/log-archives/`，隨 `storage/app` 一起打包，歸檔策略見[日誌歸檔與 MySQL 日誌維護](log-archive.md)。Docker 部署對應三個命名卷（`app-storage`、`app-uploads`、`app-media`），可用容器匯出，卷名以 `docker volume ls` 實際輸出為準（compose 專案名會作為字首）：

```bash
docker run --rm -v turaidc_app-storage:/data -v /backup/turaidc:/out alpine \
  tar -czf /out/app-storage-$TS.tar.gz -C /data .
```

## 4. 保留策略

| 物件           | 建議保留                                               | 依據                      |
| -------------- | ------------------------------------------------------ | ------------------------- |
| 資料庫每日備份 | 最近 14 天（指令碼預設），關鍵釋出視窗額外留一份手動快照 | `BACKUP_RETENTION_DAYS`   |
| 資料庫月備份   | 保留 6–12 個月，單獨目錄，不受指令碼自動清理影響         | 需要對賬、審計回溯時用    |
| 上傳與媒體檔案 | 每週全量 + 變更前一次                                  | 附件只能從備份找回        |
| `.env`         | 每次變更後留一份，長期保留最近 5 份                    | 配置回滾與 `APP_KEY` 追溯 |
| 前端 `dist`    | 每次釋出前一份，保留最近 5 份                          | 釋出回滾                  |

指令碼的自動清理只按 mtime 刪除自身目錄裡的檔案，不會碰其他目錄。把月備份放進獨立目錄，就不會被日常清理帶走。

## 5. 恢復流程與演練

### 5.1 隔離庫演練

恢復的正確順序是**先在隔離庫演練，再動生產**。不要反覆向同一臨時庫匯入；先建新庫再匯入，失敗就直接換一個庫名重來。

```bash
# 1. 建一个与生产无关的复原库
mysql -u root -p -e "CREATE DATABASE idc_restore_drill_20261016 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. 导入
gunzip -c /backup/turaidc/idc_20261016_030000.sql.gz | mysql -u root -p idc_restore_drill_20261016

# 3. 比对表数量与关键财务行数（表清单以当前库为准）
mysql -u root -p -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='idc_restore_drill_20261016';"
mysql -u root -p -N -e "SELECT COUNT(*) FROM idc_restore_drill_20261016.migrations;"
```

匯入失敗時優先看報錯行號與表名，而不是立刻換備份。曾出現過匯入在第 715 行失敗的記錄：同一份 dump 換成原生 `mysql` 客戶端即可完整匯入，說明失敗出在匯入路徑/客戶端而非 dump 本身（原始記錄屬倉庫維護者資料，不在本文連結）。

演練頻率至少每季度一次，或在每次升級前做一次最小演練（只驗證匯入與表數量）。記錄寫清備份檔案、時間戳、校驗值、匯入結果與差異清單，**差異為零才算通過**；沒有做過恢復演練的備份只能算「檔案存在」，不算「可恢復」。

### 5.2 生產還原

只有在確認備份可用、明確接受資料截止時刻的前提下才執行。還原前先停掉會寫入的動作：

```bash
# 1. 冻结调度：注释掉计划任务中的 schedule:run 行（宝塔计划任务页 / /etc/cron.d/turaidc）
# 2. 对当前（故障）库再备份一次，保留现场，不要直接覆盖
bash scripts/backup_mysql.sh

# 3. 还原
gunzip -c /backup/turaidc/idc_<确认过的时间戳>.sql.gz | mysql -u <用户> -p <库名>

# 4. 恢复调度：取消第 1 步的注释，等 1-2 分钟让心跳与队列重新跑起来
```

還原範圍限於資料庫。檔案類還原在站點目錄內解包即可（先備份當前目錄再解包）：

```bash
tar -xzf /backup/turaidc/storage-app-$TS.tar.gz -C backend/storage
tar -xzf /backup/turaidc/public-files-$TS.tar.gz -C backend/public
```

`.env` 的還原可能改變 `APP_KEY`。若與故障前不同，已加密欄位（身份證等）將無法解密——這也是 `.env` 必須與資料庫備份**同批次**儲存的原因。修改 `.env` 後需要 `php artisan config:clear && php artisan config:cache`。演練要跑完整鏈路：建庫 → 匯入 → 核對表/行數 → 指向應用（用隔離環境，不要改生產 `.env`）→ 登入管理端看訂單與資金頁。

## 6. 備份有效性如何自證

按四層遞進判斷，越往後越可信：**檔案非空**（指令碼已自動做，空檔案會被刪除並非零退出）；**壓縮包完整**（原始碼部署可自行補 `gzip -t`，Docker 指令碼已內建；能發現截斷，發現不了內容缺失）；**內容可比對**；**能真還原**（第 5 節的隔離庫匯入加行數比對，這是唯一算數的證據）。

```bash
gunzip -c idc_20261016_030000.sql.gz | grep -cE '^CREATE TABLE'
gunzip -c idc_20261016_030000.sql.gz | grep -oE 'INSERT INTO `(orders|services|users)`' | sort | uniq -c

# 每次备份后记录校验值与大小，形成可追溯台账
cd backend/storage/backups/mysql && sha256sum *.sql.gz > SHA256SUMS-$(date +%Y%m%d).txt && ls -lh --time-style=long-iso
```

監控與告警：把備份指令碼的退出碼接入現有告警渠道（如寶塔計劃任務的日誌/郵件），失敗必須有人知道；`backend/scripts/production_preflight.mjs` 只檢查配置與站點可達性，**不檢查備份**（未在程式碼中確認有備份巡檢任務）。

## 7. 常見錯誤

| 現象                      | 原因                                                                                        | 處理                                                                                 |
| ------------------------- | ------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| 指令碼報 `.env 文件不存在`  | 不在 `backend` 目錄執行或 `.env` 被移動                                                     | 確認 `backend/.env` 存在且屬主可讀                                                   |
| 備份檔案是 0 位元組         | `mysqldump` 失敗（憑據、許可權、磁碟滿）                                                      | 看指令碼 stderr；確認資料庫賬號有 `SELECT`、`LOCK TABLES`、`SHOW VIEW`、`TRIGGER` 許可權 |
| 備份後目錄沒有新檔案      | 計劃任務裡的 `cd` 路徑不對，或 CLI PHP 不可用                                               | 用絕對路徑，並把 stderr 落到日誌檔案而不是 `/dev/null`                               |
| 舊備份沒被清理            | `BACKUP_DIR` 被環境變數指到別處                                                             | 列印實際路徑核對；指令碼只清理 `*.sql.gz`                                              |
| 還原後系統報解密失敗      | `APP_KEY` 與備份資料不是同一批次                                                            | 從與資料庫同批次的 `.env` 備份恢復                                                   |
| 匯入到一半報語法/編碼錯誤 | 客戶端版本或字元集不匹配（用 MySQL 8.0 的 mysqldump 備份 5.7 需加 `--column-statistics=0`） | 換原生客戶端重試，保留報錯行號作為證據                                               |

## 關聯文件

- [版本升級](upgrade.md)：升級前的備份要求與失敗回滾路徑。
- [故障排查](../troubleshooting.md)：磁碟與日誌暴漲、資料庫無法連線等症狀定位。
- [部署與排程指南](../deploy/scheduling.md)：指令碼呼叫方式與排程配置。
- [日誌歸檔與 MySQL 日誌維護](log-archive.md)：日誌歸檔目錄與 Binlog 過期策略。
