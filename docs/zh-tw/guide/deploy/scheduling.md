---
source: guide/deploy/scheduling.md
sourceHash: 5d1d858353114fd24bbf68e01c7fb2e64cfd80f8c0ac711d70cb251f57968e11
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/deploy/scheduling.md 產生，請勿手動編輯。 -->
# 部署與排程指南

## 文件用途

- 描述當前生產環境真實的部署拓撲與排程模型
- 對齊時間：`2026-09-13`
- **全新伺服器部署**先看 `docs/guide/deploy/bt-panel.md`（寶塔面板）或 `docs/guide/deploy/bare-metal.md`（無面板、無容器的原生服務部署）
- **容器化部署（Docker Compose / 1Panel / CI 自動打包）**先看 `docs/guide/deploy/docker-1panel.md`
- 配套文件：
  - `docs/README.md`（文件入口）
  - `docs/develop/local-development.md`（本地啟動）
  - `docs/develop/architecture.md`（總體架構）

## 1. 當前生產拓撲

專案跑在單臺寶塔伺服器上，結構如下：

```
宝塔站点 (Nginx)
  ├── 官网/用户入口 -> frontend-user-v3-www/dist 静态资源
  ├── 用户控制台   -> frontend-user-v4-console/dist 静态资源
  ├── 管理端站点   -> frontend-admin-v3/dist 静态资源
  └── 后端 API 站点 -> backend/public (PHP-FPM)
                       │
                       ├── MySQL 8      (本机或独立实例)
                       ├── Redis        (缓存)
                       ├── 宝塔计划任务   每分钟执行一次 schedule:run
                       └── 宝塔守护进程   常驻 php artisan vnc:relay
```

生產環境特徵：

- **四端各自獨立寶塔站點**，瀏覽器從三個前端站點直接訪問 API 域名；前端 Nginx 不反代 API
- **後端入口走 PHP-FPM**，不使用 `php artisan serve` 或 `app:serve`
- **沒有常駐 Queue Worker**，每分鐘 `schedule:run` 驅動三個入口：`scheduler:heartbeat`（唯一時鐘源，按 15 分鐘槽位去重派發定時任務）、`queue:drain`（後臺子程序消費佇列，按 `provision`、業務組 `referral,notification,coupon,default`、定時組 `automation` 三個佇列組分別建 Worker，drain 鎖保證同一佇列不併發，空閒時自動退出）、`scheduler:liveness`（心跳存活探針，心跳異常時仍能告警）
- **VNC Relay 獨立常駐**：Relay 重啟不會阻塞心跳、支付、新購或續費
- **新購、續費支付後同步履約**：支付回撥內直接呼叫上游開通/續費，使用者付款後立即生效；同步失敗才回退 `provision` 佇列，由每分鐘心跳 Worker 重試，不再依賴佇列輪詢延遲

## 2. 計劃任務配置

### 2.1 寶塔計劃任務

在寶塔面板 → 計劃任務 → 新增：

- 任務型別：Shell 指令碼
- 執行週期：每分鐘執行
- 指令碼內容：

```bash
cd /你的项目/backend
php artisan schedule:run >> /dev/null 2>&1
```

說明：

- 這條計劃每分鐘同時觸發：心跳派發（15 分鐘槽位去重）、佇列消費（`queue:drain` 後臺按佇列組建 Worker）與存活探針；任一佇列仍在消費時，drain 鎖只跳過該佇列的重複 Worker，其餘佇列組繼續按分鐘消費
- 不要再配置覆蓋同一佇列的常駐 `queue:work`，避免重複消費；需要低延遲時應按佇列邊界單獨評估
- 任務執行超時強殺（`--timeout` 與 `JobTimedOut` 收斂）依賴 Linux `pcntl`/`SIGALRM`，Windows 開發環境不生效；超時類問題必須在 Linux 環境驗證，Windows 上長任務不會被自動終止
- 錯過的 15 分鐘心跳槽位預設不自動回放，需依賴任務自身冪等補償或管理端手動觸發

### 2.2 VNC Relay 守護

在寶塔程序守護或其他程序管理器中增加常駐命令：

```bash
cd /www/wwwroot/你的项目/backend
php artisan vnc:relay
```

上線前確認 `127.0.0.1:8100` 已監聽；Relay 異常時由守護程序自動重啟。`vnc:ensure-relay` 僅保留為手工健康檢查/故障修復命令，不再註冊到心跳任務。

### 2.3 後端排程內容

當前排程能力以真實命令與路由註冊為準。檢視真實啟用清單請看：

- `backend/routes/console.php`
- `backend/app/Console/Commands/`
- 管理端 "排程總覽"：`GET /api/v2/admin/schedules/overview`

典型任務（以程式碼為準）：

- `scheduler:heartbeat` / `queue:drain` / `scheduler:liveness`：每分鐘，排程與佇列主幹
- `tickets:cleanup-unused-upstream-uploads`：每分鐘，刪除超保留期未被工單回覆引用的上游上傳檔案
- `open-api:prune-usage-logs`：每週，按 `open_api.usage_log_retention_days`（預設 90 天）清理 API 用量日誌
- 自動續費檢查、賬單逾期處理、服務到期停用與終止、上游狀態同步、各類日誌清理：由心跳按槽位規則派發，清單見管理端「排程總覽」

## 3. 部署流程

### 3.1 後端部署

```bash
# 项目根下切到 backend
cd backend

# 拉代码（宝塔一般自带 Git 钩子或手动 git pull）
git pull

# 安装依赖
composer install --no-dev --optimize-autoloader

# 数据库初始化/更新
# 新环境首次部署：使用 schema baseline
python scripts/install_db.py
# 后续增量更新：
php artisan migrate --force

# 清旧缓存
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# 生产缓存
php artisan config:cache
php artisan route:cache
```

> **資料庫初始化說明**：
>
> - `database/schema/mysql-schema.sql` 是當前生產庫完整結構快照，包含 62 張表和 `migrations` 記錄
> - 歷史遷移檔案已歸檔至 `database/migrations/_archive/`，不再逐個執行
> - 增量遷移（`database/migrations/*.php`）用於後續新功能上線
> - `install_db.py` 最後會經 `SettingsSeeder::seed()` 冪等種入：
>   - 系統核心預設配置（`settings` 表，含 `notification.email_enabled` / `sms_enabled` / `sms_template_code`）
>   - 通知模板預設資料（`notification_templates` 表）：email 全量模板 + sms 驗證碼模板
>   - schema baseline 僅匯出表結構、不含模板資料，**模板種子必須經該 Seeder 執行**，切勿依賴遷移或手工補種
>
> **首次部署後請檢查（管理後臺 → 站點設定 → 通知總開關）**：
>
> - 郵件通知 / 簡訊通知預設關閉，按需在後臺開啟
> - 郵件/簡訊外掛需在「外掛管理」安裝、配置並繫結，再前往「通知與介面」維護對應模板

### 3.2 前端部署

```bash
# 项目根目录：只安装一次 workspace 依赖（pnpm monorepo，四个 workspace）。
# --shamefully-hoist：多个 vite build（尤其 www 的 element-plus 深路径 import）
# 会从根 node_modules 解析依赖，必须扁平提升，否则构建报 load-fallback ENOENT。
# verify-deps-before-run=false：关闭 pnpm run 前的依赖检查，避免它重新 purge 掉 hoist 布局。
pnpm install --frozen-lockfile --shamefully-hoist
pnpm config set verify-deps-before-run false

# 每个前端构建时读取各自目录下的 .env 获取后端/前端地址，
# 构建三个完全独立的 dist，不复制任何文件到 backend/public。
pnpm run build:frontends
# 若在无 TTY 的 CI/脚本环境跑 build，需 CI=true（跳过 pnpm 的模块目录移除交互确认）。
CI=true pnpm run build:frontends
```

產物目錄分別是 `frontend-user-v3-www/dist`、`frontend-user-v4-console/dist`、`frontend-admin-v3/dist`，各自指向對應寶塔站點根目錄。

VitePress 文件官網不屬於上述三端，必須通過 `pnpm run build:docs` 獨立構建和釋出。內容維護、產物目錄與釋出檢查見[文件官網維護指南](https://github.com/25Cloud/TuraIDC/blob/main/docs/internal/governance/docs-web-maintenance.md)。

> **dev 與 build 的 env 分開**：本地開發（`pnpm run dev`）讀取各前端目錄下的 `.env.dev`（dev 指令碼用 `--mode dev`）；構建（`pnpm run build:frontends`）讀取各前端目錄下的 `.env`。前端構建不依賴 backend 環境檔案。
>
> 每個前端目錄（`frontend-admin-v3`、`frontend-user-v3-www`、`frontend-user-v4-console`）需要兩份 env（均 gitignore，不入庫）：
>
> - `.env.dev`：本地開發地址（`127.0.0.1` 各端埠、本地 API）
> - `.env`：構建地址（生產域名），部署打包前填寫真實域名
>
> 部署前確認 `.env` 中 `VITE_API_BASE_URL`、`VITE_PUBLIC_SITE_URL`、`VITE_CONSOLE_SITE_URL`、`VITE_SESSION_COOKIE_DOMAIN` 與生產一致；缺項或填錯會讓產物指向錯誤地址。
>
> 站點地址的真源是 `backend/.env` 的四個公開地址：`backend/scripts/build_frontends.mjs` 會把它們解析成 `VITE_*` 注入構建，`--print-env` 可單獨列印這套變數供其他構建入口複用。這樣各前端目錄下的 `.env` 只是本地便利，不會與線上配置漂移。

**構建配置來源的區別：**

- **直接執行前端構建**（`pnpm run build`）：各前端目錄讀取自身目錄下的 `.env`（或 `.env.production`），僅用該目錄內的配置。
- **通過 `pnpm run build:frontends` 統一構建**：由 `backend/scripts/build_frontends.mjs` 讀取 `backend/.env` 中的四個公開地址，解析後作為 `VITE_*` 環境變數注入到各前端構建中。這樣各前端目錄下的 `.env` 只是本地便利，不會與線上配置漂移。

`backend/scripts/build_frontends.mjs --print-env` 可單獨列印這套變數，供其他構建入口（如 `deploy/deploy-www.sh` 的 `inject_backend_site_env`）複用，保證所有構建入口讀到同一份站點地址真源，避免與實際入口行為矛盾。

```bash
# 只预览构建目标，不实际构建
pnpm run build:frontends:dry

# 只打印从 backend/.env 解析出的 VITE_* 站点地址（供部署脚本复用）
node backend/scripts/build_frontends.mjs --print-env
```

### 3.2.1 單域名子路徑部署下的控制台地址

現網把官網、控制台、管理端、API 合併到同一個域名，用子路徑區分：

| 端      | 訪問路徑                | 對應 `backend/.env`               |
| ------- | ----------------------- | -------------------------------- |
| 官網    | `/`                     | `FRONTEND_URL=https://你的域名`   |
| 控制台  | `/console/`             | `CLIENT_CONSOLE_URL=https://你的域名/console` |
| 管理端  | `/admin/`               | `ADMIN_URL=https://你的域名/admin` |
| API     | `/api`                  | `APP_URL=https://你的域名`        |

單域名部署時 `VITE_CONSOLE_SITE_URL` **必須帶 `/console` 子路徑**。控制台的 router base 就是 `/console/`，寫成不帶子路徑的裸域名會讓官網的「登入 / 免費註冊」跳到 `https://你的域名/client/login`——那條路徑落在官網 SPA 上，永遠到不了控制台登入頁。

為此 `frontend-user-v3-www/vite.config.js` 加了構建守衛：`VITE_CONSOLE_SITE_URL` 留空，或與 `VITE_PUBLIC_SITE_URL` 同源卻沒有子路徑時，`pnpm build` 直接失敗。控制台地址配錯會在構建期暴露，不會帶著壞連結發版。

單域名部署時 `CLIENT_SESSION_COOKIE_DOMAIN` 建議留空（登入態本來就在同源）；需要跨子域共享登入態時才填父域。

> **`CLIENT_CONSOLE_URL` 必須帶 `/console`**：後端 `AuthService::normalizeConfiguredUrl()`
> 允許地址帶路徑（單域名部署的前提），管理員代理登入連結就是在該地址後追加
> `/client/login-as`。如果把它寫成不帶子路徑的裸域名，官網的登入/註冊會跳到
> `https://你的域名/client/login`（官網 SPA），代理登入也會落到官網頁面上。
> 管理端會把後端下發的地址再拼一次 `/client/login-as`，兩邊都按「base path 保留」處理。

### 3.3 .env 關鍵項

最小化需要核對的 `.env`：

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://api.你的域名`（API、`/media/*`、`/uploads/*`、VNC WebSocket）
- `FRONTEND_URL=https://www.你的域名`（單域名子路徑部署寫 `https://你的域名`，見 3.2.1）
- `CLIENT_CONSOLE_URL=https://console.你的域名`（單域名子路徑部署寫 `https://你的域名/console`）
- `ADMIN_URL=https://admin.你的域名`（單域名子路徑部署寫 `https://你的域名/admin`）
- `CLIENT_SESSION_COOKIE_DOMAIN=.你的域名`（官網和控制台需要跨子域共享登入態時設定；本地留空）
- `SESSION_SECURE_COOKIE=true`（HTTP 環境改為 `false`）
- `DB_*`：MySQL 8 連線
- `REDIS_*`：Redis 連線
- `CACHE_STORE=redis`
- `SESSION_DRIVER=file`（當前生產口徑）
- `QUEUE_CONNECTION=database`（當前生產口徑）
- `TURAIDC_PROVISION_QUEUES=provision`（開通佇列組，Worker 超時可達 1200 秒）
- `TURAIDC_BUSINESS_QUEUES=referral,notification,coupon,default`（業務佇列組；若存量 `.env` 仍含 `provision`，執行時會自動剔除，不會重複消費）
- `TURAIDC_SCHEDULE_QUEUE=automation`（定時任務佇列組）

四個公開地址可統一使用 HTTP 或 HTTPS；HTTPS 前端不能指向 HTTP API。CORS 會從三個前端 URL 自動生成精確 allowlist，無需在前端 Nginx 增加代理。

Redis 當前用於快取；不要在沒有專項方案和驗證的情況下把會話或佇列切到 Redis。

### 3.4 受信代理與來源 IP 契約

後端在 `backend/bootstrap/app.php` 配置了 `trustProxies`：只信任迴環與 RFC1918/ULA 私網段，且只採信 `X-Forwarded-For`、`X-Forwarded-Port`、`X-Forwarded-Proto`（不信任 `X-Forwarded-Host`）。`request()->ip()` 依賴該配置解析真實客戶端 IP，直接影響：

- 審計欄位（如 `last_login_ip`）與推薦風控的“註冊 IP == 推薦人 IP”判斷；
- `/upload_image` 上游附件上傳的啟用開關、白名單/限流中介軟體（`TicketUpstreamUploadThrottle`）。介面預設關閉，管理端配置工單傳遞規則前必須先開啟；白名單外上傳預設拒絕。

部署必須滿足以下契約，否則 IP 白名單與限流會被繞過或誤判：

1. **邊緣反向代理是唯一公網入口**：後端 API 埠（寶塔 PHP-FPM 站點 / 容器 `8080`）只能被受信反代訪問，不能直接暴露給公網或不可信私網來源。
2. **代理必須真實生成來源頭**：外部反代需攜帶 `X-Forwarded-For`、`X-Forwarded-Proto`。單層受信代理應把 `X-Forwarded-For` **重置為 `$remote_addr`**，不要用 `$proxy_add_x_forwarded_for` 原樣追加——否則客戶端可自帶偽造 XFF 直接汙染 `request()->ip()`。只有明確存在多層受信代理且每層都清洗時才允許追加。
3. **不得把公網代理地址加入 `trustProxies`**：當前配置只信任迴環與私網段；若實際入口代理不在這些網段（例如託管在公有云的 LB），必須先評估暴露面並收窄為明確的入口 IP/CIDR，再修改配置。
4. 寶塔 PHP-FPM 直連形態（前端 Nginx 不反代 API）本身不產生 XFF；後端以 PHP-FPM 接收到的 `REMOTE_ADDR` 為準，不受上述契約影響，但也不能依賴 XFF 取客戶端 IP。

> 容器部署注意：`deploy/docker/.env.example` 預設把 `API_PORT=8080` 暴露到所有網絡卡。上線前應改為僅本機繫結（`127.0.0.1:8080`），由宿主機 Nginx/1Panel 反代進入；否則私網來源可直接訪問 API 並偽造 XFF。

### 3.5 增量更新的實測坑

以下是現網增量更新（`git pull` + 重建 dist）時真實踩過的問題。

#### 3.5.1 前端必須走 `pnpm run build:frontends`，不能逐個 `pnpm run build`

逐個構建讀的是**各前端目錄自己的** `.env` / `.env.production`，而專案真源是
`backend/.env` 的四個公開地址（由 `build_frontends.mjs` 注入）。兩處一旦不同步，產物裡
canonical、`og:url`、結算回跳地址就會全部寫成前端目錄裡的舊值。

實測過：官網 `.env.production` 是 `idc.25y.cn`，而 `backend/.env` 的 `FRONTEND_URL` 是
`next2.25y.cn`。逐個構建後產物裡 `idc.25y.cn` 出現 4 處、`next2.25y.cn` 0 處——
**站點 HTTP 全 200，但 SEO 與回跳地址全是錯的**。改用標準指令碼重建後歸零。

部署後按真源核對，不要只看能不能訪問：

```bash
grep -ro "$FRONTEND_HOST" frontend-user-v3-www/dist/ | wc -l   # 应 > 0
grep -ro "<其他域名>"   frontend-user-v3-www/dist/ | wc -l       # 应为 0
```

#### 3.5.2 生產機沒有 `pnpm`，標準指令碼會 ENOENT

`build_frontends.mjs` 內部 `spawn pnpm`。若生產 PATH 裡只有 node 與 corepack
（`packageManager` 指定了 pnpm 版本、corepack 快取裡也有，但沒 `enable` 過），
`pnpm run build:frontends` 會以 `ENOENT: spawn pnpm` 失敗。

一次性修復：

```bash
corepack enable pnpm   # 生成 /usr/bin/pnpm shim
pnpm -v                # 确认版本与 packageManager 一致
```

在 `corepack enable` 之前只能用 `corepack pnpm run build` 逐個構建，**但那正是 3.5.1 說的
會繞過地址注入的方式**，不要當成常態。

#### 3.5.3 `git pull` 會被 untracked 檔案阻塞

為緊急止血手工放進 `backend/public/media/console-panel/` 的靜態資產，與後續提交裡的同名路徑
衝突，pull 直接拒絕：

```
error: Untracked working tree files ... Please move or remove them before you merge.
```

先比對內容再刪工作區副本，讓 git 接管（內容一致才可刪）：

```bash
sha1sum backend/public/media/console-panel/jquery.min.js
git show origin/main:backend/public/media/console-panel/jquery.min.js | sha1sum   # 两行一致才继续
rm -rf backend/public/media/console-panel && git pull --ff-only origin main
```

#### 3.5.4 不要用 `git checkout origin/main -- <file>` 精確同步

那條命令只改工作區檔案內容、**不推進 HEAD**。用它"熱修"幾個檔案後，生產 HEAD 會長期停在
很早的提交；等後來要做一次完整 pull 時，會一次性把中間所有他人的提交（虛擬主機面板、
換綁手機、官網結算欄等）全量帶進生產，部署範圍失控。

**對策**：要麼全量 `git pull --ff-only`；要麼堅持精確同步，但必須清楚自己欠了多少提交，
並在部署前把「這次會連帶上線哪些別人的提交」講清楚，讓人來決定。

#### 3.5.5 構建前先備份 dist

```bash
TS=$(date +%Y%m%d-%H%M%S)
tar -czf /tmp/backup/www-dist-$TS.tar.gz frontend-user-v3-www/dist
```

保留位置 `/tmp/backup/`，回滾時解包覆蓋即可。

#### 3.5.6 PHP 改動不需要重啟 PHP-FPM

本機 opcache 為 `validate_timestamps=On`、`revalidate_freq=2`，同步 PHP 檔案後最多 2 秒自動生效。
確認方式：`php -i | grep -i opcache`。只有改了 `php.ini`、擴充套件或 `bootstrap/cache` 才需要重啟。

#### 3.5.7 多臺伺服器時，先確認當前 SSH 連線指向哪臺

有多個已連線的資源時，遠端命令會打到「當前活動連線」，可能不是生產機。表現為
`cd /www/wwwroot/TuraIDC: No such file or directory`。每輪操作前先 `ssh_list` 確認當前連線，
或用 `ssh_connect_profile` 顯式指定。

#### 3.5.8 長任務用 nohup 後臺 + 輪詢

遠端命令有超時上限（預設 30 秒），構建幾十秒就會被中斷。正確姿勢：

```bash
nohup bash -c 'cd /www/wwwroot/TuraIDC && CI=true pnpm run build:frontends > /tmp/build.log 2>&1; echo "exit=$?" >> /tmp/build.log' > /dev/null 2>&1 &
sleep 100 && tail -10 /tmp/build.log     # 轮询直到出现 exit=
```

#### 3.5.9 部署後必須 grep 產物驗證

HTTP 200 只說明站點能開，不代表內容正確（3.5.1 就是 200 全綠但地址錯）。收尾至少核對三項：
產物含本次改動的新關鍵字、新改動前的舊關鍵字為 0、硬編碼域名與 `backend/.env` 真源一致。

#### 3.5.10 帶 TTL 的快取會讓「改了沒生效」

`console/capabilities` 等響應快取 300 秒、settings 分組快取 600 秒，管理員改完設定不會立刻生效，
舊值也不會因為重新部署而消失。驗證前先手動清對應快取鍵。

## 4. 本地啟動差異

本地同樣使用直連 API：三個前端分別在 `127.0.0.1:5175/5173/5174`，API 在 `127.0.0.1:8000`。前端開發環境的 `VITE_API_BASE_URL` 已指向 `http://127.0.0.1:8000/api`，因此會真實驗證 CORS 和 VNC WebSocket。

後端用一條封裝命令同時拉起 HTTP、Relay 與佇列：

```bash
cd backend
php artisan app:serve
# 等价于：HTTP + VNC Relay + 业务队列 Worker（queue:work 消费业务队列组）

# 如果本地想同时拉起调度，Relay 另行独立运行；
# 带调度时不再另起常驻 Worker，队列由每分钟 queue:drain 按队列组后台消费
php artisan app:serve --with-schedule --without-vnc
php artisan vnc:relay
```

細節見 `docs/develop/local-development.md` 與 `backend/app/Console/Commands/ServeBackendStackCommand.php`。

## 5. 常見運維操作

### 5.1 檢視排程執行情況

- 寶塔計劃任務頁可看指令碼日誌
- `backend/storage/logs/laravel.log`
- 管理端 `/api/v2/admin/schedules/overview` 與 `/api/v2/admin/logs/tasks`

### 5.2 手動觸發一次排程

```bash
cd backend
php artisan schedule:run
```

### 5.3 手動跑一次佇列消費

正常不需要。只在排查"佇列已堆積但未消費"時按目標佇列手動執行：

```bash
cd backend
php artisan queue:work --once --queue=provision
php artisan queue:work --once --queue=referral,notification,coupon,default
php artisan queue:work --once --queue=automation
```

### 5.4 清理失敗佇列

```bash
cd backend
php artisan queue:retry all     # 重试全部失败
php artisan queue:flush         # 清空失败队列（慎用）
```

### 5.5 OPcache / 路由 / 配置快取重新整理

上線後如果路由或配置沒生效，依次跑：

```bash
cd backend
php artisan route:clear && php artisan config:clear && php artisan cache:clear
php artisan route:cache && php artisan config:cache
# 重启 PHP-FPM（宝塔面板 → PHP → 重启）
```

### 5.6 PHP 停用函式問題

Composer 報 `Call to undefined function putenv()` 等錯誤時，優先核對寶塔 PHP 的停用函式和 CLI / FPM 配置差異；當前倉庫未保留單獨修復指南。

## 6. 監控與告警

當前已整合 Sentry SDK（需配置 `SENTRY_LARAVEL_DSN` 啟用），臨時監控靠：

- Laravel 日誌（`backend/storage/logs/laravel.log`）
- 寶塔監控面板
- `/api/v2/admin/logs/*` 管理端日誌頁
- 健康檢查端點：`/api/health`（存活）、`/api/ready`（就緒，檢查 DB/Cache/Storage/Scheduler）

`/api/ready` 會分別報告心跳存活、任務執行狀態和佇列狀態；心跳只處理當前 15 分鐘槽位，錯過的槽位預設不自動回放，需依賴任務冪等補償或管理端手動觸發。

建議的整改方向：

- 配置 Sentry DSN 並激活錯誤追蹤
- 關鍵任務失敗告警（自動續費、支付回撥、實名回撥）
- 資料庫慢查詢日誌啟用 `long_query_time=1`

## 7. 回滾策略

- 後端：程式碼回退 + `composer install` + `php artisan migrate:rollback`（僅當遷移可逆）
- 前端：按站點獨立回滾，直接恢復舊 `dist`
- 資料庫：禁止在生產直接 `migrate:rollback` 不可逆遷移，走備份還原
- 關鍵業務（支付、開通、返佣）回退前必須先凍結 `schedule:run`，避免邊回滾邊產生新非同步動作

## 8. 檔案許可權（寶塔部署後）

```bash
sudo chown -R www:www storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 public/uploads public/media
# .env 文件必须严格权限，防止凭据泄露
sudo chmod 600 .env
```

## 9. 資料庫備份

專案提供 MySQL 定時備份指令碼 `backend/scripts/backup_mysql.sh`，從 `.env` 讀取資料庫連線資訊。

### 9.1 寶塔計劃任務配置

在寶塔面板 → 計劃任務 → 新增：

- 任務型別：Shell 指令碼
- 執行週期：每天凌晨 3:00
- 指令碼內容：

```bash
cd /你的项目/backend
bash scripts/backup_mysql.sh
```

### 9.2 備份配置

| 環境變數                | 預設值                          | 說明             |
| ----------------------- | ------------------------------- | ---------------- |
| `BACKUP_DIR`            | `backend/storage/backups/mysql` | 備份檔案存放目錄 |
| `BACKUP_RETENTION_DAYS` | 14                              | 備份保留天數     |

### 9.3 手動備份

```bash
cd backend
bash scripts/backup_mysql.sh
```

### 9.4 恢復備份

```bash
gunzip -c /path/to/backup.sql.gz | mysql -u用户名 -p密码 数据库名
```

## 10. OPcache 生產配置

Laravel 生產環境強烈建議啟用 OPcache 並最佳化配置。在寶塔面板 → PHP → 配置檔案中新增或修改：

```ini
[opcache]
opcache.enable=1
opcache.enable_cli=1
; 根据项目大小调整，建议 256MB
opcache.memory_consumption=256
; Laravel 项目约 8000+ 文件，建议 20000
opcache.max_accelerated_files=20000
; 生产环境关闭时间戳验证，部署后需手动重启 PHP-FPM 或清除 OPcache
opcache.validate_timestamps=0
; 禁用文件保存，减少 I/O
opcache.save_comments=1
opcache.fast_shutdown=1
; 仅缓存项目文件，排除 vendor 中不常用的文件
opcache.blacklist_filename=/www/server/php/82/etc/opcache_blacklist.txt
```

**部署後必須重啟 PHP-FPM** 使 OPcache 和路由/配置快取生效：

```bash
# 宝塔面板 → PHP → 重启
# 或命令行
sudo systemctl restart php-fpm-82
```

**OPcache 黑名單檔案**（可選，排除不需要快取的檔案）：

```text
# /www/server/php/82/etc/opcache_blacklist.txt
/vendor/phpunit/
/vendor/mockery/
/vendor/phpspec/
/vendor/phpstan/
```

## 關聯文件

- [啟動指南](../../develop/local-development.md)：本地啟動與驗證。
- [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)：站點偽靜態規則。
- [日誌歸檔與 MySQL 維護](../maintain/log-archive.md)：日誌與資料庫運維。
- [工程踩坑記錄](../../develop/pitfalls.md)：開發、驗證與提交環節的實測坑。
