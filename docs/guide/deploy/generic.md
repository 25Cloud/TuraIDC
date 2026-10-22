# 部署指南

本文档说明如何将图拉云业务/财务系统部署到生产环境。部署拓扑：后端 API、三个前端静态站点、Redis、MySQL；队列与调度由每分钟一次的 Laravel 调度器统一驱动，无需常驻队列 Worker。

## 1. 环境要求

- 操作系统：Linux（推荐 Ubuntu 22.04+ / Debian 12）或 Windows Server
- PHP 8.3+，且必须是**已修补 CVE-2026-109618 / CVE-2026-109619 的版本**：上游分支的最低补丁版为 8.3 ≥ 8.3.36 / 8.4 ≥ 8.4.27 / 8.5 ≥ 8.5.12；发行版与 LTS 厂商的回补包同样有效（发行版版本号可能低于上述数字，以厂商公告为准）。无法立刻打补丁时，先把 `session.upload_progress.enabled=0` 写进 php.ini / FPM 池并重启全部 worker，可挡住已知的攻击路径（不是完整修复）
- MySQL 8.0+（推荐；最低兼容 5.7.8——5.7 已 EOL，仅兼容性支持。混用 8.0 的 mysqldump 客户端备份 5.7 服务端时需加 `--column-statistics=0`。**5.7 部署必须在 my.cnf 的 `[mysqld]` 段设置 `explicit_defaults_for_timestamp=ON`**：5.7 默认 OFF 时，表内首个无显式默认值的 `timestamp` 列会被隐式附加 `ON UPDATE CURRENT_TIMESTAMP`，与 8.0 行为不一致，可能悄悄改写业务时间字段）
- Redis 6.0+（生产必需：分布式锁、缓存）
- Composer 2.x
- Node.js 20+（仅构建前端时需要）
- Nginx（或任意静态服务器 + 反代）

### 1.1 扩展与禁用函数以代码为准

PHP 版本、必需扩展与禁用函数清单的唯一真源是 `backend/app/Support/EnvironmentRequirements.php`，
本文档不重复维护清单，避免与代码漂移。装完依赖后随时可自查：

```bash
cd backend
php artisan app:doctor          # 必选项：版本、必需扩展、禁用函数
php artisan app:doctor --all    # 附带列出建议安装但不阻断的扩展
```

**禁用函数是最高频的部署故障**：宝塔面板默认禁用 `putenv`、`proc_open`、`symlink`，
会直接导致 `.env` 读取与进程调用失败。解除方式：宝塔 → 软件商店 → PHP → 禁用函数，
删除这三个函数后重启 PHP，再用 `php artisan app:doctor` 复核。

`app:doctor` 在安装前后都能执行：安装向导的环境检测同样调用这份清单，
但站点装完之后向导会 404 消失，升级 PHP 或改动 php.ini 后只能靠这个命令复核。

## 2. 准备代码

```bash
git clone <你的仓库地址> TuraIDC
cd TuraIDC
```

## 3. 后端部署

### 3.1 安装依赖与配置

```bash
cd backend
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

编辑 `.env`，重点确认：

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

> 四个 URL（APP_URL / FRONTEND_URL / CLIENT_CONSOLE_URL / ADMIN_URL）必须为互不相同的 HTTPS 域名。前端构建脚本会读取这些地址注入各前端产物。

### 3.2 初始化数据库

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

> `database/schema/mysql-schema.sql` 是完整结构基线，禁止手工编辑；新功能只新增 `database/migrations/` 增量迁移。

### 3.3 创建管理员账号

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

> `role_id` 需为超级管理员角色 id（默认 `super_admin` 角色的记录 id）。可在管理后台继续创建角色与员工账号。

### 3.4 配置计划任务与队列

将以下命令加入系统 crontab（每分钟执行一次 Laravel 调度器）：

```cron
* * * * * cd /path/to/TuraIDC/backend && php artisan schedule:run >> /dev/null 2>&1
```

Laravel 调度器内部会按 `routes/console.php` 定义的节奏驱动心跳派发（15 分钟槽位去重）、`queue:drain` 队列消费（按 `provision`、业务组、`automation` 三个队列组分别建 Worker，drain 锁保证同一队列不并发）、存活探针与周期清理。数据库为唯一时钟源，无需单独配置多条 cron。

> **不要再配置覆盖同一队列的常驻 `queue:work`**：`schedule:run` 每分钟已经后台触发 `queue:drain` 消费全部队列组，再挂常驻 Worker 会形成双消费者；如需排查队列堆积，见[部署与调度指南](scheduling.md)的手动消费命令。

### 3.5 缓存清理与资源

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

> 若 `SESSION_DRIVER` 使用 file，`storage/framework/sessions` 需可写。

## 4. 前端构建

在仓库根目录执行（需 Node.js 20+ 与 pnpm）：

```bash
pnpm install --frozen-lockfile --shamefully-hoist
pnpm run build:frontends
```

该命令读取 `backend/.env` 中的四个公开地址，依次构建三端到各自 `dist/`：

| 前端       | 产物目录                         | 端口（开发） |
| ---------- | -------------------------------- | ------------ |
| 官网门户   | `frontend-user-v3-www/dist/`     | 5175         |
| 用户控制台 | `frontend-user-v4-console/dist/` | 5173         |
| 管理后台   | `frontend-admin-v3/dist/`        | 5174         |

三端均为纯静态站点，发布时将各自 `dist/` 内容部署到对应 Nginx 站点即可。

## 5. Nginx 配置示例

### 后端 API（api.example.com）

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

### 前端站点（www.example.com / console.example.com / admin.example.com）

三个前端使用同一套配置模板，替换 `server_name` 与 `root` 即可：

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

## 6. 进程守护（supervisor）

队列消费由调度器的 `queue:drain` 每分钟驱动，**不需要**常驻队列 Worker；需要进程守护的是 VNC Relay（可选）。

### VNC Relay（可选，控制台远程桌面）

若启用 VNC 远程桌面功能，需运行 `vnc:relay` 进程，并将对外 WebSocket 路径（默认 `/ws/vnc`）反代到 `127.0.0.1:8100`：

```ini
[program:finance-vnc-relay]
directory=/path/to/TuraIDC/backend
command=php artisan vnc:relay --host=127.0.0.1 --port=8100
autostart=true
autorestart=true
user=www-data
```

也可使用统一入口 `php artisan app:serve --with-schedule` 同时托管 HTTP、VNC Relay、队列与调度，但那是本地/联调入口，生产环境应按上文用 PHP-FPM + cron + supervisor 拆分独立进程。

## 7. 可选集成配置

以下能力按需在 `backend/.env` 中开启（占位见 `.env.example`）：

| 集成            | 环境变量                                                                           | 说明       |
| --------------- | ---------------------------------------------------------------------------------- | ---------- |
| 支付宝          | `ALIPAY_APP_ID` / `ALIPAY_PRIVATE_KEY` / `ALIPAY_PUBLIC_KEY` / `ALIPAY_NOTIFY_URL` | 在线支付   |
| 短信（阿里云）  | `SMS_API_ENDPOINT`                                                                 | 验证码短信 |
| 极验            | `GEETEST_CAPTCHA_ID` / `GEETEST_CAPTCHA_KEY` / `GEETEST_ENABLED`                   | 人机验证   |
| 实名认证（IDC） | `VERIFICATION_KEY` / `VERIFICATION_API_ENDPOINT`                                   | 实名校验   |
| VNC Relay       | `VNC_RELAY_HOST` / `VNC_RELAY_PORT`                                                | 远程桌面   |
| Sentry          | `SENTRY_DSN`                                                                       | 错误监控   |

供应商适配（如 `zjmf_finance`）通过管理后台「供应商」模块绑定配置，密钥不写入代码。

## 8. 备份

参考 `backend/scripts/backup_mysql.sh`（每日凌晨备份 MySQL 并保留 14 天）：

```bash
bash backend/scripts/backup_mysql.sh
```

建议同时备份：

- 数据库（`mysqldump --single-transaction --routines --triggers`）
- `backend/storage/app/private/`（上传文件、票据附件、日志归档）
- `backend/.env`（应用密钥与配置）

## 9. 上线前检查清单

- [ ] `.env` 中 `APP_DEBUG=false`、`APP_ENV=production`、`SESSION_SECURE_COOKIE=true`
- [ ] 生产环境 `CACHE_STORE=redis`（分布式锁依赖）
- [ ] 四个公开域名均为 HTTPS 且互不相同
- [ ] 数据库已执行全部迁移并初始化默认配置
- [ ] 已创建超级管理员并修改默认凭据
- [ ] crontab 已配置 `php artisan schedule:run`
- [ ] supervisor 已托管队列 Worker
- [ ] 前端三端产物已部署并刷新缓存
- [ ] `backend/public/uploads/` 与 `storage` 目录权限正确

## 10. 官网 SEO 部署

官网是 Vue 单页应用，裸壳 `index.html` 带 `noindex, nofollow`，正文由后端 `SeoRenderService` 读数据库动态渲染后注入。**没接上这条链路，搜索引擎抓到的就是一份带 `noindex` 的空壳，站点等于完全不被收录。** 完整伪静态规则见[四端 Nginx 伪静态配置](../maintain/nginx-rules.md)，本节只列各部署形态必须落位的部分与自检方法。

### 10.1 三种部署形态分别要做什么

| 部署形态                       | 转发规则放在哪                                                                                                     | 前端 shell 地址                                                                                                                                                       | 注意点                                                                                        |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| 宝塔面板 / 传统 Nginx 源码部署 | 官网站点「伪静态」照抄[四端 Nginx 伪静态配置](../maintain/nginx-rules.md)第 2 节，见[宝塔部署](bt-panel.md) 4.2 节 | 留空即自动判定为 `file://` 直读同机 `frontend-user-v3-www/dist/index.html`；也可显式填 `SEO_FRONTEND_SHELL_URL=file:///绝对路径/frontend-user-v3-www/dist/index.html` | 内部转发走 `127.0.0.1:80`；API 站点开了强制 HTTPS 时需对本机来源放行，否则回环请求被 301 拦截 |
| Docker / 1Panel 编排           | 已内置在 `deploy/docker/frontends/nginx-default.conf`，无需重复配置                                                | 默认 `http://frontends:8081/index.html`（compose 内部网络）                                                                                                           | 见[Docker / 1Panel 部署](docker-1panel.md)                                                    |
| 裸机 systemd 部署              | 照抄[四端 Nginx 伪静态配置](../maintain/nginx-rules.md)第 2 节，见[裸机部署](bare-metal.md) 7.2 节                 | 同宝塔形态，留空自动判定                                                                                                                                              | 同样注意 API 站点强制 HTTPS 的回环跳转                                                        |

### 10.2 环境变量

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

### 10.3 哪些路径必须交给后端渲染

- 首页 `/`、`/products`、`/products/{商品ID}`、落地页（`/cloud-server` 等）、`/about`、`/terms`、`/privacy`、`/notices`、`/notices/{id}`、`/help`、`/help/{id}`、`/open-source`
- `/robots.txt`、`/sitemap.xml`（必须由后端生成真实文本与 XML，不能落到 SPA 静态回退）
- 其余单段路径转给后端后返回品牌 404 壳 + HTTP 404，避免爬虫把 SPA 裸壳 200 当成可索引页面
- **多段路径**（商品选购流程 `/products/{slug}/{groupId}/{childGroupId}/{productId}`）保持 SPA 静态回退

> 通配转发有个前提：官网自己的单段路由必须先在 `SeoRenderService::resolvePage()` 里登记，否则会被转成 404 壳。新增官网单段路由时同步在后端登记。

### 10.4 上线后自检

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

自检通过后，到百度搜索资源平台提交 `https://www.你的域名.com/sitemap.xml`（站点已带 `msvalidate.01` 验证 meta），并在平台跑一次「抓取诊断」确认实时抓取结果。

### 10.5 常见坑

| 现象                                 | 原因                                                                                   | 处理                                                        |
| ------------------------------------ | -------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| 爬虫抓到空壳，源码里有 `noindex`     | 伪静态没配，全部请求落进 `try_files ... /index.html`                                   | 按 10.1 补转发规则                                          |
| `/robots.txt` 返回 HTML              | 静态回退把该路径也吞了                                                                 | 同上                                                        |
| robots 正常但页面 500                | `SEO_FRONTEND_SHELL_URL` 指向了 Docker 服务名 `frontends:8081`，源码部署解析不了该主机 | 留空让代码自动判定，或显式填 `file://` 本机路径             |
| 页面有内容但没有 canonical / JSON-LD | 该路径没在后端 `resolvePage()` 登记，返回的是 404 壳                                   | 补登记后重跑                                                |
| 内容是最旧的                         | 前端重建后 shell 缓存未过期                                                            | 等 `SEO_SHELL_CACHE_TTL` 秒或清缓存                         |
| 改了没生效                           | 改了仓库但没重新构建前端 / 没重载 Nginx                                                | 重新构建前端并重载 Nginx                                    |
| 首页标题只有品牌名                   | 站点「浏览器标题」没填关键词                                                           | 后台「站点设置 → 浏览器标题」填带关键词的值，前后端同一口径 |

> 域名走 CDN 时回源规则同样生效，但 CDN 可能缓存旧壳，必要时在 CDN 侧刷新缓存。

## 关联文档

- [宝塔部署项目指南](bt-panel.md)：面板化部署流程。
- [Docker / 1Panel 部署指南](docker-1panel.md)：容器化部署。
- [裸机源码部署指南](bare-metal.md)：无面板、无容器部署。
- [部署与调度指南](scheduling.md)：调度、队列与运维规则。
