# 部署与调度指南

## 文档用途

- 描述当前生产环境真实的部署拓扑与调度模型
- 对齐时间：`2026-07-02`
- **全新服务器部署**先看 `docs/references/operations/bt-panel-deployment.md`（宝塔面板）或 `docs/references/operations/bare-metal-deployment.md`（无面板、无容器的原生服务部署）
- **容器化部署（Docker Compose / 1Panel / CI 自动打包）**先看 `docs/references/operations/docker-and-1panel-deployment.md`
- 配套文档：
  - `docs/README.md`（文档入口）
  - `docs/references/operations/local-development.md`（本地启动）
  - `docs/ARCHITECTURE.md`（总体架构）

## 1. 当前生产拓扑

项目跑在单台宝塔服务器上，结构如下：

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

生产环境特征：

- **四端各自独立宝塔站点**，浏览器从三个前端站点直接访问 API 域名；前端 Nginx 不反代 API
- **后端入口走 PHP-FPM**，不使用 `php artisan serve` 或 `app:serve`
- **没有常驻 Queue Worker**，每分钟 `schedule:run` 会并行启动业务 Worker 和 `automation` Worker；两者分别持有独立互斥锁，空闲时约 50 秒后退出，长任务不会阻塞另一队列的下一轮消费
- **VNC Relay 独立常驻**：Relay 重启不会阻塞心跳、支付、新购或续费
- **新购、续费支付后同步履约**：支付回调内直接调用上游开通/续费，用户付款后立即生效；同步失败才回退 `provision` 队列，由每分钟心跳 Worker 重试，不再依赖队列轮询延迟

## 2. 计划任务配置

### 2.1 宝塔计划任务

在宝塔面板 → 计划任务 → 添加：

- 任务类型：Shell 脚本
- 执行周期：每分钟执行
- 脚本内容：

```bash
cd /你的项目/backend
php artisan schedule:run >> /dev/null 2>&1
```

说明：

- 这条计划会同时触发系统自动化任务，并行消费两条队列：业务队列 `provision,referral,notification,coupon,default` 和定时队列 `automation`；任一队列仍在消费时，只跳过该队列的重复 Worker，另一队列继续按分钟消费
- 不要再配置覆盖同一队列的常驻 `queue:work`，避免重复消费；需要低延迟时应按队列边界单独评估
- 任务执行超时强杀（`--timeout` 与 `JobTimedOut` 收敛）依赖 Linux `pcntl`/`SIGALRM`，Windows 开发环境不生效；超时类问题必须在 Linux 环境验证，Windows 上长任务不会被自动终止

### 2.2 VNC Relay 守护

在宝塔进程守护或其他进程管理器中增加常驻命令：

```bash
cd /www/wwwroot/你的项目/backend
php artisan vnc:relay
```

上线前确认 `127.0.0.1:8100` 已监听；Relay 异常时由守护进程自动重启。`vnc:ensure-relay` 仅保留为手工健康检查/故障修复命令，不再注册到心跳任务。

### 2.3 后端调度内容

当前调度能力以真实命令与路由注册为准。查看真实启用清单请看：

- `backend/routes/console.php`
- `backend/app/Console/Commands/`
- 管理端 "调度总览"：`GET /api/v2/admin/schedules/overview`

典型任务（以代码为准）：

- 自动续费检查
- 账单逾期处理
- 服务到期停用与终止
- 上游状态同步
- 各类日志清理

## 3. 部署流程

### 3.1 后端部署

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

> **数据库初始化说明**：
>
> - `database/schema/mysql-schema.sql` 是当前生产库完整结构快照，包含 62 张表和 `migrations` 记录
> - 历史迁移文件已归档至 `database/migrations/_archive/`，不再逐个执行
> - 增量迁移（`database/migrations/*.php`）用于后续新功能上线
> - `install_db.py` 最后会经 `SettingsSeeder::seed()` 幂等种入：
>   - 系统核心默认配置（`settings` 表，含 `notification.email_enabled` / `sms_enabled` / `sms_template_code`）
>   - 通知模板默认数据（`notification_templates` 表）：email 全量模板 + sms 验证码模板
>   - schema baseline 仅导出表结构、不含模板数据，**模板种子必须经该 Seeder 执行**，切勿依赖迁移或手工补种
>
> **首次部署后请检查（管理后台 → 站点设置 → 通知总开关）**：
>
> - 邮件通知 / 短信通知默认关闭，按需在后台打开
> - 邮件/短信插件需在「插件管理」安装、配置并绑定，再前往「通知与接口」维护对应模板

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

产物目录分别是 `frontend-user-v3-www/dist`、`frontend-user-v4-console/dist`、`frontend-admin-v3/dist`，各自指向对应宝塔站点根目录。

VitePress 文档官网不属于上述三端，必须通过 `pnpm run build:docs` 独立构建和发布。内容维护、产物目录与发布检查见[文档官网维护指南](../../governance/docs-web-maintenance.md)。

> **dev 与 build 的 env 分开**：本地开发（`pnpm run dev`）读取各前端目录下的 `.env.dev`（dev 脚本用 `--mode dev`）；构建（`pnpm run build:frontends`）读取各前端目录下的 `.env`。前端构建不依赖 backend 环境文件。
>
> 每个前端目录（`frontend-admin-v3`、`frontend-user-v3-www`、`frontend-user-v4-console`）需要两份 env（均 gitignore，不入库）：
>
> - `.env.dev`：本地开发地址（`127.0.0.1` 各端端口、本地 API）
> - `.env`：构建地址（生产域名），部署打包前填写真实域名
>
> 部署前确认 `.env` 中 `VITE_API_BASE_URL`、`VITE_PUBLIC_SITE_URL`、`VITE_CONSOLE_SITE_URL`、`VITE_SESSION_COOKIE_DOMAIN` 与生产一致；缺项或填错会让产物指向错误地址。

```bash
# 只预览构建目标，不实际构建
pnpm run build:frontends:dry
```

### 3.3 .env 关键项

最小化需要核对的 `.env`：

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://api.你的域名`（API、`/media/*`、`/uploads/*`、VNC WebSocket）
- `FRONTEND_URL=https://www.你的域名`
- `CLIENT_CONSOLE_URL=https://console.你的域名`
- `ADMIN_URL=https://admin.你的域名`
- `CLIENT_SESSION_COOKIE_DOMAIN=.你的域名`（官网和控制台需要跨子域共享登录态时设置；本地留空）
- `SESSION_SECURE_COOKIE=true`（HTTP 环境改为 `false`）
- `DB_*`：MySQL 8 连接
- `REDIS_*`：Redis 连接
- `CACHE_STORE=redis`
- `SESSION_DRIVER=file`（当前生产口径）
- `QUEUE_CONNECTION=database`（当前生产口径）
- `TURAIDC_BUSINESS_QUEUES=provision,referral,notification,coupon,default`
- `TURAIDC_SCHEDULE_QUEUE=automation`

四个公开地址可统一使用 HTTP 或 HTTPS；HTTPS 前端不能指向 HTTP API。CORS 会从三个前端 URL 自动生成精确 allowlist，无需在前端 Nginx 增加代理。

Redis 当前用于缓存；不要在没有专项方案和验证的情况下把会话或队列切到 Redis。

### 3.4 受信代理与来源 IP 契约

后端在 `backend/bootstrap/app.php` 配置了 `trustProxies`：只信任回环与 RFC1918/ULA 私网段，且只采信 `X-Forwarded-For`、`X-Forwarded-Port`、`X-Forwarded-Proto`（不信任 `X-Forwarded-Host`）。`request()->ip()` 依赖该配置解析真实客户端 IP，直接影响：

- 审计字段（如 `last_login_ip`）与推荐风控的“注册 IP == 推荐人 IP”判断；
- `/upload_image` 上游附件上传的启用开关、白名单/限流中间件（`TicketUpstreamUploadThrottle`）。接口默认关闭，管理端配置工单传递规则前必须先开启；白名单外上传默认拒绝。

部署必须满足以下契约，否则 IP 白名单与限流会被绕过或误判：

1. **边缘反向代理是唯一公网入口**：后端 API 端口（宝塔 PHP-FPM 站点 / 容器 `8080`）只能被受信反代访问，不能直接暴露给公网或不可信私网来源。
2. **代理必须真实生成来源头**：外部反代需携带 `X-Forwarded-For`、`X-Forwarded-Proto`。单层受信代理应把 `X-Forwarded-For` **重置为 `$remote_addr`**，不要用 `$proxy_add_x_forwarded_for` 原样追加——否则客户端可自带伪造 XFF 直接污染 `request()->ip()`。只有明确存在多层受信代理且每层都清洗时才允许追加。
3. **不得把公网代理地址加入 `trustProxies`**：当前配置只信任回环与私网段；若实际入口代理不在这些网段（例如托管在公有云的 LB），必须先评估暴露面并收窄为明确的入口 IP/CIDR，再修改配置。
4. 宝塔 PHP-FPM 直连形态（前端 Nginx 不反代 API）本身不产生 XFF；后端以 PHP-FPM 接收到的 `REMOTE_ADDR` 为准，不受上述契约影响，但也不能依赖 XFF 取客户端 IP。

> 容器部署注意：`deploy/docker/.env.example` 默认把 `API_PORT=8080` 暴露到所有网卡。上线前应改为仅本机绑定（`127.0.0.1:8080`），由宿主机 Nginx/1Panel 反代进入；否则私网来源可直接访问 API 并伪造 XFF。

## 4. 本地启动差异

本地同样使用直连 API：三个前端分别在 `127.0.0.1:5175/5173/5174`，API 在 `127.0.0.1:8000`。前端开发环境的 `VITE_API_BASE_URL` 已指向 `http://127.0.0.1:8000/api`，因此会真实验证 CORS 和 VNC WebSocket。

后端用一条封装命令同时拉起 HTTP、Relay 与队列：

```bash
cd backend
php artisan app:serve
# 等价于：HTTP + VNC Relay + 业务队列 Worker

# 如果本地想同时拉起调度，Relay 另行独立运行
php artisan app:serve --with-schedule --without-vnc
php artisan vnc:relay
```

细节见 `docs/references/operations/local-development.md` 与 `backend/app/Console/Commands/ServeBackendStackCommand.php`。

## 5. 常见运维操作

### 5.1 查看调度运行情况

- 宝塔计划任务页可看脚本日志
- `backend/storage/logs/laravel.log`
- 管理端 `/api/v2/admin/schedules/overview` 与 `/api/v2/admin/logs/tasks`

### 5.2 手动触发一次调度

```bash
cd backend
php artisan schedule:run
```

### 5.3 手动跑一次队列消费

正常不需要。只在排查"队列已堆积但未消费"时按目标队列手动运行：

```bash
cd backend
php artisan queue:work --once --queue=provision,referral,notification,coupon,default
php artisan queue:work --once --queue=automation
```

### 5.4 清理失败队列

```bash
cd backend
php artisan queue:retry all     # 重试全部失败
php artisan queue:flush         # 清空失败队列（慎用）
```

### 5.5 OPcache / 路由 / 配置缓存刷新

上线后如果路由或配置没生效，依次跑：

```bash
cd backend
php artisan route:clear && php artisan config:clear && php artisan cache:clear
php artisan route:cache && php artisan config:cache
# 重启 PHP-FPM（宝塔面板 → PHP → 重启）
```

### 5.6 PHP 禁用函数问题

Composer 报 `Call to undefined function putenv()` 等错误时，优先核对宝塔 PHP 的禁用函数和 CLI / FPM 配置差异；当前仓库未保留单独修复指南。

## 6. 监控与告警

当前已集成 Sentry SDK（需配置 `SENTRY_LARAVEL_DSN` 激活），临时监控靠：

- Laravel 日志（`backend/storage/logs/laravel.log`）
- 宝塔监控面板
- `/api/v2/admin/logs/*` 管理端日志页
- 健康检查端点：`/api/health`（存活）、`/api/ready`（就绪，检查 DB/Cache/Storage/Scheduler）

`/api/ready` 会分别报告心跳存活、任务运行状态和队列状态；心跳只处理当前 15 分钟槽位，错过的槽位默认不自动回放，需依赖任务幂等补偿或管理端手动触发。

建议的整改方向：

- 配置 Sentry DSN 并激活错误追踪
- 关键任务失败告警（自动续费、支付回调、实名回调）
- 数据库慢查询日志启用 `long_query_time=1`

## 7. 回滚策略

- 后端：代码回退 + `composer install` + `php artisan migrate:rollback`（仅当迁移可逆）
- 前端：按站点独立回滚，直接恢复旧 `dist`
- 数据库：禁止在生产直接 `migrate:rollback` 不可逆迁移，走备份还原
- 关键业务（支付、开通、返佣）回退前必须先冻结 `schedule:run`，避免边回滚边产生新异步动作

## 8. 文件权限（宝塔部署后）

```bash
sudo chown -R www:www storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 public/uploads public/media
# .env 文件必须严格权限，防止凭据泄露
sudo chmod 600 .env
```

## 9. 数据库备份

项目提供 MySQL 定时备份脚本 `backend/scripts/backup_mysql.sh`，从 `.env` 读取数据库连接信息。

### 9.1 宝塔计划任务配置

在宝塔面板 → 计划任务 → 添加：

- 任务类型：Shell 脚本
- 执行周期：每天凌晨 3:00
- 脚本内容：

```bash
cd /你的项目/backend
bash scripts/backup_mysql.sh
```

### 9.2 备份配置

| 环境变量                | 默认值                          | 说明             |
| ----------------------- | ------------------------------- | ---------------- |
| `BACKUP_DIR`            | `backend/storage/backups/mysql` | 备份文件存放目录 |
| `BACKUP_RETENTION_DAYS` | 14                              | 备份保留天数     |

### 9.3 手动备份

```bash
cd backend
bash scripts/backup_mysql.sh
```

### 9.4 恢复备份

```bash
gunzip -c /path/to/backup.sql.gz | mysql -u用户名 -p密码 数据库名
```

## 10. OPcache 生产配置

Laravel 生产环境强烈建议启用 OPcache 并优化配置。在宝塔面板 → PHP → 配置文件中添加或修改：

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

**部署后必须重启 PHP-FPM** 使 OPcache 和路由/配置缓存生效：

```bash
# 宝塔面板 → PHP → 重启
# 或命令行
sudo systemctl restart php-fpm-82
```

**OPcache 黑名单文件**（可选，排除不需要缓存的文件）：

```text
# /www/server/php/82/etc/opcache_blacklist.txt
/vendor/phpunit/
/vendor/mockery/
/vendor/phpspec/
/vendor/phpstan/
```

## 关联文档

- [启动指南](local-development.md)：本地启动与验证。
- [四端 Nginx 伪静态配置](frontend-nginx-rules.md)：站点伪静态规则。
- [日志归档与 MySQL 维护](../database/log-archive-and-mysql-maintenance.md)：日志与数据库运维。
