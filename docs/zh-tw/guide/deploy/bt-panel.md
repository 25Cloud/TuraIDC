---
source: guide/deploy/bt-panel.md
sourceHash: 70479deb5dddee1c407ef8f96bb3e3a2c8d1b315b8b61232815cbe06d2d00c72
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/deploy/bt-panel.md 產生，請勿手動編輯。 -->
# 寶塔部署專案指南

面向全新伺服器的完整部署流程。已有生產環境運維請直接看 [部署與排程指南.md](scheduling.md)。

> 🧭 **從智簡魔方財務系統遷移**：若你正在使用智簡魔方財務（ZJMF）且要切換到 TuraIDC，請先閱讀 [從智簡魔方財務系統遷移](../migrate/zjmf-finance.md)，按 10 步流程完成資料遷移與上游對接後再接入本指南的站點配置。

---

## 1. 前置环境

### 1.1 宝塔面板安装

宝塔面板需已安装。未安装参考官方文档，推荐 Linux 系统（CentOS 7+ / Ubuntu 20.04+ / Debian 10+）。

登录宝塔面板后，进入**软件商店**安装以下运行环境：

| 软件               | 版本要求              | 说明                                                                                                                                      |
| ------------------ | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Nginx              | 1.20+                 | Web 服务器                                                                                                                                |
| PHP                | 8.3+                  | 安装向导环境检测硬性要求 PHP >= 8.3，装 8.2 会在检测环节被拦下                                                                            |
| MySQL              | 8.0（最低兼容 5.7.8） | 数据库；5.7 需在 my.cnf 设 `explicit_defaults_for_timestamp=ON`（避免隐式 ON UPDATE CURRENT_TIMESTAMP，详见 [deployment.md](generic.md)） |
| Redis              | 7.0+                  | 缓存                                                                                                                                      |
| phpMyAdmin         | —                     | 数据库管理（可选）                                                                                                                        |
| Node.js 版本管理器 | —                     | 管理 Node.js 版本                                                                                                                         |

### 1.2 PHP 关键配置

**安装 PHP 扩展**（PHP → 安装扩展）：

- `fileinfo`
- `redis`
- `opcache`
- `pdo_mysql`

**取消禁用函数**（PHP → 禁用函数 → 删除以下函数）：

- `putenv`
- `proc_open`
- `symlink`

改完重启 PHP 后执行 `php artisan app:doctor` 复核，必需扩展与禁用函数的真源是
`backend/app/Support/EnvironmentRequirements.php`，本清单只是它在该环境下的手工预配。

**PHP CLI 版本**：确保宝塔命令行使用的 PHP 与站点一致（宝塔 → 网站 → PHP 命令行版本）。

### 1.3 Node.js 配置

在 Node.js 版本管理器中安装 **Node.js 20.19+**（项目 package.json 约束的最低版本）。

### 1.4 DNS、网络与账户

- 先为 `api`、`www`、`console`、`admin` 四个域名添加 DNS 记录，并确认都解析到同一台服务器。
- 防火墙只对公网开放 `80` 和 `443`；MySQL、Redis 和 VNC Relay 的 `8100` 仅监听内网或 `127.0.0.1`，不要对公网放行。
- HTTPS 部署前先在宝塔申请并绑定四个站点的证书。四个公开地址必须统一为 HTTP 或 HTTPS；生产环境应使用 HTTPS。
- 记录宝塔站点运行用户（通常为 `www`）。后续仅授予该用户 `backend/storage` 与 `backend/bootstrap/cache` 的写权限。

---

## 2. 專案部署概覽

生產拓撲如下：

```
宝塔 Nginx
  ├── 站点1: 官网/用户入口 -> frontend-user-v3-www/dist （静态；公开 SEO 路径转发到 API 站点 PHP 动态渲染）
  ├── 站点2: 用户控制台   -> frontend-user-v4-console/dist（静态）
  ├── 站点3: 管理端       -> frontend-admin-v3/dist      （静态）
  └── 站点4: 后端 API     -> backend/public              （PHP-FPM）
                              ├── MySQL 8
                              ├── Redis
                              └── 宝塔计划任务（每分钟 php artisan schedule:run）
```

**關鍵理解**：

- 後端只通過 PHP-FPM 執行，不使用 `php artisan serve`
- 沒有常駐 Queue Worker，佇列消費併入 `schedule:run`（每分鐘）
- 四個站點獨立部署，可分配不同的域名/埠
- **官網 SEO**：官網公開頁面（首頁、產品、落地頁、公告/幫助及其詳情）在官網站點偽靜態中轉發到 API 站點，由 Laravel 讀資料庫動態渲染完整 HTML（站名/Logo/meta/正文）；sitemap.xml / robots.txt 由 Laravel 動態生成

資料庫釋出有兩條互斥路徑：

| 場景                 | 執行入口                        | 說明                                                                                |
| -------------------- | ------------------------------- | ----------------------------------------------------------------------------------- |
| 空資料庫首次部署     | `python3 scripts/install_db.py` | 匯入 `database/schema/mysql-schema.sql`、執行增量遷移、寫入預設配置並初始化管理員。 |
| 已有生產庫釋出新版本 | `php artisan migrate --force`   | 只執行尚未記錄的增量遷移；部署前先完成資料庫備份。                                  |

首次部署不要先手動執行 `php artisan migrate --force`，也不要對生產庫使用 `install_db.py --reset`。

---

## 3. 建立寶塔站點

### 3.1 建立後端 API 站點

寶塔 → 網站 → 新增站點：

| 配置項   | 值                                                                        |
| -------- | ------------------------------------------------------------------------- |
| 域名     | `api.你的域名.com`                                                        |
| 根目錄   | `你的项目路径/backend/public`                                             |
| PHP 版本 | PHP 8.3（須 ≥ 8.3.36，含 CVE-2026-109618 / CVE-2026-109619 修復的補丁版） |
| 資料庫   | 新建（後續匯入）                                                          |

### 3.2 建立前端站點

建立三個前端站點。**網站目錄填前端應用目錄，不要直接填 `dist`**（原因見 §6.1 的說明：
寶塔會在網站目錄生成不可刪除的 `.user.ini`，落在 `dist` 裡會導致前端構建必然失敗）：

| 站點          | 域名                   | 網站目錄                            | 執行目錄 |
| ------------- | ---------------------- | ----------------------------------- | -------- |
| 官網/使用者入口 | `www.你的域名.com`     | `项目路径/frontend-user-v3-www`     | `/dist`  |
| 使用者控制台    | `console.你的域名.com` | `项目路径/frontend-user-v4-console` | `/dist`  |
| 管理端        | `admin.你的域名.com`   | `项目路径/frontend-admin-v3`        | `/dist`  |

---

## 4. 寶塔站點與偽靜態配置

四個站點均使用寶塔建立時生成的預設 Nginx 配置。不要在“配置檔案”中替換或貼上完整 `server {}`，以免覆蓋寶塔生成的 SSL、PHP-FPM 和日誌設定。

在寶塔面板完成以下設定後，只編輯每個站點的“設定 → 偽靜態”：

| 站點       | 網站目錄                   | 執行目錄 | 寶塔面板設定             |
| ---------- | -------------------------- | -------- | ------------------------ |
| 官網       | `frontend-user-v3-www`     | `/dist`  | 靜態站點，申請 SSL。     |
| 使用者控制台 | `frontend-user-v4-console` | `/dist`  | 靜態站點，申請 SSL。     |
| 管理端     | `frontend-admin-v3`        | `/dist`  | 靜態站點，申請 SSL。     |
| API        | `backend/public`           | 留空     | 選擇 PHP 8.3，申請 SSL。 |

HTTPS 跳轉和證書通過寶塔“SSL”頁面配置；純 HTTP 環境不啟用強制 HTTPS，並將 `SESSION_SECURE_COOKIE=false`。四個公開地址必須使用相同協議。

### 4.1 後端 API 站點偽靜態

在 API 站點的“偽靜態”完整填入：

```nginx
# VNC WebSocket 仅由 API 站点转发到内部 Relay。
location ^~ /ws/vnc {
    proxy_http_version 1.1;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade           $http_upgrade;
    proxy_set_header Connection        "upgrade";
    proxy_buffering off;
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
    proxy_pass http://127.0.0.1:8100;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

專案未啟用 VNC 遠端控制時，可以刪除 `/ws/vnc` 的整個 `location` 塊，保留 Laravel 回退規則。寶塔預設 PHP 處理塊保持不變。

> 注：API 請求路徑是 PHP-FPM 直連，Laravel 以 `REMOTE_ADDR` 為準，不受上方 `X-Forwarded-*` 影響（這些頭只發給 VNC Relay）。若改為宿主機 Nginx/1Panel 反向代理 API 埠，必須遵守[受信代理與來源 IP 契約](scheduling.md)：單層受信代理把 `X-Forwarded-For` 重置為 `$remote_addr`，不要原樣透傳客戶端偽造的頭。

### 4.2 三個前端站點偽靜態

使用者控制台和管理端是純 Vue History 路由站點，在“偽靜態”中填入：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

**官網**除上述回退外，還需把公開 SEO 路徑轉發到 API 站點（Laravel 讀資料庫動態渲染完整 HTML），完整偽靜態為：

```nginx
# SEO 动态渲染：公开路径转发到 API 站点（Laravel 读库渲染 title/meta/正文，
# 站名与 Logo 实时取自数据库）。将 api.你的域名.com 换成实际 API 域名。
location = / {
    proxy_pass http://127.0.0.1/seo/www;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location ~ ^/(robots\.txt|sitemap\.xml)$ {
    proxy_pass http://127.0.0.1;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 落地页 / 关于 / 条款 / 隐私 / 开源许可 / 产品列表（单段路径，含尾斜杠变体）
location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|open-source|products)/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 公告/帮助列表与详情（/notices、/notices/123、/help、/help/123，含尾斜杠）
location ~ ^/(notices|help)(/[0-9]+)?/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 产品详情（/products/123；多段购买页路径保持 SPA 静态回退）
location ~ ^/products/[0-9]+/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 其余单段路径转发后端渲染：已登记路径返回可索引的完整 HTML，
# 未登记路径返回品牌 404 壳 + HTTP 404，避免爬虫把 SPA 裸壳 200 当可索引页面。
# 前提：官网自己的单段路由须先在后端 SeoRenderService::resolvePage() 登记，
# 否则登录 / 注册 / 购物车这类路径会被转成 404 壳。
location ~ ^/[^./]+/?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

location / {
    try_files $uri $uri/ /index.html;
}
```

> 內部轉發走 `127.0.0.1:80`（HTTP）。若 API 站點在寶塔“SSL”中開啟了“強制 HTTPS”，80 埠會 301 跳轉並攔截內部轉發，需在 API 站點 Nginx 配置中對本機來源放行（`location = /` 等規則加 `if ($remote_addr != 127.0.0.1) { return 301 ...; }` 判斷），或將上述 `proxy_pass` 改為 `https://api.你的域名.com/...`（寶塔已配證書，同機迴環可正常握手）。後端已信任迴環/私有網段代理（`bootstrap/app.php` 的 `trustProxies`），`X-Forwarded-Proto` 可正確傳遞 https。

不要在三個前端站點新增 `/api`、`/uploads`、`/media` 或 `/ws/vnc` 的代理。瀏覽器直接訪問 API 域名；`/vnc/vnc.html` 是控制台的靜態構建產物，會由該規則直接命中。

### 4.3 非偽靜態項

- SSL 證書、強制 HTTPS 和站點根目錄：寶塔“網站”頁面設定。
- API 的 PHP 版本與 PHP-FPM：寶塔站點的 PHP 設定。
- 上傳大小：在寶塔 PHP 設定中同時調整 `upload_max_filesize` 和 `post_max_size`；站點級 Nginx 上傳限制僅在實際遇到 413 時通過面板提供的對應設定調整。
- 儲存偽靜態後使用寶塔的 Nginx 配置檢查與過載功能；無需手工編輯 Nginx 配置檔案。

---

## 5. 後端部署

### 5.1 上傳程式碼

將專案程式碼上傳至伺服器，例如 `/www/wwwroot/turaidc`。

推薦方式：

- **Git 克隆**：寶塔 → 軟體商店 → Git 外掛，或手動 `git clone`
- **寶塔檔案上傳**：壓縮包上傳後解壓

### 5.2 配置 .env

```bash
cd /www/wwwroot/你的项目/backend
cp .env.example .env
```

編輯 `.env`，修改以下必填項：

```ini
APP_NAME="图拉云"
APP_ENV=production
APP_DEBUG=false
APP_KEY=                            # 首次初始化脚本会在为空时生成
APP_URL=https://api.你的域名.com
FRONTEND_URL=https://www.你的域名.com
CLIENT_CONSOLE_URL=https://console.你的域名.com
ADMIN_URL=https://admin.你的域名.com
CLIENT_SESSION_COOKIE_DOMAIN=.你的域名.com
SESSION_SECURE_COOKIE=true
INSTALL_ADMIN_PASSWORD=请设置至少12位强密码

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=你的数据库名
DB_USERNAME=你的数据库用户
DB_PASSWORD=你的数据库密码

CACHE_STORE=redis
QUEUE_CONNECTION=database
SESSION_DRIVER=file

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=你的Redis密码
```

`INSTALL_ADMIN_PASSWORD` 是首次初始化預設管理員所需的臨時配置；生產環境不能為空、不能使用預設值且長度至少為 12 位。請通過受控渠道儲存它，不要提交 `.env` 或把實際密碼寫入計劃任務、Git 或本文件。

官網 SEO 動態渲染的選填配置（原始碼部署建議顯式填寫，預設值見 `config/idc.php → idc.seo`）：

```ini
# 官网公开地址，用于 canonical / sitemap / JSON-LD；默认取 APP_URL
SEO_SITE_URL=https://www.你的域名.com
# 官网 index.html 模板：源码部署直接读本机前端产物文件（零网络依赖）
SEO_FRONTEND_SHELL_URL=file:///www/wwwroot/你的项目/frontend-user-v3-www/dist/index.html
# shell 模板 / 页面渲染结果缓存秒数（默认 600 / 300）
SEO_SHELL_CACHE_TTL=600
SEO_CACHE_TTL=300
```

> `file://` 為直接讀檔案方式，路徑必須與官網站點根目錄（`frontend-user-v3-www/dist`）一致；用 HTTP 方式（如 `http://127.0.0.1:端口/index.html`）亦可，但需保證該埠返回的是**原始 index.html**（而非被強制 HTTPS 跳轉或回退改寫的內容）。前端重新構建後，最遲在 `SEO_SHELL_CACHE_TTL` 秒後新產物生效。

根目錄統一構建前端時，會從 `backend/.env` 注入 `APP_URL`、`FRONTEND_URL`、`CLIENT_CONSOLE_URL`、`ADMIN_URL`，並覆蓋各前端目錄的同名預設值。因此應先完成上面的四個公開地址配置，再構建前端。

### 5.3 安裝後端依賴

```bash
cd /www/wwwroot/你的项目/backend

# 安装依赖（生产环境跳过 dev）
composer install --no-dev --optimize-autoloader
```

### 5.4 初始化空資料庫

僅對尚未承載業務資料的空資料庫執行。指令碼會在缺少時生成 `APP_KEY`，然後匯入 schema baseline、執行增量遷移、初始化預設配置和管理員：

```bash
cd /www/wwwroot/你的项目/backend
python3 scripts/install_db.py
```

不要額外執行 `php artisan key:generate` 或首次 `php artisan migrate --force`；上述指令碼已按正確順序完成這些步驟。指令碼使用的資料庫賬戶需要有建立目標庫、匯入表結構和執行遷移的許可權。

### 5.5 釋出到已有資料庫

對已有生產資料庫，先完成可恢復的備份，再執行：

```bash
cd /www/wwwroot/你的项目/backend

# 仅执行未记录的增量迁移
php artisan migrate --force

# 清缓存并生成生产缓存
php artisan optimize:clear
php artisan config:cache && php artisan route:cache
```

不要在生產庫上使用 `migrate:fresh`、`migrate:reset` 或 `install_db.py --reset`。

### 5.6 設定檔案許可權

```bash
cd /www/wwwroot/你的项目/backend

sudo chown -R www:www storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 public/uploads public/media
```

### 5.7 重啟 PHP

寶塔面板 → 軟體商店 → PHP → 重啟，使生產快取生效。

---

## 6. 前端部署

### 6.1 構建前端

確保 Node.js 版本滿足要求後，在專案根目錄只安裝一次 workspace 依賴並統一構建：

```bash
cd /www/wwwroot/你的项目

# 先校验 backend/.env 中注入的四个公开地址和协议是否一致，不生成文件。
# 注意不要写成 `-- --dry-run`：pnpm 9+ 会把 `--` 原样透传给脚本，
# build_frontends.mjs 会抛「不支持的参数：--」。
pnpm run build:frontends --dry-run

# --shamefully-hoist：本项目依赖根 hoist 布局（如 element-plus），避免 monorepo 依赖布局不一致导致构建失败；
# --config.verify-deps-before-run=false：跳过 pnpm 10+ 默认的依赖预校验，适用于宝塔等先 install 再构建的部署流程。
# CI=true：无 TTY 的部署环境下，pnpm 11 清理 node_modules 前会等待交互确认并直接中止
#（ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY），设置后自动放行。
CI=true pnpm install --frozen-lockfile --shamefully-hoist --config.verify-deps-before-run=false
pnpm run build:frontends
# 产物仍分别位于三个前端目录的 dist/，不会写入 backend/public。
```

> **寶塔站點根目錄不要直接指向 `dist/`。** 寶塔的「防跨站攻擊」會在站點根目錄生成 `.user.ini`
> 並加上 `chattr +i` 不可修改屬性；若根目錄就是 `dist/`，Vite 構建清空產物目錄時會撞上這個
> 刪不掉的檔案，報 `ENOTDIR: not a directory, scandir '.../dist/.user.ini'` 導致構建必然失敗。
> 正確做法與 API 站點一致：「網站目錄」填應用目錄（如 `项目路径/frontend-admin-v3`），
> 「執行目錄」填 `/dist`，讓 `.user.ini` 落在 `dist` 之外。

> 如使用獨立 CDN 域名部署靜態資源，在各專案 `.env` 中配置 `VITE_ADMIN_ASSET_BASE_URL` / `VITE_CONSOLE_ASSET_BASE_URL` / `VITE_WWW_ASSET_BASE_URL`。預設以根路徑 `/` 釋出。

### 6.2 驗證站點

構建完成後，在瀏覽器中分別訪問四個站點域名，確認頁面正常載入，API 請求正常。

---

## 7. 計劃任務配置

寶塔面板 → 計劃任務 → 新增：

- **任務型別**：Shell 指令碼
- **任務名稱**：`Laravel 调度`
- **執行週期**：每分鐘
- **指令碼內容**：

```bash
cd /www/wwwroot/你的项目/backend
php artisan schedule:run >> /dev/null 2>&1
```

> 這條計劃任務每分鐘驅動心跳派發（15 分鐘槽位去重）、`queue:drain` 後臺佇列消費（按 `provision`、業務組 `referral,notification,coupon,default`、定時組 `automation` 三個佇列組分別建 Worker）與存活探針。不要額外配置覆蓋同一佇列的 `queue:work`，避免重複消費。

同時在寶塔程序守護中常駐：

```bash
cd /www/wwwroot/你的项目/backend
php artisan vnc:relay
```

確認 `127.0.0.1:8100` 監聽後再部署新程式碼；Relay 由守護程序自動重啟，`vnc:ensure-relay` 僅用於手工健康檢查。

計劃任務中的 `php` 必須是與 API 站點相同的 PHP 8.3 CLI。若寶塔預設 CLI 版本不一致，將命令中的 `php` 替換為實際路徑，例如 `/www/server/php/83/bin/php`。

---

## 8. 匯入舊庫（僅資料遷移）

全新空庫已由第 5 節的 `install_db.py` 完成初始化。以下步驟只用於有專項遷移方案的舊庫資料匯入，執行前必須核對目標庫並建立獨立備份：

```bash
cd /www/wwwroot/你的项目/backend
python3 scripts/migrate_legacy_dump.py --dump "你的dump文件.sql" --dry-run
# 确认无误后执行
python3 scripts/migrate_legacy_dump.py --dump "你的dump文件.sql"
```

> 執行前務必確認目標庫、備份和 `.env`。遷移指令碼會修改資料，建議先備份。

---

## 9. 常見問題

### 9.1 502 Bad Gateway

通常原因是 PHP-FPM 未執行或 API 站點未選中可用的 PHP 版本：

- 檢查 PHP 是否啟動（寶塔 → 軟體商店 → PHP → 狀態）
- 在 API 站點的“PHP 版本”中重新選擇 PHP 8.3，然後重啟對應 PHP 服務
- 不要手工修改寶塔生成的 `fastcgi_pass` socket 配置

### 9.2 頁面重新整理 404

前端 SPA 站點未配置 Vue Router History 模式的回退規則。在對應站點的 Nginx 配置中確保包含：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### 9.3 Composer 報 `putenv()` 等函式未定義

寶塔 PHP 的停用函式列表阻止了某些函式。進入寶塔 → 軟體商店 → PHP → 停用函式，刪除 `putenv`、`proc_open`、`symlink`，然後重啟 PHP。

### 9.4 上傳檔案過大

在寶塔“PHP → 配置修改”中同時增大 `upload_max_filesize` 和 `post_max_size`，並重啟 PHP。若仍返回 HTTP 413，在寶塔站點設定中調整上傳限制；不要為了這一項替換完整 Nginx 配置。

### 9.5 定時任務未執行

- 檢查寶塔計劃任務日誌是否正常
- 執行 `php artisan schedule:run` 手動測試
- 確認 `.env` 中 `QUEUE_CONNECTION=database`、`TURAIDC_BUSINESS_QUEUES` 和 `TURAIDC_SCHEDULE_QUEUE` 配置正確，且 `127.0.0.1:8100` 有獨立 Relay 程序監聽

### 9.6 路由或配置更新不生效

上線後依次執行：

```bash
cd /www/wwwroot/你的项目/backend
php artisan route:clear && php artisan config:clear && php artisan cache:clear
php artisan route:cache && php artisan config:cache
```

然後重啟 PHP-FPM（寶塔 → PHP → 重啟）。

---

## 10. 運維參考

以下日常運維操作的詳細說明在 [部署與排程指南.md](scheduling.md)：

- 檢視排程執行情況
- 手動觸發一次排程 / 佇列消費
- 清理失敗佇列
- 回滾策略
- 監控與告警建議

---

## 11. 檢查清單

部署完成後逐項確認：

- [ ] 四個站點均可通過 HTTPS 訪問
- [ ] 後端 API 返回正常 JSON（訪問 `https://api.你的域名.com/api/health`）
- [ ] 就緒檢查返回 HTTP 200（訪問 `https://api.你的域名.com/api/ready`；該檢查會驗證資料庫、快取、儲存和排程狀態）
- [ ] 前端頁面重新整理不 404
- [ ] 管理端可使用首次初始化時單獨設定的管理員賬號登入
- [ ] 寶塔計劃任務已新增且正常執行
- [ ] PHP `APP_DEBUG=false`
- [ ] `.env` 中密碼均為強密碼
- [ ] Redis 可連線（`redis-cli ping` 返回 PONG）
- [ ] `storage`、`bootstrap/cache` 許可權為 `www:www` 且可寫
- [ ] 資料庫遷移已執行無報錯

## 關聯文件

- [部署指南](generic.md)：通用部署步驟。
- [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)：站點偽靜態規則。
- [部署與排程指南](scheduling.md)：排程與佇列配置。
