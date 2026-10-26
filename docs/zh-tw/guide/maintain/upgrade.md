---
status: current
updated: 2026-10-16
owner: ops
source: guide/maintain/upgrade.md
sourceHash: 65a81737509953a27a6c5be081f5d729aaa7250b8f52d7f051682128f5162d83
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/maintain/upgrade.md 產生，請勿手動編輯。 -->
# 版本升級

本文面向負責生產伺服器的站長與運維，說明把已執行的 TuraIDC 升級到新版本的完整操作：升級前檢查、程式碼與依賴更新、遷移、前端重建、快取與佇列過載、驗證與失敗回滾。部署方式差異見[部署與排程指南](../deploy/scheduling.md)、[裸機原始碼部署指南](../deploy/bare-metal.md)與 [Docker / 1Panel 部署指南](../deploy/docker-1panel.md)。

## 1. 升級前檢查

按順序做完，任何一項沒過就不要開始。

| 檢查項       | 命令 / 位置                                                        | 通過標準                          |
| ------------ | ------------------------------------------------------------------ | --------------------------------- |
| 資料庫備份   | 見[備份與恢復](backup.md)                                          | 備份檔案非空且能解壓              |
| 前端產物備份 | 見下方命令                                                         | 三個 `dist` 各留一份              |
| 當前版本留痕 | `git log --oneline -1`、`php artisan migrate:status \| tail -5`    | 記下提交號與最後一條遷移          |
| 磁碟餘量     | `df -h`                                                            | 至少留出一次全量構建 + 備份的空間 |
| 生產配置     | `node backend/scripts/production_preflight.mjs --env=backend/.env` | 全部 `[PASS]`，無 `[FAIL]`        |

```bash
TS=$(date +%Y%m%d-%H%M%S)
tar -czf /tmp/backup/dist-$TS.tar.gz \
  frontend-user-v3-www/dist frontend-user-v4-console/dist frontend-admin-v3/dist

cd backend
composer check-platform-reqs --no-dev   # 只校验版本与扩展，不装包
php artisan app:doctor                  # PHP 版本、必需扩展、禁用函数
```

`app:doctor` 的檢查項真源是 `backend/app/Support/EnvironmentRequirements.php`。三條相容性注意：

- 最低 PHP 版本由程式碼約束（`composer.json` 的 `php` 約束與 `EnvironmentRequirements::MIN_PHP_VERSION`）；PHP 大版本升級必須與程式碼升級分開做、分別驗證。
- 計劃任務裡的 `php` 必須與 API 站點同一個 PHP CLI。寶塔預設 CLI 版本常與站點不一致，必要時改成絕對路徑（如 `/www/server/php/83/bin/php`），否則新程式碼會在舊直譯器上執行。
- `production_preflight.mjs --network` 會真實探測四個公開地址的 TLS、`/api/ready` 與 CORS，可在升級後復跑（可選 `--min-cert-days=`、`--json`）。

## 2. 更新程式碼

```bash
cd /www/wwwroot/你的项目      # 换成你的实际部署目录
git status --short            # 先看清工作区有什么
git pull --ff-only origin main
git log --oneline -1          # 确认 HEAD 已推进到目标提交
```

兩條必須避開的做法：

- **不要用 `git checkout origin/main -- <file>` 精確同步**。它只改工作區內容、不推進 HEAD；長期這麼做會讓生產 HEAD 停在很早的提交，等下一次完整 pull 時把中間所有他人提交一次性帶進生產。
- **不要為了讓 pull 通過而刪掉工作區檔案**。`git pull` 會被未跟蹤的同名檔案阻塞（`error: Untracked working tree files ...`）。先比對內容，一致才刪：

```bash
sha1sum backend/public/media/console-panel/jquery.min.js
git show origin/main:backend/public/media/console-panel/jquery.min.js | sha1sum   # 两行一致才继续
rm -rf backend/public/media/console-panel && git pull --ff-only origin main
```

先在預發或本地跑一次同一提交，確認這次會帶上線哪些提交，再決定釋出視窗。

## 3. 安裝依賴

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

`composer.lock` 有變化時必須重新執行，否則新程式碼呼叫的依賴版本仍是舊的。以 root 執行時前面加 `COMPOSER_ALLOW_SUPERUSER=1`。之後不需要手工清 `bootstrap/cache`，第 6 節的快取重建會覆蓋。**不要執行 `php artisan key:generate`**：`APP_KEY` 用於解密存量密文（身份證、金鑰類欄位），換掉會讓已加密資料永久無法讀取；`install_db.py` 只在鍵為空時生成一次。

## 4. 執行資料庫遷移

遷移檔案是**只新增**：`up()` 只建表、加列、加索引，不刪表、不刪列、不刪索引。少數遷移的 `down()` 裡確有 `dropColumn` / `dropIndex`，那是給本地測試庫用的，生產不執行（例：`2026_08_29_000001_add_secret_hash_index_to_api_keys.php` 明確不在 `down()` 裡 drop 索引）。

```bash
cd backend
php artisan migrate:status        # 只读，看哪些还没跑
php artisan migrate --force       # 生产必须带 --force
php artisan migrate:status | grep -i pending || echo "无待执行迁移"
```

規則：

- **先完成第 1 節的備份再遷移**。遷移一旦落庫，回退手段只有備份還原。
- 不要在生產庫執行 `migrate:fresh`、`migrate:reset`、`migrate:rollback`，也不要對承載業務資料的庫執行 `python3 scripts/install_db.py --reset`。
- `database/schema/mysql-schema.sql` 只用於空庫初始化，禁止手工編輯。
- 遷移失敗時不要反覆重試。先看 `backend/storage/logs/laravel.log` 裡的報錯語句與表名，判斷是結構衝突還是資料衝突，必要時走第 8 節的回滾路徑。

## 5. 重建前端產物

三個前端是構建期注入地址的純靜態站點，升級必須重新構建，且**只能走統一入口**：

```bash
cd /www/wwwroot/你的项目
pnpm install --frozen-lockfile --shamefully-hoist
CI=true pnpm run build:frontends
```

- `pnpm run build:frontends` 從 `backend/.env` 的四個公開地址解析出 `VITE_*` 注入構建，是站點地址的唯一真源。
- 逐個 `pnpm run build` 會改讀各前端目錄自己的 `.env`，兩處一旦不同步，產物裡的 canonical、`og:url`、結算回跳地址會全部寫成前端目錄裡的舊值——**站點 HTTP 全 200，但地址全是錯的**。
- 生產機 PATH 裡沒有 `pnpm` 時先執行 `corepack enable pnpm`，否則構建指令碼以 `spawn pnpm ENOENT` 失敗。
- 遠端命令有超時上限，構建幾十秒會被中斷。用後臺方式跑再輪詢日誌：

```bash
nohup bash -c 'cd /www/wwwroot/你的项目 && CI=true pnpm run build:frontends > /tmp/build.log 2>&1; echo "exit=$?" >> /tmp/build.log' > /dev/null 2>&1 &
sleep 100 && tail -10 /tmp/build.log     # 轮询直到出现 exit=
```

產物落在各前端目錄的 `dist/`，不會寫入 `backend/public`。釋出時替換對應寶塔站點根目錄下的內容即可；寶塔站點的「網站目錄」必須是應用目錄（如 `frontend-admin-v3`），「執行目錄」填 `/dist`，否則構建會撞上不可刪除的 `.user.ini`。

## 6. 過載快取與佇列

```bash
cd backend
php artisan optimize:clear      # 清配置、路由、视图、事件、编译缓存
php artisan config:cache
php artisan route:cache
```

- 佇列與排程不需要額外重啟：`schedule:run` 每分鐘觸發 `queue:drain` 新建 Worker，新程式碼在下一次觸發時生效。
- 只有改了 `php.ini`、PHP 擴充套件或 `bootstrap/cache` 才需要重啟 PHP-FPM（寶塔面板 → PHP → 重啟）。
- 運行了 VNC Relay 的環境，重啟一次常駐程序即可（寶塔程序守護 / `systemctl restart`）。
- **不要配置覆蓋同一佇列的常駐 `queue:work`**，那會與 `queue:drain` 形成雙消費者。

## 7. 驗證

```bash
curl -s -H "Host: api.example.com" http://127.0.0.1/api/health   # 存活
curl -s -H "Host: api.example.com" http://127.0.0.1/api/ready    # 就绪
php artisan app:doctor
```

`/api/ready` 會分別報告 `database`、`cache`、`storage`、`scheduler`、`scheduler_liveness`、`task_readiness`、`queue`。排程剛過載或 cron 尚未到觸發點時 `scheduler` 為 `false` 屬正常，等 1–2 分鐘複查。

```bash
# 产物地址必须与后台真源一致
grep -ro "<你的官网域名>" frontend-user-v3-www/dist/ | wc -l   # 应 > 0
grep -ro "<旧域名或其它域名>" frontend-user-v3-www/dist/ | wc -l # 应为 0
grep -ro "<本次改动的新关键字>" frontend-admin-v3/dist/ | wc -l
```

人工核對清單：

- [ ] 官網、控制台、管理端三個站點首頁正常，任意 SPA 路由重新整理不 404
- [ ] 官網首頁 HTML 的 title 是站點名（SEO 動態渲染生效），`/sitemap.xml`、`/robots.txt` 返回 200
- [ ] 管理端能登入，「日誌中心 → 執行日誌」無新增 error，「自動任務日誌 → 定時任務」有新的成功記錄
- [ ] 心跳活著：`php artisan scheduler:liveness` 輸出「心跳正常」
- [ ] 佇列未堆積：`php artisan queue:monitor provision,referral,notification,coupon,default,automation`
- [ ] 用一筆真實小額訂單走通「下單 → 支付 → 開通 → 通知」再放量

有 TTL 的響應快取（如 `console/capabilities` 300 秒、settings 分組 600 秒）不會因為重新部署而立即失效，驗證前先清對應快取鍵，不要誤判為「改了沒生效」。

## 8. 失敗回滾

先凍結自動動作，再動資料：

```bash
# 注释掉计划任务中的 schedule:run 行（宝塔计划任务页 / /etc/cron.d/turaidc）
```

回滾分三條路徑，互相獨立，出問題先定級再選：

- **前端**：解包升級前的 dist 備份覆蓋對應目錄，無需重啟任何服務。新產物引用的介面若已被舊後端撤回，前端也要一起回退。
- **後端程式碼**：切回升級前的提交（`git log --oneline` 找到提交號），再 `composer install --no-dev --optimize-autoloader`，然後 `php artisan optimize:clear && php artisan config:cache && php artisan route:cache`。
- **資料庫**：遷移只新增，通常**不需要**回滾資料庫；只有在遷移本身出錯或資料被寫壞時才用備份還原，流程見[備份與恢復](backup.md)。禁止在生產直接 `migrate:rollback` 不可逆遷移。

回滾收尾：確認 `php artisan scheduler:liveness` 正常、`/api/ready` 全綠後再恢復計劃任務；恢復前把回滾原因和影響範圍記進執行計劃，避免同樣的升級路線再走一遍。

## 關聯文件

- [備份與恢復](backup.md)：備份物件、現成指令碼與恢復演練。
- [部署與排程指南](../deploy/scheduling.md)：增量更新的實測坑與運維口徑。
- [故障排查](../troubleshooting.md)：升級後出現異常時的症狀定位。
- [日誌歸檔與 MySQL 日誌維護](log-archive.md)：歸檔與資料庫日誌輪轉。
