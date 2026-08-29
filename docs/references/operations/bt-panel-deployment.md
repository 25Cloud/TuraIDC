# 宝塔部署项目指南

面向全新服务器的完整部署流程。已有生产环境运维请直接看 [部署与调度指南.md](deployment-and-scheduling.md)。

> 🧭 **从智简魔方财务系统迁移**：若你正在使用智简魔方财务（ZJMF）且要切换到 TuraIDC，请先阅读 [从智简魔方财务系统迁移](../database/migrate-from-zjmf-finance.md)，按 10 步流程完成数据迁移与上游对接后再接入本指南的站点配置。

---

## 1. 前置环境

### 1.1 宝塔面板安装

宝塔面板需已安装。未安装参考官方文档，推荐 Linux 系统（CentOS 7+ / Ubuntu 20.04+ / Debian 10+）。

登录宝塔面板后，进入**软件商店**安装以下运行环境：

| 软件               | 版本要求              | 说明                                                                                                                                         |
| ------------------ | --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Nginx              | 1.20+                 | Web 服务器                                                                                                                                   |
| PHP                | 8.3+                  | 安装向导环境检测硬性要求 PHP >= 8.3，装 8.2 会在检测环节被拦下                                                                               |
| MySQL              | 8.0（最低兼容 5.7.8） | 数据库；5.7 需在 my.cnf 设 `explicit_defaults_for_timestamp=ON`（避免隐式 ON UPDATE CURRENT_TIMESTAMP，详见 [deployment.md](deployment.md)） |
| Redis              | 7.0+                  | 缓存                                                                                                                                         |
| phpMyAdmin         | —                     | 数据库管理（可选）                                                                                                                           |
| Node.js 版本管理器 | —                     | 管理 Node.js 版本                                                                                                                            |

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

**PHP CLI 版本**：确保宝塔命令行使用的 PHP 与站点一致（宝塔 → 网站 → PHP 命令行版本）。

### 1.3 Node.js 配置

在 Node.js 版本管理器中安装 **Node.js 20.19+**（项目 package.json 约束的最低版本）。

### 1.4 DNS、网络与账户

- 先为 `api`、`www`、`console`、`admin` 四个域名添加 DNS 记录，并确认都解析到同一台服务器。
- 防火墙只对公网开放 `80` 和 `443`；MySQL、Redis 和 VNC Relay 的 `8100` 仅监听内网或 `127.0.0.1`，不要对公网放行。
- HTTPS 部署前先在宝塔申请并绑定四个站点的证书。四个公开地址必须统一为 HTTP 或 HTTPS；生产环境应使用 HTTPS。
- 记录宝塔站点运行用户（通常为 `www`）。后续仅授予该用户 `backend/storage` 与 `backend/bootstrap/cache` 的写权限。

---

## 2. 项目部署概览

生产拓扑如下：

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

**关键理解**：

- 后端只通过 PHP-FPM 运行，不使用 `php artisan serve`
- 没有常驻 Queue Worker，队列消费并入 `schedule:run`（每分钟）
- 四个站点独立部署，可分配不同的域名/端口
- **官网 SEO**：官网公开页面（首页、产品、落地页、公告/帮助及其详情）在官网站点伪静态中转发到 API 站点，由 Laravel 读数据库动态渲染完整 HTML（站名/Logo/meta/正文）；sitemap.xml / robots.txt 由 Laravel 动态生成

数据库发布有两条互斥路径：

| 场景                 | 执行入口                        | 说明                                                                                |
| -------------------- | ------------------------------- | ----------------------------------------------------------------------------------- |
| 空数据库首次部署     | `python3 scripts/install_db.py` | 导入 `database/schema/mysql-schema.sql`、执行增量迁移、写入默认配置并初始化管理员。 |
| 已有生产库发布新版本 | `php artisan migrate --force`   | 只执行尚未记录的增量迁移；部署前先完成数据库备份。                                  |

首次部署不要先手动执行 `php artisan migrate --force`，也不要对生产库使用 `install_db.py --reset`。

---

## 3. 创建宝塔站点

### 3.1 创建后端 API 站点

宝塔 → 网站 → 添加站点：

| 配置项   | 值                            |
| -------- | ----------------------------- |
| 域名     | `api.你的域名.com`            |
| 根目录   | `你的项目路径/backend/public` |
| PHP 版本 | PHP 8.3                       |
| 数据库   | 新建（后续导入）              |

### 3.2 创建前端站点

创建三个前端站点。**网站目录填前端应用目录，不要直接填 `dist`**（原因见 §6.1 的说明：
宝塔会在网站目录生成不可删除的 `.user.ini`，落在 `dist` 里会导致前端构建必然失败）：

| 站点          | 域名                   | 网站目录                            | 运行目录 |
| ------------- | ---------------------- | ----------------------------------- | -------- |
| 官网/用户入口 | `www.你的域名.com`     | `项目路径/frontend-user-v3-www`     | `/dist`  |
| 用户控制台    | `console.你的域名.com` | `项目路径/frontend-user-v4-console` | `/dist`  |
| 管理端        | `admin.你的域名.com`   | `项目路径/frontend-admin-v3`        | `/dist`  |

---

## 4. 宝塔站点与伪静态配置

四个站点均使用宝塔创建时生成的默认 Nginx 配置。不要在“配置文件”中替换或粘贴完整 `server {}`，以免覆盖宝塔生成的 SSL、PHP-FPM 和日志设置。

在宝塔面板完成以下设置后，只编辑每个站点的“设置 → 伪静态”：

| 站点       | 网站目录                   | 运行目录 | 宝塔面板设置             |
| ---------- | -------------------------- | -------- | ------------------------ |
| 官网       | `frontend-user-v3-www`     | `/dist`  | 静态站点，申请 SSL。     |
| 用户控制台 | `frontend-user-v4-console` | `/dist`  | 静态站点，申请 SSL。     |
| 管理端     | `frontend-admin-v3`        | `/dist`  | 静态站点，申请 SSL。     |
| API        | `backend/public`           | 留空     | 选择 PHP 8.3，申请 SSL。 |

HTTPS 跳转和证书通过宝塔“SSL”页面配置；纯 HTTP 环境不启用强制 HTTPS，并将 `SESSION_SECURE_COOKIE=false`。四个公开地址必须使用相同协议。

### 4.1 后端 API 站点伪静态

在 API 站点的“伪静态”完整填入：

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

项目未启用 VNC 远程控制时，可以删除 `/ws/vnc` 的整个 `location` 块，保留 Laravel 回退规则。宝塔默认 PHP 处理块保持不变。

> 注：API 请求路径是 PHP-FPM 直连，Laravel 以 `REMOTE_ADDR` 为准，不受上方 `X-Forwarded-*` 影响（这些头只发给 VNC Relay）。若改为宿主机 Nginx/1Panel 反向代理 API 端口，必须遵守[受信代理与来源 IP 契约](deployment-and-scheduling.md)：单层受信代理把 `X-Forwarded-For` 重置为 `$remote_addr`，不要原样透传客户端伪造的头。

### 4.2 三个前端站点伪静态

用户控制台和管理端是纯 Vue History 路由站点，在“伪静态”中填入：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

**官网**除上述回退外，还需把公开 SEO 路径转发到 API 站点（Laravel 读数据库动态渲染完整 HTML），完整伪静态为：

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

# 落地页 / 关于 / 条款 / 隐私 / 产品列表（单段路径）
location ~ ^/(cloud-server|hong-kong-server|us-server|high-defense-server|cloud-pc|about|terms|privacy|products)$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 公告/帮助列表与详情（/notices、/notices/123、/help、/help/123）
location ~ ^/(notices|help)(/[0-9]+)?$ {
    proxy_pass http://127.0.0.1/seo/www$request_uri;
    proxy_set_header Host              api.你的域名.com;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}

# 产品详情（/products/123；多段购买页路径保持 SPA 静态回退）
location ~ ^/products/[0-9]+$ {
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

> 内部转发走 `127.0.0.1:80`（HTTP）。若 API 站点在宝塔“SSL”中开启了“强制 HTTPS”，80 端口会 301 跳转并拦截内部转发，需在 API 站点 Nginx 配置中对本机来源放行（`location = /` 等规则加 `if ($remote_addr != 127.0.0.1) { return 301 ...; }` 判断），或将上述 `proxy_pass` 改为 `https://api.你的域名.com/...`（宝塔已配证书，同机回环可正常握手）。后端已信任回环/私有网段代理（`bootstrap/app.php` 的 `trustProxies`），`X-Forwarded-Proto` 可正确传递 https。

不要在三个前端站点添加 `/api`、`/uploads`、`/media` 或 `/ws/vnc` 的代理。浏览器直接访问 API 域名；`/vnc/vnc.html` 是控制台的静态构建产物，会由该规则直接命中。

### 4.3 非伪静态项

- SSL 证书、强制 HTTPS 和站点根目录：宝塔“网站”页面设置。
- API 的 PHP 版本与 PHP-FPM：宝塔站点的 PHP 设置。
- 上传大小：在宝塔 PHP 设置中同时调整 `upload_max_filesize` 和 `post_max_size`；站点级 Nginx 上传限制仅在实际遇到 413 时通过面板提供的对应设置调整。
- 保存伪静态后使用宝塔的 Nginx 配置检查与重载功能；无需手工编辑 Nginx 配置文件。

---

## 5. 后端部署

### 5.1 上传代码

将项目代码上传至服务器，例如 `/www/wwwroot/turaidc`。

推荐方式：

- **Git 克隆**：宝塔 → 软件商店 → Git 插件，或手动 `git clone`
- **宝塔文件上传**：压缩包上传后解压

### 5.2 配置 .env

```bash
cd /www/wwwroot/你的项目/backend
cp .env.example .env
```

编辑 `.env`，修改以下必填项：

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

`INSTALL_ADMIN_PASSWORD` 是首次初始化默认管理员所需的临时配置；生产环境不能为空、不能使用默认值且长度至少为 12 位。请通过受控渠道保存它，不要提交 `.env` 或把实际密码写入计划任务、Git 或本文档。

官网 SEO 动态渲染的选填配置（源码部署建议显式填写，默认值见 `config/idc.php → idc.seo`）：

```ini
# 官网公开地址，用于 canonical / sitemap / JSON-LD；默认取 APP_URL
SEO_SITE_URL=https://www.你的域名.com
# 官网 index.html 模板：源码部署直接读本机前端产物文件（零网络依赖）
SEO_FRONTEND_SHELL_URL=file:///www/wwwroot/你的项目/frontend-user-v3-www/dist/index.html
# shell 模板 / 页面渲染结果缓存秒数（默认 600 / 300）
SEO_SHELL_CACHE_TTL=600
SEO_CACHE_TTL=300
```

> `file://` 为直接读文件方式，路径必须与官网站点根目录（`frontend-user-v3-www/dist`）一致；用 HTTP 方式（如 `http://127.0.0.1:端口/index.html`）亦可，但需保证该端口返回的是**原始 index.html**（而非被强制 HTTPS 跳转或回退改写的内容）。前端重新构建后，最迟在 `SEO_SHELL_CACHE_TTL` 秒后新产物生效。

根目录统一构建前端时，会从 `backend/.env` 注入 `APP_URL`、`FRONTEND_URL`、`CLIENT_CONSOLE_URL`、`ADMIN_URL`，并覆盖各前端目录的同名默认值。因此应先完成上面的四个公开地址配置，再构建前端。

### 5.3 安装后端依赖

```bash
cd /www/wwwroot/你的项目/backend

# 安装依赖（生产环境跳过 dev）
composer install --no-dev --optimize-autoloader
```

### 5.4 初始化空数据库

仅对尚未承载业务数据的空数据库执行。脚本会在缺少时生成 `APP_KEY`，然后导入 schema baseline、执行增量迁移、初始化默认配置和管理员：

```bash
cd /www/wwwroot/你的项目/backend
python3 scripts/install_db.py
```

不要额外执行 `php artisan key:generate` 或首次 `php artisan migrate --force`；上述脚本已按正确顺序完成这些步骤。脚本使用的数据库账户需要有创建目标库、导入表结构和执行迁移的权限。

### 5.5 发布到已有数据库

对已有生产数据库，先完成可恢复的备份，再执行：

```bash
cd /www/wwwroot/你的项目/backend

# 仅执行未记录的增量迁移
php artisan migrate --force

# 清缓存并生成生产缓存
php artisan optimize:clear
php artisan config:cache && php artisan route:cache
```

不要在生产库上使用 `migrate:fresh`、`migrate:reset` 或 `install_db.py --reset`。

### 5.6 设置文件权限

```bash
cd /www/wwwroot/你的项目/backend

sudo chown -R www:www storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 public/uploads public/media
```

### 5.7 重启 PHP

宝塔面板 → 软件商店 → PHP → 重启，使生产缓存生效。

---

## 6. 前端部署

### 6.1 构建前端

确保 Node.js 版本满足要求后，在项目根目录只安装一次 workspace 依赖并统一构建：

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

> **宝塔站点根目录不要直接指向 `dist/`。** 宝塔的「防跨站攻击」会在站点根目录生成 `.user.ini`
> 并加上 `chattr +i` 不可修改属性；若根目录就是 `dist/`，Vite 构建清空产物目录时会撞上这个
> 删不掉的文件，报 `ENOTDIR: not a directory, scandir '.../dist/.user.ini'` 导致构建必然失败。
> 正确做法与 API 站点一致：「网站目录」填应用目录（如 `项目路径/frontend-admin-v3`），
> 「运行目录」填 `/dist`，让 `.user.ini` 落在 `dist` 之外。

> 如使用独立 CDN 域名部署静态资源，在各项目 `.env` 中配置 `VITE_ADMIN_ASSET_BASE_URL` / `VITE_CONSOLE_ASSET_BASE_URL` / `VITE_WWW_ASSET_BASE_URL`。默认以根路径 `/` 发布。

### 6.2 验证站点

构建完成后，在浏览器中分别访问四个站点域名，确认页面正常加载，API 请求正常。

---

## 7. 计划任务配置

宝塔面板 → 计划任务 → 添加：

- **任务类型**：Shell 脚本
- **任务名称**：`Laravel 调度`
- **执行周期**：每分钟
- **脚本内容**：

```bash
cd /www/wwwroot/你的项目/backend
php artisan schedule:run >> /dev/null 2>&1
```

> 这条计划任务每分钟触发心跳，并行消费业务队列 `provision,referral,notification,coupon,default` 与定时队列 `automation`。不要额外配置覆盖同一队列的 `queue:work`，避免重复消费。

同时在宝塔进程守护中常驻：

```bash
cd /www/wwwroot/你的项目/backend
php artisan vnc:relay
```

确认 `127.0.0.1:8100` 监听后再部署新代码；Relay 由守护进程自动重启，`vnc:ensure-relay` 仅用于手工健康检查。

计划任务中的 `php` 必须是与 API 站点相同的 PHP 8.3 CLI。若宝塔默认 CLI 版本不一致，将命令中的 `php` 替换为实际路径，例如 `/www/server/php/83/bin/php`。

---

## 8. 导入旧库（仅数据迁移）

全新空库已由第 5 节的 `install_db.py` 完成初始化。以下步骤只用于有专项迁移方案的旧库数据导入，执行前必须核对目标库并建立独立备份：

```bash
cd /www/wwwroot/你的项目/backend
python3 scripts/migrate_legacy_dump.py --dump "你的dump文件.sql" --dry-run
# 确认无误后执行
python3 scripts/migrate_legacy_dump.py --dump "你的dump文件.sql"
```

> 执行前务必确认目标库、备份和 `.env`。迁移脚本会修改数据，建议先备份。

---

## 9. 常见问题

### 9.1 502 Bad Gateway

通常原因是 PHP-FPM 未运行或 API 站点未选中可用的 PHP 版本：

- 检查 PHP 是否启动（宝塔 → 软件商店 → PHP → 状态）
- 在 API 站点的“PHP 版本”中重新选择 PHP 8.3，然后重启对应 PHP 服务
- 不要手工修改宝塔生成的 `fastcgi_pass` socket 配置

### 9.2 页面刷新 404

前端 SPA 站点未配置 Vue Router History 模式的回退规则。在对应站点的 Nginx 配置中确保包含：

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

### 9.3 Composer 报 `putenv()` 等函数未定义

宝塔 PHP 的禁用函数列表阻止了某些函数。进入宝塔 → 软件商店 → PHP → 禁用函数，删除 `putenv`、`proc_open`、`symlink`，然后重启 PHP。

### 9.4 上传文件过大

在宝塔“PHP → 配置修改”中同时增大 `upload_max_filesize` 和 `post_max_size`，并重启 PHP。若仍返回 HTTP 413，在宝塔站点设置中调整上传限制；不要为了这一项替换完整 Nginx 配置。

### 9.5 定时任务未执行

- 检查宝塔计划任务日志是否正常
- 执行 `php artisan schedule:run` 手动测试
- 确认 `.env` 中 `QUEUE_CONNECTION=database`、`TURAIDC_BUSINESS_QUEUES` 和 `TURAIDC_SCHEDULE_QUEUE` 配置正确，且 `127.0.0.1:8100` 有独立 Relay 进程监听

### 9.6 路由或配置更新不生效

上线后依次执行：

```bash
cd /www/wwwroot/你的项目/backend
php artisan route:clear && php artisan config:clear && php artisan cache:clear
php artisan route:cache && php artisan config:cache
```

然后重启 PHP-FPM（宝塔 → PHP → 重启）。

---

## 10. 运维参考

以下日常运维操作的详细说明在 [部署与调度指南.md](deployment-and-scheduling.md)：

- 查看调度运行情况
- 手动触发一次调度 / 队列消费
- 清理失败队列
- 回滚策略
- 监控与告警建议

---

## 11. 检查清单

部署完成后逐项确认：

- [ ] 四个站点均可通过 HTTPS 访问
- [ ] 后端 API 返回正常 JSON（访问 `https://api.你的域名.com/api/health`）
- [ ] 就绪检查返回 HTTP 200（访问 `https://api.你的域名.com/api/ready`；该检查会验证数据库、缓存、存储和调度状态）
- [ ] 前端页面刷新不 404
- [ ] 管理端可使用首次初始化时单独设置的管理员账号登录
- [ ] 宝塔计划任务已添加且正常执行
- [ ] PHP `APP_DEBUG=false`
- [ ] `.env` 中密码均为强密码
- [ ] Redis 可连接（`redis-cli ping` 返回 PONG）
- [ ] `storage`、`bootstrap/cache` 权限为 `www:www` 且可写
- [ ] 数据库迁移已执行无报错

## 关联文档

- [部署指南](deployment.md)：通用部署步骤。
- [四端 Nginx 伪静态配置](frontend-nginx-rules.md)：站点伪静态规则。
- [部署与调度指南](deployment-and-scheduling.md)：调度与队列配置。
