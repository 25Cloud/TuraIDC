# 部署指南

本文档说明如何将图拉云业务/财务系统部署到生产环境。部署拓扑：后端 API、三个前端静态站点、Redis、MySQL，另需常驻队列 Worker 与调度进程。

## 一、环境要求

- 操作系统：Linux（推荐 Ubuntu 22.04+ / Debian 12）或 Windows Server
- PHP 8.3+（扩展：`pdo_mysql`、`redis`、`mbstring`、`openssl`、`zip`、`gd` 或 `imagick` 按需）
- MySQL 8.0+（推荐；最低兼容 5.7.8——5.7 已 EOL，仅兼容性支持。混用 8.0 的 mysqldump 客户端备份 5.7 服务端时需加 `--column-statistics=0`。**5.7 部署必须在 my.cnf 的 `[mysqld]` 段设置 `explicit_defaults_for_timestamp=ON`**：5.7 默认 OFF 时，表内首个无显式默认值的 `timestamp` 列会被隐式附加 `ON UPDATE CURRENT_TIMESTAMP`，与 8.0 行为不一致，可能悄悄改写业务时间字段）
- Redis 6.0+（生产必需：分布式锁、缓存）
- Composer 2.x
- Node.js 20+（仅构建前端时需要）
- Nginx（或任意静态服务器 + 反代）

## 二、准备代码

```bash
git clone <你的仓库地址> TuraIDC
cd TuraIDC
```

## 三、后端部署

### 1. 安装依赖与配置

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

### 2. 初始化数据库

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

### 3. 创建管理员账号

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

### 4. 配置计划任务与队列

将以下命令加入系统 crontab（每分钟执行一次 Laravel 调度器）：

```cron
* * * * * cd /path/to/TuraIDC/backend && php artisan schedule:run >> /dev/null 2>&1
```

Laravel 调度器内部会按 `routes/console.php` 定义的节奏驱动心跳、服务生命周期、账单、工单关闭、日志归档等任务。数据库为唯一时钟源，无需单独配置多条 cron。

常驻队列 Worker（使用 supervisor 管理，见下文）：

```bash
php artisan queue:work --queue=provision,referral,notification,coupon,default --sleep=1 --tries=3 --timeout=1200
```

### 5. 缓存清理与资源

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

> 若 `SESSION_DRIVER` 使用 file，`storage/framework/sessions` 需可写。

## 四、前端构建

在仓库根目录执行（需 Node.js）：

```bash
npm install
npm run build:frontends
```

该命令读取 `backend/.env` 中的四个公开地址，依次构建三端到各自 `dist/`：

| 前端       | 产物目录                         | 端口（开发） |
| ---------- | -------------------------------- | ------------ |
| 官网门户   | `frontend-user-v3-www/dist/`     | 5175         |
| 用户控制台 | `frontend-user-v4-console/dist/` | 5173         |
| 管理后台   | `frontend-admin-v3/dist/`        | 5174         |

三端均为纯静态站点，发布时将各自 `dist/` 内容部署到对应 Nginx 站点即可。

## 五、Nginx 配置示例

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

## 六、进程守护（supervisor）

队列 Worker 与 VNC Relay（可选）需常驻，推荐 supervisor 管理。

### 队列 Worker

```ini
[program:finance-queue]
directory=/path/to/TuraIDC/backend
command=php artisan queue:work --queue=provision,referral,notification,coupon,default --sleep=1 --tries=3 --timeout=1200
autostart=true
autorestart=true
numprocs=2
user=www-data
```

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

也可使用统一入口 `php artisan app:serve --with-schedule` 同时托管 HTTP、VNC Relay、队列与调度，生产环境建议按上文拆分独立进程。

## 七、可选集成配置

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

## 八、备份

参考 `backend/scripts/backup_mysql.sh`（每日凌晨备份 MySQL 并保留 14 天）：

```bash
bash backend/scripts/backup_mysql.sh
```

建议同时备份：

- 数据库（`mysqldump --single-transaction --routines --triggers`）
- `backend/storage/app/private/`（上传文件、票据附件、日志归档）
- `backend/.env`（应用密钥与配置）

## 九、上线前检查清单

- [ ] `.env` 中 `APP_DEBUG=false`、`APP_ENV=production`、`SESSION_SECURE_COOKIE=true`
- [ ] 生产环境 `CACHE_STORE=redis`（分布式锁依赖）
- [ ] 四个公开域名均为 HTTPS 且互不相同
- [ ] 数据库已执行全部迁移并初始化默认配置
- [ ] 已创建超级管理员并修改默认凭据
- [ ] crontab 已配置 `php artisan schedule:run`
- [ ] supervisor 已托管队列 Worker
- [ ] 前端三端产物已部署并刷新缓存
- [ ] `backend/public/uploads/` 与 `storage` 目录权限正确
