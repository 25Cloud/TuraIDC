---
source: guide/deploy/docker-1panel.md
sourceHash: 2e06393b03e23d045a10ddfe6e2b2a4f297ce856cc73ddb43f88653fe79daeba
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/deploy/docker-1panel.md 產生，請勿手動編輯。 -->
# Docker / 1Panel 部署指南

面向全新伺服器、使用容器化方式部署 TuraIDC 的完整指南。使用 Docker Compose 一鍵拉起全部服務，配合 GitHub Actions 自動構建推送映象，伺服器只負責拉取執行，不再逐端手工部署；也可直接用 1Panel 的編排與反代能力託管同一套 Compose 檔案。

- 對齊時間：`2026-08-22`
- 傳統寶塔部署見 [寶塔部署專案指南](bt-panel.md)，現網運維口徑見 [部署與排程指南](scheduling.md)
- 部署檔案位於倉庫根 `deploy/docker/`，唯一需要改的配置是 `deploy/docker/.env`
- CI 構建推送見 `.github/workflows/docker-image.yml`
- 🧭 **從智簡魔方財務系統遷移**：部署前若需匯入智簡魔方財務（ZJMF）老站資料，先閱讀 [從智簡魔方財務系統遷移](../migrate/zjmf-finance.md)（產品/使用者/訂單/上游/實名全流程與踩坑記錄）

## 1. 容器拓撲

```
deploy/docker/docker-compose.yml  (project: turaidc)
├── mysql (mysql:8.0, 命名卷 mysql-data)      [可选：DB_HOST 留空时才拉取并启动]
├── redis (redis:7-alpine, 命名卷 redis-data) [可选：REDIS_HOST 留空时才拉取并启动]
├── app   (后端镜像, :80)
│     ├── php-fpm   (PHP 8.3, 含 pdo_mysql/redis/pcntl/opcache/gd/zip/intl/bcmath；镜像用 php:8.3-fpm 浮动标签，重建即取最新补丁版，须 ≥ 8.3.36 以含 CVE-2026-109618 / CVE-2026-109619 修复)
│     ├── nginx     (API + /ws/vnc WebSocket -> 127.0.0.1:8100)
│     ├── cron      (每分钟 php artisan schedule:run，驱动心跳与队列消费)
│     └── vnc:relay (VNC WebSocket 中继, 127.0.0.1:8100)
│     命名卷: app-storage / app-uploads / app-media
├── frontends (nginx:alpine, 三端口: 8081=官网 / 8082=控制台 / 8083=管理端)
│     └── 官网公开路径转发到 app 的 /seo/www/*（SEO 动态渲染）
```

官網 SEO：`frontends` 容器內 Nginx 將官網公開路徑（首頁、產品、落地頁、公告/幫助及其詳情）`proxy_pass` 到 `app` 容器的 `/seo/www/{path}`；Laravel 讀資料庫生成完整 HTML（站名/Logo/meta/正文），並以 `http://frontends:8081/index.html` 為模板（容器內部網路直連靜態檔案，無迴圈）。`/sitemap.xml`、`/robots.txt` 由 Laravel 動態生成。

`DB_HOST` / `REDIS_HOST` 留空（預設）時本地 mysql / redis 容器隨編排啟動；填寫遠端地址則對應本地容器不建立、映象也不被 `docker compose pull` 拉取，app 直連遠端服務，兩者互相獨立可單獨切換。

映象流向：GitHub Actions 構建並推送 `ghcr.io/<owner>/turaidc-{app,frontends}` → 伺服器 `docker compose pull`。

與寶塔生產拓撲一一對應：前端三端合一（一個容器三埠分別託管 www/console/admin 靜態站點）、後端走 PHP-FPM、無常駐 `queue:work`（由每分鐘 `schedule:run` 並行消費業務佇列與 `automation` 佇列）、VNC Relay 獨立常駐。

## 2. 前置條件

- 伺服器：Linux（Ubuntu 20.04+/Debian 11+/CentOS 7+），記憶體建議 4G+，磁碟 20G+
- Docker Engine 24+ 與 Compose v2（`docker compose version` 可查）
- 四個域名解析到本機（`api`、`www`、`console`、`admin`），生產環境必須同一協議（HTTPS）
- GitHub 倉庫（當前 `github.com/25Cloud/TuraIDC`），用於 CI 構建與 GHCR 映象託管

## 3. 配置說明（deploy/docker/.env）

先複製模板並編輯：

```bash
cd deploy/docker
cp .env.example .env
vim .env
```

| 配置項                                                             | 必填 | 說明                                                                                                                                                                |
| ------------------------------------------------------------------ | ---- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `APP_URL` / `FRONTEND_URL` / `CLIENT_CONSOLE_URL` / `ADMIN_URL`    | 是   | 四個公開地址，互不相同、同一協議、無路徑；`https://api.example.com` 形式                                                                                            |
| `CLIENT_SESSION_COOKIE_DOMAIN`                                     | 否   | 官網/控制台跨子域共享登入態時填父域如 `.example.com`；單域留空                                                                                                      |
| `REGISTRY` / `IMAGE_NAMESPACE` / `IMAGE_TAG`                       | 否   | 映象倉庫地址，預設 `ghcr.io` / `25cloud` / `latest`，與 CI 推送目標一致                                                                                             |
| `APP_KEY`                                                          | 否   | **不要在此檔案設定**。容器啟動時自動生成並寫入 `backend/.env`。若在此設空值，Docker env_file 會注入容器環境變數，Dotenv 不覆蓋已存在環境變數，導致生成的 key 被忽略 |
| `INSTALL_ADMIN_PASSWORD`                                           | 是   | 首次初始化預設管理員 `cerbo` 的密碼，至少 12 位強密碼，僅空庫首次生效                                                                                               |
| `SESSION_SECURE_COOKIE`                                            | 是   | HTTPS 環境 `true`，純 HTTP 環境 `false`                                                                                                                             |
| `DB_HOST` / `DB_PORT`                                              | 否   | 資料庫來源：留空使用編排內 mysql 容器（預設）；填寫遠端地址則本地 mysql 不拉取不啟動，app 直連遠端庫，埠預設 3306                                                 |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` / `DB_ROOT_PASSWORD` | 是   | 資料庫名與賬號；同時用於 mysql 容器和 app 容器（遠端模式仍需填寫供 app 連線）                                                                                       |
| `REDIS_HOST` / `REDIS_PORT`                                        | 否   | Redis 來源：留空使用編排內 redis 容器（預設）；填寫遠端地址則本地 redis 不拉取不啟動，app 直連遠端，埠預設 6379                                                   |
| `REDIS_PASSWORD`                                                   | 否   | Redis 密碼；預設空。公網切勿放行 6379；遠端模式必須與遠端例項一致                                                                                                   |
| `API_PORT` / `WWW_PORT` / `CONSOLE_PORT` / `ADMIN_PORT`            | 否   | 宿主機對映埠，預設 8080/8081/8082/8083                                                                                                                            |
| `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER`              | 否   | 與現網口徑一致：redis / database / file，一般不用改                                                                                                                 |
| `SENTRY_LARAVEL_DSN`                                               | 否   | Sentry DSN，留空關閉                                                                                                                                                |
| `MAIL_FROM_ADDRESS`                                                | 否   | 預設發件人；SMTP 憑據由管理端"郵件外掛"配置                                                                                                                         |
| `SEO_SITE_URL`                                                     | 否   | 官網公開地址，用於 canonical / sitemap / JSON-LD；預設取 `APP_URL`，多域名部署時建議顯式填官網域名                                                                  |
| `SEO_FRONTEND_SHELL_URL`                                           | 否   | 官網 index.html 模板地址，預設 `http://frontends:8081/index.html`（compose 內部網路，一般無需配置）                                                                 |
| `SEO_SHELL_CACHE_TTL` / `SEO_CACHE_TTL`                            | 否   | shell 模板 / 頁面渲染結果快取秒數，預設 600 / 300；前端重新構建後最長延遲對應時長才生效                                                                             |

> 約束：密碼與地址值不要包含雙引號 `"` 和反斜槓 `\`，避免破壞 `.env` 解析。`.env` 已被 `.dockerignore` 排除，不會進映象、不會入庫。
>
> 遠端資料庫 / Redis 模式：`DB_HOST` 或 `REDIS_HOST` 填寫遠端地址後，對應本地容器不會被建立，`docker compose pull` 也不拉取其映象。要求伺服器 Compose v2.20+（依賴跳過用 `required: false`）；遠端庫需自行建庫、對伺服器 IP 授權；資料庫結構與初始化仍由 app 容器 entrypoint 在啟動時執行。

## 4. CI 自動打包推送

`.github/workflows/docker-image.yml` 在以下時機觸發構建：

| 觸發                                | 說明                                            |
| ----------------------------------- | ----------------------------------------------- |
| push 到 `main` / `master`           | 常規發版，推 `latest` + `main` + `sha-<短哈希>` |
| push 任意 tag（如 `v1.2.0`）        | 推 `1.2.0`、`1.2`、`latest` + `sha-<短哈希>`    |
| 手動觸發（Actions 頁 Run workflow） | 需要時手動出一版                                |

構建產物（GHCR，包名全小寫）：

```
ghcr.io/<owner>/turaidc-app
ghcr.io/<owner>/turaidc-frontends
```

### 4.1 首次使用需要配置

1. 倉庫 **Settings → Secrets and variables → Actions → New repository secret**，新增：

   | Secret                         | 示例                          | 說明                                           |
   | ------------------------------ | ----------------------------- | ---------------------------------------------- |
   | `APP_URL`                      | `https://api.example.com`     | 必填，前端構建注入 API 地址                    |
   | `FRONTEND_URL`                 | `https://www.example.com`     | 必填                                           |
   | `CLIENT_CONSOLE_URL`           | `https://console.example.com` | 必填                                           |
   | `ADMIN_URL`                    | `https://admin.example.com`   | 必填                                           |
   | `CLIENT_SESSION_COOKIE_DOMAIN` | `.example.com`                | 選填，跨子域共享登入態時配置；不配置則構建傳空 |

   四個公開地址未配置時前端構建會直接失敗（`build_frontends.mjs` 校驗），這是有意的，提醒你補全。

2. 首次推送後，開啟 GitHub → 頭像 → Your packages（或倉庫右側 Packages），把 2 個 `turaidc-*` 包設為 **public**，伺服器才能匿名拉取；私有包需要在伺服器 `docker login ghcr.io`。

### 4.2 切換映象倉庫（如騰訊雲 TCR / 阿里雲 ACR）

國內伺服器拉 GHCR 較慢時可切到國內映象倉庫：

1. 在對應雲廠商開通個人版容器映象服務，建立 `turaidc-app` / `turaidc-frontends` 兩個倉庫。
2. 在倉庫增加 `REGISTRY` 與 `IMAGE_NAMESPACE` 的 Secrets/變數（如 `REGISTRY=ccr.ccs.tencentyun.com`），並在 workflow 的 `env.REGISTRY` 處改用對應值。
3. 在 workflow 中把 GHCR 登入步驟換成目標倉庫的使用者名稱/密碼（或雲廠商的長期憑證 Secret），並給對應 `login-action` 配置。
4. 伺服器 `.env` 中同步修改 `REGISTRY` / `IMAGE_NAMESPACE`。

## 5. Docker Compose 部署

### 5.1 上傳程式碼

```bash
git clone <你的仓库地址> /opt/turaidc && cd /opt/turaidc
```

### 5.2 配置並啟動

```bash
cd deploy/docker
cp .env.example .env
vim .env            # 填写四个地址、密码等

# 拉取 CI 推送的镜像并启动（推荐）
docker compose pull && docker compose up -d
# 或本地直接构建（无镜像仓库时）
docker compose up -d --build
```

首次啟動（空庫）自動執行 `install_db.py`：匯入 schema baseline、執行增量遷移、寫入預設配置、建立管理員 `cerbo`。

### 5.3 驗證

```bash
docker compose ps                      # 全部 running
curl http://127.0.0.1:8080/api/health  # {"status":"ok"} 类响应
curl http://127.0.0.1:8080/api/ready   # DB/Cache/Storage/Scheduler 就绪检查
```

瀏覽器訪問 `http://服务器IP:8081`（官網）、`:8082`（控制台）、`:8083`（管理端），用 `cerbo` + `INSTALL_ADMIN_PASSWORD` 登入管理端。

## 6. 1Panel 部署

1Panel（https://1panel.cn）自带 Docker 與 Compose 編排，推薦流程：

### 6.1 上傳程式碼

面板 → 檔案，將倉庫上傳/解壓到 `/opt/turaidc`，或 SSH 裡 `git clone`。

### 6.2 準備 .env

SSH 執行：

```bash
cd /opt/turaidc/deploy/docker
cp .env.example .env
vim .env
```

### 6.3 建立編排

面板 → 容器 → 編排 → 建立編排：

| 配置項 | 值                                              |
| ------ | ----------------------------------------------- |
| 名稱   | `turaidc`                                       |
| 來源   | 本機路徑                                        |
| 路徑   | `/opt/turaidc/deploy/docker/docker-compose.yml` |

建立後點擊"構建並啟動"（1Panel 會自動執行 `pull`/`build`）。檢視"容器"頁確認 `turaidc-mysql`、`turaidc-app`、`turaidc-frontends` 等 4 個容器均執行；點 `turaidc-app` 的日誌確認 entrypoint 完成資料庫初始化。

> 編排內變數來自同目錄 `.env`，與命令列 `docker compose` 行為一致。

### 6.4 反向代理與 HTTPS

面板 → 網站 → 建立網站 → 反向代理，為四個域名各建一條：

| 域名               | 目標地址                |
| ------------------ | ----------------------- |
| `api.你的域名`     | `http://127.0.0.1:8080` |
| `www.你的域名`     | `http://127.0.0.1:8081` |
| `console.你的域名` | `http://127.0.0.1:8082` |
| `admin.你的域名`   | `http://127.0.0.1:8083` |

面板 → 證書 → 申請證書（Let's Encrypt，四個域名可合併一張泛域名證書），繫結到對應網站並開啟強制 HTTPS。1Panel 反代預設支援 WebSocket，`/ws/vnc` 可直接穿透；若控制台 VNC 連不上，檢查該反代是否保留了 `Upgrade`/`Connection` 頭。

### 6.5 安全收口

反代生效後，把 `.env` 中四個埠改成僅本機監聽（避免公網直連 8080 等埠），然後重建容器：

```bash
cd /opt/turaidc/deploy/docker
# 例如 API_PORT=127.0.0.1:8080 其余同理
docker compose up -d
```

面板 → 防火牆只放行 `80`/`443`（與面板埠）。

## 7. 純 Docker 環境的 HTTPS

不使用 1Panel 時，可用宿主機 Nginx/Caddy 或 Cloudflare 反代到四個埠。宿主機 Nginx 示例（每個域名一個 server）：

```nginx
# WebSocket 升级映射放在 http 块（例如 /etc/nginx/conf.d/ 下的公共片段）
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

# 80 端口统一跳转，避免用户直接访问 http 得到 404
server {
    listen 80;
    server_name api.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;                       # nginx < 1.25.1 写作 listen 443 ssl http2;
    server_name api.example.com;
    ssl_certificate     /etc/nginx/certs/api/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/api/privkey.pem;

    client_max_body_size 100m;

    # 关键：转发头必须放在 server 级。nginx 的 proxy_set_header 不跨 location 继承，
    # 若只写在 location / 内，下面的 /ws/vnc 因为自己声明了 proxy_set_header，
    # 会完全拿不到 Host / X-Forwarded-* —— Host 退化为 $proxy_host（127.0.0.1:8080），
    # 导致 VNC token 的 Origin 校验与 wss 地址推导失败。
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    location / {
        proxy_pass http://127.0.0.1:8080;
    }

    # VNC WebSocket 需升级头
    # 注意：location 里一旦出现 proxy_set_header，就不再继承 server 级的同类指令，
    # 因此 Host / X-Real-IP / X-Forwarded-For / X-Forwarded-Proto 必须在这里重复声明，
    # 否则 VNC 通道会丢失真实客户端 IP 与 HTTPS 标识（后端据此判定 isSecure()）。
    location /ws/vnc {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade    $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_buffering off;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
}
```

前端站點去掉 WebSocket 段即可。要求：四個地址協議統一（全 HTTPS）、`.env` 中 `SESSION_SECURE_COOKIE=true`。

`X-Forwarded-Proto: https` 是後端識別 HTTPS 的唯一依據：容器內 `deploy/docker/backend/nginx.conf` 用 `map` 把它轉成 `fastcgi_param HTTPS on`，Laravel 的 `isSecure()` 才會返回 true。反代若不傳該頭，HTTPS 環境下後端仍按 HTTP 處理。

> **來源 IP 契約**：後端 `trustProxies` 只信任迴環與 RFC1918/ULA 私網段並採信 `X-Forwarded-For`，`request()->ip()` 與 `/upload_image` 白名單/限流都依賴它。上面的 `$proxy_add_x_forwarded_for` 會保留客戶端自帶 XFF，公網客戶端可藉此偽造來源。單層受信反代應把該頭重置為 `$remote_addr`（多層受信代理且逐級清洗時才可追加），並把 API 埠改為僅本機繫結（`127.0.0.1:8080`），不要向公網暴露容器埠。完整契約見 [部署與排程指南](scheduling.md) 的“受信代理與來源 IP 契約”。

壓縮已由容器內部負責：`frontends` 容器對靜態資源配置了 gzip 與 `gzip_static`（優先返回構建期生成的 `.gz`），`backend/nginx.conf` 也已為 API 的 JSON 響應開啟 gzip。宿主機 Nginx **不需要**為這兩類響應再配一遍——代理響應帶 `Content-Encoding` 時 Nginx 不會二次壓縮。

若宿主機上還有其他不經這兩個容器的響應需要壓縮（例如宿主機直接提供的靜態檔案或第三方上游），可在 http 塊按需加入。注意 Nginx 預設 `gzip off`，不存在“自動生效的全域性壓縮”：

```nginx
gzip            on;
gzip_vary       on;
gzip_min_length 1024;
gzip_proxied    any;               # 必须，否则代理响应不会被压缩
gzip_types      text/plain text/css application/javascript application/json
                application/xml image/svg+xml;
```

## 8. 升級與回滾

### 8.1 升級（拉取 CI 新映象）

```bash
cd /opt/turaidc
git pull                      # 更新 compose 与 .env 模板（若有变化）
cd deploy/docker
docker compose pull           # 拉取 CI 推送的最新镜像
docker compose up -d          # 增量迁移由 app 容器启动时自动执行（migrate --force）
docker compose ps
```

> 升級前先備份資料庫（見下節）。遷移失敗時檢視 `docker compose logs app`。

### 8.2 回滾

指定舊映象 tag 重新拉起：

```bash
cd deploy/docker
sed -i 's/^IMAGE_TAG=.*/IMAGE_TAG=<上一版本或 sha-xxx>/' .env
docker compose pull && docker compose up -d
```

資料庫遷移原則上不可逆；需回滾資料庫時用備份還原，禁止對生產庫執行 `migrate:rollback` 不可逆遷移。

## 9. 備份與恢復

### 9.1 定時備份（宿主機 crontab）

```bash
0 3 * * * cd /opt/turaidc/deploy/docker && ./backup.sh >> /var/log/turaidc-backup.log 2>&1
```

備份檔案位於 `deploy/docker/backups/turaidc-<时间戳>.sql.gz`，預設保留 14 天（`./backup.sh 30` 可改）。

### 9.2 手動備份

```bash
cd /opt/turaidc/deploy/docker && ./backup.sh
```

### 9.3 恢復

```bash
gunzip -c backups/turaidc-xxx.sql.gz | docker compose exec -T mysql \
  sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

## 10. 運維命令速查

```bash
docker compose ps                        # 状态
docker compose logs -f app               # 后端日志（php-fpm/nginx/cron/relay 均走 stdout）
docker compose logs -f frontends          # 前端日志
docker compose exec app bash             # 进入后端容器
docker compose exec app php artisan schedule:run   # 手动触发一次调度
docker compose exec app php artisan queue:retry all # 重试失败队列
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
docker compose restart app               # 重启后端
```

後端日誌檔案 `storage/logs/laravel.log` 在命名卷 `app-storage` 中；健康檢查：`/api/health`（存活）、`/api/ready`（就緒）。

## 11. 常見問題

### 11.1 首次啟動後管理端登入不上

確認空庫首次啟動完成了 `install_db.py`（`docker compose logs app` 中有 "執行 install_db.py" 且無報錯）；管理員為 `cerbo` + `.env` 的 `INSTALL_ADMIN_PASSWORD`（僅首次生效，之後請立即在管理端改密）。

### 11.2 502 / 504

- 檢視 `docker compose logs app`：php-fpm 未隨 supervisor 拉起、或 MySQL 未就緒導致 entrypoint 卡住時表現為 502
- 確認 `mysql` 容器 healthcheck 通過（`docker compose ps` 中 healthy）
- `APP_KEY` 缺失會導致 Laravel 500：確認 `docker compose exec app sh -c 'grep ^APP_KEY= .env'` 有值

### 11.3 拉取映象 401 / 拉不下來

- CI 首次推送後 GHCR 包預設 private：去 Packages 頁把 2 個 `turaidc-*` 包設為 public
- 或伺服器執行 `docker login ghcr.io` 用 PAT 登入
- 國內拉取慢可切騰訊雲/阿里雲映象倉庫（見 4.2）

### 11.4 CI 構建失敗

- 檢查倉庫 Actions secrets 是否配齊四個公開地址（失敗資訊裡會提示"必須是 HTTP(S) 根地址"或缺失）
- 檢查 `ghcr.io` 登入：首次構建需 GITHUB_TOKEN 具備 `packages: write`（workflow 已宣告 permissions）

### 11.5 頁面重新整理 404

前端 nginx 已內建 `try_files $uri $uri/ /index.html;`；若仍 404，確認訪問的是前端容器（8081/8082/8083）而非 API 埠。

### 11.6 時區 / 時間不對

app 容器 `TZ=Asia/Shanghai` 已在編排中設定；MySQL 通過 `--default-time-zone=+08:00` 設定；`.env` 中 `DB_TIMEZONE=+08:00`。

### 11.7 上傳檔案過大

預設 `client_max_body_size 100m`、`upload_max_filesize=100M`、`post_max_size=120M`；需要更大時同時改 `deploy/docker/backend/nginx.conf` 與 `backend/php.ini` 後重建映象。

### 11.8 改了 .env 不生效

容器啟動時由 entrypoint 重新生成 `backend/.env` 並 `config:cache`，因此修改 `deploy/docker/.env` 後必須重啟容器：

```bash
docker compose up -d --force-recreate app
```

四個公開地址變更需要重新構建/拉取前端映象（前端是構建期注入）。

### 11.9 VNC 控制台連不上

- 檢查 `/ws/vnc` 是否被反代丟棄了 Upgrade 頭（見第 6 節）
- 容器內 `docker compose exec app supervisorctl status` 確認 `vnc-relay` 為 RUNNING
- 直連測試：`docker compose exec app sh -c 'curl -s http://127.0.0.1:8100 || true'`（Relay 對非 WebSocket 請求返回 400 屬正常）

### 11.10 Docker Desktop Windows 下 fpm 報 "Operation not permitted"

Docker Desktop WSL2 後端的 overlayfs 存在已知 bug：php-fpm fork 的 worker 讀映象層檔案時 `open()` 返回 EPERM。compose 已通過 `tmpfs: /var/www/backend` 規避——entrypoint 啟動時把程式碼從映象層 `/opt/backend-src` 複製到 tmpfs（記憶體檔案系統），fpm 只讀 tmpfs 檔案。Linux 生產環境無此 bug，tmpfs 複製 <2s 可忽略。

### 11.11 遷移/初始化失敗後重試

資料未寫入時（仍為空庫）可重建資料庫卷後重來：

```bash
docker compose down -v   # 注意：-v 会删除全部命名卷（含数据库数据），仅限全新环境
docker compose up -d
```

## 12. 與寶塔部署的差異備忘

- 相同口徑：佇列不常駐、`schedule:run` 每分鐘驅動、`CACHE_STORE=redis`、`QUEUE_CONNECTION=database`、`SESSION_DRIVER=file`、VNC Relay 常駐 8100
- 容器內不做 `route:cache`（路由含閉包，會失敗）；只做 `config:cache`
- 程式碼、PHP 擴充套件、Nginx 配置均隨映象釋出；`storage`、`public/uploads`、`public/media` 走命名卷持久化
- 生產 HTTPS 一律在容器外（1Panel/宿主機 Nginx/Cloudflare）終止

## 關聯文件

- [部署指南](generic.md)：通用部署步驟。
- [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)：站點偽靜態規則。
- [部署與排程指南](scheduling.md)：排程與佇列配置。
