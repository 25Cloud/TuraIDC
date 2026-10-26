---
source: guide/deploy/generic.md
sourceHash: cef83a388d79f811d266f67952c32b7f4ee4354fbecc7570afc893fe2cb7ffc7
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/deploy/generic.md 產生，請勿手動編輯。 -->
# 部署指南

本文件說明如何將圖拉雲業務/財務系統部署到生產環境。部署拓撲：後端 API、三個前端靜態站點、Redis、MySQL；佇列與排程由每分鐘一次的 Laravel 排程器統一驅動，無需常駐佇列 Worker。

## 1. 環境要求

- 作業系統：Linux（推薦 Ubuntu 22.04+ / Debian 12）或 Windows Server
- PHP 8.3+，且必須是**已修補 CVE-2026-109618 / CVE-2026-109619 的版本**：上游分支的最低補丁版為 8.3 ≥ 8.3.36 / 8.4 ≥ 8.4.27 / 8.5 ≥ 8.5.12；發行版與 LTS 廠商的回補包同樣有效（發行版版本號可能低於上述數字，以廠商公告為準）。無法立刻打補丁時，先把 `session.upload_progress.enabled=0` 寫進 php.ini / FPM 池並重啟全部 worker，可擋住已知的攻擊路徑（不是完整修復）
- MySQL 8.0+（推薦；最低相容 5.7.8——5.7 已 EOL，僅相容性支援。混用 8.0 的 mysqldump 客戶端備份 5.7 服務端時需加 `--column-statistics=0`。**5.7 部署必須在 my.cnf 的 `[mysqld]` 段設定 `explicit_defaults_for_timestamp=ON`**：5.7 預設 OFF 時，表內首個無顯式預設值的 `timestamp` 列會被隱式附加 `ON UPDATE CURRENT_TIMESTAMP`，與 8.0 行為不一致，可能悄悄改寫業務時間欄位）
- Redis 6.0+（生產必需：分散式鎖、快取）
- Composer 2.x
- Node.js 20+（僅構建前端時需要）
- Nginx（或任意靜態伺服器 + 反代）

### 1.1 擴充套件與停用函式以程式碼為準

PHP 版本、必需擴充套件與停用函式清單的唯一真源是 `backend/app/Support/EnvironmentRequirements.php`，
本文件不重複維護清單，避免與程式碼漂移。裝完依賴後隨時可自查：

```bash
cd backend
php artisan app:doctor          # 必选项：版本、必需扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

**停用函式是最高頻的部署故障**：寶塔面板預設停用 `putenv`、`proc_open`、`symlink`，
會直接導致 `.env` 讀取與程序呼叫失敗。解除方式：寶塔 → 軟體商店 → PHP → 停用函式，
刪除這三個函式後重啟 PHP，再用 `php artisan app:doctor` 複核。

`app:doctor` 在安裝前後都能執行：安裝嚮導的環境檢測同樣呼叫這份清單，
但站點裝完之後嚮導會 404 消失，升級 PHP 或改動 php.ini 後只能靠這個命令複核。

## 2. 準備程式碼

```bash
git clone <你的仓库地址> TuraIDC
cd TuraIDC
```

## 3. 後端部署

### 3.1 安裝依賴與配置

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

編輯 `.env`，重點確認：

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://www.example.com
CLIENT_CONSOLE_URL=https://console.example.com
ADMIN_URL=https://admin.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=finance
DB_USERNAME=finance
DB_PASSWORD=你的强密码

CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
```

> 四個 URL（APP_URL / FRONTEND_URL / CLIENT_CONSOLE_URL / ADMIN_URL）必須為互不相同的 HTTPS 域名。前端構建指令碼會讀取這些地址注入各前端產物。

### 3.2 初始化資料庫

```bash
# 创建数据库
mysql -u root -p -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 导入完整结构基线
mysql -u root -p finance < database/schema/mysql-schema.sql

# 执行增量迁移
php artisan migrate --force

# 写入系统默认配置
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder
```

> `database/schema/mysql-schema.sql` 是完整結構基線，禁止手工編輯；新功能只新增 `database/migrations/` 增量遷移。

### 3.3 建立管理員賬號

```bash
php artisan tinker
App\Models\AdminUser::create([
    'username' => 'admin',
    'password' => '你的强密码',
    'role_id'  => 1,
    'nickname' => '管理员',
    'status'   => 1,
]);
```

> `role_id` 需為超級管理員角色 id（預設 `super_admin` 角色的記錄 id）。可在管理後臺繼續建立角色與員工賬號。

### 3.4 配置計劃任務與佇列

將以下命令加入系統 crontab（每分鐘執行一次 Laravel 排程器）：

```cron
* * * * * cd /path/to/TuraIDC/backend && php artisan schedule:run >> /dev/null 2>&1
```

Laravel 排程器內部會按 `routes/console.php` 定義的節奏驅動心跳派發（15 分鐘槽位去重）、`queue:drain` 佇列消費（按 `provision`、業務組、`automation` 三個佇列組分別建 Worker，drain 鎖保證同一佇列不併發）、存活探針與週期清理。資料庫為唯一時鐘源，無需單獨配置多條 cron。

> **不要再配置覆蓋同一佇列的常駐 `queue:work`**：`schedule:run` 每分鐘已經後臺觸發 `queue:drain` 消費全部佇列組，再掛常駐 Worker 會形成雙消費者；如需排查佇列堆積，見[部署與排程指南](scheduling.md)的手動消費命令。

### 3.5 快取清理與資源

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

> 若 `SESSION_DRIVER` 使用 file，`storage/framework/sessions` 需可寫。

## 4. 前端構建

在倉庫根目錄執行（需 Node.js 20+ 與 pnpm）：

```bash
pnpm install --frozen-lockfile --shamefully-hoist
pnpm run build:frontends
```

該命令讀取 `backend/.env` 中的四個公開地址，依次構建三端到各自 `dist/`：

| 前端       | 產物目錄                         | 埠（開發） |
| ---------- | -------------------------------- | ------------ |
| 官網門戶   | `frontend-user-v3-www/dist/`     | 5175         |
| 使用者控制台 | `frontend-user-v4-console/dist/` | 5173         |
| 管理後臺   | `frontend-admin-v3/dist/`        | 5174         |

三端均為純靜態站點，釋出時將各自 `dist/` 內容部署到對應 Nginx 站點即可。

## 5. Nginx 配置示例

### 後端 API（api.example.com）

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    # ssl_certificate / ssl_certificate_key ...

    root /path/to/TuraIDC/backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 120s;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

### 前端站點（www.example.com / console.example.com / admin.example.com）

三個前端使用同一套配置模板，替換 `server_name` 與 `root` 即可：

```nginx
server {
    listen 443 ssl http2;
    server_name www.example.com;

    root /path/to/TuraIDC/frontend-user-v3-www/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## 6. 程序守護（supervisor）

佇列消費由排程器的 `queue:drain` 每分鐘驅動，**不需要**常駐佇列 Worker；需要程序守護的是 VNC Relay（可選）。

### VNC Relay（可選，控制台遠端桌面）

若啟用 VNC 遠端桌面功能，需執行 `vnc:relay` 程序，並將對外 WebSocket 路徑（預設 `/ws/vnc`）反代到 `127.0.0.1:8100`：

```ini
[program:finance-vnc-relay]
directory=/path/to/TuraIDC/backend
command=php artisan vnc:relay --host=127.0.0.1 --port=8100
autostart=true
autorestart=true
user=www-data
```

也可使用統一入口 `php artisan app:serve --with-schedule` 同時託管 HTTP、VNC Relay、佇列與排程，但那是本地/聯調入口，生產環境應按上文用 PHP-FPM + cron + supervisor 拆分獨立程序。

## 7. 可選整合配置

以下能力按需在 `backend/.env` 中開啟（佔位見 `.env.example`）：

| 整合            | 環境變數                                                                           | 說明       |
| --------------- | ---------------------------------------------------------------------------------- | ---------- |
| 支付寶          | `ALIPAY_APP_ID` / `ALIPAY_PRIVATE_KEY` / `ALIPAY_PUBLIC_KEY` / `ALIPAY_NOTIFY_URL` | 線上支付   |
| 簡訊（阿里雲）  | `SMS_API_ENDPOINT`                                                                 | 驗證碼簡訊 |
| 極驗            | `GEETEST_CAPTCHA_ID` / `GEETEST_CAPTCHA_KEY` / `GEETEST_ENABLED`                   | 人機驗證   |
| 實名認證（IDC） | `VERIFICATION_KEY` / `VERIFICATION_API_ENDPOINT`                                   | 實名校驗   |
| VNC Relay       | `VNC_RELAY_HOST` / `VNC_RELAY_PORT`                                                | 遠端桌面   |
| Sentry          | `SENTRY_DSN`                                                                       | 錯誤監控   |

供應商適配（如 `zjmf_finance`）通過管理後臺「供應商」模組繫結配置，金鑰不寫入程式碼。

## 8. 備份

參考 `backend/scripts/backup_mysql.sh`（每日凌晨備份 MySQL 並保留 14 天）：

```bash
bash backend/scripts/backup_mysql.sh
```

建議同時備份：

- 資料庫（`mysqldump --single-transaction --routines --triggers`）
- `backend/storage/app/private/`（上傳檔案、票據附件、日誌歸檔）
- `backend/.env`（應用金鑰與配置）

## 9. 上線前檢查清單

- [ ] `.env` 中 `APP_DEBUG=false`、`APP_ENV=production`、`SESSION_SECURE_COOKIE=true`
- [ ] 生產環境 `CACHE_STORE=redis`（分散式鎖依賴）
- [ ] 四個公開域名均為 HTTPS 且互不相同
- [ ] 資料庫已執行全部遷移並初始化預設配置
- [ ] 已建立超級管理員並修改預設憑據
- [ ] crontab 已配置 `php artisan schedule:run`
- [ ] supervisor 已託管佇列 Worker
- [ ] 前端三端產物已部署並重新整理快取
- [ ] `backend/public/uploads/` 與 `storage` 目錄許可權正確

## 10. 官網 SEO 部署

官網是 Vue 單頁應用，裸殼 `index.html` 帶 `noindex, nofollow`，正文由後端 `SeoRenderService` 讀資料庫動態渲染後注入。**沒接上這條鏈路，搜尋引擎抓到的就是一份帶 `noindex` 的空殼，站點等於完全不被收錄。** 完整偽靜態規則見[四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)，本節只列各部署形態必須落位的部分與自檢方法。

### 10.1 三種部署形態分別要做什麼

| 部署形態                       | 轉發規則放在哪                                                                                                     | 前端 shell 地址                                                                                                                                                       | 注意點                                                                                        |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| 寶塔面板 / 傳統 Nginx 原始碼部署 | 官網站點「偽靜態」照抄[四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)第 2 節，見[寶塔部署](bt-panel.md) 4.2 節 | 留空即自動判定為 `file://` 直讀同機 `frontend-user-v3-www/dist/index.html`；也可顯式填 `SEO_FRONTEND_SHELL_URL=file:///绝对路径/frontend-user-v3-www/dist/index.html` | 內部轉發走 `127.0.0.1:80`；API 站點開了強制 HTTPS 時需對本機來源放行，否則迴環請求被 301 攔截 |
| Docker / 1Panel 編排           | 已內建在 `deploy/docker/frontends/nginx-default.conf`，無需重複配置                                                | 預設 `http://frontends:8081/index.html`（compose 內部網路）                                                                                                           | 見[Docker / 1Panel 部署](docker-1panel.md)                                                    |
| 裸機 systemd 部署              | 照抄[四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)第 2 節，見[裸機部署](bare-metal.md) 7.2 節                 | 同寶塔形態，留空自動判定                                                                                                                                              | 同樣注意 API 站點強制 HTTPS 的迴環跳轉                                                        |

### 10.2 環境變數

```bash
# 官网公开地址：canonical / sitemap / JSON-LD 都以它为准。
# 不填会退化成 API 域名，全站被规范化到非 HTML 端点。
SEO_SITE_URL=https://www.你的域名.com

# 前端 shell 模板地址。源码部署留空即自动判定为 file:// 直读同机构建产物；
# 容器部署留空即用 compose 内部网络地址。只有自定义路径时才需要显式填写。
# SEO_FRONTEND_SHELL_URL=file:///www/wwwroot/你的项目/frontend-user-v3-www/dist/index.html

# shell 模板 / 渲染结果缓存秒数。站点内容发布后靠内容版本号自动失效，
# 这两个值只决定静态兜底的最长延迟。
SEO_SHELL_CACHE_TTL=600
SEO_CACHE_TTL=300
```

### 10.3 哪些路徑必須交給後端渲染

- 首頁 `/`、`/products`、`/products/{商品ID}`、落地頁（`/cloud-server` 等）、`/about`、`/terms`、`/privacy`、`/notices`、`/notices/{id}`、`/help`、`/help/{id}`、`/open-source`
- `/robots.txt`、`/sitemap.xml`（必須由後端生成真實文本與 XML，不能落到 SPA 靜態回退）
- 其餘單段路徑轉給後端後返回品牌 404 殼 + HTTP 404，避免爬蟲把 SPA 裸殼 200 當成可索引頁面
- **多段路徑**（商品選購流程 `/products/{slug}/{groupId}/{childGroupId}/{productId}`）保持 SPA 靜態回退

> 通配轉發有個前提：官網自己的單段路由必須先在 `SeoRenderService::resolvePage()` 裡登記，否則會被轉成 404 殼。新增官網單段路由時同步在後端登記。

### 10.4 上線後自檢

```bash
# 1) robots 与 sitemap 必须是真实内容，不是 SPA 壳
curl -s https://www.你的域名.com/robots.txt | head -3
curl -s https://www.你的域名.com/sitemap.xml | head -c 200

# 2) 爬虫 UA 取首页：应有 canonical 与 JSON-LD，且没有 noindex
curl -s -A 'Mozilla/5.0 (compatible; Googlebot/2.1)' https://www.你的域名.com/ \
  | grep -E 'rel="canonical"|ld\+json|name="robots"'

# 3) 商品详情页应带 Product 结构化数据
curl -s -A 'Mozilla/5.0 (compatible; Googlebot/2.1)' https://www.你的域名.com/products/1 \
  | grep -o '"@type":"Product"'
```

自檢通過後，到百度搜索資源平臺提交 `https://www.你的域名.com/sitemap.xml`（站點已帶 `msvalidate.01` 驗證 meta），並在平臺跑一次「抓取診斷」確認即時抓取結果。

### 10.5 常見坑

| 現象                                 | 原因                                                                                   | 處理                                                        |
| ------------------------------------ | -------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| 爬蟲抓到空殼，原始碼裡有 `noindex`     | 偽靜態沒配，全部請求落進 `try_files ... /index.html`                                   | 按 10.1 補轉發規則                                          |
| `/robots.txt` 返回 HTML              | 靜態回退把該路徑也吞了                                                                 | 同上                                                        |
| robots 正常但頁面 500                | `SEO_FRONTEND_SHELL_URL` 指向了 Docker 服務名 `frontends:8081`，原始碼部署解析不了該主機 | 留空讓程式碼自動判定，或顯式填 `file://` 本機路徑             |
| 頁面有內容但沒有 canonical / JSON-LD | 該路徑沒在後端 `resolvePage()` 登記，返回的是 404 殼                                   | 補登記後重跑                                                |
| 內容是最舊的                         | 前端重建後 shell 快取未過期                                                            | 等 `SEO_SHELL_CACHE_TTL` 秒或清快取                         |
| 改了沒生效                           | 改了倉庫但沒重新構建前端 / 沒過載 Nginx                                                | 重新構建前端並重載 Nginx                                    |
| 首頁標題只有品牌名                   | 站點「瀏覽器標題」沒填關鍵詞                                                           | 後臺「站點設定 → 瀏覽器標題」填帶關鍵詞的值，前後端同一口徑 |

> 域名走 CDN 時回源規則同樣生效，但 CDN 可能快取舊殼，必要時在 CDN 側重新整理快取。

## 關聯文件

- [寶塔部署專案指南](bt-panel.md)：面板化部署流程。
- [Docker / 1Panel 部署指南](docker-1panel.md)：容器化部署。
- [裸機原始碼部署指南](bare-metal.md)：無面板、無容器部署。
- [部署與排程指南](scheduling.md)：排程、佇列與運維規則。
