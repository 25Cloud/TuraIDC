---
source: guide/deploy/generic.md
sourceHash: 74d49601b9b17904a44825fb18deb86660abf107a79c4a3ac68126a11cbf09f0
generated: zh-hk
---

<!-- 本檔案由 docs-i18n.mjs trad zh-hk 從 docs/guide/deploy/generic.md 產生，請勿手動編輯。 -->
# 部署指南

本文檔説明如何將圖拉雲業務/財務系統部署到生產環境。

## 1. 環境要求

- Linux（推薦 Ubuntu 22.04+ / Debian 12）或 Windows Server
- PHP 8.3 +
- MySQL 8.0 +
- Redis 6.0 +
- Composer 2.x
- Node.js 20+（構建前端）
- Nginx（或任意靜態伺服器 + 反代）

> [!WARNING] 警告：對於 PHP 8.3 用戶
> 請使用**已修補 [CVE-2026-109618](https://nvd.nist.gov/vuln/detail/cve-2026-109618) / [CVE-2026-109619](https://nvd.nist.gov/vuln/detail/cve-2026-109619) 的版本**
>
> 若無法立刻應用補丁，請將 `session.upload_progress.enabled=0` 寫入 php.ini / FPM 池並重啟全部 worker，並擇日升級PHP

> [!TIP] 注意
> 該系統最低兼容MySQL 5.7.8。MySQL5.7 已 EOL，僅做兼容性支持。
>
> 使用 8.0 的 mysqldump 客戶端備份 5.7 服務端時需增加 `--column-statistics=0`。
>
> **5.7 部署必須在 my.cnf 的 `mysqld` 段設置 `explicit_defaults_for_timestamp=ON`**

### 擴展與禁用函數

PHP 版本、擴展與禁用函數清單位於 `backend/app/Support/EnvironmentRequirements.php`

本文檔不重複維護該清單。裝完依賴後可自查：

```bash
cd backend
php artisan app:doctor          # 必选项：版本、必需扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

> [!IMPORTANT] 注意
> 寶塔面板默認禁用 `putenv`、`proc_open`、`symlink`，導致 `.env` 讀取與進程調用失敗。
>
> 解除方式：寶塔 → 軟件商店 → PHP → 禁用函數，刪除這三個函數後重啟 PHP，再使用 `php artisan app:doctor` 複核。

## 2. 準備代碼

```bash
git clone https://github.com/25Cloud/TuraIDC TuraIDC
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

編輯 `.env` 並確認如下內容：

```bash
APP_ENV=production
APP_DEBUG=false

# 地址分配
APP_URL=https://api.example.com
FRONTEND_URL=https://www.example.com
CLIENT_CONSOLE_URL=https://console.example.com
ADMIN_URL=https://admin.example.com

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=finance
DB_USERNAME=finance
DB_PASSWORD=你的强密码

# Redis配置
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
```

> [!IMPORTANT] 注意
> `地址分配` 建議填入不同的域名，該內容將用於構建前端

### 3.2 初始化數據庫

```bash
# 创建数据库
mysql -u root -p -e "CREATE DATABASE finance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
# 导入结构
mysql -u root -p finance < database/schema/mysql-schema.sql
# 增量迁移
php artisan migrate --force
# 写入默认配置
php artisan db:seed --class=Database\\Seeders\\SettingsSeeder
```

### 3.3 創建超管賬號

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

> [!TIP]
> 超管創建後，您可在管理後台繼續創建其他角色/員工賬號。

### 3.4 配置計劃任務

將以下 `cron` 任務加入系統 crontab：

```bash
* * * * * cd /TuraIDC根目录/backend && php artisan schedule:run >> /dev/null 2>&1
```

> [!CAUTION] 警告：禁止配置多個Cron任務
> 禁止多次配置常駐 `queue:work`，否則可能形成雙消費者；如需排查隊列堆積，詳見 [部署與調度指南](scheduling.md) 的手動消費命令。

### 3.5 快取清理

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

## 4. 前端構建

在根目錄執行：

```bash
pnpm install --frozen-lockfile --shamefully-hoist
pnpm run build:frontends
```

| 前端       | 產物目錄                         | 端口（開發） |
| ---------- | -------------------------------- | ------------ |
| 官網       | `frontend-user-v3-www/dist/`     | 5175         |
| 用戶控制台 | `frontend-user-v4-console/dist/` | 5173         |
| 管理後台   | `frontend-admin-v3/dist/`        | 5174         |

發佈時請將各自 `dist/` 內容部署到對應 Nginx 站點

## 5. Nginx 配置示例

### 後端

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

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

### 前端

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

### 前端站點 CSP 與點擊劫持

由於前端為靜態 HTML，CSP應由 Nginx 執行中間操作。

默認文件位於 `deploy/nginx/frontend-csp.conf`，複製進對應 `server {}` 即可，策略含義是：

| 策略                         | 説明                                                                                                        |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `script-src 'unsafe-inline'` | 官網、控制台的首屏預熱/品牌注入腳本和管理端為內聯；靜態託管時無 nonce 可用。腳本外移至 `/assets` 後可去掉。 |
| `script-src 'unsafe-eval'`   | 三端使用 vue-i18n 編譯語言包（無預編譯），去掉後會報錯 `EvalError`。接入預編譯後可去掉。                    |
| 極驗域名                     | 管理與控制台的登錄驗證碼；`gt4.js` 使用腳本標籤加載驗證庫並埋點，僅放行 `static` 會在初始化階段被攔截。     |
| `https://res.25y.cn`         | 人機驗證適配腳本（Corptcha）的外網 SDK，由插件配置的 `script_url` 決定。                                    |
| `https://tdesign.gtimg.com`  | TDesign `t-icon` 默認 `loadDefaultIcons` 的 SVG 雪碧圖。                                                    |
| `style-src 'unsafe-inline'`  | TDesign / Element Plus 運行時注入樣式，首屏 splash 與驗證碼樣式也是內聯。                                   |
| `frame-src ... alipays:`     | 控制台充值頁用隱藏 iframe 拉起支付寶 App。                                                                  |
| `frame-ancestors 'self'`     | 只允許同源頁面嵌入（`dash.example.com` 等同域面板）。                                                       |

> [!WARNING] 警告：有關Nginx
> 在Nginx下，`add_header` 不會繼承。
>
> 任一 `location` 裡的 `add_header`（例如靜態資源的 `Cache-Control`），都會導致該路徑的`index.html` 無法使用策略，必須在該 `location` 內再貼一遍 CSP
>
> 建議存為 `snippets/frontend-csp-<端>.conf` ，然後再使用 `include`，避免出現錯誤。

> [!TIP] 自檢
> 運行 `curl -sI https://console.example.com/ | grep -i content-security-policy` 以確認響應頭存在；
>
> 若控制台出現 `Refused to load ...` 就是漏了來源，請按照上面的表格修補域名。

## 6. 進程守護（supervisor）

隊列由調度器驅動，**無需**常駐；需要進程守護的是 VNC Relay（可選）。

### VNC Relay（可選，控制台遠程桌面）

若啟用 VNC 遠程桌面功能，需運行 `vnc:relay` 進程，並將對外 WebSocket 路徑（默認 `/ws/vnc`）反代到 `127.0.0.1:8100`：

```ini
[program:finance-vnc-relay]
directory=/path/to/TuraIDC/backend
command=php artisan vnc:relay --host=127.0.0.1 --port=8100
autostart=true
autorestart=true
user=www-data
```

也可使用 `php artisan app:serve --with-schedule` 同時託管多個服務，但生產環境應使用 PHP-FPM + cron + supervisor 拆分進程。

## 7. 備份

參考 `backend/scripts/backup_mysql.sh`（每日凌晨備份 MySQL 並保留 14 天）：

```bash
bash backend/scripts/backup_mysql.sh
```

建議同時備份：

- 數據庫（`mysqldump --single-transaction --routines --triggers`）
- `backend/storage/app/private/`（上傳文件、票據附件、日誌歸檔）
- `backend/.env`（應用密鑰與配置）

## 8. SEO 部署

官網是 Vue 單頁應用，正文由 `SeoRenderService` 動態渲染注入。

完整偽靜態規則見 [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md)，本節只列出各部署形態必須落位的部分與自檢方法。

### 8.1 三種部署形態

| 部署形態        | 轉發規則                                                                                       | 前端 shell 地址                                                                                                                                                     |
| --------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 寶塔 / Nginx    | [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md) 第 2 節 / [寶塔部署](bt-panel.md) 4.2 節   | 留空判定為 `file://TuraIDC工作目录/frontend-user-v3-www/dist/index.html`；也可顯式填 `SEO_FRONTEND_SHELL_URL=file:///绝对路径/frontend-user-v3-www/dist/index.html` |
| Docker / 1Panel | 已內置，無需重複配置                                                                           | 默認 `http://frontends:8081/index.html`                                                                                                                             |
| 裸機部署        | [四端 Nginx 偽靜態配置](../maintain/nginx-rules.md) 第 2 節 / [裸機部署](bare-metal.md) 7.2 節 | 同寶塔                                                                                                                                                              | 注意 API 站點強制 HTTPS 的跳轉 |

### 8.2 環境變量

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

### 8.3 自檢

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

> [!TIP] 注意
> CDN 回源規則同樣生效，但 CDN 可能快取舊殼，必要時在 CDN 側刷新快取。

## 關聯文檔

- [寶塔部署項目指南](bt-panel.md)：面板化部署流程。
- [Docker / 1Panel 部署指南](docker-1panel.md)：容器化部署。
- [裸機源碼部署指南](bare-metal.md)：無面板、無容器部署。
- [部署與調度指南](scheduling.md)：調度、隊列與運維規則。
