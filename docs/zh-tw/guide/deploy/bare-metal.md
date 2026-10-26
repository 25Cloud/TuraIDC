---
source: guide/deploy/bare-metal.md
sourceHash: a2bcaa122cb21e9be670ec64c9e0d56b1726dd7a06787f1830179e05c72d5e56
generated: zh-tw
---

<!-- 本檔案由 docs-i18n.mjs trad zh-tw 從 docs/guide/deploy/bare-metal.md 產生，請勿手動編輯。 -->
# 裸機原始碼部署指南

面向不使用寶塔面板、不使用 Docker 的伺服器：以系統原生服務（nginx + PHP-FPM + systemd + cron）從原始碼部署 TuraIDC 的完整流程。

- 對齊時間：`2026-08-28`（在 Debian 13 (trixie) 上實際部署並驗證通過）
- 適用場景：內網/自管伺服器、無法或不想安裝面板與容器執行時的環境
- 配套文件：
  - [部署與排程指南](scheduling.md)（現網運維口徑）
  - [寶塔部署專案指南](bt-panel.md)（面板版，拓撲一致）
  - [通用部署指南](generic.md)（從零部署的通用參考）
  - [Docker 與 1Panel 部署指南](docker-1panel.md)（容器化）

---

## 1. 拓撲與寶塔版的對應關係

執行拓撲與[寶塔部署](bt-panel.md)完全一致，只是管理載體不同：

```
系统 nginx（sites-available 四站点）
  ├── 官网/用户入口 -> /opt/turaidc/frontend-user-v3-www/dist 静态资源
  ├── 用户控制台   -> /opt/turaidc/frontend-user-v4-console/dist 静态资源
  ├── 管理端       -> /opt/turaidc/frontend-admin-v3/dist 静态资源
  └── 后端 API     -> /opt/turaidc/backend/public (PHP-FPM)
                        ├── MySQL 8（本机）
                        ├── Redis（本机，仅 127.0.0.1）
                        ├── /etc/cron.d 每分钟 schedule:run
                        └── systemd 常驻 php artisan vnc:relay
```

| 寶塔版載體             | 裸機版載體                                                 |
| ---------------------- | ---------------------------------------------------------- |
| 寶塔站點（Nginx 配置） | `/etc/nginx/sites-available/*.conf` + `sites-enabled` 軟鏈 |
| 寶塔計劃任務           | `/etc/cron.d/turaidc`                                      |
| 寶塔程序守護           | systemd 服務單元 `turaidc-vnc-relay.service`               |
| 寶塔 PHP 設定          | `/etc/php/8.4/fpm/`（發行版預設即可用）                    |

## 2. 前置環境

實測基線：Debian 13 (trixie)、2 核 / 2G 記憶體 / 20G 磁碟。Ubuntu 22.04+、Debian 12 同理，包名基本一致。

| 軟體                  | 來源                     | 說明                                                                                                                                                                                                                           |
| --------------------- | ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| nginx                 | 發行版倉庫               | 1.26+                                                                                                                                                                                                                          |
| PHP 8.4-FPM + CLI     | 發行版倉庫               | Debian 13 自帶 8.4，滿足後端 `composer.json` 的 `^8.3`；無需第三方源。**須用含 CVE-2026-109618 / CVE-2026-109619 修復的補丁版**（8.4 分支 ≥ 8.4.27，Debian 會回補，`apt upgrade` 即可），升級後 `systemctl restart php8.4-fpm` |
| MySQL 8.4 LTS         | MySQL 官方 APT 源        | 官方源已支援 trixie；trixie 無 MySQL 8.0 系列官方包，選 8.4 LTS                                                                                                                                                                |
| Redis                 | 發行版倉庫               | 8.x，僅監聽 127.0.0.1，預設無密碼即可                                                                                                                                                                                          |
| Composer              | 發行版倉庫 `composer` 包 | 2.8+                                                                                                                                                                                                                           |
| python3               | 系統自帶                 | `install_db.py` 依賴                                                                                                                                                                                                           |
| Node.js 20.19+ / pnpm | **僅構建機需要**         | 見第 6 節：小記憶體伺服器不要原地構建前端                                                                                                                                                                                        |

記憶體口徑：2G 可執行（PHP-FPM 用 Debian 預設小程序池即可）；若要在伺服器上原地構建前端，必須另加 swap，且 LXC/VE 類容器通常 `swapon` 被核心禁止（`Operation not permitted`），此時應改為本機構建後上傳 dist。

### 域名解析

按伺服器形態二選一，四個公開地址（`api` / `www` / `console` / `admin`）都要能解析到部署伺服器：

**內網 / 無公網部署（本指南預設做法）**：在訪問端機器的 hosts 中新增四條記錄（如 `192.168.1.170 api.tura.cloud` 等）；更換伺服器 IP 或域名下線時，同理同步增刪。

**有獨立公網 IP 的雲伺服器**：到域名所在 DNS 服務商控制台配置解析，四條 A 記錄指向伺服器公網 IP：

| 記錄型別 | 主機記錄 | 記錄值            |
| -------- | -------- | ----------------- |
| A        | api      | `<服务器公网 IP>` |
| A        | www      | `<服务器公网 IP>` |
| A        | console  | `<服务器公网 IP>` |
| A        | admin    | `<服务器公网 IP>` |

- 更換伺服器 IP 或棄用舊域名時同理增刪解析記錄，避免殘留指向失效地址。
- 雲伺服器生產環境建議統一 HTTPS：解析生效後為四個子域申請證書（如 Let's Encrypt），四個公開地址改為 `https://` 並設 `SESSION_SECURE_COOKIE=true`。
- 配置完成後等待 TTL 生效，用 `dig` / `ping` 確認四個子域均已指向伺服器。

## 3. 安裝系統依賴

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y wget gnupg ca-certificates curl lsb-release
apt-get install -y nginx redis-server composer \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd php8.4-zip php8.4-intl \
  php8.4-bcmath php8.4-mbstring php8.4-curl php8.4-xml php8.4-redis

# MySQL 官方 APT 源（非交互模式使用默认选择：mysql-8.4-lts）
wget -q https://dev.mysql.com/get/mysql-apt-config_0.8.36-1_all.deb -O /tmp/mysql-apt-config.deb
apt-get install -y /tmp/mysql-apt-config.deb
apt-get update -y
apt-get install -y mysql-server
```

說明：

- `mysql-apt-config` 的 debconf 指令碼可能列印 `lsb_release: command not found` 警告，只要 `/etc/apt/sources.list.d/mysql.list` 生成即無影響。
- 四個服務（nginx、php8.4-fpm、redis-server、mysql）安裝後預設自啟，`systemctl is-active ...` 逐個確認。
- PHP 擴充套件確認：`php -m | grep -iE "pdo_mysql|redis|gd|zip|intl|bcmath|mbstring|opcache|fileinfo"`。Debian 的 PHP 沒有寶塔式停用函式問題，`putenv`/`proc_open`/`symlink` 預設可用。

### 時區對齊

專案口徑為 PHP `Asia/Shanghai`、資料庫連線 `+08:00`（`config/app.php`、`config/database.php`），裸機需自行對齊：

```bash
timedatectl set-timezone Asia/Shanghai
echo -e "[mysqld]\ndefault-time-zone=+08:00" > /etc/mysql/mysql.conf.d/99-turaidc-timezone.cnf
systemctl restart mysql
```

## 4. 上傳程式碼

構建機（有 Node/pnpm 環境）打包，伺服器只接收原始碼與構建產物：

```bash
# 构建机：从干净提交打包（自动排除 .env、node_modules、dist 等未跟踪内容）
git archive --format=tar -o code.tar HEAD
# 前端 dist 构建完成后追加进同一个包（见第 6 节）
tar -rf code.tar frontend-admin-v3/dist frontend-user-v3-www/dist frontend-user-v4-console/dist

# 上传。scp 在部分环境下会静默失败，用 ssh 管道传输并以 md5 校验更可靠：
cat code.tar | ssh root@<服务器> 'cat > /tmp/turaidc-code.tar && md5sum /tmp/turaidc-code.tar'

# 服务器：解压到统一部署目录
mkdir -p /opt/turaidc
tar -xf /tmp/turaidc-code.tar -C /opt/turaidc
```

## 5. 後端部署

### 5.1 建庫授權

Debian 的 MySQL root 預設走 auth_socket，root 登入下直接執行：

```sql
CREATE DATABASE IF NOT EXISTS idc CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'idc'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT ALL PRIVILEGES ON idc.* TO 'idc'@'127.0.0.1';
FLUSH PRIVILEGES;
```

> 若把 SQL 寫進 shell heredoc，注意未加引號的定界符會做命令替換：`CREATE DATABASE IF NOT EXISTS \`idc\``的反引號會被 bash 當作命令執行（實測報`idc: command not found`）。`idc` 不是保留字，直接去掉反引號即可。

### 5.2 生成 backend/.env

以 `backend/.env.example` 為底，**sed 原地替換**，不要靠檔案尾追加覆蓋同名鍵——Laravel Dotenv 是不可變模式，同名鍵第一次出現生效，追加無效：

```bash
cd /opt/turaidc/backend
cp .env.example .env
sed -i \
  -e "s|^APP_ENV=.*|APP_ENV=production|" \
  -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
  -e "s|^APP_URL=.*|APP_URL=http://api.tura.cloud|" \
  -e "s|^FRONTEND_URL=.*|FRONTEND_URL=http://www.tura.cloud|" \
  -e "s|^CLIENT_CONSOLE_URL=.*|CLIENT_CONSOLE_URL=http://console.tura.cloud|" \
  -e "s|^ADMIN_URL=.*|ADMIN_URL=http://admin.tura.cloud|" \
  -e "s|^CLIENT_SESSION_COOKIE_DOMAIN=.*|CLIENT_SESSION_COOKIE_DOMAIN=.tura.cloud|" \
  -e "s|^DB_PASSWORD=.*|DB_PASSWORD=<强密码>|" \
  .env
cat >> .env <<'EOF'

# example 中不存在的键才允许追加
INSTALL_ADMIN_PASSWORD=<至少12位强密码>
SEO_SITE_URL=http://www.tura.cloud
SEO_FRONTEND_SHELL_URL=file:///opt/turaidc/frontend-user-v3-www/dist/index.html
EOF
chown www-data:www-data .env && chmod 600 .env
```

要點：

- 四個公開地址同協議；內網 hosts 方案用 HTTP，`SESSION_SECURE_COOKIE` 保持 example 預設 `false`。
- `TURAIDC_BUSINESS_QUEUES` 保持 example 預設即可（`provision` 會被執行時自動併入正確分組）。
- `APP_KEY` 留空，`install_db.py` 檢測為空時自動生成並寫回。
- `INSTALL_ADMIN_PASSWORD` 是首次初始化管理員 `cerbo` 的臨時密碼，僅空庫首次生效；不要寫入文件、Git 或計劃任務。

### 5.3 安裝依賴並初始化資料庫

```bash
cd /opt/turaidc/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 关键：先预建 Laravel 运行目录，再初始化（否则 optimize:clear 报 View path not found）
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/public bootstrap/cache public/uploads public/media

python3 scripts/install_db.py
```

`install_db.py` 依次完成：校驗資料庫連線、生成 `APP_KEY`、匯入 `database/schema/mysql-schema.sql`、執行增量遷移、寫入預設配置與通知模板、建立管理員 `cerbo`。空庫可重複執行；不要對承載業務資料的庫使用 `--reset`。

### 5.4 許可權

```bash
cd /opt/turaidc/backend
chown -R www-data:www-data storage bootstrap/cache public/uploads public/media
chmod -R 775 storage bootstrap/cache public/uploads public/media
```

### 5.5 生產快取

```bash
cd /opt/turaidc/backend
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

實測本倉庫 `route:cache` 可以成功執行（[容器文件](docker-1panel.md)中"路由含閉包導致 route:cache 失敗"的口徑在原始碼部署下不成立，以此處實測為準）；若未來路由引入閉包導致失敗，跳過該步不影響執行。

## 6. 前端構建與產物

小記憶體伺服器不要原地構建。在構建機（Node.js 20.19+、pnpm）上執行：

```bash
CI=true \
APP_URL=http://api.tura.cloud \
FRONTEND_URL=http://www.tura.cloud \
CLIENT_CONSOLE_URL=http://console.tura.cloud \
ADMIN_URL=http://admin.tura.cloud \
CLIENT_SESSION_COOKIE_DOMAIN=.tura.cloud \
pnpm run build:frontends
```

- 構建指令碼從**程序環境變數優先**讀取四個公開地址（其次才是 `backend/.env`），因此無需改動構建機上的 `backend/.env`。
- 四個地址必須互不相同且同協議，構建前指令碼會強校驗。
- 構建產物在三個前端目錄各自的 `dist/`，不會寫入 `backend/public`。
- pnpm 11 在 CI/沙箱環境下可能在 `run` 前自動觸發依賴重灌並失敗（`ERR_SQLITE_ERROR: unable to open database file`）；`npm_config_verify_deps_before_run=false` 環境變數在 pnpm 11 不生效，用 CLI 形式或包裝器注入：`pnpm --config.verify_deps_before_run=false run build:frontends`（巢狀 spawn 的子 pnpm 需要通過 PATH 包裝器統一注入）。

構建完成後把三個 `dist` 追加進第 4 節的上傳包（`tar -rf`），或單獨打包上傳，解壓後落在 `/opt/turaidc/<前端目录>/dist`。

## 7. Nginx 四站點

配置檔案放 `/etc/nginx/sites-available/`，軟鏈到 `sites-enabled`，並移除發行版預設站點：

```bash
for h in api www console admin; do
  ln -sf /etc/nginx/sites-available/$h.tura.cloud.conf /etc/nginx/sites-enabled/
done
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

### 7.1 api.tura.cloud（後端 PHP-FPM）

```nginx
server {
    listen 80;
    server_name api.tura.cloud;
    root /opt/turaidc/backend/public;
    index index.php index.html;
    client_max_body_size 100m;

    # VNC WebSocket 仅由 API 站点转发到内部 Relay
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

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

### 7.2 www.tura.cloud（官網：靜態回退 + SEO 動態渲染轉發）

公開路徑轉發到 API 站點由 Laravel 讀庫渲染完整 HTML（規則依據[前端 Nginx 規則](../maintain/nginx-rules.md)與寶塔指南 4.2 節）：

```nginx
server {
    listen 80;
    server_name www.tura.cloud;
    root /opt/turaidc/frontend-user-v3-www/dist;
    index index.html;

    location = / {
        proxy_pass http://127.0.0.1/seo/www;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(robots\.txt|sitemap\.xml)$ {
        proxy_pass http://127.0.0.1;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|products)$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/(notices|help)(/[0-9]+)?$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~ ^/products/[0-9]+$ {
        proxy_pass http://127.0.0.1/seo/www$request_uri;
        proxy_set_header Host              api.tura.cloud;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # 其余单段路径转发后端渲染：已登记路径返回可索引的完整 HTML，
# 未登记路径返回品牌 404 壳 + HTTP 404，避免爬虫把 SPA 裸壳 200 当可索引页面。
# 前提：官网自己的单段路由须先在后端 SeoRenderService::resolvePage() 登记。
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
}
```

純 HTTP 內部轉發不存在強制 HTTPS 301 攔截問題；若日後啟用 HTTPS，需遵守寶塔指南 4.2 節的迴環放行說明。

### 7.3 console / admin（SPA 靜態回退）

```nginx
server {
    listen 80;
    server_name console.tura.cloud;   # admin 站点同理，替换 server_name 与 root
    root /opt/turaidc/frontend-user-v4-console/dist;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }
}
```

## 8. 排程與守護程序

### 8.1 每分鐘排程（cron）

`/etc/cron.d/turaidc`（檔名不能含點號，屬主 root，許可權 644）：

```cron
* * * * * www-data cd /opt/turaidc/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

以 `www-data` 執行，與 PHP-FPM 同用戶，保證 storage 寫許可權。這條計劃每分鐘並行消費業務佇列與 `automation` 佇列，**不要再配置覆蓋同一佇列的常駐 `queue:work`**。

### 8.2 VNC Relay 常駐（systemd）

`/etc/systemd/system/turaidc-vnc-relay.service`：

```ini
[Unit]
Description=TuraIDC VNC Relay (php artisan vnc:relay)
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/turaidc/backend
ExecStart=/usr/bin/php artisan vnc:relay
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now turaidc-vnc-relay
ss -tlnp | grep 8100   # 确认 127.0.0.1:8100 已监听
```

## 9. 部署驗證

```bash
curl -s -H "Host: api.tura.cloud" http://127.0.0.1/api/health   # {"status":"alive"}
curl -s -H "Host: api.tura.cloud" http://127.0.0.1/api/ready    # status: ready
```

`/api/ready` 中 `scheduler` 首次為 `false` 是正常的——cron 尚未到達觸發點；裝好 cron 等待 1-2 分鐘後複查應為全綠（database/cache/storage/scheduler/scheduler_liveness/task_readiness/queue 均 true）。

瀏覽器/構建機側（配置 hosts 後）：

- [ ] 四端 `http://api|www|console|admin.tura.cloud` 均返回 200
- [ ] 官網首頁 HTML title 為站點設定的「瀏覽器標題」（SEO 動態渲染生效，預設回退站點名），`/products`、`/notices`、`/sitemap.xml`、`/robots.txt` 返回 200
- [ ] 控制台/管理端重新整理任意 SPA 路由不 404
- [ ] 管理端可用 `cerbo` + `INSTALL_ADMIN_PASSWORD` 登入，登入後立即改密
- [ ] `APP_DEBUG=false`、`.env` 600 且屬主 www-data

## 10. 升級

```bash
# 构建机：新提交重新打包（含重新构建的 dist），上传并解压覆盖 /opt/turaidc
# 服务器：
cd /opt/turaidc/backend
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
systemctl restart php8.4-fpm
systemctl status turaidc-vnc-relay   # relay 由 systemd 自动守护，一般无需处理
```

升級前先備份資料庫；回滾口徑見[部署與排程指南](scheduling.md)。

## 11. 實測踩坑記錄（2026-08-28，Debian 13 / LXC 容器）

| 現象                                           | 原因與處理                                                                                                                                                                                 |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `swapon: /swapfile: Operation not permitted`   | LXC/VE 容器核心禁止 swap。本機構建方案下伺服器無需 swap，跳過即可                                                                                                                          |
| heredoc 中建庫 SQL 報 `idc: command not found` | 未加引號的 heredoc 會做命令替換，反引號被當作命令執行；去掉識別符號反引號                                                                                                                    |
| `.env` 尾部追加鍵不生效                        | Laravel Dotenv 不可變模式，同名鍵首個生效；必須 sed 原地替換                                                                                                                               |
| `optimize:clear` 報 `View path not found`      | `storage/framework/views` 不存在；**必須在 `install_db.py` 之前**預建 storage 目錄樹                                                                                                       |
| scp 顯示成功但檔案未到達                       | 部分環境 scp 靜默失敗；改用 `cat file \| ssh 'cat > dest'` 管道並 md5 校驗                                                                                                                 |
| pnpm 自動重灌依賴報 `ERR_SQLITE_ERROR`         | pnpm 11 的 `verify-deps-before-run` 在 CI/沙箱下自動觸發安裝且 store 不可寫；`npm_config_verify_deps_before_run` 環境變數無效，需 `--config.verify_deps_before_run=false` 並覆蓋巢狀 spawn |
| `/api/ready` 的 scheduler 為 false             | cron 剛安裝尚未到觸發點；等 1-2 分鐘自動轉綠                                                                                                                                               |

## 關聯文件

- [部署指南](generic.md)：通用部署步驟。
- [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)：站點偽靜態規則。
